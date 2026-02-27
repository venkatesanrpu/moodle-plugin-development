<?php

// FILE: moodle/blocks/ai_assistant/settings.php
// PURPOSE: Defines the configuration form for the AI Assistant block.
defined('MOODLE_INTERNAL') || die;

// This ensures the settings page is only available to administrators.
if ($ADMIN->fulltree) {
    // --- Agent Key Setting (from your existing code) ---
    // change this key name to match get_config usage
$settings->add(new admin_setting_configtext(
    'block_ai_assistant/agent_key',
    get_string('agent_key', 'block_ai_assistant'),
    get_string('agent_key_desc', 'block_ai_assistant'),
    '',
    PARAM_ALPHANUMEXT
));

    // --- NEW: Main Subject Key Setting ---
    $settings->add(new admin_setting_configtext(
        'block_ai_assistant/mainsubjectkey',
        get_string('mainsubjectkey', 'block_ai_assistant'),
        get_string('mainsubjectkey_desc', 'block_ai_assistant'),
        '', // Default value
        PARAM_ALPHANUMEXT
    ));
}