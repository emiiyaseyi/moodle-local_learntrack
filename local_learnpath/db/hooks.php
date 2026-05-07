<?php
defined('MOODLE_INTERNAL') || die();

// Moodle 4.3+ hook: extend the primary navigation (top bar).
// String-based class references prevent PHP fatal errors if the hook class
// doesn't exist or has been renamed between Moodle versions.
$callbacks = [
    [
        'hook'     => 'core\hook\navigation\primary_extend',
        'callback' => 'local_learnpath\hook\navigation_primary_listener::extend',
        'priority' => 500,
    ],
];
