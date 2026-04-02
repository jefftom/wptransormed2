/**
 * WPTransformed — Broken Link Checker Admin JS
 */
( function () {
    'use strict';

    var config = window.wptBLC || {};
    if ( ! config.ajaxUrl ) return;

    var currentPage = 1;

    // ── Helpers ───────────────────────────────────────────────

    function post( action, data ) {
        var fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce', config.nonce );
        for ( var k in data ) {
            if ( data.hasOwnProperty( k ) ) fd.append( k, data[ k ] );
        }
        return fetch( config.ajaxUrl, { method: 'POST', body: fd } )
            .then( function ( r ) { return r.json(); } );
    }

    function esc( str ) {
        var d = document.createElement( 'div' );
        d.textContent = str || '';
        return d.innerHTML;
    }

    function safeHref( url ) {
        if ( ! url ) return '';
        // Only allow http(s) and relative URLs — block javascript:, data:, etc.
        if ( /^https?:\/\//.test( url ) || url.charAt( 0 ) === '/' ) return esc( url );
        return '';
    }

    function statusBadge( code ) {
        var c = parseInt( code, 10 );
        var cls = 'wpt-blc-badge';
        if ( c >= 200 && c < 300 ) cls += ' wpt-blc-ok';
        else if ( c >= 300 && c < 400 ) cls += ' wpt-blc-redirect';
        else if ( c >= 400 ) cls += ' wpt-blc-broken';
        else cls += ' wpt-blc-unknown';
        return '<span class="' + cls + '">' + ( c || '?' ) + '</span>';
    }

    // ── Scan Now ──────────────────────────────────────────────

    var scanBtn = document.getElementById( 'wpt-blc-scan-now' );
    var scanStatus = document.getElementById( 'wpt-blc-scan-status' );

    if ( scanBtn ) {
        scanBtn.addEventListener( 'click', function () {
            scanBtn.disabled = true;
            scanStatus.textContent = config.i18n.scanning || 'Scanning...';

            post( 'wpt_blc_scan_now', {} ).then( function ( r ) {
                scanStatus.textContent = r.data && r.data.message ? r.data.message : '';
                scanBtn.disabled = false;
            } ).catch( function () {
                scanStatus.textContent = config.i18n.error || 'Error';
                scanBtn.disabled = false;
            } );
        } );
    }

    // ── Load Results ──────────────────────────────────────────

    var loadBtn   = document.getElementById( 'wpt-blc-load-results' );
    var tbody     = document.getElementById( 'wpt-blc-tbody' );
    var table     = document.getElementById( 'wpt-blc-table' );
    var emptyMsg  = document.getElementById( 'wpt-blc-empty' );
    var pagDiv    = document.getElementById( 'wpt-blc-pagination' );

    function loadResults( page ) {
        if ( ! tbody ) return;
        currentPage = page || 1;

        var statusSel = document.getElementById( 'wpt-blc-filter-status' );
        var typeSel   = document.getElementById( 'wpt-blc-filter-type' );

        tbody.innerHTML = '<tr><td colspan="5">' + esc( config.i18n.loading ) + '</td></tr>';
        table.style.display = '';
        emptyMsg.style.display = 'none';

        post( 'wpt_blc_fetch_results', {
            status:    statusSel ? statusSel.value : 'broken',
            link_type: typeSel ? typeSel.value : '',
            page:      currentPage
        } ).then( function ( r ) {
            if ( ! r.success || ! r.data.results.length ) {
                table.style.display = 'none';
                emptyMsg.style.display = '';
                pagDiv.innerHTML = '';
                return;
            }

            var html = '';
            r.data.results.forEach( function ( link ) {
                html += '<tr data-id="' + link.id + '">';
                html += '<td class="wpt-blc-url-cell" title="' + esc( link.url ) + '">' + esc( link.url.substring( 0, 80 ) ) + ( link.url.length > 80 ? '...' : '' ) + '</td>';
                html += '<td>' + statusBadge( link.status_code ) + '</td>';
                html += '<td>';
                if ( link.post_title && safeHref( link.edit_url ) ) {
                    html += '<a href="' + safeHref( link.edit_url ) + '">' + esc( link.post_title ) + '</a>';
                } else if ( link.post_title ) {
                    html += esc( link.post_title );
                }
                html += '</td>';
                html += '<td>' + esc( link.link_type ) + '</td>';
                html += '<td class="wpt-blc-actions">';
                if ( safeHref( link.edit_url ) ) {
                    html += '<a href="' + safeHref( link.edit_url ) + '" class="button button-small">' + 'Edit' + '</a> ';
                }
                html += '<button type="button" class="button button-small wpt-blc-recheck" data-id="' + link.id + '">Recheck</button> ';
                html += '<button type="button" class="button button-small wpt-blc-unlink" data-id="' + link.id + '">Unlink</button> ';
                html += '<button type="button" class="button button-small wpt-blc-dismiss" data-id="' + link.id + '">Dismiss</button>';
                html += '</td>';
                html += '</tr>';
            } );
            tbody.innerHTML = html;

            // Pagination.
            var pHtml = '';
            if ( r.data.pages > 1 ) {
                if ( currentPage > 1 ) pHtml += '<button type="button" class="button wpt-blc-page" data-page="' + ( currentPage - 1 ) + '">&laquo; Prev</button> ';
                pHtml += 'Page ' + r.data.page + ' of ' + r.data.pages;
                if ( currentPage < r.data.pages ) pHtml += ' <button type="button" class="button wpt-blc-page" data-page="' + ( currentPage + 1 ) + '">Next &raquo;</button>';
            }
            pagDiv.innerHTML = pHtml;
        } ).catch( function () {
            tbody.innerHTML = '<tr><td colspan="5">' + esc( config.i18n.error ) + '</td></tr>';
        } );
    }

    if ( loadBtn ) {
        loadBtn.addEventListener( 'click', function () { loadResults( 1 ); } );
    }

    // ── Delegated Actions ─────────────────────────────────────

    document.addEventListener( 'click', function ( e ) {
        var btn;

        // Pagination.
        btn = e.target.closest( '.wpt-blc-page' );
        if ( btn ) {
            loadResults( parseInt( btn.dataset.page, 10 ) );
            return;
        }

        // Dismiss.
        btn = e.target.closest( '.wpt-blc-dismiss' );
        if ( btn ) {
            btn.disabled = true;
            post( 'wpt_blc_dismiss', { link_id: btn.dataset.id } ).then( function ( r ) {
                if ( r.success ) {
                    var row = btn.closest( 'tr' );
                    if ( row ) row.style.opacity = '0.3';
                }
            } );
            return;
        }

        // Unlink.
        btn = e.target.closest( '.wpt-blc-unlink' );
        if ( btn ) {
            if ( ! confirm( config.i18n.confirmUnlink || 'Remove this link?' ) ) return;
            btn.disabled = true;
            post( 'wpt_blc_unlink', { link_id: btn.dataset.id } ).then( function ( r ) {
                if ( r.success ) {
                    var row = btn.closest( 'tr' );
                    if ( row ) row.style.opacity = '0.3';
                }
            } );
            return;
        }

        // Recheck.
        btn = e.target.closest( '.wpt-blc-recheck' );
        if ( btn ) {
            btn.disabled = true;
            btn.textContent = '...';
            post( 'wpt_blc_recheck', { link_id: btn.dataset.id } ).then( function ( r ) {
                btn.disabled = false;
                btn.textContent = 'Recheck';
                if ( r.success && r.data ) {
                    var row = btn.closest( 'tr' );
                    if ( row ) {
                        var statusCell = row.children[1];
                        if ( statusCell ) statusCell.innerHTML = statusBadge( r.data.status_code );
                    }
                }
            } ).catch( function () {
                btn.disabled = false;
                btn.textContent = 'Recheck';
            } );
            return;
        }
    } );

    // Auto-load broken results on page load.
    if ( tbody ) {
        loadResults( 1 );
    }
} )();
