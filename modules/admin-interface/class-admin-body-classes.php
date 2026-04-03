<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Body Classes -- Append user role and/or username to the admin body class.
 *
 * Filters admin_body_class to add role-{slug} and/or user-{username} classes,
 * enabling role-specific or user-specific CSS targeting in the admin area.
 *
 * @package WPTransformed
 */
class Admin_Body_Classes extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'admin-body-classes';
    }

    public function get_title(): string {
        return __( 'Admin Body Classes', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add user role and/or username CSS classes to the admin body tag for targeted styling.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'add_role'     => true,
            'add_username' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_filter( 'admin_body_class', [ $this, 'append_body_classes' ] );
    }

    // -- Hook Callback ----------------------------------------------------

    /**
     * Append role and/or username classes to the admin body class string.
     *
     * @param string $classes Existing body classes (space-separated).
     * @return string Modified body classes.
     */
    public function append_body_classes( string $classes ): string {
        $user = wp_get_current_user();
        if ( ! $user->exists() ) {
            return $classes;
        }

        $settings = $this->get_settings();
        $extra    = [];

        if ( ! empty( $settings['add_role'] ) ) {
            foreach ( $user->roles as $role ) {
                $extra[] = 'role-' . sanitize_html_class( $role );
            }
        }

        if ( ! empty( $settings['add_username'] ) ) {
            $extra[] = 'user-' . sanitize_html_class( $user->user_login );
        }

        if ( ! empty( $extra ) ) {
            $classes .= ' ' . implode( ' ', $extra );
        }

        return $classes;
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Body Classes', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox"
                                   name="wpt_add_role"
                                   value="1"
                                   <?php checked( $settings['add_role'] ); ?>>
                            <?php esc_html_e( 'Add user role classes (e.g. role-administrator, role-editor)', 'wptransformed' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox"
                                   name="wpt_add_username"
                                   value="1"
                                   <?php checked( $settings['add_username'] ); ?>>
                            <?php esc_html_e( 'Add username class (e.g. user-johndoe)', 'wptransformed' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'These classes are added to the admin body tag, allowing role- or user-specific CSS targeting.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'add_role'     => ! empty( $raw['wpt_add_role'] ),
            'add_username' => ! empty( $raw['wpt_add_username'] ),
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
