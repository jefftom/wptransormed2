<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Safe Mode — Error Recovery.
 *
 * @package WPTransformed
 */
class Safe_Mode {

    /** Option key for the safe mode secret token. */
    const TOKEN_OPTION = 'wpt_safe_mode_token';

    /**
     * Check if safe mode is requested and valid.
     * Called BEFORE the module loader runs.
     *
     * Safe mode URL: /wp-admin/?wpt_safe_mode={token}
     *
     * Activation requires BOTH the valid token AND a logged-in
     * administrator-capable user (decision 2026-06-09): manage_wpt is the
     * primary gate; manage_options is the emergency fallback for installs
     * where WPT capabilities were never granted (spec §11).
     *
     * In safe mode:
     * - No modules are loaded at all
     * - No admin chrome loads (global CSS/JS, section labels, body
     *   classes, topbar space) — native WordPress admin renders
     * - A plain banner notice warns that safe mode is active
     */
    public static function is_active(): bool {
        // Param check first: keeps the common path cheap and avoids
        // forcing early user determination on every admin request.
        if ( ! is_admin() ) return false;
        if ( ! isset( $_GET['wpt_safe_mode'] ) ) return false;

        $provided = sanitize_text_field( wp_unslash( $_GET['wpt_safe_mode'] ) );
        $stored   = get_option( self::TOKEN_OPTION, '' );

        // Token must exist and match
        if ( empty( $stored ) || ! hash_equals( $stored, $provided ) ) {
            return false;
        }

        // A bare token is not sufficient.
        if ( ! is_user_logged_in() ) return false;
        if ( ! current_user_can( Permission_Manager::CAP_MANAGE )
            && ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Generate and store a safe mode token.
     * Called on plugin activation and can be regenerated from WP-CLI.
     */
    public static function generate_token(): string {
        $token = wp_generate_password( 32, false, false );
        update_option( self::TOKEN_OPTION, $token, false ); // no autoload
        return $token;
    }

    /**
     * Get the current safe mode URL.
     * Displayed on the settings page so the admin can bookmark it.
     */
    public static function get_safe_mode_url(): string {
        $token = get_option( self::TOKEN_OPTION, '' );
        if ( empty( $token ) ) {
            $token = self::generate_token();
        }
        return add_query_arg( 'wpt_safe_mode', $token, admin_url() );
    }

    /**
     * Show the safe mode admin banner.
     */
    public static function render_banner(): void {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e( 'WPTransformed Safe Mode Active', 'wptransformed' ); ?></strong><br>
                    <?php esc_html_e( 'All WPTransformed modules and admin styling are disabled for this request, so the native WordPress admin can be used for recovery. Safe Mode applies only to URLs containing the ?wpt_safe_mode token — remove it to exit.', 'wptransformed' ); ?>
                </p>
            </div>
            <?php
        } );
    }
}
