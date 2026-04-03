<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * 404 Monitor -- Log and analyze incoming 404 requests.
 *
 * Features:
 *  - Logs 404 URLs with referrer, user agent, hashed IP
 *  - Groups by URL with hit count and first/last seen timestamps
 *  - Dashboard widget: top 10 404s
 *  - Admin settings page: full table with search and dismiss
 *  - "Create Redirect" integration with Redirect Manager if active
 *  - Bot exclusion via user agent detection
 *  - Configurable path exclusions
 *  - Daily cron to prune old entries
 *
 * Distinct from Broken Link Checker: BLC scans outgoing links.
 * 404 Monitor logs incoming 404 requests to the site.
 *
 * @package WPTransformed
 */
class Four_Oh_Four_Monitor extends Module_Base {

    private const TABLE_SUFFIX   = 'wpt_404_log';
    private const DB_VERSION_KEY = 'wpt_404_monitor_db_version';
    private const DB_VERSION     = '1.0';
    private const CRON_PRUNE     = 'wpt_404_monitor_prune';

    /**
     * Common bot user agent fragments.
     *
     * @var string[]
     */
    private const BOT_SIGNATURES = [
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
        'yandexbot', 'sogou', 'exabot', 'ia_archiver', 'facebot',
        'facebookexternalhit', 'twitterbot', 'rogerbot', 'linkedinbot',
        'embedly', 'showyoubot', 'outbrain', 'semrushbot', 'ahrefsbot',
        'mj12bot', 'dotbot', 'petalbot', 'bytespider',
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return '404-monitor';
    }

    public function get_title(): string {
        return __( '404 Monitor', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Log and analyze incoming 404 requests to find broken URLs and missing content.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'        => true,
            'retention_days' => 30,
            'exclude_bots'   => true,
            'exclude_paths'  => [ '/wp-login.php', '/xmlrpc.php' ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $this->maybe_create_table();

        // Log 404s on the frontend.
        add_action( 'template_redirect', [ $this, 'log_404' ] );

        // Dashboard widget.
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );

        // Cron for pruning.
        if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_PRUNE );
        }
        add_action( self::CRON_PRUNE, [ $this, 'prune_old_entries' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_404_fetch', [ $this, 'ajax_fetch' ] );
        add_action( 'wp_ajax_wpt_404_dismiss', [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_wpt_404_delete', [ $this, 'ajax_delete' ] );
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_PRUNE );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_PRUNE );
        }
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
            url VARCHAR(2048) NOT NULL,
            url_hash CHAR(64) NOT NULL,
            referrer VARCHAR(2048) DEFAULT '',
            user_agent VARCHAR(512) DEFAULT '',
            ip_hash CHAR(64) DEFAULT '',
            count INT UNSIGNED DEFAULT 1,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            is_dismissed TINYINT(1) DEFAULT 0,
            INDEX idx_url_hash (url_hash),
            INDEX idx_last_seen (last_seen),
            INDEX idx_count (count),
            INDEX idx_dismissed (is_dismissed)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    // ── 404 Logging ──────────────────────────────────────────

    /**
     * Log a 404 request via upsert on URL hash.
     */
    public function log_404(): void {
        if ( ! is_404() ) {
            return;
        }

        // Skip admin, REST, and AJAX requests.
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $settings   = $this->get_settings();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        // Exclude bots.
        if ( ! empty( $settings['exclude_bots'] ) && $this->is_bot( $user_agent ) ) {
            return;
        }

        // Exclude specific paths.
        $exclude_paths = (array) ( $settings['exclude_paths'] ?? [] );
        foreach ( $exclude_paths as $path ) {
            if ( $path !== '' && strpos( $request_uri, $path ) === 0 ) {
                return;
            }
        }

        $url      = home_url( $request_uri );
        $url_hash = hash( 'sha256', $url );
        $referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
        $ip_raw   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ip_hash  = wp_hash( $ip_raw );
        $now      = current_time( 'mysql', true );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Check for existing entry by URL hash.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s LIMIT 1", $url_hash )
        );

        if ( $existing ) {
            // Update count and last_seen.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET count = count + 1, last_seen = %s, referrer = %s, user_agent = %s, ip_hash = %s WHERE url_hash = %s",
                    $now,
                    mb_substr( $referrer, 0, 2048 ),
                    mb_substr( $user_agent, 0, 512 ),
                    $ip_hash,
                    $url_hash
                )
            );
        } else {
            // Insert new entry.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $table,
                [
                    'url'        => mb_substr( $url, 0, 2048 ),
                    'url_hash'   => $url_hash,
                    'referrer'   => mb_substr( $referrer, 0, 2048 ),
                    'user_agent' => mb_substr( $user_agent, 0, 512 ),
                    'ip_hash'    => $ip_hash,
                    'count'      => 1,
                    'first_seen' => $now,
                    'last_seen'  => $now,
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );
        }
    }

    /**
     * Detect bots by user agent.
     */
    private function is_bot( string $user_agent ): bool {
        $ua_lower = strtolower( $user_agent );
        foreach ( self::BOT_SIGNATURES as $sig ) {
            if ( strpos( $ua_lower, $sig ) !== false ) {
                return true;
            }
        }
        return false;
    }

    // ── Cron Pruning ─────────────────────────────────────────

    /**
     * Remove entries older than retention_days.
     */
    public function prune_old_entries(): void {
        $settings       = $this->get_settings();
        $retention_days = max( 1, (int) ( $settings['retention_days'] ?? 30 ) );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $retention_days
            )
        );
    }

    // ── Dashboard Widget ─────────────────────────────────────

    public function register_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'wpt_404_monitor',
            __( '404 Monitor - Top Hits', 'wptransformed' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }

    public function render_dashboard_widget(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT url, count, last_seen FROM {$table} WHERE is_dismissed = 0 ORDER BY count DESC LIMIT 10"
        );

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No 404 errors recorded yet.', 'wptransformed' ) . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'URL', 'wptransformed' ) . '</th>';
        echo '<th>' . esc_html__( 'Hits', 'wptransformed' ) . '</th>';
        echo '<th>' . esc_html__( 'Last Seen', 'wptransformed' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $path = wp_parse_url( $row->url, PHP_URL_PATH ) ?: $row->url;
            echo '<tr>';
            echo '<td title="' . esc_attr( $row->url ) . '"><code>' . esc_html( mb_substr( $path, 0, 60 ) ) . '</code></td>';
            echo '<td>' . esc_html( number_format_i18n( (int) $row->count ) ) . '</td>';
            echo '<td>' . esc_html( human_time_diff( strtotime( $row->last_seen ), time() ) . ' ' . __( 'ago', 'wptransformed' ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // ── AJAX Handlers ────────────────────────────────────────

    public function ajax_fetch(): void {
        check_ajax_referer( 'wpt_404_monitor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $page   = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $show   = isset( $_POST['show'] ) ? sanitize_key( $_POST['show'] ) : 'active';
        $per    = 20;
        $offset = ( $page - 1 ) * $per;

        $where  = [];
        $values = [];

        if ( $show === 'active' ) {
            $where[] = 'is_dismissed = 0';
        } elseif ( $show === 'dismissed' ) {
            $where[] = 'is_dismissed = 1';
        }

        if ( $search !== '' ) {
            $where[]  = 'url LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Count.
        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$values ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
        }

        // Fetch rows.
        $query_values = array_merge( $values, [ $per, $offset ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, referrer, count, first_seen, last_seen, is_dismissed FROM {$table} {$where_sql} ORDER BY count DESC, last_seen DESC LIMIT %d OFFSET %d",
                ...$query_values
            )
        );

        $items = [];
        foreach ( ( $rows ?: [] ) as $row ) {
            $items[] = [
                'id'           => (int) $row->id,
                'url'          => esc_url( $row->url ),
                'path'         => esc_html( wp_parse_url( $row->url, PHP_URL_PATH ) ?: $row->url ),
                'referrer'     => esc_url( $row->referrer ),
                'count'        => (int) $row->count,
                'first_seen'   => esc_html( $row->first_seen ),
                'last_seen'    => esc_html( $row->last_seen ),
                'is_dismissed' => (bool) $row->is_dismissed,
            ];
        }

        wp_send_json_success( [
            'items'       => $items,
            'total'       => $total,
            'pages'       => (int) ceil( $total / $per ),
            'current'     => $page,
            'has_redirect' => $this->is_redirect_manager_active(),
        ] );
    }

    public function ajax_dismiss(): void {
        check_ajax_referer( 'wpt_404_monitor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update( $table, [ 'is_dismissed' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        wp_send_json_success();
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'wpt_404_monitor_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    // ── Redirect Manager Integration ─────────────────────────

    /**
     * Check if the Redirect Manager module is active.
     */
    private function is_redirect_manager_active(): bool {
        $registry = \WPTransformed\Core\Module_Registry::get_all();
        if ( ! isset( $registry['redirect-manager'] ) ) {
            return false;
        }

        $module_settings = \WPTransformed\Core\Settings::get( 'redirect-manager' );
        return ! empty( $module_settings );
    }

    // ── Admin UI (Settings) ──────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $nonce    = wp_create_nonce( 'wpt_404_monitor_nonce' );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_404_retention"><?php esc_html_e( 'Retention Period', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt_404_retention" name="wpt_404_retention"
                           value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>"
                           min="1" max="365" step="1" class="small-text">
                    <?php esc_html_e( 'days', 'wptransformed' ); ?>
                    <p class="description"><?php esc_html_e( 'Entries older than this are automatically pruned.', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Exclude Bots', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_404_exclude_bots" value="1"
                               <?php checked( ! empty( $settings['exclude_bots'] ) ); ?>>
                        <?php esc_html_e( 'Ignore requests from known bots and crawlers.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt_404_exclude_paths"><?php esc_html_e( 'Exclude Paths', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt_404_exclude_paths" name="wpt_404_exclude_paths" rows="4" cols="50"
                              class="large-text"><?php
                        echo esc_textarea( implode( "\n", (array) $settings['exclude_paths'] ) );
                    ?></textarea>
                    <p class="description"><?php esc_html_e( 'One path per line (e.g., /wp-login.php). Requests starting with these paths will be ignored.', 'wptransformed' ); ?></p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( '404 Log', 'wptransformed' ); ?></h3>
        <div id="wpt-404-monitor-wrap">
            <div class="wpt-404-toolbar" style="display:flex;gap:8px;margin-bottom:12px;align-items:center;">
                <input type="text" id="wpt-404-search" placeholder="<?php esc_attr_e( 'Search URLs...', 'wptransformed' ); ?>" class="regular-text">
                <select id="wpt-404-filter">
                    <option value="active"><?php esc_html_e( 'Active', 'wptransformed' ); ?></option>
                    <option value="dismissed"><?php esc_html_e( 'Dismissed', 'wptransformed' ); ?></option>
                    <option value="all"><?php esc_html_e( 'All', 'wptransformed' ); ?></option>
                </select>
                <button type="button" id="wpt-404-refresh" class="button"><?php esc_html_e( 'Refresh', 'wptransformed' ); ?></button>
            </div>
            <table class="widefat striped" id="wpt-404-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'URL', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Hits', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Referrer', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Last Seen', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody id="wpt-404-body">
                    <tr><td colspan="5"><?php esc_html_e( 'Loading...', 'wptransformed' ); ?></td></tr>
                </tbody>
            </table>
            <div id="wpt-404-pagination" style="margin-top:8px;text-align:center;"></div>
        </div>
        <script>
        (function() {
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var body    = document.getElementById('wpt-404-body');
            var pagi    = document.getElementById('wpt-404-pagination');
            var hasRm   = false;

            function load(page) {
                page = page || 1;
                var fd = new FormData();
                fd.append('action', 'wpt_404_fetch');
                fd.append('nonce', nonce);
                fd.append('page', page);
                fd.append('search', document.getElementById('wpt-404-search').value);
                fd.append('show', document.getElementById('wpt-404-filter').value);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) return;
                        hasRm = resp.data.has_redirect;
                        body.innerHTML = '';
                        if (resp.data.items.length === 0) {
                            body.innerHTML = '<tr><td colspan="5">' + <?php echo wp_json_encode( esc_html__( 'No 404 entries found.', 'wptransformed' ) ); ?> + '</td></tr>';
                        }
                        resp.data.items.forEach(function(item) {
                            var tr = document.createElement('tr');
                            var actions = '<button type="button" class="button button-small wpt-404-dismiss" data-id="' + item.id + '">' + <?php echo wp_json_encode( esc_html__( 'Dismiss', 'wptransformed' ) ); ?> + '</button> ';
                            actions += '<button type="button" class="button button-small wpt-404-delete" data-id="' + item.id + '">' + <?php echo wp_json_encode( esc_html__( 'Delete', 'wptransformed' ) ); ?> + '</button>';
                            if (hasRm) {
                                actions += ' <a href="' + <?php echo wp_json_encode( esc_url( admin_url( 'admin.php?page=wptransformed&module=redirect-manager' ) ) ); ?> + '&from=' + encodeURIComponent(item.path) + '" class="button button-small button-primary">' + <?php echo wp_json_encode( esc_html__( 'Create Redirect', 'wptransformed' ) ); ?> + '</a>';
                            }
                            tr.innerHTML = '<td title="' + item.url + '"><code>' + item.path + '</code></td>'
                                + '<td>' + item.count + '</td>'
                                + '<td>' + (item.referrer ? '<a href="' + item.referrer + '" target="_blank" rel="noopener">' + item.referrer.substring(0, 40) + '</a>' : '&mdash;') + '</td>'
                                + '<td>' + item.last_seen + '</td>'
                                + '<td>' + actions + '</td>';
                            body.appendChild(tr);
                        });
                        // Pagination.
                        pagi.innerHTML = '';
                        if (resp.data.pages > 1) {
                            for (var i = 1; i <= resp.data.pages; i++) {
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'button button-small' + (i === resp.data.current ? ' button-primary' : '');
                                btn.textContent = i;
                                btn.setAttribute('data-page', i);
                                btn.addEventListener('click', function() { load(parseInt(this.getAttribute('data-page'))); });
                                pagi.appendChild(btn);
                                pagi.appendChild(document.createTextNode(' '));
                            }
                        }
                    });
            }

            // Delegate dismiss/delete clicks.
            document.getElementById('wpt-404-monitor-wrap').addEventListener('click', function(e) {
                var btn = e.target.closest('.wpt-404-dismiss, .wpt-404-delete');
                if (!btn) return;
                var action = btn.classList.contains('wpt-404-dismiss') ? 'wpt_404_dismiss' : 'wpt_404_delete';
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', nonce);
                fd.append('id', btn.getAttribute('data-id'));
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function() { load(); });
            });

            document.getElementById('wpt-404-refresh').addEventListener('click', function() { load(); });
            document.getElementById('wpt-404-search').addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); load(); } });
            document.getElementById('wpt-404-filter').addEventListener('change', function() { load(); });

            load();
        })();
        </script>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $exclude_paths = [];
        if ( isset( $raw['wpt_404_exclude_paths'] ) ) {
            $lines = explode( "\n", sanitize_textarea_field( wp_unslash( $raw['wpt_404_exclude_paths'] ) ) );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( $line !== '' ) {
                    $exclude_paths[] = $line;
                }
            }
        }

        return [
            'enabled'        => true,
            'retention_days' => isset( $raw['wpt_404_retention'] ) ? max( 1, min( 365, absint( $raw['wpt_404_retention'] ) ) ) : 30,
            'exclude_bots'   => ! empty( $raw['wpt_404_exclude_bots'] ),
            'exclude_paths'  => $exclude_paths,
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
