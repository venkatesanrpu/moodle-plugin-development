<?php
namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\syllabus_repository;
use context_course;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

class get_syllabus extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'blockinstanceid' => new external_value(PARAM_INT, 'Block instance ID'),
        ]);
    }

    public static function execute(int $courseid, int $blockinstanceid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:use', $context);

        $record = syllabus_repository::get_for_block($params['blockinstanceid']);

        return [
            'syllabusjson' => $record ? (string)($record->syllabus_json ?? '') : '[]',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'syllabusjson' => new external_value(PARAM_RAW, 'Syllabus JSON'),
        ]);
    }
}
