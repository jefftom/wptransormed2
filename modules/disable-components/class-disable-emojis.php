<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Emojis — Remove WordPress emoji scripts, styles, and filters.
 *
 * Strips all emoji-related assets and processing from both the front end
 * and admin, reducing page weight and DNS prefetch overhead.
 *
 * @package WPTransformed
 */
class Disable_Emojis extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-emojis';
    }

    public function get_title(): string {
        return __( 'Disable Emojis', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Remove WordPress emoji scripts, styles, and filters to reduce page weight.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Remove emoji detection script.
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );

        // Remove emoji styles.
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );

        // Remove emoji from feeds.
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );

        // Remove emoji from emails.
        remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

        // Remove emoji from TinyMCE.
        add_filter( 'tiny_mce_plugins', [ $this, 'remove_tinymce_emoji' ] );

        // Remove emoji DNS prefetch.
        add_filter( 'wp_resource_hints', [ $this, 'remove_emoji_dns_prefetch' ], 10, 2 );
    }

    /**
     * Remove the wpemoji TinyMCE plugin.
     *
     * @param array $plugins TinyMCE plugins.
     * @return array
     */
    public function remove_tinymce_emoji( $plugins ): array {
        if ( ! is_array( $plugins ) ) {
            return [];
        }
        return array_diff( $plugins, [ 'wpemoji' ] );
    }

    /**
     * Remove emoji CDN from DNS prefetch hints.
     *
     * @param array  $urls          List of URLs.
     * @param string $relation_type The relation type (dns-prefetch, etc.).
     * @return array
     */
    public function remove_emoji_dns_prefetch( $urls, $relation_type ): array {
        if ( ! is_array( $urls ) ) {
            return [];
        }

        if ( 'dns-prefetch' !== $relation_type ) {
            return $urls;
        }

        $emoji_url = 'https://s.w.org/images/core/emoji/';

        return array_filter( $urls, static function ( $url ) use ( $emoji_url ) {
            $url_string = is_array( $url ) ? ( $url['href'] ?? '' ) : (string) $url;
            return strpos( $url_string, $emoji_url ) === false;
        } );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Emojis', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_enabled"
                               value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Remove all WordPress emoji scripts, styles, and filters', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
