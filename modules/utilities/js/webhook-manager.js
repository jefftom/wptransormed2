/**
 * Webhook Manager -- AJAX CRUD and test-fire interactions.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptWebhooks;
    if (!config) return;

    document.addEventListener('DOMContentLoaded', function () {
        var saveBtn   = document.getElementById('wpt-webhook-save');
        var cancelBtn = document.getElementById('wpt-webhook-cancel');
        var spinner   = document.getElementById('wpt-webhook-spinner');
        var listDiv   = document.getElementById('wpt-webhook-list');
        var editIdEl  = document.getElementById('wpt-webhook-edit-id');

        // Load webhooks on page load.
        loadWebhooks();

        // Save handler.
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var name    = document.getElementById('wpt-webhook-name').value.trim();
                var url     = document.getElementById('wpt-webhook-url').value.trim();
                var event   = document.getElementById('wpt-webhook-event').value;
                var headers = document.getElementById('wpt-webhook-headers').value.trim();
                var id      = editIdEl ? parseInt(editIdEl.value, 10) : 0;

                if (!name || !url) {
                    alert('Name and URL are required.');
                    return;
                }

                // Validate headers JSON if provided.
                if (headers) {
                    try {
                        JSON.parse(headers);
                    } catch (e) {
                        alert('Headers must be valid JSON.');
                        return;
                    }
                }

                saveBtn.disabled = true;
                if (spinner) spinner.classList.add('is-active');

                var body = new FormData();
                body.append('action', 'wpt_webhook_save');
                body.append('nonce', config.nonce);
                body.append('id', id);
                body.append('name', name);
                body.append('url', url);
                body.append('event', event);
                body.append('headers', headers);

                fetch(config.ajaxUrl, { method: 'POST', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            clearForm();
                            loadWebhooks();
                        } else {
                            alert((data.data && data.data.message) || 'Error saving webhook.');
                        }
                    })
                    .catch(function () { alert(config.i18n.networkError); })
                    .finally(function () {
                        saveBtn.disabled = false;
                        if (spinner) spinner.classList.remove('is-active');
                    });
            });
        }

        // Cancel edit handler.
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                clearForm();
            });
        }

        function clearForm() {
            document.getElementById('wpt-webhook-name').value    = '';
            document.getElementById('wpt-webhook-url').value     = '';
            document.getElementById('wpt-webhook-event').selectedIndex = 0;
            document.getElementById('wpt-webhook-headers').value = '';
            if (editIdEl) editIdEl.value = '0';
            if (cancelBtn) cancelBtn.style.display = 'none';
        }

        function loadWebhooks() {
            var body = new FormData();
            body.append('action', 'wpt_webhook_list');
            body.append('nonce', config.nonce);

            fetch(config.ajaxUrl, { method: 'POST', body: body })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        renderWebhooks(data.data.webhooks);
                    }
                })
                .catch(function () {
                    if (listDiv) listDiv.innerHTML = '<p>' + config.i18n.networkError + '</p>';
                });
        }

        function renderWebhooks(webhooks) {
            if (!listDiv) return;

            if (!webhooks || webhooks.length === 0) {
                listDiv.innerHTML = '<p class="description">' + config.i18n.noWebhooks + '</p>';
                return;
            }

            var html = '<table class="widefat striped wpt-webhook-table">';
            html += '<thead><tr>';
            html += '<th>Name</th><th>URL</th><th>Event</th><th>Status</th><th>Last Triggered</th><th>Actions</th>';
            html += '</tr></thead><tbody>';

            webhooks.forEach(function (wh) {
                var isActive    = parseInt(wh.is_active, 10) === 1;
                var statusClass = isActive ? 'wpt-webhook-status-active' : 'wpt-webhook-status-inactive';
                var statusText  = isActive ? config.i18n.active : config.i18n.inactive;
                var eventLabel  = config.events[wh.event] || wh.event;
                var lastStatus  = wh.last_status ? ' (HTTP ' + wh.last_status + ')' : '';

                html += '<tr>';
                html += '<td><strong>' + escHtml(wh.name) + '</strong></td>';
                html += '<td><code style="font-size: 11px; word-break: break-all;">' + escHtml(wh.url) + '</code></td>';
                html += '<td>' + escHtml(eventLabel) + '</td>';
                html += '<td class="' + statusClass + '">' + statusText + '</td>';
                html += '<td>' + (wh.last_triggered ? escHtml(wh.last_triggered) + escHtml(lastStatus) : '—') + '</td>';
                html += '<td class="wpt-webhook-actions">';
                html += '<button type="button" class="button button-small wpt-wh-edit" data-id="' + wh.id + '" data-name="' + escAttr(wh.name) + '" data-url="' + escAttr(wh.url) + '" data-event="' + escAttr(wh.event) + '" data-headers="' + escAttr(wh.headers || '') + '">Edit</button> ';
                html += '<button type="button" class="button button-small wpt-wh-toggle" data-id="' + wh.id + '">' + (isActive ? 'Deactivate' : 'Activate') + '</button> ';
                html += '<button type="button" class="button button-small wpt-wh-test" data-id="' + wh.id + '">Test</button> ';
                html += '<button type="button" class="button button-small button-link-delete wpt-wh-delete" data-id="' + wh.id + '">Delete</button>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            listDiv.innerHTML = html;

            // Bind action buttons.
            bindActions();
        }

        function bindActions() {
            // Edit buttons.
            document.querySelectorAll('.wpt-wh-edit').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (editIdEl) editIdEl.value = this.dataset.id;
                    document.getElementById('wpt-webhook-name').value    = this.dataset.name;
                    document.getElementById('wpt-webhook-url').value     = this.dataset.url;
                    document.getElementById('wpt-webhook-event').value   = this.dataset.event;
                    document.getElementById('wpt-webhook-headers').value = this.dataset.headers;
                    if (cancelBtn) cancelBtn.style.display = '';
                    document.getElementById('wpt-webhook-form').scrollIntoView({ behavior: 'smooth' });
                });
            });

            // Toggle buttons.
            document.querySelectorAll('.wpt-wh-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = this.dataset.id;
                    btn.disabled = true;

                    var body = new FormData();
                    body.append('action', 'wpt_webhook_toggle');
                    body.append('nonce', config.nonce);
                    body.append('id', id);

                    fetch(config.ajaxUrl, { method: 'POST', body: body })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data.success) loadWebhooks();
                        })
                        .catch(function () { alert(config.i18n.networkError); })
                        .finally(function () { btn.disabled = false; });
                });
            });

            // Test buttons.
            document.querySelectorAll('.wpt-wh-test').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm(config.i18n.confirmTest)) return;

                    var id = this.dataset.id;
                    btn.disabled = true;
                    btn.textContent = config.i18n.testing;

                    var body = new FormData();
                    body.append('action', 'wpt_webhook_test');
                    body.append('nonce', config.nonce);
                    body.append('id', id);

                    fetch(config.ajaxUrl, { method: 'POST', body: body })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            var msg = (data.data && data.data.message) || 'Done';
                            alert(msg);
                            loadWebhooks();
                        })
                        .catch(function () { alert(config.i18n.networkError); })
                        .finally(function () {
                            btn.disabled = false;
                            btn.textContent = 'Test';
                        });
                });
            });

            // Delete buttons.
            document.querySelectorAll('.wpt-wh-delete').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm(config.i18n.confirmDelete)) return;

                    var id = this.dataset.id;
                    btn.disabled = true;

                    var body = new FormData();
                    body.append('action', 'wpt_webhook_delete');
                    body.append('nonce', config.nonce);
                    body.append('id', id);

                    fetch(config.ajaxUrl, { method: 'POST', body: body })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data.success) loadWebhooks();
                        })
                        .catch(function () { alert(config.i18n.networkError); })
                        .finally(function () { btn.disabled = false; });
                });
            });
        }

        function escHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function escAttr(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    });
})();
