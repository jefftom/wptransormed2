<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Limit Login Attempts — Brute-force protection for WordPress login.
 *
 * Features:
 *  - Configurable max attempts before lockout
 *  - Progressive lockout (short lockout, then extended after repeated lockouts)
 *  - IP whitelist/blacklist support
 *  - Proxy-aware IP detection (CF-Connecting-IP, X-Forwarded-For, REMOTE_ADDR)
 *  - Email notification on lockout
 *  - Daily cron cleanup of old records
 *  - Lockout log table in admin settings
 *
 * @package WPTransformed
 */
class Limit_Login_Attempts extends Module_Base {

    /**
     * Custom table name (without prefix).
     */
    private const TABLE_SUFFIX = 'wpt_login_attempts';

    /**
     * Transient key for table existence check.
     */
    private const TABLE_CHECK_TRANSIENT = 'wpt_login_attempts_table_exists';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'limit-login-attempts';
    }

    public function get_title(): string {
        return __( 'Limit Login Attempts', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Protect your site from brute-force login attacks with configurable lockouts, IP whitelist/blacklist, and email notifications.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'max_attempts'      => 5,
            'lockout_duration'  => 20,
            'max_lockouts'      => 3,
            'extended_lockout'  => 1440,
            'whitelist_ips'     => [],
            'blacklist_ips'     => [],
            'notify_email'      => '',
            'log_retention'     => 30,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Ensure custom table exists.
        $this->maybe_create_table();

        // Check lockout before authentication (priority 99).
        add_filter( 'authenticate', [ $this, 'check_lockout' ], 99, 3 );

        // Record failed login attempts.
        add_action( 'wp_login_failed', [ $this, 'handle_failed_login' ], 10, 1 );

        // Register cron for cleanup.
        if ( ! wp_next_scheduled( 'wpt_cleanup_login_attempts' ) ) {
            wp_schedule_event( time(), 'daily', 'wpt_cleanup_login_attempts' );
        }
        add_action( 'wpt_cleanup_login_attempts', [ $this, 'cleanup_old_records' ] );

        // AJAX handler for clearing the log.
        add_action( 'wp_ajax_wpt_clear_login_log', [ $this, 'ajax_clear_log' ] );

        // AJAX handler for unlocking an IP.
        add_action( 'wp_ajax_wpt_unlock_ip', [ $this, 'ajax_unlock_ip' ] );
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( 'wpt_cleanup_login_attempts' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpt_cleanup_login_attempts' );
        }
    }

    // ── Table Creation ────────────────────────────────────────

    /**
     * Create the login attempts table if it does not exist.
     * Uses a transient flag to avoid checking on every page load.
     */
    private function maybe_create_table(): void {
        if ( get_transient( self::TABLE_CHECK_TRANSIENT ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
            set_transient( self::TABLE_CHECK_TRANSIENT, '1', DAY_IN_SECONDS );
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(60) DEFAULT '',
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            lockout_until DATETIME NULL,
            lockout_count INT DEFAULT 0,
            INDEX idx_ip (ip_address),
            INDEX idx_lockout (lockout_until)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        set_transient( self::TABLE_CHECK_TRANSIENT, '1', DAY_IN_SECONDS );
    }

    // ── IP Detection ──────────────────────────────────────────

    /**
     * Get the client IP address with proxy header support.
     *
     * @return string Client IP address.
     */
    private function get_client_ip(): string {
        // Cloudflare.
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        // X-Forwarded-For (take the first IP in the chain).
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ips       = array_map( 'trim', explode( ',', $forwarded ) );
            $ip        = $ips[0] ?? '';
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        // Standard REMOTE_ADDR.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }

        return '0.0.0.0';
    }

    /**
     * Check if an IP is in a list (supports single IPs and CIDR ranges).
     *
     * @param string   $ip   IP address to check.
     * @param string[] $list List of IPs/CIDRs.
     * @return bool
     */
    private function ip_in_list( string $ip, array $list ): bool {
        foreach ( $list as $entry ) {
            $entry = trim( $entry );
            if ( empty( $entry ) ) {
                continue;
            }

            // Exact match.
            if ( $ip === $entry ) {
                return true;
            }

            // CIDR range match.
            if ( strpos( $entry, '/' ) !== false && $this->ip_in_cidr( $ip, $entry ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     *
     * @param string $ip   IP address.
     * @param string $cidr CIDR notation (e.g. 192.168.1.0/24).
     * @return bool
     */
    private function ip_in_cidr( string $ip, string $cidr ): bool {
        list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '32' );

        $ip_long     = ip2long( $ip );
        $subnet_long = ip2long( $subnet );

        if ( $ip_long === false || $subnet_long === false ) {
            return false;
        }

        $mask = -1 << ( 32 - (int) $bits );

        return ( $ip_long & $mask ) === ( $subnet_long & $mask );
    }

    // ── Authentication Filter ─────────────────────────────────

    /**
     * Check if the current IP is locked out before authentication.
     *
     * @param \WP_User|\WP_Error|null $user     User object or error.
     * @param string                  $username  Username.
     * @param string                  $password  Password (not stored/logged).
     * @return \WP_User|\WP_Error|null
     */
    public function check_lockout( $user, $username = '', $password = '' ) {
        $ip       = $this->get_client_ip();
        $settings = $this->get_settings();

        // Blacklist check: always block.
        if ( ! empty( $settings['blacklist_ips'] ) && $this->ip_in_list( $ip, $settings['blacklist_ips'] ) ) {
            return new \WP_Error(
                'wpt_ip_blacklisted',
                __( '<strong>Error:</strong> Your IP address has been blocked.', 'wptransformed' )
            );
        }

        // Whitelist check: skip lockout enforcement.
        if ( ! empty( $settings['whitelist_ips'] ) && $this->ip_in_list( $ip, $settings['whitelist_ips'] ) ) {
            return $user;
        }

        // Check active lockout.
        $lockout = $this->get_active_lockout( $ip );
        if ( $lockout ) {
            $lockout_until = strtotime( $lockout->lockout_until );
            $remaining     = $lockout_until - time();

            if ( $remaining > 0 ) {
                $minutes = (int) ceil( $remaining / 60 );

                return new \WP_Error(
                    'wpt_login_locked_out',
                    sprintf(
                        /* translators: %d: number of minutes */
                        _n(
                            '<strong>Error:</strong> Too many failed login attempts. Please try again in %d minute.',
                            '<strong>Error:</strong> Too many failed login attempts. Please try again in %d minutes.',
                            $minutes,
                            'wptransformed'
                        ),
                        $minutes
                    )
                );
            }
        }

        return $user;
    }

    // ── Failed Login Handler ──────────────────────────────────

    /**
     * Handle a failed login attempt.
     *
     * @param string $username The username that was attempted.
     */
    public function handle_failed_login( $username = '' ): void {
        $username = (string) $username;
        $ip       = $this->get_client_ip();
        $settings = $this->get_settings();

        // Skip for whitelisted IPs.
        if ( ! empty( $settings['whitelist_ips'] ) && $this->ip_in_list( $ip, $settings['whitelist_ips'] ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // Record the failed attempt (never log password).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table_name,
            [
                'ip_address'   => $ip,
                'username'     => sanitize_user( $username ),
                'attempted_at' => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s' ]
        );

        // Count recent failed attempts for this IP (within lockout window).
        $window_minutes = max( 1, (int) $settings['lockout_duration'] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $recent_attempts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name}
                 WHERE ip_address = %s
                   AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)
                   AND lockout_until IS NULL",
                $ip,
                $window_minutes
            )
        );

        $max_attempts = max( 1, (int) $settings['max_attempts'] );

        if ( $recent_attempts >= $max_attempts ) {
            $this->trigger_lockout( $ip, $username, $settings );
        }
    }

    /**
     * Trigger a lockout for the given IP.
     *
     * @param string $ip       IP address.
     * @param string $username Username attempted.
     * @param array  $settings Module settings.
     */
    private function trigger_lockout( string $ip, string $username, array $settings ): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // Count previous lockouts for this IP.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $previous_lockouts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(lockout_count) FROM {$table_name}
                 WHERE ip_address = %s AND lockout_until IS NOT NULL",
                $ip
            )
        );

        $lockout_count = $previous_lockouts + 1;
        $max_lockouts  = max( 1, (int) $settings['max_lockouts'] );

        // Use extended lockout duration if max lockouts reached.
        if ( $lockout_count >= $max_lockouts ) {
            $duration_minutes = max( 1, (int) $settings['extended_lockout'] );
        } else {
            $duration_minutes = max( 1, (int) $settings['lockout_duration'] );
        }

        $lockout_until = gmdate( 'Y-m-d H:i:s', time() + ( $duration_minutes * 60 ) );

        // Record the lockout.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table_name,
            [
                'ip_address'    => $ip,
                'username'      => sanitize_user( $username ),
                'attempted_at'  => current_time( 'mysql', true ),
                'lockout_until' => $lockout_until,
                'lockout_count' => $lockout_count,
            ],
            [ '%s', '%s', '%s', '%s', '%d' ]
        );

        // Send notification email.
        $this->maybe_send_notification( $ip, $username, $lockout_count, $duration_minutes, $settings );
    }

    // ── Lockout Query ─────────────────────────────────────────

    /**
     * Get the active lockout record for an IP, if any.
     *
     * @param string $ip IP address.
     * @return object|null Lockout row or null.
     */
    private function get_active_lockout( string $ip ): ?object {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name}
                 WHERE ip_address = %s
                   AND lockout_until IS NOT NULL
                   AND lockout_until > NOW()
                 ORDER BY lockout_until DESC
                 LIMIT 1",
                $ip
            )
        );

        return $row ?: null;
    }

    // ── Email Notification ────────────────────────────────────

    /**
     * Send a lockout notification email if configured.
     *
     * @param string $ip              Locked-out IP.
     * @param string $username        Username attempted.
     * @param int    $lockout_count   Number of lockouts.
     * @param int    $duration        Duration in minutes.
     * @param array  $settings        Module settings.
     */
    private function maybe_send_notification( string $ip, string $username, int $lockout_count, int $duration, array $settings ): void {
        $email = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : '';

        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );

        $subject = sprintf(
            /* translators: %s: site name */
            __( '[%s] Login Lockout Notification', 'wptransformed' ),
            $site_name
        );

        $message = sprintf(
            /* translators: 1: site name, 2: IP address, 3: username, 4: lockout count, 5: duration */
            __(
                "A login lockout has been triggered on %1\$s.\n\n" .
                "IP Address: %2\$s\n" .
                "Username attempted: %3\$s\n" .
                "Lockout #: %4\$d\n" .
                "Duration: %5\$d minutes\n\n" .
                "If this is unexpected, consider adding the IP to your blacklist in WPTransformed > Limit Login Attempts.",
                'wptransformed'
            ),
            $site_name,
            $ip,
            $username,
            $lockout_count,
            $duration
        );

        wp_mail( $email, $subject, $message );
    }

    // ── Cron Cleanup ──────────────────────────────────────────

    /**
     * Clean up old login attempt records beyond the retention period.
     */
    public function cleanup_old_records(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;
        $settings   = $this->get_settings();
        $retention  = max( 1, (int) $settings['log_retention'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name}
                 WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $retention
            )
        );
    }

    // ── AJAX Handlers ─────────────────────────────────────────

    /**
     * AJAX handler to clear the entire login log.
     */
    public function ajax_clear_log(): void {
        check_ajax_referer( 'wpt_clear_login_log_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // Use DELETE instead of TRUNCATE for replication safety on managed MySQL (WP Engine).
        // Table name is safe: $wpdb->prefix (trusted) + self::TABLE_SUFFIX (constant).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "DELETE FROM `{$table_name}`" );

        wp_send_json_success( [ 'message' => __( 'Login log cleared.', 'wptransformed' ) ] );
    }

    /**
     * AJAX handler to unlock a specific IP.
     */
    public function ajax_unlock_ip(): void {
        check_ajax_referer( 'wpt_unlock_ip_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';

        if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid IP address.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // Remove active lockouts for this IP.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name} SET lockout_until = NULL WHERE ip_address = %s AND lockout_until > NOW()",
                $ip
            )
        );

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: IP address */
                __( 'IP %s has been unlocked.', 'wptransformed' ),
                $ip
            ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        $whitelist_str = is_array( $settings['whitelist_ips'] ) ? implode( "\n", $settings['whitelist_ips'] ) : '';
        $blacklist_str = is_array( $settings['blacklist_ips'] ) ? implode( "\n", $settings['blacklist_ips'] ) : '';
        ?>

        <table class="form-table wpt-limit-login-settings" role="presentation">
            <!-- Max Attempts -->
            <tr>
                <th scope="row">
                    <label for="wpt-max-attempts"><?php esc_html_e( 'Max Login Attempts', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-max-attempts" name="wpt_max_attempts"
                           value="<?php echo esc_attr( (string) $settings['max_attempts'] ); ?>"
                           class="small-text" min="1" max="100">
                    <p class="description">
                        <?php esc_html_e( 'Number of failed login attempts before lockout.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Lockout Duration -->
            <tr>
                <th scope="row">
                    <label for="wpt-lockout-duration"><?php esc_html_e( 'Lockout Duration (minutes)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-lockout-duration" name="wpt_lockout_duration"
                           value="<?php echo esc_attr( (string) $settings['lockout_duration'] ); ?>"
                           class="small-text" min="1" max="10080">
                    <p class="description">
                        <?php esc_html_e( 'How long an IP is locked out after exceeding max attempts.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Max Lockouts -->
            <tr>
                <th scope="row">
                    <label for="wpt-max-lockouts"><?php esc_html_e( 'Max Lockouts Before Extended', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-max-lockouts" name="wpt_max_lockouts"
                           value="<?php echo esc_attr( (string) $settings['max_lockouts'] ); ?>"
                           class="small-text" min="1" max="100">
                    <p class="description">
                        <?php esc_html_e( 'After this many lockouts, the extended lockout duration is used.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Extended Lockout -->
            <tr>
                <th scope="row">
                    <label for="wpt-extended-lockout"><?php esc_html_e( 'Extended Lockout (minutes)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-extended-lockout" name="wpt_extended_lockout"
                           value="<?php echo esc_attr( (string) $settings['extended_lockout'] ); ?>"
                           class="small-text" min="1" max="43200">
                    <p class="description">
                        <?php esc_html_e( 'Duration for extended lockout (default: 1440 = 24 hours).', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Notification Email -->
            <tr>
                <th scope="row">
                    <label for="wpt-notify-email"><?php esc_html_e( 'Notification Email', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="email" id="wpt-notify-email" name="wpt_notify_email"
                           value="<?php echo esc_attr( $settings['notify_email'] ); ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'Email address to notify on lockout. Leave empty to disable.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Log Retention -->
            <tr>
                <th scope="row">
                    <label for="wpt-log-retention"><?php esc_html_e( 'Log Retention (days)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-log-retention" name="wpt_log_retention"
                           value="<?php echo esc_attr( (string) $settings['log_retention'] ); ?>"
                           class="small-text" min="1" max="365">
                    <p class="description">
                        <?php esc_html_e( 'Number of days to keep login attempt records. Older records are auto-deleted daily.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Whitelist IPs -->
            <tr>
                <th scope="row">
                    <label for="wpt-whitelist-ips"><?php esc_html_e( 'Whitelisted IPs', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-whitelist-ips" name="wpt_whitelist_ips"
                              rows="4" class="large-text code"
                              placeholder="192.168.1.1&#10;10.0.0.0/8"><?php echo esc_textarea( $whitelist_str ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One IP or CIDR range per line. Whitelisted IPs are never locked out.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Blacklist IPs -->
            <tr>
                <th scope="row">
                    <label for="wpt-blacklist-ips"><?php esc_html_e( 'Blacklisted IPs', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-blacklist-ips" name="wpt_blacklist_ips"
                              rows="4" class="large-text code"
                              placeholder="203.0.113.50&#10;198.51.100.0/24"><?php echo esc_textarea( $blacklist_str ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One IP or CIDR range per line. Blacklisted IPs are always blocked from login.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php $this->render_lockout_log(); ?>

        <?php
        // Nonce fields for AJAX actions.
        wp_nonce_field( 'wpt_clear_login_log_nonce', 'wpt_clear_login_log_nonce_field', false );
        wp_nonce_field( 'wpt_unlock_ip_nonce', 'wpt_unlock_ip_nonce_field', false );
    }

    /**
     * Render the recent lockout log table.
     */
    private function render_lockout_log(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // Check if table exists before querying.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            return;
        }

        // Get recent lockout events (last 50).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $lockouts = $wpdb->get_results(
            "SELECT ip_address, username, attempted_at, lockout_until, lockout_count
             FROM {$table_name}
             WHERE lockout_until IS NOT NULL
             ORDER BY attempted_at DESC
             LIMIT 50"
        );

        // Get current lockout stats.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $active_lockouts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT ip_address) FROM {$table_name}
             WHERE lockout_until IS NOT NULL AND lockout_until > NOW()"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_attempts_24h = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
               AND lockout_until IS NULL"
        );

        ?>
        <div class="wpt-lockout-log-section">
            <h3><?php esc_html_e( 'Login Security Overview', 'wptransformed' ); ?></h3>

            <div class="wpt-lockout-stats">
                <div class="wpt-stat-card">
                    <span class="wpt-stat-number"><?php echo esc_html( (string) $active_lockouts ); ?></span>
                    <span class="wpt-stat-label"><?php esc_html_e( 'Active Lockouts', 'wptransformed' ); ?></span>
                </div>
                <div class="wpt-stat-card">
                    <span class="wpt-stat-number"><?php echo esc_html( (string) $total_attempts_24h ); ?></span>
                    <span class="wpt-stat-label"><?php esc_html_e( 'Failed Attempts (24h)', 'wptransformed' ); ?></span>
                </div>
                <div class="wpt-stat-card">
                    <span class="wpt-stat-number"><?php echo esc_html( (string) count( $lockouts ) ); ?></span>
                    <span class="wpt-stat-label"><?php esc_html_e( 'Total Lockout Events', 'wptransformed' ); ?></span>
                </div>
            </div>

            <?php if ( ! empty( $lockouts ) ) : ?>
            <h4><?php esc_html_e( 'Recent Lockout Log', 'wptransformed' ); ?></h4>
            <table class="widefat fixed striped wpt-lockout-log-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'IP Address', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Username', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Attempted At', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Locked Until', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Lockout #', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $lockouts as $row ) : ?>
                    <?php
                        $is_active = strtotime( $row->lockout_until ) > time();
                        $status_class = $is_active ? 'wpt-status-locked' : 'wpt-status-expired';
                        $status_text  = $is_active
                            ? __( 'Locked', 'wptransformed' )
                            : __( 'Expired', 'wptransformed' );
                    ?>
                    <tr>
                        <td>
                            <code><?php echo esc_html( $row->ip_address ); ?></code>
                            <?php if ( $is_active ) : ?>
                            <button type="button" class="button button-small wpt-unlock-btn"
                                    data-ip="<?php echo esc_attr( $row->ip_address ); ?>">
                                <?php esc_html_e( 'Unlock', 'wptransformed' ); ?>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row->username ); ?></td>
                        <td><?php echo esc_html( $row->attempted_at ); ?></td>
                        <td><?php echo esc_html( $row->lockout_until ); ?></td>
                        <td><?php echo esc_html( (string) $row->lockout_count ); ?></td>
                        <td>
                            <span class="wpt-lockout-status <?php echo esc_attr( $status_class ); ?>">
                                <?php echo esc_html( $status_text ); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 12px;">
                <button type="button" class="button button-secondary" id="wpt-clear-login-log">
                    <?php esc_html_e( 'Clear Entire Log', 'wptransformed' ); ?>
                </button>
                <span class="spinner" id="wpt-clear-log-spinner" style="float: none;"></span>
            </p>
            <?php else : ?>
            <p class="description">
                <?php esc_html_e( 'No lockout events recorded yet.', 'wptransformed' ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // Max attempts.
        $max_attempts = isset( $raw['wpt_max_attempts'] ) ? absint( $raw['wpt_max_attempts'] ) : 5;
        if ( $max_attempts < 1 || $max_attempts > 100 ) {
            $max_attempts = 5;
        }

        // Lockout duration.
        $lockout_duration = isset( $raw['wpt_lockout_duration'] ) ? absint( $raw['wpt_lockout_duration'] ) : 20;
        if ( $lockout_duration < 1 || $lockout_duration > 10080 ) {
            $lockout_duration = 20;
        }

        // Max lockouts.
        $max_lockouts = isset( $raw['wpt_max_lockouts'] ) ? absint( $raw['wpt_max_lockouts'] ) : 3;
        if ( $max_lockouts < 1 || $max_lockouts > 100 ) {
            $max_lockouts = 3;
        }

        // Extended lockout.
        $extended_lockout = isset( $raw['wpt_extended_lockout'] ) ? absint( $raw['wpt_extended_lockout'] ) : 1440;
        if ( $extended_lockout < 1 || $extended_lockout > 43200 ) {
            $extended_lockout = 1440;
        }

        // Notification email.
        $notify_email = isset( $raw['wpt_notify_email'] ) ? sanitize_email( $raw['wpt_notify_email'] ) : '';

        // Log retention.
        $log_retention = isset( $raw['wpt_log_retention'] ) ? absint( $raw['wpt_log_retention'] ) : 30;
        if ( $log_retention < 1 || $log_retention > 365 ) {
            $log_retention = 30;
        }

        // Whitelist IPs.
        $whitelist_ips = [];
        if ( ! empty( $raw['wpt_whitelist_ips'] ) ) {
            $lines = explode( "\n", sanitize_textarea_field( $raw['wpt_whitelist_ips'] ) );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( ! empty( $line ) && ( filter_var( $line, FILTER_VALIDATE_IP ) || $this->is_valid_cidr( $line ) ) ) {
                    $whitelist_ips[] = $line;
                }
            }
        }

        // Blacklist IPs.
        $blacklist_ips = [];
        if ( ! empty( $raw['wpt_blacklist_ips'] ) ) {
            $lines = explode( "\n", sanitize_textarea_field( $raw['wpt_blacklist_ips'] ) );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( ! empty( $line ) && ( filter_var( $line, FILTER_VALIDATE_IP ) || $this->is_valid_cidr( $line ) ) ) {
                    $blacklist_ips[] = $line;
                }
            }
        }

        return [
            'max_attempts'     => $max_attempts,
            'lockout_duration' => $lockout_duration,
            'max_lockouts'     => $max_lockouts,
            'extended_lockout' => $extended_lockout,
            'whitelist_ips'    => $whitelist_ips,
            'blacklist_ips'    => $blacklist_ips,
            'notify_email'     => $notify_email,
            'log_retention'    => $log_retention,
        ];
    }

    /**
     * Validate a CIDR notation string.
     *
     * @param string $cidr CIDR to validate.
     * @return bool
     */
    private function is_valid_cidr( string $cidr ): bool {
        if ( strpos( $cidr, '/' ) === false ) {
            return false;
        }

        list( $ip, $bits ) = explode( '/', $cidr, 2 );

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $bits = (int) $bits;
        return $bits >= 0 && $bits <= 32;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-limit-login-attempts',
            WPT_URL . 'modules/security/css/limit-login-attempts.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-limit-login-attempts',
            WPT_URL . 'modules/security/js/limit-login-attempts.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-limit-login-attempts', 'wptLimitLogin', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'clearLogNonce' => wp_create_nonce( 'wpt_clear_login_log_nonce' ),
            'unlockNonce'   => wp_create_nonce( 'wpt_unlock_ip_nonce' ),
            'i18n'          => [
                'confirmClear'  => __( 'Are you sure you want to clear the entire login log?', 'wptransformed' ),
                'confirmUnlock' => __( 'Are you sure you want to unlock this IP?', 'wptransformed' ),
                'clearing'      => __( 'Clearing...', 'wptransformed' ),
                'unlocking'     => __( 'Unlocking...', 'wptransformed' ),
                'networkError'  => __( 'Network error. Please try again.', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        global $wpdb;
        return [
            'tables' => [ $wpdb->prefix . self::TABLE_SUFFIX ],
        ];
    }
}
