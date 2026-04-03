/**
 * Plugin Profiler -- AJAX interactions for benchmark and results.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var config = window.wptProfiler;
    if (!config) return;

    document.addEventListener('DOMContentLoaded', function () {
        var runBtn     = document.getElementById('wpt-run-profiler');
        var clearBtn   = document.getElementById('wpt-clear-profiler');
        var spinner    = document.getElementById('wpt-profiler-spinner');
        var resultsDiv = document.getElementById('wpt-profiler-results');

        if (runBtn) {
            runBtn.addEventListener('click', function () {
                runBtn.disabled = true;
                if (spinner) spinner.classList.add('is-active');

                var body = new FormData();
                body.append('action', 'wpt_run_profiler');
                body.append('nonce', config.nonce);

                fetch(config.ajaxUrl, { method: 'POST', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success && data.data.html && resultsDiv) {
                            resultsDiv.innerHTML = data.data.html;
                        } else {
                            var msg = (data.data && data.data.message) || 'Unknown error';
                            if (resultsDiv) {
                                resultsDiv.innerHTML = '<div class="notice notice-error inline"><p>' + msg + '</p></div>';
                            }
                        }
                    })
                    .catch(function () {
                        if (resultsDiv) {
                            resultsDiv.innerHTML = '<div class="notice notice-error inline"><p>' + config.i18n.networkError + '</p></div>';
                        }
                    })
                    .finally(function () {
                        runBtn.disabled = false;
                        if (spinner) spinner.classList.remove('is-active');
                    });
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!confirm(config.i18n.confirmClear)) return;

                clearBtn.disabled = true;

                var body = new FormData();
                body.append('action', 'wpt_clear_profiler');
                body.append('nonce', config.nonce);

                fetch(config.ajaxUrl, { method: 'POST', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success && resultsDiv) {
                            resultsDiv.innerHTML = '';
                            clearBtn.style.display = 'none';
                        }
                    })
                    .catch(function () {
                        alert(config.i18n.networkError);
                    })
                    .finally(function () {
                        clearBtn.disabled = false;
                    });
            });
        }
    });
})();
