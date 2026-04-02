<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Email Log — Log all outgoing WordPress emails.
 *
 * Features:
 *  - Custom table wpt_email_log (to, subject, message, headers, status, sent_at)
 *  - wp_mail filter captures all outgoing email args
 *  - Admin list table with search and filter
 *  - "Resend" button to re-send with stored args
 *  - Daily cron purge of old entries based on retention_days
 *  - Configurable content logging and retention period
 *  - Pro tier module
 *
 * @package WPTransformed
 */
class Email_Log extends Module_Base {

    /**
     * Custom table name suffix.
     */
    private const TABLE_SUFFIX = 'wpt_email_log';

    /**
     * DB version option key.
     */
    private const DB_VERSION_KEY = 'wpt_email_log_db_version';

    /**
     * Current DB schema version.
     */
    private const DB_VERSION = '1.0';

    /**
     * Entries per page in the log viewer.
     */
    private const PER_PAGE = 25;

    /**
     * Cron hook name.
     */
    private const CRON_HOOK = 'wpt_prune_email_log';

    /**
     * Last inserted log ID for status tracking.
     *
     * @var int
     */
    private int $last_insert_id = 0;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'email-log';
    }

    public function get_title(): string {
        return __( 'Email Log', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Log all outgoing WordPress emails with the ability to search, filter, and resend.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'        => true,
            'retention_days' => 30,
            'log_content'    => true,
            'resend_enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Ensure table exists.
        $this->maybe_create_table();

        // Hook into wp_mail to capture outgoing emails.
        add_filter( 'wp_mail', [ $this, 'capture_email' ], 999 );

        // Track send success/failure.
        add_action( 'wp_mail_succeeded', [ $this, 'on_mail_succeeded' ] );
        add_action( 'wp_mail_failed', [ $this, 'on_mail_failed' ] );

        // Daily cron for cleanup.
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, [ $this, 'prune_old_entries' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_email_log_fetch', [ $this, 'ajax_fetch_logs' ] );
        add_action( 'wp_ajax_wpt_email_log_resend', [ $this, 'ajax_resend' ] );
        add_action( 'wp_ajax_wpt_email_log_view', [ $this, 'ajax_view_email' ] );
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    // ── Table Creation ────────────────────────────────────────

    /**
     * Create the email log table if needed.
     * Uses get_option for version check (not transient).
     */
    private function maybe_create_table(): void {
        $current_version = get_option( self::DB_VERSION_KEY, '' );

        if ( $current_version === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            to_email VARCHAR(500) NOT NULL,
            subject VARCHAR(500) NOT NULL DEFAULT '',
            message LONGTEXT,
            headers TEXT,
            attachments TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error_message TEXT,
            sent_at DATETIME NOT NULL,
            INDEX idx_to_email (to_email(191)),
            INDEX idx_subject (subject(191)),
            INDEX idx_status (status),
            INDEX idx_sent_at (sent_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION, true );
    }

    // ── Email Capture ─────────────────────────────────────────

    /**
     * Capture outgoing email via wp_mail filter.
     * Stores the email in the log table and returns args unchanged.
     *
     * @param array $args wp_mail arguments.
     * @return array Unchanged args.
     */
    public function capture_email( array $args ): array {
        global $wpdb;

        $settings = $this->get_settings();
        $table    = $wpdb->prefix . self::TABLE_SUFFIX;

        $to = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : ( $args['to'] ?? '' );

        $headers = $args['headers'] ?? '';
        if ( is_array( $headers ) ) {
            $headers = implode( "\n", $headers );
        }

        $attachments = $args['attachments'] ?? [];
        $att_json    = is_array( $attachments ) && ! empty( $attachments )
            ? (string) wp_json_encode( array_map( 'basename', $attachments ) )
            : '';

        $data = [
            'to_email'    => sanitize_text_field( $to ),
            'subject'     => sanitize_text_field( $args['subject'] ?? '' ),
            'message'     => ! empty( $settings['log_content'] ) ? ( $args['message'] ?? '' ) : null,
            'headers'     => $headers,
            'attachments' => $att_json,
            'status'      => 'pending',
            'sent_at'     => current_time( 'mysql', true ),
        ];

        // Remove null values (message when log_content is off).
        $data = array_filter( $data, function ( $v ) { return $v !== null; } );

        // All columns are strings.
        $formats = array_fill( 0, count( $data ), '%s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert( $table, $data, $formats );

        $this->last_insert_id = (int) $wpdb->insert_id;

        return $args;
    }

    /**
     * Mark the most recent pending email as sent.
     *
     * @param array $mail_data Mail data from wp_mail_succeeded.
     */
    public function on_mail_succeeded( array $mail_data ): void {
        $this->update_last_pending_status( 'sent', $mail_data );
    }

    /**
     * Mark the most recent pending email as failed.
     *
     * @param \WP_Error $error Error object.
     */
    public function on_mail_failed( \WP_Error $error ): void {
        $this->update_last_pending_status( 'failed', [], $error->get_error_message() );
    }

    /**
     * Update the status of the log entry captured by capture_email.
     * Uses the stored insert ID to avoid race conditions with concurrent emails.
     *
     * @param string $status        New status.
     * @param array  $mail_data     Mail data (unused, kept for interface consistency).
     * @param string $error_message Error message if failed.
     */
    private function update_last_pending_status( string $status, array $mail_data = [], string $error_message = '' ): void {
        if ( $this->last_insert_id < 1 ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $update_data = [ 'status' => $status ];
        $formats     = [ '%s' ];

        if ( $error_message !== '' ) {
            $update_data['error_message'] = $error_message;
            $formats[]                    = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            $update_data,
            [ 'id' => $this->last_insert_id ],
            $formats,
            [ '%d' ]
        );
    }

    // ── Cron: Prune Old Entries ───────────────────────────────

    /**
     * Delete log entries older than the retention period.
     */
    public function prune_old_entries(): void {
        global $wpdb;

        $settings       = $this->get_settings();
        $retention_days = max( 1, (int) ( $settings['retention_days'] ?? 30 ) );
        $table          = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE sent_at < DATE_SUB(%s, INTERVAL %d DAY)",
                current_time( 'mysql', true ),
                $retention_days
            )
        );
    }

    // ── AJAX: Fetch Logs ──────────────────────────────────────

    /**
     * Fetch paginated email logs via AJAX.
     */
    public function ajax_fetch_logs(): void {
        check_ajax_referer( 'wpt_email_log_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
        $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
        $status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
        $offset = ( $page - 1 ) * self::PER_PAGE;

        $where   = [];
        $prepare = [];

        if ( $search !== '' ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(to_email LIKE %s OR subject LIKE %s)';
            $prepare[] = $like;
            $prepare[] = $like;
        }

        if ( in_array( $status, [ 'sent', 'failed', 'pending' ], true ) ) {
            $where[]   = 'status = %s';
            $prepare[] = $status;
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Count total.
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        if ( ! empty( $prepare ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$prepare ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // Fetch rows.
        $query = "SELECT id, to_email, subject, status, error_message, sent_at FROM {$table} {$where_sql} ORDER BY sent_at DESC LIMIT %d OFFSET %d";
        $all_params = array_merge( $prepare, [ self::PER_PAGE, $offset ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$all_params ), ARRAY_A );

        wp_send_json_success( [
            'rows'       => $rows ?? [],
            'total'      => $total,
            'pages'      => (int) ceil( $total / self::PER_PAGE ),
            'current'    => $page,
        ] );
    }

    // ── AJAX: View Email ──────────────────────────────────────

    /**
     * View a single email's full details.
     */
    public function ajax_view_email(): void {
        check_ajax_referer( 'wpt_email_log_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $id    = absint( $_POST['id'] ?? 0 );

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'wptransformed' ) ] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => __( 'Email not found.', 'wptransformed' ) ] );
        }

        wp_send_json_success( $row );
    }

    // ── AJAX: Resend ──────────────────────────────────────────

    /**
     * Resend an email from the log.
     */
    public function ajax_resend(): void {
        check_ajax_referer( 'wpt_email_log_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $settings = $this->get_settings();
        if ( empty( $settings['resend_enabled'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Resend is disabled.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $id    = absint( $_POST['id'] ?? 0 );

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'wptransformed' ) ] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => __( 'Email not found.', 'wptransformed' ) ] );
        }

        $to      = $row['to_email'];
        $subject = $row['subject'];
        $message = $row['message'] ?? '';
        $headers = $row['headers'] ?? '';

        // Send the email. This will also trigger our capture_email filter again,
        // creating a new log entry for the resend.
        $sent = wp_mail( $to, $subject, $message, $headers );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => __( 'Email resent successfully.', 'wptransformed' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to resend email.', 'wptransformed' ) ] );
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        // Get log stats.
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total, SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed FROM {$table}",
                'failed'
            )
        );
        $total_logs   = (int) ( $stats->total ?? 0 );
        $failed_count = (int) ( $stats->failed ?? 0 );

        ?>
        <div style="background: #fff; border: 1px solid #ddd; border-left: 4px solid #2271b1; border-radius: 4px; padding: 12px 16px; margin-bottom: 16px;">
            <p style="margin: 0;">
                <?php
                printf(
                    /* translators: 1: total emails, 2: failed emails */
                    esc_html__( 'Total logged emails: %1$s | Failed: %2$s', 'wptransformed' ),
                    '<strong>' . esc_html( number_format_i18n( $total_logs ) ) . '</strong>',
                    '<strong style="color: ' . ( $failed_count > 0 ? '#d63638' : 'inherit' ) . ';">' . esc_html( number_format_i18n( $failed_count ) ) . '</strong>'
                );
                ?>
            </p>
        </div>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Retention Period', 'wptransformed' ); ?></th>
                <td>
                    <input type="number" name="wpt_retention_days" value="<?php echo esc_attr( (string) ( $settings['retention_days'] ?? 30 ) ); ?>"
                           min="1" max="365" step="1" style="width: 80px;">
                    <?php esc_html_e( 'days', 'wptransformed' ); ?>
                    <p class="description">
                        <?php esc_html_e( 'Emails older than this will be automatically deleted by a daily cron job.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Log Options', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_log_content" value="1"
                                   <?php checked( ! empty( $settings['log_content'] ) ); ?>>
                            <?php esc_html_e( 'Log email message content', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Store the full email body. Disable to save database space (subject and recipients are always logged).', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="wpt_resend_enabled" value="1"
                                   <?php checked( ! empty( $settings['resend_enabled'] ) ); ?>>
                            <?php esc_html_e( 'Enable resend functionality', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Allow administrators to resend logged emails. Requires "Log email message content" to be enabled for full resend.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <h3 style="margin-top: 30px;"><?php esc_html_e( 'Email Log Viewer', 'wptransformed' ); ?></h3>

        <div style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center;">
            <input type="text" id="wpt-email-log-search" placeholder="<?php esc_attr_e( 'Search by recipient or subject...', 'wptransformed' ); ?>"
                   style="width: 300px;">
            <select id="wpt-email-log-status-filter">
                <option value=""><?php esc_html_e( 'All Statuses', 'wptransformed' ); ?></option>
                <option value="sent"><?php esc_html_e( 'Sent', 'wptransformed' ); ?></option>
                <option value="failed"><?php esc_html_e( 'Failed', 'wptransformed' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pending', 'wptransformed' ); ?></option>
            </select>
            <button type="button" class="button" id="wpt-email-log-search-btn">
                <?php esc_html_e( 'Search', 'wptransformed' ); ?>
            </button>
        </div>

        <table class="widefat striped" id="wpt-email-log-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'To', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Subject', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody id="wpt-email-log-body">
                <tr><td colspan="5"><?php esc_html_e( 'Loading...', 'wptransformed' ); ?></td></tr>
            </tbody>
        </table>

        <div id="wpt-email-log-pagination" style="margin-top: 12px; text-align: center;"></div>

        <!-- Email detail modal -->
        <div id="wpt-email-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999;">
            <div style="background:#fff; max-width:700px; margin:50px auto; padding:20px; border-radius:4px; max-height:80vh; overflow-y:auto; position:relative;">
                <button type="button" id="wpt-email-modal-close" style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
                <div id="wpt-email-modal-content"></div>
            </div>
        </div>

        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce = '<?php echo esc_js( wp_create_nonce( 'wpt_email_log_nonce' ) ); ?>';
            var resendEnabled = <?php echo wp_json_encode( ! empty( $settings['resend_enabled'] ) ); ?>;
            var currentPage = 1;

            function fetchLogs(page) {
                currentPage = page || 1;
                var search = document.getElementById('wpt-email-log-search').value;
                var status = document.getElementById('wpt-email-log-status-filter').value;

                var formData = new FormData();
                formData.append('action', 'wpt_email_log_fetch');
                formData.append('nonce', nonce);
                formData.append('page', currentPage);
                formData.append('search', search);
                formData.append('status', status);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (!result.success) return;
                        renderTable(result.data.rows);
                        renderPagination(result.data.pages, result.data.current);
                    });
            }

            function renderTable(rows) {
                var body = document.getElementById('wpt-email-log-body');
                if (!rows || rows.length === 0) {
                    body.innerHTML = '<tr><td colspan="5">' + '<?php echo esc_js( __( 'No emails found.', 'wptransformed' ) ); ?>' + '</td></tr>';
                    return;
                }

                var html = '';
                rows.forEach(function(row) {
                    var statusClass = row.status === 'sent' ? 'color:#46b450;' : (row.status === 'failed' ? 'color:#d63638;' : 'color:#dba617;');
                    html += '<tr>';
                    html += '<td>' + escHtml(row.sent_at) + '</td>';
                    html += '<td>' + escHtml(row.to_email) + '</td>';
                    html += '<td>' + escHtml(row.subject) + '</td>';
                    html += '<td><span style="' + statusClass + 'font-weight:600;">' + escHtml(row.status) + '</span>';
                    if (row.error_message) html += '<br><small style="color:#d63638;">' + escHtml(row.error_message) + '</small>';
                    html += '</td>';
                    html += '<td>';
                    html += '<button type="button" class="button button-small" onclick="wptEmailLog.view(' + row.id + ')">' + '<?php echo esc_js( __( 'View', 'wptransformed' ) ); ?>' + '</button> ';
                    if (resendEnabled) {
                        html += '<button type="button" class="button button-small" onclick="wptEmailLog.resend(' + row.id + ')">' + '<?php echo esc_js( __( 'Resend', 'wptransformed' ) ); ?>' + '</button>';
                    }
                    html += '</td>';
                    html += '</tr>';
                });
                body.innerHTML = html;
            }

            function renderPagination(totalPages, current) {
                var container = document.getElementById('wpt-email-log-pagination');
                if (totalPages <= 1) { container.innerHTML = ''; return; }

                var html = '';
                if (current > 1) html += '<button type="button" class="button button-small" onclick="wptEmailLog.page(' + (current - 1) + ')">&laquo;</button> ';
                for (var i = 1; i <= totalPages; i++) {
                    if (i === current) {
                        html += '<strong style="padding:0 8px;">' + i + '</strong> ';
                    } else if (i <= 3 || i > totalPages - 3 || Math.abs(i - current) <= 2) {
                        html += '<button type="button" class="button button-small" onclick="wptEmailLog.page(' + i + ')">' + i + '</button> ';
                    } else if (i === 4 || i === totalPages - 3) {
                        html += '... ';
                    }
                }
                if (current < totalPages) html += '<button type="button" class="button button-small" onclick="wptEmailLog.page(' + (current + 1) + ')">&raquo;</button>';
                container.innerHTML = html;
            }

            function escHtml(str) {
                if (!str) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }

            window.wptEmailLog = {
                page: function(p) { fetchLogs(p); },
                view: function(id) {
                    var formData = new FormData();
                    formData.append('action', 'wpt_email_log_view');
                    formData.append('nonce', nonce);
                    formData.append('id', id);

                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                        .then(function(r) { return r.json(); })
                        .then(function(result) {
                            if (!result.success) return;
                            var d = result.data;
                            var html = '<h3>' + escHtml(d.subject) + '</h3>';
                            html += '<p><strong><?php echo esc_js( __( 'To:', 'wptransformed' ) ); ?></strong> ' + escHtml(d.to_email) + '</p>';
                            html += '<p><strong><?php echo esc_js( __( 'Date:', 'wptransformed' ) ); ?></strong> ' + escHtml(d.sent_at) + '</p>';
                            html += '<p><strong><?php echo esc_js( __( 'Status:', 'wptransformed' ) ); ?></strong> ' + escHtml(d.status) + '</p>';
                            if (d.headers) html += '<p><strong><?php echo esc_js( __( 'Headers:', 'wptransformed' ) ); ?></strong><br><pre style="background:#f0f0f1;padding:8px;white-space:pre-wrap;">' + escHtml(d.headers) + '</pre></p>';
                            if (d.message) html += '<hr><div style="background:#f9f9f9;padding:12px;border:1px solid #ddd;max-height:300px;overflow-y:auto;">' + escHtml(d.message) + '</div>';
                            if (d.error_message) html += '<p style="color:#d63638;"><strong><?php echo esc_js( __( 'Error:', 'wptransformed' ) ); ?></strong> ' + escHtml(d.error_message) + '</p>';
                            document.getElementById('wpt-email-modal-content').innerHTML = html;
                            document.getElementById('wpt-email-modal').style.display = 'block';
                        });
                },
                resend: function(id) {
                    if (!confirm('<?php echo esc_js( __( 'Are you sure you want to resend this email?', 'wptransformed' ) ); ?>')) return;

                    var formData = new FormData();
                    formData.append('action', 'wpt_email_log_resend');
                    formData.append('nonce', nonce);
                    formData.append('id', id);

                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
                        .then(function(r) { return r.json(); })
                        .then(function(result) {
                            alert(result.data && result.data.message ? result.data.message : (result.success ? '<?php echo esc_js( __( 'Done.', 'wptransformed' ) ); ?>' : '<?php echo esc_js( __( 'Failed.', 'wptransformed' ) ); ?>'));
                            if (result.success) fetchLogs(currentPage);
                        });
                }
            };

            // Event listeners.
            document.getElementById('wpt-email-log-search-btn').addEventListener('click', function() { fetchLogs(1); });
            document.getElementById('wpt-email-log-search').addEventListener('keypress', function(e) { if (e.key === 'Enter') { e.preventDefault(); fetchLogs(1); } });
            document.getElementById('wpt-email-log-status-filter').addEventListener('change', function() { fetchLogs(1); });
            document.getElementById('wpt-email-modal-close').addEventListener('click', function() { document.getElementById('wpt-email-modal').style.display = 'none'; });
            document.getElementById('wpt-email-modal').addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });

            // Initial load.
            fetchLogs(1);
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $retention = absint( $raw['wpt_retention_days'] ?? 30 );
        if ( $retention < 1 ) {
            $retention = 1;
        }
        if ( $retention > 365 ) {
            $retention = 365;
        }

        return [
            'enabled'        => true,
            'retention_days' => $retention,
            'log_content'    => ! empty( $raw['wpt_log_content'] ),
            'resend_enabled' => ! empty( $raw['wpt_resend_enabled'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // All JS is inline in render_settings.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'table', 'name' => self::TABLE_SUFFIX ],
            [ 'type' => 'option', 'key' => self::DB_VERSION_KEY ],
            [ 'type' => 'cron', 'hook' => self::CRON_HOOK ],
        ];
    }
}
