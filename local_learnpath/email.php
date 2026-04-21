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
 * Send report by email page.
 *
 * @package    local_learnpath
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;
use local_learnpath\export\manager as EM;

require_login();
require_capability('local/learnpath:emailreport', context_system::instance());

$groupid = required_param('groupid', PARAM_INT);
$group   = DH::get_group($groupid);
if (!$group) {
    throw new moodle_exception('invalidgroup', 'local_learnpath');
}

$PAGE->set_url(new moodle_url('/local/learnpath/email.php', ['groupid' => $groupid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('send_email_now', 'local_learnpath'));
$PAGE->set_heading(get_string('pluginname', 'local_learnpath'));

global $OUTPUT, $USER, $DB;

$error = '';
$sent  = optional_param('sent', 0, PARAM_INT);

if (optional_param('send', 0, PARAM_INT) && confirm_sesskey()) {
    $recipients = optional_param('recipients', '', PARAM_TEXT);
    $format     = optional_param('format', 'xlsx', PARAM_ALPHA);
    $viewmode   = optional_param('viewmode', 'summary', PARAM_ALPHA);
    $rcptlist   = array_filter(array_map('trim', explode(',', $recipients)));

    if (empty($rcptlist)) {
        $error = 'Please enter at least one valid email address.';
    } else {
        $ok = EM::email_report($groupid, $rcptlist, $format, $viewmode, $USER->id);
        if ($ok) {
            redirect(
                new moodle_url('/local/learnpath/email.php', ['groupid' => $groupid, 'sent' => 1])
            );
        } else {
            $error = 'One or more emails could not be sent. Check server mail settings.';
        }
    }
}

// ── Build email log history ───────────────────────────────────────────────────
$log_rows = $DB->get_records_sql(
    'SELECT el.*, u.firstname, u.lastname
       FROM {local_learnpath_email_log} el
  LEFT JOIN {user} u ON u.id = el.senderid
      WHERE el.groupid = :gid
   ORDER BY el.timesent DESC
      LIMIT 20',
    ['gid' => $groupid]
);

$history = [];
foreach ($log_rows as $lr) {
    $fmt = strtolower($lr->format ?? 'xlsx');
    $fmt_class = ($fmt === 'pdf') ? 'pdf' : (($fmt === 'csv') ? 'success' : 'info');
    $history[] = [
        'date'        => userdate($lr->timesent, get_string('strftimedatetimeshort')),
        'sender'      => s($lr->firstname . ' ' . $lr->lastname),
        'recipients'  => s($lr->recipients),
        'format'      => strtoupper($fmt),
        'fmt_class'   => $fmt_class,
        'viewmode'    => ucfirst($lr->viewmode ?? 'summary'),
        'recordcount' => (int)($lr->recordcount ?? 0),
    ];
}

// ── Brand colour CSS ──────────────────────────────────────────────────────────
$brand_cfg = [
    'brand_color'   => get_config('local_learnpath', 'brand_color') ?: '#1e3a5f',
    'font_size'     => (int)(get_config('local_learnpath', 'font_size') ?: 13),
    'high_contrast' => (bool)get_config('local_learnpath', 'high_contrast'),
    'reduce_motion' => (bool)get_config('local_learnpath', 'reduce_motion'),
    'large_text'    => (bool)get_config('local_learnpath', 'large_text'),
];

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_learnpath/dynamic_styles', $brand_cfg);

// Notifications
if ($sent) {
    echo $OUTPUT->render_from_template('local_learnpath/notification', [
        'type_success' => true,
        'message'      => get_string('email_sent_success', 'local_learnpath'),
    ]);
}
if ($error) {
    echo $OUTPUT->render_from_template('local_learnpath/notification', [
        'type_error' => true,
        'message'    => $error,
    ]);
}

// Back nav
echo $OUTPUT->render_from_template('local_learnpath/page_nav', [
    'back_url'   => (new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid]))->out(false),
    'back_label' => '← ' . get_string('back_to_dashboard', 'local_learnpath'),
]);

// Main page content
echo $OUTPUT->render_from_template('local_learnpath/email_page', [
    'groupid'      => $groupid,
    'group_name'   => format_string($group->name),
    'sesskey'      => sesskey(),
    'schedule_url' => (new moodle_url('/local/learnpath/schedule.php', ['groupid' => $groupid]))->out(false),
    'has_history'  => !empty($history),
    'history_rows' => array_values($history),
]);

// Footer
echo $OUTPUT->render_from_template('local_learnpath/footer', [
    'author'        => 'Michael Adeniran',
    'linkedin_url'  => 'https://www.linkedin.com/in/michaeladeniran',
    'version'       => 'v2.0.0',
]);

// AMD init
$PAGE->requires->js_call_amd('local_learnpath/learntrack_init', 'init');

echo $OUTPUT->footer();
