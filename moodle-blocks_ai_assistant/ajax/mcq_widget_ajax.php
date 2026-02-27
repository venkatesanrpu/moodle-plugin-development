<?php
/**
 * MCQ Widget AJAX Endpoint - STRICT JSON via Parallel Chat API (jsonschema)
 * FILE: blocks/ai_assistant/ajax/mcq_widget_ajax.php
 * Moodle 5.1 | PHP 8.2
 * 
 * *** CACHE LOOKUP ADDED ***
 * Before calling LLM API, check if MCQs already exist in history table
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/libmcq.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

while (ob_get_level() > 0) {
    ob_end_clean();
}

set_time_limit(240);
ini_set('max_execution_time', 240);

try {
    global $DB, $USER, $CFG;

    // ========================================================================
    // PARAMETERS
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
    // *** NEW: CACHE LOOKUP - CHECK HISTORY TABLE BEFORE API CALL ***
    // ========================================================================
// ========================================================================
// CACHE LOOKUP - Use exact same format as when record was created
// ========================================================================

$level = strtolower(required_param('level', PARAM_ALPHA));
//$agenttext = required_param('agenttext', PARAM_RAW_TRIMMED);

// CRITICAL: Reconstruct usertext with EXACT same format as insert
$usertext_for_cache = strtoupper($level) . ' MCQ: ' . $agenttext;
$functioncalled_for_cache = 'mcq_widget_' . $level;

error_log('Looking for usertext: ' . $usertext_for_cache);
error_log('Looking for function: ' . $functioncalled_for_cache);

$sql = "SELECT id, botresponse, metadata 
        FROM {block_ai_assistant_history}
        WHERE courseid = :courseid 
        AND userid = :userid 
        AND " . $DB->sql_compare_text('usertext') . " = " . $DB->sql_compare_text(':usertext') . "
        AND functioncalled = :functioncalled
        AND botresponse IS NOT NULL 
        AND botresponse != ''
        ORDER BY timemodified DESC 
        LIMIT 1";

$cached_record = $DB->get_record_sql($sql, [
    'courseid' => $courseid,
    'userid' => $USER->id,
    'usertext' => $usertext_for_cache,  // Use reconstructed format with prefix
    'functioncalled' => $functioncalled_for_cache,
], IGNORE_MISSING);

error_log('Cache result: ' . ($cached_record ? 'FOUND' : 'NOT FOUND'));

if ($cached_record) {
    try {
        $mcqdata = json_decode($cached_record->botresponse, true);
        $apimetadata = json_decode($cached_record->metadata, true);
        
        if ($mcqdata && $apimetadata) {
            $apimetadata['from_cache'] = true;
            $mcqdata['metadata'] = $apimetadata;
            
            echo json_encode([
                'status' => 'success',
                'data' => $mcqdata,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Exception $e) {
        error_log('Cache decode error: ' . $e->getMessage());
    }
}


    // ========================================================================
    // CACHE MISS: Continue with normal MCQ generation (existing code below)
    // ========================================================================

    // -------------------- Context --------------------
     $context = [];
     $context[] = "Exam: {$target}";
     $context[] = "Subject: {$subject}";
     if ($lesson !== '') $context[] = "Lesson: {$lesson}";
     if ($topic !== '')  $context[] = "Topic: {$topic}";
     if ($tags !== '')   $context[] = "Keywords: {$tags}";
     $context_string = implode("\n", $context);
 
     // -------------------- Tokens --------------------
     $maxtokens = match ($level) {
         'basic' => min(7000, max(2500, 900 * $questioncount)),
         'intermediate' => min(8000, max(3000, 1100 * $questioncount)),
         'advanced' => min(8000, max(3200, 1200 * $questioncount)),
         default => 4000
     };
 
     // -------------------- Prompt --------------------
     $prompt_file = $CFG->dirroot . '/blocks/ai_assistant/prompts/mcq_' . $level . '.txt';
     if (!file_exists($prompt_file)) {
         $prompt_file = $CFG->dirroot . '/blocks/ai_assistant/prompts/mcq_widget_instruction.txt';
     }
     if (!file_exists($prompt_file)) {
         throw new Exception('MCQ prompt template not found');
     }
 
     $prompt_template = file_get_contents($prompt_file);
 
     $system_prompt = str_replace(
         ['{QUESTION_COUNT}', '{TARGET_EXAM}', '{SUBJECT}', '{TOPIC}', '{LESSON}', '{LEVEL}', '{AGENT_TEXT}', '{TAGS}', '{CONTEXT_BLOCK}'],
         [$questioncount, $target, $subject, $topic, $lesson, $level, $agenttext, $tags, $context_string],
         $prompt_template
     );

error_log("SYSTEM PROMPT:  " . $system_prompt);
    // -------- Schema Payload --------
    $schema = (object)[
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['questions'],
        'properties' => (object)[
            'questions' => (object)[
                'type' => 'array',
                'minItems' => $questioncount,
                'maxItems' => $questioncount,
                'items' => (object)[
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['question', 'options', 'correct', 'explanation'],
                    'properties' => (object)[
                        'question' => (object)['type' => 'string'],
                        'options' => (object)[
                            'type' => 'array',
                            'minItems' => 4,
                            'maxItems' => 4,
                            'items' => (object)['type' => 'string'],
                        ],
                        'correct' => (object)[
                            'type' => 'string',
                            'enum' => ['A', 'B', 'C', 'D'],
                        ],
                        'explanation' => (object)['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    $payload = [
        'model' => 'speed',
        'messages' => [
            [
                'role' => 'system',
                'content' => $system_prompt,
            ],
            [
                'role' => 'user',
                'content' => "Generate exactly $questioncount $level MCQs about $agenttext. Return JSON only that matches the schema.",
            ],
        ],
        'stream' => false,
        'max_tokens' => $maxtokens,
        'temperature' => 0.4,
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'mcq_schema',
                'schema' => $schema,
            ],
        ],
    ];

    // -------- Call LLM --------
    $starttime = microtime(true);
    $fullresponse = local_ai_functions_call_endpoint($agentkey, 'mcq', $payload);
    $apiduration = intval(round((microtime(true) - $starttime) * 1000));

    if (!$fullresponse) {
        throw new Exception('Empty response from LLM API');
    }

    $responsedata = json_decode($fullresponse, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($responsedata)) {
        throw new Exception('API response is not valid JSON: ' . json_last_error_msg());
    }

    if (isset($responsedata['error'])) {
        throw new Exception('API Error: ' . json_encode($responsedata['error']));
    }

    // Extract API metadata
    $apimetadata = [
        'model' => $responsedata['model'] ?? null,
        'id' => $responsedata['id'] ?? null,
        'created' => $responsedata['created'] ?? null,
        'object' => $responsedata['object'] ?? null,
        'finish_reason' => $responsedata['choices'][0]['finish_reason'] ?? null,
        'usage' => $responsedata['usage'] ?? null,
        'api_duration_ms' => $apiduration,
    ];

    // -------- Extract Decode MCQ JSON --------
    $content = $responsedata['choices'][0]['message']['content'] ?? null;

    if (!is_string($content)) {
        trim($content);
        echo json_encode([
            'status' => 'error',
            'code' => 'MCQ_NO_CONTENT',
            'message' => 'No assistant content returned.',
            'metadata' => $apimetadata,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mcqraw = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($mcqraw)) {
        echo json_encode([
            'status' => 'error',
            'code' => 'MCQ_JSON_DECODE_FAILED',
            'message' => 'Assistant content was not valid JSON.',
            'metadata' => $apimetadata,
            'raw_content_snippet' => mb_substr($content, 0, 2000),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mcqdata = validate_mcq_schema($mcqraw);

    if (empty($mcqdata['questions'])) {
        echo json_encode([
            'status' => 'error',
            'code' => 'MCQ_SCHEMA_VALIDATION_FAILED',
            'message' => 'MCQ generation succeeded but parsing failed. Please try again.',
            'metadata' => $apimetadata,
            'raw_json' => $mcqraw,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------- App metadata --------
    $mcqdata['metadata'] = [
        'level' => $level,
        'count' => count($mcqdata['questions']),
        'subject' => $subject,
        'topic' => $topic,
        'lesson' => $lesson,
        'target_exam' => $target,
        'agent_text' => $agenttext,
        'tags' => $tags,
        'mainsubject' => $mainsubjectkey,
        'is_dummy' => false,
        'generated_at' => time(),
        'api_duration_ms' => $apiduration,
        'format_version' => '2.0',
    ];

    // -------- Store --------
    $usertext = strtoupper($level) . ' MCQ: ' . $agenttext;

    $jsonresponse = json_encode($mcqdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $jsonmetadata = json_encode($apimetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($jsonresponse === false || $jsonmetadata === false) {
        throw new Exception('Failed to encode JSON output');
    }

    $historyrecord = (object)[
        'userid' => $USER->id,
        'courseid' => $courseid,
        'usertext' => $usertext,
        'botresponse' => $jsonresponse,
        'metadata' => $jsonmetadata,
        'functioncalled' => $functioncalled_for_cache,  // 'mcq_widget_basic', 'mcq_widget_intermediate', etc.
        'subject' => $subject,
        'topic' => $topic,
        'lesson' => $lesson,
        'timecreated' => time(),
        'timemodified' => time(),
    ];

    $historyid = $DB->insert_record('block_ai_assistant_history', $historyrecord);

    if (!$historyid) {
        throw new Exception('Failed to store MCQ data in database');
    }

    $mcqdata['metadata']['history_id'] = $historyid;

    echo json_encode([
        'status' => 'success',
        'data' => $mcqdata,
        'metadata' => $apimetadata,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'code' => 'MCQ_GENERATION_ERROR',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
/**
 * Validates and normalizes to:
 * { "questions": [ { "question": "...", "options": ["...","...","...","..."], "correct":"A|B|C|D", "explanation":"..." } ] }
 */
function validate_mcq_schema(array $json): array {
    if (!isset($json['questions']) || !is_array($json['questions'])) {
        return ['questions' => []];
    }

    $out = [];
    foreach ($json['questions'] as $q) {
        if (!is_array($q)) continue;

        $question = trim((string)($q['question'] ?? ''));
        $options  = $q['options'] ?? null;
        $correct  = strtoupper(trim((string)($q['correct'] ?? '')));
        $explain  = trim((string)($q['explanation'] ?? ''));

        if ($question === '') continue;
        if (!is_array($options) || count($options) !== 4) continue;
        if (!in_array($correct, ['A','B','C','D'], true)) continue;
        if ($explain === '') $explain = 'No explanation provided.';

        $out[] = [
            'question' => $question,
            'options' => array_values(array_map(
                static fn($v) => trim(preg_replace('/\s+/', ' ', (string)$v)),
                $options
            )),
            'correct' => $correct,
            'explanation' => $explain
        ];
    }

    return ['questions' => $out];
}
