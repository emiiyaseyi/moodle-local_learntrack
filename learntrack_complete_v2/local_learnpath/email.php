<?php
require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;
use local_learnpath\export\manager as EM;
require_login();
require_capability('local/learnpath:emailreport', context_system::instance());
$groupid = required_param('groupid', PARAM_INT);
$group   = DH::get_group($groupid);
if (!$group) throw new moodle_exception('invalidgroup', 'local_learnpath');
$PAGE->set_url(new moodle_url('/local/learnpath/email.php', ['groupid' => $groupid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Send Report');
global $OUTPUT, $USER, $DB;
$error='';
if(optional_param('send',0,PARAM_INT)&&confirm_sesskey()){
    $recipients=optional_param('recipients','',PARAM_TEXT);
    $format=optional_param('format','xlsx',PARAM_ALPHA);
    $viewmode=optional_param('viewmode','summary',PARAM_ALPHA);
    $rcptlist=array_filter(array_map('trim',explode(',',$recipients)));
    if(empty($rcptlist)){$error='Please enter at least one valid email address.';}
    else{
        $ok=EM::email_report($groupid,$rcptlist,$format,$viewmode,$USER->id);
        if($ok){
            redirect(
                new moodle_url('/local/learnpath/email.php',['groupid'=>$groupid,'sent'=>1]),
                null, null, null
            );
        } else {
            $error='One or more emails could not be sent. Check server mail settings.';
        }
    }
}
$brand=get_config('local_learnpath','brand_color')?:'#1e3a5f';
echo $OUTPUT->header();
echo '<style>:root{--lt-primary:'.$brand.';--lt-accent:'.$brand.'}</style>';
echo html_writer::link(new moodle_url('/local/learnpath/index.php',['groupid'=>$groupid]),'← Dashboard',['style'=>'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
$sent = optional_param('sent', 0, PARAM_INT);
if($sent){echo '<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:14px 18px;margin-bottom:16px;font-family:var(--lt-font);color:#065f46;display:flex;align-items:center;gap:10px"><span style="font-size:1.2rem">✅</span><div><strong>Report sent successfully!</strong><div style="font-size:.82rem;margin-top:2px">The attachment has been emailed to the recipients. Check Send History below.</div></div></div>';}
if($error){echo '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:14px 18px;margin-bottom:16px;font-family:var(--lt-font);color:#991b1b">⚠️ '.$error.'</div>';}
echo '<div class="lt-page-header"><div class="lt-header-inner"><div>';
echo '<h1 class="lt-page-title">✉️ Send Report by Email</h1>';
echo '<p class="lt-page-subtitle">'.format_string($group->name).'</p>';
echo '</div></div></div>';
echo '<div class="lt-card"><div class="lt-card-body">';
echo '<form method="post">';
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'send','value'=>1]);
echo '<div style="display:grid;gap:16px;max-width:560px">';
echo '<div><label style="font-family:var(--lt-font);font-size:.76rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Email Recipients</label>';
echo '<textarea name="recipients" rows="3" placeholder="email@example.com, another@company.com" style="width:100%;font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:9px 12px;box-sizing:border-box;resize:vertical;outline:none"></textarea>';
echo '<span style="font-size:.72rem;color:#9ca3af;font-family:var(--lt-font)">Separate multiple addresses with commas</span></div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
echo '<div><label style="font-family:var(--lt-font);font-size:.76rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Format</label>';
echo '<select name="format" class="lt-select" style="width:100%"><option value="xlsx">Excel (.xlsx)</option><option value="csv">CSV</option><option value="pdf">PDF</option></select></div>';
echo '<div><label style="font-family:var(--lt-font);font-size:.76rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">View</label>';
echo '<select name="viewmode" class="lt-select" style="width:100%"><option value="summary">Learner Summary</option><option value="detail">Per-Course Detail</option></select></div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<button type="submit" class="lt-btn lt-btn-primary">✉️ Send Report Now</button>';
echo html_writer::link(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid]),'📅 Set up a schedule instead',['style'=>'font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none;align-self:center']);
echo '</div></div></form></div></div>';
// Email send history
echo '<div class="lt-card" style="margin-top:20px">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">📋 Send History</h3>';
echo '<span style="font-size:.74rem;color:#9ca3af;font-family:var(--lt-font)">Last 20 sends for this path</span></div>';
echo '<div class="lt-card-body" style="padding:0">';

$log_rows = $DB->get_records_sql(
    'SELECT el.*, u.firstname, u.lastname
     FROM {local_learnpath_email_log} el
     LEFT JOIN {user} u ON u.id = el.senderid
     WHERE el.groupid = :gid
     ORDER BY el.timesent DESC
     LIMIT 20',
    ['gid' => $groupid]
);

if (empty($log_rows)) {
    echo '<p style="padding:16px 18px;font-family:var(--lt-font);font-size:.84rem;color:#9ca3af">No emails sent yet for this path.</p>';
} else {
    echo '<div style="overflow-x:auto">';
    echo '<table style="width:100%;border-collapse:collapse;font-family:var(--lt-font);font-size:.82rem">';
    echo '<thead><tr style="background:#0f172a">';
    foreach (['Date & Time','Sent By','Recipients','Format','View','Records'] as $h) {
        echo '<th style="padding:9px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:rgba(255,255,255,.8);white-space:nowrap">' . $h . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($log_rows as $lr) {
        $fmt_badge_color = $lr->format === 'pdf' ? '#ede9fe;color:#5b21b6' : ($lr->format === 'csv' ? '#d1fae5;color:#065f46' : '#dbeafe;color:#1e40af');
        echo '<tr style="border-bottom:1px solid #f3f4f6">';
        echo '<td style="padding:9px 12px;white-space:nowrap;color:#374151">' . userdate($lr->timesent, get_string('strftimedatetimeshort')) . '</td>';
        echo '<td style="padding:9px 12px;font-weight:600;color:#111827">' . format_string($lr->firstname . ' ' . $lr->lastname) . '</td>';
        echo '<td style="padding:9px 12px;color:#6b7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . s($lr->recipients) . '">' . s($lr->recipients) . '</td>';
        echo '<td style="padding:9px 12px"><span style="background:#' . $fmt_badge_color . ';font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">' . strtoupper($lr->format) . '</span></td>';
        echo '<td style="padding:9px 12px;color:#6b7280">' . ucfirst($lr->viewmode) . '</td>';
        echo '<td style="padding:9px 12px;font-weight:700;color:#374151">' . $lr->recordcount . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';

echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>' . html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank']) . '<span class="lt-sep">·</span><span>LearnTrack v2.0.0</span></div>';
echo $OUTPUT->footer();
