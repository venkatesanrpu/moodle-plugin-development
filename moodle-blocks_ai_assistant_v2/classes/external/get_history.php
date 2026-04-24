<?php
/**
 * External function: get_history
 *
 * Phase 7_6j fixes:
 *   - FATAL BUG: Wrong use statements. Was using pre-Moodle4 bare class names
 *     (external_api, external_value etc.) inside a namespace, which resolved to
 *     block_ai_assistant_v2\external\external_api → class not found → fatal.
 *     Fixed: all external classes now use core_external\ namespace.
 *   - FATAL BUG: core_text::strlen() called without use \core_text → fatal.
 *     Fixed: using \core_text::strlen() with fully-qualified name.
 *
 * @package    block_ai_assistant_v2
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\render_helper;
use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;

class get_history extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'    => new external_value(PARAM_INT,  'Course ID'),
            'generalonly' => new external_value(PARAM_BOOL, 'General (non-course) history only', VALUE_DEFAULT, false),
            'page'        => new external_value(PARAM_INT,  'Page number (0-based)', VALUE_DEFAULT, 0),
            'perpage'     => new external_value(PARAM_INT,  'Items per page', VALUE_DEFAULT, 20),
        ]);
    }

    public static function execute(
        int  $courseid,
        bool $generalonly = false,
        int  $page        = 0,
        int  $perpage     = 20
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'    => $courseid,
            'generalonly' => $generalonly,
            'page'        => $page,
            'perpage'     => $perpage,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:use', $context);

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

            // Build plain-text preview (first 80 chars, no HTML).
            $preview = strip_tags($botresponse);
            $preview = preg_replace('/\s+/', ' ', trim($preview));
            // FIX: use fully-qualified \core_text to avoid namespace resolution fatal.
            if (\core_text::strlen($preview) > 80) {
                $preview = \core_text::substr($preview, 0, 80) . '…';
            }

            // Pre-render at fetch time — JS injects directly, no second AJAX call.
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
            'items'      => $items,
            'total'      => $total,
            'page'       => $params['page'],
            'perpage'    => $params['perpage'],
            'totalpages' => $perpage > 0 ? (int)ceil($total / $params['perpage']) : 1,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id'           => new external_value(PARAM_INT,  'Record ID'),
                    'usertext'     => new external_value(PARAM_RAW,  'User question'),
                    'botresponse'  => new external_value(PARAM_RAW,  'Raw LLM response'),
                    'renderedhtml' => new external_value(PARAM_RAW,  'Pre-rendered HTML'),
                    'previewtext'  => new external_value(PARAM_TEXT, 'Plain text preview'),
                    'timecreated'  => new external_value(PARAM_INT,  'Unix timestamp'),
                    'courseid'     => new external_value(PARAM_INT,  'Course ID'),
                ])
            ),
            'total'      => new external_value(PARAM_INT, 'Total record count'),
            'page'       => new external_value(PARAM_INT, 'Current page'),
            'perpage'    => new external_value(PARAM_INT, 'Items per page'),
            'totalpages' => new external_value(PARAM_INT, 'Total pages'),
        ]);
    }
}
