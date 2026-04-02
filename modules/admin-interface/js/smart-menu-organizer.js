/**
 * Smart Menu Organizer — Drag-and-drop, collapsible sections, and context menu.
 *
 * Vanilla JS, no jQuery. Uses HTML5 Drag and Drop API.
 *
 * @package WPTransformed
 */
( function() {
    'use strict';

    if ( typeof wptSmartMenu === 'undefined' ) {
        return;
    }

    var config = wptSmartMenu;
    var adminMenu = document.getElementById( 'adminmenu' );

    if ( ! adminMenu ) {
        return;
    }

    // ── Section Headers ───────────────────────────────────────

    /**
     * Initialize section header click handlers for collapse/expand.
     */
    function initSectionHeaders() {
        var headers = adminMenu.querySelectorAll( '.wpt-section-header' );

        headers.forEach( function( headerLi ) {
            var link = headerLi.querySelector( 'a' );
            if ( ! link ) {
                return;
            }

            link.addEventListener( 'click', function( e ) {
                e.preventDefault();
                e.stopPropagation();
                toggleSection( headerLi );
            } );
        } );
    }

    /**
     * Toggle a section's collapse state.
     *
     * @param {HTMLElement} headerLi The section header <li> element.
     */
    function toggleSection( headerLi ) {
        var isCollapsed = headerLi.classList.contains( 'wpt-collapsed' );
        var sectionSlug = getSectionId( headerLi );

        if ( ! sectionSlug ) {
            return;
        }

        // Toggle visual state.
        headerLi.classList.toggle( 'wpt-collapsed' );

        // Toggle visibility of items in this section.
        var sibling = headerLi.nextElementSibling;
        while ( sibling && ! sibling.classList.contains( 'wpt-section-header' ) ) {
            if ( isCollapsed ) {
                sibling.classList.remove( 'wpt-section-hidden' );
            } else {
                sibling.classList.add( 'wpt-section-hidden' );
            }
            sibling = sibling.nextElementSibling;
        }

        // Save state via AJAX.
        var formData = new FormData();
        formData.append( 'action', 'wpt_toggle_section' );
        formData.append( '_nonce', config.nonce );
        formData.append( 'section_id', sectionSlug );
        formData.append( 'collapsed', isCollapsed ? '' : '1' );

        fetch( config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        } );
    }

    /**
     * Extract section ID from a section header's menu slug.
     *
     * @param {HTMLElement} headerLi
     * @return {string}
     */
    function getSectionId( headerLi ) {
        var link = headerLi.querySelector( 'a' );
        if ( ! link ) {
            return '';
        }
        var href = link.getAttribute( 'href' ) || '';
        // Section header slugs are wpt-section-{id}.
        var match = href.match( /wpt-section-(.+)/ );
        return match ? match[1] : '';
    }

    /**
     * Apply initial collapsed state to sections.
     */
    function applyInitialCollapse() {
        var headers = adminMenu.querySelectorAll( '.wpt-section-header.wpt-collapsed' );

        headers.forEach( function( headerLi ) {
            var sibling = headerLi.nextElementSibling;
            while ( sibling && ! sibling.classList.contains( 'wpt-section-header' ) ) {
                sibling.classList.add( 'wpt-section-hidden' );
                sibling = sibling.nextElementSibling;
            }
        } );
    }

    // ── Drag and Drop ─────────────────────────────────────────

    var draggedItem = null;
    var dragPlaceholder = null;

    /**
     * Initialize drag-and-drop on admin menu items.
     */
    function initDragAndDrop() {
        var menuItems = adminMenu.querySelectorAll( 'li:not(.wpt-section-header):not(.wp-menu-separator)' );

        menuItems.forEach( function( item ) {
            item.setAttribute( 'draggable', 'true' );

            item.addEventListener( 'dragstart', onDragStart );
            item.addEventListener( 'dragend', onDragEnd );
        } );

        adminMenu.addEventListener( 'dragover', onDragOver );
        adminMenu.addEventListener( 'drop', onDrop );
    }

    /**
     * @param {DragEvent} e
     */
    function onDragStart( e ) {
        draggedItem = e.currentTarget;
        draggedItem.classList.add( 'wpt-dragging' );

        // Create placeholder.
        dragPlaceholder = document.createElement( 'li' );
        dragPlaceholder.className = 'wpt-drag-placeholder';

        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData( 'text/plain', '' );
    }

    /**
     * @param {DragEvent} e
     */
    function onDragEnd( e ) {
        if ( draggedItem ) {
            draggedItem.classList.remove( 'wpt-dragging' );
        }

        if ( dragPlaceholder && dragPlaceholder.parentNode ) {
            dragPlaceholder.parentNode.removeChild( dragPlaceholder );
        }

        draggedItem = null;
        dragPlaceholder = null;
    }

    /**
     * @param {DragEvent} e
     */
    function onDragOver( e ) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        if ( ! draggedItem || ! dragPlaceholder ) {
            return;
        }

        var target = getDropTarget( e.target );
        if ( ! target || target === draggedItem || target === dragPlaceholder ) {
            return;
        }

        var rect = target.getBoundingClientRect();
        var midY = rect.top + rect.height / 2;

        if ( e.clientY < midY ) {
            target.parentNode.insertBefore( dragPlaceholder, target );
        } else {
            target.parentNode.insertBefore( dragPlaceholder, target.nextSibling );
        }
    }

    /**
     * @param {DragEvent} e
     */
    function onDrop( e ) {
        e.preventDefault();

        if ( ! draggedItem || ! dragPlaceholder || ! dragPlaceholder.parentNode ) {
            return;
        }

        // Insert dragged item where placeholder is.
        dragPlaceholder.parentNode.insertBefore( draggedItem, dragPlaceholder );
        dragPlaceholder.parentNode.removeChild( dragPlaceholder );

        draggedItem.classList.remove( 'wpt-dragging' );

        // Save new order.
        saveMenuOrder();

        draggedItem = null;
        dragPlaceholder = null;
    }

    /**
     * Get the closest <li> drop target from an event target.
     *
     * @param {HTMLElement} el
     * @return {HTMLElement|null}
     */
    function getDropTarget( el ) {
        while ( el && el !== adminMenu ) {
            if ( el.tagName === 'LI' && el.parentNode === adminMenu ) {
                return el;
            }
            el = el.parentNode;
        }
        return null;
    }

    /**
     * Read current menu order from DOM and save via AJAX.
     */
    function saveMenuOrder() {
        var sections = [];
        var currentSection = null;

        var children = adminMenu.children;
        for ( var i = 0; i < children.length; i++ ) {
            var li = children[i];

            if ( li.classList.contains( 'wpt-section-header' ) ) {
                var sectionId = getSectionId( li );
                if ( sectionId ) {
                    currentSection = {
                        id: sectionId,
                        label: getSectionLabel( sectionId ),
                        icon: getSectionIcon( sectionId ),
                        items: [],
                        collapsed: li.classList.contains( 'wpt-collapsed' )
                    };
                    sections.push( currentSection );
                }
                continue;
            }

            if ( li.classList.contains( 'wp-menu-separator' ) ) {
                continue;
            }

            var slug = getMenuSlug( li );
            if ( slug && currentSection ) {
                currentSection.items.push( slug );
            }
        }

        if ( sections.length === 0 ) {
            return;
        }

        var formData = new FormData();
        formData.append( 'action', 'wpt_save_menu_order' );
        formData.append( '_nonce', config.nonce );
        formData.append( 'sections', JSON.stringify( sections ) );

        fetch( config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        } );
    }

    /**
     * Get the menu slug from a menu <li> element.
     *
     * @param {HTMLElement} li
     * @return {string}
     */
    function getMenuSlug( li ) {
        var link = li.querySelector( 'a.menu-top, a.menu-top-first, a.menu-top-last' );
        if ( ! link ) {
            link = li.querySelector( 'a' );
        }
        if ( ! link ) {
            return '';
        }
        var href = link.getAttribute( 'href' ) || '';
        // Remove admin_url prefix if present.
        var match = href.match( /\/wp-admin\/(.+)/ );
        return match ? match[1] : href;
    }

    /**
     * Find a section config object by ID.
     *
     * @param {string} sectionId
     * @return {Object|null}
     */
    function findSection( sectionId ) {
        for ( var i = 0; i < config.sections.length; i++ ) {
            if ( config.sections[i].id === sectionId ) {
                return config.sections[i];
            }
        }
        return null;
    }

    /**
     * @param {string} sectionId
     * @return {string}
     */
    function getSectionLabel( sectionId ) {
        if ( sectionId === 'other' ) {
            return config.labels.other || 'Other';
        }
        var sec = findSection( sectionId );
        return sec ? sec.label : sectionId;
    }

    /**
     * @param {string} sectionId
     * @return {string}
     */
    function getSectionIcon( sectionId ) {
        if ( sectionId === 'other' ) {
            return 'dashicons-admin-plugins';
        }
        var sec = findSection( sectionId );
        return sec ? sec.icon : 'dashicons-admin-generic';
    }

    // ── Right-Click Context Menu ──────────────────────────────

    var contextMenu = null;

    /**
     * Initialize right-click context menu on menu items.
     */
    function initContextMenu() {
        // Create context menu element.
        contextMenu = document.createElement( 'div' );
        contextMenu.id = 'wpt-context-menu';
        contextMenu.className = 'wpt-context-menu';
        contextMenu.style.display = 'none';
        document.body.appendChild( contextMenu );

        // Listen for right-click on menu items.
        adminMenu.addEventListener( 'contextmenu', function( e ) {
            var target = getDropTarget( e.target );
            if ( ! target || target.classList.contains( 'wpt-section-header' ) || target.classList.contains( 'wp-menu-separator' ) ) {
                return;
            }

            // Only admins get context menu.
            e.preventDefault();
            showContextMenu( e.clientX, e.clientY, target );
        } );

        // Close on click outside.
        document.addEventListener( 'click', function() {
            hideContextMenu();
        } );

        document.addEventListener( 'keydown', function( e ) {
            if ( e.key === 'Escape' ) {
                hideContextMenu();
            }
        } );
    }

    /**
     * Show context menu at given position for a menu item.
     *
     * @param {number} x
     * @param {number} y
     * @param {HTMLElement} menuItem
     */
    function showContextMenu( x, y, menuItem ) {
        var slug = getMenuSlug( menuItem );
        if ( ! slug ) {
            return;
        }

        var html = '';

        // Hide option.
        html += '<div class="wpt-ctx-item" data-action="hide" data-slug="' + escAttr( slug ) + '">';
        html += '<span class="dashicons dashicons-hidden"></span> ' + escHtml( config.labels.hide );
        html += '</div>';

        // Rename option.
        html += '<div class="wpt-ctx-item" data-action="rename" data-slug="' + escAttr( slug ) + '">';
        html += '<span class="dashicons dashicons-edit"></span> ' + escHtml( config.labels.rename );
        html += '</div>';

        // Move to... submenu.
        html += '<div class="wpt-ctx-item wpt-ctx-has-sub" data-slug="' + escAttr( slug ) + '">';
        html += '<span class="dashicons dashicons-move"></span> ' + escHtml( config.labels.moveTo );
        html += '<div class="wpt-ctx-submenu">';
        for ( var i = 0; i < config.sections.length; i++ ) {
            var sec = config.sections[i];
            html += '<div class="wpt-ctx-item" data-action="move" data-slug="' + escAttr( slug ) + '" data-section="' + escAttr( sec.id ) + '">';
            html += '<span class="dashicons ' + escAttr( sec.icon ) + '"></span> ' + escHtml( sec.label );
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';

        // Change icon option.
        html += '<div class="wpt-ctx-item" data-action="icon" data-slug="' + escAttr( slug ) + '">';
        html += '<span class="dashicons dashicons-art"></span> ' + escHtml( config.labels.changeIcon );
        html += '</div>';

        contextMenu.innerHTML = html;
        contextMenu.style.display = 'block';
        contextMenu.style.left = x + 'px';
        contextMenu.style.top = y + 'px';

        // Ensure menu stays in viewport.
        var rect = contextMenu.getBoundingClientRect();
        if ( rect.right > window.innerWidth ) {
            contextMenu.style.left = ( x - rect.width ) + 'px';
        }
        if ( rect.bottom > window.innerHeight ) {
            contextMenu.style.top = ( y - rect.height ) + 'px';
        }

        // Attach click handlers.
        var items = contextMenu.querySelectorAll( '.wpt-ctx-item[data-action]' );
        items.forEach( function( item ) {
            item.addEventListener( 'click', function( e ) {
                e.stopPropagation();
                handleContextAction( item );
                hideContextMenu();
            } );
        } );
    }

    /**
     * Hide the context menu.
     */
    function hideContextMenu() {
        if ( contextMenu ) {
            contextMenu.style.display = 'none';
        }
    }

    /**
     * Handle a context menu action click.
     *
     * @param {HTMLElement} item
     */
    function handleContextAction( item ) {
        var action = item.getAttribute( 'data-action' );
        var slug = item.getAttribute( 'data-slug' );

        if ( ! action || ! slug ) {
            return;
        }

        var formData = new FormData();
        formData.append( 'action', 'wpt_menu_context_action' );
        formData.append( '_nonce', config.nonce );
        formData.append( 'action_type', action );
        formData.append( 'menu_slug', slug );

        switch ( action ) {
            case 'hide':
                formData.append( 'role', 'all' );
                sendContextAction( formData, function() {
                    // Hide the item visually.
                    var menuItems = adminMenu.querySelectorAll( 'li' );
                    menuItems.forEach( function( li ) {
                        if ( getMenuSlug( li ) === slug ) {
                            li.style.display = 'none';
                        }
                    } );
                } );
                break;

            case 'rename':
                var newLabel = prompt( config.labels.renamePrompt );
                if ( newLabel && newLabel.trim() !== '' ) {
                    formData.append( 'new_label', newLabel.trim() );
                    sendContextAction( formData, function() {
                        // Update label visually.
                        var menuItems = adminMenu.querySelectorAll( 'li' );
                        menuItems.forEach( function( li ) {
                            if ( getMenuSlug( li ) === slug ) {
                                var nameEl = li.querySelector( '.wp-menu-name' );
                                if ( nameEl ) {
                                    nameEl.textContent = newLabel.trim();
                                }
                            }
                        } );
                    } );
                }
                break;

            case 'move':
                var section = item.getAttribute( 'data-section' );
                if ( section ) {
                    formData.append( 'target_section', section );
                    sendContextAction( formData, function() {
                        // Move requires page reload to re-render menu.
                        window.location.reload();
                    } );
                }
                break;

            case 'icon':
                var newIcon = prompt( 'Enter dashicon class (e.g., dashicons-admin-post):' );
                if ( newIcon && /^dashicons-[a-z0-9-]+$/.test( newIcon ) ) {
                    formData.append( 'new_icon', newIcon );
                    sendContextAction( formData, function() {
                        window.location.reload();
                    } );
                }
                break;
        }
    }

    /**
     * Send a context action AJAX request.
     *
     * @param {FormData} formData
     * @param {Function} onSuccess
     */
    function sendContextAction( formData, onSuccess ) {
        fetch( config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        } )
        .then( function( response ) {
            return response.json();
        } )
        .then( function( data ) {
            if ( data.success && typeof onSuccess === 'function' ) {
                onSuccess();
            }
        } );
    }

    // ── Plugin Placement Notice ───────────────────────────────

    /**
     * Initialize event listeners for the plugin placement notice.
     */
    function initPluginPlacementNotice() {
        var notice = document.getElementById( 'wpt-plugin-placement-notice' );
        if ( ! notice ) {
            return;
        }

        var nonce = notice.getAttribute( 'data-nonce' );
        var pluginSlug = notice.getAttribute( 'data-plugin-slug' );

        // Place buttons.
        var placeButtons = notice.querySelectorAll( '.wpt-place-plugin-btn' );
        placeButtons.forEach( function( btn ) {
            btn.addEventListener( 'click', function() {
                var section = btn.getAttribute( 'data-section' );
                placePlugin( pluginSlug, section, nonce, notice );
            } );
        } );

        // Dismiss button.
        var dismissBtn = notice.querySelector( '.wpt-dismiss-plugin-notice' );
        if ( dismissBtn ) {
            dismissBtn.addEventListener( 'click', function() {
                dismissPluginNotice( nonce, notice );
            } );
        }
    }

    /**
     * Place a plugin into a section via AJAX.
     *
     * @param {string} pluginSlug
     * @param {string} sectionId
     * @param {string} nonce
     * @param {HTMLElement} notice
     */
    function placePlugin( pluginSlug, sectionId, nonce, notice ) {
        var formData = new FormData();
        formData.append( 'action', 'wpt_place_plugin_menu' );
        formData.append( '_nonce', nonce );
        formData.append( 'plugin_slug', pluginSlug );
        formData.append( 'target_section', sectionId );

        fetch( config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        } )
        .then( function( response ) {
            return response.json();
        } )
        .then( function( data ) {
            if ( data.success ) {
                notice.style.display = 'none';
            }
        } );
    }

    /**
     * Dismiss the plugin placement notice via AJAX.
     *
     * @param {string} nonce
     * @param {HTMLElement} notice
     */
    function dismissPluginNotice( nonce, notice ) {
        var formData = new FormData();
        formData.append( 'action', 'wpt_dismiss_plugin_notice' );
        formData.append( '_nonce', nonce );

        fetch( config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        } )
        .then( function() {
            notice.style.display = 'none';
        } );
    }

    // ── Section Header Labels ─────────────────────────────────

    /**
     * Inject section labels into section header <li> elements.
     * WordPress renders menu items with specific structure; section headers
     * need labels injected after page load.
     */
    function injectSectionLabels() {
        var headers = adminMenu.querySelectorAll( '.wpt-section-header' );

        headers.forEach( function( headerLi ) {
            var sectionId = getSectionId( headerLi );
            if ( ! sectionId ) {
                return;
            }

            var label = getSectionLabel( sectionId );
            var icon = getSectionIcon( sectionId );
            var link = headerLi.querySelector( 'a' );

            if ( link ) {
                // Build section header content.
                link.innerHTML = '<span class="wpt-section-icon dashicons ' + escAttr( icon ) + '"></span>' +
                    '<span class="wpt-section-label">' + escHtml( label ) + '</span>' +
                    '<span class="wpt-section-arrow dashicons dashicons-arrow-down-alt2"></span>';
            }
        } );
    }

    // ── Settings Page: Reset Button ───────────────────────────

    function initResetButton() {
        var resetBtn = document.getElementById( 'wpt-smo-reset' );
        if ( ! resetBtn ) {
            return;
        }

        resetBtn.addEventListener( 'click', function() {
            var initDataEl = document.getElementById( 'wpt-smo-init-data' );
            if ( ! initDataEl ) {
                return;
            }

            var initData;
            try {
                initData = JSON.parse( initDataEl.textContent );
            } catch ( e ) {
                return;
            }

            if ( initData.defaults ) {
                var sectionsInput = document.getElementById( 'wpt-smo-sections-json' );
                var hiddenInput = document.getElementById( 'wpt-smo-hidden-json' );
                var renamedInput = document.getElementById( 'wpt-smo-renamed-json' );
                var iconsInput = document.getElementById( 'wpt-smo-icons-json' );

                if ( sectionsInput ) {
                    sectionsInput.value = JSON.stringify( initData.defaults );
                }
                if ( hiddenInput ) {
                    hiddenInput.value = '{}';
                }
                if ( renamedInput ) {
                    renamedInput.value = '{}';
                }
                if ( iconsInput ) {
                    iconsInput.value = '{}';
                }
            }
        } );
    }

    // ── Utilities ─────────────────────────────────────────────

    /**
     * Escape a string for use in HTML attributes.
     *
     * @param {string} str
     * @return {string}
     */
    function escAttr( str ) {
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( str || '' ) );
        return div.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
    }

    /**
     * Escape a string for use in HTML content.
     *
     * @param {string} str
     * @return {string}
     */
    function escHtml( str ) {
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( str || '' ) );
        return div.innerHTML;
    }

    // ── Init ──────────────────────────────────────────────────

    function init() {
        injectSectionLabels();
        applyInitialCollapse();
        initSectionHeaders();
        initDragAndDrop();
        initContextMenu();
        initPluginPlacementNotice();
        initResetButton();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
