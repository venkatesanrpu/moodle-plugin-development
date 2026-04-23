<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\contracts;

defined('MOODLE_INTERNAL') || die();

/**
 * Contract for function modules such as notes_agent and mcq_agent.
 */
interface module_interface {
    /**
     * Build a canonical request that is provider-agnostic.
     *
     * @param array $payload Raw caller payload.
     * @param array $functionconfig Function configuration.
     * @return array
     */
    public function build_request(array $payload, array $functionconfig): array;

    /**
     * Normalise the provider result for the caller.
     *
     * @param array $providerresult
     * @param array $functionconfig
     * @return array
     */
    public function normalise_result(array $providerresult, array $functionconfig): array;
}
