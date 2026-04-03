<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Error Log Viewer -- View, search, filter, clear, and download the PHP error log.
 *
 * Features:
 *  - Auto-detect log path from WP_DEBUG_LOG constant
 *  - Display last 500 lines, most recent first
 *  - JS-based filter by error type (Fatal, Warning, Notice, Deprecated)
 *  - JS-based keyword search
 *  - Clear log via AJAX (truncate file)
 *  - Download log as file
 *  - Security: validates log path is within ABSPATH
 *  - Handles missing or oversized (>10MB) log files gracefully
 *
 * @package WPTransformed
 */
class Error_Log_Viewer extends Module_Base {

    /**
     * Maximum lines to read from the log file.
     */
    private const MAX_LINES = 500;

    /**
     * Maximum file size in bytes (10 MB).
     */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'error-log-viewer';
    }

    public function get_title(): string {
        return __( 'Error Log Viewer', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'View, search, and manage the PHP error log directly from the WordPress admin.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'enabled'  => true,
            'log_path' => '',
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        add_action( 'wp_ajax_wpt_error_log_clear',    [ $this, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_wpt_error_log_download', [ $this, 'ajax_download_log' ] );
    }

    // -- Log Path Resolution ----------------------------------------------

    /**
     * Resolve the error log file path.
     *
     * Priority:
     *  1. Module setting log_path (if non-empty and valid)
     *  2. WP_DEBUG_LOG constant (if it's a string path)
     *  3. Default wp-content/debug.log
     *
     * @return string Resolved path (may not exist).
     */
    private function resolve_log_path(): string {
        $settings = $this->get_settings();

        if ( ! empty( $settings['log_path'] ) ) {
            return (string) $settings['log_path'];
        }

        if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG !== '' && WP_DEBUG_LOG !== '1' ) {
            return WP_DEBUG_LOG;
        }

        return ABSPATH . 'wp-content/debug.log';
    }

    /**
     * Validate that a log path is within ABSPATH for security.
     *
     * @param string $path The path to validate.
     * @return bool True if the path is safe to read.
     */
    private function is_path_safe( string $path ): bool {
        $real_path = realpath( $path );
        if ( $real_path === false ) {
            // File doesn't exist yet -- check the directory.
            $dir = realpath( dirname( $path ) );
            if ( $dir === false ) {
                return false;
            }
            $real_path = $dir . DIRECTORY_SEPARATOR . basename( $path );
        }

        $real_abspath = realpath( ABSPATH );
        if ( $real_abspath === false ) {
            return false;
        }

        // Normalize separators for comparison.
        $real_path    = str_replace( '\\', '/', $real_path );
        $real_abspath = str_replace( '\\', '/', $real_abspath );

        // Append separator to prevent sibling-directory bypass (e.g. /html_evil matching /html).
        $safe_prefix = rtrim( $real_abspath, '/' ) . '/';
        return strpos( $real_path, $safe_prefix ) === 0 || $real_path === rtrim( $real_abspath, '/' );
    }

    // -- AJAX: Clear Log --------------------------------------------------

    /**
     * Clear (truncate) the error log file.
     */
    public function ajax_clear_log(): void {
        check_ajax_referer( 'wpt_error_log_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $path = $this->resolve_log_path();

        if ( ! $this->is_path_safe( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Log file path is outside the WordPress installation.', 'wptransformed' ) ] );
        }

        if ( ! file_exists( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Log file does not exist.', 'wptransformed' ) ] );
        }

        if ( ! is_writable( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Log file is not writable.', 'wptransformed' ) ] );
        }

        // Truncate the file.
        $handle = fopen( $path, 'w' );
        if ( $handle === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to open log file for writing.', 'wptransformed' ) ] );
        }
        fclose( $handle );

        wp_send_json_success( [ 'message' => __( 'Error log cleared.', 'wptransformed' ) ] );
    }

    // -- AJAX: Download Log -----------------------------------------------

    /**
     * Download the error log file.
     */
    public function ajax_download_log(): void {
        check_ajax_referer( 'wpt_error_log_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wptransformed' ) );
        }

        $path = $this->resolve_log_path();

        if ( ! $this->is_path_safe( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
            wp_die( esc_html__( 'Unable to access log file.', 'wptransformed' ) );
        }

        $filename = 'debug-log-' . gmdate( 'Y-m-d-His' ) . '.log';

        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile( $path );
        exit;
    }

    // -- Read Log Lines ---------------------------------------------------

    /**
     * Read the last N lines from the log file.
     *
     * @return array{lines: string[], error: string}
     */
    private function read_log_lines(): array {
        $path = $this->resolve_log_path();

        if ( ! $this->is_path_safe( $path ) ) {
            return [
                'lines' => [],
                'error' => __( 'Log file path is outside the WordPress installation directory. Please configure a valid path.', 'wptransformed' ),
            ];
        }

        if ( ! file_exists( $path ) ) {
            return [
                'lines' => [],
                'error' => sprintf(
                    /* translators: %s: file path */
                    __( 'Log file not found at: %s. Enable WP_DEBUG_LOG in wp-config.php to create it.', 'wptransformed' ),
                    $path
                ),
            ];
        }

        if ( ! is_readable( $path ) ) {
            return [
                'lines' => [],
                'error' => __( 'Log file exists but is not readable. Check file permissions.', 'wptransformed' ),
            ];
        }

        $size = filesize( $path );
        if ( $size !== false && $size > self::MAX_FILE_SIZE ) {
            return [
                'lines' => [],
                'error' => sprintf(
                    /* translators: 1: file size in MB, 2: max size in MB */
                    __( 'Log file is too large (%1$s MB). Maximum supported size is %2$s MB. Please clear or rotate the log file.', 'wptransformed' ),
                    number_format( $size / 1024 / 1024, 1 ),
                    number_format( self::MAX_FILE_SIZE / 1024 / 1024, 0 )
                ),
            ];
        }

        // Read the file and get the last MAX_LINES lines.
        $all_lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( $all_lines === false ) {
            return [
                'lines' => [],
                'error' => __( 'Failed to read the log file.', 'wptransformed' ),
            ];
        }

        // Take last N lines and reverse so most recent is first.
        $lines = array_slice( $all_lines, -self::MAX_LINES );
        $lines = array_reverse( $lines );

        return [
            'lines' => $lines,
            'error' => '',
        ];
    }

    // -- Render Settings --------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        $log_path = $this->resolve_log_path();
        $log_data = $this->read_log_lines();
        ?>

        <!-- Settings -->
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-error-log-path"><?php esc_html_e( 'Log File Path', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-error-log-path" name="wpt_log_path"
                           value="<?php echo esc_attr( $settings['log_path'] ); ?>"
                           class="large-text" placeholder="<?php echo esc_attr( $log_path ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'Leave empty to auto-detect from WP_DEBUG_LOG. Currently using:', 'wptransformed' ); ?>
                        <code><?php echo esc_html( $log_path ); ?></code>
                    </p>
                </td>
            </tr>
        </table>

        <hr>

        <!-- Error Log Viewer -->
        <div class="wpt-error-log-viewer">
            <div class="wpt-error-log-toolbar" style="display: flex; gap: 10px; align-items: center; margin-bottom: 15px; flex-wrap: wrap;">
                <input type="text" id="wpt-error-log-search" class="regular-text"
                       placeholder="<?php esc_attr_e( 'Search log entries...', 'wptransformed' ); ?>"
                       style="flex: 1; min-width: 200px;">

                <select id="wpt-error-log-filter">
                    <option value="all"><?php esc_html_e( 'All Types', 'wptransformed' ); ?></option>
                    <option value="fatal"><?php esc_html_e( 'Fatal Error', 'wptransformed' ); ?></option>
                    <option value="warning"><?php esc_html_e( 'Warning', 'wptransformed' ); ?></option>
                    <option value="notice"><?php esc_html_e( 'Notice', 'wptransformed' ); ?></option>
                    <option value="deprecated"><?php esc_html_e( 'Deprecated', 'wptransformed' ); ?></option>
                    <option value="parse"><?php esc_html_e( 'Parse Error', 'wptransformed' ); ?></option>
                </select>

                <span id="wpt-error-log-count" style="color: #666;"></span>

                <div style="margin-left: auto; display: flex; gap: 5px;">
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=wpt_error_log_download' ), 'wpt_error_log_nonce', 'nonce' ) ); ?>"
                       class="button button-secondary" id="wpt-error-log-download-btn">
                        <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                        <?php esc_html_e( 'Download', 'wptransformed' ); ?>
                    </a>

                    <button type="button" class="button button-secondary" id="wpt-error-log-clear-btn"
                            style="color: #a00;">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                        <?php esc_html_e( 'Clear Log', 'wptransformed' ); ?>
                    </button>
                    <span class="spinner" id="wpt-error-log-spinner" style="float: none;"></span>
                </div>
            </div>

            <?php if ( ! empty( $log_data['error'] ) ) : ?>
                <div class="notice notice-warning inline" style="margin: 10px 0;">
                    <p><?php echo esc_html( $log_data['error'] ); ?></p>
                </div>
            <?php elseif ( empty( $log_data['lines'] ) ) : ?>
                <div class="notice notice-success inline" style="margin: 10px 0;">
                    <p><?php esc_html_e( 'The error log is empty. No errors to display.', 'wptransformed' ); ?></p>
                </div>
            <?php else : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: number of lines */
                        esc_html__( 'Showing the last %d log entries (most recent first).', 'wptransformed' ),
                        count( $log_data['lines'] )
                    );
                    ?>
                </p>
                <div class="wpt-error-log-entries" id="wpt-error-log-entries"
                     style="max-height: 600px; overflow-y: auto; background: #1d1f21; color: #c5c8c6; font-family: monospace; font-size: 12px; padding: 10px; border-radius: 4px; border: 1px solid #ccc;">
                    <?php foreach ( $log_data['lines'] as $index => $line ) :
                        $type_class = $this->classify_line( $line );
                    ?>
                        <div class="wpt-log-line" data-type="<?php echo esc_attr( $type_class ); ?>"
                             style="padding: 2px 5px; border-bottom: 1px solid #333; white-space: pre-wrap; word-break: break-all;">
                            <?php echo esc_html( $line ); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Classify a log line by error type.
     *
     * @param string $line The log line.
     * @return string Type key: fatal, warning, notice, deprecated, parse, or other.
     */
    private function classify_line( string $line ): string {
        $lower = strtolower( $line );

        if ( strpos( $lower, 'fatal error' ) !== false ) {
            return 'fatal';
        }
        if ( strpos( $lower, 'parse error' ) !== false ) {
            return 'parse';
        }
        if ( strpos( $lower, 'warning' ) !== false ) {
            return 'warning';
        }
        if ( strpos( $lower, 'deprecated' ) !== false ) {
            return 'deprecated';
        }
        if ( strpos( $lower, 'notice' ) !== false ) {
            return 'notice';
        }

        return 'other';
    }

    // -- Sanitize Settings ------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        $log_path = isset( $raw['wpt_log_path'] ) ? sanitize_text_field( wp_unslash( $raw['wpt_log_path'] ) ) : '';

        // Validate custom path if provided.
        if ( $log_path !== '' && ! $this->is_path_safe( $log_path ) ) {
            add_settings_error(
                'wpt_error_log_viewer',
                'invalid_path',
                __( 'The log file path must be within the WordPress installation directory.', 'wptransformed' ),
                'error'
            );
            $log_path = '';
        }

        return [
            'enabled'  => true,
            'log_path' => $log_path,
        ];
    }

    // -- Assets -----------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'wpt-error-log-viewer',
            WPT_URL . 'modules/utilities/js/error-log-viewer.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-error-log-viewer', 'wptErrorLogViewer', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_error_log_nonce' ),
            'i18n'    => [
                'confirmClear' => __( 'Are you sure you want to clear the error log? This cannot be undone.', 'wptransformed' ),
                'clearing'     => __( 'Clearing...', 'wptransformed' ),
                'cleared'      => __( 'Error log cleared. Reload the page to see changes.', 'wptransformed' ),
                'networkError' => __( 'Network error. Please try again.', 'wptransformed' ),
                'showing'      => __( 'Showing', 'wptransformed' ),
                'of'           => __( 'of', 'wptransformed' ),
                'entries'      => __( 'entries', 'wptransformed' ),
            ],
        ] );
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
