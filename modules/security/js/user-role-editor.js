/**
 * User Role Editor -- Vanilla JS for role and capability management.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptRoleEditor || {};

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
        var existing = document.querySelector('.wpt-role-ajax-notice');
        if (existing) existing.remove();

        var notice = document.createElement('div');
        notice.className = 'notice notice-' + type + ' is-dismissible wpt-role-ajax-notice';
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

        var editor = document.querySelector('.wpt-role-editor-toolbar');
        if (editor) {
            editor.parentNode.insertBefore(notice, editor);
        }
    }

    function setSpinner(spinnerEl, active) {
        if (spinnerEl) {
            spinnerEl.classList.toggle('is-active', active);
        }
    }

    // -- Role Selector: Update Checkboxes ---------------------------------

    function updateCheckboxes() {
        var select = document.getElementById('wpt-role-select');
        if (!select) return;

        var option = select.options[select.selectedIndex];
        if (!option) return;

        var caps = {};
        try {
            caps = JSON.parse(option.dataset.caps || '{}');
        } catch (e) {
            caps = {};
        }

        var isDefault = option.dataset.isDefault === '1';
        var deleteBtn = document.querySelector('.wpt-role-delete-btn');
        var resetBtn  = document.querySelector('.wpt-role-reset-btn');

        // Only show delete for custom roles.
        if (deleteBtn) {
            deleteBtn.style.display = isDefault ? 'none' : '';
        }
        // Only show reset for default roles.
        if (resetBtn) {
            resetBtn.style.display = isDefault ? '' : 'none';
        }

        // Update all checkboxes.
        var checkboxes = document.querySelectorAll('.wpt-cap-checkbox');
        checkboxes.forEach(function (cb) {
            var capName = cb.dataset.cap;
            cb.checked = !!caps[capName];
        });
    }

    // -- Save Capabilities ------------------------------------------------

    function handleSaveCaps() {
        var select  = document.getElementById('wpt-role-select');
        var saveBtn = document.getElementById('wpt-role-save-caps');
        var spinner = document.getElementById('wpt-role-save-spinner');

        if (!select || !saveBtn) return;

        var role = select.value;
        var caps = {};

        var checkboxes = document.querySelectorAll('.wpt-cap-checkbox');
        checkboxes.forEach(function (cb) {
            if (cb.checked) {
                caps[cb.dataset.cap] = true;
            }
        });

        saveBtn.disabled = true;
        saveBtn.textContent = config.i18n.saving;
        setSpinner(spinner, true);

        post('wpt_save_role_caps', {
            role: role,
            caps: JSON.stringify(caps)
        }, function (response) {
            saveBtn.disabled = false;
            saveBtn.textContent = saveBtn.getAttribute('data-original-text') || 'Save Capabilities';
            setSpinner(spinner, false);

            if (response.success) {
                showNotice(response.data.message, 'success');
                // Update the data-caps on the option so local state is fresh.
                var option = select.options[select.selectedIndex];
                if (option) {
                    option.dataset.caps = JSON.stringify(caps);
                }
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Add Role ---------------------------------------------------------

    function handleAddRole() {
        var slugInput = document.getElementById('wpt-role-new-slug');
        var nameInput = document.getElementById('wpt-role-new-name');
        var cloneFrom = document.getElementById('wpt-role-clone-from');
        var addBtn    = document.getElementById('wpt-role-add-btn');
        var spinner   = document.getElementById('wpt-role-add-spinner');

        if (!slugInput || !nameInput || !addBtn) return;

        var slug = slugInput.value.trim();
        var name = nameInput.value.trim();

        if (!slug) {
            showNotice(config.i18n.emptySlug, 'error');
            slugInput.focus();
            return;
        }
        if (!name) {
            showNotice(config.i18n.emptyName, 'error');
            nameInput.focus();
            return;
        }

        addBtn.disabled = true;
        setSpinner(spinner, true);

        post('wpt_add_role', {
            role_slug:  slug,
            role_name:  name,
            clone_from: cloneFrom ? cloneFrom.value : ''
        }, function (response) {
            addBtn.disabled = false;
            setSpinner(spinner, false);

            if (response.success) {
                showNotice(response.data.message, 'success');
                slugInput.value = '';
                nameInput.value = '';
                // Reload to show the new role in the dropdown.
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Delete Role ------------------------------------------------------

    function handleDeleteRole() {
        var select  = document.getElementById('wpt-role-select');
        var spinner = document.querySelector('.wpt-role-toolbar-spinner');

        if (!select) return;

        if (!confirm(config.i18n.confirmDelete)) return;

        var role = select.value;

        setSpinner(spinner, true);

        post('wpt_delete_role', {
            role: role
        }, function (response) {
            setSpinner(spinner, false);

            if (response.success) {
                showNotice(response.data.message, 'success');
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Reset Role -------------------------------------------------------

    function handleResetRole() {
        var select  = document.getElementById('wpt-role-select');
        var spinner = document.querySelector('.wpt-role-toolbar-spinner');

        if (!select) return;

        if (!confirm(config.i18n.confirmReset)) return;

        var role = select.value;

        setSpinner(spinner, true);

        post('wpt_reset_role', {
            role: role
        }, function (response) {
            setSpinner(spinner, false);

            if (response.success) {
                showNotice(response.data.message, 'success');
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- View as Role -----------------------------------------------------

    function handleViewAsRole() {
        var select  = document.getElementById('wpt-role-select');
        var spinner = document.querySelector('.wpt-role-toolbar-spinner');

        if (!select) return;

        if (!confirm(config.i18n.confirmViewAs)) return;

        var role = select.value;

        setSpinner(spinner, true);

        post('wpt_view_as_role', {
            role: role
        }, function (response) {
            setSpinner(spinner, false);

            if (response.success) {
                showNotice(response.data.message, 'success');
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Stop View as Role ------------------------------------------------

    function handleStopViewAs() {
        var button  = document.querySelector('.wpt-role-stop-view-as');
        var spinner = button ? button.nextElementSibling : null;

        if (button) button.disabled = true;
        setSpinner(spinner, true);

        post('wpt_stop_view_as', {}, function (response) {
            if (button) button.disabled = false;
            setSpinner(spinner, false);

            if (response.success) {
                showNotice(response.data.message, 'success');
                setTimeout(function () {
                    window.location.reload();
                }, 500);
            } else {
                showNotice(response.data.message || config.i18n.networkError, 'error');
            }
        });
    }

    // -- Admin Bar: Stop View as ------------------------------------------

    function handleAdminBarStopViewAs(e) {
        e.preventDefault();
        e.stopPropagation();

        post('wpt_stop_view_as', {}, function (response) {
            if (response.success) {
                window.location.reload();
            }
        });
    }

    // -- Init -------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        // Role selector change.
        var roleSelect = document.getElementById('wpt-role-select');
        if (roleSelect) {
            roleSelect.addEventListener('change', updateCheckboxes);
            // Set initial state.
            updateCheckboxes();
        }

        // Store original save button text.
        var saveBtn = document.getElementById('wpt-role-save-caps');
        if (saveBtn) {
            saveBtn.setAttribute('data-original-text', saveBtn.textContent);
            saveBtn.addEventListener('click', handleSaveCaps);
        }

        // Add role button.
        var addBtn = document.getElementById('wpt-role-add-btn');
        if (addBtn) {
            addBtn.addEventListener('click', handleAddRole);
        }

        // Delete role button.
        var deleteBtn = document.querySelector('.wpt-role-delete-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', handleDeleteRole);
        }

        // Reset role button.
        var resetBtn = document.querySelector('.wpt-role-reset-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', handleResetRole);
        }

        // View as role button.
        var viewAsBtn = document.querySelector('.wpt-role-view-as');
        if (viewAsBtn) {
            viewAsBtn.addEventListener('click', handleViewAsRole);
        }

        // Stop view as button (in settings page notice).
        var stopBtn = document.querySelector('.wpt-role-stop-view-as');
        if (stopBtn) {
            stopBtn.addEventListener('click', handleStopViewAs);
        }

        // Admin bar "Stop Viewing as Role" link.
        var adminBarStop = document.querySelector('#wp-admin-bar-wpt-stop-view-as a');
        if (adminBarStop) {
            adminBarStop.addEventListener('click', handleAdminBarStopViewAs);
        }
    });
})();
