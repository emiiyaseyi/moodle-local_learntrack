<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$ctx = context_system::instance();
if (!has_capability('local/learnpath:manage', $ctx) && !has_capability('local/learnpath:viewdashboard', $ctx)) {
    throw new required_capability_exception($ctx, 'local/learnpath:viewdashboard', 'nopermissions', '');
}

// Overview always shows all-time data (no date filter)
$now     = time();
$from_ts = 0;
$to_ts   = $now;

$PAGE->set_url(new moodle_url('/local/learnpath/overview.php'));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Overview');

global $DB, $OUTPUT, $USER;
$brand   = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$isadmin = has_capability('local/learnpath:manage', $ctx);

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome', ['style' => 'display:inline-block;margin-bottom:14px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
try {

echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';

// Nav
echo html_writer::link(new moodle_url('/local/learnpath/index.php'), '📊 Dashboard',
    ['style' => 'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

// Page header
echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo '<h1 class="lt-page-title">📡 LearnTrack Overview</h1>';
echo '<p class="lt-page-subtitle">Site-wide analytics</p>';
echo '</div><div class="lt-header-actions">';
if ($isadmin) {
    echo html_writer::link(new moodle_url('/local/learnpath/manage.php'), '⚙️ Manage',
        ['class' => 'lt-btn lt-btn-outline']);
}
echo '</div></div></div>';



// Stats
try { $stats = \local_learnpath\data\helper::get_site_stats($from_ts, $to_ts); } catch (\Throwable $e) {
    $stats = (object)['total_paths'=>$DB->count_records('local_learnpath_groups'),
        'total_course_links'=>$DB->count_records('local_learnpath_group_courses'),
        'total_learners'=>0,'total_completions'=>0,'completion_trend'=>0,
        'avg_progress'=>null,'this_month_completions'=>0,'last_month_completions'=>0];
}

$trend_icon  = ($stats->completion_trend ?? 0) >= 0 ? '↑' : '↓';
$trend_color = ($stats->completion_trend ?? 0) >= 0 ? '#10b981' : '#ef4444';
// Count unique courses across all paths
$total_unique_courses = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT courseid) FROM {local_learnpath_group_courses}"
);
$avg_progress_val = $stats->avg_progress !== null ? (int)round((float)$stats->avg_progress) : 0;

echo '<div class="lt-stats-strip" style="grid-template-columns:repeat(5,1fr)">';
foreach ([
    ['📚', $stats->total_paths,        'Learning Paths',   'lt-icon-indigo'],
    ['🎓', $total_unique_courses,       'Unique Courses',   'lt-icon-purple'],
    ['👥', $stats->total_learners,      'Total Learners',   'lt-icon-blue'],
    ['✅', $stats->total_completions,   'Total Completions','lt-icon-green'],
    ['📈', $avg_progress_val . '%',     'Avg Progress',     'lt-icon-amber'],
] as [$icon, $val, $label, $cls]) {
    echo '<div class="lt-stat-card"><div class="lt-stat-icon ' . $cls . '">' . $icon . '</div>';
    echo '<div class="lt-stat-text"><span class="lt-stat-value">' . $val . '</span>';
    echo '<span class="lt-stat-label">' . $label . '</span></div></div>';
}
echo '</div>';

// Completion Trend chart
try { $dailyc = \local_learnpath\data\helper::get_daily_completions(max($from_ts, strtotime('-30 days')), $to_ts); } catch (\Throwable $e) { $dailyc = []; }
echo '<div class="lt-card"><div class="lt-card-header"><h3 class="lt-card-title">📈 Completion Trend</h3>';
echo '<span style="font-size:.72rem;color:#9ca3af;font-family:var(--lt-font)">' . (count($dailyc) > 0 ? 'Last 30 days · ' . count($dailyc) . ' days' : 'All time') . '</span></div>';
echo '<div class="lt-card-body">';
if (empty($dailyc)) {
    echo '<p style="font-family:var(--lt-font);font-size:.84rem;color:#9ca3af">No completion data for this period.</p>';
} else {
    $max_count = !empty($dailyc) ? max(array_values($dailyc)) : 1;
    $show_labels = count($dailyc) <= 14; // only show date labels if 14 days or fewer
    echo '<div style="overflow-x:auto">';
    echo '<div style="display:flex;align-items:flex-end;gap:3px;height:100px;min-width:' . (count($dailyc) * 28) . 'px">';
    foreach ($dailyc as $day => $count) {
        $h   = (int)round((int)$count / $max_count * 80);
        $lbl = date('d M', strtotime($day)); // e.g. "03 Apr"
        echo '<div style="flex:1;min-width:24px;display:flex;flex-direction:column;align-items:center;gap:1px">';
        // Count above bar
        echo '<span style="font-size:.6rem;font-weight:700;color:#374151;font-family:var(--lt-font)">' . (int)$count . '</span>';
        // Bar
        echo '<div style="width:100%;height:' . $h . 'px;background:var(--lt-accent);border-radius:3px 3px 0 0;min-height:3px" title="' . s($day) . ': ' . (int)$count . ' completion(s)"></div>';
        // Date label under bar
        if ($show_labels) {
            echo '<span style="font-size:.58rem;color:#9ca3af;font-family:var(--lt-font);margin-top:2px;text-align:center;white-space:nowrap">' . $lbl . '</span>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}
echo '</div></div>';

// Top Learners + At Risk side by side
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">';

// Top Learners
try { $toplrn = \local_learnpath\data\helper::get_top_learners($from_ts, 10); } catch (\Throwable $e) { $toplrn = []; }
echo '<div class="lt-card"><div class="lt-card-header"><h3 class="lt-card-title">🏆 Top 10 Learners</h3>';
echo '<span style="font-size:.72rem;color:#9ca3af;font-family:var(--lt-font)">By completions — all time</span></div>';
echo '<div class="lt-card-body">';
$medals = ['🥇','🥈','🥉'];
$rank = 0;
// Pre-load total points per user (from leaderboard points table if it exists)
$dbman_ldr = $DB->get_manager();
$points_by_user = [];
if ($dbman_ldr->table_exists(new xmldb_table('local_learnpath_points'))) {
    $pts_rows = $DB->get_records_sql(
        "SELECT userid, SUM(points) AS total FROM {local_learnpath_points} GROUP BY userid"
    );
    foreach ($pts_rows as $pr) { $points_by_user[$pr->userid] = (int)$pr->total; }
}
if (empty($toplrn)) {
    echo '<p style="font-family:var(--lt-font);font-size:.82rem;color:#9ca3af">No completions recorded yet.</p>';
} else {
    foreach ($toplrn as $tl) {
        $m = $medals[$rank] ?? '#' . ($rank + 1);
        $pts = $points_by_user[$tl->id] ?? ($tl->completions * 10); // fallback: 10 pts per completion
        echo '<div style="display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid #f9fafb;font-family:var(--lt-font)">';
        echo '<span style="font-size:1rem;width:24px;flex-shrink:0;text-align:center">' . $m . '</span>';
        echo '<div style="flex:1">';
        echo '<div style="font-size:.84rem;font-weight:700;color:#111827">' . format_string($tl->firstname . ' ' . $tl->lastname) . '</div>';
        echo '<div style="font-size:.72rem;color:#9ca3af">' . $tl->completions . ' course' . ($tl->completions != 1 ? 's' : '') . ' completed</div>';
        echo '</div>';
        echo '<span style="font-size:.78rem;font-weight:700;color:var(--lt-accent);white-space:nowrap">' . $pts . ' pts</span>';
        echo '</div>';
        $rank++;
    }
}
echo '</div></div>';

// At Risk
try { $atrisk = \local_learnpath\data\helper::get_at_risk_learners(30, 20); } catch (\Throwable $e) { $atrisk = []; }
echo '<div class="lt-card"><div class="lt-card-header"><h3 class="lt-card-title">⚠️ At Risk</h3>';
echo '<div style="display:flex;align-items:center;gap:8px">';
echo '<span style="font-size:.72rem;color:#9ca3af;font-family:var(--lt-font)">No activity in 30+ days</span>';
if ($isadmin && !empty($atrisk)) {
    $all_ids = implode(',', array_keys((array)$atrisk));
    $remall_url = new moodle_url('/local/learnpath/reminders.php', [
        'groupid' => 0, 'action' => 'bulk_remind',
        'userids' => $all_ids, 'sesskey' => sesskey(),
    ]);
    $ar_count = count((array)$atrisk);
    echo html_writer::link($remall_url, '📢 Remind All (' . $ar_count . ')',
        ['style' => 'font-size:.74rem;font-weight:700;color:#fff;background:#f59e0b;padding:4px 11px;border-radius:6px;text-decoration:none;white-space:nowrap',
         'onclick' => "return confirm('Send a reminder to all " . $ar_count . " at-risk learner(s)?')"]);
}
echo '</div></div>';
echo '<div class="lt-card-body">';
if (empty($atrisk)) {
    echo '<p style="font-family:var(--lt-font);font-size:.82rem;color:#10b981">✓ No at-risk learners.</p>';
} else {
    foreach ($atrisk as $ar) {
        $ar_grp = $DB->get_record_sql(
            'SELECT lpa.groupid FROM {local_learnpath_user_assign} lpa WHERE lpa.userid=:uid LIMIT 1',
            ['uid' => $ar->id]
        );
        $ar_gid = $ar_grp ? (int)$ar_grp->groupid : 0;
        echo '<div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid #f9fafb;font-family:var(--lt-font)">';
        echo '<span style="font-size:.9rem">🔴</span>';
        echo '<div style="flex:1"><div style="font-size:.82rem;font-weight:700;color:#111827">' . format_string($ar->firstname . ' ' . $ar->lastname) . '</div>';
        echo '<div style="font-size:.72rem;color:#9ca3af">' . round((float)($ar->days_inactive ?? 0)) . ' days inactive</div></div>';
        if ($isadmin) {
            echo '<div style="display:flex;gap:4px">';
            if ($ar_gid) {
                $remind_url = new moodle_url('/local/learnpath/reminders.php', [
                    'groupid' => $ar_gid, 'action' => 'bulk_remind',
                    'userids' => $ar->id, 'sesskey' => sesskey(),
                ]);
                echo html_writer::link($remind_url, '📢 Remind',
                    ['style' => 'font-size:.7rem;font-weight:700;color:#fff;background:#f59e0b;padding:3px 8px;border-radius:6px;text-decoration:none;white-space:nowrap',
                     'title' => 'Send reminder to this learner',
                     'onclick' => "return confirm('Send reminder to this learner?')"]);
                $profile_url = new moodle_url('/local/learnpath/profile.php', [
                    'userid' => $ar->id, 'groupid' => $ar_gid
                ]);
                echo html_writer::link($profile_url, '👤',
                    ['style' => 'font-size:.7rem;font-weight:700;color:#1e40af;background:#dbeafe;padding:3px 8px;border-radius:6px;text-decoration:none',
                     'title' => 'View learner profile',
                     'target' => '_blank']);
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
echo '</div></div>';
echo '</div>'; // end grid

// Recent Activity
try { $feed = \local_learnpath\data\helper::get_recent_activity(10); } catch (\Throwable $e) { $feed = []; }
echo '<div class="lt-card"><div class="lt-card-header"><h3 class="lt-card-title">🕐 Recent Completions</h3></div>';
echo '<div class="lt-card-body">';
if (empty($feed)) {
    echo '<p style="font-family:var(--lt-font);font-size:.82rem;color:#9ca3af">No recent completions.</p>';
} else {
    foreach ($feed as $f) {
        echo '<div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px solid #f9fafb;font-family:var(--lt-font)">';
        echo '<span style="font-size:.9rem;flex-shrink:0">✅</span>';
        echo '<div style="flex:1"><span style="font-size:.84rem;font-weight:600;color:#111827">' . format_string($f->firstname . ' ' . $f->lastname) . '</span>';
        echo ' <span style="font-size:.78rem;color:#6b7280">completed</span> ';
        echo '<span style="font-size:.82rem;color:#374151">' . format_string($f->coursename) . '</span>';
        echo '<div style="font-size:.72rem;color:#9ca3af">' . userdate($f->timecompleted, get_string('strftimedatefullshort')) . '</div></div></div>';
    }
}
echo '</div></div>';

// Popular Courses
try { $popular = \local_learnpath\data\helper::get_popular_courses(10); } catch (\Throwable $e) { $popular = []; }
echo '<div class="lt-card"><div class="lt-card-header"><h3 class="lt-card-title">🔥 Popular Courses</h3></div>';
echo '<div class="lt-card-body" style="padding:0"><div style="overflow-x:auto"><table class="lt-data-table"><thead><tr>';
foreach (['#','Course','Enrolled','Completed','Rate',''] as $h) { echo '<th>' . $h . '</th>'; }
echo '</tr></thead><tbody>';
$rank = 1;
foreach ($popular as $pc) {
    $rate = $pc->enrolled > 0 ? (int)round($pc->completed / $pc->enrolled * 100) : 0;
    $rclr = $rate >= 75 ? '#10b981' : ($rate >= 40 ? '#f59e0b' : '#ef4444');
    $curl = new moodle_url('/local/learnpath/courseinsights.php', ['courseid' => $pc->id]);
    echo '<tr><td style="color:#9ca3af">' . $rank . '</td>';
    echo '<td>' . html_writer::link($curl, format_string($pc->fullname), ['style' => 'color:var(--lt-accent);text-decoration:none;font-weight:600']) . '</td>';
    echo '<td>' . $pc->enrolled . '</td><td>' . $pc->completed . '</td>';
    echo '<td><span style="font-weight:700;color:' . $rclr . '">' . $rate . '%</span></td>';
    echo '<td>' . html_writer::link($curl, 'Insights →', ['style' => 'font-size:.74rem;color:var(--lt-accent)']) . '</td></tr>';
    $rank++;
}
if (empty($popular)) {
    echo '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:16px">No course data.</td></tr>';
}
echo '</tbody></table></div></div></div>';

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'
    . html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank'])
    . '<span class="lt-sep">·</span><span>LearnTrack v2.0.0</span></div>';

} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui">';
    echo '<strong>Error loading overview:</strong> ' . htmlspecialchars($e->getMessage());
    echo '<br><small>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small></div>';
}
echo $OUTPUT->footer();
