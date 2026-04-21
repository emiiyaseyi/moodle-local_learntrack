<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/learnpath:viewdashboard', context_system::instance());
$PAGE->set_url(new moodle_url('/local/learnpath/welcome.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Welcome');
global $DB, $OUTPUT;
$isadmin = has_capability('local/learnpath:manage', context_system::instance());
$brand   = get_config('local_learnpath', 'brand_color') ?: '#1e3a5f';
$gcount  = $DB->count_records('local_learnpath_groups');
$ccount  = $DB->count_records('local_learnpath_group_courses');
echo $OUTPUT->header();
echo '<style>:root{--lt-primary:'.$brand.';--lt-accent:'.$brand.'}</style>';
echo '<style>'
    . '.lt-welcome-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 55%,' . $brand . ' 100%);border-radius:16px;padding:44px 40px;color:#ffffff;margin-bottom:24px;position:relative;overflow:hidden}'
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
    . '.lt-dev-avatar{width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 14px}'
    . '.lt-dev-name{font-size:1.05rem;font-weight:800;color:#111827;margin:0 0 2px}'
    . '.lt-dev-role{font-size:.78rem;color:#6b7280;margin:0 0 14px}'
    . '.lt-dev-link{display:flex;align-items:center;justify-content:center;gap:8px;padding:8px 0;border-top:1px solid #f3f4f6;font-size:.82rem;color:#374151!important;text-decoration:none!important;transition:color .12s}'
    . '.lt-dev-link:hover{color:#3b82f6!important}'
    . '.lt-quicknav{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}'
    . '@media(max-width:560px){.lt-quicknav{grid-template-columns:1fr}}'
    . '.lt-quicknav-link{display:flex;align-items:center;gap:11px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;text-decoration:none!important;color:#111827!important;font-family:var(--lt-font);transition:all .14s}'
    . '.lt-quicknav-link:hover{background:#eff6ff;border-color:#3b82f6;color:#1e40af!important}'
    . '.lt-qn-icon{font-size:1.15rem;flex-shrink:0}'
    . '.lt-qn-text strong{display:block;font-size:.86rem;font-weight:700}'
    . '.lt-qn-text span{font-size:.74rem;color:#9ca3af}'
    . '.lt-qn-arrow{margin-left:auto;color:#d1d5db;font-size:.9rem}'
    . '.lt-quicknav-link:hover .lt-qn-arrow{color:#3b82f6}'
    . '</style>';
// Hero
echo '<div class="lt-welcome-hero"><div style="position:relative;z-index:1">';
echo '<div style="font-family:var(--lt-font);font-size:.78rem;font-weight:700;background:rgba(255,255,255,.15);display:inline-block;padding:4px 14px;border-radius:100px;margin-bottom:14px;color:rgba(255,255,255,.9)">🎓 Moodle Local Plugin · v2.0.0</div>';
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
foreach ([[$gcount,'Learning Paths'],[$ccount,'Courses Tracked'],['v2.0','Plugin Version'],['4.5+','Moodle Compatible']] as [$v,$l]) {
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
echo '<div class="lt-dev-link"><span>📦</span> LearnTrack v2.0.0 · GNU GPL v3 · Moodle 4.5–5.1+</div>';
echo '</div>';

echo '<div class="lt-footer">';
echo '<span>© Michael Adeniran</span><span class="lt-sep">·</span>';
echo html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank']);
echo '<span class="lt-sep">·</span><span>LearnTrack v2.0.0 · Moodle 4.5–5.1+</span>';
echo '</div>';
echo $OUTPUT->footer();
