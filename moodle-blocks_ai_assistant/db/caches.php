<?php
// FILE: blocks/ai_assistant/db/caches.php

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'syllabus' => [
        // Shared cache is correct because syllabus is per block instance, not per user.
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true, // We store a JSON string.
        'ttl' => 3600,        // 1 hour; cache is also explicitly invalidated on save.
    ],
];