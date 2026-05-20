<?php
// LearnTrack scheduled tasks
defined('MOODLE_INTERNAL') || die();

// Time rationale (West Africa Time = UTC+1):
//   send_scheduled_reports : 14:00 UTC = 15:00 WAT (3 PM) on every day.
//     Weekly schedules are anchored to Friday; the 14:00 UTC cron catches them
//     the same Friday afternoon they are due.
//   send_reminders         : 09:00 UTC = 10:00 WAT (10 AM) daily.
//     Weekly reminders fire once per week; nextrun is pinned to 08:30 UTC so
//     the 09:00 UTC cron always catches them on the correct weekday.
//   refresh_progress_cache : every 4 h, unchanged.

$tasks = [
    [
        'classname' => '\local_learnpath\task\send_scheduled_reports',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '14',   // 14:00 UTC = 15:00 WAT
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\local_learnpath\task\send_reminders',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '9',    // 09:00 UTC = 10:00 WAT
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\local_learnpath\task\refresh_progress_cache',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '*/4',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
