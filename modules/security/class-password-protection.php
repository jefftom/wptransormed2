<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Password Protection — Require a password to access the entire site.
 *
 * Features:
 *  - Site-wide password gate with self-contained HTML form
 *  - Httponly/secure cookie for authenticated access
 *  - Configurable cookie duration
 *  - IP whitelist for bypassing the password
 *  - Page exclusion list
 *  - Excludes: login page, admin, REST API, cron
 *  - Custom message on the password form
 *
 * @package WPTransformed
 */
class Password_Protection extends Module_Base {

    /**
     * Cookie name for site access.
     */
    private const COOKIE_NAME = 'wpt_site_access';

    /**
     * Nonce action for the password form.
     */
    private const NONCE_ACTION = 'wpt_password_protection_verify';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'password-protection';
    }

    public function get_title(): string {
        return __( 'Password Protection', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Require a password to access the entire site, with IP whitelist and page exclusions.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'         => false,
            'password'        => '',
            'message'         => 'This site is password protected.',
            'allowed_ips'     => [],
            'exclude_pages'   => [],
            'cookie_duration' => 24,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) || $settings['password'] === '' ) {
            return;
        }

        // Handle form submission before any output.
        add_action( 'template_redirect', [ $this, 'handle_form_submission' ], 0 );

        // Check access right after form handling.
        add_action( 'template_redirect', [ $this, 'check_access' ], 1 );

        // Block REST API for unauthenticated visitors.
        add_filter( 'rest_authentication_errors', [ $this, 'block_rest_api' ] );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Handle password form submission.
     */
    public function handle_form_submission(): void {
        if ( empty( $_POST['wpt_site_password'] ) ) {
            return;
        }

        // Verify nonce.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
            self::NONCE_ACTION
        ) ) {
            return;
        }

        $settings       = $this->get_settings();
        $submitted      = sanitize_text_field( wp_unslash( $_POST['wpt_site_password'] ) );
        $stored_hash    = $settings['password'];

        // Verify against the stored hash.
        if ( ! wp_check_password( $submitted, $stored_hash ) ) {
            // Redirect back with error flag.
            $redirect = add_query_arg( 'wpt_pp_error', '1', wp_get_referer() ?: home_url( '/' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        // Set access cookie.
        $duration = max( 1, (int) $settings['cookie_duration'] );
        $expiry   = time() + ( $duration * HOUR_IN_SECS );
        $token    = $this->generate_access_token( $stored_hash );

        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => $expiry,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly'  => true,
                'samesite' => 'Lax',
            ]
        );

        // Redirect to the originally requested page or home.
        $redirect = ! empty( $_POST['wpt_redirect'] )
            ? esc_url_raw( wp_unslash( $_POST['wpt_redirect'] ) )
            : home_url( '/' );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Check if the visitor has access. Show password form if not.
     */
    public function check_access(): void {
        // Skip if visitor is allowed.
        if ( $this->visitor_has_access() ) {
            return;
        }

        // Skip excluded paths.
        if ( $this->is_excluded_request() ) {
            return;
        }

        // Show password form.
        status_header( 401 );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        nocache_headers();

        $this->render_password_form();
        exit;
    }

    /**
     * Block REST API for visitors without access.
     *
     * @param \WP_Error|null|true $result Current authentication result.
     * @return \WP_Error|null|true
     */
    public function block_rest_api( $result ) {
        if ( $this->visitor_has_access() ) {
            return $result;
        }

        return new \WP_Error(
            'password_protected',
            __( 'This site is password protected.', 'wptransformed' ),
            [ 'status' => 401 ]
        );
    }

    // ── Access Control ────────────────────────────────────────

    /**
     * Check if the current visitor has valid access.
     *
     * @return bool
     */
    private function visitor_has_access(): bool {
        // Logged-in WordPress users always have access.
        if ( is_user_logged_in() ) {
            return true;
        }

        // Check IP whitelist.
        $settings    = $this->get_settings();
        $allowed_ips = (array) $settings['allowed_ips'];

        if ( ! empty( $allowed_ips ) ) {
            $visitor_ip = $this->get_visitor_ip();
            if ( $visitor_ip !== '' && in_array( $visitor_ip, $allowed_ips, true ) ) {
                return true;
            }
        }

        // Check access cookie.
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $token       = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
            $stored_hash = $settings['password'];

            if ( $this->verify_access_token( $token, $stored_hash ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current request should be excluded from password protection.
     *
     * @return bool
     */
    private function is_excluded_request(): bool {
        // Always exclude cron.
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }

        // Always exclude AJAX.
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return true;
        }

        // Always exclude admin.
        if ( is_admin() ) {
            return true;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
            : '';
        $path = wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '';

        // Always exclude login page.
        if ( basename( $path ) === 'wp-login.php' ) {
            return true;
        }

        // Always exclude xmlrpc.php.
        if ( basename( $path ) === 'xmlrpc.php' ) {
            return true;
        }

        // Check excluded pages.
        $settings       = $this->get_settings();
        $exclude_pages  = (array) $settings['exclude_pages'];

        if ( ! empty( $exclude_pages ) ) {
            // Get current page/post ID.
            $current_id = get_queried_object_id();
            if ( $current_id > 0 && in_array( $current_id, array_map( 'intval', $exclude_pages ), true ) ) {
                return true;
            }
        }

        return false;
    }

    // ── Token Management ──────────────────────────────────────

    /**
     * Generate an access token tied to the stored password hash.
     *
     * @param string $password_hash The stored password hash.
     * @return string HMAC token.
     */
    private function generate_access_token( string $password_hash ): string {
        $key = wp_salt( 'auth' ) . $password_hash;

        return hash_hmac( 'sha256', 'wpt_site_access', $key );
    }

    /**
     * Verify an access token against the stored password hash.
     *
     * @param string $token         The cookie token.
     * @param string $password_hash The stored password hash.
     * @return bool
     */
    private function verify_access_token( string $token, string $password_hash ): bool {
        $expected = $this->generate_access_token( $password_hash );

        return hash_equals( $expected, $token );
    }

    // ── IP Detection ──────────────────────────────────────────

    /**
     * Get the visitor's IP address, accounting for proxies.
     *
     * @return string
     */
    private function get_visitor_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                // X-Forwarded-For can contain multiple IPs — take the first.
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '';
    }

    // ── Password Form ─────────────────────────────────────────

    /**
     * Render the self-contained password form page.
     */
    private function render_password_form(): void {
        $settings  = $this->get_settings();
        $has_error = ! empty( $_GET['wpt_pp_error'] );
        $redirect  = isset( $_SERVER['REQUEST_URI'] )
            ? esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) )
            : '/';
        $site_name = get_bloginfo( 'name' );
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
    <meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html( $site_name ); ?> — <?php echo esc_html__( 'Password Required', 'wptransformed' ); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f0f0f1;
            color: #1d2327;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .wpt-pp-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.13);
            max-width: 420px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
        }
        .wpt-pp-icon { font-size: 48px; margin-bottom: 16px; }
        .wpt-pp-title {
            font-size: 1.3em;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .wpt-pp-message {
            color: #50575e;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .wpt-pp-form { text-align: left; }
        .wpt-pp-label {
            display: block;
            font-size: 0.875em;
            font-weight: 500;
            margin-bottom: 6px;
            color: #1d2327;
        }
        .wpt-pp-input {
            width: 100%;
            padding: 10px 12px;
            font-size: 1em;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            margin-bottom: 16px;
            outline: none;
        }
        .wpt-pp-input:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }
        .wpt-pp-button {
            display: block;
            width: 100%;
            padding: 10px;
            font-size: 1em;
            font-weight: 600;
            color: #fff;
            background: #2271b1;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .wpt-pp-button:hover { background: #135e96; }
        .wpt-pp-error {
            background: #fcf0f1;
            color: #cc1818;
            border: 1px solid #cc1818;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 16px;
            font-size: 0.875em;
        }
    </style>
</head>
<body>
    <div class="wpt-pp-container">
        <div class="wpt-pp-icon">&#128274;</div>
        <div class="wpt-pp-title"><?php echo esc_html( $site_name ); ?></div>
        <div class="wpt-pp-message"><?php echo esc_html( $settings['message'] ); ?></div>
        <?php if ( $has_error ) : ?>
        <div class="wpt-pp-error">
            <?php echo esc_html__( 'Incorrect password. Please try again.', 'wptransformed' ); ?>
        </div>
        <?php endif; ?>
        <form class="wpt-pp-form" method="post" action="">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field escapes internally.
            wp_nonce_field( self::NONCE_ACTION );
            ?>
            <input type="hidden" name="wpt_redirect" value="<?php echo esc_attr( $redirect ); ?>">
            <label class="wpt-pp-label" for="wpt-site-password">
                <?php echo esc_html__( 'Password', 'wptransformed' ); ?>
            </label>
            <input
                type="password"
                id="wpt-site-password"
                name="wpt_site_password"
                class="wpt-pp-input"
                autocomplete="off"
                required
                autofocus
            >
            <button type="submit" class="wpt-pp-button">
                <?php echo esc_html__( 'Enter Site', 'wptransformed' ); ?>
            </button>
        </form>
    </div>
</body>
</html>
        <?php
    }

    // ── Admin UI ──────────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>

        <?php if ( ! empty( $settings['enabled'] ) && $settings['password'] !== '' ) : ?>
        <div class="notice notice-warning inline" style="margin-bottom: 16px;">
            <p>
                <strong><?php echo esc_html__( 'Password Protection is currently ACTIVE.', 'wptransformed' ); ?></strong>
                <?php echo esc_html__( 'Non-logged-in visitors must enter the password to access the site.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php echo esc_html__( 'Require password to access the site', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-password"><?php echo esc_html__( 'Site Password', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="password" id="wpt-password" name="wpt_password"
                           value="" class="regular-text" autocomplete="new-password">
                    <p class="description">
                        <?php if ( $settings['password'] !== '' ) : ?>
                            <?php echo esc_html__( 'A password is set. Leave blank to keep the current password.', 'wptransformed' ); ?>
                        <?php else : ?>
                            <?php echo esc_html__( 'Enter a password that visitors must provide to access the site.', 'wptransformed' ); ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-message"><?php echo esc_html__( 'Message', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-message" name="wpt_message" rows="3"
                              class="large-text"><?php echo esc_textarea( $settings['message'] ); ?></textarea>
                    <p class="description">
                        <?php echo esc_html__( 'Message displayed on the password form.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-cookie-duration"><?php echo esc_html__( 'Cookie Duration', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-cookie-duration" name="wpt_cookie_duration"
                           value="<?php echo esc_attr( (string) $settings['cookie_duration'] ); ?>"
                           min="1" max="8760" class="small-text">
                    <?php echo esc_html__( 'hours', 'wptransformed' ); ?>
                    <p class="description">
                        <?php echo esc_html__( 'How long the access cookie lasts before the visitor must re-enter the password.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-allowed-ips"><?php echo esc_html__( 'Allowed IPs', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-allowed-ips" name="wpt_allowed_ips" rows="3"
                              class="large-text" placeholder="192.168.1.1&#10;10.0.0.0"><?php
                        echo esc_textarea( implode( "\n", (array) $settings['allowed_ips'] ) );
                    ?></textarea>
                    <p class="description">
                        <?php echo esc_html__( 'One IP address per line. These IPs can access the site without a password.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-exclude-pages"><?php echo esc_html__( 'Excluded Page IDs', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-exclude-pages" name="wpt_exclude_pages"
                           value="<?php echo esc_attr( implode( ', ', array_map( 'intval', (array) $settings['exclude_pages'] ) ) ); ?>"
                           class="regular-text" placeholder="42, 108, 256">
                    <p class="description">
                        <?php echo esc_html__( 'Comma-separated page/post IDs to exclude from password protection.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $current  = $this->get_settings();

        // Handle password: hash if new, keep existing if blank.
        $password_hash = $current['password'];
        $new_password  = isset( $raw['wpt_password'] ) ? sanitize_text_field( $raw['wpt_password'] ) : '';
        if ( $new_password !== '' ) {
            $password_hash = wp_hash_password( $new_password );
        }

        // Parse allowed IPs.
        $allowed_ips_raw = isset( $raw['wpt_allowed_ips'] ) ? sanitize_textarea_field( $raw['wpt_allowed_ips'] ) : '';
        $allowed_ips     = [];
        if ( $allowed_ips_raw !== '' ) {
            $lines = preg_split( '/[\r\n]+/', $allowed_ips_raw );
            if ( $lines !== false ) {
                foreach ( $lines as $line ) {
                    $ip = trim( $line );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                        $allowed_ips[] = $ip;
                    }
                }
            }
        }

        // Parse excluded page IDs.
        $exclude_raw   = isset( $raw['wpt_exclude_pages'] ) ? sanitize_text_field( $raw['wpt_exclude_pages'] ) : '';
        $exclude_pages = [];
        if ( $exclude_raw !== '' ) {
            $parts = explode( ',', $exclude_raw );
            foreach ( $parts as $part ) {
                $id = absint( trim( $part ) );
                if ( $id > 0 ) {
                    $exclude_pages[] = $id;
                }
            }
        }

        $cookie_duration = isset( $raw['wpt_cookie_duration'] ) ? (int) $raw['wpt_cookie_duration'] : 24;
        $cookie_duration = max( 1, min( 8760, $cookie_duration ) );

        return [
            'enabled'         => ! empty( $raw['wpt_enabled'] ),
            'password'        => $password_hash,
            'message'         => isset( $raw['wpt_message'] ) ? sanitize_textarea_field( $raw['wpt_message'] ) : $current['message'],
            'allowed_ips'     => $allowed_ips,
            'exclude_pages'   => $exclude_pages,
            'cookie_duration' => $cookie_duration,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        // Cookie-based only; no persistent WP data to clean up.
        return [];
    }
}
