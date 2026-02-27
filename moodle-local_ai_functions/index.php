<?php
/**
 * FILE: moodle/local/ai_functions/index.php
 * FIXED: Updated to use underscored column names (agent_key, config_data).
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ai_functions_manage');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_agents_heading', 'local_ai_functions'));

$agents = $DB->get_records('local_ai_functions_agents');

$table = new html_table();
$table->head = [
    get_string('agentname', 'local_ai_functions'),
    get_string('agentkey', 'local_ai_functions'),
    get_string('functions_configured', 'local_ai_functions'),
    get_string('actions', 'local_ai_functions')
];
$table->data = [];

foreach ($agents as $agent) {
    $edit_url = new moodle_url('/local/ai_functions/edit.php', ['id' => $agent->id]);
    $delete_url = new moodle_url('/local/ai_functions/edit.php', [
        'id' => $agent->id,
        'action' => 'delete',
        'sesskey' => sesskey()
    ]);

    $actions = html_writer::link($edit_url, get_string('edit'));
    $actions .= ' | ';
    $actions .= html_writer::link($delete_url, get_string('delete'), [
        'onclick' => "return confirm('Are you sure?');"
    ]);

    // FIXED: Use config_data column name
    $config = json_decode($agent->config_data, true);
    $function_count = is_array($config) ? count($config) : 0;
    $function_display = $function_count > 0 ? 
        $function_count . ' function(s) configured' : 
        '<span style="color:red;">Invalid configuration</span>';

    $table->data[] = [
        htmlspecialchars($agent->name),
        htmlspecialchars($agent->agent_key),  // FIXED: agent_key not agentkey
        $function_display,
        $actions
    ];
}

echo html_writer::table($table);

$add_url = new moodle_url('/local/ai_functions/edit.php');
echo $OUTPUT->single_button($add_url, get_string('add_new_agent', 'local_ai_functions'));

echo $OUTPUT->footer();
