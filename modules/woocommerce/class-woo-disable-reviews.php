<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * WooCommerce Disable Reviews — Disable product reviews globally or per product,
 * removing the reviews tab and closing comments on product post types.
 *
 * @package WPTransformed
 */
class Woo_Disable_Reviews extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'woo-disable-reviews';
    }

    public function get_title(): string {
        return __( 'WooCommerce Disable Reviews', 'wptransformed' );
    }

    public function get_category(): string {
        return 'woocommerce';
    }

    public function get_description(): string {
        return __( 'Disable WooCommerce product reviews globally or on a per-product basis.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'disable_all'         => true,
            'disable_per_product' => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $settings = $this->get_settings();

        if ( ! empty( $settings['disable_all'] ) ) {
            // Remove the reviews tab from product pages.
            add_filter( 'woocommerce_product_tabs', [ $this, 'remove_reviews_tab' ], 999 );

            // Close comments on product post type.
            add_filter( 'comments_open', [ $this, 'close_product_comments' ], 20, 2 );

            // Remove comments support from product post type.
            add_action( 'init', [ $this, 'remove_product_comment_support' ], 100 );

            // Disable the WooCommerce reviews setting.
            add_filter( 'pre_option_woocommerce_enable_reviews', [ $this, 'force_disable_reviews_option' ] );
        }

        // Per-product disable is handled via the product meta box.
        if ( ! empty( $settings['disable_per_product'] ) && empty( $settings['disable_all'] ) ) {
            add_filter( 'woocommerce_product_tabs', [ $this, 'maybe_remove_reviews_tab_per_product' ], 999 );
            add_filter( 'comments_open', [ $this, 'maybe_close_product_comments_per_product' ], 20, 2 );
            add_action( 'woocommerce_product_options_advanced', [ $this, 'render_product_metabox_field' ] );
            add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_metabox_field' ] );
        }
    }

    // ── Global Disable Callbacks ──────────────────────────────

    /**
     * Remove the reviews tab from all product pages.
     *
     * @param array<string,array<string,mixed>> $tabs Product page tabs.
     * @return array<string,array<string,mixed>>
     */
    public function remove_reviews_tab( array $tabs ): array {
        unset( $tabs['reviews'] );
        return $tabs;
    }

    /**
     * Close comments on all products.
     *
     * @param bool $open    Whether comments are open.
     * @param int  $post_id Post ID.
     * @return bool
     */
    public function close_product_comments( bool $open, int $post_id ): bool {
        if ( get_post_type( $post_id ) === 'product' ) {
            return false;
        }
        return $open;
    }

    /**
     * Remove comments support from the product post type.
     */
    public function remove_product_comment_support(): void {
        remove_post_type_support( 'product', 'comments' );
    }

    /**
     * Force the woocommerce_enable_reviews option to 'no'.
     *
     * @return string
     */
    public function force_disable_reviews_option(): string {
        return 'no';
    }

    // ── Per-Product Disable Callbacks ─────────────────────────

    /**
     * Conditionally remove the reviews tab for specific products.
     *
     * @param array<string,array<string,mixed>> $tabs Product page tabs.
     * @return array<string,array<string,mixed>>
     */
    public function maybe_remove_reviews_tab_per_product( array $tabs ): array {
        global $post;

        if ( $post && get_post_meta( $post->ID, '_wpt_disable_reviews', true ) === 'yes' ) {
            unset( $tabs['reviews'] );
        }

        return $tabs;
    }

    /**
     * Close comments on products with per-product disable.
     *
     * @param bool $open    Whether comments are open.
     * @param int  $post_id Post ID.
     * @return bool
     */
    public function maybe_close_product_comments_per_product( bool $open, int $post_id ): bool {
        if ( get_post_type( $post_id ) === 'product' ) {
            if ( get_post_meta( $post_id, '_wpt_disable_reviews', true ) === 'yes' ) {
                return false;
            }
        }
        return $open;
    }

    /**
     * Add per-product disable reviews field to the product Advanced tab.
     */
    public function render_product_metabox_field(): void {
        woocommerce_wp_checkbox( [
            'id'          => '_wpt_disable_reviews',
            'label'       => __( 'Disable Reviews', 'wptransformed' ),
            'description' => __( 'Disable reviews for this product only.', 'wptransformed' ),
        ] );
    }

    /**
     * Save per-product disable reviews field.
     *
     * @param int $post_id Product ID.
     */
    public function save_product_metabox_field( int $post_id ): void {
        // Nonce is already verified by WooCommerce at this point.
        $value = isset( $_POST['_wpt_disable_reviews'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_wpt_disable_reviews', sanitize_key( $value ) );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Review Settings', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_all" value="1"
                                   <?php checked( ! empty( $settings['disable_all'] ) ); ?>
                                   id="wpt-disable-all">
                            <strong><?php esc_html_e( 'Disable all product reviews', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Removes the reviews tab from all product pages, closes comments on all products, and removes comment support from the product post type.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_per_product" value="1"
                                   <?php checked( ! empty( $settings['disable_per_product'] ) ); ?>
                                   id="wpt-disable-per-product">
                            <strong><?php esc_html_e( 'Enable per-product review control', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Adds a "Disable Reviews" checkbox to each product\'s Advanced tab. Only works when "Disable all" is unchecked.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <script>
        (function() {
            var disableAll = document.getElementById('wpt-disable-all');
            var perProduct = document.getElementById('wpt-disable-per-product');
            if (!disableAll || !perProduct) return;

            function toggle() {
                perProduct.disabled = disableAll.checked;
                if (disableAll.checked) {
                    perProduct.checked = false;
                }
            }
            disableAll.addEventListener('change', toggle);
            toggle();
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $disable_all = ! empty( $raw['wpt_disable_all'] );

        return [
            'disable_all'         => $disable_all,
            'disable_per_product' => $disable_all ? false : ! empty( $raw['wpt_disable_per_product'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom CSS or JS needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'post_meta' => [
                'key' => '_wpt_disable_reviews',
            ],
        ];
    }
}
