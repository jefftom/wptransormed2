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
        var expanded = bar.getAttribute('data-auto-expand') === '1';

        // Localization strings with fallback
        var i18n = (typeof wptHideNoticesI18n !== 'undefined') ? wptHideNoticesI18n : { show: 'Show', hide: 'Hide' };

        // Ensure initial state is correct
        panel.style.display = expanded ? '' : 'none';
        if (toggleBtn) {
            toggleBtn.textContent = expanded ? i18n.hide : i18n.show;
        }
        bar.classList.toggle('wpt-notice-bar-expanded', expanded);

        // Toggle panel on bar click
        bar.addEventListener('click', function() {
            expanded = !expanded;
            panel.style.display = expanded ? '' : 'none';
            if (toggleBtn) {
                toggleBtn.textContent = expanded ? i18n.hide : i18n.show;
            }
            bar.classList.toggle('wpt-notice-bar-expanded', expanded);
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
