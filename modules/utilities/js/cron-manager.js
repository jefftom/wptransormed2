/**
 * Cron Manager -- Vanilla JS for AJAX actions.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptCronManager || {};

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

    function showNotice(message, type) {
        var existing = document.querySelector('.wpt-cron-notice');
        if (existing) existing.remove();

        var notice = document.createElement('div');
        notice.className = 'notice notice-' + type + ' is-dismissible wpt-cron-notice';
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

        var table = document.querySelector('.wpt-cron-table');
        if (table) {
            table.parentNode.insertBefore(notice, table);
        } else {
            var heading = document.querySelector('.wpt-cron-add-form');
            if (heading) {
                heading.parentNode.insertBefore(notice, heading);
            }
        }
    }

    function setButtonLoading(button, loading, text) {
        button.disabled = loading;
        if (text) button.textContent = text;

        var spinner = button.parentNode.querySelector('.wpt-cron-spinner');
        if (spinner) {
            spinner.classList.toggle('is-active', loading);
        }
    }

    // -- Run Now ----------------------------------------------------------

    function handleRunNow(e) {
        var button = e.target.closest('.wpt-cron-run');
        if (!button) return;

        if (!confirm(config.i18n.confirmRun)) return;

        var originalText = button.textContent;
        setButtonLoading(button, true, config.i18n.running);

        post('wpt_cron_run_now', {
            hook:      button.dataset.hook,
            args:      button.dataset.args,
            timestamp: button.dataset.timestamp
        }, function (response) {
            setButtonLoading(button, false, originalText);

            if (response.success) {
                showNotice(response.data.message, 'success');
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Delete -----------------------------------------------------------

    function handleDelete(e) {
        var button = e.target.closest('.wpt-cron-delete');
        if (!button) return;

        if (!confirm(config.i18n.confirmDelete)) return;

        var originalText = button.textContent;
        var row = button.closest('tr');
        setButtonLoading(button, true, config.i18n.deleting);

        post('wpt_cron_delete', {
            hook:      button.dataset.hook,
            args:      button.dataset.args,
            timestamp: button.dataset.timestamp
        }, function (response) {
            if (response.success) {
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(function () {
                        row.remove();
                        updateCount();
                    }, 300);
                }
                showNotice(response.data.message, 'success');
            } else {
                setButtonLoading(button, false, originalText);
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Add Event --------------------------------------------------------

    function handleAdd() {
        var hookInput    = document.getElementById('wpt-cron-new-hook');
        var schedSelect  = document.getElementById('wpt-cron-new-schedule');
        var addButton    = document.getElementById('wpt-cron-add-btn');
        var addSpinner   = document.getElementById('wpt-cron-add-spinner');

        if (!hookInput || !schedSelect || !addButton) return;

        var hook = hookInput.value.trim();
        if (!hook) {
            showNotice(config.i18n.emptyHook, 'error');
            hookInput.focus();
            return;
        }

        addButton.disabled = true;
        if (addSpinner) addSpinner.classList.add('is-active');

        post('wpt_cron_add', {
            hook:       hook,
            recurrence: schedSelect.value
        }, function (response) {
            addButton.disabled = false;
            if (addSpinner) addSpinner.classList.remove('is-active');

            if (response.success) {
                showNotice(response.data.message, 'success');
                hookInput.value = '';
                // Reload page after a short delay so the new event appears.
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Toggle Schedules Reference ---------------------------------------

    function handleToggleSchedules() {
        var table  = document.getElementById('wpt-schedules-table');
        var button = document.getElementById('wpt-toggle-schedules');
        if (!table || !button) return;

        var isHidden = table.style.display === 'none';
        table.style.display = isHidden ? '' : 'none';

        var icon = button.querySelector('.dashicons');
        if (icon) {
            icon.className = isHidden
                ? 'dashicons dashicons-arrow-up-alt2'
                : 'dashicons dashicons-arrow-down-alt2';
        }
    }

    // -- Count Update -----------------------------------------------------

    function updateCount() {
        var countEl = document.querySelector('.wpt-cron-count');
        var rows    = document.querySelectorAll('.wpt-cron-table tbody tr');
        if (countEl) {
            countEl.textContent = '(' + rows.length + ')';
        }
    }

    // -- Init -------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        // Delegate click events on the cron table.
        var table = document.querySelector('.wpt-cron-table');
        if (table) {
            table.addEventListener('click', function (e) {
                if (e.target.closest('.wpt-cron-run')) {
                    handleRunNow(e);
                } else if (e.target.closest('.wpt-cron-delete')) {
                    handleDelete(e);
                }
            });
        }

        // Add event button.
        var addBtn = document.getElementById('wpt-cron-add-btn');
        if (addBtn) {
            addBtn.addEventListener('click', handleAdd);
        }

        // Toggle schedules reference.
        var toggleBtn = document.getElementById('wpt-toggle-schedules');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', handleToggleSchedules);
        }
    });
})();
