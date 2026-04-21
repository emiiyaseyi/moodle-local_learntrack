<?php
/**
 * LearnTrack — Dashboard
 */
require_once(__DIR__ . '/../../config.php');

require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:viewdashboard', $ctx);

$groupid     = optional_param('groupid',      0,        PARAM_INT);
$view        = optional_param('view',         'summary', PARAM_ALPHA);
$page        = optional_param('page',         0,        PARAM_INT);
$perpage     = optional_param('perpage',      25,       PARAM_INT);
$sortcol     = optional_param('sortcol',      '',       PARAM_ALPHANUMEXT);
$sortdir     = optional_param('sortdir',      'asc',    PARAM_ALPHA);
$course_filter = optional_param('course_filter', 0,     PARAM_INT);
$user_status = optional_param('user_status',  'active', PARAM_ALPHA);
$date_range  = optional_param('date_range',   'all',    PARAM_ALPHA);

if (!in_array($perpage, [25, 50, 100, 200])) { $perpage = 25; }

$PAGE->set_url(new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid, 'view' => $view]));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Dashboard');
$PAGE->requires->css('/local/learnpath/styles.css');

global $USER, $OUTPUT, $DB;
$isadmin = has_capability('local/learnpath:manage', $ctx);
$brand   = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';

// Handle enroll action - admin clicks NE badge to enroll user into path courses
// Unenrol handler
if ($isadmin && $groupid > 0 && optional_param('unenrol_user', 0, PARAM_INT) > 0 && confirm_sesskey()) {
    $unenrol_uid    = required_param('unenrol_user', PARAM_INT);
    $unenrol_course = optional_param('unenrol_course', 0, PARAM_INT);
    $unenrol_count  = 0;
    $courses_to_unenrol = $unenrol_course > 0
        ? [$unenrol_course]
        : array_column(
            $DB->get_records('local_learnpath_group_courses', ['groupid' => $groupid], '', 'courseid'),
            'courseid'
          );
    foreach ($courses_to_unenrol as $cid) {
        $ctx_c    = context_course::instance($cid, IGNORE_MISSING);
        $instance = $DB->get_record('enrol', ['courseid' => $cid, 'enrol' => 'manual']);
        $plugin   = enrol_get_plugin('manual');
        if ($plugin && $instance && $ctx_c && is_enrolled($ctx_c, $unenrol_uid)) {
            $plugin->unenrol_user($instance, $unenrol_uid);
            $unenrol_count++;
        }
    }
    $back_view = optional_param('view', 'summary', PARAM_ALPHA);
    redirect(
        new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid, 'view' => $back_view]),
        $unenrol_count > 0
            ? "Learner unenrolled from {$unenrol_count} course(s)."
            : "No changes made (not enrolled via manual enrolment).",
        null,
        $unenrol_count > 0
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING
    );
}

if ($isadmin && $groupid > 0 && optional_param('enroll_user', 0, PARAM_INT) > 0 && confirm_sesskey()) {
    $enroll_uid    = required_param('enroll_user', PARAM_INT);
    $enroll_course = optional_param('enroll_course', 0, PARAM_INT); // 0 = all, >0 = single course
    $enrolled_count = 0;

    if ($enroll_course > 0) {
        // Single course enrolment (from Comparison / Per Course NE badge)
        $ctx_c = context_course::instance($enroll_course, IGNORE_MISSING);
        if ($ctx_c && !is_enrolled($ctx_c, $enroll_uid)) {
            $instance = $DB->get_record('enrol', ['courseid' => $enroll_course, 'enrol' => 'manual']);
            $plugin   = enrol_get_plugin('manual');
            if ($plugin && $instance) {
                $plugin->enrol_user($instance, $enroll_uid);
                $enrolled_count = 1;
            }
        }
    } else {
        // All courses enrolment (from Summary NE bulk badge)
        $courses = $DB->get_records('local_learnpath_group_courses', ['groupid' => $groupid]);
        foreach ($courses as $lgc) {
            $ctx_c = context_course::instance($lgc->courseid, IGNORE_MISSING);
            if (!$ctx_c || is_enrolled($ctx_c, $enroll_uid)) { continue; }
            $instance = $DB->get_record('enrol', ['courseid' => $lgc->courseid, 'enrol' => 'manual']);
            $plugin   = enrol_get_plugin('manual');
            if ($plugin && $instance) {
                $plugin->enrol_user($instance, $enroll_uid);
                $enrolled_count++;
            }
        }
    }
    if ($enrolled_count > 0) {
        // Notify learner - in-app + email
        $learner = $DB->get_record('user', ['id' => $enroll_uid, 'deleted' => 0]);
        $group   = $DB->get_record('local_learnpath_groups', ['id' => $groupid]);
        if ($learner && $group) {
            $path_url = (new moodle_url('/local/learnpath/mypath.php', ['groupid' => $groupid]))->out(false);
            // In-app message
            $msg = new \core\message\message();
            $msg->component         = 'local_learnpath';
            $msg->name              = 'learntrack_reminder';
            $msg->userfrom          = \core_user::get_noreply_user();
            $msg->userto            = $learner;
            $msg->subject           = 'You have been enrolled in a learning path: ' . format_string($group->name);
            $enroll_tpl = get_config('local_learnpath', 'email_enroll_body')
                ?: 'Hi {firstname},\n\nYou have been added to the learning path "{groupname}" and enrolled in {count} course(s). Visit your dashboard to get started.\n\nLearnTrack';
            $evars = ['{firstname}'=>$learner->firstname,'{groupname}'=>format_string($group->name),'{count}'=>$enrolled_count,'{url}'=>$path_url];
            $msg->fullmessage = str_replace(array_keys($evars), array_values($evars), $enroll_tpl);
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml   = '<p>Hi <strong>' . s($learner->firstname) . '</strong>,</p><p>You have been added to the learning path <strong>' . format_string($group->name) . '</strong> and enrolled in <strong>' . $enrolled_count . '</strong> course(s).</p><p><a href="' . $path_url . '">View your learning path →</a></p>';
            $msg->smallmessage      = 'Enrolled in: ' . format_string($group->name);
            $msg->notification      = 1;
            $msg->contexturl        = $path_url;
            $msg->contexturlname    = 'View My Learning Path';
            message_send($msg);
            // Email
            $noreply = \core_user::get_noreply_user();
            $noreply->firstname = get_config('local_learnpath', 'email_sender_name') ?: 'LearnTrack';
            $noreply->lastname  = '';
            email_to_user($learner, $noreply, $msg->subject, $msg->fullmessage, $msg->fullmessagehtml);
        }
        redirect(
            new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid, 'view' => $view]),
            "Enrolled in {$enrolled_count} course(s). Learner notified by email and in-app.",
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        redirect(
            new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid, 'view' => $view]),
            'Already enrolled in all courses or no manual enrolment available.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }
}

// Bulk enroll
if ($isadmin && $groupid > 0 && optional_param('bulk_enroll', 0, PARAM_INT) > 0 && confirm_sesskey()) {
    $uids_raw = optional_param('userids', '', PARAM_TEXT);
    $uid_list = array_filter(array_map('intval', explode(',', $uids_raw)));
    $total_enrolled = 0;
    foreach ($uid_list as $bulk_uid) {
        $courses = $DB->get_records('local_learnpath_group_courses', ['groupid' => $groupid]);
        foreach ($courses as $lgc) {
            $ctx_c = context_course::instance($lgc->courseid, IGNORE_MISSING);
            if (!$ctx_c || is_enrolled($ctx_c, $bulk_uid)) continue;
            $instance = $DB->get_record('enrol', ['courseid' => $lgc->courseid, 'enrol' => 'manual']);
            $plugin   = enrol_get_plugin('manual');
            if ($plugin && $instance) { $plugin->enrol_user($instance, $bulk_uid); $total_enrolled++; }
        }
    }
    redirect(
        new moodle_url('/local/learnpath/index.php', ['groupid' => $groupid, 'view' => $view]),
        "Bulk enrolment complete: {$total_enrolled} enrolment(s) processed.",
        null, \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome', ['style' => 'display:inline-block;margin-bottom:14px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
try {

echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';

// Nav
echo html_writer::link(new moodle_url('/local/learnpath/overview.php'), '← Overview',
    ['style' => 'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

// Page header
echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo html_writer::tag('h1', get_config('local_learnpath','brand_name') ?: 'LearnTrack', ['class' => 'lt-page-title']);
echo html_writer::tag('p', 'Learning Path Progress Dashboard', ['class' => 'lt-page-subtitle']);
echo '</div><div class="lt-header-actions">';
if ($isadmin) {
    echo html_writer::link(new moodle_url('/local/learnpath/manage.php'), '⚙️ Manage', ['class' => 'lt-btn lt-btn-outline']);
    echo html_writer::link(new moodle_url('/local/learnpath/branding.php'), '🎨 Brand', ['class' => 'lt-btn lt-btn-outline']);
}
if ($groupid) {
    echo html_writer::link(new moodle_url('/local/learnpath/leaderboard.php', ['groupid' => $groupid]), '🏆', ['class' => 'lt-btn lt-btn-outline']);
}
echo '</div></div></div>';

// Fetch groups
$groups = \local_learnpath\data\helper::get_groups();

// Group selector toolbar
echo '<div class="lt-toolbar-wrap"><div class="lt-toolbar">';
echo '<div class="lt-toolbar-group">';
echo html_writer::tag('label', 'Learning Path', ['class' => 'lt-label']);
$gopts = [0 => '— Select a path —'];
foreach ($groups as $g) { $gopts[$g->id] = format_string($g->name); }
echo html_writer::select($gopts, 'groupid', $groupid, false, [
    'class' => 'lt-select',
    'onchange' => "window.location='?groupid='+this.value+'&view=" . $view . "'",
]);
echo '</div>';

if ($groupid) {
    $group    = \local_learnpath\data\helper::get_group_with_courses($groupid);
    $gcourses = $group ? $group->courses : [];



    // View selector dropdown
    $view_opts = ['summary'=>'👤 Summary','detail'=>'📋 Per Course','comparison'=>'🔀 Comparison','certs'=>'🎓 Certificates'];
    echo '<div class="lt-toolbar-group">';
    echo '<label class="lt-label">View</label>';
    echo '<select class="lt-select" onchange="window.location=\'?groupid=' . $groupid . '&view=\'+this.value" style="font-weight:600;min-width:130px">';
    foreach ($view_opts as $vk => $vl) {
        echo '<option value="' . $vk . '"' . ($view===$vk?' selected':'') . '>' . $vl . '</option>';
    }
    echo '</select></div>';

    // Export dropdown
    if (has_capability('local/learnpath:export', $ctx)) {
        $exp_url = new moodle_url('/local/learnpath/export.php', ['groupid'=>$groupid,'view'=>$view,'sesskey'=>sesskey()]);
        echo '<div class="lt-toolbar-group">';
        echo '<label class="lt-label">Export</label>';
        echo '<select class="lt-select" id="lt-export-sel" onchange="if(this.value){window.location=this.value;}this.selectedIndex=0">';
        echo '<option value="">📥 Export…</option>';
        foreach (['xlsx'=>'📊 Excel (.xlsx)','csv'=>'📄 CSV','pdf'=>'🖨 PDF'] as $fmt=>$lbl) {
            $exp_url->param('format', $fmt);
            echo '<option value="' . $exp_url->out(false) . '">' . $lbl . '</option>';
        }
        echo '</select></div>';

        if (has_capability('local/learnpath:emailreport', $ctx)) {
            $mailurl  = new moodle_url('/local/learnpath/email.php',    ['groupid'=>$groupid]);
            $schedurl = new moodle_url('/local/learnpath/schedule.php', ['groupid'=>$groupid]);
            $remurl   = new moodle_url('/local/learnpath/reminders.php',['groupid'=>$groupid]);
            echo '<div class="lt-toolbar-group">';
            echo '<label class="lt-label">Actions</label>';
            echo '<select class="lt-select" onchange="if(this.value){window.location=this.value;}this.selectedIndex=0">';
            echo '<option value="">📤 Actions…</option>';
            echo '<option value="' . $mailurl->out(false)  . '">✉️ Send Report</option>';
            echo '<option value="' . $schedurl->out(false) . '">📅 Schedule</option>';
            echo '<option value="' . $remurl->out(false)   . '">🔔 Reminders</option>';
            echo '</select></div>';
        }
    }
}
// Search input
$search_val = optional_param('search', '', PARAM_TEXT);
echo '<div class="lt-toolbar-group" style="margin-left:auto">';
echo '<label class="lt-label">Search</label>';
echo '<input type="text" id="lt-search-input" value="' . s($search_val) . '" placeholder="Name or email…" class="lt-search-input" oninput="ltFilterTable(this.value)" style="width:200px">';
echo '</div>';
echo '</div></div>';

// Main content
echo '<div class="lt-card">';
if (!$groupid) {
    echo '<div class="lt-empty-state"><div class="lt-empty-icon">📊</div>';
    echo '<h3 class="lt-empty-title">Select a Learning Path</h3>';
    echo '<p class="lt-empty-desc">Choose a path from the dropdown above.</p></div>';
} else {
    if (empty($group)) {
        echo '<div style="padding:32px;text-align:center;color:#9ca3af;font-family:var(--lt-font)">Learning path not found.</div>';
    } else {
        // Stat strip
        $summary = \local_learnpath\data\helper::get_progress_summary($groupid, $USER->id, $user_status);
        if (!empty($summary)) {
            $t_learners = count($summary);
            $t_courses  = count($gcourses);
            $completed_all = 0; $total_pct = 0;
            foreach ($summary as $s) {
                if ($s->completed_courses >= $s->total_courses && $s->total_courses > 0) { $completed_all++; }
                $total_pct += $s->overall_progress;
            }
            $avg = $t_learners > 0 ? (int)round($total_pct / $t_learners) : 0;
            echo '<div class="lt-stats-strip">';
            foreach ([
                ['👥', $t_learners,    'Learners',        'lt-icon-blue'],
                ['📚', $t_courses,     'Courses in Path', 'lt-icon-indigo'],
                ['✅', $completed_all, 'Fully Complete',  'lt-icon-green'],
                ['📈', $avg . '%',     'Avg Progress',    'lt-icon-amber'],
            ] as [$icon, $val, $label, $cls]) {
                echo '<div class="lt-stat-card">';
                echo '<div class="lt-stat-icon ' . $cls . '">' . $icon . '</div>';
                echo '<div class="lt-stat-text"><span class="lt-stat-value">' . $val . '</span>';
                echo '<span class="lt-stat-label">' . $label . '</span></div></div>';
            }
            echo '</div>';
        }

        // Data table
        if ($view === 'certs') {
            lt_render_certs($groupid, $gcourses, $isadmin);
        } elseif ($view === 'comparison') {
            lt_render_comparison($groupid, $USER->id, $user_status, $gcourses);
        } elseif ($view === 'detail') {
            $data = \local_learnpath\data\helper::get_progress_detail($groupid, $USER->id, $user_status);
            if ($course_filter > 0) {
                $data = array_values(array_filter($data, fn($r) => (int)$r->courseid === $course_filter));
            }
            if (empty($data)) {
                echo '<div style="padding:32px;text-align:center;color:#9ca3af;font-family:var(--lt-font)">No data for current filters.</div>';
            } else {
                lt_render_detail($data, $sortcol, $sortdir, $groupid, $view, $course_filter);
            }
        } else {
            // Summary view
            $data = \local_learnpath\data\helper::get_progress_summary($groupid, $USER->id, $user_status);
            if ($course_filter > 0) {
                $enrolled_in_course = $DB->get_fieldset_sql(
                    "SELECT DISTINCT ue.userid FROM {user_enrolments} ue
                     JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid",
                    ['cid' => $course_filter]
                );
                $data = array_values(array_filter($data, fn($r) => in_array($r->userid, $enrolled_in_course)));
            }
            if (empty($data)) {
                echo '<div style="padding:32px;text-align:center;color:#9ca3af;font-family:var(--lt-font)">No data for current filters.</div>';
            } else {
                lt_render_summary($data, $sortcol, $sortdir, $groupid, $view);
            }
        }
    }
}
echo '</div>'; // lt-card

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'
    . html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank'])
    . '<span class="lt-sep">·</span><span>LearnTrack v2.0.0</span></div>';

// Modal for profile popup
echo '<div id="lt-modal" class="lt-modal-overlay" onclick="if(event.target===this)closeLTModal()">';
echo '<div class="lt-modal-box"><button class="lt-modal-close" onclick="closeLTModal()">✕</button>';
echo '<iframe id="lt-modal-frame" src="" class="lt-modal-frame" frameborder="0"></iframe></div></div>';
echo html_writer::script("
function openLTProfile(uid,gid){ document.getElementById('lt-modal').classList.add('visible'); document.getElementById('lt-modal-frame').src='/local/learnpath/profile.php?userid='+uid+'&groupid='+gid; document.body.style.overflow='hidden'; }
function closeLTModal(){ document.getElementById('lt-modal').classList.remove('visible'); document.getElementById('lt-modal-frame').src=''; document.body.style.overflow=''; }
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeLTModal();});

function ltSortTable(tableId, colIdx, thEl) {
    var table = document.getElementById(tableId);
    if (!table) return;
    var tbody = table.tBodies[0];
    var rows  = Array.from(tbody.rows);
    var asc   = thEl.dataset.sortDir !== 'asc';
    thEl.dataset.sortDir = asc ? 'asc' : 'desc';
    // Reset all arrows
    table.querySelectorAll('.lt-sort-arrow').forEach(function(a){ a.textContent = '⇅'; });
    thEl.querySelector('.lt-sort-arrow').textContent = asc ? '↑' : '↓';
    rows.sort(function(a, b) {
        var av = a.cells[colIdx] ? a.cells[colIdx].innerText.trim() : '';
        var bv = b.cells[colIdx] ? b.cells[colIdx].innerText.trim() : '';
        var an = parseFloat(av.replace('%','')), bn = parseFloat(bv.replace('%',''));
        var cmp = (!isNaN(an) && !isNaN(bn)) ? (an - bn) : av.localeCompare(bv);
        return asc ? cmp : -cmp;
    });
    rows.forEach(function(r){ tbody.appendChild(r); });
}

function ltFilterTable(q){
    q = q.toLowerCase().trim();
    document.querySelectorAll('#lt-summary-table .lt-learner-row').forEach(function(row){
        var name  = row.getAttribute('data-name')  || '';
        var email = row.getAttribute('data-email') || '';
        row.style.display = (!q || name.includes(q) || email.includes(q)) ? '' : 'none';
    });
}
function ltToggleAll(checked){
    document.querySelectorAll('.lt-row-check').forEach(function(cb){ if(cb.closest('tr').style.display !== 'none') cb.checked = checked; });
    ltCountSelected();
}
function ltCountSelected(){
    var n = document.querySelectorAll('.lt-row-check:checked').length;
    var el = document.getElementById('lt-bulk-count');
    if(el) el.textContent = n > 0 ? n + ' selected' : '';
    var sa = document.getElementById('lt-select-all');
    if(sa){ var all = document.querySelectorAll('.lt-row-check').length; sa.indeterminate = n > 0 && n < all; sa.checked = n === all && all > 0; }
}
function ltBulkAction(action){
    var ids = [];
    document.querySelectorAll('.lt-row-check:checked').forEach(function(cb){ ids.push(cb.value); });
    if(ids.length === 0){ alert('Please select at least one learner.'); return; }
    var gid = document.querySelector('select[name=groupid]') ? document.querySelector('select[name=groupid]').value : new URLSearchParams(window.location.search).get('groupid');
    if(action === 'remind'){
        if(!confirm('Send a reminder to ' + ids.length + ' learner(s)?')) return;
        var form = document.createElement('form'); form.method = 'post'; form.action = '/local/learnpath/reminders.php';
        var f1 = document.createElement('input'); f1.type='hidden'; f1.name='groupid'; f1.value=gid; form.appendChild(f1);
        var f2 = document.createElement('input'); f2.type='hidden'; f2.name='action'; f2.value='bulk_remind'; form.appendChild(f2);
        var f3 = document.createElement('input'); f3.type='hidden'; f3.name='userids'; f3.value=ids.join(','); form.appendChild(f3);
        var f4 = document.createElement('input'); f4.type='hidden'; f4.name='sesskey'; f4.value=M.cfg.sesskey; form.appendChild(f4);
        document.body.appendChild(form); form.submit();
    } else if(action === 'enroll'){
        if(!confirm('Enrol ' + ids.length + ' selected learner(s) in all missing courses?')) return;
        var form = document.createElement('form'); form.method = 'post'; form.action = '/local/learnpath/index.php';
        var params = {groupid:gid,view:'summary',bulk_enroll:1,sesskey:M.cfg.sesskey,userids:ids.join(',')};
        for(var k in params){ var fi = document.createElement('input'); fi.type='hidden'; fi.name=k; fi.value=params[k]; form.appendChild(fi); }
        document.body.appendChild(form); form.submit();
    }
}
");

} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui">';
    echo '<strong>Error loading dashboard:</strong> ' . htmlspecialchars($e->getMessage());
    echo '<br><small>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small></div>';
}
echo $OUTPUT->footer();

// ── RENDER FUNCTIONS ─────────────────────────────────────────────────────────

function lt_render_comparison(int $groupid, int $viewerid, string $user_status, array $gcourses): void {
    global $DB;
    $learners = \local_learnpath\data\helper::get_learners_for_group($groupid, $viewerid, $user_status);
    if (empty($learners) || empty($gcourses)) {
        echo '<div style="padding:32px;text-align:center;color:#9ca3af;font-family:var(--lt-font)">No data available.</div>';
        return;
    }
    $learner_ids = array_values(array_keys($learners));
    $course_ids  = array_values(array_map(fn($c) => $c->id, $gcourses));
    list($ucids, $ucparams) = $DB->get_in_or_equal($course_ids,  SQL_PARAMS_NAMED, 'ucid');
    list($uids,  $uparams)  = $DB->get_in_or_equal($learner_ids, SQL_PARAMS_NAMED, 'uid');
    $course_totals = [];
    foreach ($gcourses as $c) {
        $course_totals[$c->id] = (int)$DB->count_records_sql(
            "SELECT COUNT(id) FROM {course_modules} WHERE course=:cid AND completion>0 AND deletioninprogress=0",
            ['cid' => $c->id]
        );
    }
    $mod_rows = $DB->get_records_sql(
        "SELECT " . $DB->sql_concat('cmc.userid', "'_'", 'cm.course') . " AS rowkey,
                cmc.userid, cm.course, COUNT(cmc.id) AS done
         FROM {course_modules_completion} cmc
         JOIN {course_modules} cm ON cm.id=cmc.coursemoduleid AND cm.completion>0 AND cm.deletioninprogress=0
         WHERE cmc.completionstate IN (1,2) AND cm.course {$ucids} AND cmc.userid {$uids}
         GROUP BY cmc.userid, cm.course",
        array_merge($ucparams, $uparams)
    );
    $mod_done = [];
    foreach ($mod_rows as $mr) { $mod_done[$mr->userid][$mr->course] = (int)$mr->done; }
    $cc_rows = $DB->get_records_sql(
        "SELECT " . $DB->sql_concat('userid', "'_'", 'course') . " AS rowkey,
                userid, course FROM {course_completions}
         WHERE course {$ucids} AND userid {$uids} AND timecompleted > 0",
        array_merge($ucparams, $uparams)
    );
    $cc_done = [];
    foreach ($cc_rows as $cr) { $cc_done[$cr->userid][$cr->course] = true; }
    $access_rows = $DB->get_records_sql(
        "SELECT userid, MIN(timecreated) AS firstaccess, MAX(timecreated) AS lastaccess
         FROM {logstore_standard_log}
         WHERE userid {$uids} AND courseid {$ucids}
         GROUP BY userid",
        array_merge($uparams, $ucparams)
    );
    $access = [];
    foreach ($access_rows as $ar) { $access[$ar->userid] = $ar; }
    // Pre-load enrollment per course for NE badges
    $cmp_enrolled = [];
    $cmp_is_admin = has_capability('local/learnpath:manage', context_system::instance());
    foreach ($gcourses as $c) {
        $cmp_enrolled[$c->id] = array_map('intval', $DB->get_fieldset_sql(
            'SELECT DISTINCT ue.userid FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid AND ue.status=0',
            ['cid' => $c->id]
        ));
    }
    $cmp_tid = 'lt-cmp-' . $groupid;
    echo '<div style="overflow-x:auto"><table class="lt-data-table" id="' . $cmp_tid . '" style="font-size:.78rem">';
    echo '<thead><tr>';
    $cmp_ths = ['Learner','Email'];
    foreach ($gcourses as $c_) { $cmp_ths[] = mb_strimwidth(format_string($c_->fullname), 0, 18, '…'); }
    $cmp_ths = array_merge($cmp_ths, ['Courses Done','Overall %','Status','First Access','Last Access']);
    foreach ($cmp_ths as $i => $th) {
        echo '<th style="cursor:pointer;user-select:none" onclick="ltSortTable(\'' . $cmp_tid . '\',' . $i . ',this)">' . $th . ' <span class="lt-sort-arrow">⇅</span></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($learners as $learner) {
        $uid = $learner->id;
        $done_count = 0; $total_count = count($gcourses);
        echo '<tr>';
        echo '<td>' . format_string($learner->firstname . ' ' . $learner->lastname) . '</td>';
        echo '<td>' . s($learner->email) . '</td>';
        foreach ($gcourses as $c) {
            $cid   = $c->id;
            $total = $course_totals[$cid] ?? 0;
            $done  = $mod_done[$uid][$cid] ?? 0;
            $is_complete = !empty($cc_done[$uid][$cid]) || ($total > 0 && $done >= $total);
            if ($is_complete) $done_count++;
            $pct   = $is_complete ? 100 : ($total > 0 ? (int)round($done/$total*100) : 0);
            $color = $is_complete ? '#10b981' : ($pct > 0 ? '#f59e0b' : '#d1d5db');
            $not_enrolled = !in_array($uid, $cmp_enrolled[$cid] ?? []);
            if ($not_enrolled && $cmp_is_admin) {
                $ne_url = new moodle_url('/local/learnpath/index.php', [
                    'groupid'       => $groupid,
                    'view'          => 'comparison',
                    'enroll_user'   => $uid,
                    'enroll_course' => $cid,
                    'sesskey'       => sesskey(),
                ]);
                $cname = format_string($c->fullname ?? '');
                echo '<td>' . html_writer::link($ne_url,
                    '<span style="background:#fef2f2;color:#dc2626;font-size:.68rem;font-weight:800;padding:2px 6px;border-radius:5px;border:1px solid #fca5a5" title="Not enrolled — click to enrol">+ Enrol</span>',
                    ['onclick' => "return confirm('Enrol this learner in ' + " . json_encode($cname) . " + '?')"]) . '</td>';
            } else {
                $label = $is_complete ? '✓' : ($pct > 0 ? $pct . '%' : '—');
                if ($cmp_is_admin) {
                    $unenrol_c_url = new moodle_url('/local/learnpath/index.php', [
                        'groupid'=>$groupid,'view'=>'comparison',
                        'unenrol_user'=>$uid,'unenrol_course'=>$cid,'sesskey'=>sesskey()]);
                    echo '<td style="white-space:nowrap"><span style="font-weight:700;color:' . $color . '">' . $label . '</span>&nbsp;'
                        . html_writer::link($unenrol_c_url,
                            '<span style="background:#fee2e2;color:#be123c;font-size:.64rem;font-weight:800;padding:1px 5px;border-radius:4px;border:1px solid #fca5a5" title="Unenrol from this course">− Off</span>',
                            ['onclick' => "return confirm('Unenrol this learner from this course?')"])
                        . '</td>';
                } else {
                    echo '<td><span style="font-weight:700;color:' . $color . '">' . $label . '</span></td>';
                }
            }
        }
        $overall = $total_count > 0 ? (int)round($done_count / $total_count * 100) : 0;
        $status  = $done_count === $total_count && $total_count > 0 ? 'Complete' : ($done_count > 0 ? 'In Progress' : 'Not Started');
        $scolor  = $done_count === $total_count && $total_count > 0 ? '#10b981' : ($done_count > 0 ? '#f59e0b' : '#9ca3af');
        echo '<td style="font-weight:700;color:#374151">' . $done_count . '/' . $total_count . '</td>';
        echo '<td><strong>' . $overall . '%</strong></td>';
        echo '<td style="color:' . $scolor . ';font-weight:700">' . $status . '</td>';
        $fa = isset($access[$uid]) ? userdate($access[$uid]->firstaccess, '%d/%m/%y') : '—';
        $la = isset($access[$uid]) ? userdate($access[$uid]->lastaccess,  '%d/%m/%y') : '—';
        echo '<td>' . $fa . '</td><td>' . $la . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function lt_sort_th(string $col, string $label, string $cur, string $dir): string {
    global $groupid, $view, $course_filter;
    $nd = ($cur === $col && $dir === 'asc') ? 'desc' : 'asc';
    $url = new moodle_url('/local/learnpath/index.php', ['groupid'=>$groupid,'view'=>$view,'sortcol'=>$col,'sortdir'=>$nd,'course_filter'=>$course_filter]);
    $arrow = $cur === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<th>' . html_writer::link($url, $label . $arrow, ['style' => 'color:inherit;text-decoration:none']) . '</th>';
}

function lt_progress_bar(int $pct): string {
    $color = $pct >= 100 ? '#10b981' : ($pct > 0 ? '#f59e0b' : '#d1d5db');
    return '<div style="display:flex;align-items:center;gap:7px">'
        . '<div style="width:80px;height:7px;background:#e5e7eb;border-radius:100px;overflow:hidden">'
        . '<div style="height:100%;width:' . $pct . '%;background:' . $color . ';border-radius:100px"></div></div>'
        . '<span style="font-size:.78rem;font-weight:800">' . $pct . '%</span></div>';
}

function lt_enroll_user_in_courses(int $userid, int $groupid): string {
    global $DB;
    // Get all courses in this path
    $courses = $DB->get_records('local_learnpath_group_courses', ['groupid' => $groupid]);
    $enrolled = 0;
    foreach ($courses as $lgc) {
        $ctx = context_course::instance($lgc->courseid, IGNORE_MISSING);
        if (!$ctx || is_enrolled($ctx, $userid)) { continue; }
        // Enroll via manual enrolment
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $lgc->courseid, 'enrol' => 'manual']);
        if ($enrol && $instance) {
            $enrol->enrol_user($instance, $userid);
            $enrolled++;
        }
    }
    if ($enrolled > 0) {
        // Send in-app + email notification to learner
        $learner = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        $group   = $DB->get_record('local_learnpath_groups', ['id' => $groupid]);
        if ($learner && $group) {
            // In-app notification
            $msg = new \core\message\message();
            $msg->component         = 'local_learnpath';
            $msg->name              = 'learntrack_reminder';
            $msg->userfrom          = \core_user::get_noreply_user();
            $msg->userto            = $learner;
            $msg->subject           = 'You have been enrolled in a learning path: ' . \format_string($group->name);
            $msg->fullmessage       = 'You have been enrolled in ' . $enrolled . ' course(s) in the learning path: ' . \format_string($group->name) . '. Log in to start learning.';
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml   = '<p>You have been enrolled in <strong>' . $enrolled . '</strong> course(s) in the learning path: <strong>' . \format_string($group->name) . '</strong>. Log in to start learning.</p>';
            $msg->smallmessage      = 'Enrolled in: ' . \format_string($group->name);
            $msg->notification      = 1;
            $msg->contexturl        = (new \moodle_url('/local/learnpath/mypath.php', ['groupid' => $groupid]))->out(false);
            $msg->contexturlname    = 'View My Learning Path';
            \message_send($msg);
            // Email
            $noreply = \core_user::get_noreply_user();
            $noreply->firstname = 'LearnTrack'; $noreply->lastname = '';
            \email_to_user($learner, $noreply, $msg->subject, $msg->fullmessage, $msg->fullmessagehtml);
        }
        return $enrolled . ' course(s) enrolled. Learner notified.';
    }
    return 'No manual enrolment available or already enrolled.';
}

function lt_render_summary(array $data, string $sc, string $sd, int $gid, string $view): void {
    global $DB;
    echo '<div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-family:var(--lt-font);font-size:.78rem">';
    echo '<label style="display:flex;align-items:center;gap:5px;color:#6b7280;cursor:pointer"><input type="checkbox" id="lt-select-all" onchange="ltToggleAll(this.checked)"> Select All</label>';
    echo '<button onclick="ltBulkAction(\'remind\')" style="font-size:.74rem;font-weight:700;padding:4px 10px;border-radius:6px;border:1.5px solid #e5e7eb;background:#fff;cursor:pointer;color:#374151">📢 Send Reminder</button>';
    echo '<button onclick="ltBulkAction(\'enroll\')" style="font-size:.74rem;font-weight:700;padding:4px 10px;border-radius:6px;border:1.5px solid #e5e7eb;background:#fff;cursor:pointer;color:#374151">➕ Enrol Selected</button>';
    echo '<span id="lt-bulk-count" style="color:#9ca3af"></span>';
    echo '</div>';
    echo '<div style="overflow-x:auto"><table class="lt-data-table" id="lt-summary-table"><thead><tr>';
    echo '<th style="width:32px"></th>'; // checkbox col
    echo lt_sort_th('lastname',        'Learner',    $sc, $sd);
    echo lt_sort_th('email',           'Email',      $sc, $sd);
    echo lt_sort_th('overall_progress','Progress',   $sc, $sd);
    echo lt_sort_th('completed_courses','Completed', $sc, $sd);
    echo '<th style="white-space:nowrap" title="Not Enrolled in course(s)">Not Enrolled</th>';
    echo lt_sort_th('engagement_score', 'Score', $sc, $sd);
    echo lt_sort_th('status_sort',      'Status', $sc, $sd);
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';
    if ($sc) {
        usort($data, function($a,$b) use ($sc,$sd) {
            $av = $a->$sc ?? ''; $bv = $b->$sc ?? '';
            $r  = is_numeric($av) ? ($av <=> $bv) : strcasecmp((string)$av, (string)$bv);
            return $sd === 'desc' ? -$r : $r;
        });
    }
    // Pre-load enrollment data for NE check (only if path has user_assign)
    $enrollment_map   = [];
    $enrolled_per_course = [];
    $has_assigned = $DB->record_exists('local_learnpath_user_assign', ['groupid' => $gid]);
    if ($has_assigned) {
        $path_crs = $DB->get_records('local_learnpath_group_courses', ['groupid' => $gid]);
        foreach ($path_crs as $lgc) {
            $cshort = $DB->get_field('course', 'shortname', ['id' => $lgc->courseid]) ?: $lgc->courseid;
            $enrollment_map[$lgc->courseid] = $cshort;
            $enrolled_per_course[$lgc->courseid] = $DB->get_fieldset_sql(
                "SELECT DISTINCT ue.userid FROM {user_enrolments} ue
                 JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid",
                ['cid' => $lgc->courseid]
            );
        }
    }

    foreach ($data as $row) {
        $status_map = ['complete'=>['#d1fae5','#065f46','✓ Done'],'inprogress'=>['#fef3c7','#92400e','⏳ Progress'],'notstarted'=>['#f3f4f6','#6b7280','○ Not started']];
        $s = $row->overall_progress >= 100 ? 'complete' : ($row->overall_progress > 0 ? 'inprogress' : 'notstarted');
        [$sbg, $sc2, $slbl] = $status_map[$s];
        $purl = new moodle_url('/local/learnpath/profile.php', ['userid'=>$row->userid,'groupid'=>$gid]);

        // NE check - uses pre-loaded $enrollment_map built outside loop
        $ne_courses = [];
        if (!empty($enrollment_map)) {
            foreach ($enrollment_map as $cid => $cshort) {
                if (!in_array($row->userid, $enrolled_per_course[$cid] ?? [])) {
                    $ne_courses[] = $cshort;
                }
            }
        }

        // Engagement score for this learner in this path
        $eng = \local_learnpath\data\helper::get_engagement_score((int)$row->userid, $gid);
        $eng_color = $eng >= 70 ? '#10b981' : ($eng >= 40 ? '#f59e0b' : '#ef4444');

        echo '<tr class="lt-learner-row" data-name="' . s(strtolower($row->firstname . ' ' . $row->lastname)) . '" data-email="' . s(strtolower($row->email ?? '')) . '">';
        echo '<td><input type="checkbox" class="lt-row-check" value="' . (int)$row->userid . '" onchange="ltCountSelected()"></td>';
        echo '<td><div class="lt-learner-name">' . format_string($row->firstname . ' ' . $row->lastname) . '</div></td>';
        echo '<td><a href="mailto:' . s($row->email) . '" class="lt-email">' . s($row->email) . '</a></td>';
        echo '<td>' . lt_progress_bar((int)$row->overall_progress) . '</td>';
        echo '<td>' . ($row->completed_courses ?? 0) . '/' . ($row->total_courses ?? 0) . '</td>';
        // Not Enrolled column
        $ne_count = count($ne_courses);
        if ($ne_count > 0 && has_capability('local/learnpath:manage', context_system::instance())) {
            $ne_all_url = new moodle_url('/local/learnpath/index.php', [
                'groupid'     => $gid,
                'view'        => 'summary',
                'enroll_user' => $row->userid,
                'sesskey'     => sesskey(),
            ]);
            $unenrol_all_url = new moodle_url('/local/learnpath/index.php', [
                'groupid'      => $gid,
                'view'         => 'summary',
                'unenrol_user' => $row->userid,
                'sesskey'      => sesskey(),
            ]);
            echo '<td style="white-space:nowrap">';
            echo html_writer::link($ne_all_url,
                '<span style="background:#fef2f2;color:#dc2626;font-size:.7rem;font-weight:800;padding:2px 7px;border-radius:6px;border:1.5px solid #fca5a5" title="Not enrolled in: ' . s(implode(', ', $ne_courses)) . '. Click to enrol in ALL missing courses.">+ Enrol</span>',
                ['onclick' => "return confirm('Enrol this learner in all " . $ne_count . " missing course(s)?')"]
            );
            echo '</td>';
        } else {
            $unenrol_all_url2 = new moodle_url('/local/learnpath/index.php', [
                'groupid'      => $gid,
                'view'         => 'summary',
                'unenrol_user' => $row->userid,
                'sesskey'      => sesskey(),
            ]);
            echo '<td>';
            echo html_writer::link($unenrol_all_url2,
                '<span style="background:#fef2f2;color:#dc2626;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:6px;border:1.5px solid #fca5a5" title="Unenrol from all path courses">− Unenrol</span>',
                ['onclick' => "return confirm('Unenrol this learner from ALL courses in this path?')"]
            );
            echo '</td>';
        }
        // Attach sortable fields to row for usort
        $row->engagement_score = $eng;
        $row->status_sort = $s;
        echo '<td><span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:' . $eng_color . '20;border:2px solid ' . $eng_color . ';font-size:.72rem;font-weight:800;color:' . $eng_color . '" title="Engagement Score: ' . $eng . '/100">' . $eng . '</span></td>';
        echo '<td><span style="background:' . $sbg . ';color:' . $sc2 . ';font-size:.72rem;font-weight:700;padding:3px 9px;border-radius:100px">' . $slbl . '</span></td>';
        echo '<td style="white-space:nowrap">';
        echo html_writer::link($purl, '👤 Profile', ['class'=>'lt-action-btn lt-btn-view']);
        // NE is now shown in the dedicated 'Not Enrolled' column above
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function lt_render_certs(int $gid, array $gcourses, bool $isadmin): void {
    global $DB, $USER;
    $learners = \local_learnpath\data\helper::get_learners_for_group($gid, $USER->id, 'active');
    if (empty($learners)) {
        echo '<div style="padding:32px;text-align:center;color:#9ca3af;font-family:var(--lt-font)">No learners in this path.</div>';
        return;
    }
    $certs = []; $dbman = $DB->get_manager();
    if ($dbman->table_exists(new xmldb_table('local_learnpath_certs'))) {
        foreach ($DB->get_records('local_learnpath_certs', ['groupid' => $gid]) as $cert) {
            $certs[$cert->userid] = $cert;
        }
    }
    $total_courses = count($gcourses);
    echo '<div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e5e7eb;font-family:var(--lt-font);font-size:.78rem;color:#6b7280">';
    echo count($certs) . ' certificate(s) issued · ' . count($learners) . ' learner(s) in path';
    echo '</div>';
    echo '<div style="overflow-x:auto"><table class="lt-data-table"><thead><tr>';
    echo '<th>Learner</th><th>Progress</th><th>Certificate</th><th>Issued</th><th>Ref #</th>';
    if ($isadmin) echo '<th>Action</th>';
    echo '</tr></thead><tbody>';
    foreach ($learners as $learner) {
        $cert = $certs[$learner->id] ?? null;
        $rows = \local_learnpath\data\helper::get_progress_summary($gid, $learner->id, 'active');
        $lrow = $rows[$learner->id] ?? null;
        $pct  = $lrow ? (int)($lrow->overall_progress ?? 0) : 0;
        $done = $lrow ? (int)($lrow->completed_courses ?? 0) : 0;
        $eligible = ($total_courses > 0 && $done >= $total_courses);
        echo '<tr>';
        echo '<td><div class="lt-learner-name">' . format_string($learner->firstname . ' ' . $learner->lastname) . '</div>';
        echo '<div style="font-size:.72rem;color:#9ca3af">' . s($learner->email) . '</div></td>';
        echo '<td>' . lt_progress_bar($pct) . '</td>';
        if ($cert) {
            echo '<td><span style="background:#d1fae5;color:#065f46;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:100px">🎓 Issued</span></td>';
            echo '<td style="font-size:.78rem">' . userdate($cert->issuedate, get_string('strftimedatefullshort')) . '</td>';
            echo '<td style="font-size:.78rem;color:#6b7280">' . s($cert->certnumber ?? '—') . '</td>';
            if ($isadmin) {
                $rurl = new moodle_url('/local/learnpath/profile.php', ['userid'=>$learner->id,'groupid'=>$gid,'action'=>'revoke_cert','sesskey'=>sesskey()]);
                echo '<td>' . html_writer::link($rurl, 'Revoke', ['class'=>'lt-action-btn lt-btn-del','onclick'=>"return confirm('Revoke certificate?')"]) . '</td>';
            }
        } else {
            $bs  = $eligible ? 'background:#fef3c7;color:#92400e' : 'background:#f3f4f6;color:#9ca3af';
            $bl  = $eligible ? '⏳ Eligible' : '○ Incomplete';
            echo '<td><span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:100px;' . $bs . '">' . $bl . '</span></td><td>—</td><td>—</td>';
            if ($isadmin && $eligible) {
                $iurl = new moodle_url('/local/learnpath/profile.php', ['userid'=>$learner->id,'groupid'=>$gid,'action'=>'issue_cert','sesskey'=>sesskey()]);
                echo '<td>' . html_writer::link($iurl, '🎓 Issue', ['class'=>'lt-action-btn lt-btn-edit']) . '</td>';
            } elseif ($isadmin) {
                echo '<td><span style="color:#d1d5db;font-size:.78rem">Not eligible</span></td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function lt_render_detail(array $data, string $sc, string $sd, int $gid, string $view, int $cf): void {
    global $DB;
    echo '<div style="overflow-x:auto"><table class="lt-data-table"><thead><tr>';
    echo lt_sort_th('lastname',  'Learner',  $sc, $sd);
    echo lt_sort_th('coursename','Course',   $sc, $sd);
    echo lt_sort_th('progress',  'Progress', $sc, $sd);
    echo '<th>Status</th><th>Activities</th><th>Enrolment</th></tr></thead><tbody>';
    if ($sc) {
        usort($data, function($a,$b) use ($sc,$sd) {
            $av = $a->$sc ?? ''; $bv = $b->$sc ?? '';
            $r  = is_numeric($av) ? ($av <=> $bv) : strcasecmp((string)$av, (string)$bv);
            return $sd === 'desc' ? -$r : $r;
        });
    }
    // Pre-load enrolled users per course for NE check
    $course_ids = array_unique(array_column($data, 'courseid'));
    $enrolled_per_course = [];
    foreach ($course_ids as $cid) {
        if (!$cid) continue;
        $enrolled_per_course[$cid] = $DB->get_fieldset_sql(
            'SELECT DISTINCT ue.userid FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid',
            ['cid' => $cid]
        );
    }
    $is_admin = has_capability('local/learnpath:manage', context_system::instance());

    foreach ($data as $row) {
        $icon = match($row->status ?? 'notstarted') { 'complete'=>'✅','inprogress'=>'⏳',default=>'○' };
        $cid  = $row->courseid ?? 0;
        $is_enrolled = !$cid || in_array($row->userid, $enrolled_per_course[$cid] ?? []);
        echo '<tr>';
        echo '<td>' . format_string($row->firstname . ' ' . $row->lastname) . '</td>';
        echo '<td>' . format_string($row->coursename) . '</td>';
        echo '<td>' . lt_progress_bar((int)($row->progress ?? 0)) . '</td>';
        echo '<td>' . $icon . ' ' . ucfirst($row->status ?? 'notstarted') . '</td>';
        echo '<td>' . ($row->completed_activities ?? 0) . '/' . ($row->total_activities ?? 0) . '</td>';
        echo '<td>';
        if (!$is_enrolled && $is_admin && $cid) {
            $cname_ne = format_string($row->coursename ?? '');
            $ne_url = new moodle_url('/local/learnpath/index.php', [
                'groupid'       => $gid,
                'view'          => 'detail',
                'enroll_user'   => $row->userid,
                'enroll_course' => $cid,
                'sesskey'       => sesskey(),
            ]);
            echo html_writer::link($ne_url,
                '<span style="background:#fef2f2;color:#dc2626;font-size:.7rem;font-weight:800;padding:3px 8px;border-radius:6px;border:1.5px solid #fca5a5" title="Not enrolled in this course. Click to enroll.">⚠ NE</span>',
                ['onclick' => "return confirm('Enroll in course: ' + " . json_encode($cname_ne) . " + ' only?')"]
            );
        } else {
            echo '<span style="color:#10b981;font-size:.75rem;font-weight:700">✓ Enrolled</span>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
