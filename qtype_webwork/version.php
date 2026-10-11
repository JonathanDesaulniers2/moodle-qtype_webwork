<?php
// This file is part of Moodle - http://moodle.org/
//
// qtype_webwork - Question type intégrant le WeBWorK Standalone Renderer.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'qtype_webwork';
$plugin->version   = 2026101100;
$plugin->requires  = 2026100500; // Moodle 5.3.
$plugin->supported = [503, 503]; // Branche MOODLE_503_STABLE ; Moodle 4.5 : branche MOODLE_405_STABLE.
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '2.0-moodle53';
// NOTE : pas de $plugin->dependencies ici. 'qtype' n'est pas un nom de
// plugin installable (c'est le préfixe du TYPE de plugin, comme mod_ ou
// block_) -- le moteur de question fait partie du noyau Moodle et n'a pas
// besoin d'être déclaré comme dépendance. Une version précédente de ce
// fichier déclarait à tort 'qtype' => ANY_VERSION, ce qui fait chercher à
// Moodle un plugin nommé "qtype" qui n'existe pas.
