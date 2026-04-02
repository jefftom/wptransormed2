/**
 * WPTransformed — Media Folders
 *
 * Renders a folder sidebar in the media library (grid + list views)
 * and supports drag-and-drop assignment of media to folders.
 *
 * @package WPTransformed
 */
(function( $, wp ) {
    'use strict';

    if ( typeof wptMediaFolders === 'undefined' ) {
        return;
    }

    var config = wptMediaFolders;
    var i18n   = config.i18n;

    // ── State ─────────────────────────────────────────────────

    var state = {
        folders: [],
        activeFolder: null, // null = all, 0 = uncategorized, term_id = folder
        isLoading: false,
    };

    // ── Helpers ───────────────────────────────────────────────

    function ajax( action, data ) {
        data = data || {};
        data.action = action;
        data._nonce = config.nonce;

        return $.ajax({
            url:      config.ajaxUrl,
            type:     'POST',
            data:     data,
            dataType: 'json',
        });
    }

    function buildTree( folders, parentId ) {
        parentId = parentId || 0;
        var children = [];
        for ( var i = 0; i < folders.length; i++ ) {
            if ( folders[i].parent === parentId ) {
                var node = $.extend( {}, folders[i] );
                node.children = buildTree( folders, node.term_id );
                children.push( node );
            }
        }
        return children;
    }

    function getCount( termId ) {
        for ( var i = 0; i < state.folders.length; i++ ) {
            if ( state.folders[i].term_id === termId ) {
                return state.folders[i].count;
            }
        }
        return 0;
    }

    function getTotalCount() {
        var total = state.uncategorizedCount || 0;
        for ( var i = 0; i < state.folders.length; i++ ) {
            total += state.folders[i].count;
        }
        return total;
    }

    // ── Folder Loading ────────────────────────────────────────

    function loadFolders( callback ) {
        state.isLoading = true;

        ajax( 'wpt_get_folders' ).done(function( response ) {
            if ( response.success ) {
                state.folders            = response.data.folders;
                state.uncategorizedCount = response.data.uncategorized_count;
            }
            state.isLoading = false;
            if ( typeof callback === 'function' ) {
                callback();
            }
        }).fail(function() {
            state.isLoading = false;
            if ( typeof callback === 'function' ) {
                callback();
            }
        });
    }

    // ── Sidebar Rendering ─────────────────────────────────────

    function renderSidebar( $container ) {
        $container.empty();

        var $header = $( '<div class="wpt-mf-header">' )
            .append( '<h3>' + i18n.folders + '</h3>' )
            .append(
                $( '<button type="button" class="button wpt-mf-add-btn" title="' + i18n.newFolder + '">' )
                    .text( '+ ' + i18n.newFolder )
                    .on( 'click', function() { showCreateForm( $container, 0 ); } )
            );

        $container.append( $header );

        // "All Media" item.
        var allCount = getTotalCount();
        var $all = $( '<div class="wpt-mf-item wpt-mf-item--all" data-folder="__all">' )
            .append( '<span class="wpt-mf-icon dashicons dashicons-admin-media"></span>' )
            .append( '<span class="wpt-mf-name">' + i18n.allMedia + '</span>' )
            .append( '<span class="wpt-mf-count">' + allCount + '</span>' );

        if ( state.activeFolder === null ) {
            $all.addClass( 'wpt-mf-item--active' );
        }

        $all.on( 'click', function() {
            state.activeFolder = null;
            applyFilter();
            renderSidebar( $container );
        });

        $container.append( $all );

        // "Uncategorized" item.
        var $uncat = $( '<div class="wpt-mf-item wpt-mf-item--uncategorized" data-folder="__uncategorized">' )
            .append( '<span class="wpt-mf-icon dashicons dashicons-category"></span>' )
            .append( '<span class="wpt-mf-name">' + i18n.uncategorized + '</span>' )
            .append( '<span class="wpt-mf-count">' + ( state.uncategorizedCount || 0 ) + '</span>' );

        if ( state.activeFolder === 0 ) {
            $uncat.addClass( 'wpt-mf-item--active' );
        }

        $uncat.on( 'click', function() {
            state.activeFolder = 0;
            applyFilter();
            renderSidebar( $container );
        });

        setupDropTarget( $uncat, 0 );
        $container.append( $uncat );

        // Folder tree.
        var tree = buildTree( state.folders, 0 );
        var $list = $( '<div class="wpt-mf-list">' );
        renderFolderNodes( $list, tree, $container, 0 );
        $container.append( $list );
    }

    function renderFolderNodes( $parent, nodes, $sidebar, depth ) {
        for ( var i = 0; i < nodes.length; i++ ) {
            var folder = nodes[i];
            var $item  = createFolderItem( folder, $sidebar, depth );
            $parent.append( $item );

            if ( folder.children && folder.children.length ) {
                var $children = $( '<div class="wpt-mf-children">' );
                renderFolderNodes( $children, folder.children, $sidebar, depth + 1 );
                $parent.append( $children );
            }
        }
    }

    function createFolderItem( folder, $sidebar, depth ) {
        var $item = $( '<div class="wpt-mf-item" data-folder="' + folder.term_id + '">' )
            .css( 'padding-left', ( 12 + depth * 16 ) + 'px' );

        var $icon  = $( '<span class="wpt-mf-icon dashicons dashicons-portfolio"></span>' );
        var $name  = $( '<span class="wpt-mf-name"></span>' ).text( folder.name );
        var $count = $( '<span class="wpt-mf-count">' + folder.count + '</span>' );

        // Context menu button.
        var $actions = $( '<span class="wpt-mf-actions">' )
            .append(
                $( '<button type="button" class="wpt-mf-action-btn" title="Actions">' )
                    .html( '&#8942;' )
                    .on( 'click', function( e ) {
                        e.stopPropagation();
                        showContextMenu( folder, $item, $sidebar );
                    })
            );

        if ( state.activeFolder === folder.term_id ) {
            $item.addClass( 'wpt-mf-item--active' );
        }

        $item.append( $icon ).append( $name ).append( $count ).append( $actions );

        // Click to filter.
        $item.on( 'click', function() {
            state.activeFolder = folder.term_id;
            applyFilter();
            renderSidebar( $sidebar );
        });

        // Drop target for media drag-and-drop.
        setupDropTarget( $item, folder.term_id );

        // Make folder items draggable for reordering (move folder).
        $item.attr( 'draggable', 'true' );
        $item.on( 'dragstart', function( e ) {
            e.originalEvent.dataTransfer.setData( 'wpt-folder-id', String( folder.term_id ) );
            $item.addClass( 'wpt-mf-dragging' );
        });
        $item.on( 'dragend', function() {
            $item.removeClass( 'wpt-mf-dragging' );
        });

        return $item;
    }

    // ── Drop Target for Media ─────────────────────────────────

    function setupDropTarget( $el, folderId ) {
        $el.on( 'dragover', function( e ) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            $el.addClass( 'wpt-mf-drop-over' );
        });

        $el.on( 'dragleave', function() {
            $el.removeClass( 'wpt-mf-drop-over' );
        });

        $el.on( 'drop', function( e ) {
            e.preventDefault();
            $el.removeClass( 'wpt-mf-drop-over' );

            // Check if a folder is being dragged (folder reorder).
            var draggedFolderId = e.originalEvent.dataTransfer.getData( 'wpt-folder-id' );
            if ( draggedFolderId ) {
                draggedFolderId = parseInt( draggedFolderId, 10 );
                if ( draggedFolderId && draggedFolderId !== folderId ) {
                    moveFolder( draggedFolderId, folderId );
                }
                return;
            }

            // Media attachment drag.
            var attachmentIds = e.originalEvent.dataTransfer.getData( 'wpt-attachment-ids' );
            if ( attachmentIds ) {
                try {
                    attachmentIds = JSON.parse( attachmentIds );
                } catch( err ) {
                    return;
                }
                assignToFolder( attachmentIds, folderId );
            }
        });
    }

    // ── Context Menu ──────────────────────────────────────────

    function showContextMenu( folder, $item, $sidebar ) {
        // Remove any existing context menu.
        $( '.wpt-mf-context-menu' ).remove();

        var $menu = $( '<div class="wpt-mf-context-menu">' );

        // Rename option.
        $( '<div class="wpt-mf-context-option">' )
            .text( i18n.rename )
            .on( 'click', function( e ) {
                e.stopPropagation();
                $menu.remove();
                showRenameForm( folder, $item, $sidebar );
            })
            .appendTo( $menu );

        // Add subfolder option.
        $( '<div class="wpt-mf-context-option">' )
            .text( '+ ' + i18n.newFolder )
            .on( 'click', function( e ) {
                e.stopPropagation();
                $menu.remove();
                showCreateForm( $sidebar, folder.term_id );
            })
            .appendTo( $menu );

        // Delete option.
        $( '<div class="wpt-mf-context-option wpt-mf-context-option--danger">' )
            .text( i18n.delete )
            .on( 'click', function( e ) {
                e.stopPropagation();
                $menu.remove();
                if ( confirm( i18n.confirmDelete ) ) {
                    deleteFolder( folder.term_id, $sidebar );
                }
            })
            .appendTo( $menu );

        $item.append( $menu );

        // Close menu on click outside.
        $( document ).one( 'click', function() {
            $menu.remove();
        });
    }

    // ── Create / Rename Forms ─────────────────────────────────

    function showCreateForm( $sidebar, parentId ) {
        // Remove any existing inline form.
        $( '.wpt-mf-inline-form' ).remove();

        var $form = $( '<div class="wpt-mf-inline-form">' )
            .append(
                $( '<input type="text" class="wpt-mf-input" placeholder="' + i18n.folderName + '">' )
                    .on( 'keydown', function( e ) {
                        if ( e.which === 13 ) {
                            e.preventDefault();
                            var name = $.trim( $( this ).val() );
                            if ( name ) {
                                createFolder( name, parentId, $sidebar );
                            }
                            $form.remove();
                        } else if ( e.which === 27 ) {
                            $form.remove();
                        }
                    })
                    .on( 'click', function( e ) { e.stopPropagation(); })
            );

        $sidebar.find( '.wpt-mf-list' ).prepend( $form );
        $form.find( 'input' ).focus();
    }

    function showRenameForm( folder, $item, $sidebar ) {
        var $nameEl = $item.find( '.wpt-mf-name' );
        var original = folder.name;

        var $input = $( '<input type="text" class="wpt-mf-input wpt-mf-rename-input">' )
            .val( original )
            .on( 'keydown', function( e ) {
                if ( e.which === 13 ) {
                    e.preventDefault();
                    var name = $.trim( $( this ).val() );
                    if ( name && name !== original ) {
                        renameFolder( folder.term_id, name, $sidebar );
                    } else {
                        $nameEl.text( original ).show();
                    }
                    $( this ).remove();
                } else if ( e.which === 27 ) {
                    $nameEl.text( original ).show();
                    $( this ).remove();
                }
            })
            .on( 'blur', function() {
                $nameEl.text( original ).show();
                $( this ).remove();
            })
            .on( 'click', function( e ) { e.stopPropagation(); });

        $nameEl.hide().after( $input );
        $input.focus().select();
    }

    // ── AJAX Operations ───────────────────────────────────────

    function createFolder( name, parentId, $sidebar ) {
        ajax( 'wpt_create_folder', { name: name, parent: parentId } )
            .done(function( response ) {
                if ( response.success ) {
                    loadFolders(function() { renderSidebar( $sidebar ); });
                } else {
                    alert( response.data && response.data.message ? response.data.message : i18n.createError );
                }
            })
            .fail(function() {
                alert( i18n.createError );
            });
    }

    function renameFolder( termId, name, $sidebar ) {
        ajax( 'wpt_rename_folder', { term_id: termId, name: name } )
            .done(function( response ) {
                if ( response.success ) {
                    loadFolders(function() { renderSidebar( $sidebar ); });
                } else {
                    alert( response.data && response.data.message ? response.data.message : i18n.renameError );
                }
            })
            .fail(function() {
                alert( i18n.renameError );
            });
    }

    function deleteFolder( termId, $sidebar ) {
        ajax( 'wpt_delete_folder', { term_id: termId } )
            .done(function( response ) {
                if ( response.success ) {
                    if ( state.activeFolder === termId ) {
                        state.activeFolder = null;
                    }
                    loadFolders(function() { renderSidebar( $sidebar ); });
                    applyFilter();
                } else {
                    alert( response.data && response.data.message ? response.data.message : i18n.deleteError );
                }
            })
            .fail(function() {
                alert( i18n.deleteError );
            });
    }

    function moveFolder( termId, newParent ) {
        ajax( 'wpt_move_folder', { term_id: termId, parent: newParent } )
            .done(function( response ) {
                if ( response.success ) {
                    loadFolders(function() {
                        var $sidebar = $( '.wpt-mf-sidebar' );
                        if ( $sidebar.length ) {
                            renderSidebar( $sidebar );
                        }
                    });
                }
            });
    }

    function assignToFolder( attachmentIds, folderId ) {
        ajax( 'wpt_assign_folder', { attachment_ids: attachmentIds, folder_id: folderId } )
            .done(function( response ) {
                if ( response.success ) {
                    loadFolders(function() {
                        var $sidebar = $( '.wpt-mf-sidebar' );
                        if ( $sidebar.length ) {
                            renderSidebar( $sidebar );
                        }
                    });
                    // Refresh the media grid if in grid mode.
                    if ( wp.media && wp.media.frame && wp.media.frame.content ) {
                        var collection = wp.media.frame.content.get();
                        if ( collection && collection.collection ) {
                            collection.collection.more();
                        }
                    }
                } else {
                    alert( response.data && response.data.message ? response.data.message : i18n.moveError );
                }
            });
    }

    // ── Media Grid Filter ─────────────────────────────────────

    function applyFilter() {
        var value = '';
        if ( state.activeFolder === 0 ) {
            value = '__uncategorized';
        } else if ( state.activeFolder !== null ) {
            // Find slug for the folder term_id.
            for ( var i = 0; i < state.folders.length; i++ ) {
                if ( state.folders[i].term_id === state.activeFolder ) {
                    value = state.folders[i].slug;
                    break;
                }
            }
        }

        // For grid view, update the query args on the media library collection.
        if ( wp.media && wp.media.frame ) {
            var library = null;

            // Try to get the library from the content region.
            if ( wp.media.frame.content && wp.media.frame.content.get() ) {
                var view = wp.media.frame.content.get();
                if ( view.collection ) {
                    library = view.collection;
                }
            }

            // Fallback: try the state's library.
            if ( ! library && wp.media.frame.state && wp.media.frame.state() ) {
                var stateObj = wp.media.frame.state();
                if ( stateObj.get && stateObj.get( 'library' ) ) {
                    library = stateObj.get( 'library' );
                }
            }

            if ( library && library.props ) {
                library.props.set( 'wpt_media_folder', value );
            }
        }

        // For list view, update the select and submit the form.
        var $select = $( '#wpt-media-folder-filter' );
        if ( $select.length ) {
            $select.val( value );
            // Only auto-submit if we are on the list view page.
            if ( $( 'body' ).hasClass( 'upload-php' ) && ! $( 'body' ).hasClass( 'mode-grid' ) ) {
                $( '#post-query-submit' ).click();
            }
        }
    }

    // ── Drag Support for Media Items ──────────────────────────

    function enableMediaDrag() {
        // Make media grid items draggable.
        $( document ).on( 'mousedown', '.attachment', function() {
            var $att = $( this );
            if ( ! $att.attr( 'draggable' ) ) {
                $att.attr( 'draggable', 'true' );
                $att.on( 'dragstart', function( e ) {
                    var id = $att.data( 'id' );
                    if ( id ) {
                        e.originalEvent.dataTransfer.setData( 'wpt-attachment-ids', JSON.stringify( [ id ] ) );
                        e.originalEvent.dataTransfer.effectAllowed = 'move';
                    }
                });
            }
        });

        // Make media list table rows draggable.
        $( document ).on( 'mousedown', '.wp-list-table .type-attachment', function() {
            var $row = $( this );
            if ( ! $row.attr( 'draggable' ) ) {
                $row.attr( 'draggable', 'true' );
                $row.on( 'dragstart', function( e ) {
                    var id = $row.attr( 'id' );
                    if ( id ) {
                        id = parseInt( id.replace( 'post-', '' ), 10 );
                        if ( id ) {
                            e.originalEvent.dataTransfer.setData( 'wpt-attachment-ids', JSON.stringify( [ id ] ) );
                            e.originalEvent.dataTransfer.effectAllowed = 'move';
                        }
                    }
                });
            }
        });
    }

    // ── Initialization ────────────────────────────────────────

    function initSidebar() {
        // Prevent double initialization.
        if ( $( '.wpt-mf-sidebar' ).length ) {
            return;
        }

        var $sidebar = $( '<div class="wpt-mf-sidebar">' );
        var $wrap    = $( '.wrap, .media-frame' ).first();

        if ( $( 'body' ).hasClass( 'upload-php' ) ) {
            // Media library page — inject sidebar before the main content.
            $wrap = $( '.wrap' );
            if ( $wrap.length ) {
                $wrap.addClass( 'wpt-mf-has-sidebar' );
                $wrap.prepend( $sidebar );
            }
        }

        loadFolders(function() {
            renderSidebar( $sidebar );
        });

        enableMediaDrag();
    }

    // ── Media Modal Integration ───────────────────────────────

    function initMediaModal() {
        if ( ! wp.media ) {
            return;
        }

        // Hook into media modal open.
        wp.media.view.Modal.prototype.on( 'open', function() {
            // Small delay to ensure the modal DOM is ready.
            setTimeout(function() {
                var $modal = $( '.media-modal-content' );
                if ( $modal.length && ! $modal.find( '.wpt-mf-sidebar' ).length ) {
                    var $sidebar = $( '<div class="wpt-mf-sidebar wpt-mf-sidebar--modal">' );
                    var $browser = $modal.find( '.media-frame-content' );
                    if ( $browser.length ) {
                        $browser.addClass( 'wpt-mf-has-sidebar' );
                        $browser.prepend( $sidebar );
                        loadFolders(function() {
                            renderSidebar( $sidebar );
                        });
                    }
                }
            }, 200 );
        });
    }

    // ── Boot ──────────────────────────────────────────────────

    $( document ).ready(function() {
        // Initialize on the media library page.
        if ( $( 'body' ).hasClass( 'upload-php' ) ) {
            // Delay slightly for grid mode to initialize.
            setTimeout( initSidebar, 100 );
        }

        // Initialize media modal hooks for post editor pages.
        initMediaModal();
    });

})( jQuery, window.wp || {} );
