<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Page Hierarchy Organizer — Collapsible tree view for the Pages list table.
 *
 * Core logic:
 * - admin_footer on edit.php?post_type=page: injects JS to transform flat list into tree
 * - Collapsible parent/child rows with expand/collapse arrows
 * - Drag-and-drop reordering updates menu_order and post_parent via AJAX
 * - Keyboard: arrow keys navigate, Enter expand/collapse
 *
 * @package WPTransformed
 */
class Page_Hierarchy_Organizer extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'page-hierarchy-organizer';
    }

    public function get_title(): string {
        return __( 'Page Hierarchy Organizer', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Transform the Pages list into a collapsible tree with drag-and-drop reordering.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'       => true,
            'show_template' => true,
            'show_status'   => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'admin_footer-edit.php', [ $this, 'render_tree_script' ] );
        add_action( 'wp_ajax_wpt_reorder_pages', [ $this, 'ajax_reorder_pages' ] );
    }

    // ── Tree Script ───────────────────────────────────────────

    /**
     * Inject the tree transformation JS/CSS on the Pages list screen only.
     */
    public function render_tree_script(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'page' ) {
            return;
        }

        if ( ! current_user_can( 'edit_pages' ) ) {
            return;
        }

        $settings = $this->get_settings();
        ?>
        <style>
            .wpt-tree-toggle {
                display: inline-block;
                width: 20px;
                height: 20px;
                cursor: pointer;
                text-align: center;
                line-height: 20px;
                color: #50575e;
                font-size: 14px;
                user-select: none;
                vertical-align: middle;
                margin-right: 2px;
                transition: transform 0.15s;
                border: none;
                background: none;
                padding: 0;
            }
            .wpt-tree-toggle:hover { color: #2271b1; }
            .wpt-tree-toggle.collapsed { transform: rotate(-90deg); }
            .wpt-tree-toggle.leaf { visibility: hidden; }
            .wpt-tree-hidden { display: none !important; }
            .wpt-tree-row-focused td:first-child {
                box-shadow: inset 3px 0 0 #2271b1;
            }
            .wpt-tree-drop-above td { border-top: 2px solid #2271b1 !important; }
            .wpt-tree-drop-below td { border-bottom: 2px solid #2271b1 !important; }
            .wpt-tree-drop-child td:first-child { box-shadow: inset 3px 0 0 #4caf50; }
            .wpt-tree-drag-row { opacity: 0.5; }
            .wpt-tree-status-badge {
                display: inline-block;
                font-size: 11px;
                padding: 1px 6px;
                border-radius: 3px;
                margin-left: 6px;
                font-weight: normal;
            }
            .wpt-tree-status-draft { background: #f0f0f1; color: #50575e; }
            .wpt-tree-status-pending { background: #fcf0e3; color: #996800; }
            .wpt-tree-status-private { background: #f0e6f6; color: #6c3483; }
            .wpt-tree-template-badge {
                display: inline-block;
                font-size: 11px;
                padding: 1px 6px;
                border-radius: 3px;
                margin-left: 6px;
                background: #e8f0fe;
                color: #1a73e8;
                font-weight: normal;
            }
            .wpt-tree-toolbar {
                margin: 8px 0;
                display: flex;
                gap: 8px;
            }
            .wpt-tree-toolbar button {
                font-size: 12px;
            }
        </style>

        <script>
        (function() {
            'use strict';

            var showTemplate = <?php echo wp_json_encode( ! empty( $settings['show_template'] ) ); ?>;
            var showStatus   = <?php echo wp_json_encode( ! empty( $settings['show_status'] ) ); ?>;
            var ajaxUrl      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce        = <?php echo wp_json_encode( wp_create_nonce( 'wpt_reorder_pages' ) ); ?>;

            var table = document.querySelector('.wp-list-table.pages');
            if (!table) return;

            var tbody = table.querySelector('tbody#the-list');
            if (!tbody) return;

            var rows = tbody.querySelectorAll('tr');
            if (rows.length === 0) return;

            // Build tree data from existing rows.
            // WordPress indents child pages with <span class="dashicons dashicons-minus"> per level.
            var treeData = [];
            var rowMap = {};

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var postId = row.id ? row.id.replace('post-', '') : '';
                if (!postId) continue;

                // Determine indent level from WordPress's existing indentation.
                var titleCol = row.querySelector('.row-title');
                var level = 0;
                if (titleCol) {
                    var parent = titleCol.closest('td');
                    if (parent) {
                        var dashes = parent.querySelectorAll('.dashicons-minus');
                        level = dashes.length;
                    }
                }

                // Find parent post ID from WP data if available.
                var parentId = '0';
                var hiddenParent = row.querySelector('input[name="post_parent"]');
                if (hiddenParent) {
                    parentId = hiddenParent.value || '0';
                }

                // Store row data.
                treeData.push({
                    id: postId,
                    parentId: parentId,
                    level: level,
                    row: row,
                    children: [],
                    collapsed: false
                });
                rowMap[postId] = treeData[treeData.length - 1];
            }

            // Build parent-child relationships.
            for (var j = 0; j < treeData.length; j++) {
                var item = treeData[j];
                if (item.parentId !== '0' && rowMap[item.parentId]) {
                    rowMap[item.parentId].children.push(item);
                }
            }

            // Add toggle buttons to parent rows.
            for (var k = 0; k < treeData.length; k++) {
                var node = treeData[k];
                var titleCell = node.row.querySelector('.row-title');
                if (!titleCell) continue;

                var toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'wpt-tree-toggle';
                toggleBtn.setAttribute('data-post-id', node.id);
                toggleBtn.setAttribute('aria-label', node.children.length > 0 ? 'Toggle children' : '');
                toggleBtn.setAttribute('role', 'button');

                if (node.children.length > 0) {
                    toggleBtn.textContent = '\u25BC';
                } else {
                    toggleBtn.className += ' leaf';
                    toggleBtn.textContent = '\u25BC';
                }

                titleCell.parentNode.insertBefore(toggleBtn, titleCell);
            }

            // Toggle expand/collapse.
            function toggleNode(postId) {
                var node = rowMap[postId];
                if (!node || node.children.length === 0) return;

                node.collapsed = !node.collapsed;
                var btn = node.row.querySelector('.wpt-tree-toggle');
                if (btn) {
                    btn.classList.toggle('collapsed', node.collapsed);
                }
                updateVisibility(node);
            }

            function updateVisibility(parentNode) {
                for (var c = 0; c < parentNode.children.length; c++) {
                    var child = parentNode.children[c];
                    if (parentNode.collapsed) {
                        child.row.classList.add('wpt-tree-hidden');
                        // Also hide grandchildren.
                        hideDescendants(child);
                    } else {
                        child.row.classList.remove('wpt-tree-hidden');
                        // Restore grandchildren visibility based on their own collapsed state.
                        if (!child.collapsed) {
                            updateVisibility(child);
                        }
                    }
                }
            }

            function hideDescendants(node) {
                for (var d = 0; d < node.children.length; d++) {
                    node.children[d].row.classList.add('wpt-tree-hidden');
                    hideDescendants(node.children[d]);
                }
            }

            // Click handler for toggle buttons.
            tbody.addEventListener('click', function(e) {
                var btn = e.target.closest('.wpt-tree-toggle');
                if (!btn) return;
                var pid = btn.getAttribute('data-post-id');
                if (pid) toggleNode(pid);
            });

            // Add toolbar.
            var toolbar = document.createElement('div');
            toolbar.className = 'wpt-tree-toolbar';

            var collapseAll = document.createElement('button');
            collapseAll.type = 'button';
            collapseAll.className = 'button button-small';
            collapseAll.textContent = <?php echo wp_json_encode( __( 'Collapse All', 'wptransformed' ) ); ?>;
            collapseAll.addEventListener('click', function() {
                for (var i = 0; i < treeData.length; i++) {
                    if (treeData[i].children.length > 0 && !treeData[i].collapsed) {
                        toggleNode(treeData[i].id);
                    }
                }
            });

            var expandAll = document.createElement('button');
            expandAll.type = 'button';
            expandAll.className = 'button button-small';
            expandAll.textContent = <?php echo wp_json_encode( __( 'Expand All', 'wptransformed' ) ); ?>;
            expandAll.addEventListener('click', function() {
                for (var i = 0; i < treeData.length; i++) {
                    if (treeData[i].children.length > 0 && treeData[i].collapsed) {
                        toggleNode(treeData[i].id);
                    }
                }
            });

            toolbar.appendChild(expandAll);
            toolbar.appendChild(collapseAll);
            table.parentNode.insertBefore(toolbar, table);

            // ── Keyboard navigation ──

            var focusedRow = null;

            document.addEventListener('keydown', function(e) {
                // Only handle when table has focus context.
                var tag = (e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) {
                    return;
                }

                var visibleRows = [];
                for (var i = 0; i < treeData.length; i++) {
                    if (!treeData[i].row.classList.contains('wpt-tree-hidden')) {
                        visibleRows.push(treeData[i]);
                    }
                }
                if (visibleRows.length === 0) return;

                var currentIdx = -1;
                if (focusedRow) {
                    for (var j = 0; j < visibleRows.length; j++) {
                        if (visibleRows[j].id === focusedRow) {
                            currentIdx = j;
                            break;
                        }
                    }
                }

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        if (currentIdx < visibleRows.length - 1) {
                            setFocus(visibleRows[currentIdx + 1].id);
                        } else if (currentIdx === -1) {
                            setFocus(visibleRows[0].id);
                        }
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        if (currentIdx > 0) {
                            setFocus(visibleRows[currentIdx - 1].id);
                        }
                        break;
                    case 'ArrowRight':
                        if (focusedRow && rowMap[focusedRow] && rowMap[focusedRow].collapsed) {
                            e.preventDefault();
                            toggleNode(focusedRow);
                        }
                        break;
                    case 'ArrowLeft':
                        if (focusedRow && rowMap[focusedRow] && !rowMap[focusedRow].collapsed && rowMap[focusedRow].children.length > 0) {
                            e.preventDefault();
                            toggleNode(focusedRow);
                        }
                        break;
                    case 'Enter':
                        if (focusedRow && rowMap[focusedRow] && rowMap[focusedRow].children.length > 0) {
                            e.preventDefault();
                            toggleNode(focusedRow);
                        }
                        break;
                }
            });

            function setFocus(postId) {
                if (focusedRow) {
                    var prevRow = rowMap[focusedRow];
                    if (prevRow) prevRow.row.classList.remove('wpt-tree-row-focused');
                }
                focusedRow = postId;
                var node = rowMap[postId];
                if (node) {
                    node.row.classList.add('wpt-tree-row-focused');
                    node.row.scrollIntoView({ block: 'nearest' });
                }
            }

            // ── Drag and Drop ──

            var dragItem = null;
            var dropTarget = null;
            var dropPosition = null;

            for (var r = 0; r < treeData.length; r++) {
                (function(node) {
                    node.row.setAttribute('draggable', 'true');

                    node.row.addEventListener('dragstart', function(e) {
                        dragItem = node;
                        node.row.classList.add('wpt-tree-drag-row');
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', node.id);
                    });

                    node.row.addEventListener('dragend', function() {
                        node.row.classList.remove('wpt-tree-drag-row');
                        clearDropIndicators();
                        dragItem = null;
                    });

                    node.row.addEventListener('dragover', function(e) {
                        if (!dragItem || dragItem.id === node.id) return;
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';

                        clearDropIndicators();
                        dropTarget = node;

                        var rect = node.row.getBoundingClientRect();
                        var y = e.clientY - rect.top;
                        var third = rect.height / 3;

                        if (y < third) {
                            dropPosition = 'above';
                            node.row.classList.add('wpt-tree-drop-above');
                        } else if (y > third * 2) {
                            dropPosition = 'below';
                            node.row.classList.add('wpt-tree-drop-below');
                        } else {
                            dropPosition = 'child';
                            node.row.classList.add('wpt-tree-drop-child');
                        }
                    });

                    node.row.addEventListener('dragleave', function() {
                        node.row.classList.remove('wpt-tree-drop-above', 'wpt-tree-drop-below', 'wpt-tree-drop-child');
                    });

                    node.row.addEventListener('drop', function(e) {
                        e.preventDefault();
                        if (!dragItem || !dropTarget || dragItem.id === dropTarget.id) return;

                        // Prevent dropping parent into its own descendant.
                        if (isDescendant(dropTarget, dragItem)) {
                            clearDropIndicators();
                            return;
                        }

                        var newParentId = '0';
                        var newOrder = 0;

                        if (dropPosition === 'child') {
                            newParentId = dropTarget.id;
                        } else if (dropPosition === 'above') {
                            newParentId = dropTarget.parentId;
                            newOrder = getOrderIndex(dropTarget);
                        } else {
                            newParentId = dropTarget.parentId;
                            newOrder = getOrderIndex(dropTarget) + 1;
                        }

                        // Send AJAX.
                        var data = new FormData();
                        data.append('action', 'wpt_reorder_pages');
                        data.append('_wpnonce', nonce);
                        data.append('post_id', dragItem.id);
                        data.append('new_parent', newParentId);
                        data.append('new_order', String(newOrder));

                        fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                            .then(function(r) { return r.json(); })
                            .then(function(resp) {
                                if (resp.success) {
                                    window.location.reload();
                                }
                            });

                        clearDropIndicators();
                    });
                })(treeData[r]);
            }

            function clearDropIndicators() {
                for (var i = 0; i < treeData.length; i++) {
                    treeData[i].row.classList.remove('wpt-tree-drop-above', 'wpt-tree-drop-below', 'wpt-tree-drop-child');
                }
            }

            function isDescendant(target, parent) {
                for (var i = 0; i < parent.children.length; i++) {
                    if (parent.children[i].id === target.id) return true;
                    if (isDescendant(target, parent.children[i])) return true;
                }
                return false;
            }

            function getOrderIndex(node) {
                var siblings = [];
                for (var i = 0; i < treeData.length; i++) {
                    if (treeData[i].parentId === node.parentId) {
                        siblings.push(treeData[i]);
                    }
                }
                for (var j = 0; j < siblings.length; j++) {
                    if (siblings[j].id === node.id) return j;
                }
                return 0;
            }
        })();
        </script>
        <?php
    }

    // ── AJAX Handler ──────────────────────────────────────────

    /**
     * AJAX: Reorder a page (update menu_order and post_parent).
     */
    public function ajax_reorder_pages(): void {
        check_ajax_referer( 'wpt_reorder_pages' );

        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $new_parent = isset( $_POST['new_parent'] ) ? absint( $_POST['new_parent'] ) : 0;
        $new_order  = isset( $_POST['new_order'] ) ? absint( $_POST['new_order'] ) : 0;

        if ( $post_id === 0 ) {
            wp_send_json_error( __( 'Invalid post ID.', 'wptransformed' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'page' ) {
            wp_send_json_error( __( 'Post not found or not a page.', 'wptransformed' ) );
        }

        // Validate new parent exists and is a page (or 0 for top-level).
        if ( $new_parent > 0 ) {
            $parent_post = get_post( $new_parent );
            if ( ! $parent_post || $parent_post->post_type !== 'page' ) {
                wp_send_json_error( __( 'Invalid parent page.', 'wptransformed' ) );
            }

            // Prevent circular reference.
            if ( $this->is_ancestor( $post_id, $new_parent ) ) {
                wp_send_json_error( __( 'Cannot set a descendant as parent.', 'wptransformed' ) );
            }
        }

        // Update post parent.
        wp_update_post( [
            'ID'          => $post_id,
            'post_parent' => $new_parent,
            'menu_order'  => $new_order,
        ] );

        // Reorder siblings to maintain sequential order.
        $this->reorder_siblings( $new_parent, $post_id, $new_order );

        wp_send_json_success( [
            'message' => __( 'Page reordered.', 'wptransformed' ),
        ] );
    }

    /**
     * Check if $check_id is an ancestor of $post_id.
     *
     * @param int $post_id  The post to move.
     * @param int $check_id The target parent.
     * @return bool
     */
    private function is_ancestor( int $post_id, int $check_id ): bool {
        $ancestors = get_post_ancestors( $check_id );
        return in_array( $post_id, $ancestors, true );
    }

    /**
     * Reorder sibling pages after a move.
     *
     * @param int $parent_id   The parent post ID.
     * @param int $moved_id    The moved post ID.
     * @param int $target_order The target order position.
     */
    private function reorder_siblings( int $parent_id, int $moved_id, int $target_order ): void {
        global $wpdb;

        // Get all siblings ordered by menu_order.
        $siblings = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, menu_order FROM {$wpdb->posts}
             WHERE post_parent = %d AND post_type = 'page' AND ID != %d
             ORDER BY menu_order ASC",
            $parent_id,
            $moved_id
        ) );

        if ( empty( $siblings ) ) {
            return;
        }

        $order = 0;
        foreach ( $siblings as $sibling ) {
            if ( $order === $target_order ) {
                $order++;
            }
            if ( (int) $sibling->menu_order !== $order ) {
                $wpdb->update(
                    $wpdb->posts,
                    [ 'menu_order' => $order ],
                    [ 'ID' => (int) $sibling->ID ],
                    [ '%d' ],
                    [ '%d' ]
                );
            }
            $order++;
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e( 'Page Hierarchy Organizer Settings', 'wptransformed' ); ?></legend>

            <p>
                <label>
                    <input type="checkbox" name="wpt_enabled" value="1"
                        <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                    <?php esc_html_e( 'Enable page hierarchy tree view', 'wptransformed' ); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="checkbox" name="wpt_show_template" value="1"
                        <?php checked( ! empty( $settings['show_template'] ) ); ?>>
                    <?php esc_html_e( 'Show page template badges', 'wptransformed' ); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="checkbox" name="wpt_show_status" value="1"
                        <?php checked( ! empty( $settings['show_status'] ) ); ?>>
                    <?php esc_html_e( 'Show status badges (Draft, Pending, Private)', 'wptransformed' ); ?>
                </label>
            </p>

            <p class="description">
                <?php esc_html_e( 'Transforms the Pages list into a collapsible tree. Use arrow keys to navigate and drag-and-drop to reorder pages.', 'wptransformed' ); ?>
            </p>
        </fieldset>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'       => ! empty( $raw['wpt_enabled'] ),
            'show_template' => ! empty( $raw['wpt_show_template'] ),
            'show_status'   => ! empty( $raw['wpt_show_status'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
