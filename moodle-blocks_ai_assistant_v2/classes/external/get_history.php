<?php
namespace block_ai_assistant_v2\external;

defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\history_repository;
use context_course;
use core_text;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

class get_history extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'page' => new external_value(PARAM_INT, 'Page number', VALUE_DEFAULT, 1),
            'perpage' => new external_value(PARAM_INT, 'Items per page', VALUE_DEFAULT, 10),
            'subject' => new external_value(PARAM_TEXT, 'Subject filter', VALUE_DEFAULT, ''),
            'topic' => new external_value(PARAM_TEXT, 'Topic filter', VALUE_DEFAULT, ''),
            'lesson' => new external_value(PARAM_TEXT, 'Lesson filter', VALUE_DEFAULT, ''),
            'general' => new external_value(PARAM_BOOL, 'General history only', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(
        int $courseid,
        int $page = 1,
        int $perpage = 10,
        string $subject = '',
        string $topic = '',
        string $lesson = '',
        bool $general = false
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'page' => $page,
            'perpage' => $perpage,
            'subject' => $subject,
            'topic' => $topic,
            'lesson' => $lesson,
            'general' => $general,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/ai_assistant_v2:use', $context);

        $page = max(1, (int)$params['page']);
        $perpage = max(1, min(50, (int)$params['perpage']));
        $offset = ($page - 1) * $perpage;

        $tonormalized = static function(string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            $value = core_text::strtolower($value);
            $value = preg_replace('/[^a-z0-9]+/', '_', $value);
            return trim($value, '_');
        };

        $sqlparams = [
            'userid' => $USER->id,
            'courseid' => $params['courseid'],
        ];

        $basesql = 'FROM ' . history_repository::table_sql() . ' WHERE userid = :userid AND courseid = :courseid';

        if (!empty($params['general'])) {
            $basesql .= " AND (subject IS NULL OR subject = '' OR subject = :generalvalue)";
            $sqlparams['generalvalue'] = 'general';
        } else {
            if ($params['subject'] !== '') {
                $values = array_values(array_filter(array_unique([$params['subject'], $tonormalized($params['subject'])])));
                list($insql, $inparams) = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, 'sub');
                $basesql .= " AND subject $insql";
                $sqlparams += $inparams;
            }
            if ($params['topic'] !== '') {
                $values = array_values(array_filter(array_unique([$params['topic'], $tonormalized($params['topic'])])));
                list($insql, $inparams) = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, 'top');
                $basesql .= " AND topic $insql";
                $sqlparams += $inparams;
            }
            if ($params['lesson'] !== '') {
                $values = array_values(array_filter(array_unique([$params['lesson'], $tonormalized($params['lesson'])])));
                list($insql, $inparams) = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, 'les');
                $basesql .= " AND lesson $insql";
                $sqlparams += $inparams;
            }
        }

        $countsql = "SELECT COUNT(1) $basesql";
        $datasql = "SELECT id, usertext, botresponse, functioncalled, subject, topic, lesson, timecreated $basesql ORDER BY timecreated DESC";
        $totalcount = (int)$DB->count_records_sql($countsql, $sqlparams);
        $records = $DB->get_records_sql($datasql, $sqlparams, $offset, $perpage);

        $items = [];
        foreach ($records as $record) {
            $botresponse = trim((string)$record->botresponse);
            $botresponse = preg_replace('/[\s\S]*?<\/think>/is', '', $botresponse);
            $preview = $botresponse;
            if ((string)$record->functioncalled === 'mcq_agent') {
                $decoded = json_decode($botresponse, true);
                if (is_array($decoded) && !empty($decoded['questions']) && is_array($decoded['questions'])) {
                    $preview = 'MCQ set with ' . count($decoded['questions']) . ' questions';
                    if (!empty($decoded['difficulty'])) {
                        $preview .= ' (' . $decoded['difficulty'] . ')';
                    }
                }
            }
            $items[] = [
                'id' => (int)$record->id,
                'usertext' => (string)$record->usertext,
                'botresponse' => $botresponse,
                'previewtext' => $preview,
                'functioncalled' => (string)$record->functioncalled,
                'subject' => (string)$record->subject,
                'topic' => (string)$record->topic,
                'lesson' => (string)$record->lesson,
                'timecreated' => (int)$record->timecreated,
                'formattedtime' => userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            ];
        }

        $totalpages = max(1, (int)ceil($totalcount / $perpage));

        return [
            'items' => $items,
            'page' => $page,
            'perpage' => $perpage,
            'totalcount' => $totalcount,
            'totalpages' => $totalpages,
            'hasnext' => $page < $totalpages,
            'hasprevious' => $page > 1,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'History ID'),
                    'usertext' => new external_value(PARAM_RAW, 'User text'),
                    'botresponse' => new external_value(PARAM_RAW, 'Bot response'),
                    'previewtext' => new external_value(PARAM_RAW, 'Preview text'),
                    'functioncalled' => new external_value(PARAM_TEXT, 'Function called'),
                    'subject' => new external_value(PARAM_TEXT, 'Subject'),
                    'topic' => new external_value(PARAM_TEXT, 'Topic'),
                    'lesson' => new external_value(PARAM_TEXT, 'Lesson'),
                    'timecreated' => new external_value(PARAM_INT, 'Creation time'),
                    'formattedtime' => new external_value(PARAM_TEXT, 'Formatted time'),
                ])
            ),
            'page' => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Per page'),
            'totalcount' => new external_value(PARAM_INT, 'Total count'),
            'totalpages' => new external_value(PARAM_INT, 'Total pages'),
            'hasnext' => new external_value(PARAM_BOOL, 'Has next page'),
            'hasprevious' => new external_value(PARAM_BOOL, 'Has previous page'),
        ]);
    }
}
