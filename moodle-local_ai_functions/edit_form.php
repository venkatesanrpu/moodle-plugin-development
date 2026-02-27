<?php
/**
 * FILE: moodle/local/ai_functions/edit_form.php
 * UPDATED: Removed endpoint field, enhanced config_data validation for new structure.
 */

require_once($CFG->libdir . '/formslib.php');

class local_ai_functions_edit_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        // Agent Name
        $mform->addElement('text', 'name', get_string('agentname', 'local_ai_functions'), ['maxlength' => 255, 'size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        // Agent Key
        $mform->addElement('text', 'agentkey', get_string('agentkey', 'local_ai_functions'), ['maxlength' => 100, 'size' => 50]);
        $mform->addHelpButton('agentkey', 'agentkey', 'local_ai_functions');
        $mform->setType('agentkey', PARAM_ALPHANUMEXT);
        $mform->addRule('agentkey', null, 'required');

        // Config Data (JSON) - removed endpoint field
        $mform->addElement('textarea', 'configdata', get_string('configdata', 'local_ai_functions'), ['rows' => 15, 'cols' => 80]);
        $mform->addHelpButton('configdata', 'configdata', 'local_ai_functions');
        $mform->setType('configdata', PARAM_RAW);
        $mform->addRule('configdata', null, 'required');
        
        // Add example in the description
        $example_json = '{
    "ask_agent": {
        "endpoint": "https://api.openai.com/v1/chat/completions",
        "api_key": "sk-..."
    },
    "youtube_summarize": {
        "endpoint": "https://api.openai.com/v1/chat/completions",
        "api_key": "sk-..."
    },
    "websearch": {
        "endpoint": "https://api.anthropic.com/v1/messages",
        "api_key": "sk-ant-..."
    },
    "mcq": {
        "endpoint": "https://api.openai.com/v1/chat/completions",
        "api_key": "sk-..."
    }
}';
        $mform->addElement('static', 'configdata_example', '', 
            '<strong>Example format:</strong><pre style="background:#f5f5f5;padding:10px;border-radius:4px;">' . 
            htmlspecialchars($example_json) . '</pre>');

        // Hidden ID field
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate JSON syntax
        $config = json_decode($data['configdata'], true);
        if ($config === null) {
            $errors['configdata'] = get_string('error_invalidjson', 'local_ai_functions');
            return $errors;
        }

        // Validate structure: must be an associative array
        if (!is_array($config)) {
            $errors['configdata'] = 'Config data must be a JSON object, not a string or number.';
            return $errors;
        }

        // Validate each function configuration
        $required_functions = ['youtube_summarize', 'websearch', 'mcq'];
        foreach ($required_functions as $func) {
            if (!isset($config[$func])) {
                $errors['configdata'] = "Missing required function configuration: '$func'";
                return $errors;
            }

            if (!is_array($config[$func])) {
                $errors['configdata'] = "Function '$func' must be a JSON object";
                return $errors;
            }

            // Validate endpoint
            if (!isset($config[$func]['endpoint']) || empty(trim($config[$func]['endpoint']))) {
                $errors['configdata'] = "Function '$func' is missing 'endpoint' field";
                return $errors;
            }

            // Validate endpoint is a URL
            if (!filter_var($config[$func]['endpoint'], FILTER_VALIDATE_URL)) {
                $errors['configdata'] = "Function '$func' has invalid endpoint URL";
                return $errors;
            }

            // Validate api_key
            if (!isset($config[$func]['api_key']) || empty(trim($config[$func]['api_key']))) {
                $errors['configdata'] = "Function '$func' is missing 'api_key' field";
                return $errors;
            }
        }

        return $errors;
    }
}
