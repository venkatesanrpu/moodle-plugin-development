<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Non-streaming call for GPT-5-mini via Azure Responses API.
 * Used for MCQ/practice question generation where full response is needed upfront.
 * Reads connection details from mdl_local_ai_functions_agents.config_data.
 *
 * @param string $agentconfigkey Agent key in local_ai_functions_agents.agent_key
 * @param string $functionname   Key inside config_data JSON (e.g. "mcq_widget")
 * @param array  $payload        From mcq_widget_ajax.php (messages[], max_completion_tokens, temperature)
 * @return array Response with 'success', 'content', 'metadata', 'error' keys
 */
function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload): array {
    global $DB;

    // 1. Load agent row.
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    if (!$agent) {
        error_log("GPT5 NON-STREAM ERROR: Agent not found: {$agentconfigkey}");
        return [
            'success' => false,
            'error' => "Agent not found: {$agentconfigkey}"
        ];
    }

    // 2. Parse config_data JSON and pick function config.
    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        error_log("GPT5 NON-STREAM ERROR: Function not configured: {$functionname}");
        return [
            'success' => false,
            'error' => "Function not configured: {$functionname}"
        ];
    }

    $function_config = $config[$functionname];

    $endpoint    = $function_config['endpoint']    ?? '';
    $api_key     = $function_config['api_key']     ?? '';
    $api_version = $function_config['api_version'] ?? '2025-04-01-preview';
    $model       = $function_config['model']       ?? 'gpt-5-mini';

    if ($endpoint === '' || $api_key === '') {
        error_log("GPT5 NON-STREAM ERROR: Invalid agent configuration (endpoint/api_key missing)");
        return [
            'success' => false,
            'error' => 'Invalid agent configuration (endpoint/api_key missing)'
        ];
    }

    // 3. Normalize endpoint for Responses API.
    if (strpos($endpoint, '/openai/responses') === false) {
        $endpoint = rtrim($endpoint, '/') . '/openai/responses';
    }

    // 4. Normalize payload for Responses API.
    //    - Move messages -> input
    //    - Map max_* -> max_output_tokens
    //    - Ensure stream is false or unset
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
    } elseif (!isset($payload['max_output_tokens'])) {
        $payload['max_output_tokens'] = 4096; // default for MCQ generation
    }

    // Ensure stream is false or absent for non-streaming
    unset($payload['stream']);

    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }

    // 5. Build full URL.
    //$full_url = $endpoint . '?api-version=' . urlencode($api_version);
	$full_url = $endpoint;

    // 6. Log request details.
    error_log("GPT5 NON-STREAM URL: " . $full_url);
    error_log("GPT5 NON-STREAM PAYLOAD: " . json_encode($payload));

    // 7. cURL non-streaming request.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $full_url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 240,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    error_log("GPT5 NON-STREAM HTTP CODE: " . $http_code);
    if ($response !== false) {
        error_log("GPT5 NON-STREAM RESPONSE LENGTH: " . strlen($response) . " bytes");
        error_log("GPT5 NON-STREAM RESPONSE PREVIEW: " . substr($response, 0, 500));
    }

    if ($response === false) {
        error_log("GPT5 NON-STREAM CURL ERROR: " . $curl_err);
        return [
            'success' => false,
            'error' => 'cURL error: ' . $curl_err
        ];
    }

    if ($http_code !== 200) {
        error_log("GPT5 NON-STREAM HTTP ERROR BODY: " . substr($response, 0, 1000));
        return [
            'success' => false,
            'error' => 'HTTP error ' . $http_code,
            'status_code' => $http_code,
            'response_body' => substr($response, 0, 500)
        ];
    }

    // 8. Parse JSON response.
    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log("GPT5 NON-STREAM INVALID JSON RESPONSE");
        return [
            'success' => false,
            'error' => 'Invalid JSON response',
            'raw_response' => substr($response, 0, 500)
        ];
    }

    // 9. Extract content from Responses API structure.
    $content = local_ai_functions_extract_gpt5_content($data);
    if ($content === null) {
        error_log("GPT5 NON-STREAM CONTENT EXTRACTION FAILED. Response keys: " . implode(', ', array_keys($data)));
        return [
            'success' => false,
            'error' => 'Could not extract content from response',
            'response_structure' => array_keys($data)
        ];
    }

    // 10. Extract metadata (usage, finish_reason, response_id).
    $metadata = [];
    if (isset($data['usage'])) {
        $metadata['usage'] = $data['usage'];
    }
    if (isset($data['id'])) {
        $metadata['response_id'] = $data['id'];
    }
    if (isset($data['finish_reason'])) {
        $metadata['finish_reason'] = $data['finish_reason'];
    }
    // Responses API may nest these differently; check actual structure:
    if (isset($data['response']['usage'])) {
        $metadata['usage'] = $data['response']['usage'];
    }
    if (isset($data['response']['id'])) {
        $metadata['response_id'] = $data['response']['id'];
    }
    if (isset($data['response']['finish_reason'])) {
        $metadata['finish_reason'] = $data['response']['finish_reason'];
    }

    error_log("GPT5 NON-STREAM SUCCESS. Content length: " . strlen($content) . " chars");
    if (!empty($metadata)) {
        error_log("GPT5 NON-STREAM METADATA: " . json_encode($metadata));
    }

    return [
        'success' => true,
        'content' => $content,
        'metadata' => $metadata,
        'full_response' => $data
    ];
}

/**
 * Extract assistant text from Responses API non-streaming JSON.
 * Handles various nesting structures.
 *
 * @param array $data Decoded JSON response
 * @return string|null Extracted content or null if not found
 */
function local_ai_functions_extract_gpt5_content(array $data): ?string {
    // PRIORITY 1: Top-level text field (most common in Responses API)
    if (isset($data['text']) && is_string($data['text'])) {
        return $data['text'];
    }
    
    // PRIORITY 2: output array structures
    if (isset($data['output']) && is_array($data['output'])) {
        // output[0].content[0].text
        if (isset($data['output'][0]['content'][0]['text'])) {
            return $data['output'][0]['content'][0]['text'];
        }
        // output[0].text
        if (isset($data['output'][0]['text'])) {
            return $data['output'][0]['text'];
        }
        // output.text
        if (isset($data['output']['text'])) {
            return $data['output']['text'];
        }
    }
    
    // PRIORITY 3: response.output nesting
    if (isset($data['response']['output'][0]['content'][0]['text'])) {
        return $data['response']['output'][0]['content'][0]['text'];
    }
    
    // PRIORITY 4: Direct content field (fallback for other formats)
    if (isset($data['content']) && is_string($data['content'])) {
        return $data['content'];
    }

    return null;
}
