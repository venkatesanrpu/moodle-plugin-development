<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tiny_ketcher;

use context;
use editor_tiny\plugin;
use editor_tiny\plugin_with_buttons;
use editor_tiny\plugin_with_menuitems;
use editor_tiny\plugin_with_configuration;

class plugin implements plugin_with_buttons, plugin_with_configuration {
    /**
     * Get the name of the plugin.
     *
     * @return string
     */
    public static function get_plugin_name(): string {
        return 'tiny_ketcher';
    }

    /**
     * Returns the configuration for the plugin.
     *
     * @param context $context The context that the editor is used within
     * @param array $options The options passed in when requesting the editor
     * @param array $fpoptions The filepicker options passed in when requesting the editor
     * @param editor|null $editor The editor instance in which the plugin is initialised
     * @return array
     */
    public static function get_plugin_configuration_for_context(
        context $context,
        array $options,
        array $fpoptions,
        ?\editor_tiny\editor $editor = null
    ): array {
        return [
            'ketcherUrl' => new \moodle_url('/lib/editor/tiny/plugins/ketcher/ketcher/index.html'),
        ];
    }

    /**
     * Get the buttons provided by this plugin.
     *
     * @return array
     */
    public static function get_buttons(): array {
        return [
            'tiny_ketcher',
        ];
    }

    /**
     * Get the configuration for the buttons provided by this plugin.
     *
     * @param context $context The context that the editor is used within
     * @param array $options The options passed in when requesting the editor
     * @param array $fpoptions The filepicker options passed in when requesting the editor
     * @param editor|null $editor The editor instance in which the plugin is initialised
     * @return array
     */
    public static function get_buttons_configuration(
        context $context,
        array $options,
        array $fpoptions,
        ?\editor_tiny\editor $editor = null
    ): array {
        return [
            'tiny_ketcher' => [
                'icon' => 'icon',
                'title' => get_string('pluginname', 'tiny_ketcher'),
            ],
        ];
    }
} 
