<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/edit_form.php');

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

admin_externalpage_setup('local_ai_functions_v2_manage');
require_sesskey();

if ($action === 'delete' && $id) {
    $DB->delete_records('local_ai_functions_v2_agents', ['id' => $id]);
    redirect(new moodle_url('/local/ai_functions_v2/index.php'));
}

$record = null;
if ($id) {
    $record = $DB->get_record('local_ai_functions_v2_agents', ['id' => $id], '*', MUST_EXIST);
}

$form = new local_ai_functions_v2_edit_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/ai_functions_v2/index.php'));
}

if ($data = $form->get_data()) {
    $now = time();
    $save = new stdClass();
    $save->id = $data->id;
    $save->name = $data->name;
    $save->agent_key = $data->agentkey;
    $save->config_data = $data->configdata;
    $save->timemodified = $now;

    if (!empty($data->id)) {
        $existing = $DB->get_record('local_ai_functions_v2_agents', ['id' => $data->id], '*', MUST_EXIST);
        $save->timecreated = $existing->timecreated;
        $DB->update_record('local_ai_functions_v2_agents', $save);
    } else {
        unset($save->id);
        $save->timecreated = $now;
        $DB->insert_record('local_ai_functions_v2_agents', $save);
    }

    redirect(new moodle_url('/local/ai_functions_v2/index.php'), get_string('saved', 'local_ai_functions_v2'));
}

if ($record) {
    $record->agentkey = $record->agent_key;
    $record->configdata = $record->config_data;
    $form->set_data($record);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editagent', 'local_ai_functions_v2'));
$form->display();
echo $OUTPUT->footer();
