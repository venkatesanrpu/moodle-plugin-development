<?php
defined('MOODLE_INTERNAL') || die();

function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload): string {
    global $DB;

    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey], '*', IGNORE_MISSING);
    if (!$agent) {
        return json_encode(['error' => "Agent not found: {$agentconfigkey}"]);
    }

    $config = json_decode($agent->config_data, true);
    if (!is_array($config) || empty($config[$functionname])) {
        return json_encode(['error' => "Function not configured: {$functionname}"]);
    }

    $fn = $config[$functionname];
    $endpoint = $fn['endpoint'] ?? '';
    $api_key  = $fn['api_key'] ?? '';
    $api_version = $fn['api_version'] ?? '2025-01-01-preview';
    $model    = $fn['model'] ?? null;

    if ($endpoint === '' || $api_key === '') {
        return json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)']);
    }

    if (!isset($payload['model']) && $model) {
        $payload['model'] = $model;
    }

    $full_url = $endpoint . '?api-version=' . urlencode($api_version); //for Azure Open AI Model only
    //$full_url = $endpoint //for parallel ai and kimi k2 model

    $ch = curl_init($full_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 240,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return json_encode(['error' => 'cURL error: ' . $err]);
    }
    if ($code !== 200) {
        return json_encode(['error' => 'API HTTP error', 'http_code' => $code, 'response' => substr($resp, 0, 2000)]);
    }
    return $resp;
}
