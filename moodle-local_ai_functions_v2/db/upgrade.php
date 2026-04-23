<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Upgrade script for local_ai_functions_v2.
 *
 * @package   local_ai_functions_v2
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade hook.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ai_functions_v2_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026042300) {
        upgrade_plugin_savepoint(true, 2026042300, 'local', 'ai_functions_v2');
    }

    return true;
}
