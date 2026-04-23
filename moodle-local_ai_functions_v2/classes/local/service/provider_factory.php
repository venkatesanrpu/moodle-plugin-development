<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\service;

defined('MOODLE_INTERNAL') || die();

use local_ai_functions_v2\local\contracts\provider_interface;
use local_ai_functions_v2\local\providers\openai_compatible_provider;
use moodle_exception;

/**
 * Creates provider adapters.
 */
class provider_factory {
    /**
     * Create a provider instance.
     *
     * @param string $type
     * @return provider_interface
     */
    public static function make(string $type): provider_interface {
        return match ($type) {
            'openai_compatible' => new openai_compatible_provider(),
            default => throw new moodle_exception('Unsupported provider type: ' . $type),
        };
    }
}
