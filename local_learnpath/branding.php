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
 * LearnTrack Branding Settings
 * Pure PHP echo - no ob_start, no ?> switching - safe with Moodle output buffers.
 */
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/learnpath:manage', context_system::instance());
$PAGE->set_url(new moodle_url('/local/learnpath/branding.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('page_title_branding', 'local_learnpath'));
global $OUTPUT, $DB, $CFG;

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $fields = ['brand_name'=>PARAM_TEXT,'brand_color'=>PARAM_TEXT,'brand_font'=>PARAM_TEXT,
        'brand_font_size'=>PARAM_INT,'email_sender_name'=>PARAM_TEXT,'inactive_days'=>PARAM_INT,
        'show_grade'=>PARAM_INT,'show_activities'=>PARAM_INT,'show_firstaccess'=>PARAM_INT,
        'show_lastaccess'=>PARAM_INT,'show_status'=>PARAM_INT,
        'high_contrast'=>PARAM_INT,'large_text'=>PARAM_INT,'reduce_motion'=>PARAM_INT,
        'cert_bg_color'=>PARAM_TEXT,'cert_border_color'=>PARAM_TEXT,'cert_border_style'=>PARAM_TEXT,
        'cert_title_font'=>PARAM_TEXT,'cert_body_font'=>PARAM_TEXT,'cert_org_name'=>PARAM_TEXT,
        'cert_signatory_title'=>PARAM_TEXT,'cert_footer_text'=>PARAM_TEXT,
        'cert_show_logo'=>PARAM_INT,'cert_show_signature'=>PARAM_INT,
        'cert_show_date'=>PARAM_INT,'cert_show_ref'=>PARAM_INT,'cert_logo_path'=>PARAM_TEXT];
    foreach ($fields as $k => $type) {
        $val = optional_param($k, '', $type);
        if ($type === PARAM_INT && !isset($_POST[$k])) { $val = 0; }
        set_config($k, $val, 'local_learnpath');
    }
    $saved = true;
}

function local_learnpath_branding_cfg(string $k, $d = '') {
    $v = get_config('local_learnpath', $k);
    return ($v !== false && $v !== null && $v !== '') ? $v : $d;
}

function local_learnpath_cert_card(string $title, string $body): void {
    echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px;overflow:hidden">';
    echo '<div style="padding:12px 18px;border-bottom:1px solid #f3f4f6;background:#f8fafc;font-family:var(--lt-font);font-size:.84rem;font-weight:700;color:#374151">' . $title . '</div>';
    echo '<div style="padding:18px 20px">' . $body . '</div>';
    echo '</div>';
}

function local_learnpath_text_field(string $name, string $label, string $val, string $hint = ''): string {
    return '<div style="margin-bottom:13px;font-family:var(--lt-font)">'
        . '<label style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">' . htmlspecialchars($label) . '</label>'
        . '<input type="text" name="' . htmlspecialchars($name) . '" value="' . s($val) . '"'
        . ' style="font-family:var(--lt-font);font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;'
        . 'padding:8px 12px;width:100%;max-width:480px;box-sizing:border-box;outline:none">'
        . ($hint ? '<div style="font-size:.72rem;color:#9ca3af;margin-top:4px">' . htmlspecialchars($hint) . '</div>' : '')
        . '</div>';
}

function local_learnpath_toggle_field(string $name, string $label, string $hint, int $on): string {
    $bg = $on ? '#3b82f6' : '#d1d5db';
    $lx = $on ? '23' : '3';
    $ck = $on ? ' checked' : '';
    return '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f3f4f6;font-family:var(--lt-font)">'
        . '<div><strong style="font-size:.88rem;color:#111827;display:block">' . htmlspecialchars($label) . '</strong>'
        . '<span style="font-size:.75rem;color:#9ca3af">' . htmlspecialchars($hint) . '</span></div>'
        . '<label style="position:relative;width:44px;height:24px;cursor:pointer;display:inline-block;flex-shrink:0">'
        . '<input type="checkbox" name="' . $name . '" value="1"' . $ck
        . ' style="position:absolute;opacity:0;width:0;height:0"'
        . ' onchange="var s=this.parentElement.querySelector(\'span\'),k=s.querySelector(\'span\');'
        . 's.style.background=this.checked?\'#3b82f6\':\'#d1d5db\';k.style.left=this.checked?\'23px\':\'3px\'">'
        . '<span style="position:absolute;inset:0;border-radius:100px;background:' . $bg . ';transition:background .2s">'
        . '<span style="position:absolute;width:18px;height:18px;top:3px;left:' . $lx . 'px;background:#fff;border-radius:50%;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)"></span>'
        . '</span></label></div>';
}

function local_learnpath_select_field(string $name, string $label, array $opts, string $cur, string $id = ''): string {
    $attr = 'name="' . htmlspecialchars($name) . '"';
    if ($id) $attr .= ' id="' . $id . '"';
    $attr .= ' style="font-family:var(--lt-font);font-size:.86rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:7px 10px;background:#f9fafb;width:100%;max-width:340px;outline:none"';
    $html = '<div style="margin-bottom:13px;font-family:var(--lt-font)">'
        . '<label style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">' . htmlspecialchars($label) . '</label>'
        . '<select ' . $attr . '>';
    foreach ($opts as $v => $l) {
        $html .= '<option value="' . htmlspecialchars($v) . '"' . ($cur === (string)$v ? ' selected' : '') . '>'
            . htmlspecialchars($l) . '</option>';
    }
    $html .= '</select></div>';
    return $html;
}

$brand      = local_learnpath_branding_cfg('brand_color', '#1e3a5f');
$logo_path  = local_learnpath_branding_cfg('cert_logo_path', '');
$serve_url  = $logo_path ? (new moodle_url('/local/learnpath/logo_upload.php',['serve'=>1]))->out(false).'&t='.time() : '';
$upload_url = (new moodle_url('/local/learnpath/logo_upload.php'))->out(false);
$upload_sk  = sesskey();

echo $OUTPUT->header();
echo '<style>:root{--lt-primary:' . $brand . ';--lt-accent:' . $brand . '}</style>';
echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">';
foreach ([['<- Welcome','/local/learnpath/welcome.php'],['Dashboard','/local/learnpath/index.php'],
          ['Manage','/local/learnpath/manage.php'],['Overview','/local/learnpath/overview.php']] as [$l,$u]) {
    echo html_writer::link(new moodle_url($u), $l, ['style'=>'font-family:var(--lt-font);font-size:.84rem;color:#374151;text-decoration:none;padding:6px 12px;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff']);
}
echo '</div>';
if ($saved) echo '<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-family:var(--lt-font);color:#065f46">Branding settings saved!</div>';
echo '<h1 style="font-family:var(--lt-font);font-size:1.3rem;font-weight:800;color:#111827;margin:0 0 18px">Branding Settings</h1>';
echo '<form method="post" style="max-width:820px">';
echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);

// 1. Identity
local_learnpath_cert_card('Identity',
    local_learnpath_text_field('brand_name','Plugin Display Name',local_learnpath_branding_cfg('brand_name','LearnTrack'),'Shown in headers') .
    local_learnpath_text_field('email_sender_name','Email Sender Name',local_learnpath_branding_cfg('email_sender_name','LearnTrack'),'Name on outgoing emails') .
    local_learnpath_text_field('invite_expiry_hours','Manager Invite Expiry (hours)',(string)(int)local_learnpath_branding_cfg('invite_expiry_hours','24'),'Hours before invite links expire (default 24)')
);

// 2. Colours
$bc = local_learnpath_branding_cfg('brand_color','#1e3a5f');
local_learnpath_cert_card('Colours',
    '<div style="display:flex;align-items:center;gap:10px;font-family:var(--lt-font)">'
    . '<input type="color" name="brand_color" value="' . s($bc) . '" id="ltp-col"'
    . ' style="width:48px;height:36px;border:1.5px solid #e5e7eb;border-radius:8px;padding:2px;cursor:pointer">'
    . '<div><div style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px">Primary Colour</div>'
    . '<div style="font-size:.72rem;color:#9ca3af">Used in headers, buttons, progress bars</div>'
    . '</div></div>'
);

// 3. Typography
$fonts=['inherit'=>'Inherit from Moodle (default)','system-ui'=>'System UI','DM Sans'=>'DM Sans',
        'Inter'=>'Inter','Roboto'=>'Roboto','Open Sans'=>'Open Sans','Poppins'=>'Poppins',
        'Georgia,serif'=>'Georgia (serif)','Arial,sans-serif'=>'Arial'];
$sizes=[11=>'11px',12=>'12px',13=>'13px (default)',14=>'14px',15=>'15px',16=>'16px',17=>'17px',18=>'18px'];
local_learnpath_cert_card('Typography',
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">'
    . local_learnpath_select_field('brand_font','Font Family',$fonts,local_learnpath_branding_cfg('brand_font','inherit'))
    . local_learnpath_select_field('brand_font_size','Base Font Size',$sizes,(string)(int)local_learnpath_branding_cfg('brand_font_size',13))
    . '</div>'
);

// 4. Visible Fields
local_learnpath_cert_card('Visible Fields',
    local_learnpath_toggle_field('show_status','Completion Status','Show status badge',(int)local_learnpath_branding_cfg('show_status',1)) .
    local_learnpath_toggle_field('show_grade','Grade / Score','Show learner grade',(int)local_learnpath_branding_cfg('show_grade',1)) .
    local_learnpath_toggle_field('show_activities','Activity Count','Show activities completed/total',(int)local_learnpath_branding_cfg('show_activities',1)) .
    local_learnpath_toggle_field('show_firstaccess','First Access Date','Show first access date',(int)local_learnpath_branding_cfg('show_firstaccess',1)) .
    local_learnpath_toggle_field('show_lastaccess','Last Access Date','Show last access date',(int)local_learnpath_branding_cfg('show_lastaccess',1))
);

// 5. Accessibility with live preview
$ah  = local_learnpath_toggle_field('high_contrast','High Contrast Mode','Increase colour contrast',(int)local_learnpath_branding_cfg('high_contrast',0));
$ah .= local_learnpath_toggle_field('large_text','Larger Text Mode','Apply larger base font size',(int)local_learnpath_branding_cfg('large_text',0));
$ah .= local_learnpath_toggle_field('reduce_motion','Reduce Motion','Disable transitions and animations',(int)local_learnpath_branding_cfg('reduce_motion',0));
$ah .= '<div id="lt-ap" style="margin-top:14px;padding:14px 16px;border-radius:8px;border:2px solid #e5e7eb;font-family:var(--lt-font)">'
    . '<div style="font-size:.76rem;font-weight:700;color:#6b7280;margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px">Live Preview</div>'
    . '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">'
    . '<div id="lt-ap-card" style="padding:12px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;display:flex;align-items:center;gap:10px;transition:all .3s">'
    . '<div style="font-size:1.2rem;width:38px;height:38px;border-radius:9px;background:#dbeafe;display:flex;align-items:center;justify-content:center">&#128202;</div>'
    . '<div><div id="lt-ap-val" style="font-size:1.3rem;font-weight:800;color:#111827">84%</div>'
    . '<div id="lt-ap-lbl" style="font-size:.68rem;font-weight:600;color:#9ca3af;text-transform:uppercase">Progress</div></div></div>'
    . '<button id="lt-ap-btn" style="background:#3b82f6;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-family:var(--lt-font);font-size:.84rem;font-weight:700;cursor:pointer;transition:all .2s">Continue &#8594;</button>'
    . '</div></div>';
$ah .= '<script>function ltApply(){'
    . 'var hc=!!(document.querySelector("[name=high_contrast]")||{}).checked;'
    . 'var lt=!!(document.querySelector("[name=large_text]")||{}).checked;'
    . 'var rm=!!(document.querySelector("[name=reduce_motion]")||{}).checked;'
    . 'var p=document.getElementById("lt-ap");if(!p)return;'
    . 'p.style.filter=hc?"contrast(1.5)":"";'
    . 'var v=document.getElementById("lt-ap-val");if(v)v.style.fontSize=lt?"1.7rem":"1.3rem";'
    . 'var l=document.getElementById("lt-ap-lbl");if(l)l.style.fontSize=lt?".82rem":".68rem";'
    . 'var b=document.getElementById("lt-ap-btn");if(b)b.style.fontSize=lt?"1rem":".84rem";'
    . 'var tr=rm?"none":"all .3s";'
    . '[document.getElementById("lt-ap-card"),b].forEach(function(el){if(el)el.style.transition=tr;});}'
    . 'document.querySelectorAll("[name=high_contrast],[name=large_text],[name=reduce_motion]").forEach(function(el){el.addEventListener("change",ltApply);});'
    . 'document.addEventListener("DOMContentLoaded",ltApply);</script>';
local_learnpath_cert_card('Accessibility', $ah);

// 6. Certificate Design
$cfn=['Georgia,serif'=>'Georgia','Times New Roman,serif'=>'Times New Roman','Palatino,serif'=>'Palatino',
      'Arial,sans-serif'=>'Arial','Helvetica,sans-serif'=>'Helvetica','Garamond,serif'=>'Garamond'];
$cbn=['double'=>'Double line (formal)','solid'=>'Single line (clean)','ridge'=>'Ridge (embossed)','groove'=>'Groove (inset)','none'=>'None'];
$cbg  = local_learnpath_branding_cfg('cert_bg_color','#fffdf6');
$cbrd = local_learnpath_branding_cfg('cert_border_color','#c8a951');
$cbrs = local_learnpath_branding_cfg('cert_border_style','double');
$ctf  = local_learnpath_branding_cfg('cert_title_font','Georgia,serif');
$cbf  = local_learnpath_branding_cfg('cert_body_font','Georgia,serif');
$corg = local_learnpath_branding_cfg('cert_org_name',local_learnpath_branding_cfg('brand_name','LearnTrack'));
$csig = local_learnpath_branding_cfg('cert_signatory_title','Learning Path Manager');
$cft  = local_learnpath_branding_cfg('cert_footer_text','This certificate was issued as proof of course completion.');

$ch = '';
$ch .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-family:var(--lt-font);margin-bottom:12px">';
foreach ([['cert_bg_color','ltc-bg','Background Colour',$cbg],['cert_border_color','ltc-brd','Border Colour',$cbrd]] as [$n,$id,$lbl,$v]) {
    $ch .= '<div><label style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">' . $lbl . '</label>'
        . '<input type="color" name="' . $n . '" id="' . $id . '" value="' . s($v) . '"'
        . ' oninput="if(window.ltCPrev)window.ltCPrev()" onchange="if(window.ltCPrev)window.ltCPrev()"'
        . ' style="width:44px;height:34px;border:1.5px solid #e5e7eb;border-radius:6px;padding:2px;cursor:pointer"></div>';
}
$ch .= '</div>';
$ch .= local_learnpath_select_field('cert_border_style','Border Style',$cbn,$cbrs,'ltc-brs');
$ch .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">'
    . local_learnpath_select_field('cert_title_font','Heading Font',$cfn,$ctf,'ltc-tf')
    . local_learnpath_select_field('cert_body_font','Body Font',$cfn,$cbf,'ltc-bf')
    . '</div>';
$ch .= local_learnpath_text_field('cert_org_name','Issuing Organisation Name',$corg,'Shown at top of certificate');
$ch .= local_learnpath_text_field('cert_signatory_title','Signatory Title',$csig,'Shown under signature line');
$ch .= local_learnpath_text_field('cert_footer_text','Footer Text (small print)',$cft,'Shown at bottom of certificate');
$ch .= local_learnpath_toggle_field('cert_show_logo','Show Logo','Display your LMS/brand logo',(int)local_learnpath_branding_cfg('cert_show_logo',1));
$ch .= local_learnpath_toggle_field('cert_show_signature','Show Signature Line','Display signatory name/title',(int)local_learnpath_branding_cfg('cert_show_signature',1));
$ch .= local_learnpath_toggle_field('cert_show_date','Show Issue Date','Display the certificate date',(int)local_learnpath_branding_cfg('cert_show_date',1));
$ch .= local_learnpath_toggle_field('cert_show_ref','Show Reference Number','Display certificate ref',(int)local_learnpath_branding_cfg('cert_show_ref',1));

// Logo upload with position selector
$lms_logo_url = '';
try {
    $site_logo = get_config('core', 'sitelogo');
    if ($site_logo) {
        $lms_logo_url = (new moodle_url('/pluginfile.php/1/core_admin/logo/0x150/' . $site_logo))->out(false);
    }
} catch (\Throwable $e_logo) {}
$effective_logo = $serve_url ?: $lms_logo_url;
$cert_pos = local_learnpath_branding_cfg('cert_logo_pos', 'top-right');
$pos_opts = ['top-left'=>'Top Left','top-center'=>'Top Centre','top-right'=>'Top Right (default)',
             'bottom-left'=>'Bottom Left','bottom-center'=>'Bottom Centre','bottom-right'=>'Bottom Right'];

$ch .= '<div style="margin-top:14px;font-family:var(--lt-font)">';
$ch .= '<label style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:8px">Certificate Logo</label>';
$ch .= '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">';
$ch .= '<div id="lt-logo-thumb" style="width:60px;height:60px;border:2px dashed #e5e7eb;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#f9fafb;overflow:hidden;flex-shrink:0">';
$ch .= $effective_logo ? '<img src="' . s($effective_logo) . '" style="max-width:100%;max-height:100%;object-fit:contain">' : '<span style="font-size:1.4rem">&#128246;</span>';
$ch .= '</div><div>';
$ch .= '<input type="file" id="lt-logo-file" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp" style="display:none">';
$ch .= '<button type="button" onclick="document.getElementById(\'lt-logo-file\').click()" style="font-family:var(--lt-font);font-size:.82rem;font-weight:700;padding:7px 14px;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;cursor:pointer;color:#374151">';
$ch .= $serve_url ? '&#128247; Change Logo' : '&#128247; Upload Logo';
$ch .= '</button>';
if ($serve_url) {
    $rm = (new moodle_url('/local/learnpath/logo_upload.php',['remove'=>1,'sesskey'=>sesskey()]))->out(false);
    $ch .= ' <a href="' . s($rm) . '" style="font-size:.78rem;color:#be123c;text-decoration:none;margin-left:6px" onclick="return confirm(\'Remove uploaded logo?\')">&#10005; Remove</a>';
}
if (!$serve_url && $lms_logo_url) {
    $ch .= '<div style="font-size:.74rem;color:#10b981;margin-top:4px">&#10003; Using LMS site logo by default</div>';
}
$ch .= '<div style="font-size:.72rem;color:#9ca3af;margin-top:4px">PNG, JPG, GIF, SVG or WebP &#183; max 2 MB. Leave blank to use the LMS site logo.</div>';
$ch .= '<div id="lt-logo-status" style="font-size:.76rem;font-weight:600;margin-top:3px"></div>';
$ch .= '</div></div>';

// Logo position selector
$ch .= '<div style="margin-top:8px">';
$ch .= '<label style="font-size:.74rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px">Logo Position on Certificate</label>';
$ch .= '<select name="cert_logo_pos" id="ltc-logo-pos" onchange="if(window.ltCPrev)window.ltCPrev()" style="font-family:var(--lt-font);font-size:.84rem;border:1.5px solid #e5e7eb;border-radius:8px;padding:7px 10px;background:#f9fafb;width:100%;max-width:260px">';
foreach ($pos_opts as $pv => $pl) {
    $ch .= '<option value="' . $pv . '"' . ($cert_pos === $pv ? ' selected' : '') . '>' . $pl . '</option>';
}
$ch .= '</select></div>';
$ch .= '</div>';

// Update JS defaults to include logo position and effective logo



// All JS via json_encode - zero nesting issues
// Get a sample path + courses for preview
$_preview_group = $DB->get_record_sql("SELECT id, name FROM {local_learnpath_groups} ORDER BY id DESC LIMIT 1");
$_preview_path  = $_preview_group ? format_string($_preview_group->name) : 'Sample Learning Path';
$_preview_courses = [];
if ($_preview_group) {
    $pcrows = $DB->get_records_sql(
        "SELECT c.fullname FROM {course} c JOIN {local_learnpath_group_courses} lgc ON lgc.courseid=c.id WHERE lgc.groupid=:gid ORDER BY lgc.id LIMIT 6",
        ['gid' => $_preview_group->id]
    );
    foreach ($pcrows as $pcr) { $_preview_courses[] = format_string($pcr->fullname); }
}
$_preview_course_list = empty($_preview_courses) ? 'Course 1 · Course 2 · Course 3' : implode(' · ', $_preview_courses);

$jsd = json_encode(['bg'=>$cbg,'brd'=>$cbrd,'brs'=>$cbrs,'tf'=>$ctf,'bf'=>$cbf,
    'org'=>$corg,'sig'=>$csig,'foot'=>$cft,'logo'=>$effective_logo,'logoPos'=>$cert_pos,
    'uploadUrl'=>$upload_url,'uploadSk'=>$upload_sk,
    'pathName'=>$_preview_path,'courseList'=>$_preview_course_list]);
// Inject cert preview JS via $PAGE->requires->js_init_code() — CSP-safe with Moodle 4.5+
$cert_js  = 'var LTC=' . $jsd . ';';
$cert_js .= 'window.ltCPrev=function(){';
$cert_js .= '  var gv=function(id){var e=document.getElementById(id);return e?e.value:""};';
$cert_js .= '  var gn=function(n){var e=document.querySelector("[name=\""+n+"\"]");return e?e.value:""};';
$cert_js .= '  var bg=gv("ltc-bg")||LTC.bg,brd=gv("ltc-brd")||LTC.brd,brs=gv("ltc-brs")||LTC.brs;';
$cert_js .= '  var tf=gv("ltc-tf")||LTC.tf,bf=gv("ltc-bf")||LTC.bf;';
$cert_js .= '  var org=gn("cert_org_name")||LTC.org,sig=gn("cert_signatory_title")||LTC.sig;';
$cert_js .= '  var foot=gn("cert_footer_text")||LTC.foot;';
$cert_js .= '  var logo=LTC.logo;';
$cert_js .= '  var th=document.getElementById("lt-logo-thumb");';
$cert_js .= '  if(th){var im=th.querySelector("img");if(im&&im.src&&im.src!==window.location.href)logo=im.src;}';
$cert_js .= '  var lpos=gv("ltc-logo-pos")||LTC.logoPos||"top-right";';
$cert_js .= '  var lpm={"top-left":"top:8px;left:8px","top-center":"top:8px;left:50%;transform:translateX(-50%)","top-right":"top:8px;right:8px","bottom-left":"bottom:8px;left:8px","bottom-center":"bottom:8px;left:50%;transform:translateX(-50%)","bottom-right":"bottom:8px;right:8px"};';
$cert_js .= '  var lps=lpm[lpos]||"top:8px;right:8px";';
$cert_js .= '  var lh=logo?"<img src=\'"+logo+"\' style=\'position:absolute;height:36px;"+lps+"\'>":"";';
$cert_js .= '  var p=document.getElementById("lt-cert-preview");';
$cert_js .= '  if(!p){return;}';
$cert_js .= '  var today=new Date();var mo=["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];';
$cert_js .= '  var dateStr=today.getDate()+" "+mo[today.getMonth()]+" "+today.getFullYear();';
$cert_js .= '  p.innerHTML=';
$cert_js .= '    "<div style=\'background:"+bg+";border:8px "+brs+" "+brd+";border-radius:4px;padding:24px 32px;text-align:center;position:relative;font-family:Georgia,serif\'>"';
$cert_js .= '   +"<div style=\'border:2px solid "+brd+";padding:18px 24px;position:relative\'>"+lh';
$cert_js .= '   +"<div style=\'font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:2.5px;color:#6b7280;margin-bottom:5px\'>"+org+"</div>"';
$cert_js .= '   +"<div style=\'font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:3px;color:#374151;margin-bottom:10px;font-family:"+tf+"\'>Certificate of Completion</div>"';
$cert_js .= '   +"<div style=\'width:60px;height:2px;background:"+brd+";margin:0 auto 10px\'></div>"';
$cert_js .= '   +"<div style=\'font-size:.65rem;color:#6b7280;margin-bottom:6px;font-family:"+bf+"\'>This is to certify that</div>"';
$cert_js .= '   +"<div style=\'font-size:1.05rem;font-weight:700;color:#111827;font-family:"+tf+";border-bottom:1.5px solid "+brd+";padding-bottom:5px;margin-bottom:6px\'>Learner Full Name</div>"';
$cert_js .= '   +"<div style=\'font-size:.65rem;color:#6b7280;font-family:"+bf+";margin-bottom:8px\'>has successfully completed all courses in</div>"';
$cert_js .= '   +"<div style=\'font-size:.88rem;font-weight:700;color:#1e3a5f;font-family:"+tf+";margin-bottom:4px\'>"+LTC.pathName+"</div>"';
$cert_js .= '   +"<div style=\'font-size:.56rem;color:#888;font-family:"+bf+";margin-bottom:14px;line-height:1.8\'>"+LTC.courseList+"</div>"';
$cert_js .= '   +"<div style=\'display:flex;justify-content:space-around;margin-top:14px\'>"';
$cert_js .= '   +"<div style=\'text-align:center\'><div style=\'border-top:1.5px solid "+brd+";padding-top:4px;font-size:.56rem;color:#374151;font-family:"+bf+"\'>Authorised by<br><em>"+sig+"</em></div></div>"';
$cert_js .= '   +"<div style=\'text-align:center\'><div style=\'border-top:1.5px solid "+brd+";padding-top:4px;font-size:.56rem;color:#374151;font-family:"+bf+"\'>Date Issued<br><em>"+dateStr+"</em></div></div>"';
$cert_js .= '   +"</div>"';
$cert_js .= '   +"<div style=\'font-size:.48rem;color:#aaa;margin-top:10px;font-family:"+bf+"\'>"+foot+"</div>"';
$cert_js .= '   +"</div></div>";';
$cert_js .= '};';
$cert_js .= 'document.querySelectorAll("#ltc-bg,#ltc-brd,#ltc-brs,#ltc-tf,#ltc-bf,#ltc-logo-pos").forEach(function(e){';
$cert_js .= '  e.addEventListener("input",window.ltCPrev);e.addEventListener("change",window.ltCPrev);';
$cert_js .= '});';
$cert_js .= 'document.querySelectorAll(\'[name="cert_org_name"],[name="cert_signatory_title"],[name="cert_footer_text"]\').forEach(function(e){';
$cert_js .= '  e.addEventListener("input",window.ltCPrev);e.addEventListener("change",window.ltCPrev);';
$cert_js .= '});';
$cert_js .= 'var _lfi=document.getElementById("lt-logo-file");';
$cert_js .= 'if(_lfi){_lfi.addEventListener("change",function(){';
$cert_js .= '  var f=this.files[0],st=document.getElementById("lt-logo-status");if(!f)return;';
$cert_js .= '  if(f.size>2097152){st.textContent="Too large (max 2MB)";st.style.color="#be123c";return;}';
$cert_js .= '  var fd=new FormData();fd.append("logo",f);fd.append("sesskey",LTC.uploadSk);';
$cert_js .= '  st.textContent="Uploading...";st.style.color="#6b7280";';
$cert_js .= '  fetch(LTC.uploadUrl,{method:"POST",body:fd}).then(function(r){return r.json();})';
$cert_js .= '  .then(function(d){if(d.ok){st.textContent="Uploaded ✓";st.style.color="#10b981";';
$cert_js .= '  document.getElementById("lt-logo-thumb").innerHTML="<img src=\'"+d.url+"\' style=\'max-width:100%;max-height:100%;object-fit:contain\'>";';
$cert_js .= '  LTC.logo=d.url;window.ltCPrev();}else{st.textContent=d.error||"Failed";st.style.color="#be123c";}});';
$cert_js .= '});}';
$cert_js .= 'window.ltCPrev();';
$PAGE->requires->js_init_code($cert_js);

local_learnpath_cert_card('Certificate Design', $ch);

// Live certificate preview - shown BELOW the Certificate Design card
echo '<div class="lt-card" style="margin-bottom:16px">';
echo '<div class="lt-card-header"><h3 class="lt-card-title">📄 Live Certificate Preview</h3>';
echo '<span style="font-size:.72rem;color:#9ca3af;font-family:var(--lt-font)">Updates as you change settings above</span>';
echo '</div><div class="lt-card-body" style="overflow-x:auto">';
echo '<div id="lt-cert-preview" style="min-width:540px"></div>';
echo '</div></div>';

echo '<div style="display:flex;gap:10px;padding:14px 0;border-top:1px solid #e5e7eb">';
echo '<button type="submit" class="lt-btn lt-btn-primary">Save Branding Settings</button>';
echo html_writer::link(new moodle_url('/local/learnpath/welcome.php'),'Cancel',['class'=>'lt-btn lt-btn-ghost']);
echo '</div></form>';
echo '<div class="lt-footer"><span>&#169; Michael Adeniran</span><span class="lt-sep">&#183;</span>'
    . html_writer::link('https://www.linkedin.com/in/michaeladeniran','LinkedIn',['target'=>'_blank'])
    . '<span class="lt-sep">&#183;</span><span>LearnTrack v1.0.0</span></div>';
echo $OUTPUT->footer();
