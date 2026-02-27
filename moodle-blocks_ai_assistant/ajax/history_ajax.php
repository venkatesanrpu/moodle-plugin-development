<?php
/**
 * Block AI Assistant - History AJAX Handler
 *
 * Handles history operations: lookup (cache check), create, update, list
 * - lookup: Query DB for existing cached response (no API call if found)
 * - create: Idempotent insert (reuses existing row on race condition)
 * - update: Update botresponse after SSE completes
 * - list: Return all history for user in course
 */

require_once(__DIR__ . '/../../../config.php');
//require_once(__DIR__ . '/../lib.php');

// JSON input expected
header('Content-Type: application/json; charset=utf-8');
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

try {
    global $DB, $USER;

    // Get action and params
    $action = clean_param($data['action'] ?? '', PARAM_ALPHANUMEXT);
    $sesskey = clean_param($data['sesskey'] ?? '', PARAM_ALPHANUM);
    $courseid = isset($data['courseid']) ? (int)$data['courseid'] : 0;
    $historyData = $data['history'] ?? [];

    // Validate sesskey
    if (!confirm_sesskey($sesskey)) {
        throw new moodle_exception('invalidsesskey', 'error');
    }

    // Validate course context
    if ($courseid <= 0) {
        throw new moodle_exception('missingparam', 'error', '', 'courseid');
    }

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course, false);

    $context = context_course::instance($courseid);
    require_capability('block/ai_assistant:use', $context);

    // ==========================================================================
    // ACTION: lookup
    // Query DB for existing cached response (same userid, courseid, usertext)
    // If found with non-empty botresponse, return it (cache hit).
    // ==========================================================================
if ($action === 'lookup') {
    $history = $data['history'] ?? null;
    if (!is_array($history)) {
        throw new moodle_exception('missingparam', 'error', '', 'history');
    }

    $usertext = clean_param($history['usertext'] ?? '', PARAM_RAW);
    if (trim($usertext) === '') {
        throw new moodle_exception('missingparam', 'error', '', 'history[usertext]');
    }

    // NOTE: userid is derived from session ($USER->id) - NOT from browser.
    // Only return completed answers (non-empty botresponse).
    // Use sql_compare_text for TEXT column comparison.
    $sql = "SELECT id, usertext, botresponse, timemodified, functioncalled
              FROM {block_ai_assistant_history}
             WHERE courseid = :courseid
               AND userid = :userid
               AND " . $DB->sql_compare_text('usertext') . " = " . $DB->sql_compare_text(':usertext') . "
               AND botresponse <> ''
          ORDER BY timemodified DESC
             LIMIT 1";

    $params = [
        'courseid' => $courseid,
        'userid' => $USER->id,
        'usertext' => $usertext,
    ];

    $record = $DB->get_record_sql($sql, $params);

    if ($record) {
        // Cache hit: return cached response.
        echo json_encode([
            'status' => 'success',
            'hit' => true,
            'historyid' => (int)$record->id,
            'usertext' => $record->usertext,
            'botresponse' => $record->botresponse,
            'functioncalled' => $record->functioncalled,
            'timemodified' => (int)$record->timemodified,
            'notice' => 'Fetched from history',
        ]);
    } else {
        // Cache miss: no matching completed response found.
        echo json_encode([
            'status' => 'success',
            'hit' => false,
        ]);
    }
    exit;
}

    // ==========================================================================
    // ACTION: create
    // Insert a new history row (or reuse existing on race condition).
    // Idempotent: pre-check for existing row, and catch unique-key collision.
    // ==========================================================================
    if ($action === 'create') {
    $history = $data['history'] ?? null;
    if (!is_array($history)) {
        throw new moodle_exception('missingparam', 'error', '', 'history');
    }

    $usertext = clean_param($history['usertext'] ?? '', PARAM_RAW);
    if (trim($usertext) === '') {
        throw new moodle_exception('missingparam', 'error', '', 'history[usertext]');
    }

    $functioncalled = clean_param($history['functioncalled'] ?? '', PARAM_ALPHANUMEXT);
	$subject = clean_param($history['subject'] ?? 'General', PARAM_TEXT);        // ADD
	$topic = clean_param($history['topic'] ?? '', PARAM_TEXT);            // ADD
	$lesson = clean_param($history['lesson'] ?? '', PARAM_TEXT);          // ADD
	$metadata = isset($history['metadata']) ? json_encode($history['metadata']) : null;

    // Build record for insertion
    $record = new stdClass();
    $record->courseid = $courseid;
    $record->userid = $USER->id;
    $record->usertext = $usertext;
	$record->subject = $subject;
	$record->topic = $topic;
	$record->lesson = $lesson;
    $record->functioncalled = $functioncalled;
    $record->botresponse = ''; // Empty initially, filled by SSE update
    $record->metadata = $metadata;
    $record->timecreated = time();
    $record->timemodified = time();

    // ====================================================================
    // Idempotency guard 1: pre-check for existing row
    // If row already exists for this (courseid, userid, usertext), reuse it.
    // ====================================================================
    $sql = "SELECT * FROM {block_ai_assistant_history}
             WHERE courseid = :courseid
               AND userid = :userid
               AND " . $DB->sql_compare_text('usertext') . " = " . $DB->sql_compare_text(':usertext');

    $existing = $DB->get_record_sql($sql, [
        'courseid' => $courseid,
        'userid' => $USER->id,
        'usertext' => $usertext,
    ], IGNORE_MISSING);

    if ($existing) {
        // Row already exists, reuse its ID and skip insert.
        echo json_encode([
            'status' => 'success',
            'historyid' => (int)$existing->id,
            'message' => 'History row exists (reused)',
        ]);
        exit;
    }

    // ====================================================================
    // Idempotency guard 2: try insert with race-condition fallback
    // If unique constraint collision occurs (another request inserted
    // between our pre-check and insert), catch and re-select.
    // ====================================================================
    try {
        $historyid = $DB->insert_record('block_ai_assistant_history', $record, true);

        if (empty($historyid)) {
            throw new moodle_exception('error', 'block_ai_assistant', '', null, 'Failed to insert history');
        }

        echo json_encode([
            'status' => 'success',
            'historyid' => (int)$historyid,
            'message' => 'History created',
        ]);
    } catch (dml_exception $e) {
        // Race condition fallback: another request inserted same key.
        // Try to fetch the existing row and return its ID.
        $sql = "SELECT * FROM {block_ai_assistant_history}
                 WHERE courseid = :courseid
                   AND userid = :userid
                   AND " . $DB->sql_compare_text('usertext') . " = " . $DB->sql_compare_text(':usertext');

        $existing = $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'userid' => $USER->id,
            'usertext' => $usertext,
        ], IGNORE_MISSING);

        if ($existing) {
            // Found after collision; return its ID so client can proceed with SSE.
            echo json_encode([
                'status' => 'success',
                'historyid' => (int)$existing->id,
                'message' => 'History row exists (reused after race condition)',
            ]);
        } else {
            // Unknown DB error (not a duplicate key). Rethrow.
            throw $e;
        }
    }
    exit;
}

    // ==========================================================================
    // ACTION: update
    // Update botresponse for a given history row (after SSE streaming ends).
    // ==========================================================================
    if ($action === 'update') {
        $historyid = isset($data['historyid']) ? (int)$data['historyid'] : 0;
        $botresponse = clean_param($data['botresponse'] ?? '', PARAM_RAW);

        if ($historyid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'historyid');
        }

        // Fetch the row and verify ownership (userid, courseid).
        $record = $DB->get_record('block_ai_assistant_history', [
            'id' => $historyid,
            'userid' => $USER->id,
            'courseid' => $courseid,
        ], '*', MUST_EXIST);

        // Update botresponse and timemodified.
        $record->botresponse = $botresponse;
        $record->timemodified = time();

        $DB->update_record('block_ai_assistant_history', $record);

        echo json_encode([
            'status' => 'success',
            'message' => 'History updated',
        ]);
        exit;
    }

    // ==========================================================================
    // ACTION: list
    // Return all history records for user in this course.
    // ==========================================================================
    if ($action === 'list') {
        $records = $DB->get_records('block_ai_assistant_history', [
            'courseid' => $courseid,
            'userid' => $USER->id,
        ], 'timemodified DESC');

        $history = [];
        foreach ($records as $rec) {
            $history[] = [
                'id' => (int)$rec->id,
                'usertext' => $rec->usertext,
                'botresponse' => $rec->botresponse,
                'functioncalled' => $rec->functioncalled,
                'timemodified' => (int)$rec->timemodified,
            ];
        }

        echo json_encode([
            'status' => 'success',
            'data' => $history,
        ]);
        exit;
    }

    // Unknown action
    throw new moodle_exception('invalidparam', 'error', '', 'action: ' . $action);

} catch (moodle_exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unexpected error: ' . $e->getMessage(),
    ]);
    exit;
}
