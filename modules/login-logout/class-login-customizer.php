<?php
declare(strict_types=1);

namespace WPTransformed\Modules\LoginLogout;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Login Customizer — Customize the WordPress login page appearance.
 *
 * Features:
 *  - Custom logo with configurable URL, link, and dimensions
 *  - Background color and image
 *  - Form styling (background, border radius)
 *  - Button color customization
 *  - Text and link color control
 *  - Hide "Back to blog" and privacy policy links
 *  - Custom CSS injection
 *  - Pure CSS — no JavaScript required on login page
 *  - Media uploader on settings page for logo/bg image selection
 *
 * @package WPTransformed
 */
class Login_Customizer extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'login-branding';
    }

    public function get_title(): string {
        return __( 'Login Branding', 'wptransformed' );
    }

    public function get_category(): string {
        return 'login-logout';
    }

    public function get_description(): string {
        return __( 'Brand your login page with custom logos, colors, styles, and site identity.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'logo_url'           => '',
            'logo_link'          => '',
            'logo_width'         => 320,
            'logo_height'        => 84,
            'bg_color'           => '#f1f1f1',
            'bg_image'           => '',
            'form_bg_color'      => '#ffffff',
            'form_border_radius' => 4,
            'button_bg_color'    => '#2271b1',
            'button_text_color'  => '#ffffff',
            'text_color'         => '#3c434a',
            'link_color'         => '#2271b1',
            'hide_back_to_blog'  => false,
            'hide_privacy_policy' => false,
            'custom_css'         => '',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Login page hooks — output inline styles.
        add_action( 'login_head', [ $this, 'output_login_styles' ] );

        // Logo link filter.
        add_filter( 'login_headerurl', [ $this, 'filter_logo_link' ] );

        // Logo alt text filter.
        add_filter( 'login_headertext', [ $this, 'filter_logo_text' ] );

        // Settings page: enqueue media uploader.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Login Page Styles ─────────────────────────────────────

    /**
     * Output inline CSS on the login page.
     */
    public function output_login_styles(): void {
        $s = $this->get_settings();

        $css = '';

        // Body background.
        $bg_color = $this->sanitize_hex_color( $s['bg_color'] );
        if ( $bg_color ) {
            $css .= 'body.login { background-color: ' . $bg_color . '; }' . "\n";
        }

        // Background image.
        $bg_image = $s['bg_image'];
        if ( ! empty( $bg_image ) && filter_var( $bg_image, FILTER_VALIDATE_URL ) ) {
            $css .= 'body.login { background-image: url(' . esc_url( $bg_image ) . '); background-size: cover; background-position: center; background-repeat: no-repeat; }' . "\n";
        }

        // Logo.
        $logo_url = $s['logo_url'];
        if ( ! empty( $logo_url ) && filter_var( $logo_url, FILTER_VALIDATE_URL ) ) {
            $width  = max( 1, min( 1000, (int) $s['logo_width'] ) );
            $height = max( 1, min( 1000, (int) $s['logo_height'] ) );
            $css .= '.login h1 a { background-image: url(' . esc_url( $logo_url ) . '); background-size: ' . $width . 'px ' . $height . 'px; width: ' . $width . 'px; height: ' . $height . 'px; }' . "\n";
        }

        // Form background.
        $form_bg = $this->sanitize_hex_color( $s['form_bg_color'] );
        if ( $form_bg ) {
            $css .= '#loginform { background-color: ' . $form_bg . '; }' . "\n";
        }

        // Form border radius.
        $radius = max( 0, min( 50, (int) $s['form_border_radius'] ) );
        $css .= '#loginform { border-radius: ' . $radius . 'px; }' . "\n";

        // Button colors.
        $btn_bg = $this->sanitize_hex_color( $s['button_bg_color'] );
        $btn_text = $this->sanitize_hex_color( $s['button_text_color'] );
        if ( $btn_bg ) {
            $css .= '.wp-core-ui .button-primary { background: ' . $btn_bg . '; border-color: ' . $btn_bg . '; }' . "\n";
            $css .= '.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus { background: ' . $btn_bg . '; border-color: ' . $btn_bg . '; opacity: 0.9; }' . "\n";
        }
        if ( $btn_text ) {
            $css .= '.wp-core-ui .button-primary { color: ' . $btn_text . '; }' . "\n";
            $css .= '.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus { color: ' . $btn_text . '; }' . "\n";
        }

        // Text color.
        $text_color = $this->sanitize_hex_color( $s['text_color'] );
        if ( $text_color ) {
            $css .= 'body.login { color: ' . $text_color . '; }' . "\n";
            $css .= '#loginform label { color: ' . $text_color . '; }' . "\n";
        }

        // Link color.
        $link_color = $this->sanitize_hex_color( $s['link_color'] );
        if ( $link_color ) {
            $css .= '#nav a, #backtoblog a { color: ' . $link_color . '; }' . "\n";
            $css .= '#nav a:hover, #backtoblog a:hover { color: ' . $link_color . '; opacity: 0.8; }' . "\n";
        }

        // Hide "Back to blog" link.
        if ( ! empty( $s['hide_back_to_blog'] ) ) {
            $css .= '#backtoblog { display: none; }' . "\n";
        }

        // Hide privacy policy link.
        if ( ! empty( $s['hide_privacy_policy'] ) ) {
            $css .= '.privacy-policy-page-link { display: none; }' . "\n";
        }

        // Custom CSS.
        $custom_css = $s['custom_css'];
        if ( ! empty( $custom_css ) ) {
            $safe_css = wp_strip_all_tags( $custom_css );
            $safe_css = str_replace( '</', '', $safe_css );
            $css .= $safe_css . "\n";
        }

        if ( ! empty( $css ) ) {
            echo '<style id="wpt-login-customizer">' . "\n";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS values are individually sanitized above.
            echo $css;
            echo '</style>' . "\n";
        }
    }

    // ── Logo Filters ──────────────────────────────────────────

    /**
     * Change the logo link URL on the login page.
     *
     * @param string $url Default URL.
     * @return string
     */
    public function filter_logo_link( string $url ): string {
        $settings = $this->get_settings();
        $logo_link = $settings['logo_link'];

        if ( ! empty( $logo_link ) && filter_var( $logo_link, FILTER_VALIDATE_URL ) ) {
            return esc_url( $logo_link );
        }

        return home_url( '/' );
    }

    /**
     * Change the logo alt text to the site name.
     *
     * @param string $text Default text.
     * @return string
     */
    public function filter_logo_text( string $text ): string {
        return get_bloginfo( 'name', 'display' );
    }

    // ── Assets ────────────────────────────────────────────────

    /**
     * Enqueue WordPress media uploader on the settings page.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_media();

        // Inline script for media uploader buttons — no separate JS file needed.
        $inline_js = <<<'JS'
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.wpt-media-upload').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var targetSelector = btn.getAttribute('data-target');
            var targetInput = document.querySelector(targetSelector);
            if (!targetInput) return;

            var frame = wp.media({
                title: btn.textContent.trim(),
                button: { text: btn.textContent.trim() },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                targetInput.value = attachment.url;
            });

            frame.open();
        });
    });

    document.querySelectorAll('.wpt-media-clear').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var targetSelector = btn.getAttribute('data-target');
            var targetInput = document.querySelector(targetSelector);
            if (targetInput) {
                targetInput.value = '';
            }
        });
    });
});
JS;

        wp_register_script( 'wpt-login-customizer-admin', '', [], WPT_VERSION, true );
        wp_enqueue_script( 'wpt-login-customizer-admin' );
        wp_add_inline_script( 'wpt-login-customizer-admin', $inline_js );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <!-- Logo URL -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Logo image', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_logo_url" id="wpt-logo-url"
                           value="<?php echo esc_url( $settings['logo_url'] ); ?>"
                           class="regular-text" placeholder="https://">
                    <button type="button" class="button wpt-media-upload" data-target="#wpt-logo-url">
                        <?php esc_html_e( 'Select Image', 'wptransformed' ); ?>
                    </button>
                    <?php if ( ! empty( $settings['logo_url'] ) ) : ?>
                        <div style="margin-top: 8px;">
                            <img src="<?php echo esc_url( $settings['logo_url'] ); ?>"
                                 style="max-width: 200px; max-height: 100px; border: 1px solid #ddd; padding: 4px;">
                        </div>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e( 'Upload or enter the URL of the logo to display on the login page.', 'wptransformed' ); ?></p>
                </td>
            </tr>

            <!-- Logo Link -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Logo link URL', 'wptransformed' ); ?></th>
                <td>
                    <input type="url" name="wpt_logo_link"
                           value="<?php echo esc_url( $settings['logo_link'] ); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'URL the logo links to. Defaults to your site home page.', 'wptransformed' ); ?></p>
                </td>
            </tr>

            <!-- Logo Dimensions -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Logo dimensions', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <?php esc_html_e( 'Width', 'wptransformed' ); ?>
                        <input type="number" name="wpt_logo_width"
                               value="<?php echo esc_attr( (string) $settings['logo_width'] ); ?>"
                               min="1" max="1000" step="1" style="width: 80px;">
                        px
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <?php esc_html_e( 'Height', 'wptransformed' ); ?>
                        <input type="number" name="wpt_logo_height"
                               value="<?php echo esc_attr( (string) $settings['logo_height'] ); ?>"
                               min="1" max="1000" step="1" style="width: 80px;">
                        px
                    </label>
                </td>
            </tr>

            <!-- Background Color -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Background color', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_bg_color"
                           value="<?php echo esc_attr( $settings['bg_color'] ); ?>"
                           class="wpt-color-field" placeholder="#f1f1f1">
                </td>
            </tr>

            <!-- Background Image -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Background image', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_bg_image" id="wpt-bg-image"
                           value="<?php echo esc_url( $settings['bg_image'] ); ?>"
                           class="regular-text" placeholder="https://">
                    <button type="button" class="button wpt-media-upload" data-target="#wpt-bg-image">
                        <?php esc_html_e( 'Select Image', 'wptransformed' ); ?>
                    </button>
                    <?php if ( ! empty( $settings['bg_image'] ) ) : ?>
                        <button type="button" class="button wpt-media-clear" data-target="#wpt-bg-image">
                            <?php esc_html_e( 'Remove', 'wptransformed' ); ?>
                        </button>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e( 'Optional background image for the login page.', 'wptransformed' ); ?></p>
                </td>
            </tr>

            <!-- Form Background Color -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Form background color', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_form_bg_color"
                           value="<?php echo esc_attr( $settings['form_bg_color'] ); ?>"
                           class="wpt-color-field" placeholder="#ffffff">
                </td>
            </tr>

            <!-- Form Border Radius -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Form border radius', 'wptransformed' ); ?></th>
                <td>
                    <input type="number" name="wpt_form_border_radius"
                           value="<?php echo esc_attr( (string) $settings['form_border_radius'] ); ?>"
                           min="0" max="50" step="1" style="width: 80px;">
                    px
                </td>
            </tr>

            <!-- Button Colors -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Button background color', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_button_bg_color"
                           value="<?php echo esc_attr( $settings['button_bg_color'] ); ?>"
                           class="wpt-color-field" placeholder="#2271b1">
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Button text color', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_button_text_color"
                           value="<?php echo esc_attr( $settings['button_text_color'] ); ?>"
                           class="wpt-color-field" placeholder="#ffffff">
                </td>
            </tr>

            <!-- Text Color -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Text color', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_text_color"
                           value="<?php echo esc_attr( $settings['text_color'] ); ?>"
                           class="wpt-color-field" placeholder="#3c434a">
                </td>
            </tr>

            <!-- Link Color -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Link color', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_link_color"
                           value="<?php echo esc_attr( $settings['link_color'] ); ?>"
                           class="wpt-color-field" placeholder="#2271b1">
                </td>
            </tr>

            <!-- Visibility Options -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Visibility', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_hide_back_to_blog" value="1"
                                   <?php checked( ! empty( $settings['hide_back_to_blog'] ) ); ?>>
                            <?php esc_html_e( 'Hide "Back to blog" link', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="wpt_hide_privacy_policy" value="1"
                                   <?php checked( ! empty( $settings['hide_privacy_policy'] ) ); ?>>
                            <?php esc_html_e( 'Hide privacy policy link', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <!-- Custom CSS -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Custom CSS', 'wptransformed' ); ?></th>
                <td>
                    <textarea name="wpt_custom_css" rows="8" class="large-text code"
                              placeholder="<?php esc_attr_e( '/* Add custom CSS for the login page */', 'wptransformed' ); ?>"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Additional CSS to apply to the login page. Targets: body.login, .login h1 a, #loginform, .wp-core-ui .button-primary, #nav a, #backtoblog.', 'wptransformed' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'logo_url'           => esc_url_raw( $raw['wpt_logo_url'] ?? '' ),
            'logo_link'          => esc_url_raw( $raw['wpt_logo_link'] ?? '' ),
            'logo_width'         => max( 1, min( 1000, (int) ( $raw['wpt_logo_width'] ?? 320 ) ) ),
            'logo_height'        => max( 1, min( 1000, (int) ( $raw['wpt_logo_height'] ?? 84 ) ) ),
            'bg_color'           => $this->sanitize_hex_color( $raw['wpt_bg_color'] ?? '#f1f1f1' ) ?: '#f1f1f1',
            'bg_image'           => esc_url_raw( $raw['wpt_bg_image'] ?? '' ),
            'form_bg_color'      => $this->sanitize_hex_color( $raw['wpt_form_bg_color'] ?? '#ffffff' ) ?: '#ffffff',
            'form_border_radius' => max( 0, min( 50, (int) ( $raw['wpt_form_border_radius'] ?? 4 ) ) ),
            'button_bg_color'    => $this->sanitize_hex_color( $raw['wpt_button_bg_color'] ?? '#2271b1' ) ?: '#2271b1',
            'button_text_color'  => $this->sanitize_hex_color( $raw['wpt_button_text_color'] ?? '#ffffff' ) ?: '#ffffff',
            'text_color'         => $this->sanitize_hex_color( $raw['wpt_text_color'] ?? '#3c434a' ) ?: '#3c434a',
            'link_color'         => $this->sanitize_hex_color( $raw['wpt_link_color'] ?? '#2271b1' ) ?: '#2271b1',
            'hide_back_to_blog'  => ! empty( $raw['wpt_hide_back_to_blog'] ),
            'hide_privacy_policy' => ! empty( $raw['wpt_hide_privacy_policy'] ),
            'custom_css'         => wp_strip_all_tags( $raw['wpt_custom_css'] ?? '' ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => 'wpt_settings_login-customizer' ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Validate and sanitize a hex color value.
     *
     * @param string $color Hex color string.
     * @return string Sanitized hex color or empty string if invalid.
     */
    private function sanitize_hex_color( string $color ): string {
        $color = trim( $color );

        if ( empty( $color ) ) {
            return '';
        }

        // Ensure it starts with #.
        if ( strpos( $color, '#' ) !== 0 ) {
            $color = '#' . $color;
        }

        // Match 3 or 6 digit hex color.
        if ( preg_match( '/^#([0-9a-fA-F]{3}){1,2}$/', $color ) ) {
            return $color;
        }

        return '';
    }
}
