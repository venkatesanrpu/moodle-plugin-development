<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ai_functions_v2_manage',
        get_string('pluginname', 'local_ai_functions_v2'),
        new moodle_url('/local/ai_functions_v2/index.php')
    ));
}
