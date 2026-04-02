/**
 * Limit Login Attempts — Admin JS.
 *
 * Handles clear log and unlock IP AJAX actions.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptLimitLogin || {};

    /**
     * Clear entire login log.
     */
    function initClearLog() {
        var btn = document.getElementById('wpt-clear-login-log');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!confirm(config.i18n.confirmClear)) return;

            btn.disabled = true;
            btn.textContent = config.i18n.clearing;

            var spinner = document.getElementById('wpt-clear-log-spinner');
            if (spinner) spinner.classList.add('is-active');

            var formData = new FormData();
            formData.append('action', 'wpt_clear_login_log');
            formData.append('nonce', config.clearLogNonce);

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.data.message || config.i18n.networkError);
                        btn.disabled = false;
                        btn.textContent = 'Clear Entire Log';
                    }
                })
                .catch(function () {
                    alert(config.i18n.networkError);
                    btn.disabled = false;
                    btn.textContent = 'Clear Entire Log';
                })
                .finally(function () {
                    if (spinner) spinner.classList.remove('is-active');
                });
        });
    }

    /**
     * Unlock individual IPs.
     */
    function initUnlockButtons() {
        var buttons = document.querySelectorAll('.wpt-unlock-btn');
        if (!buttons.length) return;

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ip = btn.getAttribute('data-ip');
                if (!ip) return;

                if (!confirm(config.i18n.confirmUnlock + ' (' + ip + ')')) return;

                btn.disabled = true;
                btn.textContent = config.i18n.unlocking;

                var formData = new FormData();
                formData.append('action', 'wpt_unlock_ip');
                formData.append('nonce', config.unlockNonce);
                formData.append('ip', ip);

                fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            alert(data.data.message || config.i18n.networkError);
                            btn.disabled = false;
                            btn.textContent = 'Unlock';
                        }
                    })
                    .catch(function () {
                        alert(config.i18n.networkError);
                        btn.disabled = false;
                        btn.textContent = 'Unlock';
                    });
            });
        });
    }

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initClearLog();
            initUnlockButtons();
        });
    } else {
        initClearLog();
        initUnlockButtons();
    }
})();
