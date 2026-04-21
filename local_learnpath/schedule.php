<?php
require_once(__DIR__ . '/../../config.php');
use local_learnpath\data\helper as DH;
use local_learnpath\form\schedule_form;
use local_learnpath\task\send_scheduled_reports;

require_login();
require_capability('local/learnpath:emailreport', context_system::instance());

$groupid    = optional_param('groupid',    0,       PARAM_INT);
$action     = optional_param('action',     'list',  PARAM_ALPHA);
$scheduleid = optional_param('scheduleid', 0,       PARAM_INT);

$group = $groupid > 0 ? DH::get_group($groupid) : null;

$PAGE->set_url(new moodle_url('/local/learnpath/schedule.php', ['groupid'=>$groupid,'action'=>$action]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title('LearnTrack — Schedules');

global $DB, $OUTPUT, $USER;
$brand = get_config('local_learnpath','brand_color') ?: '#1e3a5f';

if ($action === 'delete' && $scheduleid && confirm_sesskey()) {
    $DB->delete_records('local_learnpath_schedules', ['id'=>$scheduleid,'groupid'=>$groupid]);
    redirect(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid]),'Schedule deleted.',null,\core\output\notification::NOTIFY_SUCCESS);
}
if ($action === 'toggle' && $scheduleid && confirm_sesskey()) {
    $s = $DB->get_record('local_learnpath_schedules', ['id'=>$scheduleid,'groupid'=>$groupid]);
    if ($s) { $DB->update_record('local_learnpath_schedules',(object)['id'=>$s->id,'enabled'=>$s->enabled?0:1]); }
    redirect(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid]));
}
if ($action === 'add' || ($action === 'edit' && $scheduleid)) {
    $customdata = ['groupid'=>$groupid,'id'=>0];
    if ($scheduleid) { $s=$DB->get_record('local_learnpath_schedules',['id'=>$scheduleid,'groupid'=>$groupid],'*',MUST_EXIST); $customdata=array_merge($customdata,(array)$s); }
    $form = new schedule_form($PAGE->url, $customdata);
    if ($form->is_cancelled()) { redirect(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid])); }
    if ($data = $form->get_data()) {
        $rec=(object)['groupid'=>$groupid,'recipients'=>trim($data->recipients),'frequency'=>$data->frequency,'format'=>$data->format,'viewmode'=>$data->viewmode??'summary','enabled'=>!empty($data->enabled)?1:0];
        if (!empty($data->id)) { $rec->id=$data->id; $DB->update_record('local_learnpath_schedules',$rec); }
        else { $rec->createdby=$USER->id;$rec->timecreated=time();$rec->nextrun=send_scheduled_reports::calc_next_run($data->frequency,time()); $DB->insert_record('local_learnpath_schedules',$rec); }
        redirect(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid]),'Schedule saved.',null,\core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo '<style>:root{--lt-primary:'.$brand.';--lt-accent:'.$brand.'}</style>';
    echo html_writer::link(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid]),'← Schedules',['style'=>'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
    // If no group selected yet, show path selector in the form
if (!$group) {
    echo '<div class="lt-card" style="margin-bottom:16px"><div class="lt-card-body">';
    echo '<label style="font-family:var(--lt-font);font-size:.76rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Select Learning Path First</label>';
    echo '<select onchange="window.location=\'/local/learnpath/schedule.php?action=add\u0026groupid=\'+this.value" style="font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 12px;background:#f9fafb;outline:none;min-width:280px">';
    echo '<option value="0">— Choose a path —</option>';
    foreach ($DB->get_records('local_learnpath_groups', null, 'name ASC') as $sg) {
        echo '<option value="' . $sg->id . '">' . format_string($sg->name) . '</option>';
    }
    echo '</select></div></div>';
    echo $OUTPUT->footer(); exit;
}
echo '<div class="lt-page-header"><h1 class="lt-page-title">'.($scheduleid?'Edit':'New').' Schedule</h1><p class="lt-page-subtitle">'.format_string($group->name).'</p></div>';
    echo '<div class="lt-card lt-form-card">';
    $form->set_data($customdata); $form->display();
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'), '🏠 Welcome', ['style' => 'display:inline-block;margin-bottom:14px;margin-right:10px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
echo '<style>:root{--lt-primary:'.$brand.';--lt-accent:'.$brand.'}</style>';
echo html_writer::link(new moodle_url('/local/learnpath/index.php',['groupid'=>$groupid]),'← Dashboard',['style'=>'display:inline-block;margin-bottom:14px;font-family:var(--lt-font);font-size:.84rem;color:var(--lt-accent);text-decoration:none']);
// Path selector
$all_groups_s = $DB->get_records('local_learnpath_groups', null, 'name ASC');
echo '<div class="lt-page-header"><div class="lt-header-inner">';
echo '<div><h1 class="lt-page-title">📅 Scheduled Reports</h1>';
echo '<p class="lt-page-subtitle">' . ($group ? format_string($group->name) : 'Select a learning path') . '</p></div>';
if ($groupid > 0) {
    echo html_writer::link(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid,'action'=>'add']),'+ Add Schedule',['class'=>'lt-btn lt-btn-primary']);
}
echo '</div></div>';

// Path selector dropdown
echo '<div class="lt-card" style="margin-bottom:14px"><div class="lt-card-body" style="padding:12px 16px">';
echo '<label style="font-family:var(--lt-font);font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Select Learning Path</label>';
echo '<select onchange="window.location=\'/local/learnpath/schedule.php?groupid=\'+this.value" style="font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 12px;background:#f9fafb;outline:none;min-width:280px">';
echo '<option value="0">— Choose a path —</option>';
foreach ($all_groups_s as $sg) {
    $sel = $sg->id == $groupid ? ' selected' : '';
    echo '<option value="' . $sg->id . '"' . $sel . '>' . format_string($sg->name) . '</option>';
}
echo '</select></div></div>';

$schedules = $groupid > 0 ? $DB->get_records('local_learnpath_schedules',['groupid'=>$groupid]) : [];
$freqicons = ['daily'=>'⚡','weekly'=>'📆','monthly'=>'🗓️'];
$freqbg    = ['daily'=>'#fee2e2','weekly'=>'#dbeafe','monthly'=>'#d1fae5'];

if (empty($schedules)) {
    echo '<div class="lt-empty-state"><div class="lt-empty-icon">📅</div><h3 class="lt-empty-title">No Schedules Yet</h3><p class="lt-empty-desc">Set up automated reports to be sent daily, weekly, or monthly.</p>'.html_writer::link(new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid,'action'=>'add']),'+ Add Schedule',['class'=>'lt-btn lt-btn-primary']).'</div>';
} else {
    foreach ($schedules as $s) {
        $freq=$s->frequency??'weekly';
        $editurl=new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid,'action'=>'edit','scheduleid'=>$s->id]);
        $delurl=new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid,'action'=>'delete','scheduleid'=>$s->id,'sesskey'=>sesskey()]);
        $togurl=new moodle_url('/local/learnpath/schedule.php',['groupid'=>$groupid,'action'=>'toggle','scheduleid'=>$s->id,'sesskey'=>sesskey()]);
        $bg=$freqbg[$freq]??'#f3f4f6';
        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;margin-bottom:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;box-shadow:0 1px 3px rgba(0,0,0,.05);font-family:var(--lt-font)">';
        echo '<div style="width:42px;height:42px;border-radius:10px;background:'.$bg.';display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">'.($freqicons[$freq]??'📅').'</div>';
        echo '<div style="flex:1"><p style="font-size:.9rem;font-weight:700;color:#111827;margin:0 0 3px">'.ucfirst($freq).' · '.strtoupper($s->format).' &nbsp;'.($s->enabled?'<span style="background:#d1fae5;color:#065f46;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">Active</span>':'<span style="background:#f3f4f6;color:#9ca3af;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:100px">Paused</span>').'</p>';
        echo '<p style="font-size:.76rem;color:#6b7280;margin:0">📧 '.htmlspecialchars($s->recipients).' &nbsp;·&nbsp; Next: '.userdate($s->nextrun,get_string('strftimedatefullshort')).($s->lastrun?' &nbsp;·&nbsp; Last: '.userdate($s->lastrun,get_string('strftimedatefullshort')):'').'</p></div>';
        echo '<div style="display:flex;gap:6px">';
        echo html_writer::link($togurl,$s->enabled?'⏸ Pause':'▶ Resume',['class'=>'lt-action-btn lt-btn-view']);
        echo html_writer::link($editurl,'✏️ Edit',['class'=>'lt-action-btn lt-btn-edit']);
        echo html_writer::link($delurl,'🗑',['class'=>'lt-action-btn lt-btn-del','onclick'=>"return confirm('Delete this schedule?')"]);
        echo '</div></div>';
    }
}
echo '<div class="lt-footer"><span>© Michael Adeniran</span><span class="lt-sep">·</span>'.html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank']).'<span class="lt-sep">·</span><span>LearnTrack v2.0.0</span></div>';
echo $OUTPUT->footer();
