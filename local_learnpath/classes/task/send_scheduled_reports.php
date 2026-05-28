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

        // ── 1. Explicit admin-created schedules ───────────────────────────────
        $schedules = $DB->get_records_select(
            'local_learnpath_schedules',
            'enabled = 1 AND (nextrun IS NULL OR nextrun <= :now)',
            ['now' => $now]
        );

        if (!empty($schedules)) {
            \mtrace('LearnTrack Reports: ' . count($schedules) . ' explicit schedule(s) due.');
            foreach ($schedules as $schedule) {
                \mtrace("LearnTrack Reports: Schedule [{$schedule->id}]"
                    . " group={$schedule->groupid} freq={$schedule->frequency}");

                $recipients = array_filter(array_map('trim', explode(',', $schedule->recipients)));
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
        } else {
            \mtrace('LearnTrack Reports: No explicit schedules due.');
        }

        // ── 2. Auto-weekly: send summary report to every path's managers ──────
        // Fires every Friday between 14:00–15:00 UTC (15:00–16:00 WAT = 3–4 PM Lagos).
        // Guards against duplicate sends within the same week.
        $this->maybe_send_manager_weekly_reports($now);
    }

    /**
     * On Fridays 14:00–14:59 UTC, send each path's progress summary to its managers.
     * One send per path per week (tracked via local_learnpath_email_log viewmode flag).
     */
    private function maybe_send_manager_weekly_reports(int $now): void {
        global $DB;

        $dt  = new \DateTime('@' . $now, new \DateTimeZone('UTC'));
        $dow = (int)$dt->format('N'); // 1=Mon … 5=Fri … 7=Sun
        $h   = (int)$dt->format('H');

        if ($dow !== 5 || $h < 14 || $h >= 15) {
            // Not the Friday 14:xx UTC window.
            return;
        }

        // Already sent this week for this path? Use Monday 00:00 UTC as week boundary.
        $monday_utc = strtotime('monday this week 00:00:00', $now);

        \mtrace('LearnTrack Reports: Friday 14:xx UTC — checking manager auto-reports.');

        $paths = $DB->get_records('local_learnpath_groups');
        if (empty($paths)) return;

        $sent_count = 0;
        foreach ($paths as $path) {
            // Get managers for this path.
            $managers = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname
                 FROM {local_learnpath_managers} m
                 JOIN {user} u ON u.id = m.userid
                 WHERE m.groupid = :gid AND u.deleted = 0 AND u.suspended = 0",
                ['gid' => $path->id]
            );
            if (empty($managers)) continue;

            // Already auto-sent this week?
            $already = $DB->record_exists_select(
                'local_learnpath_email_log',
                "groupid = :gid AND viewmode = 'auto_manager_weekly' AND timesent >= :ws",
                ['gid' => $path->id, 'ws' => $monday_utc]
            );
            if ($already) continue;

            $recipients = array_values(array_map(fn($m) => $m->email, $managers));
            \mtrace("  → Path [{$path->id}] '{$path->name}' → "
                . count($recipients) . ' manager(s): ' . implode(', ', $recipients));

            try {
                // email_report logs with viewmode='auto_manager_weekly'
                $ok = export_manager::email_report(
                    (int)$path->id,
                    $recipients,
                    'xlsx',
                    'auto_manager_weekly',
                    \get_admin()->id
                );
                \mtrace($ok ? "    ✓ Sent." : "    ✗ Failed (check SMTP).");
                if ($ok) $sent_count++;
            } catch (\Throwable $e) {
                \mtrace("    ✗ Exception: " . $e->getMessage());
            }
        }

        \mtrace("LearnTrack Reports: Auto-manager weekly — sent {$sent_count} report(s).");
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
