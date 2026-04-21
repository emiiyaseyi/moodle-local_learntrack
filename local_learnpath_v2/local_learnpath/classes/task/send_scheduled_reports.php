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

        $now       = time();
        $schedules = $DB->get_records_select(
            'local_learnpath_schedules',
            'enabled = 1 AND nextrun <= :now',
            ['now' => $now]
        );

        foreach ($schedules as $schedule) {
            \mtrace("LearnTrack: Processing schedule [{$schedule->id}] for group {$schedule->groupid}");
            $recipients = array_filter(array_map('trim', explode(',', $schedule->recipients)));
            try {
                $ok = export_manager::email_report(
                    (int)$schedule->groupid,
                    $recipients,
                    $schedule->format,
                    $schedule->viewmode ?? 'summary',
                    \get_admin()->id
                );
                $schedule->lastrun = $now;
                $schedule->nextrun = self::calc_next_run($schedule->frequency, $now);
                $DB->update_record('local_learnpath_schedules', $schedule);
                \mtrace($ok ? "  ✓ Sent successfully." : "  ✗ Send failed.");
            } catch (\Throwable $e) {
                \mtrace("  ✗ Error: " . $e->getMessage());
            }
        }
    }

    public static function calc_next_run(string $frequency, int $from): int {
        return match($frequency) {
            'daily'   => strtotime('+1 day',   $from),
            'monthly' => strtotime('+1 month', $from),
            default   => strtotime('+1 week',  $from),
        };
    }
}
