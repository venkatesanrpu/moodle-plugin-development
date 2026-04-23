<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Public entrypoint for callers such as block AJAX endpoints.
 *
 * @package   local_ai_functions_v2
 */

defined('MOODLE_INTERNAL') || die();

use local_ai_functions_v2\local\service\dispatcher;

/**
 * Dispatch an AI function call for a configured agent.
 *
 * Streaming calls emit normalised SSE events directly and return null.
 * Non-streaming calls return a normalised array with content and metadata.
 *
 * @param string $agentkey
 * @param string $functionkey
 * @param array $payload
 * @return array|null
 */
function local_ai_functions_v2_call_endpoint(string $agentkey, string $functionkey, array $payload): ?array {
    return dispatcher::call_endpoint($agentkey, $functionkey, $payload);
}
