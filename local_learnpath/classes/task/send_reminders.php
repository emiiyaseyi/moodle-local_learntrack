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
            \mtrace('LearnTrack: No reminders due.');
            return;
        }

        foreach ($reminders as $reminder) {
            \mtrace("LearnTrack: Reminder [{$reminder->id}] '{$reminder->name}'");
            $group = $DB->get_record('local_learnpath_groups', ['id' => $reminder->groupid]);
            if (!$group) {
                continue;
            }

            $allrows = data_helper::get_progress_detail((int)$reminder->groupid, \get_admin()->id);

            // Group by user
            $by_user = [];
            foreach ($allrows as $row) {
                $by_user[$row->userid][] = $row;
            }

            $sent = 0;
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
                    continue;
                }

                if ($reminder->frequency === 'once') {
                    $already = $DB->record_exists('local_learnpath_reminder_log', [
                        'reminderid' => $reminder->id,
                        'userid'     => $uid,
                    ]);
                    if ($already) {
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

            $next = self::calc_next_run($reminder->frequency, $now);
            $DB->update_record('local_learnpath_reminders', (object)[
                'id'      => $reminder->id,
                'lastrun' => $now,
                'nextrun' => $next,
            ]);
            \mtrace("  ✓ Sent to {$sent} learner(s). Next: " . \userdate($next));
        }
    }

    public static function calc_next_run(string $frequency, int $from): int {
        return match($frequency) {
            'daily'   => strtotime('+1 day',    $from),
            'weekly'  => strtotime('+1 week',   $from),
            'monthly' => strtotime('+1 month',  $from),
            'once'    => strtotime('+10 years', $from),
            default   => strtotime('+1 week',   $from),
        };
    }
}
