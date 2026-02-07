/**
 * WPTransformed Admin Settings Page
 * assets/admin/js/admin.js
 *
 * Handles:
 * 1. Module toggle (on/off) via AJAX
 * 2. Settings section show/hide when toggling
 * 3. No jQuery dependency — vanilla JS
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // -- Module Toggle (AJAX) --
        document.querySelectorAll('.wpt-module-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var moduleId = this.dataset.moduleId;
                var active = this.checked ? '1' : '0';
                var card = this.closest('.wpt-module-card');
                var settingsPanel = card ? card.querySelector('.wpt-module-settings') : null;

                // Optimistic UI: toggle the card active class immediately
                if (card) {
                    card.classList.toggle('wpt-module-active', this.checked);
                }

                // Show/hide settings panel
                if (settingsPanel) {
                    settingsPanel.style.display = this.checked ? '' : 'none';
                }

                // AJAX request to save toggle state
                var formData = new FormData();
                formData.append('action', 'wpt_toggle_module');
                formData.append('module_id', moduleId);
                formData.append('active', active);
                formData.append('nonce', wptAdmin.nonce);

                fetch(wptAdmin.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data.success) {
                        // Revert on failure
                        toggle.checked = !toggle.checked;
                        if (card) {
                            card.classList.toggle('wpt-module-active', toggle.checked);
                        }
                        if (settingsPanel) {
                            settingsPanel.style.display = toggle.checked ? '' : 'none';
                        }
                        // Show error
                        alert('Failed to toggle module: ' + (data.data || 'Unknown error'));
                    }
                })
                .catch(function() {
                    // Revert on network error
                    toggle.checked = !toggle.checked;
                    if (card) {
                        card.classList.toggle('wpt-module-active', toggle.checked);
                    }
                    if (settingsPanel) {
                        settingsPanel.style.display = toggle.checked ? '' : 'none';
                    }
                });
            });
        });
    });
})();
