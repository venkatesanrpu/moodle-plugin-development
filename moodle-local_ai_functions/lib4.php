<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Streaming-only call for Phi-4 (chat completions-compatible endpoint).
 * Reads connection details from mdl_local_ai_functions_agents.config_data.
 *
 * Used by: ask_agent_ajax.php
 *
 * @param string $agentconfigkey Agent key in local_ai_functions_agents.agent_key
 * @param string $functionname   Key inside config_data JSON (e.g. "ask_agent")
 * @param array  $payload        From ask_agent_ajax.php (messages[], stream=>true, max_tokens, temperature, etc.)
 * @return void Streams SSE events to the client
 */
function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload): void {
    global $DB;

    $stream = !empty($payload['stream']);

    if (!$stream) {
        throw new moodle_exception('Streaming disabled in payload; use lib4-non_streaming.php for non-streaming calls.');
    }

    // 1. Load agent row.
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    if (!$agent) {
        error_log("Phi-4 STREAM ERROR: Agent not found: {$agentconfigkey}");
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo "data: " . json_encode(['error' => "Agent not found: {$agentconfigkey}"]) . "\n\n";
        flush();
        return;
    }

    // 2. Parse config_data JSON and pick function config.
    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        error_log("Phi-4 STREAM ERROR: Function not configured: {$functionname}");
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo "data: " . json_encode(['error' => "Function not configured: {$functionname}"]) . "\n\n";
        flush();
        return;
    }

    $function_config = $config[$functionname];

    $endpoint    = $function_config['endpoint']    ?? '';
    $api_key     = $function_config['api_key']     ?? '';
    $api_version = $function_config['api_version'] ?? '2024-05-01-preview';
    $model       = $function_config['model']       ?? 'Phi-4';

    if ($endpoint === '' || $api_key === '') {
        error_log("Phi-4 STREAM ERROR: Invalid agent configuration (endpoint/api_key missing)");
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)']) . "\n\n";
        flush();
        return;
    }

    // Ensure model in payload (original behaviour).
    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }

    $full_url = $endpoint . '?api-version=' . urlencode($api_version);

    // 3. SSE headers.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    echo ": connected\n\n";
    if (ob_get_level() > 0) { ob_flush(); }
    flush();

    error_log("AI Functions - STREAMING MODE ACTIVATED (Phi-4)");
    error_log("AI Functions - Full URL: {$full_url}");
    error_log("AI Functions - Payload: " . json_encode($payload));

    // 4. cURL streaming call.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $full_url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => 'local_ai_functions_phi4_stream_callback',
        CURLOPT_TIMEOUT        => 0,   // no overall timeout
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT=> 1,
        CURLOPT_LOW_SPEED_TIME => 240,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $curl_result = curl_exec($ch);
    $curl_error  = curl_error($ch);

    if ($curl_error) {
        error_log("Phi-4 STREAM CURL ERROR: " . $curl_error);
        echo "event: error\n";
        echo "data: " . json_encode(['error' => $curl_error]) . "\n\n";
        if (ob_get_level() > 0) { ob_flush(); }
        flush();
    }

    curl_close($ch);

    // Always send final done event
    echo "event: done\n";
    echo "data: {}\n\n";
    if (ob_get_level() > 0) { ob_flush(); }
    flush();
}

/**
 * cURL write callback for Phi-4 / Chat Completions streaming.
 * Emits:
 *  - event: chunk      data: {"content":"..."}
 *  - event: metadata   data: {"finish_reason":"..."}
 *  - event: done       data: {}
 */
function local_ai_functions_phi4_stream_callback($curl, string $data): int {
    static $buffer = '';

    $buffer .= $data;
    $length = strlen($data);

    while (($pos = strpos($buffer, "\n\n")) !== false) {
        $event = substr($buffer, 0, $pos + 2);
        $buffer = substr($buffer, $pos + 2);

        if (trim($event) === '' || strpos($event, ':') === 0) {
            continue;
        }

        if (preg_match('/^data:\s*(.+)$/m', $event, $matches)) {
            $json_data = trim($matches[1]);

            if ($json_data === '[DONE]') {
                echo "event: done\n";
                echo "data: {}\n\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
                continue;
            }

            $chunk = json_decode($json_data, true);
            if (!$chunk) {
                continue;
            }

            // Stream content chunks
            if (isset($chunk['choices'][0]['delta']['content'])) {
                $content = $chunk['choices'][0]['delta']['content'];
                if ($content !== '') {
                    echo "event: chunk\n";
                    echo "data: " . json_encode(['content' => $content]) . "\n\n";
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();
                }
            }

            // Stream finish_reason as metadata
            if (isset($chunk['choices'][0]['finish_reason']) &&
                $chunk['choices'][0]['finish_reason'] !== null) {

                echo "event: metadata\n";
                echo "data: " . json_encode([
                    'finish_reason' => $chunk['choices'][0]['finish_reason']
                ]) . "\n\n";
                flush();
            }
        }
    }

    return $length;
}
