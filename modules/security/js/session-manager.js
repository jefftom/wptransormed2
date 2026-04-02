/**
 * Session Manager -- Vanilla JS for AJAX destroy actions.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptSessionManager || {};

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
        var existing = document.querySelector('.wpt-session-notice');
        if (existing) existing.remove();

        var notice = document.createElement('div');
        notice.className = 'notice notice-' + type + ' is-dismissible wpt-session-notice';
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

        var table = document.querySelector('.wpt-session-table');
        if (table) {
            table.parentNode.insertBefore(notice, table);
        }
    }

    function setButtonLoading(button, loading, text) {
        button.disabled = loading;
        if (text) button.textContent = text;

        var spinner = button.parentNode.querySelector('.wpt-session-spinner');
        if (spinner) {
            spinner.classList.toggle('is-active', loading);
        }
    }

    // -- Destroy Single Session -------------------------------------------

    function handleDestroy(e) {
        var button = e.target.closest('.wpt-session-destroy');
        if (!button) return;

        if (!confirm(config.i18n.confirmDestroy)) return;

        var originalText = button.textContent;
        var row = button.closest('tr');
        setButtonLoading(button, true, config.i18n.destroying);

        post('wpt_destroy_session', {
            user_id:  button.dataset.userId,
            verifier: button.dataset.verifier
        }, function (response) {
            if (response.success) {
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(function () {
                        row.remove();
                    }, 300);
                }
                showNotice(response.data.message, 'success');
            } else {
                setButtonLoading(button, false, originalText);
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Destroy Other Sessions -------------------------------------------

    function handleDestroyOthers(e) {
        var button = e.target.closest('.wpt-session-destroy-others');
        if (!button) return;

        if (!confirm(config.i18n.confirmDestroyOthers)) return;

        var originalText = button.textContent;
        setButtonLoading(button, true, config.i18n.destroying);

        post('wpt_destroy_other_sessions', {
            user_id: button.dataset.userId
        }, function (response) {
            if (response.success) {
                showNotice(response.data.message, 'success');
                // Reload after short delay to refresh the table.
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            } else {
                setButtonLoading(button, false, originalText);
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Init -------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        // Delegate click events on the session table.
        var table = document.querySelector('.wpt-session-table');
        if (table) {
            table.addEventListener('click', function (e) {
                if (e.target.closest('.wpt-session-destroy')) {
                    handleDestroy(e);
                }
            });
        }

        // Destroy other sessions buttons.
        var destroyOtherBtns = document.querySelectorAll('.wpt-session-destroy-others');
        for (var i = 0; i < destroyOtherBtns.length; i++) {
            destroyOtherBtns[i].addEventListener('click', handleDestroyOthers);
        }
    });
})();
