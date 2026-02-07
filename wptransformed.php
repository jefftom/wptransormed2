<?php
/**
 * Plugin Name:       WPTransformed
 * Plugin URI:        https://wptransformed.com
 * Description:       Replace 15+ plugins with one. Modular admin enhancements for WordPress.
 * Version:           1.0.0
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
define( 'WPT_VERSION', '1.0.0' );
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
        if ( file_exists( $path ) ) {
            require_once $path;
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
    \WPTransformed\Core\Safe_Mode::generate_token();
    flush_rewrite_rules();
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

    // Check safe mode BEFORE loading any modules
    $safe_mode = \WPTransformed\Core\Safe_Mode::is_active();

    if ( $safe_mode ) {
        // Show warning banner — no modules loaded
        \WPTransformed\Core\Safe_Mode::render_banner();
    } else {
        // Normal boot — load and init modules
        \WPTransformed\Core\Core::instance()->boot();
    }

    // Admin settings page loads ALWAYS (even in safe mode)
    if ( is_admin() ) {
        require_once WPT_PATH . 'includes/class-admin.php';
        new \WPTransformed\Core\Admin();
    }
} );
