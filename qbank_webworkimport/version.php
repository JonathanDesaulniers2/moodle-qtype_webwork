<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'qbank_webworkimport';
$plugin->version   = 2026101100;
$plugin->requires  = 2026100500;   // Moodle 5.3
$plugin->supported = [503, 503];
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '2.0-moodle53';

// Ce plugin n'est qu'un point d'entrée : toute la logique d'importation
// vit dans qtype_webwork, sans lequel il n'a aucune raison d'être.
$plugin->dependencies = [
    'qtype_webwork' => 2026101100,
];
