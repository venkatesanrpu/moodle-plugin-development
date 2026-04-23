<?php
// This file is part of Moodle - http://moodle.org/

namespace local_ai_functions_v2\local\service;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;

/**
 * Central dispatcher for configured AI functions.
 */
class dispatcher {
    /**
     * Call a configured function for an agent.
     *
     * @param string $agentkey
     * @param string $functionkey
     * @param array $payload
     * @return array|null
     */
    public static function call_endpoint(string $agentkey, string $functionkey, array $payload): ?array {
        $definition = config_repository::get_agent_definition($agentkey);

        if (empty($definition['functions'][$functionkey])) {
            throw new moodle_exception('Function not configured: ' . $functionkey);
        }

        $functionconfig = $definition['functions'][$functionkey];
        $providername = $functionconfig['provider'] ?? '';
        if ($providername === '' || empty($definition['providers'][$providername])) {
            throw new moodle_exception('Provider not configured for function: ' . $functionkey);
        }

        $providerconfig = $definition['providers'][$providername];
        $modulename = $functionconfig['module'] ?? $functionkey;
        $providertype = $providerconfig['type'] ?? '';

        $module = module_factory::make($modulename);
        $provider = provider_factory::make($providertype);

        $request = $module->build_request($payload, $functionconfig);
        $providerresult = $provider->execute($providerconfig, $functionconfig, $request);

        if (!empty($request['stream'])) {
            return null;
        }

        if ($providerresult === null) {
            throw new moodle_exception('Non-streaming provider call returned no result.');
        }

        return $module->normalise_result($providerresult, $functionconfig);
    }
}
