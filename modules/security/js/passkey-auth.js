/**
 * Passkey Authentication (WebAuthn) -- Registration and login client-side logic.
 *
 * Handles:
 *  - Browser compat check (window.PublicKeyCredential)
 *  - Register new passkey on profile page
 *  - Revoke passkey on profile page
 *  - Authenticate with passkey on login page
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptPasskeyAuth || {};

    /**
     * Convert base64url string to Uint8Array.
     */
    function base64urlToBuffer(base64url) {
        var base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        var padding = base64.length % 4;
        if (padding) {
            base64 += '='.repeat(4 - padding);
        }
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }

    /**
     * Convert ArrayBuffer to base64url string.
     */
    function bufferToBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    /**
     * Make an AJAX POST request.
     */
    function ajaxPost(action, data) {
        var formData = new FormData();
        formData.append('action', action);
        if (config.nonce) {
            formData.append('nonce', config.nonce);
        }
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (r) { return r.json(); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Check browser support.
        var supported = window.PublicKeyCredential !== undefined;

        if (config.context === 'profile') {
            initProfile(supported);
        } else if (config.context === 'login') {
            initLogin(supported);
        }
    });

    /**
     * Initialize profile page passkey management.
     */
    function initProfile(supported) {
        var registerBtn = document.getElementById('wpt-passkey-register-btn');
        var spinner     = document.getElementById('wpt-passkey-spinner');
        var statusEl    = document.getElementById('wpt-passkey-status');
        var noSupportEl = document.getElementById('wpt-passkey-no-support');

        if (!supported) {
            if (registerBtn) registerBtn.style.display = 'none';
            if (noSupportEl) noSupportEl.style.display = '';
            return;
        }

        // Register button handler.
        if (registerBtn) {
            registerBtn.addEventListener('click', function () {
                var name = prompt(config.i18n.namePrompt || 'Enter a name for this passkey:');
                if (name === null) return;

                registerBtn.disabled = true;
                if (spinner) spinner.classList.add('is-active');
                if (statusEl) statusEl.textContent = config.i18n.registering;

                ajaxPost('wpt_passkey_get_register_options', {})
                    .then(function (response) {
                        if (!response.success) {
                            throw new Error(response.data && response.data.message ? response.data.message : config.i18n.error);
                        }

                        var options = response.data;

                        // Build create options for WebAuthn API.
                        var createOptions = {
                            publicKey: {
                                rp: options.rp,
                                user: {
                                    id:          base64urlToBuffer(options.user.id),
                                    name:        options.user.name,
                                    displayName: options.user.displayName
                                },
                                challenge:     base64urlToBuffer(options.challenge),
                                pubKeyCredParams: options.pubKeyCredParams,
                                timeout:       options.timeout,
                                authenticatorSelection: options.authenticatorSelection,
                                attestation:   options.attestation,
                                excludeCredentials: (options.excludeCredentials || []).map(function (c) {
                                    return { type: c.type, id: base64urlToBuffer(c.id) };
                                })
                            }
                        };

                        return navigator.credentials.create(createOptions);
                    })
                    .then(function (credential) {
                        if (!credential) {
                            throw new Error('No credential returned.');
                        }

                        // Extract the public key from the attestation response.
                        var response = credential.response;
                        var publicKeyBytes = response.getPublicKey ? response.getPublicKey() : null;

                        // If getPublicKey() is not available, use the raw attestation object.
                        var publicKeyB64 = publicKeyBytes
                            ? bufferToBase64url(publicKeyBytes)
                            : bufferToBase64url(response.attestationObject);

                        return ajaxPost('wpt_passkey_register', {
                            credential_id: bufferToBase64url(credential.rawId),
                            public_key:    publicKeyB64,
                            client_data:   bufferToBase64url(response.clientDataJSON),
                            passkey_name:  name || '',
                            algorithm:     credential.response.getPublicKeyAlgorithm
                                ? credential.response.getPublicKeyAlgorithm()
                                : -7
                        });
                    })
                    .then(function (result) {
                        if (result.success) {
                            if (statusEl) {
                                statusEl.innerHTML = '<div class="notice notice-success inline"><p>' +
                                    escapeHtml(config.i18n.registered) + '</p></div>';
                            }
                            setTimeout(function () { window.location.reload(); }, 1500);
                        } else {
                            throw new Error(result.data && result.data.message ? result.data.message : config.i18n.error);
                        }
                    })
                    .catch(function (err) {
                        if (statusEl) {
                            statusEl.innerHTML = '<div class="notice notice-error inline"><p>' +
                                escapeHtml(err.message || config.i18n.error) + '</p></div>';
                        }
                    })
                    .finally(function () {
                        registerBtn.disabled = false;
                        if (spinner) spinner.classList.remove('is-active');
                    });
            });
        }

        // Revoke button handlers.
        var revokeButtons = document.querySelectorAll('.wpt-passkey-revoke');
        for (var i = 0; i < revokeButtons.length; i++) {
            revokeButtons[i].addEventListener('click', function () {
                if (!confirm(config.i18n.confirmRevoke)) return;

                var btn = this;
                var credId = btn.getAttribute('data-credential-id');
                btn.disabled = true;

                ajaxPost('wpt_passkey_revoke', { credential_id: credId })
                    .then(function (result) {
                        if (result.success) {
                            alert(config.i18n.revoked);
                            window.location.reload();
                        } else {
                            alert(result.data && result.data.message ? result.data.message : config.i18n.error);
                            btn.disabled = false;
                        }
                    })
                    .catch(function () {
                        alert(config.i18n.error);
                        btn.disabled = false;
                    });
            });
        }
    }

    /**
     * Initialize login page passkey authentication.
     */
    function initLogin(supported) {
        var wrapper  = document.getElementById('wpt-passkey-login-wrapper');
        var loginBtn = document.getElementById('wpt-passkey-login-btn');
        var statusEl = document.getElementById('wpt-passkey-login-status');

        if (!supported || !wrapper || !loginBtn) {
            return;
        }

        // Show the passkey login option.
        wrapper.style.display = '';

        loginBtn.addEventListener('click', function () {
            loginBtn.disabled = true;
            if (statusEl) statusEl.textContent = config.i18n.authenticating;

            var usernameInput = document.getElementById('user_login');
            var username = usernameInput ? usernameInput.value : '';

            ajaxPost('wpt_passkey_get_auth_options', { username: username })
                .then(function (response) {
                    if (!response.success) {
                        throw new Error(response.data && response.data.message ? response.data.message : config.i18n.error);
                    }

                    var options = response.data;

                    var getOptions = {
                        publicKey: {
                            challenge:        base64urlToBuffer(options.challenge),
                            rpId:             options.rpId,
                            timeout:          options.timeout,
                            userVerification: options.userVerification,
                            allowCredentials: (options.allowCredentials || []).map(function (c) {
                                return { type: c.type, id: base64urlToBuffer(c.id) };
                            })
                        }
                    };

                    return navigator.credentials.get(getOptions);
                })
                .then(function (assertion) {
                    if (!assertion) {
                        throw new Error('No assertion returned.');
                    }

                    var resp = assertion.response;

                    return ajaxPost('wpt_passkey_authenticate', {
                        credential_id:      bufferToBase64url(assertion.rawId),
                        client_data:        bufferToBase64url(resp.clientDataJSON),
                        authenticator_data: bufferToBase64url(resp.authenticatorData),
                        signature:          bufferToBase64url(resp.signature),
                        user_handle:        resp.userHandle ? bufferToBase64url(resp.userHandle) : ''
                    });
                })
                .then(function (result) {
                    if (result.success) {
                        if (statusEl) statusEl.textContent = '';
                        window.location.href = result.data.redirect_to || '/wp-admin/';
                    } else {
                        throw new Error(result.data && result.data.message ? result.data.message : config.i18n.error);
                    }
                })
                .catch(function (err) {
                    if (statusEl) statusEl.textContent = err.message || config.i18n.error;
                    loginBtn.disabled = false;
                });
        });
    }

    /**
     * Simple HTML escaping.
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
