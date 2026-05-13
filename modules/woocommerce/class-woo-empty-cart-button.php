<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * WooCommerce Empty Cart Button — Add a button to the cart page that empties
 * the entire cart with optional confirmation dialog.
 *
 * @package WPTransformed
 */
class Woo_Empty_Cart_Button extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'woo-empty-cart-button';
    }

    public function get_title(): string {
        return __( 'WooCommerce Empty Cart Button', 'wptransformed' );
    }

    public function get_category(): string {
        return 'woocommerce';
    }

    public function get_description(): string {
        return __( 'Add a button to the WooCommerce cart page to empty the entire cart.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'button_text'  => 'Empty Cart',
            'button_style' => 'link',
            'confirm'      => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Output the empty cart button in the cart actions area.
        add_action( 'woocommerce_cart_actions', [ $this, 'render_empty_cart_button' ] );

        // Handle the empty cart action before any output.
        add_action( 'template_redirect', [ $this, 'handle_empty_cart' ] );

        // Enqueue confirmation JS on cart page.
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_confirm_script' ] );
    }

    // ── Callbacks ─────────────────────────────────────────────

    /**
     * Render the empty cart button inside the cart actions area.
     */
    public function render_empty_cart_button(): void {
        $settings     = $this->get_settings();
        $button_text  = sanitize_text_field( $settings['button_text'] ?? 'Empty Cart' );
        $button_style = $settings['button_style'] ?? 'link';
        $confirm      = ! empty( $settings['confirm'] );
        $nonce_url    = wp_nonce_url(
            add_query_arg( 'wpt_empty_cart', '1', wc_get_cart_url() ),
            'wpt_empty_cart_action',
            '_wpt_nonce'
        );

        $classes = 'wpt-empty-cart-btn';
        if ( $button_style === 'button' ) {
            $classes .= ' button';
        }

        printf(
            '<a href="%s" class="%s"%s>%s</a>',
            esc_url( $nonce_url ),
            esc_attr( $classes ),
            $confirm ? ' data-wpt-confirm="1"' : '',
            esc_html( $button_text )
        );
    }

    /**
     * Handle the empty cart action on template_redirect.
     */
    public function handle_empty_cart(): void {
        if ( ! isset( $_GET['wpt_empty_cart'] ) || $_GET['wpt_empty_cart'] !== '1' ) {
            return;
        }

        if ( ! isset( $_GET['_wpt_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpt_nonce'] ) ), 'wpt_empty_cart_action' ) ) {
            return;
        }

        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->empty_cart();
        }

        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    /**
     * Enqueue confirmation dialog JS on the cart page.
     */
    public function maybe_enqueue_confirm_script(): void {
        if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
            return;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['confirm'] ) ) {
            return;
        }

        $confirm_message = esc_js( __( 'Are you sure you want to empty your cart?', 'wptransformed' ) );

        wp_add_inline_script( 'woocommerce', "
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.wpt-empty-cart-btn[data-wpt-confirm]');
                if (btn && !confirm('" . $confirm_message . "')) {
                    e.preventDefault();
                }
            });
        " );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings     = $this->get_settings();
        $button_text  = $settings['button_text'] ?? 'Empty Cart';
        $button_style = $settings['button_style'] ?? 'link';
        $confirm      = $settings['confirm'] ?? true;

        $style_options = [
            'link'   => __( 'Text link', 'wptransformed' ),
            'button' => __( 'Button', 'wptransformed' ),
        ];
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_button_text"><?php esc_html_e( 'Button Text', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" name="wpt_button_text" id="wpt_button_text"
                           value="<?php echo esc_attr( $button_text ); ?>"
                           class="regular-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_button_style"><?php esc_html_e( 'Button Style', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select name="wpt_button_style" id="wpt_button_style">
                        <?php foreach ( $style_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $button_style, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Confirmation', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_confirm" value="1"
                               <?php checked( ! empty( $confirm ) ); ?>>
                        <?php esc_html_e( 'Show confirmation dialog before emptying cart', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Displays a browser confirmation prompt before clearing all items from the cart.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_styles = [ 'link', 'button' ];
        $style        = sanitize_key( $raw['wpt_button_style'] ?? 'link' );

        if ( ! in_array( $style, $valid_styles, true ) ) {
            $style = 'link';
        }

        $button_text = sanitize_text_field( $raw['wpt_button_text'] ?? 'Empty Cart' );
        if ( $button_text === '' ) {
            $button_text = 'Empty Cart';
        }

        return [
            'button_text'  => $button_text,
            'button_style' => $style,
            'confirm'      => ! empty( $raw['wpt_confirm'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom admin CSS or JS needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
