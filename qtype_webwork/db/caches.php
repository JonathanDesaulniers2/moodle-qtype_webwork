<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'renders' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 3600,
    ],
];
