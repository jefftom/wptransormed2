<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * External Links New Tab — Open external links in new tabs with security attributes.
 *
 * Filters post content, widget text, and comment text to add target="_blank"
 * and rel="noopener noreferrer" (optionally nofollow) to external links.
 * Respects a configurable exclude-domains list.
 *
 * @package WPTransformed
 */
class External_Links_New_Tab extends Module_Base {

    /**
     * Cached settings for use during content filtering (avoids repeated DB reads per link).
     *
     * @var array<string,mixed>|null
     */
    private ?array $cached_settings = null;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'external-links-new-tab';
    }

    public function get_title(): string {
        return __( 'External Links New Tab', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Automatically open external links in new tabs with security attributes.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'         => true,
            'add_nofollow'    => true,
            'exclude_domains' => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_filter( 'the_content', [ $this, 'process_links' ], 999 );
        add_filter( 'widget_text', [ $this, 'process_links' ], 999 );
        add_filter( 'comment_text', [ $this, 'process_links' ], 999 );
    }

    // ── Link Processing ───────────────────────────────────────

    /**
     * Process HTML content to add target and rel attributes to external links.
     *
     * @param string $content The HTML content.
     * @return string Modified content.
     */
    public function process_links( string $content ): string {
        if ( empty( $content ) ) {
            return $content;
        }

        // Cache settings for the duration of this filter pass.
        $this->cached_settings = $this->get_settings();

        $result = preg_replace_callback(
            '/<a\s([^>]*href\s*=\s*["\'][^"\']+["\'][^>]*)>/i',
            [ $this, 'process_single_link' ],
            $content
        ) ?? $content;

        $this->cached_settings = null;

        return $result;
    }

    /**
     * Process a single <a> tag match.
     *
     * @param string[] $match Regex match array.
     * @return string Modified <a> tag.
     */
    private function process_single_link( array $match ): string {
        $attrs = $match[1];

        if ( ! preg_match( '/href\s*=\s*["\']([^"\']+)["\']/i', $attrs, $href_match ) ) {
            return $match[0];
        }

        $href = $href_match[1];

        if ( ! preg_match( '/^https?:\/\//i', $href ) ) {
            return $match[0];
        }

        $link_host = $this->normalize_host( wp_parse_url( $href, PHP_URL_HOST ) );
        if ( empty( $link_host ) ) {
            return $match[0];
        }

        $site_host = $this->normalize_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( $link_host === $site_host ) {
            return $match[0];
        }

        $settings = $this->cached_settings ?? $this->get_settings();
        $excludes = (array) ( $settings['exclude_domains'] ?? [] );
        foreach ( $excludes as $domain ) {
            if ( $this->normalize_host( $domain ) === $link_host ) {
                return $match[0];
            }
        }

        if ( ! preg_match( '/target\s*=/i', $attrs ) ) {
            $attrs .= ' target="_blank"';
        }

        $rel_parts = [ 'noopener', 'noreferrer' ];
        if ( ! empty( $settings['add_nofollow'] ) ) {
            $rel_parts[] = 'nofollow';
        }

        if ( preg_match( '/rel\s*=\s*["\']([^"\']*)["\']/', $attrs, $rel_match ) ) {
            $existing = array_filter( array_map( 'trim', explode( ' ', $rel_match[1] ) ) );
            $merged   = array_unique( array_merge( $existing, $rel_parts ) );
            $new_rel  = implode( ' ', $merged );
            $attrs    = str_replace( $rel_match[0], 'rel="' . esc_attr( $new_rel ) . '"', $attrs );
        } else {
            $attrs .= ' rel="' . esc_attr( implode( ' ', $rel_parts ) ) . '"';
        }

        return '<a ' . $attrs . '>';
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Normalize a hostname by stripping www. prefix and lowercasing.
     *
     * @param string|null $host Raw hostname.
     * @return string Normalized hostname, or empty string.
     */
    private function normalize_host( ?string $host ): string {
        if ( empty( $host ) ) {
            return '';
        }
        return (string) preg_replace( '/^www\./i', '', strtolower( trim( $host ) ) );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings         = $this->get_settings();
        $exclude_domains  = (array) ( $settings['exclude_domains'] ?? [] );
        $exclude_text     = implode( "\n", $exclude_domains );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Open external links in new tabs.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Add nofollow', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_add_nofollow" value="1"
                               <?php checked( ! empty( $settings['add_nofollow'] ) ); ?>>
                        <?php esc_html_e( 'Add rel="nofollow" to external links.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Exclude Domains', 'wptransformed' ); ?></th>
                <td>
                    <textarea name="wpt_exclude_domains" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( $exclude_text ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One domain per line. Links to these domains will not be modified. Example: example.com', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $domains_raw = $raw['wpt_exclude_domains'] ?? '';
        $lines       = explode( "\n", (string) $domains_raw );
        $domains     = [];

        foreach ( $lines as $line ) {
            $domain = trim( sanitize_text_field( $line ) );
            // Strip protocol if pasted.
            $domain = preg_replace( '#^https?://#i', '', $domain );
            // Strip trailing path.
            $domain = explode( '/', $domain )[0];
            if ( ! empty( $domain ) ) {
                $domains[] = strtolower( $domain );
            }
        }

        return [
            'enabled'         => ! empty( $raw['wpt_enabled'] ),
            'add_nofollow'    => ! empty( $raw['wpt_add_nofollow'] ),
            'exclude_domains' => array_unique( $domains ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
