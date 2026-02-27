<?php
defined('MOODLE_INTERNAL') || die();

/**
 * MCQ-only GPT-5-mini client using Azure Responses API streaming.
 *
 * This file is isolated from lib5.php so that changes here do not affect ask_agent_ajax.php.
 * It:
 *  - Calls the same Azure endpoint configured in local_ai_functions_agents.config_data.
 *  - Uses streaming events (Responses API) but buffers all content server-side.
 *  - Returns a simple array to the caller, not SSE.
 *
 * Return structure on success:
 * [
 *   'success'  => true,
 *   'text'     => 'Q1. ...',            // full concatenated MCQ text
 *   'metadata' => [
 *       'usage' => [
 *           'prompt_tokens'      => ...,
 *           'completion_tokens'  => ...,
 *           'total_tokens'       => ...
 *       ],
 *       'response_id'  => 'resp_...',
 *       'finish_reason'=> 'stop' | 'length' | ...
 *   ]
 * ]
 *
 * On error:
 * [
 *   'success' => false,
 *   'error'   => 'Message'
 * ]
 */

/**
 * Stream GPT-5-mini response and buffer content for MCQ generation.
 *
 * @param string $agentconfigkey Agent key in local_ai_functions_agents.agent_key
 * @param string $functionname   Key inside config_data JSON (e.g. "mcq_widget")
 * @param array  $payload        From mcq_widget_ajax.php (messages[], stream=>true, max_completion_tokens, temperature)
 * @return array
 */
function local_ai_functions_call_endpoint_gpt5_mcq(string $agentconfigkey, string $functionname, array $payload): array {
    global $DB;

    // 1. Load agent configuration from DB.
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    if (!$agent) {
        error_log("GPT5 MCQ ERROR: Agent not found: {$agentconfigkey}");
        return [
            'success' => false,
            'error'   => "Agent not found: {$agentconfigkey}"
        ];
    }

    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        error_log("GPT5 MCQ ERROR: Function not configured: {$functionname}");
        return [
            'success' => false,
            'error'   => "Function not configured: {$functionname}"
        ];
    }

    $fn = $config[$functionname];

    $endpoint    = $fn['endpoint']    ?? '';
    $api_key     = $fn['api_key']     ?? '';
    $api_version = $fn['api_version'] ?? '2025-04-01-preview';
    $model       = $fn['model']       ?? 'gpt-5-mini';

    if ($endpoint === '' || $api_key === '') {
        error_log("GPT5 MCQ ERROR: Invalid agent configuration (endpoint/api_key missing)");
        return [
            'success' => false,
            'error'   => 'Invalid agent configuration (endpoint/api_key missing)'
        ];
    }

    // 2. Normalize endpoint to /openai/responses.
    if (strpos($endpoint, '/openai/responses') === false) {
        $endpoint = rtrim($endpoint, '/') . '/openai/responses';
    }
    $full_url = $endpoint . '?api-version=' . urlencode($api_version);

    // 3. Normalize payload for Responses API.
    //    - messages -> input
    //    - max_completion_tokens/max_tokens -> max_output_tokens
    if (isset($payload['messages'])) {
        $payload['input'] = $payload['messages'];
        unset($payload['messages']);
    }

    if (isset($payload['max_completion_tokens'])) {
        $payload['max_output_tokens'] = $payload['max_completion_tokens'];
        unset($payload['max_completion_tokens']);
    } elseif (isset($payload['max_tokens'])) {
        $payload['max_output_tokens'] = $payload['max_tokens'];
        unset($payload['max_tokens']);
    }

    if (!isset($payload['max_output_tokens'])) {
        $payload['max_output_tokens'] = 4096;
    }

    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }

    // Always stream for this helper.
    $payload['stream'] = true;

    error_log("GPT5 MCQ STREAM URL: " . $full_url);
    error_log("GPT5 MCQ STREAM PAYLOAD: " . json_encode($payload));

    // 4. Prepare streaming buffers.
    $sse_buffer  = '';
    $full_text   = '';
    $metadata    = [];

    // 5. cURL streaming request (no SSE to browser).
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $full_url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function($curl, string $data) use (&$sse_buffer, &$full_text, &$metadata): int {
            $sse_buffer .= $data;

            // Process complete SSE events separated by blank line.
            while (($pos = strpos($sse_buffer, "\n\n")) !== false) {
                $raw_event = substr($sse_buffer, 0, $pos + 2);
                $sse_buffer = substr($sse_buffer, $pos + 2);

                $trimmed = trim($raw_event);
                if ($trimmed === '' || strpos($trimmed, ':') === 0) {
                    continue;
                }

                // Extract event type and data.
                $event_type = null;
                $event_data = null;

                if (preg_match('/^event:\s*(.+)$/m', $raw_event, $m1)) {
                    $event_type = trim($m1[1]);
                }
                if (preg_match('/^data:\s*(.+)$/m', $raw_event, $m2)) {
                    $event_data = trim($m2[1]);
                }

                if ($event_type === null || $event_data === null) {
                    continue;
                }

                // Responses API: text chunks come as response.output_text.delta
                if ($event_type === 'response.output_text.delta') {
                    $chunk = json_decode($event_data, true);
                    if (isset($chunk['delta']) && $chunk['delta'] !== '') {
                        $full_text .= $chunk['delta'];
                    }
                }
                // Completion event with usage, id, finish_reason.
                else if ($event_type === 'response.completed') {
					error_log("GPT5 MCQ STREAM COMPLETED EVENT: " . $event_data);  // add this
                    $chunk = json_decode($event_data, true);
                    if (isset($chunk['response']['usage'])) {
                        $metadata['usage'] = $chunk['response']['usage'];
                    }
                    if (isset($chunk['response']['id'])) {
                        $metadata['response_id'] = $chunk['response']['id'];
                    }
                    if (isset($chunk['response']['finish_reason'])) {
                        $metadata['finish_reason'] = $chunk['response']['finish_reason'];
                    }
                }
                // Optional: capture errors (but we still let outer layer handle)
                else if ($event_type === 'response.error') {
                    error_log("GPT5 MCQ STREAM RESPONSE.ERROR: " . $event_data);
                }
            }

            return strlen($data);
        },
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT=> 1,
        CURLOPT_LOW_SPEED_TIME => 240,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $ok        = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("GPT5 MCQ STREAM HTTP CODE: " . $http_code);
    if ($curl_err !== '') {
        error_log("GPT5 MCQ STREAM CURL ERROR: " . $curl_err);
        return [
            'success' => false,
            'error'   => 'cURL error: ' . $curl_err
        ];
    }

    if ($ok === false || $http_code !== 200) {
        return [
            'success' => false,
            'error'   => 'HTTP error ' . $http_code
        ];
    }

    if ($full_text === '') {
        error_log("GPT5 MCQ STREAM: full_text is empty after streaming.");
        return [
            'success' => false,
            'error'   => 'Empty text from streaming API'
        ];
    }

    error_log("GPT5 MCQ STREAM SUCCESS. Text length: " . strlen($full_text) . " chars");
    if (!empty($metadata)) {
        error_log("GPT5 MCQ STREAM METADATA: " . json_encode($metadata));
    }

    return [
        'success'  => true,
        'text'     => $full_text,
        'metadata' => $metadata
    ];
}
