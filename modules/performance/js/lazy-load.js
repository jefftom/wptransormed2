/**
 * WPTransformed — Lazy Load Intersection Observer
 *
 * Enhances native lazy loading with a configurable viewport threshold.
 * Only loaded when a custom threshold is set in module settings.
 */
( function () {
    'use strict';

    if ( typeof IntersectionObserver === 'undefined' ) {
        return;
    }

    var config = window.wptLazyLoad || {};
    var threshold = config.threshold || '200px';

    var observer = new IntersectionObserver(
        function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( ! entry.isIntersecting ) {
                    return;
                }

                var el = entry.target;

                if ( el.dataset.wptSrc ) {
                    el.src = el.dataset.wptSrc;
                    el.removeAttribute( 'data-wpt-src' );
                }

                if ( el.dataset.wptSrcset ) {
                    el.srcset = el.dataset.wptSrcset;
                    el.removeAttribute( 'data-wpt-srcset' );
                }

                el.removeAttribute( 'loading' );
                observer.unobserve( el );
            } );
        },
        {
            rootMargin: threshold,
        }
    );

    function observeElements() {
        var selectors = 'img[loading="lazy"], iframe[loading="lazy"]';
        var elements = document.querySelectorAll( selectors );

        elements.forEach( function ( el ) {
            observer.observe( el );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', observeElements );
    } else {
        observeElements();
    }
} )();
