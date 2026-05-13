<?php
declare(strict_types=1);

namespace WPTransformed\Modules\LoginLogout;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Site Identity Login — Replace the WordPress login logo with the site's own logo.
 *
 * Uses the site logo from the Customizer (or a custom uploaded URL) to brand
 * the login page. Falls back to displaying the site title as text when no
 * logo is available.
 *
 * @package WPTransformed
 */
class Site_Identity_Login extends Module_Base {

    // -- Identity ----------------------------------------------------------

    public function get_id(): string {
        return 'site-identity-login';
    }

    public function get_title(): string {
        return __( 'Site Identity Login', 'wptransformed' );
    }

    public function get_category(): string {
        return 'login-logout';
    }

    public function get_description(): string {
        return __( 'Replace the default WordPress login logo with your site logo or title.', 'wptransformed' );
    }

    // -- Settings ----------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'logo_source'     => 'site_logo',
            'custom_logo_url' => '',
            'logo_width'      => 84,
            'logo_height'     => 84,
        ];
    }

    // -- Lifecycle ---------------------------------------------------------

    public function init(): void {
        add_filter( 'login_headerurl',  [ $this, 'filter_login_url' ] );
        add_filter( 'login_headertext', [ $this, 'filter_login_text' ] );
        add_action( 'login_head',       [ $this, 'output_login_css' ] );
    }

    // -- Filters -----------------------------------------------------------

    /**
     * Point the login logo link to the site home page.
     *
     * @param string $url Default URL (wordpress.org).
     * @return string
     */
    public function filter_login_url( string $url ): string {
        return home_url( '/' );
    }

    /**
     * Use the site name as the logo alt / link text.
     *
     * @param string $text Default text.
     * @return string
     */
    public function filter_login_text( string $text ): string {
        return get_bloginfo( 'name', 'display' );
    }

    // -- Login CSS ---------------------------------------------------------

    /**
     * Output inline CSS on the login page to replace the default WP logo.
     */
    public function output_login_css(): void {
        $s        = $this->get_settings();
        $logo_url = $this->resolve_logo_url( $s );

        if ( ! empty( $logo_url ) ) {
            $this->render_logo_css( $logo_url, $s );
        } else {
            $this->render_text_fallback_css();
        }
    }

    // -- Settings UI -------------------------------------------------------

    public function render_settings(): void {
        $s = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Logo source', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_logo_source" value="site_logo"
                                   <?php checked( $s['logo_source'], 'site_logo' ); ?>>
                            <?php esc_html_e( 'Use site logo from Customizer', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="radio" name="wpt_logo_source" value="custom"
                                   <?php checked( $s['logo_source'], 'custom' ); ?>>
                            <?php esc_html_e( 'Use a custom image URL', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Custom logo URL', 'wptransformed' ); ?></th>
                <td>
                    <input type="url" name="wpt_custom_logo_url"
                           value="<?php echo esc_url( $s['custom_logo_url'] ); ?>"
                           class="regular-text" placeholder="https://">
                    <p class="description">
                        <?php esc_html_e( 'Only used when "Use a custom image URL" is selected above.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Logo dimensions', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <?php esc_html_e( 'Width', 'wptransformed' ); ?>
                        <input type="number" name="wpt_logo_width"
                               value="<?php echo esc_attr( (string) $s['logo_width'] ); ?>"
                               min="1" max="500" step="1" style="width: 80px;">
                        px
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <?php esc_html_e( 'Height', 'wptransformed' ); ?>
                        <input type="number" name="wpt_logo_height"
                               value="<?php echo esc_attr( (string) $s['logo_height'] ); ?>"
                               min="1" max="500" step="1" style="width: 80px;">
                        px
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize ----------------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        $source = ( $raw['wpt_logo_source'] ?? 'site_logo' ) === 'custom' ? 'custom' : 'site_logo';

        return [
            'logo_source'     => $source,
            'custom_logo_url' => esc_url_raw( $raw['wpt_custom_logo_url'] ?? '' ),
            'logo_width'      => max( 1, min( 500, (int) ( $raw['wpt_logo_width'] ?? 84 ) ) ),
            'logo_height'     => max( 1, min( 500, (int) ( $raw['wpt_logo_height'] ?? 84 ) ) ),
        ];
    }

    // -- Cleanup -----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => 'wpt_settings_site-identity-login' ],
        ];
    }

    // -- Helpers -----------------------------------------------------------

    /**
     * Resolve the logo URL based on the configured source.
     *
     * @param array<string,mixed> $s Module settings.
     * @return string Logo URL or empty string if none available.
     */
    private function resolve_logo_url( array $s ): string {
        if ( $s['logo_source'] === 'custom' && ! empty( $s['custom_logo_url'] ) ) {
            return $s['custom_logo_url'];
        }

        // Try the Customizer custom logo (theme_mod).
        $custom_logo_id = (int) get_theme_mod( 'custom_logo', 0 );
        if ( $custom_logo_id > 0 ) {
            $image = wp_get_attachment_image_url( $custom_logo_id, 'full' );
            if ( is_string( $image ) && $image !== '' ) {
                return $image;
            }
        }

        return '';
    }

    /**
     * Render CSS that replaces the login logo with an image.
     *
     * @param string              $logo_url Validated logo URL.
     * @param array<string,mixed> $s        Module settings.
     */
    private function render_logo_css( string $logo_url, array $s ): void {
        $width  = max( 1, min( 500, (int) $s['logo_width'] ) );
        $height = max( 1, min( 500, (int) $s['logo_height'] ) );

        echo '<style id="wpt-site-identity-login">' . "\n";
        echo '.login h1 a {' . "\n";
        echo '  background-image: url(' . esc_url( $logo_url ) . ');' . "\n";
        echo '  background-size: ' . $width . 'px ' . $height . 'px;' . "\n";
        echo '  width: ' . $width . 'px;' . "\n";
        echo '  height: ' . $height . 'px;' . "\n";
        echo '}' . "\n";
        echo '</style>' . "\n";
    }

    /**
     * Render CSS that replaces the logo with the site title as text.
     */
    private function render_text_fallback_css(): void {
        echo '<style id="wpt-site-identity-login">' . "\n";
        echo '.login h1 a {' . "\n";
        echo '  background-image: none !important;' . "\n";
        echo '  font-size: 24px;' . "\n";
        echo '  font-weight: 600;' . "\n";
        echo '  color: #3c434a;' . "\n";
        echo '  text-indent: 0;' . "\n";
        echo '  width: auto;' . "\n";
        echo '  height: auto;' . "\n";
        echo '  text-decoration: none;' . "\n";
        echo '}' . "\n";
        echo '</style>' . "\n";
    }
}
