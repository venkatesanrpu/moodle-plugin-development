<?php
defined('MOODLE_INTERNAL') || die();

function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload): void {
    global $DB;

    if (empty($payload['stream'])) {
        throw new moodle_exception('Streaming disabled in payload.');
    }

    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey], '*', IGNORE_MISSING);
    if (!$agent) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => "Agent not found: {$agentconfigkey}"], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "event: done\n";
        echo "data: {}\n\n";
        flush();
        return;
    }

    $config = json_decode($agent->config_data, true);
    if (!is_array($config) || empty($config[$functionname])) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => "Function not configured: {$functionname}"], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "event: done\n";
        echo "data: {}\n\n";
        flush();
        return;
    }

    $fn = $config[$functionname];
    $endpoint = $fn['endpoint'] ?? '';
    $api_key  = $fn['api_key'] ?? '';
    $model    = $fn['model'] ?? null;

    if ($endpoint === '' || $api_key === '') {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)'], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "event: done\n";
        echo "data: {}\n\n";
        flush();
        return;
    }

    if (!isset($payload['model']) && $model) {
        $payload['model'] = $model;
    }

    $GLOBALS['parallel_stream_done_sent'] = false;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
            'Accept: text/event-stream',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => 'local_ai_functions_parallel_stream_callback',
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT=> 1,
        CURLOPT_LOW_SPEED_TIME => 240,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_BUFFERSIZE     => 128000,
        CURLOPT_TCP_NODELAY    => true,
    ]);

    curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => $curl_error], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    if (empty($GLOBALS['parallel_stream_done_sent'])) {
        echo "event: done\n";
        echo "data: {}\n\n";
        flush();
    }
}

/**
 * Converts provider SSE -> your UI SSE
 * Emits:
 *  event: chunk    data: {"content":"..."}
 *  event: metadata data: {"finish_reason":"..."}
 *  event: error    data: {"error":"..."}
 *  event: done     data: {}
 */
function local_ai_functions_parallel_stream_callback($curl, string $data): int {
    static $buffer = '';
    $buffer .= $data;
    $len = strlen($data);

    while (($pos = strpos($buffer, "\n\n")) !== false) {
        $event = substr($buffer, 0, $pos + 2);
        $buffer = substr($buffer, $pos + 2);

        $lines = preg_split("/\r?\n/", trim($event));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ':')) continue;
            if (!str_starts_with($line, 'data:')) continue;

            $json_data = trim(substr($line, 5));

            if ($json_data === '[DONE]') {
                echo "event: done\n";
                echo "data: {}\n\n";
                $GLOBALS['parallel_stream_done_sent'] = true;
                flush();
                continue;
            }

            $chunk = json_decode($json_data, true);
            if (!is_array($chunk)) continue;

            if (isset($chunk['error'])) {
                echo "event: error\n";
                echo "data: " . json_encode(['error' => $chunk['error']], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
                continue;
            }

            $delta = $chunk['choices'][0]['delta']['content'] ?? '';
            if (is_string($delta) && $delta !== '') {
                echo "event: chunk\n";
                echo "data: " . json_encode(['content' => $delta], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            }

            $finish = $chunk['choices'][0]['finish_reason'] ?? null;
            if ($finish !== null) {
                echo "event: metadata\n";
                echo "data: " . json_encode(['finish_reason' => $finish], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            }
        }
    }

    return $len;
}

