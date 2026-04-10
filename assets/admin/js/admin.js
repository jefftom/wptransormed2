/**
 * WPTransformed Admin Dashboard
 * assets/admin/js/admin.js
 *
 * Dashboard page-specific JS. Global admin chrome (dark mode toggle,
 * sidebar injections, topbar) handled by admin-global.js.
 *
 * Handles:
 * 1. Sub-module toggles (AJAX via wpt_toggle_module)
 * 2. Parent toggle → cascade activate/deactivate of all sub-modules
 *    (Stage 3 cascades via sub-toggle events; Stage 4 will swap to a
 *    batch wpt_toggle_parent endpoint)
 * 3. Expand/collapse of parent cards (Session 3)
 * 4. Pill-tab category filtering across parent cards
 * 5. Command palette (Ctrl+K) with search and keyboard nav
 * 6. Animated bento counters
 *
 * No jQuery dependency — vanilla JS.
 */
(function() {
    'use strict';

    var dashboard, cmdOverlay, cmdInput, cmdResults, selectedIdx;

    document.addEventListener('DOMContentLoaded', function() {
        dashboard = document.querySelector('.wpt-dashboard');
        if (!dashboard) return;

        initModuleToggles();
        initParentToggles();
        initParentCardInteractions();
        initPillTabs();
        initCommandPalette();
        setTimeout(animateCounters, 350);
    });

    /* ──────────────────────────────────────
       SUB-MODULE TOGGLES (AJAX via wpt_toggle_module)
       Parent toggles are handled separately in initParentToggles.
    ────────────────────────────────────── */
    function initModuleToggles() {
        document.querySelectorAll('.wpt-module-toggle').forEach(function(toggle) {
            /* Skip parent toggles — initParentToggles() handles those. */
            if (toggle.classList.contains('wpt-parent-toggle')) return;

            toggle.addEventListener('change', function(e) {
                e.stopPropagation();

                var moduleId = this.dataset.moduleId;
                var active   = this.checked ? '1' : '0';
                var inp      = this;
                var parentCard = this.closest('.parent-card');
                var subItem = this.closest('.submodule-item');

                /* Optimistic UI: reflect the new state immediately. */
                if (subItem) subItem.classList.toggle('disabled', !inp.checked);
                if (parentCard) syncParentCard(parentCard);

                var formData = new FormData();
                formData.append('action', 'wpt_toggle_module');
                formData.append('module_id', moduleId);
                formData.append('active', active);
                formData.append('nonce', wptAdmin.nonce);

                fetch(wptAdmin.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        /* Revert optimistic UI on server error. */
                        inp.checked = !inp.checked;
                        if (subItem) subItem.classList.toggle('disabled', !inp.checked);
                        if (parentCard) syncParentCard(parentCard);
                    } else {
                        updateBentoCount();
                    }
                })
                .catch(function() {
                    inp.checked = !inp.checked;
                    if (subItem) subItem.classList.toggle('disabled', !inp.checked);
                    if (parentCard) syncParentCard(parentCard);
                });
            });

            /* Prevent label click from bubbling to the card. */
            var label = toggle.closest('.toggle, .sub-toggle');
            if (label) {
                label.addEventListener('click', function(e) { e.stopPropagation(); });
            }
        });
    }

    /* ──────────────────────────────────────
       PARENT TOGGLES
       Stage 3: cascade via client-side dispatch of sub-toggle change
       events, which re-use the existing wpt_toggle_module AJAX path.
       This works but makes N HTTP calls for a parent with N sub-modules.
       Stage 4 will add a wpt_toggle_parent batch endpoint that does the
       whole operation in a single round-trip.
    ────────────────────────────────────── */
    function initParentToggles() {
        document.querySelectorAll('.wpt-parent-toggle').forEach(function(parentToggle) {
            parentToggle.addEventListener('change', function(e) {
                e.stopPropagation();

                var card = this.closest('.parent-card');
                if (!card) return;

                /* Disabled Pro toggle — revert and bail. */
                if (this.disabled) {
                    this.checked = !this.checked;
                    return;
                }

                var shouldActivate = this.checked;
                var subToggles = card.querySelectorAll('.wpt-sub-module-toggle');

                /* Flip each sub-toggle that doesn't already match the new
                   state and dispatch a change event — that triggers the
                   existing wpt_toggle_module AJAX handler installed by
                   initModuleToggles. Each sub-toggle fires one request;
                   Stage 4 will replace this with a single batch call. */
                subToggles.forEach(function(sub) {
                    if (sub.checked !== shouldActivate) {
                        sub.checked = shouldActivate;
                        sub.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });

                /* After dispatching, sync the parent card state in case
                   the sub-toggles fired synchronously already. */
                syncParentCard(card);
            });

            var label = parentToggle.closest('.toggle');
            if (label) {
                label.addEventListener('click', function(e) { e.stopPropagation(); });
            }
        });
    }

    /* ──────────────────────────────────────
       PARENT CARD INTERACTIONS
       - Expand/collapse the sub-modules panel on expand-button click
       - Don't hijack mod-main clicks on parent cards (no card-level URL)
    ────────────────────────────────────── */
    function initParentCardInteractions() {
        document.querySelectorAll('.parent-card .mod-expand-btn').forEach(function(btn) {
            /* APP parents use an <a class="mod-app-link">; let native
               navigation handle them. */
            if (btn.classList.contains('mod-app-link')) return;

            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var card = this.closest('.parent-card');
                if (!card) return;

                var isExpanded = card.classList.toggle('expanded');
                this.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            });
        });
    }

    /* ──────────────────────────────────────
       SYNC PARENT CARD STATE
       Re-compute a parent card's UI state based on its sub-toggles.
       Called after any sub-toggle change or AJAX success/failure.
    ────────────────────────────────────── */
    function syncParentCard(card) {
        if (!card || !card.classList.contains('parent-card')) return;

        var subToggles = card.querySelectorAll('.wpt-sub-module-toggle');
        var totalSubs  = subToggles.length;
        var activeSubs = card.querySelectorAll('.wpt-sub-module-toggle:checked').length;

        /* Parent toggle reflects "any sub active", not a server write —
           update .checked directly without firing change events. */
        var parentToggle = card.querySelector('.wpt-parent-toggle');
        if (parentToggle) {
            parentToggle.checked = activeSubs > 0;
        }

        /* Card-level disabled: greys out the card when no subs are active. */
        card.classList.toggle('disabled', activeSubs === 0);

        /* Update the "X/Y sub-modules" text in the footer. */
        var footerCount = card.querySelector('.wpt-sub-count-text');
        if (footerCount) {
            footerCount.textContent = activeSubs + '/' + totalSubs + ' sub-modules';
        }

        /* Update the "X of Y enabled" text inside the expanded panel. */
        var panelCount = card.querySelector('.wpt-sub-count-text-expanded');
        if (panelCount) {
            panelCount.textContent = activeSubs + ' of ' + totalSubs + ' enabled';
        }

        /* Update data attrs so downstream code reading them stays in sync. */
        card.dataset.activeSubCount = String(activeSubs);
    }

    function updateBentoCount() {
        /* Bento "Active Modules" — counts only real sub-module toggles,
           excludes .wpt-parent-toggle which are presentation-layer only. */
        var activeCount = 0;
        document.querySelectorAll('.wpt-module-toggle:checked').forEach(function(t) {
            if (!t.classList.contains('wpt-parent-toggle')) activeCount++;
        });
        var el = document.getElementById('wptActiveCount');
        if (el) el.textContent = activeCount;
    }

    /* ──────────────────────────────────────
       PILL TAB FILTERING — parent cards by category
    ────────────────────────────────────── */
    function initPillTabs() {
        var tabs  = document.querySelectorAll('.pill-tab');
        var cards = document.querySelectorAll('.parent-card');

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var cat = this.dataset.category;

                tabs.forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');

                cards.forEach(function(card) {
                    if (cat === 'all' || card.dataset.category === cat) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
    }

    /* ──────────────────────────────────────
       SIDEBAR SEARCH → COMMAND PALETTE
    ────────────────────────────────────── */
    /* ──────────────────────────────────────
       COMMAND PALETTE
    ────────────────────────────────────── */
    function initCommandPalette() {
        cmdOverlay = document.getElementById('wptCmdOverlay');
        cmdInput = document.getElementById('wptCmdInput');
        cmdResults = document.getElementById('wptCmdResults');
        if (!cmdOverlay || !cmdInput) return;

        selectedIdx = -1;

        document.addEventListener('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                toggleCmdPalette();
            }
            if (e.key === 'Escape' && cmdOverlay.classList.contains('open')) {
                closeCmdPalette();
            }
        });

        cmdOverlay.addEventListener('click', function(e) {
            if (e.target === cmdOverlay) closeCmdPalette();
        });

        cmdInput.addEventListener('input', function() {
            renderCmdResults(this.value.toLowerCase().trim());
        });

        cmdInput.addEventListener('keydown', function(e) {
            var items = cmdResults.querySelectorAll('.wpt-cmd-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIdx = Math.min(selectedIdx + 1, items.length - 1);
                updateCmdSelection(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIdx = Math.max(selectedIdx - 1, 0);
                updateCmdSelection(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedIdx >= 0 && items[selectedIdx]) {
                    var url = items[selectedIdx].dataset.url;
                    if (url) window.location.href = url;
                }
            }
        });

        renderCmdResults('');
    }

    function toggleCmdPalette() {
        if (cmdOverlay.classList.contains('open')) closeCmdPalette();
        else openCmdPalette();
    }

    function openCmdPalette() {
        if (!cmdOverlay) return;
        cmdOverlay.classList.add('open');
        if (cmdInput) {
            cmdInput.value = '';
            renderCmdResults('');
            setTimeout(function() { cmdInput.focus(); }, 100);
        }
        selectedIdx = -1;
    }

    function closeCmdPalette() {
        if (!cmdOverlay) return;
        cmdOverlay.classList.remove('open');
        if (cmdInput) cmdInput.value = '';
        selectedIdx = -1;
    }

    function cmdItemHtml(url, iconClass, colorClass, title, desc) {
        return '<div class="wpt-cmd-item" data-url="' + esc(url) + '">' +
            '<div class="wpt-cmd-icon ' + esc(colorClass) + '"><i class="fas ' + esc(iconClass) + '"></i></div>' +
            '<div class="wpt-cmd-text"><span>' + esc(title) + '</span><small>' + esc(desc) + '</small></div>' +
            '</div>';
    }

    function renderCmdResults(query) {
        if (!cmdResults || typeof wptAdmin === 'undefined' || !wptAdmin.modules) return;

        var html = '';
        var modules = wptAdmin.modules;

        var quickActions = [
            { title: 'New Post', desc: 'Create a new blog post', icon: 'fa-plus', color: 'blue', url: wptAdmin.adminUrl + 'post-new.php' },
            { title: 'New Page', desc: 'Create a new page', icon: 'fa-plus', color: 'green', url: wptAdmin.adminUrl + 'post-new.php?post_type=page' },
            { title: 'Upload Media', desc: 'Upload images and files', icon: 'fa-cloud-upload-alt', color: 'violet', url: wptAdmin.adminUrl + 'media-new.php' }
        ];

        var navPages = [
            { title: 'All Posts', desc: 'View and manage posts', icon: 'fa-file-alt', color: 'blue', url: wptAdmin.adminUrl + 'edit.php' },
            { title: 'All Pages', desc: 'View and manage pages', icon: 'fa-copy', color: 'green', url: wptAdmin.adminUrl + 'edit.php?post_type=page' },
            { title: 'Media Library', desc: 'Browse media files', icon: 'fa-images', color: 'violet', url: wptAdmin.adminUrl + 'upload.php' },
            { title: 'Plugins', desc: 'Manage installed plugins', icon: 'fa-plug', color: 'amber', url: wptAdmin.adminUrl + 'plugins.php' },
            { title: 'Settings', desc: 'General WordPress settings', icon: 'fa-cog', color: 'rose', url: wptAdmin.adminUrl + 'options-general.php' },
            { title: 'Users', desc: 'Manage user accounts', icon: 'fa-users', color: 'blue', url: wptAdmin.adminUrl + 'users.php' }
        ];

        var matchedActions = quickActions;
        var matchedModules = modules;
        var matchedNav = navPages;

        if (query) {
            matchedActions = quickActions.filter(function(a) {
                return a.title.toLowerCase().indexOf(query) !== -1 ||
                       a.desc.toLowerCase().indexOf(query) !== -1;
            });
            matchedModules = modules.filter(function(m) {
                return m.title.toLowerCase().indexOf(query) !== -1 ||
                       m.category.toLowerCase().indexOf(query) !== -1;
            });
            matchedNav = navPages.filter(function(p) {
                return p.title.toLowerCase().indexOf(query) !== -1 ||
                       p.desc.toLowerCase().indexOf(query) !== -1;
            });
        }

        if (matchedActions.length) {
            html += '<div class="wpt-cmd-group">';
            html += '<div class="wpt-cmd-group-label">Quick Actions</div>';
            matchedActions.forEach(function(a) {
                html += cmdItemHtml(a.url, a.icon, a.color, a.title, a.desc);
            });
            html += '</div>';
        }

        if (matchedModules.length) {
            html += '<div class="wpt-cmd-group">';
            html += '<div class="wpt-cmd-group-label">Modules</div>';
            matchedModules.slice(0, 8).forEach(function(m) {
                var statusDesc = m.active ? 'Currently enabled' : 'Currently disabled';
                var colorClass = m.active ? 'blue' : '';
                html += cmdItemHtml(m.settingsUrl, m.icon, colorClass, m.title, statusDesc);
            });
            html += '</div>';
        }

        if (matchedNav.length) {
            html += '<div class="wpt-cmd-group">';
            html += '<div class="wpt-cmd-group-label">Navigate</div>';
            matchedNav.forEach(function(p) {
                html += cmdItemHtml(p.url, p.icon, p.color, p.title, p.desc);
            });
            html += '</div>';
        }

        if (!html) {
            html = '<div class="wpt-cmd-group"><div class="wpt-cmd-group-label">No results found</div></div>';
        }

        cmdResults.innerHTML = html;
        selectedIdx = -1;

        cmdResults.querySelectorAll('.wpt-cmd-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var url = this.dataset.url;
                if (url) window.location.href = url;
            });
        });
    }

    function updateCmdSelection(items) {
        items.forEach(function(item, i) {
            item.classList.toggle('selected', i === selectedIdx);
        });
        if (items[selectedIdx]) {
            items[selectedIdx].scrollIntoView({ block: 'nearest' });
        }
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /* ──────────────────────────────────────
       ANIMATED BENTO COUNTERS
       Source: wp-transformation-final.html animateCounters()
    ────────────────────────────────────── */
    function animateCounters() {
        document.querySelectorAll('.bento-value[data-count]').forEach(function(el) {
            var target = parseInt(el.dataset.count, 10);
            var suffix = el.dataset.suffix || '';
            if (isNaN(target) || target === 0) {
                el.textContent = '0' + suffix;
                return;
            }
            var current = 0;
            var step = Math.max(1, Math.floor(target / 35));
            var interval = setInterval(function() {
                current += step;
                if (current >= target) {
                    current = target;
                    clearInterval(interval);
                }
                el.textContent = current + suffix;
            }, 28);
        });
    }

})();
