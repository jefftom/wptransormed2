/**
 * WPTransformed — Global Admin JS
 * assets/admin/js/admin-global.js
 *
 * Handles:
 * 1. Dark/light mode toggle (body.wpt-dark) + localStorage + AJAX user_meta
 * 2. Sidebar injections: logo, search bar, section labels, upgrade card, user profile
 * 3. Topbar injection: page title, breadcrumb, theme toggle, notification bell, avatar
 *
 * No jQuery dependency — vanilla JS.
 * Loaded on ALL admin pages when WPTransformed is active.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        if (!document.body.classList.contains('wpt-admin')) return;

        /* Add class to <html> for toolbar height override */
        document.documentElement.classList.add('wpt-active');

        initGlobalDarkMode();
        injectSidebarElements();
        enhanceSectionLabels();
        injectTopbarContent();
    });

    /* ──────────────────────────────────────
       DARK MODE (Global)
    ────────────────────────────────────── */
    function initGlobalDarkMode() {
        /* Priority: localStorage → server-side (body class already set by PHP) */
        var saved = localStorage.getItem('wpt_dark_mode');
        if (saved === '1' && !document.body.classList.contains('wpt-dark')) {
            document.body.classList.add('wpt-dark');
        } else if (saved === '0' && document.body.classList.contains('wpt-dark')) {
            document.body.classList.remove('wpt-dark');
        }
    }

    function toggleDarkMode() {
        document.body.classList.toggle('wpt-dark');
        var isDark = document.body.classList.contains('wpt-dark');
        localStorage.setItem('wpt_dark_mode', isDark ? '1' : '0');
        updateGlobalThemeIcon();

        /* Persist to user_meta via AJAX */
        if (typeof wptGlobal !== 'undefined') {
            var fd = new FormData();
            fd.append('action', 'wpt_save_dark_mode');
            fd.append('dark_mode', isDark ? '1' : '0');
            fd.append('nonce', wptGlobal.nonce);
            fetch(wptGlobal.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
        }
    }

    function updateGlobalThemeIcon() {
        var icon = document.getElementById('wptGlobalThemeIcon');
        if (!icon) return;
        var isDark = document.body.classList.contains('wpt-dark');
        icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    /* ──────────────────────────────────────
       SIDEBAR INJECTIONS
    ────────────────────────────────────── */
    function injectSidebarElements() {
        var wrap = document.getElementById('adminmenuwrap');
        if (!wrap) return;

        var menu = document.getElementById('adminmenu');
        if (!menu) return;

        var g = typeof wptGlobal !== 'undefined' ? wptGlobal : {};

        /* 1. Logo area — insert before #adminmenu */
        var logo = document.createElement('div');
        logo.className = 'wpt-sidebar-logo';
        logo.innerHTML =
            '<div class="wpt-logo-mark"><i class="fas fa-bolt"></i></div>' +
            '<h1>WPTransformed<small>v' + esc(g.version || '1.0') + '</small></h1>';
        wrap.insertBefore(logo, menu);

        /* 2. Search bar — insert after logo, before #adminmenu */
        var search = document.createElement('div');
        search.className = 'wpt-sidebar-search';
        search.setAttribute('role', 'button');
        search.setAttribute('tabindex', '0');
        search.innerHTML =
            '<i class="fas fa-search"></i>' +
            '<span>Search\u2026</span>' +
            '<kbd>' + (navigator.platform.indexOf('Mac') > -1 ? '\u2318K' : 'Ctrl+K') + '</kbd>';
        search.addEventListener('click', function() {
            /* Trigger command palette (Session 6). For now, dispatch custom event. */
            document.dispatchEvent(new CustomEvent('wpt-open-palette'));
            /* Also check if WPT dashboard palette exists */
            var overlay = document.getElementById('wptCmdOverlay');
            if (overlay) {
                overlay.classList.add('open');
                var inp = document.getElementById('wptCmdInput');
                if (inp) inp.focus();
            }
        });
        search.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                search.click();
            }
        });
        wrap.insertBefore(search, menu);

        /* 3. Footer (upgrade card + user profile) — append after #adminmenu */
        var footer = document.createElement('div');
        footer.className = 'wpt-sidebar-footer';

        /* Upgrade card */
        var upgrade = document.createElement('div');
        upgrade.className = 'wpt-sidebar-upgrade';
        upgrade.innerHTML =
            '<p>Unlock Pro Features</p>' +
            '<small>60+ extra modules, priority support, and white-labeling.</small>';
        var upgradeBtn = document.createElement('button');
        upgradeBtn.textContent = 'Upgrade \u2014 $99/yr';
        upgradeBtn.addEventListener('click', function() {
            window.open('https://wptransformed.com/pro', '_blank', 'noopener');
        });
        upgrade.appendChild(upgradeBtn);

        /* User profile */
        var user = document.createElement('div');
        user.className = 'wpt-sidebar-user';
        user.innerHTML =
            '<div class="wpt-sidebar-avatar">' + esc(g.userInitials || 'U') + '</div>' +
            '<div class="wpt-sidebar-user-info">' +
                '<span>' + esc(g.userName || 'User') + '</span>' +
                '<small>' + esc(g.userRole || 'Administrator') + '</small>' +
            '</div>';
        user.addEventListener('click', function() {
            window.location.href = g.profileUrl || '/wp-admin/profile.php';
        });

        footer.appendChild(upgrade);
        footer.appendChild(user);
        wrap.appendChild(footer);
    }

    /* ──────────────────────────────────────
       SECTION LABELS
    ────────────────────────────────────── */
    function enhanceSectionLabels() {
        var seps = document.querySelectorAll('#adminmenu li.wpt-section-sep');
        seps.forEach(function(sep) {
            var id = sep.id || '';
            var label = id.replace('wpt-sep-', '').toUpperCase();
            if (label) {
                sep.innerHTML = '<div class="wpt-nav-label">' + esc(label) + '</div>';
            }
        });
    }

    /* ──────────────────────────────────────
       TOPBAR INJECTION
    ────────────────────────────────────── */
    function injectTopbarContent() {
        var bar = document.getElementById('wpadminbar');
        if (!bar) return;

        var g = typeof wptGlobal !== 'undefined' ? wptGlobal : {};
        var isDark = document.body.classList.contains('wpt-dark');
        var themeIconClass = isDark ? 'fas fa-sun' : 'fas fa-moon';

        var topbar = document.createElement('div');
        topbar.className = 'wpt-topbar-inner';
        topbar.innerHTML =
            '<div class="wpt-topbar-left">' +
                '<span class="wpt-topbar-title">' + esc(g.pageTitle || 'Dashboard') + '</span>' +
                '<span class="wpt-topbar-sep"></span>' +
                '<span class="wpt-topbar-crumb">' + esc(g.pageCrumb || 'Overview') + '</span>' +
            '</div>' +
            '<div class="wpt-topbar-right">' +
                '<button class="wpt-tb-btn" id="wptGlobalThemeToggle" title="Toggle theme">' +
                    '<i class="' + themeIconClass + '" id="wptGlobalThemeIcon"></i>' +
                '</button>' +
                '<button class="wpt-tb-btn" title="Notifications">' +
                    '<i class="fas fa-bell"></i>' +
                    '<span class="wpt-notif-dot"></span>' +
                '</button>' +
                '<div class="wpt-tb-avatar">' + esc(g.userInitials || 'U') + '</div>' +
            '</div>';

        bar.appendChild(topbar);

        /* Theme toggle click */
        var themeBtn = document.getElementById('wptGlobalThemeToggle');
        if (themeBtn) {
            themeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleDarkMode();
            });
        }

        /* Avatar click → profile */
        var avatar = topbar.querySelector('.wpt-tb-avatar');
        if (avatar) {
            avatar.addEventListener('click', function() {
                window.location.href = g.profileUrl || '/wp-admin/profile.php';
            });
        }
    }

    /* ──────────────────────────────────────
       GLOBAL KEYBOARD SHORTCUTS
    ────────────────────────────────────── */
    document.addEventListener('keydown', function(e) {
        if (!document.body.classList.contains('wpt-admin')) return;

        /* Ctrl+K / Cmd+K — open command palette */
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            document.dispatchEvent(new CustomEvent('wpt-open-palette'));
            var overlay = document.getElementById('wptCmdOverlay');
            if (overlay) {
                overlay.classList.add('open');
                var inp = document.getElementById('wptCmdInput');
                if (inp) inp.focus();
            }
        }
    });

    /* ──────────────────────────────────────
       UTILITY
    ────────────────────────────────────── */
    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})();
