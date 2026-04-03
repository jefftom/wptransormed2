<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Frontend CSS -- Inject custom CSS into the site frontend.
 *
 * Outputs a <style> block in wp_head at priority 999 so it overrides
 * theme and plugin styles. Settings page uses wp-codemirror for editing.
 *
 * @package WPTransformed
 */
class Custom_Frontend_Css extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'custom-frontend-css';
    }

    public function get_title(): string {
        return __( 'Custom Frontend CSS', 'wptransformed' );
    }

    public function get_category(): string {
        return 'custom-code';
    }

    public function get_description(): string {
        return __( 'Add custom CSS to the site frontend via wp_head.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'css'               => '',
            'enable_codemirror' => true,
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $settings = $this->get_settings();
        $css      = trim( (string) ( $settings['css'] ?? '' ) );

        if ( '' === $css ) {
            return;
        }

        add_action( 'wp_head', [ $this, 'output_css' ], 999 );
    }

    /**
     * Output the custom CSS in a style tag.
     */
    public function output_css(): void {
        if ( is_admin() ) {
            return;
        }

        $settings = $this->get_settings();
        $css      = trim( (string) ( $settings['css'] ?? '' ) );

        if ( '' === $css ) {
            return;
        }

        // Strip any closing style tags to prevent injection.
        $css = str_replace( '</style', '', $css );

        echo '<style id="wpt-custom-frontend-css">' . "\n";
        echo $css . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content, sanitized on save.
        echo '</style>' . "\n";
    }

    // -- Assets --

    public function enqueue_admin_assets( string $hook ): void {
        // Only on our settings page.
        if ( false === strpos( $hook, 'wptransformed' ) ) {
            return;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['enable_codemirror'] ) ) {
            return;
        }

        $cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );

        if ( false === $cm_settings ) {
            return;
        }

        wp_add_inline_script(
            'code-editor',
            'document.addEventListener("DOMContentLoaded",function(){'
            . 'var ta=document.getElementById("wpt-custom-frontend-css-editor");'
            . 'if(ta){wp.codeEditor.initialize(ta,' . wp_json_encode( $cm_settings ) . ');}'
            . '});'
        );
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Use Code Editor', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enable_codemirror" value="1"
                               <?php checked( ! empty( $settings['enable_codemirror'] ) ); ?>>
                        <?php esc_html_e( 'Enable syntax-highlighted code editor', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-custom-frontend-css-editor">
                        <?php esc_html_e( 'Custom CSS', 'wptransformed' ); ?>
                    </label>
                </th>
                <td>
                    <textarea id="wpt-custom-frontend-css-editor"
                              name="wpt_css"
                              rows="15"
                              class="large-text code"
                              style="font-family:monospace;"><?php echo esc_textarea( $settings['css'] ?? '' ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'This CSS will be output in a <style> tag in the site frontend head.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        $css = (string) ( $raw['wpt_css'] ?? '' );

        // Strip closing style tags to prevent injection.
        $css = str_replace( '</style', '', $css );

        // Use wp_strip_all_tags to remove HTML but keep CSS.
        $css = wp_strip_all_tags( $css );

        return [
            'css'               => $css,
            'enable_codemirror' => ! empty( $raw['wpt_enable_codemirror'] ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [
            'settings' => [
                'description' => __( 'Remove saved custom frontend CSS', 'wptransformed' ),
                'type'        => 'option',
            ],
        ];
    }
}
