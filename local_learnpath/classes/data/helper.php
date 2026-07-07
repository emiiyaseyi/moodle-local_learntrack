<?php
namespace local_learnpath\data;

defined('MOODLE_INTERNAL') || die();

// gradelib is loaded inside get_course_progress() where it is needed.
// It is NOT loaded at file level because that crashes Moodle's autoloader
// when the class is first resolved before config.php has populated $CFG.

/**
 * LearnTrack data helper — all data access goes through here.
 * Uses Moodle DML API only (MySQL + PostgreSQL compatible).
 * PHP 8.1+ compatible. No raw database-specific SQL functions.
 *
 * @author  Michael Adeniran
 * @license GNU GPL v3+
 */
class helper {

    // ── TABLE-EXISTS CACHE ────────────────────────────────────────────────────
    private static array $tbl_cache = [];

    private static function tbl_exists(string $table): bool {
        if (!array_key_exists($table, self::$tbl_cache)) {
            global $DB;
            self::$tbl_cache[$table] = $DB->get_manager()->table_exists(new \xmldb_table($table));
        }
        return self::$tbl_cache[$table];
    }

    // ── GROUPS ────────────────────────────────────────────────────────────────

    public static function get_groups(int $userid = 0): array {
        global $DB, $USER;
        $uid = $userid ?: (int)$USER->id;
        $ctx = \context_system::instance();
        // Admins/managers with full manage cap see all paths
        if (\has_capability('local/learnpath:manage', $ctx, $uid)) {
            return $DB->get_records('local_learnpath_groups', null, 'name ASC');
        }
        // Teachers/managers scoped to assigned paths only
        if (\has_capability('local/learnpath:viewdashboard', $ctx, $uid)) {
            $assigned = $DB->get_fieldset_select(
                'local_learnpath_managers', 'groupid', 'userid = :uid', ['uid' => $uid]
            );
            if (!empty($assigned)) {
                list($in, $params) = $DB->get_in_or_equal($assigned, SQL_PARAMS_NAMED);
                return $DB->get_records_select('local_learnpath_groups', "id $in", $params, 'name ASC');
            }
        }
        return $DB->get_records('local_learnpath_groups', null, 'name ASC');
    }

    public static function get_group(int $id): ?object {
        global $DB;
        return $DB->get_record('local_learnpath_groups', ['id' => $id]) ?: null;
    }

    public static function get_group_with_courses(int $groupid): ?object {
        global $DB;
        $group = $DB->get_record('local_learnpath_groups', ['id' => $groupid]);
        if (!$group) {
            return null;
        }
        $group->courses = self::get_group_courses($groupid);
        return $group;
    }

    public static function get_group_courses(int $groupid): array {
        global $DB;
        $sql = "SELECT c.id, c.fullname, c.shortname, lgc.sortorder
                FROM {course} c
                JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = c.id
                WHERE lgc.groupid = :groupid
                ORDER BY lgc.sortorder ASC, c.fullname ASC";
        return $DB->get_records_sql($sql, ['groupid' => $groupid]);
    }

    // ── LEARNERS ──────────────────────────────────────────────────────────────

    /**
     * Get learners enrolled in at least one course in the group.
     * Respects user_status: active | suspended | inactive | all
     * Safe against missing local_learnpath_user_assign table (pre-upgrade).
     */
    public static function get_learners_for_group(
        int    $groupid,
        int    $viewerid,
        string $user_status = 'active'
    ): array {
        global $DB;

        $courses = self::get_group_courses($groupid);
        if (empty($courses)) {
            return [];
        }

        $courseids = array_keys($courses);
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');

        // Build suspended/deleted clause
        $suspended_clause = '';
        if ($user_status === 'active' || $user_status === 'inactive') {
            $suspended_clause = ' AND u.suspended = 0';
        } elseif ($user_status === 'suspended') {
            $suspended_clause = ' AND u.suspended = 1';
        }

        // Fetch enrolled learners
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
                FROM {user} u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid {$insql}
                WHERE u.deleted = 0{$suspended_clause}
                ORDER BY u.lastname ASC, u.firstname ASC";

        $learners = $DB->get_records_sql($sql, $params);

        // Check for individually assigned users
        if (self::tbl_exists('local_learnpath_user_assign')) {
            $assigned_count = $DB->count_records('local_learnpath_user_assign', ['groupid' => $groupid]);
            if ($assigned_count > 0) {
                // Path has explicit user selection — RESTRICT to only those users
                $sql2 = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
                         FROM {user} u
                         JOIN {local_learnpath_user_assign} ua ON ua.userid = u.id
                         WHERE ua.groupid = :assign_gid
                           AND u.deleted = 0{$suspended_clause}
                         ORDER BY u.lastname ASC, u.firstname ASC";
                return $DB->get_records_sql($sql2, ['assign_gid' => $groupid]);
            }
        }

        // Post-filter: inactive = no course access within threshold
        if ($user_status === 'inactive') {
            $inactive_days = (int)\get_config('local_learnpath', 'inactive_days');
            if ($inactive_days <= 0) {
                return []; // not configured — return nothing
            }
            $cutoff   = time() - ($inactive_days * 86400);
            $filtered = [];
            foreach ($learners as $uid => $learner) {
                list($in2, $p2) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ic');
                $last = $DB->get_field_sql(
                    "SELECT MAX(timecreated) FROM {logstore_standard_log}
                     WHERE userid = :uid AND courseid {$in2}",
                    array_merge(['uid' => $uid], $p2)
                );
                if (!$last || (int)$last < $cutoff) {
                    $filtered[$uid] = $learner;
                }
            }
            return $filtered;
        }

        return $learners;
    }

    // ── COMPLETION (single source of truth — mirrors Moodle's own numbers) ─────

    /**
     * For each course, the "must-do" denominator for a completion percentage.
     *
     * Mirrors what Moodle's own Course Completion report divides by: when a
     * course has completion criteria configured (course_completion_criteria —
     * whatever mix of activity/grade/self/date conditions a teacher actually
     * selected under Course Settings → Completion tracking), that count is
     * authoritative. A course can have more completion-TRACKED activities than
     * are actually REQUIRED for course completion, so counting every tracked
     * activity (the old behaviour) diverges from Moodle whenever criteria are a
     * subset. Falls back to counting completion-tracked activities only for
     * courses with no criteria configured at all (nothing better to mirror).
     *
     * @return array [courseid => ['total' => int, 'source' => 'criteria'|'activities']]
     */
    public static function get_completion_totals_bulk(array $courseids): array {
        global $DB;
        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        if (empty($courseids)) {
            return [];
        }

        $result = [];
        foreach ($courseids as $cid) {
            $result[$cid] = ['total' => 0, 'source' => 'activities'];
        }

        list($cins, $cps) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ctot');
        try {
            foreach ($DB->get_records_sql(
                "SELECT course AS courseid, COUNT(id) AS cnt
                 FROM {course_completion_criteria}
                 WHERE course $cins
                 GROUP BY course",
                $cps
            ) as $r) {
                if ((int)$r->cnt > 0) {
                    $result[(int)$r->courseid] = ['total' => (int)$r->cnt, 'source' => 'criteria'];
                }
            }
        } catch (\Throwable $e) {
            // course_completion_criteria always exists on supported Moodle
            // versions, but stay defensive rather than fatal.
        }

        // Fallback for courses with no criteria configured.
        $fallback_cids = array_keys(array_filter($result, fn($v) => $v['source'] === 'activities'));
        if (!empty($fallback_cids)) {
            list($fins, $fps) = $DB->get_in_or_equal($fallback_cids, SQL_PARAMS_NAMED, 'ctotfb');
            foreach ($DB->get_records_sql(
                "SELECT course AS courseid, COUNT(id) AS cnt
                 FROM {course_modules}
                 WHERE course $fins AND completion > 0 AND deletioninprogress = 0
                 GROUP BY course",
                $fps
            ) as $r) {
                $result[(int)$r->courseid]['total'] = (int)$r->cnt;
            }
        }

        return $result;
    }

    /**
     * For each (user, course), how many of the "must-do" items are done —
     * paired with get_completion_totals_bulk(). Courses using criteria are
     * counted from course_completion_crit_compl (exactly what Moodle counts as
     * "criteria met"); courses on the activities fallback are counted from
     * course_modules_completion, same as before.
     *
     * @param array $totals_map Output of get_completion_totals_bulk() for the same $courseids.
     * @param int $from_ts Optional — only count completions on/after this timestamp.
     * @param int $to_ts Optional — only count completions on/before this timestamp.
     * @return array [userid => [courseid => int]]
     */
    public static function get_completion_done_bulk(
        array $courseids,
        array $userids,
        array $totals_map,
        int   $from_ts = 0,
        int   $to_ts = 0
    ): array {
        global $DB;
        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        $userids   = array_values(array_unique(array_map('intval', $userids)));
        if (empty($courseids) || empty($userids)) {
            return [];
        }

        list($uins, $ups) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cdu');

        $criteria_cids = [];
        $activity_cids = [];
        foreach ($courseids as $cid) {
            if (($totals_map[$cid]['source'] ?? 'activities') === 'criteria') {
                $criteria_cids[] = $cid;
            } else {
                $activity_cids[] = $cid;
            }
        }

        $done_map = [];

        if (!empty($criteria_cids)) {
            list($cins, $cps) = $DB->get_in_or_equal($criteria_cids, SQL_PARAMS_NAMED, 'cdc');
            $params    = array_merge($cps, $ups);
            $date_sql  = '';
            if ($from_ts > 0) {
                $date_sql = ' AND timecompleted >= :cdfrom AND timecompleted <= :cdto';
                $params['cdfrom'] = $from_ts;
                $params['cdto']   = $to_ts;
            }
            try {
                foreach ($DB->get_records_sql(
                    "SELECT " . $DB->sql_concat('userid', "'_'", 'course') . " AS rowkey,
                            userid, course AS courseid, COUNT(id) AS cnt
                     FROM {course_completion_crit_compl}
                     WHERE course $cins AND userid $uins AND timecompleted IS NOT NULL{$date_sql}
                     GROUP BY course, userid",
                    $params
                ) as $r) {
                    $done_map[(int)$r->userid][(int)$r->courseid] = (int)$r->cnt;
                }
            } catch (\Throwable $e) {
                // Stay defensive; falls through with 0s for these courses.
            }
        }

        if (!empty($activity_cids)) {
            list($ains, $aps) = $DB->get_in_or_equal($activity_cids, SQL_PARAMS_NAMED, 'cda');
            $params   = array_merge($aps, $ups);
            $date_sql = '';
            if ($from_ts > 0) {
                $date_sql = ' AND cmc.timemodified >= :cdafrom AND cmc.timemodified <= :cdato';
                $params['cdafrom'] = $from_ts;
                $params['cdato']   = $to_ts;
            }
            foreach ($DB->get_records_sql(
                "SELECT " . $DB->sql_concat('cmc.userid', "'_'", 'cm.course') . " AS rowkey,
                        cm.course AS courseid, cmc.userid, COUNT(cmc.id) AS cnt
                 FROM {course_modules_completion} cmc
                 JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                 WHERE cm.course $ains AND cmc.userid $uins
                   AND cm.completion > 0 AND cm.deletioninprogress = 0
                   AND cmc.completionstate IN (1,2){$date_sql}
                 GROUP BY cm.course, cmc.userid",
                $params
            ) as $r) {
                $done_map[(int)$r->userid][(int)$r->courseid] = (int)$r->cnt;
            }
        }

        return $done_map;
    }

    // ── PROGRESS CALCULATION ──────────────────────────────────────────────────

    /**
     * Calculate progress for a single learner in a single course.
     * Returns a stdClass with all progress fields.
     */
    public static function get_course_progress(int $userid, int $courseid): object {
        global $DB;

        $row = new \stdClass();
        $row->courseid = $courseid;
        $row->userid   = $userid;

        // Formal completion record
        $completion = $DB->get_record('course_completions', [
            'userid' => $userid,
            'course' => $courseid,
        ]);
        $row->completed     = !empty($completion->timecompleted);
        $row->timecompleted = $completion->timecompleted ?? null;

        // First / last access from log — DML-safe aggregation
        $log_sql = "SELECT MIN(timecreated) AS firstaccess, MAX(timecreated) AS lastaccess
                    FROM {logstore_standard_log}
                    WHERE userid = :uid AND courseid = :cid AND action = 'viewed'";
        $logdata = $DB->get_record_sql($log_sql, ['uid' => $userid, 'cid' => $courseid]);
        $row->firstaccess = $logdata->firstaccess ?? null;
        $row->lastaccess  = $logdata->lastaccess  ?? null;

        // "Must-do" totals/done — mirrors Moodle's own completion criteria when
        // configured, falling back to completion-tracked activities otherwise.
        // Never allowed to crash the caller (this runs inside cron tasks) —
        // any unexpected failure here degrades to 0/0 for this row instead of
        // taking down the whole task run.
        $row->total_activities     = 0;
        $row->completed_activities = 0;
        try {
            $totals_map = self::get_completion_totals_bulk([$courseid]);
            $row->total_activities = $totals_map[$courseid]['total'] ?? 0;
            $done_map = self::get_completion_done_bulk([$courseid], [$userid], $totals_map);
            $row->completed_activities = $done_map[$userid][$courseid] ?? 0;
        } catch (\Throwable $e) {
            \debugging('LearnTrack get_course_progress(): completion calc failed for '
                . "course={$courseid} user={$userid}: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // ── Progress % — definitive rules ──────────────────────────────────
        // Rule 1: course_completions.timecompleted set → 100%
        // Rule 2: completed_activities >= total_activities (e.g. 5/5) → 100%
        // Rule 3: proportional otherwise, capped at 99% until formally complete
        if ($row->completed) {
            $row->progress = 100;
        } elseif ($row->total_activities > 0 && $row->completed_activities >= $row->total_activities) {
            $row->progress  = 100;
            $row->completed = true;
        } elseif ($row->total_activities > 0) {
            $pct = (int)round(($row->completed_activities / $row->total_activities) * 100);
            $row->progress = min($pct, 99);
        } else {
            $row->progress = 0;
        }

        // ── Grade — load gradelib inside method with full safety ────────────
        // require_once here (not at file level) so it never runs during autoloading.
        // The \grade_get_course_grade() backslash prefix resolves to global namespace.
        $grade_info = null;
        try {
            global $CFG;
            require_once($CFG->libdir . '/gradelib.php');
            $grade_info = \grade_get_course_grade($userid, $courseid);
        } catch (\Throwable $e) {
            $grade_info = null;
        }
        $row->grade    = ($grade_info && $grade_info->grade !== null)
                         ? round((float)$grade_info->grade, 1) : null;
        $row->maxgrade = ($grade_info && isset($grade_info->item->grademax))
                         ? round((float)$grade_info->item->grademax, 1) : null;

        // ── Status ──────────────────────────────────────────────────────────
        if ($row->completed || $row->progress === 100) {
            $row->status   = 'complete';
            $row->progress = 100;
        } elseif ($row->progress > 0 || $row->firstaccess) {
            $row->status = 'inprogress';
        } else {
            $row->status = 'notstarted';
        }

        return $row;
    }

    /**
     * Get full per-course detail for all learners in a group.
     * Uses 4 bulk queries instead of N×M×5 individual queries.
     */
    public static function get_progress_detail(
        int    $groupid,
        int    $viewerid,
        string $user_status = 'active'
    ): array {
        global $DB;

        $courses  = self::get_group_courses($groupid);
        $learners = self::get_learners_for_group($groupid, $viewerid, $user_status);

        if (empty($courses) || empty($learners)) {
            return [];
        }

        $courseids  = array_keys($courses);
        $learnerids = array_keys($learners);

        list($cins, $cps) = $DB->get_in_or_equal($courseids,  SQL_PARAMS_NAMED, 'c');
        list($uins, $ups) = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'u');
        $cup = array_merge($cps, $ups);

        // ── Bulk queries — IMPORTANT: all SELECT lists begin with a UNIQUE rowkey ──
        // Moodle's get_records_sql() keys the result array by the FIRST COLUMN.
        // Without a unique first column, rows with the same first value overwrite
        // each other: a learner with 17 completions in 17 courses keeps only 1.
        // The unique rowkey (userid_courseid concat) prevents this.

        // Bulk 1: formal course completions
        $cc_map = [];
        foreach ($DB->get_records_sql(
            "SELECT " . $DB->sql_concat('cc.userid', "'_'", 'cc.course') . " AS rowkey,
                    cc.userid, cc.course AS courseid, cc.timecompleted
             FROM {course_completions} cc
             WHERE cc.course $cins AND cc.userid $uins AND cc.timecompleted > 0",
            $cup
        ) as $r) {
            $cc_map[(int)$r->userid][(int)$r->courseid] = (int)$r->timecompleted;
        }

        // Bulk 2+3: "must-do" totals and per-user done counts — mirrors Moodle's
        // own completion criteria when configured, falling back to
        // completion-tracked activities otherwise (see get_completion_totals_bulk()
        // / get_completion_done_bulk()). Never allowed to crash the caller (this
        // runs inside cron tasks) — falls back to empty maps (0s everywhere)
        // instead of taking down the whole task run.
        $totals_map = [];
        $done_map   = [];
        try {
            $totals_map = self::get_completion_totals_bulk($courseids);
            $done_map   = self::get_completion_done_bulk($courseids, $learnerids, $totals_map);
        } catch (\Throwable $e) {
            \debugging('LearnTrack get_progress_detail(): completion calc failed for '
                . "group={$groupid}: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Bulk 4: first/last access from log (last 365 days for performance)
        $log_map = [];
        try {
            $log_cutoff = time() - (365 * 86400);
            foreach ($DB->get_records_sql(
                "SELECT " . $DB->sql_concat('userid', "'_'", 'courseid') . " AS rowkey,
                        userid, courseid,
                        MIN(timecreated) AS firstaccess, MAX(timecreated) AS lastaccess
                 FROM {logstore_standard_log}
                 WHERE courseid $cins AND userid $uins
                   AND action = 'viewed' AND timecreated > :lcut
                 GROUP BY userid, courseid",
                array_merge($cup, ['lcut' => $log_cutoff])
            ) as $r) {
                $log_map[(int)$r->userid][(int)$r->courseid] = $r;
            }
        } catch (\Throwable $e) {
            // logstore may be disabled; silently skip
        }

        // Build result rows from pre-fetched maps — zero additional queries
        $rows = [];
        foreach ($learners as $learner) {
            foreach ($courses as $course) {
                $uid = (int)$learner->id;
                $cid = (int)$course->id;

                $row              = new \stdClass();
                $row->userid      = $uid;
                $row->firstname   = $learner->firstname;
                $row->lastname    = $learner->lastname;
                $row->email       = $learner->email;
                $row->username    = $learner->username;
                $row->courseid    = $cid;
                $row->coursename  = $course->fullname;
                $row->timecompleted        = $cc_map[$uid][$cid] ?? null;
                $row->completed            = (bool)$row->timecompleted;
                $row->total_activities     = $totals_map[$cid]['total'] ?? 0;
                $row->completed_activities = $done_map[$uid][$cid] ?? 0;
                $log              = $log_map[$uid][$cid] ?? null;
                $row->firstaccess = $log ? (int)$log->firstaccess : null;
                $row->lastaccess  = $log ? (int)$log->lastaccess  : null;
                $row->grade       = null;
                $row->maxgrade    = null;

                // Progress
                if ($row->completed) {
                    $row->progress = 100;
                } elseif ($row->total_activities > 0 && $row->completed_activities >= $row->total_activities) {
                    $row->progress  = 100;
                    $row->completed = true;
                } elseif ($row->total_activities > 0) {
                    $row->progress = min((int)round($row->completed_activities / $row->total_activities * 100), 99);
                } else {
                    $row->progress = 0;
                }

                // Status
                if ($row->completed || $row->progress === 100) {
                    $row->status   = 'complete';
                    $row->progress = 100;
                } elseif ($row->progress > 0 || $row->firstaccess) {
                    $row->status = 'inprogress';
                } else {
                    $row->status = 'notstarted';
                }

                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Summarise progress: one row per learner.
     *
     * Always uses the live bulk calculation via get_progress_detail() so that
     * summary, per-course, and comparison views all derive from the same
     * authoritative data source. The progress cache had two problems:
     *   1. Stale completed_courses / total_courses when courses are added or
     *      completions happen between cron runs.
     *   2. inprogress/notstarted were computed as (total - completed) / 0,
     *      which was wrong whenever some courses were genuinely not started.
     *
     * The cache is still updated by cron and still used by the block widget
     * (get_user_path_progress). It is no longer used here.
     */
    public static function get_progress_summary(
        int    $groupid,
        int    $viewerid,
        string $user_status = 'active'
    ): array {
        return self::_live_summary($groupid, $viewerid, $user_status);
    }

    /**
     * Live summary calculation (fallback when cache is empty/unavailable).
     */
    private static function _live_summary(int $groupid, int $viewerid, string $user_status): array {
        $detail  = self::get_progress_detail($groupid, $viewerid, $user_status);
        $courses = self::get_group_courses($groupid);
        $total   = count($courses);

        $summary = [];
        foreach ($detail as $row) {
            $uid = $row->userid;
            if (!isset($summary[$uid])) {
                $summary[$uid] = (object)[
                    'userid'             => $uid,
                    'firstname'          => $row->firstname,
                    'lastname'           => $row->lastname,
                    'email'              => $row->email,
                    'username'           => $row->username,
                    'total_courses'      => $total,
                    'completed_courses'  => 0,
                    'inprogress_courses' => 0,
                    'notstarted_courses' => 0,
                    'overall_progress'   => 0,
                    'sum_course_progress'=> 0,   // accumulates per-course activity %
                    'firstaccess'        => null,
                    'lastaccess'         => null,
                ];
            }
            $s = $summary[$uid];
            if ($row->status === 'complete')   { $s->completed_courses++; }
            if ($row->status === 'inprogress') { $s->inprogress_courses++; }
            if ($row->status === 'notstarted') { $s->notstarted_courses++; }
            $s->sum_course_progress += (int)($row->progress ?? 0);
            if ($row->firstaccess && (!$s->firstaccess || $row->firstaccess < $s->firstaccess)) {
                $s->firstaccess = $row->firstaccess;
            }
            if ($row->lastaccess && (!$s->lastaccess || $row->lastaccess > $s->lastaccess)) {
                $s->lastaccess = $row->lastaccess;
            }
        }
        foreach ($summary as $s) {
            if ($s->completed_courses >= $s->total_courses && $s->total_courses > 0) {
                // All courses formally complete
                $s->overall_progress = 100;
            } elseif ($s->completed_courses > 0) {
                // Mix: some formally complete, some not — count-based %
                $s->overall_progress = (int)round(($s->completed_courses / $s->total_courses) * 100);
            } elseif ($s->total_courses > 0) {
                // No courses formally complete yet — use average activity-level progress.
                // This fixes single-course paths (and early multi-course) showing 0%
                // when learners have done activity work but not triggered formal completion.
                $s->overall_progress = min(
                    (int)round($s->sum_course_progress / $s->total_courses),
                    99  // cap at 99% — 100% requires formal course completion
                );
            }
        }
        return array_values($summary);
    }

    /**
     * Get progress for a single user across multiple groups.
     * Used by the block — one cache query instead of full N×M detail per group.
     */
    public static function get_user_path_progress(int $userid, array $groupids): array {
        global $DB;
        if (empty($groupids)) return [];

        // Fast path: batch cache lookup
        if (self::tbl_exists('local_learnpath_progress_cache')) {
            list($gidin, $gidps) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
            $rows = $DB->get_records_sql(
                "SELECT groupid, completed_courses, total_courses, overall_progress,
                        firstaccess, lastaccess
                 FROM {local_learnpath_progress_cache}
                 WHERE userid = :uid AND groupid $gidin",
                array_merge(['uid' => $userid], $gidps)
            );
            if (!empty($rows)) {
                $result = [];
                foreach ($rows as $r) { $result[(int)$r->groupid] = $r; }
                return $result;
            }
        }

        // Fallback: per-group bulk query for this user only (3 queries per group)
        $result = [];
        foreach ($groupids as $gid) {
            $courses = self::get_group_courses($gid);
            if (empty($courses)) {
                $result[$gid] = (object)['completed_courses'=>0,'total_courses'=>0,'overall_progress'=>0];
                continue;
            }
            $courseids = array_keys($courses);
            list($cins, $cps) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

            $cc_done = $DB->get_fieldset_sql(
                "SELECT course FROM {course_completions}
                 WHERE course $cins AND userid = :uid AND timecompleted > 0",
                array_merge($cps, ['uid' => $userid])
            );
            $cc_set = array_flip($cc_done);

            // "Must-do" totals/done — mirrors Moodle's own completion criteria
            // when configured, falling back to completion-tracked activities.
            // Never allowed to crash the caller (block widget) — falls back to
            // empty maps (0s) on any unexpected failure.
            $totals_map = [];
            $done_map   = [];
            try {
                $totals_map = self::get_completion_totals_bulk($courseids);
                $done_map   = self::get_completion_done_bulk($courseids, [$userid], $totals_map);
            } catch (\Throwable $e) {
                \debugging('LearnTrack get_user_path_progress(): completion calc failed for '
                    . "group={$gid} user={$userid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            }

            $done = 0; $tot = count($courses); $sum_act = 0;
            foreach ($courses as $c) {
                $cid = (int)$c->id;
                $ta  = $totals_map[$cid]['total'] ?? 0;
                $da  = $done_map[$userid][$cid] ?? 0;
                $is_complete = isset($cc_set[$cid]) || ($ta > 0 && $da >= $ta);
                if ($is_complete) {
                    $done++;
                    $sum_act += 100;
                } elseif ($ta > 0) {
                    $sum_act += (int)round($da / $ta * 100);
                }
            }
            // Activity-aware progress — same logic as mypath.php and refresh_cache()
            if ($tot === 0) {
                $pct = 0;
            } elseif ($done >= $tot) {
                $pct = 100;
            } elseif ($done > 0) {
                $pct = (int)round($done / $tot * 100);
            } else {
                $pct = min((int)round($sum_act / $tot), 99);
            }
            $result[$gid] = (object)[
                'completed_courses' => $done,
                'total_courses'     => $tot,
                'overall_progress'  => $pct,
            ];
        }
        return $result;
    }

    // ── CACHE ─────────────────────────────────────────────────────────────────

    /**
     * Refresh the progress cache for a group.
     * Called by cron task and after significant events.
     */
    public static function refresh_cache(int $groupid): void {
        global $DB;

        $learners = self::get_learners_for_group($groupid, get_admin()->id, 'active');
        $courses  = self::get_group_courses($groupid);
        $total    = count($courses);
        $now      = time();

        foreach ($learners as $learner) {
            $completed   = 0;
            $sum_pct     = 0;
            $firstaccess = null;
            $lastaccess  = null;

            foreach ($courses as $course) {
                $p = self::get_course_progress($learner->id, $course->id);
                if ($p->status === 'complete') {
                    $completed++;
                }
                $sum_pct += (int)($p->progress ?? 0);
                if ($p->firstaccess && (!$firstaccess || $p->firstaccess < $firstaccess)) {
                    $firstaccess = $p->firstaccess;
                }
                if ($p->lastaccess && (!$lastaccess || $p->lastaccess > $lastaccess)) {
                    $lastaccess = $p->lastaccess;
                }
            }

            // Activity-aware progress: when no courses are formally complete,
            // use average activity-level progress (capped at 99%) so the block
            // shows meaningful progress for single-course paths / early learners.
            if ($total === 0) {
                $pct = 0;
            } elseif ($completed >= $total) {
                $pct = 100;
            } elseif ($completed > 0) {
                $pct = (int)round($completed / $total * 100);
            } else {
                $pct = min((int)round($sum_pct / $total), 99);
            }

            $existing = $DB->get_record('local_learnpath_progress_cache', [
                'groupid' => $groupid,
                'userid'  => $learner->id,
            ]);

            $record = (object)[
                'groupid'           => $groupid,
                'userid'            => $learner->id,
                'completed_courses' => $completed,
                'total_courses'     => $total,
                'overall_progress'  => $pct,
                'firstaccess'       => $firstaccess,
                'lastaccess'        => $lastaccess,
                'timeupdated'       => $now,
            ];

            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('local_learnpath_progress_cache', $record);
            } else {
                $DB->insert_record('local_learnpath_progress_cache', $record);
            }
        }
    }

    // ── SITE-WIDE STATS (DML-safe, DB-agnostic) ───────────────────────────────

    public static function get_site_stats(int $from_ts = 0, int $to_ts = 0): object {
        global $DB;
        $dbman = $DB->get_manager();

        if ($to_ts === 0) {
            $to_ts = time();
        }

        $stats = new \stdClass();

        // These are always safe — direct count_records, no JOINs
        $stats->total_paths        = $DB->count_records('local_learnpath_groups');
        $stats->total_course_links = $DB->count_records('local_learnpath_group_courses');

        // Total unique learners across path courses — JOIN query, may be 0 if no paths
        $stats->total_learners = 0;
        if ($stats->total_course_links > 0) {
            $sql = "SELECT COUNT(DISTINCT ue.userid) AS cnt
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = e.courseid
                    JOIN {user} u ON u.id = ue.userid
                    WHERE u.deleted = 0 AND u.suspended = 0";
            $row = $DB->get_record_sql($sql);
            $stats->total_learners = $row ? (int)$row->cnt : 0;
        }

        // Completions — filtered by date when a range is selected
        $date_where  = '';
        $date_params = [];
        if ($from_ts > 0) {
            $date_where  = ' AND cc.timecompleted >= :from AND cc.timecompleted <= :to';
            $date_params = ['from' => $from_ts, 'to' => $to_ts];
        }
        $sql = "SELECT COUNT(cc.id) AS cnt
                FROM {course_completions} cc
                JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cc.course
                WHERE cc.timecompleted > 0{$date_where}";
        $row = $DB->get_record_sql($sql, $date_params);
        $stats->total_completions = $row ? (int)$row->cnt : 0;

        // Month-over-month trend (always based on calendar months, not the filter)
        $month_start      = mktime(0, 0, 0, (int)date('n'), 1);
        $last_month_start = mktime(0, 0, 0, (int)date('n') - 1, 1);
        $last_month_end   = $month_start - 1;

        $sql = "SELECT COUNT(cc.id) AS cnt FROM {course_completions} cc
                JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cc.course
                WHERE cc.timecompleted >= :from AND cc.timecompleted > 0";
        $row = $DB->get_record_sql($sql, ['from' => $month_start]);
        $stats->this_month_completions = $row ? (int)$row->cnt : 0;

        $sql = "SELECT COUNT(cc.id) AS cnt FROM {course_completions} cc
                JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cc.course
                WHERE cc.timecompleted >= :from AND cc.timecompleted <= :to AND cc.timecompleted > 0";
        $row = $DB->get_record_sql($sql, ['from' => $last_month_start, 'to' => $last_month_end]);
        $stats->last_month_completions = $row ? (int)$row->cnt : 0;

        $stats->completion_trend = $stats->last_month_completions > 0
            ? (int)round(($stats->this_month_completions - $stats->last_month_completions) / $stats->last_month_completions * 100)
            : ($stats->this_month_completions > 0 ? 100 : 0);

        // Avg progress from cache — safe check (table may not exist on old installs)
        $stats->avg_progress = null;
        if (self::tbl_exists('local_learnpath_progress_cache')) {
            $row = $DB->get_record_sql("SELECT AVG(overall_progress) AS avg_pct FROM {local_learnpath_progress_cache}");
            $stats->avg_progress = ($row && $row->avg_pct !== null) ? (int)round((float)$row->avg_pct) : null;
        }

        return $stats;
    }

    /**
     * Get popular courses ordered by enrolment count.
     * Shows ALL TIME data regardless of date filter.
     * Fixed: separate subquery for completions avoids JOIN collision.
     */
    public static function get_popular_courses(int $limit = 20): array {
        global $DB;

        // Guard: if group_courses table missing, return empty
        if (!self::tbl_exists('local_learnpath_group_courses')) {
            return [];
        }

        // Step 1: get enrolled counts per course (all time)
        $sql_enrol = "SELECT e.courseid,
                             COUNT(DISTINCT ue.userid) AS enrolled
                      FROM {enrol} e
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id
                      JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                      JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = e.courseid
                      GROUP BY e.courseid";
        $enrol_rows = $DB->get_records_sql($sql_enrol);

        if (empty($enrol_rows)) {
            return [];
        }

        $courseids = array_keys($enrol_rows);

        // Step 2: count learners who have completed each course — mirrors
        // Moodle's own completion criteria when configured, falling back to
        // completion-tracked activities otherwise (see
        // get_completion_totals_bulk()/get_completion_done_bulk()). Matches
        // what courseinsights.php shows.
        list($peins, $peps) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'pce');
        $enrolled_uids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ue.userid
             FROM {enrol} e
             JOIN {user_enrolments} ue ON ue.enrolid = e.id
             JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
             WHERE e.courseid $peins",
            $peps
        );

        $totals_map = [];
        $done_map   = [];
        try {
            $totals_map = self::get_completion_totals_bulk($courseids);
            $done_map   = !empty($enrolled_uids)
                ? self::get_completion_done_bulk($courseids, $enrolled_uids, $totals_map)
                : [];
        } catch (\Throwable $e) {
            \debugging('LearnTrack get_popular_courses(): completion calc failed: '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Invert to [courseid][userid] = done count for the per-course loop below.
        $done_idx = [];
        foreach ($done_map as $uid => $bycourse) {
            foreach ($bycourse as $cid => $cnt) {
                $done_idx[$cid][$uid] = $cnt;
            }
        }

        // Also get course_completions as an authoritative completion signal
        // (catches courses completed via a criteria type unrelated to activities).
        $sql_cc = "SELECT cc.course AS courseid, cc.userid
                    FROM {course_completions} cc
                    JOIN {enrol} e ON e.courseid = cc.course
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = cc.userid
                    JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cc.course
                    WHERE cc.timecompleted > 0";
        $cc_rows = $DB->get_records_sql($sql_cc);
        $cc_set  = [];
        foreach ($cc_rows as $ccr) { $cc_set[$ccr->courseid][$ccr->userid] = true; }

        // For each course: count users who met the "must-do" total OR have a
        // formal course_completions record.
        $compl_rows = [];
        foreach ($courseids as $cid) {
            $total = $totals_map[$cid]['total'] ?? 0;
            $count = 0;
            $all_users = array_unique(array_merge(
                array_keys($done_idx[$cid] ?? []),
                array_keys($cc_set[$cid]  ?? [])
            ));
            foreach ($all_users as $uid) {
                $done = $done_idx[$cid][$uid] ?? 0;
                if (!empty($cc_set[$cid][$uid]) || ($total > 0 && $done >= $total)) {
                    $count++;
                }
            }
            $row = new \stdClass();
            $row->courseid  = $cid;
            $row->completed = $count;
            $compl_rows[$cid] = $row;
        }

        // Step 3: get course names
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'pc');
        $courses_info = $DB->get_records_sql(
            "SELECT id, fullname, shortname FROM {course} WHERE id {$insql}",
            $params
        );

        // Step 4: merge and build result
        $records = [];
        foreach ($enrol_rows as $courseid => $erow) {
            if (!isset($courses_info[$courseid])) {
                continue;
            }
            $course   = $courses_info[$courseid];
            $enrolled = (int)$erow->enrolled;
            $completed = isset($compl_rows[$courseid]) ? (int)$compl_rows[$courseid]->completed : 0;

            $r = new \stdClass();
            $r->id              = $courseid;
            $r->fullname        = $course->fullname;
            $r->shortname       = $course->shortname;
            $r->enrolled        = $enrolled;
            $r->completed       = $completed;
            $r->completion_rate = $enrolled > 0 ? (int)round($completed / $enrolled * 100) : 0;
            $records[$courseid] = $r;
        }

        // Sort by enrolled desc
        uasort($records, function($a, $b) { return $b->enrolled <=> $a->enrolled; });

        return array_slice($records, 0, $limit, true);
    }

    /**
     * Get at-risk learners: enrolled, 0% progress, no access in N days.
     */
    public static function get_at_risk_learners(int $days = 7, int $limit = 10): array {
        global $DB;

        if (!self::tbl_exists('local_learnpath_progress_cache')) {
            return [];
        }

        $cutoff = time() - ($days * 86400);

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email,
                       lpc.overall_progress, lpc.lastaccess, lpc.groupid
                FROM {local_learnpath_progress_cache} lpc
                JOIN {user} u ON u.id = lpc.userid
                WHERE lpc.overall_progress = 0
                  AND u.deleted = 0
                  AND (lpc.lastaccess IS NULL OR lpc.lastaccess < :cutoff)
                ORDER BY lpc.lastaccess ASC";

        return $DB->get_records_sql($sql, ['cutoff' => $cutoff], 0, $limit);
    }

    /**
     * Get top learners by completion count.
     */
    public static function get_top_learners(int $from_ts = 0, int $limit = 5): array {
        global $DB;

        // Count distinct courses where user has module completions
        // Date filter goes in WHERE clause (not ON clause) for compatibility
        $date_where  = '';
        $date_params = [];
        if ($from_ts > 0) {
            $date_where  = ' AND cmc.timemodified >= :from';
            $date_params = ['from' => $from_ts];
        }

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email,
                       COUNT(DISTINCT cm.course) AS completions
                FROM {user} u
                JOIN {course_modules_completion} cmc ON cmc.userid = u.id
                JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cm.course
                WHERE cmc.completionstate IN (1,2)
                  AND cm.completion > 0
                  AND cm.deletioninprogress = 0
                  AND u.deleted = 0
                  AND u.suspended = 0{$date_where}
                GROUP BY u.id, u.firstname, u.lastname, u.email
                ORDER BY completions DESC";

        $result = $DB->get_records_sql($sql, $date_params, 0, $limit);
        if (!empty($result)) {
            return $result;
        }

        // Fallback: course_completions.timecompleted
        $where  = $from_ts ? " AND cc.timecompleted >= :from" : "";
        $params = $from_ts ? ['from' => $from_ts] : [];
        $sql2 = "SELECT u.id, u.firstname, u.lastname, u.email,
                        COUNT(cc.id) AS completions
                 FROM {course_completions} cc
                 JOIN {user} u ON u.id = cc.userid
                 JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cc.course
                 WHERE cc.timecompleted > 0 AND u.deleted = 0{$where}
                 GROUP BY u.id, u.firstname, u.lastname, u.email
                 ORDER BY completions DESC";
        return $DB->get_records_sql($sql2, $params, 0, $limit);
    }

    /**
     * Get recent completions feed.
     */
    public static function get_recent_activity(int $limit = 15): array {
        global $DB;

        // Use course_completions with timecompleted
        $sql = "SELECT cc.id, u.firstname, u.lastname, c.fullname AS coursename,
                       cc.timecompleted, lgc.groupid
                FROM {course_completions} cc
                JOIN {user} u ON u.id = cc.userid
                JOIN {course} c ON c.id = cc.course
                JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cc.course
                WHERE cc.timecompleted > 0 AND u.deleted = 0
                ORDER BY cc.timecompleted DESC, cc.id DESC";

        return $DB->get_records_sql($sql, [], 0, $limit);
    }

    /**
     * Get completions per day for a period (DML-safe — fetches records, groups in PHP).
     */
    public static function get_daily_completions(int $from_ts, int $to_ts): array {
        global $DB;

        // Only include completions with an actual timecompleted date (can be charted)
        $sql = "SELECT cc.timecompleted
                FROM {course_completions} cc
                JOIN {local_learnpath_group_courses} lgc ON lgc.courseid = cc.course
                WHERE cc.timecompleted > 0
                  AND cc.timecompleted >= :from AND cc.timecompleted <= :to";

        $records = $DB->get_records_sql($sql, ['from' => $from_ts, 'to' => $to_ts]);

        $by_day = [];
        foreach ($records as $r) {
            $day = date('Y-m-d', (int)$r->timecompleted);
            if (!isset($by_day[$day])) {
                $by_day[$day] = 0;
            }
            $by_day[$day]++;
        }
        ksort($by_day);
        return $by_day;
    }

    // ── MANAGERS ──────────────────────────────────────────────────────────────

    public static function get_group_managers(int $groupid): array {
        global $DB;
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, lpm.scope
                FROM {local_learnpath_managers} lpm
                JOIN {user} u ON u.id = lpm.userid
                WHERE lpm.groupid = :groupid AND u.deleted = 0";
        return $DB->get_records_sql($sql, ['groupid' => $groupid]);
    }

    public static function get_manager_groups(int $userid): array {
        global $DB;
        $sql = "SELECT lpm.groupid, lpm.scope, lpg.name
                FROM {local_learnpath_managers} lpm
                JOIN {local_learnpath_groups} lpg ON lpg.id = lpm.groupid
                WHERE lpm.userid = :uid";
        return $DB->get_records_sql($sql, ['uid' => $userid]);
    }

    public static function is_manager_of_group(int $userid, int $groupid): bool {
        global $DB;
        return $DB->record_exists('local_learnpath_managers', [
            'userid'  => $userid,
            'groupid' => $groupid,
        ]);
    }
    public static function get_engagement_score(int $userid, int $groupid): int {
        global $DB;
        $courses = $DB->get_records('local_learnpath_group_courses', ['groupid' => $groupid]);
        if (empty($courses)) return 0;

        $courseids = array_values(array_map(fn($lgc) => (int)$lgc->courseid, $courses));
        // "Must-do" totals/done — mirrors Moodle's own completion criteria when
        // configured, falling back to completion-tracked activities otherwise.
        $totals_map = [];
        $done_map   = [];
        try {
            $totals_map = self::get_completion_totals_bulk($courseids);
            $done_map   = self::get_completion_done_bulk($courseids, [$userid], $totals_map);
        } catch (\Throwable $e) {
            \debugging('LearnTrack get_engagement_score(): completion calc failed for '
                . "group={$groupid} user={$userid}: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $progress_total = 0; $act_total = 0; $act_done = 0;
        $grade_sum = 0; $grade_count = 0;
        foreach ($courseids as $cid) {
            $total_acts = $totals_map[$cid]['total'] ?? 0;
            $done_acts  = $done_map[$userid][$cid] ?? 0;
            $act_total    += $total_acts;
            $act_done     += $done_acts;
            $progress_total += $total_acts > 0 ? ($done_acts / $total_acts * 100) : 0;
            $gr = $DB->get_record_sql(
                "SELECT gg.finalgrade, gi.grademax FROM {grade_grades} gg
                 JOIN {grade_items} gi ON gi.id=gg.itemid AND gi.itemtype='course' AND gi.courseid=:cid
                 WHERE gg.userid=:uid AND gg.finalgrade IS NOT NULL",
                ['cid' => $cid, 'uid' => $userid]);
            if ($gr && $gr->grademax > 0) {
                $grade_sum += $gr->finalgrade / $gr->grademax * 100;
                $grade_count++;
            }
        }
        $n = count($courses);
        $avg_progress = $n > 0 ? $progress_total / $n : 0;
        $act_pct      = $act_total > 0 ? ($act_done / $act_total * 100) : 0;
        $avg_grade    = $grade_count > 0 ? ($grade_sum / $grade_count) : $avg_progress;
        $last = $DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {logstore_standard_log}
             WHERE userid=:uid AND courseid IN (SELECT courseid FROM {local_learnpath_group_courses} WHERE groupid=:gid)",
            ['uid' => $userid, 'gid' => $groupid]);
        $days_ago = $last ? max(0, (time() - (int)$last) / 86400) : 999;
        $recency  = $days_ago <= 7 ? 100 : ($days_ago <= 30 ? 50 : 10);
        return min(100, max(0, (int)round($avg_progress*0.35 + $act_pct*0.35 + $avg_grade*0.20 + $recency*0.10)));
    }

}
