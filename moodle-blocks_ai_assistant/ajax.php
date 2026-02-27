<?php

// FILE: moodle/blocks/ai_assistant/ajax.php
// FINAL VERSION: Handles JSON payload correctly without required_param_from_object

define('AJAX_SCRIPT', true);
require_once('../../config.php');

global $DB, $USER;

// Security checks
require_login();

header('Content-Type: application/json');

// Read raw POST data
$jsoninput = file_get_contents('php://input');
$data = json_decode($jsoninput);

// Validate JSON
if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    exit;
}

// Extract and validate parameters
if (!isset($data->action) || !isset($data->sesskey) || !isset($data->courseid)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
    exit;
}

$action = clean_param($data->action, PARAM_ALPHA);
$courseid = clean_param($data->courseid, PARAM_INT);
$sesskey = clean_param($data->sesskey, PARAM_ALPHANUM);

// Verify sesskey
if (!confirm_sesskey($sesskey)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid session key']);
    exit;
}

// Verify course enrollment
//$context = context_course::instance($courseid);
//require_capability('moodle/course:view', $context);
require_login(get_course($courseid), false);
require_capability('block/ai_assistant:use', $context);

// Handle actions
if ($action === 'create') {
    
    if (!isset($data->history)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing history data']);
        exit;
    }
    
    $history = $data->history;
    
    $record = new stdClass();
    $record->userid = $USER->id;
    $record->courseid = $courseid;
    $record->usertext = isset($history->usertext) ? clean_param($history->usertext, PARAM_RAW) : '';
    $record->botresponse = ''; // Initially empty
    $record->functioncalled = isset($history->functioncalled) ? clean_param($history->functioncalled, PARAM_ALPHANUMEXT) : '';
    
    // Handle the three-level hierarchy
    $record->subject = isset($history->subject) ? clean_param($history->subject, PARAM_TEXT) : '';
    $record->topic = isset($history->topic) ? clean_param($history->topic, PARAM_TEXT) : '';
    $record->lesson = isset($history->lesson) ? clean_param($history->lesson, PARAM_TEXT) : '';
    
    $record->timecreated = time();
	$record->timemodified = time();
	$record->metadata = null;

    
    try {
        $historyid = $DB->insert_record('block_ai_assistant_history', $record);
        echo json_encode(['status' => 'success', 'historyid' => $historyid]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    
} else 
	
if ($action === 'update') {
    
    if (!isset($data->historyid) || !isset($data->botresponse)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing historyid or botresponse']);
        exit;
    }
    
    $historyid = clean_param($data->historyid, PARAM_INT);
    $botresponse = clean_param($data->botresponse, PARAM_RAW);
    
    // NEW: Get metadata from response
    $metadata = isset($data->metadata) ? $data->metadata : null;
    
    try {
        $record = $DB->get_record('block_ai_assistant_history', 
            ['id' => $historyid, 'userid' => $USER->id]);
        
        if (!$record) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'History record not found']);
            exit;
        }
        
        // Update with response and metadata
        $update = new stdClass();
        $update->id = $historyid;
        $update->botresponse = $botresponse;
        
        // NEW: Store metadata if available
        if ($metadata) {
            $update->model = isset($metadata->model) ? clean_param($metadata->model, PARAM_TEXT) : null;
            $update->completion_id = isset($metadata->completion_id) ? clean_param($metadata->completion_id, PARAM_TEXT) : null;
            $update->tokens_used = isset($metadata->tokens_used) ? clean_param($metadata->tokens_used, PARAM_INT) : 0;
            $update->finish_reason = isset($metadata->finish_reason) ? clean_param($metadata->finish_reason, PARAM_TEXT) : null;
        }
        
        $DB->update_record('block_ai_assistant_history', $update);
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action specified']);
}
