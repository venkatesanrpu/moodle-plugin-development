<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings->add(new admin_setting_configtext(
        'block_ai_assistant_v2/agent_key',
        get_string('agentkey', 'block_ai_assistant_v2'),
        get_string('agentkey_desc', 'block_ai_assistant_v2'),
        'default',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'block_ai_assistant_v2/mainsubjectkey',
        get_string('mainsubjectkey', 'block_ai_assistant_v2'),
        get_string('mainsubjectkey_desc', 'block_ai_assistant_v2'),
        'general',
        PARAM_ALPHANUMEXT
    ));
}
