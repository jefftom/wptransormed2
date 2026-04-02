/**
 * Database Cleanup — Vanilla JS (no jQuery).
 *
 * Handles scan, per-category cleanup, bulk cleanup with batch polling,
 * and optional table optimization.
 *
 * @package WPTransformed
 */
(function() {
    'use strict';

    // Bail if config not available.
    if ( typeof wptDbCleanup === 'undefined' ) {
        return;
    }

    var config = wptDbCleanup;
    var i18n   = config.i18n || {};

    // ── DOM References ────────────────────────────────────────

    var scanBtn       = document.getElementById( 'wpt-db-scan-btn' );
    var cleanAllBtn   = document.getElementById( 'wpt-db-clean-all-btn' );
    var resultsTable  = document.getElementById( 'wpt-db-results-table' );
    var resultsBody   = document.getElementById( 'wpt-db-results-body' );
    var totalCount    = document.getElementById( 'wpt-db-total-count' );
    var totalSize     = document.getElementById( 'wpt-db-total-size' );
    var progressWrap  = document.getElementById( 'wpt-db-progress' );
    var progressFill  = progressWrap ? progressWrap.querySelector( '.wpt-db-progress-fill' ) : null;
    var progressText  = progressWrap ? progressWrap.querySelector( '.wpt-db-progress-text' ) : null;
    var cleanupResult = document.getElementById( 'wpt-db-cleanup-results' );
    var cleanupSummary = document.getElementById( 'wpt-db-cleanup-summary' );

    // Store scan data for cleanup operations.
    var scanData = {};

    // ── Utility: AJAX Request ─────────────────────────────────

    function ajaxPost( action, data, callback ) {
        var formData = new FormData();
        formData.append( 'action', action );
        formData.append( 'nonce', config.nonce );

        if ( data ) {
            for ( var key in data ) {
                if ( data.hasOwnProperty( key ) ) {
                    formData.append( key, data[key] );
                }
            }
        }

        var xhr = new XMLHttpRequest();
        xhr.open( 'POST', config.ajaxUrl, true );
        xhr.onreadystatechange = function() {
            if ( xhr.readyState === 4 ) {
                if ( xhr.status === 200 ) {
                    try {
                        var response = JSON.parse( xhr.responseText );
                        callback( null, response );
                    } catch( e ) {
                        callback( new Error( 'Invalid JSON response' ) );
                    }
                } else {
                    callback( new Error( 'HTTP ' + xhr.status ) );
                }
            }
        };
        xhr.send( formData );
    }

    // ── Utility: Format Bytes ─────────────────────────────────

    function formatBytes( bytes ) {
        if ( bytes === 0 ) return '0 B';
        var units = [ 'B', 'KB', 'MB', 'GB' ];
        var i = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );
        if ( i >= units.length ) i = units.length - 1;
        return ( bytes / Math.pow( 1024, i ) ).toFixed( 1 ) + ' ' + units[i];
    }

    // ── Utility: Format Number ────────────────────────────────

    function formatNumber( num ) {
        return num.toString().replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
    }

    // ── Progress Bar ──────────────────────────────────────────

    function showProgress( text, percent ) {
        if ( progressWrap ) progressWrap.style.display = 'block';
        if ( progressText ) progressText.textContent = text || '';
        if ( progressFill ) progressFill.style.width = ( percent || 0 ) + '%';
    }

    function hideProgress() {
        if ( progressWrap ) progressWrap.style.display = 'none';
    }

    // ── Scan ──────────────────────────────────────────────────

    function doScan() {
        if ( ! scanBtn ) return;

        scanBtn.disabled = true;
        scanBtn.textContent = i18n.scanning || 'Scanning...';
        hideCleanupResults();
        showProgress( i18n.scanning || 'Scanning database...', 50 );

        ajaxPost( 'wpt_db_cleanup_scan', {}, function( err, response ) {
            scanBtn.disabled = false;
            scanBtn.textContent = i18n.scanBtn || 'Scan Database';
            hideProgress();

            if ( err || ! response.success ) {
                alert( i18n.error || 'An error occurred.' );
                return;
            }

            scanData = response.data.categories;
            renderResults( scanData, response.data.total_size );
        });
    }

    // ── Render Results Table ──────────────────────────────────

    function renderResults( categories, totalSizeBytes ) {
        if ( ! resultsBody || ! resultsTable ) return;

        resultsBody.innerHTML = '';
        var grandCount = 0;

        var keys = Object.keys( categories );
        for ( var i = 0; i < keys.length; i++ ) {
            var cat     = keys[i];
            var data    = categories[cat];
            var count   = data.count || 0;
            var size    = data.size || 0;
            var label   = data.label || cat;

            grandCount += count;

            var tr = document.createElement( 'tr' );
            tr.setAttribute( 'data-category', cat );

            tr.innerHTML =
                '<td>' + escHtml( label ) + '</td>' +
                '<td class="wpt-db-count">' + formatNumber( count ) + '</td>' +
                '<td class="wpt-db-size">' + formatBytes( size ) + '</td>' +
                '<td class="wpt-db-status">' + ( count > 0 ? '<span style="color:#b32d2e;">&#9679;</span>' : '<span style="color:#00a32a;">&#9679;</span>' ) + '</td>' +
                '<td class="wpt-db-action">' +
                    ( count > 0
                        ? '<button type="button" class="button button-small wpt-db-clean-btn" data-category="' + escAttr( cat ) + '">' + escHtml( i18n.clean || 'Clean' ) + '</button>'
                        : '<span style="color:#999;">&mdash;</span>'
                    ) +
                '</td>';

            resultsBody.appendChild( tr );
        }

        // Add optimize row if enabled.
        if ( config.optimizeEnabled ) {
            var optTr = document.createElement( 'tr' );
            optTr.setAttribute( 'data-category', 'optimize_tables' );
            optTr.innerHTML =
                '<td><strong>' + escHtml( i18n.optimizeBtn || 'Optimize Tables' ) + '</strong></td>' +
                '<td>&mdash;</td>' +
                '<td>&mdash;</td>' +
                '<td>&mdash;</td>' +
                '<td><button type="button" class="button button-small wpt-db-clean-btn" data-category="optimize_tables">' + escHtml( i18n.optimizeBtn || 'Optimize' ) + '</button></td>';
            resultsBody.appendChild( optTr );
        }

        // Totals.
        if ( totalCount ) totalCount.textContent = formatNumber( grandCount );
        if ( totalSize )  totalSize.textContent  = formatBytes( totalSizeBytes || 0 );

        resultsTable.style.display = '';

        // Enable Clean All if there are items.
        if ( cleanAllBtn ) {
            cleanAllBtn.disabled = ( grandCount === 0 );
        }

        // Bind clean buttons.
        bindCleanButtons();
    }

    // ── Bind Clean Buttons ────────────────────────────────────

    function bindCleanButtons() {
        var buttons = document.querySelectorAll( '.wpt-db-clean-btn' );
        for ( var i = 0; i < buttons.length; i++ ) {
            buttons[i].addEventListener( 'click', handleCleanClick );
        }
    }

    function handleCleanClick( e ) {
        var btn      = e.target;
        var category = btn.getAttribute( 'data-category' );

        if ( ! category ) return;

        btn.disabled = true;
        btn.textContent = i18n.cleaning || 'Cleaning...';

        if ( category === 'optimize_tables' ) {
            showProgress( i18n.optimizing || 'Optimizing tables...', 50 );
        }

        doCleanup( category, btn );
    }

    // ── Single Category Cleanup (with batch polling) ──────────

    function doCleanup( category, btn ) {
        ajaxPost( 'wpt_db_cleanup_run', { category: category }, function( err, response ) {
            if ( err || ! response.success ) {
                alert( i18n.error || 'An error occurred.' );
                if ( btn ) {
                    btn.disabled = false;
                    btn.textContent = i18n.clean || 'Clean';
                }
                hideProgress();
                return;
            }

            var data = response.data;

            if ( category === 'optimize_tables' ) {
                hideProgress();
                if ( btn ) {
                    btn.textContent = i18n.optimized || 'Done';
                    btn.disabled = true;
                }
                showCleanupResults( i18n.optimized || 'Tables optimized.' );
                return;
            }

            // If more rows remain, poll again (batch processing).
            if ( data.continue ) {
                showProgress(
                    ( i18n.cleaningBatch || 'Processing batch...' ) + ' (' + formatNumber( data.deleted ) + ' deleted)',
                    50
                );
                doCleanup( category, btn );
                return;
            }

            // Done — update the row.
            hideProgress();
            updateRowAfterClean( category );

            if ( btn ) {
                btn.textContent = i18n.cleaned || 'Cleaned';
                btn.disabled = true;
            }
        });
    }

    // ── Clean All ─────────────────────────────────────────────

    function doCleanAll() {
        if ( ! confirm( i18n.confirmCleanAll || 'Clean all selected categories?' ) ) {
            return;
        }

        cleanAllBtn.disabled = true;
        showProgress( i18n.cleaning || 'Cleaning up...', 10 );
        disableAllCleanButtons();

        runCleanAllBatch( 0 );
    }

    function runCleanAllBatch( totalDeleted ) {
        ajaxPost( 'wpt_db_cleanup_run', { category: 'all' }, function( err, response ) {
            if ( err || ! response.success ) {
                alert( i18n.error || 'An error occurred.' );
                hideProgress();
                enableAllCleanButtons();
                return;
            }

            var data    = response.data;
            var deleted = ( data.deleted || 0 ) + totalDeleted;

            if ( data.continue ) {
                showProgress(
                    ( i18n.cleaningBatch || 'Processing...' ) + ' ' + formatNumber( deleted ) + ' items cleaned',
                    50
                );
                runCleanAllBatch( deleted );
                return;
            }

            // All done.
            hideProgress();
            showCleanupResults(
                ( i18n.complete || 'Cleanup complete!' ) + ' ' +
                formatNumber( deleted ) + ' items removed.'
            );

            // Re-scan to update counts.
            doScan();
        });
    }

    // ── UI Helpers ────────────────────────────────────────────

    function updateRowAfterClean( category ) {
        var row = resultsBody ? resultsBody.querySelector( 'tr[data-category="' + category + '"]' ) : null;
        if ( ! row ) return;

        var countCell  = row.querySelector( '.wpt-db-count' );
        var sizeCell   = row.querySelector( '.wpt-db-size' );
        var statusCell = row.querySelector( '.wpt-db-status' );

        if ( countCell )  countCell.textContent  = '0';
        if ( sizeCell )   sizeCell.textContent   = '0 B';
        if ( statusCell ) statusCell.innerHTML   = '<span style="color:#00a32a;">&#9679;</span>';

        // Update scanData.
        if ( scanData[ category ] ) {
            scanData[ category ].count = 0;
            scanData[ category ].size  = 0;
        }

        // Recalculate totals.
        recalcTotals();
    }

    function recalcTotals() {
        var grandCount = 0;
        var grandSize  = 0;
        var keys = Object.keys( scanData );

        for ( var i = 0; i < keys.length; i++ ) {
            grandCount += ( scanData[ keys[i] ].count || 0 );
            grandSize  += ( scanData[ keys[i] ].size  || 0 );
        }

        if ( totalCount ) totalCount.textContent = formatNumber( grandCount );
        if ( totalSize )  totalSize.textContent  = formatBytes( grandSize );

        if ( cleanAllBtn ) {
            cleanAllBtn.disabled = ( grandCount === 0 );
        }
    }

    function disableAllCleanButtons() {
        var buttons = document.querySelectorAll( '.wpt-db-clean-btn' );
        for ( var i = 0; i < buttons.length; i++ ) {
            buttons[i].disabled = true;
        }
    }

    function enableAllCleanButtons() {
        var buttons = document.querySelectorAll( '.wpt-db-clean-btn' );
        for ( var i = 0; i < buttons.length; i++ ) {
            buttons[i].disabled = false;
        }
    }

    function showCleanupResults( message ) {
        if ( cleanupResult ) cleanupResult.style.display = 'block';
        if ( cleanupSummary ) cleanupSummary.textContent = message;
    }

    function hideCleanupResults() {
        if ( cleanupResult ) cleanupResult.style.display = 'none';
    }

    // ── Escape Helpers ────────────────────────────────────────

    function escHtml( str ) {
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( str ) );
        return div.innerHTML;
    }

    function escAttr( str ) {
        return str.replace( /&/g, '&amp;' )
                  .replace( /"/g, '&quot;' )
                  .replace( /'/g, '&#39;' )
                  .replace( /</g, '&lt;' )
                  .replace( />/g, '&gt;' );
    }

    // ── Event Bindings ────────────────────────────────────────

    if ( scanBtn ) {
        scanBtn.addEventListener( 'click', doScan );
    }

    if ( cleanAllBtn ) {
        cleanAllBtn.addEventListener( 'click', doCleanAll );
    }

})();
