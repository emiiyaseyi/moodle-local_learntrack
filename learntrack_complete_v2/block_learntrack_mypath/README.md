# LearnTrack: My Learning Paths — Dashboard Block

A companion Moodle dashboard block for the [LearnTrack](../local_learnpath) plugin.

## What it shows

- Each learning path the learner is enrolled in
- Mini progress bar and % for each path
- Completed/total course count
- Overdue deadline warnings
- "Continue Learning →" button linking to the full My Path page

## Requirements

- LearnTrack (`local_learnpath`) v2.0.0 must be installed first
- Moodle 4.5+, PHP 8.1+

## Installation

Install separately via Site Admin → Plugins → Install plugins, **after** installing `local_learnpath`.

Then add to dashboard: Enable editing → Add a block → "LearnTrack: My Learning Paths"

## Configuration

Block visibility is controlled by the LearnTrack plugin setting:
**Site Admin → Plugins → Local plugins → LearnTrack → "My Path block visibility"**

- `enrolled` — only shows for users enrolled in at least one learning path course
- `all` — shows for all logged-in users

## Developer

Michael Adeniran · michaeladeniransnr@gmail.com · [LinkedIn](https://www.linkedin.com/in/michaeladeniran)

Licensed under GNU GPL v3
