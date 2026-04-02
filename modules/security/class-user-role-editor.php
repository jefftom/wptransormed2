<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * User Role Editor -- Manage WordPress roles and capabilities.
 *
 * Features:
 *  - View and edit capabilities for any role
 *  - Group capabilities by prefix (Posts, Pages, Users, etc.)
 *  - Add custom roles (cloned from existing role)
 *  - Delete custom roles (WP defaults protected)
 *  - Reset roles to WordPress defaults
 *  - Safety: cannot remove manage_options from last administrator
 *  - "View as Role" temporary capability switch via user_has_cap filter
 *
 * @package WPTransformed
 */
class User_Role_Editor extends Module_Base {

    /**
     * WordPress default roles that cannot be deleted.
     *
     * @var string[]
     */
    private const DEFAULT_ROLES = [
        'administrator',
        'editor',
        'author',
        'contributor',
        'subscriber',
    ];

    /**
     * Capability grouping rules. Key = group label, value = cap prefixes.
     *
     * @var array<string, string[]>
     */
    private const CAP_GROUPS = [
        'Posts'    => [ 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts' ],
        'Pages'   => [ 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'edit_private_pages', 'read_private_pages' ],
        'Users'   => [ 'list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users', 'remove_users' ],
        'Plugins' => [ 'activate_plugins', 'edit_plugins', 'install_plugins', 'update_plugins', 'delete_plugins' ],
        'Themes'  => [ 'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'edit_theme_options' ],
        'Settings'=> [ 'manage_options', 'manage_links', 'manage_categories', 'moderate_comments', 'unfiltered_html' ],
        'General' => [ 'read', 'upload_files', 'unfiltered_upload', 'import', 'export', 'edit_dashboard', 'update_core', 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'level_6', 'level_7', 'level_8', 'level_9', 'level_10' ],
    ];

    /**
     * Default capabilities for each WordPress default role.
     * Used by the reset feature.
     *
     * @var array<string, array<string, bool>>
     */
    private const WP_DEFAULT_CAPS = [
        'administrator' => [
            'switch_themes' => true, 'edit_themes' => true, 'activate_plugins' => true,
            'edit_plugins' => true, 'edit_users' => true, 'edit_files' => true,
            'manage_options' => true, 'moderate_comments' => true, 'manage_categories' => true,
            'manage_links' => true, 'upload_files' => true, 'import' => true,
            'unfiltered_html' => true, 'edit_posts' => true, 'edit_others_posts' => true,
            'edit_published_posts' => true, 'publish_posts' => true, 'edit_pages' => true,
            'read' => true, 'level_10' => true, 'level_9' => true, 'level_8' => true,
            'level_7' => true, 'level_6' => true, 'level_5' => true, 'level_4' => true,
            'level_3' => true, 'level_2' => true, 'level_1' => true, 'level_0' => true,
            'edit_others_pages' => true, 'edit_published_pages' => true,
            'publish_pages' => true, 'delete_pages' => true, 'delete_others_pages' => true,
            'delete_published_pages' => true, 'delete_posts' => true,
            'delete_others_posts' => true, 'delete_published_posts' => true,
            'delete_private_posts' => true, 'edit_private_posts' => true,
            'read_private_posts' => true, 'delete_private_pages' => true,
            'edit_private_pages' => true, 'read_private_pages' => true,
            'delete_users' => true, 'create_users' => true, 'unfiltered_upload' => true,
            'edit_dashboard' => true, 'update_plugins' => true, 'delete_plugins' => true,
            'install_plugins' => true, 'update_themes' => true, 'install_themes' => true,
            'update_core' => true, 'list_users' => true, 'remove_users' => true,
            'promote_users' => true, 'edit_theme_options' => true, 'delete_themes' => true,
            'export' => true,
        ],
        'editor' => [
            'moderate_comments' => true, 'manage_categories' => true, 'manage_links' => true,
            'upload_files' => true, 'unfiltered_html' => true, 'edit_posts' => true,
            'edit_others_posts' => true, 'edit_published_posts' => true,
            'publish_posts' => true, 'edit_pages' => true, 'read' => true,
            'level_7' => true, 'level_6' => true, 'level_5' => true, 'level_4' => true,
            'level_3' => true, 'level_2' => true, 'level_1' => true, 'level_0' => true,
            'edit_others_pages' => true, 'edit_published_pages' => true,
            'publish_pages' => true, 'delete_pages' => true, 'delete_others_pages' => true,
            'delete_published_pages' => true, 'delete_posts' => true,
            'delete_others_posts' => true, 'delete_published_posts' => true,
            'delete_private_posts' => true, 'edit_private_posts' => true,
            'read_private_posts' => true, 'delete_private_pages' => true,
            'edit_private_pages' => true, 'read_private_pages' => true,
        ],
        'author' => [
            'upload_files' => true, 'edit_posts' => true, 'edit_published_posts' => true,
            'publish_posts' => true, 'read' => true, 'level_2' => true,
            'level_1' => true, 'level_0' => true, 'delete_posts' => true,
            'delete_published_posts' => true,
        ],
        'contributor' => [
            'edit_posts' => true, 'read' => true, 'level_1' => true, 'level_0' => true,
            'delete_posts' => true,
        ],
        'subscriber' => [
            'read' => true, 'level_0' => true,
        ],
    ];

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'user-role-editor';
    }

    public function get_title(): string {
        return __( 'User Role Editor', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Edit user roles and capabilities, add custom roles, and temporarily view the site as any role.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'show_admin_bar_switch' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        // AJAX handlers -- admin only, all require manage_options.
        add_action( 'wp_ajax_wpt_save_role_caps',  [ $this, 'ajax_save_role_caps' ] );
        add_action( 'wp_ajax_wpt_add_role',         [ $this, 'ajax_add_role' ] );
        add_action( 'wp_ajax_wpt_delete_role',      [ $this, 'ajax_delete_role' ] );
        add_action( 'wp_ajax_wpt_reset_role',       [ $this, 'ajax_reset_role' ] );
        add_action( 'wp_ajax_wpt_view_as_role',     [ $this, 'ajax_view_as_role' ] );
        add_action( 'wp_ajax_wpt_stop_view_as',     [ $this, 'ajax_stop_view_as' ] );

        // "View as Role" filter -- apply cap override when transient is set.
        add_filter( 'user_has_cap', [ $this, 'filter_view_as_role' ], 999, 4 );

        // Admin bar "View as Role" indicator.
        $settings = $this->get_settings();
        if ( ! empty( $settings['show_admin_bar_switch'] ) ) {
            add_action( 'admin_bar_menu', [ $this, 'admin_bar_view_as' ], 999 );
        }
    }

    // -- AJAX: Save Role Capabilities -------------------------------------

    /**
     * Update capabilities for a specific role.
     */
    public function ajax_save_role_caps(): void {
        check_ajax_referer( 'wpt_role_editor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $role_slug = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
        $caps_json = isset( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : '{}';

        if ( empty( $role_slug ) ) {
            wp_send_json_error( [ 'message' => __( 'No role specified.', 'wptransformed' ) ] );
        }

        $role = get_role( $role_slug );
        if ( ! $role ) {
            wp_send_json_error( [ 'message' => __( 'Role not found.', 'wptransformed' ) ] );
        }

        $new_caps = json_decode( $caps_json, true );
        if ( ! is_array( $new_caps ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid capability data.', 'wptransformed' ) ] );
        }

        // Safety: ensure manage_options is not removed from administrator
        // if this is the only role with manage_options.
        if ( $role_slug === 'administrator' && empty( $new_caps['manage_options'] ) ) {
            if ( $this->is_last_admin_role() ) {
                wp_send_json_error( [
                    'message' => __( 'Cannot remove manage_options from the last administrator role.', 'wptransformed' ),
                ] );
            }
        }

        // Get all known capabilities to determine what to add/remove.
        $all_caps = $this->get_all_capabilities();

        foreach ( $all_caps as $cap ) {
            $cap_key = sanitize_key( $cap );
            if ( ! empty( $new_caps[ $cap_key ] ) ) {
                $role->add_cap( $cap_key );
            } else {
                $role->remove_cap( $cap_key );
            }
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: role name */
                __( 'Capabilities updated for role "%s".', 'wptransformed' ),
                $role_slug
            ),
        ] );
    }

    // -- AJAX: Add Role ---------------------------------------------------

    /**
     * Add a new custom role, optionally cloning caps from an existing role.
     */
    public function ajax_add_role(): void {
        check_ajax_referer( 'wpt_role_editor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $role_slug   = isset( $_POST['role_slug'] ) ? sanitize_key( wp_unslash( $_POST['role_slug'] ) ) : '';
        $role_name   = isset( $_POST['role_name'] ) ? sanitize_text_field( wp_unslash( $_POST['role_name'] ) ) : '';
        $clone_from  = isset( $_POST['clone_from'] ) ? sanitize_key( wp_unslash( $_POST['clone_from'] ) ) : '';

        if ( empty( $role_slug ) || empty( $role_name ) ) {
            wp_send_json_error( [ 'message' => __( 'Role slug and display name are required.', 'wptransformed' ) ] );
        }

        // Validate slug format.
        if ( ! preg_match( '/^[a-z0-9_]+$/', $role_slug ) ) {
            wp_send_json_error( [ 'message' => __( 'Role slug must contain only lowercase letters, numbers, and underscores.', 'wptransformed' ) ] );
        }

        // Check if role already exists.
        if ( get_role( $role_slug ) ) {
            wp_send_json_error( [ 'message' => __( 'A role with this slug already exists.', 'wptransformed' ) ] );
        }

        // Clone capabilities if source role specified.
        $capabilities = [];
        if ( ! empty( $clone_from ) ) {
            $source_role = get_role( $clone_from );
            if ( $source_role ) {
                $capabilities = $source_role->capabilities;
            }
        }

        $result = add_role( $role_slug, $role_name, $capabilities );

        if ( null === $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to create role.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: role display name */
                __( 'Role "%s" created.', 'wptransformed' ),
                $role_name
            ),
        ] );
    }

    // -- AJAX: Delete Role ------------------------------------------------

    /**
     * Delete a custom role. WP default roles are protected.
     */
    public function ajax_delete_role(): void {
        check_ajax_referer( 'wpt_role_editor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $role_slug = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

        if ( empty( $role_slug ) ) {
            wp_send_json_error( [ 'message' => __( 'No role specified.', 'wptransformed' ) ] );
        }

        // Protect default WordPress roles.
        if ( in_array( $role_slug, self::DEFAULT_ROLES, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Cannot delete a default WordPress role.', 'wptransformed' ) ] );
        }

        if ( ! get_role( $role_slug ) ) {
            wp_send_json_error( [ 'message' => __( 'Role not found.', 'wptransformed' ) ] );
        }

        // Check if any users are assigned this role.
        $users_with_role = get_users( [ 'role' => $role_slug, 'number' => 1 ] );
        if ( ! empty( $users_with_role ) ) {
            wp_send_json_error( [
                'message' => __( 'Cannot delete a role that is assigned to users. Reassign them first.', 'wptransformed' ),
            ] );
        }

        remove_role( $role_slug );

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: role slug */
                __( 'Role "%s" deleted.', 'wptransformed' ),
                $role_slug
            ),
        ] );
    }

    // -- AJAX: Reset Role -------------------------------------------------

    /**
     * Reset a default role to WordPress defaults.
     */
    public function ajax_reset_role(): void {
        check_ajax_referer( 'wpt_role_editor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $role_slug = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

        if ( empty( $role_slug ) ) {
            wp_send_json_error( [ 'message' => __( 'No role specified.', 'wptransformed' ) ] );
        }

        // Only reset WP default roles.
        if ( ! isset( self::WP_DEFAULT_CAPS[ $role_slug ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Can only reset default WordPress roles.', 'wptransformed' ) ] );
        }

        $role = get_role( $role_slug );
        if ( ! $role ) {
            wp_send_json_error( [ 'message' => __( 'Role not found.', 'wptransformed' ) ] );
        }

        // Remove all current caps.
        $current_caps = array_keys( $role->capabilities );
        foreach ( $current_caps as $cap ) {
            $role->remove_cap( $cap );
        }

        // Add default caps.
        foreach ( self::WP_DEFAULT_CAPS[ $role_slug ] as $cap => $grant ) {
            $role->add_cap( $cap, $grant );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: role name */
                __( 'Role "%s" reset to WordPress defaults.', 'wptransformed' ),
                $role_slug
            ),
        ] );
    }

    // -- AJAX: View as Role -----------------------------------------------

    /**
     * Enable "View as Role" by setting a transient.
     */
    public function ajax_view_as_role(): void {
        check_ajax_referer( 'wpt_role_editor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $role_slug = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

        if ( empty( $role_slug ) || ! get_role( $role_slug ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid role.', 'wptransformed' ) ] );
        }

        $user_id = get_current_user_id();
        $transient_key = 'wpt_view_as_role_' . $user_id;

        set_transient( $transient_key, $role_slug, HOUR_IN_SECONDS );

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: role name */
                __( 'Now viewing as "%s". Capabilities will be temporarily overridden.', 'wptransformed' ),
                $role_slug
            ),
        ] );
    }

    // -- AJAX: Stop View as Role ------------------------------------------

    /**
     * Disable "View as Role" by deleting the transient.
     */
    public function ajax_stop_view_as(): void {
        check_ajax_referer( 'wpt_role_editor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user_id = get_current_user_id();
        delete_transient( 'wpt_view_as_role_' . $user_id );

        wp_send_json_success( [
            'message' => __( 'Returned to your normal capabilities.', 'wptransformed' ),
        ] );
    }

    // -- "View as Role" Filter --------------------------------------------

    /**
     * Filter user capabilities to simulate a different role.
     *
     * Always preserves manage_options for the actual admin so they
     * can get back to normal.
     *
     * @param bool[]   $allcaps All capabilities the user has.
     * @param string[] $caps    Required primitive capabilities.
     * @param array    $args    Arguments: [0] = requested cap, [1] = user ID.
     * @param \WP_User $user    The user object.
     * @return bool[]
     */
    public function filter_view_as_role( array $allcaps, array $caps, array $args, \WP_User $user ): array {
        $user_id = $user->ID;
        $transient_key = 'wpt_view_as_role_' . $user_id;
        $view_as = get_transient( $transient_key );

        if ( empty( $view_as ) ) {
            return $allcaps;
        }

        $role = get_role( $view_as );
        if ( ! $role ) {
            return $allcaps;
        }

        // Build the simulated caps from the target role.
        $simulated = [];
        foreach ( $role->capabilities as $cap => $grant ) {
            $simulated[ $cap ] = $grant;
        }

        // Always keep manage_options and exist so the admin can undo.
        $simulated['manage_options'] = true;
        $simulated['exist']          = true;
        $simulated['read']           = true;

        return $simulated;
    }

    // -- Admin Bar: View as Role Indicator ---------------------------------

    /**
     * Add a "Viewing as: {role}" node to the admin bar when active.
     *
     * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
     */
    public function admin_bar_view_as( \WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id   = get_current_user_id();
        $view_as   = get_transient( 'wpt_view_as_role_' . $user_id );

        if ( empty( $view_as ) ) {
            return;
        }

        $roles = wp_roles()->role_names;
        $role_name = isset( $roles[ $view_as ] ) ? translate_user_role( $roles[ $view_as ] ) : $view_as;

        $wp_admin_bar->add_node( [
            'id'    => 'wpt-view-as-role',
            'title' => sprintf(
                /* translators: %s: role display name */
                __( 'Viewing as: %s', 'wptransformed' ),
                esc_html( $role_name )
            ),
            'meta'  => [
                'class' => 'wpt-view-as-active',
            ],
        ] );

        $wp_admin_bar->add_node( [
            'parent' => 'wpt-view-as-role',
            'id'     => 'wpt-stop-view-as',
            'title'  => __( 'Stop Viewing as Role', 'wptransformed' ),
            'href'   => '#',
            'meta'   => [
                'class'   => 'wpt-stop-view-as-link',
                'onclick' => 'return false;',
            ],
        ] );
    }

    // -- Render Settings --------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        $roles    = wp_roles()->roles;
        $all_caps = $this->get_all_capabilities();
        $grouped  = $this->group_capabilities( $all_caps );

        // Current "View as" state.
        $user_id  = get_current_user_id();
        $view_as  = get_transient( 'wpt_view_as_role_' . $user_id );
        ?>

        <!-- Module Settings -->
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Admin Bar Switch', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_admin_bar_switch" value="1"
                               <?php checked( ! empty( $settings['show_admin_bar_switch'] ) ); ?>>
                        <?php esc_html_e( 'Show "Viewing as" indicator in the admin bar when role preview is active', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <hr>

        <!-- View as Role status -->
        <?php if ( $view_as ) : ?>
        <div class="notice notice-warning inline wpt-role-notice" style="margin: 10px 0;">
            <p>
                <strong><?php esc_html_e( 'View as Role Active:', 'wptransformed' ); ?></strong>
                <?php
                $role_names = wp_roles()->role_names;
                $display = isset( $role_names[ $view_as ] ) ? translate_user_role( $role_names[ $view_as ] ) : $view_as;
                printf(
                    /* translators: %s: role display name */
                    esc_html__( 'You are currently viewing the site as "%s".', 'wptransformed' ),
                    esc_html( $display )
                );
                ?>
                <button type="button" class="button button-small wpt-role-stop-view-as" style="margin-left: 10px;">
                    <?php esc_html_e( 'Stop', 'wptransformed' ); ?>
                </button>
                <span class="spinner" style="float: none;"></span>
            </p>
        </div>
        <?php endif; ?>

        <!-- Add New Role -->
        <h3><?php esc_html_e( 'Add New Role', 'wptransformed' ); ?></h3>
        <div class="wpt-role-add-form">
            <label for="wpt-role-new-slug"><?php esc_html_e( 'Slug:', 'wptransformed' ); ?></label>
            <input type="text" id="wpt-role-new-slug" class="regular-text"
                   placeholder="<?php esc_attr_e( 'custom_role', 'wptransformed' ); ?>"
                   pattern="[a-z0-9_]+" style="max-width: 200px;">

            <label for="wpt-role-new-name"><?php esc_html_e( 'Display Name:', 'wptransformed' ); ?></label>
            <input type="text" id="wpt-role-new-name" class="regular-text"
                   placeholder="<?php esc_attr_e( 'Custom Role', 'wptransformed' ); ?>"
                   style="max-width: 200px;">

            <label for="wpt-role-clone-from"><?php esc_html_e( 'Clone from:', 'wptransformed' ); ?></label>
            <select id="wpt-role-clone-from">
                <option value=""><?php esc_html_e( '-- None --', 'wptransformed' ); ?></option>
                <?php foreach ( $roles as $slug => $role ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="button button-secondary" id="wpt-role-add-btn">
                <?php esc_html_e( 'Add Role', 'wptransformed' ); ?>
            </button>
            <span class="spinner" id="wpt-role-add-spinner" style="float: none;"></span>
        </div>

        <hr>

        <!-- Role Selector -->
        <h3><?php esc_html_e( 'Edit Role Capabilities', 'wptransformed' ); ?></h3>
        <div class="wpt-role-editor-toolbar">
            <label for="wpt-role-select"><?php esc_html_e( 'Select Role:', 'wptransformed' ); ?></label>
            <select id="wpt-role-select">
                <?php foreach ( $roles as $slug => $role ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>"
                        data-is-default="<?php echo in_array( $slug, self::DEFAULT_ROLES, true ) ? '1' : '0'; ?>"
                        data-caps="<?php echo esc_attr( wp_json_encode( $role['capabilities'] ) ); ?>">
                    <?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
                    <?php if ( ! in_array( $slug, self::DEFAULT_ROLES, true ) ) : ?>
                        <?php esc_html_e( '(custom)', 'wptransformed' ); ?>
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="button button-secondary wpt-role-view-as" style="margin-left: 10px;">
                <?php esc_html_e( 'View as This Role', 'wptransformed' ); ?>
            </button>
            <button type="button" class="button button-secondary wpt-role-reset-btn" style="margin-left: 4px;">
                <?php esc_html_e( 'Reset to Default', 'wptransformed' ); ?>
            </button>
            <button type="button" class="button button-link-delete wpt-role-delete-btn" style="margin-left: 4px;">
                <?php esc_html_e( 'Delete Role', 'wptransformed' ); ?>
            </button>
            <span class="spinner wpt-role-toolbar-spinner" style="float: none;"></span>
        </div>

        <!-- Capability Groups -->
        <div class="wpt-role-caps-editor" style="margin-top: 15px;">
            <?php foreach ( $grouped as $group_name => $group_caps ) : ?>
            <div class="wpt-cap-group">
                <h4 class="wpt-cap-group-title"><?php echo esc_html( $group_name ); ?>
                    <span class="wpt-cap-group-count">(<?php echo esc_html( (string) count( $group_caps ) ); ?>)</span>
                </h4>
                <div class="wpt-cap-group-items">
                    <?php foreach ( $group_caps as $cap ) : ?>
                    <label class="wpt-cap-item">
                        <input type="checkbox" class="wpt-cap-checkbox"
                               data-cap="<?php echo esc_attr( $cap ); ?>">
                        <code><?php echo esc_html( $cap ); ?></code>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="wpt-role-save-bar" style="margin-top: 15px;">
            <button type="button" class="button button-primary" id="wpt-role-save-caps">
                <?php esc_html_e( 'Save Capabilities', 'wptransformed' ); ?>
            </button>
            <span class="spinner" id="wpt-role-save-spinner" style="float: none;"></span>
        </div>

        <?php
    }

    // -- Sanitize Settings ------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        return [
            'show_admin_bar_switch' => ! empty( $raw['wpt_show_admin_bar_switch'] ),
        ];
    }

    // -- Assets -----------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-user-role-editor',
            WPT_URL . 'modules/security/css/user-role-editor.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-user-role-editor',
            WPT_URL . 'modules/security/js/user-role-editor.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-user-role-editor', 'wptRoleEditor', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_role_editor_nonce' ),
            'i18n'    => [
                'confirmDelete'  => __( 'Are you sure you want to delete this role? This cannot be undone.', 'wptransformed' ),
                'confirmReset'   => __( 'Reset this role to WordPress defaults? All customizations will be lost.', 'wptransformed' ),
                'confirmViewAs'  => __( 'This will temporarily change your capabilities to match the selected role. You can stop at any time.', 'wptransformed' ),
                'saving'         => __( 'Saving...', 'wptransformed' ),
                'deleting'       => __( 'Deleting...', 'wptransformed' ),
                'emptySlug'      => __( 'Please enter a role slug.', 'wptransformed' ),
                'emptyName'      => __( 'Please enter a display name.', 'wptransformed' ),
                'networkError'   => __( 'Network error. Please try again.', 'wptransformed' ),
            ],
        ] );
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            'transients' => [
                'description' => __( 'View-as-role transients (wpt_view_as_role_*) in options table.', 'wptransformed' ),
                'type'        => 'transient',
                'prefix'      => 'wpt_view_as_role_',
            ],
        ];
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Get all capabilities from all roles (deduplicated).
     *
     * @return string[] Sorted list of capability names.
     */
    private function get_all_capabilities(): array {
        $all_caps = [];
        $roles    = wp_roles()->roles;

        foreach ( $roles as $role ) {
            if ( is_array( $role['capabilities'] ) ) {
                foreach ( $role['capabilities'] as $cap => $grant ) {
                    $all_caps[ $cap ] = true;
                }
            }
        }

        $caps = array_keys( $all_caps );
        sort( $caps );
        return $caps;
    }

    /**
     * Group capabilities by category.
     *
     * @param string[] $capabilities All capability names.
     * @return array<string, string[]> Grouped capabilities.
     */
    private function group_capabilities( array $capabilities ): array {
        $grouped = [];
        $assigned = [];

        // Build a flat lookup of all known caps.
        foreach ( self::CAP_GROUPS as $group => $caps_list ) {
            $grouped[ $group ] = [];
            foreach ( $caps_list as $cap ) {
                $assigned[ $cap ] = $group;
            }
        }

        $grouped['Other'] = [];

        foreach ( $capabilities as $cap ) {
            if ( isset( $assigned[ $cap ] ) ) {
                $grouped[ $assigned[ $cap ] ][] = $cap;
            } else {
                $grouped['Other'][] = $cap;
            }
        }

        // Remove empty groups.
        return array_filter( $grouped, static function ( $caps ) {
            return ! empty( $caps );
        } );
    }

    /**
     * Check if administrator is the only role with manage_options.
     *
     * @return bool True if removing manage_options from admin would lock out.
     */
    private function is_last_admin_role(): bool {
        $roles = wp_roles()->roles;
        $admin_roles = 0;

        foreach ( $roles as $slug => $role ) {
            if ( $slug === 'administrator' ) {
                continue;
            }
            if ( ! empty( $role['capabilities']['manage_options'] ) ) {
                $admin_roles++;
            }
        }

        return $admin_roles === 0;
    }
}
