<?php
/**
 * MCQ History Widget AJAX Endpoint - Cache Orchestrator
 * 
 * FILE: blocks/ai_assistant/ajax/mcq_history_widget_ajax.php
 * 
 * FLOW:
 * 1. Check if MCQs exist in history (courseid + userid + usertext + functioncalled + level)
 * 2. If cache HIT: return cached MCQs directly
 * 3. If cache MISS: call mcq_widget_ajax.php to generate new MCQs
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    global $DB, $USER, $CFG;

    // ========================================================================
    // PARAMETERS (pass through from mcq_widget.mustache)
    // ========================================================================
    $agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
    $level = strtolower(required_param('level', PARAM_ALPHA));
    $agenttext = required_param('agent_text', PARAM_RAW_TRIMMED);
    $courseid = required_param('courseid', PARAM_INT);
    $questioncount = required_param('number', PARAM_INT);

    // Optional parameters
    $target = optional_param('target', 'CSIR GATE UGC-NET', PARAM_TEXT);
    $subject = optional_param('subject', 'Chemistry', PARAM_TEXT);
    $topic = optional_param('topic', '', PARAM_TEXT);
    $lesson = optional_param('lesson', '', PARAM_TEXT);
    $tags = optional_param('tags', '', PARAM_RAW_TRIMMED);
    $mainsubjectkey = optional_param('mainsubject', '', PARAM_TEXT);

    // Validate course and login
    $course = get_course($courseid);
    require_login($course);

    // Validate level
    $validlevels = ['basic', 'intermediate', 'advanced'];
    if (!in_array($level, $validlevels, true)) {
        throw new Exception('Invalid level: ' . $level);
    }

    // Validate question count
    if ($questioncount < 1 || $questioncount > 50) {
        throw new Exception('Invalid number of questions');
    }

    // ========================================================================
    // STEP 1: CHECK HISTORY CACHE
    // ========================================================================
    $usertext_for_cache = strtoupper($level) . ' MCQ: ' . $agenttext;
    $functioncalled = 'mcq_widget_' . $level;  // e.g., 'mcq_widget_basic'

    $sql = "SELECT id, botresponse, metadata
              FROM {block_ai_assistant_history}
             WHERE courseid = :courseid
               AND userid = :userid
               AND " . $DB->sql_compare_text('usertext') . " = " . $DB->sql_compare_text(':usertext') . "
               AND functioncalled = :functioncalled
               AND botresponse <> ''
          ORDER BY timemodified DESC
             LIMIT 1";

    $params = [
        'courseid' => $courseid,
        'userid' => $USER->id,
        'usertext' => $usertext_for_cache,
        'functioncalled' => $functioncalled,
    ];

    $cached = $DB->get_record_sql($sql, $params, IGNORE_MISSING);

    // ========================================================================
    // CACHE HIT: Return existing MCQs
    // ========================================================================
    if ($cached) {
        try {
            $mcqdata = json_decode($cached->botresponse, true, 512, JSON_THROW_ON_ERROR);
            $apimetadata = json_decode($cached->metadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // If cached data is corrupted, fall through to generate new
            error_log('Corrupted cache data for MCQ: ' . $e->getMessage());
        }

        if (isset($mcqdata) && isset($apimetadata)) {
            $apimetadata['from_cache'] = true;
            $apimetadata['cached_at'] = $cached->timemodified;

            echo json_encode([
                'status' => 'success',
                'data' => $mcqdata,
                'metadata' => $apimetadata,
                'cache_hit' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ========================================================================
    // CACHE MISS: Call mcq_widget_ajax.php to generate new MCQs
    // ========================================================================
    $mcq_ajax_url = $CFG->wwwroot . '/blocks/ai_assistant/ajax/mcq_widget_ajax.php';

    // Build query string
    $params = [
        'sesskey' => sesskey(),
        'agent_config_key' => $agentkey,
        'agent_text' => $agenttext,
        'level' => $level,
        'courseid' => $courseid,
        'number' => $questioncount,
        'target' => $target,
        'subject' => $subject,
    ];

    if (!empty($topic)) {
        $params['topic'] = $topic;
    }
    if (!empty($lesson)) {
        $params['lesson'] = $lesson;
    }
    if (!empty($tags)) {
        $params['tags'] = $tags;
    }
    if (!empty($mainsubjectkey)) {
        $params['mainsubject'] = $mainsubjectkey;
    }

    $mcq_ajax_url_with_params = $mcq_ajax_url . '?' . http_build_query($params);

    // Call mcq_widget_ajax.php
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 300,
            'header' => [
                'User-Agent: Moodle',
                'Cookie: MoodleSession=' . session_id(),
            ],
        ],
    ]);

    $response = @file_get_contents($mcq_ajax_url_with_params, false, $context);

    if ($response === false) {
        throw new Exception('Failed to call mcq_widget_ajax.php. Please try again.');
    }

    try {
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new Exception('Invalid response from MCQ generator: ' . $e->getMessage());
    }

    // Pass through the response (with cache_hit = false since it's new)
    if (isset($result['status']) && $result['status'] === 'success') {
        $result['cache_hit'] = false;
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 'MCQ_HISTORY_ERROR',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
