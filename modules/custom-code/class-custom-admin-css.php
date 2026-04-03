<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Admin CSS -- Inject custom CSS into the WordPress admin area.
 *
 * Features:
 *  - CodeMirror-powered CSS editor on settings page
 *  - Outputs custom CSS in admin_head via <style> tag
 *  - Sanitized with wp_strip_all_tags() to prevent script injection
 *
 * @package WPTransformed
 */
class Custom_Admin_CSS extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'custom-admin-css';
    }

    public function get_title(): string {
        return __( 'Custom Admin CSS', 'wptransformed' );
    }

    public function get_category(): string {
        return 'custom-code';
    }

    public function get_description(): string {
        return __( 'Add custom CSS to the WordPress admin dashboard.', 'wptransformed' );
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
        add_action( 'admin_head', [ $this, 'output_custom_css' ], 99 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // -- CSS Output --

    /**
     * Output custom CSS in the admin head.
     */
    public function output_custom_css(): void {
        $settings = $this->get_settings();
        $css      = $settings['css'] ?? '';

        if ( $css === '' ) {
            return;
        }

        // Strip any HTML/script tags for safety.
        $css = wp_strip_all_tags( $css );

        echo '<style id="wpt-custom-admin-css">' . "\n";
        // CSS is stripped of tags above; output directly.
        echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "\n" . '</style>' . "\n";
    }

    // -- Assets --

    public function enqueue_admin_assets( string $hook ): void {
        // Only load CodeMirror on our settings page.
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        $settings = $this->get_settings();

        if ( empty( $settings['enable_codemirror'] ) ) {
            return;
        }

        // Enqueue WordPress CodeMirror for CSS mode.
        $cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );

        if ( $cm_settings === false ) {
            return;
        }

        wp_add_inline_script(
            'code-editor',
            sprintf(
                'jQuery( function() { if ( document.getElementById("wpt-admin-css-editor") ) { wp.codeEditor.initialize( "wpt-admin-css-editor", %s ); } } );',
                wp_json_encode( $cm_settings )
            )
        );
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-admin-css-editor"><?php esc_html_e( 'Custom Admin CSS', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-admin-css-editor" name="wpt_css" rows="15"
                              class="large-text code"
                              style="font-family: monospace; min-height: 300px;"><?php echo esc_textarea( $settings['css'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Enter custom CSS to apply to the WordPress admin area. Do not include <style> tags.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Code editor', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enable_codemirror" value="1"
                               <?php checked( ! empty( $settings['enable_codemirror'] ) ); ?>>
                        <?php esc_html_e( 'Enable CodeMirror syntax highlighting', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        $css = $raw['wpt_css'] ?? '';

        // Strip all HTML tags to prevent script injection.
        $css = wp_strip_all_tags( (string) $css );

        return [
            'css'               => $css,
            'enable_codemirror' => ! empty( $raw['wpt_enable_codemirror'] ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
