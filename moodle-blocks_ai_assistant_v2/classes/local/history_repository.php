<?php
namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

class history_repository {
    public static function table_name(): string {
        global $DB;
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $manager = $DB->get_manager();
        if ($manager->table_exists('block_ai_assistant_v2_history')) {
            $resolved = 'block_ai_assistant_v2_history';
        } else {
            $resolved = 'block_ai_assistant_history';
        }
        return $resolved;
    }

    public static function insert(\stdClass $record): int {
        global $DB;
        return (int)$DB->insert_record(self::table_name(), $record);
    }

    public static function update(\stdClass $record): void {
        global $DB;
        $DB->update_record(self::table_name(), $record);
    }

    public static function get_record(array $conditions, string $fields = '*', int $strictness = IGNORE_MISSING): ?\stdClass {
        global $DB;
        return $DB->get_record(self::table_name(), $conditions, $fields, $strictness) ?: null;
    }

    public static function table_sql(): string {
        return '{' . self::table_name() . '}';
    }
}
