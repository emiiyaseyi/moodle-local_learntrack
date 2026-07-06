<?php
namespace local_learnpath\task;

defined('MOODLE_INTERNAL') || die();

use local_learnpath\export\manager as export_manager;
use local_learnpath\data\helper as data_helper;

class send_scheduled_reports extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnTrack: Send scheduled reports';
    }

    public function execute(): void {
        global $DB;

        $now = time();

        // All recurring reports — explicit admin-created schedules AND each
        // path's auto-provisioned weekly manager report (recipienttype =
        // 'managers', ismanaged = 1) — are plain rows in this table now,
        // checked the same way: nextrun <= now. This gives every schedule
        // automatic catch-up if cron was delayed or down, instead of the
        // previous fixed Friday-14:00-UTC window that had no recovery path.
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
            \mtrace("LearnTrack Reports: Schedule [{$schedule->id}]"
                . " group={$schedule->groupid} freq={$schedule->frequency}"
                . " type=" . ($schedule->recipienttype ?? 'manual'));

            $recipients = ($schedule->recipienttype ?? 'manual') === 'managers'
                ? $this->get_manager_emails((int)$schedule->groupid)
                : array_filter(array_map('trim', explode(',', $schedule->recipients)));

            if (empty($recipients)) {
                \mtrace("  ✗ No valid recipients — skipping.");
                $this->advance_schedule($DB, $schedule, $now);
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
                            : "  ✗ email_report returned false.");
            } catch (\Throwable $e) {
                \mtrace("  ✗ Exception: " . $e->getMessage());
            }

            $this->advance_schedule($DB, $schedule, $now);
        }
    }

    /**
     * Current manager emails for a path, resolved fresh at send time so
     * membership changes (added/removed managers) take effect on the very
     * next send without editing the schedule.
     */
    private function get_manager_emails(int $groupid): array {
        global $DB;
        $managers = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.email
             FROM {local_learnpath_managers} m
             JOIN {user} u ON u.id = m.userid
             WHERE m.groupid = :gid AND u.deleted = 0 AND u.suspended = 0",
            ['gid' => $groupid]
        );
        return array_values(array_map(fn($m) => $m->email, $managers));
    }

    private function advance_schedule(\moodle_database $DB, object $schedule, int $now): void {
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
     * Simple +period calculation — no fixed-hour pinning needed since the task
     * now runs every hour and checks its own nextrun timestamps.
     */
    public static function calc_next_run(string $frequency, int $from): int {
        return match($frequency) {
            'daily'   => strtotime('+1 day',   $from),
            'monthly' => strtotime('+1 month', $from),
            default   => strtotime('+7 days',  $from),
        };
    }

    /**
     * Initial nextrun for a NEW explicit schedule.
     * Weekly → next Friday at 14:00 UTC (15:00 WAT = 3 PM Lagos).
     * Other frequencies → start sending from the next period.
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
            default: // weekly → next Friday 14:00 UTC
                $dow  = (int)$dt->format('N');
                $hour = (int)$dt->format('H');
                // If today is Friday and still before 14:00 UTC, use today.
                if ($dow === 5 && $hour < 14) {
                    // keep today's date
                } else {
                    $days = (5 - $dow + 7) % 7;
                    if ($days === 0) $days = 7; // same weekday but window passed
                    $dt->modify("+{$days} days");
                }
                break;
        }
        $dt->setTime(14, 0, 0);
        return (int)$dt->getTimestamp();
    }
}
