<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']    = 'LearnTrack';
$string['plugindesc']    = 'Learning Path Progress Dashboard for Moodle. Track learner progress across multiple courses from a single interface.';

// Capabilities
$string['learnpath:viewdashboard'] = 'View LearnTrack dashboard';
$string['learnpath:manage']        = 'Manage learning paths';
$string['learnpath:export']        = 'Export reports';
$string['learnpath:emailreport']   = 'Send/schedule email reports';
$string['learnpath:viewall']       = 'View all learners (bypass group restriction)';

// Navigation
$string['overview']           = 'Overview';
$string['dashboard']          = 'Dashboard';
$string['manage_paths']       = 'Manage Paths';
$string['branding']           = 'Branding';
$string['leaderboard']        = 'Leaderboard';
$string['my_learning_paths']  = 'My Learning Paths';
$string['course_insights']    = 'Course Insights';
$string['back_to_dashboard']  = 'Back to Dashboard';
$string['back_to_overview']   = 'Back to Overview';
$string['back_to_welcome']    = 'Back to Welcome';

// Group form
$string['group_name']         = 'Path name';
$string['group_description']  = 'Description';
$string['group_type']         = 'Group type';
$string['group_category']     = 'Course category';
$string['group_cohort']       = 'Cohort';
$string['group_courses']      = 'Courses';
$string['grouptype_manual']   = 'Manual course selection';
$string['grouptype_category'] = 'By course category';
$string['grouptype_cohort']   = 'By cohort';
$string['group_saved']        = 'Learning path saved.';
$string['group_deleted']      = 'Learning path deleted.';
$string['no_groups']          = 'No learning paths created yet.';
$string['no_groups_hint']     = 'Create your first learning path to start tracking learner progress.';
$string['add_group']          = 'Add Learning Path';
$string['edit_group']         = 'Edit Learning Path';
$string['confirm_delete_group'] = 'Delete this learning path and all associated data?';
$string['invalidgroup']       = 'Invalid learning path.';
$string['group_coursecount']  = 'Courses';
$string['manage_paths_subtitle'] = 'Create and manage learning path groups.';

// Schedule
$string['schedule_recipients']      = 'Email recipients';
$string['schedule_recipients_help'] = 'Enter one or more email addresses separated by commas.';
$string['schedule_frequency']       = 'Frequency';
$string['schedule_format']          = 'File format';
$string['schedule_enabled']         = 'Enabled';
$string['schedule_saved']           = 'Schedule saved.';
$string['schedule_deleted']         = 'Schedule deleted.';
$string['manage_schedules']         = 'Schedules';
$string['no_schedules']             = 'No scheduled reports set up yet.';
$string['add_schedule']             = 'Add Schedule';
$string['edit_schedule']            = 'Edit Schedule';

// Email
$string['send_email_now']        = 'Send Report Now';
$string['email_sent_success']    = 'Report sent successfully.';
$string['email_subject']         = 'LearnTrack Progress Report: {$a}';
$string['email_body']            = 'Dear Recipient,

Attached is the progress report for: {$a->groupname}.

Date: {$a->date}
Records: {$a->count}

LearnTrack';

// Status
$string['status_complete']   = 'Completed';
$string['status_inprogress'] = 'In Progress';
$string['status_notstarted'] = 'Not Started';

// Column headers
$string['col_firstname']            = 'First Name';
$string['col_lastname']             = 'Last Name';
$string['col_email']                = 'Email';
$string['col_username']             = 'Username';
$string['col_coursename']           = 'Course';
$string['col_status']               = 'Status';
$string['col_progress']             = 'Progress';
$string['col_completed_activities'] = 'Completed Activities';
$string['col_total_activities']     = 'Total Activities';
$string['col_grade']                = 'Grade';
$string['col_firstaccess']          = 'First Access';
$string['col_lastaccess']           = 'Last Access';
$string['col_timecompleted']        = 'Date Completed';
$string['col_total_courses']        = 'Total Courses';
$string['col_completed_courses']    = 'Completed';
$string['col_inprogress_courses']   = 'In Progress';
$string['col_notstarted_courses']   = 'Not Started';
$string['col_overall_progress']     = 'Overall Progress';

// Tasks
$string['task_send_reports']  = 'LearnTrack: Send scheduled reports';
$string['task_send_reminders']= 'LearnTrack: Send learner reminders';
$string['task_refresh_cache'] = 'LearnTrack: Refresh progress cache';

// Misc
$string['select_group']        = 'Select a learning path';
$string['select_group_prompt'] = 'Choose a learning path from the dropdown to view learner progress.';
$string['search_learner']      = 'Search by name, email, or username…';
$string['no_data']             = 'No data found for current filters.';
$string['actions']             = 'Actions';
$string['view']                = 'View';
$string['view_detail']         = 'Course Detail';
$string['view_summary']        = 'Summary';
$string['admin_only_notice']   = 'Admin access only';
$string['admin_only_notice_desc'] = 'Only Site Administrators and Managers can access this area.';

// Privacy
$string['privacy:metadata'] = 'LearnTrack reads enrolment, completion, grade, and log data from Moodle core tables. It stores group configurations, schedules, certificates, notes, reminders, and a progress cache. It does not share data externally.';

// Privacy strings (required for Plugin Directory)
$string['privacy:metadata']                           = 'LearnTrack stores learner progress cache, admin notes, certificate records, and reminder logs. It reads enrolment, completion, grade, and log data from Moodle core tables. It does not share personal data with external services beyond configured email delivery.';
$string['privacy:metadata:progress_cache']            = 'A cached summary of each learner\'s progress per learning path group, updated periodically by cron.';
$string['privacy:metadata:progress_cache:userid']     = 'The ID of the learner.';
$string['privacy:metadata:progress_cache:groupid']    = 'The ID of the learning path group.';
$string['privacy:metadata:progress_cache:completed']  = 'Number of courses completed.';
$string['privacy:metadata:progress_cache:progress']   = 'Overall progress percentage.';
$string['privacy:metadata:progress_cache:firstaccess']= 'Timestamp of first access to any course in the path.';
$string['privacy:metadata:progress_cache:lastaccess'] = 'Timestamp of most recent access.';
$string['privacy:metadata:progress_cache:timeupdated']= 'When this cache entry was last updated.';
$string['privacy:metadata:notes']                     = 'Private notes written by administrators about a learner. Not visible to the learner.';
$string['privacy:metadata:notes:userid']              = 'The learner the note is about.';
$string['privacy:metadata:notes:authorid']            = 'The administrator who wrote the note.';
$string['privacy:metadata:notes:note']                = 'The note content.';
$string['privacy:metadata:notes:timecreated']         = 'When the note was created.';
$string['privacy:metadata:certs']                     = 'Certificate of completion records issued to learners for completing a learning path.';
$string['privacy:metadata:certs:userid']              = 'The learner the certificate was issued to.';
$string['privacy:metadata:certs:issuedby']            = 'The administrator who issued the certificate.';
$string['privacy:metadata:certs:issuedate']           = 'When the certificate was issued.';
$string['privacy:metadata:certs:certnumber']          = 'Optional certificate reference number.';
$string['privacy:metadata:reminder_log']              = 'A log of reminder notifications sent to learners.';
$string['privacy:metadata:reminder_log:userid']       = 'The learner who received the reminder.';
$string['privacy:metadata:reminder_log:channel']      = 'The channel used (email, inapp, or sms).';
$string['privacy:metadata:reminder_log:timesent']     = 'When the reminder was sent.';
$string['privacy:metadata:reminder_log:status']       = 'Whether the reminder was sent successfully.';
$string['privacy:metadata:email']                     = 'LearnTrack sends email notifications (reminders, reports, certificates) via Moodle\'s email system.';
$string['privacy:metadata:email:address']             = 'The recipient email address.';

// Learner management
$string['manage_learners']           = 'Manage Learners';
$string['manage_learners_subtitle']  = 'Add or remove individual learners from this learning path.';
$string['add_learner']               = 'Add Learner';
$string['learner_added']             = 'Learner(s) added to path.';
$string['learner_removed']           = 'Learner removed from path.';
$string['no_assigned_learners']      = 'No individual learners assigned yet.';
$string['individual_users']          = 'Individual users';

// Inactive filter
$string['filter_inactive']           = 'Inactive';
$string['filter_inactive_hint']      = 'Learners with no access in the last {$a} days';
$string['inactive_not_configured']   = 'Set "Inactive learner threshold" in LearnTrack settings to enable this filter.';

// Popup
$string['popup_enabled']             = 'Enable deadline countdown popup';
$string['popup_trigger']             = 'Popup trigger';
$string['popup_trigger_always']      = 'Every login (until complete)';
$string['popup_trigger_threshold']   = 'Only within X days of deadline';
$string['popup_days']                = 'Show popup within X days of deadline';

// Privacy — email_log
$string['privacy:metadata:email_log']              = 'Records of progress report emails sent by administrators.';
$string['privacy:metadata:email_log:groupid']      = 'The learning path the report was for.';
$string['privacy:metadata:email_log:senderid']     = 'The user who sent the report.';
$string['privacy:metadata:email_log:recipients']   = 'Email addresses the report was sent to.';
$string['privacy:metadata:email_log:timesent']     = 'When the email was sent.';
$string['managers_section'] = 'Path Managers & Access';
$string['path_owner'] = 'Path Owner';

// Export
$string['invalidformat'] = 'Invalid export format. Allowed formats: xlsx, csv, pdf.';

// Page titles
$string['page_title_reminders']    = 'LearnTrack — Reminders';
$string['page_title_branding']     = 'LearnTrack Branding';
$string['page_title_overview']     = 'LearnTrack — Overview';
$string['page_title_leaderboard']  = 'LearnTrack — Leaderboard';
$string['page_title_courseinsights'] = 'Course Insights';
$string['page_title_mypath']       = 'My Learning Paths';
$string['page_title_welcome']      = 'LearnTrack — Welcome';

// Branding page
$string['branding_page_title']     = 'Branding & Customisation';
$string['branding_page_subtitle']  = 'Customise the plugin\'s appearance, visible fields, and accessibility features.';
$string['branding_saved']          = 'Branding settings saved!';
$string['branding_identity']       = 'Identity';
$string['branding_colours']        = 'Colours';
$string['branding_typography']     = 'Typography';
$string['branding_visible_fields'] = 'Visible Fields';
$string['branding_accessibility']  = 'Accessibility';
$string['branding_cert_design']    = 'Certificate Design';
$string['branding_cert_preview']   = 'Live Certificate Preview';
$string['branding_save']           = 'Save Branding Settings';

// Welcome page
$string['welcome_title']           = 'Welcome to LearnTrack';
$string['welcome_subtitle']        = 'Your Moodle Learning Path Dashboard';
$string['welcome_features_title']  = 'What this plugin does';
$string['welcome_diagnostics']     = 'Diagnostics';

// Reminders page
$string['reminders_title']         = 'Reminders & Notifications';
$string['reminders_subtitle']      = 'Automate learner nudges via email, in-app & SMS';
$string['reminders_new_rule']      = '+ New Rule';
$string['reminders_no_rules']      = 'No Reminder Rules Yet';
$string['reminders_no_rules_desc'] = 'Create a rule to automatically notify learners who haven\'t started or completed their path.';
$string['reminders_create_first']  = '+ Create First Rule';
$string['reminders_send_now_title']= 'Send Reminder Now';
$string['reminders_save_rule']     = 'Save Rule';
$string['reminders_rule_deleted']  = 'Reminder rule deleted.';
$string['reminders_rule_saved']    = 'Reminder rule saved.';
$string['reminders_send_history']  = 'Send History';
$string['reminders_select_path']   = 'Please select a learning path before saving.';

// Overview / analytics
$string['overview_title']          = 'Site-Wide Analytics';
$string['overview_subtitle']       = 'Full picture across all learning paths';

// Diagnostics
$string['diagnostics_title']       = 'Database Diagnostics';
$string['diagnostics_table_ok']    = 'Table exists';
$string['diagnostics_table_miss']  = 'Table missing';

// Auto-added missing strings
