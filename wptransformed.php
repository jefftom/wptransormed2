<?php
/**
 * Plugin Name:       WPTransformed
 * Plugin URI:        https://wptransformed.com
 * Description:       Replace 15+ plugins with one. Modular admin enhancements for WordPress.
 * Version:           1.1.0-session5p2.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WPTransformed
 * Author URI:        https://wptransformed.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wptransformed
 * Domain Path:       /languages
 *
 * @package WPTransformed
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin constants
define( 'WPT_VERSION', '1.1.0-session5p2.3' );
define( 'WPT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPT_URL', plugin_dir_url( __FILE__ ) );
define( 'WPT_FILE', __FILE__ );

// Autoloader (simple, no Composer needed for v1)
spl_autoload_register( function( $class ) {
    $prefix = 'WPTransformed\\';
    if ( strpos( $class, $prefix ) !== 0 ) return;

    $relative = substr( $class, strlen( $prefix ) );
    // WPTransformed\Core\Settings → includes/class-settings.php
    // WPTransformed\Modules\ContentManagement\Content_Duplication → (loaded via registry)
    $parts = explode( '\\', $relative );

    if ( $parts[0] === 'Core' ) {
        $filename = 'class-' . strtolower( str_replace( '_', '-', $parts[1] ) ) . '.php';
        $path = WPT_PATH . 'includes/' . $filename;
        // Path traversal guard — ensure resolved path stays within includes/.
        $real = realpath( $path );
        $includes_dir = realpath( WPT_PATH . 'includes' );
        if ( $real && $includes_dir && strpos( $real, $includes_dir . DIRECTORY_SEPARATOR ) === 0 ) {
            require_once $real;
        }
    }

    // Module_Base lives in includes/ but uses the Modules namespace
    if ( $parts[0] === 'Modules' && isset( $parts[1] ) && $parts[1] === 'Module_Base' ) {
        $path = WPT_PATH . 'includes/class-module-base.php';
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
    // Module classes are loaded by the registry/loader, not the autoloader.
});

// Activation
register_activation_hook( __FILE__, function() {
    \WPTransformed\Core\Settings::create_table();
    \WPTransformed\Core\Permission_Manager::grant_to_administrator();
    \WPTransformed\Core\Safe_Mode::generate_token();
    flush_rewrite_rules();
    // Consumed by the Setup Wizard on the next admin load to start onboarding.
    set_transient( 'wpt_activation_redirect', true, 30 );
} );

// Deactivation
register_deactivation_hook( __FILE__, function() {
    // Don't delete data. Just clean up cron jobs if any.
    flush_rewrite_rules();
} );

// Boot
add_action( 'plugins_loaded', function() {
    // Load text domain
    load_plugin_textdomain( 'wptransformed', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Permission model boots first — capability checks below depend on it.
    // Cheap: role mutation only runs when the caps version is stale.
    \WPTransformed\Core\Permission_Manager::init();

    // One-time canonical slug migration — version-gated and idempotent.
    // Runs BEFORE boot so the Settings cache only ever sees canonical ids.
    // Audit trail: wpt_slug_migration_v1_backup option.
    if ( get_option( 'wpt_slug_version' ) !== '1' ) {
        \WPTransformed\Core\Settings::migrate_module_ids(
            \WPTransformed\Core\Module_Registry::get_legacy_map(),
            array_keys( \WPTransformed\Core\Module_Registry::get_definitions() )
        );
        update_option( 'wpt_slug_version', '1' );
    }

    // Check safe mode BEFORE loading any modules
    $safe_mode = \WPTransformed\Core\Safe_Mode::is_active();

    if ( $safe_mode ) {
        // Show warning banner — no modules loaded
        \WPTransformed\Core\Safe_Mode::render_banner();
    } else {
        // Normal boot — load and init modules
        \WPTransformed\Core\Core::instance()->boot();
    }

    // wpt/v1 REST surface — the canonical API for new server I/O
    // (reframe step 9). Registration is request-type agnostic:
    // rest_api_init only fires on REST dispatches, which Safe Mode (a
    // tokened wp-admin gate) never applies to. Existing admin-ajax
    // endpoints keep working and migrate screen-by-screen (reframe §7).
    \WPTransformed\Core\Rest_Controller::init();

    // Admin settings page loads ALWAYS (even in safe mode)
    if ( is_admin() ) {
        require_once WPT_PATH . 'includes/class-admin.php';
        new \WPTransformed\Core\Admin();
    }
} );
