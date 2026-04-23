<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ai_functions_v2_manage');

$agents = $DB->get_records('local_ai_functions_v2_agents', null, 'name ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_agents_heading', 'local_ai_functions_v2'));

$table = new html_table();
$table->head = [
    get_string('agentname', 'local_ai_functions_v2'),
    get_string('agentkey', 'local_ai_functions_v2'),
    get_string('functions_configured', 'local_ai_functions_v2'),
    get_string('actions', 'local_ai_functions_v2'),
];
$table->data = [];

foreach ($agents as $agent) {
    $config = json_decode($agent->config_data, true);
    $functioncount = is_array($config['functions'] ?? null) ? count($config['functions']) : 0;

    $editurl = new moodle_url('/local/ai_functions_v2/edit.php', ['id' => $agent->id]);
    $deleteurl = new moodle_url('/local/ai_functions_v2/edit.php', [
        'id' => $agent->id,
        'action' => 'delete',
        'sesskey' => sesskey(),
    ]);

    $actions = html_writer::link($editurl, get_string('edit'));
    $actions .= ' | ';
    $actions .= html_writer::link($deleteurl, get_string('delete'), [
        'onclick' => 'return confirm(' . json_encode(get_string('deleteconfirm', 'local_ai_functions_v2')) . ');',
    ]);

    $table->data[] = [
        format_string($agent->name),
        s($agent->agent_key),
        $functioncount,
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url('/local/ai_functions_v2/edit.php'), get_string('add_new_agent', 'local_ai_functions_v2'));
echo $OUTPUT->footer();
