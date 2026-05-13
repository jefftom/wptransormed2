<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable XML-RPC — Harden WordPress by disabling or limiting XML-RPC.
 *
 * Features:
 *  - Disable XML-RPC completely (filter xmlrpc_enabled, empty methods)
 *  - Remove X-Pingback header from responses
 *  - Optionally disable only pingbacks while keeping other XML-RPC methods
 *  - Jetpack active warning (Jetpack relies on XML-RPC)
 *
 * @package WPTransformed
 */
class Disable_Xmlrpc extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-xmlrpc';
    }

    public function get_title(): string {
        return __( 'Disable XML-RPC', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Disable or limit XML-RPC to protect your site from brute-force and DDoS amplification attacks.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'disable_completely'    => true,
            'disable_pingbacks_only' => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( ! empty( $settings['disable_completely'] ) ) {
            add_filter( 'xmlrpc_enabled', '__return_false' );
            add_filter( 'xmlrpc_methods', '__return_empty_array' );
            add_filter( 'wp_headers', [ $this, 'remove_x_pingback_header' ] );
            remove_action( 'wp_head', 'rsd_link' );
        } elseif ( ! empty( $settings['disable_pingbacks_only'] ) ) {
            add_filter( 'xmlrpc_methods', [ $this, 'remove_pingback_methods' ] );
            add_filter( 'wp_headers', [ $this, 'remove_x_pingback_header' ] );
        }
    }

    // ── Filters ───────────────────────────────────────────────

    /**
     * Remove the X-Pingback header from HTTP responses.
     *
     * @param array<string, string> $headers Response headers.
     * @return array<string, string>
     */
    public function remove_x_pingback_header( array $headers ): array {
        unset( $headers['X-Pingback'] );
        return $headers;
    }

    /**
     * Remove pingback-related methods from XML-RPC.
     *
     * @param array<string, mixed> $methods XML-RPC methods.
     * @return array<string, mixed>
     */
    public function remove_pingback_methods( array $methods ): array {
        unset( $methods['pingback.ping'] );
        unset( $methods['pingback.extensions.getPingbacks'] );
        return $methods;
    }

    // ── Admin UI ──────────────────────────────────────────────

    public function render_settings(): void {
        $settings       = $this->get_settings();
        $jetpack_active = $this->is_jetpack_active();
        ?>
        <?php if ( $jetpack_active ) : ?>
            <div class="notice notice-warning inline" style="margin: 10px 0;">
                <p>
                    <strong><?php esc_html_e( 'Warning:', 'wptransformed' ); ?></strong>
                    <?php esc_html_e( 'Jetpack is active and relies on XML-RPC for some features. Disabling XML-RPC completely may break Jetpack functionality.', 'wptransformed' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Completely', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_disable_completely" value="1"
                               <?php checked( ! empty( $settings['disable_completely'] ) ); ?>>
                        <?php esc_html_e( 'Disable XML-RPC entirely (blocks all remote publishing, pingbacks, and third-party integrations)', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Recommended unless you use mobile apps, Jetpack, or other services that require XML-RPC.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Pingbacks Only', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_disable_pingbacks_only" value="1"
                               <?php checked( ! empty( $settings['disable_pingbacks_only'] ) ); ?>>
                        <?php esc_html_e( 'Only disable pingback methods (keeps other XML-RPC functionality intact)', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Use this if you need XML-RPC for remote publishing but want to prevent pingback abuse.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $disable_completely    = ! empty( $raw['wpt_disable_completely'] );
        $disable_pingbacks_only = ! empty( $raw['wpt_disable_pingbacks_only'] );

        // If both are checked, "disable completely" wins.
        if ( $disable_completely ) {
            $disable_pingbacks_only = false;
        }

        return [
            'disable_completely'    => $disable_completely,
            'disable_pingbacks_only' => $disable_pingbacks_only,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Check if Jetpack plugin is active.
     */
    private function is_jetpack_active(): bool {
        return defined( 'JETPACK__VERSION' )
            || class_exists( 'Jetpack', false );
    }

    // ── Cleanup ──────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'options' => [],
        ];
    }
}
