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
 * LearnTrack — Manage Individual Learners
 * Admin can add/remove specific users to/from a learning path.
 */
require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;

require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:manage', $ctx);
global $DB, $OUTPUT, $USER, $CFG;

// AJAX: search users by name/email (for manager assignment)
$action_ajax = optional_param('action', '', PARAM_ALPHA);
if ($action_ajax === 'search_users') {
    require_sesskey();
    $q = optional_param('search', '', PARAM_TEXT);
    $q = trim($q);
    header('Content-Type: application/json');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $like1 = $DB->sql_like('u.firstname', ':q1', false);
    $like2 = $DB->sql_like('u.lastname',  ':q2', false);
    $like3 = $DB->sql_like('u.email',     ':q3', false);
    $sql = "SELECT u.id, u.firstname, u.lastname, u.email
            FROM {user} u
            WHERE u.deleted=0 AND u.suspended=0
            AND ($like1 OR $like2 OR $like3)
            ORDER BY u.lastname, u.firstname
            LIMIT 10";
    $users = $DB->get_records_sql($sql, [
        'q1' => '%'.$q.'%', 'q2' => '%'.$q.'%', 'q3' => '%'.$q.'%',
    ]);
    $result = [];
    foreach ($users as $u) {
        $result[] = ['id'=>$u->id,'name'=>fullname($u),'email'=>$u->email];
    }
    echo json_encode($result);
    exit;
}
if ($action_ajax === 'get_user') {
    require_sesskey();
    $uid = required_param('userid', PARAM_INT);
    header('Content-Type: application/json');
    $u = $DB->get_record('user', ['id'=>$uid,'deleted'=>0], 'id,firstname,lastname,email');
    if ($u) {
        echo json_encode(['id'=>$u->id,'name'=>fullname($u),'email'=>$u->email]);
    } else {
        echo json_encode([]);
    }
    exit;
}


// Guard: if user_assign table doesn't exist yet, show upgrade notice
$dbman_l = $DB->get_manager();
if (!$dbman_l->table_exists(new xmldb_table('local_learnpath_user_assign'))) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('The individual learner assignment feature requires a database upgrade. Please go to Site Administration → Notifications to run the upgrade first.', 'warning');
    echo $OUTPUT->footer();
    exit;
}

$groupid = required_param('groupid', PARAM_INT);
$action  = optional_param('action',  'list',  PARAM_ALPHA);
$userid  = optional_param('userid',  0,       PARAM_INT);

$group = DH::get_group($groupid);
if (!$group) {
    throw new moodle_exception('invalidgroup', 'local_learnpath');
}

$PAGE->set_url(new moodle_url('/local/learnpath/learners.php', ['groupid' => $groupid]));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Manage Learners');

global $DB, $OUTPUT, $USER;
$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';

// ── ACTIONS ───────────────────────────────────────────────────────────────────

if ($action === 'remove' && $userid && confirm_sesskey()) {
    $DB->delete_records('local_learnpath_user_assign', ['groupid' => $groupid, 'userid' => $userid]);
    redirect(
        new moodle_url('/local/learnpath/learners.php', ['groupid' => $groupid]),
        'Learner removed from path.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'add' && confirm_sesskey()) {
    $raw = optional_param('userids', '', PARAM_TEXT);
    $ids = array_filter(array_map('intval', explode(',', $raw)));
    $now = time();
    $added = 0;
    foreach ($ids as $uid) {
        if ($uid <= 0) {
            continue;
        }
        if (!$DB->record_exists('user', ['id' => $uid, 'deleted' => 0])) {
            continue;
        }
        if (!$DB->record_exists('local_learnpath_user_assign', ['groupid' => $groupid, 'userid' => $uid])) {
            $DB->insert_record('local_learnpath_user_assign', (object)[
                'groupid'     => $groupid,
                'userid'      => $uid,
                'assignedby'  => $USER->id,
                'timecreated' => $now,
            ]);
            $added++;
        }
    }
    redirect(
        new moodle_url('/local/learnpath/learners.php', ['groupid' => $groupid]),
        $added . ' learner(s) added to path.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── SEARCH (AJAX endpoint) ────────────────────────────────────────────────────
if ($action === 'search') {
    $q = optional_param('q', '', PARAM_TEXT);
    if (strlen($q) >= 2) {
        $like = '%' . $DB->sql_like_escape($q) . '%';
        $sql = "SELECT id, firstname, lastname, email, username
                FROM {user}
                WHERE deleted = 0
                  AND (
                      " . $DB->sql_like('firstname', ':q1', false) . "
                      OR " . $DB->sql_like('lastname',  ':q2', false) . "
                      OR " . $DB->sql_like('email',     ':q3', false) . "
                      OR " . $DB->sql_like('username',  ':q4', false) . "
                  )
                ORDER BY lastname, firstname";
        $users = $DB->get_records_sql($sql, ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like], 0, 20);
        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'id'    => $u->id,
                'label' => fullname($u) . ' (' . $u->email . ')',
                'name'  => fullname($u),
                'email' => $u->email,
            ];
        }
        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    }
    header('Content-Type: application/json');
    echo '[]';
    exit;
}

// ── PAGE OUTPUT ────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';

echo html_writer::link(
    new moodle_url('/local/learnpath/manage.php'),
    '← Manage Paths',
    ['style' => 'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']
);

echo '<div class="lt-page-header"><div class="lt-header-inner">';
echo '<div>';
echo html_writer::tag('h1', '👥 Manage Individual Learners', ['class' => 'lt-page-title']);
echo html_writer::tag('p', format_string($group->name) . ' — Add or remove specific learners', ['class' => 'lt-page-subtitle']);
echo '</div>';
echo html_writer::link(
    new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid]),
    '📊 View Dashboard',
    ['class' => 'lt-btn lt-btn-outline']
);
echo '</div></div>';

// ── ADD LEARNER SECTION ───────────────────────────────────────────────────────
echo '<div class="lt-card" style="margin-bottom:16px">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">➕ Add Learners</h3>';
echo '<span style="font-family:var(--lt-font);font-size:.76rem;color:#9ca3af">Search by name, email, or username</span>';
echo '</div>';
echo '<div class="lt-card-body">';
echo '<form id="lt-add-form" method="post" action="' . (new moodle_url('/local/learnpath/learners.php', ['groupid' => $groupid, 'action' => 'add', 'sesskey' => sesskey()])) . '">';
echo '<input type="hidden" name="userids" id="lt-userids-input" value="">';
echo '<div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">';
echo '<div style="flex:1;min-width:240px">';
echo '<input type="text" id="lt-user-search" class="lt-search-input" placeholder="Search users…" autocomplete="off" style="width:100%">';
echo '<div id="lt-search-results" style="display:none;position:absolute;z-index:100;background:#fff;border:1.5px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);width:340px;max-height:260px;overflow-y:auto"></div>';
echo '</div>';
echo '<button type="submit" id="lt-add-btn" class="lt-btn lt-btn-primary" disabled style="opacity:.5">➕ Add Selected</button>';
echo '</div>';
echo '<div id="lt-selected-wrap" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px"></div>';
echo '</form>';
echo '</div></div>';

// ── ASSIGNED LEARNERS LIST ────────────────────────────────────────────────────
$assigned_sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
                        lpu.timecreated, ab.firstname AS ab_first, ab.lastname AS ab_last
                 FROM {local_learnpath_user_assign} lpu
                 JOIN {user} u  ON u.id  = lpu.userid    AND u.deleted = 0
                 JOIN {user} ab ON ab.id = lpu.assignedby
                 WHERE lpu.groupid = :gid
                 ORDER BY u.lastname, u.firstname";
$assigned = $DB->get_records_sql($assigned_sql, ['gid' => $groupid]);

$total = count($assigned);

echo '<div class="lt-card">';
echo '<div class="lt-card-header">';
echo '<h3 class="lt-card-title">Assigned Learners</h3>';
echo '<span class="lt-card-meta">' . $total . ' learner' . ($total !== 1 ? 's' : '') . '</span>';
echo '</div>';

if (empty($assigned)) {
    echo '<div style="padding:28px;text-align:center;font-family:var(--lt-font)">';
    echo '<div style="font-size:2rem;margin-bottom:8px">👥</div>';
    echo '<p style="color:#9ca3af;font-size:.86rem;margin:0">No individual learners assigned yet. Use the search above to add learners.</p>';
    echo '</div>';
} else {
    // Mobile-friendly card list
    echo '<div style="overflow-x:auto">';
    echo '<table class="lt-data-table" style="min-width:560px"><thead><tr>';
    foreach (['Learner', 'Email', 'Username', 'Assigned By', 'Date Added', ''] as $h) {
        echo '<th>' . $h . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($assigned as $a) {
        $delurl = new moodle_url('/local/learnpath/learners.php', [
            'groupid' => $groupid,
            'action'  => 'remove',
            'userid'  => $a->id,
            'sesskey' => sesskey(),
        ]);
        $profurl = new moodle_url('/local/learnpath/profile.php', ['userid' => $a->id, 'groupid' => $groupid]);

        echo '<tr>';
        echo '<td><div class="lt-learner-name">' . format_string($a->firstname . ' ' . $a->lastname) . '</div></td>';
        echo '<td><a href="mailto:' . s($a->email) . '" class="lt-email">' . s($a->email) . '</a></td>';
        echo '<td><span class="lt-learner-sub">@' . s($a->username) . '</span></td>';
        echo '<td><span style="font-size:.8rem;color:#6b7280">' . format_string($a->ab_first . ' ' . $a->ab_last) . '</span></td>';
        echo '<td><span class="lt-date">' . userdate($a->timecreated, get_string('strftimedatefullshort')) . '</span></td>';
        echo '<td style="white-space:nowrap">';
        echo html_writer::link($profurl, 'Profile', ['class' => 'lt-action-btn lt-btn-view']);
        echo html_writer::link($delurl, '🗑 Remove', [
            'class'   => 'lt-action-btn lt-btn-del',
            'onclick' => "return confirm('Remove this learner from the path?')",
        ]);
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

// ── FOOTER ────────────────────────────────────────────────────────────────────
echo '<div class="lt-footer">';
echo '<span>© Michael Adeniran</span><span class="lt-sep">·</span>';
echo html_writer::link('https://www.linkedin.com/in/michaeladeniran', 'LinkedIn', ['target' => '_blank']);
echo '<span class="lt-sep">·</span><span>LearnTrack v2.0.0</span>';
echo '</div>';

// ── SEARCH JS ─────────────────────────────────────────────────────────────────
$search_url = (new moodle_url('/local/learnpath/learners.php', ['groupid' => $groupid, 'action' => 'search']))->out(false);

echo html_writer::script("
(function(){
    var inp    = document.getElementById('lt-user-search');
    var res    = document.getElementById('lt-search-results');
    var wrap   = document.getElementById('lt-selected-wrap');
    var hidden = document.getElementById('lt-userids-input');
    var btn    = document.getElementById('lt-add-btn');
    var selected = {};

    function updateHidden(){
        hidden.value = Object.keys(selected).join(',');
        var hasAny = Object.keys(selected).length > 0;
        btn.disabled = !hasAny;
        btn.style.opacity = hasAny ? '1' : '.5';
    }

    function addPill(id, label){
        if(selected[id]) return;
        selected[id] = label;
        var pill = document.createElement('div');
        pill.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:100px;padding:4px 12px;font-family:var(--lt-font);font-size:.8rem;color:#1e40af';
        pill.innerHTML = label + ' <button onclick=\"removePill(' + id + ')\" style=\"background:none;border:none;cursor:pointer;color:#9ca3af;font-size:1rem;padding:0;line-height:1\">×</button>';
        pill.id = 'pill-' + id;
        wrap.appendChild(pill);
        updateHidden();
    }

    window.removePill = function(id){
        delete selected[id];
        var el = document.getElementById('pill-' + id);
        if(el) el.remove();
        updateHidden();
    };

    var timer;
    inp.addEventListener('input', function(){
        clearTimeout(timer);
        var q = this.value.trim();
        if(q.length < 2){ res.style.display='none'; return; }
        timer = setTimeout(function(){
            fetch('" . $search_url . "&q=' + encodeURIComponent(q))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    res.innerHTML = '';
                    if(!data.length){
                        res.innerHTML = '<div style=\"padding:12px;font-size:.82rem;color:#9ca3af;font-family:var(--lt-font)\">No users found.</div>';
                    } else {
                        data.forEach(function(u){
                            var d = document.createElement('div');
                            d.style.cssText = 'padding:10px 14px;cursor:pointer;font-family:var(--lt-font);font-size:.84rem;border-bottom:1px solid #f3f4f6;transition:background .1s';
                            d.innerHTML = '<strong>' + u.name + '</strong><br><span style=\"font-size:.74rem;color:#9ca3af\">' + u.email + '</span>';
                            d.addEventListener('mouseenter', function(){ this.style.background='#f0f7ff'; });
                            d.addEventListener('mouseleave', function(){ this.style.background=''; });
                            d.addEventListener('click', function(){
                                addPill(u.id, u.name);
                                inp.value = '';
                                res.style.display = 'none';
                            });
                            res.appendChild(d);
                        });
                    }
                    res.style.display = 'block';
                    var rect = inp.getBoundingClientRect();
                    res.style.top = (inp.offsetTop + inp.offsetHeight + 4) + 'px';
                    res.style.left = inp.offsetLeft + 'px';
                });
        }, 280);
    });

    document.addEventListener('click', function(e){
        if(!inp.contains(e.target) && !res.contains(e.target)){
            res.style.display = 'none';
        }
    });
})();
");

echo $OUTPUT->footer();
