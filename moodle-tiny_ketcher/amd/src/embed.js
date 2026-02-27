define([
    'core/str',
    'core/templates',
    'core/modal',
    'core/config',
    'core/notification'
], function(
    Str,
    Templates,
    Modal,
    Config,
    Notification
) {
    const { get_string: getString } = Str;
    const { exception: displayException } = Notification;

    var ketcherEmbed = function(editor) {
        this.editor = editor;
    };
    ketcherEmbed.prototype.init = async function() {
        try {
            const modal = await Modal.create({
                title: await getString('buttonNameTitle', 'tiny_ketcher'),
                show: true,
                removeOnClose: true,
            });

            const { html, js } = await Templates.renderForPromise('tiny_ketcher/ketcher_template', {});
            Templates.appendNodeContents(modal.getBody(), html, js);

            // Optionally load Ketcher scripts/styles here if needed
            const body = modal.getBody ? modal.getBody() : null;
            if (body) {
                body.style.height = '500px';
                body.style.overflow = 'hidden';
            }
        } catch (error) {
            displayException(error);
        }
    };

    ketcherEmbed.prototype.waitForKetcher = function() {
        return new Promise(function(resolve, reject) {
            const checkKetcher = setInterval(function() {
                if (window.ketcher) {
                    clearInterval(checkKetcher);
                    resolve(window.ketcher);
                }
            }, 100);
            setTimeout(function() {
                clearInterval(checkKetcher);
                reject(new Error('Ketcher loading timeout'));
            }, 15000);
        });
    };

    var saveData = async function() {
        var ketcher = window.ketcher;
        var struct = await ketcher.getKet();
        var image = await ketcher.generateImage(struct, {
            outputFormat: "svg",
            backgroundColor: "255, 255, 255"
        });
        var reader = new FileReader();
        reader.addEventListener('load', function() {
            var base64Image = reader.result;
            var parser = new DOMParser();
            var svgDoc = parser.parseFromString(atob(base64Image.split(',')[1]), "image/svg+xml");
            var svgElement = svgDoc.documentElement;
            var width = svgElement.getAttribute("width");
            var height = svgElement.getAttribute("height");
            if (window.parent.tinyMCE && window.parent.tinyMCE.activeEditor) {
                var url = URL.createObjectURL(image);
                var ketString = JSON.stringify(struct);
                var ketStruct = ketString.replace(/\\n/g, '').replace(/\\"/g, '"').replace(/ /g, '').slice(1, -1);
                var content = '<img src="' + url + '" width="' + width + '" height="' + height + '">';
                window.parent.tinyMCE.activeEditor.execCommand('mceInsertContent', 0, content);
                window.parent.tinyMCE.activeEditor.execCommand('mceInsertContent', 0, '<!--' + ketStruct + '-->');
            } else {
                window.console.log('TinyMCE not initialized');
            }
            window.parent.document.querySelector(".modal .close").click();
        });
        reader.readAsDataURL(image);
    };

    return {
        ketcherEmbed: ketcherEmbed,
        saveData: saveData
    };
});