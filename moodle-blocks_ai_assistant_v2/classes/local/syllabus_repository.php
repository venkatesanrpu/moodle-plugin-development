<?php
namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

class syllabus_repository {
    public static function table_name(): string {
        global $DB;
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $manager = $DB->get_manager();
        if ($manager->table_exists('block_ai_assistant_v2_syllabus')) {
            $resolved = 'block_ai_assistant_v2_syllabus';
        } else {
            $resolved = 'block_ai_assistant_syllabus';
        }
        return $resolved;
    }

    public static function get_for_block(int $blockinstanceid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::table_name(), ['blockinstanceid' => $blockinstanceid], '*', IGNORE_MISSING) ?: null;
    }

    public static function upsert_for_block(int $blockinstanceid, string $agentkey, string $mainsubjectkey, string $syllabusjson): void {
        global $DB;
        $existing = self::get_for_block($blockinstanceid);
        $now = time();
        if ($existing) {
            $existing->agent_key = $agentkey;
            $existing->mainsubjectkey = $mainsubjectkey;
            $existing->syllabus_json = $syllabusjson;
            $existing->timemodified = $now;
            $DB->update_record(self::table_name(), $existing);
            return;
        }
        $record = (object)[
            'blockinstanceid' => $blockinstanceid,
            'agent_key' => $agentkey,
            'mainsubjectkey' => $mainsubjectkey,
            'syllabus_json' => $syllabusjson,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record(self::table_name(), $record);
    }
}
