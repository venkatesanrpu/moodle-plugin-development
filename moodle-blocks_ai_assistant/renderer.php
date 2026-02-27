<?php
defined('MOODLE_INTERNAL') || die;
// FILE: moodle/blocks/ai_assistant/renderer.php


class block_ai_assistant_renderer extends plugin_renderer_base {
    /**
     * Renders the main chat widget template.
     *
     * @param stdClass $data The data to pass to the template.
     * @return string The rendered HTML.
     */
    public function render_main($data) {
        return $this->render_from_template('block_ai_assistant/main', $data);
    }

    /**
     * Renders the history page template.
     *
     * @param stdClass $data The data to pass to the template.
     * @return string The rendered HTML.
     */
    public function render_history($data) {
        // BUG FIX: Corrected 'a' to '$data'
        return $this->render_from_template('block_ai_assistant/history', $data);
    }
}

