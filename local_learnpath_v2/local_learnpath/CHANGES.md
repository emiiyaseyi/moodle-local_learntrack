# LearnTrack — Changelog

## v2.0.0 (2026-04-18) — Current Release

### New Features
- **Learning path dashboard** — Summary, Per-Course, and Comparison views with sortable columns
- **Overview page** — Site-wide stats (unique courses, total learners, completions, avg progress), Completion Trend chart with date labels, Top 10 Learners with points, At-Risk panel with 📢 Remind + 👤 Profile actions
- **Course Insights** — Progress distribution chart (bar/column/donut), drop-off analysis, inactive learners, export + email + schedule per course
- **Reminders** — Automated learner nudges via email, in-app notification, and SMS (where available); bulk remind from dashboard; send history per rule
- **Export** — CSV, XLSX, PDF (redesigned with branded header, LMS name, path name, text-wrapping, paginated headers)
- **Email reports** — Admin-configurable subject and body templates; HTML email with summary card; send history table on email page
- **Scheduled reports** — Daily/weekly/monthly auto-send via Moodle cron
- **NE (Not Enrolled) badge** — Shows in Summary, Per-Course, and Comparison views; admin can click to enrol learner and auto-notify via email + in-app
- **Engagement Score (0–100)** — Per-learner metric combining progress, activity completion, grade, and recency; shown in dashboard and profile
- **Certificate issuance** — Issue/revoke certs from learner profile or Certificates view tab; auto-notifies learner
- **Learner profile popup** — SVG progress ring, engagement score, all-paths view with deadline countdown, activity drill-down, cert management, admin notes
- **Bulk actions** — Select multiple learners → Send Reminder or Enrol All with one click
- **Search/filter** — Live name/email search in dashboard summary view
- **Manager/Teacher scope** — Managers see only their assigned paths
- **Deadline countdown** — My Path page shows days/hours remaining with colour-coded urgency; overdue warning
- **Mobile responsive** — Full responsive CSS for phones and tablets; tables scroll horizontally; toolbar stacks vertically
- **Privacy API** — Full GDPR compliance: metadata declaration, data export, data deletion per user
- **My Path block** — Moodle dashboard block for learners with progress bars and Continue Learning button

### Bug Fixes
- Email body showing literal `\n` instead of line breaks — fixed by normalising escape sequences after config retrieval
- Dashboard blank page — fixed multiline string parse error in enrol action handler
- `max()` on empty array in overview completion trend
- Duplicate avg completion stat in overview
- `get_records_sql` duplicate userid warning in comparison view — fixed with unique `rowkey` column
- `global $DB` missing in render functions causing blank views

### Technical
- Moodle 4.5–5.1+ compatible
- PHP 8.1+ with strict typing
- MySQL/MariaDB and PostgreSQL via Moodle DML API only
- 13 DB tables with idempotent upgrade path
- Cron tasks: send_reminders (daily 07:30), send_scheduled_reports (daily 06:00), refresh_progress_cache (every 4h)

---

## v1.7.0 (2026-02-01)
- Overview page with site-wide stats and at-risk learners
- Course Insights page with progress distribution and drop-off analysis
- My Path block for learner dashboard

## v1.6.0 (2026-01-15)
- Export (CSV, XLSX, PDF) — fixed namespace errors
- Email report sending
- Scheduled reports via cron
- Pagination (25/50/100/200 rows per page)
- Date range filter

## v1.5.0 (2025-12-01)
- Reminders system (DB + task)
- Certificate tracker
- Admin notes on learner profiles
- Branding & customisation settings

## v1.4.0 (2025-11-01)
- Core dashboard with Summary and Per-Course views
- Learning path creation and management
- Role-based access (Admin, Manager, Learner)
