# LearnTrack

**A Moodle plugin suite for tracking learner progress across multiple courses in a learning path — from a single dashboard.**

Developed by [Michael Adeniran](https://www.linkedin.com/in/michaeladeniran) · Nigeria 🇳🇬

[![Moodle 4.5–5.1+](https://img.shields.io/badge/Moodle-4.5%E2%80%935.1%2B-orange)](https://moodle.org)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

This repository contains two Moodle plugins that work together:

| Plugin | Component | What it does |
|---|---|---|
| [`local_learnpath/`](local_learnpath/) | `local_learnpath` | The main plugin — dashboard, reports, reminders, leaderboard, branding, admin tools. See its [full README](local_learnpath/README.md) for details. |
| [`block_learntrack_mypath_v2/`](block_learntrack_mypath_v2/) | `block_learntrack_mypath` | A companion Moodle dashboard block showing learners their assigned paths and progress at a glance. |

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
