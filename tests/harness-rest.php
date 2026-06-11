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
 *   - no-op SAVE suppression: identical re-save (by wp_json_encode
 *     string equality) returns true with NO write and NO
 *     wpt_module_settings_saved hook; changed saves and first saves
 *     (no cache entry) write and fire; encoding-changing differences
 *     (e.g. key order) count as real changes
 *   - validate_settings (slice 10a): Module_Base floor (whitelist,
 *     type coercion, array-mismatch + missing-key + unsupported-type
 *     fallbacks) and the Database_Cleanup override (strict categories,
 *     clamp, bool casts)
 *   - settings routes (slice 10a): full gate ladder on GET+POST
 *     (unknown 404 / pro 403 / stub 400 / missing-file 500) with zero
 *     includes and zero hooks on every rejection; alias requests carry
 *     canonicalized_from, canonical requests do not; GET lazy-loads
 *     exactly the target module file (the sanctioned zero-load
 *     exception); POST is FULL REPLACE via validate_settings with
 *     changed true/false semantics and wpt_settings_save_failed on a
 *     failed write — zero hook on every non-change path
 *   - the per-module wpt_user_can_manage_module filter receives the
 *     CANONICAL id and can deny the toggle
 *   - secret settings (slice 10b): GET masks declared secrets ('' or
 *     the __WPT_SECRET__ sentinel, defaults included); POST splices
 *     stored values for sentinel/omitted keys, '' clears, new
 *     plaintext is stored enc1:-encrypted, ciphertext injection is
 *     re-encrypted, legacy plaintext splices byte-unchanged,
 *     non-string secrets reject wpt_invalid_settings 400
 *   - Email_SMTP + Content_Duplication validate_settings overrides
 *     (port clamp, encryption enum, post_types registered-type filter,
 *     status/redirect enums)
 *   - action routes (slice 10b): ladder incl. wpt_module_inactive 409
 *     and active-instance wpt_module_unavailable 500; wrong-module ids
 *     reject; two-tier permissions (coarse gate + object-level 403);
 *     send-test-email success/failure mapping over the pinned
 *     {sent, debug} contract; actions fire ZERO lifecycle hooks
 *   - canonicalized_from parity on GET /modules/{id} (success payloads
 *     only, alias-addressed requests only)
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
define( 'AUTH_KEY', 'harness-auth-key-3f9a2c81d7e645b0' );        // Real AES path for Email_SMTP.
define( 'SECURE_AUTH_KEY', 'harness-secure-auth-key-8b14c6d2' );

// ── WP stubs ──────────────────────────────────────────────────
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( (array) $defaults, (array) $args ); }
function absint( $n ) { return abs( (int) $n ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function remove_action( $tag, $cb, $p = 10 ) { return true; }
function sanitize_email( $email ) { $email = trim( (string) $email ); return preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ? $email : ''; }
function is_email( $email ) { return preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email ) ? $email : false; }
function get_bloginfo( $show = '' ) { return 'Harness Site'; }
function current_time( $type ) { return '2026-06-10 00:00:00'; }
function post_type_exists( $type ) { return in_array( $type, [ 'post', 'page' ], true ); }
$GLOBALS['__wp_mail_calls']  = [];
$GLOBALS['__wp_mail_result'] = true;
function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
    $GLOBALS['__wp_mail_calls'][] = [ 'to' => $to, 'subject' => $subject ];
    return (bool) $GLOBALS['__wp_mail_result'];
}
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
        // Slice 10b action fixtures: both action-owning modules ACTIVE
        // (their files load at boot — the sanctioned active set), plus an
        // ACTIVE ghost row whose definition has a missing file (drives
        // the action ladder's wpt_module_unavailable 500 rung).
        // email-delivery settings are seeded in validate-output key
        // order so a sentinel round-trip is encoding-identical.
        $this->rows[] = [ 'module_id' => 'email-delivery', 'is_active' => '1', 'settings' => json_encode( [
            'from_email'     => '',
            'from_name'      => '',
            'force_from'     => true,
            'smtp_host'      => '',
            'smtp_port'      => 587,
            'encryption'     => 'tls',
            'authentication' => true,
            'username'       => '',
            'password'       => 'enc1:SEEDEDCIPHER',
        ] ) ];
        $this->rows[] = [ 'module_id' => 'content-duplication', 'is_active' => '1', 'settings' => '[]' ];
        $this->rows[] = [ 'module_id' => 'harness-ghost', 'is_active' => '1', 'settings' => '{}' ];
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

/** Fixture for the Module_Base::validate_settings floor assertions. */
class WPT_Harness_Settings_Module extends \WPTransformed\Modules\Module_Base {
    public function get_id(): string { return 'harness-settings-module'; }
    public function get_title(): string { return 'Harness Settings Module'; }
    public function get_category(): string { return 'utilities'; }
    public function get_description(): string { return 'validate_settings fixture'; }
    public function init(): void {}
    public function get_default_settings(): array {
        return [
            'flag'        => false,
            'count'       => 2,
            'ratio'       => 1.5,
            'name'        => 'x',
            'list'        => [ 'a' ],
            'unsupported' => null,
        ];
    }
}

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
function as_admin(): void { $GLOBALS['__user'] = [ 'logged_in' => true, 'caps' => array_merge( Permission_Manager::CAPS, [ 'edit_posts', 'edit_post' ] ) ]; }
function as_editor(): void { $GLOBALS['__user'] = [ 'logged_in' => true, 'caps' => [ 'edit_posts' ] ]; }
function as_subscriber(): void { $GLOBALS['__user'] = [ 'logged_in' => true, 'caps' => [ 'read' ] ]; }
function as_nobody(): void { $GLOBALS['__user'] = [ 'logged_in' => false, 'caps' => [] ]; }
function req( array $params = [] ): WP_REST_Request { return new WP_REST_Request( $params ); }
function is_wpt_error( $v, string $code, int $status ): bool {
    return $v instanceof WP_Error
        && $v->get_error_code() === $code
        && ( $v->get_error_data()['status'] ?? null ) === $status;
}

// Missing-file fixture: an implemented definition whose file does not
// exist on disk — drives the wpt_module_unavailable 500 gate.
add_filter( 'wpt_registered_modules', function ( $defs ) {
    $defs['harness-ghost'] = [
        'file'        => 'modules/utilities/class-harness-ghost.php',
        'class'       => 'WPTransformed\\Modules\\Utilities\\Harness_Ghost',
        'title'       => 'Ghost',
        'category'    => 'utilities',
        'description' => 'Missing-file fixture for wpt_module_unavailable.',
    ];
    return $defs;
} );

// Boot the real Core — the plugins_loaded equivalent of a REST dispatch.
$core = Core::instance();
$core->boot();
$defs = $core->get_definitions();

// Boot include set: exactly the two seeded-active action modules (the
// ghost's file is missing; the active stub never loads).
$boot_files = module_files_included();

$ctrl = new Rest_Controller();
$ctrl->register_routes();

// ── Route table ───────────────────────────────────────────────
$registered = [];
foreach ( $GLOBALS['__routes'] as $r ) {
    $registered[ $r['route'] . '|' . ( $r['args']['methods'] ?? '?' ) ] = $r;
}
$expected_routes = [
    '/system/status|' . WP_REST_Server::READABLE,
    '/modules|' . WP_REST_Server::READABLE,
    '/modules/(?P<id>[a-z0-9-]+)|' . WP_REST_Server::READABLE,
    '/capabilities|' . WP_REST_Server::READABLE,
    '/modules/(?P<id>[a-z0-9-]+)/toggle|' . WP_REST_Server::CREATABLE,
    '/modules/(?P<id>[a-z0-9-]+)/settings|' . WP_REST_Server::READABLE,
    '/modules/(?P<id>[a-z0-9-]+)/settings|' . WP_REST_Server::CREATABLE,
    '/modules/(?P<id>[a-z0-9-]+)/actions/(?P<action>duplicate)|' . WP_REST_Server::CREATABLE,
    '/modules/(?P<id>[a-z0-9-]+)/actions/(?P<action>send-test-email)|' . WP_REST_Server::CREATABLE,
];
$route_problems = [];
foreach ( $expected_routes as $key ) {
    $r = $registered[ $key ] ?? null;
    if ( ! $r ) { $route_problems[] = "{$key}: not registered"; continue; }
    if ( 'wpt/v1' !== $r['ns'] ) { $route_problems[] = "{$key}: wrong namespace"; }
    if ( ! is_callable( $r['args']['callback'] ?? null ) ) { $route_problems[] = "{$key}: no callback"; }
    if ( ! is_callable( $r['args']['permission_callback'] ?? null ) ) { $route_problems[] = "{$key}: no permission callback"; }
}
check( count( $GLOBALS['__routes'] ) === 9 && $route_problems === [], 'all 9 wpt/v1 routes registered with callbacks + permission callbacks' . ( $route_problems ? ' [' . implode( '; ', $route_problems ) . ']' : '' ) );
check( 'wpt/v1' === Rest_Controller::REST_NAMESPACE, 'namespace constant is wpt/v1' );
$toggle_args = $registered[ '/modules/(?P<id>[a-z0-9-]+)/toggle|' . WP_REST_Server::CREATABLE ]['args']['args'] ?? [];
check( ( $toggle_args['active']['required'] ?? false ) === true && ( $toggle_args['active']['type'] ?? '' ) === 'boolean', 'toggle route requires boolean active param' );
$sget  = $registered[ '/modules/(?P<id>[a-z0-9-]+)/settings|' . WP_REST_Server::READABLE ]['args']['args'] ?? [];
$spost = $registered[ '/modules/(?P<id>[a-z0-9-]+)/settings|' . WP_REST_Server::CREATABLE ]['args']['args'] ?? [];
check(
    ( $spost['settings']['required'] ?? false ) === true && ( $spost['settings']['type'] ?? '' ) === 'object'
    && ( $sget['id'] ?? null ) === ( $toggle_args['id'] ?? false )
    && ( $spost['id'] ?? null ) === ( $toggle_args['id'] ?? false ),
    'settings POST requires an object settings param; settings id args identical to the pinned toggle id arg (route regex + pattern + sanitizer)'
);
$adup  = $registered[ '/modules/(?P<id>[a-z0-9-]+)/actions/(?P<action>duplicate)|' . WP_REST_Server::CREATABLE ]['args']['args'] ?? [];
$amail = $registered[ '/modules/(?P<id>[a-z0-9-]+)/actions/(?P<action>send-test-email)|' . WP_REST_Server::CREATABLE ]['args']['args'] ?? [];
check(
    ( $adup['post_id']['required'] ?? false ) === true && ( $adup['post_id']['type'] ?? '' ) === 'integer' && ( $adup['post_id']['minimum'] ?? 0 ) === 1
    && ( $amail['recipient']['required'] ?? false ) === true && ( $amail['recipient']['type'] ?? '' ) === 'string'
    && ( $adup['id'] ?? null ) === ( $toggle_args['id'] ?? false ) && ( $amail['id'] ?? null ) === ( $toggle_args['id'] ?? false )
    && ( $adup['action']['pattern'] ?? '' ) === ( $toggle_args['id']['pattern'] ?? false ) && ( $adup['action']['sanitize_callback'] ?? '' ) === 'sanitize_key'
    && ( $amail['action']['pattern'] ?? '' ) === ( $toggle_args['id']['pattern'] ?? false ) && ( $amail['action']['sanitize_callback'] ?? '' ) === 'sanitize_key',
    'action routes: post_id required integer min 1; recipient required string; id + action args pin the existing strict pattern + sanitize_key'
);

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
check( $resp->get_status() === 200 && count( $list ) === count( $defs ) && count( $defs ) === 84 && $payload_problems === [], 'GET /modules returns all 84 definitions (83 registry + 1 ghost fixture), canonical ids only, no internal fields' . ( $payload_problems ? ' [' . implode( '; ', array_slice( $payload_problems, 0, 3 ) ) . ']' : '' ) );

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
    && $status['modules'] === [ 'total' => count( $defs ), 'active' => 4, 'pro_locked' => $pro_total ]
    && false === $status['safe_mode'],
    'GET /system/status reports version + module counts (4 active = stale stub + ghost + 2 action modules) + request-scoped safe_mode'
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

// ── Settings-save no-op suppression ───────────────────────────
// admin-bar-manager is seeded with settings {probe: kept}; the cache
// was primed at boot, so an identical re-save must suppress.
$GLOBALS['__did_actions'] = [];
$rows_before = json_encode( $GLOBALS['wpdb']->rows );
check(
    true === Settings::save( 'admin-bar-manager', [ 'probe' => 'kept' ] )
    && json_encode( $GLOBALS['wpdb']->rows ) === $rows_before
    && [] === $GLOBALS['__did_actions'],
    'identical re-save returns true with NO write and NO wpt_module_settings_saved hook'
);

$GLOBALS['__did_actions'] = [];
check(
    true === Settings::save( 'admin-bar-manager', [ 'a' => 1, 'b' => 2 ] )
    && [ 'a' => 1, 'b' => 2 ] === json_decode( $GLOBALS['wpdb']->row( 'admin-bar-manager' )['settings'], true )
    && '0' === $GLOBALS['wpdb']->row( 'admin-bar-manager' )['is_active']
    && [ [ 'wpt_module_settings_saved', [ 'admin-bar-manager', [ 'a' => 1, 'b' => 2 ] ] ] ] === $GLOBALS['__did_actions'],
    'changed save writes, preserves is_active, and fires the hook once with the persisted array'
);

$GLOBALS['__did_actions'] = [];
$rows_before = json_encode( $GLOBALS['wpdb']->rows );
Settings::save( 'admin-bar-manager', [ 'a' => 1, 'b' => 2 ] );
$noop_held = json_encode( $GLOBALS['wpdb']->rows ) === $rows_before && [] === $GLOBALS['__did_actions'];
Settings::save( 'admin-bar-manager', [ 'b' => 2, 'a' => 1 ] ); // same pairs, different key order
check(
    $noop_held
    && [ [ 'wpt_module_settings_saved', [ 'admin-bar-manager', [ 'b' => 2, 'a' => 1 ] ] ] ] === $GLOBALS['__did_actions'],
    'comparison is by encoding: identical re-save suppresses, key-order change writes and fires'
);

$GLOBALS['__did_actions'] = [];
check(
    null === $GLOBALS['wpdb']->row( 'duplicate-widget' )
    && true === Settings::save( 'duplicate-widget', [ 'fresh' => true ] )
    && [ 'fresh' => true ] === json_decode( $GLOBALS['wpdb']->row( 'duplicate-widget' )['settings'], true )
    && [ [ 'wpt_module_settings_saved', [ 'duplicate-widget', [ 'fresh' => true ] ] ] ] === $GLOBALS['__did_actions'],
    'first save with no cache entry is never a no-op: row created, hook fires'
);

// ── validate_settings: Module_Base floor ──────────────────────
$hm = new WPT_Harness_Settings_Module();
$v  = $hm->validate_settings( [ 'rogue' => 1, 'flag' => '1', 'count' => '7', 'ratio' => '2.5', 'name' => 99, 'unsupported' => 'evil' ] );
check(
    ! array_key_exists( 'rogue', $v )
    && true === $v['flag'] && 7 === $v['count'] && 2.5 === $v['ratio'] && '99' === $v['name']
    && [ 'a' ] === $v['list']
    && null === $v['unsupported']
    && array_keys( $hm->get_default_settings() ) === array_keys( $v ),
    'base validate_settings: unknown keys dropped, scalars coerced to default types, unsupported default type falls back, output keys = default keys exactly'
);
$v = $hm->validate_settings( [ 'list' => 'not-an-array', 'count' => [ 1, 2 ] ] );
check( [ 'a' ] === $v['list'] && 2 === $v['count'], 'base validate_settings: array/non-array mismatches (both directions) fall back to the default value' );
check( $hm->get_default_settings() === $hm->validate_settings( [] ), 'base validate_settings: missing keys fall back to defaults (empty input returns exactly the defaults)' );

// ── Settings routes: gate ladder (zero includes, zero hooks) ──
$GLOBALS['__did_actions'] = [];
check(
    is_wpt_error( $ctrl->get_module_settings( req( [ 'id' => 'not-a-module' ] ) ), 'wpt_invalid_module', 404 )
    && is_wpt_error( $ctrl->save_module_settings( req( [ 'id' => 'not-a-module', 'settings' => [] ] ) ), 'wpt_invalid_module', 404 ),
    'settings GET+POST: unknown id returns wpt_invalid_module 404'
);
check(
    is_wpt_error( $ctrl->get_module_settings( req( [ 'id' => 'white-label' ] ) ), 'wpt_pro_locked', 403 )
    && is_wpt_error( $ctrl->save_module_settings( req( [ 'id' => 'white-label', 'settings' => [] ] ) ), 'wpt_pro_locked', 403 )
    && ! class_exists( 'WPTransformed\\Modules\\AdminInterface\\White_Label', false ),
    'settings GET+POST: unlicensed Pro rejects wpt_pro_locked 403; Pro class never loads'
);
check(
    is_wpt_error( $ctrl->get_module_settings( req( [ 'id' => 'login-protection' ] ) ), 'wpt_module_stub', 400 )
    && is_wpt_error( $ctrl->save_module_settings( req( [ 'id' => 'login-security', 'settings' => [] ] ) ), 'wpt_module_stub', 400 ),
    'settings GET+POST: stub rejects wpt_module_stub 400 via canonical id AND legacy alias'
);
check(
    is_wpt_error( $ctrl->get_module_settings( req( [ 'id' => 'harness-ghost' ] ) ), 'wpt_module_unavailable', 500 )
    && is_wpt_error( $ctrl->save_module_settings( req( [ 'id' => 'harness-ghost', 'settings' => [] ] ) ), 'wpt_module_unavailable', 500 ),
    'settings GET+POST: implemented definition with a missing file returns wpt_module_unavailable 500'
);
check( module_files_included() === $boot_files && [] === $GLOBALS['__did_actions'], 'every settings gate-ladder rejection: ZERO module files included beyond the boot set, ZERO hooks fired' );

as_editor();
$GLOBALS['__did_actions'] = [];
check(
    is_wpt_error( $ctrl->can_manage_settings( req() ), 'wpt_forbidden', 403 ) && [] === $GLOBALS['__did_actions'],
    'settings permission failure (capless editor): wpt_forbidden 403, zero hooks'
);
as_nobody();
check( is_wpt_error( $ctrl->can_manage_settings( req() ), 'wpt_forbidden', 401 ), 'settings permission: logged-out caller gets 401' );
as_admin();

// ── Settings routes: GET (sanctioned single-module lazy load) ──
$resp  = $ctrl->get_module_settings( req( [ 'id' => 'database-cleanup' ] ) );
$delta = array_values( array_diff( array_map( 'basename', module_files_included() ), array_map( 'basename', $boot_files ) ) );
check(
    $resp instanceof WP_REST_Response && 200 === $resp->get_status()
    && 'database-optimizer' === $resp->get_data()['id']
    && 'database-cleanup' === ( $resp->get_data()['canonicalized_from'] ?? null )
    && [ 'class-database-cleanup.php' ] === $delta,
    'GET settings via alias: canonical id + canonicalized_from; include delta exactly 1 = class-database-cleanup.php'
);
$dbo = Core::instance()->get_module( 'database-optimizer' );
check(
    null !== $dbo
    && $resp->get_data()['defaults'] === $dbo->get_default_settings()
    && 0 === $resp->get_data()['settings']['keep_recent_revisions']
    && 'me' === ( $resp->get_data()['settings']['keep'] ?? null ),
    'GET settings payload: defaults = get_default_settings(); settings = defaults-merged stored row (stale stored keys surface in reads)'
);
$resp = $ctrl->get_module_settings( req( [ 'id' => 'database-optimizer' ] ) );
check(
    ! array_key_exists( 'canonicalized_from', $resp->get_data() ) && count( module_files_included() ) === count( $boot_files ) + 1,
    'canonical GET settings: no canonicalized_from member, no additional file includes'
);

// ── DBO validate_settings override ────────────────────────────
$cats = array_keys( $dbo->get_default_settings()['items_to_clean'] );
$post_body = [
    'items_to_clean'        => [ 'revisions' => false, 'bogus' => true ],
    'keep_recent_revisions' => '250',
    'optimize_tables'       => '1',
    'rogue'                 => 'dropped',
];
$expected_validated = $dbo->validate_settings( $post_body );
check(
    [ 'items_to_clean', 'keep_recent_revisions', 'optimize_tables' ] === array_keys( $expected_validated )
    && $cats === array_keys( $expected_validated['items_to_clean'] )
    && ! array_key_exists( 'bogus', $expected_validated['items_to_clean'] )
    && false === $expected_validated['items_to_clean']['revisions']
    && 100 === $expected_validated['keep_recent_revisions']
    && true === $expected_validated['optimize_tables'],
    'DBO validate_settings: strict categories (all present, non-listed dropped), keep clamped 250→100, optimize cast bool, output exactly 3 keys'
);
check( $dbo->get_default_settings() === $dbo->validate_settings( [] ), 'DBO validate_settings: empty input returns exactly the defaults' );

// ── Settings routes: POST (full replace + changed semantics) ──
$GLOBALS['__did_actions'] = [];
$resp = $ctrl->save_module_settings( req( [ 'id' => 'database-cleanup', 'settings' => $post_body ] ) );
$row  = $GLOBALS['wpdb']->row( 'database-optimizer' );
check(
    $resp instanceof WP_REST_Response && 200 === $resp->get_status()
    && true === $resp->get_data()['changed']
    && 'database-optimizer' === $resp->get_data()['id']
    && 'database-cleanup' === ( $resp->get_data()['canonicalized_from'] ?? null )
    && json_decode( $row['settings'], true ) === $expected_validated
    && [ [ 'wpt_module_settings_saved', [ 'database-optimizer', $expected_validated ] ] ] === $GLOBALS['__did_actions'],
    'POST settings via alias: FULL REPLACE (row becomes exactly validate_settings(body); stale stored key gone), changed=true, hook fires once with canonical id + validated array'
);

$GLOBALS['__did_actions'] = [];
$resp = $ctrl->save_module_settings( req( [ 'id' => 'database-optimizer', 'settings' => $post_body ] ) );
check(
    false === $resp->get_data()['changed']
    && ! array_key_exists( 'canonicalized_from', $resp->get_data() )
    && [] === $GLOBALS['__did_actions'],
    'identical re-POST: changed=false, ZERO hooks (storage-layer no-op); canonical request carries no canonicalized_from'
);

$GLOBALS['__did_actions'] = [];
$GLOBALS['wpdb']->fail_next_write = true;
check(
    is_wpt_error( $ctrl->save_module_settings( req( [ 'id' => 'database-optimizer', 'settings' => [ 'keep_recent_revisions' => 9 ] ] ) ), 'wpt_settings_save_failed', 500 )
    && [] === $GLOBALS['__did_actions']
    && 100 === json_decode( $GLOBALS['wpdb']->row( 'database-optimizer' )['settings'], true )['keep_recent_revisions'],
    'save failure: wpt_settings_save_failed 500, ZERO hooks, row unchanged'
);

// ── Secret settings (slice 10b) ───────────────────────────────
$email = Core::instance()->get_module( 'email-delivery' );
check( $email instanceof \WPTransformed\Modules\Utilities\Email_SMTP && [ 'password' ] === $email->get_secret_settings_keys(), 'email-delivery is active with password declared secret' );

$resp = $ctrl->get_module_settings( req( [ 'id' => 'email-smtp' ] ) );
check(
    '__WPT_SECRET__' === $resp->get_data()['settings']['password']
    && '' === $resp->get_data()['defaults']['password']
    && 'tls' === $resp->get_data()['settings']['encryption']
    && 'email-smtp' === ( $resp->get_data()['canonicalized_from'] ?? null ),
    'GET settings masks the stored secret with the sentinel (empty default stays empty); ciphertext never leaves; alias carries canonicalized_from'
);

// Sentinel round-trip: untouched GET -> POST is a clean no-op.
$round_trip = $resp->get_data()['settings'];
$GLOBALS['__did_actions'] = [];
$resp = $ctrl->save_module_settings( req( [ 'id' => 'email-delivery', 'settings' => $round_trip ] ) );
check(
    false === $resp->get_data()['changed']
    && [] === $GLOBALS['__did_actions']
    && 'enc1:SEEDEDCIPHER' === json_decode( $GLOBALS['wpdb']->row( 'email-delivery' )['settings'], true )['password'],
    'sentinel round-trip: stored secret spliced byte-unchanged, changed=false, ZERO hooks'
);

// New plaintext is encrypted; ciphertext injection is re-encrypted.
$GLOBALS['__did_actions'] = [];
$body = $round_trip;
$body['password'] = 'new-harness-pw';
$resp = $ctrl->save_module_settings( req( [ 'id' => 'email-delivery', 'settings' => $body ] ) );
$stored_pw = json_decode( $GLOBALS['wpdb']->row( 'email-delivery' )['settings'], true )['password'];
check(
    0 === strpos( $stored_pw, 'enc1:' ) && 'enc1:SEEDEDCIPHER' !== $stored_pw && 'new-harness-pw' !== $stored_pw
    && '__WPT_SECRET__' === $resp->get_data()['settings']['password']
    && 1 === count( $GLOBALS['__did_actions'] ),
    'new plaintext secret is stored enc1:-encrypted (never verbatim); the POST response masks it too; hook fires once'
);
$body['password'] = 'enc1:FAKEINJECT';
$ctrl->save_module_settings( req( [ 'id' => 'email-delivery', 'settings' => $body ] ) );
$stored_pw = json_decode( $GLOBALS['wpdb']->row( 'email-delivery' )['settings'], true )['password'];
check(
    0 === strpos( $stored_pw, 'enc1:' ) && 'enc1:FAKEINJECT' !== $stored_pw,
    'ciphertext injection: a non-matching enc1:-prefixed string is re-encrypted as plaintext, never persisted verbatim'
);

// Explicit '' clears; GET then shows ''.
$body['password'] = '';
$ctrl->save_module_settings( req( [ 'id' => 'email-delivery', 'settings' => $body ] ) );
$resp = $ctrl->get_module_settings( req( [ 'id' => 'email-delivery' ] ) );
check(
    '' === json_decode( $GLOBALS['wpdb']->row( 'email-delivery' )['settings'], true )['password']
    && '' === $resp->get_data()['settings']['password'],
    "explicit '' clears the secret; GET reports '' (not the sentinel) for empty"
);

// Legacy plaintext under sentinel splices byte-unchanged (the
// pre-encryption store case): seed via the storage layer directly —
// it is shape-agnostic — then round-trip with the sentinel.
$legacy_shape             = json_decode( $GLOBALS['wpdb']->row( 'email-delivery' )['settings'], true );
$legacy_shape['password'] = 'legacy-plain-pw';
Settings::save( 'email-delivery', $legacy_shape );
$GLOBALS['__did_actions'] = [];
$rt             = $legacy_shape;
$rt['password'] = '__WPT_SECRET__';
$resp = $ctrl->save_module_settings( req( [ 'id' => 'email-delivery', 'settings' => $rt ] ) );
check(
    'legacy-plain-pw' === json_decode( $GLOBALS['wpdb']->row( 'email-delivery' )['settings'], true )['password']
    && false === $resp->get_data()['changed']
    && [] === $GLOBALS['__did_actions'],
    'legacy plaintext under sentinel splices byte-unchanged (no silent upgrade), changed=false, ZERO hooks'
);

// Non-string secret rejects.
$GLOBALS['__did_actions'] = [];
$body['password'] = 123;
check(
    is_wpt_error( $ctrl->save_module_settings( req( [ 'id' => 'email-delivery', 'settings' => $body ] ) ), 'wpt_invalid_settings', 400 )
    && [] === $GLOBALS['__did_actions'],
    'non-string secret value rejects wpt_invalid_settings 400 with ZERO hooks'
);

// ── validate_settings overrides (slice 10b) ───────────────────
$v = $email->validate_settings( [ 'smtp_port' => '70000', 'encryption' => 'junk', 'from_email' => [ 'x' ], 'password' => '__keep__unrelated__' ] );
check( 587 === $v['smtp_port'] && 'tls' === $v['encryption'] && '' === $v['from_email'], 'Email_SMTP validate: port clamp 70000→587, encryption junk→tls, non-scalar from_email→empty' );
$v = $email->validate_settings( [ 'smtp_port' => '443', 'encryption' => 'ssl', 'authentication' => 1, 'username' => ' user ' ] );
check( 443 === $v['smtp_port'] && 'ssl' === $v['encryption'] && true === $v['authentication'] && 'user' === $v['username'], 'Email_SMTP validate: in-range port + valid enum + bool cast + text sanitization pass through' );

$cd = Core::instance()->get_module( 'content-duplication' );
$v  = $cd->validate_settings( [ 'post_types' => [ 'post', 'bogus_type', 'page' ], 'new_status' => 'publish', 'redirect_after' => 'edit' ] );
check( [ 'post', 'page' ] === $v['post_types'] && 'publish' === $v['new_status'] && 'edit' === $v['redirect_after'], 'Content_Duplication validate: post_types filtered to registered types, publish allowed, redirect enum' );
$v = $cd->validate_settings( [ 'post_types' => [ 'bogus_type' ], 'new_status' => 'junk', 'redirect_after' => 'junk' ] );
check( [ 'post', 'page' ] === $v['post_types'] && 'draft' === $v['new_status'] && 'list' === $v['redirect_after'], 'Content_Duplication validate: empty filter result falls back to [post, page]; invalid enums fall back' );

// ── Action routes: gate ladder (slice 10b) ────────────────────
$GLOBALS['__did_actions'] = [];
check(
    is_wpt_error( $ctrl->action_duplicate( req( [ 'id' => 'not-a-module', 'post_id' => 1 ] ) ), 'wpt_invalid_module', 404 )
    && is_wpt_error( $ctrl->action_send_test_email( req( [ 'id' => 'not-a-module', 'recipient' => 'a@b.co' ] ) ), 'wpt_invalid_module', 404 ),
    'actions: unknown id returns wpt_invalid_module 404'
);
check(
    is_wpt_error( $ctrl->action_duplicate( req( [ 'id' => 'white-label', 'post_id' => 1 ] ) ), 'wpt_pro_locked', 403 )
    && ! class_exists( 'WPTransformed\\Modules\\AdminInterface\\White_Label', false ),
    'actions: unlicensed Pro rejects wpt_pro_locked 403; Pro class never loads'
);
check(
    is_wpt_error( $ctrl->action_duplicate( req( [ 'id' => 'login-protection', 'post_id' => 1 ] ) ), 'wpt_module_stub', 400 ),
    'actions: stub rejects wpt_module_stub 400'
);
check(
    is_wpt_error( $ctrl->action_duplicate( req( [ 'id' => 'public-preview', 'post_id' => 1 ] ) ), 'wpt_module_inactive', 409 )
    && is_wpt_error( $ctrl->action_send_test_email( req( [ 'id' => 'public-preview', 'recipient' => 'a@b.co' ] ) ), 'wpt_module_inactive', 409 ),
    'actions: implemented-but-INACTIVE module rejects wpt_module_inactive 409 (settings routes serve it; actions do not)'
);
check(
    is_wpt_error( $ctrl->action_duplicate( req( [ 'id' => 'harness-ghost', 'post_id' => 1 ] ) ), 'wpt_module_unavailable', 500 ),
    'actions: ACTIVE module whose instance failed to load returns wpt_module_unavailable 500'
);
check(
    is_wpt_error( $ctrl->action_duplicate( req( [ 'id' => 'email-delivery', 'post_id' => 1 ] ) ), 'wpt_invalid_module', 404 )
    && is_wpt_error( $ctrl->action_send_test_email( req( [ 'id' => 'content-duplication', 'recipient' => 'a@b.co' ] ) ), 'wpt_invalid_module', 404 ),
    'actions: an active module that does not own the action returns wpt_invalid_module 404'
);

// Two-tier permissions: object-level edit_post check fails in the
// handler for a user holding only the coarse edit_posts cap.
as_editor();
check(
    is_wpt_error( $ctrl->action_duplicate( req( [ 'id' => 'content-duplication', 'post_id' => 5 ] ) ), 'wpt_forbidden', 403 ),
    'duplicate: object-level edit_post failure returns wpt_forbidden 403 (two-tier pattern)'
);
as_admin();

// Coarse permission callbacks.
check( true === $ctrl->can_duplicate_content( req() ) && true === $ctrl->can_send_test_email( req() ), 'action coarse gates pass for the caps-granted admin' );
as_subscriber();
check(
    is_wpt_error( $ctrl->can_duplicate_content( req() ), 'wpt_forbidden', 403 )
    && is_wpt_error( $ctrl->can_send_test_email( req() ), 'wpt_forbidden', 403 ),
    'action coarse gates: authenticated user without the capability gets 403'
);
as_nobody();
check(
    is_wpt_error( $ctrl->can_duplicate_content( req() ), 'wpt_forbidden', 401 )
    && is_wpt_error( $ctrl->can_send_test_email( req() ), 'wpt_forbidden', 401 ),
    'action coarse gates: logged-out caller gets 401'
);
as_admin();

// ── send-test-email action over the pinned {sent, debug} contract ──
check(
    is_wpt_error( $ctrl->action_send_test_email( req( [ 'id' => 'email-delivery', 'recipient' => 'not-an-email' ] ) ), 'wpt_invalid_recipient', 400 ),
    'send-test-email: invalid recipient rejects wpt_invalid_recipient 400 before any send'
);

$GLOBALS['__wp_mail_calls']  = [];
$GLOBALS['__wp_mail_result'] = true;
$resp = $ctrl->action_send_test_email( req( [ 'id' => 'email-smtp', 'recipient' => 'test@example.com' ] ) );
check(
    $resp instanceof WP_REST_Response && 200 === $resp->get_status()
    && [ 'sent' => true, 'recipient' => 'test@example.com', 'debug' => '', 'canonicalized_from' => 'email-smtp' ] === $resp->get_data()
    && 1 === count( $GLOBALS['__wp_mail_calls'] )
    && 'test@example.com' === $GLOBALS['__wp_mail_calls'][0]['to']
    && false !== strpos( $GLOBALS['__wp_mail_calls'][0]['subject'], 'Harness Site' ),
    'send-test-email success: 200 {sent, recipient, debug} + canonicalized_from via alias; wp_mail called once with site-name subject'
);

$GLOBALS['__wp_mail_result'] = false;
$err = $ctrl->action_send_test_email( req( [ 'id' => 'email-delivery', 'recipient' => 'test@example.com' ] ) );
check(
    is_wpt_error( $err, 'wpt_email_send_failed', 502 ) && '' === ( $err->get_error_data()['debug'] ?? null ),
    'send-test-email failure: wpt_email_send_failed 502 with data.debug carrying the capture'
);
$GLOBALS['__wp_mail_result'] = true;

// send_test_email pinned contract direct: {sent, debug}, never throws.
$direct = $email->send_test_email( 'direct@example.com' );
check( [ 'sent', 'debug' ] === array_keys( $direct ) && true === $direct['sent'] && is_string( $direct['debug'] ), 'send_test_email returns the pinned {sent: bool, debug: string} shape' );

// Actions fire ZERO lifecycle hooks (they are not toggles or saves).
check( [] === $GLOBALS['__did_actions'], 'across every action call above: ZERO wpt_module_* lifecycle hooks fired' );

// ── canonicalized_from parity on the module READ route ────────
$resp = $ctrl->get_module( req( [ 'id' => 'email-smtp' ] ) );
check(
    'email-delivery' === $resp->get_data()['id'] && 'email-smtp' === ( $resp->get_data()['canonicalized_from'] ?? null ),
    'GET /modules/{alias} now carries top-level canonicalized_from (parity with settings routes)'
);
$resp = $ctrl->get_module( req( [ 'id' => 'email-delivery' ] ) );
check(
    ! array_key_exists( 'canonicalized_from', $resp->get_data() ),
    'GET /modules/{canonical} carries no canonicalized_from member'
);
check(
    $ctrl->get_module( req( [ 'id' => 'nope-nope' ] ) ) instanceof WP_Error,
    'canonicalized_from is a SUCCESS-payload member only — error responses are bare WP_Error'
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

// ── Zero-load: the only module includes in this run are the two
// seeded-active action modules (boot) plus the single sanctioned
// settings-route lazy load ───────────────────────────────────────
$included = array_map( 'basename', module_files_included() );
sort( $included );
check(
    [ 'class-content-duplication.php', 'class-database-cleanup.php', 'class-email-smtp.php' ] === $included,
    'across boot + every route, exactly the sanctioned includes: 2 boot-active action modules + the settings-route target'
);
check( ! class_exists( 'WPTransformed\\Modules\\AdminInterface\\White_Label', false ), 'Pro class (White_Label) never loaded' );

echo $fail === 0 ? "\nALL GREEN\n" : "\n{$fail} FAILURE(S)\n";
exit( $fail === 0 ? 0 : 1 );
