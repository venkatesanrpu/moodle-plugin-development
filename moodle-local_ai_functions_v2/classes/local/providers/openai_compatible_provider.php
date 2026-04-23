<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\providers;

defined('MOODLE_INTERNAL') || die();

use local_ai_functions_v2\local\contracts\provider_interface;
use moodle_exception;

/**
 * Adapter for OpenAI-compatible endpoints.
 */
class openai_compatible_provider implements provider_interface {
    /**
     * @inheritDoc
     */
    public function execute(array $providerconfig, array $functionconfig, array $request): ?array {
        $stream = !empty($request['stream']);
        $body = $this->build_http_body($providerconfig, $functionconfig, $request);
        $headers = $this->build_headers($providerconfig);
        $endpoint = $this->build_url($providerconfig);

        if ($stream) {
            $this->execute_stream($endpoint, $headers, $body, $providerconfig);
            return null;
        }

        return $this->execute_nonstream($endpoint, $headers, $body, $providerconfig);
    }

    /**
     * Build the provider URL.
     *
     * @param array $providerconfig
     * @return string
     */
    protected function build_url(array $providerconfig): string {
        $endpoint = (string)$providerconfig['endpoint'];
        $apiversion = $providerconfig['api_version'] ?? null;

        if ($apiversion && strpos($endpoint, 'api-version=') === false) {
            $separator = (strpos($endpoint, '?') === false) ? '?' : '&';
            $endpoint .= $separator . 'api-version=' . rawurlencode((string)$apiversion);
        }

        return $endpoint;
    }

    /**
     * Build HTTP headers.
     *
     * @param array $providerconfig
     * @return array
     */
    protected function build_headers(array $providerconfig): array {
        $headers = ['Content-Type: application/json'];
        $authtype = $providerconfig['auth_type'] ?? 'bearer';

        if ($authtype === 'api-key') {
            $headers[] = 'api-key: ' . $providerconfig['api_key'];
        } else {
            $headers[] = 'Authorization: Bearer ' . $providerconfig['api_key'];
        }

        if (!empty($providerconfig['extra_headers']) && is_array($providerconfig['extra_headers'])) {
            foreach ($providerconfig['extra_headers'] as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        return $headers;
    }

    /**
     * Build provider request body.
     *
     * @param array $providerconfig
     * @param array $functionconfig
     * @param array $request
     * @return array
     */
    protected function build_http_body(array $providerconfig, array $functionconfig, array $request): array {
        $apistyle = $providerconfig['api_style'] ?? 'chat_completions';
        $model = $functionconfig['model'] ?? null;
        $options = array_filter($request['options'] ?? [], static fn($value) => $value !== null);

        if ($apistyle === 'responses') {
            $body = [
                'model' => $model,
                'stream' => !empty($request['stream']),
                'input' => [
                    ['role' => 'system', 'content' => $request['system_prompt']],
                    ['role' => 'user', 'content' => $request['user_prompt']],
                ],
            ];

            if (!empty($request['json_schema'])) {
                $body['text'] = [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'response_schema',
                        'schema' => $request['json_schema'],
                    ],
                ];
            }

            return array_merge($body, $options);
        }

        $body = [
            'model' => $model,
            'stream' => !empty($request['stream']),
            'messages' => [
                ['role' => 'system', 'content' => $request['system_prompt']],
                ['role' => 'user', 'content' => $request['user_prompt']],
            ],
        ];

        if (!empty($request['json_schema'])) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response_schema',
                    'schema' => $request['json_schema'],
                ],
            ];
        }

        return array_merge($body, $options);
    }

    /**
     * Execute non-streaming request.
     *
     * @param string $endpoint
     * @param array $headers
     * @param array $body
     * @param array $providerconfig
     * @return array
     */
    protected function execute_nonstream(string $endpoint, array $headers, array $body, array $providerconfig): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 240,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new moodle_exception('Provider request failed: ' . $error);
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new moodle_exception('Provider HTTP error: ' . $httpcode . ' ' . substr((string)$response, 0, 500));
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new moodle_exception('Provider response is not valid JSON.');
        }

        return [
            'content' => $this->extract_content($decoded, $providerconfig),
            'metadata' => $this->extract_metadata($decoded),
            'raw' => $decoded,
        ];
    }

    /**
     * Execute streaming request and emit normalised SSE events.
     *
     * @param string $endpoint
     * @param array $headers
     * @param array $body
     * @param array $providerconfig
     */
    protected function execute_stream(string $endpoint, array $headers, array $body, array $providerconfig): void {
        $buffer = '';
        $done = false;

        $streamheaders = $headers;
        $streamheaders[] = 'Accept: text/event-stream';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $streamheaders,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 240,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION => function($curl, string $data) use (&$buffer, &$done, $providerconfig) {
                $buffer .= $data;
                $length = strlen($data);

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $pos + 2);
                    $buffer = substr($buffer, $pos + 2);

                    $lines = preg_split('/\r?\n/', trim($event));
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
                            continue;
                        }

                        $json = trim(substr($line, 5));
                        if ($json === '[DONE]') {
                            $this->emit_event('done', []);
                            $done = true;
                            continue;
                        }

                        $chunk = json_decode($json, true);
                        if (!is_array($chunk)) {
                            continue;
                        }

                        $delta = $this->extract_stream_delta($chunk, $providerconfig);
                        if ($delta !== '') {
                            $this->emit_event('chunk', ['content' => $delta]);
                        }

                        $finishreason = $this->extract_stream_finish_reason($chunk);
                        if ($finishreason !== null) {
                            $this->emit_event('metadata', ['finish_reason' => $finishreason]);
                        }

                        if (($chunk['type'] ?? '') === 'response.completed') {
                            $this->emit_event('done', []);
                            $done = true;
                        }
                    }
                }

                return $length;
            },
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            $this->emit_event('error', ['error' => $error]);
        }

        if (!$done) {
            $this->emit_event('done', []);
        }
    }

    /**
     * Emit one SSE event.
     *
     * @param string $event
     * @param array $data
     */
    protected function emit_event(string $event, array $data): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }

    /**
     * Extract text from non-streaming JSON.
     *
     * @param array $decoded
     * @param array $providerconfig
     * @return string
     */
    protected function extract_content(array $decoded, array $providerconfig): string {
        $apistyle = $providerconfig['api_style'] ?? 'chat_completions';

        if ($apistyle === 'responses') {
            foreach (($decoded['output'] ?? []) as $item) {
                foreach (($item['content'] ?? []) as $contentpart) {
                    if (($contentpart['type'] ?? '') === 'output_text' && isset($contentpart['text'])) {
                        return (string)$contentpart['text'];
                    }
                }
            }
            return '';
        }

        $messagecontent = $decoded['choices'][0]['message']['content'] ?? '';
        if (is_array($messagecontent)) {
            $parts = [];
            foreach ($messagecontent as $item) {
                if (is_array($item) && isset($item['text'])) {
                    $parts[] = $item['text'];
                }
            }
            return implode('', $parts);
        }

        return (string)$messagecontent;
    }

    /**
     * Extract generic metadata.
     *
     * @param array $decoded
     * @return array
     */
    protected function extract_metadata(array $decoded): array {
        return [
            'id' => $decoded['id'] ?? null,
            'model' => $decoded['model'] ?? null,
            'finish_reason' => $decoded['choices'][0]['finish_reason'] ?? null,
            'usage' => $decoded['usage'] ?? null,
        ];
    }

    /**
     * Extract text delta from a streaming event.
     *
     * @param array $chunk
     * @param array $providerconfig
     * @return string
     */
    protected function extract_stream_delta(array $chunk, array $providerconfig): string {
        $apistyle = $providerconfig['api_style'] ?? 'chat_completions';

        if ($apistyle === 'responses') {
            if (($chunk['type'] ?? '') === 'response.output_text.delta' && isset($chunk['delta'])) {
                return (string)$chunk['delta'];
            }
            return '';
        }

        return (string)($chunk['choices'][0]['delta']['content'] ?? '');
    }

    /**
     * Extract finish reason from a streaming event.
     *
     * @param array $chunk
     * @return string|null
     */
    protected function extract_stream_finish_reason(array $chunk): ?string {
        if (isset($chunk['choices'][0]['finish_reason']) && $chunk['choices'][0]['finish_reason'] !== null) {
            return (string)$chunk['choices'][0]['finish_reason'];
        }
        if (($chunk['type'] ?? '') === 'response.completed') {
            return 'completed';
        }
        return null;
    }
}
