<?php
namespace local_learnpath\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for LearnTrack.
 *
 * LearnTrack stores the following personal data:
 * - Progress cache (per user per learning path group)
 * - Admin notes written about a user
 * - Certificate issuance records
 * - Reminder logs (which reminders were sent to which users)
 *
 * It reads (but does not store) data from Moodle core tables:
 * course_completions, grade_grades, logstore_standard_log, user_enrolments.
 *
 * @author  Michael Adeniran <michaeladeniransnr@gmail.com>
 * @license GNU GPL v3+
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    // ── Metadata ─────────────────────────────────────────────────────────────

    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_learnpath_progress_cache',
            [
                'userid'            => 'privacy:metadata:progress_cache:userid',
                'groupid'           => 'privacy:metadata:progress_cache:groupid',
                'completed_courses' => 'privacy:metadata:progress_cache:completed',
                'overall_progress'  => 'privacy:metadata:progress_cache:progress',
                'firstaccess'       => 'privacy:metadata:progress_cache:firstaccess',
                'lastaccess'        => 'privacy:metadata:progress_cache:lastaccess',
                'timeupdated'       => 'privacy:metadata:progress_cache:timeupdated',
            ],
            'privacy:metadata:progress_cache'
        );

        $collection->add_database_table(
            'local_learnpath_notes',
            [
                'userid'      => 'privacy:metadata:notes:userid',
                'authorid'    => 'privacy:metadata:notes:authorid',
                'note'        => 'privacy:metadata:notes:note',
                'timecreated' => 'privacy:metadata:notes:timecreated',
            ],
            'privacy:metadata:notes'
        );

        $collection->add_database_table(
            'local_learnpath_certs',
            [
                'userid'     => 'privacy:metadata:certs:userid',
                'issuedby'   => 'privacy:metadata:certs:issuedby',
                'issuedate'  => 'privacy:metadata:certs:issuedate',
                'certnumber' => 'privacy:metadata:certs:certnumber',
            ],
            'privacy:metadata:certs'
        );

        $collection->add_database_table(
            'local_learnpath_reminder_log',
            [
                'userid'   => 'privacy:metadata:reminder_log:userid',
                'channel'  => 'privacy:metadata:reminder_log:channel',
                'timesent' => 'privacy:metadata:reminder_log:timesent',
                'status'   => 'privacy:metadata:reminder_log:status',
            ],
            'privacy:metadata:reminder_log'
        );

        $collection->add_external_location_link(
            'email',
            ['email' => 'privacy:metadata:email:address'],
            'privacy:metadata:email'
        );


        $collection->add_database_table(
            'local_learnpath_email_log',
            [
                'groupid'     => 'privacy:metadata:email_log:groupid',
                'senderid'    => 'privacy:metadata:email_log:senderid',
                'recipients'  => 'privacy:metadata:email_log:recipients',
                'timesent'    => 'privacy:metadata:email_log:timesent',
            ],
            'privacy:metadata:email_log'
        );

        $collection->add_database_table(
            'local_learnpath_user_assign',
            ['userid' => 'privacy:metadata:user_assign:userid',
             'groupid' => 'privacy:metadata:user_assign:groupid'],
            'privacy:metadata:user_assign'
        );

        $collection->add_database_table(
            'local_learnpath_points',
            ['userid'  => 'privacy:metadata:points:userid',
             'points'  => 'privacy:metadata:points:points',
             'reason'  => 'privacy:metadata:points:reason'],
            'privacy:metadata:points'
        );

        $collection->add_database_table(
            'local_learnpath_user_badges',
            ['userid'   => 'privacy:metadata:user_badges:userid',
             'badgeid'  => 'privacy:metadata:user_badges:badgeid',
             'timeearned' => 'privacy:metadata:user_badges:timeearned'],
            'privacy:metadata:user_badges'
        );

        return $collection;
    }

    // ── Context list ──────────────────────────────────────────────────────────

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        // All stored data is at system context
        $contextlist->add_system_context();
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $sql = "SELECT DISTINCT userid FROM {local_learnpath_progress_cache}
                UNION
                SELECT DISTINCT userid FROM {local_learnpath_notes}
                UNION
                SELECT DISTINCT userid FROM {local_learnpath_certs}
                UNION
                SELECT DISTINCT userid FROM {local_learnpath_reminder_log}";
        $userlist->add_from_sql('userid', $sql, []);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }

            // Progress cache
            $cache_rows = $DB->get_records('local_learnpath_progress_cache', ['userid' => $userid]);
            if ($cache_rows) {
                writer::with_context($context)->export_data(
                    ['LearnTrack', 'Progress Cache'],
                    (object)['records' => array_values($cache_rows)]
                );
            }

            // Notes about this user
            $notes = $DB->get_records('local_learnpath_notes', ['userid' => $userid]);
            if ($notes) {
                writer::with_context($context)->export_data(
                    ['LearnTrack', 'Admin Notes'],
                    (object)['notes' => array_values($notes)]
                );
            }

            // Certificates
            $certs = $DB->get_records('local_learnpath_certs', ['userid' => $userid]);
            if ($certs) {
                writer::with_context($context)->export_data(
                    ['LearnTrack', 'Certificates'],
                    (object)['certificates' => array_values($certs)]
                );
            }

            // Reminder log
            $logs = $DB->get_records('local_learnpath_reminder_log', ['userid' => $userid]);
            if ($logs) {
                writer::with_context($context)->export_data(
                    ['LearnTrack', 'Reminder Log'],
                    (object)['reminders_sent' => array_values($logs)]
                );
            }
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('local_learnpath_progress_cache');
        $DB->delete_records('local_learnpath_notes');
        $DB->delete_records('local_learnpath_certs');
        $DB->delete_records('local_learnpath_reminder_log');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $DB->delete_records('local_learnpath_progress_cache', ['userid' => $userid]);
            $DB->delete_records('local_learnpath_notes',          ['userid' => $userid]);
            $DB->delete_records('local_learnpath_certs',          ['userid' => $userid]);
            $DB->delete_records('local_learnpath_reminder_log',   ['userid' => $userid]);
            $DB->delete_records('local_learnpath_user_assign',        ['userid' => $userid]);
            $DB->delete_records('local_learnpath_points',             ['userid' => $userid]);
            $DB->delete_records('local_learnpath_user_badges',        ['userid' => $userid]);
            $DB->delete_records('local_learnpath_email_log',          ['senderid' => $userid]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach (['local_learnpath_progress_cache','local_learnpath_notes','local_learnpath_certs','local_learnpath_reminder_log','local_learnpath_user_assign','local_learnpath_points','local_learnpath_user_badges'] as $table) {
            $DB->delete_records_select($table, "userid {$insql}", $params);
        }
    }
}
