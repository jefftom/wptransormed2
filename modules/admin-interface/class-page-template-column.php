<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Page Template Column — Show the assigned template in the Pages list table.
 *
 * Adds a "Template" column to the Pages admin list that displays the
 * human-readable template name, or "Default" when none is assigned.
 *
 * @package WPTransformed
 */
class Page_Template_Column extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'page-template-column';
    }

    public function get_title(): string {
        return __( 'Page Template Column', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Show the assigned page template in the Pages list table.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_filter( 'manage_pages_columns', [ $this, 'add_template_column' ] );
        add_action( 'manage_pages_custom_column', [ $this, 'render_template_column' ], 10, 2 );
    }

    /**
     * Add the "Template" column to the Pages list table.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_template_column( array $columns ): array {
        // Insert before the "date" column if it exists.
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            if ( 'date' === $key ) {
                $new_columns['wpt_template'] = __( 'Template', 'wptransformed' );
            }
            $new_columns[ $key ] = $label;
        }

        // If "date" column was not found, append at end.
        if ( ! isset( $new_columns['wpt_template'] ) ) {
            $new_columns['wpt_template'] = __( 'Template', 'wptransformed' );
        }

        return $new_columns;
    }

    /**
     * Render the template name for each page row.
     *
     * @param string $column_name The column identifier.
     * @param int    $post_id     The post ID.
     */
    public function render_template_column( string $column_name, int $post_id ): void {
        if ( 'wpt_template' !== $column_name ) {
            return;
        }

        $template_slug = get_page_template_slug( $post_id );

        if ( empty( $template_slug ) ) {
            echo esc_html__( 'Default', 'wptransformed' );
            return;
        }

        // Look up the human-readable name from the current theme's templates.
        $templates = wp_get_theme()->get_page_templates( null, 'page' );

        if ( isset( $templates[ $template_slug ] ) ) {
            echo esc_html( $templates[ $template_slug ] );
        } else {
            // Template file exists in DB but not in theme — show the slug.
            echo esc_html( $template_slug );
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_enabled"
                               value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Show the Template column on the Pages list', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
