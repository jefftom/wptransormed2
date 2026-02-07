/**
 * Hide Admin Notices — Dashboard widget + text link approach.
 * modules/admin-interface/js/hide-admin-notices.js
 *
 * CSS has already hidden all notices instantly (no flash).
 *
 * Dashboard page: moves notices into the Notifications widget.
 * Other pages: builds a minimal text link showing the count.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var cfg = (typeof wptHideNotices !== 'undefined') ? wptHideNotices : {};
        var i18n = cfg.i18n || {};

        // Collect ALL notice elements inside #wpbody-content
        var container = document.getElementById('wpbody-content');
        if (!container) return;

        var all = container.querySelectorAll('.notice, .updated, .error, .update-nag');

        // Filter out elements already inside the widget (guards against double-init)
        var notices = [];
        all.forEach(function(el) {
            if (!el.closest('#wpt-notices-widget-content')) {
                notices.push(el);
            }
        });

        // Check for errors
        var hasErrors = notices.some(function(el) {
            return el.classList.contains('notice-error') || el.classList.contains('error');
        });

        // Update the sidebar menu count bubble
        updateMenuBubble(notices.length, hasErrors);

        if (cfg.isDashboard) {
            handleDashboard(notices, hasErrors, i18n);
        } else {
            handleOtherPages(notices, hasErrors, cfg, i18n);
        }
    });

    /**
     * Update the "Notifications" sidebar menu count bubble.
     */
    function updateMenuBubble(count, hasErrors) {
        var bubble = document.getElementById('wpt-menu-notice-count');
        if (!bubble) return;

        if (count === 0) {
            bubble.style.display = 'none';
            return;
        }

        var inner = bubble.querySelector('.plugin-count');
        if (inner) {
            inner.textContent = (hasErrors ? '\u26A0\uFE0F ' : '') + count;
        }
        bubble.className = 'update-plugins count-' + count;
        bubble.style.display = '';
    }

    /**
     * Dashboard: move notices into the widget container.
     */
    function handleDashboard(notices, hasErrors, i18n) {
        var widgetContent = document.getElementById('wpt-notices-widget-content');
        var widgetFooter = document.getElementById('wpt-notices-widget-footer');
        if (!widgetContent) return;

        // Scroll to widget if URL hash points to it
        if (window.location.hash === '#wpt_notices_widget') {
            var widget = document.getElementById('wpt_notices_widget');
            if (widget) {
                widget.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Update widget title with count and warning icon
        var widgetTitle = document.querySelector('#wpt_notices_widget .hndle span, #wpt_notices_widget h2 .hndle');
        // Try the standard dashboard widget title structure
        if (!widgetTitle) {
            widgetTitle = document.querySelector('#wpt_notices_widget .hndle');
        }

        if (notices.length === 0) {
            // No notices — show the "No notifications." message (already in PHP)
            return;
        }

        // Remove the "No notifications." placeholder
        var placeholder = widgetContent.querySelector('.wpt-no-notices');
        if (placeholder) {
            placeholder.style.display = 'none';
        }

        // Update widget title with count
        if (widgetTitle) {
            var count = notices.length;
            var tpl = (count === 1) ? (i18n.oneNotification || '%d notification') : (i18n.manyNotifications || '%d notifications');
            var titleText = tpl.replace('%d', count);
            if (hasErrors) {
                titleText = titleText + ' \u26A0\uFE0F';
            }
            // The hndle element may contain child elements (like toggle buttons)
            // Only update the text node
            var firstText = null;
            for (var i = 0; i < widgetTitle.childNodes.length; i++) {
                if (widgetTitle.childNodes[i].nodeType === Node.TEXT_NODE && widgetTitle.childNodes[i].textContent.trim() !== '') {
                    firstText = widgetTitle.childNodes[i];
                    break;
                }
            }
            if (firstText) {
                firstText.textContent = titleText;
            } else {
                // Fallback: prepend text node
                widgetTitle.insertBefore(document.createTextNode(titleText), widgetTitle.firstChild);
            }
        }

        // Move each notice into the widget and make it visible
        notices.forEach(function(el) {
            el.style.setProperty('display', '', 'important');
            widgetContent.appendChild(el);
        });

        // Show the Dismiss All footer
        if (widgetFooter) {
            widgetFooter.style.display = '';

            var dismissBtn = widgetFooter.querySelector('.wpt-dismiss-all');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var buttons = widgetContent.querySelectorAll('.notice.is-dismissible .notice-dismiss');
                    buttons.forEach(function(btn) { btn.click(); });

                    // After a tick, check remaining
                    setTimeout(function() {
                        var remaining = widgetContent.querySelectorAll('.notice, .updated, .error, .update-nag');
                        var remainCount = remaining.length;

                        // Update menu bubble with new count
                        updateMenuBubble(remainCount, false);

                        if (remainCount === 0) {
                            if (placeholder) {
                                placeholder.style.display = '';
                            }
                            widgetFooter.style.display = 'none';
                            // Reset title
                            if (widgetTitle) {
                                var ft = null;
                                for (var j = 0; j < widgetTitle.childNodes.length; j++) {
                                    if (widgetTitle.childNodes[j].nodeType === Node.TEXT_NODE && widgetTitle.childNodes[j].textContent.trim() !== '') {
                                        ft = widgetTitle.childNodes[j];
                                        break;
                                    }
                                }
                                if (ft) {
                                    ft.textContent = i18n.noNotifications || 'Notifications';
                                }
                            }
                        }
                    }, 100);
                });
            }
        }
    }

    /**
     * Non-dashboard pages: show a minimal text link with count.
     */
    function handleOtherPages(notices, hasErrors, cfg, i18n) {
        if (notices.length === 0) return;

        var count = notices.length;
        var tpl = (count === 1) ? (i18n.oneNotification || '%d notification') : (i18n.manyNotifications || '%d notifications');
        var countText = tpl.replace('%d', count);

        var viewText = i18n.viewDashboard || 'View Dashboard';
        var dashboardUrl = cfg.dashboardUrl || '';

        // Build the text link
        var wrapper = document.createElement('div');
        wrapper.className = 'wpt-notice-link';

        var text = document.createElement('span');
        text.className = 'wpt-notice-link-count';
        text.textContent = (hasErrors ? '\u26A0\uFE0F ' : '') + countText;

        wrapper.appendChild(text);

        if (dashboardUrl) {
            var sep = document.createTextNode(' \u2014 ');
            wrapper.appendChild(sep);

            var link = document.createElement('a');
            link.href = dashboardUrl;
            link.className = 'wpt-notice-link-dashboard';
            link.textContent = viewText;
            wrapper.appendChild(link);
        }

        // Insert at the top of .wrap or #wpbody-content
        var target = document.querySelector('#wpbody-content .wrap')
            || document.getElementById('wpbody-content');
        if (!target) return;

        var heading = target.querySelector('h1');
        var insertRef = heading ? heading.nextSibling : target.firstChild;
        target.insertBefore(wrapper, insertRef);
    }
})();
