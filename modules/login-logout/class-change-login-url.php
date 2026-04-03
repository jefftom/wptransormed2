<?php
declare(strict_types=1);

namespace WPTransformed\Modules\LoginLogout;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Change Login URL — Hide wp-login.php behind a custom slug.
 *
 * Rewrites the login page URL to a user-defined slug (e.g. /my-login)
 * and blocks direct access to /wp-login.php with a 404 or redirect.
 * Preserves postpass and logout actions so password-protected posts
 * and logout flows continue to work.
 *
 * @package WPTransformed
 */
class Change_Login_Url extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'change-login-url';
    }

    public function get_title(): string {
        return __( 'Change Login URL', 'wptransformed' );
    }

    public function get_category(): string {
        return 'login-logout';
    }

    public function get_description(): string {
        return __( 'Hide wp-login.php behind a custom URL slug to reduce brute-force attacks.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'custom_slug'  => 'my-login',
            'redirect_to'  => '404',       // '404', 'home', or a custom URL.
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Store slug in a standalone option for early access (before module settings load).
        $settings = $this->get_settings();
        $slug     = $this->sanitize_slug( $settings['custom_slug'] );

        if ( empty( $slug ) ) {
            return; // No valid slug configured — do nothing.
        }

        // Sync standalone option so rewrite rules and early checks can use it.
        $stored = get_option( 'wpt_login_slug', '' );
        if ( $stored !== $slug ) {
            update_option( 'wpt_login_slug', $slug, true );
        }

        // ── Request interception (runs on every page load) ───
        add_action( 'init', [ $this, 'handle_custom_login_request' ], 1 );
        add_action( 'init', [ $this, 'block_wp_login' ], 1 );

        // ── URL rewriting ────────────────────────────────────
        add_filter( 'login_url', [ $this, 'filter_login_url' ], 10, 3 );
        add_filter( 'site_url', [ $this, 'filter_site_url' ], 10, 4 );
        add_filter( 'wp_redirect', [ $this, 'filter_wp_redirect' ], 10, 2 );
        add_filter( 'register_url', [ $this, 'filter_register_url' ] );
        add_filter( 'lostpassword_url', [ $this, 'filter_lostpassword_url' ], 10, 2 );
    }

    // ── Request Handling ──────────────────────────────────────

    /**
     * Serve the login form when the custom slug is requested.
     */
    public function handle_custom_login_request(): void {
        $slug = $this->get_active_slug();
        if ( empty( $slug ) ) {
            return;
        }

        // Parse the request URI path.
        $request_path = $this->get_request_path();

        if ( $request_path === '/' . $slug || $request_path === '/' . $slug . '/' ) {
            // Load wp-login.php directly — this bypasses our block_wp_login check
            // because we set a flag before including it.
            $this->serve_login_page();
        }
    }

    /**
     * Block direct access to wp-login.php (with allowed exceptions).
     */
    public function block_wp_login(): void {
        if ( ! $this->is_wp_login_request() ) {
            return;
        }

        if ( defined( 'WPT_LOGIN_SERVING' ) ) {
            return;
        }

        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if ( $action === 'postpass' || $action === 'logout' ) {
            return;
        }

        // Allow POST submissions — WordPress's own wp-login.php validates nonces internally.
        // The custom login slug page includes wp-login.php, so form POSTs go through the allowed path.
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            return;
        }

        $this->do_redirect_or_404();
    }

    // ── URL Filters ───────────────────────────────────────────

    /**
     * Rewrite the login_url to use the custom slug.
     *
     * @param string $login_url    Default login URL.
     * @param string $redirect     Redirect URL after login.
     * @param bool   $force_reauth Whether to force reauth.
     * @return string
     */
    public function filter_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
        $custom_url = $this->get_custom_login_url();
        if ( empty( $custom_url ) ) {
            return $login_url;
        }

        if ( ! empty( $redirect ) ) {
            $custom_url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $custom_url );
        }

        if ( $force_reauth ) {
            $custom_url = add_query_arg( 'reauth', '1', $custom_url );
        }

        return $custom_url;
    }

    /**
     * Rewrite site_url calls that reference wp-login.php.
     * This catches password-reset emails and other internal references.
     *
     * @param string $url     The complete site URL.
     * @param string $path    Path relative to site URL.
     * @param string $scheme  URL scheme.
     * @param int    $blog_id Blog ID.
     * @return string
     */
    public function filter_site_url( string $url, string $path, string $scheme, int $blog_id ): string {
        return $this->rewrite_login_url( $url );
    }

    /**
     * Rewrite redirects that point to wp-login.php.
     *
     * @param string $location Redirect location.
     * @param int    $status   HTTP status code.
     * @return string
     */
    public function filter_wp_redirect( string $location, int $status ): string {
        return $this->rewrite_login_url( $location );
    }

    /**
     * Rewrite the registration URL.
     *
     * @param string $url Default registration URL.
     * @return string
     */
    public function filter_register_url( string $url ): string {
        return $this->rewrite_login_url( $url );
    }

    /**
     * Rewrite the lost-password URL.
     *
     * @param string $url      Default lost-password URL.
     * @param string $redirect Redirect URL.
     * @return string
     */
    public function filter_lostpassword_url( string $url, string $redirect ): string {
        return $this->rewrite_login_url( $url );
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Rewrite wp-login.php to the custom slug in a URL, preserving excluded actions.
     *
     * @param string $url URL to rewrite.
     * @return string
     */
    private function rewrite_login_url( string $url ): string {
        if ( strpos( $url, 'wp-login.php' ) === false ) {
            return $url;
        }

        if ( strpos( $url, 'action=postpass' ) !== false || strpos( $url, 'action=logout' ) !== false ) {
            return $url;
        }

        $slug = $this->get_active_slug();
        if ( empty( $slug ) ) {
            return $url;
        }

        return str_replace( 'wp-login.php', $slug, $url );
    }

    /**
     * Get the active login slug from the standalone option (fast).
     *
     * @return string
     */
    private function get_active_slug(): string {
        return (string) get_option( 'wpt_login_slug', '' );
    }

    /**
     * Build the full custom login URL.
     *
     * @return string
     */
    private function get_custom_login_url(): string {
        $slug = $this->get_active_slug();
        if ( empty( $slug ) ) {
            return '';
        }

        return home_url( '/' . $slug . '/' );
    }

    /**
     * Get the normalised request path (without query string).
     *
     * @return string
     */
    private function get_request_path(): string {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
        $trimmed     = rtrim( $path, '/' );

        return $trimmed !== '' ? $trimmed : '/';
    }

    /**
     * Check whether the current request targets wp-login.php.
     *
     * @return bool
     */
    private function is_wp_login_request(): bool {
        $path = $this->get_request_path();

        return substr( $path, -strlen( 'wp-login.php' ) ) === 'wp-login.php';
    }

    /**
     * Check if a POST to wp-login.php came from our custom login page.
     *
     * @return bool
     */
    private function has_valid_login_referrer(): bool {
        $referer = wp_get_raw_referer();
        if ( empty( $referer ) ) {
            return false;
        }

        $slug = $this->get_active_slug();
        if ( empty( $slug ) ) {
            return false;
        }

        // Check if referrer contains our custom slug.
        return strpos( $referer, '/' . $slug ) !== false;
    }

    /**
     * Include wp-login.php to serve the login page.
     */
    private function serve_login_page(): void {
        // Signal to block_wp_login() that this is an authorised load.
        if ( ! defined( 'WPT_LOGIN_SERVING' ) ) {
            define( 'WPT_LOGIN_SERVING', true );
        }

        // WordPress expects these globals.
        global $error, $interim_login, $action, $user_login;

        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    /**
     * Send a 404 or redirect, based on settings.
     */
    private function do_redirect_or_404(): void {
        $settings    = $this->get_settings();
        $redirect_to = $settings['redirect_to'];

        if ( $redirect_to === 'home' ) {
            wp_safe_redirect( home_url( '/' ), 302 );
            exit;
        }

        if ( $redirect_to !== '404' && filter_var( $redirect_to, FILTER_VALIDATE_URL ) ) {
            wp_safe_redirect( esc_url_raw( $redirect_to ), 302 );
            exit;
        }

        // Default: serve a 404.
        status_header( 404 );
        nocache_headers();

        // Try the theme's 404 template.
        if ( defined( 'TEMPLATEPATH' ) ) {
            $template = TEMPLATEPATH . '/404.php';
            if ( file_exists( $template ) ) {
                include $template;
                exit;
            }
        }

        // Fallback plain 404.
        wp_die(
            esc_html__( 'Not Found', 'wptransformed' ),
            esc_html__( 'Page Not Found', 'wptransformed' ),
            [ 'response' => 404 ]
        );
    }

    /**
     * Sanitize a slug string: lowercase, alphanumeric + hyphens only.
     *
     * @param string $slug Raw slug.
     * @return string
     */
    private function sanitize_slug( string $slug ): string {
        $slug = sanitize_title( trim( $slug ) );
        $slug = preg_replace( '/[^a-z0-9\-]/', '', $slug );

        // Reject reserved WordPress paths.
        $reserved = [
            'wp-admin', 'wp-content', 'wp-includes', 'wp-json',
            'wp-login', 'wp-register', 'wp-signup', 'wp-cron',
            'xmlrpc', 'feed', 'admin', 'login', 'register',
        ];

        if ( in_array( $slug, $reserved, true ) || empty( $slug ) ) {
            return '';
        }

        return $slug;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings    = $this->get_settings();
        $slug        = $settings['custom_slug'];
        $redirect_to = $settings['redirect_to'];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-custom-slug"><?php esc_html_e( 'Login URL slug', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                    <input type="text" name="wpt_custom_slug" id="wpt-custom-slug"
                           value="<?php echo esc_attr( $slug ); ?>"
                           class="regular-text" style="width: 200px;"
                           pattern="[a-z0-9\-]+" placeholder="my-login">
                    <p class="description">
                        <?php esc_html_e( 'Lowercase letters, numbers, and hyphens only. This becomes your new login page URL.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'When wp-login.php is accessed', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_redirect_to" value="404"
                                   <?php checked( $redirect_to, '404' ); ?>>
                            <?php esc_html_e( 'Show 404 Not Found page', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_redirect_to" value="home"
                                   <?php checked( $redirect_to, 'home' ); ?>>
                            <?php esc_html_e( 'Redirect to homepage', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="radio" name="wpt_redirect_to" value="custom"
                                   <?php checked( ! in_array( $redirect_to, [ '404', 'home' ], true ) ); ?>>
                            <?php esc_html_e( 'Redirect to custom URL:', 'wptransformed' ); ?>
                            <input type="url" name="wpt_redirect_custom_url"
                                   value="<?php echo esc_url( in_array( $redirect_to, [ '404', 'home' ], true ) ? '' : $redirect_to ); ?>"
                                   class="regular-text" style="width: 300px;"
                                   placeholder="https://example.com/go-away">
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <div style="background: #fff; border: 1px solid #ddd; border-left: 4px solid #2271b1; border-radius: 4px; padding: 12px 16px; margin-top: 12px;">
            <p style="margin: 0 0 4px 0;">
                <strong><?php esc_html_e( 'Important notes:', 'wptransformed' ); ?></strong>
            </p>
            <ul style="margin: 4px 0 0 16px; list-style: disc;">
                <li><?php esc_html_e( 'Bookmark your new login URL. If you forget it, deactivate this module via FTP or WP-CLI.', 'wptransformed' ); ?></li>
                <li><?php esc_html_e( 'Password-protected post forms and logout will continue to work normally.', 'wptransformed' ); ?></li>
                <li><?php esc_html_e( 'Password reset emails will use the new login URL automatically.', 'wptransformed' ); ?></li>
            </ul>
        </div>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $slug = $this->sanitize_slug( $raw['wpt_custom_slug'] ?? 'my-login' );

        // If slug is invalid/reserved, fall back to default.
        if ( empty( $slug ) ) {
            $slug = 'my-login';
        }

        // Determine redirect target.
        $redirect_choice = $raw['wpt_redirect_to'] ?? '404';
        if ( $redirect_choice === 'custom' ) {
            $custom_url  = esc_url_raw( $raw['wpt_redirect_custom_url'] ?? '' );
            $redirect_to = ! empty( $custom_url ) ? $custom_url : '404';
        } elseif ( $redirect_choice === 'home' ) {
            $redirect_to = 'home';
        } else {
            $redirect_to = '404';
        }

        // Sync standalone option immediately.
        update_option( 'wpt_login_slug', $slug, true );

        return [
            'custom_slug'  => $slug,
            'redirect_to'  => $redirect_to,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom CSS or JS needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => 'wpt_login_slug' ],
        ];
    }

    // ── Deactivation ──────────────────────────────────────────

    public function deactivate(): void {
        delete_option( 'wpt_login_slug' );
    }
}
