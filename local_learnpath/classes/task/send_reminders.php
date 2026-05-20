<?php
namespace local_learnpath\task;

defined('MOODLE_INTERNAL') || die();

use local_learnpath\data\helper as data_helper;
use local_learnpath\notification\notifier;

class send_reminders extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnTrack: Send learner reminders';
    }

    public function execute(): void {
        global $DB;

        $now       = time();
        $reminders = $DB->get_records_select(
            'local_learnpath_reminders',
            'enabled = 1 AND (nextrun IS NULL OR nextrun <= :now)',
            ['now' => $now]
        );

        if (empty($reminders)) {
            \mtrace('LearnTrack Reminders: None due at ' . \userdate($now));
            return;
        }

        \mtrace('LearnTrack Reminders: ' . count($reminders) . ' reminder(s) due.');

        foreach ($reminders as $reminder) {
            \mtrace("LearnTrack Reminders: [{$reminder->id}] '{$reminder->name}'"
                . " freq={$reminder->frequency} group={$reminder->groupid}");

            $group = $DB->get_record('local_learnpath_groups', ['id' => $reminder->groupid]);
            if (!$group) {
                \mtrace("  ✗ Group {$reminder->groupid} not found — skipping.");
                // Advance nextrun so a deleted-group reminder doesn't block forever.
                $this->advance_nextrun($DB, $reminder, $now);
                continue;
            }

            $allrows = data_helper::get_progress_detail((int)$reminder->groupid, \get_admin()->id);

            // Group rows by user
            $by_user = [];
            foreach ($allrows as $row) {
                $by_user[$row->userid][] = $row;
            }

            if (empty($by_user)) {
                \mtrace("  — No learners in group.");
                $this->advance_nextrun($DB, $reminder, $now);
                continue;
            }

            $sent = 0;
            $skipped = 0;
            foreach ($by_user as $uid => $courses) {
                $completed = 0;
                $total     = count($courses);
                foreach ($courses as $c) {
                    if ($c->status === 'complete') {
                        $completed++;
                    }
                }
                $pct = $total > 0 ? (int)round($completed / $total * 100) : 0;

                $match = match($reminder->target) {
                    'notstarted' => ($pct === 0),
                    'inprogress' => ($pct > 0 && $pct < 100),
                    'incomplete' => ($pct < 100),
                    default      => false,
                };

                if (!$match) {
                    $skipped++;
                    continue;
                }

                if ($reminder->frequency === 'once') {
                    $already = $DB->record_exists('local_learnpath_reminder_log', [
                        'reminderid' => $reminder->id,
                        'userid'     => $uid,
                    ]);
                    if ($already) {
                        $skipped++;
                        continue;
                    }
                }

                $learner = $DB->get_record('user', ['id' => $uid, 'deleted' => 0]);
                if (!$learner) {
                    continue;
                }

                try {
                    notifier::send_reminder($reminder, $learner, $group, $courses);
                    $sent++;
                } catch (\Throwable $e) {
                    \mtrace("  ✗ {$learner->email}: " . $e->getMessage());
                }
            }

            $this->advance_nextrun($DB, $reminder, $now);
            \mtrace("  ✓ Sent={$sent} Skipped={$skipped}. Next: " . \userdate(
                self::calc_next_run($reminder->frequency, $now)
            ));
        }
    }

    /**
     * Update lastrun and nextrun for a reminder.
     * nextrun is pinned to 08:30 UTC (30 min before the 09:00 UTC task cron)
     * to prevent drift when cron execution time varies by seconds.
     */
    private function advance_nextrun(\moodle_database $DB, object $reminder, int $now): void {
        $next = self::calc_next_run($reminder->frequency, $now);
        try {
            $DB->update_record('local_learnpath_reminders', (object)[
                'id'      => $reminder->id,
                'lastrun' => $now,
                'nextrun' => $next,
            ]);
        } catch (\Throwable $e) {
            \mtrace("  ✗ Could not update nextrun: " . $e->getMessage());
        }
    }

    /**
     * Next run pinned to 08:30 UTC on the correct future date.
     * Pinning to a fixed time prevents weekly drift: the 09:00 UTC cron always
     * catches an 08:30 UTC nextrun on the correct weekday.
     *
     * 'once' → +10 years (effectively disabled after first send).
     */
    public static function calc_next_run(string $frequency, int $from): int {
        if ($frequency === 'once') {
            return strtotime('+10 years', $from);
        }
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
        // Pin to 08:30 UTC so the 09:00 UTC task cron catches it without drift.
        $dt->setTime(8, 30, 0);
        return (int)$dt->getTimestamp();
    }
}
