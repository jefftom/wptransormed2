<?php
declare(strict_types=1);

/**
 * Standalone wpt/v1 REST skeleton harness — plain PHP CLI, no PHPUnit.
 *
 * Boots the REAL Core + Rest_Controller over minimal WP REST stubs
 * (post-migration database: canonical ids only, every module inactive
 * except a stale ACTIVE row on the stub login-protection — the
 * stub-disable cleanup case) and asserts:
 *
 *   - route table: the 5 wpt/v1 routes register with callbacks AND
 *     permission callbacks (no public unauthenticated routes)
 *   - permission matrix: caps-granted admin -> true; capless editor ->
 *     wpt_forbidden 403; logged-out -> wpt_forbidden 401
 *   - read payloads are definition-backed and canonical-id only, with
 *     internal wiring fields (file/class) never exposed
 *   - legacy alias resolves to the canonical id in the payload;
 *     invalid ids return wpt_invalid_module 404 on read and toggle
 *   - ZERO module implementation files load anywhere in the run
 *     (the only active row is a stub the loader refuses — any
 *     modules/ include is a failure)
 *   - Pro metadata returns with pro_locked, Pro class never loads;
 *     Pro toggle returns wpt_pro_locked 403 without a write
 *   - toggle: alias-addressed writes land on the canonical row (no
 *     legacy row recreated), settings survive the round-trip,
 *     wpt_module_enabled/disabled fire with canonical ids only AFTER
 *     persistence succeeds (failed write -> wpt_toggle_failed, no hook)
 *   - the four-field toggle response contract: {id, active,
 *     previous_active, changed}
 *   - no-op suppression: re-asserting the current state is HTTP 200
 *     with changed=false, NO database write, NO lifecycle hook
 *   - stub gating: ENABLE rejects wpt_module_stub 400 (canonical id or
 *     legacy alias), DISABLE is allowed so stale active rows clean up
 *   - the per-module wpt_user_can_manage_module filter receives the
 *     CANONICAL id and can deny the toggle
 *
 * Usage: php tests/harness-rest.php
 *
 * @package WPTransformed
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' ); // Dummy — satisfies the ABSPATH guards.
define( 'WPT_PATH', dirname( __DIR__ ) . '/' );
define( 'WPT_VERSION', '0.0.0-harness' );
define( 'ARRAY_A', 'ARRAY_A' );

// ── WP stubs ──────────────────────────────────────────────────
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function is_admin() { return false; } // REST dispatch context.
function get_current_user_id() { return $GLOBALS['__user']['logged_in'] ? 1 : 0; }
function is_user_logged_in() { return (bool) $GLOBALS['__user']['logged_in']; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['__user']['caps'], true ); }
function user_can( $u, $cap ) { return current_user_can( $cap ); }
function rest_authorization_required_code() { return is_user_logged_in() ? 403 : 401; }

$GLOBALS['__filters']     = [];
$GLOBALS['__actions']     = [];
$GLOBALS['__did_actions'] = [];
$GLOBALS['__routes']      = [];
function add_filter( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value, ...$args ) {
    foreach ( $GLOBALS['__filters'][ $tag ] ?? [] as $cb ) { $value = $cb( $value, ...$args ); }
    return $value;
}
function add_action( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function do_action( $tag, ...$args ) { $GLOBALS['__did_actions'][] = [ $tag, $args ]; }
function register_rest_route( $ns, $route, $args = [], $override = false ) {
    $GLOBALS['__routes'][] = [ 'ns' => $ns, 'route' => $route, 'args' => $args ];
    return true;
}

// ── WP REST classes (minimal stand-ins) ───────────────────────
class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data;
    public $status;
    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}
class WP_REST_Request implements ArrayAccess {
    private $params;
    public function __construct( array $params = [] ) { $this->params = $params; }
    #[\ReturnTypeWillChange] public function offsetExists( $k ) { return isset( $this->params[ $k ] ); }
    #[\ReturnTypeWillChange] public function offsetGet( $k ) { return $this->params[ $k ] ?? null; }
    #[\ReturnTypeWillChange] public function offsetSet( $k, $v ) { $this->params[ $k ] = $v; }
    #[\ReturnTypeWillChange] public function offsetUnset( $k ) { unset( $this->params[ $k ] ); }
}
class WP_REST_Server {
    const READABLE  = 'GET';
    const CREATABLE = 'POST';
    const EDITABLE  = 'POST, PUT, PATCH';
    const DELETABLE = 'DELETE';
}

// ── Fake wpdb seeded POST-migration: canonical ids only ──────────
// Every module inactive EXCEPT a stale active row on the stub
// login-protection (e.g. persisted before stub gating existed). The
// loader refuses stubs, so ANY modules/ file include in this run is
// still a zero-load violation.
class WPT_Rest_Harness_Wpdb {
    public $prefix = 'wp_';
    public $rows   = [];
    public $fail_next_write = false;
    public function __construct() {
        $this->rows[] = [ 'module_id' => 'admin-bar-manager', 'is_active' => '0', 'settings' => json_encode( [ 'probe' => 'kept' ] ) ];
        $this->rows[] = [ 'module_id' => 'database-optimizer', 'is_active' => '0', 'settings' => json_encode( [ 'keep' => 'me' ] ) ];
        $this->rows[] = [ 'module_id' => 'login-protection', 'is_active' => '1', 'settings' => json_encode( [ 'stale' => 'row' ] ) ];
    }
    public function get_results( $sql, $output = null ) { return $this->rows; }
    public function row( string $id ): ?array {
        foreach ( $this->rows as $r ) { if ( $r['module_id'] === $id ) { return $r; } }
        return null;
    }
    public function replace( $t, $data, $f = null ) {
        if ( $this->fail_next_write ) { $this->fail_next_write = false; return false; }
        $this->rows   = array_values( array_filter( $this->rows, fn( $r ) => $r['module_id'] !== $data['module_id'] ) );
        $this->rows[] = [ 'module_id' => $data['module_id'], 'is_active' => (string) $data['is_active'], 'settings' => $data['settings'] ];
        return 1;
    }
}
$GLOBALS['wpdb']   = new WPT_Rest_Harness_Wpdb();
$GLOBALS['__opts'] = [];
$GLOBALS['__user'] = [ 'logged_in' => false, 'caps' => [] ];

require WPT_PATH . 'includes/class-settings.php';
require WPT_PATH . 'includes/class-module-base.php';
require WPT_PATH . 'includes/class-permission-manager.php';
require WPT_PATH . 'includes/class-module-registry.php';
require WPT_PATH . 'includes/class-safe-mode.php';
require WPT_PATH . 'includes/class-core.php';
require WPT_PATH . 'includes/class-rest-controller.php';

use WPTransformed\Core\Core;
use WPTransformed\Core\Permission_Manager;
use WPTransformed\Core\Rest_Controller;
use WPTransformed\Core\Settings;

$fail = 0;
function check( bool $ok, string $label ): void {
    global $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
    if ( ! $ok ) { $fail++; }
}
function module_files_included(): array {
    return array_values( array_filter(
        get_included_files(),
        fn( $f ) => (bool) preg_match( '#[/\\\\]modules[/\\\\]#', $f )
    ) );
}
function as_admin(): void { $GLOBALS['__user'] = [ 'logged_in' => true, 'caps' => Permission_Manager::CAPS ]; }
function as_editor(): void { $GLOBALS['__user'] = [ 'logged_in' => true, 'caps' => [ 'edit_posts' ] ]; }
function as_nobody(): void { $GLOBALS['__user'] = [ 'logged_in' => false, 'caps' => [] ]; }
function req( array $params = [] ): WP_REST_Request { return new WP_REST_Request( $params ); }
function is_wpt_error( $v, string $code, int $status ): bool {
    return $v instanceof WP_Error
        && $v->get_error_code() === $code
        && ( $v->get_error_data()['status'] ?? null ) === $status;
}

// Boot the real Core — the plugins_loaded equivalent of a REST dispatch.
$core = Core::instance();
$core->boot();
$defs = $core->get_definitions();

$ctrl = new Rest_Controller();
$ctrl->register_routes();

// ── Route table ───────────────────────────────────────────────
$registered = [];
foreach ( $GLOBALS['__routes'] as $r ) {
    $registered[ $r['route'] ] = $r;
}
$expected_routes = [
    '/system/status'                       => WP_REST_Server::READABLE,
    '/modules'                             => WP_REST_Server::READABLE,
    '/modules/(?P<id>[a-z0-9-]+)'          => WP_REST_Server::READABLE,
    '/capabilities'                        => WP_REST_Server::READABLE,
    '/modules/(?P<id>[a-z0-9-]+)/toggle'   => WP_REST_Server::CREATABLE,
];
$route_problems = [];
foreach ( $expected_routes as $route => $method ) {
    $r = $registered[ $route ] ?? null;
    if ( ! $r ) { $route_problems[] = "{$route}: not registered"; continue; }
    if ( 'wpt/v1' !== $r['ns'] ) { $route_problems[] = "{$route}: wrong namespace"; }
    if ( ( $r['args']['methods'] ?? '' ) !== $method ) { $route_problems[] = "{$route}: wrong method"; }
    if ( ! is_callable( $r['args']['callback'] ?? null ) ) { $route_problems[] = "{$route}: no callback"; }
    if ( ! is_callable( $r['args']['permission_callback'] ?? null ) ) { $route_problems[] = "{$route}: no permission callback"; }
}
check( count( $GLOBALS['__routes'] ) === 5 && $route_problems === [], 'all 5 wpt/v1 routes registered with callbacks + permission callbacks' . ( $route_problems ? ' [' . implode( '; ', $route_problems ) . ']' : '' ) );
check( 'wpt/v1' === Rest_Controller::REST_NAMESPACE, 'namespace constant is wpt/v1' );
$toggle_args = $registered['/modules/(?P<id>[a-z0-9-]+)/toggle']['args']['args'] ?? [];
check( ( $toggle_args['active']['required'] ?? false ) === true && ( $toggle_args['active']['type'] ?? '' ) === 'boolean', 'toggle route requires boolean active param' );

// ── Permission matrix ─────────────────────────────────────────
as_admin();
check( true === $ctrl->can_manage( req() ) && true === $ctrl->can_manage_modules( req() ) && true === $ctrl->can_toggle_module( req( [ 'id' => 'database-optimizer' ] ) ), 'admin with WPT caps passes all permission callbacks' );

as_editor();
check(
    is_wpt_error( $ctrl->can_manage( req() ), 'wpt_forbidden', 403 )
    && is_wpt_error( $ctrl->can_manage_modules( req() ), 'wpt_forbidden', 403 )
    && is_wpt_error( $ctrl->can_toggle_module( req( [ 'id' => 'database-optimizer' ] ) ), 'wpt_forbidden', 403 ),
    'editor without WPT caps gets wpt_forbidden 403 on every callback'
);

as_nobody();
check(
    is_wpt_error( $ctrl->can_manage( req() ), 'wpt_forbidden', 401 )
    && is_wpt_error( $ctrl->can_manage_modules( req() ), 'wpt_forbidden', 401 ),
    'logged-out caller gets wpt_forbidden 401'
);

// ── Read routes (as caps-granted admin) ───────────────────────
as_admin();

$resp = $ctrl->get_modules( req() );
$list = $resp->get_data();
$payload_problems = [];
foreach ( $list as $m ) {
    if ( ! isset( $defs[ $m['id'] ] ) ) { $payload_problems[] = "non-canonical id {$m['id']}"; }
    if ( array_key_exists( 'file', $m ) || array_key_exists( 'class', $m ) ) { $payload_problems[] = "internal field exposed on {$m['id']}"; }
}
check( $resp->get_status() === 200 && count( $list ) === count( $defs ) && count( $defs ) === 83 && $payload_problems === [], 'GET /modules returns all 83 definitions, canonical ids only, no internal fields' . ( $payload_problems ? ' [' . implode( '; ', array_slice( $payload_problems, 0, 3 ) ) . ']' : '' ) );

$resp = $ctrl->get_module( req( [ 'id' => 'database-cleanup' ] ) );
check( $resp instanceof WP_REST_Response && 'database-optimizer' === $resp->get_data()['id'] && in_array( 'database-cleanup', $resp->get_data()['legacy_ids'], true ), 'GET /modules/{legacy alias} returns the canonical id' );

check( is_wpt_error( $ctrl->get_module( req( [ 'id' => 'not-a-module' ] ) ), 'wpt_invalid_module', 404 ), 'GET /modules/{unknown} returns wpt_invalid_module 404' );

$resp = $ctrl->get_module( req( [ 'id' => 'white-label' ] ) );
check( $resp instanceof WP_REST_Response && true === $resp->get_data()['pro_locked'] && 'pro' === $resp->get_data()['tier'], 'Pro module metadata returns with pro_locked' );

$resp = $ctrl->get_capabilities( req() );
check( $resp->get_status() === 200 && array_keys( $resp->get_data() ) === Permission_Manager::CAPS && ! in_array( false, $resp->get_data(), true ), 'GET /capabilities returns exactly the WPT capability map (all true for admin)' );
as_editor();
$resp = $ctrl->get_capabilities( req() );
check( ! in_array( true, $resp->get_data(), true ), 'capability map is all false for a capless user' );
as_admin();

$pro_total = count( array_filter( $defs, fn( $d ) => 'pro' === $d['tier'] ) );
$resp      = $ctrl->get_system_status( req() );
$status    = $resp->get_data();
check(
    $resp->get_status() === 200
    && WPT_VERSION === $status['version']
    && $status['modules'] === [ 'total' => count( $defs ), 'active' => 1, 'pro_locked' => $pro_total ]
    && false === $status['safe_mode'],
    'GET /system/status reports version + module counts (1 active = the stale stub row) + request-scoped safe_mode'
);

// ── Toggle route ──────────────────────────────────────────────
$GLOBALS['__did_actions'] = [];
$resp = $ctrl->toggle_module( req( [ 'id' => 'database-cleanup', 'active' => true ] ) );
$row  = $GLOBALS['wpdb']->row( 'database-optimizer' );
check(
    $resp instanceof WP_REST_Response
    && [ 'id' => 'database-optimizer', 'active' => true, 'previous_active' => false, 'changed' => true ] === $resp->get_data()
    && '1' === ( $row['is_active'] ?? null )
    && null === $GLOBALS['wpdb']->row( 'database-cleanup' ),
    'toggle via legacy alias activates the canonical row with the four-field response; no legacy row recreated'
);
check( [ [ 'wpt_module_enabled', [ 'database-optimizer' ] ] ] === $GLOBALS['__did_actions'], 'wpt_module_enabled fires once with the canonical id' );

// No-op suppression: re-asserting the current state is HTTP 200 with
// changed=false, no database write, no lifecycle hook.
$GLOBALS['__did_actions'] = [];
$rows_before = json_encode( $GLOBALS['wpdb']->rows );
$resp = $ctrl->toggle_module( req( [ 'id' => 'database-cleanup', 'active' => true ] ) );
check(
    $resp instanceof WP_REST_Response && 200 === $resp->get_status()
    && [ 'id' => 'database-optimizer', 'active' => true, 'previous_active' => true, 'changed' => false ] === $resp->get_data()
    && json_encode( $GLOBALS['wpdb']->rows ) === $rows_before
    && [] === $GLOBALS['__did_actions'],
    'no-op re-enable returns 200 changed=false with NO write and NO hook'
);

$GLOBALS['__did_actions'] = [];
$resp = $ctrl->toggle_module( req( [ 'id' => 'database-cleanup', 'active' => false ] ) );
$row  = $GLOBALS['wpdb']->row( 'database-optimizer' );
check(
    [ 'id' => 'database-optimizer', 'active' => false, 'previous_active' => true, 'changed' => true ] === $resp->get_data()
    && '0' === ( $row['is_active'] ?? null )
    && [ 'keep' => 'me' ] === json_decode( $row['settings'], true )
    && [ [ 'wpt_module_disabled', [ 'database-optimizer' ] ] ] === $GLOBALS['__did_actions'],
    'toggle round-trip preserves settings and fires wpt_module_disabled with the canonical id'
);

check( is_wpt_error( $ctrl->toggle_module( req( [ 'id' => 'not-a-module', 'active' => true ] ) ), 'wpt_invalid_module', 404 ), 'toggle of unknown id returns wpt_invalid_module 404' );

$GLOBALS['__did_actions'] = [];
check(
    is_wpt_error( $ctrl->toggle_module( req( [ 'id' => 'white-label', 'active' => true ] ) ), 'wpt_pro_locked', 403 )
    && null === $GLOBALS['wpdb']->row( 'white-label' )
    && [] === $GLOBALS['__did_actions'],
    'unlicensed Pro toggle returns wpt_pro_locked 403 with no write and no hook'
);

// Hooks fire only AFTER persistence succeeds: a failed write returns
// wpt_toggle_failed and fires nothing.
$GLOBALS['__did_actions']           = [];
$GLOBALS['wpdb']->fail_next_write   = true;
check(
    is_wpt_error( $ctrl->toggle_module( req( [ 'id' => 'database-optimizer', 'active' => true ] ) ), 'wpt_toggle_failed', 500 )
    && [] === $GLOBALS['__did_actions'],
    'failed persistence returns wpt_toggle_failed 500 and fires NO lifecycle hook'
);

// ── Stub gating ───────────────────────────────────────────────
// login-protection is a stub seeded with a stale ACTIVE row: enabling
// rejects (canonical id or alias), disabling is allowed for cleanup.
$GLOBALS['__did_actions'] = [];
$stale = $GLOBALS['wpdb']->row( 'login-protection' );
check(
    is_wpt_error( $ctrl->toggle_module( req( [ 'id' => 'login-protection', 'active' => true ] ) ), 'wpt_module_stub', 400 )
    && is_wpt_error( $ctrl->toggle_module( req( [ 'id' => 'login-security', 'active' => true ] ) ), 'wpt_module_stub', 400 )
    && json_encode( $GLOBALS['wpdb']->row( 'login-protection' ) ) === json_encode( $stale )
    && [] === $GLOBALS['__did_actions'],
    'stub ENABLE rejects wpt_module_stub 400 via canonical id AND legacy alias, with no write and no hook'
);

check( true === Settings::is_module_active( 'login-protection' ), 'Settings::is_module_active reads the stale active row' );
$GLOBALS['__did_actions'] = [];
$resp = $ctrl->toggle_module( req( [ 'id' => 'login-protection', 'active' => false ] ) );
check(
    $resp instanceof WP_REST_Response
    && [ 'id' => 'login-protection', 'active' => false, 'previous_active' => true, 'changed' => true ] === $resp->get_data()
    && '0' === ( $GLOBALS['wpdb']->row( 'login-protection' )['is_active'] ?? null )
    && [ [ 'wpt_module_disabled', [ 'login-protection' ] ] ] === $GLOBALS['__did_actions']
    && false === Settings::is_module_active( 'login-protection' ),
    'stub DISABLE is allowed: stale active row cleaned up, wpt_module_disabled fires with canonical id'
);

$GLOBALS['__did_actions'] = [];
$resp = $ctrl->toggle_module( req( [ 'id' => 'login-protection', 'active' => false ] ) );
check(
    $resp instanceof WP_REST_Response && false === $resp->get_data()['changed'] && [] === $GLOBALS['__did_actions'],
    'second stub DISABLE is a suppressed no-op (changed=false, no hook)'
);
check(
    is_wpt_error( $ctrl->toggle_module( req( [ 'id' => 'login-protection', 'active' => true ] ) ), 'wpt_module_stub', 400 ),
    'stub ENABLE still rejects after cleanup (enable/disable asymmetry)'
);

// Per-module gating: the wpt_user_can_manage_module filter receives the
// CANONICAL id (even when the route is addressed by alias) and can deny.
$GLOBALS['__filter_saw'] = null;
add_filter( 'wpt_user_can_manage_module', function ( $can, $uid, $mid ) {
    $GLOBALS['__filter_saw'] = $mid;
    return 'database-optimizer' === $mid ? false : $can;
} );
check(
    is_wpt_error( $ctrl->can_toggle_module( req( [ 'id' => 'database-cleanup' ] ) ), 'wpt_forbidden', 403 )
    && 'database-optimizer' === $GLOBALS['__filter_saw'],
    'per-module filter receives the canonical id and can deny the toggle'
);
$GLOBALS['__filters']['wpt_user_can_manage_module'] = [];

// ── Zero-load: nothing in this run may include module code ───
check( [] === module_files_included(), 'ZERO module implementation files included across boot + every route' );
check( ! class_exists( 'WPTransformed\\Modules\\AdminInterface\\White_Label', false ), 'Pro class (White_Label) never loaded' );

echo $fail === 0 ? "\nALL GREEN\n" : "\n{$fail} FAILURE(S)\n";
exit( $fail === 0 ? 0 : 1 );
