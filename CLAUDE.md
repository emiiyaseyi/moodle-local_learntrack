# LearnTrack — Change Log (Scheduling Rework & Assignment Fix)

This documents two rounds of fixes made to `local_learnpath` (LearnTrack): a rework of the
reminder/report scheduling system, and a fix for a data-loss bug that caused assigned
learners to silently disappear from the "My Learning Paths" dashboard block.

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

## Version history

| Version | Change |
|---|---|
| `2026050134` | Reminder/report scheduling rework (interval frequency, managed weekly schedules, task resync, diagnostics) |
| `2026050135` | Additive-only `local_learnpath_user_assign` writes; fixes silent learner-assignment loss |

## Post-deploy checklist
1. Run the site upgrade (Site Administration → Notifications) to apply both savepoints.
2. Check Site Administration → Server → Tasks → Scheduled tasks — confirm the three
   LearnTrack tasks show the current cadence (every 30 min / hourly / every 4 hours).
3. Check the new "🩺 Cron & Delivery Health" panel on the LearnTrack Welcome page.
4. Add a learner via Manage Individual Learners, then edit that path's deadline via
   Manage Paths — confirm the learner still appears in their dashboard block afterward.
