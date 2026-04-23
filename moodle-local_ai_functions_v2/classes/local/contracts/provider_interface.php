<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\contracts;

defined('MOODLE_INTERNAL') || die();

/**
 * Contract for provider adapters.
 */
interface provider_interface {
    /**
     * Execute a provider request.
     *
     * Non-streaming calls return a normalised provider result array.
     * Streaming calls emit normalised SSE events directly and return null.
     *
     * @param array $providerconfig
     * @param array $functionconfig
     * @param array $request
     * @return array|null
     */
    public function execute(array $providerconfig, array $functionconfig, array $request): ?array;
}
