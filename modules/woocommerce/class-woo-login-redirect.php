<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * WooCommerce Login Redirect — Redirect WooCommerce customers to a configurable
 * page after login or registration, without affecting admin logins.
 *
 * @package WPTransformed
 */
class Woo_Login_Redirect extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'woo-login-redirect';
    }

    public function get_title(): string {
        return __( 'WooCommerce Login Redirect', 'wptransformed' );
    }

    public function get_category(): string {
        return 'woocommerce';
    }

    public function get_description(): string {
        return __( 'Redirect WooCommerce customers to a specific page after login or registration.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'customer_redirect' => 'my_account',
            'redirect_url'     => '',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        add_filter( 'woocommerce_login_redirect', [ $this, 'handle_login_redirect' ], 10, 2 );
        add_filter( 'woocommerce_registration_redirect', [ $this, 'handle_registration_redirect' ] );
    }

    // ── Callbacks ─────────────────────────────────────────────

    /**
     * Redirect customers after WooCommerce login.
     * Does not affect users with admin capabilities.
     *
     * @param string   $redirect Default redirect URL.
     * @param \WP_User $user     The logged-in user.
     * @return string
     */
    public function handle_login_redirect( string $redirect, $user ): string {
        // Don't redirect admins/editors — let WP handle them normally.
        if ( $user instanceof \WP_User && $user->has_cap( 'edit_posts' ) ) {
            return $redirect;
        }

        $url = $this->get_redirect_url();
        return $url !== '' ? $url : $redirect;
    }

    /**
     * Redirect customers after WooCommerce registration.
     *
     * @param string $redirect Default redirect URL.
     * @return string
     */
    public function handle_registration_redirect( string $redirect ): string {
        $url = $this->get_redirect_url();
        return $url !== '' ? $url : $redirect;
    }

    /**
     * Build the redirect URL based on settings.
     *
     * @return string URL or empty string if not configured.
     */
    private function get_redirect_url(): string {
        $settings = $this->get_settings();
        $target   = $settings['customer_redirect'] ?? 'my_account';

        switch ( $target ) {
            case 'my_account':
                $page_id = (int) get_option( 'woocommerce_myaccount_page_id', 0 );
                return $page_id > 0 ? (string) get_permalink( $page_id ) : '';

            case 'shop':
                $page_id = (int) get_option( 'woocommerce_shop_page_id', 0 );
                return $page_id > 0 ? (string) get_permalink( $page_id ) : '';

            case 'cart':
                return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';

            case 'custom_url':
                $url = trim( $settings['redirect_url'] ?? '' );
                return $url !== '' ? esc_url_raw( $url ) : '';

            default:
                return '';
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $target   = $settings['customer_redirect'] ?? 'my_account';
        $url      = $settings['redirect_url'] ?? '';

        $options = [
            'my_account' => __( 'My Account page', 'wptransformed' ),
            'shop'       => __( 'Shop page', 'wptransformed' ),
            'cart'       => __( 'Cart page', 'wptransformed' ),
            'custom_url' => __( 'Custom URL', 'wptransformed' ),
        ];
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_customer_redirect"><?php esc_html_e( 'Redirect customers to', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select name="wpt_customer_redirect" id="wpt_customer_redirect">
                        <?php foreach ( $options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $target, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Where to send WooCommerce customers after login or registration. Admin users are not affected.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr id="wpt-custom-url-row" <?php echo $target !== 'custom_url' ? 'style="display:none;"' : ''; ?>>
                <th scope="row">
                    <label for="wpt_redirect_url"><?php esc_html_e( 'Custom URL', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="url" name="wpt_redirect_url" id="wpt_redirect_url"
                           value="<?php echo esc_attr( $url ); ?>"
                           class="regular-text" placeholder="https://example.com/welcome">
                    <p class="description">
                        <?php esc_html_e( 'Enter a full URL including https://.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <script>
        (function() {
            var select = document.getElementById('wpt_customer_redirect');
            var row    = document.getElementById('wpt-custom-url-row');
            if (!select || !row) return;
            select.addEventListener('change', function() {
                row.style.display = this.value === 'custom_url' ? '' : 'none';
            });
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_targets = [ 'my_account', 'shop', 'cart', 'custom_url' ];
        $target        = sanitize_key( $raw['wpt_customer_redirect'] ?? 'my_account' );

        if ( ! in_array( $target, $valid_targets, true ) ) {
            $target = 'my_account';
        }

        $url = '';
        if ( $target === 'custom_url' ) {
            $url = esc_url_raw( trim( $raw['wpt_redirect_url'] ?? '' ) );
        }

        return [
            'customer_redirect' => $target,
            'redirect_url'     => $url,
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
