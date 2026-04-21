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
 * Scheduled reports management page.
 *
 * @package    local_learnpath
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;
use local_learnpath\form\schedule_form;
use local_learnpath\task\send_scheduled_reports;

require_login();
require_capability('local/learnpath:emailreport', context_system::instance());

$groupid    = optional_param('groupid',    0,       PARAM_INT);
$action     = optional_param('action',     'list',  PARAM_ALPHA);
$scheduleid = optional_param('scheduleid', 0,       PARAM_INT);

$group = $groupid > 0 ? DH::get_group($groupid) : null;

$PAGE->set_url(new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid, 'action' => $action]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('manage_schedules', 'local_learnpath'));
$PAGE->set_heading(get_string('pluginname', 'local_learnpath'));

global $DB, $OUTPUT, $USER;

// ── Brand colour config ───────────────────────────────────────────────────────
$brand_cfg = [
    'brand_color'   => get_config('local_learnpath', 'brand_color') ?: '#1e3a5f',
    'font_size'     => (int)(get_config('local_learnpath', 'font_size') ?: 13),
    'high_contrast' => (bool)get_config('local_learnpath', 'high_contrast'),
    'reduce_motion' => (bool)get_config('local_learnpath', 'reduce_motion'),
    'large_text'    => (bool)get_config('local_learnpath', 'large_text'),
];

// ── Action handlers ───────────────────────────────────────────────────────────
if ($action === 'delete' && $scheduleid && confirm_sesskey()) {
    $DB->delete_records('local_learnpath_schedules', ['id' => $scheduleid, 'groupid' => $groupid]);
    redirect(
        new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid]),
        get_string('schedule_deleted', 'local_learnpath'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'toggle' && $scheduleid && confirm_sesskey()) {
    $s = $DB->get_record('local_learnpath_schedules', ['id' => $scheduleid, 'groupid' => $groupid]);
    if ($s) {
        $DB->update_record('local_learnpath_schedules', (object)['id' => $s->id, 'enabled' => $s->enabled ? 0 : 1]);
    }
    redirect(new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid]));
}

// ── Add / Edit form ───────────────────────────────────────────────────────────
if ($action === 'add' || ($action === 'edit' && $scheduleid)) {
    $customdata = ['groupid' => $groupid, 'id' => 0];
    if ($scheduleid) {
        $s = $DB->get_record('local_learnpath_schedules', ['id' => $scheduleid, 'groupid' => $groupid], '*', MUST_EXIST);
        $customdata = array_merge($customdata, (array)$s);
    }
    $form = new schedule_form($PAGE->url, $customdata);

    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid]));
    }

    if ($data = $form->get_data()) {
        $rec = (object)[
            'groupid'    => $groupid,
            'recipients' => trim($data->recipients),
            'frequency'  => $data->frequency,
            'format'     => $data->format,
            'viewmode'   => $data->viewmode ?? 'summary',
            'enabled'    => !empty($data->enabled) ? 1 : 0,
        ];
        if (!empty($data->id)) {
            $rec->id = $data->id;
            $DB->update_record('local_learnpath_schedules', $rec);
        } else {
            $rec->createdby   = $USER->id;
            $rec->timecreated = time();
            $rec->nextrun     = send_scheduled_reports::calc_next_run($data->frequency, time());
            $DB->insert_record('local_learnpath_schedules', $rec);
        }
        redirect(
            new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid]),
            get_string('schedule_saved', 'local_learnpath'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_learnpath/dynamic_styles', $brand_cfg);
    echo $OUTPUT->render_from_template('local_learnpath/page_nav', [
        'back_url'   => (new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid]))->out(false),
        'back_label' => '← ' . get_string('manage_schedules', 'local_learnpath'),
    ]);

    // If no group is selected yet, show a path-selector card and exit.
    if (!$group) {
        $all_groups = $DB->get_records('local_learnpath_groups', null, 'name ASC');
        $group_items = [];
        foreach ($all_groups as $sg) {
            $group_items[] = ['id' => $sg->id, 'name' => format_string($sg->name), 'selected' => false];
        }
        echo $OUTPUT->render_from_template('local_learnpath/path_selector', [
            'redirect_base' => (new moodle_url('/local/learnpath/schedule.php', ['action' => 'add', 'groupid' => '']))->out(false),
            'selected_id'   => 0,
            'groups'        => $group_items,
        ]);
        echo $OUTPUT->footer();
        exit;
    }

    echo '<div class="lt-page-header"><h1 class="lt-page-title">' .
        ($scheduleid ? get_string('edit_schedule', 'local_learnpath') : get_string('add_schedule', 'local_learnpath')) .
        '</h1><p class="lt-page-subtitle">' . format_string($group->name) . '</p></div>';
    echo '<div class="lt-card lt-form-card">';
    $form->set_data($customdata);
    $form->display();
    echo '</div>';

    $PAGE->requires->js_call_amd('local_learnpath/learntrack_init', 'init');
    echo $OUTPUT->footer();
    exit;
}

// ── List page ─────────────────────────────────────────────────────────────────
$all_groups = $DB->get_records('local_learnpath_groups', null, 'name ASC');
$group_items = [];
foreach ($all_groups as $sg) {
    $group_items[] = [
        'id'       => $sg->id,
        'name'     => format_string($sg->name),
        'selected' => ($sg->id == $groupid),
    ];
}

$schedules_raw = $groupid > 0 ? $DB->get_records('local_learnpath_schedules', ['groupid' => $groupid]) : [];
$freq_icons = ['daily' => '⚡', 'weekly' => '📆', 'monthly' => '🗓️'];
$freq_bg    = ['daily' => '#fee2e2', 'weekly' => '#dbeafe', 'monthly' => '#d1fae5'];

$schedule_items = [];
foreach ($schedules_raw as $s) {
    $freq = $s->frequency ?? 'weekly';
    $schedule_items[] = [
        'freq_icon'    => $freq_icons[$freq] ?? '📅',
        'freq_bg'      => $freq_bg[$freq] ?? '#f3f4f6',
        'freq_label'   => ucfirst($freq),
        'format_label' => strtoupper($s->format ?? 'xlsx'),
        'is_active'    => (bool)$s->enabled,
        'recipients'   => s($s->recipients),
        'next_run'     => userdate($s->nextrun, get_string('strftimedatefullshort')),
        'has_last_run' => !empty($s->lastrun),
        'last_run'     => !empty($s->lastrun) ? userdate($s->lastrun, get_string('strftimedatefullshort')) : '',
        'toggle_url'   => (new moodle_url('/local/learnpath/schedule.php', [
            'groupid' => $groupid, 'action' => 'toggle', 'scheduleid' => $s->id, 'sesskey' => sesskey(),
        ]))->out(false),
        'toggle_label' => $s->enabled ? '⏸ Pause' : '▶ Resume',
        'edit_url'     => (new moodle_url('/local/learnpath/schedule.php', [
            'groupid' => $groupid, 'action' => 'edit', 'scheduleid' => $s->id,
        ]))->out(false),
        'delete_url'   => (new moodle_url('/local/learnpath/schedule.php', [
            'groupid' => $groupid, 'action' => 'delete', 'scheduleid' => $s->id, 'sesskey' => sesskey(),
        ]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_learnpath/dynamic_styles', $brand_cfg);

echo $OUTPUT->render_from_template('local_learnpath/page_nav', [
    'home_url'   => (new moodle_url('/local/learnpath/welcome.php'))->out(false),
    'back_url'   => (new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid]))->out(false),
    'back_label' => '← ' . get_string('back_to_dashboard', 'local_learnpath'),
]);

echo $OUTPUT->render_from_template('local_learnpath/schedule_list', [
    'groupid'       => $groupid,
    'group_name'    => $group ? format_string($group->name) : '',
    'has_group'     => (bool)$group,
    'add_url'       => (new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid, 'action' => 'add']))->out(false),
    'redirect_base' => (new moodle_url('/local/learnpath/schedule.php', ['groupid' => '']))->out(false),
    'selected_id'   => $groupid,
    'groups'        => $group_items,
    'has_schedules' => !empty($schedule_items),
    'schedules'     => $schedule_items,
]);

echo $OUTPUT->render_from_template('local_learnpath/footer', [
    'author'       => 'Michael Adeniran',
    'linkedin_url' => 'https://www.linkedin.com/in/michaeladeniran',
    'version'      => 'v2.0.0',
]);

$PAGE->requires->js_call_amd('local_learnpath/learntrack_init', 'init');
echo $OUTPUT->footer();
