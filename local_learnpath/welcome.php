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

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/learnpath:viewdashboard', context_system::instance());

/**
 * Status badge HTML for a {task_scheduled} row — shared between the table
 * render and the "Run Now" JSON response so both agree on what "OK" means.
 */
function local_learnpath_task_status_html(?\stdClass $row): string {
    if (!$row) {
        return '<span style="color:#be123c;font-weight:700">Not registered</span>';
    }
    if (!empty($row->disabled)) {
        return '<span style="color:#be123c;font-weight:700">Disabled</span>';
    }
    if (!empty($row->faildelay)) {
        return '<span style="color:#b45309;font-weight:700">Failing (retry delay ' . (int)$row->faildelay . 's)</span>';
    }
    return '<span style="color:#065f46;font-weight:700">OK</span>';
}
$PAGE->set_url(new moodle_url('/local/learnpath/welcome.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Welcome');
global $DB, $OUTPUT;
$isadmin      = has_capability('local/learnpath:manage', context_system::instance());
$cansiteconfig = has_capability('moodle/site:config', context_system::instance());
$brand   = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$gcount  = $DB->count_records('local_learnpath_groups');
$ccount  = $DB->count_records('local_learnpath_group_courses');

// ── Manual "Run Now" for a LearnTrack scheduled task ────────────────────────
// Site cron can be entirely unconfigured on some hosts (every task on the
// whole site shows "Never run", not just LearnTrack's) — this gives an admin
// a way to force a task to run right now and confirm the mechanism itself
// works, without waiting on server cron. This executes the REAL task, with
// real side effects (it will send whatever reminders/reports are currently
// due) — it is not a dry run. Restricted to moodle/site:config because it
// executes core Moodle scheduled-task code, not just LearnTrack data.
//
// Triggered via fetch() as a background request (see JS below) so a slow
// task doesn't freeze the page — the button shows "Running…" while the
// request is in flight and only that task's row updates when it resolves;
// nothing else on the page is blocked or reloaded.
$run_task_action = optional_param('lt_run_task', '', PARAM_RAW);
if ($run_task_action !== '') {
    header('Content-Type: application/json');

    if (!$cansiteconfig || !confirm_sesskey()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Not permitted.']);
        exit;
    }

    $allowed_tasks = [
        '\\local_learnpath\\task\\send_reminders',
        '\\local_learnpath\\task\\send_scheduled_reports',
        '\\local_learnpath\\task\\refresh_progress_cache',
    ];
    if (!in_array($run_task_action, $allowed_tasks, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown task.']);
        exit;
    }

    // Simple overlap guard so a double-click (or a real cron run landing at
    // the same moment) can't run the same task twice concurrently.
    $lockkey = 'manual_run_lock_' . md5($run_task_action);
    $lockval = (int)(get_config('local_learnpath', $lockkey) ?: 0);
    if ($lockval && (time() - $lockval) < 120) {
        echo json_encode(['ok' => false, 'message' => 'Already running (started less than 2 minutes ago) — please wait.']);
        exit;
    }
    set_config($lockkey, time(), 'local_learnpath');

    $task = \core\task\manager::get_scheduled_task($run_task_action);
    if (!$task) {
        unset_config($lockkey, 'local_learnpath');
        echo json_encode(['ok' => false, 'message' => 'Task is not registered with Moodle — try "Reset to default" under Scheduled tasks first.']);
        exit;
    }

    // A manually-triggered run can process a large overdue backlog the first
    // time — don't let the default script time limit kill it partway through.
    if (class_exists('\\core_php_time_limit')) {
        \core_php_time_limit::raise();
    } else {
        @set_time_limit(0);
    }

    $ranok = true;
    ob_start();
    try {
        $task->execute();
    } catch (\Throwable $e) {
        $ranok = false;
        echo 'ERROR: ' . $e->getMessage();
    }
    $output = trim(ob_get_clean());

    // Mirror what Moodle's real cron runner records so "Last run" / "Next
    // run" reflect this manual execution, and future automatic runs stay on
    // the correct schedule.
    $DB->set_field('task_scheduled', 'lastruntime', time(), ['classname' => $run_task_action]);
    if ($ranok) {
        $DB->set_field('task_scheduled', 'faildelay', 0, ['classname' => $run_task_action]);
    }
    $nextruntime = null;
    try {
        $next = $task->get_next_scheduled_time();
        if ($next) {
            $DB->set_field('task_scheduled', 'nextruntime', $next, ['classname' => $run_task_action]);
            $nextruntime = $next;
        }
    } catch (\Throwable $e) {
        // Non-fatal — lastruntime is already recorded.
    }
    unset_config($lockkey, 'local_learnpath');

    // Re-read the row so the status badge reflects exactly what's now in the
    // DB (faildelay reset on success, or whatever a concurrent real cron run
    // left behind) — not just what this one request assumed.
    $freshrow = $DB->get_record('task_scheduled', ['classname' => $run_task_action]);

    echo json_encode([
        'ok'          => $ranok,
        'message'     => ($ranok ? 'Ran successfully.' : 'Ran with an error.') . ' ' . ($output !== '' ? substr($output, -600) : '(no output)'),
        'lastruntime' => userdate(time(), get_string('strftimedatetimeshort')),
        'nextruntime' => $nextruntime ? userdate($nextruntime, get_string('strftimedatetimeshort')) : '—',
        'statushtml'  => local_learnpath_task_status_html($freshrow),
    ]);
    exit;
}

echo $OUTPUT->header();
echo local_learnpath_brand_css();
echo '<style>'
    . '.lt-welcome-hero{background:linear-gradient(135deg,#0f172a 0%,var(--lt-primary-dark,#162d4a) 55%,var(--lt-accent) 100%);border-radius:16px;padding:44px 40px;color:#ffffff;margin-bottom:24px;position:relative;overflow:hidden}'
    . '.lt-welcome-hero::before{content:"";position:absolute;top:-50px;right:-50px;width:220px;height:220px;background:rgba(255,255,255,.05);border-radius:50%;pointer-events:none}'
    . '.lt-welcome-hero h1{font-size:2.2rem;font-weight:800;margin:0 0 10px;letter-spacing:-.4px;font-family:var(--lt-font);color:#ffffff}'
    . '.lt-welcome-hero p{font-size:1rem;color:rgba(255,255,255,.78);margin:0 0 24px;max-width:560px;font-family:var(--lt-font);line-height:1.6}'
    . '.lt-hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:28px}'
    . '.lt-hero-btn{display:inline-flex;align-items:center;gap:7px;font-family:var(--lt-font);font-size:.9rem;font-weight:700;padding:11px 22px;border-radius:10px;text-decoration:none!important;transition:all .15s}'
    . '.lt-hero-btn-white{background:#ffffff;color:#0f172a!important;box-shadow:0 4px 14px rgba(0,0,0,.2)}'
    . '.lt-hero-btn-white:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(0,0,0,.25)}'
    . '.lt-hero-btn-glass{background:rgba(255,255,255,.14);color:#ffffff!important;border:1.5px solid rgba(255,255,255,.28)}'
    . '.lt-hero-btn-glass:hover{background:rgba(255,255,255,.22)}'
    . '.lt-hero-stats{display:flex;gap:28px;flex-wrap:wrap}'
    . '.lt-hero-stat-val{font-size:1.9rem;font-weight:800;display:block;font-family:var(--lt-font);color:#ffffff}'
    . '.lt-hero-stat-label{font-size:.7rem;color:rgba(255,255,255,.62);text-transform:uppercase;letter-spacing:.5px;font-family:var(--lt-font)}'
    . '.lt-features-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}'
    . '@media(max-width:900px){.lt-features-grid{grid-template-columns:repeat(2,1fr)}}'
    . '@media(max-width:560px){.lt-features-grid{grid-template-columns:1fr}}'
    . '.lt-feat-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:transform .14s,box-shadow .14s;font-family:var(--lt-font)}'
    . '.lt-feat-card:hover{transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.08)}'
    . '.lt-feat-icon{font-size:1.7rem;margin-bottom:8px}'
    . '.lt-feat-title{font-size:.93rem;font-weight:700;color:#111827;margin:0 0 5px}'
    . '.lt-feat-desc{font-size:.8rem;color:#6b7280;margin:0;line-height:1.5}'
    . '.lt-dev-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px 32px;box-shadow:0 1px 3px rgba(0,0,0,.05);font-family:var(--lt-font);margin-bottom:16px;text-align:center;max-width:480px;margin-left:auto;margin-right:auto}'
    . '.lt-dev-avatar{width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,var(--lt-accent));display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 14px}'
    . '.lt-dev-name{font-size:1.05rem;font-weight:800;color:#111827;margin:0 0 2px}'
    . '.lt-dev-role{font-size:.78rem;color:#6b7280;margin:0 0 14px}'
    . '.lt-dev-link{display:flex;align-items:center;justify-content:center;gap:8px;padding:8px 0;border-top:1px solid #f3f4f6;font-size:.82rem;color:#374151!important;text-decoration:none!important;transition:color .12s}'
    . '.lt-dev-link:hover{color:var(--lt-accent)!important}'
    . '.lt-quicknav{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}'
    . '@media(max-width:560px){.lt-quicknav{grid-template-columns:1fr}}'
    . '.lt-quicknav-link{display:flex;align-items:center;gap:11px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;text-decoration:none!important;color:#111827!important;font-family:var(--lt-font);transition:all .14s}'
    . '.lt-quicknav-link:hover{background:#eff6ff;border-color:var(--lt-accent);color:var(--lt-accent)!important}'
    . '.lt-qn-icon{font-size:1.15rem;flex-shrink:0}'
    . '.lt-qn-text strong{display:block;font-size:.86rem;font-weight:700}'
    . '.lt-qn-text span{font-size:.74rem;color:#9ca3af}'
    . '.lt-qn-arrow{margin-left:auto;color:#d1d5db;font-size:.9rem}'
    . '.lt-quicknav-link:hover .lt-qn-arrow{color:var(--lt-accent)}'
    . '</style>';
// Hero
echo '<div class="lt-welcome-hero"><div style="position:relative;z-index:1">';
echo '<div style="font-family:var(--lt-font);font-size:.78rem;font-weight:700;background:rgba(255,255,255,.15);display:inline-block;padding:4px 14px;border-radius:100px;margin-bottom:14px;color:rgba(255,255,255,.9)">🎓 Moodle Local Plugin · v1.0.0</div>';
echo '<h1>LearnTrack</h1>';
echo '<p>Track learner progress across multiple courses from a single dashboard. Export reports, schedule emails, and manage learning paths — all in one place.</p>';
echo '<div class="lt-hero-actions">';
echo html_writer::link(new moodle_url('/local/learnpath/index.php'),'📊 Open Dashboard',['class'=>'lt-hero-btn lt-hero-btn-white']);
if ($isadmin) {
    echo html_writer::link(new moodle_url('/local/learnpath/manage.php'),'⚙️ Manage Paths',['class'=>'lt-hero-btn lt-hero-btn-glass']);
    echo html_writer::link(new moodle_url('/local/learnpath/branding.php'),'🎨 Branding',['class'=>'lt-hero-btn lt-hero-btn-glass']);
}
echo '</div>';
echo '<div class="lt-hero-stats">';
foreach ([[$gcount,'Learning Paths'],[$ccount,'Courses Tracked'],['v1.0','Plugin Version'],['4.5+','Moodle Compatible']] as [$v,$l]) {
    echo '<div><span class="lt-hero-stat-val">'.$v.'</span><span class="lt-hero-stat-label">'.$l.'</span></div>';
}
echo '</div></div></div>';

// Features
$features = [
    ['📊','Progress Dashboard','View all learner progress across every course in a path from one screen.'],
    ['📋','Two View Modes','Switch between per-course detail and learner summary with one click.'],
    ['📤','Export Reports','Download as Excel, CSV, or PDF with summary header and filters applied.'],
    ['✉️','Email Reports','Send reports instantly or schedule daily, weekly, or monthly deliveries.'],
    ['🔍','Search & Filter','Filter by learner, course, status, date range, and user status.'],
    ['🎨','Branding Control','Customise colours, logo, font, and visible fields to match your brand.'],
    ['🔒','Role-based Access','Admin-configurable: restrict by group, cohort, or role.'],
    ['📅','Scheduled Reports','Automate recurring email reports to any recipient list.'],
    ['🌍','Multi-language','Built on Moodle language packs — ready for localisation.'],
];
echo '<div class="lt-features-grid">';
foreach ($features as [$icon,$title,$desc]) {
    echo '<div class="lt-feat-card"><div class="lt-feat-icon">'.$icon.'</div><p class="lt-feat-title">'.$title.'</p><p class="lt-feat-desc">'.$desc.'</p></div>';
}
echo '</div>';

// Cron & Delivery Health — lets an admin see at a glance whether reminders
// and reports are actually flowing, instead of needing to read cron logs.
if ($isadmin) {
    $tasks = [
        '\\local_learnpath\\task\\send_reminders'         => 'Reminders',
        '\\local_learnpath\\task\\send_scheduled_reports' => 'Scheduled reports',
        '\\local_learnpath\\task\\refresh_progress_cache' => 'Progress cache refresh',
    ];
    $now = time();
    $overdue_threshold = 2 * HOURSECS;

    $overdue_reminders = (int)$DB->count_records_select(
        'local_learnpath_reminders',
        'enabled = 1 AND nextrun IS NOT NULL AND nextrun < :cutoff',
        ['cutoff' => $now - $overdue_threshold]
    );
    $overdue_schedules = (int)$DB->count_records_select(
        'local_learnpath_schedules',
        'enabled = 1 AND nextrun < :cutoff',
        ['cutoff' => $now - $overdue_threshold]
    );

    echo '<h3 style="font-family:var(--lt-font);font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin:24px 0 10px">🩺 CRON &amp; DELIVERY HEALTH</h3>';
    echo '<div class="lt-feat-card" style="margin-bottom:22px">';
    echo '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-family:var(--lt-font);font-size:.82rem">';
    echo '<thead><tr style="text-align:left;color:#6b7280;font-size:.72rem;text-transform:uppercase;letter-spacing:.4px">'
        . '<th style="padding:6px 10px 6px 0">Task</th><th>Last run</th><th>Next run</th><th>Status</th>'
        . ($cansiteconfig ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($tasks as $classname => $label) {
        $row = $DB->get_record('task_scheduled', ['classname' => $classname]);
        $last   = ($row && $row->lastruntime) ? userdate($row->lastruntime, get_string('strftimedatetimeshort')) : 'Never run';
        $next   = ($row && $row->nextruntime) ? userdate($row->nextruntime, get_string('strftimedatetimeshort')) : '—';
        $status = local_learnpath_task_status_html($row);
        $rowid = 'lt-task-' . substr(md5($classname), 0, 10);
        echo '<tr style="border-top:1px solid #f3f4f6"><td style="padding:8px 10px 8px 0;font-weight:700">' . s($label) . '</td>'
            . '<td id="' . $rowid . '-last" style="padding:8px 10px">' . $last . '</td>'
            . '<td id="' . $rowid . '-next" style="padding:8px 10px">' . $next . '</td>'
            . '<td id="' . $rowid . '-status" style="padding:8px 10px">' . $status . '</td>';
        if ($cansiteconfig) {
            echo '<td style="padding:8px 10px">'
                . '<button type="button" id="' . $rowid . '-btn" class="lt-btn lt-btn-ghost lt-cron-run-btn" '
                . 'data-task="' . s($classname) . '" data-rowid="' . $rowid . '" '
                . 'style="font-size:.74rem;padding:5px 12px">▶ Run Now</button></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p id="lt-cron-run-msg" style="font-family:var(--lt-font);font-size:.78rem;margin:12px 0 0;display:none"></p>';
    echo '<p style="font-family:var(--lt-font);font-size:.78rem;color:#6b7280;margin:12px 0 0">';
    echo $overdue_reminders > 0
        ? '⚠️ <strong>' . $overdue_reminders . '</strong> reminder rule(s) are overdue by more than 2 hours — check that site cron is running.'
        : '✅ No reminder rules are overdue.';
    echo '<br>';
    echo $overdue_schedules > 0
        ? '⚠️ <strong>' . $overdue_schedules . '</strong> report schedule(s) (including weekly manager reports) are overdue by more than 2 hours — check that site cron is running.'
        : '✅ No report schedules are overdue.';
    echo '</p>';
    echo '<p style="font-family:var(--lt-font);font-size:.74rem;color:#9ca3af;margin:10px 0 0">If a task shows "Not registered" or an unexpected next-run time, go to '
        . html_writer::link(new moodle_url('/admin/tool/task/scheduledtasks.php'), 'Site Administration → Server → Scheduled tasks')
        . ' and use "Reset to default" on the LearnTrack tasks.';
    if ($cansiteconfig) {
        echo ' "Run Now" executes the task for real in the background — it will send whatever reminders/reports are currently due, it is not a test.';
    }
    echo '</p>';
    echo '</div>';

    if ($cansiteconfig) {
        $ajaxurl = (new moodle_url('/local/learnpath/welcome.php'))->out(false);
        $sesskey = sesskey();
        $PAGE->requires->js_init_code("
(function(){
    var busy = false;
    document.querySelectorAll('.lt-cron-run-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            if (busy) return;
            busy = true;
            var task = btn.getAttribute('data-task');
            var rowid = btn.getAttribute('data-rowid');
            var msgEl = document.getElementById('lt-cron-run-msg');
            var allBtns = document.querySelectorAll('.lt-cron-run-btn');
            allBtns.forEach(function(b){ b.disabled = true; });
            var original = btn.textContent;
            btn.textContent = '⏳ Running…';
            msgEl.style.display = 'none';

            var params = new URLSearchParams();
            params.set('lt_run_task', task);
            params.set('sesskey', " . json_encode($sesskey) . ");

            fetch(" . json_encode($ajaxurl) . ", {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params.toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                var lastEl   = document.getElementById(rowid + '-last');
                var nextEl   = document.getElementById(rowid + '-next');
                var statusEl = document.getElementById(rowid + '-status');
                if (data.ok) {
                    if (lastEl) lastEl.textContent = data.lastruntime;
                    if (nextEl) nextEl.textContent = data.nextruntime;
                }
                // Always refresh the status badge — even on a reported success,
                // a concurrent real cron run could have just marked it failing,
                // and this re-reads the DB rather than assuming.
                if (statusEl && data.statushtml) { statusEl.innerHTML = data.statushtml; }
                msgEl.style.display = 'block';
                msgEl.style.color = data.ok ? '#065f46' : '#be123c';
                msgEl.textContent = (data.ok ? '✅ ' : '⚠️ ') + data.message;
            })
            .catch(function(err){
                msgEl.style.display = 'block';
                msgEl.style.color = '#be123c';
                msgEl.textContent = '⚠️ Request failed: ' + err;
            })
            .finally(function(){
                busy = false;
                allBtns.forEach(function(b){ b.disabled = false; });
                btn.textContent = original;
            });
        });
    });
})();
");
    }
}

// Quick nav — BELOW features, 2-column grid
$navlinks = [
    ['/local/learnpath/index.php',      '📊','Dashboard',      'View learner progress'],
    ['/local/learnpath/overview.php',   '📡','Overview',       'Site-wide analytics'],
    ['/local/learnpath/manage.php',     '⚙️','Manage Paths',  'Create & edit learning paths'],
    ['/local/learnpath/branding.php',   '🎨','Branding',       'Customise look & feel'],
    ['/local/learnpath/leaderboard.php','🏆','Leaderboard',    'Rank learners by progress'],
    ['/local/learnpath/courseinsights.php','📈','Course Insights','Individual course analytics'],
    ['/admin/settings.php?section=local_learnpath','🔧','Settings','Plugin configuration'],
    ['/local/learnpath/certificates.php','🎓','Certificates','View & verify issued certificates'],
    ['/local/learnpath/manage.php?debug=1','🩺','Diagnostics','Check DB tables & plugin health'],
];
echo '<h3 style="font-family:var(--lt-font);font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin:0 0 10px">QUICK NAVIGATION</h3>';
echo '<div class="lt-quicknav">';
foreach ($navlinks as [$url,$icon,$label,$sub]) {
    if (!$isadmin && in_array($label,['Manage Paths','Branding','Settings','Diagnostics'])) { continue; }
    echo html_writer::link(new moodle_url($url),
        '<span class="lt-qn-icon">'.$icon.'</span><span class="lt-qn-text"><strong>'.$label.'</strong><span>'.$sub.'</span></span><span class="lt-qn-arrow">→</span>',
        ['class'=>'lt-quicknav-link']);
}
echo '</div>';

// Developer card
echo '<div class="lt-dev-card">';
echo '<div class="lt-dev-avatar">👨🏾‍💻</div>';
echo '<p class="lt-dev-name">Michael Adeniran</p>';
echo '<p class="lt-dev-role">Plugin Developer · Nigeria 🇳🇬</p>';
echo html_writer::link('https://www.linkedin.com/in/michaeladeniran','<span>💼</span> linkedin.com/in/michaeladeniran',['class'=>'lt-dev-link','target'=>'_blank']);
echo html_writer::link('mailto:michaeladeniransnr@gmail.com','<span>✉️</span> michaeladeniransnr@gmail.com',['class'=>'lt-dev-link']);
echo '<div class="lt-dev-link"><span>📦</span> LearnTrack v1.0.0 · GNU GPL v3 · Moodle 4.5–5.1+</div>';
echo '</div>';

echo '<div class="lt-footer">';
echo '<span>© Michael Adeniran</span><span class="lt-sep">·</span>';
echo html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank']);
echo '<span class="lt-sep">·</span><span>LearnTrack v1.0.0 · Moodle 4.5–5.1+</span>';
echo '</div>';
echo $OUTPUT->footer();
