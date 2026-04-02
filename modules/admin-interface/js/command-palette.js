/**
 * Command Palette — Vanilla JS (~8KB)
 *
 * Keyboard-driven searchable overlay for WP admin pages,
 * modules, quick actions, and content search.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    /* ── Config ──────────────────────────────────────────── */

    var cfg = window.wptCommandPalette || {};
    if (!cfg.ajaxUrl) return;

    var SHORTCUT_KEY = (cfg.shortcut || 'mod+k').split('+').pop();
    var items        = cfg.items || [];
    var recentPages  = cfg.recentPages || [];
    var i18n         = cfg.i18n || {};

    /* ── DOM refs ────────────────────────────────────────── */

    var root    = document.getElementById('wpt-command-palette');
    var input   = document.getElementById('wpt-palette-input');
    var listEl  = document.getElementById('wpt-palette-results');

    if (!root || !input || !listEl) return;

    /* ── State ───────────────────────────────────────────── */

    var isOpen        = false;
    var activeIndex   = -1;
    var visibleItems  = [];  // flat array of currently displayed result objects
    var searchTimer   = null;
    var ajaxController = null;

    /* ── Open / Close ────────────────────────────────────── */

    function open() {
        if (isOpen) return;
        isOpen = true;
        root.classList.remove('wpt-palette-hidden');
        input.value = '';
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'true');

        if (cfg.showRecent && recentPages.length > 0) {
            renderResults(recentPages, i18n.recentPages || 'Recent Pages');
        } else {
            listEl.innerHTML = '';
        }

        requestAnimationFrame(function () {
            input.focus();
        });

        trackCurrentPage();
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        root.classList.add('wpt-palette-hidden');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-activedescendant', '');
        listEl.innerHTML = '';
        activeIndex = -1;
        visibleItems = [];

        if (searchTimer) {
            clearTimeout(searchTimer);
            searchTimer = null;
        }
        if (ajaxController) {
            ajaxController.abort();
            ajaxController = null;
        }
    }

    /* ── Keyboard Shortcut ───────────────────────────────── */

    document.addEventListener('keydown', function (e) {
        if (e.key === SHORTCUT_KEY && (e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey) {
            // Yield to Gutenberg when block editor is focused.
            if (document.querySelector('.block-editor') && document.activeElement && document.activeElement.closest('.block-editor')) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            if (isOpen) {
                close();
            } else {
                open();
            }
            return;
        }

        if (!isOpen) return;

        switch (e.key) {
            case 'Escape':
                e.preventDefault();
                close();
                break;
            case 'ArrowDown':
                e.preventDefault();
                moveSelection(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                moveSelection(-1);
                break;
            case 'Enter':
                e.preventDefault();
                selectCurrent();
                break;
            case 'Tab':
                // Focus trap: keep focus within palette.
                e.preventDefault();
                input.focus();
                break;
        }
    });

    /* ── Overlay click to close ───────────────────────────── */

    root.addEventListener('click', function (e) {
        if (e.target.classList.contains('wpt-palette-overlay')) {
            close();
        }
    });

    /* ── Input handler ───────────────────────────────────── */

    input.addEventListener('input', function () {
        var query = input.value.trim();

        if (searchTimer) {
            clearTimeout(searchTimer);
            searchTimer = null;
        }

        if (query === '') {
            if (cfg.showRecent && recentPages.length > 0) {
                renderResults(recentPages, i18n.recentPages || 'Recent Pages');
            } else {
                listEl.innerHTML = '';
                visibleItems = [];
                activeIndex = -1;
            }
            return;
        }

        var localResults = fuzzySearch(query, items);
        renderGroupedResults(localResults);

        if (cfg.searchContent && query.length >= 2) {
            searchTimer = setTimeout(function () {
                ajaxContentSearch(query, localResults);
            }, 300);
        }
    });

    /* ── Fuzzy Search ────────────────────────────────────── */

    /**
     * Score and filter items by query.
     * Scoring: exact match (100) > starts-with (80) > word-starts-with (60) > contains (40) > fuzzy (20).
     */
    function fuzzySearch(query, list) {
        var q = query.toLowerCase();
        var scored = [];

        for (var i = 0; i < list.length; i++) {
            var item = list[i];
            var title = (item.title || '').toLowerCase();
            var score = 0;

            if (title === q) {
                score = 100;
            } else if (title.indexOf(q) === 0) {
                score = 80;
            } else if (wordStartsWith(title, q)) {
                score = 60;
            } else if (title.indexOf(q) !== -1) {
                score = 40;
            } else if (fuzzyCharMatch(title, q)) {
                score = 20;
            }

            if (score > 0) {
                scored.push({ item: item, score: score });
            }
        }

        scored.sort(function (a, b) {
            return b.score - a.score;
        });

        return scored.map(function (s) { return s.item; });
    }

    /**
     * Check if any word in title starts with query.
     */
    function wordStartsWith(title, query) {
        var words = title.split(/[\s\-_/]+/);
        for (var i = 0; i < words.length; i++) {
            if (words[i].indexOf(query) === 0) return true;
        }
        return false;
    }

    /**
     * Fuzzy character match: all characters in query appear in title in order.
     */
    function fuzzyCharMatch(title, query) {
        var ti = 0;
        for (var qi = 0; qi < query.length; qi++) {
            var found = false;
            while (ti < title.length) {
                if (title[ti] === query[qi]) {
                    ti++;
                    found = true;
                    break;
                }
                ti++;
            }
            if (!found) return false;
        }
        return true;
    }

    /* ── AJAX Content Search ─────────────────────────────── */

    function ajaxContentSearch(query, localResults) {
        if (ajaxController) {
            ajaxController.abort();
        }

        if (typeof AbortController !== 'undefined') {
            ajaxController = new AbortController();
        }

        var url = cfg.ajaxUrl + '?action=wpt_palette_search&nonce=' +
            encodeURIComponent(cfg.nonce) + '&q=' + encodeURIComponent(query);

        var fetchOptions = { method: 'GET' };
        if (ajaxController) {
            fetchOptions.signal = ajaxController.signal;
        }

        fetch(url, fetchOptions)
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                    // Merge content results with local results.
                    var merged = localResults.concat(data.data);
                    renderGroupedResults(merged);
                }
            })
            .catch(function (err) {
                // Ignore abort errors.
                if (err.name !== 'AbortError') {
                    // eslint-disable-next-line no-console
                    console.warn('WPT Command Palette: content search failed', err);
                }
            });
    }

    /* ── Rendering ───────────────────────────────────────── */

    /**
     * Render grouped results (by type/subtitle).
     */
    function renderGroupedResults(resultItems) {
        if (resultItems.length === 0) {
            listEl.innerHTML = '<li class="wpt-palette-no-results">' +
                escapeHtml(i18n.noResults || 'No results found.') + '</li>';
            visibleItems = [];
            activeIndex = -1;
            return;
        }

        var groups = {};
        var groupOrder = [];
        for (var i = 0; i < resultItems.length; i++) {
            var group = resultItems[i].subtitle || 'Other';
            if (!groups[group]) {
                groups[group] = [];
                groupOrder.push(group);
            }
            groups[group].push(resultItems[i]);
        }

        var html = '';
        visibleItems = [];

        for (var g = 0; g < groupOrder.length; g++) {
            var groupName = groupOrder[g];
            var groupItems = groups[groupName];

            html += '<li class="wpt-palette-group-label" role="presentation">' +
                escapeHtml(groupName) + '</li>';

            for (var j = 0; j < groupItems.length; j++) {
                var item = groupItems[j];
                var idx = visibleItems.length;
                var itemId = 'wpt-palette-item-' + idx;

                html += '<li id="' + itemId + '" class="wpt-palette-item" role="option" ' +
                    'aria-selected="false" data-index="' + idx + '">' +
                    '<span class="wpt-palette-item-icon dashicons ' + escapeHtml(item.icon || 'dashicons-admin-generic') + '" aria-hidden="true"></span>' +
                    '<span class="wpt-palette-item-text">' +
                    '<span class="wpt-palette-item-title">' + escapeHtml(item.title || '') + '</span>' +
                    '</span></li>';

                visibleItems.push(item);
            }
        }

        listEl.innerHTML = html;
        activeIndex = -1;

        var itemEls = listEl.querySelectorAll('.wpt-palette-item');
        for (var k = 0; k < itemEls.length; k++) {
            itemEls[k].addEventListener('click', onItemClick);
            itemEls[k].addEventListener('mouseenter', onItemHover);
        }
    }

    /**
     * Render a flat list of results with a single group label.
     */
    function renderResults(resultItems, groupLabel) {
        var grouped = resultItems.map(function (item) {
            return Object.assign({}, item, { subtitle: groupLabel });
        });
        renderGroupedResults(grouped);
    }

    /* ── Selection / Navigation ───────────────────────────── */

    function moveSelection(delta) {
        if (visibleItems.length === 0) return;

        var prevIndex = activeIndex;
        activeIndex += delta;

        if (activeIndex < 0) activeIndex = visibleItems.length - 1;
        if (activeIndex >= visibleItems.length) activeIndex = 0;

        if (prevIndex >= 0) {
            var prevEl = listEl.querySelector('[data-index="' + prevIndex + '"]');
            if (prevEl) prevEl.setAttribute('aria-selected', 'false');
        }

        var activeEl = listEl.querySelector('[data-index="' + activeIndex + '"]');
        if (activeEl) {
            activeEl.setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', activeEl.id);
            activeEl.scrollIntoView({ block: 'nearest' });
        }
    }

    function selectCurrent() {
        if (activeIndex < 0 || activeIndex >= visibleItems.length) return;
        executeItem(visibleItems[activeIndex]);
    }

    function onItemClick(e) {
        var el = e.currentTarget;
        var idx = parseInt(el.getAttribute('data-index'), 10);
        if (!isNaN(idx) && idx >= 0 && idx < visibleItems.length) {
            executeItem(visibleItems[idx]);
        }
    }

    function onItemHover(e) {
        var el = e.currentTarget;
        var idx = parseInt(el.getAttribute('data-index'), 10);
        if (isNaN(idx) || idx === activeIndex) return;

        if (activeIndex >= 0) {
            var prevEl = listEl.querySelector('[data-index="' + activeIndex + '"]');
            if (prevEl) prevEl.setAttribute('aria-selected', 'false');
        }

        activeIndex = idx;
        el.setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', el.id);
    }

    /* ── Execute Item ────────────────────────────────────── */

    function executeItem(item) {
        if (!item) return;

        if (item.action) {
            close();
            handleAction(item.action);
            return;
        }

        if (item.url) {
            close();
            window.location.href = item.url;
        }
    }

    /**
     * Handle quick actions by name.
     */
    function handleAction(actionName) {
        switch (actionName) {
            case 'toggle-dark-mode':
                document.body.classList.toggle('wpt-dark-mode');
                var darkToggle = document.querySelector('.wpt-dark-mode-toggle');
                if (darkToggle) darkToggle.click();
                break;

            case 'toggle-maintenance-mode':
                window.location.href = cfg.adminUrl + 'admin.php?page=wptransformed&module=maintenance-mode';
                break;

            case 'clear-transients':
                window.location.href = cfg.adminUrl + 'admin.php?page=wptransformed&module=database-cleanup';
                break;

            case 'run-db-cleanup':
                window.location.href = cfg.adminUrl + 'admin.php?page=wptransformed&module=database-cleanup';
                break;

            case 'export-settings':
                window.location.href = cfg.adminUrl + 'admin.php?page=wptransformed';
                break;

            case 'view-audit-log':
                window.location.href = cfg.adminUrl + 'admin.php?page=wptransformed&module=audit-log';
                break;

            default:
                document.dispatchEvent(new CustomEvent('wpt-palette-action', {
                    detail: { action: actionName }
                }));
                break;
        }
    }

    /* ── Track Recent Page ───────────────────────────────── */

    function trackCurrentPage() {
        var title = document.title.replace(/\s*[\u2039\u2014\u2022\|].*/g, '').trim();
        var url = window.location.href;

        if (!title || !url || !cfg.trackNonce) return;

        var formData = new FormData();
        formData.append('action', 'wpt_palette_track_page');
        formData.append('nonce', cfg.trackNonce);
        formData.append('title', title);
        formData.append('url', url);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).catch(function () {
            // Silent fail — tracking is non-critical.
        });
    }

    /* ── Helpers ──────────────────────────────────────────── */

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})();
