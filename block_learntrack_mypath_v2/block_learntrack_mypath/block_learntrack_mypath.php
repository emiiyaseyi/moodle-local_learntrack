<?php
defined('MOODLE_INTERNAL') || die();

class block_learntrack_mypath extends block_base {

    public function init(): void { $this->title = 'My Learning Paths'; }
    public function has_config(): bool { return false; }
    public function instance_allow_multiple(): bool { return false; }
    public function applicable_formats(): array {
        return ['site-index' => true, 'my' => true, 'course-view' => false];
    }

    public function get_content(): ?stdClass {
        global $USER, $DB, $CFG;
        if ($this->content !== null) return $this->content;
        $this->content         = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) return $this->content;
        if (!file_exists($CFG->dirroot . '/local/learnpath/classes/data/helper.php')) return $this->content;
        require_once($CFG->dirroot . '/local/learnpath/classes/data/helper.php');

        $brand      = get_config('local_learnpath', 'brand_color')        ?: '#1e3a5f';
        $bname      = get_config('local_learnpath', 'brand_name')         ?: 'LearnTrack';
        $blk_learner= get_config('local_learnpath', 'block_learner_color') ?: $brand;
        $blk_mgr    = get_config('local_learnpath', 'block_manager_color') ?: '#5b21b6';
        if (!$blk_learner) $blk_learner = $brand;
        if (!$blk_mgr)     $blk_mgr     = '#5b21b6';
        $sysctx = context_system::instance();
        $isAdmin = has_capability('local/learnpath:manage',        $sysctx, $USER->id);
        $isMgr   = has_capability('local/learnpath:viewdashboard', $sysctx, $USER->id) && !$isAdmin;

        // Derive palette from brand hex
        $hex  = ltrim($brand, '#');
        $r    = hexdec(substr($hex, 0, 2));
        $g    = hexdec(substr($hex, 2, 2));
        $b    = hexdec(substr($hex, 4, 2));
        $dark = sprintf('#%02x%02x%02x', max(0,(int)($r*.70)), max(0,(int)($g*.70)), max(0,(int)($b*.70)));
        $pale = sprintf('#%02x%02x%02x',
            min(255,(int)(255*.92+$r*.08)),
            min(255,(int)(255*.92+$g*.08)),
            min(255,(int)(255*.92+$b*.08))
        );

        // ── Path membership ─────────────────────────────────────────────────────
        $groups = $DB->get_records('local_learnpath_groups', null, 'name ASC');
        $myGrps = [];
        if (!empty($groups)) {
            $dbman_b        = $DB->get_manager();
            $has_assign_tbl = $dbman_b->table_exists(new xmldb_table('local_learnpath_user_assign'));
            if ($isAdmin) {
                $myGrps = $groups;
            } else {
                $assigned_set = $has_assign_tbl
                    ? array_flip($DB->get_fieldset_select('local_learnpath_user_assign', 'groupid', 'userid = :uid', ['uid' => $USER->id]))
                    : [];
                $managed_set = $isMgr
                    ? array_flip($DB->get_fieldset_select('local_learnpath_managers', 'groupid', 'userid = :uid', ['uid' => $USER->id]))
                    : [];
                foreach ($groups as $grp) {
                    if ($isMgr && isset($managed_set[$grp->id])) { $myGrps[$grp->id] = $grp; continue; }
                    if ($has_assign_tbl) {
                        if (isset($assigned_set[$grp->id])) { $myGrps[$grp->id] = $grp; }
                        continue;
                    }
                    foreach (\local_learnpath\data\helper::get_group_courses($grp->id) as $crs) {
                        $cx = context_course::instance($crs->id, IGNORE_MISSING);
                        if ($cx && is_enrolled($cx, $USER->id)) { $myGrps[$grp->id] = $grp; break; }
                    }
                }
            }
        }

        $vis = get_config('local_learnpath', 'block_visibility') ?: 'enrolled';
        if ($vis === 'enrolled' && empty($myGrps) && !$isAdmin && !$isMgr) return $this->content;

        // ── Progress data (batched) ─────────────────────────────────────────────
        $prog_map  = \local_learnpath\data\helper::get_user_path_progress((int)$USER->id, array_keys($myGrps));
        $mgr_set   = array_flip($DB->get_fieldset_select('local_learnpath_managers', 'groupid', 'userid = :uid', ['uid' => $USER->id]));

        $now        = time();
        $items      = [];
        $totalDone  = 0;
        $totalAll   = 0;
        $overdueCt  = 0;

        foreach ($myGrps as $gid => $grp) {
            $prog  = $prog_map[$gid] ?? null;
            $done  = $prog ? (int)$prog->completed_courses : 0;
            $total = $prog ? (int)$prog->total_courses     : 0;
            $pct   = $prog ? (int)$prog->overall_progress  : 0;
            $last  = $prog ? ($prog->lastaccess ?? null)   : null;
            $mgr   = isset($mgr_set[$gid]) || $isAdmin;

            $deadline  = (int)($grp->deadline ?? 0);
            $days_left = $deadline > 0 ? (int)ceil(($deadline - $now) / 86400) : null;
            $overdue   = $deadline > 0 && $deadline < $now && $pct < 100;
            if ($overdue) $overdueCt++;

            // Status intelligence
            if ($pct >= 100)                                                          { $status = 'done'; }
            elseif ($overdue)                                                          { $status = 'overdue'; }
            elseif ($deadline > 0 && $days_left !== null && $days_left <= 7 && $pct < 75) { $status = 'atrisk'; }
            elseif ($pct > 0)                                                          { $status = 'inprogress'; }
            else                                                                       { $status = 'notstarted'; }

            $totalDone += $done;
            $totalAll  += $total;
            $items[] = compact('gid','grp','done','total','pct','last','days_left','deadline','overdue','status','mgr');
        }
        $overall = $totalAll > 0 ? (int)round($totalDone / $totalAll * 100) : 0;

        // Separate manager vs learner items
        $mgrItems     = array_values(array_filter($items, fn($i) =>  $i['mgr']));
        $learnerItems = array_values(array_filter($items, fn($i) => !$i['mgr']));

        // Priority path for "Continue Learning" card (most urgent incomplete)
        $priority = null;
        $sorted_learner = $learnerItems;
        usort($sorted_learner, function($a, $b) use ($now) {
            $ua = $a['overdue'] ? 3 : ($a['status']==='atrisk' ? 2 : ($a['pct']>0 ? 1 : 0));
            $ub = $b['overdue'] ? 3 : ($b['status']==='atrisk' ? 2 : ($b['pct']>0 ? 1 : 0));
            if ($ua !== $ub) return $ub - $ua;
            return ($b['last'] ?? 0) <=> ($a['last'] ?? 0);
        });
        foreach ($sorted_learner as $pi) {
            if ($pi['pct'] < 100) { $priority = $pi; break; }
        }

        // Reminder notice (set by reminders.php popup queue)
        $reminder_notice = '';
        $remind_raw = function_exists('get_user_preference')
            ? get_user_preference('lt_remind_popup', '', $USER->id)
            : '';
        if ($remind_raw) {
            $rd = json_decode($remind_raw, true);
            if (is_array($rd) && !empty($rd)) {
                $last_rd = end($rd);
                $rgrp    = $myGrps[$last_rd['groupid'] ?? 0] ?? null;
                if ($rgrp) {
                    $reminder_notice = '&#128276; Your manager sent a reminder for: <strong>' . format_string($rgrp->name) . '</strong>';
                }
            }
        }

        $bid     = 'ltrk-' . $this->instance->id;
        $prefKey = 'ltrk_lay_' . $USER->id;
        $myUrl   = new moodle_url('/local/learnpath/mypath.php');
        $dashUrl = new moodle_url('/local/learnpath/index.php');
        $ovUrl   = new moodle_url('/local/learnpath/overview.php');
        $profUrl = new moodle_url('/local/learnpath/myprofile.php');
        $circ    = round(2 * M_PI * 26, 2);
        $off     = round($circ * (1 - $overall / 100), 2);

        // ── CSS ────────────────────────────────────────────────────────────────
        $h = '';
        // Derive learner palette
        $lhex = ltrim($blk_learner, '#');
        $lr = hexdec(substr($lhex, 0, 2)); $lg = hexdec(substr($lhex, 2, 2)); $lb = hexdec(substr($lhex, 4, 2));
        $ldark = sprintf('#%02x%02x%02x', max(0,(int)($lr*.70)), max(0,(int)($lg*.70)), max(0,(int)($lb*.70)));
        $lpale = sprintf('#%02x%02x%02x', min(255,(int)(255*.92+$lr*.08)), min(255,(int)(255*.92+$lg*.08)), min(255,(int)(255*.92+$lb*.08)));

        $h .= '<style>
#' . $bid . '{font-family:system-ui,-apple-system,sans-serif;font-size:13px;--blk-brand:' . $blk_learner . ';--blk-dark:' . $ldark . ';--blk-pale:' . $lpale . ';--blk-mgr:' . $blk_mgr . '}
#' . $bid . ' *{box-sizing:border-box}
/* Layout switcher */
#' . $bid . ' .blk-sw{display:flex;gap:3px;justify-content:flex-end;margin-bottom:7px}
#' . $bid . ' .blk-sw button{background:#f3f4f6;border:1.5px solid #e5e7eb;border-radius:6px;width:26px;height:26px;cursor:pointer;color:#9ca3af;font-size:.9rem;padding:0;line-height:1;transition:all .15s}
#' . $bid . ' .blk-sw button.on{background:var(--blk-brand);border-color:var(--blk-brand);color:#fff}
/* Header */
#' . $bid . ' .blk-head{background:linear-gradient(135deg,#0f172a,var(--blk-brand));border-radius:12px;padding:14px 16px;color:#fff;margin-bottom:9px;display:flex;justify-content:space-between;align-items:center}
#' . $bid . ' .blk-head-brand{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;opacity:.7;margin-bottom:3px}
#' . $bid . ' .blk-head-title{font-size:.94rem;font-weight:800}
#' . $bid . ' .blk-head-sub{font-size:.68rem;opacity:.75;margin-top:2px}
#' . $bid . ' .blk-overall-bar{background:rgba(255,255,255,.2);height:4px;border-radius:100px;overflow:hidden;margin-top:8px}
#' . $bid . ' .blk-overall-fill{height:100%;background:#fff;border-radius:100px}
/* Reminder notice */
#' . $bid . ' .blk-notice{background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:7px 10px;font-size:.74rem;color:#92400e;margin-bottom:8px;line-height:1.4}
/* Priority card */
#' . $bid . ' .blk-priority{background:linear-gradient(135deg,var(--blk-pale),#fff);border:1.5px solid var(--blk-brand);border-radius:11px;padding:11px 13px;margin-bottom:9px}
#' . $bid . ' .blk-priority-label{font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--blk-brand);margin-bottom:3px}
#' . $bid . ' .blk-priority-name{font-size:.86rem;font-weight:800;color:#111;margin-bottom:5px;line-height:1.2}
#' . $bid . ' .blk-priority-meta{font-size:.68rem;color:#6b7280;margin-bottom:7px}
#' . $bid . ' .blk-continue-btn{display:block;text-align:center;background:var(--blk-brand);color:#fff!important;font-size:.8rem;font-weight:800;padding:9px;border-radius:8px;text-decoration:none!important;transition:opacity .15s}
#' . $bid . ' .blk-continue-btn:hover{opacity:.87}
/* Section labels */
#' . $bid . ' .blk-section{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--blk-brand);margin:9px 0 5px;display:flex;align-items:center;gap:4px}
#' . $bid . ' .blk-section.mgr-sec{color:var(--blk-mgr)}
/* PATH CARDS (cards layout) */
#' . $bid . ' .blk-path{background:#fff;border:1px solid #e8eaed;border-radius:11px;margin-bottom:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07);transition:box-shadow .2s}
#' . $bid . ' .blk-path:hover{box-shadow:0 4px 16px rgba(0,0,0,.11)}
#' . $bid . ' .blk-path.mgr-path{border-left:3px solid var(--blk-mgr)}
#' . $bid . ' .blk-path-top{padding:11px 13px 0;display:flex;justify-content:space-between;align-items:flex-start;gap:6px}
#' . $bid . ' .blk-pname{font-size:.84rem;font-weight:700;color:#111827;text-decoration:none;flex:1;line-height:1.3}
#' . $bid . ' .blk-pname:hover{color:var(--blk-brand)}
#' . $bid . ' .blk-ppct{font-size:.9rem;font-weight:900;flex-shrink:0}
#' . $bid . ' .blk-bar{height:5px;background:#eef0f3;border-radius:100px;overflow:hidden;margin:7px 13px 0}
#' . $bid . ' .blk-fill{height:100%;border-radius:100px;transition:width .5s}
#' . $bid . ' .blk-pmeta{padding:5px 13px 9px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:3px}
#' . $bid . ' .blk-meta-l{font-size:.67rem;color:#9ca3af}
#' . $bid . ' .blk-mgr-l{font-size:.68rem;font-weight:700;color:var(--blk-brand)!important;text-decoration:none;float:right}
/* BADGES */
#' . $bid . ' .bb{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:100px;white-space:nowrap}
#' . $bid . ' .bb-over{background:#fee2e2;color:#be123c}
#' . $bid . ' .bb-atrisk{background:#fef3c7;color:#92400e}
#' . $bid . ' .bb-ontrack{background:#dcfce7;color:#166534}
#' . $bid . ' .bb-done{background:#d1fae5;color:#065f46}
#' . $bid . ' .bb-mgr{background:#ede9fe;color:var(--blk-mgr)}
#' . $bid . ' .bb-np{background:#f3f4f6;color:#6b7280}
#' . $bid . ' .bb-warn{background:#fef3c7;color:#92400e}
#' . $bid . ' .bb-day{background:#f0fdf4;color:#15803d}
/* Summary strip */
#' . $bid . ' .blk-strip{background:rgba(' . $r . ',' . $g . ',' . $b . ',.07);border:1px solid rgba(' . $r . ',' . $g . ',' . $b . ',.12);border-radius:9px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;margin:6px 0}
#' . $bid . ' .blk-strip-t{font-size:.75rem;font-weight:600;color:#374151}
/* Collapsible toggle */
#' . $bid . ' .blk-hidden{display:none}
#' . $bid . ' .blk-toggle{font-size:.74rem;font-weight:700;color:var(--blk-brand);cursor:pointer;background:none;border:none;padding:3px 0;font-family:inherit;width:100%;text-align:center}
/* Buttons */
#' . $bid . ' .blk-btns{display:flex;flex-direction:column;gap:6px;margin-top:8px}
#' . $bid . ' .blk-bp{display:block;text-align:center;background:var(--blk-brand);color:#fff!important;font-size:.8rem;font-weight:700;padding:9px;border-radius:9px;text-decoration:none!important;transition:opacity .15s}
#' . $bid . ' .blk-bp:hover{opacity:.87}
#' . $bid . ' .blk-bs{display:block;text-align:center;background:#f3f4f6;color:#374151!important;font-size:.76rem;font-weight:700;padding:7px;border-radius:9px;text-decoration:none!important}
#' . $bid . ' .blk-bs:hover{background:#e5e7eb}
/* LIST layout */
#' . $bid . ' .lv-list .blk-lrow{display:flex;align-items:center;gap:7px;padding:6px 0;border-bottom:1px solid #f3f4f6}
#' . $bid . ' .lv-list .blk-lname{font-size:.8rem;font-weight:600;color:#111827;text-decoration:none;min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#' . $bid . ' .lv-list .blk-lname:hover{color:var(--blk-brand)}
#' . $bid . ' .lv-list .blk-bar{margin:0 6px;flex:1;height:5px}
/* MINIMAL layout */
#' . $bid . ' .lv-min{text-align:center;padding:8px 4px}
#' . $bid . ' .blk-empty{text-align:center;padding:18px;color:#9ca3af;font-size:.8rem}
</style>';

        // ── Layout switcher ─────────────────────────────────────────────────────
        $h .= '<div id="' . $bid . '">';
        $h .= '<div class="blk-sw">';
        $h .= '<button title="Cards"   onclick="ltrkLay(\'' . $bid . '\',\'cards\')">&#9635;</button>';
        $h .= '<button title="List"    onclick="ltrkLay(\'' . $bid . '\',\'list\')">&#9776;</button>';
        $h .= '<button title="Minimal" onclick="ltrkLay(\'' . $bid . '\',\'min\')">&#8942;</button>';
        $h .= '</div>';

        // ── Header (always visible across all layouts) ──────────────────────────
        $paths_in_prog = count(array_filter($learnerItems, fn($i) => $i['pct'] > 0 && $i['pct'] < 100));
        $paths_total   = count($learnerItems);
        $h .= '<div class="blk-head">';
        $h .= '<div style="flex:1">';
        $h .= '<div class="blk-head-brand">&#128218; ' . htmlspecialchars($bname) . '</div>';
        $h .= '<div class="blk-head-title">My Learning Paths</div>';
        if ($paths_total > 0) {
            $sub = $paths_in_prog > 0
                ? $paths_in_prog . ' of ' . $paths_total . ' path' . ($paths_total !== 1 ? 's' : '') . ' in progress'
                : ($overall >= 100 ? 'All paths complete ✓' : $paths_total . ' path' . ($paths_total !== 1 ? 's' : '') . ' assigned');
            $h .= '<div class="blk-head-sub">' . $sub . '</div>';
        }
        if ($overdueCt > 0) $h .= '<div style="margin-top:4px"><span class="bb bb-over">&#9888; ' . $overdueCt . ' overdue</span></div>';
        $h .= '<div class="blk-overall-bar"><div class="blk-overall-fill" style="width:' . $overall . '%"></div></div>';
        $h .= '</div>';
        // SVG ring (same as before)
        $h .= '<svg width="60" height="60" viewBox="0 0 60 60" style="flex-shrink:0">';
        $h .= '<circle cx="30" cy="30" r="26" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="5"/>';
        $h .= '<circle cx="30" cy="30" r="26" fill="none" stroke="#fff" stroke-width="5"';
        $h .= ' stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $off . '"';
        $h .= ' stroke-linecap="round" transform="rotate(-90 30 30)"/>';
        $h .= '<text x="30" y="25" text-anchor="middle" font-size="11" font-weight="800" fill="#fff">' . $overall . '%</text>';
        $h .= '<text x="30" y="35" text-anchor="middle" font-size="7" fill="rgba(255,255,255,.75)">overall</text>';
        $h .= '</svg>';
        $h .= '</div>'; // .blk-head

        if (empty($myGrps)) {
            $h .= '<div class="blk-empty"><div style="font-size:1.6rem;margin-bottom:6px">&#128218;</div>No learning paths assigned.</div>';
        } else {
            // ── Build shared HTML pieces ─────────────────────────────────────────
            $status_badges = [
                'done'       => ['bb-done',    '&#10003; Done'],
                'ontrack'    => ['bb-ontrack',  '&#128994; On Track'],
                'atrisk'     => ['bb-atrisk',   '&#128992; At Risk'],
                'overdue'    => ['bb-over',     '&#128308; Overdue'],
                'inprogress' => ['bb-warn',     '&#9203; In Progress'],
                'notstarted' => ['bb-np',       '&#9898; Not started'],
            ];

            // Helper: deadline badge
            $deadline_badge = function(array $pi): string {
                if ($pi['days_left'] === null || $pi['status'] === 'done') return '';
                if ($pi['overdue']) return '<span class="bb bb-over">&#9888; ' . abs($pi['days_left']) . 'd overdue</span>';
                if ($pi['days_left'] <= 7) return '<span class="bb bb-atrisk">&#9201; ' . $pi['days_left'] . 'd left</span>';
                if ($pi['days_left'] <= 30) return '<span class="bb bb-day">&#128197; ' . $pi['days_left'] . 'd</span>';
                return '';
            };

            // Helper: last-active text
            $last_active = function(?int $last) use ($now): string {
                if (!$last) return '';
                $days = (int)floor(($now - $last) / 86400);
                return $days === 0 ? 'Today' : ($days === 1 ? 'Yesterday' : $days . 'd ago');
            };

            // ── CARDS layout HTML ────────────────────────────────────────────────
            $cardsHtml = '';
            $listHtml  = '';

            // Reminder notice in cards
            if ($reminder_notice) {
                $cardsHtml .= '<div class="blk-notice">' . $reminder_notice . '</div>';
            }

            // Priority card in cards layout
            if ($priority && $priority['pct'] < 100) {
                $pu  = new moodle_url('/local/learnpath/mypath.php', ['groupid' => $priority['gid']]);
                $pnm = format_string($priority['grp']->name);
                $meta_parts = [$priority['pct'] . '% complete', $priority['done'] . '/' . $priority['total'] . ' courses'];
                $la = $last_active($priority['last'] ? (int)$priority['last'] : null);
                if ($la) $meta_parts[] = $la;
                if ($priority['days_left'] !== null) {
                    if ($priority['overdue']) {
                        $meta_parts[] = '&#128308; Overdue by ' . abs($priority['days_left']) . 'd';
                    } else {
                        $meta_parts[] = 'Due in ' . $priority['days_left'] . 'd';
                    }
                }
                $cardsHtml .= '<div class="blk-priority">';
                $cardsHtml .= '<div class="blk-priority-label">&#128073; Continue Learning</div>';
                $cardsHtml .= '<div class="blk-priority-name">' . $pnm . '</div>';
                $cardsHtml .= '<div class="blk-priority-meta">' . implode(' · ', $meta_parts) . '</div>';
                $cardsHtml .= '<a href="' . $pu . '" class="blk-continue-btn">Continue Learning &#8594;</a>';
                $cardsHtml .= '</div>';
            }

            // Manager section
            if (!empty($mgrItems)) {
                $cardsHtml .= '<div class="blk-section mgr-sec">&#9878; Paths I Manage</div>';
                $listHtml  .= '<div class="blk-section mgr-sec" style="font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--blk-mgr);margin:5px 0 3px">&#9878; Paths I Manage</div>';
                foreach ($mgrItems as $pi) {
                    $du = new moodle_url('/local/learnpath/index.php', ['groupid' => $pi['gid']]);
                    $ru = new moodle_url('/local/learnpath/reminders.php', ['groupid' => $pi['gid']]);
                    $nm = format_string($pi['grp']->name);
                    // Card
                    $cardsHtml .= '<div class="blk-path mgr-path">';
                    $cardsHtml .= '<div class="blk-path-top"><a href="' . $du . '" class="blk-pname" style="color:var(--blk-mgr)">' . $nm . '</a><span class="bb bb-mgr">&#9878; Mgr</span></div>';
                    $cardsHtml .= '<div class="blk-pmeta">';
                    $cardsHtml .= '<a href="' . $du . '" style="font-size:.72rem;font-weight:700;color:var(--blk-mgr);text-decoration:none">&#128202; View Learner Progress &#8594;</a>';
                    $cardsHtml .= '<a href="' . $ru . '" style="font-size:.66rem;color:#9ca3af;text-decoration:none">&#128276;</a>';
                    $cardsHtml .= '</div></div>';
                    // List row
                    $listHtml .= '<div class="blk-lrow"><a href="' . $du . '" class="blk-lname" style="color:var(--blk-mgr)">&#9878; ' . $nm . '</a>';
                    $listHtml .= '<span style="font-size:.68rem;color:var(--blk-mgr);font-weight:700;white-space:nowrap">Dashboard &#8594;</span></div>';
                }
            }

            // Learner section
            if (!empty($learnerItems)) {
                if (!empty($mgrItems)) {
                    $cardsHtml .= '<div class="blk-section">&#128218; My Learning Paths</div>';
                    $listHtml  .= '<div class="blk-section" style="font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--blk-brand,#1e3a5f);margin:7px 0 3px">&#128218; My Learning Paths</div>';
                }

                $show_limit = 3;
                $has_more   = count($learnerItems) > $show_limit;

                foreach ($learnerItems as $idx => $pi) {
                    $clr = $pi['pct'] >= 100 ? '#10b981' : ($pi['pct'] > 0 ? '#f59e0b' : '#d1d5db');
                    $pu  = new moodle_url('/local/learnpath/mypath.php', ['groupid' => $pi['gid']]);
                    $nm  = format_string($pi['grp']->name);
                    $hidden_cls = ($has_more && $idx >= $show_limit) ? ' blk-hidden' : '';
                    [$badge_cls, $badge_lbl] = $status_badges[$pi['status']] ?? ['bb-np', ''];

                    // Left meta
                    $meta_l = $pi['done'] . '/' . $pi['total'] . ' courses';
                    $la = $last_active($pi['last'] ? (int)$pi['last'] : null);
                    if ($la) $meta_l .= ' · ' . $la;

                    // CARD
                    $cardsHtml .= '<div class="blk-path' . $hidden_cls . '">';
                    $cardsHtml .= '<div class="blk-path-top">';
                    $cardsHtml .= '<a href="' . $pu . '" class="blk-pname">' . $nm . '</a>';
                    $cardsHtml .= '<span class="blk-ppct" style="color:' . $clr . '">' . $pi['pct'] . '%</span>';
                    $cardsHtml .= '</div>';
                    $cardsHtml .= '<div class="blk-bar"><div class="blk-fill" style="width:' . $pi['pct'] . '%;background:' . $clr . '"></div></div>';
                    $cardsHtml .= '<div class="blk-pmeta">';
                    $cardsHtml .= '<span class="blk-meta-l">' . $meta_l . '</span>';
                    $cardsHtml .= '<div style="display:flex;gap:3px;align-items:center;flex-wrap:wrap">';
                    $cardsHtml .= '<span class="bb ' . $badge_cls . '">' . $badge_lbl . '</span>';
                    $cardsHtml .= $deadline_badge($pi);
                    $cardsHtml .= '</div></div></div>';

                    // LIST ROW
                    $listHtml .= '<div class="blk-lrow' . $hidden_cls . '">';
                    $listHtml .= '<a href="' . $pu . '" class="blk-lname">' . $nm . '</a>';
                    $listHtml .= '<div class="blk-bar"><div class="blk-fill" style="width:' . $pi['pct'] . '%;background:' . $clr . '"></div></div>';
                    $listHtml .= '<span style="font-size:.76rem;font-weight:800;color:' . $clr . ';min-width:30px;text-align:right">' . $pi['pct'] . '%</span>';
                    $listHtml .= '<span class="bb ' . $badge_cls . '" style="flex-shrink:0">' . $badge_lbl . '</span>';
                    $listHtml .= '</div>';
                }

                if ($has_more) {
                    $rem = count($learnerItems) - $show_limit;
                    $toggle_btn = '<button class="blk-toggle" onclick="ltrkToggle(\'' . $bid . '\')" id="' . $bid . '-tog">&#9660; Show ' . $rem . ' more path' . ($rem !== 1 ? 's' : '') . '</button>';
                    $cardsHtml .= $toggle_btn;
                    $listHtml  .= $toggle_btn;
                }
            }

            // Summary strip
            $strip  = '<div class="blk-strip">';
            $strip .= '<span class="blk-strip-t">' . $totalDone . '/' . $totalAll . ' courses</span>';
            if ($overdueCt > 0) $strip .= '<span class="bb bb-over">&#9888; ' . $overdueCt . ' overdue</span>';
            elseif ($overall >= 100) $strip .= '<span class="bb bb-done">&#127891; All done!</span>';
            $strip .= '</div>';

            // Buttons
            $btns  = '<div class="blk-btns">';
            if (!empty($learnerItems)) $btns .= '<a href="' . $myUrl . '" class="blk-bp">My Learning Dashboard &#8594;</a>';
            $btns .= '<a href="' . $profUrl . '" class="blk-bs">&#128100; My Profile</a>';
            if ($isMgr || $isAdmin) $btns .= '<a href="' . $dashUrl . '" class="blk-bs">&#128202; Learner Dashboard</a>';
            if ($isAdmin)           $btns .= '<a href="' . $ovUrl  . '" class="blk-bs">&#128225; Overview</a>';
            $btns .= '</div>';

            // ── CARDS layout ──────────────────────────────────────────────────
            $h .= '<div class="ltrk-lv lv-cards">' . $cardsHtml . $strip . $btns . '</div>';

            // ── LIST layout ───────────────────────────────────────────────────
            $listHead  = '<div style="font-size:.72rem;font-weight:700;color:var(--blk-brand,#1e3a5f);text-transform:uppercase;letter-spacing:.5px;padding-bottom:5px;border-bottom:2px solid var(--blk-brand,#1e3a5f);margin-bottom:5px">';
            $listHead .= 'Paths &mdash; ' . $overall . '% overall</div>';
            $h .= '<div class="ltrk-lv lv-list" style="display:none">' . $listHead . $listHtml . $strip . $btns . '</div>';

            // ── MINIMAL layout ────────────────────────────────────────────────
            $h .= '<div class="ltrk-lv lv-min" style="display:none">';
            $h .= '<div style="font-size:2.6rem;font-weight:900;color:var(--blk-brand,#1e3a5f);line-height:1">' . $overall . '%</div>';
            $h .= '<div style="font-size:.68rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin:3px 0 8px">Overall Progress</div>';
            $h .= '<div style="font-size:.76rem;color:#374151;margin-bottom:4px">' . $totalDone . '/' . $totalAll . ' courses &middot; ' . count($myGrps) . ' path' . (count($myGrps) !== 1 ? 's' : '') . '</div>';
            if ($overdueCt > 0) $h .= '<div style="font-size:.72rem;font-weight:700;color:#be123c;margin-bottom:6px">&#9888; ' . $overdueCt . ' overdue</div>';
            $h .= $btns . '</div>';
        }

        $h .= '</div><!-- /blk -->';

        // ── JavaScript: layout switcher + collapsible toggle ───────────────────
        global $PAGE;
        $lay_js  = '(function(){';
        $lay_js .= 'var K=' . json_encode($prefKey) . ',B=' . json_encode($bid) . ',Ls=["cards","list","min"];';
        $lay_js .= 'window.ltrkLay=function(bid,lay){';
        $lay_js .=   'var bl=document.getElementById(bid);if(!bl)return;';
        $lay_js .=   'bl.querySelectorAll(".ltrk-lv").forEach(function(el){el.style.display=el.classList.contains("lv-"+lay)?"":"none";});';
        $lay_js .=   'bl.querySelectorAll(".blk-sw button").forEach(function(btn,i){btn.classList.toggle("on",Ls[i]===lay);});';
        $lay_js .=   'try{localStorage.setItem(K,lay);}catch(e){}';
        $lay_js .= '};';
        $lay_js .= 'window.ltrkToggle=function(bid){';
        $lay_js .=   'var btn=document.getElementById(bid+"-tog");if(!btn)return;';
        $lay_js .=   'var open=btn.getAttribute("data-open")==="1";';
        $lay_js .=   'document.querySelectorAll("#"+bid+" .blk-hidden").forEach(function(el){el.style.display=open?"none":"";});';
        $lay_js .=   'btn.setAttribute("data-open",open?"0":"1");';
        $lay_js .=   'btn.textContent=open?"▼ Show more paths":"▲ Show fewer paths";';
        $lay_js .= '};';
        $lay_js .= 'document.addEventListener("DOMContentLoaded",function(){';
        $lay_js .=   'var s="cards";try{s=localStorage.getItem(K)||"cards";}catch(e){}';
        $lay_js .=   'ltrkLay(B,s);';
        $lay_js .= '});';
        $lay_js .= '})();';
        $PAGE->requires->js_init_code($lay_js);

        $this->content->text = $h;
        return $this->content;
    }
}
