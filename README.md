# LearnTrack

**A Moodle plugin suite for tracking learner progress across multiple courses in a learning path — from a single dashboard.**

Developed by [Michael Adeniran](https://www.linkedin.com/in/michaeladeniran) · Nigeria 🇳🇬

[![Moodle 4.5–5.1+](https://img.shields.io/badge/Moodle-4.5%E2%80%935.1%2B-orange)](https://moodle.org)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

## Overview

Moodle tracks completion one course at a time. The moment training is organized into a
*sequence* of related courses — onboarding, a certification track, a compliance
program — answering "who's actually done?" means opening every course individually
and cross-referencing by hand. LearnTrack solves that.

You group any set of courses (chosen manually, by course category, or by cohort) into
a **Learning Path**, and from that point on LearnTrack gives you one dashboard for the
whole path: every learner's progress across every course in it, aggregated,
sortable, exportable, and automatically reported — without ever leaving the plugin.

This repository contains two plugins that work together:

| Plugin | Component | What it does |
|---|---|---|
| [`local_learnpath/`](local_learnpath/) | `local_learnpath` | The main plugin — dashboard, reports, reminders, leaderboard, branding, admin tools. See its [full README](local_learnpath/README.md) for installation and file structure. |
| [`block_learntrack_mypath_v2/`](block_learntrack_mypath_v2/) | `block_learntrack_mypath` | A companion dashboard block showing each learner (and manager) their assigned paths and live progress on Moodle's `/my` page. |

---

## Features

### 📊 Progress Dashboard (`local_learnpath`)
- **Three views**: Summary (one row per learner), Per-Course (full detail per
  learner/course), and Comparison (learners × courses grid)
- Sortable columns, live search/filter, pagination (25/50/100/200 per page)
- Completion percentage and status mirror **Moodle's own Course Completion
  criteria** exactly — not an approximation
- Inline "not enrolled" badges with one-click (or bulk) enrolment straight from
  the dashboard
- Bulk actions: remind selected learners, enrol all, schedule a report

### 🗂️ Learning Paths
- Build a path from manually-picked courses, an entire course category, or a
  cohort's course enrolments
- Set a completion deadline with overdue/at-risk indicators
- Assign specific individual learners to a path (additive — safe from
  accidental removal on unrelated edits) or track everyone enrolled
- Assign managers per path with scoped access (view only / view + remind / full)

### 📤 Reporting & Export
- Export to **Excel (.xlsx)** with a multi-sheet, branded layout, **CSV**, or **PDF**
- On-demand "send now" email reports, or fully scheduled recurring reports
  (daily, weekly, monthly) with automatic catch-up if a run is missed
- Every path automatically gets a **weekly manager report**, provisioned the
  moment the path is created — recipients resolve to the path's *current*
  managers at send time, not a frozen list
- Full send history log

### 🔔 Reminders & Notifications
- Email, in-app (Moodle notification bell), and SMS (Moodle 4.4+ SMS gateway) channels
- Target learners by status: not started, in progress, or incomplete
- Configurable frequency: once (manual send only), daily, weekly, monthly, or a
  **custom interval in days** (e.g. every 3 days), with a site-wide default
- Template variables: `{{firstname}}`, `{{progress}}`, `{{deadline}}`, `{{dashboardurl}}`
- Full send history per rule, plus a manual "Send Now" trigger

### 🩺 Reliability & Diagnostics
- **Cron & Delivery Health** panel showing last/next run and live status for
  every LearnTrack scheduled task, straight from Moodle's own task table
- **Run Now** button per task — executes it for real in the background
  (non-blocking) so an admin can confirm delivery is working without waiting
  on server cron, and diagnose exactly what's failing if it isn't
- Every scheduled task is hardened so a single bad record can't crash the
  whole run — it's logged and skipped instead

### 🏆 Leaderboard & Gamification
- Points awarded automatically for course completion, path completion, and
  other configurable events
- Badges with configurable point thresholds
- Ranked leaderboard view per path

### 🎓 Certificates
- Auto-generated certificate IDs with configurable format (prefix, date, user ID)
- Issue, revoke, and publicly verify certificates by reference number

### 📡 Overview & Course Insights
- Site-wide analytics: completion trend, at-risk learners, top learners,
  popular courses, recent activity feed
- Per-course insights: progress distribution chart (bar/column/donut), drop-off
  analysis, inactive-learner detection

### 👤 Learner Experience
- **My Path** page — a learner's own progress across every path they're in,
  with deadline countdowns and "Continue" links
- **My Profile** page with engagement score and certificate list
- Deadline countdown popup on login

### 🎨 Branding & Accessibility
- Customisable plugin name, brand colour, font size, and visible fields
- High-contrast mode, large text, reduced motion
- Branded certificate design with logo and signature upload

### 🔒 Access Control & Compliance
- Fine-grained capabilities (`viewdashboard`, `manage`, `export`, `emailreport`, `viewall`)
- Email-based manager invites for people who need path access without a
  Moodle role change, with per-feature scoping (dashboard, branding,
  leaderboard, reminders, export, certs)
- Full GDPR Privacy API implementation — export and delete personal data on request

### 🧭 Dashboard Block (`block_learntrack_mypath`)
- Drop-in block for Moodle's `/my` dashboard showing every path a learner (or
  manager) is part of, with live progress
- Three switchable layouts — Cards, List, Minimal — remembered per user
- Overall progress ring plus a "Continue Learning" card highlighting the most
  urgent incomplete path
- Overdue / at-risk status badges per path
- Separate "Paths I Manage" section with direct links to the dashboard and
  reminders for managers and admins
- Login popup surfacing reminders sent by a manager

---

## Screenshots

**Welcome page**

![LearnTrack welcome hero — 5 learning paths, 77 courses tracked](screenshots/welcome-hero.png)

**Feature overview**

![Feature grid — progress dashboard, export, reminders, branding, and more](screenshots/features-grid.png)

**Quick navigation**

![Quick navigation grid linking to Dashboard, Manage Paths, Leaderboard, Overview, Branding, Course Insights, Settings, Certificates, and Diagnostics](screenshots/quick-navigation.png)

**Developer card**

![Developer card — Michael Adeniran, Plugin Developer, Nigeria](screenshots/developer-card.png)

---

## Getting started

See [`local_learnpath/README.md`](local_learnpath/README.md) for full installation steps, requirements, feature list, and file structure.

## Documentation

- [`local_learnpath/README.md`](local_learnpath/README.md) — full plugin documentation
- [`local_learnpath/CHANGES.md`](local_learnpath/CHANGES.md) — version history
- [`CLAUDE.md`](CLAUDE.md) — detailed log of fixes and changes made during development sessions

## License

Released under the [GNU General Public License v3.0](local_learnpath/LICENSE).
