/**
 * Workflow Automation -- Rule builder UI with AJAX CRUD.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptWorkflowAutomation || {};

    /**
     * Make an AJAX POST request.
     */
    function ajaxPost(action, data) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', config.nonce);
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

    /**
     * Get template HTML from a script type="text/template" element.
     */
    function getTemplate(id) {
        var el = document.getElementById(id);
        return el ? el.innerHTML : '';
    }

    /**
     * Render action-specific fields based on type.
     */
    function renderActionFields(row, type, data) {
        var container = row.querySelector('.wpt-action-fields');
        if (!container) return;

        data = data || {};
        var html = '';

        switch (type) {
            case 'send_email':
                html = '<input type="text" class="wpt-action-to regular-text" placeholder="' + escapeAttr(config.i18n.emailTo) + '" value="' + escapeAttr(data.to || '') + '" style="width:100%;margin-bottom:5px;">'
                     + '<input type="text" class="wpt-action-subject regular-text" placeholder="' + escapeAttr(config.i18n.emailSubject) + '" value="' + escapeAttr(data.subject || '') + '" style="width:100%;margin-bottom:5px;">'
                     + '<textarea class="wpt-action-body" placeholder="' + escapeAttr(config.i18n.emailBody) + '" rows="3" style="width:100%;">' + escapeHtml(data.body || '') + '</textarea>';
                break;
            case 'send_webhook':
                html = '<input type="url" class="wpt-action-url regular-text" placeholder="' + escapeAttr(config.i18n.webhookUrl) + '" value="' + escapeAttr(data.url || '') + '" style="width:100%;margin-bottom:5px;">'
                     + '<textarea class="wpt-action-payload" placeholder="' + escapeAttr(config.i18n.webhookPayload) + '" rows="3" style="width:100%;">' + escapeHtml(data.payload || '') + '</textarea>';
                break;
            case 'set_meta':
                html = '<input type="text" class="wpt-action-meta-key" placeholder="' + escapeAttr(config.i18n.metaKey) + '" value="' + escapeAttr(data.meta_key || '') + '" style="width:48%;margin-right:4%;margin-bottom:5px;">'
                     + '<input type="text" class="wpt-action-meta-value" placeholder="' + escapeAttr(config.i18n.metaValue) + '" value="' + escapeAttr(data.meta_value || '') + '" style="width:48%;">';
                break;
            case 'clear_caches':
                html = '<p class="description" style="margin:0;">Clears WordPress object cache and known caching plugin caches.</p>';
                break;
        }

        container.innerHTML = html;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/"/g, '&quot;');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var editor          = document.getElementById('wpt-automation-editor');
        var editorTitle     = document.getElementById('wpt-automation-editor-title');
        var ruleIdInput     = document.getElementById('wpt-automation-rule-id');
        var nameInput       = document.getElementById('wpt-automation-name');
        var triggerSelect   = document.getElementById('wpt-automation-trigger');
        var conditionsDiv   = document.getElementById('wpt-automation-conditions');
        var actionsDiv      = document.getElementById('wpt-automation-actions');
        var addConditionBtn = document.getElementById('wpt-automation-add-condition');
        var addActionBtn    = document.getElementById('wpt-automation-add-action');
        var saveBtn         = document.getElementById('wpt-automation-save-rule');
        var cancelBtn       = document.getElementById('wpt-automation-cancel');
        var spinner         = document.getElementById('wpt-automation-spinner');
        var addRuleBtn      = document.getElementById('wpt-automation-add-rule');
        var viewLogBtn      = document.getElementById('wpt-automation-view-log');
        var logView         = document.getElementById('wpt-automation-log-view');
        var closeLogBtn     = document.getElementById('wpt-automation-close-log');
        var logEntries      = document.getElementById('wpt-automation-log-entries');

        if (!editor) return;

        // Show editor for new rule.
        if (addRuleBtn) {
            addRuleBtn.addEventListener('click', function () {
                resetEditor();
                editorTitle.textContent = config.i18n.newRule;
                editor.style.display = '';
                if (logView) logView.style.display = 'none';
            });
        }

        // Cancel.
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                editor.style.display = 'none';
            });
        }

        // Add condition row.
        if (addConditionBtn) {
            addConditionBtn.addEventListener('click', function () {
                addConditionRow();
            });
        }

        // Add action row.
        if (addActionBtn) {
            addActionBtn.addEventListener('click', function () {
                var actionRows = actionsDiv.querySelectorAll('.wpt-automation-action-row');
                if (actionRows.length >= config.maxActions) {
                    alert('Maximum ' + config.maxActions + ' actions per rule.');
                    return;
                }
                addActionRow();
            });
        }

        // Remove row (delegate).
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('wpt-remove-row')) {
                var row = e.target.closest('.wpt-automation-condition-row, .wpt-automation-action-row');
                if (row) row.remove();
            }
        });

        // Action type change (delegate).
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('wpt-action-type')) {
                var row = e.target.closest('.wpt-automation-action-row');
                if (row) renderActionFields(row, e.target.value);
            }
        });

        // Save rule.
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var name = nameInput.value.trim();
                if (!name) {
                    alert(config.i18n.nameRequired);
                    return;
                }

                var actions = collectActions();
                if (actions.length === 0) {
                    alert(config.i18n.noActions);
                    return;
                }

                saveBtn.disabled = true;
                if (spinner) spinner.classList.add('is-active');

                ajaxPost('wpt_automation_save_rule', {
                    rule_id:      ruleIdInput.value,
                    name:         name,
                    trigger_hook: triggerSelect.value,
                    conditions:   JSON.stringify(collectConditions()),
                    actions:      JSON.stringify(actions)
                })
                .then(function (result) {
                    if (result.success) {
                        alert(config.i18n.saved);
                        window.location.reload();
                    } else {
                        alert(result.data && result.data.message ? result.data.message : config.i18n.networkError);
                    }
                })
                .catch(function () {
                    alert(config.i18n.networkError);
                })
                .finally(function () {
                    saveBtn.disabled = false;
                    if (spinner) spinner.classList.remove('is-active');
                });
            });
        }

        // Edit rule buttons (delegate).
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('wpt-automation-edit')) {
                var ruleId = e.target.getAttribute('data-rule-id');
                e.target.disabled = true;

                ajaxPost('wpt_automation_get_rule', { rule_id: ruleId })
                    .then(function (result) {
                        if (result.success) {
                            loadRuleIntoEditor(result.data);
                            editor.style.display = '';
                            if (logView) logView.style.display = 'none';
                        } else {
                            alert(result.data && result.data.message ? result.data.message : config.i18n.networkError);
                        }
                    })
                    .catch(function () { alert(config.i18n.networkError); })
                    .finally(function () { e.target.disabled = false; });
            }
        });

        // Delete rule buttons (delegate).
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('wpt-automation-delete')) {
                if (!confirm(config.i18n.confirmDelete)) return;

                var ruleId = e.target.getAttribute('data-rule-id');
                e.target.disabled = true;

                ajaxPost('wpt_automation_delete_rule', { rule_id: ruleId })
                    .then(function (result) {
                        if (result.success) {
                            window.location.reload();
                        } else {
                            alert(result.data && result.data.message ? result.data.message : config.i18n.networkError);
                            e.target.disabled = false;
                        }
                    })
                    .catch(function () {
                        alert(config.i18n.networkError);
                        e.target.disabled = false;
                    });
            }
        });

        // Toggle rule buttons (delegate).
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('wpt-automation-toggle') || e.target.closest('.wpt-automation-toggle')) {
                var btn = e.target.classList.contains('wpt-automation-toggle')
                    ? e.target
                    : e.target.closest('.wpt-automation-toggle');
                var ruleId = btn.getAttribute('data-rule-id');
                btn.disabled = true;

                ajaxPost('wpt_automation_toggle_rule', { rule_id: ruleId })
                    .then(function (result) {
                        if (result.success) {
                            window.location.reload();
                        } else {
                            alert(result.data && result.data.message ? result.data.message : config.i18n.networkError);
                            btn.disabled = false;
                        }
                    })
                    .catch(function () {
                        alert(config.i18n.networkError);
                        btn.disabled = false;
                    });
            }
        });

        // View log.
        if (viewLogBtn) {
            viewLogBtn.addEventListener('click', function () {
                editor.style.display = 'none';
                logView.style.display = '';
                logEntries.innerHTML = '<p>Loading...</p>';

                ajaxPost('wpt_automation_get_log', {})
                    .then(function (result) {
                        if (result.success && result.data.length > 0) {
                            var html = '<table class="widefat striped"><thead><tr>'
                                     + '<th>Time</th><th>Rule</th><th>Trigger</th><th>Results</th></tr></thead><tbody>';
                            result.data.forEach(function (entry) {
                                html += '<tr>';
                                html += '<td>' + escapeHtml(entry.timestamp) + '</td>';
                                html += '<td>' + escapeHtml(entry.rule_name) + ' (#' + entry.rule_id + ')</td>';
                                html += '<td>' + escapeHtml(entry.trigger) + '</td>';
                                html += '<td>';
                                if (entry.results && entry.results.length) {
                                    entry.results.forEach(function (r) {
                                        var icon = r.success ? '&#10003;' : '&#10007;';
                                        html += '<div>' + icon + ' ' + escapeHtml(r.type) + ': ' + escapeHtml(r.message) + '</div>';
                                    });
                                }
                                html += '</td></tr>';
                            });
                            html += '</tbody></table>';
                            logEntries.innerHTML = html;
                        } else {
                            logEntries.innerHTML = '<p>' + escapeHtml(config.i18n.noLogEntries) + '</p>';
                        }
                    })
                    .catch(function () {
                        logEntries.innerHTML = '<p>' + escapeHtml(config.i18n.networkError) + '</p>';
                    });
            });
        }

        // Close log.
        if (closeLogBtn) {
            closeLogBtn.addEventListener('click', function () {
                logView.style.display = 'none';
            });
        }

        // -- Helper functions --

        function resetEditor() {
            ruleIdInput.value = '0';
            nameInput.value = '';
            triggerSelect.selectedIndex = 0;
            conditionsDiv.innerHTML = '';
            actionsDiv.innerHTML = '';
        }

        function addConditionRow(data) {
            var html = getTemplate('wpt-tmpl-condition-row');
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            var row = wrapper.firstElementChild;
            conditionsDiv.appendChild(row);

            if (data) {
                row.querySelector('.wpt-cond-field').value = data.field || '';
                row.querySelector('.wpt-cond-op').value = data.op || '==';
                row.querySelector('.wpt-cond-value').value = data.value || '';
            }
        }

        function addActionRow(data) {
            var html = getTemplate('wpt-tmpl-action-row');
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            var row = wrapper.firstElementChild;
            actionsDiv.appendChild(row);

            var type = (data && data.type) ? data.type : 'send_email';
            row.querySelector('.wpt-action-type').value = type;
            renderActionFields(row, type, data);
        }

        function collectConditions() {
            var rows = conditionsDiv.querySelectorAll('.wpt-automation-condition-row');
            var conditions = [];
            for (var i = 0; i < rows.length; i++) {
                var field = rows[i].querySelector('.wpt-cond-field').value.trim();
                var op    = rows[i].querySelector('.wpt-cond-op').value;
                var value = rows[i].querySelector('.wpt-cond-value').value.trim();
                if (field) {
                    conditions.push({ field: field, op: op, value: value });
                }
            }
            return conditions;
        }

        function collectActions() {
            var rows = actionsDiv.querySelectorAll('.wpt-automation-action-row');
            var actions = [];
            for (var i = 0; i < rows.length; i++) {
                var type = rows[i].querySelector('.wpt-action-type').value;
                var action = { type: type };

                switch (type) {
                    case 'send_email':
                        action.to      = (rows[i].querySelector('.wpt-action-to') || {}).value || '';
                        action.subject = (rows[i].querySelector('.wpt-action-subject') || {}).value || '';
                        action.body    = (rows[i].querySelector('.wpt-action-body') || {}).value || '';
                        break;
                    case 'send_webhook':
                        action.url     = (rows[i].querySelector('.wpt-action-url') || {}).value || '';
                        action.payload = (rows[i].querySelector('.wpt-action-payload') || {}).value || '';
                        break;
                    case 'set_meta':
                        action.meta_key   = (rows[i].querySelector('.wpt-action-meta-key') || {}).value || '';
                        action.meta_value = (rows[i].querySelector('.wpt-action-meta-value') || {}).value || '';
                        break;
                    case 'clear_caches':
                        break;
                }

                actions.push(action);
            }
            return actions;
        }

        function loadRuleIntoEditor(rule) {
            resetEditor();
            editorTitle.textContent = config.i18n.editRule;
            ruleIdInput.value = rule.id || '0';
            nameInput.value = rule.name || '';
            triggerSelect.value = rule.trigger_hook || '';

            if (rule.conditions && rule.conditions.length) {
                rule.conditions.forEach(function (c) { addConditionRow(c); });
            }
            if (rule.actions && rule.actions.length) {
                rule.actions.forEach(function (a) { addActionRow(a); });
            }
        }
    });
})();
