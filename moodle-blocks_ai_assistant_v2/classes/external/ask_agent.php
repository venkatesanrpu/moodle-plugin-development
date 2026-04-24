<?php
namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\agent_repository;
use block_ai_assistant_v2\local\history_repository;
use block_ai_assistant_v2\local\stream_token;
use context_course;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

class ask_agent extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'blockinstanceid' => new external_value(PARAM_INT, 'Block instance ID'),
            'agentkey' => new external_value(PARAM_ALPHANUMEXT, 'Agent key'),
            'usertext' => new external_value(PARAM_RAW, 'User prompt'),
            'subject' => new external_value(PARAM_TEXT, 'Subject', VALUE_DEFAULT, ''),
            'topic' => new external_value(PARAM_TEXT, 'Topic', VALUE_DEFAULT, ''),
            'lesson' => new external_value(PARAM_TEXT, 'Lesson', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $courseid, int $blockinstanceid, string $agentkey, string $usertext, string $subject = '', string $topic = '', string $lesson = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
            'agentkey' => $agentkey,
            'usertext' => $usertext,
            'subject' => $subject,
            'topic' => $topic,
            'lesson' => $lesson,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:use', $context);

        $functionconfig = agent_repository::require_function($params['agentkey'], 'notes_agent');

        $now = time();
        $record = (object)[
            'userid' => $USER->id,
            'courseid' => $params['courseid'],
            'usertext' => trim((string)$params['usertext']),
            'botresponse' => '',
            'functioncalled' => 'notes_agent',
            'subject' => trim((string)$params['subject']),
            'topic' => trim((string)$params['topic']),
            'lesson' => trim((string)$params['lesson']),
            'metadata' => json_encode([
                'status' => 'pending',
                'agentkey' => $params['agentkey'],
                'blockinstanceid' => $params['blockinstanceid'],
                'functionkey' => 'notes_agent',
                'module' => $functionconfig['module'] ?? 'notes_agent',
                'requestedstream' => !empty($functionconfig['stream']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $historyid = history_repository::insert($record);
        $expires = $now + (15 * MINSECS);
        $streamtoken = stream_token::issue($USER->id, $params['courseid'], $historyid, $params['agentkey'], $expires);

        return [
            'historyid' => $historyid,
            'status' => 'pending',
            'streamtoken' => $streamtoken,
            'expires' => $expires,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'historyid' => new external_value(PARAM_INT, 'History row ID'),
            'status' => new external_value(PARAM_TEXT, 'Request status'),
            'streamtoken' => new external_value(PARAM_RAW, 'Signed stream token'),
            'expires' => new external_value(PARAM_INT, 'Expiry timestamp'),
        ]);
    }
}
