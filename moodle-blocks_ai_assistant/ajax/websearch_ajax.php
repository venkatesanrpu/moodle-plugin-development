<?php

// FILE: moodle/blocks/ai_assistant/websearch_ajax.php
// FIX: Corrected required_param to look for 'agent_text'.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/lib.php');

require_login();
require_sesskey();
header('Content-Type: application/json');

$agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
$usertext = required_param('agent_text', PARAM_RAW);

$payload = [
    'query' => $usertext
];

echo local_ai_functions_call_endpoint($agentkey, 'websearch', $payload);

