<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_block_ai_assistant_v2_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026042301) {
        upgrade_block_savepoint(true, 2026042301, 'ai_assistant_v2');
    }

    if ($oldversion < 2026042304) {
        upgrade_block_savepoint(true, 2026042304, 'ai_assistant_v2');
    }

    if ($oldversion < 2026042305) {
        upgrade_block_savepoint(true, 2026042305, 'ai_assistant_v2');
    }

    if ($oldversion < 2026042306) {
        upgrade_block_savepoint(true, 2026042306, 'ai_assistant_v2');
    }

    if ($oldversion < 2026042307) {
        $historytable = new xmldb_table('block_ai_assistant_v2_history');
        if (!$dbman->table_exists($historytable)) {
            $historytable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $historytable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $historytable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $historytable->add_field('usertext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $historytable->add_field('botresponse', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $historytable->add_field('functioncalled', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $historytable->add_field('subject', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $historytable->add_field('topic', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $historytable->add_field('lesson', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $historytable->add_field('metadata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $historytable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $historytable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $historytable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $historytable->add_index('useridcourseid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $dbman->create_table($historytable);
        }

        $syllabustable = new xmldb_table('block_ai_assistant_v2_syllabus');
        if (!$dbman->table_exists($syllabustable)) {
            $syllabustable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $syllabustable->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $syllabustable->add_field('agent_key', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $syllabustable->add_field('mainsubjectkey', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $syllabustable->add_field('syllabus_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $syllabustable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $syllabustable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $syllabustable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $syllabustable->add_index('blockinstanceid_uix', XMLDB_INDEX_UNIQUE, ['blockinstanceid']);
            $dbman->create_table($syllabustable);
        }

        if ($dbman->table_exists(new xmldb_table('block_ai_assistant_history')) && !$DB->record_exists('block_ai_assistant_v2_history', [])) {
            $rs = $DB->get_recordset('block_ai_assistant_history');
            foreach ($rs as $record) {
                unset($record->id);
                $DB->insert_record('block_ai_assistant_v2_history', $record);
            }
            $rs->close();
        }

        if ($dbman->table_exists(new xmldb_table('block_ai_assistant_syllabus')) && !$DB->record_exists('block_ai_assistant_v2_syllabus', [])) {
            $rs = $DB->get_recordset('block_ai_assistant_syllabus');
            foreach ($rs as $record) {
                unset($record->id);
                $DB->insert_record('block_ai_assistant_v2_syllabus', $record);
            }
            $rs->close();
        }

        upgrade_block_savepoint(true, 2026042307, 'ai_assistant_v2');
    }

    if ($oldversion < 2026042308) {
        upgrade_block_savepoint(true, 2026042308, 'ai_assistant_v2');
    }

    return true;
}
