<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Webhook Manager -- Send HTTP webhooks on WordPress events.
 *
 * Features:
 *  - CRUD for webhook endpoints (AJAX-powered)
 *  - Events: post_published, post_updated, user_registered, comment_posted, plugin_activated
 *  - JSON payloads via wp_remote_post()
 *  - Custom headers for auth tokens
 *  - Delivery log stored in transient (last 50)
 *  - Single retry on failure
 *  - Test-fire button
 *
 * @package WPTransformed
 */
class Webhook_Manager extends Module_Base {

    /**
     * DB version option key.
     */
    private const DB_VERSION_KEY = 'wpt_webhook_manager_db_version';

    /**
     * Current DB schema version.
     */
    private const DB_VERSION = '1.0';

    /**
     * Transient key for delivery log.
     */
    private const LOG_KEY = 'wpt_webhook_delivery_log';

    /**
     * Max delivery log entries.
     */
    private const MAX_LOG_ENTRIES = 50;

    /**
     * Supported webhook events.
     */
    private const EVENTS = [
        'post_published',
        'post_updated',
        'user_registered',
        'comment_posted',
        'plugin_activated',
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'webhook-manager';
    }

    public function get_title(): string {
        return __( 'Webhook Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Send HTTP webhooks to external services when WordPress events occur.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $this->maybe_create_table();

        // WordPress event hooks.
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );
        add_action( 'post_updated',           [ $this, 'on_post_updated' ], 10, 3 );
        add_action( 'user_register',          [ $this, 'on_user_registered' ], 10, 1 );
        add_action( 'wp_insert_comment',      [ $this, 'on_comment_posted' ], 10, 2 );
        add_action( 'activated_plugin',       [ $this, 'on_plugin_activated' ], 10, 2 );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_webhook_list',   [ $this, 'ajax_list' ] );
        add_action( 'wp_ajax_wpt_webhook_save',   [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_wpt_webhook_delete', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_wpt_webhook_toggle', [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_wpt_webhook_test',   [ $this, 'ajax_test' ] );
    }

    // ── Table Creation ────────────────────────────────────────

    /**
     * Create the webhooks table if needed.
     */
    private function maybe_create_table(): void {
        $installed = get_option( self::DB_VERSION_KEY );

        if ( $installed === self::DB_VERSION ) {
            return;
        }

        global $wpdb;

        $table           = $wpdb->prefix . 'wpt_webhooks';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            event VARCHAR(100) NOT NULL,
            headers JSON,
            is_active TINYINT(1) DEFAULT 1,
            last_triggered DATETIME NULL,
            last_status SMALLINT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event (event),
            INDEX idx_active (is_active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION, true );
    }

    /**
     * Get the webhooks table name.
     *
     * @return string
     */
    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'wpt_webhooks';
    }

    // ── Event Handlers ────────────────────────────────────────

    /**
     * Fire post_published webhooks when a post transitions to 'publish'.
     *
     * @param string   $new_status New status.
     * @param string   $old_status Old status.
     * @param \WP_Post $post       Post object.
     */
    public function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }

        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }

        $this->fire_webhooks( 'post_published', [
            'post_id'    => $post->ID,
            'title'      => $post->post_title,
            'url'        => get_permalink( $post ),
            'post_type'  => $post->post_type,
            'author'     => get_the_author_meta( 'display_name', (int) $post->post_author ),
            'date'       => $post->post_date,
        ] );
    }

    /**
     * Fire post_updated webhooks.
     *
     * @param int      $post_id     Post ID.
     * @param \WP_Post $post_after  Post after update.
     * @param \WP_Post $post_before Post before update.
     */
    public function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        // Only fire for published posts being updated.
        if ( 'publish' !== $post_after->post_status ) {
            return;
        }

        $this->fire_webhooks( 'post_updated', [
            'post_id'   => $post_id,
            'title'     => $post_after->post_title,
            'url'       => get_permalink( $post_after ),
            'post_type' => $post_after->post_type,
            'author'    => get_the_author_meta( 'display_name', (int) $post_after->post_author ),
        ] );
    }

    /**
     * Fire user_registered webhooks.
     *
     * @param int $user_id New user ID.
     */
    public function on_user_registered( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $this->fire_webhooks( 'user_registered', [
            'user_id'      => $user_id,
            'username'     => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'role'         => implode( ', ', $user->roles ),
        ] );
    }

    /**
     * Fire comment_posted webhooks.
     *
     * @param int         $comment_id Comment ID.
     * @param \WP_Comment $comment    Comment object.
     */
    public function on_comment_posted( int $comment_id, \WP_Comment $comment ): void {
        // Only fire for approved comments.
        if ( '1' !== $comment->comment_approved ) {
            return;
        }

        $this->fire_webhooks( 'comment_posted', [
            'comment_id' => $comment_id,
            'post_id'    => (int) $comment->comment_post_ID,
            'author'     => $comment->comment_author,
            'email'      => $comment->comment_author_email,
            'content'    => wp_trim_words( $comment->comment_content, 50 ),
        ] );
    }

    /**
     * Fire plugin_activated webhooks.
     *
     * @param string $plugin       Plugin basename.
     * @param bool   $network_wide Network activation.
     */
    public function on_plugin_activated( string $plugin, bool $network_wide ): void {
        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );

        $this->fire_webhooks( 'plugin_activated', [
            'plugin'       => $plugin,
            'name'         => $plugin_data['Name'] ?? $plugin,
            'version'      => $plugin_data['Version'] ?? '',
            'network_wide' => $network_wide,
        ] );
    }

    // ── Webhook Dispatch ──────────────────────────────────────

    /**
     * Fire all active webhooks for a given event.
     *
     * @param string $event   Event name.
     * @param array  $payload Data payload.
     */
    private function fire_webhooks( string $event, array $payload ): void {
        global $wpdb;

        $table    = $this->table_name();
        $webhooks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event = %s AND is_active = 1",
                $event
            ),
            ARRAY_A
        );

        if ( empty( $webhooks ) ) {
            return;
        }

        $payload['event']     = $event;
        $payload['site_url']  = home_url();
        $payload['timestamp'] = current_time( 'mysql' );

        foreach ( $webhooks as $webhook ) {
            $this->dispatch_webhook( $webhook, $payload );
        }
    }

    /**
     * Send a single webhook request.
     *
     * @param array $webhook    Webhook row data.
     * @param array $payload    JSON payload.
     * @param bool  $is_retry   Whether this is a retry attempt.
     * @param bool  $skip_retry Skip automatic retry on failure (used by test-fire).
     * @return array{status: int, body: string} HTTP status and response body.
     */
    private function dispatch_webhook( array $webhook, array $payload, bool $is_retry = false, bool $skip_retry = false ): array {
        global $wpdb;

        // Block non-HTTP schemes to prevent SSRF.
        $scheme = wp_parse_url( $webhook['url'] ?? '', PHP_URL_SCHEME );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return [ 'status' => 0, 'body' => 'Blocked: non-HTTP scheme.' ];
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Parse custom headers.
        $custom_headers = $this->parse_headers( $webhook['headers'] ?? '' );
        $headers        = array_merge( $headers, $custom_headers );

        $response = wp_remote_post( $webhook['url'], [
            'body'      => wp_json_encode( $payload ),
            'headers'   => $headers,
            'timeout'   => 15,
            'sslverify' => true,
        ] );

        $status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $body   = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

        // Update last_triggered and last_status.
        $wpdb->update(
            $this->table_name(),
            [
                'last_triggered' => current_time( 'mysql' ),
                'last_status'    => $status,
            ],
            [ 'id' => (int) $webhook['id'] ],
            [ '%s', '%d' ],
            [ '%d' ]
        );

        // Log delivery.
        $this->log_delivery( $webhook, $status, $is_retry, $response );

        // Retry once on failure (non-2xx status) if not already a retry.
        if ( ! $is_retry && ! $skip_retry && ( $status < 200 || $status >= 300 ) ) {
            return $this->dispatch_webhook( $webhook, $payload, true );
        }

        // Truncate body for return.
        if ( strlen( $body ) > 500 ) {
            $body = substr( $body, 0, 500 ) . '...';
        }

        return [ 'status' => $status, 'body' => $body ];
    }

    /**
     * Parse JSON or newline-separated headers string.
     *
     * @param string|null $headers_raw Raw headers from DB.
     * @return array<string,string> Parsed headers.
     */
    private function parse_headers( ?string $headers_raw ): array {
        if ( empty( $headers_raw ) ) {
            return [];
        }

        // Try JSON first.
        $decoded = json_decode( $headers_raw, true );
        if ( is_array( $decoded ) ) {
            $result = [];
            foreach ( $decoded as $key => $value ) {
                $key   = sanitize_text_field( (string) $key );
                $value = sanitize_text_field( (string) $value );
                if ( ! empty( $key ) ) {
                    $result[ $key ] = $value;
                }
            }
            return $result;
        }

        return [];
    }

    /**
     * Log a webhook delivery to transient.
     *
     * @param array               $webhook  Webhook row.
     * @param int                 $status   HTTP status code.
     * @param bool                $is_retry Whether this was a retry.
     * @param array|\WP_Error     $response Full response.
     */
    private function log_delivery( array $webhook, int $status, bool $is_retry, $response ): void {
        $log = get_transient( self::LOG_KEY );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        $body = '';
        if ( ! is_wp_error( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            // Truncate large response bodies.
            if ( strlen( $body ) > 500 ) {
                $body = substr( $body, 0, 500 ) . '...';
            }
        } else {
            $body = $response->get_error_message();
        }

        array_unshift( $log, [
            'webhook_id'   => (int) $webhook['id'],
            'webhook_name' => $webhook['name'],
            'url'          => $webhook['url'],
            'event'        => $webhook['event'],
            'status'       => $status,
            'is_retry'     => $is_retry,
            'response'     => $body,
            'timestamp'    => current_time( 'mysql' ),
        ] );

        // Keep only last N entries.
        $log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );

        set_transient( self::LOG_KEY, $log, WEEK_IN_SECONDS );
    }

    // ── AJAX: List Webhooks ───────────────────────────────────

    /**
     * Return all webhooks as JSON.
     */
    public function ajax_list(): void {
        check_ajax_referer( 'wpt_webhook_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $webhooks = $wpdb->get_results(
            "SELECT * FROM {$this->table_name()} ORDER BY created_at DESC",
            ARRAY_A
        );

        wp_send_json_success( [ 'webhooks' => $webhooks ?: [] ] );
    }

    // ── AJAX: Save (Create/Update) ────────────────────────────

    /**
     * Create or update a webhook.
     */
    public function ajax_save(): void {
        check_ajax_referer( 'wpt_webhook_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        $event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';

        $headers_raw = isset( $_POST['headers'] ) ? wp_unslash( $_POST['headers'] ) : '';
        // Validate headers JSON.
        $headers_json = '';
        if ( ! empty( $headers_raw ) ) {
            $decoded = json_decode( $headers_raw, true );
            if ( is_array( $decoded ) ) {
                // Sanitize each key/value.
                $clean = [];
                foreach ( $decoded as $k => $v ) {
                    $k = sanitize_text_field( (string) $k );
                    $v = sanitize_text_field( (string) $v );
                    if ( ! empty( $k ) ) {
                        $clean[ $k ] = $v;
                    }
                }
                $headers_json = wp_json_encode( $clean );
            } else {
                wp_send_json_error( [ 'message' => __( 'Invalid headers JSON.', 'wptransformed' ) ] );
            }
        }

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Name is required.', 'wptransformed' ) ] );
        }

        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => __( 'A valid URL is required.', 'wptransformed' ) ] );
        }

        if ( ! in_array( $event, self::EVENTS, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid event.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $this->table_name();

        $data = [
            'name'    => $name,
            'url'     => $url,
            'event'   => $event,
            'headers' => $headers_json ?: null,
        ];

        $format = [ '%s', '%s', '%s', '%s' ];

        if ( $id > 0 ) {
            // Update existing.
            $wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );
            $message = __( 'Webhook updated.', 'wptransformed' );
        } else {
            // Insert new.
            $data['is_active']  = 1;
            $data['created_at'] = current_time( 'mysql' );
            $format[]           = '%d';
            $format[]           = '%s';

            $wpdb->insert( $table, $data, $format );
            $id      = (int) $wpdb->insert_id;
            $message = __( 'Webhook created.', 'wptransformed' );
        }

        wp_send_json_success( [ 'message' => $message, 'id' => $id ] );
    }

    // ── AJAX: Delete ──────────────────────────────────────────

    /**
     * Delete a webhook.
     */
    public function ajax_delete(): void {
        check_ajax_referer( 'wpt_webhook_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid webhook ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $wpdb->delete( $this->table_name(), [ 'id' => $id ], [ '%d' ] );

        wp_send_json_success( [ 'message' => __( 'Webhook deleted.', 'wptransformed' ) ] );
    }

    // ── AJAX: Toggle Active ───────────────────────────────────

    /**
     * Toggle a webhook's active state.
     */
    public function ajax_toggle(): void {
        check_ajax_referer( 'wpt_webhook_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid webhook ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table   = $this->table_name();
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT is_active FROM {$table} WHERE id = %d",
            $id
        ) );

        if ( null === $current ) {
            wp_send_json_error( [ 'message' => __( 'Webhook not found.', 'wptransformed' ) ] );
        }

        $new_state = (int) $current === 1 ? 0 : 1;
        $wpdb->update( $table, [ 'is_active' => $new_state ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

        wp_send_json_success( [
            'message'   => $new_state ? __( 'Webhook activated.', 'wptransformed' ) : __( 'Webhook deactivated.', 'wptransformed' ),
            'is_active' => $new_state,
        ] );
    }

    // ── AJAX: Test Fire ───────────────────────────────────────

    /**
     * Send a test payload to a webhook.
     */
    public function ajax_test(): void {
        check_ajax_referer( 'wpt_webhook_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid webhook ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $webhook = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name()} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        if ( ! $webhook ) {
            wp_send_json_error( [ 'message' => __( 'Webhook not found.', 'wptransformed' ) ] );
        }

        $payload = [
            'event'     => 'test',
            'site_url'  => home_url(),
            'timestamp' => current_time( 'mysql' ),
            'message'   => 'This is a test webhook from WPTransformed.',
        ];

        // Dispatch without retry for test-fire.
        $result = $this->dispatch_webhook( $webhook, $payload, false, true );
        $status = $result['status'];
        $body   = $result['body'];

        if ( $status >= 200 && $status < 300 ) {
            wp_send_json_success( [
                'message' => sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Test successful (HTTP %d).', 'wptransformed' ),
                    $status
                ),
                'status'  => $status,
                'body'    => $body,
            ] );
        } else {
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Test failed (HTTP %d).', 'wptransformed' ),
                    $status
                ),
                'status'  => $status,
                'body'    => $body,
            ] );
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $log = get_transient( self::LOG_KEY );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        ?>
        <div class="wpt-webhook-manager">
            <!-- Add/Edit Form -->
            <h3><?php esc_html_e( 'Add Webhook', 'wptransformed' ); ?></h3>
            <div class="wpt-webhook-form" id="wpt-webhook-form">
                <input type="hidden" id="wpt-webhook-edit-id" value="0">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wpt-webhook-name"><?php esc_html_e( 'Name', 'wptransformed' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wpt-webhook-name" class="regular-text"
                                   placeholder="<?php esc_attr_e( 'My Webhook', 'wptransformed' ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpt-webhook-url"><?php esc_html_e( 'URL', 'wptransformed' ); ?></label>
                        </th>
                        <td>
                            <input type="url" id="wpt-webhook-url" class="large-text"
                                   placeholder="<?php esc_attr_e( 'https://example.com/webhook', 'wptransformed' ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpt-webhook-event"><?php esc_html_e( 'Event', 'wptransformed' ); ?></label>
                        </th>
                        <td>
                            <select id="wpt-webhook-event">
                                <?php foreach ( self::EVENTS as $event ) : ?>
                                    <option value="<?php echo esc_attr( $event ); ?>">
                                        <?php echo esc_html( $this->event_label( $event ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpt-webhook-headers"><?php esc_html_e( 'Headers (JSON)', 'wptransformed' ); ?></label>
                        </th>
                        <td>
                            <textarea id="wpt-webhook-headers" class="large-text code" rows="3"
                                      placeholder='<?php echo esc_attr( '{"Authorization": "Bearer your-token"}' ); ?>'></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Optional. JSON object of custom HTTP headers for authentication.', 'wptransformed' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button button-primary" id="wpt-webhook-save">
                        <?php esc_html_e( 'Save Webhook', 'wptransformed' ); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="wpt-webhook-cancel" style="display: none;">
                        <?php esc_html_e( 'Cancel Edit', 'wptransformed' ); ?>
                    </button>
                    <span class="spinner" id="wpt-webhook-spinner" style="float: none;"></span>
                </p>
            </div>

            <hr>

            <!-- Webhooks Table -->
            <h3><?php esc_html_e( 'Registered Webhooks', 'wptransformed' ); ?></h3>
            <div id="wpt-webhook-list">
                <p class="description"><?php esc_html_e( 'Loading...', 'wptransformed' ); ?></p>
            </div>

            <hr>

            <!-- Delivery Log -->
            <h3><?php esc_html_e( 'Recent Deliveries', 'wptransformed' ); ?>
                <span style="font-weight: normal; font-size: 13px; color: #999;">
                    (<?php echo esc_html( (string) count( $log ) ); ?>)
                </span>
            </h3>

            <?php if ( empty( $log ) ) : ?>
                <p class="description"><?php esc_html_e( 'No deliveries logged yet.', 'wptransformed' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 100%;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Webhook', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Event', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Response', 'wptransformed' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['timestamp'] ); ?></td>
                                <td><?php echo esc_html( $entry['webhook_name'] ); ?></td>
                                <td>
                                    <?php echo esc_html( $this->event_label( $entry['event'] ) ); ?>
                                    <?php if ( ! empty( $entry['is_retry'] ) ) : ?>
                                        <span style="color: #dba617; font-size: 11px;"><?php esc_html_e( '(retry)', 'wptransformed' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_color = ( $entry['status'] >= 200 && $entry['status'] < 300 ) ? '#00a32a' : '#d63638';
                                    ?>
                                    <span style="color: <?php echo esc_attr( $status_color ); ?>; font-weight: 600;">
                                        <?php echo esc_html( (string) $entry['status'] ); ?>
                                    </span>
                                </td>
                                <td>
                                    <code style="font-size: 11px; word-break: break-all;">
                                        <?php echo esc_html( substr( $entry['response'] ?? '', 0, 200 ) ); ?>
                                    </code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // No module-level settings; webhooks are in custom table.
        return [];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-webhook-manager',
            WPT_URL . 'modules/utilities/css/webhook-manager.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-webhook-manager',
            WPT_URL . 'modules/utilities/js/webhook-manager.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-webhook-manager', 'wptWebhooks', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_webhook_nonce' ),
            'events'  => array_combine( self::EVENTS, array_map( [ $this, 'event_label' ], self::EVENTS ) ),
            'i18n'    => [
                'confirmDelete' => __( 'Delete this webhook?', 'wptransformed' ),
                'confirmTest'   => __( 'Send a test payload to this webhook?', 'wptransformed' ),
                'networkError'  => __( 'Network error. Please try again.', 'wptransformed' ),
                'noWebhooks'    => __( 'No webhooks registered yet.', 'wptransformed' ),
                'saving'        => __( 'Saving...', 'wptransformed' ),
                'testing'       => __( 'Testing...', 'wptransformed' ),
                'active'        => __( 'Active', 'wptransformed' ),
                'inactive'      => __( 'Inactive', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => self::LOG_KEY ],
            [ 'type' => 'option', 'key' => self::DB_VERSION_KEY ],
            [ 'type' => 'table', 'key' => 'wpt_webhooks' ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Get human-readable event label.
     *
     * @param string $event Event slug.
     * @return string Translated label.
     */
    public function event_label( string $event ): string {
        $labels = [
            'post_published'   => __( 'Post Published', 'wptransformed' ),
            'post_updated'     => __( 'Post Updated', 'wptransformed' ),
            'user_registered'  => __( 'User Registered', 'wptransformed' ),
            'comment_posted'   => __( 'Comment Posted', 'wptransformed' ),
            'plugin_activated' => __( 'Plugin Activated', 'wptransformed' ),
        ];

        return $labels[ $event ] ?? $event;
    }
}
