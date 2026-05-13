<?php
declare(strict_types=1);

namespace WPTransformed\Modules\LoginLogout;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Login ID Type — Restrict login identification to email-only or username-only.
 *
 * By default WordPress accepts both email addresses and usernames.
 * This module lets admins enforce one or the other. Useful for:
 *  - email_only: prevents username enumeration attacks
 *  - username_only: sites that don't expose user emails
 *
 * Skips enforcement for WP-CLI, XML-RPC, and REST API authentication
 * to avoid breaking automated workflows.
 *
 * @package WPTransformed
 */
class Login_Id_Type extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'login-id-type';
    }

    public function get_title(): string {
        return __( 'Login ID Type', 'wptransformed' );
    }

    public function get_category(): string {
        return 'login-logout';
    }

    public function get_description(): string {
        return __( 'Restrict login to email-only or username-only instead of both.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'login_type' => 'email_only',  // 'both', 'email_only', 'username_only'.
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    /** @var string Cached login type to avoid repeated settings lookups in gettext filter. */
    private string $login_type = '';

    public function init(): void {
        $settings         = $this->get_settings();
        $this->login_type = $settings['login_type'];

        if ( $this->login_type === 'both' ) {
            return;
        }

        // Priority 30: validate input type before core's wp_authenticate_username_password (priority 20).
        add_filter( 'authenticate', [ $this, 'enforce_login_type' ], 30, 3 );

        add_filter( 'gettext', [ $this, 'filter_login_label' ], 10, 3 );
    }

    // ── Authentication Filter ─────────────────────────────────

    /**
     * Enforce the configured login type by rejecting disallowed inputs.
     *
     * Runs at priority 30 on the 'authenticate' filter. If a previous filter
     * already returned a WP_User (e.g. SSO plugin), we let it through.
     *
     * @param \WP_User|\WP_Error|null $user     User object, error, or null.
     * @param string                  $username  Submitted username/email.
     * @param string                  $password  Submitted password.
     * @return \WP_User|\WP_Error|null
     */
    public function enforce_login_type( $user, string $username, string $password ) {
        // Skip if already authenticated by a prior filter (SSO, etc.).
        if ( $user instanceof \WP_User ) {
            return $user;
        }

        // Skip if no input (empty form submission is handled by core).
        if ( empty( $username ) ) {
            return $user;
        }

        // Skip enforcement for non-interactive contexts.
        if ( $this->is_non_interactive_context() ) {
            return $user;
        }

        $is_email = is_email( $username );

        if ( $this->login_type === 'email_only' && ! $is_email ) {
            return new \WP_Error(
                'wpt_email_required',
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__( 'Error:', 'wptransformed' ),
                    esc_html__( 'Please enter your email address to log in.', 'wptransformed' )
                )
            );
        }

        if ( $this->login_type === 'username_only' && $is_email ) {
            return new \WP_Error(
                'wpt_username_required',
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__( 'Error:', 'wptransformed' ),
                    esc_html__( 'Please enter your username to log in.', 'wptransformed' )
                )
            );
        }

        return $user;
    }

    // ── Label Filter ──────────────────────────────────────────

    /**
     * Change the "Username or Email Address" label on the login form.
     *
     * @param string $translated Translated text.
     * @param string $original   Original text.
     * @param string $domain     Text domain.
     * @return string
     */
    public function filter_login_label( string $translated, string $original, string $domain ): string {
        if ( $original !== 'Username or Email Address' || $domain !== 'default' ) {
            return $translated;
        }

        if ( $this->login_type === 'email_only' ) {
            return __( 'Email Address', 'wptransformed' );
        }

        if ( $this->login_type === 'username_only' ) {
            return __( 'Username', 'wptransformed' );
        }

        return $translated;
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the current request is a non-interactive context
     * (WP-CLI, XML-RPC, REST API) where login-type enforcement
     * should be skipped.
     *
     * @return bool
     */
    private function is_non_interactive_context(): bool {
        return ( defined( 'WP_CLI' ) && WP_CLI )
            || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings   = $this->get_settings();
        $login_type = $settings['login_type'];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Login identifier', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_login_type" value="both"
                                   <?php checked( $login_type, 'both' ); ?>>
                            <strong><?php esc_html_e( 'Username or Email (WordPress default)', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Users can log in with either their username or email address.', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_login_type" value="email_only"
                                   <?php checked( $login_type, 'email_only' ); ?>>
                            <strong><?php esc_html_e( 'Email address only', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Users must enter their email address. Helps prevent username enumeration.', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="radio" name="wpt_login_type" value="username_only"
                                   <?php checked( $login_type, 'username_only' ); ?>>
                            <strong><?php esc_html_e( 'Username only', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Users must enter their username. Useful if email addresses should not be used as login identifiers.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <div style="background: #fff; border: 1px solid #ddd; border-left: 4px solid #2271b1; border-radius: 4px; padding: 12px 16px; margin-top: 12px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'This restriction applies to the standard WordPress login form only. WP-CLI, XML-RPC, and REST API authentication are not affected.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $allowed    = [ 'both', 'email_only', 'username_only' ];
        $login_type = $raw['wpt_login_type'] ?? 'email_only';

        if ( ! in_array( $login_type, $allowed, true ) ) {
            $login_type = 'email_only';
        }

        return [
            'login_type' => $login_type,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom CSS or JS needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
