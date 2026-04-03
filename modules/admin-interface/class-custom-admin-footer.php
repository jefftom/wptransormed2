<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Admin Footer -- Replace the default WordPress admin footer text.
 *
 * Filters admin_footer_text (left side) and update_footer (right side)
 * to display custom branding or informational text.
 *
 * @package WPTransformed
 */
class Custom_Admin_Footer extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'custom-admin-footer';
    }

    public function get_title(): string {
        return __( 'Custom Admin Footer', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Replace the default WordPress admin footer text with your own custom branding.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'left_text'  => 'Powered by Your Agency',
            'right_text' => '',
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_filter( 'admin_footer_text', [ $this, 'filter_left_footer' ] );
        add_filter( 'update_footer',     [ $this, 'filter_right_footer' ], 11 );
    }

    // -- Hook Callbacks ---------------------------------------------------

    /**
     * Replace the left admin footer text.
     *
     * @param string $text Default footer text.
     * @return string Custom footer text.
     */
    public function filter_left_footer( string $text ): string {
        $settings  = $this->get_settings();
        $left_text = trim( $settings['left_text'] );

        if ( '' === $left_text ) {
            return $text;
        }

        return wp_kses_post( $left_text );
    }

    /**
     * Replace the right admin footer text (WordPress version area).
     *
     * @param string $text Default version text.
     * @return string Custom footer text or original.
     */
    public function filter_right_footer( string $text ): string {
        $settings   = $this->get_settings();
        $right_text = trim( $settings['right_text'] );

        if ( '' === $right_text ) {
            return $text;
        }

        return wp_kses_post( $right_text );
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_left_text"><?php esc_html_e( 'Left Footer Text', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt_left_text"
                              name="wpt_left_text"
                              rows="3"
                              class="large-text"><?php echo esc_textarea( $settings['left_text'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Replaces the default "Thank you for creating with WordPress" text. Basic HTML allowed. Leave empty to keep the default.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt_right_text"><?php esc_html_e( 'Right Footer Text', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt_right_text"
                              name="wpt_right_text"
                              rows="3"
                              class="large-text"><?php echo esc_textarea( $settings['right_text'] ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Replaces the WordPress version text in the right footer. Basic HTML allowed. Leave empty to keep the default.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'left_text'  => isset( $raw['wpt_left_text'] )  ? wp_kses_post( wp_unslash( $raw['wpt_left_text'] ) )  : '',
            'right_text' => isset( $raw['wpt_right_text'] ) ? wp_kses_post( wp_unslash( $raw['wpt_right_text'] ) ) : '',
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
