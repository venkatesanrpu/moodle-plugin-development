<?php
defined("MOODLE_INTERNAL") || die();

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
function local_ai_functions_call_endpoint(
    string $agentconfigkey,
    string $functionname,
    array $payload
): void {
    global $DB;

    $stream = !empty($payload["stream"]);

    if (!$stream) {
        throw new moodle_exception(
            "Streaming disabled in payload; use lib4-non_streaming.php for non-streaming calls."
        );
    }

    // 1. Load agent row.
    $agent = $DB->get_record("local_ai_functions_agents", [
        "agent_key" => $agentconfigkey,
    ]);
    if (!$agent) {
        error_log("Phi-4 STREAM ERROR: Agent not found: {$agentconfigkey}");
        header("Content-Type: text/event-stream");
        echo "event: error\n";
        echo "data: " .
            json_encode(["error" => "Agent not found: {$agentconfigkey}"]) .
            "\n\n";
        flush();
        return;
    }

    // 2. Parse config_data JSON and pick function config.
    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        error_log(
            "Phi-4 STREAM ERROR: Function not configured: {$functionname}"
        );
        header("Content-Type: text/event-stream");
        echo "event: error\n";
        echo "data: " .
            json_encode([
                "error" => "Function not configured: {$functionname}",
            ]) .
            "\n\n";
        flush();
        return;
    }

    $function_config = $config[$functionname];

    $endpoint = $function_config["endpoint"] ?? "";
    $api_key = $function_config["api_key"] ?? "";
    $api_version = $function_config["api_version"] ?? "2025-04-01-preview";
    $model = $function_config["model"] ?? "gpt-5-mini";

    if ($endpoint === "" || $api_key === "") {
        error_log(
            "Phi-4 STREAM ERROR: Invalid agent configuration (endpoint/api_key missing)"
        );
        header("Content-Type: text/event-stream");
        echo "event: error\n";
        echo "data: " .
            json_encode([
                "error" =>
                    "Invalid agent configuration (endpoint/api_key missing)",
            ]) .
            "\n\n";
        flush();
        return;
    }

    // Ensure model in payload (original behaviour).
    if (!isset($payload["model"])) {
        $payload["model"] = $model;
    }

    $full_url = $endpoint . "?api-version=" . urlencode($api_version); // azure foundry model
    //$full_url = $endpoint; // kimi-k2-model

    error_log("AI Functions - STREAMING MODE ACTIVATED (Phi-4)");
    error_log("AI Functions - Full URL: {$full_url}");
    error_log("AI Functions - Payload: " . json_encode($payload));

    // 4. cURL streaming call.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $full_url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $api_key,
            "Accept: text/event-stream",
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => "local_ai_functions_phi4_stream_callback",
        CURLOPT_TIMEOUT => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => 240,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_BUFFERSIZE => 128000,
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_VERBOSE => true,
        //CURLOPT_STDERR         => fopen('D:\temp\curl_verbose.log', 'w+'),
    ]);

    $curl_result = curl_exec($ch);
    $curl_error = curl_error($ch);

    if ($curl_error) {
        error_log("Phi-4 STREAM CURL ERROR: " . $curl_error);
        echo "event: error\n";
        echo "data: " . json_encode(["error" => $curl_error]) . "\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    curl_close($ch);

    // Always send final done event
    echo "event: done\n";
    echo "data: {}\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * cURL write callback for Phi-4 / Chat Completions streaming.
 * Emits:
 *  - event: chunk      data: {"content":"..."}
 *  - event: metadata   data: {"finish_reason":"..."}
 *  - event: done       data: {}
 */
function local_ai_functions_phi4_stream_callback($curl, string $data): int
{
    static $buffer = "";

    // Azure uses CRLF; normalize so "\n\n" splitting works.
    $buffer .= str_replace("\r\n", "\n", $data);
    $length = strlen($data);

    while (($pos = strpos($buffer, "\n\n")) !== false) {
        $eventblock = substr($buffer, 0, $pos + 2);
        $buffer = substr($buffer, $pos + 2);

        // Get the data line (simple case: single data: per event).
        if (!preg_match('/^data:\s*(.+)$/m', $eventblock, $m)) {
            continue;
        }

        $json = trim($m[1]);
        if ($json === "" || $json === "[DONE]") {
            continue;
        }

        $evt = json_decode($json, true);
        if (!$evt) {
            continue;
        }

        // Responses API streaming
        if (($evt["type"] ?? "") === "response.output_text.delta") {
            $delta = $evt["delta"] ?? "";
            if ($delta !== "") {
                echo "event: chunk\n";
                echo "data: " . json_encode(["content" => $delta]) . "\n\n";
                @ob_flush();
                flush();
            }
            continue;
        }

        // Optional: finish signals from Responses API
        if (($evt["type"] ?? "") === "response.completed") {
            echo "event: metadata\n";
            echo "data: " .
                json_encode([
                    "status" => $evt["response"]["status"] ?? null,
                    "incomplete_details" =>
                        $evt["response"]["incomplete_details"] ?? null,
                    "usage" => $evt["response"]["usage"] ?? null,
                ]) .
                "\n\n";
            @ob_flush();
            flush();

            echo "event: done\n";
            echo "data: {}\n\n";
            @ob_flush();
            flush();
            continue;
        }

        // Chat Completions streaming (your old logic)
        if (isset($evt["choices"][0]["delta"]["content"])) {
            $content = $evt["choices"][0]["delta"]["content"];
            if ($content !== "") {
                echo "event: chunk\n";
                echo "data: " . json_encode(["content" => $content]) . "\n\n";
                @ob_flush();
                flush();
            }
        }
    }

    return $length;
}