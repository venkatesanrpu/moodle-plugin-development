<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\modules;

defined('MOODLE_INTERNAL') || die();

use local_ai_functions_v2\local\contracts\module_interface;

/**
 * Structured MCQ generation module.
 */
class mcq_module implements module_interface {
    /**
     * @inheritDoc
     */
    public function build_request(array $payload, array $functionconfig): array {
        $systemprompt = (string)($payload['system_prompt'] ?? '');
        $userprompt = (string)($payload['user_prompt'] ?? ($payload['prompt'] ?? ''));
        $stream = array_key_exists('stream', $payload) ? !empty($payload['stream']) : !empty($functionconfig['stream']);

        return [
            'task' => 'mcq',
            'system_prompt' => $systemprompt,
            'user_prompt' => $userprompt,
            'stream' => $stream,
            'response_format' => 'json',
            'json_schema' => $payload['json_schema'] ?? null,
            'difficulty' => $payload['difficulty'] ?? null,
            'options' => array_merge(
                [
                    'temperature' => $functionconfig['temperature'] ?? null,
                    'max_output_tokens' => $functionconfig['max_output_tokens'] ?? null,
                ],
                $payload['options'] ?? []
            ),
        ];
    }

    /**
     * @inheritDoc
     */
    public function normalise_result(array $providerresult, array $functionconfig): array {
        $decoded = json_decode((string)($providerresult['content'] ?? ''), true);

        return [
            'status' => 'ok',
            'format' => 'json',
            'content' => $decoded !== null ? $decoded : (string)($providerresult['content'] ?? ''),
            'metadata' => $providerresult['metadata'] ?? [],
            'raw' => $providerresult['raw'] ?? null,
        ];
    }
}
