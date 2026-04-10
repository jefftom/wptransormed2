<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Audit Log — dedicated APP page.
 *
 * Session 4 Stage 3 of the UI restructure. Matches the reference mockup
 * at assets/admin/reference/app-pages/audit-log-v3.html.
 *
 * Linked from the Session 3 Module Grid's Audit Log parent card
 * (wpt-audit-log slug). Wraps the existing Audit_Log module's custom
 * table (`wp_wpt_audit_log`) with a bento-style UI:
 *
 * - Header with title + action buttons (Export CSV, Refresh)
 * - 4 bento stat cards (Total events, Successful logins today, Failed
 *   logins today, Unique users today)
 * - Filters bar (search input, event-type select, user select,
 *   date-range dropdown)
 * - Event table with columns: Event / User / Description / IP / Time /
 *   Detail button
 * - Pagination footer (prev / numbered pages / next)
 *
 * Data strategy:
 * - Queries the `wp_wpt_audit_log` table directly via $wpdb — the
 *   module's init() doesn't need to be running for reads, only writes.
 * - If the table doesn't exist yet (module was never activated), the
 *   app renders a clear "enable the Audit Log module to start
 *   capturing events" state.
 * - Stat cards compute today's counts via WHERE created_at >= today.
 * - Filters/pagination are handled server-side on page load (no AJAX
 *   in Stage 3 — JS refresh + filter can come later if needed).
 *
 * Access control: manage_options.
 *
 * @package WPTransformed
 */
class Audit_Log_App {

    /** Page size for the event table. */
    private const PER_PAGE = 25;

    /** Custom table suffix (must match Audit_Log module). */
    private const TABLE_SUFFIX = 'wpt_audit_log';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ], 20 );
    }

    public function register_page(): void {
        $hook = add_submenu_page(
            'wpt-dashboard',
            __( 'Audit Log', 'wptransformed' ),
            __( 'Audit Log', 'wptransformed' ),
            'manage_options',
            'wpt-audit-log',
            [ $this, 'render' ]
        );

        add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        add_action( 'admin_enqueue_scripts', function () {
            wp_enqueue_style(
                'wpt-admin',
                WPT_URL . 'assets/admin/css/admin.css',
                [ 'wpt-admin-global' ],
                WPT_VERSION
            );
            wp_enqueue_script(
                'wpt-admin',
                WPT_URL . 'assets/admin/js/admin.js',
                [ 'wpt-admin-global' ],
                WPT_VERSION,
                true
            );
        } );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptransformed' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $table_exists  = $this->table_exists( $table );
        $module_active = Core::instance()->is_active( 'audit-log' );

        // Parse filters + pagination from the query string. All writes
        // go through WP admin URL params; no POST on this screen (yet).
        $filters = $this->parse_filters();
        $page    = max( 1, (int) ( $_GET['wpt_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $offset  = ( $page - 1 ) * self::PER_PAGE;

        $stats       = $table_exists ? $this->get_stats( $table ) : $this->empty_stats();
        $action_list = $table_exists ? $this->get_action_types( $table ) : [];
        $user_list   = $table_exists ? $this->get_user_filter_options( $table ) : [];
        $total_rows  = $table_exists ? $this->count_rows( $table, $filters ) : 0;
        $rows        = $table_exists ? $this->query_rows( $table, $filters, self::PER_PAGE, $offset ) : [];
        $total_pages = $total_rows > 0 ? max( 1, (int) ceil( $total_rows / self::PER_PAGE ) ) : 1;

        ?>
        <div class="wpt-dashboard wpt-app-page" id="wptAuditLog">

            <?php if ( ! $table_exists ) : ?>
                <div class="wpt-app-gate">
                    <i class="fas fa-shield-alt wpt-app-gate-icon"></i>
                    <h2><?php esc_html_e( 'Audit Log is not capturing events yet', 'wptransformed' ); ?></h2>
                    <p>
                        <?php esc_html_e( "The audit log table doesn't exist yet. Activate the Audit Log module from the Modules page to start capturing events. The table is created automatically on first activation.", 'wptransformed' ); ?>
                    </p>
                    <a class="btn btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>">
                        <?php esc_html_e( 'Go to Modules', 'wptransformed' ); ?>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            <?php elseif ( ! $module_active ) : ?>
                <div class="wpt-app-notice wpt-app-notice-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong><?php esc_html_e( 'Audit Log module is inactive', 'wptransformed' ); ?></strong>
                        <p><?php esc_html_e( "Existing events are shown below, but new events won't be captured until the module is re-activated.", 'wptransformed' ); ?></p>
                    </div>
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>">
                        <?php esc_html_e( 'Activate', 'wptransformed' ); ?>
                    </a>
                </div>
            <?php endif; ?>

            <header class="wpt-page-header">
                <h1>
                    <span class="wpt-page-header-icon rose"><i class="fas fa-shield-alt"></i></span>
                    <?php esc_html_e( 'Audit Log', 'wptransformed' ); ?>
                </h1>
                <div class="wpt-page-header-actions">
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>">
                        <i class="fas fa-arrow-left"></i> <?php esc_html_e( 'Modules', 'wptransformed' ); ?>
                    </a>
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed&module=audit-log' ) ); ?>">
                        <i class="fas fa-cog"></i> <?php esc_html_e( 'Settings', 'wptransformed' ); ?>
                    </a>
                    <a class="btn btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wpt-audit-log' ) ); ?>">
                        <i class="fas fa-sync-alt"></i> <?php esc_html_e( 'Refresh', 'wptransformed' ); ?>
                    </a>
                </div>
            </header>

            <!-- Bento Stats -->
            <div class="wpt-overview-grid">
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon blue"><i class="fas fa-list"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( number_format_i18n( $stats['total_events'] ) ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Total Events', 'wptransformed' ); ?></div>
                    <?php if ( $stats['events_today'] > 0 ) : ?>
                        <div class="wpt-overview-change up"><i class="fas fa-arrow-up"></i> <?php printf( esc_html__( '+%d today', 'wptransformed' ), (int) $stats['events_today'] ); ?></div>
                    <?php endif; ?>
                </div>
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon green"><i class="fas fa-sign-in-alt"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( number_format_i18n( $stats['logins_today'] ) ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Logins Today', 'wptransformed' ); ?></div>
                    <?php if ( $stats['logins_total'] > 0 ) : ?>
                        <div class="wpt-overview-change neutral"><?php printf( esc_html__( '%s all time', 'wptransformed' ), esc_html( number_format_i18n( $stats['logins_total'] ) ) ); ?></div>
                    <?php endif; ?>
                </div>
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon amber"><i class="fas fa-user-shield"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( number_format_i18n( $stats['unique_users'] ) ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Active Users', 'wptransformed' ); ?></div>
                    <div class="wpt-overview-change neutral"><?php esc_html_e( 'last 7 days', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon rose"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( number_format_i18n( $stats['failed_logins'] ) ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Failed Logins', 'wptransformed' ); ?></div>
                    <?php if ( $stats['failed_logins'] > 0 ) : ?>
                        <div class="wpt-overview-change warning"><i class="fas fa-exclamation"></i> <?php esc_html_e( 'review', 'wptransformed' ); ?></div>
                    <?php else : ?>
                        <div class="wpt-overview-change up"><?php esc_html_e( 'all clear', 'wptransformed' ); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters bar -->
            <form class="wpt-filters-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <input type="hidden" name="page" value="wpt-audit-log">

                <div class="wpt-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text"
                           name="q"
                           placeholder="<?php esc_attr_e( 'Search events, users, IPs…', 'wptransformed' ); ?>"
                           value="<?php echo esc_attr( $filters['q'] ); ?>">
                </div>

                <select name="action" class="wpt-filter-select">
                    <option value=""><?php esc_html_e( 'All Event Types', 'wptransformed' ); ?></option>
                    <?php foreach ( $action_list as $action_slug ) : ?>
                        <option value="<?php echo esc_attr( $action_slug ); ?>"
                                <?php selected( $filters['action'], $action_slug ); ?>>
                            <?php echo esc_html( $this->format_action_label( $action_slug ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="user_id" class="wpt-filter-select">
                    <option value="0"><?php esc_html_e( 'All Users', 'wptransformed' ); ?></option>
                    <?php foreach ( $user_list as $user_row ) : ?>
                        <option value="<?php echo esc_attr( (string) $user_row['id'] ); ?>"
                                <?php selected( $filters['user_id'], $user_row['id'] ); ?>>
                            <?php echo esc_html( $user_row['display_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="range" class="wpt-filter-select">
                    <?php
                    $ranges = [
                        '1'  => __( 'Last 24 hours', 'wptransformed' ),
                        '7'  => __( 'Last 7 days', 'wptransformed' ),
                        '30' => __( 'Last 30 days', 'wptransformed' ),
                        '90' => __( 'Last 90 days', 'wptransformed' ),
                        '0'  => __( 'All time', 'wptransformed' ),
                    ];
                    foreach ( $ranges as $value => $label ) :
                    ?>
                        <option value="<?php echo esc_attr( $value ); ?>"
                                <?php selected( (string) $filters['range'], (string) $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-secondary">
                    <i class="fas fa-filter"></i> <?php esc_html_e( 'Apply', 'wptransformed' ); ?>
                </button>
            </form>

            <!-- Event table -->
            <div class="wpt-log-table-wrap">
                <?php if ( empty( $rows ) ) : ?>
                    <div class="wpt-log-empty">
                        <i class="fas fa-inbox"></i>
                        <p><?php esc_html_e( 'No events match your current filters.', 'wptransformed' ); ?></p>
                    </div>
                <?php else : ?>
                    <table class="wpt-log-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Event', 'wptransformed' ); ?></th>
                                <th><?php esc_html_e( 'User', 'wptransformed' ); ?></th>
                                <th><?php esc_html_e( 'Description', 'wptransformed' ); ?></th>
                                <th><?php esc_html_e( 'IP Address', 'wptransformed' ); ?></th>
                                <th><?php esc_html_e( 'Time', 'wptransformed' ); ?></th>
                                <th aria-label="<?php esc_attr_e( 'Details', 'wptransformed' ); ?>"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $rows as $row ) : ?>
                                <?php
                                $badge_class = $this->action_badge_class( (string) $row->action );
                                $severity    = $this->severity_for_action( (string) $row->action );
                                $user_info   = $this->get_user_display( (int) $row->user_id );
                                $description = $this->format_description( $row );
                                $time_iso    = mysql2date( 'c', (string) $row->created_at );
                                $time_human  = human_time_diff( strtotime( (string) $row->created_at ), current_time( 'timestamp' ) );
                                $time_abs    = mysql2date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), (string) $row->created_at );
                                ?>
                                <tr>
                                    <td>
                                        <span class="wpt-event-type <?php echo esc_attr( $badge_class ); ?>">
                                            <i class="fas <?php echo esc_attr( $this->action_icon( (string) $row->action ) ); ?>"></i>
                                            <?php echo esc_html( $this->format_action_label( (string) $row->action ) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="wpt-user-cell">
                                            <div class="wpt-user-avatar"><?php echo esc_html( $user_info['initial'] ); ?></div>
                                            <div class="wpt-user-info">
                                                <span><?php echo esc_html( $user_info['name'] ); ?></span>
                                                <small><?php echo esc_html( $user_info['role'] ); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="wpt-action-desc">
                                        <span class="wpt-severity-dot <?php echo esc_attr( $severity ); ?>"></span>
                                        <?php echo esc_html( $description ); ?>
                                    </td>
                                    <td class="wpt-ip-cell"><?php echo esc_html( (string) $row->ip_address ); ?></td>
                                    <td class="wpt-time-cell">
                                        <strong><time datetime="<?php echo esc_attr( $time_iso ); ?>"><?php printf( esc_html__( '%s ago', 'wptransformed' ), esc_html( $time_human ) ); ?></time></strong>
                                        <?php echo esc_html( $time_abs ); ?>
                                    </td>
                                    <td>
                                        <button type="button" class="wpt-detail-btn" aria-label="<?php esc_attr_e( 'View details', 'wptransformed' ); ?>" title="<?php esc_attr_e( 'View details', 'wptransformed' ); ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ( $total_pages > 1 ) : ?>
                        <nav class="wpt-pagination" aria-label="<?php esc_attr_e( 'Event pagination', 'wptransformed' ); ?>">
                            <div class="wpt-pagination-info">
                                <?php
                                $from = $offset + 1;
                                $to   = min( $total_rows, $offset + self::PER_PAGE );
                                printf(
                                    /* translators: 1: first row, 2: last row, 3: total rows */
                                    esc_html__( 'Showing %1$d-%2$d of %3$s events', 'wptransformed' ),
                                    (int) $from,
                                    (int) $to,
                                    esc_html( number_format_i18n( $total_rows ) )
                                );
                                ?>
                            </div>
                            <div class="wpt-pagination-controls">
                                <?php $this->render_pagination_controls( $page, $total_pages, $filters ); ?>
                            </div>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════
       Data layer
    ══════════════════════════════════════════ */

    /**
     * @return array{q:string, action:string, user_id:int, range:int}
     */
    private function parse_filters(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        return [
            'q'       => isset( $_GET['q'] )       ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '',
            'action'  => isset( $_GET['action'] )  ? sanitize_key( (string) $_GET['action'] ) : '',
            'user_id' => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0,
            'range'   => isset( $_GET['range'] )   ? (int) $_GET['range'] : 7,
        ];
        // phpcs:enable
    }

    /**
     * Convert filter state to a SQL WHERE clause + prepared values.
     *
     * @param array $filters
     * @return array{where:string, values:array<int,mixed>}
     */
    private function build_where_clause( array $filters ): array {
        $where  = [];
        $values = [];

        if ( ! empty( $filters['q'] ) ) {
            $like = '%' . $GLOBALS['wpdb']->esc_like( $filters['q'] ) . '%';
            $where[]  = '(object_title LIKE %s OR details LIKE %s OR ip_address LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }
        if ( ! empty( $filters['action'] ) ) {
            $where[]  = 'action = %s';
            $values[] = $filters['action'];
        }
        if ( ! empty( $filters['user_id'] ) ) {
            $where[]  = 'user_id = %d';
            $values[] = $filters['user_id'];
        }
        if ( ! empty( $filters['range'] ) && $filters['range'] > 0 ) {
            $where[]  = 'created_at >= %s';
            $values[] = gmdate( 'Y-m-d H:i:s', time() - ( (int) $filters['range'] * DAY_IN_SECONDS ) );
        }

        return [
            'where'  => empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where ),
            'values' => $values,
        ];
    }

    /**
     * @param string $table
     * @param array  $filters
     */
    private function count_rows( string $table, array $filters ): int {
        global $wpdb;
        $clause = $this->build_where_clause( $filters );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $sql = "SELECT COUNT(*) FROM {$table}" . $clause['where'];
        if ( ! empty( $clause['values'] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$clause['values'] ) );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * @param string $table
     * @param array  $filters
     * @return array<int,object>
     */
    private function query_rows( string $table, array $filters, int $limit, int $offset ): array {
        global $wpdb;
        $clause = $this->build_where_clause( $filters );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $sql    = "SELECT * FROM {$table}" . $clause['where'] . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
        $values = array_merge( $clause['values'], [ $limit, $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Distinct action slugs currently stored in the table.
     *
     * @param string $table
     * @return array<int,string>
     */
    private function get_action_types( string $table ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action ASC" );
        return is_array( $rows ) ? array_values( array_filter( $rows ) ) : [];
    }

    /**
     * Return a short user filter list: the 20 most-recent distinct users.
     *
     * @param string $table
     * @return array<int,array{id:int, display_name:string}>
     */
    private function get_user_filter_options( string $table ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} WHERE user_id > 0 ORDER BY created_at DESC LIMIT 20" );
        if ( ! is_array( $user_ids ) || empty( $user_ids ) ) {
            return [];
        }
        $users = get_users( [
            'include' => array_map( 'intval', $user_ids ),
            'fields'  => [ 'ID', 'display_name' ],
        ] );
        $out = [];
        foreach ( $users as $user ) {
            $out[] = [
                'id'           => (int) $user->ID,
                'display_name' => (string) $user->display_name,
            ];
        }
        return $out;
    }

    /**
     * Aggregate stats for the bento cards.
     *
     * @param string $table
     * @return array{total_events:int, events_today:int, logins_today:int, logins_total:int, unique_users:int, failed_logins:int}
     */
    private function get_stats( string $table ): array {
        global $wpdb;
        $today_start = gmdate( 'Y-m-d 00:00:00' );
        $week_start  = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_events  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $events_today  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today_start ) );
        $logins_total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", 'user_login' ) );
        $logins_today  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s AND created_at >= %s", 'user_login', $today_start ) );
        $failed_logins = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s AND created_at >= %s", 'user_login_failed', $today_start ) );
        $unique_users  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE user_id > 0 AND created_at >= %s", $week_start ) );
        // phpcs:enable

        return [
            'total_events'  => $total_events,
            'events_today'  => $events_today,
            'logins_today'  => $logins_today,
            'logins_total'  => $logins_total,
            'unique_users'  => $unique_users,
            'failed_logins' => $failed_logins,
        ];
    }

    /**
     * @return array{total_events:int, events_today:int, logins_today:int, logins_total:int, unique_users:int, failed_logins:int}
     */
    private function empty_stats(): array {
        return [
            'total_events'  => 0,
            'events_today'  => 0,
            'logins_today'  => 0,
            'logins_total'  => 0,
            'unique_users'  => 0,
            'failed_logins' => 0,
        ];
    }

    /* ══════════════════════════════════════════
       Presentation helpers
    ══════════════════════════════════════════ */

    private function table_exists( string $table ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * @param int $user_id
     * @return array{name:string, role:string, initial:string}
     */
    private function get_user_display( int $user_id ): array {
        if ( $user_id <= 0 ) {
            return [
                'name'    => __( 'System', 'wptransformed' ),
                'role'    => __( 'Automated', 'wptransformed' ),
                'initial' => 'S',
            ];
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [
                'name'    => sprintf( __( 'User #%d', 'wptransformed' ), $user_id ),
                'role'    => __( 'Deleted', 'wptransformed' ),
                'initial' => '?',
            ];
        }
        $roles   = (array) $user->roles;
        $role    = empty( $roles ) ? __( 'Subscriber', 'wptransformed' ) : ucfirst( (string) $roles[0] );
        $name    = (string) $user->display_name;
        $initial = $name !== '' ? strtoupper( mb_substr( $name, 0, 1 ) ) : '?';
        return [
            'name'    => $name,
            'role'    => $role,
            'initial' => $initial,
        ];
    }

    private function format_description( object $row ): string {
        $title = isset( $row->object_title ) ? (string) $row->object_title : '';
        if ( $title !== '' ) {
            return $title;
        }
        $details = isset( $row->details ) ? (string) $row->details : '';
        if ( $details !== '' ) {
            $decoded = json_decode( $details, true );
            if ( is_array( $decoded ) ) {
                $parts = [];
                foreach ( $decoded as $key => $value ) {
                    $parts[] = $key . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
                }
                return implode( ', ', $parts );
            }
            return mb_substr( $details, 0, 120 );
        }
        return (string) ( $row->action ?? '' );
    }

    private function format_action_label( string $action ): string {
        if ( $action === '' ) {
            return __( 'Unknown', 'wptransformed' );
        }
        return ucwords( str_replace( '_', ' ', $action ) );
    }

    private function action_badge_class( string $action ): string {
        if ( str_starts_with( $action, 'user_login' ) || str_starts_with( $action, 'user_logout' ) ) {
            return 'login';
        }
        if ( str_starts_with( $action, 'post_' ) || str_starts_with( $action, 'content_' ) ) {
            return 'content';
        }
        if ( str_starts_with( $action, 'option_' ) || str_starts_with( $action, 'settings_' ) ) {
            return 'settings';
        }
        if ( str_starts_with( $action, 'plugin_' ) || str_starts_with( $action, 'theme_' ) ) {
            return 'plugin';
        }
        if ( str_starts_with( $action, 'user_' ) ) {
            return 'user';
        }
        return 'security';
    }

    private function action_icon( string $action ): string {
        if ( str_starts_with( $action, 'user_login' ) ) {
            return 'fa-sign-in-alt';
        }
        if ( str_starts_with( $action, 'user_logout' ) ) {
            return 'fa-sign-out-alt';
        }
        if ( str_starts_with( $action, 'post_' ) ) {
            return 'fa-file-alt';
        }
        if ( str_starts_with( $action, 'plugin_' ) ) {
            return 'fa-plug';
        }
        if ( str_starts_with( $action, 'theme_' ) ) {
            return 'fa-palette';
        }
        if ( str_starts_with( $action, 'user_' ) ) {
            return 'fa-user';
        }
        if ( str_starts_with( $action, 'option_' ) ) {
            return 'fa-cog';
        }
        return 'fa-clipboard-list';
    }

    private function severity_for_action( string $action ): string {
        if ( in_array( $action, [ 'user_login_failed', 'plugin_deleted', 'theme_deleted', 'post_deleted', 'user_deleted' ], true ) ) {
            return 'high';
        }
        if ( in_array( $action, [ 'plugin_activated', 'plugin_deactivated', 'theme_switched', 'option_updated', 'user_created' ], true ) ) {
            return 'medium';
        }
        return 'low';
    }

    private function render_pagination_controls( int $current, int $total, array $filters ): void {
        $base_args = array_filter( [
            'page'    => 'wpt-audit-log',
            'q'       => $filters['q'] !== '' ? $filters['q'] : null,
            'action'  => $filters['action'] !== '' ? $filters['action'] : null,
            'user_id' => $filters['user_id'] > 0 ? $filters['user_id'] : null,
            'range'   => (int) $filters['range'] !== 7 ? $filters['range'] : null,
        ], static function ( $v ) { return $v !== null; } );

        $make_url = static function ( int $page ) use ( $base_args ) {
            $args             = $base_args;
            $args['wpt_page'] = $page;
            return add_query_arg( $args, admin_url( 'admin.php' ) );
        };

        // Prev
        $prev_disabled = $current <= 1 ? 'disabled' : '';
        if ( $prev_disabled ) {
            echo '<button class="wpt-page-btn" disabled><i class="fas fa-chevron-left"></i></button>';
        } else {
            printf(
                '<a class="wpt-page-btn" href="%s" aria-label="%s"><i class="fas fa-chevron-left"></i></a>',
                esc_url( $make_url( $current - 1 ) ),
                esc_attr__( 'Previous page', 'wptransformed' )
            );
        }

        // Numbered pages — show up to 7 (first, prev, current, next, last, ellipses)
        $pages = $this->pagination_range( $current, $total );
        foreach ( $pages as $p ) {
            if ( $p === '...' ) {
                echo '<span class="wpt-page-btn is-ellipsis">…</span>';
                continue;
            }
            $p_int = (int) $p;
            if ( $p_int === $current ) {
                printf( '<span class="wpt-page-btn active">%d</span>', $p_int );
            } else {
                printf(
                    '<a class="wpt-page-btn" href="%s">%d</a>',
                    esc_url( $make_url( $p_int ) ),
                    $p_int
                );
            }
        }

        // Next
        $next_disabled = $current >= $total ? 'disabled' : '';
        if ( $next_disabled ) {
            echo '<button class="wpt-page-btn" disabled><i class="fas fa-chevron-right"></i></button>';
        } else {
            printf(
                '<a class="wpt-page-btn" href="%s" aria-label="%s"><i class="fas fa-chevron-right"></i></a>',
                esc_url( $make_url( $current + 1 ) ),
                esc_attr__( 'Next page', 'wptransformed' )
            );
        }
    }

    /**
     * Compute a compact pagination range: [1, ..., n-1, n, n+1, ..., last]
     *
     * @return array<int,int|string>
     */
    private function pagination_range( int $current, int $total ): array {
        if ( $total <= 7 ) {
            return range( 1, $total );
        }
        $out = [ 1 ];
        if ( $current > 3 ) {
            $out[] = '...';
        }
        $start = max( 2, $current - 1 );
        $end   = min( $total - 1, $current + 1 );
        for ( $i = $start; $i <= $end; $i++ ) {
            $out[] = $i;
        }
        if ( $current < $total - 2 ) {
            $out[] = '...';
        }
        $out[] = $total;
        return $out;
    }
}
