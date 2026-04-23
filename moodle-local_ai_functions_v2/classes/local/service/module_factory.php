<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\service;

defined('MOODLE_INTERNAL') || die();

use local_ai_functions_v2\local\contracts\module_interface;
use local_ai_functions_v2\local\modules\notes_agent_module;
use local_ai_functions_v2\local\modules\mcq_agent_module;
use moodle_exception;

/**
 * Creates function modules.
 */
class module_factory {
    /**
     * Create a module instance.
     *
     * @param string $name
     * @return module_interface
     */
    public static function make(string $name): module_interface {
        return match ($name) {
            'notes_agent' => new notes_agent_module(),
            'mcq_agent' => new mcq_agent_module(),
            default => throw new moodle_exception('Unsupported module: ' . $name),
        };
    }
}
