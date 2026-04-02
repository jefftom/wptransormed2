/**
 * Audit Log — Admin JS.
 *
 * Handles filter form, pagination, and AJAX log loading.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptAuditLog || {};
    var currentPage = 1;

    /**
     * Fetch audit log entries via AJAX.
     *
     * @param {number} page Page number to fetch.
     */
    function fetchLogs(page) {
        var spinner = document.getElementById('wpt-audit-spinner');
        var tbody   = document.getElementById('wpt-audit-log-body');

        if (!tbody) return;

        if (spinner) spinner.classList.add('is-active');

        var filterUser   = document.getElementById('wpt-audit-filter-user');
        var filterAction = document.getElementById('wpt-audit-filter-action');
        var filterFrom   = document.getElementById('wpt-audit-filter-from');
        var filterTo     = document.getElementById('wpt-audit-filter-to');

        var formData = new FormData();
        formData.append('action', 'wpt_audit_log_fetch');
        formData.append('nonce', config.nonce);
        formData.append('page', page);

        if (filterUser && filterUser.value) {
            formData.append('filter_user', filterUser.value);
        }
        if (filterAction && filterAction.value) {
            formData.append('filter_action', filterAction.value);
        }
        if (filterFrom && filterFrom.value) {
            formData.append('filter_date_from', filterFrom.value);
        }
        if (filterTo && filterTo.value) {
            formData.append('filter_date_to', filterTo.value);
        }

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    tbody.innerHTML = data.data.html;
                    currentPage = data.data.current;
                    updatePagination(data.data.current, data.data.total_pages, data.data.total);
                } else {
                    tbody.innerHTML = '<tr><td colspan="7">' + config.i18n.noEntries + '</td></tr>';
                }
            })
            .catch(function () {
                tbody.innerHTML = '<tr><td colspan="7">' + config.i18n.networkError + '</td></tr>';
            })
            .finally(function () {
                if (spinner) spinner.classList.remove('is-active');
            });
    }

    /**
     * Update pagination controls.
     *
     * @param {number} current    Current page.
     * @param {number} totalPages Total pages.
     * @param {number} total      Total entries.
     */
    function updatePagination(current, totalPages, total) {
        var prevBtn    = document.getElementById('wpt-audit-prev');
        var nextBtn    = document.getElementById('wpt-audit-next');
        var pageInfo   = document.getElementById('wpt-audit-page-info');
        var pagination = document.querySelector('.wpt-audit-pagination');
        var totalCount = document.querySelector('.wpt-audit-total-count');

        if (prevBtn) prevBtn.disabled = current <= 1;
        if (nextBtn) nextBtn.disabled = current >= totalPages;

        if (pageInfo) {
            var text = config.i18n.pageInfo
                .replace('%1$d', current)
                .replace('%2$d', Math.max(1, totalPages));
            pageInfo.textContent = text;
        }

        if (pagination) {
            pagination.style.display = totalPages > 1 ? '' : 'none';
        }

        if (totalCount) {
            totalCount.textContent = config.i18n.entries.replace('%s', total.toLocaleString());
        }
    }

    /**
     * Initialize filter and pagination controls.
     */
    function init() {
        // Filter button.
        var filterBtn = document.getElementById('wpt-audit-filter-btn');
        if (filterBtn) {
            filterBtn.addEventListener('click', function () {
                currentPage = 1;
                fetchLogs(1);
            });
        }

        // Reset button.
        var resetBtn = document.getElementById('wpt-audit-reset-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var filterUser   = document.getElementById('wpt-audit-filter-user');
                var filterAction = document.getElementById('wpt-audit-filter-action');
                var filterFrom   = document.getElementById('wpt-audit-filter-from');
                var filterTo     = document.getElementById('wpt-audit-filter-to');

                if (filterUser) filterUser.value = '';
                if (filterAction) filterAction.value = '';
                if (filterFrom) filterFrom.value = '';
                if (filterTo) filterTo.value = '';

                currentPage = 1;
                fetchLogs(1);
            });
        }

        // Previous page.
        var prevBtn = document.getElementById('wpt-audit-prev');
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (currentPage > 1) {
                    fetchLogs(currentPage - 1);
                }
            });
        }

        // Next page.
        var nextBtn = document.getElementById('wpt-audit-next');
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                fetchLogs(currentPage + 1);
            });
        }
    }

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
