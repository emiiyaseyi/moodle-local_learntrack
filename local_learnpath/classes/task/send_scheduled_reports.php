<?php
namespace local_learnpath\task;

defined('MOODLE_INTERNAL') || die();

use local_learnpath\export\manager as export_manager;

class send_scheduled_reports extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnTrack: Send scheduled reports';
    }

    public function execute(): void {
        global $DB;

        $now = time();

        // BUG FIX: Original query used "nextrun <= :now" which silently skips rows
        // where nextrun IS NULL (new schedules before first send). Added IS NULL check.
        $schedules = $DB->get_records_select(
            'local_learnpath_schedules',
            'enabled = 1 AND (nextrun IS NULL OR nextrun <= :now)',
            ['now' => $now]
        );

        if (empty($schedules)) {
            \mtrace('LearnTrack Reports: No schedules due at ' . \userdate($now));
            return;
        }

        \mtrace('LearnTrack Reports: ' . count($schedules) . ' schedule(s) due.');

        foreach ($schedules as $schedule) {
            \mtrace("LearnTrack Reports: Schedule [{$schedule->id}] group={$schedule->groupid}"
                . " freq={$schedule->frequency} recipients={$schedule->recipients}");

            $recipients = array_filter(array_map('trim', explode(',', $schedule->recipients)));
            if (empty($recipients)) {
                \mtrace("  ✗ No valid recipients — skipping.");
                // Always advance nextrun so an empty-recipients schedule doesn't block forever.
                $this->advance_nextrun($DB, $schedule, $now);
                continue;
            }

            try {
                $ok = export_manager::email_report(
                    (int)$schedule->groupid,
                    $recipients,
                    $schedule->format,
                    $schedule->viewmode ?? 'summary',
                    \get_admin()->id
                );
                \mtrace($ok ? "  ✓ Sent to: " . implode(', ', $recipients)
                            : "  ✗ email_report returned false (check SMTP/mail log).");
            } catch (\Throwable $e) {
                \mtrace("  ✗ Exception: " . $e->getMessage());
                // Still advance nextrun on exception so the schedule isn't retried
                // on every single cron run if there's a persistent error.
                // The error is visible in Moodle's scheduled-task log.
            }

            // Always advance nextrun — outside the try block so it runs whether
            // the send succeeded, failed, or threw. Keeps the schedule moving.
            $this->advance_nextrun($DB, $schedule, $now);
        }
    }

    /**
     * Update lastrun and nextrun for a schedule.
     * nextrun is pinned to 13:30 UTC (30 min before the 14:00 UTC task) so
     * the cron always catches it on the correct weekday without drift.
     */
    private function advance_nextrun(\moodle_database $DB, object $schedule, int $now): void {
        $next = self::calc_next_run($schedule->frequency, $now);
        try {
            $DB->update_record('local_learnpath_schedules', (object)[
                'id'      => $schedule->id,
                'lastrun' => $now,
                'nextrun' => $next,
            ]);
            \mtrace("  → Next run: " . \userdate($next));
        } catch (\Throwable $e) {
            \mtrace("  ✗ Could not update nextrun: " . $e->getMessage());
        }
    }

    /**
     * Calculate the next run timestamp, pinned to 13:30 UTC on the correct date.
     * Pinning to a fixed time prevents drift when cron execution time varies by seconds.
     * The 14:00 UTC task cron will always catch a 13:30 UTC nextrun on the same day.
     *
     * For weekly: same day-of-week next week at 13:30 UTC.
     * For daily : tomorrow at 13:30 UTC.
     * For monthly: same date next month at 13:30 UTC.
     */
    public static function calc_next_run(string $frequency, int $from): int {
        $dt = new \DateTime('@' . $from, new \DateTimeZone('UTC'));
        switch ($frequency) {
            case 'daily':
                $dt->modify('+1 day');
                break;
            case 'monthly':
                $dt->modify('+1 month');
                break;
            default: // weekly
                $dt->modify('+7 days');
                break;
        }
        // Pin to 13:30 UTC so the 14:00 UTC cron always catches it.
        $dt->setTime(13, 30, 0);
        return (int)$dt->getTimestamp();
    }

    /**
     * Calculate the initial nextrun for a NEW weekly schedule.
     * Targets the next Friday at 13:30 UTC so the 14:00 UTC cron catches it
     * that same Friday afternoon (WAT = 15:00 = 3 PM).
     * For daily/monthly schedules, targets tomorrow/next month at 13:30 UTC.
     */
    public static function first_nextrun(string $frequency): int {
        $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        switch ($frequency) {
            case 'daily':
                $dt->modify('+1 day');
                break;
            case 'monthly':
                $dt->modify('+1 month');
                break;
            default: // weekly → target next Friday
                $dow = (int)$dt->format('N'); // 1=Mon … 5=Fri … 7=Sun
                // Days until next Friday; if today IS Friday and it's before 13:30, use today.
                if ($dow === 5 && (int)$dt->format('H') < 13) {
                    // today is Friday and before the window — keep today
                } else {
                    $days_ahead = (5 - $dow + 7) % 7;
                    if ($days_ahead === 0) {
                        $days_ahead = 7; // already past the window today
                    }
                    $dt->modify("+{$days_ahead} days");
                }
                break;
        }
        $dt->setTime(13, 30, 0);
        return (int)$dt->getTimestamp();
    }
}
