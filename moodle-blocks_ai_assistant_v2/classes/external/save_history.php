<?php
namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\history_repository;
use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class save_history extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'historyid' => new external_value(PARAM_INT, 'History ID'),
            'botresponse' => new external_value(PARAM_RAW, 'Final bot response'),
            'metadata' => new external_value(PARAM_RAW, 'Metadata json', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function execute(int $courseid, int $historyid, string $botresponse, string $metadata = '{}'): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'historyid' => $historyid,
            'botresponse' => $botresponse,
            'metadata' => $metadata,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:use', $context);

        $record = history_repository::get_record([
            'id' => $params['historyid'],
            'courseid' => $params['courseid'],
            'userid' => $USER->id,
        ], '*', MUST_EXIST);

        $record->botresponse = $params['botresponse'];
        $record->metadata = $params['metadata'];
        $record->timemodified = time();
        history_repository::update($record);

        return ['status' => 'saved'];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Save status'),
        ]);
    }
}
