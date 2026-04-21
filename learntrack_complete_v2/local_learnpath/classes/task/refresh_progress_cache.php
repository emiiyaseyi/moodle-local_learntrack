<?php
namespace local_learnpath\task;

defined('MOODLE_INTERNAL') || die();

use local_learnpath\data\helper as data_helper;

class refresh_progress_cache extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnTrack: Refresh progress cache';
    }

    public function execute(): void {
        global $DB;

        $groups = $DB->get_records('local_learnpath_groups', null, 'id ASC', 'id');
        foreach ($groups as $g) {
            try {
                data_helper::refresh_cache((int)$g->id);
                \mtrace("LearnTrack cache: refreshed group {$g->id}");
            } catch (\Throwable $e) {
                \mtrace("LearnTrack cache error group {$g->id}: " . $e->getMessage());
            }
        }
    }
}
