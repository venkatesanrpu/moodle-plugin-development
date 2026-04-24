<?php
/**
 * render_response external function.
 *
 * Called by widget.js renderNode() after save_history() to convert the stored
 * raw markdown+LaTeX botresponse into safe rendered HTML.
 *
 * phase7_6e fix:
 *   - Uses history_repository::get_record() (NOT raw $DB->get_record with TABLE const)
 *     because history_repository resolves the correct table name dynamically
 *     (handles both 'block_ai_assistant_v2_history' and 'block_ai_assistant_history').
 *   - Uses IGNORE_MISSING — never throws MUST_EXIST exception on historyid mismatch.
 *   - Uses render_helper::render() which uses strip_tags() NOT clean_text/HTMLPurifier.
 *     Backslashes in \( \[ survive intact → MathJax can typeset them.
 *
 * Returns: {html: string} — safe HTML, ready for node.innerHTML assignment.
 *
 * @package   block_ai_assistant_v2
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\render_helper;
use block_ai_assistant_v2\local\history_repository;
use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class render_response extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'historyid' => new external_value(PARAM_INT, 'History record ID'),
            'courseid'  => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    public static function execute(int $historyid, int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'historyid' => $historyid,
            'courseid'  => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:use', $context);

        // Use history_repository::get_record() — resolves correct table name
        // dynamically (supports both v2 and legacy table names).
        // IGNORE_MISSING means no exception if record not found.
        $record = history_repository::get_record(
            [
                'id'       => $params['historyid'],
                'userid'   => $USER->id,
                'courseid' => $params['courseid'],
            ],
            'id, botresponse',
            IGNORE_MISSING
        );

        if (!$record) {
            return ['html' => ''];
        }

        $raw = trim((string)$record->botresponse);

        // Strip chain-of-thought blocks before rendering.
        $raw = preg_replace('/<think>[\s\S]*?<\/think>/is', '', $raw);

        // render_helper::render() uses strip_tags() NOT clean_text/HTMLPurifier.
        // Backslashes \( \[ survive intact — MathJax delimiters are preserved.
        $html = render_helper::render($raw);

        return ['html' => $html];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered HTML'),
        ]);
    }
}
