/**
 * WPTransformed — Dark Mode Toggle
 *
 * Admin bar toggle, AJAX persistence, keyboard shortcut.
 * No jQuery dependency.
 */
(function () {
    'use strict';

    var config = window.wptDarkMode || {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce = config.nonce || '';
    var enableShortcut = config.enableShortcut !== false;
    var includeSidebar = !!config.includeSidebar;

    /**
     * Is dark mode currently active?
     */
    function isDark() {
        return document.documentElement.classList.contains('wpt-dark');
    }

    /**
     * Toggle dark mode and persist via AJAX.
     */
    function toggle() {
        var html = document.documentElement;

        // Enable transitions for smooth toggle.
        html.classList.add('wpt-dark-transitions');

        if (isDark()) {
            html.classList.remove('wpt-dark');
            html.classList.remove('wpt-dark-sidebar');
            persist('light');
            updateToggleIcon(false);
        } else {
            html.classList.add('wpt-dark');
            if (includeSidebar) {
                html.classList.add('wpt-dark-sidebar');
            }
            persist('dark');
            updateToggleIcon(true);
        }

        // Remove transitions class after animation completes.
        setTimeout(function () {
            html.classList.remove('wpt-dark-transitions');
        }, 300);
    }

    /**
     * Update the admin bar toggle icon.
     */
    function updateToggleIcon(dark) {
        var node = document.querySelector('#wp-admin-bar-wpt-dark-mode-toggle > .ab-item');
        if (node) {
            node.textContent = dark ? '\u2600\uFE0F' : '\uD83C\uDF19';
        }
    }

    /**
     * Save preference to user meta via AJAX.
     */
    function persist(mode) {
        if (!ajaxUrl || !nonce) {
            return;
        }

        var data = new FormData();
        data.append('action', 'wpt_toggle_dark_mode');
        data.append('nonce', nonce);
        data.append('mode', mode);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        });
    }

    // Bind admin bar toggle click.
    document.addEventListener('click', function (e) {
        var toggle_node = e.target.closest('#wp-admin-bar-wpt-dark-mode-toggle');
        if (toggle_node) {
            e.preventDefault();
            toggle();
        }
    });

    // Keyboard shortcut: Ctrl+Alt+D (Cmd+Alt+D on Mac).
    if (enableShortcut) {
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.altKey && (e.key === 'd' || e.key === 'D')) {
                e.preventDefault();
                toggle();
            }
        });
    }
})();
