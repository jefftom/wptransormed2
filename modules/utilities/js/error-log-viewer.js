/**
 * Error Log Viewer -- Client-side search, filter, and clear functionality.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptErrorLogViewer || {};

    document.addEventListener('DOMContentLoaded', function () {
        var searchInput  = document.getElementById('wpt-error-log-search');
        var filterSelect = document.getElementById('wpt-error-log-filter');
        var clearBtn     = document.getElementById('wpt-error-log-clear-btn');
        var spinner      = document.getElementById('wpt-error-log-spinner');
        var countEl      = document.getElementById('wpt-error-log-count');
        var container    = document.getElementById('wpt-error-log-entries');

        if (!container) {
            return;
        }

        var allLines = container.querySelectorAll('.wpt-log-line');
        var totalLines = allLines.length;

        /**
         * Apply search and type filter to log lines.
         */
        function applyFilters() {
            var search = (searchInput ? searchInput.value.toLowerCase() : '');
            var typeFilter = (filterSelect ? filterSelect.value : 'all');
            var visibleCount = 0;

            for (var i = 0; i < allLines.length; i++) {
                var line = allLines[i];
                var lineType = line.getAttribute('data-type') || 'other';
                var lineText = (line.textContent || '').toLowerCase();

                var matchesType = (typeFilter === 'all' || lineType === typeFilter);
                var matchesSearch = (search === '' || lineText.indexOf(search) !== -1);

                if (matchesType && matchesSearch) {
                    line.style.display = '';
                    visibleCount++;
                } else {
                    line.style.display = 'none';
                }
            }

            if (countEl) {
                countEl.textContent = config.i18n.showing + ' ' + visibleCount + ' ' + config.i18n.of + ' ' + totalLines + ' ' + config.i18n.entries;
            }
        }

        // Initialize count display.
        applyFilters();

        // Debounced search.
        var searchTimeout;
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(applyFilters, 200);
            });
        }

        if (filterSelect) {
            filterSelect.addEventListener('change', applyFilters);
        }

        // Color-code lines by type.
        var typeColors = {
            fatal:      '#cc6666',
            parse:      '#cc6666',
            warning:    '#f0c674',
            notice:     '#81a2be',
            deprecated: '#b294bb',
            other:      '#c5c8c6'
        };

        for (var i = 0; i < allLines.length; i++) {
            var type = allLines[i].getAttribute('data-type') || 'other';
            if (typeColors[type]) {
                allLines[i].style.color = typeColors[type];
            }
        }

        // Clear log button.
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!confirm(config.i18n.confirmClear)) {
                    return;
                }

                clearBtn.disabled = true;
                if (spinner) spinner.classList.add('is-active');

                var formData = new FormData();
                formData.append('action', 'wpt_error_log_clear');
                formData.append('nonce', config.nonce);

                fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        alert(config.i18n.cleared);
                        window.location.reload();
                    } else {
                        alert(data.data && data.data.message ? data.data.message : config.i18n.networkError);
                    }
                })
                .catch(function () {
                    alert(config.i18n.networkError);
                })
                .finally(function () {
                    clearBtn.disabled = false;
                    if (spinner) spinner.classList.remove('is-active');
                });
            });
        }
    });
})();
