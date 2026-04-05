/**
 * WPTransformed — Editor Dashboard JS
 * assets/admin/js/editor-dashboard.js
 *
 * Handles animated stat counters on the Editor Dashboard.
 * Source: wp-transformation-editor.html <script>
 *
 * No jQuery dependency — vanilla JS.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var dashboard = document.querySelector('.wpt-editor-dashboard');
        if (!dashboard) return;

        /* Animated counters — fire after 300ms to let fadeUp complete */
        setTimeout(function() {
            var els = dashboard.querySelectorAll('[data-count]');
            els.forEach(function(el) {
                var target = parseInt(el.getAttribute('data-count'), 10);
                if (isNaN(target) || target === 0) {
                    el.textContent = '0';
                    return;
                }
                var current = 0;
                var step = Math.max(1, Math.floor(target / 25));
                var interval = setInterval(function() {
                    current += step;
                    if (current >= target) {
                        current = target;
                        clearInterval(interval);
                    }
                    el.textContent = current;
                }, 35);
            });
        }, 300);
    });
})();
