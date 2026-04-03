<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Activity Feed -- Dashboard widget showing chronological site activity.
 *
 * Features:
 *  - Dashboard widget with real-time activity feed
 *  - Reads from Audit Log table if that module is active
 *  - Maintains own simple table otherwise
 *  - AJAX auto-refresh at configurable interval
 *  - Filter tabs: all, content, users, plugins
 *  - Show last N items with "Load More"
 *
 * @package WPTransformed
 */
class Activity_Feed extends Module_Base {

    private const TABLE_SUFFIX   = 'wpt_activity_feed';
    private const DB_VERSION_KEY = 'wpt_activity_feed_db_version';
    private const DB_VERSION     = '1.0';

    /**
     * Audit-log action-to-category map for filtering.
     */
    private const AUDIT_LOG_CATEGORY_MAP = [
        'content' => [ 'post_created', 'post_updated', 'post_deleted', 'post_trashed', 'post_untrashed', 'attachment_added', 'attachment_deleted' ],
        'users'   => [ 'user_login', 'user_logout', 'user_registered', 'user_deleted', 'profile_updated' ],
        'plugins' => [ 'plugin_activated', 'plugin_deactivated', 'theme_switched' ],
    ];

    /**
     * Cached result of audit log active check.
     */
    private ?bool $audit_log_active = null;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'activity-feed';
    }

    public function get_title(): string {
        return __( 'Activity Feed', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Dashboard widget showing chronological site activity with auto-refresh and filtering.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'          => true,
            'max_items'        => 20,
            'refresh_interval' => 60,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Only create own table and hook events if Audit Log is NOT active.
        if ( ! $this->is_audit_log_active() ) {
            $this->maybe_create_table();
            $this->register_event_hooks();
        }

        // Dashboard widget.
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_activity_feed_fetch', [ $this, 'ajax_fetch' ] );
        add_action( 'wp_ajax_wpt_activity_feed_load_more', [ $this, 'ajax_load_more' ] );
    }

    // ── Audit Log Detection ──────────────────────────────────

    /**
     * Check whether the Audit Log module is active and its table exists.
     */
    private function is_audit_log_active(): bool {
        if ( $this->audit_log_active !== null ) {
            return $this->audit_log_active;
        }

        $registry = \WPTransformed\Core\Module_Registry::get_all();
        if ( ! isset( $registry['audit-log'] ) ) {
            $this->audit_log_active = false;
            return false;
        }

        $module_settings = \WPTransformed\Core\Settings::get( 'audit-log' );
        if ( empty( $module_settings ) ) {
            $this->audit_log_active = false;
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_audit_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->audit_log_active = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        return $this->audit_log_active;
    }

    // ── Table Creation ────────────────────────────────────────

    private function maybe_create_table(): void {
        $installed = get_option( self::DB_VERSION_KEY );
        if ( $installed === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(50) NOT NULL,
            object_title VARCHAR(255) DEFAULT '',
            category VARCHAR(20) DEFAULT 'content',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    // ── Event Hooks (only when Audit Log is NOT active) ──────

    private function register_event_hooks(): void {
        // Content events.
        add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
        add_action( 'delete_post', [ $this, 'on_delete_post' ] );
        add_action( 'wp_trash_post', [ $this, 'on_trash_post' ] );

        // User events.
        add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
        add_action( 'user_register', [ $this, 'on_user_register' ] );

        // Plugin events.
        add_action( 'activated_plugin', [ $this, 'on_activate_plugin' ] );
        add_action( 'deactivated_plugin', [ $this, 'on_deactivate_plugin' ] );
    }

    /**
     * Log a simple activity entry.
     */
    private function log_activity( string $action, string $object_title, string $category = 'content' ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table,
            [
                'user_id'      => get_current_user_id(),
                'action'       => $action,
                'object_title' => mb_substr( $object_title, 0, 255 ),
                'category'     => $category,
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );

        // Trim old entries beyond a reasonable limit (500).
        $settings  = $this->get_settings();
        $max_store = max( 500, (int) $settings['max_items'] * 5 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > $max_store ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY created_at DESC LIMIT %d) AS keep_rows)",
                    $max_store
                )
            );
        }
    }

    // ── Event Callbacks ──────────────────────────────────────

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     * @param bool     $update
     */
    public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( in_array( $post->post_type, [ 'nav_menu_item', 'revision', 'customize_changeset' ], true ) ) {
            return;
        }

        $action = $update ? 'post_updated' : 'post_created';
        $this->log_activity( $action, $post->post_title, 'content' );
    }

    public function on_delete_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || in_array( $post->post_type, [ 'nav_menu_item', 'revision' ], true ) ) {
            return;
        }
        $this->log_activity( 'post_deleted', $post->post_title, 'content' );
    }

    public function on_trash_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }
        $this->log_activity( 'post_trashed', $post->post_title, 'content' );
    }

    /**
     * @param string   $user_login
     * @param \WP_User $user
     */
    public function on_login( string $user_login, \WP_User $user ): void {
        $this->log_activity( 'user_login', $user->display_name, 'users' );
    }

    public function on_user_register( int $user_id ): void {
        $user = get_userdata( $user_id );
        $name = $user ? $user->display_name : (string) $user_id;
        $this->log_activity( 'user_registered', $name, 'users' );
    }

    public function on_activate_plugin( string $plugin ): void {
        $name = $this->get_plugin_name( $plugin );
        $this->log_activity( 'plugin_activated', $name, 'plugins' );
    }

    public function on_deactivate_plugin( string $plugin ): void {
        $name = $this->get_plugin_name( $plugin );
        $this->log_activity( 'plugin_deactivated', $name, 'plugins' );
    }

    /**
     * Get human-readable plugin name from the plugin file path.
     */
    private function get_plugin_name( string $plugin_file ): string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        return isset( $all[ $plugin_file ] ) ? $all[ $plugin_file ]['Name'] : basename( $plugin_file );
    }

    // ── Dashboard Widget ─────────────────────────────────────

    public function register_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'wpt_activity_feed',
            __( 'Activity Feed', 'wptransformed' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }

    public function render_dashboard_widget(): void {
        $settings = $this->get_settings();
        $nonce    = wp_create_nonce( 'wpt_activity_feed_nonce' );
        $interval = max( 10, (int) $settings['refresh_interval'] );
        $max      = max( 1, (int) $settings['max_items'] );
        ?>
        <div id="wpt-activity-feed-wrap">
            <div class="wpt-af-tabs">
                <button type="button" class="wpt-af-tab active" data-filter="all"><?php esc_html_e( 'All', 'wptransformed' ); ?></button>
                <button type="button" class="wpt-af-tab" data-filter="content"><?php esc_html_e( 'Content', 'wptransformed' ); ?></button>
                <button type="button" class="wpt-af-tab" data-filter="users"><?php esc_html_e( 'Users', 'wptransformed' ); ?></button>
                <button type="button" class="wpt-af-tab" data-filter="plugins"><?php esc_html_e( 'Plugins', 'wptransformed' ); ?></button>
            </div>
            <div id="wpt-activity-feed-list" class="wpt-af-list">
                <p class="wpt-af-loading"><?php esc_html_e( 'Loading...', 'wptransformed' ); ?></p>
            </div>
            <div class="wpt-af-footer">
                <button type="button" id="wpt-af-load-more" class="button button-small" style="display:none;">
                    <?php esc_html_e( 'Load More', 'wptransformed' ); ?>
                </button>
            </div>
        </div>
        <style>
            .wpt-af-tabs { display: flex; gap: 0; border-bottom: 1px solid #ccd0d4; margin-bottom: 12px; }
            .wpt-af-tab { background: none; border: none; border-bottom: 2px solid transparent; padding: 6px 12px; cursor: pointer; font-size: 13px; color: #50575e; }
            .wpt-af-tab:hover { color: #0073aa; }
            .wpt-af-tab.active { border-bottom-color: #0073aa; color: #0073aa; font-weight: 600; }
            .wpt-af-list { max-height: 400px; overflow-y: auto; }
            .wpt-af-item { display: flex; align-items: flex-start; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
            .wpt-af-item:last-child { border-bottom: none; }
            .wpt-af-avatar { flex-shrink: 0; }
            .wpt-af-avatar img { border-radius: 50%; }
            .wpt-af-content { flex: 1; }
            .wpt-af-meta { color: #787c82; font-size: 12px; }
            .wpt-af-action { font-weight: 500; }
            .wpt-af-empty { color: #787c82; text-align: center; padding: 20px 0; }
            .wpt-af-loading { text-align: center; color: #787c82; }
            .wpt-af-footer { text-align: center; padding-top: 8px; }
        </style>
        <script>
        (function() {
            var wrap     = document.getElementById('wpt-activity-feed-wrap');
            if (!wrap) return;

            var list     = document.getElementById('wpt-activity-feed-list');
            var loadMore = document.getElementById('wpt-af-load-more');
            var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var interval = <?php echo (int) $interval; ?> * 1000;
            var maxItems = <?php echo (int) $max; ?>;
            var filter   = 'all';
            var offset   = 0;
            var timer    = null;

            function fetchItems(append) {
                var body = new FormData();
                body.append('action', append ? 'wpt_activity_feed_load_more' : 'wpt_activity_feed_fetch');
                body.append('nonce', nonce);
                body.append('filter', filter);
                body.append('offset', append ? offset : 0);
                body.append('limit', maxItems);

                fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) return;
                        if (!append) {
                            list.innerHTML = '';
                            offset = 0;
                        }
                        if (resp.data.items.length === 0 && offset === 0) {
                            list.innerHTML = '<p class="wpt-af-empty">' + <?php echo wp_json_encode( esc_html__( 'No activity yet.', 'wptransformed' ) ); ?> + '</p>';
                        }
                        resp.data.items.forEach(function(item) {
                            var el = document.createElement('div');
                            el.className = 'wpt-af-item';
                            el.innerHTML = '<div class="wpt-af-avatar">' + item.avatar + '</div>'
                                + '<div class="wpt-af-content">'
                                + '<span class="wpt-af-action">' + item.user + '</span> '
                                + item.description
                                + '<div class="wpt-af-meta">' + item.time_ago + '</div>'
                                + '</div>';
                            list.appendChild(el);
                        });
                        offset += resp.data.items.length;
                        loadMore.style.display = resp.data.has_more ? '' : 'none';
                    })
                    .catch(function() {});
            }

            // Tab clicks.
            wrap.querySelectorAll('.wpt-af-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    wrap.querySelectorAll('.wpt-af-tab').forEach(function(t) { t.classList.remove('active'); });
                    tab.classList.add('active');
                    filter = tab.getAttribute('data-filter');
                    offset = 0;
                    fetchItems(false);
                });
            });

            // Load more.
            loadMore.addEventListener('click', function() { fetchItems(true); });

            // Initial load.
            fetchItems(false);

            // Auto-refresh.
            if (interval > 0) {
                timer = setInterval(function() { fetchItems(false); }, interval);
            }

            // Cleanup on page unload.
            window.addEventListener('beforeunload', function() { if (timer) clearInterval(timer); });
        })();
        </script>
        <?php
    }

    // ── AJAX Handlers ────────────────────────────────────────

    public function ajax_fetch(): void {
        check_ajax_referer( 'wpt_activity_feed_nonce', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $filter = isset( $_POST['filter'] ) ? sanitize_key( $_POST['filter'] ) : 'all';
        $limit  = isset( $_POST['limit'] ) ? min( 100, max( 1, absint( $_POST['limit'] ) ) ) : 20;

        $items = $this->query_activities( $filter, $limit, 0 );
        $total = $this->count_activities( $filter );

        wp_send_json_success( [
            'items'    => $items,
            'has_more' => $total > $limit,
        ] );
    }

    public function ajax_load_more(): void {
        check_ajax_referer( 'wpt_activity_feed_nonce', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $filter = isset( $_POST['filter'] ) ? sanitize_key( $_POST['filter'] ) : 'all';
        $limit  = isset( $_POST['limit'] ) ? min( 100, max( 1, absint( $_POST['limit'] ) ) ) : 20;
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;

        $items = $this->query_activities( $filter, $limit, $offset );
        $total = $this->count_activities( $filter );

        wp_send_json_success( [
            'items'    => $items,
            'has_more' => ( $offset + $limit ) < $total,
        ] );
    }

    // ── Query Helpers ────────────────────────────────────────

    /**
     * Query activities from the appropriate source (audit log or own table).
     *
     * @return array<int, array{user: string, avatar: string, description: string, time_ago: string}>
     */
    private function query_activities( string $filter, int $limit, int $offset ): array {
        global $wpdb;

        if ( $this->is_audit_log_active() ) {
            return $this->query_from_audit_log( $filter, $limit, $offset );
        }

        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $where = '';
        if ( $filter !== 'all' ) {
            $where = $wpdb->prepare( ' WHERE category = %s', $filter );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, action, object_title, created_at FROM {$table}{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return $this->format_rows( $rows ?: [] );
    }

    /**
     * Query from the shared audit log table.
     *
     * @return array<int, array{user: string, avatar: string, description: string, time_ago: string}>
     */
    private function query_from_audit_log( string $filter, int $limit, int $offset ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_audit_log';

        $where = '';

        if ( $filter !== 'all' && isset( self::AUDIT_LOG_CATEGORY_MAP[ $filter ] ) ) {
            $actions      = self::AUDIT_LOG_CATEGORY_MAP[ $filter ];
            $placeholders = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );
            $where = $wpdb->prepare( " WHERE action IN ({$placeholders})", ...$actions );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, action, object_title, created_at FROM {$table}{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return $this->format_rows( $rows ?: [] );
    }

    /**
     * Count total activities for pagination.
     */
    private function count_activities( string $filter ): int {
        global $wpdb;

        $use_audit = $this->is_audit_log_active();
        $table     = $use_audit ? $wpdb->prefix . 'wpt_audit_log' : $wpdb->prefix . self::TABLE_SUFFIX;

        // Unfiltered: count all rows.
        if ( $filter === 'all' ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        // Audit log: filter by action names.
        if ( $use_audit && isset( self::AUDIT_LOG_CATEGORY_MAP[ $filter ] ) ) {
            $actions      = self::AUDIT_LOG_CATEGORY_MAP[ $filter ];
            $placeholders = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action IN ({$placeholders})", ...$actions ) );
        }

        // Own table: filter by category column.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE category = %s", $filter ) );
    }

    /**
     * Format raw DB rows into display-ready items.
     *
     * @param  array<int, object> $rows
     * @return array<int, array{user: string, avatar: string, description: string, time_ago: string}>
     */
    private function format_rows( array $rows ): array {
        $items = [];
        foreach ( $rows as $row ) {
            $user_id = (int) $row->user_id;
            $user    = $user_id ? get_userdata( $user_id ) : null;
            $name    = $user ? $user->display_name : __( 'System', 'wptransformed' );
            $avatar  = get_avatar( $user_id, 32 );

            $items[] = [
                'user'        => esc_html( $name ),
                'avatar'      => $avatar,
                'description' => esc_html( $this->action_label( $row->action, $row->object_title ) ),
                'time_ago'    => esc_html( human_time_diff( strtotime( $row->created_at ), time() ) . ' ' . __( 'ago', 'wptransformed' ) ),
            ];
        }
        return $items;
    }

    /**
     * Human-readable label for an action.
     */
    private function action_label( string $action, string $object_title ): string {
        $labels = [
            'post_created'       => __( 'created', 'wptransformed' ),
            'post_updated'       => __( 'updated', 'wptransformed' ),
            'post_deleted'       => __( 'deleted', 'wptransformed' ),
            'post_trashed'       => __( 'trashed', 'wptransformed' ),
            'post_untrashed'     => __( 'restored', 'wptransformed' ),
            'user_login'         => __( 'logged in', 'wptransformed' ),
            'user_logout'        => __( 'logged out', 'wptransformed' ),
            'user_registered'    => __( 'registered', 'wptransformed' ),
            'user_deleted'       => __( 'was deleted', 'wptransformed' ),
            'profile_updated'    => __( 'profile updated', 'wptransformed' ),
            'plugin_activated'   => __( 'activated plugin', 'wptransformed' ),
            'plugin_deactivated' => __( 'deactivated plugin', 'wptransformed' ),
            'theme_switched'     => __( 'switched theme to', 'wptransformed' ),
            'attachment_added'   => __( 'uploaded', 'wptransformed' ),
            'attachment_deleted' => __( 'deleted media', 'wptransformed' ),
        ];

        $label = $labels[ $action ] ?? $action;
        if ( $object_title !== '' ) {
            /* translators: 1: action label, 2: object title */
            return sprintf( '%1$s "%2$s"', $label, $object_title );
        }
        return $label;
    }

    // ── Admin UI (Settings) ──────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_af_max_items"><?php esc_html_e( 'Items to Show', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt_af_max_items" name="wpt_af_max_items"
                           value="<?php echo esc_attr( (string) $settings['max_items'] ); ?>"
                           min="5" max="100" step="1" class="small-text">
                    <p class="description"><?php esc_html_e( 'Number of activity items to display at once (5-100).', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt_af_refresh_interval"><?php esc_html_e( 'Auto-Refresh Interval', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt_af_refresh_interval" name="wpt_af_refresh_interval"
                           value="<?php echo esc_attr( (string) $settings['refresh_interval'] ); ?>"
                           min="10" max="600" step="1" class="small-text">
                    <p class="description"><?php esc_html_e( 'Seconds between auto-refresh (10-600, 0 to disable).', 'wptransformed' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'          => true,
            'max_items'        => isset( $raw['wpt_af_max_items'] ) ? max( 5, min( 100, absint( $raw['wpt_af_max_items'] ) ) ) : 20,
            'refresh_interval' => isset( $raw['wpt_af_refresh_interval'] ) ? max( 0, min( 600, absint( $raw['wpt_af_refresh_interval'] ) ) ) : 60,
        ];
    }

    // ── Cleanup ──────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        global $wpdb;
        return [
            'tables'  => [ $wpdb->prefix . self::TABLE_SUFFIX ],
            'options' => [ self::DB_VERSION_KEY ],
        ];
    }
}
