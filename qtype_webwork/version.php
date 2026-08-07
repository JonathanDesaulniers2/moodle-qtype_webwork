<?php
// This file is part of Moodle - http://moodle.org/
//
// qtype_webwork - Question type intégrant le WeBWorK Standalone Renderer.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'qtype_webwork';
$plugin->version   = 2026072841;
$plugin->requires  = 2024100700; // Moodle 4.5.
$plugin->supported = [405, 405]; // Testé sur Moodle 4.5 ; à étendre lors du passage à 5.x.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.2.1';
// NOTE : pas de $plugin->dependencies ici. 'qtype' n'est pas un nom de
// plugin installable (c'est le préfixe du TYPE de plugin, comme mod_ ou
// block_) -- le moteur de question fait partie du noyau Moodle et n'a pas
// besoin d'être déclaré comme dépendance. Une version précédente de ce
// fichier déclarait à tort 'qtype' => ANY_VERSION, ce qui fait chercher à
// Moodle un plugin nommé "qtype" qui n'existe pas.
