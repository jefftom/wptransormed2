<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Author Archives — Remove author archive pages to prevent
 * username enumeration and reduce thin content.
 *
 * Hooks into template_redirect to intercept author page requests and
 * either redirect to home or return a 404. Also empties author
 * rewrite rules to remove the URL endpoints entirely.
 *
 * @package WPTransformed
 */
class Disable_Author_Archives extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-author-archives';
    }

    public function get_title(): string {
        return __( 'Disable Author Archives', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Disable author archive pages to prevent username enumeration and reduce thin content.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'     => true,
            'redirect_to' => 'home',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'template_redirect', [ $this, 'handle_author_request' ] );
        add_filter( 'author_rewrite_rules', [ $this, 'disable_author_rewrites' ] );
    }

    // ── Callbacks ─────────────────────────────────────────────

    /**
     * Redirect or 404 author archive requests.
     */
    public function handle_author_request(): void {
        if ( ! is_author() ) {
            return;
        }

        $settings = $this->get_settings();

        if ( $settings['redirect_to'] === '404' ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();

            $template = get_404_template();
            if ( $template ) {
                include $template;
            }
            exit;
        }

        wp_safe_redirect( home_url( '/' ), 301 );
        exit;
    }

    /**
     * Return empty array to remove author rewrite rules.
     *
     * @param array<string,string> $rules Author rewrite rules.
     * @return array<string,string>
     */
    public function disable_author_rewrites( array $rules ): array {
        return [];
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Author Archives', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Disable all author archive pages', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Prevents access to /author/username/ pages, which can expose usernames to attackers.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Redirect Author Pages To', 'wptransformed' ); ?></th>
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
                        <?php esc_html_e( 'What happens when someone visits an author archive URL.', 'wptransformed' ); ?>
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
            'enabled'     => ! empty( $raw['wpt_enabled'] ),
            'redirect_to' => in_array( $redirect_to, $valid_redirects, true ) ? $redirect_to : 'home',
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
