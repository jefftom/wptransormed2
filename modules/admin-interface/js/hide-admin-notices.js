/**
 * Hide Admin Notices — CSS+JS approach
 * modules/admin-interface/js/hide-admin-notices.js
 *
 * CSS has already hidden all notices instantly (no flash).
 * This script collects them, moves them into a toggleable panel,
 * and makes them visible again inside that panel.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var cfg = (typeof wptHideNotices !== 'undefined') ? wptHideNotices : {};
        var i18n = cfg.i18n || { show: 'Show', hide: 'Hide', notices: 'Notices', oneNotice: '%d notice', manyNotices: '%d notices', dismissAll: 'Dismiss All' };

        // Collect all notice elements from standard WP locations
        var selectors = [
            '#wpbody-content > .notice',
            '#wpbody-content > .updated',
            '#wpbody-content > .error',
            '#wpbody-content > .update-nag',
            '.wrap > .notice',
            '.wrap > .updated',
            '.wrap > .error',
            '.wrap > .update-nag'
        ];
        var all = document.querySelectorAll(selectors.join(','));

        // Dedupe (an element matching multiple selectors should only appear once)
        var seen = [];
        var notices = [];
        all.forEach(function(el) {
            if (seen.indexOf(el) === -1) {
                seen.push(el);
                notices.push(el);
            }
        });

        // Nothing to do
        if (notices.length === 0) return;

        // Check for errors
        var hasErrors = notices.some(function(el) {
            return el.classList.contains('notice-error');
        });
        var expanded = !!(cfg.autoExpandErrors && hasErrors);

        // Build badge text
        var count = notices.length;
        var badgeText;
        if (cfg.showCountBadge) {
            var tpl = (count === 1) ? i18n.oneNotice : i18n.manyNotices;
            badgeText = tpl.replace('%d', count);
        } else {
            badgeText = i18n.notices;
        }

        // Build bar
        var bar = document.createElement('div');
        bar.className = 'wpt-notice-bar' + (expanded ? ' wpt-notice-bar-expanded' : '');

        var label = document.createElement('span');
        label.className = 'wpt-notice-bar-label';
        label.textContent = badgeText;

        var toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'wpt-notice-toggle';
        toggleBtn.textContent = expanded ? i18n.hide : i18n.show;

        bar.appendChild(label);
        bar.appendChild(toggleBtn);

        // Build panel
        var panel = document.createElement('div');
        panel.className = 'wpt-notice-panel';
        panel.style.display = expanded ? '' : 'none';

        // Move each notice into the panel and undo the CSS hide
        notices.forEach(function(el) {
            el.style.display = '';
            el.style.setProperty('display', '', 'important');
            panel.appendChild(el);
        });

        // Dismiss All footer
        var footer = document.createElement('div');
        footer.className = 'wpt-notice-panel-footer';

        var dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'button wpt-dismiss-all';
        dismissBtn.textContent = i18n.dismissAll;
        footer.appendChild(dismissBtn);
        panel.appendChild(footer);

        // Insert bar + panel at top of .wrap (before first child)
        var wrap = document.querySelector('#wpbody-content .wrap');
        if (!wrap) return;

        // Insert after the <h1> if present, otherwise as first child
        var heading = wrap.querySelector('h1');
        var insertRef = heading ? heading.nextSibling : wrap.firstChild;
        wrap.insertBefore(panel, insertRef);
        wrap.insertBefore(bar, panel);

        // Toggle handler
        bar.addEventListener('click', function() {
            expanded = !expanded;
            panel.style.display = expanded ? '' : 'none';
            toggleBtn.textContent = expanded ? i18n.hide : i18n.show;
            bar.classList.toggle('wpt-notice-bar-expanded', expanded);
        });

        // Dismiss All handler
        dismissBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var buttons = panel.querySelectorAll('.notice.is-dismissible .notice-dismiss');
            buttons.forEach(function(btn) { btn.click(); });

            // After a tick, check if any notices remain
            setTimeout(function() {
                var remaining = panel.querySelectorAll('.notice, .updated, .error, .update-nag');
                if (remaining.length === 0) {
                    bar.style.display = 'none';
                    panel.style.display = 'none';
                }
            }, 100);
        });
    });
})();
