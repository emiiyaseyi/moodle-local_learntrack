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
 * LearnTrack — Reminders & Notifications
 * Manage reminder rules per path; send manually with channel selector.
 */
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/learnpath:manage', context_system::instance());

global $DB, $OUTPUT, $USER;

// groupid is OPTIONAL — rules are path-specific but the list page can show all
$groupid    = optional_param('groupid',    0,       PARAM_INT);
$action     = optional_param('action',     'list',  PARAM_ALPHANUMEXT);
$reminderid = optional_param('reminderid', 0,       PARAM_INT);
$tab        = optional_param('tab',        'rules', PARAM_ALPHA);

$group = ($groupid > 0) ? \local_learnpath\data\helper::get_group($groupid) : null;

// PAGE must be set BEFORE any action handlers that call redirect()
$PAGE->set_url(new moodle_url('/local/learnpath/reminders.php', ['groupid'=>$groupid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('page_title_reminders', 'local_learnpath'));

$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';

// ── ACTION HANDLERS (wrapped in try/catch to prevent fatal errors) ───────────
try {

// ── DELETE ──────────────────────────────────────────────────────────────────
if ($action === 'delete' && $reminderid && confirm_sesskey()) {
    $r = $DB->get_record('local_learnpath_reminders', ['id' => $reminderid]);
    if ($r) {
        $DB->delete_records('local_learnpath_reminder_log', ['reminderid' => $reminderid]);
        $DB->delete_records('local_learnpath_reminders',    ['id'         => $reminderid]);
    }
    redirect(
        new moodle_url('/local/learnpath/reminders.php', ['groupid' => $groupid]),
        'Reminder rule deleted.', null, \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── TOGGLE ENABLED/PAUSED ───────────────────────────────────────────────────
if ($action === 'toggle' && $reminderid && confirm_sesskey()) {
    $r = $DB->get_record('local_learnpath_reminders', ['id' => $reminderid]);
    if ($r) {
        $DB->update_record('local_learnpath_reminders',
            (object)['id' => $r->id, 'enabled' => $r->enabled ? 0 : 1]);
    }
    redirect(new moodle_url('/local/learnpath/reminders.php',
        ['groupid' => $groupid ?: ($r->groupid ?? 0)]));
}

// ── SAVE RULE ───────────────────────────────────────────────────────────────
if ($action === 'save' && confirm_sesskey()) {
    $save_gid = optional_param('save_groupid', $groupid, PARAM_INT);
    if (!$save_gid) {
        redirect(
            new moodle_url('/local/learnpath/reminders.php', ['groupid'=>0,'action'=>'add']),
            'Please select a learning path before saving.', null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $rec = (object)[
        'groupid'       => $save_gid,
        'name'          => required_param('name',      PARAM_TEXT),
        'target'        => required_param('target',    PARAM_ALPHA),
        'channel_email' => optional_param('channel_email', 0, PARAM_INT),
        'channel_inapp' => optional_param('channel_inapp', 0, PARAM_INT),
        'channel_sms'   => optional_param('channel_sms',   0, PARAM_INT),
        'subject'       => optional_param('subject',  '', PARAM_TEXT),
        'message'       => optional_param('message',  '', PARAM_TEXT),
        'frequency'     => required_param('frequency', PARAM_ALPHA),
        'enabled'       => 1,
        'nextrun'       => time(),
    ];
    $eid = optional_param('id', 0, PARAM_INT);
    if ($eid) {
        $rec->id = $eid;
        $DB->update_record('local_learnpath_reminders', $rec);
    } else {
        $rec->createdby   = $USER->id;
        $rec->timecreated = time();
        $DB->insert_record('local_learnpath_reminders', $rec);
    }
    redirect(
        new moodle_url('/local/learnpath/reminders.php', ['groupid' => $save_gid]),
        'Reminder rule saved.', null, \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── TRIGGER: show channel-selector page, then send ──────────────────────────
if ($action === 'trigger_send' && $reminderid && confirm_sesskey()) {
    $reminder     = $DB->get_record('local_learnpath_reminders', ['id' => $reminderid], '*', MUST_EXIST);
    $trig_groupid = (int)$reminder->groupid;
    $trig_group   = \local_learnpath\data\helper::get_group($trig_groupid);
    if (!$trig_group) {
        redirect(
            new moodle_url('/local/learnpath/reminders.php', ['groupid' => $groupid]),
            'Learning path not found.', null, \core\output\notification::NOTIFY_ERROR
        );
    }

    $reminder->channel_email = optional_param('ch_email', 0, PARAM_INT);
    $reminder->channel_inapp = optional_param('ch_inapp', 0, PARAM_INT);
    $reminder->channel_sms   = optional_param('ch_sms',   0, PARAM_INT);

    $allrows = \local_learnpath\data\helper::get_progress_detail($trig_groupid, 0);
    $by_user = [];
    foreach ($allrows as $row) { $by_user[$row->userid][] = $row; }

    $sent = 0;
    foreach ($by_user as $uid => $courses) {
        $completed = 0; $total = count($courses);
        foreach ($courses as $co) { if ($co->status === 'complete') { $completed++; } }
        $pct = $total > 0 ? (int)round($completed / $total * 100) : 0;
        $match = match($reminder->target) {
            'notstarted' => ($pct === 0),
            'inprogress' => ($pct > 0 && $pct < 100),
            'incomplete' => ($pct < 100),
            default      => false,
        };
        if (!$match) { continue; }
        $learner = $DB->get_record('user', ['id' => $uid, 'deleted' => 0]);
        if (!$learner) { continue; }
        try {
            \local_learnpath\notification\notifier::send_reminder($reminder, $learner, $trig_group, $courses);
        } catch (\Throwable $e_send) {
            debugging('LearnTrack: send_reminder failed for user ' . $uid . ': ' . $e_send->getMessage(), DEBUG_DEVELOPER);
        }
        $sent++;
    }

    $channels_used = implode('+', array_filter([
        $reminder->channel_email ? 'email' : '',
        $reminder->channel_inapp ? 'inapp' : '',
        $reminder->channel_sms   ? 'sms'   : '',
    ]));
    try {
        $DB->insert_record('local_learnpath_reminder_log', (object)[
            'reminderid' => $reminderid,
            'userid'     => 0,
            'timesent'   => time(),
            'channel'    => $channels_used ?: 'none',
            'status'     => $sent > 0 ? 'sent' : 'no_match',
        ]);
        $tbl = new xmldb_table('local_learnpath_reminders');
        $fld = new xmldb_field('lastrun');
        if ($DB->get_manager()->field_exists($tbl, $fld)) {
            $DB->set_field('local_learnpath_reminders', 'lastrun', time(), ['id' => $reminderid]);
        }
    } catch (\Throwable $e_log) {}

    $msg = $sent > 0
        ? "✅ Reminder sent to {$sent} learner(s) in \u{201c}" . format_string($trig_group->name) . "\u{201d}."
        : "⚠️ No learners matched the reminder criteria (target: {$reminder->target}).";
    $notify_type = $sent > 0
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_WARNING;

    redirect(
        new moodle_url('/local/learnpath/reminders.php', ['groupid' => $trig_groupid, 'tab' => 'history']),
        $msg, null, $notify_type
    );
}

// ── BULK REMIND from dashboard/overview ─────────────────────────────────────
if ($action === 'bulk_remind' && confirm_sesskey()) {
    $uids_raw = optional_param('userids', '', PARAM_TEXT);
    $uid_list = array_filter(array_map('intval', explode(',', $uids_raw)));
    $ch_email = optional_param('ch_email', 1, PARAM_INT);
    $ch_inapp = optional_param('ch_inapp', 1, PARAM_INT);
    $ch_sms   = optional_param('ch_sms',   0, PARAM_INT);

    $gcourses_bulk = $groupid > 0
        ? $DB->get_records('local_learnpath_group_courses', ['groupid' => $groupid])
        : [];
    $remind_group = $group ?: (object)[
        'id'   => 0,
        'name' => get_config('local_learnpath', 'brand_name') ?: 'LearnTrack',
        'deadline' => 0,
    ];
    $fake_reminder = (object)[
        'id'            => 0,
        'subject'       => get_config('local_learnpath', 'reminder_subject') ?: '',
        'message'       => get_config('local_learnpath', 'reminder_body')    ?: '',
        'channel_email' => $ch_email,
        'channel_inapp' => $ch_inapp,
        'channel_sms'   => $ch_sms,
    ];

    $sent = 0;
    foreach ($uid_list as $buid) {
        $learner = $DB->get_record('user', ['id' => $buid, 'deleted' => 0]);
        if (!$learner) continue;
        $course_list = [];
        foreach ($gcourses_bulk as $lgc) {
            $cctx = context_course::instance($lgc->courseid, IGNORE_MISSING);
            $course_list[] = (object)[
                'status' => ($cctx && is_enrolled($cctx, $buid)) ? 'enrolled' : 'notenrolled',
            ];
        }
        try {
            \local_learnpath\notification\notifier::send_reminder($fake_reminder, $learner, $remind_group, $course_list);
        } catch (\Throwable $e_bulk) {
            debugging('LearnTrack: bulk send_reminder failed for user ' . $buid . ': ' . $e_bulk->getMessage(), DEBUG_DEVELOPER);
        }
        $sent++;
    }
    $redir = $groupid > 0
        ? new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid, 'view' => 'summary'])
        : new moodle_url('/local/learnpath/overview.php');
    redirect($redir, "Reminder sent to {$sent} learner(s).",
        null, \core\output\notification::NOTIFY_SUCCESS);
}

} catch (\Throwable $e_action) {
    // Action failed — redirect back to list with error rather than showing a fatal Moodle error
    $err_msg = 'Reminder action failed: ' . $e_action->getMessage();
    debugging('LearnTrack reminders action error: ' . $e_action->getMessage(), DEBUG_DEVELOPER);
    redirect(
        new moodle_url('/local/learnpath/reminders.php', ['groupid' => $groupid]),
        $err_msg, null, \core\output\notification::NOTIFY_ERROR
    );
}

// ════════════════════════════════════════════════════════════════════
// RENDER
// ════════════════════════════════════════════════════════════════════
echo $OUTPUT->header();
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome',
    ['style' => 'display:inline-block;margin-bottom:10px;margin-right:12px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
echo html_writer::link(new moodle_url('/local/learnpath/manage.php'), '← Manage Paths',
    ['style' => 'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

$grp_name = $group ? format_string($group->name) : 'All Paths';
echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo '<h1 class="lt-page-title">🔔 Reminders &amp; Notifications</h1>';
echo '<p class="lt-page-subtitle">' . $grp_name . ' — Automate learner nudges via email, in-app &amp; SMS</p>';
echo '</div><div>';
echo html_writer::link(
    new moodle_url('/local/learnpath/reminders.php', ['groupid' => $groupid, 'action' => 'add']),
    '+ New Rule', ['class' => 'lt-btn lt-btn-primary']
);
echo '</div></div></div>';

// Tabs (only on list/rules view — not on add/edit/trigger forms)
if (!in_array($action, ['add','edit','trigger'])) {
    echo '<div style="display:flex;gap:6px;margin-bottom:16px;border-bottom:2px solid #e5e7eb;padding-bottom:0">';
    foreach (['rules' => '📋 Rules', 'history' => '📜 Send History'] as $t => $lbl) {
        $turl = new moodle_url('/local/learnpath/reminders.php', ['groupid'=>$groupid,'tab'=>$t]);
        $active = $tab === $t;
        echo html_writer::link($turl, $lbl, [
            'style' => 'font-family:var(--lt-font);font-size:.84rem;font-weight:700;padding:8px 16px;text-decoration:none;border-radius:8px 8px 0 0;'
                . ($active ? 'background:var(--lt-accent);color:#fff' : 'color:#374151')
        ]);
    }
    echo '</div>';
}

try {

// ── CHANNEL SELECTOR (shown when "Send Now" is clicked) ──────────────────────
if ($action === 'trigger' && $reminderid) {
    $r = $DB->get_record('local_learnpath_reminders', ['id' => $reminderid], '*', MUST_EXIST);
    $r_group = \local_learnpath\data\helper::get_group((int)$r->groupid);
    echo '<div class="lt-card" style="max-width:500px">';
    echo '<div class="lt-card-header"><h3 class="lt-card-title">📢 Send Reminder Now</h3></div>';
    echo '<div class="lt-card-body">';
    echo '<p style="font-family:var(--lt-font);font-size:.88rem;color:#374151;margin:0 0 14px">';
    echo 'Rule: <strong>' . s($r->name) . '</strong><br>';
    echo 'Path: <strong>' . ($r_group ? format_string($r_group->name) : 'Unknown') . '</strong><br>';
    echo 'Targets: <strong>' . ($r->target === 'notstarted' ? 'Not started (0%)' : ($r->target === 'inprogress' ? 'In progress' : 'Incomplete')) . '</strong>';
    echo '</p>';
    echo '<form method="post" action="' . (new moodle_url('/local/learnpath/reminders.php'))->out(false) . '">';
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey',    'value'=>sesskey()]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action',     'value'=>'trigger_send']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'reminderid', 'value'=>$reminderid]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'groupid',    'value'=>$groupid]);
    echo '<p style="font-family:var(--lt-font);font-size:.78rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin:0 0 10px">Select channels to send via:</p>';
    echo '<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px">';
    foreach ([
        ['ch_email', '✉️', 'Email',  $r->channel_email],
        ['ch_inapp', '🔔', 'In-App notification', $r->channel_inapp],
        ['ch_sms',   '📱', 'SMS',    $r->channel_sms],
    ] as [$nm, $ic, $lbl, $default]) {
        $chk = $default ? ' checked' : '';
        echo '<label style="display:flex;align-items:center;gap:10px;font-family:var(--lt-font);font-size:.88rem;cursor:pointer;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;background:#f9fafb">';
        echo '<input type="checkbox" name="' . $nm . '" value="1"' . $chk . ' style="width:16px;height:16px">';
        echo $ic . ' <strong>' . $lbl . '</strong>';
        echo '</label>';
    }
    echo '</div>';
    echo '<div style="display:flex;gap:10px">';
    echo '<button type="submit" class="lt-btn lt-btn-primary" onclick="this.disabled=true;this.textContent=\'Sending…\';this.form.submit()">📢 Send Now</button>';
    echo html_writer::link(
        new moodle_url('/local/learnpath/reminders.php', ['groupid' => $groupid]),
        'Cancel', ['class' => 'lt-btn lt-btn-ghost']
    );
    echo '</div>';
    echo '</form></div></div>';

// ── ADD / EDIT RULE FORM ─────────────────────────────────────────────────────
} elseif ($action === 'add' || $action === 'edit') {
    $editing = null;
    if ($action === 'edit' && $reminderid) {
        $editing = $DB->get_record('local_learnpath_reminders', ['id' => $reminderid]);
    }
    $e = $editing ?? (object)[
        'id' => 0, 'groupid' => $groupid, 'name' => '', 'target' => 'incomplete',
        'channel_email' => 1, 'channel_inapp' => 1, 'channel_sms' => 0,
        'subject' => '', 'message' => '', 'frequency' => 'once',
    ];

    $all_groups_r = $DB->get_records('local_learnpath_groups', null, 'name ASC');

    echo '<div class="lt-card"><div class="lt-card-body">';
    echo '<form method="post">';
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey', 'value'=>sesskey()]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action',  'value'=>'save']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'id',      'value'=>$e->id]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'groupid', 'value'=>$groupid]);

    // Path selector — ALWAYS shown
    $sel_gid = (int)($e->groupid ?: $groupid);
    echo '<div style="margin-bottom:16px;font-family:var(--lt-font)">';
    echo '<label style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">Learning Path <span style="color:#ef4444">*</span></label>';
    echo '<select name="save_groupid" required style="font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 12px;background:#f9fafb;outline:none;width:100%;max-width:420px">';
    echo '<option value="">-- Select a learning path --</option>';
    foreach ($all_groups_r as $agr) {
        $sel = ((int)$agr->id === $sel_gid) ? ' selected' : '';
        echo '<option value="' . $agr->id . '"' . $sel . '>' . format_string($agr->name) . '</option>';
    }
    echo '</select></div>';

    $inp = 'font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 12px;background:#f9fafb;outline:none;width:100%';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">';
    echo '<div><label style="font-family:var(--lt-font);font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">Rule Name</label>';
    echo '<input type="text" name="name" value="' . s($e->name) . '" style="' . $inp . '" placeholder="e.g. Weekly nudge for not started" required></div>';
    echo '<div><label style="font-family:var(--lt-font);font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">Target Learners</label>';
    echo '<select name="target" style="' . $inp . '">';
    foreach (['notstarted'=>'Not started (0%)','inprogress'=>'In progress (1–99%)','incomplete'=>'Incomplete (any not 100%)'] as $v=>$l) {
        echo '<option value="' . $v . '"' . ($e->target===$v?' selected':'') . '>' . $l . '</option>';
    }
    echo '</select></div></div>';

    echo '<div style="margin-bottom:14px"><label style="font-family:var(--lt-font);font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">Frequency</label>';
    echo '<select name="frequency" style="' . $inp . '" style="max-width:220px">';
    foreach (['once'=>'Once only','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly'] as $v=>$l) {
        echo '<option value="' . $v . '"' . ($e->frequency===$v?' selected':'') . '>' . $l . '</option>';
    }
    echo '</select></div>';

    echo '<div style="margin-bottom:14px"><label style="font-family:var(--lt-font);font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:8px">Default Channels</label>';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
    foreach ([['channel_email','✉️','Email'],['channel_inapp','🔔','In-App'],['channel_sms','📱','SMS']] as [$nm,$ic,$lbl]) {
        $chk = ($e->$nm ?? 0) ? ' checked' : '';
        echo '<label style="display:flex;align-items:center;gap:6px;font-family:var(--lt-font);font-size:.86rem;cursor:pointer;padding:7px 12px;border:1.5px solid #e5e7eb;border-radius:8px;background:#f9fafb">';
        echo '<input type="checkbox" name="' . $nm . '" value="1"' . $chk . '>' . $ic . ' ' . $lbl;
        echo '</label>';
    }
    echo '</div></div>';

    echo '<div style="margin-bottom:14px"><label style="font-family:var(--lt-font);font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">Custom Message <span style="font-weight:400;text-transform:none;color:#9ca3af">(optional — blank = default)</span></label>';
    echo '<textarea name="message" rows="4" style="' . $inp . ';resize:vertical" placeholder="Dear {{firstname}}, you have incomplete courses in {{groupname}}. Progress: {{progress}}. Continue: {{dashboardurl}}">' . s($e->message) . '</textarea>';
    echo '<div style="font-size:.72rem;color:#9ca3af;font-family:var(--lt-font);margin-top:3px">Variables: {{firstname}} {{fullname}} {{groupname}} {{progress}} {{completed}} {{total}} {{deadline}} {{dashboardurl}}</div></div>';

    echo '<div style="display:flex;gap:10px">';
    echo '<button type="submit" class="lt-btn lt-btn-primary">💾 Save Rule</button>';
    echo html_writer::link(
        new moodle_url('/local/learnpath/reminders.php', ['groupid' => $groupid]),
        'Cancel', ['class' => 'lt-btn lt-btn-ghost']
    );
    echo '</div></form></div></div>';

// ── HISTORY TAB ──────────────────────────────────────────────────────────────
} elseif ($tab === 'history') {
    $dbman_h = $DB->get_manager();
    if (!$dbman_h->table_exists(new xmldb_table('local_learnpath_reminder_log'))) {
        echo '<p style="font-family:var(--lt-font);color:#9ca3af;padding:24px">History table not available. Run upgrade (Site Admin → Notifications).</p>';
    } else {
        $hist_where = $groupid > 0
            ? "WHERE r.groupid = :gid"
            : "";
        $hist_params = $groupid > 0 ? ['gid' => $groupid] : [];
        $hist_logs = $DB->get_records_sql(
            "SELECT rl.*, r.name AS rulename, r.groupid AS rgroupid
             FROM {local_learnpath_reminder_log} rl
             LEFT JOIN {local_learnpath_reminders} r ON r.id=rl.reminderid
             $hist_where
             ORDER BY rl.timesent DESC
             LIMIT 200",
            $hist_params
        );

        echo '<div class="lt-card">';
        echo '<div class="lt-card-header"><h3 class="lt-card-title">📜 Full Send History</h3>';
        echo '<span style="font-size:.76rem;color:#9ca3af;font-family:var(--lt-font)">Last 200 sends</span></div>';
        echo '<div class="lt-card-body" style="padding:0">';

        if (empty($hist_logs)) {
            echo '<p style="font-family:var(--lt-font);color:#9ca3af;padding:24px 18px">No send history yet.</p>';
        } else {
            echo '<div style="overflow-x:auto"><table class="lt-data-table"><thead><tr>';
            foreach (['Date & Time','Rule','Channel','Status',''] as $h) { echo '<th>' . $h . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ($hist_logs as $log) {
                $ch_icon = str_contains($log->channel??'', 'email') ? '✉️' : (str_contains($log->channel??'', 'inapp') ? '🔔' : '📢');
                $st_ok   = $log->status === 'sent';
                echo '<tr>';
                echo '<td style="white-space:nowrap;font-size:.8rem">' . userdate($log->timesent, get_string('strftimedatetimeshort')) . '</td>';
                echo '<td style="font-size:.82rem;font-weight:700">' . s($log->rulename ?? 'Deleted rule') . '</td>';
                echo '<td>' . $ch_icon . ' <span style="font-size:.76rem;color:#6b7280">' . s($log->channel ?? '—') . '</span></td>';
                echo '<td><span style="background:' . ($st_ok?'#d1fae5':'#fee2e2') . ';color:' . ($st_ok?'#065f46':'#be123c') . ';font-weight:700;padding:2px 9px;border-radius:100px;font-size:.72rem">' . ucfirst($log->status ?? '?') . '</span></td>';
                // Link to the rule if it still exists
                if ($log->reminderid && $log->rulename) {
                    $rule_url = new moodle_url('/local/learnpath/reminders.php', ['groupid'=>$log->rgroupid??0,'action'=>'edit','reminderid'=>$log->reminderid]);
                    echo '<td>' . html_writer::link($rule_url, '✏️', ['style'=>'text-decoration:none','title'=>'Edit rule']) . '</td>';
                } else {
                    echo '<td></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    }

// ── RULES LIST ───────────────────────────────────────────────────────────────
} else {
    // Show rules for this path, or ALL rules if groupid=0
    if ($groupid > 0) {
        $reminders = $DB->get_records('local_learnpath_reminders', ['groupid' => $groupid], 'timecreated DESC');
    } else {
        $reminders = $DB->get_records('local_learnpath_reminders', null, 'timecreated DESC');
    }

    $tarbg  = ['notstarted'=>'#f3f4f6','inprogress'=>'#fef3c7','incomplete'=>'#fee2e2'];
    $tarlbl = ['notstarted'=>'⭕ Not Started','inprogress'=>'⏳ In Progress','incomplete'=>'🔴 Incomplete'];
    $freqlbl= ['once'=>'Once','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly'];
    $dbman_r = $DB->get_manager();
    $has_log_table = $dbman_r->table_exists(new xmldb_table('local_learnpath_reminder_log'));

    if (empty($reminders)) {
        echo '<div class="lt-empty-state">';
        echo '<div class="lt-empty-icon">🔔</div>';
        echo '<h3 class="lt-empty-title">No Reminder Rules Yet</h3>';
        echo '<p class="lt-empty-desc">Create a rule to automatically notify learners who haven\'t started or completed their path.</p>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:12px">';
        echo html_writer::link(
            new moodle_url('/local/learnpath/reminders.php', ['groupid' => $groupid, 'action' => 'add']),
            '+ Create First Rule', ['class' => 'lt-btn lt-btn-primary']
        );
        echo '</div></div>';
    } else {
        foreach ($reminders as $r) {
            $r_group_name = '';
            if ((int)$r->groupid !== $groupid) {
                $rg = \local_learnpath\data\helper::get_group((int)$r->groupid);
                $r_group_name = $rg ? ' · 📚 ' . format_string($rg->name) : '';
            }
            $bg     = $tarbg[$r->target] ?? '#f3f4f6';
            // Trigger goes to channel selector, not directly to send
            $turl   = new moodle_url('/local/learnpath/reminders.php', [
                'groupid'    => $groupid,
                'action'     => 'trigger',
                'reminderid' => $r->id,
            ]);
            $eurl   = new moodle_url('/local/learnpath/reminders.php', ['groupid'=>$groupid,'action'=>'edit',  'reminderid'=>$r->id]);
            $durl   = new moodle_url('/local/learnpath/reminders.php', ['groupid'=>$groupid,'action'=>'delete','reminderid'=>$r->id,'sesskey'=>sesskey()]);
            $togurl = new moodle_url('/local/learnpath/reminders.php', ['groupid'=>$groupid,'action'=>'toggle','reminderid'=>$r->id,'sesskey'=>sesskey()]);

            $logcnt = $has_log_table
                ? $DB->count_records('local_learnpath_reminder_log', ['reminderid' => $r->id])
                : 0;

            echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;margin-bottom:10px;box-shadow:0 1px 3px rgba(0,0,0,.05);font-family:var(--lt-font)">';
            echo '<div style="display:flex;align-items:center;gap:13px;flex-wrap:wrap">';
            echo '<div style="width:42px;height:42px;border-radius:10px;background:' . $bg . ';display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">🔔</div>';
            echo '<div style="flex:1">';
            echo '<p style="font-size:.9rem;font-weight:700;color:#111827;margin:0 0 3px">' . s($r->name);
            echo '&nbsp;' . ($r->enabled
                ? '<span style="background:#d1fae5;color:#065f46;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">Active</span>'
                : '<span style="background:#f3f4f6;color:#9ca3af;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">Paused</span>');
            echo $r_group_name ? '<span style="font-size:.74rem;color:#6b7280;font-weight:400">' . $r_group_name . '</span>' : '';
            echo '</p>';
            echo '<p style="font-size:.74rem;color:#6b7280;margin:0">';
            echo ($tarlbl[$r->target] ?? $r->target) . ' · ' . ($freqlbl[$r->frequency] ?? $r->frequency);
            if ($r->channel_email) echo ' &nbsp;<span style="background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:100px;font-size:.68rem;font-weight:700">✉️ Email</span>';
            if ($r->channel_inapp) echo ' <span style="background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:100px;font-size:.68rem;font-weight:700">🔔 In-App</span>';
            if ($r->channel_sms)   echo ' <span style="background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:100px;font-size:.68rem;font-weight:700">📱 SMS</span>';
            echo ' · <strong>' . $logcnt . '</strong> sent';
            if (isset($r->lastrun) && $r->lastrun) {
                echo ' · Last: ' . userdate($r->lastrun, get_string('strftimedatefullshort'));
            }
            echo '</p></div>';
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap">';
            echo html_writer::link($turl, '▶ Send Now', [
                'class' => 'lt-action-btn lt-btn-view',
                'title' => 'Choose channels and send this reminder now',
            ]);
            echo html_writer::link($togurl, $r->enabled ? '⏸ Pause' : '▶ Resume', ['class' => 'lt-action-btn lt-btn-edit', 'onclick' => "return confirm('Toggle this reminder rule?')"]);
            echo html_writer::link($eurl, '✏️ Edit',   ['class' => 'lt-action-btn lt-btn-edit']);
            echo html_writer::link($durl, '🗑',        ['class' => 'lt-action-btn lt-btn-del', 'onclick' => "return confirm('Delete this rule?')"]);
            echo '</div></div>';

            // Send history
            if ($logcnt > 0 && $has_log_table) {
                $logs = $DB->get_records('local_learnpath_reminder_log',
                    ['reminderid' => $r->id], 'timesent DESC', '*', 0, 5);
                echo '<div style="margin-top:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px">';
                echo '<div style="font-size:.72rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px">📋 Send History (' . $logcnt . ' total)</div>';
                foreach ($logs as $log) {
                    $ch_icon = str_contains($log->channel, 'email') ? '✉️' : (str_contains($log->channel, 'inapp') ? '🔔' : '📢');
                    $st_ok   = $log->status === 'sent';
                    echo '<div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:.76rem;color:#374151">';
                    echo '<span>' . $ch_icon . '</span>';
                    echo '<span style="flex:1">' . userdate($log->timesent, get_string('strftimedatetimeshort')) . '</span>';
                    echo '<span style="color:#6b7280;font-size:.7rem">' . s($log->channel) . '</span>';
                    echo '<span style="background:' . ($st_ok?'#d1fae5':'#fee2e2') . ';color:' . ($st_ok?'#065f46':'#be123c') . ';font-weight:700;padding:1px 7px;border-radius:100px;font-size:.68rem">' . ucfirst($log->status) . '</span>';
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</div>'; // rule card
        }
    }
}

} catch (\Throwable $e_main) {
    echo '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:14px 18px;font-family:var(--lt-font);color:#991b1b">Error: ' . s($e_main->getMessage()) . '</div>';
}

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'
    . html_writer::link('https://www.linkedin.com/in/michaeladeniran', 'LinkedIn', ['target'=>'_blank'])
    . '<span class="lt-sep">·</span><span>LearnTrack v1.0.0</span></div>';
echo $OUTPUT->footer();
