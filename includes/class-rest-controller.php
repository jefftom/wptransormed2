<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API — canonical wpt/v1 surface (Phase 1 reframe step 9).
 *
 * Foundation skeleton: the namespace, the shared response/error contract,
 * and Permission_Manager-backed permission callbacks. REST is canonical
 * for NEW server I/O; existing admin-ajax endpoints keep working and
 * migrate screen-by-screen (reframe §7).
 *
 * Response contract (permanent — every future route uses these helpers):
 * - Success: WP_REST_Response wrapping the data payload directly with the
 *   HTTP status. No custom {success: true} envelope.
 * - Error: WP_Error with a stable wpt_* code, a human-readable message,
 *   and data.status — WP core renders the standard REST error shape.
 * Stable error codes so far: wpt_forbidden, wpt_invalid_module,
 * wpt_pro_locked, wpt_module_stub, wpt_module_unavailable,
 * wpt_toggle_failed, wpt_settings_save_failed.
 *
 * Read routes render from validated definitions only — never module
 * instances — so no module implementation file loads from a read
 * (zero-load contract). Zero-load amendment (slice 10a, PERMANENT):
 * the settings routes are the sanctioned single-module lazy-load
 * exception — at most the TARGET module's file is included, via
 * Core::load_module(), which never calls init() (no hooks register);
 * /modules and /modules/{id} stay strictly no-load, and the pro/stub
 * gates guarantee those files still never load. Route ids accept
 * legacy aliases, but every response and every settings write carries
 * the canonical id (alias-addressed responses carry canonicalized_from).
 *
 * @package WPTransformed
 */
class Rest_Controller {

    /** REST namespace — the canonical API surface for all new work. */
    public const REST_NAMESPACE = 'wpt/v1';

    /**
     * Boot-time hookup. Called on plugins_loaded for every request type;
     * rest_api_init only fires on REST dispatches, so this is free
     * everywhere else.
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ new self(), 'register_routes' ] );
    }

    /**
     * Register the wpt/v1 routes. Every route has a real permission
     * callback — there are no public unauthenticated routes.
     */
    public function register_routes(): void {
        register_rest_route( self::REST_NAMESPACE, '/system/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_system_status' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/modules', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_modules' ],
            'permission_callback' => [ $this, 'can_manage_modules' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/modules/(?P<id>[a-z0-9-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_module' ],
            'permission_callback' => [ $this, 'can_manage_modules' ],
            'args'                => [ 'id' => $this->module_id_arg() ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/capabilities', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_capabilities' ],
            'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/modules/(?P<id>[a-z0-9-]+)/toggle', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'toggle_module' ],
            'permission_callback' => [ $this, 'can_toggle_module' ],
            'args'                => [
                'id'     => $this->module_id_arg(),
                'active' => [
                    'description' => __( 'Target active state.', 'wptransformed' ),
                    'type'        => 'boolean',
                    'required'    => true,
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/modules/(?P<id>[a-z0-9-]+)/settings', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_module_settings' ],
            'permission_callback' => [ $this, 'can_manage_settings' ],
            'args'                => [ 'id' => $this->module_id_arg() ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/modules/(?P<id>[a-z0-9-]+)/settings', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'save_module_settings' ],
            'permission_callback' => [ $this, 'can_manage_settings' ],
            'args'                => [
                'id'       => $this->module_id_arg(),
                'settings' => [
                    'description' => __( 'Storage-shape module settings (full replace).', 'wptransformed' ),
                    'type'        => 'object',
                    'required'    => true,
                ],
            ],
        ] );
    }

    /* ══════════════════════════════════════════
       SHARED RESPONSE CONTRACT
    ══════════════════════════════════════════ */

    /**
     * Shared success helper: the payload IS the response body — no
     * envelope — plus the HTTP status.
     *
     * @param mixed $data   Response payload.
     * @param int   $status HTTP status code.
     */
    private function respond( $data, int $status = 200 ): \WP_REST_Response {
        return new \WP_REST_Response( $data, $status );
    }

    /**
     * Shared error helper: stable wpt_* code + readable message +
     * data.status. WP core converts this to the standard REST error
     * shape ({code, message, data: {status}}).
     */
    private function error( string $code, string $message, int $status ): \WP_Error {
        return new \WP_Error( $code, $message, [ 'status' => $status ] );
    }

    /** Canonical unknown-module error — identical shape on every route. */
    private function invalid_module(): \WP_Error {
        return $this->error( 'wpt_invalid_module', __( 'Unknown module.', 'wptransformed' ), 404 );
    }

    /* ══════════════════════════════════════════
       SHARED PERMISSION CALLBACKS
    ══════════════════════════════════════════ */

    /**
     * Shared capability gate with the canonical error shape: 401 for
     * logged-out callers, 403 for authenticated callers without the
     * capability (rest_authorization_required_code()).
     *
     * @return true|\WP_Error
     */
    private function require_cap( string $capability ) {
        if ( current_user_can( $capability ) ) {
            return true;
        }
        return $this->error(
            'wpt_forbidden',
            __( 'Sorry, you are not allowed to do that.', 'wptransformed' ),
            rest_authorization_required_code()
        );
    }

    /**
     * Route gate: manage_wpt.
     *
     * @return true|\WP_Error
     */
    public function can_manage( \WP_REST_Request $request ) {
        return $this->require_cap( Permission_Manager::CAP_MANAGE );
    }

    /**
     * Route gate: manage_wpt_modules.
     *
     * @return true|\WP_Error
     */
    public function can_manage_modules( \WP_REST_Request $request ) {
        return $this->require_cap( Permission_Manager::CAP_MODULES );
    }

    /**
     * Settings-route gate: manage_wpt_settings only — parity with the
     * Admin::handle_save form path, which checks CAP_SETTINGS and
     * nothing else. Deliberately NO per-module
     * wpt_user_can_manage_module filter and NO def['capability'] check:
     * the existing settings-save surface applies neither, and
     * def['capability'] currently gates app pages, not settings writes.
     * Decided parity choice — recorded with an open revisit-at-10c
     * question in checkpoint §16.2.
     *
     * @return true|\WP_Error
     */
    public function can_manage_settings( \WP_REST_Request $request ) {
        return $this->require_cap( Permission_Manager::CAP_SETTINGS );
    }

    /**
     * Toggle gate: manage_wpt_modules PLUS the per-module
     * wpt_user_can_manage_module filter — the same module-level gating
     * the single-module admin-ajax toggle applies. The filter receives
     * the canonical id when the route id resolves; unknown ids fall
     * through to the route callback's 404 so the error shape stays
     * consistent.
     *
     * @return true|\WP_Error
     */
    public function can_toggle_module( \WP_REST_Request $request ) {
        $base = $this->require_cap( Permission_Manager::CAP_MODULES );
        if ( true !== $base ) {
            return $base;
        }

        $def = Core::instance()->get_definition( (string) $request['id'] );
        if ( null !== $def && ! Permission_Manager::user_can_manage_module( $def['id'] ) ) {
            return $this->error(
                'wpt_forbidden',
                __( 'Sorry, you are not allowed to manage this module.', 'wptransformed' ),
                rest_authorization_required_code()
            );
        }

        return true;
    }

    /* ══════════════════════════════════════════
       ROUTE CALLBACKS
    ══════════════════════════════════════════ */

    /**
     * GET /system/status — minimal install overview.
     *
     * @return \WP_REST_Response
     */
    public function get_system_status( \WP_REST_Request $request ) {
        $core       = Core::instance();
        $active     = 0;
        $pro_locked = 0;

        foreach ( $core->get_definitions() as $id => $def ) {
            if ( $core->is_active( $id ) ) {
                $active++;
            }
            if ( 'pro' === $def['tier'] && ! Core::is_pro_licensed() ) {
                $pro_locked++;
            }
        }

        return $this->respond( [
            'version' => WPT_VERSION,
            'modules' => [
                'total'      => count( $core->get_definitions() ),
                'active'     => $active,
                'pro_locked' => $pro_locked,
            ],
            // Request-scoped by design: Safe Mode is a tokened wp-admin
            // gate and never applies to REST dispatches.
            'safe_mode' => Safe_Mode::is_active(),
        ] );
    }

    /**
     * GET /modules — definition-backed metadata + active state for every
     * registered module (locked Pro modules included; their files never
     * load).
     *
     * @return \WP_REST_Response
     */
    public function get_modules( \WP_REST_Request $request ) {
        $out = [];
        foreach ( Core::instance()->get_definitions() as $def ) {
            $out[] = $this->module_payload( $def );
        }
        return $this->respond( $out );
    }

    /**
     * GET /modules/{id} — accepts a canonical id or legacy alias; the
     * payload always carries the canonical id.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_module( \WP_REST_Request $request ) {
        $def = Core::instance()->get_definition( (string) $request['id'] );
        if ( null === $def ) {
            return $this->invalid_module();
        }
        return $this->respond( $this->module_payload( $def ) );
    }

    /**
     * GET /capabilities — the current user's WPT capability map as
     * booleans. No role or user enumeration.
     *
     * @return \WP_REST_Response
     */
    public function get_capabilities( \WP_REST_Request $request ) {
        $caps = [];
        foreach ( Permission_Manager::CAPS as $cap ) {
            $caps[ $cap ] = current_user_can( $cap );
        }
        return $this->respond( $caps );
    }

    /**
     * POST /modules/{id}/toggle — same validation order and gating as
     * the admin-ajax toggle, through the same shared persistence path
     * (Core::set_module_active), so lifecycle hooks can never drift
     * between the two surfaces.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function toggle_module( \WP_REST_Request $request ) {
        $def = Core::instance()->get_definition( (string) $request['id'] );
        if ( null === $def ) {
            return $this->invalid_module();
        }

        // Canonical id only past this point — settings writes must never
        // recreate a legacy row (live-verification F1).
        $module_id = $def['id'];

        if ( 'pro' === $def['tier'] && ! Core::is_pro_licensed() ) {
            return $this->error( 'wpt_pro_locked', __( 'Pro license required.', 'wptransformed' ), 403 );
        }

        $active = (bool) $request['active'];

        // Non-implemented definitions (stub today; fails closed for any
        // future status) are inert: ENABLE rejects, DISABLE stays
        // allowed so previously-persisted active rows can be cleaned up.
        if ( $active && 'implemented' !== $def['status'] ) {
            return $this->error( 'wpt_module_stub', __( 'This module is not yet implemented.', 'wptransformed' ), 400 );
        }

        // Pre-toggle state for the response contract — read from the
        // same source the no-op suppression in Settings::toggle_module
        // keys off (the loader's active list is a boot snapshot).
        $previous_active = Settings::is_module_active( $module_id );

        if ( ! Core::instance()->set_module_active( $module_id, $active ) ) {
            return $this->error( 'wpt_toggle_failed', __( 'Failed to update module state.', 'wptransformed' ), 500 );
        }

        // PERMANENT response contract: a no-op re-assertion is HTTP 200
        // with changed=false (and no lifecycle hook fired).
        return $this->respond( [
            'id'              => $module_id,
            'active'          => $active,
            'previous_active' => $previous_active,
            'changed'         => $previous_active !== $active,
        ] );
    }

    /**
     * GET /modules/{id}/settings — the module's defaults-merged stored
     * settings plus its defaults, rendered from the lazily-loaded
     * instance (the sanctioned zero-load exception; init() never runs).
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_module_settings( \WP_REST_Request $request ) {
        $resolved = $this->resolve_settings_module( $request );
        if ( is_wp_error( $resolved ) ) {
            return $resolved;
        }
        [ $module, $def ] = $resolved;

        $payload = [
            'id'       => $def['id'],
            'settings' => $module->get_settings(),
            'defaults' => $module->get_default_settings(),
        ];

        return $this->respond( $this->with_canonicalized_from( $payload, $request, $def ) );
    }

    /**
     * POST /modules/{id}/settings — FULL REPLACE: the stored row
     * becomes exactly validate_settings( body.settings ), storage shape
     * in and out. NEVER sanitize_settings() — that method expects raw
     * form input and silently returns defaults for storage-shape data
     * (the import bug class). Reads stay defaults-merged via
     * get_settings(), so omitted keys behave as defaults. An identical
     * save is a storage-layer no-op (no write, no
     * wpt_module_settings_saved) and returns changed=false.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function save_module_settings( \WP_REST_Request $request ) {
        $resolved = $this->resolve_settings_module( $request );
        if ( is_wp_error( $resolved ) ) {
            return $resolved;
        }
        [ $module, $def ] = $resolved;

        $validated = $module->validate_settings( (array) $request['settings'] );
        $pre       = wp_json_encode( Settings::get( $def['id'] ) );

        if ( ! Settings::save( $def['id'], $validated ) ) {
            return $this->error( 'wpt_settings_save_failed', __( 'Failed to save module settings.', 'wptransformed' ), 500 );
        }

        $payload = [
            'id'       => $def['id'],
            'settings' => $module->get_settings(),
            'changed'  => wp_json_encode( Settings::get( $def['id'] ) ) !== $pre,
        ];

        return $this->respond( $this->with_canonicalized_from( $payload, $request, $def ) );
    }

    /* ══════════════════════════════════════════
       INTERNAL
    ══════════════════════════════════════════ */

    /**
     * Shared settings-route gate ladder, in the toggle route's order:
     * unknown definition → wpt_invalid_module 404 · pro and unlicensed
     * → wpt_pro_locked 403 (file never loads) · status !== implemented
     * → wpt_module_stub 400 (file never loads) · load_module() null
     * after passing those gates → wpt_module_unavailable 500.
     *
     * The load_module() call is the sanctioned single-module lazy-load
     * exception to the read-route zero-load rule: it includes at most
     * the target module's file and never calls init(), so no hooks
     * register.
     *
     * @return array{0:\WPTransformed\Modules\Module_Base,1:array}|\WP_Error
     */
    private function resolve_settings_module( \WP_REST_Request $request ) {
        $def = Core::instance()->get_definition( (string) $request['id'] );
        if ( null === $def ) {
            return $this->invalid_module();
        }

        if ( 'pro' === $def['tier'] && ! Core::is_pro_licensed() ) {
            return $this->error( 'wpt_pro_locked', __( 'Pro license required.', 'wptransformed' ), 403 );
        }

        if ( 'implemented' !== $def['status'] ) {
            return $this->error( 'wpt_module_stub', __( 'This module is not yet implemented.', 'wptransformed' ), 400 );
        }

        $module = Core::instance()->load_module( $def['id'] );
        if ( null === $module ) {
            return $this->error( 'wpt_module_unavailable', __( 'Module could not be loaded.', 'wptransformed' ), 500 );
        }

        return [ $module, $def ];
    }

    /**
     * Alias meta: when the route was addressed by a legacy alias, the
     * payload carries canonicalized_from = the requested id; the id
     * member is always canonical (skeleton convention).
     */
    private function with_canonicalized_from( array $payload, \WP_REST_Request $request, array $def ): array {
        $requested = (string) $request['id'];
        if ( $requested !== $def['id'] ) {
            $payload['canonicalized_from'] = $requested;
        }
        return $payload;
    }

    /**
     * Shared {id} route argument schema: canonical id or legacy alias.
     */
    private function module_id_arg(): array {
        return [
            'description'       => __( 'Canonical module id or legacy alias.', 'wptransformed' ),
            'type'              => 'string',
            'pattern'           => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
            'sanitize_callback' => 'sanitize_key',
        ];
    }

    /**
     * Definition → REST payload. Renders from the definition only — no
     * module instance, no file include. Internal wiring fields (file,
     * class, capability, has_cleanup) stay private.
     */
    private function module_payload( array $def ): array {
        $core = Core::instance();

        return [
            'id'              => $def['id'],
            'title'           => $def['title'],
            'description'     => $def['description'],
            'category'        => $def['category'],
            'tier'            => $def['tier'],
            'risk'            => $def['risk'],
            'status'          => $def['status'],
            'default_enabled' => (bool) $def['default_enabled'],
            'has_settings'    => (bool) $def['has_settings'],
            'app_page'        => $def['app_page'],
            'dependencies'    => array_values( (array) $def['dependencies'] ),
            'search_terms'    => array_values( (array) $def['search_terms'] ),
            'legacy_ids'      => array_values( (array) $def['legacy_ids'] ),
            'active'          => $core->is_active( $def['id'] ),
            'pro_locked'      => 'pro' === $def['tier'] && ! Core::is_pro_licensed(),
        ];
    }
}
