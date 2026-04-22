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
 * LearnTrack — Learner Profile Popup
 * Rich profile with My Path progress, cert, notes, and stats.
 */
require_once(__DIR__ . '/../../config.php');

require_login();
$ctx = context_system::instance();
require_capability('local/learnpath:viewdashboard', $ctx);

$userid  = required_param('userid',  PARAM_INT);
$groupid = required_param('groupid', PARAM_INT);
$isadmin = has_capability('local/learnpath:manage', $ctx);

global $DB, $USER, $OUTPUT, $CFG;

// ── Set page context BEFORE action handlers (required for redirect() in Moodle 4.5+) ──
$PAGE->set_url(new moodle_url('/local/learnpath/profile.php',
    ['userid' => $userid, 'groupid' => $groupid]));
$PAGE->set_context($ctx);
$PAGE->set_pagelayout('popup');

$learner = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$group   = $groupid > 0 ? $DB->get_record('local_learnpath_groups', ['id' => $groupid]) : null;

// ── Handle actions — only when action params are present ────────────────────
if ($isadmin) {
    $_action_done = false;

    // GET link actions: revoke cert, delete note (carry sesskey in URL)
    if (optional_param('revokecert', 0, PARAM_INT) && confirm_sesskey()) {
        $DB->delete_records('local_learnpath_certs', ['groupid'=>$groupid,'userid'=>$userid]);
        $_action_done = true;
    } elseif (optional_param('deletenote', 0, PARAM_INT) && confirm_sesskey()) {
        $noteid = optional_param('noteid', 0, PARAM_INT);
        if ($noteid) {
            $DB->delete_records('local_learnpath_notes',
                ['id'=>$noteid,'groupid'=>$groupid,'userid'=>$userid]);
        }
        $_action_done = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
        // POST form actions
        if (optional_param('savenote', 0, PARAM_INT)) {
            $note = optional_param('note', '', PARAM_TEXT);
            if (trim($note) !== '') {
                $DB->insert_record('local_learnpath_notes', (object)[
                    'groupid'=>$groupid,'userid'=>$userid,'authorid'=>$USER->id,
                    'note'=>$note,'timecreated'=>time(),'timemodified'=>time(),
                ]);
            }
            $_action_done = true;
        }
        if (optional_param('issuecert', 0, PARAM_INT)) {
            $certn = trim(optional_param('certnumber', '', PARAM_TEXT));
            if ($certn === '') {
                // Auto-generate based on admin settings
                $cfg_prefix = get_config('local_learnpath', 'cert_id_prefix');
                $cfg_format = get_config('local_learnpath', 'cert_id_format') ?: 'site-path-date-uid';
                $sitename   = $cfg_prefix
                    ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cfg_prefix))
                    : strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', get_config('moodle', 'shortname') ?: 'LMS'), 0, 4));
                $pathcode   = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $group ? $group->name : 'PATH'), 0, 6));
                $datecode   = date('mY');
                $uid_part   = str_pad($userid, 4, '0', STR_PAD_LEFT);
                if ($cfg_format === 'prefix-uid') {
                    $certn = $sitename . '-' . $uid_part;
                } elseif ($cfg_format === 'prefix-date-uid') {
                    $certn = $sitename . '-' . $datecode . '-' . $uid_part;
                } elseif ($cfg_format === 'prefix-random') {
                    $certn = $sitename . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
                } else {
                    $certn = $sitename . '-' . $pathcode . '-' . $datecode . '-' . $uid_part;
                }
                // Ensure uniqueness
                if ($DB->record_exists('local_learnpath_certs', ['certnumber' => $certn])) {
                    $certn .= '-' . strtoupper(substr(md5(uniqid()), 0, 4));
                }
            }
            if (!$DB->record_exists('local_learnpath_certs', ['groupid'=>$groupid,'userid'=>$userid])) {
                $DB->insert_record('local_learnpath_certs', (object)[
                    'groupid'=>$groupid,'userid'=>$userid,'issuedby'=>$USER->id,
                    'issuedate'=>time(),'certnumber'=>$certn,'timecreated'=>time(),
                ]);
                if ($group) {
                    \local_learnpath\notification\notifier::send_cert_notification($learner, $group, $certn);
                }
            }
            $_action_done = true;
        }
    }

    if ($_action_done) {
        redirect(new moodle_url('/local/learnpath/profile.php',
            ['userid'=>$userid,'groupid'=>$groupid]));
    }
}

// ── Pre-header setup ──────────────────────────────────────────────────────
$brand = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$bname = get_config('local_learnpath', 'brand_name')  ?: 'LearnTrack';

$PAGE->set_title(fullname($learner) . ' — ' . $bname);

echo $OUTPUT->header();
try {

// ── Data (all DB/DH calls AFTER header so errors show cleanly) ──────────────
$allrows = [];
$myrows  = [];
$completed = 0; $total = 0; $pct = 0;
$cert = null; $notes = []; $eng = 0;
$last_access = null; $all_paths = [];

try {
    if ($groupid > 0) {
        // Use $userid as viewerid so we get only THIS learner's data
        $allrows = \local_learnpath\data\helper::get_progress_detail($groupid, $userid);
        $myrows  = array_filter($allrows, fn($r) => (int)$r->userid === $userid);
    }
} catch (\Throwable $e_dh) { $allrows = []; $myrows = []; }

$dbman_p = $DB->get_manager();
if ($groupid > 0 && $dbman_p->table_exists(new xmldb_table('local_learnpath_certs'))) {
    $cert = $DB->get_record('local_learnpath_certs', ['groupid'=>$groupid,'userid'=>$userid]);
}
if ($groupid > 0 && $dbman_p->table_exists(new xmldb_table('local_learnpath_notes'))) {
    $notes = $DB->get_records('local_learnpath_notes',
        ['groupid'=>$groupid,'userid'=>$userid], 'timecreated DESC');
}

$completed = count(array_filter($myrows, fn($r) => $r->status === 'complete'));
$total     = count($myrows);
$pct       = ($total > 0) ? (int)round($completed / $total * 100) : 0;
if ($completed >= $total && $total > 0) { $pct = 100; }

try {
    $eng = ($groupid > 0) ? \local_learnpath\data\helper::get_engagement_score($userid, $groupid) : 0;
} catch (\Throwable $e_eng) { $eng = 0; }
$eng_color = $eng >= 70 ? '#10b981' : ($eng >= 40 ? '#f59e0b' : '#ef4444');
$eng_label = $eng >= 70 ? 'High' : ($eng >= 40 ? 'Medium' : 'Low');

try {
    $last_access = $DB->get_field_sql(
        "SELECT MAX(timecreated) FROM {logstore_standard_log} WHERE userid=:uid AND courseid > 1",
        ['uid' => $userid]
    );
} catch (\Throwable $e_ls) { $last_access = null; }

try {
    $ap_rows = $DB->get_records_sql(
        "SELECT DISTINCT lpg.id, lpg.name, lpg.deadline
         FROM {local_learnpath_groups} lpg
         JOIN {local_learnpath_group_courses} lgc ON lgc.groupid = lpg.id
         JOIN {enrol} e ON e.courseid = lgc.courseid
         JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :uid",
        ['uid' => $userid]
    );
    $all_paths = $ap_rows ?: [];
} catch (\Throwable $e_ap) { $all_paths = []; }

// Parse brand hex → RGB for gradient
$hex = ltrim($brand, '#');
$r   = hexdec(substr($hex,0,2));
$g_  = hexdec(substr($hex,2,2));
$b_  = hexdec(substr($hex,4,2));

echo '<style>'
    . ':root{--ltp-brand:' . $brand . '}'
    . 'body{margin:0;padding:0;background:#f1f5f9}'
    . '.ltp-wrap{max-width:740px;margin:0 auto;padding:14px;font-family:var(--lt-font,system-ui)}'
    . '.ltp-hero{background:linear-gradient(135deg,#0f172a,' . $brand . ');border-radius:14px;padding:22px 24px;color:#fff;margin-bottom:14px;display:grid;grid-template-columns:auto 1fr auto;gap:16px;align-items:center}'
    . '.ltp-avatar{width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;border:2.5px solid rgba(255,255,255,.35)}'
    . '.ltp-hero-name{font-size:1.15rem;font-weight:800;margin:0 0 2px;color:#fff}'
    . '.ltp-hero-sub{font-size:.76rem;color:rgba(255,255,255,.72);margin:0}'
    . '.ltp-hero-stats{display:flex;flex-direction:column;align-items:center;gap:6px}'
    . '.ltp-ring{width:62px;height:62px;position:relative;flex-shrink:0}'
    . '.ltp-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:12px}'
    . '.ltp-card-hdr{padding:11px 16px;border-bottom:1px solid #f3f4f6;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;font-family:var(--lt-font);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#374151}'
    . '.ltp-card-body{padding:14px 16px}'
    . '.ltp-stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px}'
    . '.ltp-stat{text-align:center;padding:10px 8px;background:#f8fafc;border-radius:8px;border:1px solid #f3f4f6}'
    . '.ltp-stat-val{font-size:1.2rem;font-weight:800;color:#111827;display:block}'
    . '.ltp-stat-lbl{font-size:.66rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#9ca3af;display:block;margin-top:2px}'
    . '.ltp-course-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f9fafb}'
    . '.ltp-course-row:last-child{border-bottom:none}'
    . '.ltp-progress-track{flex:1;height:6px;background:#e5e7eb;border-radius:100px;overflow:hidden;min-width:60px}'
    . '.ltp-progress-fill{height:100%;border-radius:100px;transition:width .4s}'
    . '.ltp-path-badge{display:inline-flex;align-items:center;gap:5px;background:#eff6ff;color:#1e40af;font-size:.74rem;font-weight:700;padding:4px 10px;border-radius:100px;margin:2px;text-decoration:none}'
    . '.ltp-path-badge:hover{background:#dbeafe}'
    . '.ltp-eng-circle{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:800;border:2.5px solid ' . $eng_color . ';color:' . $eng_color . ';background:' . $eng_color . '18;flex-shrink:0}'
    . '@media(max-width:560px){.ltp-hero{grid-template-columns:auto 1fr}.ltp-hero-stats{display:none}.ltp-stat-row{grid-template-columns:1fr 1fr}}'
    . '.otherbadges,.badges-container{display:none!important}'
    . '</style>';

echo '<div class="ltp-wrap">';

// ── Breadcrumb nav
echo '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">';
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'),'🏠 Welcome',
    ['style'=>'font-size:.78rem;color:var(--lt-accent,'.$brand.');text-decoration:none;padding:4px 9px;border:1.5px solid #e5e7eb;border-radius:6px;background:#fff']);
if ($groupid) {
    echo html_writer::link(new moodle_url('/local/learnpath/index.php',['groupid'=>$groupid]),'← Back to Path',
        ['style'=>'font-size:.78rem;color:var(--lt-accent,'.$brand.');text-decoration:none;padding:4px 9px;border:1.5px solid #e5e7eb;border-radius:6px;background:#fff']);
}
echo '</div>';

// ── Hero
echo '<div class="ltp-hero">';
echo '<div class="ltp-avatar">👤</div>';
echo '<div>';
echo '<h2 class="ltp-hero-name">' . fullname($learner) . '</h2>';
echo '<p class="ltp-hero-sub">' . s($learner->email) . ' · @' . s($learner->username) . '</p>';
if ($group) {
    echo '<p class="ltp-hero-sub" style="margin-top:3px">📚 ' . format_string($group->name) . '</p>';
}
if ($last_access) {
    echo '<p class="ltp-hero-sub" style="margin-top:2px">Last active: ' .
        userdate($last_access, get_string('strftimedatefullshort')) . '</p>';
}
echo '</div>';
// Circular progress ring (SVG)
$circumference = 2 * M_PI * 22;
$offset = $circumference * (1 - $pct / 100);
echo '<div class="ltp-hero-stats">';
echo '<svg width="62" height="62" viewBox="0 0 50 50">';
echo '<circle cx="25" cy="25" r="22" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="4"/>';
echo '<circle cx="25" cy="25" r="22" fill="none" stroke="#fff" stroke-width="4"
       stroke-dasharray="' . round($circumference,2) . '"
       stroke-dashoffset="' . round($offset,2) . '"
       stroke-linecap="round" transform="rotate(-90 25 25)"/>';
echo '<text x="25" y="20" text-anchor="middle" font-size="10" font-weight="800" fill="#fff">' . $pct . '%</text>';
echo '<text x="25" y="31" text-anchor="middle" font-size="6" fill="rgba(255,255,255,.7)">progress</text>';
echo '</svg>';
$eng_icon = $eng >= 70 ? '🔥' : ($eng >= 40 ? '⚡' : '💤');
$eng_tip  = htmlspecialchars($eng . '/100 — ' . $eng_label . ' engagement (progress, activity, grade, recency)');
echo '<div style="position:relative;cursor:help;text-align:center" title="' . $eng_tip . '">';
echo '<div style="width:52px;height:52px;border-radius:50%;background:' . $eng_color . ';display:flex;flex-direction:column;align-items:center;justify-content:center;box-shadow:0 2px 10px ' . $eng_color . '88;border:3px solid rgba(255,255,255,.5)">';
echo '<span style="font-size:.9rem;line-height:1">' . $eng_icon . '</span>';
echo '<span style="font-size:.7rem;font-weight:900;color:#fff;line-height:1.1">' . $eng . '</span>';
echo '</div>';
echo '<span style="font-size:.58rem;color:rgba(255,255,255,.7);display:block;margin-top:3px">' . strtoupper($eng_label) . '</span>';
echo '</div>';
echo '</div>';
echo '</div>';

// ── Stats strip
echo '<div class="ltp-stat-row">';
$stats = [
    ['✅', $completed . '/' . $total,  'Courses Done'],
    ['📅', $last_access ? userdate($last_access, '%d %b %y') : '—', 'Last Access'],
    ['🎓', $cert ? '✓ Issued' : 'None', 'Certificate'],
];
foreach ($stats as [$icon, $val, $lbl]) {
    echo '<div class="ltp-stat">';
    echo '<span class="ltp-stat-val">' . $icon . ' ' . $val . '</span>';
    echo '<span class="ltp-stat-lbl">' . $lbl . '</span>';
    echo '</div>';
}
echo '</div>';

// ── My Learning Paths
echo '<div class="ltp-card">';
echo '<div class="ltp-card-hdr">📚 Learning Paths<span style="font-weight:400;text-transform:none;font-size:.74rem;color:#9ca3af">' . count($all_paths) . ' path(s)</span></div>';
echo '<div class="ltp-card-body">';
if (empty($all_paths)) {
    echo '<p style="font-size:.82rem;color:#9ca3af;margin:0">No learning paths found.</p>';
} else {
    foreach ($all_paths as $ap) {
        $path_rows  = \local_learnpath\data\helper::get_progress_detail($ap->id, $userid);
        $path_mine  = array_filter($path_rows, fn($r) => (int)$r->userid === $userid);
        $p_done     = count(array_filter($path_mine, fn($r) => $r->status === 'complete'));
        $p_total    = count($path_mine);
        $p_pct      = $p_total > 0 ? (int)round($p_done / $p_total * 100) : 0;
        $bar_color  = $p_pct >= 100 ? '#10b981' : ($p_pct > 0 ? '#f59e0b' : '#d1d5db');
        $is_overdue = $ap->deadline && $ap->deadline < time() && $p_pct < 100;

        echo '<div style="padding:8px 0;border-bottom:1px solid #f9fafb;font-family:var(--lt-font)">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px">';
        echo '<span style="font-size:.85rem;font-weight:700;color:#111827">' . format_string($ap->name) . '</span>';
        echo '<span style="font-size:.72rem;font-weight:800;color:' . $bar_color . '">' . $p_pct . '%</span>';
        echo '</div>';
        echo '<div style="display:flex;align-items:center;gap:8px">';
        echo '<div class="ltp-progress-track"><div class="ltp-progress-fill" style="width:' . $p_pct . '%;background:' . $bar_color . '"></div></div>';
        echo '<span style="font-size:.72rem;color:#9ca3af;white-space:nowrap">' . $p_done . '/' . $p_total . ' courses</span>';
        if ($is_overdue) {
            echo '<span style="font-size:.68rem;font-weight:700;color:#be123c;white-space:nowrap">⚠️ Overdue</span>';
        } elseif ($ap->deadline && $p_pct < 100) {
            $dl_ts_p  = (int)$ap->deadline;
            $days_left = (int)ceil(($ap->deadline - time()) / 86400);
            $dl_color  = $days_left <= 3 ? '#ef4444' : ($days_left <= 7 ? '#f59e0b' : '#9ca3af');
            $cdt_id    = 'pcdt_' . (int)$ap->id;
            echo '<span id="' . $cdt_id . '" style="font-size:.68rem;font-weight:700;color:' . $dl_color . ';white-space:nowrap"></span>';
            echo '<script>(function(){var dl=' . $dl_ts_p . '*1000,id="' . $cdt_id . '";function tick(){var el=document.getElementById(id);if(!el)return;var diff=dl-Date.now();if(diff<=0){el.textContent="⚠️ Overdue";el.style.color="#ef4444";return;}var d=Math.floor(diff/86400000),h=Math.floor(diff%86400000/3600000),m=Math.floor(diff%3600000/60000),s=Math.floor(diff%60000/1000);el.textContent="⏳ "+(d>0?d+"d ":"")+("0"+h).slice(-2)+"h "+("0"+m).slice(-2)+"m "+("0"+s).slice(-2)+"s";setTimeout(tick,1000);}tick();})();</script>';
        } elseif ($ap->deadline) {
            echo '<span style="font-size:.68rem;color:#10b981;white-space:nowrap">✅ Done</span>';
        }
        echo '</div></div>';
    }
}
echo '</div></div>';

// ── Course Progress for current path
if (!empty($myrows)) {
    echo '<div class="ltp-card">';
    echo '<div class="ltp-card-hdr">📋 Course Progress in ' . ($group ? format_string($group->name) : 'Path') . '</div>';
    echo '<div class="ltp-card-body" style="padding:0">';
    foreach ($myrows as $row) {
        $icon  = match($row->status) { 'complete' => '✅', 'inprogress' => '⏳', default => '○' };
        $fc    = match($row->status) { 'complete' => '#10b981', 'inprogress' => '#f59e0b', default => '#d1d5db' };
        $cid   = 'drill_' . $row->courseid;
        echo '<div class="ltp-course-row" style="padding:10px 16px">';
        echo '<span style="font-size:1rem;flex-shrink:0">' . $icon . '</span>';
        echo '<div style="flex:1;min-width:0">';
        echo '<div style="font-size:.85rem;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . format_string($row->coursename) . '</div>';
        echo '<div style="font-size:.72rem;color:#9ca3af">' . $row->completed_activities . '/' . $row->total_activities . ' activities</div>';
        echo '</div>';
        echo '<div class="ltp-progress-track" style="width:80px"><div class="ltp-progress-fill" style="width:' . $row->progress . '%;background:' . $fc . '"></div></div>';
        echo '<span style="font-size:.78rem;font-weight:800;color:' . $fc . ';min-width:34px;text-align:right">' . $row->progress . '%</span>';
        echo '<button onclick="var e=document.getElementById(\'' . s($cid) . '\');e.style.display=e.style.display===\'block\'?\'none\':\'block\'" style="background:#f3f4f6;border:none;border-radius:5px;padding:3px 7px;cursor:pointer;font-size:.72rem">▼</button>';
        echo '</div>';
        // Activity drill-down
        $acts = $DB->get_records_sql(
            "SELECT cm.id, m.name as modtype, COALESCE(cmc.completionstate,0) as done
             FROM {course_modules} cm
             JOIN {modules} m ON m.id=cm.module
             LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid=cm.id AND cmc.userid=:uid
             WHERE cm.course=:cid AND cm.completion>0 AND cm.deletioninprogress=0
             ORDER BY cm.section, cm.id",
            ['uid' => $userid, 'cid' => $row->courseid]
        );
        try { $mod_info = get_fast_modinfo($row->courseid, $userid); } catch (\Throwable $e_mi) { $mod_info = null; }
        echo '<div id="' . s($cid) . '" style="display:none;margin:4px 16px 8px;padding:8px 10px;background:#f8fafc;border-radius:8px">';
        if (empty($acts)) {
            echo '<p style="font-size:.76rem;color:#9ca3af;margin:0">No tracked activities.</p>';
        }
        foreach ($acts as $act) {
            $adone = (int)$act->done > 0;
            $curl  = new moodle_url('/mod/' . s($act->modtype) . '/view.php', ['id' => $act->id]);
            $act_label = strtoupper($act->modtype);
            if (isset($mod_info)) {
                try { $cminfo = $mod_info->get_cm($act->id); if ($cminfo && !empty($cminfo->name)) { $act_label = format_string($cminfo->name); } } catch (\Throwable $emi) {}
            }
            echo '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:.78rem;font-family:var(--lt-font)">';
            echo '<span>' . ($adone ? '✅' : '○') . '</span>';
            echo '<span style="font-size:.62rem;background:#f3f4f6;color:#6b7280;padding:1px 5px;border-radius:4px;text-transform:uppercase;flex-shrink:0">' . htmlspecialchars($act->modtype) . '</span>';
            echo html_writer::link($curl, $act_label, [
                'target' => '_blank',
                'style'  => 'color:' . ($adone ? '#065f46' : '#9ca3af') . ';text-decoration:none;font-weight:' . ($adone ? '600' : '400'),
            ]);
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}

// ── Certificate
if ($isadmin && $group) {
    echo '<div class="ltp-card">';
    echo '<div class="ltp-card-hdr">🎓 Certificate';
    if ($cert) {
        echo '<span style="background:#d1fae5;color:#065f46;font-size:.72rem;font-weight:700;padding:2px 10px;border-radius:100px;text-transform:none">Issued</span>';
    }
    echo '</div><div class="ltp-card-body">';
    if ($cert) {
        $issuer = $DB->get_record('user', ['id' => $cert->issuedby, 'deleted' => 0]);
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start">';
        echo '<div style="flex:1">';
        echo '<div style="font-size:.88rem;font-weight:700;color:#065f46;margin-bottom:4px">✅ Certificate Issued</div>';
        echo '<div style="font-size:.76rem;color:#6b7280">';
        echo 'Date: ' . userdate($cert->issuedate, get_string('strftimedatefullshort'));
        if ($cert->certnumber) echo ' · Ref: ' . s($cert->certnumber);
        if ($issuer) echo ' · Issued by: ' . fullname($issuer);
        echo '</div></div>';
        $rurl = new moodle_url('/local/learnpath/profile.php',
            ['userid'=>$userid,'groupid'=>$groupid,'revokecert'=>1,'sesskey'=>sesskey()]);
        echo html_writer::link($rurl, 'Revoke Certificate', [
            'style'   => 'font-size:.76rem;font-weight:700;background:#fee2e2;color:#be123c;padding:6px 12px;border-radius:8px;text-decoration:none;white-space:nowrap',
            'onclick' => "return confirm('Revoke certificate for this learner?')",
        ]);
        echo '</div>';
    } else {
        $eligible = ($total > 0 && $completed >= $total);
        if (!$eligible) {
            echo '<p style="font-size:.82rem;color:#9ca3af;margin:0 0 6px">Learner has not completed all courses yet (' . $completed . '/' . $total . ').</p>';
        }
        echo '<p style="font-size:.82rem;color:#6b7280;margin:0 0 10px">No certificate issued yet.</p>';
        echo '<form method="post" style="display:flex;gap:8px;flex-wrap:wrap">';
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'issuecert','value'=>1]);
        echo '<input type="text" name="certnumber" placeholder="Auto-generated if left blank" style="font-family:var(--lt-font);font-size:.84rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:7px 11px;flex:1;min-width:160px;outline:none">';
        $btn_style = 'font-family:var(--lt-font);font-size:.8rem;font-weight:700;border:none;border-radius:8px;padding:7px 14px;cursor:pointer;';
        echo '<button type="submit" style="' . $btn_style . 'background:#d1fae5;color:#065f46">🎓 Issue Certificate</button>';
        echo '</form>';
    }
    echo '</div></div>';
}

// ── Admin notes
if ($isadmin) {
    echo '<div class="ltp-card">';
    echo '<div class="ltp-card-hdr">📝 Admin Notes <span style="font-weight:400;text-transform:none;font-size:.72rem;color:#9ca3af">Private — not visible to learner</span></div>';
    echo '<div class="ltp-card-body">';
    if (empty($notes)) {
        echo '<p style="font-size:.82rem;color:#9ca3af;margin:0 0 10px">No notes yet.</p>';
    }
    foreach ($notes as $n) {
        $author = $DB->get_record('user', ['id' => $n->authorid]);
        $durl   = new moodle_url('/local/learnpath/profile.php',
            ['userid'=>$userid,'groupid'=>$groupid,'deletenote'=>1,'noteid'=>$n->id,'sesskey'=>sesskey()]);
        echo '<div style="border-bottom:1px solid #f3f4f6;padding:8px 0;font-family:var(--lt-font)">';
        echo '<div style="display:flex;justify-content:space-between;font-size:.72rem;color:#9ca3af;margin-bottom:3px">';
        echo '<span>' . ($author ? fullname($author) : 'Admin') . ' · ' .
            userdate($n->timecreated, get_string('strftimedatefullshort')) . '</span>';
        echo html_writer::link($durl, '🗑 Delete', [
            'style'   => 'color:#be123c;font-size:.72rem;text-decoration:none',
            'onclick' => "return confirm('Delete this note?')",
        ]);
        echo '</div>';
        echo '<div style="font-size:.84rem;color:#374151;line-height:1.5">' . nl2br(s($n->note)) . '</div>';
        echo '</div>';
    }
    echo '<form method="post" style="display:flex;gap:8px;margin-top:10px">';
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'savenote','value'=>1]);
    echo '<textarea name="note" rows="2" placeholder="Add a private note…" style="flex:1;font-family:var(--lt-font);font-size:.84rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px;resize:vertical;outline:none"></textarea>';
    echo '<button type="submit" style="font-family:var(--lt-font);font-size:.8rem;font-weight:700;background:var(--lt-accent,' . $brand . ');color:#fff;border:none;border-radius:8px;padding:0 14px;cursor:pointer;align-self:flex-end;height:36px">Save</button>';
    echo '</form></div></div>';
}

echo '</div>'; // ltp-wrap

} catch (\Throwable $e) {
    // Close any open divs then show the error centered
    echo '</div>'; // close any partial .ltp-wrap
    echo '<div style="max-width:740px;margin:20px auto;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui;font-size:.84rem">';
    echo '<strong style="color:#be123c">⚠ Error loading profile:</strong> ' . htmlspecialchars($e->getMessage());
    echo '<br><small style="color:#9ca3af">' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small></div>';
}
echo $OUTPUT->footer();
