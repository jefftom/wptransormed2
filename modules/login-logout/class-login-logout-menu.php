<?php
declare(strict_types=1);

namespace WPTransformed\Modules\LoginLogout;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Login Logout Menu — Append login/logout/register links to navigation menus.
 *
 * Hooks into `wp_nav_menu_items` to add contextual authentication links
 * at the end of every nav menu. Logged-out visitors see Login (and optionally
 * Register); logged-in users see Logout.
 *
 * @package WPTransformed
 */
class Login_Logout_Menu extends Module_Base {

    // -- Identity ----------------------------------------------------------

    public function get_id(): string {
        return 'login-logout-menu';
    }

    public function get_title(): string {
        return __( 'Login Logout Menu', 'wptransformed' );
    }

    public function get_category(): string {
        return 'login-logout';
    }

    public function get_description(): string {
        return __( 'Append login, logout, and register links to your navigation menus.', 'wptransformed' );
    }

    // -- Settings ----------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'show_login'    => true,
            'show_logout'   => true,
            'show_register' => true,
            'login_text'    => 'Log In',
            'logout_text'   => 'Log Out',
        ];
    }

    // -- Lifecycle ---------------------------------------------------------

    public function init(): void {
        add_filter( 'wp_nav_menu_items', [ $this, 'append_menu_items' ], 10, 2 );
    }

    // -- Menu Filter -------------------------------------------------------

    /**
     * Append login/logout/register items to navigation menus.
     *
     * @param string   $items Existing menu HTML.
     * @param \stdClass $args  Menu arguments.
     * @return string
     */
    public function append_menu_items( string $items, \stdClass $args ): string {
        $s = $this->get_settings();

        if ( is_user_logged_in() ) {
            if ( ! empty( $s['show_logout'] ) ) {
                $logout_text = ! empty( $s['logout_text'] ) ? $s['logout_text'] : 'Log Out';
                $items .= '<li class="menu-item wpt-logout-link"><a href="'
                    . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">'
                    . esc_html( $logout_text ) . '</a></li>';
            }
        } else {
            if ( ! empty( $s['show_login'] ) ) {
                $login_text = ! empty( $s['login_text'] ) ? $s['login_text'] : 'Log In';
                $items .= '<li class="menu-item wpt-login-link"><a href="'
                    . esc_url( wp_login_url( home_url( '/' ) ) ) . '">'
                    . esc_html( $login_text ) . '</a></li>';
            }

            if ( ! empty( $s['show_register'] ) && get_option( 'users_can_register' ) ) {
                $items .= '<li class="menu-item wpt-register-link"><a href="'
                    . esc_url( wp_registration_url() ) . '">'
                    . esc_html__( 'Register', 'wptransformed' ) . '</a></li>';
            }
        }

        return $items;
    }

    // -- Settings UI -------------------------------------------------------

    public function render_settings(): void {
        $s = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Visible links', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_show_login" value="1"
                                   <?php checked( ! empty( $s['show_login'] ) ); ?>>
                            <?php esc_html_e( 'Show Login link (for logged-out visitors)', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_show_logout" value="1"
                                   <?php checked( ! empty( $s['show_logout'] ) ); ?>>
                            <?php esc_html_e( 'Show Logout link (for logged-in users)', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="wpt_show_register" value="1"
                                   <?php checked( ! empty( $s['show_register'] ) ); ?>>
                            <?php esc_html_e( 'Show Register link (only if registration is enabled in Settings)', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Link text', 'wptransformed' ); ?></th>
                <td>
                    <label style="display: block; margin-bottom: 8px;">
                        <?php esc_html_e( 'Login text:', 'wptransformed' ); ?>
                        <input type="text" name="wpt_login_text"
                               value="<?php echo esc_attr( $s['login_text'] ); ?>"
                               class="regular-text" placeholder="Log In">
                    </label>
                    <label style="display: block; margin-bottom: 4px;">
                        <?php esc_html_e( 'Logout text:', 'wptransformed' ); ?>
                        <input type="text" name="wpt_logout_text"
                               value="<?php echo esc_attr( $s['logout_text'] ); ?>"
                               class="regular-text" placeholder="Log Out">
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize ----------------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        return [
            'show_login'    => ! empty( $raw['wpt_show_login'] ),
            'show_logout'   => ! empty( $raw['wpt_show_logout'] ),
            'show_register' => ! empty( $raw['wpt_show_register'] ),
            'login_text'    => sanitize_text_field( $raw['wpt_login_text'] ?? 'Log In' ),
            'logout_text'   => sanitize_text_field( $raw['wpt_logout_text'] ?? 'Log Out' ),
        ];
    }

    // -- Cleanup -----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => 'wpt_settings_login-logout-menu' ],
        ];
    }
}
