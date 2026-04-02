/**
 * Search & Replace -- Vanilla JS for AJAX-powered search/replace with dry run, batch processing, and undo.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptSearchReplace || {};

    // ── Helpers ──────────────────────────────────────────────

    function post(action, data, callback) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', config.nonce || '');

        Object.keys(data).forEach(function (key) {
            if (Array.isArray(data[key])) {
                data[key].forEach(function (val) {
                    body.append(key + '[]', val);
                });
            } else {
                body.append(key, data[key]);
            }
        });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', config.ajaxUrl || '', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = { success: false, data: { message: i18n('networkError') } };
            }
            callback(response);
        };
        xhr.onerror = function () {
            callback({ success: false, data: { message: i18n('networkError') } });
        };
        xhr.send(body);
    }

    function i18n(key) {
        return (config.i18n && config.i18n[key]) || key;
    }

    function sprintf(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        var idx = 0;
        return str.replace(/%[sd]/g, function () {
            return args[idx++] !== undefined ? args[idx - 1] : '';
        });
    }

    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function showNotice(container, message, type) {
        var existing = container.querySelector('.wpt-sr-notice');
        if (existing) existing.remove();

        var notice = document.createElement('div');
        notice.className = 'notice notice-' + type + ' is-dismissible wpt-sr-notice';
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

    function setSpinner(active) {
        var spinner = document.getElementById('wpt-sr-spinner');
        if (spinner) {
            spinner.classList.toggle('is-active', active);
        }
    }

    function setButtonsDisabled(disabled) {
        var dryRun = document.getElementById('wpt-sr-dry-run');
        var execute = document.getElementById('wpt-sr-execute');
        if (dryRun) dryRun.disabled = disabled;
        if (execute) execute.disabled = disabled;
    }

    // ── Table Loading ────────────────────────────────────────

    function loadTables() {
        var container = document.getElementById('wpt-sr-tables-list');
        if (!container) return;

        post('wpt_search_replace_tables', {}, function (response) {
            if (!response.success || !response.data || !response.data.tables) {
                container.innerHTML = '<p class="description" style="color: #d63638;">' +
                    i18n('networkError') + '</p>';
                return;
            }

            var tables = response.data.tables;
            if (tables.length === 0) {
                container.innerHTML = '<p class="description">No tables found.</p>';
                return;
            }

            var html = '<div class="wpt-sr-table-grid">';
            tables.forEach(function (table) {
                html += '<label class="wpt-sr-table-label">' +
                    '<input type="checkbox" class="wpt-sr-table-cb" value="' + escAttr(table.name) + '" checked> ' +
                    '<span class="wpt-sr-table-name">' + escHtml(table.name) + '</span>' +
                    ' <span class="wpt-sr-table-rows">(' + escHtml(String(table.rows)) + ' rows)</span>' +
                    '</label>';
            });
            html += '</div>';

            container.innerHTML = html;
        });
    }

    function getSelectedTables() {
        var checkboxes = document.querySelectorAll('.wpt-sr-table-cb:checked');
        var tables = [];
        checkboxes.forEach(function (cb) {
            tables.push(cb.value);
        });
        return tables;
    }

    // ── Escaping ─────────────────────────────────────────────

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                  .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ── Dry Run ──────────────────────────────────────────────

    function doDryRun() {
        var search = (document.getElementById('wpt-sr-search') || {}).value || '';
        var tables = getSelectedTables();
        var caseSensitive = document.getElementById('wpt-sr-case-sensitive');
        var regex = document.getElementById('wpt-sr-regex');

        if (!search) {
            showNotice(document.getElementById('wpt-sr-tool'), i18n('searchRequired'), 'error');
            return;
        }

        if (tables.length === 0) {
            showNotice(document.getElementById('wpt-sr-tool'), i18n('noTablesSelected'), 'error');
            return;
        }

        setButtonsDisabled(true);
        setSpinner(true);
        hideResults();
        hideProgress();

        post('wpt_search_replace_dry_run', {
            search: search,
            tables: tables,
            case_sensitive: caseSensitive && caseSensitive.checked ? '1' : '0',
            regex: regex && regex.checked ? '1' : '0'
        }, function (response) {
            setSpinner(false);
            setButtonsDisabled(false);

            var resultsDiv = document.getElementById('wpt-sr-results');
            if (!resultsDiv) return;

            if (!response.success) {
                showNotice(document.getElementById('wpt-sr-tool'),
                    (response.data && response.data.message) || i18n('networkError'), 'error');
                return;
            }

            var data = response.data;

            if (!data.results || data.results.length === 0 || data.total === 0) {
                resultsDiv.innerHTML = '<div class="notice notice-info inline"><p>' +
                    escHtml(i18n('noMatches')) + '</p></div>';
                resultsDiv.style.display = '';
                return;
            }

            // Build results table.
            var html = '<div class="notice notice-success inline"><p>' +
                escHtml(sprintf(i18n('matchesFound'), data.total, data.results.length)) +
                '</p></div>';

            html += '<table class="widefat striped wpt-sr-results-table">';
            html += '<thead><tr><th>Table</th><th>Rows</th><th>Matches</th></tr></thead><tbody>';

            data.results.forEach(function (r) {
                html += '<tr><td>' + escHtml(r.table) + '</td>' +
                    '<td>' + escHtml(String(r.rows)) + '</td>' +
                    '<td>' + escHtml(String(r.matches)) + '</td></tr>';
            });

            html += '</tbody></table>';

            resultsDiv.innerHTML = html;
            resultsDiv.style.display = '';

            // Enable execute button.
            var execute = document.getElementById('wpt-sr-execute');
            if (execute) execute.disabled = false;
        });
    }

    // ── Execute ──────────────────────────────────────────────

    function doExecute() {
        var search = (document.getElementById('wpt-sr-search') || {}).value || '';
        var replace = (document.getElementById('wpt-sr-replace') || {}).value || '';
        var tables = getSelectedTables();
        var caseSensitive = document.getElementById('wpt-sr-case-sensitive');
        var regex = document.getElementById('wpt-sr-regex');

        if (!search) {
            showNotice(document.getElementById('wpt-sr-tool'), i18n('searchRequired'), 'error');
            return;
        }

        if (tables.length === 0) {
            showNotice(document.getElementById('wpt-sr-tool'), i18n('noTablesSelected'), 'error');
            return;
        }

        // Backup confirmation.
        if (config.backupReminder) {
            if (!confirm(i18n('confirmBackup'))) {
                return;
            }
        }

        if (!confirm(i18n('confirmRun'))) {
            return;
        }

        var runId = generateUUID();
        var tableIndex = 0;
        var totalReplaced = 0;

        setButtonsDisabled(true);
        setSpinner(true);
        hideResults();
        showProgress();

        function processNextTable() {
            if (tableIndex >= tables.length) {
                // All done.
                setSpinner(false);
                setButtonsDisabled(false);
                updateProgress(100, sprintf(i18n('complete') + ' ' + i18n('replaced'), totalReplaced));
                showNotice(document.getElementById('wpt-sr-tool'),
                    sprintf(i18n('replaced'), totalReplaced), 'success');
                return;
            }

            var currentTable = tables[tableIndex];
            var pct = Math.round((tableIndex / tables.length) * 100);
            updateProgress(pct, sprintf(i18n('processingTable'), currentTable));

            processBatch(currentTable, 0);
        }

        function processBatch(table, lastId) {
            post('wpt_search_replace_run', {
                search: search,
                replace: replace,
                table: table,
                run_id: runId,
                last_id: String(lastId),
                case_sensitive: caseSensitive && caseSensitive.checked ? '1' : '0',
                regex: regex && regex.checked ? '1' : '0'
            }, function (response) {
                if (!response.success) {
                    setSpinner(false);
                    setButtonsDisabled(false);
                    showNotice(document.getElementById('wpt-sr-tool'),
                        (response.data && response.data.message) || i18n('networkError'), 'error');
                    return;
                }

                var data = response.data;
                totalReplaced += data.replaced || 0;

                if (data.done) {
                    tableIndex++;
                    processNextTable();
                } else {
                    // More rows in this table.
                    processBatch(table, data.last_id || 0);
                }
            });
        }

        processNextTable();
    }

    // ── Undo ─────────────────────────────────────────────────

    function doUndo(runId) {
        if (!confirm(i18n('confirmUndo'))) {
            return;
        }

        setSpinner(true);

        post('wpt_search_replace_undo', {
            run_id: runId
        }, function (response) {
            setSpinner(false);

            if (!response.success) {
                showNotice(document.getElementById('wpt-sr-tool'),
                    (response.data && response.data.message) || i18n('networkError'), 'error');
                return;
            }

            showNotice(document.getElementById('wpt-sr-tool'),
                (response.data && response.data.message) || i18n('undoComplete'), 'success');

            // Remove the row from the undo table.
            var row = document.querySelector('tr[data-run-id="' + runId + '"]');
            if (row) row.remove();

            // Hide table if empty.
            var tbody = document.querySelector('#wpt-sr-undo-table tbody');
            if (tbody && tbody.children.length === 0) {
                var undoTable = document.getElementById('wpt-sr-undo-table');
                if (undoTable) undoTable.style.display = 'none';
            }
        });
    }

    // ── Progress Bar ─────────────────────────────────────────

    function showProgress() {
        var el = document.getElementById('wpt-sr-progress');
        if (el) el.style.display = '';
        updateProgress(0, i18n('processing'));
    }

    function hideProgress() {
        var el = document.getElementById('wpt-sr-progress');
        if (el) el.style.display = 'none';
    }

    function updateProgress(pct, text) {
        var fill = document.getElementById('wpt-sr-progress-fill');
        var label = document.getElementById('wpt-sr-progress-text');
        if (fill) fill.style.width = pct + '%';
        if (label) label.textContent = text;
    }

    function hideResults() {
        var el = document.getElementById('wpt-sr-results');
        if (el) {
            el.style.display = 'none';
            el.innerHTML = '';
        }
    }

    // ── Init ─────────────────────────────────────────────────

    function init() {
        // Load table list.
        loadTables();

        // Dry run button.
        var dryRunBtn = document.getElementById('wpt-sr-dry-run');
        if (dryRunBtn) {
            dryRunBtn.addEventListener('click', doDryRun);
        }

        // Execute button.
        var executeBtn = document.getElementById('wpt-sr-execute');
        if (executeBtn) {
            executeBtn.addEventListener('click', doExecute);
        }

        // Select all / deselect all.
        var selectAll = document.getElementById('wpt-sr-select-all');
        if (selectAll) {
            selectAll.addEventListener('click', function () {
                document.querySelectorAll('.wpt-sr-table-cb').forEach(function (cb) {
                    cb.checked = true;
                });
            });
        }

        var deselectAll = document.getElementById('wpt-sr-deselect-all');
        if (deselectAll) {
            deselectAll.addEventListener('click', function () {
                document.querySelectorAll('.wpt-sr-table-cb').forEach(function (cb) {
                    cb.checked = false;
                });
            });
        }

        // Undo buttons (event delegation).
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.wpt-sr-undo-btn');
            if (!btn) return;

            var runId = btn.getAttribute('data-run-id');
            if (runId) doUndo(runId);
        });
    }

    // DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
