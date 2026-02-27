<?php
// blocks/ai_assistant/ajax/history_widget_ajax.php

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

global $DB, $USER;

header('Content-Type: application/json; charset=utf-8');

try {
    // ---- Read JSON body ----
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new moodle_exception('invalidjson', 'error');
    }

    // ---- Core params ----
    $sesskey  = clean_param($data['sesskey'] ?? '', PARAM_ALPHANUM);
    $courseid = clean_param($data['courseid'] ?? 0, PARAM_INT);

    if (empty($courseid)) {
        throw new moodle_exception('missingparam', 'error', '', 'courseid');
    }
    if (empty($sesskey) || !confirm_sesskey($sesskey)) {
        throw new moodle_exception('invalidsesskey', 'error');
    }

    // ---- Course-aware login + capability ----
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course, false);

    $context = context_course::instance($courseid);
    require_capability('block/ai_assistant:use', $context);

    // ---- Filters (key + label) ----
    $isgeneral = !empty($data['general']);

    $subjectkey  = clean_param($data['subject'] ?? '', PARAM_ALPHANUMEXT);
    $subjectname = clean_param($data['subjectname'] ?? '', PARAM_TEXT);

    $topickey  = clean_param($data['topic'] ?? '', PARAM_ALPHANUMEXT);
    $topicname = clean_param($data['topicname'] ?? '', PARAM_TEXT);

    // lesson can be key OR label (UI might send either)
    $lessonkey  = clean_param($data['lesson'] ?? '', PARAM_TEXT);
    $lessonname = clean_param($data['lessonname'] ?? '', PARAM_TEXT);

    // Pagination.
    $page    = max(1, (int)($data['page'] ?? 1));
    $perpage = max(1, min(50, (int)($data['perpage'] ?? 20)));
    $offset  = ($page - 1) * $perpage;

    // For non-general requests, subject is required (either key or label).
    if (!$isgeneral && $subjectkey === '' && $subjectname === '') {
        throw new moodle_exception('missingparam', 'error', '', 'subject');
    }

    // ---- Helper: normalize human title to snake_case ----
    $to_snake = function(string $s): string {
        $s = trim($s);
        if ($s === '') { return ''; }

        // Lowercase with Moodle-safe helper.
        $s = core_text::strtolower($s);

        // Any run of non-alnum -> underscore.
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);

        // Trim underscores.
        $s = trim($s, '_');

        return $s;
    };

    // ---- Build SQL ----
    $params = [
        'userid'   => $USER->id,
        'courseid' => $courseid,
    ];

    $basesql = "FROM {block_ai_assistant_history}
                WHERE userid = :userid AND courseid = :courseid";

    if ($isgeneral) {
        $basesql .= " AND (subject IS NULL OR subject = '' OR subject = :generalsubject)";
        $params['generalsubject'] = 'general';
    } else {
        // Subject: match key OR label (IN).
        $subjectvals = array_values(array_filter(array_unique([$subjectkey, $subjectname])));
        list($subsql, $subparams) = $DB->get_in_or_equal($subjectvals, SQL_PARAMS_NAMED, 'sub');
        $basesql .= " AND subject $subsql";
        $params += $subparams;

        // Topic optional.
        $topicvals = array_values(array_filter(array_unique([$topickey, $topicname])));
        if (!empty($topicvals)) {
            list($topsql, $topparams) = $DB->get_in_or_equal($topicvals, SQL_PARAMS_NAMED, 'top');
            $basesql .= " AND topic $topsql";
            $params += $topparams;
        }

        // Lesson optional: accept BOTH label and snake_case.
        // Example: "04 Genomics" should match "04_genomics" stored in DB.
        $lessonvals = [];

        if ($lessonkey !== '') {
            $lessonvals[] = trim($lessonkey);
            $lessonvals[] = $to_snake($lessonkey); // harmless if already snake_case
        }

        if ($lessonname !== '') {
            $lessonvals[] = trim($lessonname);
            $lessonvals[] = $to_snake($lessonname);
        }

        $lessonvals = array_values(array_filter(array_unique($lessonvals)));

        if (!empty($lessonvals)) {
            list($lessql, $lesparams) = $DB->get_in_or_equal($lessonvals, SQL_PARAMS_NAMED, 'les');
            $basesql .= " AND lesson $lessql";
            $params += $lesparams;
        }
    }

    $countsql = "SELECT COUNT(1) $basesql";

    $sql = "SELECT id, usertext, botresponse, timecreated, functioncalled, subject, topic, lesson
            $basesql
            ORDER BY timecreated DESC";

    $totalcount = (int)$DB->count_records_sql($countsql, $params);
    $records    = $DB->get_records_sql($sql, $params, $offset, $perpage);

    // ---- Format results ----
    $conversations = [];

    foreach ($records as $r) {
        $bot = (string)$r->botresponse;
        $bot = preg_replace('/[\s\S]*?<\/think>/is', '', $bot);
        $bot = trim($bot);

        $conversations[] = [
            'id'            => (int)$r->id,
            'usertext'      => (string)$r->usertext,
            'botresponse'   => $bot,
            'formattedtime' => userdate($r->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'timecreated'   => (int)$r->timecreated,
            'functioncalled'=> (string)$r->functioncalled,
            'subject'       => (string)$r->subject,
            'topic'         => (string)$r->topic,
            'lesson'        => (string)$r->lesson,
        ];
    }

    $totalpages = (int)ceil($totalcount / $perpage);

    echo json_encode([
        'status' => 'success',
        'conversations' => $conversations,
        'pagination' => [
            'currentpage'  => $page,
            'perpage'      => $perpage,
            'totalcount'   => $totalcount,
            'totalpages'   => $totalpages,
            'hasnext'      => $page < $totalpages,
            'hasprevious'  => $page > 1,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (required_capability_exception $e) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (moodle_exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'errorcode' => $e->errorcode]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
