<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Search Replace -- Database-wide search and replace with safe serialized data handling.
 *
 * Features:
 *  - Dry run mode (preview matches without changing data)
 *  - Safe serialized PHP data handling with UTF-8 length recalculation
 *  - JSON data handling (Elementor, Gutenberg, DIVI, Bricks)
 *  - Batch processing by primary key range (NOT OFFSET)
 *  - Undo via wpt_replace_log table
 *  - Case sensitivity toggle
 *  - Regex mode (advanced)
 *  - Multisite support
 *  - Always excludes wp_users.user_pass
 *  - Cache clearing after replace
 *
 * @package WPTransformed
 */
class Search_Replace extends Module_Base {

    /**
     * DB version key for migration tracking.
     */
    private const DB_VERSION_KEY = 'wpt_search_replace_db_version';

    /**
     * Current DB schema version.
     */
    private const DB_VERSION = '1.0';

    /**
     * Columns that must NEVER be modified.
     *
     * @var array<string, string[]>
     */
    private const EXCLUDED_COLUMNS = [
        'users' => [ 'user_pass' ],
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'search-replace';
    }

    public function get_title(): string {
        return __( 'Search & Replace', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Database-wide search and replace with safe serialized data handling and undo support.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'           => true,
            'max_batch_size'    => 50,
            'backup_reminder'   => true,
            'exclude_tables'    => [],
            'log_replacements'  => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $this->maybe_create_tables();

        // AJAX handlers -- admin only.
        add_action( 'wp_ajax_wpt_search_replace_tables',  [ $this, 'ajax_list_tables' ] );
        add_action( 'wp_ajax_wpt_search_replace_dry_run', [ $this, 'ajax_dry_run' ] );
        add_action( 'wp_ajax_wpt_search_replace_run',     [ $this, 'ajax_run' ] );
        add_action( 'wp_ajax_wpt_search_replace_undo',    [ $this, 'ajax_undo' ] );
    }

    // ── Table Creation ────────────────────────────────────────

    /**
     * Create the replace log table if it doesn't exist.
     */
    private function maybe_create_tables(): void {
        $installed_version = get_option( self::DB_VERSION_KEY, '' );

        if ( $installed_version === self::DB_VERSION ) {
            return;
        }

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $wpdb->prefix . 'wpt_replace_log';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_id VARCHAR(36) NOT NULL,
            table_name VARCHAR(64) NOT NULL,
            column_name VARCHAR(64) NOT NULL,
            row_id BIGINT UNSIGNED NOT NULL,
            old_value LONGTEXT,
            new_value LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_run (run_id),
            INDEX idx_created (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
    }

    // ── Core: Safe Replace ────────────────────────────────────

    /**
     * Replace within a value, handling serialized PHP, JSON, and plain strings.
     *
     * @param string $data           Raw DB value.
     * @param string $search         Search string.
     * @param string $replace        Replacement string.
     * @param bool   $case_sensitive Case-sensitive matching.
     * @param bool   $regex          Whether $search is a regex pattern.
     * @return string Replaced value.
     */
    private function safe_replace( string $data, string $search, string $replace, bool $case_sensitive = true, bool $regex = false ): string {
        // Check for serialized PHP data first.
        if ( is_serialized( $data ) ) {
            $unserialized = @unserialize( $data );
            if ( $unserialized !== false || $data === 'b:0;' ) {
                $replaced = $this->recursive_replace( $unserialized, $search, $replace, $case_sensitive, $regex );
                return serialize( $replaced );
            }
        }

        // Check for JSON data (Elementor _elementor_data, etc.).
        $decoded = json_decode( $data, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $replaced = $this->recursive_replace( $decoded, $search, $replace, $case_sensitive, $regex );
            return (string) wp_json_encode( $replaced );
        }

        // Plain string replacement.
        return $this->string_replace( $data, $search, $replace, $case_sensitive, $regex );
    }

    /**
     * Recursively walk arrays/objects and replace string values.
     *
     * @param mixed  $data           Data to walk.
     * @param string $search         Search string.
     * @param string $replace        Replacement string.
     * @param bool   $case_sensitive Case-sensitive matching.
     * @param bool   $regex          Regex mode.
     * @return mixed Replaced data with correct types preserved.
     */
    private function recursive_replace( $data, string $search, string $replace, bool $case_sensitive = true, bool $regex = false ) {
        if ( is_string( $data ) ) {
            // Handle nested serialized data.
            if ( is_serialized( $data ) ) {
                $nested = @unserialize( $data );
                if ( $nested !== false || $data === 'b:0;' ) {
                    $replaced = $this->recursive_replace( $nested, $search, $replace, $case_sensitive, $regex );
                    return serialize( $replaced );
                }
            }

            // Handle nested JSON.
            $decoded = json_decode( $data, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $replaced = $this->recursive_replace( $decoded, $search, $replace, $case_sensitive, $regex );
                return (string) wp_json_encode( $replaced );
            }

            return $this->string_replace( $data, $search, $replace, $case_sensitive, $regex );
        }

        if ( is_array( $data ) ) {
            $result = [];
            foreach ( $data as $key => $value ) {
                $new_key          = is_string( $key ) ? $this->string_replace( $key, $search, $replace, $case_sensitive, $regex ) : $key;
                $result[ $new_key ] = $this->recursive_replace( $value, $search, $replace, $case_sensitive, $regex );
            }
            return $result;
        }

        if ( is_object( $data ) ) {
            $props = get_object_vars( $data );
            foreach ( $props as $key => $value ) {
                $data->$key = $this->recursive_replace( $value, $search, $replace, $case_sensitive, $regex );
            }
            return $data;
        }

        // Non-string, non-array, non-object: return as-is.
        return $data;
    }

    /**
     * Perform a single string replacement.
     *
     * @param string $data           Haystack.
     * @param string $search         Needle or regex pattern.
     * @param string $replace        Replacement.
     * @param bool   $case_sensitive Case-sensitive.
     * @param bool   $regex          Regex mode.
     * @return string
     */
    private function string_replace( string $data, string $search, string $replace, bool $case_sensitive = true, bool $regex = false ): string {
        if ( $regex ) {
            $result = @preg_replace( $search, $replace, $data );
            return ( $result !== null ) ? $result : $data;
        }

        if ( $case_sensitive ) {
            return str_replace( $search, $replace, $data );
        }

        // Case-insensitive, UTF-8 safe.
        return str_ireplace( $search, $replace, $data );
    }

    /**
     * Count matches in a value (for dry run).
     *
     * @param string $data           Raw DB value.
     * @param string $search         Search string.
     * @param bool   $case_sensitive Case-sensitive.
     * @param bool   $regex          Regex mode.
     * @return int Match count.
     */
    private function count_matches( string $data, string $search, bool $case_sensitive = true, bool $regex = false ): int {
        // For serialized/JSON data, work on the unserialized plain text.
        $plain = $this->flatten_to_strings( $data );

        $count = 0;
        foreach ( $plain as $str ) {
            if ( $regex ) {
                $matches = @preg_match_all( $search, $str );
                if ( $matches !== false ) {
                    $count += $matches;
                }
            } elseif ( $case_sensitive ) {
                $count += substr_count( $str, $search );
            } else {
                $count += substr_count( mb_strtolower( $str, 'UTF-8' ), mb_strtolower( $search, 'UTF-8' ) );
            }
        }

        return $count;
    }

    /**
     * Flatten a possibly serialized/JSON value to an array of plain strings.
     *
     * @param string $data Raw DB value.
     * @return string[] All string leaf values.
     */
    private function flatten_to_strings( string $data ): array {
        if ( is_serialized( $data ) ) {
            $unserialized = @unserialize( $data );
            if ( $unserialized !== false || $data === 'b:0;' ) {
                return $this->collect_strings( $unserialized );
            }
        }

        $decoded = json_decode( $data, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $this->collect_strings( $decoded );
        }

        return [ $data ];
    }

    /**
     * Collect all string values from a nested structure.
     *
     * @param mixed $data Data to traverse.
     * @return string[]
     */
    private function collect_strings( $data ): array {
        if ( is_string( $data ) ) {
            // Recurse into nested serialized/JSON.
            if ( is_serialized( $data ) ) {
                $nested = @unserialize( $data );
                if ( $nested !== false || $data === 'b:0;' ) {
                    return $this->collect_strings( $nested );
                }
            }
            $decoded = json_decode( $data, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $this->collect_strings( $decoded );
            }
            return [ $data ];
        }

        if ( is_array( $data ) || is_object( $data ) ) {
            $strings = [];
            foreach ( (array) $data as $value ) {
                $strings = array_merge( $strings, $this->collect_strings( $value ) );
            }
            return $strings;
        }

        return [];
    }

    // ── Table Discovery ───────────────────────────────────────

    /**
     * Get all tables with the WP prefix.
     *
     * @return array<array{name: string, rows: int}>
     */
    private function get_wp_tables(): array {
        global $wpdb;

        $settings       = $this->get_settings();
        $exclude_tables = is_array( $settings['exclude_tables'] ) ? $settings['exclude_tables'] : [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $tables = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW TABLE STATUS LIKE %s",
                $wpdb->esc_like( $wpdb->prefix ) . '%'
            ),
            ARRAY_A
        );

        if ( ! is_array( $tables ) ) {
            return [];
        }

        $result = [];
        foreach ( $tables as $table ) {
            $name = $table['Name'] ?? '';
            if ( empty( $name ) ) {
                continue;
            }

            // Skip excluded tables.
            if ( in_array( $name, $exclude_tables, true ) ) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'rows' => (int) ( $table['Rows'] ?? 0 ),
            ];
        }

        return $result;
    }

    /**
     * Get column metadata for a table (cached per request to avoid duplicate SHOW COLUMNS queries).
     *
     * @param string $table Full table name.
     * @return array{text_columns: string[], primary_key: string}
     */
    private function get_table_meta( string $table ): array {
        static $cache = [];

        if ( isset( $cache[ $table ] ) ) {
            return $cache[ $table ];
        }

        $meta = [ 'text_columns' => [], 'primary_key' => '' ];

        global $wpdb;

        // Validate table name is prefixed.
        if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
            $cache[ $table ] = $meta;
            return $meta;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );

        if ( ! is_array( $columns ) ) {
            $cache[ $table ] = $meta;
            return $meta;
        }

        $text_types   = [ 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext' ];
        $table_suffix = str_replace( $wpdb->prefix, '', $table );

        foreach ( $columns as $col ) {
            $field = $col['Field'] ?? '';
            $type  = strtolower( $col['Type'] ?? '' );

            if ( empty( $field ) ) {
                continue;
            }

            // Primary key detection.
            if ( ( $col['Key'] ?? '' ) === 'PRI' && $meta['primary_key'] === '' ) {
                $meta['primary_key'] = $field;
            }

            // Check excluded columns.
            if ( isset( self::EXCLUDED_COLUMNS[ $table_suffix ] ) && in_array( $field, self::EXCLUDED_COLUMNS[ $table_suffix ], true ) ) {
                continue;
            }

            // Only text-type columns.
            foreach ( $text_types as $text_type ) {
                if ( strpos( $type, $text_type ) !== false ) {
                    $meta['text_columns'][] = $field;
                    break;
                }
            }
        }

        $cache[ $table ] = $meta;
        return $meta;
    }

    /**
     * Build a WHERE clause for matching text columns against a search string.
     *
     * @param string[] $columns        Text column names.
     * @param string   $search         Search string.
     * @param bool     $regex          Whether search is a regex pattern.
     * @return string SQL WHERE clause fragment.
     */
    private function build_match_where( array $columns, string $search, bool $regex ): string {
        global $wpdb;

        $conditions = [];
        foreach ( $columns as $col ) {
            if ( $regex ) {
                $conditions[] = $wpdb->prepare( "`{$col}` IS NOT NULL AND `{$col}` != %s", '' );
            } else {
                $like = '%' . $wpdb->esc_like( $search ) . '%';
                $conditions[] = $wpdb->prepare( "`{$col}` LIKE %s", $like );
            }
        }

        return implode( ' OR ', $conditions );
    }

    // ── AJAX: List Tables ─────────────────────────────────────

    /**
     * Return available tables for the search/replace UI.
     */
    public function ajax_list_tables(): void {
        check_ajax_referer( 'wpt_search_replace_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $tables = $this->get_wp_tables();

        wp_send_json_success( [ 'tables' => $tables ] );
    }

    // ── AJAX: Dry Run ─────────────────────────────────────────

    /**
     * Preview matches without modifying data.
     */
    public function ajax_dry_run(): void {
        check_ajax_referer( 'wpt_search_replace_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $search         = isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : '';
        $tables         = isset( $_POST['tables'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tables'] ) ) : [];
        $case_sensitive = ! isset( $_POST['case_sensitive'] ) || $_POST['case_sensitive'] === '1';
        $regex          = isset( $_POST['regex'] ) && $_POST['regex'] === '1';

        if ( $search === '' ) {
            wp_send_json_error( [ 'message' => __( 'Search string cannot be empty.', 'wptransformed' ) ] );
        }

        // Validate regex if enabled.
        if ( $regex && @preg_match( $search, '' ) === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid regular expression pattern.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $results     = [];
        $total_count = 0;

        $settings   = $this->get_settings();
        $batch_size = max( 1, min( 500, (int) $settings['max_batch_size'] ) );

        foreach ( $tables as $table ) {
            // Validate table belongs to this WP install.
            if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
                continue;
            }

            $meta    = $this->get_table_meta( $table );
            $columns = $meta['text_columns'];
            $pk      = $meta['primary_key'];

            if ( empty( $columns ) || empty( $pk ) ) {
                continue;
            }

            $where = $this->build_match_where( $columns, $search, $regex );

            // Count matching rows.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" );

            if ( $row_count === 0 ) {
                continue;
            }

            // Scan text values in batches to avoid loading entire table into memory.
            $table_count = 0;
            $last_pk     = 0;
            $col_list    = '`' . $pk . '`, `' . implode( '`, `', $columns ) . '`';

            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT {$col_list} FROM `{$table}` WHERE `{$pk}` > %d AND ({$where}) ORDER BY `{$pk}` ASC LIMIT %d",
                        $last_pk,
                        $batch_size
                    ),
                    ARRAY_A
                );

                if ( ! is_array( $rows ) || empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $last_pk = (int) ( $row[ $pk ] ?? 0 );
                    foreach ( $columns as $col ) {
                        if ( isset( $row[ $col ] ) && $row[ $col ] !== '' ) {
                            $table_count += $this->count_matches( $row[ $col ], $search, $case_sensitive, $regex );
                        }
                    }
                }
            } while ( count( $rows ) === $batch_size );

            if ( $table_count > 0 ) {
                $results[] = [
                    'table'   => $table,
                    'rows'    => $row_count,
                    'matches' => $table_count,
                ];
                $total_count += $table_count;
            }
        }

        wp_send_json_success( [
            'results' => $results,
            'total'   => $total_count,
        ] );
    }

    // ── AJAX: Run (batch) ─────────────────────────────────────

    /**
     * Execute search/replace on a single table batch.
     * Called repeatedly from JS until all tables/batches are done.
     */
    public function ajax_run(): void {
        check_ajax_referer( 'wpt_search_replace_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $search         = isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : '';
        $replace        = isset( $_POST['replace'] ) ? wp_unslash( $_POST['replace'] ) : '';
        $table          = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
        $run_id         = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
        $last_id        = isset( $_POST['last_id'] ) ? (int) $_POST['last_id'] : 0;
        $case_sensitive = ! isset( $_POST['case_sensitive'] ) || $_POST['case_sensitive'] === '1';
        $regex          = isset( $_POST['regex'] ) && $_POST['regex'] === '1';

        if ( $search === '' ) {
            wp_send_json_error( [ 'message' => __( 'Search string cannot be empty.', 'wptransformed' ) ] );
        }

        if ( $search === $replace ) {
            wp_send_json_error( [ 'message' => __( 'Search and replace strings are identical.', 'wptransformed' ) ] );
        }

        if ( $regex && @preg_match( $search, '' ) === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid regular expression pattern.', 'wptransformed' ) ] );
        }

        global $wpdb;

        // Validate table.
        if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid table.', 'wptransformed' ) ] );
        }

        $meta    = $this->get_table_meta( $table );
        $columns = $meta['text_columns'];
        $pk      = $meta['primary_key'];

        if ( empty( $columns ) || empty( $pk ) ) {
            wp_send_json_success( [ 'done' => true, 'replaced' => 0 ] );
        }

        $settings    = $this->get_settings();
        $batch_size  = max( 1, min( 500, (int) $settings['max_batch_size'] ) );
        $log_enabled = ! empty( $settings['log_replacements'] );
        $log_table   = $wpdb->prefix . 'wpt_replace_log';

        $where = $this->build_match_where( $columns, $search, $regex );

        $col_list = '`' . $pk . '`, `' . implode( '`, `', $columns ) . '`';

        // Batch by primary key range.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$col_list} FROM `{$table}` WHERE `{$pk}` > %d AND ({$where}) ORDER BY `{$pk}` ASC LIMIT %d",
                $last_id,
                $batch_size
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            wp_send_json_success( [ 'done' => true, 'replaced' => 0, 'last_id' => $last_id ] );
        }

        $replaced_count = 0;
        $new_last_id    = $last_id;

        foreach ( $rows as $row ) {
            $row_pk      = (int) ( $row[ $pk ] ?? 0 );
            $new_last_id = $row_pk;

            foreach ( $columns as $col ) {
                $old_value = $row[ $col ] ?? '';
                if ( $old_value === '' ) {
                    continue;
                }

                $new_value = $this->safe_replace( $old_value, $search, $replace, $case_sensitive, $regex );

                if ( $new_value === $old_value ) {
                    continue;
                }

                // Log for undo.
                if ( $log_enabled && $run_id !== '' ) {
                    $wpdb->insert(
                        $log_table,
                        [
                            'run_id'      => $run_id,
                            'table_name'  => $table,
                            'column_name' => $col,
                            'row_id'      => $row_pk,
                            'old_value'   => $old_value,
                            'new_value'   => $new_value,
                            'created_at'  => current_time( 'mysql', true ),
                        ],
                        [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
                    );
                }

                // Update the row.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->update(
                    $table,
                    [ $col => $new_value ],
                    [ $pk  => $row_pk ],
                    [ '%s' ],
                    [ '%d' ]
                );

                $replaced_count++;
            }
        }

        // Check if more rows remain.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$pk}` > %d AND ({$where})",
                $new_last_id
            )
        );

        $done = $remaining === 0;

        // Flush cache when done with this table.
        if ( $done ) {
            wp_cache_flush();
        }

        wp_send_json_success( [
            'done'     => $done,
            'replaced' => $replaced_count,
            'last_id'  => $new_last_id,
        ] );
    }

    // ── AJAX: Undo ────────────────────────────────────────────

    /**
     * Rollback a previous search/replace run by run_id.
     */
    public function ajax_undo(): void {
        check_ajax_referer( 'wpt_search_replace_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

        if ( $run_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Run ID is required.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $log_table = $wpdb->prefix . 'wpt_replace_log';

        // Get all log entries for this run, ordered newest first.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $entries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$log_table}` WHERE run_id = %s ORDER BY id DESC",
                $run_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $entries ) || empty( $entries ) ) {
            wp_send_json_error( [ 'message' => __( 'No log entries found for this run.', 'wptransformed' ) ] );
        }

        $restored = 0;
        $errors   = 0;

        foreach ( $entries as $entry ) {
            $tbl    = $entry['table_name'];
            $col    = $entry['column_name'];
            $row_id = (int) $entry['row_id'];
            $old    = $entry['old_value'];

            // Validate table prefix.
            if ( strpos( $tbl, $wpdb->prefix ) !== 0 ) {
                $errors++;
                continue;
            }

            $meta = $this->get_table_meta( $tbl );
            $pk   = $meta['primary_key'];
            if ( empty( $pk ) ) {
                $errors++;
                continue;
            }

            // Restore old value.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->update(
                $tbl,
                [ $col => $old ],
                [ $pk  => $row_id ],
                [ '%s' ],
                [ '%d' ]
            );

            if ( $result !== false ) {
                $restored++;
            } else {
                $errors++;
            }
        }

        // Delete log entries for this run.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete( $log_table, [ 'run_id' => $run_id ], [ '%s' ] );

        wp_cache_flush();

        wp_send_json_success( [
            'restored' => $restored,
            'errors'   => $errors,
            'message'  => sprintf(
                /* translators: 1: restored count, 2: error count */
                __( 'Undo complete: %1$d values restored, %2$d errors.', 'wptransformed' ),
                $restored,
                $errors
            ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        global $wpdb;

        $log_table = $wpdb->prefix . 'wpt_replace_log';

        // Get recent runs for undo list.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $recent_runs = $wpdb->get_results(
            "SELECT run_id, MIN(created_at) AS started_at, COUNT(*) AS changes
             FROM `{$log_table}`
             GROUP BY run_id
             ORDER BY started_at DESC
             LIMIT 20",
            ARRAY_A
        );

        if ( ! is_array( $recent_runs ) ) {
            $recent_runs = [];
        }
        ?>

        <!-- Settings -->
        <h3><?php esc_html_e( 'Module Settings', 'wptransformed' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-sr-batch-size"><?php esc_html_e( 'Batch Size', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-sr-batch-size" name="wpt_sr_max_batch_size"
                           value="<?php echo esc_attr( (string) $settings['max_batch_size'] ); ?>"
                           class="small-text" min="10" max="500" step="10">
                    <p class="description">
                        <?php esc_html_e( 'Number of rows to process per AJAX request. Lower = safer on shared hosting.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Backup Reminder', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_sr_backup_reminder" value="1"
                               <?php checked( ! empty( $settings['backup_reminder'] ) ); ?>>
                        <?php esc_html_e( 'Show backup reminder before running search/replace', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Log Replacements', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_sr_log_replacements" value="1"
                               <?php checked( ! empty( $settings['log_replacements'] ) ); ?>>
                        <?php esc_html_e( 'Store old values for undo support (uses additional database space)', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <!-- Search & Replace Tool -->
        <hr>
        <h3><?php esc_html_e( 'Search & Replace', 'wptransformed' ); ?></h3>

        <?php if ( ! empty( $settings['backup_reminder'] ) ) : ?>
        <div class="notice notice-warning inline" id="wpt-sr-backup-notice">
            <p>
                <strong><?php esc_html_e( 'Important:', 'wptransformed' ); ?></strong>
                <?php esc_html_e( 'Always create a full database backup before running search and replace operations.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <div id="wpt-sr-tool">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="wpt-sr-search"><?php esc_html_e( 'Search For', 'wptransformed' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wpt-sr-search" class="large-text"
                               placeholder="<?php esc_attr_e( 'e.g., http://old-domain.com', 'wptransformed' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wpt-sr-replace"><?php esc_html_e( 'Replace With', 'wptransformed' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wpt-sr-replace" class="large-text"
                               placeholder="<?php esc_attr_e( 'e.g., https://new-domain.com', 'wptransformed' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Tables', 'wptransformed' ); ?></th>
                    <td>
                        <div id="wpt-sr-tables-list">
                            <p class="description"><?php esc_html_e( 'Loading tables...', 'wptransformed' ); ?></p>
                        </div>
                        <p>
                            <button type="button" class="button button-small" id="wpt-sr-select-all">
                                <?php esc_html_e( 'Select All', 'wptransformed' ); ?>
                            </button>
                            <button type="button" class="button button-small" id="wpt-sr-deselect-all">
                                <?php esc_html_e( 'Deselect All', 'wptransformed' ); ?>
                            </button>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Options', 'wptransformed' ); ?></th>
                    <td>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" id="wpt-sr-case-sensitive" checked>
                            <?php esc_html_e( 'Case sensitive', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block;">
                            <input type="checkbox" id="wpt-sr-regex">
                            <?php esc_html_e( 'Regular expression (advanced)', 'wptransformed' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="button" class="button button-secondary" id="wpt-sr-dry-run">
                    <?php esc_html_e( 'Dry Run (Preview)', 'wptransformed' ); ?>
                </button>
                <button type="button" class="button button-primary" id="wpt-sr-execute" disabled>
                    <?php esc_html_e( 'Run Search & Replace', 'wptransformed' ); ?>
                </button>
                <span class="spinner" id="wpt-sr-spinner"></span>
            </p>

            <!-- Progress -->
            <div id="wpt-sr-progress" style="display: none;">
                <div class="wpt-sr-progress-bar">
                    <div class="wpt-sr-progress-fill" id="wpt-sr-progress-fill"></div>
                </div>
                <p id="wpt-sr-progress-text"></p>
            </div>

            <!-- Results -->
            <div id="wpt-sr-results" style="display: none;"></div>
        </div>

        <!-- Undo History -->
        <?php if ( ! empty( $recent_runs ) ) : ?>
        <hr>
        <h3><?php esc_html_e( 'Undo History', 'wptransformed' ); ?></h3>
        <table class="widefat striped" id="wpt-sr-undo-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Run ID', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Changes', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent_runs as $run ) : ?>
                <tr data-run-id="<?php echo esc_attr( $run['run_id'] ); ?>">
                    <td><code><?php echo esc_html( substr( $run['run_id'], 0, 8 ) ); ?></code></td>
                    <td><?php echo esc_html( $run['started_at'] ); ?></td>
                    <td><?php echo esc_html( (string) $run['changes'] ); ?></td>
                    <td>
                        <button type="button" class="button button-small wpt-sr-undo-btn"
                                data-run-id="<?php echo esc_attr( $run['run_id'] ); ?>">
                            <?php esc_html_e( 'Undo', 'wptransformed' ); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $batch_size = isset( $raw['wpt_sr_max_batch_size'] ) ? absint( $raw['wpt_sr_max_batch_size'] ) : 50;
        if ( $batch_size < 10 ) {
            $batch_size = 10;
        }
        if ( $batch_size > 500 ) {
            $batch_size = 500;
        }

        return [
            'enabled'          => true,
            'max_batch_size'   => $batch_size,
            'backup_reminder'  => ! empty( $raw['wpt_sr_backup_reminder'] ),
            'exclude_tables'   => [],
            'log_replacements' => ! empty( $raw['wpt_sr_log_replacements'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-search-replace',
            WPT_URL . 'modules/utilities/css/search-replace.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-search-replace',
            WPT_URL . 'modules/utilities/js/search-replace.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-search-replace', 'wptSearchReplace', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wpt_search_replace_nonce' ),
            'backupReminder' => ! empty( $this->get_settings()['backup_reminder'] ),
            'i18n'           => [
                'networkError'        => __( 'Network error. Please try again.', 'wptransformed' ),
                'searchRequired'      => __( 'Please enter a search string.', 'wptransformed' ),
                'noTablesSelected'    => __( 'Please select at least one table.', 'wptransformed' ),
                'confirmRun'          => __( 'Are you sure you want to run search & replace? This will modify your database.', 'wptransformed' ),
                'confirmBackup'       => __( 'Have you created a database backup? This operation modifies data directly.', 'wptransformed' ),
                'confirmUndo'         => __( 'Are you sure you want to undo this run? All changes will be reverted.', 'wptransformed' ),
                'dryRunComplete'      => __( 'Dry run complete.', 'wptransformed' ),
                'noMatches'           => __( 'No matches found.', 'wptransformed' ),
                'processing'          => __( 'Processing...', 'wptransformed' ),
                'complete'            => __( 'Search & replace complete!', 'wptransformed' ),
                'undoComplete'        => __( 'Undo complete.', 'wptransformed' ),
                'matchesFound'        => __( '%d match(es) found across %d table(s).', 'wptransformed' ),
                'replaced'            => __( '%d replacement(s) made.', 'wptransformed' ),
                'processingTable'     => __( 'Processing table: %s', 'wptransformed' ),
                'loadingTables'       => __( 'Loading tables...', 'wptransformed' ),
                'selectAll'           => __( 'Select All', 'wptransformed' ),
                'deselectAll'         => __( 'Deselect All', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        global $wpdb;

        return [
            'drop_table:' . $wpdb->prefix . 'wpt_replace_log',
            'delete_option:' . self::DB_VERSION_KEY,
        ];
    }
}
