<?php
namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

class sse_response {
    public static function send(string $event, array $payload): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }
}
