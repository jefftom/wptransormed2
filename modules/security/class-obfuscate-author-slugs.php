<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Obfuscate Author Slugs — Prevent username enumeration via author archives.
 *
 * Features:
 *  - Replace author slug in URLs with a hash or numeric ID
 *  - 404 or redirect requests to /author/real-username/
 *  - Add rewrite rules for obfuscated slugs
 *  - Flush rewrite rules on settings save via option flag
 *  - Does not break REST API user endpoints
 *
 * @package WPTransformed
 */
class Obfuscate_Author_Slugs extends Module_Base {

    /**
     * Option flag key for pending rewrite flush.
     */
    private const FLUSH_FLAG = 'wpt_obfuscate_author_slugs_flush';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'obfuscate-author-slugs';
    }

    public function get_title(): string {
        return __( 'Obfuscate Author Slugs', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Hide real usernames from author archive URLs to prevent user enumeration attacks.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
            'method'  => 'hash', // 'hash' or 'numeric_id'
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 99 );
        add_filter( 'author_link', [ $this, 'filter_author_link' ], 10, 3 );
        add_action( 'template_redirect', [ $this, 'protect_real_author_slugs' ] );
        add_filter( 'request', [ $this, 'resolve_obfuscated_author' ] );
    }

    // ── Author Link Filter ────────────────────────────────────

    /**
     * Replace the author slug in author links with the obfuscated version.
     *
     * @param string $link       The author link URL.
     * @param int    $author_id  The author user ID.
     * @param string $author_nicename The author nicename (slug).
     * @return string
     */
    public function filter_author_link( string $link, int $author_id, string $author_nicename ): string {
        $obfuscated = $this->get_obfuscated_slug( $author_id, $author_nicename );
        return str_replace( '/author/' . $author_nicename . '/', '/author/' . $obfuscated . '/', $link );
    }

    // ── Template Redirect Protection ──────────────────────────

    /**
     * Redirect requests using real author nicename to the obfuscated URL.
     */
    public function protect_real_author_slugs(): void {
        if ( ! is_author() ) {
            return;
        }

        $author = get_queried_object();
        if ( ! $author instanceof \WP_User ) {
            return;
        }

        $requested_slug = get_query_var( 'author_name' );
        if ( empty( $requested_slug ) ) {
            return;
        }

        if ( $requested_slug === $author->user_nicename ) {
            $obfuscated   = $this->get_obfuscated_slug( $author->ID, $author->user_nicename );
            $redirect_url = get_author_posts_url( $author->ID, $obfuscated );
            wp_safe_redirect( $redirect_url, 301 );
            exit;
        }
    }

    // ── Request Filter ────────────────────────────────────────

    /**
     * Resolve obfuscated author slug back to the real user for WP_Query.
     *
     * @param array<string, mixed> $query_vars WordPress query vars.
     * @return array<string, mixed>
     */
    public function resolve_obfuscated_author( array $query_vars ): array {
        if ( empty( $query_vars['author_name'] ) ) {
            return $query_vars;
        }

        $requested = $query_vars['author_name'];
        $method    = $this->get_method();
        $user      = null;

        if ( 'numeric_id' === $method ) {
            if ( ctype_digit( $requested ) ) {
                $user = get_user_by( 'id', (int) $requested );
            }
        } else {
            $user = $this->find_user_by_hash( $requested );
        }

        if ( $user instanceof \WP_User ) {
            $query_vars['author_name'] = $user->user_nicename;
        }

        return $query_vars;
    }

    // ── Rewrite Flush ─────────────────────────────────────────

    /**
     * Flush rewrite rules if the option flag is set (set during settings save).
     */
    public function maybe_flush_rewrite_rules(): void {
        if ( get_option( self::FLUSH_FLAG ) ) {
            delete_option( self::FLUSH_FLAG );
            flush_rewrite_rules( false );
        }
    }

    // ── Obfuscation Helpers ───────────────────────────────────

    /**
     * Get the obfuscated slug for a user.
     *
     * @param int    $user_id        The user ID.
     * @param string $user_nicename  The real user nicename.
     * @return string Obfuscated slug.
     */
    private function get_obfuscated_slug( int $user_id, string $user_nicename ): string {
        $method = $this->get_method();

        if ( 'numeric_id' === $method ) {
            return (string) $user_id;
        }

        return $this->generate_hash( $user_id, $user_nicename );
    }

    /**
     * Get the configured obfuscation method, cached for the request.
     */
    private function get_method(): string {
        static $method = null;
        if ( null === $method ) {
            $settings = $this->get_settings();
            $method   = $settings['method'] ?? 'hash';
        }
        return $method;
    }

    /**
     * Generate a deterministic short hash from user data.
     *
     * Uses AUTH_SALT as a secret to prevent reverse-engineering.
     *
     * @param int    $user_id       User ID.
     * @param string $user_nicename User nicename.
     * @return string 12-character hex hash.
     */
    private function generate_hash( int $user_id, string $user_nicename ): string {
        $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'wpt-default-salt';
        $raw  = hash_hmac( 'sha256', $user_id . ':' . $user_nicename, $salt );

        return substr( $raw, 0, 12 );
    }

    /**
     * Find a user by matching the hash of their slug.
     *
     * @param string $hash The obfuscated hash from the URL.
     * @return \WP_User|null
     */
    private function find_user_by_hash( string $hash ): ?\WP_User {
        // Only search if the hash looks valid (12 hex chars).
        if ( ! preg_match( '/^[a-f0-9]{12}$/', $hash ) ) {
            return null;
        }

        // Query authors who have published posts to limit the search.
        $users = get_users( [
            'has_published_posts' => true,
            'fields'             => [ 'ID', 'user_nicename' ],
        ] );

        foreach ( $users as $user ) {
            $expected = $this->generate_hash( (int) $user->ID, $user->user_nicename );
            if ( hash_equals( $expected, $hash ) ) {
                return get_user_by( 'id', $user->ID );
            }
        }

        return null;
    }

    // ── Admin UI ──────────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable Obfuscation', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Replace real author slugs with obfuscated versions', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Prevents attackers from discovering usernames via /author/username/ URLs.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-method"><?php esc_html_e( 'Obfuscation Method', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-method" name="wpt_method">
                        <option value="hash" <?php selected( $settings['method'], 'hash' ); ?>>
                            <?php esc_html_e( 'Hash (e.g., /author/a1b2c3d4e5f6/)', 'wptransformed' ); ?>
                        </option>
                        <option value="numeric_id" <?php selected( $settings['method'], 'numeric_id' ); ?>>
                            <?php esc_html_e( 'Numeric ID (e.g., /author/42/)', 'wptransformed' ); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Hash is more secure as it hides user IDs. Numeric ID is simpler but reveals user IDs.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $enabled = ! empty( $raw['wpt_enabled'] );
        $method  = isset( $raw['wpt_method'] ) && in_array( $raw['wpt_method'], [ 'hash', 'numeric_id' ], true )
            ? $raw['wpt_method']
            : 'hash';

        update_option( self::FLUSH_FLAG, true, false );

        return [
            'enabled' => $enabled,
            'method'  => $method,
        ];
    }

    // ── Cleanup ──────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'options' => [ self::FLUSH_FLAG ],
        ];
    }
}
