<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Content Order — Drag-and-drop reordering of posts/pages in the admin list table.
 *
 * Adds `menu_order` support to selected post types, orders by `menu_order ASC`
 * in admin list views, and provides an AJAX endpoint to persist new order.
 *
 * @package WPTransformed
 */
class Content_Order extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'content-order';
    }

    public function get_title(): string {
        return __( 'Content Order', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Drag-and-drop reordering of posts, pages, and custom post types in the admin list table.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled_for' => [ 'page' ],
            'drag_drop'   => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Add menu_order support to selected post types.
        add_action( 'init', [ $this, 'add_menu_order_support' ], 99 );

        // Order by menu_order in admin list tables.
        add_action( 'pre_get_posts', [ $this, 'order_admin_list' ] );

        // AJAX endpoint for saving order.
        add_action( 'wp_ajax_wpt_save_content_order', [ $this, 'ajax_save_order' ] );

        // Inline drag-and-drop JS in admin footer.
        add_action( 'admin_footer', [ $this, 'render_drag_drop_js' ] );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Register menu_order support for enabled post types.
     */
    public function add_menu_order_support(): void {
        $enabled = $this->get_enabled_post_types();

        foreach ( $enabled as $post_type ) {
            if ( post_type_exists( $post_type ) ) {
                add_post_type_support( $post_type, 'page-attributes' );
            }
        }
    }

    /**
     * On admin list screens for enabled post types, order by menu_order ASC.
     *
     * @param \WP_Query $query The query object.
     */
    public function order_admin_list( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        // pre_get_posts fires before current_screen is set, so check
        // the global pagenow directly instead of get_current_screen().
        global $pagenow;
        if ( $pagenow !== 'edit.php' ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( empty( $post_type ) ) {
            return;
        }

        $enabled = $this->get_enabled_post_types();
        if ( ! in_array( $post_type, $enabled, true ) ) {
            return;
        }

        // Only apply if user hasn't explicitly chosen an orderby.
        if ( $query->get( 'orderby' ) ) {
            return;
        }

        $query->set( 'orderby', 'menu_order' );
        $query->set( 'order', 'ASC' );
    }

    /**
     * AJAX handler — save the new content order.
     */
    public function ajax_save_order(): void {
        check_ajax_referer( 'wpt_content_order_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_others_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $post_ids = isset( $_POST['order'] ) ? array_map( 'absint', (array) $_POST['order'] ) : [];
        if ( empty( $post_ids ) ) {
            wp_send_json_error( [ 'message' => 'No post IDs provided.' ] );
        }

        global $wpdb;

        $menu_order = 0;
        foreach ( $post_ids as $post_id ) {
            if ( $post_id < 1 ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->posts,
                [ 'menu_order' => $menu_order ],
                [ 'ID' => $post_id ],
                [ '%d' ],
                [ '%d' ]
            );
            $menu_order++;
        }

        foreach ( $post_ids as $id ) {
            clean_post_cache( $id );
        }

        wp_send_json_success( [ 'message' => 'Order saved.' ] );
    }

    /**
     * Render inline drag-and-drop JS on the admin list screen for enabled types.
     */
    public function render_drag_drop_js(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['drag_drop'] ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'edit' ) {
            return;
        }

        $enabled = $this->get_enabled_post_types();
        if ( ! isset( $screen->post_type ) || ! in_array( $screen->post_type, $enabled, true ) ) {
            return;
        }

        $nonce = wp_create_nonce( 'wpt_content_order_nonce' );
        ?>
        <style>
            .wpt-drag-handle {
                cursor: grab;
                color: #999;
                padding: 0 8px;
                font-size: 18px;
                line-height: 1;
                user-select: none;
            }
            .wpt-drag-handle:hover { color: #2271b1; }
            tr.wpt-dragging { opacity: 0.5; background: #f0f6fc; }
            tr.wpt-drag-over td { border-top: 2px solid #2271b1; }
        </style>
        <script>
        (function() {
            var table = document.querySelector('#the-list');
            if (!table) return;

            var rows = table.querySelectorAll('tr');
            var dragSrc = null;

            rows.forEach(function(row) {
                // Add drag handle as first visible indicator.
                var firstTd = row.querySelector('.column-title') || row.querySelector('td');
                if (!firstTd) return;

                var handle = document.createElement('span');
                handle.className = 'wpt-drag-handle';
                handle.textContent = '\u2630';
                handle.title = <?php echo wp_json_encode( __( 'Drag to reorder', 'wptransformed' ) ); ?>;
                firstTd.insertBefore(handle, firstTd.firstChild);

                row.draggable = true;

                row.addEventListener('dragstart', function(e) {
                    dragSrc = this;
                    this.classList.add('wpt-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', '');
                });

                row.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    this.classList.add('wpt-drag-over');
                });

                row.addEventListener('dragleave', function() {
                    this.classList.remove('wpt-drag-over');
                });

                row.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('wpt-drag-over');
                    if (dragSrc === this) return;

                    // Insert dragged row before or after this one.
                    var allRows = Array.from(table.querySelectorAll('tr'));
                    var srcIdx = allRows.indexOf(dragSrc);
                    var tgtIdx = allRows.indexOf(this);

                    if (srcIdx < tgtIdx) {
                        this.parentNode.insertBefore(dragSrc, this.nextSibling);
                    } else {
                        this.parentNode.insertBefore(dragSrc, this);
                    }

                    saveOrder();
                });

                row.addEventListener('dragend', function() {
                    this.classList.remove('wpt-dragging');
                    table.querySelectorAll('.wpt-drag-over').forEach(function(el) {
                        el.classList.remove('wpt-drag-over');
                    });
                });
            });

            function saveOrder() {
                var orderedIds = [];
                table.querySelectorAll('tr').forEach(function(row) {
                    var id = row.id ? row.id.replace('post-', '') : '';
                    if (id && !isNaN(parseInt(id, 10))) {
                        orderedIds.push(parseInt(id, 10));
                    }
                });

                if (orderedIds.length === 0) return;

                var formData = new FormData();
                formData.append('action', 'wpt_save_content_order');
                formData.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
                orderedIds.forEach(function(id) {
                    formData.append('order[]', id);
                });

                fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) {
                            console.error('WPT Content Order: save failed', resp);
                        }
                    })
                    .catch(function(err) {
                        console.error('WPT Content Order: network error', err);
                    });
            }
        })();
        </script>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings    = $this->get_settings();
        $enabled_for = $settings['enabled_for'] ?? [];
        $drag_drop   = ! empty( $settings['drag_drop'] );

        if ( ! is_array( $enabled_for ) ) {
            $enabled_for = [];
        }

        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        ?>

        <div class="wpt-content-order-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Enable custom ordering for specific post types. When enabled, the admin list table will sort by menu_order, and drag-and-drop handles will appear for easy reordering.', 'wptransformed' ); ?>
            </p>
        </div>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable For', 'wptransformed' ); ?></th>
                <td>
                    <?php foreach ( $post_types as $pt ) : ?>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox"
                                   name="wpt_enabled_for[]"
                                   value="<?php echo esc_attr( $pt->name ); ?>"
                                   <?php checked( in_array( $pt->name, $enabled_for, true ) ); ?>>
                            <?php echo esc_html( $pt->labels->singular_name ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php esc_html_e( 'Select which post types should support custom ordering.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt_drag_drop"><?php esc_html_e( 'Drag & Drop', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox"
                               id="wpt_drag_drop"
                               name="wpt_drag_drop"
                               value="1"
                               <?php checked( $drag_drop ); ?>>
                        <?php esc_html_e( 'Enable drag-and-drop reordering in the admin list table', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $clean = [];

        // Enabled post types.
        $enabled_for = $raw['wpt_enabled_for'] ?? [];
        if ( ! is_array( $enabled_for ) ) {
            $enabled_for = [];
        }
        $valid_types = array_keys( get_post_types( [ 'public' => true ] ) );
        $clean['enabled_for'] = array_values( array_intersect( $enabled_for, $valid_types ) );

        // Drag & drop toggle.
        $clean['drag_drop'] = ! empty( $raw['wpt_drag_drop'] );

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Inline JS/CSS is rendered in admin_footer. No external assets.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Get the list of enabled post type slugs.
     *
     * @return string[]
     */
    private function get_enabled_post_types(): array {
        $settings = $this->get_settings();
        $enabled  = $settings['enabled_for'] ?? [];

        return is_array( $enabled ) ? $enabled : [];
    }
}
