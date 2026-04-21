<?php
defined('MOODLE_INTERNAL') || die();

class block_learntrack_mypath extends block_base {

    public function init(): void { $this->title = 'My Learning Paths'; }
    public function has_config(): bool { return false; }
    public function instance_allow_multiple(): bool { return false; }
    public function applicable_formats(): array {
        return ['site-index'=>true,'my'=>true,'course-view'=>false];
    }

    public function get_content(): ?stdClass {
        global $USER, $DB, $CFG;
        if ($this->content !== null) return $this->content;
        $this->content = new stdClass();
        $this->content->text = $this->content->footer = '';

        if (!isloggedin() || isguestuser()) return $this->content;
        if (!file_exists($CFG->dirroot.'/local/learnpath/classes/data/helper.php')) return $this->content;
        require_once($CFG->dirroot.'/local/learnpath/classes/data/helper.php');

        $brand  = get_config('local_learnpath','brand_color') ?: '#1e3a5f';
        $bname  = get_config('local_learnpath','brand_name')  ?: 'LearnTrack';
        $sysctx = context_system::instance();
        $isAdmin= has_capability('local/learnpath:manage',       $sysctx, $USER->id);
        $isMgr  = has_capability('local/learnpath:viewdashboard',$sysctx, $USER->id) && !$isAdmin;

        $hex = ltrim($brand,'#');
        $r   = hexdec(substr($hex,0,2));
        $g   = hexdec(substr($hex,2,2));
        $b   = hexdec(substr($hex,4,2));

        // Collect paths
        $groups  = $DB->get_records('local_learnpath_groups',null,'name ASC');
        $myGrps  = [];
        foreach ($groups as $grp) {
            if ($isAdmin||$isMgr) {
                if ($DB->record_exists('local_learnpath_managers',['groupid'=>$grp->id,'userid'=>$USER->id])) {
                    $myGrps[$grp->id]=$grp; continue;
                }
            }
            foreach (\local_learnpath\data\helper::get_group_courses($grp->id) as $crs) {
                $cx=context_course::instance($crs->id,IGNORE_MISSING);
                if ($cx&&is_enrolled($cx,$USER->id)) { $myGrps[$grp->id]=$grp; break; }
            }
        }

        $vis = get_config('local_learnpath','block_visibility')?:'enrolled';
        if ($vis==='enrolled'&&empty($myGrps)&&!$isAdmin&&!$isMgr) return $this->content;

        // Progress data
        $totalDone=0; $totalAll=0; $overdueCt=0; $items=[];
        foreach ($myGrps as $gid=>$grp) {
            $rows  = \local_learnpath\data\helper::get_progress_detail($gid,$USER->id);
            $mine  = array_filter($rows,fn($r)=>(int)$r->userid===(int)$USER->id);
            $done  = count(array_filter($mine,fn($r)=>$r->status==='complete'));
            $total = count($mine);
            $pct   = $total>0?(int)round($done/$total*100):0;
            if ($done>=$total&&$total>0) $pct=100;
            $over  = $grp->deadline&&$grp->deadline<time()&&$pct<100;
            $days  = $grp->deadline?(int)ceil(($grp->deadline-time())/86400):null;
            $mgr   = $DB->record_exists('local_learnpath_managers',['groupid'=>$gid,'userid'=>$USER->id]);
            $totalDone+=$done; $totalAll+=$total;
            if ($over) $overdueCt++;
            $items[]=compact('gid','grp','done','total','pct','over','days','mgr');
        }
        $overall = $totalAll>0?(int)round($totalDone/$totalAll*100):0;

        $myUrl   = new moodle_url('/local/learnpath/mypath.php');
        $dashUrl = new moodle_url('/local/learnpath/index.php');
        $ovUrl   = new moodle_url('/local/learnpath/overview.php');
        $bid     = 'ltrk-'.$this->instance->id;
        $prefKey = 'ltrk_lay_'.$USER->id;
        $circ    = round(2*M_PI*26,2);
        $off     = round($circ*(1-$overall/100),2);

        // Build HTML entirely in PHP - no ob_start to avoid Moodle output quirks
        $h = '';

        // CSS
        $h .= '<style>'
            . '#'.$bid.'{font-family:system-ui,-apple-system,sans-serif;font-size:13px}'
            . '#'.$bid.' .blk-sw{display:flex;gap:3px;justify-content:flex-end;margin-bottom:7px}'
            . '#'.$bid.' .blk-sw button{background:#f3f4f6;border:1.5px solid #e5e7eb;border-radius:6px;'
            .   'width:26px;height:26px;cursor:pointer;color:#9ca3af;font-size:.9rem;padding:0;line-height:1;transition:all .15s}'
            . '#'.$bid.' .blk-sw button.on{background:'.$brand.';border-color:'.$brand.';color:#fff}'
            // Header card
            . '#'.$bid.' .blk-head{background:linear-gradient(135deg,#0f172a,'.$brand.');border-radius:12px;'
            .   'padding:14px 16px;color:#fff;margin-bottom:9px;display:flex;justify-content:space-between;align-items:center}'
            . '#'.$bid.' .blk-head-brand{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;opacity:.7;margin-bottom:3px}'
            . '#'.$bid.' .blk-head-title{font-size:.94rem;font-weight:800}'
            // Path card
            . '#'.$bid.' .blk-path{background:#fff;border:1px solid #e8eaed;border-radius:11px;'
            .   'margin-bottom:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07);transition:box-shadow .2s}'
            . '#'.$bid.' .blk-path:hover{box-shadow:0 4px 16px rgba(0,0,0,.11)}'
            . '#'.$bid.' .blk-path-top{padding:11px 13px 0;display:flex;justify-content:space-between;align-items:flex-start;gap:6px}'
            . '#'.$bid.' .blk-pname{font-size:.84rem;font-weight:700;color:#111827;text-decoration:none;flex:1;line-height:1.3}'
            . '#'.$bid.' .blk-pname:hover{color:'.$brand.'}'
            . '#'.$bid.' .blk-ppct{font-size:.9rem;font-weight:900;flex-shrink:0}'
            . '#'.$bid.' .blk-bar{height:5px;background:#eef0f3;border-radius:100px;overflow:hidden;margin:7px 13px 0}'
            . '#'.$bid.' .blk-fill{height:100%;border-radius:100px;transition:width .5s}'
            . '#'.$bid.' .blk-pmeta{padding:5px 13px 9px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:3px}'
            . '#'.$bid.' .blk-meta-l{font-size:.67rem;color:#9ca3af}'
            // Badge
            . '#'.$bid.' .bb{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:100px;white-space:nowrap}'
            . '#'.$bid.' .bb-over{background:#fee2e2;color:#be123c}'
            . '#'.$bid.' .bb-warn{background:#fef3c7;color:#92400e}'
            . '#'.$bid.' .bb-done{background:#d1fae5;color:#065f46}'
            . '#'.$bid.' .bb-mgr{background:#ede9fe;color:#5b21b6}'
            . '#'.$bid.' .bb-np{background:#f3f4f6;color:#6b7280}'
            // Summary strip
            . '#'.$bid.' .blk-strip{background:rgba('.$r.','.$g.','.$b.',.07);border:1px solid rgba('.$r.','.$g.','.$b.',.12);'
            .   'border-radius:9px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;margin:6px 0}'
            . '#'.$bid.' .blk-strip-t{font-size:.75rem;font-weight:600;color:#374151}'
            // Buttons
            . '#'.$bid.' .blk-btns{display:flex;flex-direction:column;gap:6px;margin-top:8px}'
            . '#'.$bid.' .blk-bp{display:block;text-align:center;background:'.$brand.';color:#fff!important;'
            .   'font-size:.8rem;font-weight:700;padding:9px;border-radius:9px;text-decoration:none!important;transition:opacity .15s}'
            . '#'.$bid.' .blk-bp:hover{opacity:.87}'
            . '#'.$bid.' .blk-bs{display:block;text-align:center;background:#f3f4f6;color:#374151!important;'
            .   'font-size:.76rem;font-weight:700;padding:7px;border-radius:9px;text-decoration:none!important}'
            . '#'.$bid.' .blk-bs:hover{background:#e5e7eb}'
            . '#'.$bid.' .blk-mgr-l{font-size:.68rem;font-weight:700;color:'.$brand.'!important;text-decoration:none;float:right}'
            // List layout
            . '#'.$bid.' .lv-list .blk-lrow{display:flex;align-items:center;gap:7px;padding:6px 0;border-bottom:1px solid #f3f4f6}'
            . '#'.$bid.' .lv-list .blk-lname{font-size:.8rem;font-weight:600;color:#111827;text-decoration:none;'
            .   'min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '#'.$bid.' .lv-list .blk-lname:hover{color:'.$brand.'}'
            . '#'.$bid.' .lv-list .blk-bar{margin:0 6px;flex:1;height:5px}'
            // Minimal
            . '#'.$bid.' .lv-min{text-align:center;padding:8px 4px}'
            . '#'.$bid.' .blk-empty{text-align:center;padding:18px;color:#9ca3af;font-size:.8rem}'
            . '</style>';

        // Layout switcher
        $h .= '<div id="'.$bid.'">';
        $h .= '<div class="blk-sw">';
        $h .= '<button title="Cards"   onclick="ltrkLay(\''.$bid.'\',\'cards\')">&#9635;</button>';
        $h .= '<button title="List"    onclick="ltrkLay(\''.$bid.'\',\'list\')">&#9776;</button>';
        $h .= '<button title="Minimal" onclick="ltrkLay(\''.$bid.'\',\'min\')">&#8942;</button>';
        $h .= '</div>';

        // Header (always visible)
        $h .= '<div class="blk-head">';
        $h .= '<div>';
        $h .= '<div class="blk-head-brand">&#128218; '.htmlspecialchars($bname).'</div>';
        $h .= '<div class="blk-head-title">My Learning Paths</div>';
        if ($overdueCt>0) $h .= '<div style="margin-top:4px"><span class="bb bb-over">&#9888; '.$overdueCt.' overdue</span></div>';
        $h .= '</div>';
        // SVG ring
        $h .= '<svg width="60" height="60" viewBox="0 0 60 60" style="flex-shrink:0">';
        $h .= '<circle cx="30" cy="30" r="26" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="5"/>';
        $h .= '<circle cx="30" cy="30" r="26" fill="none" stroke="#fff" stroke-width="5"';
        $h .= ' stroke-dasharray="'.$circ.'" stroke-dashoffset="'.$off.'"';
        $h .= ' stroke-linecap="round" transform="rotate(-90 30 30)"/>';
        $h .= '<text x="30" y="25" text-anchor="middle" font-size="11" font-weight="800" fill="#fff">'.$overall.'%</text>';
        $h .= '<text x="30" y="35" text-anchor="middle" font-size="7" fill="rgba(255,255,255,.75)">overall</text>';
        $h .= '</svg>';
        $h .= '</div>';

        if (empty($myGrps)) {
            $h .= '<div class="blk-empty"><div style="font-size:1.6rem;margin-bottom:6px">&#128218;</div>No learning paths assigned.</div>';
        } else {
            // Path cards for card + list layouts
            $cardsHtml = ''; $listHtml = '';
            foreach ($items as $pi) {
                $clr = $pi['pct']===100?'#10b981':($pi['pct']>0?'#f59e0b':'#d1d5db');
                $pu  = new moodle_url('/local/learnpath/mypath.php',['groupid'=>$pi['gid']]);
                $du  = new moodle_url('/local/learnpath/index.php', ['groupid'=>$pi['gid']]);
                $nm  = format_string($pi['grp']->name);

                // Build badge row
                $badges = '';
                if ($pi['pct']===100)
                    $badges .= '<span class="bb bb-done">&#10003; Done</span>';
                elseif ($pi['pct']===0)
                    $badges .= '<span class="bb bb-np">Not started</span>';
                elseif ($pi['over'])
                    $badges .= '<span class="bb bb-over">&#9888; Overdue</span>';
                elseif ($pi['days']!==null&&$pi['days']<=7&&$pi['days']>0)
                    $badges .= '<span class="bb bb-warn">&#9201; '.$pi['days'].'d left</span>';
                elseif ($pi['days']!==null&&$pi['days']>0)
                    $badges .= '<span class="bb" style="background:#f0fdf4;color:#15803d">&#128197; '.$pi['days'].'d</span>';
                if ($pi['mgr']&&($isMgr||$isAdmin))
                    $badges .= ' <span class="bb bb-mgr">Mgr</span>';

                // Card layout item
                $cardsHtml .= '<div class="blk-path">';
                $cardsHtml .= '<div class="blk-path-top">';
                $cardsHtml .= '<a href="'.$pu.'" class="blk-pname">'.$nm.'</a>';
                $cardsHtml .= '<span class="blk-ppct" style="color:'.$clr.'">'.$pi['pct'].'%</span>';
                $cardsHtml .= '</div>';
                $cardsHtml .= '<div class="blk-bar"><div class="blk-fill" style="width:'.$pi['pct'].'%;background:'.$clr.'"></div></div>';
                $cardsHtml .= '<div class="blk-pmeta">';
                $cardsHtml .= '<span class="blk-meta-l">'.$pi['done'].'/'.$pi['total'].' courses</span>';
                $cardsHtml .= '<div style="display:flex;gap:3px;align-items:center;flex-wrap:wrap">'.$badges.'</div>';
                $cardsHtml .= '</div>';
                if ($pi['mgr']&&($isMgr||$isAdmin)) {
                    $cardsHtml .= '<div style="padding:0 13px 9px;text-align:right">';
                    $cardsHtml .= '<a href="'.$du.'" class="blk-mgr-l">&#128202; Reports &#8594;</a>';
                    $cardsHtml .= '</div>';
                }
                $cardsHtml .= '</div>';

                // List layout item
                $listHtml .= '<div class="blk-lrow">';
                $listHtml .= '<a href="'.$pu.'" class="blk-lname">'.$nm.'</a>';
                $listHtml .= '<div class="blk-bar"><div class="blk-fill" style="width:'.$pi['pct'].'%;background:'.$clr.'"></div></div>';
                $listHtml .= '<span style="font-size:.76rem;font-weight:800;color:'.$clr.';min-width:30px;text-align:right">'.$pi['pct'].'%</span>';
                $listHtml .= '</div>';
            }

            // Summary strip
            $strip = '<div class="blk-strip">';
            $strip .= '<span class="blk-strip-t">'.$totalDone.'/'.$totalAll.' courses</span>';
            if ($overdueCt>0) $strip .= '<span class="bb bb-over">&#9888; '.$overdueCt.' overdue</span>';
            elseif ($overall===100) $strip .= '<span class="bb bb-done">&#127891; All done!</span>';
            $strip .= '</div>';

            // Buttons
            $profUrl = new moodle_url('/local/learnpath/myprofile.php');
            $btns  = '<div class="blk-btns">';
            $btns .= '<a href="'.$myUrl.'" class="blk-bp">Continue Learning &#8594;</a>';
            $btns .= '<a href="'.$profUrl.'" class="blk-bs">&#128100; My Profile</a>';
            if ($isMgr||$isAdmin) $btns .= '<a href="'.$dashUrl.'" class="blk-bs">&#128202; Dashboard</a>';
            if ($isAdmin)         $btns .= '<a href="'.$ovUrl.'"  class="blk-bs">&#128225; Overview</a>';
            $btns .= '</div>';

            // Cards layout (default)
            $h .= '<div class="ltrk-lv lv-cards">'.$cardsHtml.$strip.$btns.'</div>';

            // List layout
            $listHead = '<div style="font-size:.72rem;font-weight:700;color:'.$brand.';text-transform:uppercase;'
                       .'letter-spacing:.5px;padding-bottom:5px;border-bottom:2px solid '.$brand.';margin-bottom:5px">'
                       .'Paths &mdash; '.$overall.'% overall</div>';
            $h .= '<div class="ltrk-lv lv-list" style="display:none">'.$listHead.$listHtml.$strip.$btns.'</div>';

            // Minimal layout
            $h .= '<div class="ltrk-lv lv-min" style="display:none">';
            $h .= '<div style="font-size:2.6rem;font-weight:900;color:'.$brand.';line-height:1">'.$overall.'%</div>';
            $h .= '<div style="font-size:.68rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin:3px 0 8px">Overall Progress</div>';
            $h .= '<div style="font-size:.76rem;color:#374151;margin-bottom:4px">'.$totalDone.'/'.$totalAll.' courses &middot; '.count($myGrps).' path(s)</div>';
            if ($overdueCt>0) $h .= '<div style="font-size:.72rem;font-weight:700;color:#be123c;margin-bottom:6px">&#9888; '.$overdueCt.' overdue</div>';
            $h .= $btns.'</div>';
        }

        $h .= '</div><!-- /blk -->';

        // Layout switcher JS
        $h .= '<script>(function(){'
            . 'var K="'.$prefKey.'",B="'.$bid.'",Ls=["cards","list","min"];'
            . 'window.ltrkLay=function(bid,lay){'
            .   'var bl=document.getElementById(bid);if(!bl)return;'
            .   'bl.querySelectorAll(".ltrk-lv").forEach(function(el){'
            .     'el.style.display=el.classList.contains("lv-"+lay)?"":"none";});'
            .   'bl.querySelectorAll(".blk-sw button").forEach(function(btn,i){'
            .     'btn.classList.toggle("on",Ls[i]===lay);});'
            .   'try{localStorage.setItem(K,lay);}catch(e){}};'
            . 'document.addEventListener("DOMContentLoaded",function(){'
            .   'var s="cards";try{s=localStorage.getItem(K)||"cards";}catch(e){}'
            .   'ltrkLay(B,s);});'
            . '})();</script>';

        $this->content->text = $h;
        return $this->content;
    }
}
