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
 * LearnTrack - My Profile
 * Learner-facing profile: shows progress across all paths, certs, engagement,
 * and direct links to start/continue each course activity.
 */
require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;

require_login();
// All logged-in users can view their own path; check basic login only
$ctx = context_system::instance();
global $DB, $USER, $OUTPUT, $CFG;

$ctx = context_system::instance();
$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$bname = get_config('local_learnpath', 'brand_name') ?: 'LearnTrack';

$PAGE->set_url(new moodle_url('/local/learnpath/myprofile.php'));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($bname . ' — My Profile');

// Collect all paths this learner belongs to
$all_paths = [];
try {
    $ap_rows = $DB->get_records_sql(
        "SELECT DISTINCT lpg.id, lpg.name, lpg.deadline
         FROM {local_learnpath_groups} lpg
         JOIN {local_learnpath_group_courses} lgc ON lgc.groupid = lpg.id
         JOIN {enrol} e ON e.courseid = lgc.courseid
         JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :uid",
        ['uid' => $USER->id]
    );
    $all_paths = $ap_rows ?: [];
} catch (Throwable $e_ap) { $all_paths = []; }

// Also include paths where user is explicitly assigned
try {
    $assigned = $DB->get_records_sql(
        "SELECT DISTINCT lpg.id, lpg.name, lpg.deadline
         FROM {local_learnpath_groups} lpg
         JOIN {local_learnpath_user_assign} ua ON ua.groupid = lpg.id AND ua.userid = :uid",
        ['uid' => $USER->id]
    );
    foreach ($assigned as $ap) {
        if (!isset($all_paths[$ap->id])) $all_paths[$ap->id] = $ap;
    }
} catch (Throwable $e_as) {}

// Overall stats
$total_done = 0; $total_courses = 0; $overdue_ct = 0;
$path_data = [];
foreach ($all_paths as $ap) {
    $rows  = DH::get_progress_detail($ap->id, $USER->id);
    $mine  = array_filter($rows, fn($r) => (int)$r->userid === (int)$USER->id);
    $done  = count(array_filter($mine, fn($r) => $r->status === 'complete'));
    $total = count($mine);
    $pct   = $total > 0 ? (int)round($done / $total * 100) : 0;
    if ($done >= $total && $total > 0) $pct = 100;
    $over  = $ap->deadline && $ap->deadline < time() && $pct < 100;
    if ($over) $overdue_ct++;
    $total_done    += $done;
    $total_courses += $total;
    // Get cert
    $cert = null;
    $dbman = $DB->get_manager();
    if ($dbman->table_exists(new xmldb_table('local_learnpath_certs'))) {
        $cert = $DB->get_record('local_learnpath_certs', ['groupid'=>$ap->id,'userid'=>$USER->id]);
    }
    $eng = DH::get_engagement_score((int)$USER->id, (int)$ap->id);
    $path_data[] = compact('ap', 'mine', 'done', 'total', 'pct', 'over', 'cert', 'eng', 'rows');
}
$overall = $total_courses > 0 ? (int)round($total_done / $total_courses * 100) : 0;

// User pic URL
$user_pic = $OUTPUT->user_picture($USER, ['size' => 100, 'link' => false]);

echo $OUTPUT->header();
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';
echo '<style>'
    . '.mp-wrap{max-width:860px;margin:0 auto;padding:14px;font-family:var(--lt-font,system-ui)}'
    . '.mp-hero{background:linear-gradient(135deg,#0f172a,' . $brand . ');border-radius:16px;padding:26px 28px;color:#fff;margin-bottom:18px;display:flex;gap:20px;align-items:center;flex-wrap:wrap}'
    . '.mp-avatar img{width:72px;height:72px;border-radius:50%;border:3px solid rgba(255,255,255,.3);object-fit:cover}'
    . '.mp-name{font-size:1.3rem;font-weight:800;margin:0 0 3px}'
    . '.mp-sub{font-size:.8rem;color:rgba(255,255,255,.72);margin:0}'
    . '.mp-stats{display:flex;gap:20px;margin-left:auto;flex-wrap:wrap}'
    . '.mp-stat{text-align:center;background:rgba(255,255,255,.12);padding:10px 16px;border-radius:10px}'
    . '.mp-stat-val{font-size:1.5rem;font-weight:800;display:block}'
    . '.mp-stat-lbl{font-size:.66rem;text-transform:uppercase;letter-spacing:.5px;opacity:.75;display:block}'
    . '.mp-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:16px}'
    . '.mp-card-hdr{padding:12px 16px;border-bottom:1px solid #f3f4f6;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;font-family:var(--lt-font);font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#374151}'
    . '.mp-card-body{padding:14px 16px}'
    . '.mp-path-pct{font-size:.88rem;font-weight:800}'
    . '.mp-bar{height:6px;background:#e5e7eb;border-radius:100px;overflow:hidden;flex:1}'
    . '.mp-fill{height:100%;border-radius:100px;transition:width .4s}'
    . '.mp-course-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f9fafb;font-family:var(--lt-font)}'
    . '.mp-course-row:last-child{border-bottom:none}'
    . '.mp-course-name{font-size:.86rem;font-weight:600;color:#111827;text-decoration:none;flex:1}'
    . '.mp-course-name:hover{color:var(--lt-accent)}'
    . '.mp-act-btn{display:inline-flex;align-items:center;gap:5px;font-family:var(--lt-font);font-size:.74rem;font-weight:700;padding:4px 10px;border-radius:6px;text-decoration:none!important;transition:all .15s}'
    . '.mp-act-start{background:var(--lt-accent);color:#fff!important}'
    . '.mp-act-continue{background:#f59e0b;color:#fff!important}'
    . '.mp-act-done{background:#d1fae5;color:#065f46!important}'
    . '</style>';

echo '<div class="mp-wrap">';

// Back link
echo html_writer::link(new moodle_url('/local/learnpath/mypath.php'), '← My Learning',
    ['style' => 'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

// Hero
echo '<div class="mp-hero">';
echo '<div class="mp-avatar">' . $user_pic . '</div>';
echo '<div>';
echo '<p class="mp-name">' . fullname($USER) . '</p>';
echo '<p class="mp-sub">' . s($USER->email) . '</p>';
if ($overdue_ct > 0) {
    echo '<span style="background:#ef4444;color:#fff;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px;margin-top:6px;display:inline-block">⚠ ' . $overdue_ct . ' overdue</span>';
}
echo '</div>';
echo '<div class="mp-stats">';
echo '<div class="mp-stat"><span class="mp-stat-val">' . $overall . '%</span><span class="mp-stat-lbl">Overall</span></div>';
echo '<div class="mp-stat"><span class="mp-stat-val">' . $total_done . '</span><span class="mp-stat-lbl">Completed</span></div>';
echo '<div class="mp-stat"><span class="mp-stat-val">' . count($all_paths) . '</span><span class="mp-stat-lbl">Paths</span></div>';
echo '</div>';
echo '</div>';

if (empty($path_data)) {
    echo '<div class="mp-card"><div class="mp-card-body" style="text-align:center;padding:32px;color:#9ca3af">';
    echo '<div style="font-size:2rem;margin-bottom:8px">📭</div>';
    echo '<p>You are not enrolled in any learning paths yet.</p>';
    echo '</div></div>';
} else {
    foreach ($path_data as $pd) {
        $ap    = $pd['ap'];
        $mine  = $pd['mine'];
        $pct   = $pd['pct'];
        $done  = $pd['done'];
        $total = $pd['total'];
        $over  = $pd['over'];
        $cert  = $pd['cert'];
        $eng   = $pd['eng'];

        $bar_color = $pct >= 100 ? '#10b981' : ($pct > 0 ? '#f59e0b' : '#d1d5db');
        $eng_color = $eng >= 70 ? '#10b981' : ($eng >= 40 ? '#f59e0b' : '#ef4444');

        // Card header
        echo '<div class="mp-card">';
        echo '<div class="mp-card-hdr">';
        echo '<span>' . format_string($ap->name) . '</span>';
        echo '<span style="display:flex;align-items:center;gap:8px">';
        if ($cert) {
            echo '<span style="background:#fef3c7;color:#92400e;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">🎓 Certificate Issued</span>';
        }
        if ($over) {
            echo '<span style="background:#fee2e2;color:#be123c;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">⚠ Overdue</span>';
        }
        echo '<span class="mp-path-pct" style="color:' . $bar_color . '">' . $pct . '%</span>';
        echo '</span></div>';

        echo '<div class="mp-card-body">';

        // Progress bar
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">';
        echo '<div class="mp-bar"><div class="mp-fill" style="width:' . $pct . '%;background:' . $bar_color . '"></div></div>';
        echo '<span style="font-size:.78rem;color:#6b7280;white-space:nowrap">' . $done . '/' . $total . ' courses</span>';
        echo '<span style="font-size:.72rem;color:' . $eng_color . ';font-weight:700;white-space:nowrap">Engagement: ' . $eng . '/100</span>';
        echo '</div>';

        // Deadline
        if ($ap->deadline) {
            $days_left = (int)ceil(($ap->deadline - time()) / 86400);
            $dl_color = $days_left < 0 ? '#be123c' : ($days_left <= 7 ? '#d97706' : '#6b7280');
            echo '<div style="font-size:.76rem;color:' . $dl_color . ';margin-bottom:12px">';
            echo '📅 Deadline: ' . userdate($ap->deadline, get_string('strftimedatefullshort'));
            if ($days_left < 0) echo ' <strong>(Overdue by ' . abs($days_left) . ' days)</strong>';
            elseif ($days_left <= 7) echo ' <strong>(' . $days_left . ' days left)</strong>';
            echo '</div>';
        }

        // Course rows with activity links
        foreach ($mine as $row) {
            $is_complete = $row->status === 'complete';
            $course = $DB->get_record('course', ['id' => $row->courseid], 'id,fullname,shortname', IGNORE_MISSING);
            if (!$course) continue;

            $course_url = new moodle_url('/course/view.php', ['id' => $row->courseid]);
            $btn_class  = $is_complete ? 'mp-act-done' : ($row->status === 'inprogress' ? 'mp-act-continue' : 'mp-act-start');
            $btn_label  = $is_complete ? '✓ Done' : ($row->status === 'inprogress' ? '▶ Continue' : '▶ Start');
            $c_color    = $is_complete ? '#10b981' : ($row->status === 'inprogress' ? '#f59e0b' : '#d1d5db');

            echo '<div class="mp-course-row">';
            echo '<div style="width:8px;height:8px;border-radius:50%;background:' . $c_color . ';flex-shrink:0"></div>';
            echo '<a href="' . $course_url->out(false) . '" class="mp-course-name">' . format_string($course->fullname) . '</a>';

            // Find next incomplete activity in course
            if (!$is_complete) {
                try {
                    $mod_info = get_fast_modinfo($row->courseid, $USER->id);
                    $next_act = null;
                    foreach ($mod_info->get_cms() as $cm) {
                        if (!$cm->uservisible || $cm->completion == 0) continue;
                        $comp = $DB->get_record('course_modules_completion', [
                            'coursemoduleid' => $cm->id,
                            'userid' => $USER->id
                        ]);
                        if (!$comp || !in_array($comp->completionstate, [1, 2])) {
                            $next_act = $cm;
                            break;
                        }
                    }
                    if ($next_act) {
                        $act_url = new moodle_url('/mod/' . $next_act->modname . '/view.php', ['id' => $next_act->id]);
                        echo '<a href="' . $act_url->out(false) . '" class="mp-act-btn ' . $btn_class . '">'
                            . $btn_label . ' — ' . format_string($next_act->name) . '</a>';
                    } else {
                        echo html_writer::link($course_url, $btn_label, ['class' => 'mp-act-btn ' . $btn_class]);
                    }
                } catch (Throwable $e_mi) {
                    echo html_writer::link($course_url, $btn_label, ['class' => 'mp-act-btn ' . $btn_class]);
                }
            } else {
                echo '<span class="mp-act-btn mp-act-done">✓ Done</span>';
            }
            echo '</div>';
        }

        // Certificate button
        if ($cert) {
            $cert_url = new moodle_url('/local/learnpath/mypath.php', ['groupid' => $ap->id]);
            echo '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #f3f4f6">';
            echo html_writer::link($cert_url,
                '🎓 View Certificate',
                ['style' => 'font-family:var(--lt-font);font-size:.8rem;font-weight:700;color:var(--lt-accent);text-decoration:none']);
            echo '</div>';
        }

        echo '</div></div>';
    }
}

echo '</div>';
echo $OUTPUT->footer();
