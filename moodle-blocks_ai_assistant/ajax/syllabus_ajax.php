<?php

// FILE: blocks/ai_assistant/ajax/syllabus_ajax.php

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');


header('Content-Type: application/json; charset=utf-8');

try {
    global $DB;

    $blockid = required_param('blockid', PARAM_INT);

    if (!$DB->get_manager()->table_exists('block_ai_assistant_syllabus')) {
        throw new moodle_exception('missingtable', 'error', '', null, 'Syllabus table not installed.');
    }

    // -----------------------------
    // Cache (read-through) by blockid
    // -----------------------------
    $cache = cache::make('block_ai_assistant', 'syllabus');

    // Cache key includes blockid => no collision across courses/blocks.
    $cachekey = 'blockid_' . $blockid;

    $cachedjson = $cache->get($cachekey);
    if ($cachedjson !== false && $cachedjson !== null && $cachedjson !== '') {
        // Return cached JSON (already validated at cache set time).
        echo $cachedjson;
        exit;
    }

    // Cache miss -> DB lookup.
    $rec = $DB->get_record('block_ai_assistant_syllabus', ['blockinstanceid' => $blockid], '*', IGNORE_MISSING);
    if (!$rec) {
        throw new moodle_exception('nosyllabus', 'error', '', null, 'No syllabus saved for this block.');
    }

    $jsoncontent = (string)$rec->syllabus_json;

    // Validate JSON before caching.
    json_decode($jsoncontent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new moodle_exception('invalidjson', 'error', '', null, 'Invalid JSON in DB: ' . json_last_error_msg());
    }

    // Populate cache for next request.
    $cache->set($cachekey, $jsoncontent);

    echo $jsoncontent;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
    ]);
}
