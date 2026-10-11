<?php
defined('MOODLE_INTERNAL') || die();

// Page de dépôt de fichiers .pg, RÉSERVÉE AUX ADMINISTRATEURS DE SITE.
// Déclarée en dehors de $ADMIN->fulltree pour que l'entrée de menu existe
// dès la construction de l'arbre d'administration. La capacité exigée
// (moodle/site:config) est volontairement la plus restrictive : un .pg est
// du code Perl exécuté par le renderer -- voir l'en-tête de upload.php.
$ADMIN->add('qtypesettings', new admin_externalpage(
    'qtypewebworkupload',
    get_string('uploadtitle', 'qtype_webwork'),
    new moodle_url('/question/type/webwork/upload.php'),
    'moodle/site:config'
));

if ($ADMIN->fulltree) {
    // Rappel visible en tête des réglages : l'importation en lot se lance
    // depuis une banque de questions (elle a besoin d'une catégorie de
    // destination, qui n'existe pas au niveau du site).
    $settings->add(new admin_setting_heading(
        'qtype_webwork/importhint',
        get_string('importtitle', 'qtype_webwork'),
        get_string('importadminhint', 'qtype_webwork')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'qtype_webwork/allowupload',
        get_string('allowupload', 'qtype_webwork'),
        get_string('allowupload_help', 'qtype_webwork'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_webwork/serverurl',
        get_string('serverurl', 'qtype_webwork'),
        get_string('serverurl_help', 'qtype_webwork'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configcheckbox(
        'qtype_webwork/selfsignedcert',
        get_string('selfsignedcert', 'qtype_webwork'),
        get_string('selfsignedcert_help', 'qtype_webwork'),
        0
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'qtype_webwork/sharedsecret',
        get_string('sharedsecret', 'qtype_webwork'),
        get_string('sharedsecret_help', 'qtype_webwork'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_webwork/libraryroot',
        get_string('libraryroot', 'qtype_webwork'),
        get_string('libraryroot_help', 'qtype_webwork'),
        'Library',
        PARAM_PATH
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_webwork/privateroot',
        get_string('privateroot', 'qtype_webwork'),
        get_string('privateroot_help', 'qtype_webwork'),
        'private',
        PARAM_PATH
    ));

    $settings->add(new admin_setting_configselect(
        'qtype_webwork/mathjaxsource',
        get_string('mathjaxsource', 'qtype_webwork'),
        get_string('mathjaxsource_help', 'qtype_webwork'),
        'local',
        [
            'local' => get_string('mathjaxsource_local', 'qtype_webwork'),
            'cdn'   => get_string('mathjaxsource_cdn', 'qtype_webwork'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_webwork/language',
        get_string('language', 'qtype_webwork'),
        get_string('language_help', 'qtype_webwork'),
        'en',
        PARAM_ALPHANUMEXT
    ));
}
