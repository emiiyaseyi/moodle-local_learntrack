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
 * LearnTrack — Course Insights. Clean rewrite — safe SQL for MySQL and PostgreSQL.
 * Completion uses course_completions (authoritative, matches Moodle's own report).
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

use local_learnpath\data\helper as DH;

require_login();
require_capability('local/learnpath:viewdashboard', context_system::instance());

$courseid   = optional_param('courseid',  0,       PARAM_INT);

// Handle enrol action
if ($courseid > 0 && has_capability('local/learnpath:manage', context_system::instance())
        && optional_param('enrol_user', 0, PARAM_INT) > 0 && confirm_sesskey()) {
    $enrol_uid    = required_param('enrol_user', PARAM_INT);
    $enrol_plugin = enrol_get_plugin('manual');
    $enrol_inst   = $DB->get_record('enrol', ['courseid'=>$courseid,'enrol'=>'manual']);
    if ($enrol_plugin && $enrol_inst) {
        $ctx_c = context_course::instance($courseid, IGNORE_MISSING);
        if ($ctx_c && !is_enrolled($ctx_c, $enrol_uid)) {
            $enrol_plugin->enrol_user($enrol_inst, $enrol_uid);
        }
    }
    redirect(new moodle_url('/local/learnpath/courseinsights.php',
        ['courseid'=>$courseid,'date_range'=>optional_param('date_range','all',PARAM_ALPHA)]),
        'Learner enrolled.', null, \core\output\notification::NOTIFY_SUCCESS);
}
$date_range = optional_param('date_range','7days',  PARAM_ALPHA);
$date_from  = optional_param('date_from', '',       PARAM_TEXT);
$date_to    = optional_param('date_to',   '',       PARAM_TEXT);
$chart_type = optional_param('chart',     'bar',    PARAM_ALPHA);
$page       = optional_param('page',      0,        PARAM_INT);
$perpage    = optional_param('perpage',   25,       PARAM_INT);
if (!in_array($perpage, [25,50,100,200])) { $perpage = 25; }

$now = time();
$from_ts = match($date_range) {
    '7days'  => strtotime('-7 days', $now),
    'week'   => strtotime('monday this week'),
    'month'  => mktime(0, 0, 0, (int)date('n'), 1),
    'year'   => mktime(0, 0, 0, 1, 1, (int)date('Y')),
    'all'    => 0,
    'custom' => ($date_from ? strtotime($date_from) : 0),
    default  => strtotime('-7 days', $now),
};
$to_ts = ($date_range === 'custom' && $date_to) ? strtotime($date_to . ' 23:59:59') : $now;

$PAGE->set_url(new moodle_url('/local/learnpath/courseinsights.php', ['courseid'=>$courseid,'date_range'=>$date_range]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Course Insights');

global $DB, $OUTPUT;
$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';

// All tracked courses for switcher
$all_path_courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname FROM {course} c
     JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = c.id
     ORDER BY c.fullname ASC"
);

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome', ['style' => 'display:inline-block;margin-bottom:14px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
try {
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';

// Nav
echo html_writer::link(new moodle_url('/local/learnpath/overview.php'), '← Overview',
    ['style'=>'display:inline-block;margin-bottom:6px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
echo ' &nbsp; ';
echo html_writer::link(new moodle_url('/local/learnpath/index.php'), '📊 Dashboard',
    ['style'=>'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

// Hero — overflow:visible so course dropdown never clips
echo '<div class="lt-page-header" style="overflow:visible;margin-bottom:0">';
echo '<h1 class="lt-page-title">📈 Course Insights</h1>';
echo '<p class="lt-page-subtitle">Individual course analytics</p>';
echo '</div>';

// Course selector — standalone row below hero
echo '<div class="lt-toolbar-wrap" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;border-radius:0 0 12px 12px;margin-bottom:14px">';
echo '<label class="lt-label">SELECT COURSE</label>';
echo '<select style="flex:1;min-width:220px;max-width:520px;font-family:var(--lt-font);font-size:.86rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:7px 12px;background:#fff;color:#111827;cursor:pointer" '
    . 'onchange="window.location=\'/local/learnpath/courseinsights.php?courseid=\'+this.value+\'&date_range=' . urlencode($date_range) . '\'">';
echo '<option value="0" style="color:#111827">— Choose a course —</option>';
foreach ($all_path_courses as $pc) {
    $sel = ((int)$pc->id === (int)$courseid) ? ' selected' : '';
    echo '<option value="' . $pc->id . '"' . $sel . ' style="color:#111827">' . format_string($pc->fullname) . '</option>';
}
echo '</select>';
echo '</div>';

if (!$courseid) {
    echo '<div class="lt-empty-state"><div class="lt-empty-icon">📈</div>';
    echo '<h3 class="lt-empty-title">Select a Course</h3>';
    echo '<p class="lt-empty-desc">Choose a course from the dropdown above to view analytics.</p></div>';
    echo html_writer::script('
function ltSortTable(tableId,colIdx,thEl){
    var t=document.getElementById(tableId);if(!t)return;
    var tb=t.tBodies[0],rows=Array.from(tb.rows);
    var asc=thEl.dataset.sortDir!=="asc";thEl.dataset.sortDir=asc?"asc":"desc";
    t.querySelectorAll(".lt-sort-arrow").forEach(function(a){a.textContent="⇅";});
    thEl.querySelector(".lt-sort-arrow").textContent=asc?"↑":"↓";
    rows.sort(function(a,b){
        var av=a.cells[colIdx]?a.cells[colIdx].innerText.trim():"";
        var bv=b.cells[colIdx]?b.cells[colIdx].innerText.trim():"";
        var an=parseFloat(av.replace("%","")),bn=parseFloat(bv.replace("%",""));
        var cmp=(!isNaN(an)&&!isNaN(bn))?(an-bn):av.localeCompare(bv);
        return asc?cmp:-cmp;
    });
    rows.forEach(function(r){tb.appendChild(r);});
}
');
echo $OUTPUT->footer(); exit;
}

$course     = $DB->get_record('course', ['id'=>$courseid], '*', MUST_EXIST);
$ctx_course = context_course::instance($courseid);

// Enrolled count
$enrolled = (int)\count_enrolled_users($ctx_course);

// --- Completions: use timecompleted > 0 (the only reliable field in course_completions) ---
// Matches get_course_progress() in helper.php which powers mypath.php (confirmed working)
$completed_userids_rows = $DB->get_records_sql(
    "SELECT DISTINCT userid FROM {course_completions}
     WHERE course = :cid AND timecompleted > 0",
    ['cid' => $courseid]
);
$completed_userids   = array_keys($completed_userids_rows);
$completed_total     = count($completed_userids);
$completion_rate     = $enrolled > 0 ? (int)round($completed_total / $enrolled * 100) : 0;

// Period completions
if ($from_ts > 0) {
    $completed_period = (int)$DB->get_field_sql(
        "SELECT COUNT(DISTINCT userid) FROM {course_completions}
         WHERE course = :cid AND timecompleted > 0
           AND timecompleted >= :from AND timecompleted <= :to",
        ['cid'=>$courseid, 'from'=>$from_ts, 'to'=>$to_ts]
    );
} else {
    $completed_period = $completed_total;
}

// Average grade — safe (no cartesian JOIN)
$avg_grade = null; $grade_max = null; $avg_grade_pct = null; $grade_item = null;
try {
    $grade_item = $DB->get_record('grade_items', ['courseid'=>$courseid, 'itemtype'=>'course']);
    if ($grade_item) {
        $avg_row = $DB->get_field_sql(
            "SELECT AVG(finalgrade) FROM {grade_grades} WHERE itemid=:iid AND finalgrade IS NOT NULL",
            ['iid'=>$grade_item->id]
        );
        if ($avg_row !== null && $avg_row !== false) {
            $avg_grade     = round((float)$avg_row, 1);
            $grade_max     = round((float)$grade_item->grademax, 1);
            $avg_grade_pct = $grade_max > 0 ? (int)round($avg_grade / $grade_max * 100) : null;
        }
    }
} catch (\Throwable $e) { /* grade not set up */ }

// Module completion counts (one query for all users)
$total_mods = (int)$DB->count_records_sql(
    "SELECT COUNT(id) FROM {course_modules}
     WHERE course=:cid AND completion>0 AND deletioninprogress=0",
    ['cid'=>$courseid]
);
$mod_comp_counts = [];
if ($total_mods > 0) {
    // Apply date filter to module completions so chart updates with period selection
    $mod_date_where  = '';
    $mod_date_params = ['cid' => $courseid];
    if ($from_ts > 0) {
        $mod_date_where = ' AND cmc.timemodified >= :mfrom AND cmc.timemodified <= :mto';
        $mod_date_params['mfrom'] = $from_ts;
        $mod_date_params['mto']   = $to_ts;
    }
    $rows = $DB->get_records_sql(
        "SELECT cmc.userid, COUNT(cmc.id) AS done
         FROM {course_modules_completion} cmc
         JOIN {course_modules} cm ON cm.id=cmc.coursemoduleid
         WHERE cm.course=:cid AND cmc.completionstate IN (1,2)
           AND cm.deletioninprogress=0{$mod_date_where}
         GROUP BY cmc.userid",
        $mod_date_params
    );
    foreach ($rows as $r) { $mod_comp_counts[$r->userid] = (int)$r->done; }
}

// Progress distribution
$enrolled_uids = $DB->get_records_sql(
    "SELECT DISTINCT u.id AS userid FROM {user_enrolments} ue
     JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid
     JOIN {user} u ON u.id=ue.userid AND u.deleted=0",
    ['cid'=>$courseid]
);
$buckets = ['0'=>0,'1_25'=>0,'26_50'=>0,'51_75'=>0,'76_99'=>0,'100'=>0];
foreach ($enrolled_uids as $eu) {
    $uid = $eu->userid;
    if (in_array($uid, $completed_userids)) { $buckets['100']++; continue; }
    $done = $mod_comp_counts[$uid] ?? 0;
    $pct  = $total_mods > 0 ? (int)round($done/$total_mods*100) : 0;
    if ($pct >= 100) { $buckets['100']++; }        // 6/6 modules = complete
    elseif ($pct===0)   $buckets['0']++;
    elseif ($pct<=25)   $buckets['1_25']++;
    elseif ($pct<=50)   $buckets['26_50']++;
    elseif ($pct<=75)   $buckets['51_75']++;
    else                $buckets['76_99']++;
}
$bvals  = array_values($buckets);
$blbls  = ['Not started','1–25%','26–50%','51–75%','76–99%','Complete'];
$bclrs  = ['#e5e7eb','#fca5a5','#fcd34d','#6ee7b7','#34d399','#10b981'];
$maxb   = max($bvals) ?: 1;
$total_b = array_sum($bvals);

// Override completed_total to match the chart (includes module-based completions)
// This ensures the stat strip is consistent with the progress distribution chart
$completed_total  = $buckets['100'];
$completion_rate  = $enrolled > 0 ? (int)round($completed_total / $enrolled * 100) : 0;
// For All Time, period = total; for date filters use the already-computed period value
if ($from_ts === 0) {
    $completed_period = $completed_total;
}

// Inactive — enrolled but no recent access and not complete
$inactive = [];
$inactive_days = (int)get_config('local_learnpath', 'inactive_days');
if ($inactive_days > 0) {
    try {
        $cutoff = time() - ($inactive_days * 86400);
        // Simple safe query: not completed + last access before cutoff
        $inactive = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :cid
              JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
              LEFT JOIN {course_completions} cc
                     ON cc.userid = u.id AND cc.course = :cid2
                    AND cc.timecompleted > 0
              LEFT JOIN {user_lastaccess} la ON la.userid = u.id AND la.courseid = :cid3
              WHERE cc.id IS NULL
                AND (la.timeaccess IS NULL OR la.timeaccess < :cutoff)
              ORDER BY la.timeaccess ASC",
            ['cid'=>$courseid, 'cid2'=>$courseid, 'cid3'=>$courseid, 'cutoff'=>$cutoff],
            0, 20
        );
    } catch (\Throwable $e) {
        $inactive = [];
    }
}

// Learner table
// Learner query — u.id is the unique key, completions fetched separately
$total_learner_rows = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id) FROM {user_enrolments} ue
     JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid
     JOIN {user} u ON u.id=ue.userid AND u.deleted=0",
    ['cid'=>$courseid]
);
$learner_sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid
                JOIN {user} u ON u.id=ue.userid AND u.deleted=0
                ORDER BY u.lastname ASC, u.firstname ASC";
$learners_data = $DB->get_records_sql($learner_sql, ['cid'=>$courseid], $page*$perpage, $perpage);
// Fetch completion dates separately (avoids non-unique row issue from LEFT JOIN)
$completion_dates = $DB->get_records_sql(
    "SELECT userid, MAX(timecompleted) AS completed_ts
     FROM {course_completions} WHERE course=:cid AND timecompleted > 0
     GROUP BY userid",
    ['cid'=>$courseid]
);

// Grade per user (separate query, no cartesian product)
$user_grades = [];
if ($grade_item) {
    $gr = $DB->get_records_sql(
        "SELECT userid, finalgrade FROM {grade_grades} WHERE itemid=:iid AND finalgrade IS NOT NULL",
        ['iid'=>$grade_item->id]
    );
    foreach ($gr as $g) { $user_grades[$g->userid] = round((float)$g->finalgrade, 1); }
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────

// Date bar
echo '<div class="lt-date-bar" style="margin-bottom:14px">';
foreach (['7days'=>'Last 7 Days','week'=>'This Week','month'=>'This Month','year'=>'This Year','all'=>'All Time'] as $rk=>$rl) {
    $rurl = new moodle_url('/local/learnpath/courseinsights.php',['courseid'=>$courseid,'date_range'=>$rk]);
    echo html_writer::link($rurl,$rl,['class'=>'lt-date-chip '.($date_range===$rk?'lt-date-active':'lt-date-inactive')]);
}
echo '<form method="get" style="display:flex;align-items:center;gap:5px">';
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'courseid','value'=>$courseid]);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'date_range','value'=>'custom']);
echo '<input type="date" name="date_from" value="'.s($date_from).'" style="font-size:.74rem;border:1.5px solid #e5e7eb;border-radius:6px;padding:3px 7px">';
echo '<span style="font-size:.72rem;color:#9ca3af">–</span>';
echo '<input type="date" name="date_to" value="'.s($date_to).'" style="font-size:.74rem;border:1.5px solid #e5e7eb;border-radius:6px;padding:3px 7px">';
echo '<button type="submit" style="font-size:.72rem;font-weight:700;background:#374151;color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer">Apply</button>';
echo '</form></div>';

// Stats
echo '<div class="lt-stats-strip" style="grid-template-columns:repeat(4,1fr)">';
foreach ([
    ['👥',$enrolled,'Enrolled','lt-icon-blue'],
    ['✅',$completed_period,'Completed'.($from_ts>0?' (period)':''),'lt-icon-green'],
    ['📊',$completion_rate.'%','Completion Rate','lt-icon-amber'],
    ['⭐',$avg_grade!==null?$avg_grade.'/'.$grade_max:'—','Avg Grade','lt-icon-purple'],
] as [$icon,$val,$label,$cls]) {
    echo '<div class="lt-stat-card"><div class="lt-stat-icon '.$cls.'">'.$icon.'</div>';
    echo '<div class="lt-stat-text"><span class="lt-stat-value">'.s((string)$val).'</span><span class="lt-stat-label">'.$label.'</span></div></div>';
}
echo '</div>';

// Chart + inactive panel
echo '<div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px">';

echo '<div class="lt-card"><div class="lt-card-header">';
echo '<h3 class="lt-card-title">📊 Progress Distribution</h3>';
echo '<div style="display:flex;gap:4px">';
foreach (['bar'=>'Bar','column'=>'Column','donut'=>'Donut'] as $ct=>$cl) {
    $curl  = new moodle_url('/local/learnpath/courseinsights.php',['courseid'=>$courseid,'date_range'=>$date_range,'chart'=>$ct]);
    $astyle = $chart_type===$ct ? 'background:var(--lt-accent);color:#fff;border-color:var(--lt-accent)' : 'background:#fff;color:#374151;border-color:#e5e7eb';
    echo html_writer::link($curl,$cl,['style'=>'font-family:var(--lt-font);font-size:.76rem;font-weight:700;padding:4px 10px;border-radius:6px;text-decoration:none;border:1.5px solid;'.$astyle]);
}
echo '</div></div><div class="lt-card-body">';

if ($chart_type==='donut') {
    $cx=110;$cy=100;$r=72;$sw=26;$circ=2*M_PI*$r;
    echo '<svg viewBox="0 0 220 200" style="max-width:200px;display:block;margin:0 auto">';
    $off=0;
    for ($i=0;$i<count($bvals);$i++) {
        $seg=$total_b>0?$bvals[$i]/$total_b*$circ:0;
        echo '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="'.$bclrs[$i].'" stroke-width="'.$sw.'" stroke-dasharray="'.$seg.' '.($circ-$seg).'" stroke-dashoffset="'.(-$off).'" transform="rotate(-90 '.$cx.' '.$cy.')"/>';
        $off+=$seg;
    }
    echo '<text x="'.$cx.'" y="'.($cy+5).'" text-anchor="middle" font-size="20" font-weight="800" fill="#111827">'.$completion_rate.'%</text></svg>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-top:8px">';
    for ($i=0;$i<count($blbls);$i++) {
        echo '<div style="display:flex;align-items:center;gap:5px;font-family:var(--lt-font);font-size:.74rem"><div style="width:10px;height:10px;border-radius:2px;background:'.$bclrs[$i].';flex-shrink:0"></div><span>'.$blbls[$i].'</span><span style="color:#9ca3af;margin-left:auto">'.$bvals[$i].'</span></div>';
    }
    echo '</div>';
} elseif ($chart_type==='column') {
    echo '<div style="display:flex;align-items:flex-end;gap:7px;height:100px;margin-bottom:4px">';
    for ($i=0;$i<count($bvals);$i++) {
        $h=$maxb>0?(int)round($bvals[$i]/$maxb*90):0;
        echo '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px" title="'.$blbls[$i].': '.$bvals[$i].'"><span style="font-size:.66rem;font-weight:700;color:#374151;font-family:var(--lt-font)">'.$bvals[$i].'</span><div style="width:100%;height:'.$h.'px;background:'.$bclrs[$i].';border-radius:3px 3px 0 0;min-height:2px"></div></div>';
    }
    echo '</div><div style="display:flex;gap:7px">';
    for ($i=0;$i<count($blbls);$i++) { echo '<div style="flex:1;font-size:.62rem;text-align:center;color:#9ca3af;font-family:var(--lt-font)">'.$blbls[$i].'</div>'; }
    echo '</div>';
} else {
    for ($i=0;$i<count($bvals);$i++) {
        $w=$maxb>0?(int)round($bvals[$i]/$maxb*100):0;
        echo '<div style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;font-family:var(--lt-font);font-size:.74rem;margin-bottom:3px"><span style="color:#374151">'.$blbls[$i].'</span><span style="font-weight:700">'.$bvals[$i].'</span></div><div style="height:10px;background:#f3f4f6;border-radius:100px;overflow:hidden"><div style="height:100%;width:'.$w.'%;background:'.$bclrs[$i].';border-radius:100px"></div></div></div>';
    }
}
echo '</div></div>';

echo '<div class="lt-card"><div class="lt-card-header"><h3 class="lt-card-title">⚠️ Inactive</h3><span style="font-size:.72rem;color:#9ca3af;font-family:var(--lt-font)">'.($inactive_days>0?$inactive_days.'+ days':'Not set').'</span></div><div class="lt-card-body" style="max-height:300px;overflow-y:auto">';
if ($inactive_days<=0) { echo '<p style="font-family:var(--lt-font);font-size:.82rem;color:#9ca3af">Configure threshold in Settings.</p>'; }
elseif (empty($inactive)) { echo '<p style="font-family:var(--lt-font);font-size:.82rem;color:#10b981">✓ No inactive learners!</p>'; }
else { foreach ($inactive as $il) { echo '<div style="padding:7px 0;border-bottom:1px solid #f9fafb;font-family:var(--lt-font)"><div style="font-size:.84rem;font-weight:600;color:#111827">'.format_string($il->firstname.' '.$il->lastname).'</div><div style="font-size:.74rem;color:#9ca3af">'.s($il->email).'</div></div>'; } }
echo '</div></div></div>';

// Learner table
// Export URLs for course insights
$export_base = new moodle_url('/local/learnpath/export.php', [
    'groupid'    => 0,
    'courseid'   => $courseid,
    'date_range' => $date_range,
    'sesskey'    => sesskey(),
]);
echo '<div class="lt-card" style="margin-bottom:14px"><div class="lt-card-header"><h3 class="lt-card-title">👥 Learner Progress</h3>';
echo '<div style="display:flex;gap:6px;align-items:center">';
foreach (['xlsx' => '📊 Excel', 'csv' => '📄 CSV', 'pdf' => '🖨 PDF'] as $fmt => $lbl) {
    $eurl = clone $export_base;
    $eurl->param('format', $fmt);
    echo html_writer::link($eurl, $lbl, [
        'style' => 'font-family:var(--lt-font);font-size:.74rem;font-weight:700;padding:4px 10px;border-radius:6px;text-decoration:none;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb'
    ]);
}
// Get a group that contains this course (for email/schedule)
$course_group = $DB->get_record('local_learnpath_group_courses', ['courseid' => $courseid]);
if ($course_group && has_capability('local/learnpath:emailreport', context_system::instance())) {
    $mailurl  = new moodle_url('/local/learnpath/email.php',     ['groupid' => $course_group->groupid]);
    $schedurl = new moodle_url('/local/learnpath/schedule.php',  ['groupid' => $course_group->groupid]);
    $remurl   = new moodle_url('/local/learnpath/reminders.php', ['groupid' => $course_group->groupid]);
    $btn = 'font-family:var(--lt-font);font-size:.74rem;font-weight:700;padding:4px 10px;border-radius:6px;text-decoration:none;border:1px solid';
    echo html_writer::link($mailurl,  '✉️ Email',     ['style' => $btn . ' #bfdbfe;background:#eff6ff;color:#1e40af']);
    echo html_writer::link($schedurl, '📅 Schedule',  ['style' => $btn . ' #bbf7d0;background:#f0fdf4;color:#065f46']);
    echo html_writer::link($remurl,   '🔔 Reminders', ['style' => $btn . ' #fde68a;background:#fef3c7;color:#92400e']);
}
echo '</div>';
echo '<div style="display:flex;align-items:center;gap:6px"><div style="display:flex;gap:3px">';
foreach ([25,50,100,200] as $pp) {
    $purl=new moodle_url('/local/learnpath/courseinsights.php',['courseid'=>$courseid,'date_range'=>$date_range,'perpage'=>$pp,'chart'=>$chart_type]);
    $act=$perpage===$pp;
    echo html_writer::link($purl,$pp,['style'=>'font-size:.74rem;font-weight:700;padding:3px 8px;border-radius:5px;text-decoration:none;'.($act?'background:var(--lt-accent);color:#fff':'background:#f3f4f6;color:#374151')]);
}
echo '</div><span style="font-family:var(--lt-font);font-size:.74rem;color:#9ca3af">'.$total_learner_rows.' learners</span></div></div>';

echo '<div style="overflow-x:auto"><table class="lt-data-table" id="ci-learners"><thead><tr>';
foreach (['Learner','Email','Status','Activities','Progress','Grade','Completed','Enrol'] as $ci_i => $h) {
    echo '<th style="cursor:pointer;user-select:none" onclick="ltSortTable(\'ci-learners\',' . $ci_i . ',this)">' . $h . ' <span class="lt-sort-arrow">&#8645;</span></th>';
}
echo '</tr></thead><tbody>';

// Pre-load enrolled user IDs for this course (for enrol button)
$ci_enrolled_ids = $DB->get_fieldset_sql(
    'SELECT DISTINCT ue.userid FROM {user_enrolments} ue JOIN {enrol} e ON e.id=ue.enrolid WHERE e.courseid=:cid',
    ['cid' => $courseid]
);
$ci_enrolled_ids = array_flip($ci_enrolled_ids);

foreach ($learners_data as $ld) {
    $uid = $ld->id;
    $comp_rec = $completion_dates[$uid] ?? null;
    $is_complete = in_array($uid, $completed_userids)
        || ($comp_rec && !empty($comp_rec->completed_ts));

    if ($is_complete) { $pct=100; $stat='complete'; }
    else {
        $done = $mod_comp_counts[$uid] ?? 0;
        $pct  = $total_mods>0 ? (int)round($done/$total_mods*100) : 0;
        // If all modules done, mark as complete regardless of course_completions record
        if ($pct >= 100) { $is_complete = true; $pct = 100; }
        $stat = $pct>0?'inprogress':'notstarted';
    }
    $bc   = match($stat){'complete'=>'#10b981','inprogress'=>'#f59e0b',default=>'#d1d5db'};
    $badge= match($stat){
        'complete'  =>'<span style="background:#d1fae5;color:#065f46;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">✓ Complete</span>',
        'inprogress'=>'<span style="background:#fef3c7;color:#92400e;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">⏳ In Progress</span>',
        default     =>'<span style="background:#f3f4f6;color:#6b7280;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">○ Not Started</span>',
    };
    $grade_val = isset($user_grades[$uid]) ? $user_grades[$uid].($grade_max?'/'.$grade_max:'') : '—';
    $tc = $comp_rec->completed_ts ?? 0;
    $comp_date = ($tc > 0) ? userdate($tc, get_string('strftimedatefullshort')) : ($is_complete ? 'Complete' : '—');

    echo '<tr>';
    echo '<td><div class="lt-learner-name">'.format_string($ld->firstname.' '.$ld->lastname).'</div><div class="lt-learner-sub">@'.s($ld->username).'</div></td>';
    echo '<td><a href="mailto:'.s($ld->email).'" class="lt-email">'.s($ld->email).'</a></td>';
    echo '<td>'.$badge.'</td>';
    $acts_done  = $mod_comp_counts[$uid] ?? 0;
    $acts_total = $total_mods;
    echo '<td><span style="font-family:var(--lt-font);font-size:.82rem;font-weight:600;color:#374151">'
        . $acts_done . '/' . $acts_total . '</span></td>';
    echo '<td><div class="lt-progress-wrap"><div class="lt-progress-track"><div class="lt-progress-fill" style="width:'.$pct.'%;background:'.$bc.'"></div></div><span class="lt-progress-pct">'.$pct.'%</span></div></td>';
    echo '<td><span class="lt-grade">'.$grade_val.'</span></td>';
    echo '<td><span class="lt-date'.($is_complete?' lt-date-done':'').'">'.$comp_date.'</span></td>';
    // Enrol cell
    $is_ci_enrolled = isset($ci_enrolled_ids[$uid]);
    if (!$is_ci_enrolled && has_capability('local/learnpath:manage', context_system::instance())) {
        $enr_url = new moodle_url('/local/learnpath/courseinsights.php', [
            'courseid'    => $courseid,
            'enrol_user'  => $uid,
            'sesskey'     => sesskey(),
            'date_range'  => $date_range,
        ]);
        echo '<td>';
        echo html_writer::link($enr_url,
            '<span style="background:#fef2f2;color:#dc2626;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:6px;border:1.5px solid #fca5a5">&#9888; Enrol</span>',
            ['onclick' => "return confirm('Enrol ' + " . json_encode(format_string($ld->firstname.' '.$ld->lastname)) . " + ' in this course?')"]);
        echo '</td>';
    } else {
        echo '<td><span style="color:#10b981;font-size:.74rem">&#10003; Enrolled</span></td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div>';

if ($total_learner_rows>$perpage) {
    $pages=(int)ceil($total_learner_rows/$perpage);
    $base=new moodle_url('/local/learnpath/courseinsights.php',['courseid'=>$courseid,'date_range'=>$date_range,'perpage'=>$perpage,'chart'=>$chart_type]);
    echo '<div class="lt-pagination" style="padding:10px 16px;border-top:1px solid #f3f4f6"><span>'.$total_learner_rows.' learners · Page '.($page+1).'/'.$pages.'</span><div style="margin-left:auto;display:flex;gap:3px">';
    if ($page>0){$p=clone $base;$p->param('page',$page-1);echo html_writer::link($p,'←',['class'=>'lt-page-link inactive']);}
    for($i=max(0,$page-2);$i<=min($pages-1,$page+2);$i++){$p=clone $base;$p->param('page',$i);echo html_writer::link($p,$i+1,['class'=>'lt-page-link '.($i===$page?'active':'inactive')]);}
    if ($page<$pages-1){$p=clone $base;$p->param('page',$page+1);echo html_writer::link($p,'→',['class'=>'lt-page-link inactive']);}
    echo '</div></div>';
}
echo '</div>';

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'.html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank']).'<span class="lt-sep">·</span><span>LearnTrack v1.0.0</span></div>';
} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '<br><small>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small></div>';
}
echo $OUTPUT->footer();
