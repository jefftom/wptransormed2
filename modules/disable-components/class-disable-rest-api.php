<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable REST API — Restrict or disable the WordPress REST API.
 *
 * Modes:
 *  - disabled: Block all unauthenticated REST requests (returns 401)
 *  - auth_only: Require authentication for all REST requests (default)
 *  - enabled: Leave REST API fully open (module effectively inactive)
 *
 * Safety:
 *  - Always allows wp/v2 for authenticated users when Gutenberg is active
 *  - Always allows WooCommerce REST namespace (wc/) when WooCommerce is active
 *  - Allows specific namespaces via allowlist
 *
 * @package WPTransformed
 */
class Disable_Rest_Api extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-rest-api';
    }

    public function get_title(): string {
        return __( 'Disable REST API', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Restrict the WordPress REST API to authenticated users only or disable it entirely for public access.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'mode'           => 'auth_only',
            'allow_specific' => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( $settings['mode'] === 'enabled' ) {
            return;
        }

        add_filter( 'rest_authentication_errors', [ $this, 'filter_rest_authentication' ], 99 );
    }

    // ── Filters ───────────────────────────────────────────────

    /**
     * Filter REST API authentication to restrict access.
     *
     * @param \WP_Error|true|null $result Authentication result.
     * @return \WP_Error|true|null
     */
    public function filter_rest_authentication( $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( is_user_logged_in() ) {
            return $result;
        }

        if ( $this->is_route_allowed( $this->get_current_rest_route() ) ) {
            return $result;
        }

        return new \WP_Error(
            'rest_not_logged_in',
            __( 'REST API access requires authentication.', 'wptransformed' ),
            [ 'status' => 401 ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Get the current REST API route being requested.
     */
    private function get_current_rest_route(): string {
        $rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';

        if ( empty( $rest_route ) ) {
            $rest_prefix = rest_get_url_prefix();
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $prefix_pos  = strpos( $request_uri, '/' . $rest_prefix . '/' );

            if ( $prefix_pos !== false ) {
                $rest_route = substr( $request_uri, $prefix_pos + strlen( $rest_prefix ) + 1 );
                $rest_route = strtok( $rest_route, '?' );
                if ( $rest_route === false ) {
                    $rest_route = '';
                }
            }
        }

        return ltrim( (string) $rest_route, '/' );
    }

    /**
     * Check if a REST route is in the allowed namespaces.
     *
     * @param string $route The REST route being requested.
     * @return bool
     */
    private function is_route_allowed( string $route ): bool {
        if ( $route === '' ) {
            return false;
        }

        $settings         = $this->get_settings();
        $allowed          = (array) ( $settings['allow_specific'] ?? [] );
        $auto_allowed     = $this->get_auto_allowed_namespaces();
        $all_allowed      = array_merge( $allowed, $auto_allowed );

        foreach ( $all_allowed as $namespace ) {
            $namespace = trim( (string) $namespace, '/' );
            if ( $namespace === '' ) {
                continue;
            }

            if ( strpos( $route, $namespace ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get namespaces that are automatically allowed based on active plugins.
     *
     * @return string[]
     */
    private function get_auto_allowed_namespaces(): array {
        $auto = [];

        if ( $this->is_woocommerce_active() ) {
            $auto[] = 'wc/';
            $auto[] = 'wc-analytics/';
            $auto[] = 'wc-admin/';
        }

        return $auto;
    }

    /**
     * Check if WooCommerce is active.
     */
    private function is_woocommerce_active(): bool {
        return defined( 'WC_VERSION' )
            || class_exists( 'WooCommerce', false );
    }

    /**
     * Check if Gutenberg / block editor is active.
     */
    private function is_gutenberg_active(): bool {
        return function_exists( 'use_block_editor_for_post_type' );
    }

    // ── Admin UI ──────────────────────────────────────────────

    public function render_settings(): void {
        $settings       = $this->get_settings();
        $mode           = $settings['mode'];
        $allow_specific = (array) ( $settings['allow_specific'] ?? [] );
        $allow_text     = implode( "\n", $allow_specific );

        $wc_active       = $this->is_woocommerce_active();
        $gutenberg_active = $this->is_gutenberg_active();
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Mode', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_mode" value="auth_only"
                                   <?php checked( $mode, 'auth_only' ); ?>>
                            <strong><?php esc_html_e( 'Require Authentication', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Only logged-in users can access the REST API. Unauthenticated requests receive a 401 error. This is the recommended setting for most sites.', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_mode" value="disabled"
                                   <?php checked( $mode, 'disabled' ); ?>>
                            <strong><?php esc_html_e( 'Disable for Public', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Block all unauthenticated REST requests except those in the allowlist below.', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="radio" name="wpt_mode" value="enabled"
                                   <?php checked( $mode, 'enabled' ); ?>>
                            <strong><?php esc_html_e( 'Leave Enabled', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'REST API is fully accessible. Module is effectively inactive in this mode.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Allowed Namespaces', 'wptransformed' ); ?></th>
                <td>
                    <textarea name="wpt_allow_specific" rows="5" class="large-text code"
                              placeholder="oembed/1.0&#10;wp-site-health/"><?php echo esc_textarea( $allow_text ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One namespace per line. Requests matching these prefixes will be allowed even for unauthenticated users.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php if ( $wc_active || $gutenberg_active ) : ?>
            <div class="notice notice-info inline" style="margin: 10px 0;">
                <p>
                    <strong><?php esc_html_e( 'Auto-allowed namespaces:', 'wptransformed' ); ?></strong>
                </p>
                <ul style="margin: 4px 0 8px 20px; list-style: disc;">
                    <?php if ( $gutenberg_active ) : ?>
                        <li><?php esc_html_e( 'wp/v2 — Required by the block editor (Gutenberg) for authenticated users', 'wptransformed' ); ?></li>
                    <?php endif; ?>
                    <?php if ( $wc_active ) : ?>
                        <li><?php esc_html_e( 'wc/, wc-analytics/, wc-admin/ — Required by WooCommerce', 'wptransformed' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_modes = [ 'disabled', 'auth_only', 'enabled' ];
        $mode        = sanitize_text_field( $raw['wpt_mode'] ?? 'auth_only' );
        if ( ! in_array( $mode, $valid_modes, true ) ) {
            $mode = 'auth_only';
        }

        $allow_raw  = $raw['wpt_allow_specific'] ?? '';
        $allow_lines = array_filter(
            array_map(
                function ( string $line ): string {
                    $clean = sanitize_text_field( trim( $line ) );
                    return trim( $clean, '/' );
                },
                explode( "\n", (string) $allow_raw )
            ),
            function ( string $line ): bool {
                return $line !== '';
            }
        );

        return [
            'mode'           => $mode,
            'allow_specific' => array_values( $allow_lines ),
        ];
    }

    // ── Cleanup ──────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'options' => [],
        ];
    }
}
