/**
 * Local User Avatar — Media uploader integration for profile page.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var data = window.wptLocalAvatar || {};

    function initUploader(buttonId, inputId, previewId, removeId) {
        var button = document.getElementById(buttonId);
        if (!button) return;

        var frame = null;

        button.addEventListener('click', function (e) {
            e.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: data.title || 'Select Avatar',
                button: { text: data.button || 'Use as Avatar' },
                multiple: false,
                library: { type: 'image' },
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var input = document.getElementById(inputId);
                var preview = document.getElementById(previewId);

                if (input) {
                    input.value = attachment.id;
                }

                if (preview) {
                    var url = attachment.sizes && attachment.sizes.thumbnail
                        ? attachment.sizes.thumbnail.url
                        : attachment.url;
                    preview.innerHTML = '<img src="' + url + '" alt="" style="width: 96px; height: 96px; border-radius: 50%; object-fit: cover;">';
                }

                // Show remove button if it exists.
                var removeBtn = document.getElementById(removeId);
                if (removeBtn) {
                    removeBtn.style.display = '';
                }
            });

            frame.open();
        });

        // Remove button.
        var removeBtn = document.getElementById(removeId);
        if (removeBtn) {
            removeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var input = document.getElementById(inputId);
                var preview = document.getElementById(previewId);

                if (input) {
                    input.value = '0';
                }

                if (preview) {
                    preview.innerHTML = '';
                }

                removeBtn.style.display = 'none';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Profile page avatar.
        initUploader('wpt-upload-avatar', 'wpt-avatar-id', 'wpt-avatar-preview', 'wpt-remove-avatar');

        // Settings page default avatar.
        initUploader('wpt-upload-default-avatar', 'wpt-default-avatar-id', 'wpt-default-avatar-preview', 'wpt-remove-default-avatar');
    });
})();
