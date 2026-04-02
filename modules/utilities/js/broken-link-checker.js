/**
 * WPTransformed — Broken Link Checker admin JS.
 *
 * Vanilla JS, no jQuery dependency.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    if (typeof wptBrokenLinkChecker === 'undefined') {
        return;
    }

    var config = wptBrokenLinkChecker;
    var currentPage = 1;
    var currentFilter = 'broken';

    // ── Helpers ───────────────────────────────────────────────

    function ajax(action, data, callback) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', config.nonce);

        if (data) {
            Object.keys(data).forEach(function (key) {
                formData.append(key, data[key]);
            });
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', config.ajaxUrl, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    callback(null, resp);
                } catch (e) {
                    callback(config.i18n.networkError);
                }
            } else {
                callback(config.i18n.networkError);
            }
        };
        xhr.send(formData);
    }

    function showNotice(message, type) {
        var existing = document.querySelector('.wpt-blc-notice');
        if (existing) {
            existing.remove();
        }

        var notice = document.createElement('div');
        notice.className = 'notice notice-' + (type || 'success') + ' is-dismissible wpt-blc-notice';
        notice.innerHTML = '<p>' + escapeHtml(message) + '</p>';

        var wrap = document.querySelector('.wpt-blc-summary');
        if (wrap) {
            wrap.parentNode.insertBefore(notice, wrap);
        }

        setTimeout(function () {
            if (notice.parentNode) {
                notice.remove();
            }
        }, 5000);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // ── Scan Now ──────────────────────────────────────────────

    function initScanNow() {
        var btn = document.getElementById('wpt-blc-scan-now');
        var spinner = document.getElementById('wpt-blc-scan-spinner');

        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = config.i18n.scanning;
            if (spinner) spinner.classList.add('is-active');

            ajax('wpt_blc_scan_now', {}, function (err, resp) {
                btn.disabled = false;
                btn.textContent = config.i18n.scanComplete;
                if (spinner) spinner.classList.remove('is-active');

                if (err) {
                    showNotice(err, 'error');
                    return;
                }

                if (resp.success) {
                    showNotice(resp.data.message, 'success');
                    // Reload to show updated results.
                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotice(resp.data.message || config.i18n.networkError, 'error');
                }
            });
        });
    }

    // ── Row Actions (Unlink / Dismiss) ──────────────────────────

    function initRowActions() {
        var actions = [
            { selector: '.wpt-blc-unlink',  action: 'wpt_blc_unlink',  confirm: config.i18n.confirmUnlink },
            { selector: '.wpt-blc-dismiss', action: 'wpt_blc_dismiss', confirm: config.i18n.confirmDismiss }
        ];

        document.addEventListener('click', function (e) {
            actions.forEach(function (def) {
                var btn = e.target.closest(def.selector);
                if (!btn) return;

                if (!confirm(def.confirm)) return;

                var linkId = btn.getAttribute('data-id');
                var row = btn.closest('tr');

                btn.disabled = true;

                ajax(def.action, { link_id: linkId }, function (err, resp) {
                    btn.disabled = false;

                    if (err) {
                        showNotice(err, 'error');
                        return;
                    }

                    if (resp.success) {
                        if (row) row.remove();
                        showNotice(resp.data.message, 'success');
                    } else {
                        showNotice(resp.data.message || config.i18n.networkError, 'error');
                    }
                });
            });
        });
    }

    // ── Filter Buttons ────────────────────────────────────────

    function initFilters() {
        var filters = document.querySelectorAll('.wpt-blc-filter');

        filters.forEach(function (btn) {
            btn.addEventListener('click', function () {
                filters.forEach(function (f) { f.classList.remove('active'); });
                btn.classList.add('active');

                currentFilter = btn.getAttribute('data-filter');
                currentPage = 1;
                loadResults();
            });
        });
    }

    // ── Pagination ────────────────────────────────────────────

    function initPagination() {
        var prevBtn = document.getElementById('wpt-blc-prev');
        var nextBtn = document.getElementById('wpt-blc-next');

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (currentPage > 1) {
                    currentPage--;
                    loadResults();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                currentPage++;
                loadResults();
            });
        }
    }

    // ── Load Results via AJAX ─────────────────────────────────

    function loadResults() {
        var tbody = document.getElementById('wpt-blc-tbody');
        if (!tbody) return;

        ajax('wpt_blc_get_results', {
            page: currentPage,
            filter: currentFilter
        }, function (err, resp) {
            if (err || !resp.success) {
                return;
            }

            var data = resp.data;
            var results = data.results;
            var pagination = document.getElementById('wpt-blc-pagination');
            var pageInfo = document.getElementById('wpt-blc-page-info');
            var prevBtn = document.getElementById('wpt-blc-prev');
            var nextBtn = document.getElementById('wpt-blc-next');

            tbody.innerHTML = '';

            if (results.length === 0) {
                var tr = document.createElement('tr');
                tr.className = 'wpt-blc-no-results';
                tr.innerHTML = '<td colspan="5">' + escapeHtml(config.i18n.noResults) + '</td>';
                tbody.appendChild(tr);
                if (pagination) pagination.style.display = 'none';
                return;
            }

            results.forEach(function (link) {
                var tr = document.createElement('tr');
                tr.setAttribute('data-id', link.id);

                var url = link.url || '';
                var displayUrl = url.length > 80 ? url.substring(0, 80) + '...' : url;
                var statusClass = getStatusClass(parseInt(link.status_code, 10));

                var html = '<td class="wpt-blc-col-url"><code class="wpt-blc-url" title="' + escapeHtml(url) + '">' + escapeHtml(displayUrl) + '</code>';
                if (link.anchor_text) {
                    html += '<span class="wpt-blc-anchor">' + escapeHtml(link.anchor_text) + '</span>';
                }
                html += '</td>';

                html += '<td class="wpt-blc-col-status"><span class="wpt-blc-status wpt-blc-status-' + escapeHtml(statusClass) + '">';
                html += escapeHtml(String(link.status_code));
                if (link.status_text) {
                    html += ' ' + escapeHtml(link.status_text);
                }
                html += '</span></td>';

                html += '<td class="wpt-blc-col-source">';
                if (link.post_title && link.found_in_post) {
                    html += '<a href="post.php?action=edit&post=' + escapeHtml(String(link.found_in_post)) + '">' + escapeHtml(link.post_title) + '</a>';
                } else {
                    html += '<span class="wpt-blc-muted">Unknown</span>';
                }
                html += '</td>';

                html += '<td class="wpt-blc-col-type"><span class="wpt-blc-type-badge wpt-blc-type-' + escapeHtml(link.link_type) + '">' + escapeHtml(capitalize(link.link_type)) + '</span></td>';

                html += '<td class="wpt-blc-col-actions">';
                if (link.found_in_post) {
                    html += '<a href="post.php?action=edit&post=' + escapeHtml(String(link.found_in_post)) + '" class="button button-small">Edit</a> ';
                }
                html += '<button type="button" class="button button-small wpt-blc-unlink" data-id="' + escapeHtml(String(link.id)) + '">Unlink</button> ';
                html += '<button type="button" class="button button-small wpt-blc-dismiss" data-id="' + escapeHtml(String(link.id)) + '">Dismiss</button>';
                html += '</td>';

                tr.innerHTML = html;
                tbody.appendChild(tr);
            });

            // Update pagination.
            if (data.pages > 1) {
                if (pagination) pagination.style.display = 'block';
                if (pageInfo) {
                    pageInfo.textContent = config.i18n.pageOf
                        .replace('%1$s', String(data.page))
                        .replace('%2$s', String(data.pages));
                }
                if (prevBtn) prevBtn.disabled = (data.page <= 1);
                if (nextBtn) nextBtn.disabled = (data.page >= data.pages);
            } else {
                if (pagination) pagination.style.display = 'none';
            }
        });
    }

    function getStatusClass(code) {
        if (code === 0) return 'error';
        if (code >= 200 && code < 300) return 'ok';
        if (code >= 300 && code < 400) return 'redirect';
        if (code >= 400 && code < 500) return 'broken';
        if (code >= 500) return 'server-error';
        return 'unknown';
    }

    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    // ── Init ──────────────────────────────────────────────────

    function init() {
        initScanNow();
        initRowActions();
        initFilters();
        initPagination();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
