<?php
/**
 * WPTransformed Uninstall
 *
 * Fired when the plugin is deleted (NOT deactivated).
 * Removes all plugin data from the database.
 *
 * @package WPTransformed
 */

// Verify this is a legitimate uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// -- Step 1: Load just enough to read the registry --

require_once plugin_dir_path( __FILE__ ) . 'includes/class-module-registry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-module-base.php';

// -- Step 2: Collect cleanup tasks from ALL modules --

$registry = \WPTransformed\Core\Module_Registry::get_all();
$tasks = [];

foreach ( $registry as $id => $file ) {
    $path = plugin_dir_path( __FILE__ ) . $file;
    if ( ! file_exists( $path ) ) continue;

    try {
        require_once $path;

        // Resolve class name (same logic as Core::resolve_class_name)
        $parts = explode( '/', $file );
        $category_slug = $parts[1] ?? '';
        $category_ns = str_replace( ' ', '', ucwords( str_replace( '-', ' ', $category_slug ) ) );
        $filename = basename( $file, '.php' );
        $filename = preg_replace( '/^class-/', '', $filename );
        $class_name = str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $filename ) ) );
        $class = "WPTransformed\\Modules\\{$category_ns}\\{$class_name}";

        if ( class_exists( $class ) ) {
            $module = new $class();
            $module_tasks = $module->get_cleanup_tasks();
            if ( ! empty( $module_tasks ) ) {
                $tasks = array_merge( $tasks, $module_tasks );
            }
        }
    } catch ( \Throwable $e ) {
        // If we can't load a module to get its tasks, skip it.
        // Better to leave some orphaned data than crash the uninstaller.
        continue;
    }
}

// -- Step 3: Execute cleanup tasks --

global $wpdb;

foreach ( $tasks as $task ) {
    try {
        switch ( $task['type'] ) {

            case 'option':
                delete_option( $task['key'] );
                break;

            case 'user_meta':
                // Delete from ALL users
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete(
                    $wpdb->usermeta,
                    [ 'meta_key' => $task['key'] ],
                    [ '%s' ]
                );
                break;

            case 'post_meta':
                // Delete from ALL posts
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete(
                    $wpdb->postmeta,
                    [ 'meta_key' => $task['key'] ],
                    [ '%s' ]
                );
                break;

            case 'transient':
                delete_transient( $task['key'] );
                break;

            case 'cron':
                $timestamp = wp_next_scheduled( $task['hook'] );
                if ( $timestamp ) {
                    wp_unschedule_event( $timestamp, $task['hook'] );
                }
                wp_unschedule_hook( $task['hook'] ); // Remove all instances
                break;

            case 'table':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$task['name']}" );
                break;
        }
    } catch ( \Throwable $e ) {
        // Don't let one failed cleanup stop the rest
        continue;
    }
}

// -- Step 4: Remove core plugin data --

// Drop the settings table
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpt_settings" );

// Remove plugin options
delete_option( 'wpt_db_version' );
delete_option( 'wpt_safe_mode_token' );

// Clear any remaining transients with our prefix
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wpt_%'
     OR option_name LIKE '_transient_timeout_wpt_%'"
);
