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
 * LearnTrack — Leaderboard with criteria, points and badges.
 */
require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;

require_login();
require_capability('local/learnpath:viewdashboard', context_system::instance());

$groupid  = optional_param('groupid',  0,        PARAM_INT);
$action   = optional_param('action',   '',        PARAM_ALPHANUMEXT);
$PAGE->set_url(new moodle_url('/local/learnpath/leaderboard.php', ['groupid'=>$groupid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Leaderboard');

global $DB, $OUTPUT, $USER, $CFG;
$brand   = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$isadmin = has_capability('local/learnpath:manage', context_system::instance());
$dbman   = $DB->get_manager();

// ── Helper: ensure tables exist ───────────────────────────────────────────────
$tables_ready = $dbman->table_exists(new xmldb_table('local_learnpath_badges'))
             && $dbman->table_exists(new xmldb_table('local_learnpath_criteria'))
             && $dbman->table_exists(new xmldb_table('local_learnpath_points'))
             && $dbman->table_exists(new xmldb_table('local_learnpath_user_badges'));

// ── Admin actions — only run when an action is actually being performed ────────
if ($isadmin && $tables_ready && $action !== '' && confirm_sesskey()) {

    if ($action === 'save_criteria') {
        // Use crit_enabled_id[] to track which IDs have enabled checkboxes
        // (unchecked boxes are not submitted, so we can't use positional matching)
        $ids          = optional_param_array('crit_id',         [], PARAM_INT);
        $names        = optional_param_array('crit_name',       [], PARAM_TEXT);
        $descs        = optional_param_array('crit_desc',       [], PARAM_TEXT);
        $points       = optional_param_array('crit_points',     [], PARAM_INT);
        $enabled_ids  = optional_param_array('crit_enabled_id', [], PARAM_INT);
        foreach ($ids as $i => $id) {
            if (empty(trim($names[$i] ?? ''))) { continue; }
            $is_enabled = in_array((int)$id, $enabled_ids) ? 1 : ($id === 0 ? 1 : 0);
            if ($id > 0) {
                $rec_u = (object)[
                    'id'          => $id,
                    'name'        => $names[$i] ?? '',
                    'description' => $descs[$i] ?? '',
                    'points'      => (int)($points[$i] ?? 0),
                    'enabled'     => $is_enabled,
                ];
                // Only set timemodified if column exists
                $crit_tbl = new xmldb_table('local_learnpath_criteria');
                $crit_fld = new xmldb_field('timemodified');
                if ($dbman->field_exists($crit_tbl, $crit_fld)) {
                    $rec_u->timemodified = time();
                }
                $DB->update_record('local_learnpath_criteria', $rec_u);
            } else {
                $DB->insert_record('local_learnpath_criteria', (object)[
                    'name'        => $names[$i] ?? '',
                    'description' => $descs[$i] ?? '',
                    'points'      => (int)($points[$i] ?? 0),
                    'event_type'  => 'manual_award',
                    'enabled'     => 1,
                    'sortorder'   => 99,
                    'timecreated' => time(),
                ]);
            }
        }
        redirect(new moodle_url('/local/learnpath/leaderboard.php', ['groupid'=>$groupid, 'tab'=>'criteria']),
            'Criteria saved.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'save_badges') {
        $ids    = optional_param_array('badge_id',    [], PARAM_INT);
        $names  = optional_param_array('badge_name',  [], PARAM_TEXT);
        $descs  = optional_param_array('badge_desc',  [], PARAM_TEXT);
        $icons  = optional_param_array('badge_icon',  [], PARAM_TEXT);
        $pts    = optional_param_array('badge_pts',   [], PARAM_INT);
        foreach ($ids as $i => $id) {
            $nm = trim($names[$i] ?? '');
            if ($id === 0 && $nm === '') { continue; } // skip empty new row
            $rec = (object)[
                'name'        => $nm !== '' ? $nm : ($names[$i] ?? ''),
                'description' => $descs[$i] ?? '',
                'icon'        => trim($icons[$i] ?? '') ?: '🏅',
                'points_req'  => (int)($pts[$i] ?? 0),
            ];
            if ($id > 0) {
                $rec->id = $id;
                $DB->update_record('local_learnpath_badges', $rec);
            } else {
                $rec->sortorder   = 99;
                $rec->timecreated = time();
                $DB->insert_record('local_learnpath_badges', $rec);
            }
        }
        redirect(new moodle_url('/local/learnpath/leaderboard.php', ['groupid'=>$groupid, 'tab'=>'badges']),
            'Badges saved.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'delete_criteria') {
        $cid = required_param('crit_id', PARAM_INT);
        $DB->delete_records('local_learnpath_criteria', ['id' => $cid]);
        redirect(new moodle_url('/local/learnpath/leaderboard.php', ['groupid'=>$groupid, 'tab'=>'criteria']));
    }

    if ($action === 'delete_badge') {
        $bid = required_param('badge_id', PARAM_INT);
        $DB->delete_records('local_learnpath_badges',      ['id' => $bid]);
        $DB->delete_records('local_learnpath_user_badges', ['badgeid' => $bid]);
        redirect(new moodle_url('/local/learnpath/leaderboard.php', ['groupid'=>$groupid, 'tab'=>'badges']));
    }
}

$tab = optional_param('tab', 'leaderboard', PARAM_ALPHA);
$groups = DH::get_groups();

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome', ['style' => 'display:inline-block;margin-bottom:14px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
try {
echo '<style>:root{--lt-primary:'.$brand.';--lt-accent:'.$brand.'}</style>';

// Nav
echo html_writer::link(new moodle_url('/local/learnpath/overview.php'), '← Overview',
    ['style'=>'display:inline-block;margin:0 8px 12px 0;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
echo html_writer::link(new moodle_url('/local/learnpath/index.php', ['groupid'=>$groupid]), '📊 Dashboard',
    ['style'=>'display:inline-block;margin:0 0 12px 0;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);

// Page header
echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo '<h1 class="lt-page-title">🏆 Leaderboard</h1>';
echo '<p class="lt-page-subtitle">Points, badges and rankings</p>';
echo '</div><div>';
$gopts = [0 => '— Select a path —'];
foreach ($groups as $g) { $gopts[$g->id] = format_string($g->name); }
echo html_writer::select($gopts, 'groupid', $groupid, false, [
    'class'    => 'lt-select',
    'style'    => 'background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff',
    'onchange' => "window.location='?groupid='+this.value+'&tab={$tab}'"
]);
echo '</div></div></div>';

// Tabs
$tabs_list = [
    'leaderboard' => '🏆 Rankings',
    'badges'      => '🎖 Badges',
    'criteria'    => '⚙️ Criteria',
];
if (!$isadmin) {
    unset($tabs_list['criteria']);
}
echo '<div style="display:flex;gap:6px;margin-bottom:16px;border-bottom:2px solid #e5e7eb;padding-bottom:0">';
foreach ($tabs_list as $t => $lbl) {
    $turl = new moodle_url('/local/learnpath/leaderboard.php', ['groupid'=>$groupid,'tab'=>$t]);
    $active = $tab === $t;
    echo html_writer::link($turl, $lbl, [
        'style' => 'font-family:var(--lt-font);font-size:.84rem;font-weight:700;padding:8px 16px;text-decoration:none;border-radius:8px 8px 0 0;'
            . ($active ? 'background:var(--lt-accent);color:#fff' : 'color:#374151')
    ]);
}
echo '</div>';

if (!$tables_ready) {
    echo '<div class="lt-card"><div class="lt-card-body">';
    echo '<p style="font-family:var(--lt-font);color:#9ca3af">Run the upgrade (Site Admin → Notifications) to enable leaderboard features.</p>';
    echo '</div></div>';
    echo $OUTPUT->footer(); exit;
}

// ── TAB: LEADERBOARD ──────────────────────────────────────────────────────────
if ($tab === 'leaderboard') {
    if (!$groupid) {
        echo '<div class="lt-empty-state"><div class="lt-empty-icon">🏆</div>';
        echo '<h3 class="lt-empty-title">Select a Learning Path</h3>';
        echo '<p class="lt-empty-desc">Choose a path above to see the rankings.</p></div>';
    } else {
        $summary = DH::get_progress_summary($groupid, $USER->id);
        if (empty($summary)) {
            echo '<p style="font-family:var(--lt-font);color:#9ca3af;padding:24px">No learners in this path yet.</p>';
        } else {
            // Build rankings from progress data
            $rankings = [];
            foreach ($summary as $row) {
                // Points = sum from points table + auto-calculated from progress
                $db_points = (int)$DB->get_field_sql(
                    "SELECT COALESCE(SUM(points),0) FROM {local_learnpath_points} WHERE userid=:uid",
                    ['uid' => $row->userid]
                );
                // Auto-award points based on progress if none recorded
                $auto_points = 0;
                if ($db_points === 0) {
                    if ($row->overall_progress >= 100) {
                        $auto_points = (int)$DB->get_field_sql(
                            "SELECT COALESCE(SUM(points),0) FROM {local_learnpath_criteria}
                             WHERE event_type IN ('course_complete','path_complete') AND enabled=1"
                        ) ?: 100;
                    } else {
                        $auto_points = (int)round($row->overall_progress);
                    }
                }
                $total_points = $db_points + $auto_points;

                // Get badges for this user
                $user_badges = $DB->get_records_sql(
                    "SELECT b.icon, b.name FROM {local_learnpath_user_badges} ub
                     JOIN {local_learnpath_badges} b ON b.id=ub.badgeid
                     WHERE ub.userid=:uid ORDER BY b.points_req DESC",
                    ['uid' => $row->userid], 0, 3
                );

                $rankings[] = (object)[
                    'userid'        => $row->userid,
                    'firstname'     => $row->firstname,
                    'lastname'      => $row->lastname,
                    'progress'      => $row->overall_progress,
                    'points'        => $total_points,
                    'badges'        => $user_badges,
                    'completed'     => $row->completed_courses ?? 0,
                    'total_courses' => $row->total_courses ?? 0,
                ];
            }
            usort($rankings, fn($a,$b) => $b->points <=> $a->points ?: $b->progress <=> $a->progress);

            $medals = ['🥇','🥈','🥉'];
            echo '<div class="lt-card">';
            echo '<table class="lt-data-table"><thead><tr>';
            foreach (['Rank','Learner','Courses','Progress','Points','Badges'] as $h) {
                echo '<th>'.$h.'</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($rankings as $rank => $r) {
                $medal = $medals[$rank] ?? '#'.($rank+1);
                $prof_url = new moodle_url('/local/learnpath/profile.php', ['userid'=>$r->userid,'groupid'=>$groupid]);
                echo '<tr>';
                echo '<td><span style="font-size:1.1rem">'.$medal.'</span></td>';
                echo '<td>'.html_writer::link($prof_url, format_string($r->firstname.' '.$r->lastname),
                    ['style'=>'font-weight:700;color:var(--lt-accent);text-decoration:none']).'</td>';
                echo '<td>'.$r->completed.'/'.$r->total_courses.'</td>';
                // Progress bar
                $bc = $r->progress >= 100 ? '#10b981' : ($r->progress > 0 ? '#f59e0b' : '#d1d5db');
                echo '<td><div class="lt-progress-wrap"><div class="lt-progress-track">';
                echo '<div class="lt-progress-fill" style="width:'.$r->progress.'%;background:'.$bc.'"></div>';
                echo '</div><span class="lt-progress-pct">'.$r->progress.'%</span></div></td>';
                echo '<td><strong style="color:var(--lt-accent)">'.$r->points.'</strong> pts</td>';
                // Badges
                echo '<td>';
                foreach ($r->badges as $b) {
                    echo '<span title="'.s($b->name).'" style="font-size:1.2rem;margin-right:3px">'.$b->icon.'</span>';
                }
                if (empty($r->badges)) echo '<span style="color:#d1d5db;font-size:.78rem">—</span>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
    }
}

// ── TAB: BADGES ───────────────────────────────────────────────────────────────
if ($tab === 'badges') {
    $badges = $DB->get_records('local_learnpath_badges', null, 'points_req ASC');

    // Show learner's own badges if not admin
    if (!$isadmin) {
        $my_badges = $DB->get_records_sql(
            "SELECT b.* FROM {local_learnpath_user_badges} ub
             JOIN {local_learnpath_badges} b ON b.id=ub.badgeid
             WHERE ub.userid=:uid ORDER BY b.points_req ASC",
            ['uid' => $USER->id]
        );
        $my_badge_ids = array_column((array)$my_badges, 'id');

        echo '<h3 style="font-family:var(--lt-font);font-size:1rem;font-weight:800;margin:0 0 12px">Your Badges</h3>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px">';
        foreach ($my_badges as $b) {
            echo '<div style="background:#fff;border:2px solid #fbbf24;border-radius:12px;padding:14px 18px;text-align:center;min-width:110px">';
            echo '<div style="font-size:2rem">'.$b->icon.'</div>';
            echo '<div style="font-family:var(--lt-font);font-size:.82rem;font-weight:700;color:#111827;margin-top:6px">'.s($b->name).'</div>';
            echo '<div style="font-family:var(--lt-font);font-size:.72rem;color:#9ca3af">'.$b->points_req.' pts</div>';
            echo '</div>';
        }
        if (empty($my_badges)) {
            echo '<p style="font-family:var(--lt-font);color:#9ca3af">No badges yet. Keep learning to earn your first badge!</p>';
        }
        echo '</div>';
        echo '<h3 style="font-family:var(--lt-font);font-size:1rem;font-weight:800;margin:0 0 12px">All Badges</h3>';
    }

    // All badges grid
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">';
    foreach ($badges as $b) {
        $earned = !$isadmin && in_array($b->id, $my_badge_ids ?? []);
        $opacity = (!$isadmin && !$earned) ? 'opacity:.4' : '';
        echo '<div style="background:#fff;border:1.5px solid '.($earned?'#fbbf24':'#e5e7eb').';border-radius:12px;padding:16px;text-align:center;'.$opacity.'">';
        echo '<div style="font-size:2.2rem">'.$b->icon.'</div>';
        echo '<div style="font-family:var(--lt-font);font-size:.84rem;font-weight:800;color:#111827;margin:8px 0 4px">'.s($b->name).'</div>';
        echo '<div style="font-family:var(--lt-font);font-size:.76rem;color:#6b7280">'.s($b->description).'</div>';
        echo '<div style="font-family:var(--lt-font);font-size:.78rem;font-weight:700;color:var(--lt-accent);margin-top:8px">'.$b->points_req.' points</div>';
        if ($isadmin) {
            $del_url = new moodle_url('/local/learnpath/leaderboard.php',
                ['action'=>'delete_badge','badge_id'=>$b->id,'sesskey'=>sesskey(),'groupid'=>$groupid,'tab'=>'badges']);
            echo '<br>'.html_writer::link($del_url,'🗑 Delete',['style'=>'font-size:.72rem;color:#ef4444;text-decoration:none']);
        }
        echo '</div>';
    }
    echo '</div>';

    if ($isadmin) {
        // Edit form
        echo '<div class="lt-card" style="margin-top:20px">';
        echo '<div class="lt-card-header"><h3 class="lt-card-title">Edit Badges</h3></div>';
        echo '<div class="lt-card-body">';
        echo '<form method="post">';
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'save_badges']);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'groupid','value'=>$groupid]);
        echo '<div style="overflow-x:auto"><table class="lt-data-table"><thead><tr>';
        foreach (['Icon','Name','Description','Points Required',''] as $h) { echo '<th>'.$h.'</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($badges as $i => $b) {
            echo '<tr>';
            echo '<td><input name="badge_id[]" type="hidden" value="'.$b->id.'">';
            echo '<input name="badge_icon[]" type="text" value="'.s($b->icon).'" style="width:48px;font-size:1.2rem;text-align:center;border:1px solid #e5e7eb;border-radius:6px;padding:4px"></td>';
            echo '<td><input name="badge_name[]" type="text" value="'.s($b->name).'" style="width:140px;border:1px solid #e5e7eb;border-radius:6px;padding:5px 8px;font-family:var(--lt-font)"></td>';
            echo '<td><input name="badge_desc[]" type="text" value="'.s($b->description).'" style="width:220px;border:1px solid #e5e7eb;border-radius:6px;padding:5px 8px;font-family:var(--lt-font)"></td>';
            echo '<td><input name="badge_pts[]" type="number" value="'.$b->points_req.'" style="width:80px;border:1px solid #e5e7eb;border-radius:6px;padding:5px 8px"></td>';
            echo '<td></td></tr>';
        }
        // New badge row
        echo '<tr style="background:#f0fdf4">';
        echo '<td><input name="badge_id[]" type="hidden" value="0"><input name="badge_icon[]" type="text" placeholder="🏅" style="width:48px;font-size:1.2rem;text-align:center;border:1px solid #6ee7b7;border-radius:6px;padding:4px"></td>';
        echo '<td><input name="badge_name[]" type="text" placeholder="New badge name" style="width:140px;border:1px solid #6ee7b7;border-radius:6px;padding:5px 8px;font-family:var(--lt-font)"></td>';
        echo '<td><input name="badge_desc[]" type="text" placeholder="Description" style="width:220px;border:1px solid #6ee7b7;border-radius:6px;padding:5px 8px;font-family:var(--lt-font)"></td>';
        echo '<td><input name="badge_pts[]" type="number" placeholder="0" style="width:80px;border:1px solid #6ee7b7;border-radius:6px;padding:5px 8px"></td>';
        echo '<td><span style="font-size:.74rem;color:#059669;font-family:var(--lt-font)">+ New</span></td></tr>';
        echo '</tbody></table></div>';
        echo '<div style="margin-top:12px">';
        echo '<button type="submit" class="lt-btn lt-btn-primary">Save Badges</button>';
        echo '</div></form></div></div>';
    }
}

// ── TAB: CRITERIA ─────────────────────────────────────────────────────────────
if ($tab === 'criteria' && $isadmin) {
    $criteria = $DB->get_records('local_learnpath_criteria', null, 'sortorder ASC');

    echo '<div class="lt-card">';
    echo '<div class="lt-card-header"><h3 class="lt-card-title">⚙️ Scoring Criteria</h3>';
    echo '<span style="font-size:.76rem;color:#9ca3af;font-family:var(--lt-font)">Points can be positive or negative</span></div>';
    echo '<div class="lt-card-body">';
    echo '<form method="post">';
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>'save_criteria']);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'groupid','value'=>$groupid]);
    echo '<div style="overflow-x:auto"><table class="lt-data-table"><thead><tr>';
    foreach (['Name','Description','Points','Event Type','Active',''] as $h) { echo '<th>'.$h.'</th>'; }
    echo '</tr></thead><tbody>';

    $style_inp = 'border:1px solid #e5e7eb;border-radius:6px;padding:5px 8px;font-family:var(--lt-font)';
    foreach ($criteria as $cr) {
        echo '<tr>';
        echo '<td><input name="crit_id[]" type="hidden" value="'.$cr->id.'">';
        echo '<input name="crit_name[]" type="text" value="'.s($cr->name).'" style="width:160px;'.$style_inp.'"></td>';
        echo '<td><input name="crit_desc[]" type="text" value="'.s($cr->description??'').'" style="width:220px;'.$style_inp.'"></td>';
        echo '<td><input name="crit_points[]" type="number" value="'.$cr->points.'" style="width:70px;'.$style_inp.'"></td>';
        echo '<td><code style="font-size:.74rem;color:#6b7280">'.s($cr->event_type).'</code></td>';
        echo '<td><input type="checkbox" name="crit_enabled_id[]" value="'.$cr->id.'"'.($cr->enabled?' checked':'').'></td>';
        $del_url = new moodle_url('/local/learnpath/leaderboard.php',
            ['action'=>'delete_criteria','crit_id'=>$cr->id,'sesskey'=>sesskey(),'groupid'=>$groupid,'tab'=>'criteria']);
        echo '<td>'.html_writer::link($del_url,'🗑',['style'=>'color:#ef4444;text-decoration:none']).'</td>';
        echo '</tr>';
    }
    // New criteria row
    echo '<tr style="background:#f0fdf4">';
    echo '<td><input name="crit_id[]" type="hidden" value="0"><input name="crit_name[]" type="text" placeholder="New criterion" style="width:160px;border:1px solid #6ee7b7;border-radius:6px;padding:5px 8px;font-family:var(--lt-font)"></td>';
    echo '<td><input name="crit_desc[]" type="text" placeholder="Description" style="width:220px;border:1px solid #6ee7b7;border-radius:6px;padding:5px 8px;font-family:var(--lt-font)"></td>';
    echo '<td><input name="crit_points[]" type="number" placeholder="10" style="width:70px;border:1px solid #6ee7b7;border-radius:6px;padding:5px 8px"></td>';
    echo '<td><code style="font-size:.74rem;color:#059669">manual_award</code></td>';
    echo '<td><span style="font-size:.72rem;color:#059669">✓ Enabled</span><input type="hidden" name="crit_enabled_id[]" value="new"></td>';
    echo '<td><span style="font-size:.74rem;color:#059669;font-family:var(--lt-font)">+ New</span></td></tr>';
    echo '</tbody></table></div>';
    echo '<div style="margin-top:12px"><button type="submit" class="lt-btn lt-btn-primary">Save Criteria</button></div>';
    echo '</form></div></div>';
}

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'
    .html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank'])
    .'<span class="lt-sep">·</span><span>LearnTrack v2.0.0</span></div>';
} catch (\Throwable $e) {
    echo '<div style="margin:20px;padding:16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-family:system-ui"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '<br><small>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small></div>';
}
echo $OUTPUT->footer();
