<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/edit_form.php');

use block_ai_assistant_v2\local\syllabus_repository;

class block_ai_assistant_v2_edit_form extends block_edit_form {

    protected function specific_definition($mform): void {
        global $CFG;

        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        // Load the local plugin lib so list helper is available.
        $locallib = $CFG->dirroot . '/local/ai_functions_v2/lib.php';
        if (is_readable($locallib)) {
            require_once($locallib);
        }

        $agents = ['' => get_string('choose')];
        if (function_exists('local_ai_functions_v2_list_agent_keys')) {
            foreach (local_ai_functions_v2_list_agent_keys() as $key => $label) {
                $agents[$key] = $label;
            }
        } else {
            // Fallback: query the agents table directly.
            global $DB;
            $table = 'local_ai_functions_v2_agents';
            if ($DB->get_manager()->table_exists($table)) {
                $rows = $DB->get_records($table, null, 'name ASC', 'agent_key, name');
                foreach ($rows as $row) {
                    $agents[$row->agent_key] = $row->name;
                }
            }
        }

        $mform->addElement('select', 'config_agent_key',
            get_string('agentkey', 'block_ai_assistant_v2'), $agents);
        $mform->setDefault('config_agent_key',
            (string)get_config('block_ai_assistant_v2', 'agent_key'));

        $mform->addElement('text', 'config_mainsubjectkey',
            get_string('mainsubjectkey', 'block_ai_assistant_v2'));
        $mform->setType('config_mainsubjectkey', PARAM_TEXT);
        $mform->setDefault('config_mainsubjectkey',
            (string)get_config('block_ai_assistant_v2', 'mainsubjectkey'));

        $mform->addElement('textarea', 'config_syllabusjson',
            get_string('syllabusjson', 'block_ai_assistant_v2'), 'rows="14" cols="70"');
        $mform->setType('config_syllabusjson', PARAM_RAW);
        $mform->addHelpButton('config_syllabusjson', 'syllabusjson', 'block_ai_assistant_v2');
    }

    public function set_data($defaults) {
        if (!empty($this->block->instance->id)) {
            $record = syllabus_repository::get_for_block((int)$this->block->instance->id);
            if ($record) {
                $defaults->config_syllabusjson  = (string)($record->syllabus_json ?? '');
                if (empty($defaults->config_agent_key) && !empty($record->agent_key)) {
                    $defaults->config_agent_key = $record->agent_key;
                }
                if (empty($defaults->config_mainsubjectkey) && !empty($record->mainsubjectkey)) {
                    $defaults->config_mainsubjectkey = $record->mainsubjectkey;
                }
            }
        }
        parent::set_data($defaults);
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!empty($data['config_syllabusjson'])) {
            json_decode($data['config_syllabusjson'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors['config_syllabusjson'] = get_string('invalidjson', 'editor');
            }
        }
        return $errors;
    }
}
