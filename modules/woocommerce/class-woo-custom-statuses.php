<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * WooCommerce Custom Order Statuses — Register custom order statuses with
 * configurable labels, colors, and optional email notifications.
 *
 * @package WPTransformed
 */
class Woo_Custom_Statuses extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'woo-custom-statuses';
    }

    public function get_title(): string {
        return __( 'WooCommerce Custom Order Statuses', 'wptransformed' );
    }

    public function get_category(): string {
        return 'woocommerce';
    }

    public function get_description(): string {
        return __( 'Create custom WooCommerce order statuses with colors and optional email notifications.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'statuses' => [
                [
                    'slug'       => 'wc-awaiting-parts',
                    'label'      => 'Awaiting Parts',
                    'color'      => '#f0ad4e',
                    'send_email' => true,
                ],
            ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Register custom post statuses.
        add_action( 'init', [ $this, 'register_custom_statuses' ], 20 );

        // Add custom statuses to the WC order statuses dropdown.
        add_filter( 'wc_order_statuses', [ $this, 'add_order_statuses' ] );

        // AJAX handlers for CRUD.
        add_action( 'wp_ajax_wpt_woo_save_status', [ $this, 'ajax_save_status' ] );
        add_action( 'wp_ajax_wpt_woo_delete_status', [ $this, 'ajax_delete_status' ] );

        // Admin styles for status colors in order list.
        add_action( 'admin_head', [ $this, 'output_status_colors_css' ] );
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Normalize a status slug: sanitize and ensure wc- prefix.
     *
     * @param string $slug Raw slug.
     * @return string Normalized slug (may be empty if input was empty).
     */
    private function normalize_slug( string $slug ): string {
        $slug = sanitize_key( $slug );
        if ( $slug === '' ) {
            return '';
        }
        return strpos( $slug, 'wc-' ) === 0 ? $slug : 'wc-' . $slug;
    }

    // ── Register Statuses ─────────────────────────────────────

    /**
     * Register each custom status as a post status.
     */
    public function register_custom_statuses(): void {
        $settings = $this->get_settings();
        $statuses = $settings['statuses'] ?? [];

        foreach ( $statuses as $status ) {
            $slug  = $this->normalize_slug( $status['slug'] ?? '' );
            $label = sanitize_text_field( $status['label'] ?? '' );

            if ( $slug === '' || $label === '' ) {
                continue;
            }

            register_post_status( $slug, [
                'label'                     => $label,
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop(
                    $label . ' <span class="count">(%s)</span>',
                    $label . ' <span class="count">(%s)</span>',
                    'wptransformed'
                ),
            ] );
        }
    }

    /**
     * Add custom statuses to the WooCommerce order statuses dropdown.
     *
     * @param array<string,string> $statuses Existing order statuses.
     * @return array<string,string>
     */
    public function add_order_statuses( array $statuses ): array {
        $settings       = $this->get_settings();
        $custom_statuses = $settings['statuses'] ?? [];

        foreach ( $custom_statuses as $status ) {
            $slug  = $this->normalize_slug( $status['slug'] ?? '' );
            $label = sanitize_text_field( $status['label'] ?? '' );

            if ( $slug === '' || $label === '' ) {
                continue;
            }

            $statuses[ $slug ] = $label;
        }

        return $statuses;
    }

    // ── Status Colors CSS ─────────────────────────────────────

    /**
     * Output inline CSS for custom status colors in the orders list table.
     */
    public function output_status_colors_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }

        $statuses = $this->get_settings()['statuses'] ?? [];

        if ( empty( $statuses ) ) {
            return;
        }

        echo '<style>';
        foreach ( $statuses as $status ) {
            $slug  = $this->normalize_slug( $status['slug'] ?? '' );
            $color = sanitize_hex_color( $status['color'] ?? '#999999' );

            if ( $slug === '' || $color === null ) {
                continue;
            }

            // Strip wc- prefix for CSS class (WC uses status name without prefix).
            $css_slug = substr( $slug, 3 );

            printf(
                '.order-status.status-%s { background: %s; color: #fff; }',
                esc_attr( $css_slug ),
                esc_attr( $color )
            );
        }
        echo '</style>';
    }

    // ── AJAX Handlers ─────────────────────────────────────────

    /**
     * AJAX: Save (add or update) a custom order status.
     */
    public function ajax_save_status(): void {
        check_ajax_referer( 'wpt_woo_status_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $slug       = $this->normalize_slug( wp_unslash( $_POST['slug'] ?? '' ) );
        $label      = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
        $color      = sanitize_hex_color( wp_unslash( $_POST['color'] ?? '#999999' ) );
        $send_email = ! empty( $_POST['send_email'] );

        if ( $slug === '' || $label === '' ) {
            wp_send_json_error( [ 'message' => __( 'Slug and label are required.', 'wptransformed' ) ] );
        }

        // Prevent overriding built-in statuses.
        $builtin = [
            'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed',
            'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft',
        ];
        if ( in_array( $slug, $builtin, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Cannot override built-in WooCommerce statuses.', 'wptransformed' ) ] );
        }

        $settings = $this->get_settings();
        $statuses = $settings['statuses'] ?? [];

        // Check if updating existing or adding new.
        $found = false;
        foreach ( $statuses as $i => $existing ) {
            if ( $this->normalize_slug( $existing['slug'] ?? '' ) === $slug ) {
                $statuses[ $i ] = [
                    'slug'       => $slug,
                    'label'      => $label,
                    'color'      => $color ?? '#999999',
                    'send_email' => $send_email,
                ];
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            $statuses[] = [
                'slug'       => $slug,
                'label'      => $label,
                'color'      => $color ?? '#999999',
                'send_email' => $send_email,
            ];
        }

        $settings['statuses'] = array_values( $statuses );
        \WPTransformed\Core\Settings::set( $this->get_id(), $settings );

        wp_send_json_success( [
            'message'  => __( 'Status saved.', 'wptransformed' ),
            'statuses' => $settings['statuses'],
        ] );
    }

    /**
     * AJAX: Delete a custom order status.
     */
    public function ajax_delete_status(): void {
        check_ajax_referer( 'wpt_woo_status_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $slug = $this->normalize_slug( wp_unslash( $_POST['slug'] ?? '' ) );

        if ( $slug === '' ) {
            wp_send_json_error( [ 'message' => __( 'Status slug is required.', 'wptransformed' ) ] );
        }

        $settings = $this->get_settings();
        $statuses = $settings['statuses'] ?? [];

        $statuses = array_values( array_filter( $statuses, function ( array $status ) use ( $slug ): bool {
            return $this->normalize_slug( $status['slug'] ?? '' ) !== $slug;
        } ) );

        $settings['statuses'] = $statuses;
        \WPTransformed\Core\Settings::set( $this->get_id(), $settings );

        wp_send_json_success( [
            'message'  => __( 'Status deleted.', 'wptransformed' ),
            'statuses' => $statuses,
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $statuses = $settings['statuses'] ?? [];
        $nonce    = wp_create_nonce( 'wpt_woo_status_nonce' );
        ?>

        <div id="wpt-custom-statuses-app" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Custom Order Statuses', 'wptransformed' ); ?></th>
                    <td>
                        <div id="wpt-statuses-list">
                            <?php if ( empty( $statuses ) ) : ?>
                                <p class="description"><?php esc_html_e( 'No custom statuses defined yet.', 'wptransformed' ); ?></p>
                            <?php else : ?>
                                <?php foreach ( $statuses as $status ) :
                                    $slug  = esc_attr( sanitize_key( $status['slug'] ?? '' ) );
                                    $label = esc_html( sanitize_text_field( $status['label'] ?? '' ) );
                                    $color = esc_attr( sanitize_hex_color( $status['color'] ?? '#999999' ) ?: '#999999' );
                                    $email = ! empty( $status['send_email'] );
                                ?>
                                    <div class="wpt-status-row" data-slug="<?php echo $slug; ?>" style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; padding: 8px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                                        <span style="display: inline-block; width: 16px; height: 16px; border-radius: 50%; background: <?php echo $color; ?>;"></span>
                                        <strong><?php echo $label; ?></strong>
                                        <code><?php echo $slug; ?></code>
                                        <?php if ( $email ) : ?>
                                            <span style="color: #666; font-size: 12px;"><?php esc_html_e( '(email)', 'wptransformed' ); ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="button button-small wpt-delete-status" data-slug="<?php echo $slug; ?>"><?php esc_html_e( 'Delete', 'wptransformed' ); ?></button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Add New Status', 'wptransformed' ); ?></th>
                    <td>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">
                            <div>
                                <label for="wpt-new-slug"><?php esc_html_e( 'Slug', 'wptransformed' ); ?></label><br>
                                <input type="text" id="wpt-new-slug" placeholder="wc-awaiting-parts" style="width: 180px;">
                            </div>
                            <div>
                                <label for="wpt-new-label"><?php esc_html_e( 'Label', 'wptransformed' ); ?></label><br>
                                <input type="text" id="wpt-new-label" placeholder="Awaiting Parts" style="width: 180px;">
                            </div>
                            <div>
                                <label for="wpt-new-color"><?php esc_html_e( 'Color', 'wptransformed' ); ?></label><br>
                                <input type="color" id="wpt-new-color" value="#f0ad4e">
                            </div>
                            <div>
                                <label style="display: block;">
                                    <input type="checkbox" id="wpt-new-email" value="1">
                                    <?php esc_html_e( 'Send Email', 'wptransformed' ); ?>
                                </label>
                            </div>
                            <div>
                                <button type="button" class="button button-primary" id="wpt-add-status"><?php esc_html_e( 'Add Status', 'wptransformed' ); ?></button>
                            </div>
                        </div>
                        <p class="description" style="margin-top: 8px;">
                            <?php esc_html_e( 'Slug must start with "wc-". Built-in WooCommerce statuses cannot be overridden.', 'wptransformed' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <script>
        (function() {
            var app = document.getElementById('wpt-custom-statuses-app');
            if (!app) return;

            var nonce = app.getAttribute('data-nonce');

            function ajaxPost(action, data, callback) {
                var formData = new FormData();
                formData.append('action', action);
                formData.append('nonce', nonce);
                for (var key in data) {
                    if (data.hasOwnProperty(key)) {
                        formData.append(key, data[key]);
                    }
                }
                fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(callback);
            }

            // Add status.
            var addBtn = document.getElementById('wpt-add-status');
            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    var slug  = document.getElementById('wpt-new-slug').value.trim();
                    var label = document.getElementById('wpt-new-label').value.trim();
                    var color = document.getElementById('wpt-new-color').value;
                    var email = document.getElementById('wpt-new-email').checked ? '1' : '0';

                    if (!slug || !label) return;

                    ajaxPost('wpt_woo_save_status', {
                        slug: slug, label: label, color: color, send_email: email
                    }, function(resp) {
                        if (resp.success) {
                            location.reload();
                        }
                    });
                });
            }

            // Delete status.
            document.querySelectorAll('.wpt-delete-status').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var slug = this.getAttribute('data-slug');
                    ajaxPost('wpt_woo_delete_status', { slug: slug }, function(resp) {
                        if (resp.success) {
                            location.reload();
                        }
                    });
                });
            });
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // Settings are managed via AJAX, so just pass through the current saved state.
        return $this->get_settings();
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Inline JS/CSS only — no external assets needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'settings' => __( 'Custom order status definitions', 'wptransformed' ),
        ];
    }
}
