<?php
namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

class stream_token {
    public static function issue(int $userid, int $courseid, int $historyid, string $agentkey, int $expires): string {
        $payload = implode('|', [$userid, $courseid, $historyid, $agentkey, $expires]);
        $signature = hash_hmac('sha256', $payload, sesskey());
        return base64_encode($payload . '|' . $signature);
    }

    public static function verify(string $token, int $userid, int $courseid, int $historyid, string $agentkey): bool {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 6) {
            return false;
        }

        [$tokenuserid, $tokencourseid, $tokenhistoryid, $tokenagentkey, $expires, $signature] = $parts;
        if ((int)$tokenuserid !== $userid || (int)$tokencourseid !== $courseid || (int)$tokenhistoryid !== $historyid || $tokenagentkey !== $agentkey) {
            return false;
        }

        if ((int)$expires < time()) {
            return false;
        }

        $payload = implode('|', [$tokenuserid, $tokencourseid, $tokenhistoryid, $tokenagentkey, $expires]);
        $expected = hash_hmac('sha256', $payload, sesskey());
        return hash_equals($expected, $signature);
    }
}
