<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\service;

defined('MOODLE_INTERNAL') || die();

use dml_missing_record_exception;
use moodle_exception;

/**
 * Reads and validates stored agent configuration.
 */
class config_repository {
    /**
     * Load an agent definition by key.
     *
     * @param string $agentkey
     * @return array
     */
    public static function get_agent_definition(string $agentkey): array {
        global $DB;

        $record = $DB->get_record('local_ai_functions_v2_agents', ['agent_key' => $agentkey], '*', IGNORE_MISSING);
        if (!$record) {
            throw new dml_missing_record_exception('Agent not found: ' . $agentkey);
        }

        $config = json_decode($record->config_data, true);
        if (!is_array($config)) {
            throw new moodle_exception('Invalid JSON in config_data for agent: ' . $agentkey);
        }

        if (empty($config['providers']) || empty($config['functions'])) {
            throw new moodle_exception('Agent config must define providers and functions.');
        }

        return $config;
    }
}
