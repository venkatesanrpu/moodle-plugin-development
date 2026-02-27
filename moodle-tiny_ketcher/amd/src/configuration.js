define([
    './common',
    'editor_tiny/uploader',
    'editor_tiny/utils'
], function(
    Common,
    uploader,
    Utils
) {
    const { buttonName } = Common;
    const { addContextmenuItem } = Utils;

    function configureMenu(menu) {
        menu.insert.items = buttonName + ' ' + menu.insert.items;
        return menu;
    }

    function configureToolbar(toolbar) {
        return toolbar.map(function(section) {
            if (section.name === 'content') {
                section.items.unshift(buttonName);
            }
            return section;
        });
    }

    function configure(instanceConfig) {
        return {
            contextmenu: addContextmenuItem(instanceConfig.contextmenu, buttonName),
            menu: configureMenu(instanceConfig.menu),
            toolbar: configureToolbar(instanceConfig.toolbar),
            images_upload_handler: function(blobInfo, progress) {
                return uploader(
                    window.tinymce.activeEditor,
                    'image',
                    blobInfo.blob(),
                    blobInfo.filename(),
                    progress
                );
            },
            images_reuse_filename: true,
        };
    }

    return {
        configure: configure
    };
});