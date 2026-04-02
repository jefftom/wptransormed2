/**
 * WPTransformed -- Email SMTP Settings
 *
 * Auth field toggling, password change behavior, test email AJAX.
 * No jQuery dependency.
 */
(function () {
    'use strict';

    var config = window.wptEmailSmtp || {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce = config.nonce || '';
    var i18n = config.i18n || {};

    // ── Authentication Fields Toggle ──────────────────────────

    var authCheckbox = document.getElementById('wpt-authentication');
    var authFields = document.querySelectorAll('.wpt-auth-fields');

    function toggleAuthFields() {
        if (!authCheckbox) return;
        var show = authCheckbox.checked;
        authFields.forEach(function (row) {
            row.style.display = show ? '' : 'none';
        });
    }

    if (authCheckbox) {
        authCheckbox.addEventListener('change', toggleAuthFields);
    }

    // ── Password Change Behavior ──────────────────────────────

    var passwordDisplay = document.getElementById('wpt-password-display');
    var passwordInput = document.getElementById('wpt-password-input');
    var changeBtn = document.getElementById('wpt-change-password');
    var cancelBtn = document.getElementById('wpt-cancel-password');
    var passwordField = document.getElementById('wpt-password');

    if (changeBtn) {
        changeBtn.addEventListener('click', function () {
            if (passwordDisplay) passwordDisplay.style.display = 'none';
            if (passwordInput) passwordInput.style.display = '';
            if (passwordField) passwordField.focus();
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            if (passwordInput) passwordInput.style.display = 'none';
            if (passwordDisplay) passwordDisplay.style.display = '';
            if (passwordField) passwordField.value = '';
        });
    }

    // ── Provider Table Toggle ─────────────────────────────────

    var toggleProviders = document.getElementById('wpt-toggle-providers');
    var providerTable = document.getElementById('wpt-provider-table');

    if (toggleProviders && providerTable) {
        toggleProviders.addEventListener('click', function () {
            var isVisible = providerTable.style.display !== 'none';
            providerTable.style.display = isVisible ? 'none' : '';

            var icon = toggleProviders.querySelector('.dashicons');
            if (icon) {
                icon.className = isVisible
                    ? 'dashicons dashicons-arrow-down-alt2'
                    : 'dashicons dashicons-arrow-up-alt2';
                icon.style.verticalAlign = 'middle';
            }
        });
    }

    // ── Send Test Email ───────────────────────────────────────

    var sendBtn = document.getElementById('wpt-send-test');
    var recipientInput = document.getElementById('wpt-test-recipient');
    var spinner = document.getElementById('wpt-test-spinner');
    var resultDiv = document.getElementById('wpt-test-result');

    /**
     * Simple email validation.
     *
     * @param {string} email
     * @return {boolean}
     */
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    /**
     * Show test result message.
     *
     * @param {string} message
     * @param {string} type 'success' or 'error'
     */
    function showResult(message, type) {
        if (!resultDiv) return;

        resultDiv.style.display = '';
        resultDiv.className = type === 'success'
            ? 'notice notice-success inline'
            : 'notice notice-error inline';
        resultDiv.innerHTML = '<p>' + escapeHtml(message) + '</p>';
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} str
     * @return {string}
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            var recipient = recipientInput ? recipientInput.value.trim() : '';

            if (!recipient) {
                showResult(i18n.emptyEmail || 'Please enter a recipient email address.', 'error');
                return;
            }

            if (!isValidEmail(recipient)) {
                showResult(i18n.invalidEmail || 'Please enter a valid email address.', 'error');
                return;
            }

            // Set loading state.
            sendBtn.disabled = true;
            sendBtn.textContent = i18n.sending || 'Sending...';
            if (spinner) spinner.classList.add('is-active');
            if (resultDiv) resultDiv.style.display = 'none';

            var data = new FormData();
            data.append('action', 'wpt_send_test_email');
            data.append('nonce', nonce);
            data.append('recipient', recipient);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                if (result.success) {
                    showResult(result.data.message, 'success');
                } else {
                    showResult(result.data.message || 'An error occurred.', 'error');
                }
            })
            .catch(function () {
                showResult(i18n.networkError || 'Network error. Please try again.', 'error');
            })
            .finally(function () {
                sendBtn.disabled = false;
                sendBtn.textContent = i18n.send || 'Send Test Email';
                if (spinner) spinner.classList.remove('is-active');
            });
        });
    }
})();
