<?php
defined('MOODLE_INTERNAL') || die();

/**
 * LearnTrack lib.php
 * Navigation hooks and login deadline popup.
 */

// ── NAVIGATION ────────────────────────────────────────────────────────────────
function local_learnpath_extend_navigation(global_navigation $nav): void {
    global $USER, $DB;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $ctx     = context_system::instance();
    $isadmin = has_capability('local/learnpath:manage',        $ctx);
    $canview = has_capability('local/learnpath:viewdashboard', $ctx);

    if ($isadmin || $canview) {
        $root = $nav->add(
            'LearnTrack',
            new moodle_url('/local/learnpath/overview.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'learntrack',
            new pix_icon('i/report', '')
        );
        $root->showinflatnavigation = true;
        $root->add('Overview',        new moodle_url('/local/learnpath/overview.php'),       navigation_node::TYPE_CUSTOM);
        $root->add('Dashboard',       new moodle_url('/local/learnpath/index.php'),          navigation_node::TYPE_CUSTOM);
        $root->add('Course Insights', new moodle_url('/local/learnpath/courseinsights.php'), navigation_node::TYPE_CUSTOM);
        $root->add('Leaderboard',     new moodle_url('/local/learnpath/leaderboard.php'),    navigation_node::TYPE_CUSTOM);
        if ($isadmin) {
            $root->add('Manage Paths', new moodle_url('/local/learnpath/manage.php'),   navigation_node::TYPE_CUSTOM);
            $root->add('Branding',     new moodle_url('/local/learnpath/branding.php'), navigation_node::TYPE_CUSTOM);
        }
        return;
    }

    // Learner nav
    $visibility = get_config('local_learnpath', 'block_visibility') ?: 'enrolled';
    if ($visibility === 'enrolled') {
        $has_path = $DB->record_exists_sql(
            "SELECT 1 FROM {local_learnpath_group_courses} lgc
             JOIN {enrol} e ON e.courseid = lgc.courseid
             JOIN {user_enrolments} ue ON ue.enrolid = e.id
             WHERE ue.userid = :uid",
            ['uid' => $USER->id]
        );
        if (!$has_path) {
            return;
        }
    }

    $node = $nav->add(
        '📚 My Learning Paths',
        new moodle_url('/local/learnpath/mypath.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'learntrack_learner',
        new pix_icon('i/course', '')
    );
    $node->showinflatnavigation = true;
}

// ── LOGIN DEADLINE POPUP ──────────────────────────────────────────────────────
/**
 * Inject deadline popup HTML+JS after every login for learners.
 * Controlled by admin settings: popup_enabled, popup_trigger, popup_days.
 */
function local_learnpath_after_require_login(): void {
    global $USER, $DB, $PAGE;

    // Only for logged-in, non-guest, non-admin users
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $ctx = context_system::instance();
    if (has_capability('local/learnpath:manage', $ctx) || has_capability('local/learnpath:viewdashboard', $ctx)) {
        return; // Admins and managers don't get the popup
    }

    // Check admin setting
    if (!get_config('local_learnpath', 'popup_enabled')) {
        return;
    }

    // Find the most urgent (nearest) deadline path this user has
    $all_groups = $DB->get_records('local_learnpath_groups', null, 'deadline ASC');
    $urgent     = null;

    foreach ($all_groups as $g) {
        if (!$g->deadline || $g->deadline < time()) {
            continue; // Skip paths with no deadline or already overdue
        }

        // Check if this user is enrolled in at least one course in this path
        $courses = $DB->get_records_sql(
            "SELECT lgc.courseid FROM {local_learnpath_group_courses} lgc WHERE lgc.groupid = :gid",
            ['gid' => $g->id]
        );
        if (empty($courses)) {
            continue;
        }

        $enrolled_in_path = false;
        foreach ($courses as $c) {
            $course_ctx = context_course::instance($c->courseid, IGNORE_MISSING);
            if ($course_ctx && is_enrolled($course_ctx, $USER->id)) {
                $enrolled_in_path = true;
                break;
            }
        }
        if (!$enrolled_in_path) {
            continue;
        }

        // Apply trigger threshold
        $trigger = get_config('local_learnpath', 'popup_trigger') ?: 'always';
        if ($trigger === 'threshold') {
            $popup_days = (int)(get_config('local_learnpath', 'popup_days') ?: 30);
            $days_left  = (int)ceil(($g->deadline - time()) / 86400);
            if ($days_left > $popup_days) {
                continue; // Too far away — don't show
            }
        }

        $urgent = $g;
        break; // Take the nearest upcoming deadline
    }

    if (!$urgent) {
        return;
    }

    // Check progress — don't show if already 100%
    // Quick cache check (no heavy calculation on every page load)
    $cache = $DB->get_record('local_learnpath_progress_cache', ['groupid' => $urgent->id, 'userid' => $USER->id]);
    if ($cache && (int)$cache->overall_progress >= 100) {
        return;
    }

    // Build popup — shown once per session via sessionStorage
    $brand      = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
    $days_left  = max(0, (int)ceil(($urgent->deadline - time()) / 86400));
    $hours_left = max(0, (int)ceil(($urgent->deadline - time()) / 3600));
    $is_overdue = $urgent->deadline < time();
    $mypath_url = (new moodle_url('/local/learnpath/mypath.php', ['groupid' => $urgent->id]))->out(false);
    $path_name  = format_string($urgent->name);
    $session_key = 'lt_popup_shown_' . $urgent->id . '_' . date('Ymd');

    $deadline_str = $is_overdue
        ? '⚠️ Overdue since ' . userdate($urgent->deadline, get_string('strftimedatefullshort'))
        : ($days_left <= 1
            ? ($hours_left . ' hour' . ($hours_left !== 1 ? 's' : '') . ' remaining')
            : ($days_left . ' day' . ($days_left !== 1 ? 's' : '') . ' remaining'));

    $urgency_color = $is_overdue ? '#dc2626' : ($days_left <= 3 ? '#dc2626' : ($days_left <= 7 ? '#d97706' : '#059669'));

    $html = '
<div id="lt-deadline-overlay" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);align-items:center;justify-content:center">
  <div id="lt-deadline-popup" style="background:#fff;border-radius:18px;padding:0;max-width:420px;width:92%;box-shadow:0 24px 60px rgba(0,0,0,.25);overflow:hidden;font-family:system-ui,sans-serif;animation:lt-pop-in .3s ease">
    <div style="background:linear-gradient(135deg,#0f172a,' . $brand . ');padding:22px 24px;color:#fff;text-align:center">
      <div style="font-size:2rem;margin-bottom:8px">⏳</div>
      <h2 style="font-size:1.1rem;font-weight:800;margin:0 0 4px;color:#fff">Learning Deadline Reminder</h2>
      <p style="font-size:.82rem;color:rgba(255,255,255,.75);margin:0">' . s($path_name) . '</p>
    </div>
    <div style="padding:24px;text-align:center">
      <div style="font-size:2.4rem;font-weight:800;color:' . $urgency_color . ';line-height:1;margin-bottom:4px">' . s($deadline_str) . '</div>
      <p style="font-size:.82rem;color:#6b7280;margin:0 0 20px">Deadline: ' . userdate($urgent->deadline, get_string('strftimedatefullshort')) . '</p>
      <a href="' . $mypath_url . '" style="display:inline-block;background:' . $brand . ';color:#fff;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;margin-bottom:10px">
        📚 Go to My Learning Path →
      </a>
      <br>
      <button onclick="closeLTDeadlinePopup()" style="background:none;border:none;color:#9ca3af;font-size:.8rem;cursor:pointer;padding:6px 12px;font-family:inherit">
        Dismiss for today
      </button>
    </div>
  </div>
</div>
<style>
@keyframes lt-pop-in{from{transform:scale(.88);opacity:0}to{transform:scale(1);opacity:1}}
#lt-deadline-overlay.lt-visible{display:flex}
</style>';

    $js = "
<script>
(function(){
    var key = '" . s($session_key) . "';
    if(sessionStorage.getItem(key)){return;}
    var overlay = document.getElementById('lt-deadline-overlay');
    if(overlay){
        setTimeout(function(){ overlay.classList.add('lt-visible'); }, 800);
    }
})();
function closeLTDeadlinePopup(){
    var overlay = document.getElementById('lt-deadline-overlay');
    if(overlay){ overlay.classList.remove('lt-visible'); }
    sessionStorage.setItem('" . s($session_key) . "','1');
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeLTDeadlinePopup(); });
</script>";

    // Inject deadline popup
    $PAGE->requires->js_init_code('
        document.addEventListener("DOMContentLoaded", function(){
            var d = document.createElement("div");
            d.innerHTML = ' . json_encode($html . $js) . ';
            document.body.appendChild(d);
        });
    ');

    // Also check for new badge awards
    local_learnpath_check_new_badges();
}

// ── BADGE NOTIFICATION POPUP ─────────────────────────────────────────────────
/**
 * Check if learner has unseen badges and show a popup.
 * Called after local_learnpath_after_require_login.
 */
function local_learnpath_check_new_badges(): void {
    global $USER, $DB, $PAGE;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Only for non-admin learners
    $ctx = context_system::instance();
    if (has_capability('local/learnpath:manage', $ctx)) {
        return;
    }

    // Check tables exist
    try {
        if (!$DB->get_manager()->table_exists(new xmldb_table('local_learnpath_user_badges'))) {
            return;
        }
    } catch (\Throwable $e) {
        return;
    }

    // Find unseen badges for this user
    $unseen = $DB->get_records_sql(
        "SELECT ub.id AS award_id, b.name, b.icon, b.description, b.points_req
         FROM {local_learnpath_user_badges} ub
         JOIN {local_learnpath_badges} b ON b.id = ub.badgeid
         WHERE ub.userid = :uid AND ub.seen = 0
         ORDER BY b.points_req ASC",
        ['uid' => $USER->id]
    );

    if (empty($unseen)) {
        return;
    }

    // Mark as seen
    foreach ($unseen as $award) {
        $DB->set_field('local_learnpath_user_badges', 'seen', 1, ['id' => $award->award_id]);
    }

    // Build popup HTML for each badge
    $badges_html = '';
    foreach ($unseen as $award) {
        $badges_html .= '<div style="text-align:center;padding:10px 0">'
            . '<div style="font-size:3rem;margin-bottom:8px">' . s($award->icon) . '</div>'
            . '<div style="font-family:inherit;font-size:1rem;font-weight:800;color:#111827">' . s($award->name) . '</div>'
            . '<div style="font-family:inherit;font-size:.82rem;color:#6b7280;margin-top:4px">' . s($award->description) . '</div>'
            . '<div style="font-family:inherit;font-size:.78rem;font-weight:700;color:#f59e0b;margin-top:6px">'
            . $award->points_req . ' points milestone</div>'
            . '</div>';
    }

    $lb_url = (new moodle_url('/local/learnpath/leaderboard.php', ['tab' => 'badges']))->out(false);
    $count  = count($unseen);
    $title  = $count === 1 ? 'You earned a new badge! 🎉' : 'You earned ' . $count . ' new badges! 🎉';

    // Build HTML using heredoc — no quote escaping issues possible
    $dismiss_fn = "document.getElementById('lt-badge-overlay').remove()";
    $html  = '<div id="lt-badge-overlay" style="position:fixed;inset:0;z-index:99999;';
    $html .= 'background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center">';
    $html .= '<div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:400px;';
    $html .= 'width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);font-family:system-ui;position:relative">';
    $html .= '<button onclick="' . $dismiss_fn . '" ';
    $html .= 'style="position:absolute;top:12px;right:14px;background:none;border:none;';
    $html .= 'font-size:1.3rem;cursor:pointer;color:#9ca3af">&#x2715;</button>';
    $html .= '<h2 style="font-size:1.1rem;font-weight:800;color:#111827;margin:0 0 16px;';
    $html .= 'text-align:center">' . htmlspecialchars($title, ENT_QUOTES) . '</h2>';
    $html .= $badges_html;
    $html .= '<div style="margin-top:20px;display:flex;gap:10px;justify-content:center">';
    $html .= '<a href="' . $lb_url . '" style="background:#1e3a5f;color:#fff;padding:9px 20px;';
    $html .= 'border-radius:8px;text-decoration:none;font-weight:700;font-size:.86rem">View My Badges</a>';
    $html .= '<button onclick="' . $dismiss_fn . '" ';
    $html .= 'style="background:#f3f4f6;color:#374151;padding:9px 20px;border-radius:8px;';
    $html .= 'border:none;cursor:pointer;font-weight:700;font-size:.86rem">Dismiss</button>';
    $html .= '</div></div></div>';
    $html .= '<style>@keyframes lt-fade-in{from{opacity:0}to{opacity:1}}</style>';

    $PAGE->requires->js_init_code(
        'document.addEventListener("DOMContentLoaded",function(){'
        . 'var d=document.createElement("div");'
        . 'd.innerHTML=' . json_encode($html) . ';'
        . 'document.body.appendChild(d);'
        . '});'
    );
}


