/**
 * WPTransformed -- Image Upload Control Settings
 *
 * Bulk optimization AJAX handler with progress bar.
 * No jQuery dependency.
 */
(function () {
    'use strict';

    var config = window.wptImageUploadControl || {};
    var ajaxUrl = config.ajaxUrl || '';
    var nonce = config.nonce || '';
    var i18n = config.i18n || {};

    var running = false;
    var totalOptimized = 0;

    // ── DOM Elements ─────────────────────────────────────────

    var startBtn = document.getElementById('wpt-bulk-optimize-start');
    var stopBtn = document.getElementById('wpt-bulk-optimize-stop');
    var spinner = document.getElementById('wpt-bulk-spinner');
    var progressWrap = document.getElementById('wpt-bulk-progress');
    var progressBar = document.getElementById('wpt-progress-bar');
    var statusText = document.getElementById('wpt-bulk-status');
    var resultDiv = document.getElementById('wpt-bulk-result');

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Escape HTML entities.
     *
     * @param {string} str
     * @return {string}
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /**
     * Show result message.
     *
     * @param {string} message
     * @param {string} type 'success' or 'error'
     */
    function showResult(message, type) {
        if (!resultDiv) return;
        resultDiv.style.display = '';
        resultDiv.className = type === 'success'
            ? 'notice notice-success inline'
            : 'notice notice-error inline';
        resultDiv.innerHTML = '<p>' + escapeHtml(message) + '</p>';
    }

    /**
     * Update progress bar width.
     *
     * @param {number} percent 0-100
     */
    function setProgress(percent) {
        if (progressBar) {
            progressBar.style.width = Math.min(100, Math.max(0, percent)) + '%';
        }
    }

    /**
     * Set UI to running state.
     */
    function setRunning() {
        running = true;
        if (startBtn) startBtn.disabled = true;
        if (stopBtn) stopBtn.style.display = '';
        if (spinner) spinner.classList.add('is-active');
        if (progressWrap) progressWrap.style.display = '';
        if (resultDiv) resultDiv.style.display = 'none';
    }

    /**
     * Set UI to stopped state.
     */
    function setStopped() {
        running = false;
        if (startBtn) {
            startBtn.disabled = false;
            startBtn.textContent = i18n.start || 'Start Bulk Optimization';
        }
        if (stopBtn) stopBtn.style.display = 'none';
        if (spinner) spinner.classList.remove('is-active');
    }

    // ── Batch Processing ─────────────────────────────────────

    /**
     * Process a single batch at the given offset.
     *
     * @param {number} offset
     */
    function processBatch(offset) {
        if (!running) {
            showResult(i18n.stopped || 'Optimization stopped.', 'success');
            setStopped();
            return;
        }

        var data = new FormData();
        data.append('action', 'wpt_bulk_optimize_images');
        data.append('nonce', nonce);
        data.append('offset', String(offset));

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (result) {
            if (!result.success) {
                showResult(result.data.message || i18n.error || 'An error occurred.', 'error');
                setStopped();
                return;
            }

            var d = result.data;
            totalOptimized += d.optimized || 0;

            // Update progress.
            if (d.total > 0) {
                var percent = Math.round((Math.min(d.offset, d.total) / d.total) * 100);
                setProgress(percent);
            }

            if (statusText) {
                statusText.textContent = d.message || '';
            }

            if (d.done) {
                setProgress(100);
                var completeMsg = (i18n.complete || 'Bulk optimization complete!') +
                    ' (' + totalOptimized + ' optimized)';
                showResult(completeMsg, 'success');
                setStopped();
            } else {
                // Process next batch.
                processBatch(d.offset);
            }
        })
        .catch(function () {
            showResult(i18n.networkError || 'Network error. Please try again.', 'error');
            setStopped();
        });
    }

    // ── Event Listeners ──────────────────────────────────────

    if (startBtn) {
        startBtn.addEventListener('click', function () {
            totalOptimized = 0;
            setProgress(0);
            if (statusText) {
                statusText.textContent = i18n.starting || 'Starting optimization...';
            }
            setRunning();
            processBatch(0);
        });
    }

    if (stopBtn) {
        stopBtn.addEventListener('click', function () {
            running = false;
        });
    }
})();
