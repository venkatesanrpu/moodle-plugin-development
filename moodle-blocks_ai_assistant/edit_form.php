<?php

// FILE: blocks/ai_assistant/edit_form.php

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot . '/blocks/edit_form.php');

/**
 * Block instance configuration form for block_ai_assistant.
 *
 * Design (auditing):
 * - config_agent_key and config_mainsubjectkey are saved in block config.
 * - config_syllabusjson is displayed/edited in the form but the source of truth is DB table
 *   {block_ai_assistant_syllabus} (one row per block instance).
 * - No "clear syllabus" UI to keep the form clean; clearing is done via other admin means.
 */
class block_ai_assistant_edit_form extends block_edit_form {

    protected function specific_definition($mform) {
        global $DB;

        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        // Agent options from local_ai_functions_agents.
        $agents = $DB->get_records('local_ai_functions_agents', [], 'name ASC');
        $options = [];
        foreach ($agents as $agent) {
            $options[$agent->agent_key] = $agent->name;
        }

        if (empty($options)) {
            $mform->addElement(
                'static',
                'noagents',
                get_string('no_agents_found_title', 'block_ai_assistant'),
                get_string('no_agents_found_desc', 'block_ai_assistant')
            );
        } else {
            $mform->addElement(
                'select',
                'config_agent_key',
                get_string('select_agent', 'block_ai_assistant'),
                $options
            );
            $mform->addHelpButton('config_agent_key', 'select_agent', 'block_ai_assistant');
        }

        // mainsubjectkey = audit label.
        $mform->addElement('text', 'config_mainsubjectkey', get_string('mainsubjectkey', 'block_ai_assistant'));
        $mform->setType('config_mainsubjectkey', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('config_mainsubjectkey', 'mainsubjectkey', 'block_ai_assistant');
        $mform->addRule('config_mainsubjectkey', get_string('required'), 'required', null, 'client');

        // Syllabus JSON (stored in DB table, not in block config).
        $mform->addElement(
            'textarea',
            'config_syllabusjson',
            get_string('syllabusjson', 'block_ai_assistant'),
            ['rows' => 22, 'cols' => 90]
        );
        $mform->setType('config_syllabusjson', PARAM_RAW);
        $mform->addHelpButton('config_syllabusjson', 'syllabusjson', 'block_ai_assistant');
    }

    /**
     * Populate textarea with existing DB JSON when editing an existing block instance.
     */
    public function set_data($defaults) {
        global $DB;

        if (is_array($defaults)) {
            $defaults = (object)$defaults;
        }

        if (!empty($this->block) && !empty($this->block->instance) && !empty($this->block->instance->id)) {
            $blockinstanceid = (int)$this->block->instance->id;

            if ($DB->get_manager()->table_exists('block_ai_assistant_syllabus')) {
                $rec = $DB->get_record(
                    'block_ai_assistant_syllabus',
                    ['blockinstanceid' => $blockinstanceid],
                    '*',
                    IGNORE_MISSING
                );
                if ($rec && isset($rec->syllabus_json)) {
                    $defaults->config_syllabusjson = $rec->syllabus_json;
                }
            }
        }

        parent::set_data($defaults);
    }
}
