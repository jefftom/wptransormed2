<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Redirect Manager -- Manage 301/302/307 redirects and log 404 errors.
 *
 * Features:
 *  - Custom redirect rules with 301, 302, 307 types
 *  - 404 error logging with referrer and user agent tracking
 *  - Transient-based redirect cache for performance
 *  - CSV import/export of redirect rules
 *  - AJAX-powered CRUD for redirects and 404 log management
 *  - Redirect loop detection
 *  - Daily cron for 404 log pruning
 *
 * @package WPTransformed
 */
class Redirect_Manager extends Module_Base {

    /**
     * Allowed redirect types.
     */
    private const REDIRECT_TYPES = [ 301, 302, 307 ];

    /**
     * Cache key for active redirects.
     */
    private const CACHE_KEY = 'wpt_redirects_cache';

    /**
     * DB version transient key.
     */
    private const DB_VERSION_KEY = 'wpt_redirect_manager_db_version';

    /**
     * Current DB schema version.
     */
    private const DB_VERSION = '1.0';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'redirect-manager';
    }

    public function get_title(): string {
        return __( 'Redirect Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Manage 301/302/307 redirects and log 404 errors with referrer tracking.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'log_404s'            => true,
            'max_404_log'         => 1000,
            'auto_redirect_slugs' => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $this->maybe_create_tables();

        // Redirect handler -- priority 1 to run before anything else.
        add_action( 'template_redirect', [ $this, 'handle_redirects' ], 1 );

        // 404 logging -- priority 99 to run after redirect check.
        add_action( 'template_redirect', [ $this, 'log_404' ], 99 );

        // Daily cron for 404 log pruning.
        add_action( 'wpt_prune_404_log', [ $this, 'prune_404_log' ] );

        if ( ! wp_next_scheduled( 'wpt_prune_404_log' ) ) {
            wp_schedule_event( time(), 'daily', 'wpt_prune_404_log' );
        }

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_add_redirect',    [ $this, 'ajax_add_redirect' ] );
        add_action( 'wp_ajax_wpt_edit_redirect',   [ $this, 'ajax_edit_redirect' ] );
        add_action( 'wp_ajax_wpt_delete_redirect', [ $this, 'ajax_delete_redirect' ] );
        add_action( 'wp_ajax_wpt_toggle_redirect', [ $this, 'ajax_toggle_redirect' ] );
        add_action( 'wp_ajax_wpt_clear_404_log',   [ $this, 'ajax_clear_404_log' ] );
        add_action( 'wp_ajax_wpt_export_redirects', [ $this, 'ajax_export_redirects' ] );
        add_action( 'wp_ajax_wpt_import_redirects', [ $this, 'ajax_import_redirects' ] );
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( 'wpt_prune_404_log' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpt_prune_404_log' );
        }

        delete_transient( self::CACHE_KEY );
    }

    // ── Table Creation ────────────────────────────────────────

    /**
     * Create custom tables if they don't exist.
     */
    private function maybe_create_tables(): void {
        $installed_version = get_transient( self::DB_VERSION_KEY );

        if ( $installed_version === self::DB_VERSION ) {
            return;
        }

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $redirects_table = $wpdb->prefix . 'wpt_redirects';
        $log_table       = $wpdb->prefix . 'wpt_404_log';

        $sql_redirects = "CREATE TABLE {$redirects_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_url VARCHAR(2048) NOT NULL,
            target_url VARCHAR(2048) NOT NULL,
            redirect_type SMALLINT DEFAULT 301,
            hit_count BIGINT DEFAULT 0,
            last_hit DATETIME NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_source (source_url(191)),
            INDEX idx_active (is_active)
        ) {$charset_collate};";

        $sql_404_log = "CREATE TABLE {$log_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            url VARCHAR(2048) NOT NULL,
            referrer VARCHAR(2048),
            user_agent VARCHAR(512),
            hit_count INT DEFAULT 1,
            last_hit DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_url (url(191))
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_redirects );
        dbDelta( $sql_404_log );

        set_transient( self::DB_VERSION_KEY, self::DB_VERSION, YEAR_IN_SECONDS );
    }

    // ── Redirect Execution ────────────────────────────────────

    /**
     * Check current request URI against active redirects and execute if matched.
     */
    public function handle_redirects(): void {
        if ( is_admin() ) {
            return;
        }

        $request_uri = $this->get_request_uri();

        if ( empty( $request_uri ) ) {
            return;
        }

        $redirects = $this->get_cached_redirects();

        if ( empty( $redirects ) ) {
            return;
        }

        foreach ( $redirects as $redirect ) {
            if ( $this->normalize_url( $redirect['source_url'] ) === $this->normalize_url( $request_uri ) ) {
                $this->increment_hit_count( (int) $redirect['id'] );

                $type = in_array( (int) $redirect['redirect_type'], self::REDIRECT_TYPES, true )
                    ? (int) $redirect['redirect_type']
                    : 301;

                wp_redirect( $redirect['target_url'], $type );
                exit;
            }
        }
    }

    /**
     * Get normalized request URI.
     *
     * @return string
     */
    private function get_request_uri(): string {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        // Remove query string for matching.
        $uri = strtok( $uri, '?' );

        return $uri !== false ? $uri : '';
    }

    /**
     * Normalize a URL for comparison (trim trailing slash, lowercase).
     *
     * @param string $url URL to normalize.
     * @return string
     */
    private function normalize_url( string $url ): string {
        return rtrim( strtolower( $url ), '/' );
    }

    /**
     * Increment the hit count for a redirect.
     *
     * @param int $redirect_id Redirect ID.
     */
    private function increment_hit_count( int $redirect_id ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET hit_count = hit_count + 1, last_hit = %s WHERE id = %d",
                current_time( 'mysql' ),
                $redirect_id
            )
        );
    }

    // ── Redirect Cache ────────────────────────────────────────

    /**
     * Get active redirects from cache or database.
     *
     * @return array
     */
    private function get_cached_redirects(): array {
        $cached = get_transient( self::CACHE_KEY );

        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $redirects = $wpdb->get_results(
            "SELECT id, source_url, target_url, redirect_type FROM {$table} WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        );

        if ( ! is_array( $redirects ) ) {
            $redirects = [];
        }

        set_transient( self::CACHE_KEY, $redirects, DAY_IN_SECONDS );

        return $redirects;
    }

    /**
     * Invalidate the redirect cache.
     */
    private function invalidate_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    // ── 404 Logging ───────────────────────────────────────────

    /**
     * Log 404 errors if enabled.
     */
    public function log_404(): void {
        if ( ! is_404() ) {
            return;
        }

        $settings = $this->get_settings();

        if ( empty( $settings['log_404s'] ) ) {
            return;
        }

        $url = $this->get_request_uri();

        if ( empty( $url ) ) {
            return;
        }

        // Skip common bot/asset requests.
        if ( $this->should_skip_404( $url ) ) {
            return;
        }

        global $wpdb;

        $table    = $wpdb->prefix . 'wpt_404_log';
        $referrer = isset( $_SERVER['HTTP_REFERER'] )
            ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
            : '';
        $ua       = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';

        // Truncate fields to fit DB columns.
        $url      = substr( $url, 0, 2048 );
        $referrer = substr( $referrer, 0, 2048 );
        $ua       = substr( $ua, 0, 512 );

        // Upsert -- increment hit_count if URL already logged.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE url = %s LIMIT 1",
                $url
            )
        );

        if ( $existing ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET hit_count = hit_count + 1, last_hit = %s, referrer = %s, user_agent = %s WHERE id = %d",
                    current_time( 'mysql' ),
                    $referrer,
                    $ua,
                    (int) $existing
                )
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'url'        => $url,
                    'referrer'   => $referrer,
                    'user_agent' => $ua,
                    'hit_count'  => 1,
                    'last_hit'   => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%d', '%s' ]
            );
        }
    }

    /**
     * Check if a 404 URL should be skipped from logging.
     *
     * @param string $url Request URL.
     * @return bool
     */
    private function should_skip_404( string $url ): bool {
        $skip_extensions = [ '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf', '.map' ];

        $path = strtolower( $url );

        foreach ( $skip_extensions as $ext ) {
            if ( substr( $path, -strlen( $ext ) ) === $ext ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prune 404 log to max entries (cron handler).
     */
    public function prune_404_log(): void {
        $settings  = $this->get_settings();
        $max_log   = max( 1, (int) $settings['max_404_log'] );

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_404_log';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        if ( $count <= $max_log ) {
            return;
        }

        $to_delete = $count - $max_log;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} ORDER BY last_hit ASC LIMIT %d",
                $to_delete
            )
        );
    }

    // ── AJAX: Add Redirect ────────────────────────────────────

    /**
     * Add a new redirect rule.
     */
    public function ajax_add_redirect(): void {
        check_ajax_referer( 'wpt_redirect_manager_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $source = isset( $_POST['source_url'] ) ? sanitize_text_field( wp_unslash( $_POST['source_url'] ) ) : '';
        $target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
        $type   = isset( $_POST['redirect_type'] ) ? (int) $_POST['redirect_type'] : 301;

        // Validation.
        $error = $this->validate_redirect( $source, $target, $type );
        if ( $error ) {
            wp_send_json_error( [ 'message' => $error ] );
        }

        // Check for duplicate source URL.
        if ( $this->source_exists( $source ) ) {
            wp_send_json_error( [ 'message' => __( 'A redirect for this source URL already exists.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        $inserted = $wpdb->insert(
            $table,
            [
                'source_url'    => $source,
                'target_url'    => $target,
                'redirect_type' => $type,
                'is_active'     => 1,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%d', '%s' ]
        );

        if ( $inserted === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save redirect.', 'wptransformed' ) ] );
        }

        $this->invalidate_cache();

        $new_id = (int) $wpdb->insert_id;

        wp_send_json_success( [
            'message' => __( 'Redirect added successfully.', 'wptransformed' ),
            'redirect' => [
                'id'            => $new_id,
                'source_url'    => $source,
                'target_url'    => $target,
                'redirect_type' => $type,
                'hit_count'     => 0,
                'is_active'     => 1,
                'created_at'    => current_time( 'mysql' ),
            ],
        ] );
    }

    // ── AJAX: Edit Redirect ───────────────────────────────────

    /**
     * Edit an existing redirect rule.
     */
    public function ajax_edit_redirect(): void {
        check_ajax_referer( 'wpt_redirect_manager_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id     = isset( $_POST['redirect_id'] ) ? (int) $_POST['redirect_id'] : 0;
        $source = isset( $_POST['source_url'] ) ? sanitize_text_field( wp_unslash( $_POST['source_url'] ) ) : '';
        $target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
        $type   = isset( $_POST['redirect_type'] ) ? (int) $_POST['redirect_type'] : 301;

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid redirect ID.', 'wptransformed' ) ] );
        }

        // Validation.
        $error = $this->validate_redirect( $source, $target, $type );
        if ( $error ) {
            wp_send_json_error( [ 'message' => $error ] );
        }

        // Check for duplicate source URL (excluding this redirect).
        if ( $this->source_exists( $source, $id ) ) {
            wp_send_json_error( [ 'message' => __( 'A redirect for this source URL already exists.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        $updated = $wpdb->update(
            $table,
            [
                'source_url'    => $source,
                'target_url'    => $target,
                'redirect_type' => $type,
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to update redirect.', 'wptransformed' ) ] );
        }

        $this->invalidate_cache();

        wp_send_json_success( [
            'message' => __( 'Redirect updated successfully.', 'wptransformed' ),
        ] );
    }

    // ── AJAX: Delete Redirect ─────────────────────────────────

    /**
     * Delete a redirect rule.
     */
    public function ajax_delete_redirect(): void {
        check_ajax_referer( 'wpt_redirect_manager_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['redirect_id'] ) ? (int) $_POST['redirect_id'] : 0;

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid redirect ID.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        if ( $deleted === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete redirect.', 'wptransformed' ) ] );
        }

        $this->invalidate_cache();

        wp_send_json_success( [
            'message' => __( 'Redirect deleted.', 'wptransformed' ),
        ] );
    }

    // ── AJAX: Toggle Active ───────────────────────────────────

    /**
     * Toggle a redirect's active state.
     */
    public function ajax_toggle_redirect(): void {
        check_ajax_referer( 'wpt_redirect_manager_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['redirect_id'] ) ? (int) $_POST['redirect_id'] : 0;

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid redirect ID.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        $current = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT is_active FROM {$table} WHERE id = %d",
                $id
            )
        );

        if ( $current === null ) {
            wp_send_json_error( [ 'message' => __( 'Redirect not found.', 'wptransformed' ) ] );
        }

        $new_state = ( (int) $current === 1 ) ? 0 : 1;

        $wpdb->update(
            $table,
            [ 'is_active' => $new_state ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );

        $this->invalidate_cache();

        wp_send_json_success( [
            'message'   => $new_state ? __( 'Redirect enabled.', 'wptransformed' ) : __( 'Redirect disabled.', 'wptransformed' ),
            'is_active' => $new_state,
        ] );
    }

    // ── AJAX: Clear 404 Log ───────────────────────────────────

    /**
     * Clear all 404 log entries.
     */
    public function ajax_clear_404_log(): void {
        check_ajax_referer( 'wpt_redirect_manager_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_404_log';

        $wpdb->query( "TRUNCATE TABLE {$table}" );

        wp_send_json_success( [
            'message' => __( '404 log cleared.', 'wptransformed' ),
        ] );
    }

    // ── AJAX: Export Redirects ────────────────────────────────

    /**
     * Export all redirects as CSV.
     */
    public function ajax_export_redirects(): void {
        check_ajax_referer( 'wpt_redirect_manager_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $redirects = $wpdb->get_results(
            "SELECT source_url, target_url, redirect_type, is_active FROM {$table} ORDER BY id ASC",
            ARRAY_A
        );

        if ( empty( $redirects ) ) {
            wp_send_json_error( [ 'message' => __( 'No redirects to export.', 'wptransformed' ) ] );
        }

        $csv_lines = [ 'source_url,target_url,redirect_type,is_active' ];

        foreach ( $redirects as $row ) {
            $csv_lines[] = sprintf(
                '"%s","%s",%d,%d',
                str_replace( '"', '""', $row['source_url'] ),
                str_replace( '"', '""', $row['target_url'] ),
                (int) $row['redirect_type'],
                (int) $row['is_active']
            );
        }

        wp_send_json_success( [
            'csv' => implode( "\n", $csv_lines ),
        ] );
    }

    // ── AJAX: Import Redirects ────────────────────────────────

    /**
     * Import redirects from CSV data.
     */
    public function ajax_import_redirects(): void {
        check_ajax_referer( 'wpt_redirect_manager_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $csv_data = isset( $_POST['csv_data'] ) ? wp_unslash( $_POST['csv_data'] ) : '';

        if ( empty( $csv_data ) ) {
            wp_send_json_error( [ 'message' => __( 'No CSV data provided.', 'wptransformed' ) ] );
        }

        $lines = preg_split( '/\r\n|\r|\n/', $csv_data );

        if ( empty( $lines ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid CSV data.', 'wptransformed' ) ] );
        }

        // Skip header if present.
        $first_line = strtolower( trim( $lines[0] ) );
        if ( strpos( $first_line, 'source_url' ) !== false ) {
            array_shift( $lines );
        }

        global $wpdb;

        $table    = $wpdb->prefix . 'wpt_redirects';
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ( $lines as $line_num => $line ) {
            $line = trim( $line );

            if ( empty( $line ) ) {
                continue;
            }

            $fields = str_getcsv( $line );

            if ( count( $fields ) < 2 ) {
                $skipped++;
                continue;
            }

            $source = sanitize_text_field( trim( $fields[0] ) );
            $target = esc_url_raw( trim( $fields[1] ) );
            $type   = isset( $fields[2] ) ? (int) $fields[2] : 301;
            $active = isset( $fields[3] ) ? (int) $fields[3] : 1;

            // Validate.
            $error = $this->validate_redirect( $source, $target, $type );
            if ( $error ) {
                $skipped++;
                continue;
            }

            // Skip duplicate source URLs.
            if ( $this->source_exists( $source ) ) {
                $skipped++;
                continue;
            }

            $inserted = $wpdb->insert(
                $table,
                [
                    'source_url'    => $source,
                    'target_url'    => $target,
                    'redirect_type' => $type,
                    'is_active'     => $active ? 1 : 0,
                    'created_at'    => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%d', '%d', '%s' ]
            );

            if ( $inserted !== false ) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        $this->invalidate_cache();

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: 1: imported count, 2: skipped count */
                __( 'Import complete: %1$d imported, %2$d skipped.', 'wptransformed' ),
                $imported,
                $skipped
            ),
            'imported' => $imported,
            'skipped'  => $skipped,
        ] );
    }

    // ── Validation ────────────────────────────────────────────

    /**
     * Validate a redirect rule.
     *
     * @param string $source Source URL.
     * @param string $target Target URL.
     * @param int    $type   Redirect type.
     * @return string|null Error message or null if valid.
     */
    private function validate_redirect( string $source, string $target, int $type ): ?string {
        if ( empty( $source ) ) {
            return __( 'Source URL is required.', 'wptransformed' );
        }

        if ( strpos( $source, '/' ) !== 0 ) {
            return __( 'Source URL must start with /.', 'wptransformed' );
        }

        if ( empty( $target ) ) {
            return __( 'Target URL is required.', 'wptransformed' );
        }

        if ( ! in_array( $type, self::REDIRECT_TYPES, true ) ) {
            return sprintf(
                /* translators: %s: comma-separated valid types */
                __( 'Invalid redirect type. Allowed: %s.', 'wptransformed' ),
                implode( ', ', self::REDIRECT_TYPES )
            );
        }

        // Redirect loop detection.
        if ( $this->detect_loop( $source, $target ) ) {
            return __( 'Redirect loop detected. The target URL would create a circular redirect.', 'wptransformed' );
        }

        return null;
    }

    /**
     * Detect redirect loops.
     *
     * @param string $source Source URL.
     * @param string $target Target URL.
     * @return bool True if a loop would be created.
     */
    private function detect_loop( string $source, string $target ): bool {
        // Direct loop: source == target.
        $normalized_source = $this->normalize_url( $source );
        $normalized_target = $this->normalize_url( wp_parse_url( $target, PHP_URL_PATH ) ?? '' );

        if ( $normalized_source === $normalized_target ) {
            return true;
        }

        // Check if target is a source of an existing redirect that points back.
        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        // Walk the chain up to 10 hops to detect cycles.
        $visited = [ $normalized_source ];
        $current = $normalized_target;

        for ( $i = 0; $i < 10; $i++ ) {
            if ( empty( $current ) ) {
                break;
            }

            $next_target = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT target_url FROM {$table} WHERE source_url = %s AND is_active = 1 LIMIT 1",
                    $current
                )
            );

            if ( $next_target === null ) {
                break;
            }

            $next_normalized = $this->normalize_url( wp_parse_url( $next_target, PHP_URL_PATH ) ?? '' );

            if ( in_array( $next_normalized, $visited, true ) ) {
                return true;
            }

            $visited[] = $next_normalized;
            $current   = $next_normalized;
        }

        return false;
    }

    /**
     * Check if a source URL already exists in the database.
     *
     * @param string $source     Source URL.
     * @param int    $exclude_id Optional redirect ID to exclude.
     * @return bool
     */
    private function source_exists( string $source, int $exclude_id = 0 ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'wpt_redirects';

        if ( $exclude_id > 0 ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE source_url = %s AND id != %d",
                    $source,
                    $exclude_id
                )
            );
        } else {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE source_url = %s",
                    $source
                )
            );
        }

        return $count > 0;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        global $wpdb;

        $redirects_table = $wpdb->prefix . 'wpt_redirects';
        $log_table       = $wpdb->prefix . 'wpt_404_log';

        // Fetch redirects.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $redirects = $wpdb->get_results(
            "SELECT * FROM {$redirects_table} ORDER BY created_at DESC",
            ARRAY_A
        );

        if ( ! is_array( $redirects ) ) {
            $redirects = [];
        }

        // Fetch 404 log.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $log_entries = $wpdb->get_results(
            "SELECT * FROM {$log_table} ORDER BY hit_count DESC, last_hit DESC LIMIT 100",
            ARRAY_A
        );

        if ( ! is_array( $log_entries ) ) {
            $log_entries = [];
        }

        $log_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table}" );
        ?>

        <!-- Settings -->
        <h3><?php esc_html_e( 'Settings', 'wptransformed' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Log 404 Errors', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_log_404s" value="1"
                               <?php checked( ! empty( $settings['log_404s'] ) ); ?>>
                        <?php esc_html_e( 'Track pages that return 404 errors', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-max-404-log"><?php esc_html_e( 'Max 404 Log Entries', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-max-404-log" name="wpt_max_404_log"
                           value="<?php echo esc_attr( (string) $settings['max_404_log'] ); ?>"
                           class="small-text" min="100" max="10000" step="100">
                    <p class="description">
                        <?php esc_html_e( 'Older entries are pruned daily when this limit is exceeded.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Auto Redirect Slugs', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_auto_redirect_slugs" value="1"
                               <?php checked( ! empty( $settings['auto_redirect_slugs'] ) ); ?>>
                        <?php esc_html_e( 'Automatically redirect old slugs when a post slug changes', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <!-- Tabs -->
        <div class="wpt-redirect-tabs">
            <button type="button" class="wpt-tab-button active" data-tab="redirects">
                <?php esc_html_e( 'Redirects', 'wptransformed' ); ?>
                <span class="wpt-tab-count"><?php echo esc_html( (string) count( $redirects ) ); ?></span>
            </button>
            <button type="button" class="wpt-tab-button" data-tab="404-log">
                <?php esc_html_e( '404 Log', 'wptransformed' ); ?>
                <span class="wpt-tab-count"><?php echo esc_html( (string) $log_count ); ?></span>
            </button>
            <button type="button" class="wpt-tab-button" data-tab="import-export">
                <?php esc_html_e( 'Import / Export', 'wptransformed' ); ?>
            </button>
        </div>

        <!-- Redirects Tab -->
        <div class="wpt-tab-content active" id="wpt-tab-redirects">

            <!-- Add Redirect Form -->
            <div class="wpt-redirect-add-form">
                <h3><?php esc_html_e( 'Add Redirect', 'wptransformed' ); ?></h3>
                <div class="wpt-redirect-form-row">
                    <div class="wpt-redirect-field">
                        <label for="wpt-add-source"><?php esc_html_e( 'Source URL', 'wptransformed' ); ?></label>
                        <input type="text" id="wpt-add-source" placeholder="/old-page" class="regular-text">
                    </div>
                    <div class="wpt-redirect-field">
                        <label for="wpt-add-target"><?php esc_html_e( 'Target URL', 'wptransformed' ); ?></label>
                        <input type="text" id="wpt-add-target" placeholder="<?php echo esc_attr( home_url( '/new-page' ) ); ?>" class="regular-text">
                    </div>
                    <div class="wpt-redirect-field wpt-redirect-field-type">
                        <label for="wpt-add-type"><?php esc_html_e( 'Type', 'wptransformed' ); ?></label>
                        <select id="wpt-add-type">
                            <option value="301"><?php esc_html_e( '301 Permanent', 'wptransformed' ); ?></option>
                            <option value="302"><?php esc_html_e( '302 Temporary', 'wptransformed' ); ?></option>
                            <option value="307"><?php esc_html_e( '307 Temporary (strict)', 'wptransformed' ); ?></option>
                        </select>
                    </div>
                    <div class="wpt-redirect-field wpt-redirect-field-action">
                        <label>&nbsp;</label>
                        <button type="button" class="button button-primary" id="wpt-add-redirect-btn">
                            <?php esc_html_e( 'Add Redirect', 'wptransformed' ); ?>
                        </button>
                        <span class="spinner" id="wpt-add-spinner"></span>
                    </div>
                </div>
            </div>

            <!-- Redirects Table -->
            <table class="widefat fixed striped wpt-redirects-table" id="wpt-redirects-table">
                <thead>
                    <tr>
                        <th class="wpt-col-source"><?php esc_html_e( 'Source URL', 'wptransformed' ); ?></th>
                        <th class="wpt-col-target"><?php esc_html_e( 'Target URL', 'wptransformed' ); ?></th>
                        <th class="wpt-col-type"><?php esc_html_e( 'Type', 'wptransformed' ); ?></th>
                        <th class="wpt-col-hits"><?php esc_html_e( 'Hits', 'wptransformed' ); ?></th>
                        <th class="wpt-col-status"><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                        <th class="wpt-col-actions"><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody id="wpt-redirects-tbody">
                    <?php if ( empty( $redirects ) ) : ?>
                    <tr class="wpt-no-redirects">
                        <td colspan="6"><?php esc_html_e( 'No redirects configured yet.', 'wptransformed' ); ?></td>
                    </tr>
                    <?php else : ?>
                        <?php foreach ( $redirects as $redirect ) : ?>
                        <tr data-id="<?php echo esc_attr( (string) $redirect['id'] ); ?>">
                            <td class="wpt-col-source">
                                <code><?php echo esc_html( $redirect['source_url'] ); ?></code>
                            </td>
                            <td class="wpt-col-target">
                                <span class="wpt-target-url"><?php echo esc_html( $redirect['target_url'] ); ?></span>
                            </td>
                            <td class="wpt-col-type">
                                <span class="wpt-redirect-type-badge"><?php echo esc_html( (string) $redirect['redirect_type'] ); ?></span>
                            </td>
                            <td class="wpt-col-hits"><?php echo esc_html( number_format_i18n( (int) $redirect['hit_count'] ) ); ?></td>
                            <td class="wpt-col-status">
                                <button type="button" class="button button-small wpt-toggle-redirect <?php echo ( (int) $redirect['is_active'] === 1 ) ? 'wpt-active' : 'wpt-inactive'; ?>"
                                        data-id="<?php echo esc_attr( (string) $redirect['id'] ); ?>">
                                    <?php echo ( (int) $redirect['is_active'] === 1 ) ? esc_html__( 'Active', 'wptransformed' ) : esc_html__( 'Inactive', 'wptransformed' ); ?>
                                </button>
                            </td>
                            <td class="wpt-col-actions">
                                <button type="button" class="button button-small wpt-edit-redirect"
                                        data-id="<?php echo esc_attr( (string) $redirect['id'] ); ?>"
                                        data-source="<?php echo esc_attr( $redirect['source_url'] ); ?>"
                                        data-target="<?php echo esc_attr( $redirect['target_url'] ); ?>"
                                        data-type="<?php echo esc_attr( (string) $redirect['redirect_type'] ); ?>">
                                    <?php esc_html_e( 'Edit', 'wptransformed' ); ?>
                                </button>
                                <button type="button" class="button button-small button-link-delete wpt-delete-redirect"
                                        data-id="<?php echo esc_attr( (string) $redirect['id'] ); ?>">
                                    <?php esc_html_e( 'Delete', 'wptransformed' ); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 404 Log Tab -->
        <div class="wpt-tab-content" id="wpt-tab-404-log" style="display: none;">
            <div class="wpt-404-log-header">
                <p>
                    <?php
                    printf(
                        /* translators: %s: number of 404 entries */
                        esc_html__( 'Total logged 404 errors: %s', 'wptransformed' ),
                        '<strong>' . esc_html( number_format_i18n( $log_count ) ) . '</strong>'
                    );
                    ?>
                </p>
                <?php if ( $log_count > 0 ) : ?>
                <button type="button" class="button button-secondary" id="wpt-clear-404-log">
                    <?php esc_html_e( 'Clear 404 Log', 'wptransformed' ); ?>
                </button>
                <?php endif; ?>
            </div>

            <table class="widefat fixed striped wpt-404-table" id="wpt-404-table">
                <thead>
                    <tr>
                        <th class="wpt-col-url"><?php esc_html_e( 'URL', 'wptransformed' ); ?></th>
                        <th class="wpt-col-referrer"><?php esc_html_e( 'Referrer', 'wptransformed' ); ?></th>
                        <th class="wpt-col-hits"><?php esc_html_e( 'Hits', 'wptransformed' ); ?></th>
                        <th class="wpt-col-last-hit"><?php esc_html_e( 'Last Hit', 'wptransformed' ); ?></th>
                        <th class="wpt-col-actions"><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $log_entries ) ) : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e( 'No 404 errors logged yet.', 'wptransformed' ); ?></td>
                    </tr>
                    <?php else : ?>
                        <?php foreach ( $log_entries as $entry ) : ?>
                        <tr>
                            <td class="wpt-col-url">
                                <code><?php echo esc_html( $entry['url'] ); ?></code>
                            </td>
                            <td class="wpt-col-referrer">
                                <?php if ( ! empty( $entry['referrer'] ) ) : ?>
                                    <a href="<?php echo esc_url( $entry['referrer'] ); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html( wp_parse_url( $entry['referrer'], PHP_URL_HOST ) ?? $entry['referrer'] ); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="wpt-muted"><?php esc_html_e( 'Direct', 'wptransformed' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="wpt-col-hits"><?php echo esc_html( number_format_i18n( (int) $entry['hit_count'] ) ); ?></td>
                            <td class="wpt-col-last-hit"><?php echo esc_html( $entry['last_hit'] ); ?></td>
                            <td class="wpt-col-actions">
                                <button type="button" class="button button-small wpt-create-redirect-from-404"
                                        data-url="<?php echo esc_attr( $entry['url'] ); ?>">
                                    <?php esc_html_e( 'Create Redirect', 'wptransformed' ); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Import/Export Tab -->
        <div class="wpt-tab-content" id="wpt-tab-import-export" style="display: none;">
            <div class="wpt-import-export-section">
                <h3><?php esc_html_e( 'Export Redirects', 'wptransformed' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Download all redirect rules as a CSV file.', 'wptransformed' ); ?>
                </p>
                <button type="button" class="button button-secondary" id="wpt-export-redirects">
                    <?php esc_html_e( 'Export CSV', 'wptransformed' ); ?>
                </button>
            </div>

            <div class="wpt-import-export-section" style="margin-top: 24px;">
                <h3><?php esc_html_e( 'Import Redirects', 'wptransformed' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'Paste CSV data below. Format: source_url,target_url,redirect_type,is_active', 'wptransformed' ); ?>
                </p>
                <textarea id="wpt-import-csv" rows="8" class="large-text code"
                          placeholder="<?php esc_attr_e( '/old-page,https://example.com/new-page,301,1', 'wptransformed' ); ?>"></textarea>
                <p style="margin-top: 8px;">
                    <button type="button" class="button button-primary" id="wpt-import-redirects">
                        <?php esc_html_e( 'Import CSV', 'wptransformed' ); ?>
                    </button>
                    <span class="spinner" id="wpt-import-spinner"></span>
                </p>
            </div>
        </div>

        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $log_404s = ! empty( $raw['wpt_log_404s'] );

        $max_404_log = isset( $raw['wpt_max_404_log'] ) ? absint( $raw['wpt_max_404_log'] ) : 1000;
        if ( $max_404_log < 100 ) {
            $max_404_log = 100;
        }
        if ( $max_404_log > 10000 ) {
            $max_404_log = 10000;
        }

        $auto_redirect_slugs = ! empty( $raw['wpt_auto_redirect_slugs'] );

        return [
            'log_404s'            => $log_404s,
            'max_404_log'         => $max_404_log,
            'auto_redirect_slugs' => $auto_redirect_slugs,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-redirect-manager',
            WPT_URL . 'modules/utilities/css/redirect-manager.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-redirect-manager',
            WPT_URL . 'modules/utilities/js/redirect-manager.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-redirect-manager', 'wptRedirectManager', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_redirect_manager_nonce' ),
            'i18n'    => [
                'confirmDelete'    => __( 'Are you sure you want to delete this redirect?', 'wptransformed' ),
                'confirmClear404'  => __( 'Are you sure you want to clear the entire 404 log?', 'wptransformed' ),
                'confirmImport'    => __( 'Import redirects from CSV? Duplicate source URLs will be skipped.', 'wptransformed' ),
                'networkError'     => __( 'Network error. Please try again.', 'wptransformed' ),
                'sourceRequired'   => __( 'Source URL is required and must start with /.', 'wptransformed' ),
                'targetRequired'   => __( 'Target URL is required.', 'wptransformed' ),
                'noExportData'     => __( 'No redirects to export.', 'wptransformed' ),
                'emptyCsv'         => __( 'Please paste CSV data to import.', 'wptransformed' ),
                'active'           => __( 'Active', 'wptransformed' ),
                'inactive'         => __( 'Inactive', 'wptransformed' ),
                'editPromptSource' => __( 'Edit source URL:', 'wptransformed' ),
                'editPromptTarget' => __( 'Edit target URL:', 'wptransformed' ),
                'saving'           => __( 'Saving...', 'wptransformed' ),
                'importing'        => __( 'Importing...', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        global $wpdb;

        return [
            'drop_table:' . $wpdb->prefix . 'wpt_redirects',
            'drop_table:' . $wpdb->prefix . 'wpt_404_log',
            'delete_transient:' . self::CACHE_KEY,
            'delete_transient:' . self::DB_VERSION_KEY,
        ];
    }
}
