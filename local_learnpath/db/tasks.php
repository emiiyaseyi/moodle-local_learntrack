<?php
// LearnTrack scheduled tasks
defined('MOODLE_INTERNAL') || die();

// WHY frequent schedules:
//   Moodle evaluates task cron expressions in the SITE'S configured timezone
//   (Site admin → Location → Default timezone), NOT always UTC.  Using a
//   specific hour (e.g. hour=9) fires at unexpected WAT times when the site
//   timezone differs from WAT (UTC+1).  Running every 30 min / every hour is
//   timezone-proof: the task checks its own nextrun timestamp and only sends
//   when that moment has arrived, regardless of which "hour" it is locally.

$tasks = [
    [
        'classname' => '\local_learnpath\task\send_scheduled_reports',
        'blocking'  => 0,
        'minute'    => '0',      // top of every hour
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\local_learnpath\task\send_reminders',
        'blocking'  => 0,
        'minute'    => '*/30',   // every 30 minutes
        'hour'      => '*',
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
