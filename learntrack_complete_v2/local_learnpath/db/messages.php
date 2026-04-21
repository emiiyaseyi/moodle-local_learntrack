<?php
defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'learntrack_reminder' => [
        'name'     => 'LearnTrack course reminder',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],
    'learntrack_overdue' => [
        'name'     => 'LearnTrack overdue alert',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
    'learntrack_cert' => [
        'name'     => 'LearnTrack certificate issued',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],
];
