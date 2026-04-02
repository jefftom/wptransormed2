<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Multiple User Roles -- Allow assigning multiple roles to a single user.
 *
 * Features:
 *  - Multi-select role checkboxes on user profile replacing single dropdown
 *  - Always keep at least one role
 *  - Confirmation before removing Administrator role
 *  - Profile update handler: set_role('') then add_role() for each selected
 *
 * @package WPTransformed
 */
class Multiple_User_Roles extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'multiple-user-roles';
    }

    public function get_title(): string {
        return __( 'Multiple User Roles', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Assign multiple roles to a single WordPress user with checkbox-based role selection.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Replace role dropdown with checkboxes.
        add_action( 'edit_user_profile', [ $this, 'render_role_checkboxes' ] );
        add_action( 'show_user_profile', [ $this, 'render_role_checkboxes' ] );

        // Save roles on profile update.
        add_action( 'profile_update', [ $this, 'save_user_roles' ] );

        // Hide the default role dropdown via CSS.
        add_action( 'admin_head', [ $this, 'hide_default_role_select' ] );

        // Enqueue confirmation script.
        add_action( 'admin_footer', [ $this, 'output_confirmation_script' ] );
    }

    // ── Hide Default Role Select ──────────────────────────────

    /**
     * Hide the default WordPress role selector on user edit pages.
     */
    public function hide_default_role_select(): void {
        $screen = get_current_screen();
        if ( ! $screen || ( $screen->base !== 'user-edit' && $screen->base !== 'profile' ) ) {
            return;
        }
        echo '<style>.user-role-wrap { display: none !important; }</style>';
    }

    // ── Render Role Checkboxes ────────────────────────────────

    /**
     * Render role checkboxes on the user profile page.
     *
     * @param \WP_User $user The user being edited.
     */
    public function render_role_checkboxes( \WP_User $user ): void {
        if ( ! current_user_can( 'promote_users' ) && ! current_user_can( 'edit_users' ) ) {
            return;
        }

        $all_roles  = wp_roles()->get_names();
        $user_roles = (array) $user->roles;
        $nonce      = wp_create_nonce( 'wpt_multiple_roles_' . $user->ID );
        ?>
        <h3><?php esc_html_e( 'User Roles', 'wptransformed' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Assigned Roles', 'wptransformed' ); ?></th>
                <td>
                    <input type="hidden" name="wpt_multiple_roles_nonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <?php foreach ( $all_roles as $role_slug => $role_name ) : ?>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" name="wpt_user_roles[]"
                                   value="<?php echo esc_attr( $role_slug ); ?>"
                                   <?php checked( in_array( $role_slug, $user_roles, true ) ); ?>
                                   class="wpt-role-checkbox"
                                   data-role="<?php echo esc_attr( $role_slug ); ?>">
                            <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php esc_html_e( 'Select one or more roles for this user. At least one role must remain assigned.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Save User Roles ───────────────────────────────────────

    /**
     * Save user roles on profile update.
     *
     * @param int $user_id User ID being updated.
     */
    public function save_user_roles( int $user_id ): void {
        // Verify nonce.
        if ( ! isset( $_POST['wpt_multiple_roles_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpt_multiple_roles_nonce'] ) ), 'wpt_multiple_roles_' . $user_id ) ) {
            return;
        }

        // Check capability.
        if ( ! current_user_can( 'promote_users' ) && ! current_user_can( 'edit_users' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $new_roles = isset( $_POST['wpt_user_roles'] ) && is_array( $_POST['wpt_user_roles'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['wpt_user_roles'] ) )
            : [];

        // Validate roles exist.
        $valid_roles = array_keys( wp_roles()->get_names() );
        $new_roles   = array_intersect( $new_roles, $valid_roles );

        // Must have at least one role.
        if ( empty( $new_roles ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        // Prevent non-super-admins from granting administrator to users who don't already have it.
        if ( ! current_user_can( 'manage_options' ) && in_array( 'administrator', $new_roles, true ) ) {
            if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
                $new_roles = array_diff( $new_roles, [ 'administrator' ] );
                if ( empty( $new_roles ) ) {
                    return;
                }
            }
        }

        // Clear all existing roles.
        $user->set_role( '' );

        // Add selected roles.
        foreach ( $new_roles as $role ) {
            $user->add_role( $role );
        }
    }

    // ── Confirmation Script ───────────────────────────────────

    /**
     * Output JavaScript for confirmation when unchecking Administrator.
     */
    public function output_confirmation_script(): void {
        $screen = get_current_screen();
        if ( ! $screen || ( $screen->base !== 'user-edit' && $screen->base !== 'profile' ) ) {
            return;
        }
        ?>
        <script>
        (function(){
            var checkboxes = document.querySelectorAll('.wpt-role-checkbox');
            checkboxes.forEach(function(cb){
                cb.addEventListener('change', function(){
                    // Confirm removal of Administrator.
                    if(this.dataset.role === 'administrator' && !this.checked){
                        if(!confirm(<?php echo wp_json_encode( __( 'Are you sure you want to remove the Administrator role from this user?', 'wptransformed' ) ); ?>)){
                            this.checked = true;
                        }
                    }

                    // Ensure at least one role stays checked.
                    var checked = document.querySelectorAll('.wpt-role-checkbox:checked');
                    if(checked.length === 0){
                        this.checked = true;
                        alert(<?php echo wp_json_encode( __( 'At least one role must remain assigned.', 'wptransformed' ) ); ?>);
                    }
                });
            });
        })();
        </script>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-mur-enabled"><?php esc_html_e( 'Enable Multiple Roles', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wpt-mur-enabled" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Allow assigning multiple roles to users via checkboxes on the profile page.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Inline CSS/JS only on user profile pages.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'settings' => true,
        ];
    }
}
