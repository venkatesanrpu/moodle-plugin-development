<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function: render_response
 *
 * Fetches raw botresponse from DB by historyid, runs it through
 * render_helper::render() (Parsedown + math protection + clean_text),
 * and returns the sanitised HTML string.
 *
 * Security: verifies the requesting user owns the history row and
 * is enrolled in the associated course.
 *
 * @package   block_ai_assistant_v2
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;
use block_ai_assistant_v2\local\render_helper;

class render_response extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'historyid' => new external_value(PARAM_INT, 'History row ID'),
            'courseid'  => new external_value(PARAM_INT, 'Course ID for context check'),
        ]);
    }

    public static function execute(int $historyid, int $courseid): array {
        global $DB, $USER;

        // Validate parameters.
        ['historyid' => $historyid, 'courseid' => $courseid] =
            self::validate_parameters(self::execute_parameters(), [
                'historyid' => $historyid,
                'courseid'  => $courseid,
            ]);

        // Security: validate course context and enrollment.
        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:view', $context);

        // Fetch the history row — must belong to this user and course.
        $row = $DB->get_record('block_ai_assistant_v2_history', [
            'id'       => $historyid,
            'userid'   => (int)$USER->id,
            'courseid' => $courseid,
        ], 'id, botresponse', MUST_EXIST);

        $raw = (string)($row->botresponse ?? '');

        // Render: math-safe Markdown → sanitised HTML.
        $html = render_helper::render($raw);

        return ['html' => $html];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered HTML'),
        ]);
    }
}
