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
                $this->advance_nextrun($DB, $reminder, $now);
                continue;
            }

            $allrows = data_helper::get_progress_detail((int)$reminder->groupid, \get_admin()->id);

            $by_user = [];
            foreach ($allrows as $row) {
                $by_user[$row->userid][] = $row;
            }

            if (empty($by_user)) {
                \mtrace("  — No learners in group.");
                $this->advance_nextrun($DB, $reminder, $now);
                continue;
            }

            $sent        = 0;
            $sent_email  = false;
            $sent_inapp  = false;
            $sent_sms    = false;

            foreach ($by_user as $uid => $courses) {
                $completed = 0;
                $total     = count($courses);
                foreach ($courses as $c) {
                    if ($c->status === 'complete') $completed++;
                }
                $pct = $total > 0 ? (int)round($completed / $total * 100) : 0;

                $match = match($reminder->target) {
                    'notstarted' => ($pct === 0),
                    'inprogress' => ($pct > 0 && $pct < 100),
                    'incomplete' => ($pct < 100),
                    default      => false,
                };

                if (!$match) continue;

                if ($reminder->frequency === 'once') {
                    $already = $DB->record_exists('local_learnpath_reminder_log', [
                        'reminderid' => $reminder->id,
                        'userid'     => $uid,
                    ]);
                    if ($already) continue;
                }

                $learner = $DB->get_record('user', ['id' => $uid, 'deleted' => 0]);
                if (!$learner) continue;

                try {
                    $results = notifier::send_reminder($reminder, $learner, $group, $courses);
                    if ($results['email']) $sent_email = true;
                    if ($results['inapp'])  $sent_inapp = true;
                    if ($results['sms'])    $sent_sms   = true;
                    $sent++;
                } catch (\Throwable $e) {
                    \mtrace("  ✗ {$learner->email}: " . $e->getMessage());
                }
            }

            // Insert ONE batch-summary row (userid=0) per dispatch so the history
            // tab shows a single clean entry instead of one row per learner.
            $channels_used = implode('+', array_filter([
                $sent_email ? 'email' : '',
                $sent_inapp ? 'inapp' : '',
                $sent_sms   ? 'sms'   : '',
            ]));
            try {
                $DB->insert_record('local_learnpath_reminder_log', (object)[
                    'reminderid' => $reminder->id,
                    'userid'     => 0,   // 0 = batch summary, not a real user
                    'channel'    => $channels_used ?: 'none',
                    'timesent'   => $now,
                    'status'     => $sent > 0 ? 'sent' : 'no_match',
                ]);
            } catch (\Throwable $e) {}

            $this->advance_nextrun($DB, $reminder, $now);
            \mtrace("  ✓ Sent to {$sent} learner(s) via [{$channels_used}]. Next: "
                . \userdate(self::calc_next_run($reminder->frequency, $now)));
        }
    }

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
     * Calculate the next run timestamp.
     * Uses a simple +period calculation — no timezone-dependent hour pinning,
     * because the Moodle task itself now runs every 30 minutes so timing is
     * accurate to within 30 minutes regardless of site timezone.
     */
    public static function calc_next_run(string $frequency, int $from): int {
        return match($frequency) {
            'once'    => strtotime('+10 years', $from),
            'daily'   => strtotime('+1 day',    $from),
            'weekly'  => strtotime('+7 days',   $from),
            'monthly' => strtotime('+1 month',  $from),
            default   => strtotime('+7 days',   $from),
        };
    }
}
