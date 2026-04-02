<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Maintenance Mode — Show a maintenance page to visitors while admins work on the site.
 *
 * Features:
 *  - Customizable maintenance page (headline, message, colors)
 *  - Optional countdown timer
 *  - Role-based and IP-based bypass
 *  - 503 status with Retry-After header for SEO
 *  - Admin bar indicator when active
 *  - REST API blocking for non-authenticated users
 *  - Self-contained HTML (no theme dependency)
 *
 * @package WPTransformed
 */
class Maintenance_Mode extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'maintenance-mode';
    }

    public function get_title(): string {
        return __( 'Maintenance Mode', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Show a maintenance page to visitors while you work on the site.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'          => false,
            'headline'         => 'Under Maintenance',
            'message'          => 'We are currently performing scheduled maintenance. We will be back shortly.',
            'show_countdown'   => false,
            'countdown_end'    => '',
            'bg_color'         => '#1a1a2e',
            'text_color'       => '#ffffff',
            'accent_color'     => '#e94560',
            'custom_css'       => '',
            'allowed_roles'    => [ 'administrator' ],
            'allowed_ips'      => [],
            'allow_login_page' => true,
            'response_code'    => 503,
            'retry_after'      => 3600,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Intercept frontend requests.
        add_action( 'template_redirect', [ $this, 'maybe_show_maintenance' ], 0 );

        // Block REST API for non-authenticated users.
        add_filter( 'rest_authentication_errors', [ $this, 'block_rest_api' ] );

        // Admin bar indicator.
        add_action( 'admin_bar_menu', [ $this, 'admin_bar_indicator' ], 100 );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Show maintenance page if the current visitor is not allowed.
     */
    public function maybe_show_maintenance(): void {
        if ( $this->is_allowed_visitor() ) {
            return;
        }

        if ( $this->is_bypass_url() ) {
            return;
        }

        $settings = $this->get_settings();
        $code     = (int) $settings['response_code'] === 200 ? 200 : 503;
        $retry    = (int) $settings['retry_after'];

        // Send headers.
        status_header( $code );
        if ( $code === 503 ) {
            header( 'Retry-After: ' . $retry );
        }
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: Thu, 01 Jan 1970 00:00:00 GMT' );
        nocache_headers();

        $this->render_maintenance_page( $settings );
        exit;
    }

    /**
     * Block REST API for non-authenticated users during maintenance.
     *
     * @param \WP_Error|null|true $result Current authentication result.
     * @return \WP_Error|null|true
     */
    public function block_rest_api( $result ) {
        if ( $this->is_allowed_visitor() ) {
            return $result;
        }

        return new \WP_Error(
            'maintenance_mode',
            __( 'Site is under maintenance. Please try again later.', 'wptransformed' ),
            [ 'status' => 503 ]
        );
    }

    /**
     * Add maintenance mode indicator to admin bar.
     *
     * @param \WP_Admin_Bar $admin_bar Admin bar instance.
     */
    public function admin_bar_indicator( $admin_bar ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $admin_bar->add_node( [
            'id'    => 'wpt-maintenance-mode',
            'title' => '<span style="background:#e94560;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">' .
                       esc_html__( 'Maintenance Mode Active', 'wptransformed' ) .
                       '</span>',
            'href'  => admin_url( 'options-general.php?page=wptransformed' ),
        ] );
    }

    // ── Access Control ────────────────────────────────────────

    /**
     * Check if the current visitor is allowed to bypass maintenance mode.
     *
     * @return bool
     */
    private function is_allowed_visitor(): bool {
        $settings = $this->get_settings();

        // Check user roles.
        if ( is_user_logged_in() ) {
            $allowed_roles = (array) $settings['allowed_roles'];
            $user          = wp_get_current_user();

            foreach ( $allowed_roles as $role ) {
                if ( in_array( $role, $user->roles, true ) ) {
                    return true;
                }
            }
        }

        // Check allowed IPs.
        $allowed_ips = (array) $settings['allowed_ips'];
        if ( ! empty( $allowed_ips ) ) {
            $visitor_ip = $this->get_visitor_ip();
            if ( in_array( $visitor_ip, $allowed_ips, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current URL should bypass maintenance mode.
     *
     * @return bool
     */
    private function is_bypass_url(): bool {
        $settings = $this->get_settings();

        // Always allow wp-cron.php.
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }

        // Always allow admin-ajax.php.
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return true;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        // Allow login page if setting enabled.
        if ( ! empty( $settings['allow_login_page'] ) ) {
            if ( strpos( $request_uri, 'wp-login.php' ) !== false ) {
                return true;
            }
            if ( strpos( $request_uri, '/wp-admin' ) !== false ) {
                return true;
            }
        }

        return false;
    }

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
                // HTTP_X_FORWARDED_FOR can contain multiple IPs — take the first.
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

    // ── Maintenance Page ──────────────────────────────────────

    /**
     * Render the self-contained maintenance page.
     *
     * @param array<string,mixed> $settings Module settings.
     */
    private function render_maintenance_page( array $settings ): void {
        $headline       = esc_html( $settings['headline'] );
        $message        = esc_html( $settings['message'] );
        $bg_color       = esc_attr( $settings['bg_color'] );
        $text_color     = esc_attr( $settings['text_color'] );
        $accent_color   = esc_attr( $settings['accent_color'] );
        $custom_css     = wp_strip_all_tags( $settings['custom_css'] );
        $show_countdown = ! empty( $settings['show_countdown'] ) && ! empty( $settings['countdown_end'] );
        $countdown_end  = $show_countdown ? esc_attr( $settings['countdown_end'] ) : '';
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
    <meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $headline; ?> - <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: <?php echo $bg_color; ?>;
            color: <?php echo $text_color; ?>;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            text-align: center;
            padding: 20px;
        }
        .wpt-maintenance-container { max-width: 600px; width: 100%; }
        .wpt-maintenance-icon { font-size: 64px; margin-bottom: 24px; }
        h1 { font-size: 2.5em; font-weight: 700; margin-bottom: 16px; color: <?php echo $accent_color; ?>; }
        p { font-size: 1.2em; line-height: 1.6; opacity: 0.85; }
        .wpt-countdown { margin-top: 32px; display: flex; justify-content: center; gap: 16px; flex-wrap: wrap; }
        .wpt-countdown-unit { background: rgba(255,255,255,0.1); border-radius: 8px; padding: 16px 20px; min-width: 80px; }
        .wpt-countdown-value { font-size: 2em; font-weight: 700; color: <?php echo $accent_color; ?>; }
        .wpt-countdown-label { font-size: 0.75em; text-transform: uppercase; letter-spacing: 1px; opacity: 0.7; margin-top: 4px; }
        <?php if ( $custom_css ) { echo $custom_css; } ?>
    </style>
</head>
<body>
    <div class="wpt-maintenance-container">
        <div class="wpt-maintenance-icon">&#9888;</div>
        <h1><?php echo $headline; ?></h1>
        <p><?php echo $message; ?></p>
        <?php if ( $show_countdown ) : ?>
        <div class="wpt-countdown" id="wpt-countdown">
            <div class="wpt-countdown-unit">
                <div class="wpt-countdown-value" id="wpt-days">--</div>
                <div class="wpt-countdown-label"><?php echo esc_html__( 'Days', 'wptransformed' ); ?></div>
            </div>
            <div class="wpt-countdown-unit">
                <div class="wpt-countdown-value" id="wpt-hours">--</div>
                <div class="wpt-countdown-label"><?php echo esc_html__( 'Hours', 'wptransformed' ); ?></div>
            </div>
            <div class="wpt-countdown-unit">
                <div class="wpt-countdown-value" id="wpt-mins">--</div>
                <div class="wpt-countdown-label"><?php echo esc_html__( 'Minutes', 'wptransformed' ); ?></div>
            </div>
            <div class="wpt-countdown-unit">
                <div class="wpt-countdown-value" id="wpt-secs">--</div>
                <div class="wpt-countdown-label"><?php echo esc_html__( 'Seconds', 'wptransformed' ); ?></div>
            </div>
        </div>
        <script>
        (function(){
            var end=new Date('<?php echo $countdown_end; ?>').getTime();
            function u(){
                var n=Date.now(),d=Math.max(0,end-n);
                document.getElementById('wpt-days').textContent=Math.floor(d/86400000);
                document.getElementById('wpt-hours').textContent=Math.floor((d%86400000)/3600000);
                document.getElementById('wpt-mins').textContent=Math.floor((d%3600000)/60000);
                document.getElementById('wpt-secs').textContent=Math.floor((d%60000)/1000);
                if(d>0)setTimeout(u,1000);
            }
            u();
        })();
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings  = $this->get_settings();
        $all_roles = wp_roles()->roles;
        ?>

        <?php if ( ! empty( $settings['enabled'] ) ) : ?>
        <div class="notice notice-warning inline" style="margin-bottom: 16px;">
            <p>
                <strong><?php esc_html_e( 'Maintenance Mode is currently ACTIVE.', 'wptransformed' ); ?></strong>
                <?php esc_html_e( 'Visitors without allowed roles/IPs are seeing the maintenance page.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable Maintenance Mode', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Show maintenance page to visitors', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-headline"><?php esc_html_e( 'Headline', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-headline" name="wpt_headline"
                           value="<?php echo esc_attr( $settings['headline'] ); ?>"
                           class="regular-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-message"><?php esc_html_e( 'Message', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-message" name="wpt_message" rows="3"
                              class="large-text"><?php echo esc_textarea( $settings['message'] ); ?></textarea>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Colors', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <?php esc_html_e( 'Background:', 'wptransformed' ); ?>
                        <input type="color" name="wpt_bg_color"
                               value="<?php echo esc_attr( $settings['bg_color'] ); ?>">
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <?php esc_html_e( 'Text:', 'wptransformed' ); ?>
                        <input type="color" name="wpt_text_color"
                               value="<?php echo esc_attr( $settings['text_color'] ); ?>">
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <?php esc_html_e( 'Accent:', 'wptransformed' ); ?>
                        <input type="color" name="wpt_accent_color"
                               value="<?php echo esc_attr( $settings['accent_color'] ); ?>">
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Countdown Timer', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_countdown" value="1"
                               <?php checked( ! empty( $settings['show_countdown'] ) ); ?>>
                        <?php esc_html_e( 'Show countdown timer', 'wptransformed' ); ?>
                    </label>
                    <br><br>
                    <label for="wpt-countdown-end">
                        <?php esc_html_e( 'Countdown end (date/time):', 'wptransformed' ); ?>
                    </label>
                    <input type="datetime-local" id="wpt-countdown-end" name="wpt_countdown_end"
                           value="<?php echo esc_attr( $settings['countdown_end'] ); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Allowed Roles', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $all_roles as $role_slug => $role_data ) : ?>
                        <label>
                            <input type="checkbox" name="wpt_allowed_roles[]"
                                   value="<?php echo esc_attr( $role_slug ); ?>"
                                   <?php checked( in_array( $role_slug, (array) $settings['allowed_roles'], true ) ); ?>
                                   <?php disabled( $role_slug, 'administrator' ); ?>>
                            <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
                        </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Administrator is always allowed and cannot be unchecked.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-allowed-ips"><?php esc_html_e( 'Allowed IPs', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-allowed-ips" name="wpt_allowed_ips" rows="3"
                              class="regular-text"
                              placeholder="192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea( implode( "\n", (array) $settings['allowed_ips'] ) ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One IP address per line. These IPs can access the site during maintenance.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Allow Login Page', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_allow_login_page" value="1"
                               <?php checked( ! empty( $settings['allow_login_page'] ) ); ?>>
                        <?php esc_html_e( 'Allow access to wp-login.php and wp-admin', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-response-code"><?php esc_html_e( 'Response Code', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-response-code" name="wpt_response_code">
                        <option value="503" <?php selected( (int) $settings['response_code'], 503 ); ?>>
                            503 <?php esc_html_e( 'Service Unavailable (recommended)', 'wptransformed' ); ?>
                        </option>
                        <option value="200" <?php selected( (int) $settings['response_code'], 200 ); ?>>
                            200 OK
                        </option>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-retry-after"><?php esc_html_e( 'Retry-After (seconds)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-retry-after" name="wpt_retry_after"
                           value="<?php echo esc_attr( (string) $settings['retry_after'] ); ?>"
                           min="60" max="86400" step="1" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Tells search engines how long to wait before rechecking. Only applies with 503 response code.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-custom-css"><?php esc_html_e( 'Custom CSS', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-custom-css" name="wpt_custom_css" rows="5"
                              class="large-text code"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Additional CSS for the maintenance page. No script tags allowed.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $clean = [];

        $clean['enabled']        = ! empty( $raw['wpt_enabled'] );
        $clean['headline']       = sanitize_text_field( $raw['wpt_headline'] ?? 'Under Maintenance' );
        $clean['message']        = sanitize_textarea_field( $raw['wpt_message'] ?? '' );
        $clean['show_countdown'] = ! empty( $raw['wpt_show_countdown'] );
        $clean['countdown_end']  = sanitize_text_field( $raw['wpt_countdown_end'] ?? '' );

        // Colors — validate hex format.
        foreach ( [ 'bg_color', 'text_color', 'accent_color' ] as $color_key ) {
            $val = $raw[ 'wpt_' . $color_key ] ?? '';
            if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $val ) ) {
                $clean[ $color_key ] = $val;
            } else {
                $defaults = $this->get_default_settings();
                $clean[ $color_key ] = $defaults[ $color_key ];
            }
        }

        // Custom CSS — strip all HTML tags.
        $clean['custom_css'] = wp_strip_all_tags( $raw['wpt_custom_css'] ?? '' );

        // Allowed roles — validate against existing roles, always include administrator.
        $valid_roles   = array_keys( wp_roles()->roles );
        $allowed_roles = isset( $raw['wpt_allowed_roles'] ) && is_array( $raw['wpt_allowed_roles'] )
            ? $raw['wpt_allowed_roles']
            : [];
        $allowed_roles = array_intersect( $allowed_roles, $valid_roles );
        if ( ! in_array( 'administrator', $allowed_roles, true ) ) {
            $allowed_roles[] = 'administrator';
        }
        $clean['allowed_roles'] = array_values( $allowed_roles );

        // Allowed IPs — validate each.
        $ip_text = $raw['wpt_allowed_ips'] ?? '';
        $ips     = array_filter( array_map( 'trim', explode( "\n", $ip_text ) ) );
        $clean['allowed_ips'] = array_values( array_filter( $ips, static function ( string $ip ): bool {
            return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
        } ) );

        $clean['allow_login_page'] = ! empty( $raw['wpt_allow_login_page'] );

        // Response code — whitelist.
        $code = (int) ( $raw['wpt_response_code'] ?? 503 );
        $clean['response_code'] = in_array( $code, [ 200, 503 ], true ) ? $code : 503;

        // Retry-After — clamp.
        $retry = (int) ( $raw['wpt_retry_after'] ?? 3600 );
        $clean['retry_after'] = max( 60, min( 86400, $retry ) );

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom admin CSS or JS.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
