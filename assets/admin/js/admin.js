/**
 * WPTransformed Admin Dashboard
 * assets/admin/js/admin.js
 *
 * Handles:
 * 1. Module toggle (on/off) via AJAX
 * 2. Dark/light mode toggle with localStorage
 * 3. Command palette (Ctrl+K) with search and keyboard nav
 * 4. Category pill-tab filtering
 * 5. Module card click → settings page navigation
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

        initDarkMode();
        initModuleToggles();
        initModuleCardClicks();
        initPillTabs();
        initCommandPalette();
        animateCounters();
    });

    /* ──────────────────────────────────────
       DARK MODE
    ────────────────────────────────────── */
    function initDarkMode() {
        var saved = localStorage.getItem('wpt_dark_mode');
        if (saved === '1') {
            dashboard.classList.add('wpt-dark');
            updateThemeIcon();
        }

        var btn = document.getElementById('wptThemeToggle');
        if (btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                dashboard.classList.toggle('wpt-dark');
                var isDark = dashboard.classList.contains('wpt-dark');
                localStorage.setItem('wpt_dark_mode', isDark ? '1' : '0');
                updateThemeIcon();
            });
        }
    }

    function updateThemeIcon() {
        var icon = document.getElementById('wptThemeIcon');
        if (!icon) return;
        var isDark = dashboard.classList.contains('wpt-dark');
        icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    /* ──────────────────────────────────────
       MODULE TOGGLES (AJAX)
    ────────────────────────────────────── */
    function initModuleToggles() {
        document.querySelectorAll('.wpt-module-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function(e) {
                e.stopPropagation(); // Don't trigger card click
                var moduleId = this.dataset.moduleId;
                var active = this.checked ? '1' : '0';
                var card = this.closest('.wpt-mod-card');
                var inp = this;

                // Optimistic UI
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
                        revert(inp, card);
                        console.error('Toggle failed:', data.data);
                    } else {
                        updateBentoCount();
                    }
                })
                .catch(function() {
                    revert(inp, card);
                });
            });

            // Prevent label clicks from navigating to settings
            toggle.closest('.wpt-toggle').addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    }

    function revert(inp, card) {
        inp.checked = !inp.checked;
        if (card) card.classList.toggle('disabled', !inp.checked);
    }

    function updateBentoCount() {
        var activeCount = document.querySelectorAll('.wpt-module-toggle:checked').length;
        var el = document.getElementById('wptActiveCount');
        if (el) el.textContent = activeCount;
    }

    /* ──────────────────────────────────────
       MODULE CARD CLICKS → SETTINGS
    ────────────────────────────────────── */
    function initModuleCardClicks() {
        document.querySelectorAll('.wpt-mod-main').forEach(function(main) {
            main.addEventListener('click', function(e) {
                // Don't navigate if clicking the toggle
                if (e.target.closest('.wpt-toggle')) return;
                var card = this.closest('.wpt-mod-card');
                var url = card ? card.dataset.settingsUrl : null;
                if (url) window.location.href = url;
            });
        });
    }

    /* ──────────────────────────────────────
       CATEGORY PILL TABS
    ────────────────────────────────────── */
    function initPillTabs() {
        var tabs = document.querySelectorAll('.wpt-pill-tab');
        var cards = document.querySelectorAll('.wpt-mod-card');

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var cat = this.dataset.category;

                // Update active tab
                tabs.forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');

                // Filter cards
                var visibleIdx = 0;
                cards.forEach(function(card) {
                    if (cat === 'all' || card.dataset.category === cat) {
                        card.style.display = '';
                        card.style.animationDelay = (visibleIdx * 0.035) + 's';
                        card.style.animation = 'none';
                        card.offsetHeight; // force reflow
                        card.style.animation = '';
                        visibleIdx++;
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
    }

    /* ──────────────────────────────────────
       COMMAND PALETTE
    ────────────────────────────────────── */
    function initCommandPalette() {
        cmdOverlay = document.getElementById('wptCmdOverlay');
        cmdInput = document.getElementById('wptCmdInput');
        cmdResults = document.getElementById('wptCmdResults');
        if (!cmdOverlay || !cmdInput) return;

        selectedIdx = -1;

        // Keyboard shortcut: Ctrl+K / Cmd+K
        document.addEventListener('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                toggleCmdPalette();
            }
            if (e.key === 'Escape' && cmdOverlay.classList.contains('open')) {
                closeCmdPalette();
            }
        });

        // Search button in topbar
        var searchBtn = document.getElementById('wptSearchTrigger');
        if (searchBtn) {
            searchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleCmdPalette();
            });
        }

        // Close on overlay click
        cmdOverlay.addEventListener('click', function(e) {
            if (e.target === cmdOverlay) closeCmdPalette();
        });

        // Filter on input
        cmdInput.addEventListener('input', function() {
            renderCmdResults(this.value.toLowerCase().trim());
        });

        // Keyboard navigation within results
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

        // Initial render
        renderCmdResults('');
    }

    function toggleCmdPalette() {
        if (cmdOverlay.classList.contains('open')) {
            closeCmdPalette();
        } else {
            openCmdPalette();
        }
    }

    function openCmdPalette() {
        cmdOverlay.classList.add('open');
        cmdInput.value = '';
        selectedIdx = -1;
        renderCmdResults('');
        setTimeout(function() { cmdInput.focus(); }, 100);
    }

    function closeCmdPalette() {
        cmdOverlay.classList.remove('open');
        cmdInput.value = '';
        selectedIdx = -1;
    }

    function renderCmdResults(query) {
        if (!cmdResults || !wptAdmin.modules) return;

        var html = '';
        var modules = wptAdmin.modules;
        var wpPages = [
            { title: 'All Posts', desc: 'View and manage posts', icon: 'fa-file-alt', color: 'blue', url: wptAdmin.adminUrl + 'edit.php' },
            { title: 'Add New Post', desc: 'Create a new blog post', icon: 'fa-plus', color: 'blue', url: wptAdmin.adminUrl + 'post-new.php' },
            { title: 'All Pages', desc: 'View and manage pages', icon: 'fa-copy', color: 'green', url: wptAdmin.adminUrl + 'edit.php?post_type=page' },
            { title: 'Media Library', desc: 'Manage images and files', icon: 'fa-images', color: 'amber', url: wptAdmin.adminUrl + 'upload.php' },
            { title: 'Plugins', desc: 'Manage installed plugins', icon: 'fa-plug', color: 'violet', url: wptAdmin.adminUrl + 'plugins.php' },
            { title: 'Settings', desc: 'General WordPress settings', icon: 'fa-cog', color: 'blue', url: wptAdmin.adminUrl + 'options-general.php' }
        ];

        // Filter modules
        var matchedModules = modules;
        var matchedPages = wpPages;

        if (query) {
            matchedModules = modules.filter(function(m) {
                return m.title.toLowerCase().indexOf(query) !== -1 ||
                       m.category.toLowerCase().indexOf(query) !== -1;
            });
            matchedPages = wpPages.filter(function(p) {
                return p.title.toLowerCase().indexOf(query) !== -1 ||
                       p.desc.toLowerCase().indexOf(query) !== -1;
            });
        }

        // Modules section
        if (matchedModules.length) {
            html += '<div class="wpt-cmd-group-label">Modules</div>';
            matchedModules.slice(0, 8).forEach(function(m) {
                html += '<div class="wpt-cmd-item" data-url="' + esc(m.settingsUrl) + '">' +
                    '<div class="cmd-icon ' + esc(m.color) + '"><i class="fas ' + esc(m.icon) + '"></i></div>' +
                    '<div class="cmd-text"><span>' + esc(m.title) + '</span><small>' + esc(m.desc) + '</small></div>' +
                    '</div>';
            });
        }

        // WP Pages section
        if (matchedPages.length) {
            html += '<div class="wpt-cmd-group-label">Navigate</div>';
            matchedPages.forEach(function(p) {
                html += '<div class="wpt-cmd-item" data-url="' + esc(p.url) + '">' +
                    '<div class="cmd-icon ' + esc(p.color) + '"><i class="fas ' + esc(p.icon) + '"></i></div>' +
                    '<div class="cmd-text"><span>' + esc(p.title) + '</span><small>' + esc(p.desc) + '</small></div>' +
                    '</div>';
            });
        }

        if (!html) {
            html = '<div class="wpt-cmd-group-label">No results found</div>';
        }

        cmdResults.innerHTML = html;
        selectedIdx = -1;

        // Add click handlers
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
    ────────────────────────────────────── */
    function animateCounters() {
        document.querySelectorAll('.bento-value[data-count]').forEach(function(el) {
            var target = parseInt(el.dataset.count, 10);
            var suffix = el.dataset.suffix || '';
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
