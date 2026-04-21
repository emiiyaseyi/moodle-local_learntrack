<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Top-level admin category for LearnTrack
    $ADMIN->add('localplugins', new admin_category(
        'local_learnpath_category',
        'LearnTrack'
    ));

    // Quick-access pages in the left nav
    $ADMIN->add('local_learnpath_category', new admin_externalpage(
        'local_learnpath_overview',
        get_string('overview', 'local_learnpath') ?: 'Overview',
        new moodle_url('/local/learnpath/overview.php'),
        'local/learnpath:viewdashboard'
    ));

    $ADMIN->add('local_learnpath_category', new admin_externalpage(
        'local_learnpath_welcome',
        'Welcome Page',
        new moodle_url('/local/learnpath/welcome.php'),
        'local/learnpath:viewdashboard'
    ));

    $ADMIN->add('local_learnpath_category', new admin_externalpage(
        'local_learnpath_dashboard',
        'Dashboard',
        new moodle_url('/local/learnpath/index.php'),
        'local/learnpath:viewdashboard'
    ));

    $ADMIN->add('local_learnpath_category', new admin_externalpage(
        'local_learnpath_manage',
        'Manage Paths',
        new moodle_url('/local/learnpath/manage.php'),
        'local/learnpath:manage'
    ));

    $ADMIN->add('local_learnpath_category', new admin_externalpage(
        'local_learnpath_branding',
        'Branding',
        new moodle_url('/local/learnpath/branding.php'),
        'local/learnpath:manage'
    ));

    // Sub-page: Leaderboard (criteria and badges management)
    $ADMIN->add('local_learnpath_category', new admin_externalpage(
        'local_learnpath_leaderboard',
        'Leaderboard',
        new moodle_url('/local/learnpath/leaderboard.php', ['tab' => 'criteria']),
        'local/learnpath:manage'
    ));

    // Settings page — key MUST be 'local_learnpath' (matches plugin component)
    // This is what Moodle's plugin manager links to from the plugins list
    $settings = new admin_settingpage('local_learnpath', get_string('pluginname', 'local_learnpath') . ' Settings');
    $ADMIN->add('local_learnpath_category', $settings);

    // ── General ──────────────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_learnpath/general_hdr', 'General', ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_learnpath/brand_name',
        'Plugin Display Name',
        'Shown in headers and navigation.',
        'LearnTrack'
    ));

    $settings->add(new admin_setting_configselect(
        'local_learnpath/restrict_by_group',
        'Restrict visibility by group/cohort',
        'When enabled, managers only see learners who share a group or cohort with them.',
        '0',
        ['0' => 'No — all learners visible', '1' => 'Yes — restrict by shared group/cohort']
    ));

    $settings->add(new admin_setting_configselect(
        'local_learnpath/block_visibility',
        'My Path block/nav visibility',
        'Controls who sees the LearnTrack navigation link and block.',
        'enrolled',
        ['enrolled' => 'Only users enrolled in a learning path course', 'all' => 'All logged-in users']
    ));

    $settings->add(new admin_setting_configselect(
        'local_learnpath/default_user_status',
        'Default user filter',
        'Which users appear in reports by default.',
        'active',
        ['active' => 'Active users only', 'suspended' => '+Suspended', 'all' => 'All (incl. deleted)']
    ));

    $settings->add(new admin_setting_configtext(
        'local_learnpath/inactive_days',
        'Inactive learner threshold (days)',
        'Learners with no course access for this many days appear in the Inactive filter. Leave 0 to disable.',
        '0',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_learnpath/participant_cap',
        'Manual participant selection cap',
        'Maximum number of users loaded in the path creation form for manual participant selection. Max 500.',
        '500',
        PARAM_INT
    ));

    // ── Deadline Popup ────────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_learnpath/popup_hdr', 'Deadline Popup', ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_learnpath/popup_enabled',
        'Enable deadline countdown popup',
        'Learners see a countdown popup on login showing their nearest upcoming deadline.',
        '1'
    ));

    $settings->add(new admin_setting_configselect(
        'local_learnpath/popup_trigger',
        'When to show popup',
        '',
        'always',
        ['always' => 'Every login (until 100% complete)', 'threshold' => 'Only within X days of deadline']
    ));

    $settings->add(new admin_setting_configtext(
        'local_learnpath/popup_days',
        'Show popup within X days of deadline',
        'Used when trigger is set to "Only within X days". Default: 30.',
        '30',
        PARAM_INT
    ));

    // ── Export & Email ────────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_learnpath/export_hdr', 'Export & Email', ''
    ));

    $settings->add(new admin_setting_configselect(
        'local_learnpath/default_export_format',
        'Default export format',
        '',
        'xlsx',
        ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV', 'pdf' => 'PDF']
    ));

    $settings->add(new admin_setting_configtext(
        'local_learnpath/email_sender_name',
        'Email sender name',
        'Name shown as sender on outgoing LearnTrack emails.',
        'LearnTrack'
    ));

    // ── Email Template ──────────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_learnpath/email_template_hdr',
        'Report Email Template',
        'Customise the subject and body of progress report emails. '
        . 'Variables: <code>{groupname}</code> <code>{date}</code> <code>{count}</code>'
    ));

    $settings->add(new admin_setting_configtext(
        'local_learnpath/email_report_subject',
        'Report Email Subject',
        'Subject line for progress report emails. Use {groupname} for the path name.',
        'LearnTrack Progress Report: {groupname}'
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_learnpath/email_report_body',
        'Report Email Body',
        'Body text of the report email. Variables: {groupname}, {date}, {count}.',
        'Dear Recipient,\n\nPlease find attached the progress report for: {groupname}.\n\nDate: {date}\nTotal records: {count}\n\nThis report was generated automatically by LearnTrack.\n\nRegards,\nLearnTrack'
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_learnpath/email_enroll_body',
        'Enrolment Notification Body',
        'Email sent to learners when admin enrols them into a path. Variables: {firstname}, {groupname}, {count}, {url}.',
        'Hi {firstname},\n\nYou have been added to the learning path "{groupname}" and enrolled in {count} course(s).\n\nLog in to start learning: {url}\n\nLearnTrack'
    ));

    // ── Branding (quick colour setting) ───────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_learnpath/branding_hdr',
        'Branding',
        'Quick colour setting. For full branding options including fonts, visit the <a href="/local/learnpath/branding.php">Branding page</a>.'
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_learnpath/brand_color',
        'Primary colour',
        'Used in headers, buttons, and progress bars.',
        '#1e3a5f'
    ));
}
