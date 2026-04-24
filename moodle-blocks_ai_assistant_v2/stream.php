<?php
@ini_set('output_buffering', 'off');
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ai_functions_v2/libagent.php');

global $DB, $USER;

require_login();
$courseid = required_param('courseid', PARAM_INT);
$historyid = required_param('historyid', PARAM_INT);
$agentkey = required_param('agentkey', PARAM_ALPHANUMEXT);
$sesskeyparam = required_param('sesskey', PARAM_ALPHANUM);
$token = required_param('token', PARAM_RAW);

if (!confirm_sesskey($sesskeyparam)) {
    http_response_code(403);
    exit;
}

$course = get_course($courseid);
require_login($course, false);
$context = context_course::instance($courseid);
require_capability('block/ai_assistant_v2:use', $context);

if (!\block_ai_assistant_v2\local\stream_token::verify($token, $USER->id, $courseid, $historyid, $agentkey)) {
    http_response_code(403);
    exit;
}

$record = \block_ai_assistant_v2\local\history_repository::get_record([
    'id' => $historyid,
    'courseid' => $courseid,
    'userid' => $USER->id,
], '*', MUST_EXIST);

\block_ai_assistant_v2\local\agent_repository::require_function($agentkey, 'notes_agent');

session_write_close();
ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

\block_ai_assistant_v2\local\sse_response::send('status', ['message' => 'connected']);

try {
    $payload = [
        'system_prompt' => \block_ai_assistant_v2\local\prompt_builder::build_notes_prompt([
            'course' => $course->shortname ?? '',
            'subject' => $record->subject ?? '',
            'topic' => $record->topic ?? '',
            'lesson' => $record->lesson ?? '',
        ], (string)$record->usertext),
        'user_prompt' => (string)$record->usertext,
        'stream' => true,
        'options' => [
            'max_output_tokens' => 5000,
        ],
    ];

    local_ai_functions_v2_call_endpoint($agentkey, 'notes_agent', $payload);
    \block_ai_assistant_v2\local\sse_response::send('done', ['historyid' => $historyid]);
} catch (Throwable $e) {
    \block_ai_assistant_v2\local\sse_response::send('error', ['error' => $e->getMessage(), 'historyid' => $historyid]);
}
