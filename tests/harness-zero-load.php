<?php
declare(strict_types=1);

/**
 * Standalone zero-load harness — runs with plain PHP CLI, no PHPUnit/WP needed.
 *
 * Boots the REAL Core against WP stubs with a controlled active set:
 *   - dark-mode  (core, ACTIVE)        -> must load + register hooks
 *   - admin-bar  (core, ACTIVE)        -> must load; settings preserved
 *   - email-log  (pro,  ACTIVE, unlicensed) -> must NOT load (locked card from definition)
 *   - media-library-pro (stub, ACTIVE) -> must NOT load
 *   - everything else INACTIVE         -> must NOT load
 *
 * Usage:
 *   php tests/harness-zero-load.php           # assert the zero-load contract
 *   php tests/harness-zero-load.php --report  # metrics report only (exit 0)
 *
 * The pre-boot check doubles as the Safe Mode proof: Core::boot() is the
 * only module loader, and Safe Mode skips boot entirely (wptransformed.php).
 *
 * @package WPTransformed
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' ); // Dummy — satisfies the ABSPATH guards.
define( 'WPT_PATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$report_only = in_array( '--report', $argv, true );

// ── WP stubs ──────────────────────────────────────────────────
function __( $s, $d = null ) { return $s; }
function _x( $s, $c, $d = null ) { return $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function sanitize_key( $k ) { return strtolower( (string) $k ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_unslash( $v ) { return $v; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function admin_url( $p = '' ) { return $p; }
function plugin_dir_url( $f ) { return ''; }
function is_admin() { return true; }
function is_user_logged_in() { return true; }
function current_user_can( $c ) { return true; }
function user_can( $u, $c ) { return true; }
function get_current_user_id() { return 1; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function wp_next_scheduled( $h, $a = [] ) { return false; }
function wp_schedule_event( $t, $r, $h, $a = [] ) { return true; }
function wp_clear_scheduled_hook( $h, $a = [] ) { return 0; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function shortcode_exists( $s ) { return false; }
function add_shortcode( $t, $cb ) {}

$GLOBALS['__filters'] = [];
$GLOBALS['__actions'] = [];
function add_filter( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value, ...$args ) {
    foreach ( $GLOBALS['__filters'][ $tag ] ?? [] as $cb ) { $value = $cb( $value, ...$args ); }
    return $value;
}
function add_action( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function remove_action( $tag, $cb, $p = 10 ) { return true; }
function remove_filter( $tag, $cb, $p = 10 ) { return true; }
function did_action( $tag ) { return 0; }
function has_action( $tag, $cb = false ) { return ! empty( $GLOBALS['__actions'][ $tag ] ); }

class WPT_Harness_Wpdb {
    public $prefix = 'wp_';
    public $rows;
    public function __construct() {
        $this->rows = [
            [ 'module_id' => 'dark-mode', 'is_active' => '1', 'settings' => '{}' ],
            [ 'module_id' => 'admin-bar', 'is_active' => '1', 'settings' => json_encode( [ 'keep' => 'me' ] ) ],
            [ 'module_id' => 'email-log', 'is_active' => '1', 'settings' => '{}' ],
            [ 'module_id' => 'media-library-pro', 'is_active' => '1', 'settings' => '{}' ],
        ];
    }
    public function get_results( $sql, $output = null ) { return $this->rows; }
    public function replace( $t, $data, $f = null ) {
        $this->rows   = array_values( array_filter( $this->rows, fn( $r ) => $r['module_id'] !== $data['module_id'] ) );
        $this->rows[] = [ 'module_id' => $data['module_id'], 'is_active' => (string) $data['is_active'], 'settings' => $data['settings'] ];
        return 1;
    }
}
$GLOBALS['wpdb'] = new WPT_Harness_Wpdb();

require WPT_PATH . 'includes/class-settings.php';
require WPT_PATH . 'includes/class-module-base.php';
require WPT_PATH . 'includes/class-permission-manager.php';
require WPT_PATH . 'includes/class-module-registry.php';
require WPT_PATH . 'includes/class-module-hierarchy.php';
require WPT_PATH . 'includes/class-core.php';

use WPTransformed\Core\Core;
use WPTransformed\Core\Settings;

$fail = 0;
function check( bool $ok, string $label ): void {
    global $fail, $report_only;
    if ( $report_only ) { return; }
    echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
    if ( ! $ok ) { $fail++; }
}
function module_files_included(): array {
    return array_values( array_filter(
        get_included_files(),
        fn( $f ) => (bool) preg_match( '#[/\\\\]modules[/\\\\]#', $f )
    ) );
}

// ── Safe Mode equivalence: before boot, zero module files ─────
$pre_boot = count( module_files_included() );
echo "METRIC: module files included pre-boot (Safe Mode equivalent): {$pre_boot}\n";
check( 0 === $pre_boot, 'Safe Mode equivalent — no module implementation files before boot' );

$core = Core::instance();
$core->boot();

// ── Metrics ────────────────────────────────────────────────────
$included  = module_files_included();
$instances = $core->get_all_modules();
echo 'METRIC: module files included after boot: ' . count( $included ) . "\n";
echo 'METRIC: module instances after boot: ' . count( $instances ) . ' (' . implode( ', ', array_keys( $instances ) ) . ")\n";
echo 'METRIC: definitions available: ' . count( $core->get_definitions() ) . "\n";
foreach ( [
    'Dark_Mode (core, active)'          => 'WPTransformed\\Modules\\AdminInterface\\Dark_Mode',
    'Clean_Admin_Bar (core, active)'    => 'WPTransformed\\Modules\\AdminInterface\\Clean_Admin_Bar',
    'Maintenance_Mode (inactive)'       => 'WPTransformed\\Modules\\Utilities\\Maintenance_Mode',
    'Email_Log (pro, active, unlic.)'   => 'WPTransformed\\Modules\\Utilities\\Email_Log',
    'Media_Library_Pro (stub, active)'  => 'WPTransformed\\Modules\\ContentManagement\\Media_Library_Pro',
] as $label => $class ) {
    echo 'METRIC: class loaded — ' . $label . ': ' . ( class_exists( $class, false ) ? 'YES' : 'no' ) . "\n";
}

// ── Invariants (hold before AND after the zero-load flip) ─────
check( count( $core->get_definitions() ) === 83, 'all 83 definitions available regardless of load state' );
$pro_def = $core->get_definition( 'email-log' );
check( is_array( $pro_def ) && 'pro' === $pro_def['tier'] && '' !== $pro_def['title'], 'locked Pro card data renders from definition' );
check( $core->is_active( 'admin-bar' ) && $core->is_active( 'email-log' ), 'active states readable for loaded AND unloaded modules' );

$dark = $core->get_module( 'dark-mode' );
check( null !== $dark, 'active core module instantiated' );
$dark_hooked = false;
foreach ( $GLOBALS['__actions'] as $tag => $cbs ) {
    foreach ( $cbs as $cb ) {
        if ( is_array( $cb ) && ( $cb[0] ?? null ) === $dark ) { $dark_hooked = true; }
    }
}
check( $dark_hooked, 'active core module registered at least one hook via init()' );

Settings::toggle_module( 'admin-bar', false );
check( Settings::get( 'admin-bar' ) === [ 'keep' => 'me' ], 'toggle preserves settings under legacy runtime id' );
Settings::toggle_module( 'admin-bar', true );

// ── Zero-load contract (RED before the flip, GREEN after) ─────
$expected_loaded = [];
foreach ( $core->get_definitions() as $id => $def ) {
    $runtime = $def['legacy_ids'][0] ?? $id;
    if ( ! $core->is_active( $runtime ) ) { continue; }
    if ( 'implemented' !== $def['status'] ) { continue; }
    if ( 'pro' === $def['tier'] && ! Core::is_pro_licensed() ) { continue; }
    $expected_loaded[] = $runtime;
}
sort( $expected_loaded );
$actual_loaded = array_keys( $instances );
sort( $actual_loaded );
check( $actual_loaded === $expected_loaded, 'ONLY active+allowed modules are instantiated (expected: ' . implode( ', ', $expected_loaded ) . ')' );
check( count( $included ) === count( $expected_loaded ), 'included module file count equals allowed-active count (' . count( $included ) . ' vs ' . count( $expected_loaded ) . ')' );
check( ! class_exists( 'WPTransformed\\Modules\\Utilities\\Maintenance_Mode', false ), 'inactive module class never loaded (no hooks possible)' );
check( ! class_exists( 'WPTransformed\\Modules\\Utilities\\Email_Log', false ), 'unlicensed Pro module class never loaded' );
check( ! class_exists( 'WPTransformed\\Modules\\ContentManagement\\Media_Library_Pro', false ), 'stub module class never loaded' );

if ( $report_only ) {
    echo "\nREPORT MODE — metrics only, no assertions.\n";
    exit( 0 );
}
echo $fail === 0 ? "\nALL GREEN\n" : "\n{$fail} FAILURE(S)\n";
exit( $fail === 0 ? 0 : 1 );
