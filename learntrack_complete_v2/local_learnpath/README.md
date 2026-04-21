# LearnTrack — Learning Path Progress Dashboard

**A powerful Moodle local plugin for tracking learner progress across multiple courses in a learning path — from a single dashboard.**

Developed by [Michael Adeniran](https://www.linkedin.com/in/michaeladeniran) · Nigeria 🇳🇬

[![Moodle 4.5+](https://img.shields.io/badge/Moodle-4.5%2B-orange)](https://moodle.org)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Version](https://img.shields.io/badge/version-2.0.0-green)](CHANGES.md)

---

## Overview

LearnTrack solves a common pain point for Moodle administrators, HR managers, and training coordinators: **tracking learner progress across a group of related courses without opening each course individually.**

You define a "learning path" (e.g. "New Employee Onboarding — 20 courses"), and LearnTrack gives you a single dashboard showing every enrolled learner's progress across all 20 courses at once.

---

## Features

### Core Dashboard
- **Summary view** — one row per learner showing overall progress across all courses (fast loading)
- **Detail view** — one row per learner per course with full activity breakdown
- **Sortable columns** — click any header to sort
- **Live search** — filter by name, email, or username instantly
- **Pagination** — 25/50/100/200 rows per page

### Learning Paths
- Create paths from manually selected courses, a course category, or a cohort
- Set completion deadlines with overdue alerts
- Assign managers to specific paths (per-path scope control)

### Progress Tracking
- Accurate completion % (5/5 activities = 100%, not 99%)
- Activity-level drill-down per course
- First access, last access, date completed
- Grade tracking
- Completion rate across all learners

### Reporting & Export
- Export to **Excel (.xlsx)**, **CSV**, and **PDF**
- Exports include summary header, all visible columns, and respect active filters
- XLSX exports include a separate Summary sheet
- Send reports by email on demand
- Schedule recurring email reports (daily, weekly, monthly)

### Notifications & Reminders
- **Email** reminders to learners who haven't started or completed
- **In-app** notifications (Moodle notification bell + login popup)
- **SMS** support (requires Moodle 4.4+ SMS gateway)
- Template variables: `{{firstname}}`, `{{progress}}`, `{{deadline}}`, `{{dashboardurl}}`
- Configurable frequency: once, daily, weekly, monthly
- Manual "Send Now" trigger from admin

### Overview & Analytics
- **Site-wide overview** with completion trends, at-risk learners, top learners
- **Popular courses** table ranked by enrolment
- **Recent activity feed**
- **Course Insights** page with 3-chart-type progress distribution (bar, column, donut), drop-off analysis, inactive learner alerts, learner table

### Learner Experience
- **My Path page** — learners see their own progress per course with "Continue" buttons
- **Dashboard block** — compact progress block for the Moodle dashboard (separate plugin)
- **Leaderboard** — rank learners by overall progress with podium display
- In-app notifications link directly to the learner's progress page

### Administration
- **Branding** — customise plugin name, colours, font size, visible fields
- **Accessibility** — high contrast mode, large text, reduce motion
- **Role-based access** — admin, manager, teacher, learner roles with configurable scopes
- **User status filter** — include/exclude suspended and deleted users in reports
- **Privacy compliant** — full GDPR privacy provider (export, delete user data)
- **Performance cache** — background cron pre-computes progress for fast loading

---

## Installation

### Requirements
- Moodle 4.5 or higher (PHP 8.1+)
- MySQL/MariaDB or PostgreSQL

### Install via Moodle Admin UI (recommended)
1. Download `learntrack_v2.0.0.zip`
2. Unzip — you will find two folders: `local_learnpath/` and `block_learntrack_mypath/`
3. Go to **Site Administration → Plugins → Install plugins**
4. Upload `local_learnpath` first
5. Then install `block_learntrack_mypath` separately
6. Follow the on-screen upgrade steps

### Install via File Manager (Moodle 4.5 / 5.0)
Copy `local_learnpath/` to `/path/to/moodle/local/learnpath/`
Copy `block_learntrack_mypath/` to `/path/to/moodle/blocks/learntrack_mypath/`

### Install via File Manager (Moodle 5.1+)
Copy `local_learnpath/` to `/path/to/moodle/public/local/learnpath/`
Copy `block_learntrack_mypath/` to `/path/to/moodle/public/blocks/learntrack_mypath/`

Then visit Site Administration → Notifications to run the upgrade.

### Adding the Dashboard Block
After installing the block plugin:
1. Go to your Moodle dashboard
2. Enable editing
3. Click "Add a block"
4. Select "LearnTrack: My Learning Paths"

---

## Getting Started

1. Go to **Site Administration → Plugins → Local plugins → LearnTrack**
2. Click **"Manage Paths"** to create your first learning path
3. Select the courses (manually, by category, or by cohort)
4. Go to the **Dashboard** and select your path
5. View learner progress in Summary or Detail view
6. Use **Export** to download reports or **Send** to email them

---

## File Structure

```
local_learnpath/
├── classes/
│   ├── data/helper.php          # All database queries
│   ├── export/manager.php       # CSV, XLSX, PDF export
│   ├── form/                    # Moodle forms
│   ├── notification/notifier.php# Email, in-app, SMS
│   ├── privacy/provider.php     # GDPR privacy API
│   └── task/                    # Scheduled cron tasks
├── db/
│   ├── access.php               # Capabilities
│   ├── install.xml              # Database schema
│   ├── messages.php             # Notification providers
│   ├── tasks.php                # Scheduled tasks
│   ├── uninstall.php            # Clean uninstall
│   └── upgrade.php              # Upgrade from any version
├── lang/en/local_learnpath.php  # English language strings
├── pix/icon.svg                 # Plugin icon
├── branding.php                 # Branding customisation
├── courseinsights.php           # Individual course analytics
├── index.php                    # Main dashboard
├── leaderboard.php              # Learner leaderboard
├── manage.php                   # Learning path management
├── mypath.php                   # Learner-facing progress page
├── overview.php                 # Site-wide analytics
├── profile.php                  # Learner profile popup
├── reminders.php                # Reminder rule management
├── schedule.php                 # Scheduled report management
├── settings.php                 # Admin settings
├── styles.css                   # Plugin stylesheet
├── version.php                  # Plugin version
└── welcome.php                  # Plugin landing page

block_learntrack_mypath/         # Dashboard block (separate plugin)
```

---

## Capabilities

| Capability | Default Roles |
|---|---|
| `local/learnpath:viewdashboard` | Manager, Course Creator, Teacher |
| `local/learnpath:manage` | Manager |
| `local/learnpath:export` | Manager, Course Creator, Teacher |
| `local/learnpath:emailreport` | Manager |
| `local/learnpath:viewall` | Manager |

---

## Privacy & GDPR

LearnTrack implements Moodle's Privacy API (`\core_privacy\local\request\plugin\provider`).

**Data stored:** Progress cache, admin notes, certificates, reminder logs.

**Data read (not stored):** Enrolment, completion, grades, access logs from Moodle core tables.

**User rights supported:** Export personal data, delete personal data.

---

## Upgrade Path

LearnTrack v2.0.0 upgrades safely from any previous version. The `db/upgrade.php` file checks for the existence of each table/field before adding it, making upgrades fully idempotent.

---

## Roadmap

- **v2.1** — Predictive risk indicators, engagement score, Manager Action Center
- **v2.2** — Compliance dashboard, certification expiry alerts, goal setting
- **v2.3** — Smart recommendations, gamification (badges, streaks)
- **v3.0** — REST API, Power BI/Tableau integration, custom report builder

---

## Support & Contact

- **Developer:** Michael Adeniran
- **Email:** michaeladeniransnr@gmail.com
- **LinkedIn:** [linkedin.com/in/michaeladeniran](https://www.linkedin.com/in/michaeladeniran)
- **Country:** Nigeria 🇳🇬

---

## License

LearnTrack is released under the [GNU General Public License v3.0](LICENSE).

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
