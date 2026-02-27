<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Main entry: used by ask_agent_ajax.php and mcq_widget_ajax.php.
 *
 * @param string $agentconfigkey Agent key in mdl_local_ai_functions_agents.agent_key
 * @param string $functionname   Function key inside config_data JSON (e.g. "ask_agent", "mcq")
 * @param array  $payload        Request payload (includes 'stream' flag)
 * @return mixed For stream=true: void (SSE). For stream=false: string (LLM text)
 */
function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload) {
    global $DB, $ai_functions_uses_responses_api;

    $stream = !empty($payload['stream']);

    // 1. Read agent row from DB (your existing design).
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    if (!$agent) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Agent not found: {$agentconfigkey}"]) . "\n\n";
            flush();
            return;
        }
        return json_encode(['error' => "Agent not found: {$agentconfigkey}"]);
    }

    // 2. Parse config_data JSON and pick function.
    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Function not configured: {$functionname}"]) . "\n\n";
            flush();
            return;
        }
        return json_encode(['error' => "Function not found: {$functionname}"]);
    }

    $function_config = $config[$functionname];
    $endpoint   = $function_config['endpoint']   ?? '';
    $api_key    = $function_config['api_key']    ?? '';
    $api_version= $function_config['api_version']?? '2024-05-01-preview';
    $model      = $function_config['model']      ?? 'Phi-4';

    if ($endpoint === '' || $api_key === '') {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)']) . "\n\n";
            flush();
            return;
        }
        return json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)']);
    }

    // Ensure model goes into payload if not already present.
    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }

    // 3. Determine API style: Responses API (gpt‑5‑mini) vs Chat Completions (Phi‑4).
    //    - Responses API: /openai/responses or model name containing "gpt-5".
    $uses_responses_api =
        (strpos($endpoint, '/openai/responses') !== false) ||
        (stripos($model, 'gpt-5') !== false);

    $full_url = $endpoint . '?api-version=' . urlencode($api_version);

    if ($stream) {
        // STREAMING MODE (ask_agent_ajax.php).
        local_ai_functions_handle_streaming($full_url, $payload, $api_key, $uses_responses_api);
        return;
    } else {
        // NON-STREAMING MODE (mcq_widget_ajax.php).
        return local_ai_functions_handle_non_streaming($full_url, $payload, $api_key, $uses_responses_api);
    }
}

/* -------------------------------------------------------------------------
 *  NON-STREAMING HANDLER  (used by mcq_widget_ajax.php)
 * ------------------------------------------------------------------------- */

function local_ai_functions_handle_non_streaming(string $full_url, array $payload, string $api_key, bool $uses_responses_api): string {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $full_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 240,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return json_encode(['error' => 'cURL error: ' . $curl_err]);
    }

    if ($http_code !== 200) {
        return json_encode([
            'error'      => 'API HTTP error',
            'http_code'  => $http_code,
            'response'   => substr($response, 0, 500),
        ]);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        // Not JSON → return raw for the caller to inspect.
        return $response;
    }

    $content = local_ai_functions_extract_content($data, $uses_responses_api);
    if ($content === null) {
        // Fallback to full JSON string so your existing parser can still try.
        return $response;
    }

    return $response;//return $content;
}

/**
 * Extract assistant text from non-streaming JSON, for both API types.
 */
function local_ai_functions_extract_content(array $data, bool $uses_responses_api): ?string {
    if ($uses_responses_api) {
        // GPT‑5‑mini / Responses API shapes. [web:4][web:22][web:27]
        if (isset($data['output'][0]['content'][0]['text'])) {
            return $data['output'][0]['content'][0]['text'];
        }
        if (isset($data['output'][0]['text'])) {
            return $data['output'][0]['text'];
        }
        if (isset($data['output']['text'])) {
            return $data['output']['text'];
        }
        return null;
    } else {
        // Phi‑4 / Chat Completions JSON. [web:11][web:26]
        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        return null;
    }
}

/* -------------------------------------------------------------------------
 *  STREAMING HANDLER  (used by ask_agent_ajax.php)
 * ------------------------------------------------------------------------- */

global $ai_functions_stream_buffer, $ai_functions_uses_responses_api;
$ai_functions_stream_buffer       = '';
$ai_functions_uses_responses_api  = false;

function local_ai_functions_handle_streaming(string $full_url, array $payload, string $api_key, bool $uses_responses_api): void {
    global $ai_functions_uses_responses_api, $ai_functions_stream_buffer;

    $ai_functions_uses_responses_api = $uses_responses_api;
    $ai_functions_stream_buffer      = '';

    // SSE headers: ask_agent_ajax.php can remove its duplicate headers.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    echo ": connected\n\n";
    if (ob_get_level() > 0) { ob_flush(); }
    flush();

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
        CURLOPT_WRITEFUNCTION  => 'local_ai_functions_stream_callback',
        CURLOPT_TIMEOUT        => 0,    // long-lived stream
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT=> 1,
        CURLOPT_LOW_SPEED_TIME => 240,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $ok       = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false || $curl_err !== '') {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'cURL error', 'message' => $curl_err]) . "\n\n";
        if (ob_get_level() > 0) { ob_flush(); }
        flush();
    } else if ($http_code !== 200) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'HTTP error', 'status_code' => $http_code]) . "\n\n";
        if (ob_get_level() > 0) { ob_flush(); }
        flush();
    }

    // Always send final done event so JS knows stream ended.
    echo "event: done\n";
    echo "data: {}\n\n";
    if (ob_get_level() > 0) { ob_flush(); }
    flush();
}

/**
 * cURL write callback: routes events to the right parser depending on API type.
 */
function local_ai_functions_stream_callback($curl, string $data): int {
    global $ai_functions_stream_buffer, $ai_functions_uses_responses_api;

    $ai_functions_stream_buffer .= $data;

    while (($pos = strpos($ai_functions_stream_buffer, "\n\n")) !== false) {
        $event = substr($ai_functions_stream_buffer, 0, $pos + 2);
        $ai_functions_stream_buffer = substr($ai_functions_stream_buffer, $pos + 2);

        if (trim($event) === '' || strpos($event, ':') === 0) {
            continue;
        }

        if ($ai_functions_uses_responses_api) {
            if (local_ai_functions_parse_responses_api_event($event)) {
                continue;
            }
        } else {
            if (local_ai_functions_parse_chat_completions_event($event)) {
                continue;
            }
        }
    }

    return strlen($data);
}

/**
 * GPT‑5‑mini / Responses API streaming parser. [web:4][web:22][web:27]
 */
function local_ai_functions_parse_responses_api_event(string $event): bool {
    if (!preg_match('/^event:\s*(.+)/m', $event, $event_matches)) {
        return false;
    }
    $event_type = trim($event_matches[1]);

    if (!preg_match('/^data:\s*(.+)/m', $event, $data_matches)) {
        return false;
    }
    $json_data = trim($data_matches[1]);
    $chunk     = json_decode($json_data, true);
    if (!is_array($chunk)) {
        return false;
    }

    switch ($event_type) {
        case 'response.content.delta':
            if (isset($chunk['delta']['text']) && $chunk['delta']['text'] !== '') {
                echo "event: chunk\n";
                echo "data: " . json_encode(['content' => $chunk['delta']['text']]) . "\n\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            }
            return true;

        case 'response.completed':
            echo "event: done\n";
            echo "data: {}\n\n";
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
            return true;

        case 'response.error':
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'API error', 'details' => $chunk]) . "\n\n";
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
            return true;

        default:
            return false;
    }
}

/**
 * Phi‑4 / Chat Completions streaming parser. [web:11][web:24][web:26]
 */
function local_ai_functions_parse_chat_completions_event(string $event): bool {
    if (!preg_match('/^data:\s*(.+)$/m', $event, $matches)) {
        return false;
    }

    $json_data = trim($matches[1]);

    if ($json_data === '[DONE]') {
        echo "event: done\n";
        echo "data: {}\n\n";
        if (ob_get_level() > 0) { ob_flush(); }
        flush();
        return true;
    }

    $chunk = json_decode($json_data, true);
    if (!is_array($chunk)) {
        return false;
    }

    if (isset($chunk['choices'][0]['delta']['content'])) {
        $content = $chunk['choices'][0]['delta']['content'];
        if ($content !== '') {
            echo "event: chunk\n";
            echo "data: " . json_encode(['content' => $content]) . "\n\n";
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
        }
    }

    if (isset($chunk['choices'][0]['finish_reason']) &&
        $chunk['choices'][0]['finish_reason'] !== null) {
        echo "event: metadata\n";
        echo "data: " . json_encode([
            'finish_reason' => $chunk['choices'][0]['finish_reason']
        ]) . "\n\n";
        flush();
    }

    return true;
}
