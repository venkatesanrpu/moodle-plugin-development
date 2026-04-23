<?php
// This file is part of Moodle - http://moodle.org/

require_once($CFG->libdir . '/formslib.php');

/**
 * Edit form for AI agent definitions.
 */
class local_ai_functions_v2_edit_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('agentname', 'local_ai_functions_v2'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        $mform->addElement('text', 'agentkey', get_string('agentkey', 'local_ai_functions_v2'), ['size' => 50]);
        $mform->addHelpButton('agentkey', 'agentkey', 'local_ai_functions_v2');
        $mform->setType('agentkey', PARAM_ALPHANUMEXT);
        $mform->addRule('agentkey', null, 'required');

        $mform->addElement('textarea', 'configdata', get_string('configdata', 'local_ai_functions_v2'), ['rows' => 24, 'cols' => 100]);
        $mform->addHelpButton('configdata', 'configdata', 'local_ai_functions_v2');
        $mform->setType('configdata', PARAM_RAW);
        $mform->addRule('configdata', null, 'required');

        $example = [
            'providers' => [
                'notes_provider' => [
                    'type' => 'openai_compatible',
                    'endpoint' => 'https://example.com/v1/chat/completions',
                    'api_key' => 'replace-me',
                    'api_style' => 'chat_completions',
                ],
                'mcq_provider' => [
                    'type' => 'openai_compatible',
                    'endpoint' => 'https://example.com/v1/responses',
                    'api_key' => 'replace-me',
                    'api_style' => 'responses',
                ],
            ],
            'functions' => [
                'notes_agent' => [
                    'module' => 'notes_agent',
                    'provider' => 'notes_provider',
                    'model' => 'mistral-large-3',
                    'stream' => true,
                ],
                'mcq_agent' => [
                    'module' => 'mcq_agent',
                    'provider' => 'mcq_provider',
                    'model' => 'gpt-4.1',
                    'stream' => false,
                ],
            ],
        ];

        $mform->addElement('static', 'configexample', '', html_writer::tag(
            'pre',
            s(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            ['style' => 'background:#f5f5f5;padding:1rem;overflow:auto;']
        ));

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $config = json_decode($data['configdata'], true);
        if ($config === null || !is_array($config)) {
            $errors['configdata'] = get_string('error_invalidjson', 'local_ai_functions_v2');
            return $errors;
        }

        if (empty($config['providers']) || !is_array($config['providers'])) {
            $errors['configdata'] = get_string('error_invalidschema', 'local_ai_functions_v2');
            return $errors;
        }

        if (empty($config['functions']) || !is_array($config['functions'])) {
            $errors['configdata'] = get_string('error_invalidschema', 'local_ai_functions_v2');
            return $errors;
        }

        foreach (['notes_agent', 'mcq_agent'] as $requiredfunction) {
            if (empty($config['functions'][$requiredfunction])) {
                $errors['configdata'] = get_string('requiredfunctionmissing', 'local_ai_functions_v2', $requiredfunction);
                return $errors;
            }
        }

        foreach ($config['providers'] as $providername => $providerconfig) {
            if (!is_array($providerconfig)) {
                $errors['configdata'] = 'Provider ' . s($providername) . ' must be an object.';
                return $errors;
            }
            if (empty($providerconfig['type']) || empty($providerconfig['endpoint']) || empty($providerconfig['api_key'])) {
                $errors['configdata'] = 'Provider ' . s($providername) . ' must define type, endpoint, and api_key.';
                return $errors;
            }
            if (!filter_var($providerconfig['endpoint'], FILTER_VALIDATE_URL)) {
                $errors['configdata'] = 'Provider ' . s($providername) . ' has an invalid endpoint URL.';
                return $errors;
            }
        }

        foreach ($config['functions'] as $functionname => $functionconfig) {
            if (!is_array($functionconfig)) {
                $errors['configdata'] = 'Function ' . s($functionname) . ' must be an object.';
                return $errors;
            }
            if (empty($functionconfig['module']) || empty($functionconfig['provider'])) {
                $errors['configdata'] = 'Function ' . s($functionname) . ' must define module and provider.';
                return $errors;
            }
            if (empty($config['providers'][$functionconfig['provider']])) {
                $errors['configdata'] = get_string('providermissing', 'local_ai_functions_v2');
                return $errors;
            }
        }

        return $errors;
    }
}
