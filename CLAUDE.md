# LearnTrack — Change Log (Scheduling Rework & Assignment Fix)

This documents fixes made to `local_learnpath` (LearnTrack): a rework of the
reminder/report scheduling system, a fix for a data-loss bug that caused assigned
learners to silently disappear from the "My Learning Paths" dashboard block, and a
manual "Run Now" control for scheduled tasks.

## 1. Reminder & scheduled-report rework

### Problem reported
- Learners were not reliably getting reminders about incomplete courses/paths on a
  recurring cadence. The requirement was a default of every 3 days, adjustable by the admin.
- Path managers were not getting their weekly auto-generated report as scheduled.

### Root causes found
1. **Stale cron schedule on upgrade.** Moodle only applies `db/tasks.php`'s schedule
   defaults on a fresh install. Upgrading the plugin's code never changed an
   already-installed task's cadence in Moodle's `{task_scheduled}` table. Two earlier
   fix attempts (savepoints `2026050131`, `2026050132`) changed the code's intended
   cadence but never forced Moodle to actually re-adopt it on upgrade.
2. **"Every 3 days" did not exist.** The reminder frequency enum only supported
   `once` / `daily` / `weekly` / `monthly` — a custom interval was never implemented.
3. **Fragile one-hour window for manager reports.** The weekly manager report only
   fired inside a hardcoded Friday 14:00–14:59 UTC window with no catch-up if cron
   was delayed or down during that hour — unlike explicit schedules, which already
   used a robust `nextrun <= now` check that self-heals.

### Changes made

**`local_learnpath/db/install.xml`**
- Added `intervaldays` (int) to `local_learnpath_reminders`.
- Added `recipienttype` (char, default `manual`) and `ismanaged` (int, default `0`)
  to `local_learnpath_schedules`.

**`local_learnpath/db/upgrade.php`** (new savepoint `2026050134`)
- Forces a resync of all three LearnTrack scheduled tasks to the current
  `db/tasks.php` cadence via `\core\task\manager::reset_scheduled_tasks_for_component()`,
  so upgrading sites actually pick up the fixed schedule instead of keeping a stale one.
- Adds the two schema fields above.
- Data migration: back-fills a managed weekly manager-report schedule
  (`ismanaged = 1`, `recipienttype = 'managers'`) for every existing learning path
  that doesn't already have one.

**`local_learnpath/classes/task/send_scheduled_reports.php`**
- Removed `maybe_send_manager_weekly_reports()` and the Friday-window special case
  entirely.
- The main loop now treats every row in `local_learnpath_schedules` the same way
  (`nextrun <= now`, with automatic catch-up). Rows with `recipienttype = 'managers'`
  resolve their recipient list live from `local_learnpath_managers` at send time
  (new `get_manager_emails()` helper), so manager membership changes take effect on
  the very next send.

**`local_learnpath/classes/task/send_reminders.php`**
- `calc_next_run()` now accepts `$intervaldays` and supports a new `interval`
  frequency (`+N days`).
- Added `first_nextrun()`: recurring reminders become eligible on the very next
  cron tick when created, instead of waiting a full period before their first send.
  `once` reminders still park 10 years out on purpose — they are manual
  "Send Now" only and must never be auto-picked up by cron.

**`local_learnpath/reminders.php`**
- Add/Edit rule form: frequency dropdown now includes "Every N days (custom)" with
  a day-count input, defaulting from the new admin settings. `once` is labeled
  "manual send only" so its non-automatic behavior is explicit.
- New rules now call `first_nextrun()` instead of `calc_next_run()`.
- Rule list shows "Every N days" for interval rules.

**`local_learnpath/manage.php`**
- New learning paths automatically get a managed weekly manager-report schedule at
  creation time (mirrors the upgrade migration for existing paths).

**`local_learnpath/schedule.php`** + **`local_learnpath/templates/schedule_list_item.mustache`**
- Managed (`ismanaged = 1`) schedule rows show an "Auto · Managers" badge and can be
  paused/resumed but not deleted (server-side guard added, not just UI).
- Recipients column shows "All current path managers" for managed rows.

**`local_learnpath/settings.php`**
- New "Reminders" section: `reminder_default_frequency` (daily / interval / weekly /
  monthly, default `interval`) and `reminder_default_interval_days` (default `3`).

**`local_learnpath/welcome.php`**
- New "🩺 Cron & Delivery Health" panel (admin-only): last/next run and status for
  each of the three LearnTrack scheduled tasks (read from Moodle's
  `{task_scheduled}` table), plus counts of reminders/schedules overdue by more
  than 2 hours — so a stalled cron is visible at a glance instead of requiring log
  spelunking.

---

## 2. Fix: assigned learners invisible in the dashboard block

### Problem reported
Learners added to a learning path could not see it in the "My Learning Paths"
block on their Moodle dashboard.

### Root cause
`local_learnpath_user_assign` (who is individually assigned to a path) had two
writers with incompatible assumptions:

- `learners.php` (Manage Individual Learners) was **additive**: insert if not
  already present, delete only via an explicit per-user "Remove".
- `manage.php`'s path save handler was **destructive-and-authoritative**: on every
  save (create *or* edit), it deleted **all** `local_learnpath_user_assign` rows for
  that path and re-inserted only the users/cohorts present in that submission's form
  fields.

That would have been harmless if the form always round-tripped the complete current
assignment list — but `classes/form/group_form.php` capped the "Add Participants"
picker's option list to the first `participant_cap` users (default/max 500, sorted
by lastname). A Moodle `autocomplete`/`select multiple` element can only submit a
value as selected if it exists as a defined option. So a learner added via
`learners.php` (or a cohort, or simply sorted past the cap on a larger site) would be
missing from the rendered picker — and the very next time anyone edited that path for
*any* reason (even changing the deadline), the delete-then-reinsert wiped their
assignment for good. The dashboard block was reporting the data correctly; the row
underneath it had been silently deleted.

### Changes made

**`local_learnpath/manage.php`**
- Removed the `delete_records()` call on `local_learnpath_user_assign` from the path
  save handler. Participant/cohort saves are now insert-if-not-exists (additive),
  matching `learners.php`'s existing model. Saving the path form no longer removes
  anyone, regardless of what is or isn't shown as selected in the picker.
- Path deletion now also cleans up `local_learnpath_user_assign` rows for that path
  (was previously missed, leaving orphaned rows).

**`local_learnpath/classes/form/group_form.php`**
- The "Add Participants" option list now also includes any user currently assigned
  to the path (via `local_learnpath_user_assign`) even if they fall outside the
  `participant_cap` cutoff, so the form displays current state honestly.

**`local_learnpath/learners.php`**
- Added an explicit, confirmed "Remove All" action/button so bulk-clearing a path's
  individual assignments is still possible — as a deliberate action, not an implicit
  side effect of an unrelated edit.

**`local_learnpath/db/upgrade.php`** (new savepoint `2026050135`)
- Documents the fix. No schema changes.

---

## 3. Manual "Run Now" for scheduled tasks

### Problem reported
All scheduled tasks (not just LearnTrack's) were showing "Never run" in the Cron &
Delivery Health panel — indicating site cron itself has likely never executed on this
server (an infrastructure/hosting issue, not a LearnTrack bug). Requested: a button in
the diagnostics panel to force a task to run immediately, without freezing the page
while it runs.

### Changes made

**`local_learnpath/welcome.php`**
- Added a small JSON endpoint at the top of the page (before any HTML output),
  triggered by `lt_run_task=<classname>`, gated to `moodle/site:config` (stricter than
  the rest of the page — this executes real core Moodle scheduled-task code with real
  side effects, e.g. sending due reminders/reports, not just LearnTrack data).
  - Validates the classname against a hardcoded allow-list of the 3 LearnTrack tasks
    (never instantiates an arbitrary class from user input).
  - A plugin-config-based lock (`manual_run_lock_<hash>`, 2-minute TTL) prevents a
    double-click or an overlapping real cron run from executing the same task twice
    concurrently.
  - Calls `\core_php_time_limit::raise()` (falls back to `set_time_limit(0)` if that
    class isn't present) before executing, since a first manual run could have a large
    overdue backlog to process.
  - Runs the task via `\core\task\manager::get_scheduled_task($classname)->execute()`,
    capturing `mtrace()` output via output buffering.
  - Afterwards, writes `lastruntime`/`nextruntime`/`faildelay` on the `{task_scheduled}`
    row itself (via `$task->get_next_scheduled_time()`), mirroring what Moodle's real
    cron runner records, so both the diagnostics panel and future automatic runs stay
    correct.
- Added a "▶ Run Now" button per task row (visible only to `moodle/site:config` users).
  Wired via `fetch()` (POST, with sesskey) instead of a page reload/redirect — the
  clicked button shows "⏳ Running…" and all Run Now buttons are disabled for the
  duration of that one request, but the rest of the page stays interactive and nothing
  reloads. Only that task's Last Run / Next Run cells update in place when the request
  resolves, and a status line shows the captured output.

### Note
This button treats the *symptom* (LearnTrack reminders not firing) but the real
underlying problem — if literally every scheduled task on the site shows "Never run" —
is that server-side cron (e.g. `php admin/cli/cron.php` on a crontab, or the
host's equivalent) has never been configured to run at all. That's a server
configuration issue outside this plugin; "Run Now" is a working diagnostic/manual
workaround, not a substitute for real site cron running periodically.

---

## 4. Completion tracker didn't match Moodle's own numbers

### Problem reported
A course Moodle's own report showed as 15 users completed, LearnTrack showed 13.
Separately, Moodle showed 3 users at 90%, LearnTrack showed 5. Requested: resolved
once and for all, mirroring Moodle's own activity-completion numbers exactly.

### Root cause
Every "percent complete" calculation in the plugin counted **all** completion-tracked
activities (`course_modules` with `completion > 0`) as the denominator. Moodle's own
Course Completion report instead divides by `course_completion_criteria` — the
specific conditions a teacher actually configured under Course Settings → Completion
tracking, which can be a *subset* of tracked activities (or a grade/self/date
criterion entirely). A course can have more completion-tracked activities than are
actually required for completion, so the plugin's numbers diverged from Moodle's own
in both directions — matching exactly what was reported.

This logic had been copy-pasted rather than shared, so it existed independently in
**eight places**: `classes/data/helper.php`'s `get_course_progress()`,
`get_progress_detail()`, the `get_user_path_progress()` cache-miss fallback,
`get_popular_courses()`, and `get_engagement_score()`; `index.php`'s Comparison tab;
`courseinsights.php`; and `classes/export/manager.php::export_course()`.

### Changes made

**`local_learnpath/classes/data/helper.php`**
- Added two new shared functions that are now the single source of truth:
  - `get_completion_totals_bulk(array $courseids)` — for each course, if it has any
    rows in `course_completion_criteria`, total = that count (matches Moodle's real
    denominator, whatever mix of criteria types are configured). Falls back to
    counting completion-tracked activities only for courses with zero criteria
    configured (nothing better to mirror in that case).
  - `get_completion_done_bulk(array $courseids, array $userids, array $totals_map, int $from_ts = 0, int $to_ts = 0)`
    — for each (user, course), counts `course_completion_crit_compl` rows with
    `timecompleted` set for criteria-mode courses (exactly what Moodle counts as
    "criteria met"), or `course_modules_completion` for activities-fallback courses.
    Optional date range for callers that need period filtering.
  - Both are plain bulk `IN (...)` + `GROUP BY` SQL, same shape as the plugin's
    existing bulk-query pattern — no per-user Moodle API object construction, so this
    doesn't reintroduce the N×M×5 query cost previously fixed.
- `get_course_progress()` and `get_progress_detail()` rewritten to call these two
  functions instead of their inline SQL. Return shape unchanged
  (`total_activities`/`completed_activities`/`progress`/`status` fields the same), so
  every caller of these two (mypath.php, profile.php, myprofile.php, leaderboard.php,
  export summary/detail, refresh_progress_cache cron) is fixed for free.
- `get_user_path_progress()`'s fallback, `get_popular_courses()`, and
  `get_engagement_score()` each rewritten to call the same two shared functions
  instead of their own independent copies of the old logic.

**`local_learnpath/index.php`**
- The Comparison view's per-course/per-learner totals now come from
  `helper::get_completion_totals_bulk()` / `get_completion_done_bulk()` instead of
  inline `course_modules`/`course_modules_completion` queries.

**`local_learnpath/courseinsights.php`**
- The activity totals/distribution chart now use the same two shared functions,
  including the period-filter (`$from_ts`/`$to_ts`) support so the existing "filter by
  date range" feature keeps working with the corrected denominator.

**`local_learnpath/classes/export/manager.php`**
- `export_course()`'s per-learner activity totals now come from the same shared
  functions instead of its own duplicate query.

**`local_learnpath/db/upgrade.php`** (new savepoint `2026050136`)
- Documents the fix. No schema changes.

### Note
`course_completions` and `course_completion_crit_compl` are themselves populated by
Moodle's own completion cron (`core\task\completion_regular_task`). Since every
scheduled task on this site showed "Never run" before the fixes in section 3, Moodle's
own completion data could also have been stale, independent of this fix. This change
makes LearnTrack exactly as accurate as Moodle's own report — but if the underlying
source data itself was stale from cron never running, expect it to catch up once cron
actually executes (via "Run Now" or real site cron).

---

## 5. Reliability fix — completion calc can no longer crash a cron task

### Problem reported
After 2026050136 shipped, real site cron started actually executing (a separate,
positive development — "Moodle mails is working as other schedule tasks are working
fine"), but all three LearnTrack tasks then showed **"Failing (retry delay 30720s)"**
in Cron & Delivery Health. A manual "Run Now" on Reminders reported success, but the
Status badge still showed "Failing" afterward — two different problems tangled
together.

### Root cause
Two separate bugs:

1. **Real bug**: the two new completion-calculation functions added in `2026050136`
   (`get_completion_totals_bulk()` / `get_completion_done_bulk()`) had their own risky
   queries (against `course_completion_criteria` / `course_completion_crit_compl`)
   wrapped in try/catch — but every *caller* of those two functions had no protection
   of its own. So if anything unexpected happened (schema variance, unusual data,
   etc.), the failure propagated all the way out of a scheduled task's `execute()` and
   Moodle's cron runner marked the task "Failing" with escalating exponential backoff.
   The manual "Run Now" test on Reminders happened to hit the "no reminders due" fast
   path and returned *before* ever calling the new completion code, so it reported
   success without actually exercising (or proving safe) the code that was failing for
   real cron on the other two tasks, which always exercise it.
2. **Cosmetic bug**: the "Run Now" button's JS only updated the Last Run/Next Run
   cells, never the Status cell — so even a genuinely successful manual run left the
   stale "Failing" badge on screen until a full page reload.

### Changes made

**`local_learnpath/classes/data/helper.php`**
- Every call site of `get_completion_totals_bulk()`/`get_completion_done_bulk()`
  (`get_course_progress()`, `get_progress_detail()`, the `get_user_path_progress()`
  fallback, `get_popular_courses()`, `get_engagement_score()`) now wraps the call in
  its own try/catch, degrading to 0/0 for that row/course and logging via
  `debugging()` on failure — never able to crash the calling task again.

**`local_learnpath/index.php`, `local_learnpath/courseinsights.php`, `local_learnpath/classes/export/manager.php`**
- Same defensive try/catch wrapping applied to their own direct calls to the two
  shared functions, for consistency (these run in web-page context, lower urgency
  than the cron paths, but same fix).

**`local_learnpath/welcome.php`**
- Extracted `local_learnpath_task_status_html()` — one function used by both the
  table render and the "Run Now" JSON response, so they can never disagree.
- The JSON response now re-reads `{task_scheduled}` after running and returns fresh
  status HTML; the JS now updates the Status cell in place (not just Last/Next Run),
  so a successful run immediately shows "OK" without a page reload.

**`local_learnpath/db/upgrade.php`** (new savepoint `2026050137`)
- Documents the fix. No schema changes.

### Still to confirm
This makes the *symptom* (crashing) impossible, but the specific exception behind the
original "Failing" status on Scheduled reports / Progress cache refresh hasn't been
directly observed yet — those two were never manually run before this fix. After
deploying, click "Run Now" on each and check the output message; if either still
reports an error, that message now pinpoints the real cause precisely instead of
guessing further.

---

## Version history

| Version | Change |
|---|---|
| `2026050134` | Reminder/report scheduling rework (interval frequency, managed weekly schedules, task resync, diagnostics) |
| `2026050135` | Additive-only `local_learnpath_user_assign` writes; fixes silent learner-assignment loss |
| `2026050136` | Manual "Run Now" button for scheduled tasks; completion tracker rewritten to mirror Moodle's own criteria-based numbers |
| `2026050137` | Reliability fix — completion calc can no longer crash a cron task; Run Now status badge refreshes correctly |

## Post-deploy checklist
1. Run the site upgrade (Site Administration → Notifications) to apply the savepoints.
2. Check Site Administration → Server → Tasks → Scheduled tasks — confirm the three
   LearnTrack tasks show the current cadence (every 30 min / hourly / every 4 hours).
3. Check the new "🩺 Cron & Delivery Health" panel on the LearnTrack Welcome page.
4. Add a learner via Manage Individual Learners, then edit that path's deadline via
   Manage Paths — confirm the learner still appears in their dashboard block afterward.
5. As a site admin, try "▶ Run Now" on each task and confirm both Last Run *and*
   Status update without a page reload. Separately, arrange for real server cron to
   run `admin/cli/cron.php` periodically (e.g. every minute via crontab) if it isn't
   already.
6. Open the same course's Moodle Course Completion report side-by-side with
   LearnTrack's dashboard for that course and confirm the completed-count and
   percentages now match.
7. Click "Run Now" on "Scheduled reports" and "Progress cache refresh" specifically —
   these were the two showing "Failing" and were never manually exercised before this
   fix. If either still reports an error message, that pinpoints the exact remaining
   bug to fix next.
