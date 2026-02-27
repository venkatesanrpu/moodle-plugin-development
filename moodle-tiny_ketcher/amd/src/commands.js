define([
    'editor_tiny/utils',
    'core/str',
    './common',
    './embed'
], function(
    Utils,
    Str,
    Common,
    Embed
) {
    const { getButtonImage } = Utils;
    const { get_string: getString } = Str;
    const { component, icon, buttonName } = Common;
    const { ketcherEmbed, saveData } = Embed;

    const isImage = function(node) { return node.nodeName.toLowerCase() === 'img'; };

    const handleAction = async function(editor) {
        const ketcherImage = new ketcherEmbed(editor);
        ketcherImage.init();
        try {
            const ketcher = await ketcherImage.waitForKetcher();
            if (window.json) {
                window.console.log("molecule loading...", window.json);
                ketcher.setMolecule(window.json);
            } else {
                window.console.log("Ketcher Molecular Data Not Available");
            }
        } catch (error) {
            window.console.error(error.message);
        }
        document.getElementById('actionButton').addEventListener('click', saveData);
    };

    const getSetup = async function() {
        const [
            buttonNameTitle,
            buttonImage,
        ] = await Promise.all([
            getString('buttonNameTitle', component),
            getButtonImage('icon', component),
        ]);

        return function(editor) {
            editor.ui.registry.addIcon(icon, buttonImage.html);

            editor.ui.registry.addButton(buttonName, {
                icon,
                tooltip: buttonNameTitle,
                onAction: function() { handleAction(editor); },
            });

            editor.ui.registry.addToggleButton(buttonName, {
                icon,
                tooltip: buttonNameTitle,
                onAction: function() { handleAction(editor, window.json); },
                onSetup: function(api) {
                    return editor.selection.selectorChangedWithUnbind(
                        'img:not([data-mce-object]):not([data-mce-placeholder]),figure.image',
                        function () {
                            var node = editor.selection.getNode();
                            var parentNode = node.parentNode;
                            const html = editor.serializer.serialize(parentNode);
                            const commentMatch = html.match(/<!--(.*?)-->/);
                            if (commentMatch) {
                                try {
                                    var json = JSON.parse(commentMatch[1]);
                                    api.setActive(true);
                                    window.json = JSON.stringify(json);
                                } catch (e) {
                                    api.setActive(false);
                                }
                            } else {
                                api.setActive(false);
                            }
                        }
                    ).unbind;
                }
            });

            editor.ui.registry.addContextToolbar(buttonName, {
                predicate: isImage,
                items: buttonName,
                position: 'node',
                scope: 'node'
            });

            editor.ui.registry.addMenuItem(buttonName, {
                icon,
                text: buttonNameTitle,
                onAction: function() { handleAction(editor); },
            });
        };
    };

    return {
        getSetup: getSetup
    };
});