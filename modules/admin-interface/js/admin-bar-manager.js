/**
 * Admin Bar Manager — Settings Page JS
 *
 * Responsibilities:
 * 1. Role tab switching (no page reload)
 * 2. Status summary + section count live updates
 * 3. Toggle switch → context selector enable/disable
 * 4. Parent/child toggle dependency
 * 5. Warning show/hide
 * 6. Select All / Deselect All per section
 * 7. Reset to Default
 * 8. Create / delete role profiles
 * 9. Serialize all profile data into hidden JSON input before submit
 */
(function () {
    'use strict';

    /* ── State ────────────────────────────────────────────── */

    // profiles = { 'default': { hidden_nodes: { 'wp-logo': 'both', ... } }, 'editor': { ... } }
    var profiles = {};
    var nodeGroups = {};   // { left: [{id,title,children:[]}], right: [...], plugin: [...] }
    var customLinks = [];  // [{title,url,icon,new_tab,position,roles:[]}]
    var wpRoles = {};      // {administrator:'Administrator',...}
    var activeRole = 'default';
    var MAX_LINKS = 10;

    /* ── Init ─────────────────────────────────────────────── */

    function init() {
        var dataEl = document.getElementById('wpt-abm-init-data');
        if (!dataEl) return;

        try {
            var data = JSON.parse(dataEl.textContent);
            profiles = data.profiles || {};
            nodeGroups = data.nodeGroups || {};
            customLinks = data.customLinks || [];
            wpRoles = data.wpRoles || {};
        } catch (e) {
            return;
        }

        // Ensure default profile exists.
        if (!profiles['default']) {
            profiles['default'] = { hidden_nodes: {} };
        }

        bindRoleTabs();
        bindCustomLinksControls();
        showRolePanel('default');
        renderCustomLinks();
        syncHiddenInput();
    }

    /* ── Role Tabs ────────────────────────────────────────── */

    function bindRoleTabs() {
        var tabs = document.querySelectorAll('.wpt-abm-role-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].addEventListener('click', function (e) {
                e.preventDefault();
                var role = this.getAttribute('data-role');
                showRolePanel(role);
            });
        }
    }

    function showRolePanel(role) {
        activeRole = role;

        // Update tab active state.
        var tabs = document.querySelectorAll('.wpt-abm-role-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-role') === role);
        }

        // Show/hide panels.
        var panels = document.querySelectorAll('.wpt-abm-profile-panel');
        for (var i = 0; i < panels.length; i++) {
            panels[i].classList.remove('active');
        }

        var hasProfile = !!profiles[role];
        var panelId = 'wpt-abm-panel-' + role;
        var panel = document.getElementById(panelId);

        if (role === 'default') {
            // Default always has content.
            if (panel) {
                panel.classList.add('active');
                renderProfileContent(panel, 'default');
            }
        } else if (hasProfile) {
            if (panel) {
                panel.classList.add('active');
                renderProfileContent(panel, role);
            }
        } else {
            // Show placeholder.
            if (panel) {
                panel.classList.add('active');
                renderPlaceholder(panel, role);
            }
        }

        updateStatusBar();
    }

    function renderPlaceholder(panel, role) {
        var roleName = panel.getAttribute('data-role-name') || role;
        panel.innerHTML =
            '<div class="wpt-abm-profile-placeholder">' +
                '<p>Using default (All Roles) settings</p>' +
                '<button type="button" class="wpt-abm-create-profile" data-role="' + esc(role) + '">' +
                    'Create custom profile for ' + esc(roleName) +
                '</button>' +
            '</div>';

        panel.querySelector('.wpt-abm-create-profile').addEventListener('click', function () {
            var r = this.getAttribute('data-role');
            createProfile(r);
        });
    }

    function createProfile(role) {
        // Clone default profile.
        profiles[role] = JSON.parse(JSON.stringify(profiles['default']));
        syncHiddenInput();
        showRolePanel(role);
    }

    function deleteProfile(role) {
        if (role === 'default') return;
        if (!confirm('Delete custom profile for this role? It will revert to the default (All Roles) settings.')) return;
        delete profiles[role];
        syncHiddenInput();
        showRolePanel(role);
    }

    /* ── Render Profile Content ───────────────────────────── */

    function renderProfileContent(panel, role) {
        var profile = profiles[role] || { hidden_nodes: {} };
        var hidden = profile.hidden_nodes || {};

        var html = '';

        // Delete profile link for non-default.
        if (role !== 'default') {
            html += '<button type="button" class="wpt-abm-delete-profile" data-role="' + esc(role) + '">' +
                'Delete profile (revert to defaults)</button>';
        }

        // Global controls.
        html += '<div class="wpt-abm-global-controls">' +
            '<button type="button" class="wpt-abm-reset-btn" data-role="' + esc(role) + '">Reset to Default</button>' +
        '</div>';

        // Sections.
        var sectionOrder = [
            { key: 'left', label: 'Left Side' },
            { key: 'right', label: 'Right Side' },
            { key: 'plugin', label: 'Plugin & Theme Items' }
        ];

        for (var s = 0; s < sectionOrder.length; s++) {
            var sec = sectionOrder[s];
            var nodes = nodeGroups[sec.key] || {};
            var nodeIds = Object.keys(nodes);
            if (nodeIds.length === 0 && sec.key === 'plugin') continue;

            html += renderSection(sec.key, sec.label, nodes, hidden, role);
        }

        panel.innerHTML = html;

        // Bind events.
        bindPanelEvents(panel, role);
    }

    function renderSection(sectionKey, label, nodes, hidden, role) {
        var nodeIds = Object.keys(nodes);
        var total = nodeIds.length;
        var hiddenCount = 0;
        for (var i = 0; i < nodeIds.length; i++) {
            if (hidden[nodeIds[i]]) hiddenCount++;
        }

        var html = '<div class="wpt-abm-section" data-section="' + esc(sectionKey) + '">';
        html += '<div class="wpt-abm-section-header">';
        html += '<h4 class="wpt-abm-section-title">' + esc(label) +
            ' <span class="wpt-abm-section-count">(' + total + ' items, ' + hiddenCount + ' hidden)</span></h4>';
        html += '<div class="wpt-abm-section-actions">' +
            '<a href="#" class="wpt-abm-select-all" data-section="' + esc(sectionKey) + '" data-role="' + esc(role) + '">Select All</a>' +
            '<a href="#" class="wpt-abm-deselect-all" data-section="' + esc(sectionKey) + '" data-role="' + esc(role) + '">Deselect All</a>' +
        '</div>';
        html += '</div>';
        html += '<div class="wpt-abm-section-body">';

        for (var i = 0; i < nodeIds.length; i++) {
            var node = nodes[nodeIds[i]];
            var id = node.id;
            var isHidden = !!hidden[id];
            var ctx = hidden[id] || 'both';

            html += renderItemRow(id, node.title || id, isHidden, ctx, false, role);

            // Children.
            var children = node.children || [];
            for (var c = 0; c < children.length; c++) {
                var child = children[c];
                var childId = child.id;
                var childHidden = !!hidden[childId];
                var childCtx = hidden[childId] || 'both';
                var parentOff = isHidden;
                html += renderItemRow(childId, child.title || childId, childHidden, childCtx, true, role, id, parentOff);
            }

            // Warnings.
            if (id === 'my-account') {
                html += '<div class="wpt-abm-warning" data-warn-for="my-account"' +
                    (isHidden ? ' style="display:block"' : '') +
                    '>&#9888;&#65039; This removes the logout link. Users can still log out via wp-login.php?action=logout</div>';
            }
            if (id === 'site-name') {
                html += '<div class="wpt-abm-warning" data-warn-for="site-name"' +
                    (isHidden ? ' style="display:block"' : '') +
                    '>&#9888;&#65039; This removes the quick link to visit your site from admin pages</div>';
            }
        }

        html += '</div></div>';
        return html;
    }

    function renderItemRow(id, title, isHidden, ctx, isChild, role, parentId, parentOff) {
        var tooltips = {
            'wp-logo': 'WordPress menu \u2014 links to About WordPress, Documentation, and WordPress.org',
            'site-name': 'Your site name \u2014 links to visit frontend, plus Customizer, Widgets, Menus',
            'updates': 'Shows count of available updates for plugins, themes, and WordPress core',
            'comments': 'Shows count of comments awaiting moderation',
            'new-content': 'Quick-add menu for creating new posts, pages, media, and users',
            'edit': 'Edit link for the current post or page (only appears on frontend)',
            'my-account': 'Your profile, account settings, and logout link',
            'top-secondary': 'Right-side container \u2014 holds the account/logout menu',
            'search': 'Admin search bar'
        };

        // isHidden means the toggle is OFF (item hidden). Checked toggle = visible (ON).
        var checked = !isHidden;
        var disabled = isChild && parentOff;

        var cls = 'wpt-abm-item';
        if (isChild) cls += ' is-child';
        if (disabled) cls += ' is-disabled';

        var html = '<div class="' + cls + '" data-node-id="' + esc(id) + '"';
        if (parentId) html += ' data-parent-id="' + esc(parentId) + '"';
        html += '>';

        // Toggle.
        html += '<label class="wpt-toggle-switch">';
        html += '<input type="checkbox" class="wpt-abm-toggle" data-node-id="' + esc(id) + '" data-role="' + esc(role) + '"';
        if (checked) html += ' checked';
        if (disabled) html += ' disabled';
        html += '>';
        html += '<span class="wpt-toggle-track"></span>';
        html += '</label>';

        // Title.
        html += '<span class="wpt-abm-item-title">' + esc(title) + '</span>';

        // ID.
        html += '<span class="wpt-abm-item-id">' + esc(id) + '</span>';

        // Right side.
        html += '<span class="wpt-abm-item-right">';

        // Context select.
        html += '<select class="wpt-abm-context-select" data-node-id="' + esc(id) + '" data-role="' + esc(role) + '"';
        if (checked || disabled) html += ' disabled';
        html += '>';
        html += '<option value="admin"' + (ctx === 'admin' ? ' selected' : '') + '>Admin</option>';
        html += '<option value="frontend"' + (ctx === 'frontend' ? ' selected' : '') + '>Frontend</option>';
        html += '<option value="both"' + (ctx === 'both' ? ' selected' : '') + '>Both</option>';
        html += '</select>';

        // Tooltip.
        if (tooltips[id]) {
            html += '<span class="wpt-abm-tooltip" data-tip="' + esc(tooltips[id]) + '">?</span>';
        }

        html += '</span>';
        html += '</div>';

        return html;
    }

    /* ── Panel Events ─────────────────────────────────────── */

    function bindPanelEvents(panel, role) {
        // Toggle switches.
        var toggles = panel.querySelectorAll('.wpt-abm-toggle');
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].addEventListener('change', handleToggleChange);
        }

        // Context selects.
        var selects = panel.querySelectorAll('.wpt-abm-context-select');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', handleContextChange);
        }

        // Select All / Deselect All.
        var selectAlls = panel.querySelectorAll('.wpt-abm-select-all');
        for (var i = 0; i < selectAlls.length; i++) {
            selectAlls[i].addEventListener('click', handleSelectAll);
        }

        var deselectAlls = panel.querySelectorAll('.wpt-abm-deselect-all');
        for (var i = 0; i < deselectAlls.length; i++) {
            deselectAlls[i].addEventListener('click', handleDeselectAll);
        }

        // Reset to Default.
        var resetBtns = panel.querySelectorAll('.wpt-abm-reset-btn');
        for (var i = 0; i < resetBtns.length; i++) {
            resetBtns[i].addEventListener('click', handleReset);
        }

        // Delete profile.
        var deleteBtns = panel.querySelectorAll('.wpt-abm-delete-profile');
        for (var i = 0; i < deleteBtns.length; i++) {
            deleteBtns[i].addEventListener('click', function () {
                deleteProfile(this.getAttribute('data-role'));
            });
        }
    }

    /* ── Toggle Change ────────────────────────────────────── */

    function handleToggleChange() {
        var nodeId = this.getAttribute('data-node-id');
        var role = this.getAttribute('data-role');
        var visible = this.checked;
        var profile = profiles[role];
        if (!profile) return;

        if (visible) {
            // Remove from hidden.
            delete profile.hidden_nodes[nodeId];
        } else {
            // Add to hidden with context from the select.
            var panel = this.closest('.wpt-abm-profile-panel');
            var ctxSelect = panel ? panel.querySelector('.wpt-abm-context-select[data-node-id="' + nodeId + '"]') : null;
            var ctx = ctxSelect ? ctxSelect.value : 'both';
            profile.hidden_nodes[nodeId] = ctx;
        }

        // Update context selector state.
        var row = this.closest('.wpt-abm-item');
        if (row) {
            var sel = row.querySelector('.wpt-abm-context-select');
            if (sel) {
                sel.disabled = visible; // Disabled when visible (ON).
            }
        }

        // Parent/child: if this is a parent, disable/enable children.
        var panel = this.closest('.wpt-abm-profile-panel');
        if (panel) {
            var children = panel.querySelectorAll('.wpt-abm-item[data-parent-id="' + nodeId + '"]');
            for (var i = 0; i < children.length; i++) {
                var childRow = children[i];
                var childToggle = childRow.querySelector('.wpt-abm-toggle');
                var childSelect = childRow.querySelector('.wpt-abm-context-select');

                if (!visible) {
                    // Parent OFF → disable children.
                    childRow.classList.add('is-disabled');
                    if (childToggle) childToggle.disabled = true;
                    if (childSelect) childSelect.disabled = true;
                } else {
                    // Parent ON → re-enable children.
                    childRow.classList.remove('is-disabled');
                    if (childToggle) childToggle.disabled = false;
                    // Context select: only enabled if child is OFF.
                    if (childToggle && childSelect) {
                        childSelect.disabled = childToggle.checked;
                    }
                }
            }
        }

        // Warnings.
        updateWarning(nodeId, !visible, panel);

        syncHiddenInput();
        updateStatusBar();
        updateSectionCounts(panel);
    }

    /* ── Context Change ───────────────────────────────────── */

    function handleContextChange() {
        var nodeId = this.getAttribute('data-node-id');
        var role = this.getAttribute('data-role');
        var ctx = this.value;
        var profile = profiles[role];
        if (!profile) return;

        // Only update if node is hidden.
        if (profile.hidden_nodes[nodeId]) {
            profile.hidden_nodes[nodeId] = ctx;
        }

        syncHiddenInput();
        updateStatusBar();
    }

    /* ── Select All / Deselect All ────────────────────────── */

    function handleSelectAll(e) {
        e.preventDefault();
        var sectionKey = this.getAttribute('data-section');
        var role = this.getAttribute('data-role');
        setAllInSection(sectionKey, role, false); // false = toggle OFF (hidden)
    }

    function handleDeselectAll(e) {
        e.preventDefault();
        var sectionKey = this.getAttribute('data-section');
        var role = this.getAttribute('data-role');
        setAllInSection(sectionKey, role, true); // true = toggle ON (visible)
    }

    function setAllInSection(sectionKey, role, visible) {
        var profile = profiles[role];
        if (!profile) return;

        var nodes = nodeGroups[sectionKey] || {};
        var nodeIds = Object.keys(nodes);

        for (var i = 0; i < nodeIds.length; i++) {
            var id = nodeIds[i];
            var node = nodes[id];

            if (visible) {
                delete profile.hidden_nodes[id];
            } else {
                profile.hidden_nodes[id] = 'both';
            }

            // Children too.
            var children = node.children || [];
            for (var c = 0; c < children.length; c++) {
                if (visible) {
                    delete profile.hidden_nodes[children[c].id];
                } else {
                    profile.hidden_nodes[children[c].id] = 'both';
                }
            }
        }

        syncHiddenInput();
        // Re-render this role panel.
        showRolePanel(role);
    }

    /* ── Reset to Default ─────────────────────────────────── */

    function handleReset() {
        var role = this.getAttribute('data-role');
        if (!confirm('This will restore all admin bar items to their default visible state for this profile. Continue?')) return;

        var profile = profiles[role];
        if (!profile) return;

        profile.hidden_nodes = {};
        syncHiddenInput();
        showRolePanel(role);
    }

    /* ── Warnings ─────────────────────────────────────────── */

    function updateWarning(nodeId, isHidden, panel) {
        if (!panel) return;
        var warn = panel.querySelector('.wpt-abm-warning[data-warn-for="' + nodeId + '"]');
        if (warn) {
            warn.style.display = isHidden ? 'block' : 'none';
        }
    }

    /* ── Status Bar ───────────────────────────────────────── */

    function updateStatusBar() {
        var bar = document.getElementById('wpt-abm-status');
        if (!bar) return;

        var profile = profiles[activeRole] || profiles['default'] || { hidden_nodes: {} };
        var hidden = profile.hidden_nodes || {};

        var adminCount = 0;
        var frontendCount = 0;
        var keys = Object.keys(hidden);

        for (var i = 0; i < keys.length; i++) {
            var ctx = hidden[keys[i]];
            if (ctx === 'admin' || ctx === 'both') adminCount++;
            if (ctx === 'frontend' || ctx === 'both') frontendCount++;
        }

        var roleName = activeRole === 'default' ? 'All Roles' : activeRole.charAt(0).toUpperCase() + activeRole.slice(1);

        bar.innerHTML = 'Currently hiding <strong>' + adminCount + ' item' + (adminCount !== 1 ? 's' : '') +
            '</strong> on admin, <strong>' + frontendCount + ' item' + (frontendCount !== 1 ? 's' : '') +
            '</strong> on frontend for <strong>' + esc(roleName) + '</strong>';
    }

    /* ── Section Counts ───────────────────────────────────── */

    function updateSectionCounts(panel) {
        if (!panel) return;
        var sections = panel.querySelectorAll('.wpt-abm-section');
        var profile = profiles[activeRole] || { hidden_nodes: {} };
        var hidden = profile.hidden_nodes || {};

        for (var i = 0; i < sections.length; i++) {
            var sec = sections[i];
            var sectionKey = sec.getAttribute('data-section');
            var nodes = nodeGroups[sectionKey] || {};
            var nodeIds = Object.keys(nodes);
            var total = nodeIds.length;
            var hiddenCount = 0;

            for (var n = 0; n < nodeIds.length; n++) {
                if (hidden[nodeIds[n]]) hiddenCount++;
            }

            var countEl = sec.querySelector('.wpt-abm-section-count');
            if (countEl) {
                countEl.textContent = '(' + total + ' items, ' + hiddenCount + ' hidden)';
            }
        }
    }

    /* ── Sync Hidden Input ────────────────────────────────── */

    function syncHiddenInput() {
        var input = document.getElementById('wpt-abm-profiles-json');
        if (input) {
            input.value = JSON.stringify(profiles);
        }
        var linksInput = document.getElementById('wpt-abm-custom-links-json');
        if (linksInput) {
            linksInput.value = JSON.stringify(customLinks);
        }
    }

    /* ── Global Bindings ──────────────────────────────────── */

    function bindCustomLinksControls() {
        var addBtn = document.getElementById('wpt-abm-add-link-btn');
        if (addBtn) {
            addBtn.addEventListener('click', handleAddLink);
        }
    }

    /* ── Custom Links ─────────────────────────────────────── */

    function renderCustomLinks() {
        var container = document.getElementById('wpt-abm-links-list');
        if (!container) return;

        container.innerHTML = '';

        for (var i = 0; i < customLinks.length; i++) {
            container.appendChild(buildLinkCard(i, customLinks[i]));
        }

        updateAddButtonState();
    }

    function buildLinkCard(index, link) {
        var card = document.createElement('div');
        card.className = 'wpt-abm-link-card';
        card.setAttribute('data-link-index', index);

        var roleKeys = Object.keys(wpRoles);
        var linkRoles = link.roles || [];

        var rolesHtml = '';
        for (var r = 0; r < roleKeys.length; r++) {
            var rk = roleKeys[r];
            var checked = linkRoles.indexOf(rk) !== -1 ? ' checked' : '';
            rolesHtml += '<label><input type="checkbox" class="wpt-abm-link-role-cb" value="' + esc(rk) + '"' + checked + '> ' + esc(wpRoles[rk]) + '</label>';
        }

        card.innerHTML =
            '<div class="wpt-abm-link-fields">' +
                '<div class="wpt-abm-link-field">' +
                    '<label>Title</label>' +
                    '<input type="text" class="wpt-abm-link-title" value="' + esc(link.title || '') + '" placeholder="e.g., Google Analytics">' +
                '</div>' +
                '<div class="wpt-abm-link-field">' +
                    '<label>URL</label>' +
                    '<input type="text" class="wpt-abm-link-url" value="' + esc(link.url || '') + '" placeholder="https://">' +
                '</div>' +
                '<div class="wpt-abm-link-field">' +
                    '<label>Icon</label>' +
                    '<div class="wpt-abm-link-icon-row">' +
                        '<input type="text" class="wpt-abm-link-icon" value="' + esc(link.icon || 'dashicons-admin-links') + '" placeholder="dashicons-admin-links">' +
                        '<a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener noreferrer">Browse Dashicons</a>' +
                    '</div>' +
                '</div>' +
                '<div class="wpt-abm-link-field">' +
                    '<label>Position</label>' +
                    '<select class="wpt-abm-link-position">' +
                        '<option value="left"' + (link.position !== 'right' ? ' selected' : '') + '>Left side</option>' +
                        '<option value="right"' + (link.position === 'right' ? ' selected' : '') + '>Right side</option>' +
                    '</select>' +
                '</div>' +
                '<div class="wpt-abm-link-field">' +
                    '<label>Open in new tab</label>' +
                    '<div class="wpt-abm-link-toggle-row">' +
                        '<label class="wpt-toggle-switch">' +
                            '<input type="checkbox" class="wpt-abm-link-newtab"' + (link.new_tab ? ' checked' : '') + '>' +
                            '<span class="wpt-toggle-track"></span>' +
                        '</label>' +
                        '<span class="wpt-abm-link-toggle-label">' + (link.new_tab ? 'Yes' : 'No') + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="wpt-abm-link-field full-width">' +
                    '<label>Visible to (leave empty for all roles)</label>' +
                    '<div class="wpt-abm-link-roles">' + rolesHtml + '</div>' +
                '</div>' +
            '</div>' +
            '<div class="wpt-abm-link-footer">' +
                '<button type="button" class="wpt-abm-link-delete">Delete Link</button>' +
            '</div>';

        // Bind events for this card.
        bindLinkCardEvents(card, index);

        return card;
    }

    function bindLinkCardEvents(card, index) {
        // Field change handlers — update customLinks array on any change.
        var titleInput = card.querySelector('.wpt-abm-link-title');
        var urlInput = card.querySelector('.wpt-abm-link-url');
        var iconInput = card.querySelector('.wpt-abm-link-icon');
        var posSelect = card.querySelector('.wpt-abm-link-position');
        var newTabCb = card.querySelector('.wpt-abm-link-newtab');
        var roleCbs = card.querySelectorAll('.wpt-abm-link-role-cb');
        var deleteBtn = card.querySelector('.wpt-abm-link-delete');
        var toggleLabel = card.querySelector('.wpt-abm-link-toggle-label');

        function syncLink() {
            var i = parseInt(card.getAttribute('data-link-index'), 10);
            if (!customLinks[i]) return;
            customLinks[i].title = titleInput.value;
            customLinks[i].url = urlInput.value;
            customLinks[i].icon = iconInput.value || 'dashicons-admin-links';
            customLinks[i].position = posSelect.value;
            customLinks[i].new_tab = newTabCb.checked;
            var roles = [];
            for (var r = 0; r < roleCbs.length; r++) {
                if (roleCbs[r].checked) roles.push(roleCbs[r].value);
            }
            customLinks[i].roles = roles;
            syncHiddenInput();
        }

        titleInput.addEventListener('input', syncLink);
        urlInput.addEventListener('input', syncLink);
        iconInput.addEventListener('input', syncLink);
        posSelect.addEventListener('change', syncLink);
        newTabCb.addEventListener('change', function () {
            if (toggleLabel) toggleLabel.textContent = this.checked ? 'Yes' : 'No';
            syncLink();
        });
        for (var r = 0; r < roleCbs.length; r++) {
            roleCbs[r].addEventListener('change', syncLink);
        }

        deleteBtn.addEventListener('click', function () {
            if (!confirm('Remove this custom link?')) return;
            var i = parseInt(card.getAttribute('data-link-index'), 10);
            customLinks.splice(i, 1);
            syncHiddenInput();
            renderCustomLinks();
        });
    }

    function handleAddLink() {
        if (customLinks.length >= MAX_LINKS) return;

        customLinks.push({
            title: '',
            url: '',
            icon: 'dashicons-admin-links',
            new_tab: false,
            position: 'left',
            roles: []
        });

        syncHiddenInput();
        renderCustomLinks();

        // Focus the title input of the new card.
        var container = document.getElementById('wpt-abm-links-list');
        if (container) {
            var cards = container.querySelectorAll('.wpt-abm-link-card');
            var last = cards[cards.length - 1];
            if (last) {
                var titleInput = last.querySelector('.wpt-abm-link-title');
                if (titleInput) titleInput.focus();
            }
        }
    }

    function updateAddButtonState() {
        var addBtn = document.getElementById('wpt-abm-add-link-btn');
        var maxMsg = document.getElementById('wpt-abm-links-max-msg');
        if (!addBtn) return;

        if (customLinks.length >= MAX_LINKS) {
            addBtn.style.display = 'none';
            if (maxMsg) maxMsg.style.display = '';
        } else {
            addBtn.style.display = '';
            if (maxMsg) maxMsg.style.display = 'none';
        }
    }

    /* ── Utility ──────────────────────────────────────────── */

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* ── Boot ─────────────────────────────────────────────── */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
