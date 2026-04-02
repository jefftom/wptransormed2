/**
 * Redirect Manager -- Vanilla JS for AJAX CRUD + CSV import/export.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptRedirectManager || {};

    // -- Helpers ----------------------------------------------------------

    function post(action, data, callback) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', config.nonce || '');

        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', config.ajaxUrl || '', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = { success: false, data: { message: config.i18n.networkError } };
            }
            callback(response);
        };
        xhr.onerror = function () {
            callback({ success: false, data: { message: config.i18n.networkError } });
        };
        xhr.send(body);
    }

    function showNotice(container, message, type) {
        var existing = container.querySelector('.wpt-redirect-notice');
        if (existing) existing.remove();

        var notice = document.createElement('div');
        notice.className = 'notice notice-' + type + ' is-dismissible wpt-redirect-notice';
        notice.style.margin = '10px 0';

        var p = document.createElement('p');
        p.textContent = message;
        notice.appendChild(p);

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'notice-dismiss';
        dismiss.addEventListener('click', function () {
            notice.remove();
        });
        notice.appendChild(dismiss);

        container.insertBefore(notice, container.firstChild);
    }

    function setSpinner(spinner, active) {
        if (spinner) {
            spinner.classList.toggle('is-active', active);
        }
    }

    // -- Tab Switching ----------------------------------------------------

    function initTabs() {
        var buttons = document.querySelectorAll('.wpt-tab-button');
        var contents = document.querySelectorAll('.wpt-tab-content');

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = btn.getAttribute('data-tab');

                buttons.forEach(function (b) { b.classList.remove('active'); });
                contents.forEach(function (c) { c.style.display = 'none'; c.classList.remove('active'); });

                btn.classList.add('active');

                var target = document.getElementById('wpt-tab-' + tab);
                if (target) {
                    target.style.display = '';
                    target.classList.add('active');
                }
            });
        });
    }

    // -- Add Redirect -----------------------------------------------------

    function initAddRedirect() {
        var addBtn = document.getElementById('wpt-add-redirect-btn');
        if (!addBtn) return;

        addBtn.addEventListener('click', function () {
            var source = document.getElementById('wpt-add-source');
            var target = document.getElementById('wpt-add-target');
            var type   = document.getElementById('wpt-add-type');
            var spinner = document.getElementById('wpt-add-spinner');
            var container = document.getElementById('wpt-tab-redirects');

            if (!source.value || source.value.charAt(0) !== '/') {
                showNotice(container, config.i18n.sourceRequired, 'error');
                source.focus();
                return;
            }

            if (!target.value) {
                showNotice(container, config.i18n.targetRequired, 'error');
                target.focus();
                return;
            }

            addBtn.disabled = true;
            setSpinner(spinner, true);

            post('wpt_add_redirect', {
                source_url: source.value,
                target_url: target.value,
                redirect_type: type.value
            }, function (response) {
                addBtn.disabled = false;
                setSpinner(spinner, false);

                if (response.success) {
                    showNotice(container, response.data.message, 'success');

                    // Add row to table.
                    addRedirectRow(response.data.redirect);

                    // Clear form.
                    source.value = '';
                    target.value = '';
                    type.value = '301';
                } else {
                    showNotice(container, response.data.message, 'error');
                }
            });
        });
    }

    function addRedirectRow(redirect) {
        var tbody = document.getElementById('wpt-redirects-tbody');
        if (!tbody) return;

        // Remove "no redirects" row.
        var noRow = tbody.querySelector('.wpt-no-redirects');
        if (noRow) noRow.remove();

        var tr = document.createElement('tr');
        tr.setAttribute('data-id', redirect.id);

        tr.innerHTML =
            '<td class="wpt-col-source"><code>' + escapeHtml(redirect.source_url) + '</code></td>' +
            '<td class="wpt-col-target"><span class="wpt-target-url">' + escapeHtml(redirect.target_url) + '</span></td>' +
            '<td class="wpt-col-type"><span class="wpt-redirect-type-badge">' + escapeHtml(String(redirect.redirect_type)) + '</span></td>' +
            '<td class="wpt-col-hits">0</td>' +
            '<td class="wpt-col-status">' +
                '<button type="button" class="button button-small wpt-toggle-redirect wpt-active" data-id="' + redirect.id + '">' +
                    escapeHtml(config.i18n.active) +
                '</button>' +
            '</td>' +
            '<td class="wpt-col-actions">' +
                '<button type="button" class="button button-small wpt-edit-redirect"' +
                    ' data-id="' + redirect.id + '"' +
                    ' data-source="' + escapeAttr(redirect.source_url) + '"' +
                    ' data-target="' + escapeAttr(redirect.target_url) + '"' +
                    ' data-type="' + redirect.redirect_type + '">Edit</button> ' +
                '<button type="button" class="button button-small button-link-delete wpt-delete-redirect"' +
                    ' data-id="' + redirect.id + '">Delete</button>' +
            '</td>';

        tbody.insertBefore(tr, tbody.firstChild);

        // Update count.
        updateRedirectCount(1);
    }

    // -- Edit Redirect ----------------------------------------------------

    function initEditRedirect() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.wpt-edit-redirect');
            if (!btn) return;

            var id     = btn.getAttribute('data-id');
            var source = btn.getAttribute('data-source');
            var target = btn.getAttribute('data-target');
            var type   = btn.getAttribute('data-type');

            var newSource = prompt(config.i18n.editPromptSource, source);
            if (newSource === null) return;

            var newTarget = prompt(config.i18n.editPromptTarget, target);
            if (newTarget === null) return;

            var container = document.getElementById('wpt-tab-redirects');

            if (!newSource || newSource.charAt(0) !== '/') {
                showNotice(container, config.i18n.sourceRequired, 'error');
                return;
            }

            if (!newTarget) {
                showNotice(container, config.i18n.targetRequired, 'error');
                return;
            }

            btn.disabled = true;

            post('wpt_edit_redirect', {
                redirect_id: id,
                source_url: newSource,
                target_url: newTarget,
                redirect_type: type
            }, function (response) {
                btn.disabled = false;

                if (response.success) {
                    showNotice(container, response.data.message, 'success');

                    // Update row data.
                    var row = document.querySelector('tr[data-id="' + id + '"]');
                    if (row) {
                        var sourceCell = row.querySelector('.wpt-col-source code');
                        if (sourceCell) sourceCell.textContent = newSource;

                        var targetCell = row.querySelector('.wpt-target-url');
                        if (targetCell) targetCell.textContent = newTarget;

                        btn.setAttribute('data-source', newSource);
                        btn.setAttribute('data-target', newTarget);
                    }
                } else {
                    showNotice(container, response.data.message, 'error');
                }
            });
        });
    }

    // -- Delete Redirect --------------------------------------------------

    function initDeleteRedirect() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.wpt-delete-redirect');
            if (!btn) return;

            if (!confirm(config.i18n.confirmDelete)) return;

            var id = btn.getAttribute('data-id');
            var container = document.getElementById('wpt-tab-redirects');

            btn.disabled = true;

            post('wpt_delete_redirect', { redirect_id: id }, function (response) {
                btn.disabled = false;

                if (response.success) {
                    showNotice(container, response.data.message, 'success');

                    var row = document.querySelector('tr[data-id="' + id + '"]');
                    if (row) row.remove();

                    updateRedirectCount(-1);
                } else {
                    showNotice(container, response.data.message, 'error');
                }
            });
        });
    }

    // -- Toggle Redirect --------------------------------------------------

    function initToggleRedirect() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.wpt-toggle-redirect');
            if (!btn) return;

            var id = btn.getAttribute('data-id');

            btn.disabled = true;

            post('wpt_toggle_redirect', { redirect_id: id }, function (response) {
                btn.disabled = false;

                if (response.success) {
                    var isActive = response.data.is_active;

                    btn.textContent = isActive ? config.i18n.active : config.i18n.inactive;
                    btn.classList.toggle('wpt-active', !!isActive);
                    btn.classList.toggle('wpt-inactive', !isActive);
                }
            });
        });
    }

    // -- Clear 404 Log ----------------------------------------------------

    function initClear404Log() {
        var clearBtn = document.getElementById('wpt-clear-404-log');
        if (!clearBtn) return;

        clearBtn.addEventListener('click', function () {
            if (!confirm(config.i18n.confirmClear404)) return;

            var container = document.getElementById('wpt-tab-404-log');

            clearBtn.disabled = true;

            post('wpt_clear_404_log', {}, function (response) {
                clearBtn.disabled = false;

                if (response.success) {
                    showNotice(container, response.data.message, 'success');

                    var tbody = document.querySelector('#wpt-404-table tbody');
                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="5">No 404 errors logged yet.</td></tr>';
                    }

                    // Update count.
                    var countBadge = document.querySelector('.wpt-tab-button[data-tab="404-log"] .wpt-tab-count');
                    if (countBadge) countBadge.textContent = '0';

                    clearBtn.style.display = 'none';
                } else {
                    showNotice(container, response.data.message, 'error');
                }
            });
        });
    }

    // -- Export Redirects --------------------------------------------------

    function initExport() {
        var exportBtn = document.getElementById('wpt-export-redirects');
        if (!exportBtn) return;

        exportBtn.addEventListener('click', function () {
            var container = document.getElementById('wpt-tab-import-export');

            exportBtn.disabled = true;

            post('wpt_export_redirects', {}, function (response) {
                exportBtn.disabled = false;

                if (response.success) {
                    // Create and download CSV file.
                    var blob = new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = 'wpt-redirects-' + new Date().toISOString().slice(0, 10) + '.csv';
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(link.href);
                } else {
                    showNotice(container, response.data.message, 'error');
                }
            });
        });
    }

    // -- Import Redirects --------------------------------------------------

    function initImport() {
        var importBtn = document.getElementById('wpt-import-redirects');
        if (!importBtn) return;

        importBtn.addEventListener('click', function () {
            var textarea = document.getElementById('wpt-import-csv');
            var spinner  = document.getElementById('wpt-import-spinner');
            var container = document.getElementById('wpt-tab-import-export');

            if (!textarea.value.trim()) {
                showNotice(container, config.i18n.emptyCsv, 'error');
                textarea.focus();
                return;
            }

            if (!confirm(config.i18n.confirmImport)) return;

            importBtn.disabled = true;
            setSpinner(spinner, true);

            post('wpt_import_redirects', { csv_data: textarea.value }, function (response) {
                importBtn.disabled = false;
                setSpinner(spinner, false);

                if (response.success) {
                    showNotice(container, response.data.message, 'success');
                    textarea.value = '';

                    // Reload page to show imported redirects.
                    if (response.data.imported > 0) {
                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    }
                } else {
                    showNotice(container, response.data.message, 'error');
                }
            });
        });
    }

    // -- Create Redirect from 404 -----------------------------------------

    function initCreateFromLog() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.wpt-create-redirect-from-404');
            if (!btn) return;

            var url = btn.getAttribute('data-url');

            // Switch to redirects tab and populate source.
            var redirectsTabBtn = document.querySelector('.wpt-tab-button[data-tab="redirects"]');
            if (redirectsTabBtn) redirectsTabBtn.click();

            var sourceInput = document.getElementById('wpt-add-source');
            if (sourceInput) {
                sourceInput.value = url;
                sourceInput.focus();
            }

            var targetInput = document.getElementById('wpt-add-target');
            if (targetInput) targetInput.focus();
        });
    }

    // -- Helpers -----------------------------------------------------------

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function updateRedirectCount(delta) {
        var badge = document.querySelector('.wpt-tab-button[data-tab="redirects"] .wpt-tab-count');
        if (badge) {
            var current = parseInt(badge.textContent, 10) || 0;
            badge.textContent = String(Math.max(0, current + delta));
        }
    }

    // -- Init -------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        if (!config.ajaxUrl) return;

        initTabs();
        initAddRedirect();
        initEditRedirect();
        initDeleteRedirect();
        initToggleRedirect();
        initClear404Log();
        initExport();
        initImport();
        initCreateFromLog();
    });
})();
