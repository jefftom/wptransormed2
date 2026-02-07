/**
 * Hide Admin Notices — Notifications page approach.
 * modules/admin-interface/js/hide-admin-notices.js
 *
 * On the Notifications page: collects notices from the standard WP
 * notice area, groups them by type, moves them into the page content.
 *
 * On all other pages: CSS has already hidden notices. JS counts them,
 * updates the sidebar menu bubble, and inserts a text link.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var cfg = (typeof wptHideNotices !== 'undefined') ? wptHideNotices : {};
        var i18n = cfg.i18n || {};

        // Detect notifications page by DOM presence — no wp_localize_script boolean needed.
        var notificationsContent = document.getElementById('wpt-notifications-content');
        var isNotificationsPage = !!notificationsContent;

        // Collect all notice elements inside #wpbody-content.
        var container = document.getElementById('wpbody-content');
        if (!container) return;

        var all = container.querySelectorAll('.notice, .updated, .error, .update-nag');

        // Filter out elements already inside our notifications content area.
        var notices = [];
        all.forEach(function(el) {
            if (!el.closest('#wpt-notifications-content')) {
                notices.push(el);
            }
        });

        var hasErrors = notices.some(function(el) {
            return el.classList.contains('notice-error')
                || (el.classList.contains('error') && !el.classList.contains('notice'));
        });

        // Debug logging.
        console.log('[WPT Hide Notices] isNotificationsPage:', isNotificationsPage,
            '| notices found:', notices.length,
            '| hasErrors:', hasErrors);

        // Update the sidebar menu count bubble.
        updateMenuBubble(notices.length);

        if (isNotificationsPage) {
            handleNotificationsPage(notices, hasErrors, notificationsContent, i18n);
        } else {
            handleOtherPages(notices, hasErrors, cfg, i18n);
        }
    });

    /**
     * Update the sidebar "Notifications" menu count bubble.
     */
    function updateMenuBubble(count) {
        var menuLink = document.querySelector('#adminmenu a[href*="page=wpt-notifications"]');
        if (!menuLink) return;

        var bubble = menuLink.querySelector('.awaiting-mod');

        if (count === 0) {
            if (bubble) bubble.style.display = 'none';
            return;
        }

        if (!bubble) {
            bubble = document.createElement('span');
            bubble.innerHTML = '<span class="pending-count"></span>';
            menuLink.appendChild(document.createTextNode(' '));
            menuLink.appendChild(bubble);
        }

        var inner = bubble.querySelector('.pending-count');
        if (inner) {
            inner.textContent = count;
        }
        bubble.className = 'awaiting-mod count-' + count;
        bubble.style.display = '';
    }

    /**
     * Notifications page: group notices by type and move them into sections.
     */
    function handleNotificationsPage(notices, hasErrors, content, i18n) {
        console.log('[WPT Hide Notices] handleNotificationsPage — notices to process:', notices.length);

        if (notices.length === 0) {
            content.innerHTML = '<p class="wpt-no-notices">'
                + (i18n.noNotifications || 'No notifications.') + '</p>';
            return;
        }

        // Clear the content container.
        content.innerHTML = '';

        // Build Dismiss All button at top.
        var header = document.createElement('div');
        header.className = 'wpt-notifications-header';
        var dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'button wpt-dismiss-all';
        dismissBtn.textContent = i18n.dismissAll || 'Dismiss All';
        header.appendChild(dismissBtn);
        content.appendChild(header);

        // Categorize notices by class.
        var errors = [];
        var warnings = [];
        var other = [];

        notices.forEach(function(el) {
            if (el.classList.contains('notice-error') || (el.classList.contains('error') && !el.classList.contains('notice'))) {
                errors.push(el);
            } else if (el.classList.contains('notice-warning')) {
                warnings.push(el);
            } else {
                other.push(el);
            }
        });

        console.log('[WPT Hide Notices] categorized — errors:', errors.length,
            '| warnings:', warnings.length, '| other:', other.length);

        // Render each non-empty group — moves notices from their original
        // position into the section container via appendChild.
        if (errors.length > 0) {
            renderGroup(content, '\u26A0\uFE0F ' + (i18n.errors || 'Errors'), errors);
        }
        if (warnings.length > 0) {
            renderGroup(content, i18n.warnings || 'Warnings', warnings);
        }
        if (other.length > 0) {
            renderGroup(content, i18n.other || 'Info & Success', other);
        }

        // Dismiss All handler.
        dismissBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var buttons = content.querySelectorAll('.notice.is-dismissible .notice-dismiss');
            buttons.forEach(function(btn) { btn.click(); });

            setTimeout(function() {
                var remaining = content.querySelectorAll('.notice, .updated, .error, .update-nag');
                updateMenuBubble(remaining.length);

                if (remaining.length === 0) {
                    content.innerHTML = '<p class="wpt-no-notices">'
                        + (i18n.noNotifications || 'No notifications.') + '</p>';
                }
            }, 100);
        });
    }

    /**
     * Render a group of notices with a heading.
     * appendChild moves each notice from its original DOM position.
     */
    function renderGroup(container, title, noticeEls) {
        var section = document.createElement('div');
        section.className = 'wpt-notice-group';

        var heading = document.createElement('h3');
        heading.className = 'wpt-notice-group-title';
        heading.textContent = title;
        section.appendChild(heading);

        for (var i = 0; i < noticeEls.length; i++) {
            var el = noticeEls[i];
            // Force visible — override any CSS hide rules.
            el.style.setProperty('display', 'block', 'important');
            // appendChild moves the element from its original position.
            section.appendChild(el);
        }

        container.appendChild(section);

        console.log('[WPT Hide Notices] renderGroup "' + title + '" — moved', noticeEls.length, 'notices');
    }

    /**
     * Other admin pages: insert a text link with notice count.
     */
    function handleOtherPages(notices, hasErrors, cfg, i18n) {
        if (notices.length === 0) return;

        var count = notices.length;
        var tpl = (count === 1)
            ? (i18n.oneNotification || '%d notification')
            : (i18n.manyNotifications || '%d notifications');
        var countText = tpl.replace('%d', count);
        var viewText = i18n.viewNotifications || 'View Notifications';
        var url = cfg.notificationsUrl || '';

        var wrapper = document.createElement('div');
        wrapper.className = 'wpt-notice-link';

        var text = document.createElement('span');
        text.className = 'wpt-notice-link-count';
        text.textContent = (hasErrors ? '\u26A0\uFE0F ' : '') + countText;
        wrapper.appendChild(text);

        if (url) {
            wrapper.appendChild(document.createTextNode(' \u2014 '));
            var link = document.createElement('a');
            link.href = url;
            link.className = 'wpt-notice-link-url';
            link.textContent = viewText;
            wrapper.appendChild(link);
        }

        var target = document.querySelector('#wpbody-content .wrap')
            || document.getElementById('wpbody-content');
        if (!target) return;

        var heading = target.querySelector('h1');
        var insertRef = heading ? heading.nextSibling : target.firstChild;
        target.insertBefore(wrapper, insertRef);
    }
})();
