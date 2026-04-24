<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Required by Moodle core (moodleblock.class.php) for every block plugin.
    // Allows teachers and admins to add this block to a course page.
    'block/ai_assistant_v2:addinstance' => [
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Required by Moodle core for My Dashboard / user private pages.
    'block/ai_assistant_v2:myaddinstance' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Custom capability: used by external services and stream.php.
    'block/ai_assistant_v2:use' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'user'           => CAP_ALLOW,
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
