<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Redirect 404 -- Redirect 404 pages to the homepage or a custom URL.
 *
 * Hooks into template_redirect to catch 404 responses and redirect them.
 * Skips admin and REST API requests. Includes loop prevention.
 *
 * @package WPTransformed
 */
class Redirect_404 extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'redirect-404';
    }

    public function get_title(): string {
        return __( 'Redirect 404', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Automatically redirect 404 pages to the homepage or a custom URL.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enabled'     => true,
            'redirect_to' => 'home',
            'custom_url'  => '',
            'status_code' => 301,
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'template_redirect', [ $this, 'handle_404_redirect' ] );
    }

    /**
     * Redirect 404 pages.
     */
    public function handle_404_redirect(): void {
        if ( ! is_404() ) {
            return;
        }

        // Don't redirect admin or REST API requests.
        if ( is_admin() ) {
            return;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        $settings    = $this->get_settings();
        $redirect_to = $settings['redirect_to'] ?? 'home';
        $status_code = (int) ( $settings['status_code'] ?? 301 );

        // Validate status code.
        if ( ! in_array( $status_code, [ 301, 302, 307 ], true ) ) {
            $status_code = 301;
        }

        if ( 'custom' === $redirect_to ) {
            $url = trim( (string) ( $settings['custom_url'] ?? '' ) );
            if ( '' === $url ) {
                $url = home_url( '/' );
            }
        } else {
            $url = home_url( '/' );
        }

        $url = esc_url_raw( $url );

        // Loop prevention: don't redirect if target is the current URL.
        $current_url = home_url( add_query_arg( [] ) );
        if ( untrailingslashit( $url ) === untrailingslashit( $current_url ) ) {
            return;
        }

        wp_safe_redirect( $url, $status_code, 'WPTransformed' );
        exit;
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Redirect 404 pages automatically', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Redirect To', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="radio" name="wpt_redirect_to" value="home"
                                   <?php checked( ( $settings['redirect_to'] ?? 'home' ), 'home' ); ?>>
                            <?php esc_html_e( 'Homepage', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="radio" name="wpt_redirect_to" value="custom"
                                   <?php checked( ( $settings['redirect_to'] ?? 'home' ), 'custom' ); ?>>
                            <?php esc_html_e( 'Custom URL', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-custom-url"><?php esc_html_e( 'Custom URL', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="url" id="wpt-custom-url" name="wpt_custom_url"
                           value="<?php echo esc_attr( $settings['custom_url'] ?? '' ); ?>"
                           class="regular-text"
                           placeholder="https://example.com/custom-page">
                    <p class="description">
                        <?php esc_html_e( 'Only used when "Custom URL" is selected above.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-status-code"><?php esc_html_e( 'HTTP Status Code', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-status-code" name="wpt_status_code">
                        <option value="301" <?php selected( (int) ( $settings['status_code'] ?? 301 ), 301 ); ?>>
                            301 — <?php esc_html_e( 'Permanent Redirect', 'wptransformed' ); ?>
                        </option>
                        <option value="302" <?php selected( (int) ( $settings['status_code'] ?? 301 ), 302 ); ?>>
                            302 — <?php esc_html_e( 'Temporary Redirect', 'wptransformed' ); ?>
                        </option>
                        <option value="307" <?php selected( (int) ( $settings['status_code'] ?? 301 ), 307 ); ?>>
                            307 — <?php esc_html_e( 'Temporary Redirect (Strict)', 'wptransformed' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        $redirect_to = sanitize_text_field( (string) ( $raw['wpt_redirect_to'] ?? 'home' ) );
        if ( ! in_array( $redirect_to, [ 'home', 'custom' ], true ) ) {
            $redirect_to = 'home';
        }

        $status_code = (int) ( $raw['wpt_status_code'] ?? 301 );
        if ( ! in_array( $status_code, [ 301, 302, 307 ], true ) ) {
            $status_code = 301;
        }

        return [
            'enabled'     => ! empty( $raw['wpt_enabled'] ),
            'redirect_to' => $redirect_to,
            'custom_url'  => esc_url_raw( (string) ( $raw['wpt_custom_url'] ?? '' ) ),
            'status_code' => $status_code,
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
