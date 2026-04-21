<?php
require_once(__DIR__ . '/../../config.php');
use local_learnpath\export\manager as export_manager;

require_login();
require_sesskey();
require_capability('local/learnpath:export', context_system::instance());

$groupid     = optional_param('groupid',     0,        PARAM_INT);
$courseid    = optional_param('courseid',    0,        PARAM_INT);
$format      = required_param('format',      PARAM_ALPHA);
$viewmode    = optional_param('view',        'summary', PARAM_ALPHA);
$user_status = optional_param('user_status', 'active',  PARAM_ALPHA);
$from_ts     = optional_param('from_ts',     0,         PARAM_INT);
$to_ts       = optional_param('to_ts',       0,         PARAM_INT);
$date_range  = optional_param('date_range',  'all',     PARAM_ALPHA);

if (!in_array($format, ['xlsx', 'csv', 'pdf'])) {
    throw new moodle_exception('invalidformat', 'local_learnpath');
}

if ($courseid > 0) {
    // Course Insights export
    export_manager::export_course($courseid, $format, $date_range, $USER->id);
} else {
    export_manager::export($groupid, $format, $viewmode, $USER->id, $user_status, $from_ts, $to_ts);
}
