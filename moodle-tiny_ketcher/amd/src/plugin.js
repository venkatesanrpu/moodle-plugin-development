define([
    'editor_tiny/loader',
    'editor_tiny/utils',
    './common',
    './options',
    './commands',
    './configuration'
], function(
    Loader,
    Utils,
    Common,
    Options,
    Commands,
    Configuration
) {
    const { component, pluginName } = Common;
    const { register: registerOptions } = Options;
    const { getSetup: getCommandSetup } = Commands;

    return new Promise(async function(resolve) {
        const [
            tinyMCE,
            pluginMetadata,
            setupCommands,
        ] = await Promise.all([
            Loader.getTinyMCE(),
            Utils.getPluginMetadata(component, pluginName),
            getCommandSetup(),
        ]);

        tinyMCE.PluginManager.add(pluginName, function(editor) {
            registerOptions(editor);
            setupCommands(editor);
            return pluginMetadata;
        });

        resolve([pluginName, Configuration]);
    });
});