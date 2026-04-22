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

    return true;
}
