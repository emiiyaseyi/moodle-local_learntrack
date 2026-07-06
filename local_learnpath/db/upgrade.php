<?php
// LearnTrack upgrade.php — handles any previous version safely.
// All steps are idempotent (check existence before acting).
defined('MOODLE_INTERNAL') || die();

function xmldb_local_learnpath_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    // Single comprehensive block — runs for any install older than 2026041800.
    // Creates any missing tables, adds any missing fields. Safe to run on any state.
    if ($oldversion < 2026041800) {

        // local_learnpath_groups
        $table = new xmldb_table('local_learnpath_groups');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name',         XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL);
            $table->add_field('description',  XMLDB_TYPE_TEXT,    null,  null, false);
            $table->add_field('grouptype',    XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null, 'manual');
            $table->add_field('categoryid',   XMLDB_TYPE_INTEGER, '10',  null, false);
            $table->add_field('cohortid',     XMLDB_TYPE_INTEGER, '10',  null, false);
            $table->add_field('deadline',     XMLDB_TYPE_INTEGER, '10',  null, false);
            $table->add_field('adminnotes',   XMLDB_TYPE_TEXT,    null,  null, false);
            $table->add_field('createdby',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        } else {
            $gt = new xmldb_table('local_learnpath_groups');
            foreach ([
                new xmldb_field('description',  XMLDB_TYPE_TEXT,    null, null, false),
                new xmldb_field('grouptype',     XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'manual'),
                new xmldb_field('categoryid',    XMLDB_TYPE_INTEGER, '10', null, false),
                new xmldb_field('cohortid',      XMLDB_TYPE_INTEGER, '10', null, false),
                new xmldb_field('deadline',      XMLDB_TYPE_INTEGER, '10', null, false),
                new xmldb_field('adminnotes',    XMLDB_TYPE_TEXT,    null, null, false),
                new xmldb_field('createdby',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '2'),
                new xmldb_field('timecreated',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
                new xmldb_field('timemodified',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'),
            ] as $field) {
                if (!$dbman->field_exists($gt, $field)) {
                    $dbman->add_field($gt, $field);
                }
            }
        }

        // local_learnpath_group_courses
        $table = new xmldb_table('local_learnpath_group_courses');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('courseid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_managers
        $table = new xmldb_table('local_learnpath_managers');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('scope',   XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, null, 'cohort');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_schedules
        $table = new xmldb_table('local_learnpath_schedules');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('recipients',  XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL);
            $table->add_field('frequency',   XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null, 'weekly');
            $table->add_field('format',      XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, null, 'xlsx');
            $table->add_field('viewmode',    XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, null, 'summary');
            $table->add_field('nextrun',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('lastrun',     XMLDB_TYPE_INTEGER, '10',  null, false);
            $table->add_field('createdby',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('enabled',     XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, null, '1');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        } else {
            $st  = new xmldb_table('local_learnpath_schedules');
            $vm  = new xmldb_field('viewmode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'summary');
            if (!$dbman->field_exists($st, $vm)) {
                $dbman->add_field($st, $vm);
            }
        }

        // local_learnpath_reminders
        $table = new xmldb_table('local_learnpath_reminders');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('name',          XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL);
            $table->add_field('target',        XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null, 'incomplete');
            $table->add_field('channel_email', XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, null, '1');
            $table->add_field('channel_inapp', XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, null, '1');
            $table->add_field('channel_sms',   XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('subject',       XMLDB_TYPE_CHAR,    '255', null, false);
            $table->add_field('message',       XMLDB_TYPE_TEXT,    null,  null, false);
            $table->add_field('frequency',     XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null, 'once');
            $table->add_field('nextrun',       XMLDB_TYPE_INTEGER, '10',  null, false);
            $table->add_field('lastrun',       XMLDB_TYPE_INTEGER, '10',  null, false);
            $table->add_field('enabled',       XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, null, '1');
            $table->add_field('createdby',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_reminder_log
        $table = new xmldb_table('local_learnpath_reminder_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('reminderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('channel',    XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL);
            $table->add_field('timesent',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('status',     XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL, null, null, 'sent');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_certs
        $table = new xmldb_table('local_learnpath_certs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('issuedby',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('issuedate',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('certnumber',  XMLDB_TYPE_CHAR,    '64', null, false);
            $table->add_field('notes',       XMLDB_TYPE_TEXT,    null, null, false);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_notes
        $table = new xmldb_table('local_learnpath_notes');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('authorid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('note',         XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_progress_cache
        $table = new xmldb_table('local_learnpath_progress_cache');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',                XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('completed_courses', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('total_courses',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('overall_progress',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('firstaccess',       XMLDB_TYPE_INTEGER, '10', null, false);
            $table->add_field('lastaccess',        XMLDB_TYPE_INTEGER, '10', null, false);
            $table->add_field('timeupdated',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_user_assign
        $table = new xmldb_table('local_learnpath_user_assign');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('assignedby',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // ── Leaderboard tables ────────────────────────────────────────────────

        // local_learnpath_criteria
        $table = new xmldb_table('local_learnpath_criteria');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name',        XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL);
            $table->add_field('description', XMLDB_TYPE_TEXT,    null,  null, false);
            $table->add_field('points',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null, '10');
            $table->add_field('event_type',  XMLDB_TYPE_CHAR,    '50',  null, XMLDB_NOTNULL);
            $table->add_field('enabled',     XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, null, '1');
            $table->add_field('sortorder',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_points
        $table = new xmldb_table('local_learnpath_points');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('criteriaid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('points',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, false);
            $table->add_field('groupid',     XMLDB_TYPE_INTEGER, '10', null, false);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_badges
        $table = new xmldb_table('local_learnpath_badges');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name',        XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL);
            $table->add_field('description', XMLDB_TYPE_TEXT,    null,  null, false);
            $table->add_field('icon',        XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, null, '🏅');
            $table->add_field('points_req',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('sortorder',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // local_learnpath_user_badges
        $table = new xmldb_table('local_learnpath_user_badges');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('badgeid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('seen',        XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // Seed default criteria if none exist
        if (!$DB->record_exists('local_learnpath_criteria', [])) {
            $now = time();
            $defaults = [
                ['Course Completion',        'Completing any course in a learning path',     50, 'course_complete',    1],
                ['Activity Completion',      'Completing a tracked activity',                 10, 'activity_complete',  1],
                ['Path 100% Complete',       'Finishing all courses in a learning path',     100, 'path_complete',      1],
                ['First Login to a Course',  'Accessing a course for the first time',          5, 'course_first_access',1],
                ['Weekly Streak',            'Accessing a course every day for 7 days',       30, 'weekly_streak',      1],
                ['Monthly Streak',           'Accessing a course every day for 30 days',     100, 'monthly_streak',     1],
                ['Early Completion',         'Completing a path before the deadline',         25, 'early_completion',   1],
                ['Grade ≥ 80%',              'Achieving 80% or higher on a graded course',   20, 'high_grade',         1],
                ['Grade ≥ 90%',              'Achieving 90% or higher on a graded course',   40, 'very_high_grade',    1],
                ['Peer Mentor',              'Awarded manually by admin for mentoring',       15, 'manual_award',       1],
            ];
            foreach ($defaults as $i => [$name, $desc, $pts, $event, $enabled]) {
                $DB->insert_record('local_learnpath_criteria', (object)[
                    'name'        => $name,
                    'description' => $desc,
                    'points'      => $pts,
                    'event_type'  => $event,
                    'enabled'     => $enabled,
                    'sortorder'   => $i,
                    'timecreated' => $now,
                ]);
            }
        }

        // Seed default badges if none exist
        if (!$DB->record_exists('local_learnpath_badges', [])) {
            $now = time();
            $badges = [
                ['Starter',       'Earned your first points',           '🌱',  10],
                ['Explorer',      'Completed your first course',        '🔍',  50],
                ['Achiever',      'Reached 100 points',                 '⭐', 100],
                ['Learner',       'Completed 3 courses',                '📚', 150],
                ['Dedicated',     'Reached 250 points',                 '💪', 250],
                ['Scholar',       'Completed a full learning path',     '🎓', 350],
                ['Trailblazer',   'Reached 500 points',                 '🔥', 500],
                ['Champion',      'Completed 5 learning paths',         '🏆', 700],
                ['Elite Learner', 'Reached 1000 points',                '💎',1000],
                ['Legend',        'Topped the leaderboard for a month', '👑',1500],
            ];
            foreach ($badges as $i => [$name, $desc, $icon, $pts]) {
                $DB->insert_record('local_learnpath_badges', (object)[
                    'name'        => $name,
                    'description' => $desc,
                    'icon'        => $icon,
                    'points_req'  => $pts,
                    'sortorder'   => $i,
                    'timecreated' => $now,
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026041800, 'local', 'learnpath');
    }

    if ($oldversion < 2026042900) {
        // Add email_log table to track sent reports
        $table = new xmldb_table('local_learnpath_email_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('senderid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('recipients',  XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
            $table->add_field('format',      XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL, null, 'xlsx');
            $table->add_field('viewmode',    XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'summary');
            $table->add_field('recordcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timesent',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('groupid',  XMLDB_INDEX_NOTUNIQUE, ['groupid']);
            $table->add_index('timesent', XMLDB_INDEX_NOTUNIQUE, ['timesent']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026042900, 'local', 'learnpath');
    }

    if ($oldversion < 2026043000) {
        // Manager invite links table
        $table = new xmldb_table('local_learnpath_mgr_invites');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('email',        XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL);
            $table->add_field('token',        XMLDB_TYPE_CHAR,    '64',  null, XMLDB_NOTNULL);
            $table->add_field('invitedby',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('status',       XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, null, 'pending');
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('timeaccepted', XMLDB_TYPE_INTEGER, '10',  null, false);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }
        // Add timemodified to criteria if missing
        $crit_table = new xmldb_table('local_learnpath_criteria');
        if ($dbman->table_exists($crit_table)) {
            $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, false, null, null, 'timecreated');
            if (!$dbman->field_exists($crit_table, $field)) {
                $dbman->add_field($crit_table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026043000, 'local', 'learnpath');
    }


    if ($oldversion < 2026043400) {
        // v2026043400: Bug fixes - no schema changes required.
        // This block ensures the upgrade runs even when only PHP files changed.

        // Ensure local_learnpath_certs table exists (in case of partial installs)
        $table = new xmldb_table('local_learnpath_certs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('issuedby',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('issuedate',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_field('certnumber',  XMLDB_TYPE_CHAR,    '64',  null, false);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // Ensure local_learnpath_notes table exists
        $table = new xmldb_table('local_learnpath_notes');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('groupid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('authorid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('note',         XMLDB_TYPE_TEXT,    null, null, XMLDB_NOTNULL);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026043400, 'local', 'learnpath');
    }

    if ($oldversion < 2026043500) {
        // v2026043500: Reminders overhaul, profile fix, cert preview, leaderboard save fix.
        // No schema changes — this block ensures PHP-only updates trigger the upgrade.

        // Ensure channel field in reminder_log is wide enough for combined values (e.g. 'email+inapp')
        $log_table = new xmldb_table('local_learnpath_reminder_log');
        if ($dbman->table_exists($log_table)) {
            $channel_field = new xmldb_field('channel', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'sent');
            if ($dbman->field_exists($log_table, $channel_field)) {
                $dbman->change_field_precision($log_table, $channel_field);
            }
        }

        upgrade_plugin_savepoint(true, 2026043500, 'local', 'learnpath');
    }

    if ($oldversion < 2026043600) {
        // v2026043600: Fix reminders page load, leaderboard sesskey crash,
        // schedule.php JS syntax, cert preview querySelector, xlsx email attachment.
        // Widen channel field to 50 chars if not already done.
        $log_table = new xmldb_table('local_learnpath_reminder_log');
        if ($dbman->table_exists($log_table)) {
            $field = new xmldb_field('channel', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'sent');
            if ($dbman->field_exists($log_table, $field)) {
                $dbman->change_field_precision($log_table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026043600, 'local', 'learnpath');
    }

    if ($oldversion < 2026043700) {
        // v2026043700: Fix branding missing global $DB, remove use statements from reminders.php.
        // No schema changes.
        upgrade_plugin_savepoint(true, 2026043700, 'local', 'learnpath');
    }

    if ($oldversion < 2026050100) {
        // v2026050100: Critical fixes — profile, reminders, branding pages loading.
        // Fix: global $DB added to branding.php, use-statement removed from reminders.php,
        // profile.php confirm_sesskey only on action. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050100, 'local', 'learnpath');
    }

    if ($oldversion < 2026050101) {
        // v2026050101: Mustache templates + AMD modules (Issue #4).
        // Added lib.php table-existence guards to prevent crash during fresh install.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050101, 'local', 'learnpath');
    }

    if ($oldversion < 2026050102) {
        // v2026050102: Fix cert live preview (CSP-safe via js_init_code),
        // fix reminders action handlers (try/catch prevents fatal Moodle errors).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050102, 'local', 'learnpath');
    }

    if ($oldversion < 2026050103) {
        // v1.0.0 (2026050103): Cert preview live updates (window.ltCPrev), leaderboard
        // top/bottom/all filter, auto-seed placeholder badges & criteria, cert ID format
        // setting (cert_id_prefix, cert_id_format) wired into profile.php.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050103, 'local', 'learnpath');
    }

    if ($oldversion < 2026050104) {
        // v1.0.0 (2026050104): Pagination in dashboard summary view, reminder history tab,
        // redirect to history after Send Now, managers.php admin assignment page,
        // leaderboard max-validation fix, version string cleanup across all pages.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050104, 'local', 'learnpath');
    }

    if ($oldversion < 2026050105) {
        // v1.0.0 (2026050105): Fix block showing paths to learners not assigned to them.
        // Block now checks local_learnpath_user_assign first; only falls back to course
        // enrollment for paths with no explicit assignments (backwards-compat).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050105, 'local', 'learnpath');
    }

    if ($oldversion < 2026050107) {
        // v1.0.0 (2026050107): Definitive fix for block/mypath showing removed paths.
        // Root cause: enrollment fallback ran whenever $paths_with_assign_set was empty
        // (e.g. after last learner removed from a path, or for paths with zero assignments).
        // Fix: when local_learnpath_user_assign table exists, explicit assignment is the
        // ONLY gate — no enrollment fallback under any circumstances. Enrollment fallback
        // only runs on pre-upgrade installs where the table doesn't exist yet.
        // Same fix applied to mypath.php (learner-facing path list).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050107, 'local', 'learnpath');
    }

    if ($oldversion < 2026050106) {
        // v1.0.0 (2026050106): Performance overhaul — eliminate N×M×5 query pattern.
        // get_progress_detail() now uses 4 bulk queries (was N×M×5).
        // get_progress_summary() reads progress cache first (was full detail recalc).
        // New get_user_path_progress() for block: one batch cache query for all groups.
        // Block membership checks batched (was N record_exists calls per group).
        // Removed per-row get_engagement_score() from dashboard render loop.
        // Static tbl_exists() cache eliminates repeated table_exists() calls.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050106, 'local', 'learnpath');
    }

    if ($oldversion < 2026050108) {
        // v1.0.0 (2026050108): Lasting fix for block showing wrong paths.
        //
        // ROOT CAUSE: paths created without using the explicit participant picker had zero
        // records in local_learnpath_user_assign. The block fell back to course enrollment,
        // so any learner enrolled in a course that happened to be in a path would see it.
        //
        // FIX: one-time data migration — seed local_learnpath_user_assign from current
        // course enrollment for every path that has no explicit assignments yet.
        // After this, user_assign is the authoritative gate for ALL paths on this site.
        // Block and mypath.php no longer have any enrollment fallback.
        if ($dbman->table_exists(new xmldb_table('local_learnpath_user_assign'))
            && $dbman->table_exists(new xmldb_table('local_learnpath_group_courses'))) {
            $paths = $DB->get_records('local_learnpath_groups', null, 'id ASC', 'id');
            $now   = time();
            foreach ($paths as $path) {
                // Only seed paths that have no explicit assignments yet
                if ($DB->record_exists('local_learnpath_user_assign', ['groupid' => $path->id])) {
                    continue;
                }
                $courseids = $DB->get_fieldset_select(
                    'local_learnpath_group_courses', 'courseid', 'groupid = :gid', ['gid' => $path->id]
                );
                if (empty($courseids)) {
                    continue;
                }
                list($cins, $cps) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
                $enrolled = $DB->get_records_sql(
                    "SELECT DISTINCT ue.userid
                     FROM {user_enrolments} ue
                     JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid {$cins}
                     JOIN {user} u  ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0",
                    $cps
                );
                foreach ($enrolled as $eu) {
                    if (!$DB->record_exists('local_learnpath_user_assign',
                            ['groupid' => $path->id, 'userid' => $eu->userid])) {
                        $DB->insert_record('local_learnpath_user_assign', (object)[
                            'groupid'     => $path->id,
                            'userid'      => (int)$eu->userid,
                            'assignedby'  => 0,
                            'timecreated' => $now,
                        ]);
                    }
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026050108, 'local', 'learnpath');
    }

    if ($oldversion < 2026050109) {
        // v1.0.0 (2026050109): Manager invite overhaul.
        // - invite-accept.php: new public page for accepting/rejecting invites (no admin cap required).
        // - manager-invite.php: in-app notification now points to invite-accept.php.
        //   Non-admin users landing on manager-invite.php with a token are redirected.
        //   Added Resend action for expired/revoked invites.
        // - Block: distinct "Paths I Manage" (purple, dashboard links) and "My Learning Paths"
        //   (learner progress) sections with clear visual separation.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050109, 'local', 'learnpath');
    }

    if ($oldversion < 2026050110) {
        // v1.0.0 (2026050110): Fix invite-accept redirect bug + cohort support.
        // invite-accept.php rewritten to use GET+sesskey links instead of POST form —
        // eliminates spurious browser replays triggered by Moodle notification redirects.
        // manager-invite.php: all token URLs now redirect to invite-accept.php (admins too).
        // learners.php: add_cohorts action + cohort picker UI with member counts,
        // searchable filter, select-all, and multi-cohort support.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050110, 'local', 'learnpath');
    }

    if ($oldversion < 2026050111) {
        // v1.0.0 (2026050111): Definitive invite-accept fix + reminder popup.
        // invite-accept.php: actions now POST-only with JS confirm guards; cache-control
        // headers prevent browser replay; GET requests are read-only (never trigger accept).
        // manager-invite.php: all token URLs redirect to invite-accept.php (including admins).
        // Accept notifies inviter via in-app; Decline notifies inviter and redirects to
        // dashboard with "owner has been informed" message.
        // Reminder popup: when Send Now is triggered, each matching learner receives a
        // user_preference 'lt_remind_popup'. On next login or page load, lib.php reads it,
        // clears it, and shows a popup with path name and "Continue Learning" button.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050111, 'local', 'learnpath');
    }

    if ($oldversion < 2026050112) {
        // v1.0.0 (2026050112): Rebuilt invite-accept.php from scratch.
        // The page now bypasses Moodle's $OUTPUT->header()/footer() entirely.
        // No AMD, no theme JS, no plugin hooks — just require_login() + pure HTML.
        // Actions (accept/reject) are POST-only with JS confirm guard + header() redirect.
        // This eliminates any possibility of auto-redirect from Moodle JS interference.
        upgrade_plugin_savepoint(true, 2026050112, 'local', 'learnpath');
    }

    if ($oldversion < 2026050113) {
        // v1.0.0 (2026050113): Three critical fixes.
        // 1. Permission: path managers (in local_learnpath_managers) get dashboard
        //    access via new local_learnpath_can_view_dashboard() helper in lib.php;
        //    index.php, overview.php, leaderboard.php now check the table alongside
        //    the Moodle capability so no Moodle role assignment is needed.
        // 2. Brand color: new local_learnpath_brand_css() helper computes
        //    --lt-primary-dark and --lt-primary-pale from the brand color; all pages
        //    now call this instead of the bare --lt-accent override. styles.css
        //    gradient no longer has hardcoded #1e40af.
        // 3. Logo persistence: cert_logo_path removed from branding.php $fields
        //    (it has no form input, so being in $fields caused it to be cleared on
        //    every Save). cert_logo_pos added to $fields so logo position actually
        //    saves. Logo file itself is safe in $CFG->dataroot/local_learnpath_logos/.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050113, 'local', 'learnpath');
    }

    if ($oldversion < 2026050114) {
        // v1.0.0 (2026050114): Five improvements.
        // 1. Brand color: all remaining hardcoded #1e40af and #3b82f6 in styles.css
        //    now use CSS variables (--lt-accent, --lt-primary-pale). Table headers
        //    use --lt-primary instead of hardcoded navy.
        // 2. Export: Excel now has three sheets — Summary, Per Course, Comparison —
        //    matching exactly what the dashboard shows. Comparison export added.
        //    Excel header row uses the admin's brand color instead of hardcoded navy.
        // 3. Manager permissions: admin can set scope per manager (View only /
        //    View+Reminders / Full access) via inline dropdown on manage.php.
        //    set_manager_scope action handles the update.
        // 4. Cohort in path creation: group_form.php now has a cohort multi-select
        //    in the participant section. manage.php save merges cohort members into
        //    local_learnpath_user_assign alongside individually selected users.
        // 5. Manager revocation UI improved: revoke button now shows user name.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050114, 'local', 'learnpath');
    }

    if ($oldversion < 2026050115) {
        // v1.0.0 (2026050115): Manager save fix + brand color + block redesign.
        // manage.php action param changed PARAM_ALPHA→PARAM_ALPHANUMEXT so underscored
        // actions (revoke_manager, set_manager_scope) are no longer stripped.
        // local_learnpath_brand_css() added to manage.php and all other pages that
        // were missing it (email, myprofile, schedule, welcome).
        // Block completely rebuilt: priority "Continue Learning" card, status badges
        // (On Track/At Risk/Overdue/Done), deadline awareness, last-active date,
        // collapsible path list (show 3, toggle rest), separate manager section,
        // quick actions, reminder notice, overall progress ring + course bar.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050115, 'local', 'learnpath');
    }

    if ($oldversion < 2026050117) {
        // v1.0.0 (2026050117): Guard get_user_preference() / set_user_preference() /
        // unset_user_preference() with function_exists() checks in lib.php, reminders.php,
        // and block. These Moodle functions are unavailable in early bootstrap and CLI
        // upgrade contexts, causing "Call to undefined function" fatal errors on install.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050117, 'local', 'learnpath');
    }

    if ($oldversion < 2026050118) {
        // v1.0.0 (2026050118): Email + color fixes.
        // notifier.php: removed duplicate salutation (build_html no longer adds "Dear name,"
        //   since default_message already includes it); fixed {groupname} → {{groupname}} in
        //   default subject so path name is replaced; URLs in email body now hyperlinked;
        //   build_html now uses email_signatory, email_sig_title, email_show_footer,
        //   email_footer_text_custom admin settings.
        // branding.php: added Email Appearance section (signatory, title, footer toggle,
        //   custom footer text) and Block Design Colours section (learner color, manager
        //   color with live mini-preview). Both saved to plugin config.
        // welcome.php: removed hardcoded #3b82f6/#1e40af; hero gradient uses CSS variables.
        // block: reads block_learner_color and block_manager_color from config; all manager
        //   section purple now uses --blk-mgr variable.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050118, 'local', 'learnpath');
    }

    if ($oldversion < 2026050119) {
        // v1.0.0 (2026050119): Email overhaul.
        // db/messages.php: learntrack_reminder email default changed to MESSAGE_PERMITTED only.
        // Clear ALL existing email-enabled preferences for learntrack_reminder so the new
        // popup-only default applies to every user going forward.
        $DB->delete_records_select('user_preferences',
            $DB->sql_like('name', ':n', false),
            ['n' => 'message_provider_local_learnpath_learntrack_%']
        );
        // notifier.php: removed duplicate sign-off from default_message(); build_html()
        //   now uses email_signature_html (rich HTML from branding) or falls back to
        //   plain text signatory. Table-based HTML layout for Outlook compatibility.
        //   URLs in body auto-hyperlinked. {{groupname}} fixed in default subject.
        // branding.php: rich signature editor (contenteditable + toolbar + image upload),
        //   email_replyto field, email_signature_html stored as PARAM_RAW.
        // signature_upload.php: new endpoint for uploading inline signature images.
        upgrade_plugin_savepoint(true, 2026050119, 'local', 'learnpath');
    }

    if ($oldversion < 2026050120) {
        // v1.0.0 (2026050120): Double email fix + category sync setting.
        // notifier.php: suppress_inapp_email() helper sets popup-only preference
        //   for each user before message_send() — prevents Moodle's email processor
        //   from firing a second plain-text email alongside our direct HTML email.
        //   Applied in: notifier.php send_inapp(), manager-invite.php (send + resend),
        //   index.php (enroll notification × 2).
        // Upgrade: clear ALL existing learntrack_reminder message preferences so new
        //   popup-only default applies to all existing users.
        // group_form.php: new 'auto_sync_courses' checkbox (visible only for category-type
        //   paths). When unchecked, saving the path keeps existing courses unchanged.
        // manage.php: honours auto_sync_courses flag; stores it on the DB record.
        // Add auto_sync_courses column to local_learnpath_groups.
        $table = new xmldb_table('local_learnpath_groups');
        $field = new xmldb_field('auto_sync_courses', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'adminnotes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Default existing category-type paths to auto_sync = 1
        $DB->execute("UPDATE {local_learnpath_groups} SET auto_sync_courses = 1 WHERE grouptype = 'category'");
        upgrade_plugin_savepoint(true, 2026050120, 'local', 'learnpath');
    }

    if ($oldversion < 2026050121) {
        // v1.0.0 (2026050121): Email delivery fix + navigation.
        // REPLACED suppress_inapp_email() with clone-user approach: send_inapp() now clones
        //   the user object and blanks email before passing to message_send(). Moodle's
        //   email processor checks userto->email and skips if blank; popup processor uses
        //   userto->id (unchanged) so bell notification still shows. No preferences touched.
        //   Applied in: notifier.php send_inapp(), manager-invite.php ×2, index.php ×2.
        // messages.php reverted to email=MESSAGE_DEFAULT_ENABLED so existing user prefs work.
        // lib.php extend_navigation(): added path manager nav node with per-path links;
        //   admin sees full menu (Welcome, Overview, Dashboard, Manage, Leaderboard,
        //   Course Insights, Branding, Managers); path manager sees Dashboard + Reminders
        //   + per-path quick links; learner sees My Learning Paths only.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050121, 'local', 'learnpath');
    }

    if ($oldversion < 2026050122) {
        // v1.0.0 (2026050122): Primary (top bar) navigation + notifier rebuild.
        // Added local_learnpath_extend_navigation_primary() to lib.php so the
        // plugin appears in Moodle 4.x top navigation bar (not just sidebar).
        // Visibility is role-aware: admin → welcome; path manager → dashboard;
        // learner → mypath.php (hidden when user has no assigned paths).
        // Also: notifier.php fully rebuilt — email sent when channel_email OR
        // channel_inapp is enabled; blank-email clone prevents double email.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050122, 'local', 'learnpath');
    }

    if ($oldversion < 2026050123) {
        // v1.0.0 (2026050123): Email delivery fix (switch to message_send) + nav hooks.
        // notifier.php: all delivery now uses message_send() with fullmessagehtml set
        //   to the full HTML template — Moodle's email processor sends the formatted
        //   email directly. Removed email_to_user() which was failing silently.
        //   Bell-only (channel_inapp without channel_email): blank-email clone so
        //   email processor skips but popup fires.
        // db/hooks.php + classes/hook/navigation_primary_listener.php: registers the
        //   \core\hook\navigation\primary_extend listener (Moodle 4.3+) which adds
        //   the plugin entry to the top navigation bar.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050123, 'local', 'learnpath');
    }

    if ($oldversion < 2026050124) {
        // v1.0.0 (2026050124): Restore email_to_user() for delivery.
        // SMTP confirmed working via Moodle test. email_to_user() bypasses user
        // notification preferences and always delivers via SMTP. message_send()
        // with blank-email clone handles bell-only (no duplicate email).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050124, 'local', 'learnpath');
    }

    if ($oldversion < 2026050125) {
        // v1.0.0 (2026050125): Email firewall fix + invite HTML + nav hook fix.
        // notifier.php: switched to message_send() for all delivery — adds anti-spam
        //   headers (Auto-Submitted, X-Moodle-*) that bypass corporate firewalls.
        //   HTML in fullmessagehtml is delivered as formatted email by Moodle processor.
        //   Bell-only still uses blank-email clone.
        // manager-invite.php: branded HTML template via notifier::build_invite_html()
        //   with proper "Hi [firstname]," salutation. Moodle users get message_send()
        //   (anti-spam headers); external emails get email_to_user() (no Moodle account).
        // db/hooks.php: string-based class refs prevent PHP fatal on class-not-found.
        // navigation_primary_listener.php: no type hint + multi-method probe for
        //   get_primarynav()/get_primary_nav()/get_primary() across Moodle 4.x versions.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050125, 'local', 'learnpath');
    }

    if ($oldversion < 2026050126) {
        // v1.0.0 (2026050126): Firewall-safe email template.
        // Replaced complex branded HTML (DOCTYPE, box-shadow, rgba fills, CTA button,
        // progress card) with body-only plain-structure HTML. Darktrace and similar
        // AI security gateways classify complex HTML from internal systems as
        // phishing/impersonation. New template uses: left-border brand accent,
        // inline progress text, plain underlined link (no button), "automated
        // notification" legitimacy footer. Same change applied to invite emails.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050126, 'local', 'learnpath');
    }

    if ($oldversion < 2026050127) {
        // v1.0.0 (2026050127): Darktrace-safe email — remove query-param URLs from body.
        // Key change: URLs with ?groupid=N are phishing-tracker signals to AI gateways.
        // Email body now only contains the site root URL (wwwroot). Deep link lives
        // in contexturl (bell notification only, not in email body).
        // default_body() no longer includes {{dashboardurl}} in the message text.
        // {{dashboardurl}} in custom templates now resolves to site root, not full path.
        // build_plain() produces properly formatted plain-text email with separator lines.
        // Subject default changed from "Reminder: Complete your learning" to
        // "Learning Path Update" (less alarm-like language for AI content filters).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050127, 'local', 'learnpath');
    }

    if ($oldversion < 2026050128) {
        // v1.0.0 (2026050128): Restore full branded email + user-settings nav.
        // Email: full branded HTML template restored (firewall whitelisted by admin).
        // nav: added extend_navigation_user_settings() — appears in top-right user
        //   dropdown, confirmed working in all Moodle 4.x. Primary nav callback
        //   type hint removed (was causing silent TypeError in Moodle 4.x).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050128, 'local', 'learnpath');
    }

    if ($oldversion < 2026050129) {
        // v1.0.0 (2026050129): Fix summary/per-course completion count mismatch.
        // Root cause: get_progress_summary() was reading from stale progress cache.
        // Cache stores completed_courses at last cron run — misses completions since
        // then and also has wrong total_courses when courses are added/removed.
        // Fix: get_progress_summary() now always calls _live_summary() which aggregates
        // from get_progress_detail() (same 4-bulk-query source as comparison view).
        // All four views — summary, per-course, comparison, individual — now read
        // from the same authoritative live data.
        // Also fixed: get_course_progress() completed_activities query now includes
        // cm.completion > 0 AND cm.deletioninprogress = 0 filters to be consistent
        // with the bulk query in get_progress_detail().
        // Also fixed: export date filter now checks timecompleted for completed courses
        // (previously only checked lastaccess, dropping completed courses accessed
        // before the filter start date).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050129, 'local', 'learnpath');
    }

    if ($oldversion < 2026050130) {
        // v1.0.0 (2026050130): Fix bulk-query row collision in get_progress_detail().
        // ROOT CAUSE: Moodle's get_records_sql() keys the result array by the first
        // selected column. With first column = userid (Bulk 1, 4) or courseid (Bulk 3),
        // rows for the SAME user in different courses overwrite each other. A learner
        // with 17 completions in 17 courses was left with only 1 in the cc_map.
        // FIX: Prefix every affected SELECT with a unique sql_concat(userid,'_',courseid)
        // rowkey as the first column — identical technique used by the comparison view
        // which already worked correctly. Bulk 2 was unaffected (GROUP BY course gives
        // one row per course, already unique).
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050130, 'local', 'learnpath');
    }

    if ($oldversion < 2026050131) {
        // v1.0.0 (2026050131): Fix automated reminder and scheduled-report delivery.
        // BUGS FIXED (cron confirmed running — all bugs were in plugin code):
        //
        // 1. tasks.php timing: send_scheduled_reports moved from 06:00 UTC to 14:00 UTC
        //    (15:00 WAT = 3 PM). send_reminders moved from 07:30 UTC to 09:00 UTC (10:00 WAT).
        //
        // 2. NULL nextrun ignored by send_scheduled_reports: condition was
        //    "nextrun <= :now" — SQL NULL comparison is always false, so new
        //    schedules with nextrun=NULL never fired. Fixed to "(nextrun IS NULL
        //    OR nextrun <= :now)".
        //
        // 3. nextrun drift: +1 week from cron execution time causes the trigger day
        //    to slowly drift because cron doesn't run at exactly the same second each
        //    time. Fixed: nextrun is now pinned to 08:30 UTC (reminders) / 13:30 UTC
        //    (reports), 30 min before the task's scheduled hour, so the cron always
        //    catches it on the correct weekday regardless of second-level variance.
        //
        // 4. schedule.php initial nextrun: was "now + 1 week" (random creation time).
        //    New weekly report schedules now target the next Friday at 13:30 UTC so
        //    the first automated send is that Friday afternoon (WAT = 15:00 = 3 PM).
        //
        // 5. reminders.php: edit no longer resets nextrun (preserves schedule anchor).
        //    New reminders use pinned nextrun instead of time().
        //
        // 6. nextrun advance moved outside try block in both tasks so a persistent
        //    error (e.g., bad group ID) doesn't retry on every cron run forever.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050131, 'local', 'learnpath');
    }

    if ($oldversion < 2026050132) {
        // v1.0.0 (2026050132): Fix automated task scheduling + history display.
        //
        // ROOT CAUSE of tasks never running: Moodle evaluates task cron expressions
        // in the SITE configured timezone (Site admin → Location), not UTC. Setting
        // hour=9 fires at 09:00 SITE-TIME which can be wildly different from WAT.
        // Fix: tasks now run every 30 min (reminders) / every hour (reports). The
        // internal nextrun timestamps in each table control actual delivery time,
        // making the schedule timezone-independent.
        //
        // SEND HISTORY: Previously showed one row per learner per channel (64+ rows
        // for a 32-learner path). Now the cron task inserts ONE batch-summary row
        // (userid=0) per reminder dispatch. History tab shows only those summary rows,
        // with a "X learners" count computed from the per-user rows in the same window.
        //
        // AUTO MANAGER WEEKLY REPORTS: send_scheduled_reports now automatically sends
        // each path's summary report to all its managers every Friday between 14:00
        // and 15:00 UTC (15:00-16:00 WAT = 3-4 PM Lagos). No manual schedule setup
        // required per path. De-duplicates within the week via email_log viewmode flag
        // 'auto_manager_weekly'.
        //
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050132, 'local', 'learnpath');
    }

    if ($oldversion < 2026050134) {
        // v1.0.0 (2026050134): Rework of reminder/report scheduling.
        //
        // ROOT CAUSE #1: Moodle only applies db/tasks.php's schedule on fresh
        // install — upgrading the plugin's code never changes an already-
        // installed task's cron cadence in {task_scheduled}. Sites that have
        // been through several LearnTrack versions could still be running a
        // stale pre-savepoint-132 cadence despite the code now saying "every
        // 30 minutes". Fix: force a resync of this plugin's task schedules to
        // the current db/tasks.php defaults on every upgrade through this step.
        if (class_exists('\core\task\manager')
                && method_exists('\core\task\manager', 'reset_scheduled_tasks_for_component')) {
            try {
                \core\task\manager::reset_scheduled_tasks_for_component('local_learnpath');
            } catch (\Throwable $e) {
                debugging('LearnTrack upgrade: task resync failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // ROOT CAUSE #2: "every 3 days" reminders were impossible — the
        // frequency enum only had once/daily/weekly/monthly. Add intervaldays
        // to support a custom "every N days" frequency ('interval').
        $rtable = new xmldb_table('local_learnpath_reminders');
        $rfield = new xmldb_field('intervaldays', XMLDB_TYPE_INTEGER, '10', null, false);
        if (!$dbman->field_exists($rtable, $rfield)) {
            $dbman->add_field($rtable, $rfield);
        }

        // ROOT CAUSE #3: the manager weekly report used a fragile one-hour
        // Friday-only window with no catch-up if cron missed it. Retire that
        // special case — a path's weekly manager report is now just a normal
        // local_learnpath_schedules row (recipients resolved dynamically at
        // send time), reusing the same robust nextrun<=now mechanism explicit
        // schedules already use.
        $stable = new xmldb_table('local_learnpath_schedules');
        $sfield1 = new xmldb_field('recipienttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
        if (!$dbman->field_exists($stable, $sfield1)) {
            $dbman->add_field($stable, $sfield1);
        }
        $sfield2 = new xmldb_field('ismanaged', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($stable, $sfield2)) {
            $dbman->add_field($stable, $sfield2);
        }

        // Data migration: give every existing path a managed weekly manager
        // report if it doesn't already have one. Idempotent — safe to re-run.
        $paths = $DB->get_records('local_learnpath_groups');
        foreach ($paths as $path) {
            $exists = $DB->record_exists('local_learnpath_schedules', [
                'groupid' => $path->id, 'ismanaged' => 1,
            ]);
            if ($exists) {
                continue;
            }
            $DB->insert_record('local_learnpath_schedules', (object)[
                'groupid'       => $path->id,
                'recipients'    => '',
                'recipienttype' => 'managers',
                'frequency'     => 'weekly',
                'format'        => 'xlsx',
                'viewmode'      => 'summary',
                'nextrun'       => \local_learnpath\task\send_scheduled_reports::first_nextrun('weekly'),
                'lastrun'       => null,
                'createdby'     => get_admin()->id,
                'timecreated'   => time(),
                'enabled'       => 1,
                'ismanaged'     => 1,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026050134, 'local', 'learnpath');
    }

    if ($oldversion < 2026050135) {
        // v1.0.0 (2026050135): Stop manage.php from silently deleting individual
        // learner assignments on every path edit.
        //
        // ROOT CAUSE: manage.php's save handler ran delete_records() on
        // local_learnpath_user_assign then re-inserted only from the edit form's
        // participant pickers — but classes/form/group_form.php caps that
        // picker's option list to the first `participant_cap` users (default
        // 500) ordered by lastname. A learner added via learners.php (or a
        // cohort, or simply sorted past the cap) would be missing from the
        // rendered options, so the browser could never resubmit them as
        // selected — and the very next path edit (for any unrelated reason)
        // deleted their assignment. The dashboard block and mypath.php were
        // reporting reality correctly; the row underneath had been wiped.
        //
        // FIX: manage.php's participant/cohort save is now additive-only
        // (insert-if-not-exists), matching learners.php's existing model.
        // group_form.php now also merges currently-assigned users into the
        // option list even if they're outside the cap, so the form displays
        // them correctly. Bulk removal is now an explicit "Remove All" action
        // on learners.php instead of an implicit side effect of any edit.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050135, 'local', 'learnpath');
    }

    return true;
}
