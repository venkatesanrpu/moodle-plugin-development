<?php
defined('MOODLE_INTERNAL') || die();

function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload): void {
    global $DB;

    // Buffer for partial SSE frames coming from Azure.
    $buffer = '';
    $sawCompleted = false;

    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    if (!$agent) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => "Agent not found: {$agentconfigkey}"]) . "\n\n";
        flush();
        return;
    }

    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => "Function not configured: {$functionname}"]) . "\n\n";
        flush();
        return;
    }

    $function_config = $config[$functionname];

    $endpoint    = $function_config['endpoint']    ?? '';
    $api_key     = $function_config['api_key']     ?? '';
    $api_version = $function_config['api_version'] ?? '2025-04-01-preview';
    $model       = $function_config['model']       ?? 'gpt-5-mini';

    if ($endpoint === '' || $api_key === '') {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)']) . "\n\n";
        flush();
        return;
    }

    // Normalize endpoint for Responses API.
    if (strpos($endpoint, '/openai/responses') === false) {
        $endpoint = rtrim($endpoint, '/') . '/openai/responses';
    }

    // Normalize payload for Responses API.
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
        $payload['max_output_tokens'] = 2048;
    }
    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }

    //$full_url = $endpoint . '?api-version=' . urlencode($api_version);
	$full_url = $endpoint;

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
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT=> 1,
        CURLOPT_LOW_SPEED_TIME => 240,
        CURLOPT_SSL_VERIFYPEER => true,

        // Important: disable libcurl's own buffering effects as much as possible.
        CURLOPT_TCP_NODELAY    => true,

        CURLOPT_WRITEFUNCTION  => function($curl, string $data) use (&$buffer, &$sawCompleted): int {
            $buffer .= $data;

            // SSE frames are separated by a blank line.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $pos);     // WITHOUT the delimiter
                $buffer = substr($buffer, $pos + 2);   // skip the delimiter

                $frame = trim($frame);
                if ($frame === '' || str_starts_with($frame, ':')) {
                    continue; // comment/keepalive
                }

                // Parse "event:" (single) and *all* "data:" lines (possibly many).
                $eventType = null;
                $dataLines = [];

                foreach (preg_split("/\r?\n/", $frame) as $line) {
                    if (str_starts_with($line, 'event:')) {
                        $eventType = trim(substr($line, 6));
                        continue;
                    }
                    if (str_starts_with($line, 'data:')) {
                        $dataLines[] = ltrim(substr($line, 5)); // keep leading spaces after "data:"
                        continue;
                    }
                }

                if ($eventType === null || empty($dataLines)) {
                    continue;
                }

                // SSE spec: multiple data lines are concatenated with "\n". [web:137][web:164]
                $json_data = implode("\n", $dataLines);
                $chunk = json_decode($json_data, true);
                if (!is_array($chunk)) {
                    // If Azure ever sends non-JSON data lines, ignore safely.
                    continue;
                }

                switch ($eventType) {
                    case 'response.output_text.delta':
                        if (!empty($chunk['delta'])) {
                            echo "event: chunk\n";
                            echo "data: " . json_encode(['content' => $chunk['delta']]) . "\n\n";
                            if (ob_get_level() > 0) { @ob_flush(); }
                            flush();
                        }
                        break;

                    case 'response.completed':
                        $meta = [];

                        if (isset($chunk['response']['usage'])) {
                            $meta['usage'] = $chunk['response']['usage'];
                        }
                        if (isset($chunk['response']['id'])) {
                            $meta['response_id'] = $chunk['response']['id'];
                        }
                        if (isset($chunk['response']['finish_reason'])) {
                            $meta['finish_reason'] = $chunk['response']['finish_reason'];
                        }

                        if (!empty($meta)) {
                            echo "event: metadata\n";
                            echo "data: " . json_encode($meta) . "\n\n";
                            if (ob_get_level() > 0) { @ob_flush(); }
                            flush();
                        }

                        // Emit DONE ONCE, here.
                        $sawCompleted = true;
                        echo "event: done\n";
                        echo "data: {}\n\n";
                        if (ob_get_level() > 0) { @ob_flush(); }
                        flush();
                        break;

                    case 'response.error':
                        echo "event: error\n";
                        echo "data: " . json_encode(['error' => 'API error', 'details' => $chunk]) . "\n\n";
                        if (ob_get_level() > 0) { @ob_flush(); }
                        flush();
                        break;

                    // Ignore other lifecycle events.
                    default:
                        break;
                }
            }

            return strlen($data);
        },
    ]);

    $ok       = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false || $curl_err !== '') {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'cURL error', 'message' => $curl_err, 'status_code' => $http_code]) . "\n\n";
        if (ob_get_level() > 0) { @ob_flush(); }
        flush();
        return;
    }

    // IMPORTANT: do NOT emit "done" here unconditionally.
    // If Azure already sent response.completed, you already emitted done.
    // If Azure never sent it, emit done now as a fallback.
    if (!$sawCompleted) {
        echo "event: done\n";
        echo "data: " . json_encode(['note' => 'done_without_response_completed', 'status_code' => $http_code]) . "\n\n";
        if (ob_get_level() > 0) { @ob_flush(); }
        flush();
    }
}
