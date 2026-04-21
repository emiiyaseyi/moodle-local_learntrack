<?php
// LearnTrack scheduled tasks — clean syntax, PHP 8.1+ compatible
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_learnpath\task\send_scheduled_reports',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '6',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
    [
        'classname' => '\local_learnpath\task\send_reminders',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '7',
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
