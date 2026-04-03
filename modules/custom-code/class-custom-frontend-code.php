<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Frontend Code -- Inject custom code into the site frontend.
 *
 * Features:
 *  - Head code (wp_head) -- scripts, meta tags, styles
 *  - Body open code (wp_body_open) -- tracking pixels, noscript tags
 *  - Footer code (wp_footer) -- analytics, chat widgets
 *  - Load on all pages or homepage only
 *  - CodeMirror HTML editor on settings page
 *  - Only outputs on frontend (not admin)
 *  - Raw storage (admin-only access for saving)
 *
 * @package WPTransformed
 */
class Custom_Frontend_Code extends Module_Base {

    /**
     * Valid load_on values.
     */
    private const VALID_LOAD_ON = [ 'all', 'homepage' ];

    /**
     * Cached settings for the current request.
     *
     * @var array<string,mixed>|null
     */
    private ?array $cached_settings = null;

    // -- Identity --

    public function get_id(): string {
        return 'custom-frontend-code';
    }

    public function get_title(): string {
        return __( 'Custom Frontend Code', 'wptransformed' );
    }

    public function get_category(): string {
        return 'custom-code';
    }

    public function get_description(): string {
        return __( 'Add custom code to your site header, body, and footer for analytics, tracking, and customizations.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'head_code'      => '',
            'body_open_code' => '',
            'footer_code'    => '',
            'load_on'        => 'all',
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        // Only output on frontend, never in admin.
        if ( is_admin() ) {
            return;
        }

        add_action( 'wp_head',      [ $this, 'output_head_code' ], 99 );
        add_action( 'wp_body_open', [ $this, 'output_body_open_code' ], 1 );
        add_action( 'wp_footer',    [ $this, 'output_footer_code' ], 99 );
    }

    // -- Code Output --

    /**
     * Output head code.
     */
    public function output_head_code(): void {
        $this->maybe_output( 'head_code' );
    }

    /**
     * Output body open code.
     */
    public function output_body_open_code(): void {
        $this->maybe_output( 'body_open_code' );
    }

    /**
     * Output footer code.
     */
    public function output_footer_code(): void {
        $this->maybe_output( 'footer_code' );
    }

    /**
     * Conditionally output a code setting based on load_on rules.
     *
     * @param string $key Settings key.
     */
    private function maybe_output( string $key ): void {
        if ( $this->cached_settings === null ) {
            $this->cached_settings = $this->get_settings();
        }
        $settings = $this->cached_settings;
        $code     = $settings[ $key ] ?? '';

        if ( $code === '' ) {
            return;
        }

        // Check load_on condition.
        $load_on = $settings['load_on'] ?? 'all';

        if ( $load_on === 'homepage' && ! is_front_page() ) {
            return;
        }

        // Code is stored raw; only admins can save settings.
        // Output with a comment marker for debugging.
        echo "\n<!-- WPTransformed: {$key} -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "\n<!-- /WPTransformed: {$key} -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // -- Assets --

    public function enqueue_admin_assets( string $hook ): void {
        // Only load CodeMirror on our settings page.
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        // Enqueue WordPress CodeMirror for HTML mode.
        $cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );

        if ( $cm_settings === false ) {
            return;
        }

        $editor_ids = [
            'wpt-head-code-editor',
            'wpt-body-open-code-editor',
            'wpt-footer-code-editor',
        ];

        $js = 'jQuery( function() {';
        foreach ( $editor_ids as $id ) {
            $js .= sprintf(
                ' if ( document.getElementById("%s") ) { wp.codeEditor.initialize( "%s", %s ); }',
                $id,
                $id,
                wp_json_encode( $cm_settings )
            );
        }
        $js .= ' } );';

        wp_add_inline_script( 'code-editor', $js );
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-head-code-editor"><?php esc_html_e( 'Header code', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-head-code-editor" name="wpt_head_code" rows="8"
                              class="large-text code"
                              style="font-family: monospace;"><?php echo esc_textarea( $settings['head_code'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Code added before the closing </head> tag. Useful for analytics, meta tags, and CSS.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-body-open-code-editor"><?php esc_html_e( 'Body open code', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-body-open-code-editor" name="wpt_body_open_code" rows="6"
                              class="large-text code"
                              style="font-family: monospace;"><?php echo esc_textarea( $settings['body_open_code'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Code added right after the opening <body> tag. Useful for tracking pixels and noscript tags.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-footer-code-editor"><?php esc_html_e( 'Footer code', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-footer-code-editor" name="wpt_footer_code" rows="8"
                              class="large-text code"
                              style="font-family: monospace;"><?php echo esc_textarea( $settings['footer_code'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Code added before the closing </body> tag. Useful for chat widgets and deferred scripts.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Load on', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="margin-right: 16px;">
                            <input type="radio" name="wpt_load_on" value="all"
                                   <?php checked( ( $settings['load_on'] ?? 'all' ), 'all' ); ?>>
                            <?php esc_html_e( 'All pages', 'wptransformed' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="wpt_load_on" value="homepage"
                                   <?php checked( ( $settings['load_on'] ?? 'all' ), 'homepage' ); ?>>
                            <?php esc_html_e( 'Homepage only', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <div class="notice notice-warning inline" style="margin-top: 10px;">
            <p>
                <?php esc_html_e( 'Only administrators can save these settings. Code is stored and output exactly as entered. Be careful with scripts from third parties.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        // Only administrators should save these settings.
        // The framework already checks manage_options, but double-check.
        if ( ! current_user_can( 'manage_options' ) ) {
            return $this->get_settings();
        }

        // Store raw code -- only admins can save.
        // Use wp_unslash to remove WordPress magic quotes.
        $head_code      = isset( $raw['wpt_head_code'] )      ? wp_unslash( $raw['wpt_head_code'] )      : '';
        $body_open_code = isset( $raw['wpt_body_open_code'] )  ? wp_unslash( $raw['wpt_body_open_code'] ) : '';
        $footer_code    = isset( $raw['wpt_footer_code'] )     ? wp_unslash( $raw['wpt_footer_code'] )    : '';

        $load_on = sanitize_text_field( $raw['wpt_load_on'] ?? 'all' );
        if ( ! in_array( $load_on, self::VALID_LOAD_ON, true ) ) {
            $load_on = 'all';
        }

        return [
            'head_code'      => (string) $head_code,
            'body_open_code' => (string) $body_open_code,
            'footer_code'    => (string) $footer_code,
            'load_on'        => $load_on,
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
