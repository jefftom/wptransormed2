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

        // A missing table (fresh install before the activation hook runs) simply
        // yields no rows — every module then reads as inactive with defaults.
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
     * Whether a module's PERSISTED state is active. Primes the cache;
     * absent rows read as inactive.
     *
     * The single source of pre-toggle state for toggle surfaces — the
     * loader's active id list is a boot-time snapshot and goes stale
     * after mid-request writes.
     */
    public static function is_module_active( string $module_id ): bool {
        self::load();
        return (bool) ( self::$cache[ $module_id ]['is_active'] ?? false );
    }

    /**
     * Save settings for a module. Uses REPLACE INTO (upsert).
     */
    public static function save( string $module_id, array $settings ): bool {
        // Prime the cache — a cold-cache save would otherwise write is_active=0.
        self::load();

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

            /**
             * Fires after a module's settings are persisted — never
             * before. Lives in the shared storage method so every save
             * surface (admin form, app pages, import, future REST
             * settings routes) fires it identically. Callers
             * canonicalize ids ahead of the write, so subscribers
             * always receive the canonical module id.
             *
             * @param string $module_id Canonical module id.
             * @param array  $settings  The persisted (sanitized) settings.
             */
            do_action( 'wpt_module_settings_saved', $module_id, $settings );

            return true;
        }
        return false;
    }

    /**
     * Toggle a module active/inactive.
     */
    public static function toggle_module( string $module_id, bool $active ): bool {
        // Prime the cache — a cold-cache toggle would otherwise wipe saved settings.
        self::load();

        // No-op suppression: re-asserting the current state writes
        // nothing and fires no lifecycle hook. Absent rows read as
        // inactive, so disabling a row-less module is also a no-op
        // (and creates no row).
        if ( self::is_module_active( $module_id ) === $active ) {
            return true;
        }

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

            /**
             * Fires after a module's active state is persisted — never
             * before. Lives in the shared storage method so every toggle
             * surface (admin-ajax single/parent, wpt/v1 REST, setup
             * wizard) fires it identically. Callers canonicalize ids
             * ahead of the write, so subscribers always receive the
             * canonical module id. Fires only on actual state
             * transitions — no-op re-assertions return early above,
             * without a write and without this hook.
             *
             * @param string $module_id Canonical module id.
             */
            do_action( $active ? 'wpt_module_enabled' : 'wpt_module_disabled', $module_id );

            return true;
        }
        return false;
    }

    /**
     * One-time canonical slug migration: rename legacy module_id rows to
     * their canonical ids. Version-gated by the caller (wpt_slug_version)
     * and idempotent — a second run finds no legacy rows and changes
     * nothing, including the audit option.
     *
     * Conflict policy (when canonical AND legacy rows both exist):
     * - active state = canonical_active OR legacy_active
     * - canonical settings empty + legacy non-empty => legacy preserved
     * - both non-empty => canonical wins; legacy settings are backed up
     *   in the audit option and debug-logged
     * - the legacy row is deleted only AFTER the canonical row is
     *   safely written
     *
     * Audit trail (pre-launch migration record, not a Recovery Center
     * feature) is stored in the non-autoloaded option
     * wpt_slug_migration_v1_backup.
     *
     * @param array<string,string> $legacy_map    legacy id => canonical id.
     * @param string[]             $canonical_ids All canonical ids (for the
     *                                            skipped-unknown report).
     */
    public static function migrate_module_ids( array $legacy_map, array $canonical_ids ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows  = $wpdb->get_results( "SELECT module_id, is_active, settings FROM {$table}", ARRAY_A );
        $by_id = [];
        foreach ( (array) $rows as $row ) {
            $by_id[ $row['module_id'] ] = $row;
        }

        $audit = [
            'timestamp'              => gmdate( 'c' ),
            'version'                => '1',
            'map'                    => $legacy_map,
            'legacy_rows_touched'    => [],
            'canonical_rows_touched' => [],
            'conflicts'              => [],
            'skipped_unknown'        => [],
        ];

        foreach ( array_keys( $by_id ) as $row_id ) {
            if ( ! in_array( $row_id, $canonical_ids, true ) && ! isset( $legacy_map[ $row_id ] ) ) {
                $audit['skipped_unknown'][] = $row_id;
            }
        }

        foreach ( $legacy_map as $legacy => $canonical ) {
            if ( ! isset( $by_id[ $legacy ] ) ) {
                continue;
            }
            $legacy_row                     = $by_id[ $legacy ];
            $audit['legacy_rows_touched'][] = $legacy;

            if ( ! isset( $by_id[ $canonical ] ) ) {
                // Simple rename — single atomic UPDATE, nothing deleted.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $ok = $wpdb->update(
                    $table,
                    [ 'module_id' => $canonical ],
                    [ 'module_id' => $legacy ],
                    [ '%s' ],
                    [ '%s' ]
                );
                if ( false !== $ok ) {
                    $audit['canonical_rows_touched'][] = $canonical;
                }
                continue;
            }

            // Conflict: both rows exist.
            $canon_row       = $by_id[ $canonical ];
            $legacy_settings = json_decode( $legacy_row['settings'], true ) ?: [];
            $canon_settings  = json_decode( $canon_row['settings'], true ) ?: [];
            $merged_active   = ( (bool) $canon_row['is_active'] ) || ( (bool) $legacy_row['is_active'] );

            if ( [] === $canon_settings && [] !== $legacy_settings ) {
                $settings = $legacy_settings;
                $decision = 'legacy-settings-preserved';
            } elseif ( [] !== $canon_settings && [] !== $legacy_settings ) {
                $settings = $canon_settings;
                $decision = 'canonical-settings-won';
                $audit['conflicts'][ $canonical ]['legacy_settings_backup'] = $legacy_settings;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "WPTransformed slug migration: settings conflict for '{$canonical}' — canonical kept, legacy '{$legacy}' settings backed up in wpt_slug_migration_v1_backup." );
                }
            } else {
                $settings = $canon_settings;
                $decision = 'no-settings-conflict';
            }
            $audit['conflicts'][ $canonical ]['decision']      = $decision;
            $audit['conflicts'][ $canonical ]['merged_active'] = $merged_active;

            // Write the canonical row FIRST; delete legacy only on success.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ok = $wpdb->replace(
                $table,
                [
                    'module_id' => $canonical,
                    'is_active' => (int) $merged_active,
                    'settings'  => wp_json_encode( $settings ),
                ],
                [ '%s', '%d', '%s' ]
            );
            if ( false !== $ok ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete( $table, [ 'module_id' => $legacy ], [ '%s' ] );
                $audit['canonical_rows_touched'][] = $canonical;
            }
        }

        // Audit is written only when a migration actually ran, so re-runs
        // never clobber the original record.
        if ( $audit['legacy_rows_touched'] ) {
            update_option( 'wpt_slug_migration_v1_backup', $audit, false );
        }

        // Drop the request cache so post-migration reads see the new ids.
        self::$cache = null;
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
