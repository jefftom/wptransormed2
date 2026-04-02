<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;
use WPTransformed\Core\Settings;

/**
 * White Label — Rebrand the WordPress admin with custom logos, text, and branding.
 *
 * Features:
 *  - Custom admin bar logo
 *  - Custom admin footer text
 *  - Custom login page logo (skipped if Login Customizer module is active)
 *  - Hide WordPress version
 *  - Custom admin page title prefix
 *  - Remove wp_generator meta tag
 *
 * @package WPTransformed
 */
class White_Label extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'white-label';
    }

    public function get_title(): string {
        return __( 'White Label', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Rebrand the WordPress admin with custom logos, footer text, and branding.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'admin_logo_url'     => '',
            'admin_footer_text'  => 'Powered by Your Agency',
            'login_logo_url'     => '',
            'hide_wp_version'    => true,
            'custom_admin_title' => '',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        // Custom admin footer text.
        $footer_text = trim( $settings['admin_footer_text'] ?? '' );
        if ( ! empty( $footer_text ) ) {
            add_filter( 'admin_footer_text', [ $this, 'custom_footer_text' ] );
        }

        // Hide WP version / custom version text.
        if ( ! empty( $settings['hide_wp_version'] ) ) {
            add_filter( 'update_footer', [ $this, 'custom_version_text' ], 999 );
            remove_action( 'wp_head', 'wp_generator' );
        }

        // Custom admin bar logo CSS.
        $admin_logo = trim( $settings['admin_logo_url'] ?? '' );
        if ( ! empty( $admin_logo ) ) {
            add_action( 'admin_head', [ $this, 'admin_logo_css' ] );
            add_action( 'wp_head', [ $this, 'admin_logo_css' ] );
        }

        // Custom admin title.
        $custom_title = trim( $settings['custom_admin_title'] ?? '' );
        if ( ! empty( $custom_title ) ) {
            add_filter( 'admin_title', [ $this, 'custom_admin_title' ], 10, 2 );
        }

        // Custom login logo — only if Login Customizer is NOT active.
        $login_logo = trim( $settings['login_logo_url'] ?? '' );
        if ( ! empty( $login_logo ) && ! $this->is_login_customizer_active() ) {
            add_action( 'login_head', [ $this, 'login_logo_css' ] );
        }
    }

    // ── Footer ────────────────────────────────────────────────

    /**
     * Replace the admin footer text.
     *
     * @return string Custom footer text.
     */
    public function custom_footer_text(): string {
        $settings = $this->get_settings();
        $text     = $settings['admin_footer_text'] ?? '';

        return wp_kses_post( $text );
    }

    /**
     * Replace or hide the WordPress version in the footer.
     *
     * @return string Empty string to hide version.
     */
    public function custom_version_text(): string {
        return '';
    }

    // ── Admin Bar Logo ────────────────────────────────────────

    /**
     * Output CSS to replace the admin bar WordPress logo with a custom image.
     */
    public function admin_logo_css(): void {
        if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
            return;
        }

        $settings = $this->get_settings();
        $logo_url = $settings['admin_logo_url'] ?? '';

        if ( empty( $logo_url ) ) {
            return;
        }

        ?>
        <style id="wpt-white-label-admin-logo">
        #wpadminbar #wp-admin-bar-wp-logo > .ab-item .ab-icon:before {
            content: '';
            background: url('<?php echo esc_url( $logo_url ); ?>') no-repeat center center;
            background-size: contain;
            width: 20px;
            height: 20px;
            display: inline-block;
            top: 2px;
        }
        </style>
        <?php
    }

    // ── Admin Title ───────────────────────────────────────────

    /**
     * Prepend custom title to admin page titles.
     *
     * @param string $admin_title Full admin title.
     * @param string $title       Page title.
     * @return string Modified admin title.
     */
    public function custom_admin_title( string $admin_title, string $title ): string {
        $settings     = $this->get_settings();
        $custom_title = sanitize_text_field( $settings['custom_admin_title'] ?? '' );

        if ( empty( $custom_title ) ) {
            return $admin_title;
        }

        // Replace "WordPress" with custom title or prepend.
        if ( strpos( $admin_title, 'WordPress' ) !== false ) {
            return str_replace( 'WordPress', $custom_title, $admin_title );
        }

        return $title . ' &lsaquo; ' . $custom_title;
    }

    // ── Login Logo ────────────────────────────────────────────

    /**
     * Output CSS to replace the login page WordPress logo with a custom image.
     */
    public function login_logo_css(): void {
        $settings = $this->get_settings();
        $logo_url = $settings['login_logo_url'] ?? '';

        if ( empty( $logo_url ) ) {
            return;
        }

        ?>
        <style id="wpt-white-label-login-logo">
        #login h1 a,
        .login h1 a {
            background-image: url('<?php echo esc_url( $logo_url ); ?>');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center center;
            width: 320px;
            height: 80px;
        }
        </style>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $login_customizer_active = $this->is_login_customizer_active();

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_admin_logo_url"><?php esc_html_e( 'Admin bar logo URL', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="url" id="wpt_admin_logo_url" name="wpt_admin_logo_url"
                           value="<?php echo esc_attr( $settings['admin_logo_url'] ); ?>"
                           class="regular-text" placeholder="https://example.com/logo.png">
                    <p class="description">
                        <?php esc_html_e( 'URL to an image that will replace the WordPress logo in the admin bar. Recommended size: 20x20px.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_admin_footer_text"><?php esc_html_e( 'Admin footer text', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt_admin_footer_text" name="wpt_admin_footer_text"
                           value="<?php echo esc_attr( $settings['admin_footer_text'] ); ?>"
                           class="regular-text" placeholder="Powered by Your Agency">
                    <p class="description">
                        <?php esc_html_e( 'Custom text for the admin footer. Basic HTML allowed.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_login_logo_url"><?php esc_html_e( 'Login page logo URL', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="url" id="wpt_login_logo_url" name="wpt_login_logo_url"
                           value="<?php echo esc_attr( $settings['login_logo_url'] ); ?>"
                           class="regular-text" placeholder="https://example.com/login-logo.png"
                           <?php echo $login_customizer_active ? 'disabled' : ''; ?>>
                    <?php if ( $login_customizer_active ) : ?>
                        <p class="description" style="color: #d63638;">
                            <?php esc_html_e( 'Login Customizer module is active. Login logo is managed there instead.', 'wptransformed' ); ?>
                        </p>
                    <?php else : ?>
                        <p class="description">
                            <?php esc_html_e( 'URL to an image that will replace the WordPress logo on the login page. Recommended size: 320x80px.', 'wptransformed' ); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Version & branding', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_hide_wp_version" value="1"
                                   <?php checked( ! empty( $settings['hide_wp_version'] ) ); ?>>
                            <?php esc_html_e( 'Hide WordPress version from footer and HTML source', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_custom_admin_title"><?php esc_html_e( 'Custom admin title', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt_custom_admin_title" name="wpt_custom_admin_title"
                           value="<?php echo esc_attr( $settings['custom_admin_title'] ); ?>"
                           class="regular-text" placeholder="Your Brand">
                    <p class="description">
                        <?php esc_html_e( 'Replaces "WordPress" in browser tab titles with your custom text.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $admin_logo_url = esc_url_raw( $raw['wpt_admin_logo_url'] ?? '' );
        $login_logo_url = esc_url_raw( $raw['wpt_login_logo_url'] ?? '' );

        $admin_footer_text = wp_kses_post( $raw['wpt_admin_footer_text'] ?? '' );
        if ( mb_strlen( $admin_footer_text ) > 500 ) {
            $admin_footer_text = mb_substr( $admin_footer_text, 0, 500 );
        }

        $hide_wp_version = ! empty( $raw['wpt_hide_wp_version'] );

        $custom_admin_title = sanitize_text_field( $raw['wpt_custom_admin_title'] ?? '' );
        if ( mb_strlen( $custom_admin_title ) > 100 ) {
            $custom_admin_title = mb_substr( $custom_admin_title, 0, 100 );
        }

        return [
            'admin_logo_url'     => $admin_logo_url,
            'admin_footer_text'  => $admin_footer_text,
            'login_logo_url'     => $login_logo_url,
            'hide_wp_version'    => $hide_wp_version,
            'custom_admin_title' => $custom_admin_title,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the Login Customizer module is active.
     *
     * @return bool
     */
    private function is_login_customizer_active(): bool {
        $active_modules = Settings::get_active_modules();
        return in_array( 'login-customizer', $active_modules, true );
    }
}
