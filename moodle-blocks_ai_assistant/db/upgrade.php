<?php
// FILE: blocks/ai_assistant/db/upgrade.php

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade code for block_ai_assistant.
 *
 * Production notes:
 * - All steps must be idempotent (safe to re-run).
 * - Use version gates and upgrade savepoints.
 */
function xmldb_block_ai_assistant_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // -------------------------------------------------------------------------
    // 2025122002: Create syllabus table (DB storage for syllabus JSON).
    // -------------------------------------------------------------------------
    if ($oldversion < 2025122002) {

        $table = new xmldb_table('block_ai_assistant_syllabus');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // IMPORTANT: NOT NULL char fields must not have DEFAULT ''.
        $table->add_field('agent_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mainsubjectkey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);

        $table->add_field('syllabus_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);

        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('blockinstanceid_uq', XMLDB_KEY_UNIQUE, ['blockinstanceid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2025122002, 'ai_assistant');
    }

    // -------------------------------------------------------------------------
    // 2025122004: Migrate admin config key agent_config_key -> agent_key.
    // -------------------------------------------------------------------------
    if ($oldversion < 2025122004) {

        // If the new key is not set, but the old key is set, copy it.
        $newvalue = get_config('block_ai_assistant', 'agent_key');
        $oldvalue = get_config('block_ai_assistant', 'agent_config_key');

        if ((empty($newvalue) || $newvalue === '') && !empty($oldvalue)) {
            set_config('agent_key', $oldvalue, 'block_ai_assistant');
        }

        // Optional cleanup: remove the old config to avoid future confusion.
        // Safe even if it doesn't exist.
        unset_config('agent_config_key', 'block_ai_assistant');

        upgrade_block_savepoint(true, 2025122004, 'ai_assistant');
    }

    return true;
}