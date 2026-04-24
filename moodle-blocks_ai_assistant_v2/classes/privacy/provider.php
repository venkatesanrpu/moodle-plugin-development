<?php
namespace block_ai_assistant_v2\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;

class provider implements metadata_provider {
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_ai_assistant_v2_history', [
            'userid' => 'privacy:metadata:block_ai_assistant_history:userid',
            'courseid' => 'privacy:metadata:block_ai_assistant_history:courseid',
            'usertext' => 'privacy:metadata:block_ai_assistant_history:usertext',
            'botresponse' => 'privacy:metadata:block_ai_assistant_history:botresponse',
            'functioncalled' => 'privacy:metadata:block_ai_assistant_history:functioncalled',
            'subject' => 'privacy:metadata:block_ai_assistant_history:subject',
            'topic' => 'privacy:metadata:block_ai_assistant_history:topic',
            'lesson' => 'privacy:metadata:block_ai_assistant_history:lesson',
            'metadata' => 'privacy:metadata:block_ai_assistant_history:metadata',
            'timecreated' => 'privacy:metadata:block_ai_assistant_history:timecreated',
            'timemodified' => 'privacy:metadata:block_ai_assistant_history:timemodified',
        ], 'privacy:metadata:block_ai_assistant_history');

        $collection->add_database_table('block_ai_assistant_v2_syllabus', [
            'blockinstanceid' => 'privacy:metadata:block_ai_assistant_syllabus:blockinstanceid',
            'agent_key' => 'privacy:metadata:block_ai_assistant_syllabus:agent_key',
            'mainsubjectkey' => 'privacy:metadata:block_ai_assistant_syllabus:mainsubjectkey',
            'syllabus_json' => 'privacy:metadata:block_ai_assistant_syllabus:syllabus_json',
            'timecreated' => 'privacy:metadata:block_ai_assistant_syllabus:timecreated',
            'timemodified' => 'privacy:metadata:block_ai_assistant_syllabus:timemodified',
        ], 'privacy:metadata:block_ai_assistant_syllabus');

        return $collection;
    }
}
