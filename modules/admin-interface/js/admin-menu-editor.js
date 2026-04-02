/**
 * Admin Menu Editor — Settings Page JS
 *
 * Responsibilities:
 * 1. Render menu items as a sortable list
 * 2. HTML5 drag-and-drop for reordering
 * 3. Click-to-edit labels (inline rename)
 * 4. Eye icon toggle for hiding items
 * 5. Dashicons picker grid for custom icons
 * 6. Separator toggles
 * 7. Serialize all state into hidden form fields before submit
 * 8. Reset to Default
 */
(function () {
    'use strict';

    /* ── State ────────────────────────────────────────────── */

    var menuItems    = [];   // [{slug, label, icon}, ...]
    var menuOrder    = [];   // [slug, slug, ...]
    var hiddenItems  = [];   // [slug, ...]
    var renamedItems = {};   // {slug: label}
    var customIcons  = {};   // {slug: dashicon-class}
    var separators   = [];   // [slug, ...]

    /* ── Common Dashicons ─────────────────────────────────── */

    var DASHICONS = [
        'dashicons-admin-home', 'dashicons-admin-post', 'dashicons-admin-media',
        'dashicons-admin-links', 'dashicons-admin-page', 'dashicons-admin-comments',
        'dashicons-admin-appearance', 'dashicons-admin-plugins', 'dashicons-admin-users',
        'dashicons-admin-tools', 'dashicons-admin-settings', 'dashicons-admin-network',
        'dashicons-admin-generic', 'dashicons-admin-collapse', 'dashicons-admin-site',
        'dashicons-dashboard', 'dashicons-heart', 'dashicons-star-filled',
        'dashicons-star-empty', 'dashicons-flag', 'dashicons-warning',
        'dashicons-yes', 'dashicons-no', 'dashicons-plus', 'dashicons-minus',
        'dashicons-dismiss', 'dashicons-marker', 'dashicons-lock',
        'dashicons-unlock', 'dashicons-calendar', 'dashicons-calendar-alt',
        'dashicons-visibility', 'dashicons-hidden', 'dashicons-editor-bold',
        'dashicons-editor-italic', 'dashicons-editor-ul', 'dashicons-editor-ol',
        'dashicons-editor-quote', 'dashicons-editor-code', 'dashicons-editor-table',
        'dashicons-image-crop', 'dashicons-image-rotate', 'dashicons-image-filter',
        'dashicons-archive', 'dashicons-tagcloud', 'dashicons-text',
        'dashicons-category', 'dashicons-tag', 'dashicons-clipboard',
        'dashicons-email', 'dashicons-email-alt', 'dashicons-smartphone',
        'dashicons-tablet', 'dashicons-desktop', 'dashicons-laptop',
        'dashicons-cart', 'dashicons-money', 'dashicons-vault',
        'dashicons-shield', 'dashicons-shield-alt', 'dashicons-tickets',
        'dashicons-nametag', 'dashicons-id', 'dashicons-id-alt',
        'dashicons-businessman', 'dashicons-businesswoman', 'dashicons-businessperson',
        'dashicons-groups', 'dashicons-awards', 'dashicons-thumbs-up',
        'dashicons-thumbs-down', 'dashicons-location', 'dashicons-location-alt',
        'dashicons-chart-pie', 'dashicons-chart-bar', 'dashicons-chart-line',
        'dashicons-chart-area', 'dashicons-performance', 'dashicons-analytics',
        'dashicons-building', 'dashicons-store', 'dashicons-hammer',
        'dashicons-art', 'dashicons-migrate', 'dashicons-backup',
        'dashicons-database', 'dashicons-cloud', 'dashicons-download',
        'dashicons-upload', 'dashicons-share', 'dashicons-share-alt',
        'dashicons-share-alt2', 'dashicons-rss', 'dashicons-external',
        'dashicons-networking', 'dashicons-translation', 'dashicons-globe',
        'dashicons-lightbulb', 'dashicons-microphone', 'dashicons-portfolio',
        'dashicons-book', 'dashicons-book-alt', 'dashicons-format-image',
        'dashicons-format-gallery', 'dashicons-format-audio', 'dashicons-format-video',
        'dashicons-format-chat', 'dashicons-format-status', 'dashicons-format-aside',
        'dashicons-format-quote', 'dashicons-welcome-write-blog',
        'dashicons-welcome-add-page', 'dashicons-welcome-view-site',
        'dashicons-welcome-widgets-menus', 'dashicons-welcome-comments',
        'dashicons-welcome-learn-more', 'dashicons-rest-api', 'dashicons-code-standards',
        'dashicons-buddicons-activity', 'dashicons-buddicons-bbpress-logo',
        'dashicons-buddicons-buddypress-logo', 'dashicons-buddicons-community',
        'dashicons-buddicons-forums', 'dashicons-buddicons-friends',
        'dashicons-buddicons-groups', 'dashicons-buddicons-pm',
        'dashicons-buddicons-replies', 'dashicons-buddicons-topics',
        'dashicons-buddicons-tracking', 'dashicons-color-picker',
        'dashicons-editor-customchar', 'dashicons-editor-help',
        'dashicons-editor-indent', 'dashicons-editor-insertmore',
        'dashicons-editor-kitchensink', 'dashicons-editor-ltr',
        'dashicons-editor-outdent', 'dashicons-editor-paragraph',
        'dashicons-editor-paste-text', 'dashicons-editor-paste-word',
        'dashicons-editor-removeformatting', 'dashicons-editor-rtl',
        'dashicons-editor-spellcheck', 'dashicons-editor-strikethrough',
        'dashicons-editor-textcolor', 'dashicons-editor-unlink',
        'dashicons-editor-video', 'dashicons-ellipsis',
        'dashicons-info', 'dashicons-insert', 'dashicons-menu',
        'dashicons-menu-alt', 'dashicons-menu-alt2', 'dashicons-menu-alt3',
        'dashicons-move', 'dashicons-no-alt', 'dashicons-pets',
        'dashicons-plus-alt', 'dashicons-plus-alt2', 'dashicons-randomize',
        'dashicons-redo', 'dashicons-remove', 'dashicons-saved',
        'dashicons-search', 'dashicons-slides', 'dashicons-sort',
        'dashicons-sos', 'dashicons-sticky', 'dashicons-superhero',
        'dashicons-superhero-alt', 'dashicons-trash', 'dashicons-undo',
        'dashicons-update', 'dashicons-update-alt'
    ];

    /* ── Init ─────────────────────────────────────────────── */

    function init() {
        var dataEl = document.getElementById('wpt-ame-init-data');
        if (!dataEl) return;

        try {
            var data = JSON.parse(dataEl.textContent);
            menuItems    = data.menuItems || [];
            menuOrder    = data.menuOrder || [];
            hiddenItems  = data.hiddenItems || [];
            renamedItems = data.renamedItems || {};
            customIcons  = data.customIcons || {};
            separators   = data.separators || [];
        } catch (e) {
            return;
        }

        // Fix: if renamedItems/customIcons came as array, convert to object.
        if (Array.isArray(renamedItems)) renamedItems = {};
        if (Array.isArray(customIcons)) customIcons = {};

        buildOrderedList();
        renderList();
        bindResetButton();
        bindFormSubmit();
    }

    /**
     * Build the ordered menu items list based on saved order.
     * Items in menuOrder come first, then any new items not in saved order.
     */
    function buildOrderedList() {
        if (menuOrder.length === 0) {
            // No saved order — use current order.
            menuOrder = menuItems.map(function (item) { return item.slug; });
            return;
        }

        var itemMap = {};
        menuItems.forEach(function (item) {
            itemMap[item.slug] = item;
        });

        var ordered = [];
        var seen = {};

        // Add items in saved order.
        menuOrder.forEach(function (slug) {
            if (itemMap[slug]) {
                ordered.push(itemMap[slug]);
                seen[slug] = true;
            }
        });

        // Append new items not in saved order.
        menuItems.forEach(function (item) {
            if (!seen[item.slug]) {
                ordered.push(item);
            }
        });

        menuItems = ordered;
        menuOrder = menuItems.map(function (item) { return item.slug; });
    }

    /* ── Render ───────────────────────────────────────────── */

    function renderList() {
        var listEl = document.getElementById('wpt-ame-list');
        if (!listEl) return;

        listEl.innerHTML = '';

        menuItems.forEach(function (item) {
            var row = createRow(item);
            listEl.appendChild(row);
        });
    }

    function createRow(item) {
        var slug    = item.slug;
        var label   = renamedItems[slug] || item.label;
        var icon    = customIcons[slug] || item.icon || 'dashicons-admin-generic';
        var isHidden = hiddenItems.indexOf(slug) !== -1;
        var hasSep   = separators.indexOf(slug) !== -1;

        var row = document.createElement('div');
        row.className = 'wpt-ame-row' + (isHidden ? ' wpt-ame-row--hidden' : '');
        row.setAttribute('data-slug', slug);
        row.setAttribute('draggable', 'true');

        // Drag handle.
        var handle = document.createElement('span');
        handle.className = 'wpt-ame-handle dashicons dashicons-move';
        handle.setAttribute('title', 'Drag to reorder');
        row.appendChild(handle);

        // Icon button.
        var iconBtn = document.createElement('button');
        iconBtn.type = 'button';
        iconBtn.className = 'wpt-ame-icon-btn';
        iconBtn.setAttribute('title', 'Change icon');
        iconBtn.innerHTML = '<span class="dashicons ' + escAttr(icon) + '"></span>';
        iconBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleIconPicker(row, slug);
        });
        row.appendChild(iconBtn);

        // Label (click to edit).
        var labelSpan = document.createElement('span');
        labelSpan.className = 'wpt-ame-label';
        labelSpan.textContent = label;
        labelSpan.setAttribute('title', 'Click to rename');
        labelSpan.addEventListener('click', function (e) {
            e.stopPropagation();
            startInlineEdit(row, slug, labelSpan);
        });
        row.appendChild(labelSpan);

        // Slug display.
        var slugSpan = document.createElement('span');
        slugSpan.className = 'wpt-ame-slug';
        slugSpan.textContent = slug;
        row.appendChild(slugSpan);

        // Eye toggle.
        var eyeBtn = document.createElement('button');
        eyeBtn.type = 'button';
        eyeBtn.className = 'wpt-ame-eye-btn';
        eyeBtn.setAttribute('title', isHidden ? 'Show item' : 'Hide item');
        eyeBtn.innerHTML = '<span class="dashicons ' + (isHidden ? 'dashicons-hidden' : 'dashicons-visibility') + '"></span>';
        eyeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleHidden(slug, row, eyeBtn);
        });
        row.appendChild(eyeBtn);

        // Expand/collapse arrow.
        var expandBtn = document.createElement('button');
        expandBtn.type = 'button';
        expandBtn.className = 'wpt-ame-expand-btn';
        expandBtn.setAttribute('title', 'More options');
        expandBtn.innerHTML = '<span class="dashicons dashicons-arrow-down-alt2"></span>';
        expandBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleExpanded(row, slug, expandBtn);
        });
        row.appendChild(expandBtn);

        // Expanded panel (hidden by default).
        var panel = document.createElement('div');
        panel.className = 'wpt-ame-expanded-panel';
        panel.style.display = 'none';

        // Rename field in expanded panel.
        var renameWrap = document.createElement('div');
        renameWrap.className = 'wpt-ame-expanded-field';
        renameWrap.innerHTML =
            '<label>Rename: <input type="text" class="wpt-ame-rename-input" value="' + escAttr(renamedItems[slug] || '') + '" placeholder="' + escAttr(item.label) + '"></label>';
        var renameInput = renameWrap.querySelector('input');
        renameInput.addEventListener('input', function () {
            var val = renameInput.value.trim();
            if (val === '' || val === item.label) {
                delete renamedItems[slug];
                labelSpan.textContent = item.label;
            } else {
                renamedItems[slug] = val;
                labelSpan.textContent = val;
            }
            syncFields();
        });
        panel.appendChild(renameWrap);

        // Hide checkbox in expanded panel.
        var hideWrap = document.createElement('div');
        hideWrap.className = 'wpt-ame-expanded-field';
        hideWrap.innerHTML =
            '<label><input type="checkbox" class="wpt-ame-hide-check"' + (isHidden ? ' checked' : '') + '> Hide this item</label>' +
            '<p class="description">Hidden items remain accessible via direct URL.</p>';
        var hideCheck = hideWrap.querySelector('input');
        hideCheck.addEventListener('change', function () {
            toggleHidden(slug, row, eyeBtn);
            hideCheck.checked = hiddenItems.indexOf(slug) !== -1;
        });
        panel.appendChild(hideWrap);

        // Separator checkbox.
        var sepWrap = document.createElement('div');
        sepWrap.className = 'wpt-ame-expanded-field';
        sepWrap.innerHTML =
            '<label><input type="checkbox" class="wpt-ame-sep-check"' + (hasSep ? ' checked' : '') + '> Add separator after this item</label>';
        var sepCheck = sepWrap.querySelector('input');
        sepCheck.addEventListener('change', function () {
            toggleSeparator(slug);
        });
        panel.appendChild(sepWrap);

        // Icon picker placeholder.
        var iconPickerWrap = document.createElement('div');
        iconPickerWrap.className = 'wpt-ame-icon-picker-wrap';
        panel.appendChild(iconPickerWrap);

        row.appendChild(panel);

        // Drag events.
        row.addEventListener('dragstart', onDragStart);
        row.addEventListener('dragover', onDragOver);
        row.addEventListener('dragenter', onDragEnter);
        row.addEventListener('dragleave', onDragLeave);
        row.addEventListener('drop', onDrop);
        row.addEventListener('dragend', onDragEnd);

        return row;
    }

    /* ── Inline Edit ──────────────────────────────────────── */

    function startInlineEdit(row, slug, labelSpan) {
        if (row.querySelector('.wpt-ame-inline-input')) return; // Already editing.

        var originalItem = menuItems.find(function (m) { return m.slug === slug; });
        var currentLabel = renamedItems[slug] || (originalItem ? originalItem.label : '');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'wpt-ame-inline-input';
        input.value = currentLabel;

        labelSpan.textContent = '';
        labelSpan.appendChild(input);
        input.focus();
        input.select();

        function finishEdit() {
            var val = input.value.trim();
            if (val === '' || (originalItem && val === originalItem.label)) {
                delete renamedItems[slug];
                labelSpan.textContent = originalItem ? originalItem.label : val;
            } else {
                renamedItems[slug] = val;
                labelSpan.textContent = val;
            }
            syncFields();

            // Also update rename input in expanded panel if open.
            var renameInput = row.querySelector('.wpt-ame-rename-input');
            if (renameInput) {
                renameInput.value = renamedItems[slug] || '';
            }
        }

        input.addEventListener('blur', finishEdit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            }
            if (e.key === 'Escape') {
                input.value = originalItem ? originalItem.label : '';
                input.blur();
            }
        });
    }

    /* ── Toggle Hidden ────────────────────────────────────── */

    function toggleHidden(slug, row, eyeBtn) {
        var idx = hiddenItems.indexOf(slug);
        if (idx !== -1) {
            hiddenItems.splice(idx, 1);
            row.classList.remove('wpt-ame-row--hidden');
            eyeBtn.innerHTML = '<span class="dashicons dashicons-visibility"></span>';
            eyeBtn.setAttribute('title', 'Hide item');
        } else {
            hiddenItems.push(slug);
            row.classList.add('wpt-ame-row--hidden');
            eyeBtn.innerHTML = '<span class="dashicons dashicons-hidden"></span>';
            eyeBtn.setAttribute('title', 'Show item');
        }

        // Sync the hide checkbox if panel is open.
        var hideCheck = row.querySelector('.wpt-ame-hide-check');
        if (hideCheck) {
            hideCheck.checked = hiddenItems.indexOf(slug) !== -1;
        }

        syncFields();
    }

    /* ── Toggle Separator ─────────────────────────────────── */

    function toggleSeparator(slug) {
        var idx = separators.indexOf(slug);
        if (idx !== -1) {
            separators.splice(idx, 1);
        } else {
            separators.push(slug);
        }
        syncFields();
    }

    /* ── Expanded Panel ───────────────────────────────────── */

    function toggleExpanded(row, slug, expandBtn) {
        var panel = row.querySelector('.wpt-ame-expanded-panel');
        if (!panel) return;

        var isOpen = panel.style.display !== 'none';
        panel.style.display = isOpen ? 'none' : 'block';
        expandBtn.innerHTML = '<span class="dashicons ' + (isOpen ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-up-alt2') + '"></span>';
    }

    /* ── Icon Picker ──────────────────────────────────────── */

    function toggleIconPicker(row, slug) {
        var wrap = row.querySelector('.wpt-ame-icon-picker-wrap');
        if (!wrap) return;

        // Toggle visibility.
        if (wrap.childElementCount > 0) {
            wrap.innerHTML = '';
            return;
        }

        // Also expand the panel to show the picker.
        var panel = row.querySelector('.wpt-ame-expanded-panel');
        if (panel) panel.style.display = 'block';

        var grid = document.createElement('div');
        grid.className = 'wpt-ame-icon-grid';

        DASHICONS.forEach(function (iconClass) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wpt-ame-icon-grid-item';
            btn.setAttribute('title', iconClass.replace('dashicons-', ''));
            btn.innerHTML = '<span class="dashicons ' + iconClass + '"></span>';

            var currentIcon = customIcons[slug] || '';
            if (iconClass === currentIcon) {
                btn.classList.add('wpt-ame-icon-grid-item--active');
            }

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                selectIcon(slug, iconClass, row);
                wrap.innerHTML = '';
            });

            grid.appendChild(btn);
        });

        // Reset to default button.
        var resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'button wpt-ame-icon-reset-btn';
        resetBtn.textContent = 'Reset to default icon';
        resetBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            delete customIcons[slug];
            var originalItem = menuItems.find(function (m) { return m.slug === slug; });
            var defaultIcon = originalItem ? originalItem.icon : 'dashicons-admin-generic';
            var iconBtn = row.querySelector('.wpt-ame-icon-btn .dashicons');
            if (iconBtn) {
                iconBtn.className = 'dashicons ' + defaultIcon;
            }
            syncFields();
            wrap.innerHTML = '';
        });

        wrap.appendChild(grid);
        wrap.appendChild(resetBtn);
    }

    function selectIcon(slug, iconClass, row) {
        customIcons[slug] = iconClass;

        // Update the icon button display.
        var iconBtn = row.querySelector('.wpt-ame-icon-btn .dashicons');
        if (iconBtn) {
            iconBtn.className = 'dashicons ' + iconClass;
        }

        syncFields();
    }

    /* ── Drag and Drop ────────────────────────────────────── */

    var draggedRow = null;

    function onDragStart(e) {
        draggedRow = this;
        this.classList.add('wpt-ame-row--dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.getAttribute('data-slug'));
    }

    function onDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    function onDragEnter(e) {
        e.preventDefault();
        if (this !== draggedRow) {
            this.classList.add('wpt-ame-row--drag-over');
        }
    }

    function onDragLeave(e) {
        this.classList.remove('wpt-ame-row--drag-over');
    }

    function onDrop(e) {
        e.preventDefault();
        this.classList.remove('wpt-ame-row--drag-over');

        if (!draggedRow || this === draggedRow) return;

        var list = document.getElementById('wpt-ame-list');
        if (!list) return;

        // Determine insertion position.
        var rows = Array.prototype.slice.call(list.querySelectorAll('.wpt-ame-row'));
        var targetIdx = rows.indexOf(this);
        var dragIdx   = rows.indexOf(draggedRow);

        if (dragIdx < targetIdx) {
            list.insertBefore(draggedRow, this.nextSibling);
        } else {
            list.insertBefore(draggedRow, this);
        }

        // Update order from DOM.
        updateOrderFromDOM();
    }

    function onDragEnd(e) {
        this.classList.remove('wpt-ame-row--dragging');
        draggedRow = null;

        // Remove all drag-over classes.
        var list = document.getElementById('wpt-ame-list');
        if (list) {
            var rows = list.querySelectorAll('.wpt-ame-row');
            for (var i = 0; i < rows.length; i++) {
                rows[i].classList.remove('wpt-ame-row--drag-over');
            }
        }
    }

    function updateOrderFromDOM() {
        var list = document.getElementById('wpt-ame-list');
        if (!list) return;

        var rows = list.querySelectorAll('.wpt-ame-row');
        var newOrder = [];
        for (var i = 0; i < rows.length; i++) {
            var slug = rows[i].getAttribute('data-slug');
            if (slug) newOrder.push(slug);
        }

        menuOrder = newOrder;

        // Also reorder menuItems to match.
        var itemMap = {};
        menuItems.forEach(function (item) { itemMap[item.slug] = item; });
        menuItems = newOrder.map(function (slug) { return itemMap[slug]; }).filter(Boolean);

        syncFields();
    }

    /* ── Sync Hidden Fields ───────────────────────────────── */

    function syncFields() {
        var orderField   = document.getElementById('wpt-ame-menu-order');
        var hiddenField  = document.getElementById('wpt-ame-hidden-items');
        var renamedField = document.getElementById('wpt-ame-renamed-json');
        var iconsField   = document.getElementById('wpt-ame-icons-json');
        var sepField     = document.getElementById('wpt-ame-separators');

        if (orderField)   orderField.value   = menuOrder.join(',');
        if (hiddenField)  hiddenField.value   = hiddenItems.join(',');
        if (renamedField) renamedField.value   = JSON.stringify(renamedItems);
        if (iconsField)   iconsField.value     = JSON.stringify(customIcons);
        if (sepField)     sepField.value       = separators.join(',');
    }

    /* ── Reset ────────────────────────────────────────────── */

    function bindResetButton() {
        var resetBtn = document.getElementById('wpt-ame-reset');
        if (!resetBtn) return;

        resetBtn.addEventListener('click', function () {
            if (!confirm('Reset all menu editor settings to defaults?')) return;

            menuOrder    = [];
            hiddenItems  = [];
            renamedItems = {};
            customIcons  = {};
            separators   = [];

            // Rebuild from original init data.
            var dataEl = document.getElementById('wpt-ame-init-data');
            if (dataEl) {
                try {
                    var data = JSON.parse(dataEl.textContent);
                    menuItems = data.menuItems || [];
                } catch (e) {
                    // Use current menuItems.
                }
            }

            menuOrder = menuItems.map(function (item) { return item.slug; });
            syncFields();
            renderList();
        });
    }

    /* ── Form Submit ──────────────────────────────────────── */

    function bindFormSubmit() {
        // Find the WPT settings form and sync fields before submit.
        var form = document.querySelector('form');
        if (!form) return;

        form.addEventListener('submit', function () {
            syncFields();
        });
    }

    /* ── Helpers ──────────────────────────────────────────── */

    function escAttr(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    /* ── Boot ─────────────────────────────────────────────── */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
