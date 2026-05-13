<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Embeds — Remove WordPress oEmbed and embed functionality from the frontend.
 *
 * Deregisters wp-embed script, removes oEmbed discovery links, removes
 * REST oEmbed endpoints, and optionally filters embed HTML to plain URLs.
 * Preserves embed functionality in admin for Gutenberg previews.
 *
 * @package WPTransformed
 */
class Disable_Embeds extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-embeds';
    }

    public function get_title(): string {
        return __( 'Disable Embeds', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Remove oEmbed discovery, embed scripts, and embed HTML from the frontend.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'disable_oembed'          => true,
            'disable_embed_discovery' => true,
            'remove_embed_js'         => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Don't disable in admin — Gutenberg needs embed previews.
        if ( is_admin() ) {
            return;
        }

        $settings = $this->get_settings();

        if ( ! empty( $settings['remove_embed_js'] ) ) {
            add_action( 'wp_footer', [ $this, 'deregister_embed_script' ] );
        }

        if ( ! empty( $settings['disable_embed_discovery'] ) ) {
            remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        }

        // Remove REST oEmbed link from wp_head and disable oEmbed.
        if ( ! empty( $settings['disable_oembed'] ) ) {
            remove_action( 'wp_head', 'wp_oembed_add_host_js' );

                add_filter( 'rest_endpoints', [ $this, 'remove_oembed_endpoints' ] );
            add_filter( 'embed_oembed_html', [ $this, 'filter_embed_html' ], 10, 2 );
            add_filter( 'embed_oembed_discover', '__return_false' );
        }
    }

    // ── Callbacks ─────────────────────────────────────────────

    /**
     * Deregister the wp-embed script.
     */
    public function deregister_embed_script(): void {
        wp_deregister_script( 'wp-embed' );
    }

    /**
     * Remove oEmbed REST API endpoints.
     *
     * @param array<string,mixed> $endpoints REST endpoints.
     * @return array<string,mixed>
     */
    public function remove_oembed_endpoints( array $endpoints ): array {
        unset( $endpoints['/oembed/1.0/embed'] );
        unset( $endpoints['/oembed/1.0/proxy'] );
        return $endpoints;
    }

    /**
     * Replace embed HTML with a plain link to the URL.
     *
     * @param string $html Embed HTML.
     * @param string $url  Original URL.
     * @return string
     */
    public function filter_embed_html( string $html, string $url ): string {
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'oEmbed', 'wptransformed' ); ?></th>
                <td>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="wpt_disable_oembed" value="1"
                               <?php checked( ! empty( $settings['disable_oembed'] ) ); ?>>
                        <?php esc_html_e( 'Disable oEmbed functionality', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Removes oEmbed REST endpoints and converts embed HTML to plain links.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Discovery Links', 'wptransformed' ); ?></th>
                <td>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="wpt_disable_embed_discovery" value="1"
                               <?php checked( ! empty( $settings['disable_embed_discovery'] ) ); ?>>
                        <?php esc_html_e( 'Remove oEmbed discovery links from page head', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Removes the link tags that allow other sites to discover your embeddable content.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Embed Script', 'wptransformed' ); ?></th>
                <td>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="wpt_remove_embed_js" value="1"
                               <?php checked( ! empty( $settings['remove_embed_js'] ) ); ?>>
                        <?php esc_html_e( 'Remove wp-embed JavaScript', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Deregisters the wp-embed script from the frontend. Embed previews in the block editor are not affected.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <p class="description" style="margin-top: 12px; font-style: italic;">
            <?php esc_html_e( 'These settings only affect the frontend. Gutenberg embed block previews in the admin are preserved.', 'wptransformed' ); ?>
        </p>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'disable_oembed'          => ! empty( $raw['wpt_disable_oembed'] ),
            'disable_embed_discovery' => ! empty( $raw['wpt_disable_embed_discovery'] ),
            'remove_embed_js'         => ! empty( $raw['wpt_remove_embed_js'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
