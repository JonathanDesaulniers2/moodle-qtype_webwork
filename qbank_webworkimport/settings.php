<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'qbank_webworkimport/previewheight',
        get_string('previewheight', 'qbank_webworkimport'),
        get_string('previewheight_help', 'qbank_webworkimport'),
        350,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_webworkimport/previewwidth',
        get_string('previewwidth', 'qbank_webworkimport'),
        get_string('previewwidth_help', 'qbank_webworkimport'),
        500,
        PARAM_INT
    ));
}
