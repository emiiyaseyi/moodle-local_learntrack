<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * LearnTrack export handler — serves Excel, CSV, and PDF downloads.
 *
 * @package   local_learnpath
 * @copyright 2025 Michael Adeniran
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
