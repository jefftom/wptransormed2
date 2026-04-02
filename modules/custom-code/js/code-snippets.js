/**
 * WPTransformed Code Snippets — CodeMirror init + AJAX handlers.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptCodeSnippets || {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce = config.nonce || '';
    var cmSettings = config.cmSettings || {};
    var i18n = config.i18n || {};

    /** @type {CodeMirror.Editor|null} */
    var addEditor = null;

    /** @type {CodeMirror.Editor|null} */
    var editEditor = null;

    // -- CodeMirror Mode Map --

    var modeMap = {
        php: 'text/x-php',
        css: 'text/css',
        js: 'text/javascript',
        html: 'text/html'
    };

    // -- Init --

    document.addEventListener('DOMContentLoaded', function () {
        initCodeMirrorAdd();
        bindAddSnippet();
        bindToggleSnippet();
        bindDeleteSnippet();
        bindEditSnippet();
        bindTypeChange();
        bindModalClose();
    });

    // -- CodeMirror Initialization --

    function initCodeMirrorAdd() {
        var textarea = document.getElementById('wpt-snippet-code');
        if (!textarea || !cmSettings.codemirror) {
            return;
        }

        var settings = Object.assign({}, cmSettings.codemirror, {
            mode: modeMap.php
        });

        addEditor = wp.codeEditor.initialize(textarea, { codemirror: settings }).codemirror;
    }

    function initCodeMirrorEdit() {
        var textarea = document.getElementById('wpt-edit-code');
        if (!textarea || !cmSettings.codemirror) {
            return;
        }

        // Destroy existing instance if any.
        if (editEditor) {
            editEditor.toTextArea();
            editEditor = null;
        }

        var type = document.getElementById('wpt-edit-type');
        var mode = type ? (modeMap[type.value] || modeMap.php) : modeMap.php;

        var settings = Object.assign({}, cmSettings.codemirror, {
            mode: mode
        });

        editEditor = wp.codeEditor.initialize(textarea, { codemirror: settings }).codemirror;
    }

    // -- Type Change (show/hide HTML placement, update CM mode) --

    function bindTypeChange() {
        var addType = document.getElementById('wpt-snippet-type');
        var editType = document.getElementById('wpt-edit-type');
        var phpNote = document.getElementById('wpt-snippet-php-note');
        var htmlPlacementRow = document.getElementById('wpt-snippet-html-placement-row');
        var editHtmlPlacementRow = document.getElementById('wpt-edit-html-placement-row');

        if (addType) {
            addType.addEventListener('change', function () {
                var val = addType.value;

                // Toggle PHP note visibility.
                if (phpNote) {
                    phpNote.style.display = val === 'php' ? '' : 'none';
                }

                // Toggle HTML placement row.
                if (htmlPlacementRow) {
                    htmlPlacementRow.style.display = val === 'html' ? '' : 'none';
                }

                // Update CodeMirror mode.
                if (addEditor) {
                    addEditor.setOption('mode', modeMap[val] || modeMap.php);
                }
            });
        }

        if (editType) {
            editType.addEventListener('change', function () {
                var val = editType.value;

                if (editHtmlPlacementRow) {
                    editHtmlPlacementRow.style.display = val === 'html' ? '' : 'none';
                }

                if (editEditor) {
                    editEditor.setOption('mode', modeMap[val] || modeMap.php);
                }
            });
        }
    }

    // -- Add Snippet --

    function bindAddSnippet() {
        var btn = document.getElementById('wpt-add-snippet-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var title = document.getElementById('wpt-snippet-title');
            var type = document.getElementById('wpt-snippet-type');
            var scope = document.getElementById('wpt-snippet-scope');
            var priority = document.getElementById('wpt-snippet-priority');
            var description = document.getElementById('wpt-snippet-description');
            var placement = document.getElementById('wpt-snippet-placement');

            var titleVal = title ? title.value.trim() : '';
            var codeVal = addEditor ? addEditor.getValue().trim() : '';
            var typeVal = type ? type.value : 'php';

            if (!titleVal) {
                alert(i18n.titleRequired || 'Title is required.');
                return;
            }

            if (!codeVal) {
                alert(i18n.codeRequired || 'Code is required.');
                return;
            }

            // Check PHP disabled.
            var manager = document.getElementById('wpt-snippets-manager');
            if (typeVal === 'php' && manager && manager.dataset.phpEnabled === '0') {
                alert(i18n.phpDisabled || 'PHP snippets are disabled.');
                return;
            }

            // Build conditional for HTML placement.
            var conditional = '';
            if (typeVal === 'html' && placement) {
                conditional = JSON.stringify({ placement: placement.value });
            }

            btn.disabled = true;
            btn.textContent = i18n.saving || 'Saving...';

            var data = new FormData();
            data.append('action', 'wpt_add_snippet');
            data.append('nonce', nonce);
            data.append('title', titleVal);
            data.append('code', codeVal);
            data.append('type', typeVal);
            data.append('scope', scope ? scope.value : 'everywhere');
            data.append('priority', priority ? priority.value : '10');
            data.append('description', description ? description.value.trim() : '');
            data.append('conditional', conditional);

            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    btn.disabled = false;
                    btn.textContent = i18n.addSnippet || 'Add Snippet';

                    if (response.success) {
                        // Reload page to show new snippet.
                        window.location.reload();
                    } else {
                        alert(response.data && response.data.message ? response.data.message : 'Error adding snippet.');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = i18n.addSnippet || 'Add Snippet';
                    alert(i18n.networkError || 'Network error.');
                });
        });
    }

    // -- Toggle Snippet Active/Inactive --

    function bindToggleSnippet() {
        document.addEventListener('change', function (e) {
            if (!e.target.classList.contains('wpt-snippet-toggle')) return;

            var checkbox = e.target;
            var snippetId = checkbox.dataset.snippetId;
            var active = checkbox.checked ? '1' : '0';

            var data = new FormData();
            data.append('action', 'wpt_toggle_snippet');
            data.append('nonce', nonce);
            data.append('snippet_id', snippetId);
            data.append('active', active);

            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (!response.success) {
                        // Revert checkbox.
                        checkbox.checked = !checkbox.checked;
                        alert(response.data && response.data.message ? response.data.message : 'Error toggling snippet.');
                    }
                })
                .catch(function () {
                    checkbox.checked = !checkbox.checked;
                    alert(i18n.networkError || 'Network error.');
                });
        });
    }

    // -- Delete Snippet --

    function bindDeleteSnippet() {
        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('wpt-delete-snippet')) return;

            var btn = e.target;
            var snippetId = btn.dataset.snippetId;

            if (!confirm(i18n.confirmDelete || 'Are you sure?')) return;

            btn.disabled = true;

            var data = new FormData();
            data.append('action', 'wpt_delete_snippet');
            data.append('nonce', nonce);
            data.append('snippet_id', snippetId);

            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (response.success) {
                        // Remove the row.
                        var row = btn.closest('tr');
                        if (row) row.remove();

                        // Show empty message if no rows left.
                        var tbody = document.getElementById('wpt-snippets-tbody');
                        if (tbody && tbody.querySelectorAll('tr[data-snippet-id]').length === 0) {
                            var emptyRow = document.createElement('tr');
                            emptyRow.className = 'wpt-no-snippets';
                            emptyRow.innerHTML = '<td colspan="7">No snippets yet. Add your first snippet above.</td>';
                            tbody.appendChild(emptyRow);
                        }
                    } else {
                        btn.disabled = false;
                        alert(response.data && response.data.message ? response.data.message : 'Error deleting snippet.');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    alert(i18n.networkError || 'Network error.');
                });
        });
    }

    // -- Edit Snippet (Modal) --

    function bindEditSnippet() {
        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('wpt-edit-snippet')) return;

            var btn = e.target;
            var modal = document.getElementById('wpt-snippet-edit-modal');
            if (!modal) return;

            // Populate modal fields.
            document.getElementById('wpt-edit-snippet-id').value = btn.dataset.snippetId;
            document.getElementById('wpt-edit-title').value = btn.dataset.title || '';
            document.getElementById('wpt-edit-type').value = btn.dataset.type || 'php';
            document.getElementById('wpt-edit-scope').value = btn.dataset.scope || 'everywhere';
            document.getElementById('wpt-edit-priority').value = btn.dataset.priority || '10';
            document.getElementById('wpt-edit-description').value = btn.dataset.description || '';

            // HTML placement.
            var editHtmlRow = document.getElementById('wpt-edit-html-placement-row');
            if (editHtmlRow) {
                editHtmlRow.style.display = btn.dataset.type === 'html' ? '' : 'none';
            }

            var conditionalStr = btn.dataset.conditional || '';
            if (conditionalStr) {
                try {
                    var cond = JSON.parse(conditionalStr);
                    var placementSelect = document.getElementById('wpt-edit-placement');
                    if (placementSelect && cond.placement) {
                        placementSelect.value = cond.placement;
                    }
                } catch (ex) {
                    // Ignore parse errors.
                }
            }

            // Set code in textarea first, then init CM.
            var editCodeEl = document.getElementById('wpt-edit-code');
            if (editCodeEl) {
                editCodeEl.value = btn.dataset.code || '';
            }

            // Show modal.
            modal.style.display = 'flex';

            // Initialize CodeMirror for edit after modal is visible.
            setTimeout(function () {
                initCodeMirrorEdit();
                if (editEditor) {
                    editEditor.setValue(btn.dataset.code || '');
                    editEditor.refresh();
                }
            }, 50);
        });

        // Save edits.
        var saveBtn = document.getElementById('wpt-edit-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var snippetId = document.getElementById('wpt-edit-snippet-id').value;
                var title = document.getElementById('wpt-edit-title').value.trim();
                var type = document.getElementById('wpt-edit-type').value;
                var scope = document.getElementById('wpt-edit-scope').value;
                var priority = document.getElementById('wpt-edit-priority').value;
                var description = document.getElementById('wpt-edit-description').value.trim();
                var code = editEditor ? editEditor.getValue().trim() : document.getElementById('wpt-edit-code').value.trim();
                var placement = document.getElementById('wpt-edit-placement');

                if (!title) {
                    alert(i18n.titleRequired || 'Title is required.');
                    return;
                }

                if (!code) {
                    alert(i18n.codeRequired || 'Code is required.');
                    return;
                }

                var conditional = '';
                if (type === 'html' && placement) {
                    conditional = JSON.stringify({ placement: placement.value });
                }

                saveBtn.disabled = true;
                saveBtn.textContent = i18n.saving || 'Saving...';

                var data = new FormData();
                data.append('action', 'wpt_edit_snippet');
                data.append('nonce', nonce);
                data.append('snippet_id', snippetId);
                data.append('title', title);
                data.append('code', code);
                data.append('type', type);
                data.append('scope', scope);
                data.append('priority', priority);
                data.append('description', description);
                data.append('conditional', conditional);

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = i18n.saveChanges || 'Save Changes';

                        if (response.success) {
                            window.location.reload();
                        } else {
                            alert(response.data && response.data.message ? response.data.message : 'Error saving snippet.');
                        }
                    })
                    .catch(function () {
                        saveBtn.disabled = false;
                        saveBtn.textContent = i18n.saveChanges || 'Save Changes';
                        alert(i18n.networkError || 'Network error.');
                    });
            });
        }
    }

    // -- Modal Close --

    function bindModalClose() {
        var modal = document.getElementById('wpt-snippet-edit-modal');
        if (!modal) return;

        var closeBtn = modal.querySelector('.wpt-modal-close');
        var cancelBtn = document.getElementById('wpt-edit-cancel');
        var overlay = modal.querySelector('.wpt-modal-overlay');

        function closeModal() {
            modal.style.display = 'none';
            if (editEditor) {
                editEditor.toTextArea();
                editEditor = null;
            }
        }

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        if (overlay) overlay.addEventListener('click', closeModal);

        // Escape key.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display !== 'none') {
                closeModal();
            }
        });
    }

})();
