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
 * LearnTrack — Manage Learning Paths
 * Admin creates, edits, and deletes learning path groups.
 */
require_once(__DIR__ . '/../../config.php');

use local_learnpath\data\helper as DH;
use local_learnpath\form\group_form;

require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:manage', $ctx);

$action  = optional_param('action',  'list', PARAM_ALPHA);
$groupid = optional_param('groupid', 0,      PARAM_INT);
$debug   = optional_param('debug',   0,      PARAM_INT);

// Always include action+groupid so the form can POST back correctly.
$PAGE->set_url(new moodle_url('/local/learnpath/manage.php', [
    'action'  => $action,
    'groupid' => $groupid,
]));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('manage_paths', 'local_learnpath'));
$PAGE->set_heading(get_string('pluginname', 'local_learnpath'));

global $DB, $USER, $OUTPUT;

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'revoke_manager' && $groupid > 0 && confirm_sesskey()) {
    $revoke_uid = required_param('userid', PARAM_INT);
    $DB->delete_records('local_learnpath_managers', ['groupid' => $groupid, 'userid' => $revoke_uid]);
    redirect(new moodle_url('/local/learnpath/manage.php'),
        'Manager access revoked.',null,\core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $groupid > 0 && confirm_sesskey()) {
    $DB->delete_records('local_learnpath_group_courses',   ['groupid' => $groupid]);
    $DB->delete_records('local_learnpath_schedules',       ['groupid' => $groupid]);
    $DB->delete_records('local_learnpath_reminders',       ['groupid' => $groupid]);
    $DB->delete_records('local_learnpath_certs',           ['groupid' => $groupid]);
    $DB->delete_records('local_learnpath_notes',           ['groupid' => $groupid]);
    $DB->delete_records('local_learnpath_managers',        ['groupid' => $groupid]);
    $DB->delete_records('local_learnpath_progress_cache',  ['groupid' => $groupid]);
    $DB->delete_records('local_learnpath_groups',          ['id'      => $groupid]);
    redirect(
        new moodle_url('/local/learnpath/manage.php'),
        get_string('group_deleted', 'local_learnpath'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── ADD / EDIT FORM ───────────────────────────────────────────────────────────
if ($action === 'add' || ($action === 'edit' && $groupid > 0)) {

    $formdata = (object)['id' => 0];
    if ($action === 'edit' && $groupid > 0) {
        $formdata = $DB->get_record('local_learnpath_groups', ['id' => $groupid], '*', MUST_EXIST);
        $formdata->courseids = array_values(
            $DB->get_fieldset_select('local_learnpath_group_courses', 'courseid', 'groupid = ?', [$groupid])
        );
        // Load existing participant selections
        $dbman_edit = $DB->get_manager();
        if ($dbman_edit->table_exists(new xmldb_table('local_learnpath_user_assign'))) {
            $formdata->participant_userids = array_values(
                $DB->get_fieldset_select('local_learnpath_user_assign', 'userid', 'groupid = ?', [$groupid])
            );
        } else {
            $formdata->participant_userids = [];
        }
        // Load existing managers
        $formdata->manager_userids = implode(', ',
            $DB->get_fieldset_select('local_learnpath_managers', 'userid', 'groupid = ?', [$groupid])
        );
    }

    // THE FIX: pass $PAGE->url (moodle_url object) — converts action+groupid to hidden fields
    $form = new group_form($PAGE->url);
    $form->set_data($formdata);

    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/learnpath/manage.php'));

    } elseif ($data = $form->get_data()) {
        $now = time();

        // Duplicate name check (case-insensitive) — redirect with error if duplicate
        $dup = $DB->get_record_sql(
            "SELECT id FROM {local_learnpath_groups} WHERE "
            . $DB->sql_like('name', ':dname', false) . " AND id <> :deid",
            ['dname' => trim($data->name), 'deid' => (int)($data->id ?? 0)]
        );
        if ($dup) {
            redirect(
                new moodle_url('/local/learnpath/manage.php', ['action' => empty($data->id) ? 'add' : 'edit', 'groupid' => (int)($data->id ?? 0)]),
                '⚠️ A path named "' . s(trim($data->name)) . '" already exists. Please choose a unique name.',
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        if (!empty($data->id)) {
            // UPDATE
            $DB->update_record('local_learnpath_groups', (object)[
                'id'           => (int)$data->id,
                'name'         => trim($data->name),
                'description'  => (string)($data->description ?? ''),
                'grouptype'    => $data->grouptype,
                'categoryid'   => !empty($data->categoryid) ? (int)$data->categoryid : null,
                'cohortid'     => !empty($data->cohortid)   ? (int)$data->cohortid   : null,
                'deadline'     => !empty($data->deadline)   ? (int)$data->deadline   : null,
                'adminnotes'   => (string)($data->adminnotes ?? ''),
                'timemodified' => $now,
            ]);
            $savedid = (int)$data->id;
            $DB->delete_records('local_learnpath_group_courses', ['groupid' => $savedid]);
        } else {
            // INSERT — capture returned ID
            $savedid = (int)$DB->insert_record('local_learnpath_groups', (object)[
                'name'         => trim($data->name),
                'description'  => (string)($data->description ?? ''),
                'grouptype'    => $data->grouptype,
                'categoryid'   => !empty($data->categoryid) ? (int)$data->categoryid : null,
                'cohortid'     => !empty($data->cohortid)   ? (int)$data->cohortid   : null,
                'deadline'     => !empty($data->deadline)   ? (int)$data->deadline   : null,
                'adminnotes'   => (string)($data->adminnotes ?? ''),
                'createdby'    => (int)$USER->id,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        // Resolve course IDs by type
        $courseids = [];
        if ($data->grouptype === 'manual') {
            $raw = $data->courseids ?? [];
            if (!is_array($raw)) {
                $raw = array_filter(array_map('trim', explode(',', (string)$raw)));
            }
            $courseids = array_values(array_filter(array_map('intval', $raw), function ($v) { return $v > 0; }));
        } elseif ($data->grouptype === 'category' && !empty($data->categoryid)) {
            $courseids = array_keys($DB->get_records('course', ['category' => (int)$data->categoryid], '', 'id'));
        } elseif ($data->grouptype === 'cohort' && !empty($data->cohortid)) {
            // Moodle cohort enrolment: {enrol}.enrol='cohort' and {enrol}.customint1=cohortid
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT e.courseid FROM {enrol} e
                 WHERE e.enrol = 'cohort' AND e.customint1 = :cid AND e.status = 0",
                ['cid' => (int)$data->cohortid]
            );
            $courseids = array_column($rows, 'courseid');
        }

        $sort = 0;
        foreach ($courseids as $cid) {
            if ((int)$cid <= 0) { continue; }
            $DB->insert_record('local_learnpath_group_courses', (object)[
                'groupid'   => $savedid,
                'courseid'  => (int)$cid,
                'sortorder' => $sort++,
            ]);
        }

        // Save manually selected participants to user_assign table
        $dbman = $DB->get_manager();
        if ($dbman->table_exists(new xmldb_table('local_learnpath_user_assign'))) {
            $DB->delete_records('local_learnpath_user_assign', ['groupid' => $savedid]);
            $raw_users = $data->participant_userids ?? [];
            if (!is_array($raw_users)) {
                $raw_users = array_filter(array_map('trim', explode(',', (string)$raw_users)));
            }
            $pnow = time();
            foreach ($raw_users as $puid) {
                $puid = (int)$puid;
                if ($puid <= 0) { continue; }
                if (!$DB->record_exists('user', ['id' => $puid, 'deleted' => 0])) { continue; }
                $DB->insert_record('local_learnpath_user_assign', (object)[
                    'groupid'     => $savedid,
                    'userid'      => $puid,
                    'assignedby'  => $USER->id,
                    'timecreated' => $pnow,
                ]);
            }
        }

        // Save path managers
        $DB->delete_records('local_learnpath_managers', ['groupid' => $savedid]);
        $raw_mgrs = $data->manager_userids ?? [];
        if (!is_array($raw_mgrs)) {
            $raw_mgrs = array_filter(array_map('trim', explode(',', (string)$raw_mgrs)));
        }
        foreach ($raw_mgrs as $muid) {
            $muid = (int)$muid;
            if ($muid <= 0) continue;
            if (!$DB->record_exists('user', ['id' => $muid, 'deleted' => 0])) continue;
            $mscope = optional_param('manager_scope_' . $muid, 'all', PARAM_ALPHA);
            if (!in_array($mscope, ['all','cohort','courses'])) $mscope = 'all';
            if (!$DB->record_exists('local_learnpath_managers', ['groupid'=>$savedid,'userid'=>$muid])) {
                $DB->insert_record('local_learnpath_managers', (object)[
                    'groupid' => $savedid,
                    'userid'  => $muid,
                    'scope'   => $mscope,
                ]);
            }
        }

        redirect(
            new moodle_url('/local/learnpath/manage.php'),
            get_string('group_saved', 'local_learnpath') . ' (ID: ' . $savedid . ', ' . count($courseids) . ' courses)',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );

    }

    // Display form (add/edit)
    echo $OUTPUT->header();
    echo local_learnpath_page_header(
        $action === 'edit' ? get_string('edit_group', 'local_learnpath') : get_string('add_group', 'local_learnpath'),
        '',
        new moodle_url('/local/learnpath/manage.php'),
        true
    );
    echo html_writer::start_div('lt-card lt-form-card');
    $form->display();
    echo html_writer::end_div();
    echo local_learnpath_footer();
    echo $OUTPUT->footer();
    exit;
}

function local_learnpath_render_path_managers(int $groupid, int $ownerid): string {
global $DB;
$owner = $DB->get_record('user', ['id' => $ownerid, 'deleted' => 0]);
$html  = '';
if ($owner) {
    $html .= '<div style="font-size:.76rem;color:#374151;font-weight:700;margin-bottom:3px">👤 ' . htmlspecialchars(fullname($owner)) . '</div>';
}
$managers = $DB->get_records_sql(
    'SELECT lpm.userid, lpm.scope, u.firstname, u.lastname
     FROM {local_learnpath_managers} lpm
     JOIN {user} u ON u.id=lpm.userid AND u.deleted=0
     WHERE lpm.groupid=:gid',
    ['gid' => $groupid]
);
foreach ($managers as $m) {
    $revoke_url = new moodle_url('/local/learnpath/manage.php', [
        'action'  => 'revoke_manager',
        'groupid' => $groupid,
        'userid'  => $m->userid,
        'sesskey' => sesskey(),
    ]);
    $html .= '<div style="display:flex;align-items:center;gap:4px;margin-top:2px">';
    $html .= '<span style="font-size:.72rem;color:#6b7280">' . htmlspecialchars($m->firstname . ' ' . $m->lastname) . '</span>';
    $html .= '<span style="font-size:.64rem;background:#eff6ff;color:#1e40af;padding:1px 5px;border-radius:4px">' . htmlspecialchars($m->scope) . '</span>';
    $html .= html_writer::link($revoke_url,
        '<span style="color:#be123c;font-size:.7rem" title="Revoke access">✕</span>',
        ['onclick' => "return confirm('Revoke manager access for this user?')"]);
    $html .= '</div>';
}
if (empty($managers)) {
    $html .= '<div style="font-size:.72rem;color:#9ca3af">No managers</div>';
}
return $html;
}


// ── LIST ──────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome', ['style' => 'display:inline-block;margin-bottom:10px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
try {
echo local_learnpath_page_header(
    get_string('manage_paths', 'local_learnpath'),
    get_string('manage_paths_subtitle', 'local_learnpath'),
    new moodle_url('/local/learnpath/overview.php'),
    true
);

echo html_writer::div(
    '🔒 &nbsp;<strong>' . get_string('admin_only_notice', 'local_learnpath') . '</strong>'
    . ' — ' . get_string('admin_only_notice_desc', 'local_learnpath'),
    'lt-admin-badge'
);

$addurl = new moodle_url('/local/learnpath/manage.php', ['action' => 'add']);

echo html_writer::start_div('', ['style' => 'display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px']);
echo html_writer::link($addurl, '+ ' . get_string('add_group', 'local_learnpath'), ['class' => 'lt-btn lt-btn-primary']);
echo html_writer::link(new moodle_url('/local/learnpath/managers.php'), '👥 Managers & Access', ['class' => 'lt-btn lt-btn-outline']);
echo html_writer::end_div();

// Debug panel — activated via ?debug=1 (linked from Welcome > Diagnostics)
if ($debug) {
    echo '<div style="background:#0f172a;border-radius:12px;padding:16px;margin-bottom:16px;font-family:monospace;font-size:.82rem">';
    echo '<p style="font-weight:700;color:#a3e635;margin:0 0 10px">🩺 DB Diagnostics</p>';
    $tables = ['local_learnpath_groups','local_learnpath_group_courses','local_learnpath_managers',
               'local_learnpath_schedules','local_learnpath_reminders','local_learnpath_user_assign',
               'local_learnpath_criteria','local_learnpath_badges','local_learnpath_points','local_learnpath_user_badges'];
    foreach ($tables as $t) {
        $exists = $DB->get_manager()->table_exists($t);
        $count  = $exists ? $DB->count_records($t) : 'MISSING';
        $color  = $exists ? '#a3e635' : '#ef4444';
        echo '<p style="color:' . $color . ';margin:2px 0">' . ($exists?'✅':'❌') . ' ' . $t . ' (' . $count . ' rows)</p>';
    }
    global $CFG;
    echo '<p style="color:#94a3b8;margin:10px 0 2px">PHP: ' . phpversion() . '</p>';
    echo '<p style="color:#94a3b8;margin:2px 0">Moodle: ' . $CFG->version . '</p>';
    echo '</div>';
}

// Group list
$groups = $DB->get_records('local_learnpath_groups', null, 'name ASC');

if (empty($groups)) {
    echo html_writer::start_div('lt-empty-state');
    echo html_writer::div('📂', 'lt-empty-icon');
    echo html_writer::tag('h3', get_string('no_groups', 'local_learnpath'), ['class' => 'lt-empty-title']);
    echo html_writer::tag('p',  get_string('no_groups_hint', 'local_learnpath'), ['class' => 'lt-empty-desc']);
    echo html_writer::link($addurl, '+ ' . get_string('add_group', 'local_learnpath'), ['class' => 'lt-btn lt-btn-primary']);
    echo html_writer::end_div();
} else {
    echo html_writer::start_div('lt-card');

    // Desktop: scrollable table | Mobile: cards
    echo '<style>
    .lt-manage-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .lt-manage-cards{display:none}
    .lt-manage-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px;font-family:var(--lt-font)}
    .lt-manage-card-title{font-size:1rem;font-weight:800;color:#111827;margin:0 0 6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .lt-manage-card-meta{font-size:.78rem;color:#6b7280;display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;align-items:center}
    .lt-manage-card-actions{display:flex;flex-wrap:wrap;gap:6px}
    @media(max-width:700px){
        .lt-manage-table-wrap{display:none}
        .lt-manage-cards{display:block}
    }
    </style>';

    // Desktop table
    echo '<div class="lt-manage-table-wrap">';
    echo html_writer::start_tag('table', ['class' => 'lt-table', 'style' => 'min-width:700px']);
    echo html_writer::tag('thead',
        html_writer::tag('tr',
            html_writer::tag('th', 'Path Name') .
            html_writer::tag('th', 'Type') .
            html_writer::tag('th', 'Courses') .
            html_writer::tag('th', 'Owner & Managers') .
            html_writer::tag('th', 'Deadline') .
            html_writer::tag('th', 'Actions')
        )
    );
    echo html_writer::start_tag('tbody');
    $cards_html = [];

    // Preload all course counts in a single query to avoid N+1 per group.
    $counts_raw = $DB->get_records_sql(
        "SELECT groupid, COUNT(id) AS cnt FROM {local_learnpath_group_courses} GROUP BY groupid"
    );
    $course_counts = [];
    foreach ($counts_raw as $cr) {
        $course_counts[(int)$cr->groupid] = (int)$cr->cnt;
    }

    foreach ($groups as $g) {
        $count    = $course_counts[(int)$g->id] ?? 0;
        $editurl  = new moodle_url('/local/learnpath/manage.php',   ['action' => 'edit',   'groupid' => $g->id]);
        $delurl   = new moodle_url('/local/learnpath/manage.php',   ['action' => 'delete', 'groupid' => $g->id, 'sesskey' => sesskey()]);
        $viewurl  = new moodle_url('/local/learnpath/index.php',    ['groupid' => $g->id]);
        $schedurl = new moodle_url('/local/learnpath/schedule.php', ['groupid' => $g->id]);
        $remurl   = new moodle_url('/local/learnpath/reminders.php',['groupid' => $g->id]);

        $typebadge = html_writer::tag('span',
            get_string('grouptype_' . $g->grouptype, 'local_learnpath'),
            ['class' => 'lt-type-badge lt-type-' . $g->grouptype]
        );

        $deadline_disp = $g->deadline
            ? html_writer::tag('span', userdate($g->deadline, get_string('strftimedatefullshort')),
                ['style' => 'font-size:.78rem;color:' . (($g->deadline < time()) ? '#be123c' : '#374151')])
            : html_writer::tag('span', '—', ['style' => 'color:#9ca3af']);

        $inviteurl = new moodle_url('/local/learnpath/manager-invite.php', ['groupid' => $g->id]);
        $actions =
            html_writer::link($viewurl,    '👁 View',         ['class' => 'lt-action-btn lt-btn-view']) .
            html_writer::link($editurl,    '✏️ Edit',         ['class' => 'lt-action-btn lt-btn-edit']) .
            html_writer::link(new moodle_url('/local/learnpath/learners.php', ['groupid' => $g->id]), '👥 Learners', ['class' => 'lt-action-btn lt-btn-edit']) .
            html_writer::link($inviteurl,  '📨 Managers',     ['class' => 'lt-action-btn lt-btn-edit', 'title' => 'Invite managers by email']) .
            html_writer::link($schedurl,   '📅 Schedule',     ['class' => 'lt-action-btn lt-btn-sched']) .
            html_writer::link($remurl,     '🔔 Reminders',    ['class' => 'lt-action-btn lt-btn-sched']) .
            html_writer::link($delurl,     '🗑 Delete',       [
                'class'   => 'lt-action-btn lt-btn-del',
                'onclick' => "return confirm('" . get_string('confirm_delete_group', 'local_learnpath') . "');",
            ]);

        echo html_writer::tag('tr',
            html_writer::tag('td',
                html_writer::link($viewurl, format_string($g->name), [
                    'style' => 'font-weight:700;color:var(--lt-accent, #1e3a5f);text-decoration:none',
                    'title' => 'View dashboard for this path',
                ]) .
                (empty($g->description) ? '' : html_writer::tag('div', format_string($g->description), ['style' => 'font-size:.74rem;color:#9ca3af;margin-top:2px']))
            ) .
            html_writer::tag('td', $typebadge) .
            html_writer::tag('td', html_writer::tag('span', $count . ' course' . ($count !== 1 ? 's' : ''), ['class' => 'lt-course-count'])) .
            html_writer::tag('td', local_learnpath_render_path_managers($g->id, $g->createdby)) .
            html_writer::tag('td', $deadline_disp) .
            html_writer::tag('td', $actions, ['style' => 'white-space:nowrap;min-width:360px'])
        );

        // Mobile card (rendered into $cards_html, appended after table)
        $cards_html[] = '
<div class="lt-manage-card">
  <div class="lt-manage-card-title">
    <a href="' . $viewurl . '" style="color:var(--lt-accent,#1e3a5f);text-decoration:none;font-weight:800">' . format_string($g->name) . '</a>
    ' . $typebadge . '
  </div>
  <div class="lt-manage-card-meta">
    <span class="lt-course-count">' . $count . ' course' . ($count !== 1 ? 's' : '') . '</span>
    ' . ($g->deadline ? '<span>' . strip_tags($deadline_disp) . '</span>' : '') . '
  </div>
  <div class="lt-manage-card-actions">
    ' . html_writer::link($viewurl,  '👁 View',      ['class' => 'lt-action-btn lt-btn-view']) .
    html_writer::link($editurl,      '✏️ Edit',      ['class' => 'lt-action-btn lt-btn-edit']) .
    html_writer::link(new moodle_url('/local/learnpath/learners.php', ['groupid' => $g->id]), '👥 Learners', ['class' => 'lt-action-btn lt-btn-edit']) .
    html_writer::link($schedurl,     '📅',           ['class' => 'lt-action-btn lt-btn-sched', 'title' => 'Schedule']) .
    html_writer::link($remurl,       '🔔',           ['class' => 'lt-action-btn lt-btn-sched', 'title' => 'Reminders']) .
    html_writer::link($delurl,       '🗑',           ['class' => 'lt-action-btn lt-btn-del', 'onclick' => "return confirm('" . get_string('confirm_delete_group', 'local_learnpath') . "')", 'title' => 'Delete']) . '
  </div>
</div>';
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo '</div>'; // .lt-manage-table-wrap

    // Mobile cards
    echo '<div class="lt-manage-cards">';
    echo implode('', $cards_html);
    echo '</div>';

    echo html_writer::end_div(); // lt-card
}

echo local_learnpath_footer();
} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '<br><small>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small></div>';
}
echo $OUTPUT->footer();

// ── Shared helpers ────────────────────────────────────────────────────────────

function local_learnpath_page_header(string $title, string $subtitle = '', ?moodle_url $backurl = null, bool $showback = false): string {
    $back = ($showback && $backurl)
        ? html_writer::link($backurl, '← ' . get_string('back_to_dashboard', 'local_learnpath'), ['class' => 'lt-back-link'])
        : '';
    return html_writer::div(
        $back .
        html_writer::tag('h1', $title, ['class' => 'lt-page-title']) .
        ($subtitle ? html_writer::tag('p', $subtitle, ['class' => 'lt-page-subtitle']) : ''),
        'lt-page-header'
    );
}

function local_learnpath_footer(): string {
    return html_writer::div(
        html_writer::tag('span', '© Michael Adeniran') . '<span class="lt-sep">·</span>' .
        html_writer::link('mailto:michaeladeniransnr@gmail.com', 'michaeladeniransnr@gmail.com') . '<span class="lt-sep">·</span>' .
        html_writer::link('https://www.linkedin.com/in/michaeladeniran', 'LinkedIn', ['target' => '_blank', 'rel' => 'noopener']) . '<span class="lt-sep">·</span>' .
        html_writer::tag('span', '🇳🇬 Nigeria') . '<span class="lt-sep">·</span>' .
        html_writer::tag('span', 'LearnTrack v1.0.0'),
        'lt-footer'
    );
}
