define([
    'editor_tiny/options',
    './common'
], function(
    Options,
    Common
) {
    const { getPluginOptionName } = Options;
    const { pluginName } = Common;

    const showPlugin = getPluginOptionName(pluginName, 'showplugin');

    function register(editor) {
        const registerOption = editor.options.register;
        registerOption(showPlugin, {
            processor: 'boolean',
            "default": true,
        });
    }

    function isPluginVisible(editor) {
        return editor.options.get(showPlugin);
    }

    return {
        register: register,
        isPluginVisible: isPluginVisible
    };
});