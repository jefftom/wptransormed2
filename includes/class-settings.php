<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings Storage — Custom table, single query.
 *
 * @package WPTransformed
 */
class Settings {

    /** @var array|null Cached settings — loaded once per request */
    private static ?array $cache = null;

    /**
     * Load ALL module settings in a single query.
     * Called once during boot. Everything after reads from cache.
     */
    private static function load(): void {
        if ( self::$cache !== null ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        // Check if table exists (handles fresh install before activation hook runs)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results(
            "SELECT module_id, is_active, settings FROM {$table}",
            ARRAY_A
        );

        self::$cache = [];
        if ( $results ) {
            foreach ( $results as $row ) {
                self::$cache[ $row['module_id'] ] = [
                    'is_active' => (bool) $row['is_active'],
                    'settings'  => json_decode( $row['settings'], true ) ?: [],
                ];
            }
        }
    }

    /**
     * Get the list of active module IDs.
     * @return string[]
     */
    public static function get_active_modules(): array {
        self::load();
        $active = [];
        foreach ( self::$cache as $id => $data ) {
            if ( $data['is_active'] ) {
                $active[] = $id;
            }
        }
        return $active;
    }

    /**
     * Get settings for a specific module.
     * Returns empty array if module has no saved settings.
     */
    public static function get( string $module_id ): array {
        self::load();
        return self::$cache[ $module_id ]['settings'] ?? [];
    }

    /**
     * Save settings for a module. Uses REPLACE INTO (upsert).
     */
    public static function save( string $module_id, array $settings ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        $is_active = self::$cache[ $module_id ]['is_active'] ?? false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->replace(
            $table,
            [
                'module_id' => $module_id,
                'is_active' => (int) $is_active,
                'settings'  => wp_json_encode( $settings ),
            ],
            [ '%s', '%d', '%s' ]
        );

        if ( $result !== false ) {
            // Update cache
            self::$cache[ $module_id ] = [
                'is_active' => $is_active,
                'settings'  => $settings,
            ];
            return true;
        }
        return false;
    }

    /**
     * Toggle a module active/inactive.
     */
    public static function toggle_module( string $module_id, bool $active ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        $settings = self::$cache[ $module_id ]['settings'] ?? [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->replace(
            $table,
            [
                'module_id' => $module_id,
                'is_active' => (int) $active,
                'settings'  => wp_json_encode( $settings ),
            ],
            [ '%s', '%d', '%s' ]
        );

        if ( $result !== false ) {
            self::$cache[ $module_id ] = [
                'is_active' => $active,
                'settings'  => $settings,
            ];
            return true;
        }
        return false;
    }

    /**
     * Create the settings table. Called on plugin activation.
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            module_id VARCHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            settings JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (module_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'wpt_db_version', '1.0.0' );
    }
}
