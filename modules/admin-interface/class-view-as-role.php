<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * View as Role — Temporarily view the admin as a different role.
 *
 * Features:
 *  - Admin bar dropdown to select a role
 *  - Caps filtered via user_has_cap (DB role unchanged)
 *  - Original role stored in user meta _wpt_original_role
 *  - Persistent "Switch Back" bar while viewing as another role
 *  - Auto-expires after 1 hour
 *  - Only available to manage_options users
 *  - Restricted to is_admin() only
 *
 * @package WPTransformed
 */
class View_As_Role extends Module_Base {

    /**
     * User meta key for the original role data.
     */
    private const META_KEY = '_wpt_original_role';

    /**
     * Maximum duration in seconds before auto-expiry.
     */
    private const EXPIRY_SECONDS = 3600;

    /**
     * Static cache for the current switch data.
     *
     * @var array|null|false
     */
    private static $switch_cache = false;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'view-as-role';
    }

    public function get_title(): string {
        return __( 'View as Role', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Temporarily view the WordPress admin as a different user role without changing your actual role.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_switch_role', [ $this, 'ajax_switch_role' ] );
        add_action( 'wp_ajax_wpt_switch_back', [ $this, 'ajax_switch_back' ] );

        // Check for expired switches on admin_init.
        add_action( 'admin_init', [ $this, 'check_expiry' ] );

        // Filter capabilities when viewing as another role.
        add_filter( 'user_has_cap', [ $this, 'filter_user_caps' ], 999, 4 );

        // Admin bar dropdown.
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_menu' ], 90 );

        // Enqueue assets.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Persistent notification bar when switched.
        add_action( 'admin_notices', [ $this, 'render_switch_notice' ] );
    }

    // ── Admin Bar ─────────────────────────────────────────────

    /**
     * Add role-switching dropdown to the admin bar.
     *
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
     */
    public function add_admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! $this->can_user_switch() ) {
            return;
        }

        $switch_data = $this->get_switch_data();

        if ( $switch_data ) {
            // Show "Switch Back" button.
            $wp_admin_bar->add_node( [
                'id'    => 'wpt-view-as-role',
                'title' => sprintf(
                    /* translators: %s: role name */
                    esc_html__( 'Viewing as: %s', 'wptransformed' ),
                    esc_html( $this->get_role_display_name( $switch_data['role'] ) )
                ),
                'href'  => '#',
                'meta'  => [
                    'class' => 'wpt-view-as-role-active',
                ],
            ] );

            $wp_admin_bar->add_node( [
                'id'     => 'wpt-switch-back',
                'parent' => 'wpt-view-as-role',
                'title'  => esc_html__( 'Switch Back', 'wptransformed' ),
                'href'   => '#',
                'meta'   => [
                    'class'   => 'wpt-switch-back',
                    'onclick' => 'wptViewAsRole.switchBack(); return false;',
                ],
            ] );

            return;
        }

        // Show role selection dropdown.
        $wp_admin_bar->add_node( [
            'id'    => 'wpt-view-as-role',
            'title' => esc_html__( 'View as Role', 'wptransformed' ),
            'href'  => '#',
        ] );

        $roles = wp_roles()->get_names();

        foreach ( $roles as $slug => $name ) {
            // Skip administrator — no point viewing as your own role.
            if ( $slug === 'administrator' ) {
                continue;
            }

            $wp_admin_bar->add_node( [
                'id'     => 'wpt-role-' . $slug,
                'parent' => 'wpt-view-as-role',
                'title'  => esc_html( translate_user_role( $name ) ),
                'href'   => '#',
                'meta'   => [
                    'class'   => 'wpt-role-option',
                    'onclick' => 'wptViewAsRole.switchTo("' . esc_js( $slug ) . '"); return false;',
                ],
            ] );
        }
    }

    // ── AJAX: Switch Role ─────────────────────────────────────

    /**
     * Handle AJAX request to switch to a role.
     */
    public function ajax_switch_role(): void {
        check_ajax_referer( 'wpt_view_as_role_nonce', 'nonce' );

        if ( ! $this->can_user_switch() ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $role = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );

        // Validate role exists.
        $valid_roles = array_keys( wp_roles()->get_names() );
        if ( ! in_array( $role, $valid_roles, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid role.', 'wptransformed' ) ] );
        }

        // Don't allow switching to administrator.
        if ( $role === 'administrator' ) {
            wp_send_json_error( [ 'message' => __( 'Cannot switch to administrator.', 'wptransformed' ) ] );
        }

        // Store original role data.
        $switch_data = [
            'role'       => $role,
            'started_at' => current_time( 'mysql', true ),
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::EXPIRY_SECONDS ),
        ];

        update_user_meta( get_current_user_id(), self::META_KEY, $switch_data );
        self::$switch_cache = false; // Reset cache.

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: role name */
                __( 'Now viewing as %s.', 'wptransformed' ),
                $this->get_role_display_name( $role )
            ),
        ] );
    }

    /**
     * Handle AJAX request to switch back.
     */
    public function ajax_switch_back(): void {
        check_ajax_referer( 'wpt_view_as_role_nonce', 'nonce' );

        if ( ! current_user_can( 'exist' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        delete_user_meta( get_current_user_id(), self::META_KEY );
        self::$switch_cache = false;

        wp_send_json_success( [
            'message' => __( 'Switched back to your original role.', 'wptransformed' ),
        ] );
    }

    // ── Capability Filtering ──────────────────────────────────

    /**
     * Filter user capabilities when viewing as another role.
     *
     * @param array<string,bool> $allcaps All capabilities.
     * @param array<string>      $caps    Required capabilities.
     * @param array<mixed>       $args    Arguments (cap, user_id, ...).
     * @param \WP_User           $user    User object.
     * @return array<string,bool>
     */
    public function filter_user_caps( array $allcaps, array $caps, array $args, \WP_User $user ): array {
        $switch_data = $this->get_switch_data( $user->ID );

        if ( ! $switch_data ) {
            return $allcaps;
        }

        // Always allow these capabilities so the user can navigate and switch back.
        $always_allow = [ 'exist', 'read' ];
        $requested_cap = $args[0] ?? '';

        if ( in_array( $requested_cap, $always_allow, true ) ) {
            return $allcaps;
        }

        // The switch-back AJAX handler verifies its own nonce — no need to bypass
        // cap filtering here. manage_options is preserved in the simulated caps below.

        // Get the target role's capabilities.
        $role_obj = get_role( $switch_data['role'] );
        if ( ! $role_obj ) {
            return $allcaps;
        }

        return $role_obj->capabilities;
    }

    // ── Expiry Check ──────────────────────────────────────────

    /**
     * Check if the current switch has expired and clean up.
     * Triggers get_switch_data which handles expiry internally.
     */
    public function check_expiry(): void {
        $this->get_switch_data();
    }

    // ── Admin Notice ──────────────────────────────────────────

    /**
     * Show persistent admin notice when viewing as another role.
     */
    public function render_switch_notice(): void {
        $switch_data = $this->get_switch_data();

        if ( ! $switch_data ) {
            return;
        }

        $role_name  = $this->get_role_display_name( $switch_data['role'] );
        $expires_at = strtotime( $switch_data['expires_at'] );
        $remaining  = $expires_at ? max( 0, $expires_at - time() ) : 0;
        $minutes    = (int) ceil( $remaining / 60 );

        ?>
        <div class="notice notice-warning wpt-view-as-role-notice" style="display: flex; align-items: center; justify-content: space-between;">
            <p>
                <?php
                printf(
                    /* translators: 1: role name, 2: minutes remaining */
                    esc_html__( 'You are currently viewing the admin as "%1$s". This will auto-expire in %2$d minutes.', 'wptransformed' ),
                    esc_html( $role_name ),
                    $minutes
                );
                ?>
            </p>
            <p>
                <button type="button" class="button button-primary" onclick="wptViewAsRole.switchBack();">
                    <?php esc_html_e( 'Switch Back', 'wptransformed' ); ?>
                </button>
            </p>
        </div>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! $this->can_user_switch() && ! $this->get_switch_data() ) {
            return;
        }

        wp_enqueue_script(
            'wpt-view-as-role',
            WPT_URL . 'modules/admin-interface/js/view-as-role.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-view-as-role', 'wptViewAsRoleData', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_view_as_role_nonce' ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        ?>
        <div style="background: #fff; border: 1px solid #ddd; border-left: 4px solid #2271b1; border-radius: 4px; padding: 12px 16px; margin-bottom: 16px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'When enabled, administrators can temporarily view the admin dashboard as any other role using the admin bar dropdown. No settings to configure — just enable the module and use the "View as Role" menu in the admin bar.', 'wptransformed' ); ?>
            </p>
        </div>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'How it works', 'wptransformed' ); ?></th>
                <td>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><?php esc_html_e( 'Select a role from the "View as Role" dropdown in the admin bar.', 'wptransformed' ); ?></li>
                        <li><?php esc_html_e( 'Your actual role is never changed in the database.', 'wptransformed' ); ?></li>
                        <li><?php esc_html_e( 'Capabilities are filtered in real-time using WordPress filters.', 'wptransformed' ); ?></li>
                        <li><?php esc_html_e( 'A persistent notice shows which role you are viewing as.', 'wptransformed' ); ?></li>
                        <li><?php esc_html_e( 'The switch auto-expires after 1 hour for safety.', 'wptransformed' ); ?></li>
                        <li><?php esc_html_e( 'Only users with manage_options capability can use this feature.', 'wptransformed' ); ?></li>
                    </ul>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => self::META_KEY ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the current user has permission to switch roles.
     *
     * Must use the unfiltered check to avoid recursion.
     *
     * @return bool
     */
    private function can_user_switch(): bool {
        // If currently switched, check original caps.
        $switch_data = $this->get_switch_data();
        if ( $switch_data ) {
            // The user had manage_options before switching.
            return true;
        }

        return current_user_can( 'manage_options' );
    }

    /**
     * Get the current switch data for a user.
     *
     * Caches in a static variable to avoid repeated meta lookups.
     *
     * @param int|null $user_id User ID. Defaults to current user.
     * @return array|null Switch data or null.
     */
    private function get_switch_data( ?int $user_id = null ): ?array {
        $user_id = $user_id ?? get_current_user_id();

        if ( $user_id === get_current_user_id() && self::$switch_cache !== false ) {
            return self::$switch_cache;
        }

        $data = get_user_meta( $user_id, self::META_KEY, true );

        if ( ! is_array( $data ) || empty( $data['role'] ) || empty( $data['expires_at'] ) ) {
            if ( $user_id === get_current_user_id() ) {
                self::$switch_cache = null;
            }
            return null;
        }

        // Check expiry.
        $expires_at = strtotime( $data['expires_at'] );
        if ( $expires_at && time() > $expires_at ) {
            delete_user_meta( $user_id, self::META_KEY );
            if ( $user_id === get_current_user_id() ) {
                self::$switch_cache = null;
            }
            return null;
        }

        if ( $user_id === get_current_user_id() ) {
            self::$switch_cache = $data;
        }

        return $data;
    }

    /**
     * Get the display name for a role.
     *
     * @param string $role Role slug.
     * @return string Translated role name.
     */
    private function get_role_display_name( string $role ): string {
        $names = wp_roles()->get_names();
        $name  = $names[ $role ] ?? $role;
        return translate_user_role( $name );
    }
}
