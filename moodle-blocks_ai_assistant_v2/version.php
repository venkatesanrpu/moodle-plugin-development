<?php
// phase7_6e: render_response.php patched — uses history_repository::get_record()
// and render_helper::render() (strip_tags, not clean_text).
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_ai_assistant_v2';
$plugin->version   = 2026042407;
$plugin->requires  = 2023111800;   // Moodle 5.0+
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '7.6e';
