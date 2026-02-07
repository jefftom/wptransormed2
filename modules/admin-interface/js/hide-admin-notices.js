/**
 * Hide Admin Notices — Toggle + Dismiss All
 * modules/admin-interface/js/hide-admin-notices.js
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var bar = document.querySelector('.wpt-notice-bar');
        var panel = document.querySelector('.wpt-notice-panel');
        if (!bar || !panel) return;

        var toggleBtn = bar.querySelector('.wpt-notice-toggle');

        // Toggle panel on bar click
        bar.addEventListener('click', function() {
            var hidden = panel.style.display === 'none';
            panel.style.display = hidden ? '' : 'none';
            if (toggleBtn) {
                toggleBtn.textContent = hidden ? wptHideNoticesI18n.hide : wptHideNoticesI18n.show;
            }
            bar.classList.toggle('wpt-notice-bar-expanded', hidden);
        });

        // Dismiss All button
        var dismissBtn = panel.querySelector('.wpt-dismiss-all');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var buttons = panel.querySelectorAll('.notice.is-dismissible .notice-dismiss');
                buttons.forEach(function(btn) { btn.click(); });

                // Check if any notices remain after dismissal
                setTimeout(function() {
                    var remaining = panel.querySelectorAll('.notice');
                    if (remaining.length === 0) {
                        bar.style.display = 'none';
                        panel.style.display = 'none';
                    }
                }, 100);
            });
        }
    });
})();
