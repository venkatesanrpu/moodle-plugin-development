<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Non-streaming call for Phi-4 (chat completions-compatible).
 * Reads connection details from mdl_local_ai_functions_agents.config_data.
 *
 * Intended for MCQ / batch use (e.g. mcq_widget_ajax.php).
 *
 * @param string $agentconfigkey Agent key in local_ai_functions_agents.agent_key
 * @param string $functionname   Key inside config_data JSON (e.g. "mcq_widget")
 * @param array  $payload        From mcq_widget_ajax.php (messages[], max_tokens, temperature, etc.)
 * @return array Response with 'success', 'content', 'metadata', 'error' keys
 */
function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload): array {
    global $DB;

    // 1. Load agent row.
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    if (!$agent) {
        error_log("Phi-4 NON-STREAM ERROR: Agent not found: {$agentconfigkey}");
        return [
            'success' => false,
            'error' => "Agent not found: {$agentconfigkey}"
        ];
    }

    // 2. Parse config_data JSON and pick function config.
    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        error_log("Phi-4 NON-STREAM ERROR: Function not configured: {$functionname}");
        return [
            'success' => false,
            'error' => "Function not configured: {$functionname}"
        ];
    }

    $function_config = $config[$functionname];

    $endpoint    = $function_config['endpoint']    ?? '';
    $api_key     = $function_config['api_key']     ?? '';
    $api_version = $function_config['api_version'] ?? '2024-05-01-preview';
    $model       = $function_config['model']       ?? 'Phi-4';

    if ($endpoint === '' || $api_key === '') {
        error_log("Phi-4 NON-STREAM ERROR: Invalid agent configuration (endpoint/api_key missing)");
        return [
            'success' => false,
            'error' => 'Invalid agent configuration (endpoint/api_key missing)'
        ];
    }

    // Ensure model in payload.
    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }

    // For non-streaming, ensure stream is not set or false.
    unset($payload['stream']);

    //$full_url = $endpoint . '?api-version=' . urlencode($api_version);
	$full_url = $endpoint;

    // 3. Log request details.
    error_log("Phi-4 NON-STREAM URL: " . $full_url);
    error_log("Phi-4 NON-STREAM PAYLOAD: " . json_encode($payload));

    // 4. cURL non-streaming call.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $full_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 240,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    error_log("Phi-4 NON-STREAM HTTP CODE: {$http_code}");
    if ($response !== false) {
        error_log("Phi-4 NON-STREAM RESPONSE LENGTH: " . strlen($response) . " bytes");
        error_log("Phi-4 NON-STREAM RESPONSE PREVIEW: " . substr($response, 0, 500));
    }

    if ($response === false) {
        error_log("Phi-4 NON-STREAM CURL ERROR: " . $curl_err);
        return [
            'success' => false,
            'error' => 'cURL error: ' . $curl_err
        ];
    }

    if ($http_code !== 200) {
        return [
            'success' => false,
            'error' => 'HTTP error ' . $http_code,
            'status_code' => $http_code,
            'response_body' => substr($response, 0, 500)
        ];
    }

    // 5. Parse JSON response.
    $data = json_decode($response, true);
    if (!is_array($data
