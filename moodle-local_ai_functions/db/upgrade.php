<?php
/**
 * FILE: moodle/local/ai_functions/db/upgrade.php
 * FIXED: Updated to use underscored column names (agent_key, config_data).
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_ai_functions_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Migration to version 2025112901 - Remove endpoint column and restructure config_data
    if ($oldversion < 2025112901) {
        
        $table = new xmldb_table('local_ai_functions_agents');
        $endpoint_field = new xmldb_field('endpoint');
        
        // Check if endpoint column still exists
        if ($dbman->field_exists($table, $endpoint_field)) {
            
            // Step 1: Migrate existing data to new format
            $agents = $DB->get_records('local_ai_functions_agents');
            
            foreach ($agents as $agent) {
                // FIXED: Use underscored column names
                $old_endpoint = property_exists($agent, 'endpoint') ? $agent->endpoint : '';
                $old_configdata = property_exists($agent, 'config_data') ? $agent->config_data : '';
                
                if (empty($old_configdata)) {
                    $old_config = [];
                } else {
                    $old_config = json_decode($old_configdata, true);
                    
                    if (!$old_config || !is_array($old_config)) {
                        $old_config = [];
                    }
                }

                // Build new config structure
                $new_config = [];
                $functions = ['ask_agent', 'youtube_summarize', 'websearch', 'mcq'];
                
                foreach ($functions as $func) {
                    if (isset($old_config[$func]) && !empty($old_config[$func])) {
                        $new_config[$func] = [
                            'endpoint' => !empty($old_endpoint) ? $old_endpoint : 'https://api.openai.com/v1/chat/completions',
                            'api_key' => $old_config[$func]
                        ];
                    } else {
                        $new_config[$func] = [
                            'endpoint' => !empty($old_endpoint) ? $old_endpoint : 'https://api.openai.com/v1/chat/completions',
                            'api_key' => 'UPDATE_ME'
                        ];
                    }
                }

                // FIXED: Use config_data column name
                if (!empty($new_config)) {
                    $update_record = new stdClass();
                    $update_record->id = $agent->id;
                    $update_record->config_data = json_encode($new_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $update_record->timemodified = time();
                    
                    $DB->update_record('local_ai_functions_agents', $update_record);
                }
            }

            // Step 2: Drop the endpoint column
            if ($dbman->field_exists($table, $endpoint_field)) {
                $dbman->drop_field($table, $endpoint_field);
            }
        }

        upgrade_plugin_savepoint(true, 2025112901, 'local', 'ai_functions');
    }

    return true;
}
