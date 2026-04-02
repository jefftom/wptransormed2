<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Audit Log — Track important site activity for security and compliance.
 *
 * Features:
 *  - Logs post, plugin, theme, user, login, settings, media, and menu events
 *  - Configurable event categories and retention period
 *  - User exclusion list and admin logging toggle
 *  - IP address tracking with proxy support
 *  - Daily cron cleanup of old records
 *  - Filterable log viewer with AJAX pagination
 *  - WP-CLI operations captured as "System" (user_id = 0)
 *
 * @package WPTransformed
 */
class Audit_Log extends Module_Base {

    /**
     * Custom table name (without prefix).
     */
    private const TABLE_SUFFIX = 'wpt_audit_log';

    /**
     * Transient key for table existence check.
     */
    private const TABLE_CHECK_TRANSIENT = 'wpt_audit_log_table_exists';

    /**
     * Number of log entries per page in the admin viewer.
     */
    private const PER_PAGE = 25;

    /**
     * Options we selectively track for settings changes.
     *
     * @var string[]
     */
    private const TRACKED_OPTIONS = [
        'blogname',
        'blogdescription',
        'siteurl',
        'home',
        'admin_email',
        'permalink_structure',
        'default_role',
        'users_can_register',
        'date_format',
        'time_format',
        'timezone_string',
        'WPLANG',
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'audit-log';
    }

    public function get_title(): string {
        return __( 'Audit Log', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Track important site activity including post changes, plugin events, logins, and more for security and compliance auditing.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled_events' => [ 'posts', 'plugins', 'themes', 'users', 'logins', 'settings', 'media', 'menus' ],
            'retention_days' => 90,
            'log_admins'     => true,
            'exclude_users'  => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Ensure custom table exists.
        $this->maybe_create_table();

        $settings = $this->get_settings();
        $enabled  = (array) $settings['enabled_events'];

        // Post events.
        if ( in_array( 'posts', $enabled, true ) ) {
            add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
            add_action( 'delete_post', [ $this, 'on_delete_post' ], 10, 1 );
            add_action( 'wp_trash_post', [ $this, 'on_trash_post' ], 10, 1 );
            add_action( 'untrash_post', [ $this, 'on_untrash_post' ], 10, 1 );
        }

        // Plugin events.
        if ( in_array( 'plugins', $enabled, true ) ) {
            add_action( 'activated_plugin', [ $this, 'on_activate_plugin' ], 10, 2 );
            add_action( 'deactivated_plugin', [ $this, 'on_deactivate_plugin' ], 10, 2 );
        }

        // Theme events.
        if ( in_array( 'themes', $enabled, true ) ) {
            add_action( 'switch_theme', [ $this, 'on_switch_theme' ], 10, 3 );
        }

        // User events.
        if ( in_array( 'users', $enabled, true ) ) {
            add_action( 'user_register', [ $this, 'on_user_register' ], 10, 1 );
            add_action( 'delete_user', [ $this, 'on_delete_user' ], 10, 2 );
            add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );
        }

        // Login events.
        if ( in_array( 'logins', $enabled, true ) ) {
            add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
            add_action( 'wp_logout', [ $this, 'on_logout' ], 10, 1 );
        }

        // Settings events.
        if ( in_array( 'settings', $enabled, true ) ) {
            add_action( 'update_option', [ $this, 'on_update_option' ], 10, 3 );
        }

        // Media events.
        if ( in_array( 'media', $enabled, true ) ) {
            add_action( 'add_attachment', [ $this, 'on_add_attachment' ], 10, 1 );
            add_action( 'delete_attachment', [ $this, 'on_delete_attachment' ], 10, 1 );
        }

        // Menu events.
        if ( in_array( 'menus', $enabled, true ) ) {
            add_action( 'wp_update_nav_menu', [ $this, 'on_update_nav_menu' ], 10, 2 );
        }

        // Cron for cleanup.
        if ( ! wp_next_scheduled( 'wpt_prune_audit_log' ) ) {
            wp_schedule_event( time(), 'daily', 'wpt_prune_audit_log' );
        }
        add_action( 'wpt_prune_audit_log', [ $this, 'prune_old_records' ] );

        // AJAX handler for log pagination.
        add_action( 'wp_ajax_wpt_audit_log_fetch', [ $this, 'ajax_fetch_logs' ] );
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( 'wpt_prune_audit_log' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpt_prune_audit_log' );
        }
    }

    // ── Table Creation ────────────────────────────────────────

    /**
     * Create the audit log table if it does not exist.
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
            user_id BIGINT UNSIGNED,
            action VARCHAR(50) NOT NULL,
            object_type VARCHAR(50),
            object_id BIGINT UNSIGNED,
            object_title VARCHAR(255),
            details TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
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

    // ── User Exclusion Check ──────────────────────────────────

    /**
     * Check if the current user should be excluded from logging.
     *
     * @param int $user_id User ID to check (0 = system/CLI).
     * @return bool True if user should be excluded.
     */
    private function should_exclude_user( int $user_id ): bool {
        // Never exclude system actions (WP-CLI, cron, etc.).
        if ( $user_id === 0 ) {
            return false;
        }

        $settings = $this->get_settings();

        // Check admin exclusion.
        if ( ! $settings['log_admins'] && user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        // Check user exclusion list.
        $excluded = array_map( 'absint', (array) $settings['exclude_users'] );
        if ( in_array( $user_id, $excluded, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get the current user ID, 0 for CLI/system.
     *
     * @return int
     */
    private function get_current_user_id(): int {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return 0;
        }

        return get_current_user_id();
    }

    // ── Log Entry ─────────────────────────────────────────────

    /**
     * Insert an audit log entry.
     *
     * @param string   $action       Action identifier (e.g., 'post_updated').
     * @param string   $object_type  Object type (e.g., 'post', 'plugin').
     * @param int      $object_id    Object ID (0 if not applicable).
     * @param string   $object_title Human-readable label.
     * @param array    $details      Additional context as key-value pairs.
     * @param int|null $user_id      Override user ID (null = auto-detect).
     */
    private function log_event(
        string $action,
        string $object_type = '',
        int $object_id = 0,
        string $object_title = '',
        array $details = [],
        ?int $user_id = null
    ): void {
        $uid = $user_id !== null ? $user_id : $this->get_current_user_id();

        if ( $this->should_exclude_user( $uid ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table_name,
            [
                'user_id'      => $uid,
                'action'       => substr( sanitize_key( $action ), 0, 50 ),
                'object_type'  => substr( sanitize_key( $object_type ), 0, 50 ),
                'object_id'    => $object_id,
                'object_title' => substr( sanitize_text_field( $object_title ), 0, 255 ),
                'details'      => wp_json_encode( $details ),
                'ip_address'   => $this->get_client_ip(),
                'created_at'   => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );
    }

    // ── Post Event Callbacks ──────────────────────────────────

    /**
     * Handle post create/update events.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an update.
     */
    public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        // Skip revisions and autosaves.
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        // Skip auto-drafts.
        if ( $post->post_status === 'auto-draft' ) {
            return;
        }

        $action = $update ? 'post_updated' : 'post_created';

        $this->log_event(
            $action,
            $post->post_type,
            $post_id,
            $post->post_title,
            [
                'status' => $post->post_status,
            ]
        );
    }

    /**
     * Handle permanent post deletion.
     *
     * @param int $post_id Post ID.
     */
    public function on_delete_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $this->log_event(
            'post_deleted',
            $post->post_type,
            $post_id,
            $post->post_title
        );
    }

    /**
     * Handle post trashing.
     *
     * @param int $post_id Post ID.
     */
    public function on_trash_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $this->log_event(
            'post_trashed',
            $post->post_type,
            $post_id,
            $post->post_title
        );
    }

    /**
     * Handle post untrashing.
     *
     * @param int $post_id Post ID.
     */
    public function on_untrash_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $this->log_event(
            'post_restored',
            $post->post_type,
            $post_id,
            $post->post_title
        );
    }

    // ── Plugin Event Callbacks ────────────────────────────────

    /**
     * Handle plugin activation.
     *
     * @param string $plugin       Plugin basename.
     * @param bool   $network_wide Whether activated network-wide.
     */
    public function on_activate_plugin( string $plugin, bool $network_wide ): void {
        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
        $name        = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $plugin;

        $this->log_event(
            'plugin_activated',
            'plugin',
            0,
            $name,
            [
                'plugin'       => $plugin,
                'network_wide' => $network_wide,
            ]
        );
    }

    /**
     * Handle plugin deactivation.
     *
     * @param string $plugin       Plugin basename.
     * @param bool   $network_wide Whether deactivated network-wide.
     */
    public function on_deactivate_plugin( string $plugin, bool $network_wide ): void {
        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
        $name        = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $plugin;

        $this->log_event(
            'plugin_deactivated',
            'plugin',
            0,
            $name,
            [
                'plugin'       => $plugin,
                'network_wide' => $network_wide,
            ]
        );
    }

    // ── Theme Event Callbacks ─────────────────────────────────

    /**
     * Handle theme switch.
     *
     * @param string    $new_name  New theme name.
     * @param \WP_Theme $new_theme New theme object.
     * @param \WP_Theme $old_theme Old theme object.
     */
    public function on_switch_theme( string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme ): void {
        $this->log_event(
            'theme_switched',
            'theme',
            0,
            $new_name,
            [
                'old_theme' => $old_theme->get( 'Name' ),
                'new_theme' => $new_name,
            ]
        );
    }

    // ── User Event Callbacks ──────────────────────────────────

    /**
     * Handle new user registration.
     *
     * @param int $user_id New user ID.
     */
    public function on_user_register( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $this->log_event(
            'user_registered',
            'user',
            $user_id,
            $user->user_login,
            [
                'role' => implode( ', ', $user->roles ),
            ]
        );
    }

    /**
     * Handle user deletion.
     *
     * @param int      $user_id  Deleted user ID.
     * @param int|null $reassign ID of user to reassign content to, or null.
     */
    public function on_delete_user( int $user_id, $reassign = null ): void {
        $user = get_userdata( $user_id );
        $name = $user ? $user->user_login : "User #{$user_id}";

        $details = [];
        if ( $reassign ) {
            $reassign_user = get_userdata( (int) $reassign );
            $details['reassigned_to'] = $reassign_user ? $reassign_user->user_login : "User #{$reassign}";
        }

        $this->log_event(
            'user_deleted',
            'user',
            $user_id,
            $name,
            $details
        );
    }

    /**
     * Handle profile update.
     *
     * @param int      $user_id       User ID.
     * @param \WP_User $old_user_data Previous user data.
     */
    public function on_profile_update( int $user_id, \WP_User $old_user_data ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $changes = [];
        if ( $user->user_email !== $old_user_data->user_email ) {
            $changes['email_changed'] = true;
        }
        if ( $user->display_name !== $old_user_data->display_name ) {
            $changes['display_name_changed'] = true;
        }
        if ( $user->roles !== $old_user_data->roles ) {
            $changes['old_roles'] = implode( ', ', $old_user_data->roles );
            $changes['new_roles'] = implode( ', ', $user->roles );
        }

        // Only log if something meaningful changed.
        if ( empty( $changes ) ) {
            return;
        }

        $this->log_event(
            'user_updated',
            'user',
            $user_id,
            $user->user_login,
            $changes
        );
    }

    // ── Login Event Callbacks ─────────────────────────────────

    /**
     * Handle user login.
     *
     * @param string   $user_login Username.
     * @param \WP_User $user       User object.
     */
    public function on_login( string $user_login, \WP_User $user ): void {
        $this->log_event(
            'user_login',
            'session',
            $user->ID,
            $user_login,
            [],
            $user->ID
        );
    }

    /**
     * Handle user logout.
     *
     * @param int $user_id User ID.
     */
    public function on_logout( int $user_id ): void {
        $user = get_userdata( $user_id );
        $name = $user ? $user->user_login : "User #{$user_id}";

        $this->log_event(
            'user_logout',
            'session',
            $user_id,
            $name,
            [],
            $user_id
        );
    }

    // ── Settings Event Callbacks ──────────────────────────────

    /**
     * Handle option updates (selective tracking).
     *
     * @param string $option    Option name.
     * @param mixed  $old_value Old value.
     * @param mixed  $new_value New value.
     */
    public function on_update_option( string $option, $old_value, $new_value ): void {
        if ( ! in_array( $option, self::TRACKED_OPTIONS, true ) ) {
            return;
        }

        // Don't log identical values.
        if ( $old_value === $new_value ) {
            return;
        }

        $this->log_event(
            'option_updated',
            'option',
            0,
            $option,
            [
                'old_value' => is_scalar( $old_value ) ? (string) $old_value : '(complex)',
                'new_value' => is_scalar( $new_value ) ? (string) $new_value : '(complex)',
            ]
        );
    }

    // ── Media Event Callbacks ─────────────────────────────────

    /**
     * Handle media upload.
     *
     * @param int $post_id Attachment post ID.
     */
    public function on_add_attachment( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $this->log_event(
            'media_uploaded',
            'attachment',
            $post_id,
            $post->post_title,
            [
                'mime_type' => $post->post_mime_type,
            ]
        );
    }

    /**
     * Handle media deletion.
     *
     * @param int $post_id Attachment post ID.
     */
    public function on_delete_attachment( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $this->log_event(
            'media_deleted',
            'attachment',
            $post_id,
            $post->post_title
        );
    }

    // ── Menu Event Callbacks ──────────────────────────────────

    /**
     * Handle nav menu update.
     *
     * @param int   $menu_id    Menu ID.
     * @param array $menu_data  Menu data (may be empty on item updates).
     */
    public function on_update_nav_menu( int $menu_id, $menu_data = [] ): void {
        $menu = wp_get_nav_menu_object( $menu_id );
        $name = $menu ? $menu->name : "Menu #{$menu_id}";

        $this->log_event(
            'menu_updated',
            'nav_menu',
            $menu_id,
            $name
        );
    }

    // ── Cron Cleanup ──────────────────────────────────────────

    /**
     * Delete audit log records older than the configured retention period.
     */
    public function prune_old_records(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;
        $settings   = $this->get_settings();
        $retention  = max( 1, (int) $settings['retention_days'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $retention
            )
        );
    }

    // ── AJAX Handler ──────────────────────────────────────────

    /**
     * AJAX handler to fetch audit log entries with filtering and pagination.
     */
    public function ajax_fetch_logs(): void {
        check_ajax_referer( 'wpt_audit_log_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        $page        = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $filter_user = isset( $_POST['filter_user'] ) ? absint( $_POST['filter_user'] ) : 0;
        $filter_action = isset( $_POST['filter_action'] ) ? sanitize_key( $_POST['filter_action'] ) : '';
        $filter_date_from = isset( $_POST['filter_date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_date_from'] ) ) : '';
        $filter_date_to   = isset( $_POST['filter_date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_date_to'] ) ) : '';

        $where  = [];
        $values = [];

        if ( $filter_user > 0 ) {
            $where[]  = 'user_id = %d';
            $values[] = $filter_user;
        }

        if ( ! empty( $filter_action ) ) {
            $where[]  = 'action = %s';
            $values[] = $filter_action;
        }

        if ( ! empty( $filter_date_from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_from ) ) {
            $where[]  = 'created_at >= %s';
            $values[] = $filter_date_from . ' 00:00:00';
        }

        if ( ! empty( $filter_date_to ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_to ) ) {
            $where[]  = 'created_at <= %s';
            $values[] = $filter_date_to . ' 23:59:59';
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $offset       = ( $page - 1 ) * self::PER_PAGE;

        // Get total count.
        $count_sql = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // Get rows.
        $data_sql = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $all_values = array_merge( $values, [ self::PER_PAGE, $offset ] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$all_values ) );

        // Build HTML rows.
        $html = '';
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $user_display = $this->get_user_display( (int) $row->user_id );
                $details_decoded = json_decode( $row->details, true );
                $details_str = '';
                if ( is_array( $details_decoded ) && ! empty( $details_decoded ) ) {
                    $parts = [];
                    foreach ( $details_decoded as $key => $val ) {
                        $parts[] = esc_html( $key ) . ': ' . esc_html( (string) $val );
                    }
                    $details_str = implode( ', ', $parts );
                }

                $html .= '<tr>';
                $html .= '<td>' . esc_html( $row->created_at ) . '</td>';
                $html .= '<td>' . $user_display . '</td>';
                $html .= '<td><code>' . esc_html( $row->action ) . '</code></td>';
                $html .= '<td>' . esc_html( $row->object_type ) . '</td>';
                $html .= '<td>' . esc_html( $row->object_title ) . '</td>';
                $html .= '<td class="wpt-audit-details">' . ( $details_str ? '<span class="wpt-details-text">' . $details_str . '</span>' : '&mdash;' ) . '</td>';
                $html .= '<td><code>' . esc_html( $row->ip_address ) . '</code></td>';
                $html .= '</tr>';
            }
        } else {
            $html = '<tr><td colspan="7">' . esc_html__( 'No log entries found.', 'wptransformed' ) . '</td></tr>';
        }

        wp_send_json_success( [
            'html'        => $html,
            'total'       => $total,
            'total_pages' => (int) ceil( $total / self::PER_PAGE ),
            'current'     => $page,
        ] );
    }

    /**
     * Get display name for a user ID.
     *
     * @param int $user_id User ID.
     * @return string HTML-safe display string.
     */
    private function get_user_display( int $user_id ): string {
        if ( $user_id === 0 ) {
            return '<em>' . esc_html__( 'System', 'wptransformed' ) . '</em>';
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return esc_html( sprintf( __( 'Deleted user #%d', 'wptransformed' ), $user_id ) );
        }

        return esc_html( $user->display_name ) . ' <small>(' . esc_html( $user->user_login ) . ')</small>';
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        $all_events = [
            'posts'    => __( 'Posts & Pages', 'wptransformed' ),
            'plugins'  => __( 'Plugins', 'wptransformed' ),
            'themes'   => __( 'Themes', 'wptransformed' ),
            'users'    => __( 'Users', 'wptransformed' ),
            'logins'   => __( 'Logins & Logouts', 'wptransformed' ),
            'settings' => __( 'Settings', 'wptransformed' ),
            'media'    => __( 'Media', 'wptransformed' ),
            'menus'    => __( 'Menus', 'wptransformed' ),
        ];

        $enabled_events = (array) $settings['enabled_events'];
        $exclude_str    = is_array( $settings['exclude_users'] )
            ? implode( ', ', array_map( 'absint', $settings['exclude_users'] ) )
            : '';
        ?>

        <table class="form-table wpt-audit-log-settings" role="presentation">
            <!-- Enabled Events -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Tracked Events', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                    <?php foreach ( $all_events as $key => $label ) : ?>
                        <label style="display: inline-block; margin-right: 16px; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="wpt_enabled_events[]"
                                   value="<?php echo esc_attr( $key ); ?>"
                                   <?php checked( in_array( $key, $enabled_events, true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Select which event categories to track.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Retention Days -->
            <tr>
                <th scope="row">
                    <label for="wpt-retention-days"><?php esc_html_e( 'Retention Period (days)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-retention-days" name="wpt_retention_days"
                           value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>"
                           class="small-text" min="1" max="365">
                    <p class="description">
                        <?php esc_html_e( 'Log entries older than this will be automatically deleted daily.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Log Admins -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Log Admin Activity', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_log_admins" value="1"
                               <?php checked( $settings['log_admins'] ); ?>>
                        <?php esc_html_e( 'Include administrator actions in the audit log', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <!-- Exclude Users -->
            <tr>
                <th scope="row">
                    <label for="wpt-exclude-users"><?php esc_html_e( 'Exclude User IDs', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-exclude-users" name="wpt_exclude_users"
                           value="<?php echo esc_attr( $exclude_str ); ?>"
                           class="regular-text"
                           placeholder="1, 5, 12">
                    <p class="description">
                        <?php esc_html_e( 'Comma-separated user IDs to exclude from logging.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php $this->render_log_viewer(); ?>

        <?php
        // Nonce field for AJAX actions.
        wp_nonce_field( 'wpt_audit_log_nonce', 'wpt_audit_log_nonce_field', false );
    }

    /**
     * Render the audit log viewer with filters.
     */
    private function render_log_viewer(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_SUFFIX;

        // Check if table exists before querying.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            return;
        }

        // Get distinct actions for the filter dropdown.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table_name} ORDER BY action ASC" );

        // Get total count.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

        // Get initial page of entries.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $entries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET 0",
                self::PER_PAGE
            )
        );

        ?>
        <div class="wpt-audit-log-viewer">
            <h3><?php esc_html_e( 'Activity Log', 'wptransformed' ); ?>
                <span class="wpt-audit-total-count"><?php
                    /* translators: %s: number of entries */
                    echo esc_html( sprintf( __( '(%s entries)', 'wptransformed' ), number_format_i18n( $total ) ) );
                ?></span>
            </h3>

            <!-- Filters -->
            <div class="wpt-audit-filters">
                <label>
                    <?php esc_html_e( 'User ID:', 'wptransformed' ); ?>
                    <input type="number" id="wpt-audit-filter-user" class="small-text" min="0" placeholder="0">
                </label>

                <label>
                    <?php esc_html_e( 'Action:', 'wptransformed' ); ?>
                    <select id="wpt-audit-filter-action">
                        <option value=""><?php esc_html_e( 'All Actions', 'wptransformed' ); ?></option>
                        <?php foreach ( $actions as $action_name ) : ?>
                            <option value="<?php echo esc_attr( $action_name ); ?>">
                                <?php echo esc_html( $action_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <?php esc_html_e( 'From:', 'wptransformed' ); ?>
                    <input type="date" id="wpt-audit-filter-from">
                </label>

                <label>
                    <?php esc_html_e( 'To:', 'wptransformed' ); ?>
                    <input type="date" id="wpt-audit-filter-to">
                </label>

                <button type="button" class="button" id="wpt-audit-filter-btn">
                    <?php esc_html_e( 'Filter', 'wptransformed' ); ?>
                </button>
                <button type="button" class="button" id="wpt-audit-reset-btn">
                    <?php esc_html_e( 'Reset', 'wptransformed' ); ?>
                </button>
                <span class="spinner" id="wpt-audit-spinner" style="float: none;"></span>
            </div>

            <!-- Log Table -->
            <table class="widefat fixed striped wpt-audit-log-table">
                <thead>
                    <tr>
                        <th class="wpt-col-date"><?php esc_html_e( 'Date', 'wptransformed' ); ?></th>
                        <th class="wpt-col-user"><?php esc_html_e( 'User', 'wptransformed' ); ?></th>
                        <th class="wpt-col-action"><?php esc_html_e( 'Action', 'wptransformed' ); ?></th>
                        <th class="wpt-col-type"><?php esc_html_e( 'Type', 'wptransformed' ); ?></th>
                        <th class="wpt-col-title"><?php esc_html_e( 'Object', 'wptransformed' ); ?></th>
                        <th class="wpt-col-details"><?php esc_html_e( 'Details', 'wptransformed' ); ?></th>
                        <th class="wpt-col-ip"><?php esc_html_e( 'IP', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody id="wpt-audit-log-body">
                    <?php if ( ! empty( $entries ) ) : ?>
                        <?php foreach ( $entries as $row ) : ?>
                            <?php
                            $user_display    = $this->get_user_display( (int) $row->user_id );
                            $details_decoded = json_decode( $row->details, true );
                            $details_str     = '';
                            if ( is_array( $details_decoded ) && ! empty( $details_decoded ) ) {
                                $parts = [];
                                foreach ( $details_decoded as $key => $val ) {
                                    $parts[] = esc_html( $key ) . ': ' . esc_html( (string) $val );
                                }
                                $details_str = implode( ', ', $parts );
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $row->created_at ); ?></td>
                                <td><?php echo $user_display; // Already escaped in get_user_display(). ?></td>
                                <td><code><?php echo esc_html( $row->action ); ?></code></td>
                                <td><?php echo esc_html( $row->object_type ); ?></td>
                                <td><?php echo esc_html( $row->object_title ); ?></td>
                                <td class="wpt-audit-details"><?php echo $details_str ? '<span class="wpt-details-text">' . $details_str . '</span>' : '&mdash;'; ?></td>
                                <td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No log entries yet.', 'wptransformed' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="wpt-audit-pagination" <?php echo $total <= self::PER_PAGE ? 'style="display:none;"' : ''; ?>>
                <button type="button" class="button" id="wpt-audit-prev" disabled>
                    &laquo; <?php esc_html_e( 'Previous', 'wptransformed' ); ?>
                </button>
                <span id="wpt-audit-page-info">
                    <?php
                    /* translators: 1: current page, 2: total pages */
                    echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'wptransformed' ), 1, max( 1, (int) ceil( $total / self::PER_PAGE ) ) ) );
                    ?>
                </span>
                <button type="button" class="button" id="wpt-audit-next" <?php disabled( $total <= self::PER_PAGE ); ?>>
                    <?php esc_html_e( 'Next', 'wptransformed' ); ?> &raquo;
                </button>
            </div>
        </div>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // Enabled events.
        $valid_events = [ 'posts', 'plugins', 'themes', 'users', 'logins', 'settings', 'media', 'menus' ];
        $enabled_events = [];
        if ( ! empty( $raw['wpt_enabled_events'] ) && is_array( $raw['wpt_enabled_events'] ) ) {
            foreach ( $raw['wpt_enabled_events'] as $event ) {
                $event = sanitize_key( $event );
                if ( in_array( $event, $valid_events, true ) ) {
                    $enabled_events[] = $event;
                }
            }
        }

        // Retention days.
        $retention_days = isset( $raw['wpt_retention_days'] ) ? absint( $raw['wpt_retention_days'] ) : 90;
        if ( $retention_days < 1 || $retention_days > 365 ) {
            $retention_days = 90;
        }

        // Log admins.
        $log_admins = ! empty( $raw['wpt_log_admins'] );

        // Exclude users.
        $exclude_users = [];
        if ( ! empty( $raw['wpt_exclude_users'] ) ) {
            $ids = explode( ',', sanitize_text_field( $raw['wpt_exclude_users'] ) );
            foreach ( $ids as $id ) {
                $id = absint( trim( $id ) );
                if ( $id > 0 ) {
                    $exclude_users[] = $id;
                }
            }
            $exclude_users = array_unique( $exclude_users );
        }

        return [
            'enabled_events' => $enabled_events,
            'retention_days' => $retention_days,
            'log_admins'     => $log_admins,
            'exclude_users'  => $exclude_users,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-audit-log',
            WPT_URL . 'modules/security/css/audit-log.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-audit-log',
            WPT_URL . 'modules/security/js/audit-log.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-audit-log', 'wptAuditLog', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_audit_log_nonce' ),
            'i18n'    => [
                'loading'     => __( 'Loading...', 'wptransformed' ),
                'noEntries'   => __( 'No log entries found.', 'wptransformed' ),
                'pageInfo'    => __( 'Page %1$d of %2$d', 'wptransformed' ),
                'networkError' => __( 'Network error. Please try again.', 'wptransformed' ),
                'entries'     => __( '(%s entries)', 'wptransformed' ),
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
