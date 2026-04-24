<?php
namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;

class agent_repository {
    public static function get_agent_record(string $agentkey): \stdClass {
        global $DB;

        $record = $DB->get_record('local_ai_functions_v2_agents', ['agent_key' => $agentkey], '*', IGNORE_MISSING);
        if (!$record) {
            throw new moodle_exception('invalidagentkey', 'block_ai_assistant_v2');
        }
        return $record;
    }

    public static function get_agent_definition(string $agentkey): array {
        $record = self::get_agent_record($agentkey);
        $decoded = json_decode((string)$record->config_data, true);
        if (!is_array($decoded)) {
            throw new moodle_exception('invalidagentconfig', 'block_ai_assistant_v2');
        }
        return $decoded;
    }

    public static function require_function(string $agentkey, string $functionkey): array {
        $definition = self::get_agent_definition($agentkey);
        if (empty($definition['functions'][$functionkey]) || !is_array($definition['functions'][$functionkey])) {
            throw new moodle_exception('missingagentfunction', 'block_ai_assistant_v2', '', $functionkey);
        }
        return $definition['functions'][$functionkey];
    }
}
