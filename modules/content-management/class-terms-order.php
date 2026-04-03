<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Terms Order — Drag-and-drop reordering for taxonomy terms.
 *
 * Saves custom term_order via AJAX and filters get_terms to
 * respect the saved order for enabled taxonomies.
 *
 * @package WPTransformed
 */
class Terms_Order extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'terms-order';
    }

    public function get_title(): string {
        return __( 'Terms Order', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Drag-and-drop reordering for taxonomy terms.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled_for' => [ 'category', 'post_tag' ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_filter( 'get_terms_defaults', [ $this, 'set_default_term_order' ], 10, 2 );
        add_action( 'wp_ajax_wpt_save_term_order', [ $this, 'ajax_save_term_order' ] );
        add_action( 'admin_footer', [ $this, 'output_drag_drop_js' ] );
    }

    // ── Term Order Filter ─────────────────────────────────────

    /**
     * Default to ordering by term_order for enabled taxonomies.
     *
     * @param array<string,mixed> $defaults Default query args.
     * @param string[]            $taxonomies Taxonomies being queried.
     * @return array<string,mixed>
     */
    public function set_default_term_order( array $defaults, array $taxonomies ): array {
        $settings    = $this->get_settings();
        $enabled_for = (array) ( $settings['enabled_for'] ?? [] );

        if ( empty( $enabled_for ) ) {
            return $defaults;
        }

        // Only apply if all queried taxonomies are enabled.
        foreach ( $taxonomies as $tax ) {
            if ( ! in_array( $tax, $enabled_for, true ) ) {
                return $defaults;
            }
        }

        // Only override if not explicitly set by caller.
        if ( empty( $defaults['orderby'] ) || $defaults['orderby'] === 'name' ) {
            $defaults['orderby'] = 'term_order';
            if ( empty( $defaults['order'] ) ) {
                $defaults['order'] = 'ASC';
            }
        }

        return $defaults;
    }

    // ── AJAX Handler ──────────────────────────────────────────

    /**
     * Save term order via AJAX.
     *
     * Expects POST data: nonce, taxonomy, order (array of term IDs).
     */
    public function ajax_save_term_order(): void {
        check_ajax_referer( 'wpt_save_term_order', 'nonce' );

        if ( ! current_user_can( 'manage_categories' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '';
        $order    = isset( $_POST['order'] ) ? array_map( 'absint', (array) $_POST['order'] ) : [];

        if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid taxonomy.', 'wptransformed' ) ], 400 );
        }

        $settings    = $this->get_settings();
        $enabled_for = (array) ( $settings['enabled_for'] ?? [] );

        if ( ! in_array( $taxonomy, $enabled_for, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Term ordering is not enabled for this taxonomy.', 'wptransformed' ) ], 400 );
        }

        global $wpdb;

        foreach ( $order as $position => $term_id ) {
            if ( $term_id <= 0 ) {
                continue;
            }
            $wpdb->update(
                $wpdb->terms,
                [ 'term_order' => (int) $position ],
                [ 'term_id' => (int) $term_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        wp_send_json_success( [ 'message' => __( 'Term order saved.', 'wptransformed' ) ] );
    }

    // ── Inline JS for Drag-Drop ───────────────────────────────

    /**
     * Output drag-and-drop JS on taxonomy list screens for enabled taxonomies.
     */
    public function output_drag_drop_js(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'edit-tags' ) {
            return;
        }

        $taxonomy    = $screen->taxonomy ?? '';
        $settings    = $this->get_settings();
        $enabled_for = (array) ( $settings['enabled_for'] ?? [] );

        if ( ! in_array( $taxonomy, $enabled_for, true ) ) {
            return;
        }

        $nonce = wp_create_nonce( 'wpt_save_term_order' );
        ?>
        <script>
        (function() {
            'use strict';
            var taxonomy = <?php echo wp_json_encode( $taxonomy ); ?>;
            var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
            var tbody    = document.querySelector('#the-list');
            if (!tbody) return;

            var dragged = null;
            var highlighted = null;

            function clearHighlight() {
                if (highlighted) {
                    highlighted.style.borderTop = '';
                    highlighted.style.borderBottom = '';
                    highlighted = null;
                }
            }

            tbody.querySelectorAll('tr').forEach(function(row) {
                row.setAttribute('draggable', 'true');
                row.style.cursor = 'grab';

                row.addEventListener('dragstart', function(e) {
                    dragged = this;
                    this.style.opacity = '0.5';
                    e.dataTransfer.effectAllowed = 'move';
                });

                row.addEventListener('dragend', function() {
                    this.style.opacity = '1';
                    dragged = null;
                    clearHighlight();
                });

                row.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    clearHighlight();
                    var rect = this.getBoundingClientRect();
                    var mid  = rect.top + rect.height / 2;
                    if (e.clientY < mid) {
                        this.style.borderTop = '2px solid #2271b1';
                    } else {
                        this.style.borderBottom = '2px solid #2271b1';
                    }
                    highlighted = this;
                });

                row.addEventListener('drop', function(e) {
                    e.preventDefault();
                    if (!dragged || dragged === this) return;
                    var rect = this.getBoundingClientRect();
                    var mid  = rect.top + rect.height / 2;
                    if (e.clientY < mid) {
                        tbody.insertBefore(dragged, this);
                    } else {
                        tbody.insertBefore(dragged, this.nextSibling);
                    }
                    saveOrder();
                });
            });

            function saveOrder() {
                var order = [];
                tbody.querySelectorAll('tr').forEach(function(row) {
                    var tagId = row.id.replace('tag-', '');
                    if (tagId) order.push(parseInt(tagId, 10));
                });

                var data = new FormData();
                data.append('action', 'wpt_save_term_order');
                data.append('nonce', nonce);
                data.append('taxonomy', taxonomy);
                order.forEach(function(id) {
                    data.append('order[]', id);
                });

                fetch(window.ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                });
            }
        })();
        </script>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings    = $this->get_settings();
        $enabled_for = (array) ( $settings['enabled_for'] ?? [] );
        $taxonomies  = get_taxonomies( [ 'public' => true ], 'objects' );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable For Taxonomies', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $taxonomies as $tax ) : ?>
                            <label>
                                <input type="checkbox" name="wpt_enabled_for[]"
                                       value="<?php echo esc_attr( $tax->name ); ?>"
                                       <?php checked( in_array( $tax->name, $enabled_for, true ) ); ?>>
                                <?php echo esc_html( $tax->labels->name ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Drag-and-drop term ordering will be available on these taxonomy screens.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $submitted    = $raw['wpt_enabled_for'] ?? [];
        $valid_taxes  = array_keys( get_taxonomies( [ 'public' => true ] ) );
        $enabled_for  = array_values( array_intersect(
            array_map( 'sanitize_key', (array) $submitted ),
            $valid_taxes
        ) );

        return [
            'enabled_for' => $enabled_for,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
