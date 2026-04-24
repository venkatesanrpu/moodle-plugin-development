<?php
namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\agent_repository;
use block_ai_assistant_v2\local\history_repository;
use block_ai_assistant_v2\local\prompt_builder;
use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

class ask_mcq extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'blockinstanceid' => new external_value(PARAM_INT, 'Block instance ID'),
            'agentkey' => new external_value(PARAM_ALPHANUMEXT, 'Agent key'),
            'subject' => new external_value(PARAM_TEXT, 'Subject', VALUE_DEFAULT, ''),
            'topic' => new external_value(PARAM_TEXT, 'Topic', VALUE_DEFAULT, ''),
            'lesson' => new external_value(PARAM_TEXT, 'Lesson', VALUE_DEFAULT, ''),
            'count' => new external_value(PARAM_INT, 'Number of questions', VALUE_DEFAULT, 10),
            'difficulty' => new external_value(PARAM_ALPHA, 'Difficulty', VALUE_DEFAULT, 'medium'),
        ]);
    }

    public static function execute(
        int $courseid,
        int $blockinstanceid,
        string $agentkey,
        string $subject = '',
        string $topic = '',
        string $lesson = '',
        int $count = 10,
        string $difficulty = 'medium'
    ): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
            'agentkey' => $agentkey,
            'subject' => $subject,
            'topic' => $topic,
            'lesson' => $lesson,
            'count' => $count,
            'difficulty' => $difficulty,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:use', $context);

        agent_repository::require_function($params['agentkey'], 'mcq_agent');

        if (!function_exists('local_ai_functions_v2_call_endpoint')) {
            require_once($CFG->dirroot . '/local/ai_functions_v2/libagent.php');
        }

        $course = get_course($params['courseid']);
        $count = max(1, min(20, (int)$params['count']));
        $difficulty = in_array($params['difficulty'], ['easy', 'medium', 'hard', 'mixed'], true)
            ? $params['difficulty']
            : 'medium';

        $systemprompt = prompt_builder::build_mcq_prompt([
            'course' => $course->shortname ?? '',
            'subject' => $params['subject'],
            'topic' => $params['topic'],
            'lesson' => $params['lesson'],
            'count' => $count,
            'difficulty' => $difficulty,
        ]);

        $payload = [
            'system_prompt' => $systemprompt,
            'user_prompt' => 'Generate structured MCQs only.',
            'stream' => false,
            'options' => [
                'max_output_tokens' => 5000,
                'response_format' => 'json_object',
            ],
        ];

        $raw = local_ai_functions_v2_call_endpoint($params['agentkey'], 'mcq_agent', $payload);
        if (is_array($raw) && array_key_exists('content', $raw)) {
            $raw = $raw['content'];
        }
        if (!is_string($raw)) {
            $raw = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $decoded = self::decode_mcq_payload((string)$raw);
        if (!is_array($decoded) || empty($decoded['questions']) || !is_array($decoded['questions'])) {
            throw new moodle_exception('invalidmcqresponse', 'block_ai_assistant_v2');
        }

        $questions = [];
        foreach ($decoded['questions'] as $index => $question) {
            if (empty($question['question']) || empty($question['options']) || !is_array($question['options'])) {
                continue;
            }
            $options = [];
            foreach ($question['options'] as $key => $value) {
                if (is_array($value)) {
                    $label = $value['label'] ?? (string)($value['key'] ?? $key);
                    $text = $value['text'] ?? (string)($value['value'] ?? '');
                } else {
                    $label = is_string($key) ? strtoupper($key) : chr(65 + (int)$key);
                    $text = (string)$value;
                }
                if ($text === '') {
                    continue;
                }
                $options[] = [
                    'label' => (string)$label,
                    'text' => $text,
                ];
            }
            if (count($options) < 2) {
                continue;
            }
            $answer = strtoupper(trim((string)($question['answer'] ?? '')));
            $questions[] = [
                'number' => $index + 1,
                'question' => trim((string)$question['question']),
                'options' => $options,
                'answer' => $answer,
                'explanation' => trim((string)($question['explanation'] ?? '')),
            ];
        }

        if (!$questions) {
            throw new moodle_exception('invalidmcqresponse', 'block_ai_assistant_v2');
        }

        $record = (object)[
            'userid' => $USER->id,
            'courseid' => $params['courseid'],
            'usertext' => 'MCQ practice request',
            'botresponse' => json_encode([
                'questions' => $questions,
                'difficulty' => $difficulty,
                'count' => count($questions),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'functioncalled' => 'mcq_agent',
            'subject' => trim((string)$params['subject']),
            'topic' => trim((string)$params['topic']),
            'lesson' => trim((string)$params['lesson']),
            'metadata' => json_encode([
                'mode' => 'mcq',
                'difficulty' => $difficulty,
                'requestedcount' => $count,
                'returnedcount' => count($questions),
                'agentkey' => $params['agentkey'],
                'blockinstanceid' => $params['blockinstanceid'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $historyid = history_repository::insert($record);

        return [
            'historyid' => $historyid,
            'questions' => $questions,
            'difficulty' => $difficulty,
            'count' => count($questions),
        ];
    }

    private static function decode_mcq_payload(string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}$/', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'historyid' => new external_value(PARAM_INT, 'Saved history ID'),
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'number' => new external_value(PARAM_INT, 'Question number'),
                    'question' => new external_value(PARAM_RAW, 'Question'),
                    'options' => new external_multiple_structure(
                        new external_single_structure([
                            'label' => new external_value(PARAM_TEXT, 'Option label'),
                            'text' => new external_value(PARAM_RAW, 'Option text'),
                        ])
                    ),
                    'answer' => new external_value(PARAM_TEXT, 'Correct answer'),
                    'explanation' => new external_value(PARAM_RAW, 'Explanation'),
                ])
            ),
            'difficulty' => new external_value(PARAM_TEXT, 'Difficulty'),
            'count' => new external_value(PARAM_INT, 'Returned question count'),
        ]);
    }
}
