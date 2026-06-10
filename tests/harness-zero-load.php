<?php
declare(strict_types=1);

/**
 * Standalone zero-load + slug-migration harness — plain PHP CLI, no PHPUnit.
 *
 * Seeds a PRE-MIGRATION database (legacy module_ids) including conflict
 * rows, runs the canonical slug migration, then boots the REAL Core and
 * asserts the zero-load contract under canonical ids.
 *
 * Seeded rows (all LEGACY ids — this harness exercises the migration):
 *   - all 10 renamed modules, active, with marker settings {probe: <legacy>}
 *   - dark-mode (core, active), email-log (pro, active, unlicensed),
 *     media-library-pro (stub, active), mystery-module (unknown id)
 *   - conflict partners seeded CANONICAL pre-migration:
 *       database-optimizer (inactive, empty settings)  -> legacy settings preserved
 *       role-manager (active, non-default settings)    -> canonical wins + backup
 *
 * Usage:
 *   php tests/harness-zero-load.php           # assert everything
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
        $legacy = [
            'admin-bar', 'enhance-list-tables', 'auto-publish-missed', 'bulk-edit-posts',
            'database-cleanup', 'login-security', 'two-factor-auth', 'user-role-editor',
            'login-branding', 'email-smtp',
        ];
        $this->rows = [];
        foreach ( $legacy as $id ) {
            $this->rows[] = [ 'module_id' => $id, 'is_active' => '1', 'settings' => json_encode( [ 'probe' => $id ] ) ];
        }
        $this->rows[] = [ 'module_id' => 'dark-mode', 'is_active' => '1', 'settings' => '{}' ];
        $this->rows[] = [ 'module_id' => 'email-log', 'is_active' => '1', 'settings' => '{}' ];
        $this->rows[] = [ 'module_id' => 'media-library-pro', 'is_active' => '1', 'settings' => '{}' ];
        $this->rows[] = [ 'module_id' => 'mystery-module', 'is_active' => '1', 'settings' => '{}' ];
        // Conflict partners (canonical rows pre-existing).
        $this->rows[] = [ 'module_id' => 'database-optimizer', 'is_active' => '0', 'settings' => '{}' ];
        $this->rows[] = [ 'module_id' => 'role-manager', 'is_active' => '1', 'settings' => json_encode( [ 'canon' => 'set' ] ) ];
    }
    public function get_results( $sql, $output = null ) { return $this->rows; }
    public function row( string $id ): ?array {
        foreach ( $this->rows as $r ) { if ( $r['module_id'] === $id ) { return $r; } }
        return null;
    }
    public function replace( $t, $data, $f = null ) {
        $this->rows   = array_values( array_filter( $this->rows, fn( $r ) => $r['module_id'] !== $data['module_id'] ) );
        $this->rows[] = [ 'module_id' => $data['module_id'], 'is_active' => (string) $data['is_active'], 'settings' => $data['settings'] ];
        return 1;
    }
    public function update( $t, $data, $where, $f = null, $wf = null ) {
        $n = 0;
        foreach ( $this->rows as &$r ) {
            if ( $r['module_id'] === ( $where['module_id'] ?? null ) ) {
                $r = array_merge( $r, array_map( 'strval', $data ) );
                $n++;
            }
        }
        return $n;
    }
    public function delete( $t, $where, $wf = null ) {
        $before     = count( $this->rows );
        $this->rows = array_values( array_filter( $this->rows, fn( $r ) => $r['module_id'] !== ( $where['module_id'] ?? null ) ) );
        return $before - count( $this->rows );
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
use WPTransformed\Core\Module_Registry;
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
function settings_of( string $id ): array {
    $row = $GLOBALS['wpdb']->row( $id );
    return $row ? ( json_decode( $row['settings'], true ) ?: [] ) : [];
}

// ── Safe Mode equivalence: before boot, zero module files ─────
$pre_boot = count( module_files_included() );
echo "METRIC: module files included pre-boot (Safe Mode equivalent): {$pre_boot}\n";
check( 0 === $pre_boot, 'Safe Mode equivalent — no module implementation files before boot' );

// ── SLUG MIGRATION (legacy DB seeded above) ───────────────────
$legacy_map = Module_Registry::get_legacy_map();
$canonical  = array_keys( Module_Registry::get_definitions() );
Settings::migrate_module_ids( $legacy_map, $canonical );

$renames = [
    'admin-bar'            => 'admin-bar-manager',
    'enhance-list-tables'  => 'list-table-enhancements',
    'auto-publish-missed'  => 'auto-publish-missed-schedule',
    'bulk-edit-posts'      => 'bulk-content-editor',
    'database-cleanup'     => 'database-optimizer',
    'login-security'       => 'login-protection',
    'two-factor-auth'      => 'two-factor-authentication',
    'user-role-editor'     => 'role-manager',
    'login-branding'       => 'login-designer',
    'email-smtp'           => 'email-delivery',
];
check( [] === array_diff_assoc( $legacy_map, $renames ) && [] === array_diff_assoc( $renames, $legacy_map ), 'registry legacy map matches the 10 expected renames' );

// Active + settings preserved for ALL 10 renamed modules.
$broken = [];
foreach ( $renames as $legacy => $canon ) {
    $row = $GLOBALS['wpdb']->row( $canon );
    if ( ! $row || '1' !== $row['is_active'] ) { $broken[] = "$canon: active lost"; continue; }
    $expected_settings = ( 'role-manager' === $canon ) ? [ 'canon' => 'set' ] : [ 'probe' => $legacy ];
    if ( settings_of( $canon ) !== $expected_settings ) { $broken[] = "$canon: settings lost"; }
    if ( null !== $GLOBALS['wpdb']->row( $legacy ) ) { $broken[] = "$legacy: legacy row remains"; }
}
check( $broken === [], 'all 10 renamed modules keep active state + settings per conflict policy' . ( $broken ? ' [' . implode( '; ', $broken ) . ']' : '' ) );

// Conflict decisions per the amended policy.
$audit = get_option( 'wpt_slug_migration_v1_backup' );
check( is_array( $audit ) && $audit['map'] === $legacy_map && count( $audit['legacy_rows_touched'] ) === 10, 'audit option records map + 10 legacy rows touched' );
check( ( $audit['conflicts']['database-optimizer']['decision'] ?? '' ) === 'legacy-settings-preserved' && ( $audit['conflicts']['database-optimizer']['merged_active'] ?? false ) === true, 'conflict: empty canonical -> legacy settings preserved, active OR-merged' );
check( ( $audit['conflicts']['role-manager']['decision'] ?? '' ) === 'canonical-settings-won' && ( $audit['conflicts']['role-manager']['legacy_settings_backup'] ?? [] ) === [ 'probe' => 'user-role-editor' ], 'conflict: both non-default -> canonical wins, legacy backed up in audit' );
check( in_array( 'mystery-module', $audit['skipped_unknown'] ?? [], true ) && null !== $GLOBALS['wpdb']->row( 'mystery-module' ), 'unknown id skipped untouched + reported' );

// Idempotency: second run changes nothing (rows or audit).
$rows_snapshot  = json_encode( $GLOBALS['wpdb']->rows );
$audit_snapshot = json_encode( $audit );
Settings::migrate_module_ids( $legacy_map, $canonical );
check( json_encode( $GLOBALS['wpdb']->rows ) === $rows_snapshot && json_encode( get_option( 'wpt_slug_migration_v1_backup' ) ) === $audit_snapshot, 'second migration run is a no-op (rows + audit unchanged)' );

// ── BOOT under canonical ids ──────────────────────────────────
$core = Core::instance();
$core->boot();

$included  = module_files_included();
$instances = $core->get_all_modules();
echo 'METRIC: module files included after boot: ' . count( $included ) . "\n";
echo 'METRIC: module instances after boot: ' . count( $instances ) . ' (' . implode( ', ', array_keys( $instances ) ) . ")\n";
foreach ( [
    'Maintenance_Mode (inactive)'        => 'WPTransformed\\Modules\\Utilities\\Maintenance_Mode',
    'Email_Log (pro, active, unlic.)'    => 'WPTransformed\\Modules\\Utilities\\Email_Log',
    'Two_Factor_Auth (pro, active)'      => 'WPTransformed\\Modules\\Security\\Two_Factor_Auth',
    'Media_Library_Pro (stub, active)'   => 'WPTransformed\\Modules\\ContentManagement\\Media_Library_Pro',
    'Login_Security (stub, active)'      => 'WPTransformed\\Modules\\Security\\Login_Security',
] as $label => $class ) {
    echo 'METRIC: class loaded — ' . $label . ': ' . ( class_exists( $class, false ) ? 'YES' : 'no' ) . "\n";
}

check( count( $core->get_definitions() ) === 83, 'all 83 definitions available regardless of load state' );

// Zero-load contract under canonical ids.
$expected_loaded = [];
foreach ( $core->get_definitions() as $id => $def ) {
    if ( ! $core->is_active( $id ) ) { continue; }
    if ( 'implemented' !== $def['status'] ) { continue; }
    if ( 'pro' === $def['tier'] && ! Core::is_pro_licensed() ) { continue; }
    $expected_loaded[] = $id;
}
sort( $expected_loaded );
$actual_loaded = array_keys( $instances );
sort( $actual_loaded );
check( $actual_loaded === $expected_loaded, 'ONLY active+allowed modules instantiated, ALL under canonical ids (' . implode( ', ', $expected_loaded ) . ')' );
check( count( $included ) === count( $expected_loaded ), 'included module file count equals allowed-active count (' . count( $included ) . ' vs ' . count( $expected_loaded ) . ')' );

// Renamed + loaded modules report canonical get_id().
$id_mismatch = [];
foreach ( array_intersect( $actual_loaded, array_values( $renames ) ) as $canon ) {
    if ( $instances[ $canon ]->get_id() !== $canon ) { $id_mismatch[] = $canon; }
}
check( $id_mismatch === [] && count( array_intersect( $actual_loaded, array_values( $renames ) ) ) === 8, 'all 8 loadable renamed modules report canonical get_id() (stub + pro excluded)' );

check( ! class_exists( 'WPTransformed\\Modules\\Utilities\\Maintenance_Mode', false ), 'inactive module class never loaded' );
check( ! class_exists( 'WPTransformed\\Modules\\Utilities\\Email_Log', false ) && ! class_exists( 'WPTransformed\\Modules\\Security\\Two_Factor_Auth', false ), 'unlicensed Pro module classes never loaded' );
check( ! class_exists( 'WPTransformed\\Modules\\ContentManagement\\Media_Library_Pro', false ) && ! class_exists( 'WPTransformed\\Modules\\Security\\Login_Security', false ), 'stub module classes never loaded' );

$pro_def = $core->get_definition( 'email-log' );
check( is_array( $pro_def ) && 'pro' === $pro_def['tier'] && '' !== $pro_def['title'], 'locked Pro card data renders from definition' );

// Legacy alias resolution (old exports / bookmarks).
check( null !== $core->get_definition( 'email-smtp' ), 'legacy id resolves to a definition during transition' );
$via_alias = $core->load_module( 'email-smtp' );
check( null !== $via_alias && 'email-delivery' === $via_alias->get_id(), 'load_module via legacy alias returns the canonical module (old-export import path)' );

// Canonical toggle round-trip preserves settings.
Settings::toggle_module( 'admin-bar-manager', false );
check( Settings::get( 'admin-bar-manager' ) === [ 'probe' => 'admin-bar' ], 'canonical toggle round-trip preserves settings' );
Settings::toggle_module( 'admin-bar-manager', true );

// Lazy admin-operation loading still hook-free.
$actions_before = array_sum( array_map( 'count', $GLOBALS['__actions'] ) );
$lazy           = $core->load_module( 'maintenance-mode' );
$actions_after  = array_sum( array_map( 'count', $GLOBALS['__actions'] ) );
check( null !== $lazy && $actions_before === $actions_after, 'lazy load instantiates an inactive module with NO hooks' );
check( null === $core->load_module( 'media-library-pro' ) && null === $core->load_module( 'email-log' ), 'lazy load refuses stub and unlicensed-Pro modules' );

if ( $report_only ) {
    echo "\nREPORT MODE — metrics only, no assertions.\n";
    exit( 0 );
}
echo $fail === 0 ? "\nALL GREEN\n" : "\n{$fail} FAILURE(S)\n";
exit( $fail === 0 ? 0 : 1 );
