<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_ai_assistant_v2_ask_agent' => [
        'classname' => 'block_ai_assistant_v2\\external\\ask_agent',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Prepare AI assistant request and create history row.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/ai_assistant_v2:use',
    ],
    'block_ai_assistant_v2_get_history' => [
        'classname' => 'block_ai_assistant_v2\\external\\get_history',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Fetch AI assistant history records.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/ai_assistant_v2:use',
    ],
    'block_ai_assistant_v2_save_history' => [
        'classname' => 'block_ai_assistant_v2\\external\\save_history',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Persist final AI assistant response to history.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/ai_assistant_v2:use',
    ],
    'block_ai_assistant_v2_get_syllabus' => [
        'classname' => 'block_ai_assistant_v2\\external\\get_syllabus',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Load syllabus JSON for guided search.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/ai_assistant_v2:use',
    ],
    'block_ai_assistant_v2_ask_mcq' => [
        'classname' => 'block_ai_assistant_v2\\external\\ask_mcq',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Generate MCQs through local_ai_functions_v2.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/ai_assistant_v2:use',
    ],
    'block_ai_assistant_v2_render_response' => [
        'classname'   => 'block_ai_assistant_v2\\external\\render_response',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Render raw LLM markdown+math to safe HTML.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities'=> 'block/ai_assistant_v2:view',
    ],
];
