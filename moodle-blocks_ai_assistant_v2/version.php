<?php
// phase7_6d: Bump version for cache invalidation of patched AMD modules.
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_ai_assistant_v2';
$plugin->version   = 2026042405;   // phase7_6d — bumped from previous
$plugin->requires  = 2023111800;   // Moodle 5.0+
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '7.6d';
