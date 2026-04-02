<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Feeds — Remove all RSS/Atom/RDF feed endpoints from the site.
 *
 * Hooks into every do_feed_* action to redirect visitors or return a 404.
 * Also removes feed discovery links from wp_head output.
 *
 * @package WPTransformed
 */
class Disable_Feeds extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-feeds';
    }

    public function get_title(): string {
        return __( 'Disable Feeds', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Remove all RSS, Atom, and RDF feed endpoints from your site.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'disable_all'  => true,
            'redirect_to'  => 'home',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['disable_all'] ) ) {
            return;
        }

        $feed_actions = [
            'do_feed',
            'do_feed_rss',
            'do_feed_rss2',
            'do_feed_atom',
            'do_feed_rdf',
        ];

        foreach ( $feed_actions as $action ) {
            add_action( $action, [ $this, 'handle_feed_request' ], 1 );
        }

        remove_action( 'wp_head', 'feed_links', 2 );
        remove_action( 'wp_head', 'feed_links_extra', 3 );
    }

    // ── Callbacks ─────────────────────────────────────────────

    /**
     * Handle a feed request by redirecting to home or returning a 404.
     */
    public function handle_feed_request(): void {
        $settings = $this->get_settings();

        if ( $settings['redirect_to'] === '404' ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();

            // Try to load the theme's 404 template.
            $template = get_404_template();
            if ( $template ) {
                include $template;
            }
            exit;
        }

        wp_safe_redirect( home_url( '/' ), 301 );
        exit;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable All Feeds', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_disable_all" value="1"
                               <?php checked( ! empty( $settings['disable_all'] ) ); ?>>
                        <?php esc_html_e( 'Disable RSS, Atom, and RDF feeds', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Removes all feed endpoints and discovery links from your site.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Redirect Feed Visitors To', 'wptransformed' ); ?></th>
                <td>
                    <select name="wpt_redirect_to">
                        <option value="home" <?php selected( $settings['redirect_to'], 'home' ); ?>>
                            <?php esc_html_e( 'Homepage', 'wptransformed' ); ?>
                        </option>
                        <option value="404" <?php selected( $settings['redirect_to'], '404' ); ?>>
                            <?php esc_html_e( '404 Page', 'wptransformed' ); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'What happens when someone visits a feed URL.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $redirect_to = ( $raw['wpt_redirect_to'] ?? 'home' );
        $valid_redirects = [ 'home', '404' ];

        return [
            'disable_all' => ! empty( $raw['wpt_disable_all'] ),
            'redirect_to' => in_array( $redirect_to, $valid_redirects, true ) ? $redirect_to : 'home',
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
