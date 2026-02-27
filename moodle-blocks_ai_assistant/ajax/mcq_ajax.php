<?php

/**
 * MCQ Generator AJAX Endpoint (Streaming)
 * FILE: blocks/ai_assistant/mcq_ajax.php
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/lib.php');

require_login();
require_sesskey();

// Disable output buffering
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Set timeouts
set_time_limit(240);                    // 4 minutes
ini_set('max_execution_time', 240);
ignore_user_abort(false);

// SSE Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable compression
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);

echo ": connected\n\n";
flush();

try {
    // Get parameters
    $agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
    $level = required_param('level', PARAM_ALPHA);
    $agenttext = required_param('agent_text', PARAM_RAW);
    $target = optional_param('target', 'CSIR Chemical Sciences Exam', PARAM_TEXT);
    $subject = optional_param('subject', 'Chemistry', PARAM_TEXT);
    $topic = optional_param('topic', '', PARAM_TEXT);
    $lesson = optional_param('lesson', '', PARAM_TEXT);
    $tags = optional_param('tags', '', PARAM_RAW);

    // Validate level
    $valid_levels = ['basic', 'intermediate', 'advanced'];
    if (!in_array($level, $valid_levels)) {
        throw new Exception('Invalid level: ' . $level);
    }

    // Determine question count and tokens
    switch ($level) {
        case 'basic':
            $question_count = 10;
            $max_tokens = 2800;
            break;
        case 'intermediate':
            $question_count = 10;
            $max_tokens = 2800;
            break;
        case 'advanced':
            $question_count = 5;
            $max_tokens = 2800;
            break;
    }

    // Load prompt template
    $prompt_file = $CFG->dirroot . '/blocks/ai_assistant/prompts/mcq_instruction.txt';
    
    if (!file_exists($prompt_file)) {
        throw new Exception('MCQ prompt not found');
    }
    
    $prompt_template = file_get_contents($prompt_file);
    
    // Replace placeholders
    $system_prompt = str_replace(
        ['{QUESTION_COUNT}', '{TARGET_EXAM}', '{SUBJECT}', '{TOPIC}', '{LESSON}', '{LEVEL}', '{AGENT_TEXT}', '{TAGS}'],
        [$question_count, $target, $subject, $topic, $lesson, $level, $agenttext, $tags],
        $prompt_template
    );

    // Build payload
    $payload = [
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => "Generate {$question_count} MCQs on: {$agenttext}"]
        ],
        'stream' => true,
        'max_tokens' => $max_tokens,
        'temperature' => 0.4,
        'top_p' => 0.9,
        'presence_penalty' => 0.2,
        'frequency_penalty' => 0.3
    ];

    // Call endpoint
    local_ai_functions_call_endpoint($agentkey, 'mcq', $payload);
    
} catch (Exception $e) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
}
