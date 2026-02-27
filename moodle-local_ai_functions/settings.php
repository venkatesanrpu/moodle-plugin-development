<?php
// FILE: moodle/local/ai_functions/settings.php
defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ai_functions_manage',
        get_string('pluginname', 'local_ai_functions'),
        new moodle_url('/local/ai_functions/index.php')
    ));
}
