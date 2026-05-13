<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * WooCommerce Admin Cleanup — Remove WooCommerce marketing nags, unused menus,
 * dashboard widgets, and marketplace promotions from wp-admin.
 *
 * @package WPTransformed
 */
class Woo_Admin_Cleanup extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'woo-admin-cleanup';
    }

    public function get_title(): string {
        return __( 'WooCommerce Admin Cleanup', 'wptransformed' );
    }

    public function get_category(): string {
        return 'woocommerce';
    }

    public function get_description(): string {
        return __( 'Remove WooCommerce marketing nags, unused menus, dashboard widgets, and marketplace promotions.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'remove_marketplace'      => true,
            'remove_connect_nag'      => true,
            'remove_dashboard_widgets' => true,
            'hide_unused_menus'       => [ 'wc-admin', 'wc-reports' ],
            'remove_order_nags'       => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $settings = $this->get_settings();

        // Remove marketing and analytics features from WC Admin.
        if ( ! empty( $settings['remove_marketplace'] ) ) {
            add_filter( 'woocommerce_admin_features', [ $this, 'remove_admin_features' ] );
        }

        // Remove WooCommerce connect/setup nags.
        if ( ! empty( $settings['remove_connect_nag'] ) ) {
            add_filter( 'woocommerce_helper_suppress_admin_notices', '__return_true' );
            add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );
            add_filter( 'woocommerce_show_admin_notice', '__return_false' );
        }

        // Remove WooCommerce dashboard widgets.
        if ( ! empty( $settings['remove_dashboard_widgets'] ) ) {
            add_action( 'wp_dashboard_setup', [ $this, 'remove_dashboard_widgets' ], 999 );
        }

        // Hide unused WC admin menu pages.
        $menus_to_hide = $settings['hide_unused_menus'] ?? [];
        if ( ! empty( $menus_to_hide ) ) {
            add_action( 'admin_menu', [ $this, 'remove_menu_pages' ], 999 );
        }

        // Remove order-related admin nags (uses same filter as connect nag — only add if not already added).
        if ( ! empty( $settings['remove_order_nags'] ) && empty( $settings['remove_connect_nag'] ) ) {
            add_filter( 'woocommerce_show_admin_notice', '__return_false' );
        }
    }

    // ── Callbacks ─────────────────────────────────────────────

    /**
     * Remove marketing and analytics from WC admin features list.
     *
     * @param array<int,string> $features WC admin feature slugs.
     * @return array<int,string>
     */
    public function remove_admin_features( array $features ): array {
        $remove = [ 'marketing', 'analytics' ];
        return array_values( array_diff( $features, $remove ) );
    }

    /**
     * Remove WooCommerce dashboard widgets.
     */
    public function remove_dashboard_widgets(): void {
        remove_meta_box( 'woocommerce_dashboard_status', 'dashboard', 'normal' );
        remove_meta_box( 'woocommerce_dashboard_recent_reviews', 'dashboard', 'normal' );
        remove_meta_box( 'wc_admin_dashboard_setup', 'dashboard', 'normal' );
    }

    /**
     * Remove WooCommerce admin menu pages based on settings.
     */
    public function remove_menu_pages(): void {
        $settings     = $this->get_settings();
        $menus_to_hide = $settings['hide_unused_menus'] ?? [];

        $valid_menus = [ 'wc-admin', 'wc-reports' ];

        foreach ( $menus_to_hide as $menu_slug ) {
            $slug = sanitize_key( $menu_slug );
            if ( in_array( $slug, $valid_menus, true ) ) {
                remove_submenu_page( 'woocommerce', $slug );
            }
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        $available_menus = [
            'wc-admin'   => __( 'WC Admin (Home)', 'wptransformed' ),
            'wc-reports' => __( 'WC Reports', 'wptransformed' ),
        ];

        $hidden_menus = $settings['hide_unused_menus'] ?? [];
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Cleanup Options', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_remove_marketplace" value="1"
                                   <?php checked( ! empty( $settings['remove_marketplace'] ) ); ?>>
                            <?php esc_html_e( 'Remove Marketing and Analytics features', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Removes the Marketing hub, Analytics dashboard, and marketplace suggestions from WooCommerce admin.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_remove_connect_nag" value="1"
                                   <?php checked( ! empty( $settings['remove_connect_nag'] ) ); ?>>
                            <?php esc_html_e( 'Remove connect and setup nags', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Suppresses WooCommerce helper notices, marketplace suggestions, and setup prompts.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_remove_dashboard_widgets" value="1"
                                   <?php checked( ! empty( $settings['remove_dashboard_widgets'] ) ); ?>>
                            <?php esc_html_e( 'Remove WooCommerce dashboard widgets', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Removes the WooCommerce Status, Recent Reviews, and Setup widgets from the WordPress dashboard.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_remove_order_nags" value="1"
                                   <?php checked( ! empty( $settings['remove_order_nags'] ) ); ?>>
                            <?php esc_html_e( 'Remove order-related admin nags', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Suppresses order processing and fulfillment reminder notices.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Hide Menu Pages', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $available_menus as $slug => $label ) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="checkbox" name="wpt_hide_unused_menus[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, $hidden_menus, true ) ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Remove selected WooCommerce submenu pages from the admin sidebar.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_menus = [ 'wc-admin', 'wc-reports' ];

        $submitted_menus = array_map( 'sanitize_key', (array) ( $raw['wpt_hide_unused_menus'] ?? [] ) );
        $clean_menus     = array_values( array_intersect( $submitted_menus, $valid_menus ) );

        return [
            'remove_marketplace'       => ! empty( $raw['wpt_remove_marketplace'] ),
            'remove_connect_nag'       => ! empty( $raw['wpt_remove_connect_nag'] ),
            'remove_dashboard_widgets' => ! empty( $raw['wpt_remove_dashboard_widgets'] ),
            'hide_unused_menus'        => $clean_menus,
            'remove_order_nags'        => ! empty( $raw['wpt_remove_order_nags'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom CSS or JS needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
