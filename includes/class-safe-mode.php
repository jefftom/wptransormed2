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
     * In safe mode:
     * - No modules are loaded at all
     * - Settings page still renders (so you can toggle modules off)
     * - A prominent banner warns that safe mode is active
     */
    public static function is_active(): bool {
        if ( ! is_admin() ) return false;
        if ( ! isset( $_GET['wpt_safe_mode'] ) ) return false;

        $provided = sanitize_text_field( $_GET['wpt_safe_mode'] );
        $stored   = get_option( self::TOKEN_OPTION, '' );

        // Token must exist and match
        if ( empty( $stored ) || ! hash_equals( $stored, $provided ) ) {
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
            <div class="notice notice-warning" style="border-left-color: #d63638; background: #fef1f1;">
                <p>
                    <strong><?php esc_html_e( 'WPTransformed Safe Mode Active', 'wptransformed' ); ?></strong><br>
                    <?php esc_html_e( 'All modules are disabled. Go to Settings → WPTransformed to disable any problematic modules, then remove ?wpt_safe_mode from the URL to exit safe mode.', 'wptransformed' ); ?>
                </p>
            </div>
            <?php
        } );
    }
}
