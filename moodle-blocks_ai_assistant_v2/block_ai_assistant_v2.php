<?php
// phase7_6b: Remove plugin-bundled MathJax; rely on Moodle native filter_mathjaxloader.
defined('MOODLE_INTERNAL') || die();

use block_ai_assistant_v2\local\syllabus_repository;

class block_ai_assistant_v2 extends block_base {

    public function init(): void {
        $this->title = get_string('pluginname', 'block_ai_assistant_v2');
    }

    public function has_config(): bool {
        return true;
    }

    public function applicable_formats(): array {
        return ['course-view' => true, 'site' => false, 'my' => false];
    }

    public function get_content(): stdClass {
        global $COURSE, $OUTPUT, $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        $agentkey = !empty($this->config->agent_key)
            ? $this->config->agent_key
            : ((string)get_config('block_ai_assistant_v2', 'agent_key') ?: 'default');

        $mainsubjectkey = !empty($this->config->mainsubjectkey)
            ? $this->config->mainsubjectkey
            : ((string)get_config('block_ai_assistant_v2', 'mainsubjectkey') ?: 'general');

        $context = [
            'uniqid'          => html_writer::random_id('block_ai_assistant_v2'),
            'courseid'        => (int)$COURSE->id,
            'blockinstanceid' => (int)$this->instance->id,
            'sesskey'         => sesskey(),
            'agentkey'        => $agentkey,
            'mainsubjectkey'  => $mainsubjectkey,
            'courseshortname' => format_string($COURSE->shortname),
            'streamurl'       => (new moodle_url('/blocks/ai_assistant_v2/stream.php'))->out(false),
        ];

        $PAGE->requires->css('/blocks/ai_assistant_v2/styles.css');

        // phase7_6b: Do NOT load a bundled MathJax copy here.
        // Moodle's native filter_mathjaxloader (MathJax 3) is already initialised
        // on every course page when the MathJax filter is enabled.
        // widget.js and history.js call window.MathJax.typesetPromise([node])
        // directly after inserting rendered HTML into the DOM.
        // No manual window.MathJax config override needed — the filter handles it.

        $PAGE->requires->js_call_amd('block_ai_assistant_v2/widget',  'init', [$context]);
        $PAGE->requires->js_call_amd('block_ai_assistant_v2/history', 'init', [$context]);
        $PAGE->requires->js_call_amd('block_ai_assistant_v2/mcq',     'init', [$context]);

        $this->content->text = $OUTPUT->render_from_template('block_ai_assistant_v2/main', $context);

        return $this->content;
    }

    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Save instance configuration and persist syllabus data.
     *
     * Note: parent::instance_config_save() is declared void in Moodle 5.x.
     * Do NOT capture its return value.
     *
     * @param stdClass $data          Submitted and validated form data.
     * @param bool     $nolongerused  Legacy parameter, unused.
     * @return bool
     */
    public function instance_config_save($data, $nolongerused = false): bool {
        parent::instance_config_save($data, $nolongerused);

        $agentkey = !empty($data->agent_key)
            ? (string)$data->agent_key
            : ((string)get_config('block_ai_assistant_v2', 'agent_key') ?: 'default');

        $mainsubjectkey = !empty($data->mainsubjectkey)
            ? (string)$data->mainsubjectkey
            : ((string)get_config('block_ai_assistant_v2', 'mainsubjectkey') ?: 'general');

        $syllabusjson = isset($data->syllabusjson) ? (string)$data->syllabusjson : '[]';

        syllabus_repository::upsert_for_block(
            (int)$this->instance->id,
            $agentkey,
            $mainsubjectkey,
            $syllabusjson
        );

        return true;
    }
}
