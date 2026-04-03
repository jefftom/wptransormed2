<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Hide Admin Bar -- Selectively hide the WordPress admin bar by role.
 *
 * Controls visibility of the admin bar on the frontend (via show_admin_bar filter)
 * and optionally in the backend (via CSS). Admins can be exempt from hiding.
 *
 * @package WPTransformed
 */
class Hide_Admin_Bar extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'hide-admin-bar';
    }

    public function get_title(): string {
        return __( 'Hide Admin Bar', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Selectively hide the WordPress admin bar on the frontend or backend by user role.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'hide_frontend'       => [ 'subscriber', 'contributor' ],
            'hide_backend'        => [],
            'admin_always_visible' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        // Frontend: filter show_admin_bar.
        add_filter( 'show_admin_bar', [ $this, 'maybe_hide_frontend_bar' ] );

        // Backend: inject CSS to hide the admin bar.
        add_action( 'admin_head', [ $this, 'maybe_hide_backend_bar' ] );
    }

    // -- Hook Callbacks ---------------------------------------------------

    /**
     * Hide the admin bar on the frontend for configured roles.
     *
     * @param bool $show Whether to show the admin bar.
     * @return bool
     */
    public function maybe_hide_frontend_bar( bool $show ): bool {
        if ( $this->should_hide_for_current_user( 'hide_frontend' ) ) {
            return false;
        }

        return $show;
    }

    /**
     * Hide the admin bar in the backend via CSS for configured roles.
     */
    public function maybe_hide_backend_bar(): void {
        if ( ! $this->should_hide_for_current_user( 'hide_backend' ) ) {
            return;
        }

        echo '<style>#wpadminbar { display: none !important; } html.wp-toolbar { padding-top: 0 !important; }</style>' . "\n";
    }

    /**
     * Check whether the admin bar should be hidden for the current user.
     *
     * @param string $setting_key Either 'hide_frontend' or 'hide_backend'.
     * @return bool True if the bar should be hidden.
     */
    private function should_hide_for_current_user( string $setting_key ): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $settings = $this->get_settings();

        if ( ! empty( $settings['admin_always_visible'] ) && current_user_can( 'manage_options' ) ) {
            return false;
        }

        $hide_roles = (array) $settings[ $setting_key ];
        if ( empty( $hide_roles ) ) {
            return false;
        }

        $user = wp_get_current_user();
        return (bool) array_intersect( $user->roles, $hide_roles );
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();

        // Get all editable roles.
        $roles = wp_roles()->get_names();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Hide on Frontend', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $roles as $role_slug => $role_name ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_hide_frontend[]"
                                       value="<?php echo esc_attr( $role_slug ); ?>"
                                       <?php checked( in_array( $role_slug, (array) $settings['hide_frontend'], true ) ); ?>>
                                <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Hide the admin bar on the frontend for these roles.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Hide in Backend', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $roles as $role_slug => $role_name ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_hide_backend[]"
                                       value="<?php echo esc_attr( $role_slug ); ?>"
                                       <?php checked( in_array( $role_slug, (array) $settings['hide_backend'], true ) ); ?>>
                                <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Hide the admin bar in the WordPress dashboard for these roles.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Admin Override', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_admin_always_visible"
                               value="1"
                               <?php checked( $settings['admin_always_visible'] ); ?>>
                        <?php esc_html_e( 'Administrators always see the admin bar regardless of role settings', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $valid_roles = array_keys( wp_roles()->get_names() );

        $hide_frontend = isset( $raw['wpt_hide_frontend'] ) && is_array( $raw['wpt_hide_frontend'] )
            ? array_values( array_intersect( array_map( 'sanitize_key', $raw['wpt_hide_frontend'] ), $valid_roles ) )
            : [];

        $hide_backend = isset( $raw['wpt_hide_backend'] ) && is_array( $raw['wpt_hide_backend'] )
            ? array_values( array_intersect( array_map( 'sanitize_key', $raw['wpt_hide_backend'] ), $valid_roles ) )
            : [];

        return [
            'hide_frontend'        => $hide_frontend,
            'hide_backend'         => $hide_backend,
            'admin_always_visible' => ! empty( $raw['wpt_admin_always_visible'] ),
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
