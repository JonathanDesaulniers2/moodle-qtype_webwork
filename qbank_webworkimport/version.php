<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'qbank_webworkimport';
$plugin->version   = 2026072836;
$plugin->requires  = 2024100700;   // Moodle 4.5
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0';

// Ce plugin n'est qu'un point d'entrée : toute la logique d'importation
// vit dans qtype_webwork, sans lequel il n'a aucune raison d'être.
$plugin->dependencies = [
    'qtype_webwork' => 2026072841,
];
