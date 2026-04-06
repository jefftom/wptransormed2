/**
 * WPTransformed Admin Dashboard
 * assets/admin/js/admin.js
 *
 * Dashboard page-specific JS. Global admin chrome (dark mode toggle,
 * sidebar injections, topbar) handled by admin-global.js.
 *
 * Handles:
 * 1. Module toggle (on/off) via AJAX
 * 2. Pill-tab category filtering (shows/hides category sections)
 * 3. Configure button → settings page
 * 4. Command palette (Ctrl+K) with search and keyboard nav
 * 5. Animated bento counters (data-count + data-suffix)
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
        initConfigureButtons();
        initPillTabs();
        initCommandPalette();
        setTimeout(animateCounters, 350);
    });

    /* ──────────────────────────────────────
       MODULE TOGGLES (AJAX)
    ────────────────────────────────────── */
    function initModuleToggles() {
        document.querySelectorAll('.wpt-module-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function(e) {
                e.stopPropagation();
                var moduleId = this.dataset.moduleId;
                var active = this.checked ? '1' : '0';
                var card = this.closest('.module-card');
                var inp = this;

                if (card) {
                    card.classList.toggle('disabled', !inp.checked);
                }

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
                        inp.checked = !inp.checked;
                        if (card) card.classList.toggle('disabled', !inp.checked);
                    } else {
                        updateCategoryCounts();
                        updateBentoCount();
                    }
                })
                .catch(function() {
                    inp.checked = !inp.checked;
                    if (card) card.classList.toggle('disabled', !inp.checked);
                });
            });

            /* Prevent label/toggle clicks from triggering card click */
            var label = toggle.closest('.toggle');
            if (label) {
                label.addEventListener('click', function(e) { e.stopPropagation(); });
            }
        });
    }

    function updateBentoCount() {
        var activeCount = document.querySelectorAll('.wpt-module-toggle:checked').length;
        var el = document.getElementById('wptActiveCount');
        if (el) el.textContent = activeCount;
    }

    function updateCategoryCounts() {
        var sections = document.querySelectorAll('.category-section');
        sections.forEach(function(section) {
            var total = section.querySelectorAll('.module-card').length;
            var active = section.querySelectorAll('.wpt-module-toggle:checked').length;
            var countEl = section.querySelector('.category-count');
            if (countEl) {
                countEl.textContent = active + ' of ' + total + ' active';
            }
        });
    }

    /* ──────────────────────────────────────
       CONFIGURE BUTTONS → SETTINGS PAGE
    ────────────────────────────────────── */
    function initConfigureButtons() {
        document.querySelectorAll('.mod-expand-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var url = this.dataset.url;
                if (url) window.location.href = url;
            });
        });

        /* Module card main area click → settings */
        document.querySelectorAll('.mod-main').forEach(function(main) {
            main.addEventListener('click', function(e) {
                if (e.target.closest('.toggle')) return;
                var card = this.closest('.module-card');
                var url = card ? card.dataset.settingsUrl : null;
                if (url) window.location.href = url;
            });
        });
    }

    /* ──────────────────────────────────────
       PILL TAB FILTERING (category sections)
    ────────────────────────────────────── */
    function initPillTabs() {
        var tabs = document.querySelectorAll('.pill-tab');
        var sections = document.querySelectorAll('.category-section');

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var cat = this.dataset.category;

                tabs.forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');

                sections.forEach(function(section) {
                    if (cat === 'all' || section.dataset.category === cat) {
                        section.style.display = '';
                    } else {
                        section.style.display = 'none';
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
