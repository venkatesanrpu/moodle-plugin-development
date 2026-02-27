<?php
// FILE: moodle/local/ai_functions/db/install.php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_ai_functions_install() {
    global $DB;
    $dbman = $DB->get_manager();

    // Create the main table if it doesn't exist.
    if ($dbman->table_exists('local_ai_functions_agents')) {
        // Dummy data insertion is handled by the upgrade script to ensure it runs only once.
        return true;
    }
}

function xmldb_local_ai_functions_install_data() {
    global $DB;

    // --- Dummy Data for Chemistry AI Agent ---
    $chem_agent = new stdClass();
    $chem_agent->name = 'Chemistry AI Functions';
    $chem_agent->agent_key = 'chemistry_ai';
    $chem_agent->endpoint = 'https://chemistry-app.azurewebsites.net/api/';
    $chem_agent->config_data = json_encode([
        'ask_agent' => 'chem_ask_agent_dummy_key',
        'youtube_summarize' => 'chem_youtube_summarize_dummy_key',
        'websearch' => 'chem_websearch_dummy_key',
        'mcq' => 'chem_mcq_dummy_key'
    ]);
    $chem_agent->timecreated = time();
    $chem_agent->timemodified = time();
    $DB->insert_record('local_ai_functions_agents', $chem_agent);

    // --- Dummy Data for Physics AI Agent ---
    $phys_agent = new stdClass();
    $phys_agent->name = 'Physics AI Functions';
    $phys_agent->agent_key = 'physics_ai';
    $phys_agent->endpoint = 'https://physics-app.azurewebsites.net/api/';
    $phys_agent->config_data = json_encode([
        'define_term' => 'phys_define_term_dummy_key',
        'solve_problem' => 'phys_solve_problem_dummy_key',
        'mcq' => 'phys_mcq_dummy_key'
    ]);
    $phys_agent->timecreated = time();
    $phys_agent->timemodified = time();
    $DB->insert_record('local_ai_functions_agents', $phys_agent);

    return true;
}
