<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External function: get_history
 *
 * Phase 7_6f — adds pre-rendered HTML field so JS needs zero extra AJAX
 * calls when loading history items. Eliminates the MUST_EXIST race
 * condition and the typing-indicator flash on history click.
 *
 * @package    block_ai_assistant_v2
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use block_ai_assistant_v2\local\render_helper;

/**
 * Returns paginated chat history for the current user / course.
 */
class get_history extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT,  'Course ID'),
            'generalonly' => new external_value(PARAM_BOOL, 'General (non-course) history only', VALUE_DEFAULT, false),
            'page'       => new external_value(PARAM_INT,  'Page number (0-based)', VALUE_DEFAULT, 0),
            'perpage'    => new external_value(PARAM_INT,  'Items per page',        VALUE_DEFAULT, 20),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param  int  $courseid
     * @param  bool $generalonly
     * @param  int  $page
     * @param  int  $perpage
     * @return array
     */
    public static function execute(
        int  $courseid,
        bool $generalonly = false,
        int  $page        = 0,
        int  $perpage     = 20
    ): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'    => $courseid,
            'generalonly' => $generalonly,
            'page'        => $page,
            'perpage'     => $perpage,
        ]);

        // Validate context.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Build query conditions.
        $conditions = ['userid' => $USER->id];
        if ($params['generalonly']) {
            $conditions['courseid'] = 0;
        } else {
            $conditions['courseid'] = $params['courseid'];
        }

        $total   = $DB->count_records('block_ai_assistant_v2_history', $conditions);
        $records = $DB->get_records(
            'block_ai_assistant_v2_history',
            $conditions,
            'timecreated DESC',
            '*',
            $params['page'] * $params['perpage'],
            $params['perpage']
        );

        $items = [];
        foreach ($records as $record) {
            $usertext    = (string)($record->usertext    ?? '');
            $botresponse = (string)($record->botresponse ?? '');

            // Build preview text (first 80 chars of bot response, no HTML).
            $preview = strip_tags($botresponse);
            $preview = preg_replace('/\s+/', ' ', trim($preview));
            if (core_text::strlen($preview) > 80) {
                $preview = core_text::substr($preview, 0, 80) . '…';
            }

            // Phase 7_6f: Pre-render at fetch time so JS does not need a
            // second AJAX call per history item. renderedhtml is set once here
            // and injected directly via innerHTML in history.js.
            $renderedhtml = render_helper::render($botresponse);

            $items[] = [
                'id'           => (int)$record->id,
                'usertext'     => $usertext,
                'botresponse'  => $botresponse,
                'renderedhtml' => $renderedhtml,
                'previewtext'  => $preview,
                'timecreated'  => (int)$record->timecreated,
                'courseid'     => (int)$record->courseid,
            ];
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $params['page'],
            'perpage'  => $params['perpage'],
            'totalpages' => $perpage > 0 ? (int)ceil($total / $params['perpage']) : 1,
        ];
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id'           => new external_value(PARAM_INT,  'Record ID'),
                    'usertext'     => new external_value(PARAM_RAW,  'User question'),
                    'botresponse'  => new external_value(PARAM_RAW,  'Raw LLM response'),
                    'renderedhtml' => new external_value(PARAM_RAW,  'Pre-rendered HTML (Markdown+Math)'),
                    'previewtext'  => new external_value(PARAM_TEXT, 'Plain text preview'),
                    'timecreated'  => new external_value(PARAM_INT,  'Unix timestamp'),
                    'courseid'     => new external_value(PARAM_INT,  'Course ID (0 = general)'),
                ])
            ),
            'total'      => new external_value(PARAM_INT, 'Total record count'),
            'page'       => new external_value(PARAM_INT, 'Current page'),
            'perpage'    => new external_value(PARAM_INT, 'Items per page'),
            'totalpages' => new external_value(PARAM_INT, 'Total pages'),
        ]);
    }
}
