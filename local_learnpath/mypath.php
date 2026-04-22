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
 * LearnTrack — My Learning Paths (Learner View)
 * Appears in: nav menu, dashboard block, notification links.
 */
require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;

require_login();
// All logged-in users can view their own path; check basic login only
$ctx = context_system::instance();
// Learner page — just needs login, capability check is inside

$groupid = optional_param('groupid', 0, PARAM_INT);
$PAGE->set_url(new moodle_url('/local/learnpath/mypath.php', ['groupid' => $groupid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('LearnTrack — My Learning Paths');

global $USER, $OUTPUT, $DB;
$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';

// Find paths this learner is enrolled in
$all_groups = $DB->get_records('local_learnpath_groups', null, 'name ASC');
$my_groups  = [];
foreach ($all_groups as $g) {
    $courses = DH::get_group_courses($g->id);
    foreach ($courses as $c) {
        $ctx = context_course::instance($c->id, IGNORE_MISSING);
        if ($ctx && is_enrolled($ctx, $USER->id)) {
            $my_groups[$g->id] = $g;
            break;
        }
    }
}

if ($groupid === 0 && count($my_groups) === 1) {
    $groupid = array_key_first($my_groups);
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome', ['style' => 'display:inline-block;margin-bottom:14px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
try {
echo '<style>:root{--lt-primary:'.$brand.';--lt-accent:'.$brand.'}</style>';

echo '<style>'
    . '.lt-mypath-hero{background:linear-gradient(135deg,#0f172a,' . $brand . ');border-radius:14px;padding:26px 28px;color:#fff;margin-bottom:20px;font-family:var(--lt-font)}'
    . '.lt-mypath-hero h1{font-size:1.35rem;font-weight:800;margin:0 0 4px;color:#fff}'
    . '.lt-mypath-hero p{font-size:.84rem;color:rgba(255,255,255,.75);margin:0}'
    . '.lt-path-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-bottom:18px;overflow:hidden;font-family:var(--lt-font);box-shadow:0 2px 8px rgba(0,0,0,.05)}'
    . '.lt-path-card-hdr{padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#f8fafc}'
    . '.lt-path-title{font-size:1rem;font-weight:800;color:#111827;margin:0}'
    . '.lt-path-pct{font-size:1.3rem;font-weight:800;color:var(--lt-accent)}'
    . '.lt-course-row{display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid #f9fafb;transition:background .1s}'
    . '.lt-course-row:hover{background:#fafbff}'
    . '.lt-course-row:last-child{border-bottom:none}'
    . '.lt-course-link{font-size:.88rem;font-weight:600;color:var(--lt-accent)!important;text-decoration:none!important;display:block}'
    . '.lt-course-link:hover{text-decoration:underline!important}'
    . '.lt-course-meta{font-size:.74rem;color:#9ca3af;margin-top:1px}'
    . '.lt-continue-btn{font-family:var(--lt-font);font-size:.76rem;font-weight:700;background:var(--lt-accent);color:#fff;padding:5px 12px;border-radius:6px;text-decoration:none!important;flex-shrink:0;white-space:nowrap}'
    . '.lt-deadline-warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 16px;font-size:.82rem;color:#92400e;font-family:var(--lt-font)}'
    . '.lt-overdue-warn{background:#fee2e2;border-left:4px solid #ef4444;padding:10px 16px;font-size:.82rem;color:#991b1b;font-family:var(--lt-font)}'
    . '</style>';

echo '<div style="display:flex;justify-content:flex-end;margin-bottom:8px">';
echo html_writer::link(new moodle_url('/local/learnpath/myprofile.php'),'👤 My Profile',
    ['style'=>'font-family:var(--lt-font);font-size:.8rem;font-weight:700;color:var(--lt-accent);text-decoration:none;padding:5px 12px;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff']);
echo '</div>';
echo '<div class="lt-mypath-hero">';
echo '<h1>📚 My Learning Paths</h1>';
echo '<p>Hello ' . format_string($USER->firstname) . '! Track and continue your learning below.</p>';
echo '</div>';

if (count($my_groups) > 1) {
    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;font-family:var(--lt-font)">';
    echo '<label style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px">Filter path:</label>';
    $gopts = [0 => 'All Paths'];
    foreach ($my_groups as $g) { $gopts[$g->id] = format_string($g->name); }
    echo html_writer::select($gopts, 'groupid', $groupid, false, [
        'class' => 'lt-select',
        'onchange' => "window.location='?groupid='+this.value",
    ]);
    echo '</div>';
}

if (empty($my_groups)) {
    echo '<div class="lt-empty-state"><div class="lt-empty-icon">📭</div>';
    echo '<h3 class="lt-empty-title">No Learning Paths Assigned</h3>';
    echo '<p class="lt-empty-desc">You are not currently enrolled in any learning path. Contact your administrator.</p></div>';
} else {
    $show_groups = ($groupid > 0 && isset($my_groups[$groupid]))
        ? [$groupid => $my_groups[$groupid]]
        : $my_groups;

    foreach ($show_groups as $gid => $group) {
        // Get progress only for the current learner — avoids loading all learners
        $path_courses = DH::get_group_courses($gid);
        $myrows = [];
        foreach ($path_courses as $course) {
            $progress = DH::get_course_progress($USER->id, $course->id);
            $progress->userid     = $USER->id;
            $progress->coursename = $course->fullname;
            $progress->courseid   = $course->id;
            $myrows[$course->id]  = $progress;
        }
        if (empty($myrows)) { continue; }

        $completed = count(array_filter($myrows, function ($r) { return $r->status === 'complete'; }));
        $total     = count($myrows);
        $pct       = $total > 0 ? (int)round($completed / $total * 100) : 0;
        if ($completed >= $total && $total > 0) { $pct = 100; }
        $is_overdue = $group->deadline && $group->deadline < time() && $pct < 100;

        echo '<div class="lt-path-card">';
        echo '<div class="lt-path-card-hdr">';
        echo '<div><h2 class="lt-path-title">' . format_string($group->name) . '</h2>';
        if ($group->deadline) {
            $dl_color = $is_overdue ? '#be123c' : ($secs_left < 86400*3 ? '#f59e0b' : '#6b7280');
            $timer_id = 'cdt_' . (int)$group->id;
            echo '<span style="font-size:.74rem;color:' . $dl_color . '">' . ($is_overdue ? '⚠️ Overdue — was due ' : '⏱ Deadline: ') . userdate($group->deadline, get_string('strftimedatefullshort')) . '</span>';
            if (!$is_overdue && $pct < 100) {
                $dl_ts = (int)$group->deadline;
                echo '<span id="' . $timer_id . '" style="display:inline-block;margin-left:8px;font-size:.74rem;font-weight:700;color:' . $dl_color . ';font-family:var(--lt-font)"></span>';
                echo '<script>(function(){var dl=' . $dl_ts . '*1000,id="' . $timer_id . '";function tick(){var el=document.getElementById(id);if(!el)return;var diff=dl-Date.now();if(diff<=0){el.textContent="Deadline passed";el.style.color="#ef4444";return;}var d=Math.floor(diff/86400000),h=Math.floor(diff%86400000/3600000),m=Math.floor(diff%3600000/60000),s=Math.floor(diff%60000/1000);el.textContent="⏳ "+(d>0?d+"d ":"")+("0"+h).slice(-2)+"h "+("0"+m).slice(-2)+"m "+("0"+s).slice(-2)+"s";setTimeout(tick,1000);}tick();})();</script>';
            }
        }
        echo '</div>';
        echo '<div style="display:flex;align-items:center;gap:10px">';
        echo '<div class="lt-progress-track" style="width:120px"><div class="lt-progress-fill ' . ($pct===100?'lt-bar-complete':($pct>0?'lt-bar-progress':'lt-bar-empty')) . '" style="width:' . $pct . '%"></div></div>';
        echo '<span class="lt-path-pct">' . $pct . '%</span>';
        echo '</div></div>';

        if ($is_overdue) {
            $days_over = (int)ceil((time() - $group->deadline) / 86400);
            echo '<div class="lt-overdue-warn" style="display:flex;align-items:center;gap:10px">';
            echo '<span style="font-size:1.4rem">⚠️</span>';
            echo '<div><strong>Deadline passed ' . $days_over . ' day' . ($days_over===1?'':'s') . ' ago</strong>';
            echo '<br><span style="font-size:.78rem">Was due: ' . userdate($group->deadline, get_string('strftimedatefullshort')) . '. Please complete remaining courses as soon as possible.</span></div>';
            echo '</div>';
        } elseif ($group->deadline && $pct < 100) {
            $secs_left  = $group->deadline - time();
            $days_left  = (int)floor($secs_left / 86400);
            $hours_left = (int)floor(($secs_left % 86400) / 3600);
            $dl_color   = $days_left <= 3 ? '#ef4444' : ($days_left <= 7 ? '#f59e0b' : '#3b82f6');
            $dl_bg      = $days_left <= 3 ? '#fef2f2' : ($days_left <= 7 ? '#fef3c7' : '#eff6ff');
            echo '<div style="background:' . $dl_bg . ';border-left:4px solid ' . $dl_color . ';border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:10px;display:flex;align-items:center;gap:12px">';
            echo '<span style="font-size:1.4rem">' . ($days_left <= 3 ? '🔴' : ($days_left <= 7 ? '⏳' : '📅')) . '</span>';
            echo '<div><strong style="color:' . $dl_color . ';font-size:.88rem">';
            if ($days_left > 0) {
                echo $days_left . ' day' . ($days_left===1?'':'s') . ' ' . $hours_left . 'h remaining';
            } else {
                echo 'Due in ' . $hours_left . ' hour' . ($hours_left===1?'':'s');
            }
            echo '</strong><br>';
            echo '<span style="font-size:.74rem;color:#6b7280">Deadline: ' . userdate($group->deadline, get_string('strftimedatefullshort')) . '</span></div>';
            // Progress toward deadline
            if ($pct > 0) {
                echo '<div style="margin-left:auto;text-align:center">';
                echo '<div style="font-size:1.1rem;font-weight:800;color:' . $dl_color . '">' . $pct . '%</div>';
                echo '<div style="font-size:.66rem;color:#9ca3af">done</div>';
                echo '</div>';
            }
            echo '</div>';
        }

        foreach ($myrows as $row) {
            $icon      = match($row->status) { 'complete' => '✅', 'inprogress' => '⏳', default => '○' };
            $courseurl = new moodle_url('/course/view.php', ['id' => $row->courseid]);
            $cls       = match($row->status) { 'complete' => 'lt-bar-complete', 'inprogress' => 'lt-bar-progress', default => 'lt-bar-empty' };
            echo '<div class="lt-course-row">';
            echo '<span style="font-size:1.1rem;flex-shrink:0">' . $icon . '</span>';
            echo '<div style="flex:1;min-width:0">';
            echo '<a href="' . $courseurl . '" class="lt-course-link">' . format_string($row->coursename) . '</a>';
            echo '<div class="lt-course-meta">' . $row->completed_activities . '/' . $row->total_activities . ' activities';
            if ($row->lastaccess) { echo ' · Last: ' . userdate($row->lastaccess, get_string('strftimedatefullshort')); }
            echo '</div></div>';
            echo '<div class="lt-progress-track" style="width:100px"><div class="lt-progress-fill ' . $cls . '" style="width:' . $row->progress . '%"></div></div>';
            echo '<span style="font-size:.8rem;font-weight:800;min-width:36px;text-align:right;font-family:var(--lt-mono)">' . $row->progress . '%</span>';
            if ($row->status !== 'complete') {
                echo html_writer::link($courseurl, 'Continue →', ['class' => 'lt-continue-btn']);
            }
            echo '</div>';
        }
        echo '</div>';
    }
}

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>' . html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank']) . '<span class="lt-sep">·</span><span>LearnTrack v1.0.0</span></div>';
} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '<br><small>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small></div>';
}
echo $OUTPUT->footer();
