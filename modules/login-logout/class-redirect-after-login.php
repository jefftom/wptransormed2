<?php
declare(strict_types=1);

namespace WPTransformed\Modules\LoginLogout;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Redirect After Login — Role-based login and logout redirects.
 *
 * Features:
 *  - Per-role redirect URL after successful login
 *  - Configurable default redirect for unlisted roles
 *  - Logout redirect URL
 *  - Respects explicit redirect_to parameter (e.g., from protected pages)
 *  - Uses wp_safe_redirect() for all redirects
 *
 * @package WPTransformed
 */
class Redirect_After_Login extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'redirect-after-login';
    }

    public function get_title(): string {
        return __( 'Redirect After Login', 'wptransformed' );
    }

    public function get_category(): string {
        return 'login-logout';
    }

    public function get_description(): string {
        return __( 'Redirect users to different URLs after login or logout based on their role.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'redirects' => [
                'administrator' => '/wp-admin/',
                'editor'        => '/wp-admin/edit.php',
                'subscriber'    => '/',
                'default'       => '/',
            ],
            'logout_redirect' => '/',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_filter( 'login_redirect', [ $this, 'handle_login_redirect' ], 99, 3 );
        add_action( 'wp_logout', [ $this, 'handle_logout_redirect' ] );
    }

    // ── Login Redirect ────────────────────────────────────────

    /**
     * Filter the login redirect URL based on user role.
     *
     * Does NOT override an explicit redirect_to param that differs from
     * the default admin URL — this means protected-page redirects still work.
     *
     * @param  string   $redirect_to           Requested redirect URL.
     * @param  string   $requested_redirect_to Originally requested redirect URL.
     * @param  \WP_User|\WP_Error $user        User object or error.
     * @return string
     */
    public function handle_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
        if ( ! $user instanceof \WP_User ) {
            return $redirect_to;
        }

        // Respect explicit redirect_to from protected pages or other plugins.
        if ( ! empty( $requested_redirect_to ) && admin_url() !== $requested_redirect_to ) {
            return $redirect_to;
        }

        $settings  = $this->get_settings();
        $redirects = $settings['redirects'] ?? [];

        foreach ( $user->roles as $role ) {
            if ( ! empty( $redirects[ $role ] ) ) {
                return home_url( $redirects[ $role ] );
            }
        }

        if ( ! empty( $redirects['default'] ) ) {
            return home_url( $redirects['default'] );
        }

        return $redirect_to;
    }

    // ── Logout Redirect ───────────────────────────────────────

    /**
     * Redirect to configured URL after logout.
     */
    public function handle_logout_redirect(): void {
        $settings = $this->get_settings();
        $url      = $settings['logout_redirect'] ?? '/';

        if ( empty( $url ) ) {
            return;
        }

        wp_safe_redirect( home_url( $url ) );
        exit;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings  = $this->get_settings();
        $redirects = $settings['redirects'] ?? [];

        // Get all editable roles.
        $roles = wp_roles()->get_names();
        ?>
        <h3><?php esc_html_e( 'Login Redirects by Role', 'wptransformed' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Set the URL path each role should be redirected to after login. Leave empty to use the default.', 'wptransformed' ); ?>
        </p>
        <table class="form-table" role="presentation">
            <?php foreach ( $roles as $role_slug => $role_name ) : ?>
            <tr>
                <th scope="row">
                    <label for="wpt-redirect-<?php echo esc_attr( $role_slug ); ?>">
                        <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                        id="wpt-redirect-<?php echo esc_attr( $role_slug ); ?>"
                        name="redirects[<?php echo esc_attr( $role_slug ); ?>]"
                        value="<?php echo esc_attr( $redirects[ $role_slug ] ?? '' ); ?>"
                        class="regular-text"
                        placeholder="/">
                </td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row">
                    <label for="wpt-redirect-default">
                        <?php esc_html_e( 'Default (all other roles)', 'wptransformed' ); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                        id="wpt-redirect-default"
                        name="redirects[default]"
                        value="<?php echo esc_attr( $redirects['default'] ?? '/' ); ?>"
                        class="regular-text"
                        placeholder="/">
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Logout Redirect', 'wptransformed' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-logout-redirect">
                        <?php esc_html_e( 'Redirect URL', 'wptransformed' ); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                        id="wpt-logout-redirect"
                        name="logout_redirect"
                        value="<?php echo esc_attr( $settings['logout_redirect'] ?? '/' ); ?>"
                        class="regular-text"
                        placeholder="/">
                    <p class="description">
                        <?php esc_html_e( 'Where to send users after they log out. Relative to site URL.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $redirects = [];

        if ( ! empty( $raw['redirects'] ) && is_array( $raw['redirects'] ) ) {
            foreach ( $raw['redirects'] as $role => $url ) {
                $role = sanitize_key( $role );
                $url  = sanitize_text_field( wp_unslash( $url ) );
                if ( '' !== $url ) {
                    $redirects[ $role ] = '/' . ltrim( $url, '/' );
                }
            }
        }

        $logout = sanitize_text_field( wp_unslash( $raw['logout_redirect'] ?? '/' ) );
        if ( '' !== $logout ) {
            $logout = '/' . ltrim( $logout, '/' );
        } else {
            $logout = '/';
        }

        return [
            'redirects'       => $redirects,
            'logout_redirect' => $logout,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
