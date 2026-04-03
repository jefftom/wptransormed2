<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Taxonomy Filter -- Add taxonomy filter dropdowns to post list tables.
 *
 * Features:
 *  - Adds dropdowns for all custom taxonomies on enabled post types
 *  - Uses wp_dropdown_categories() for native WP rendering
 *  - Filters posts by selected taxonomy term via parse_query
 *  - Configurable per post type
 *
 * @package WPTransformed
 */
class Taxonomy_Filter extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'taxonomy-filter';
    }

    public function get_title(): string {
        return __( 'Taxonomy Filter', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add taxonomy filter dropdowns to post list tables for quick filtering by custom taxonomies.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enabled'    => true,
            'post_types' => [ 'post', 'page' ],
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'restrict_manage_posts', [ $this, 'render_taxonomy_dropdowns' ] );
        add_action( 'parse_query',           [ $this, 'filter_by_taxonomy' ] );
    }

    // -- Dropdown Rendering --

    /**
     * Render taxonomy filter dropdowns on post list screens.
     *
     * @param string $post_type Current post type.
     */
    public function render_taxonomy_dropdowns( string $post_type ): void {
        $settings   = $this->get_settings();
        $post_types = $this->get_enabled_post_types( $settings );

        if ( ! in_array( $post_type, $post_types, true ) ) {
            return;
        }

        $taxonomies = get_object_taxonomies( $post_type, 'objects' );

        foreach ( $taxonomies as $taxonomy ) {
            // Skip built-in taxonomies already handled by WP core (category, post_tag).
            if ( in_array( $taxonomy->name, [ 'category', 'post_tag', 'post_format' ], true ) ) {
                continue;
            }

            // Only show public, hierarchical or flat custom taxonomies with a UI.
            if ( ! $taxonomy->show_ui ) {
                continue;
            }

            $terms = get_terms( [
                'taxonomy'   => $taxonomy->name,
                'hide_empty' => true,
            ] );

            // Skip if no terms exist.
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter value.
            $selected = isset( $_GET[ $taxonomy->name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy->name ] ) ) : '';

            wp_dropdown_categories( [
                'show_option_all' => $taxonomy->labels->all_items,
                'taxonomy'        => $taxonomy->name,
                'name'            => $taxonomy->name,
                'orderby'         => 'name',
                'selected'        => $selected,
                'hierarchical'    => $taxonomy->hierarchical,
                'show_count'      => true,
                'hide_empty'      => true,
                'value_field'     => 'slug',
            ] );
        }
    }

    // -- Query Filtering --

    /**
     * Filter the query by selected taxonomy term.
     *
     * @param \WP_Query $query The current query.
     */
    public function filter_by_taxonomy( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'edit' ) {
            return;
        }

        $post_type  = $screen->post_type;
        $settings   = $this->get_settings();
        $post_types = $this->get_enabled_post_types( $settings );

        if ( ! in_array( $post_type, $post_types, true ) ) {
            return;
        }

        $taxonomies = get_object_taxonomies( $post_type, 'objects' );

        foreach ( $taxonomies as $taxonomy ) {
            if ( in_array( $taxonomy->name, [ 'category', 'post_tag', 'post_format' ], true ) ) {
                continue;
            }

            if ( ! $taxonomy->show_ui ) {
                continue;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter value.
            if ( ! empty( $_GET[ $taxonomy->name ] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $term_slug = sanitize_text_field( wp_unslash( $_GET[ $taxonomy->name ] ) );

                if ( $term_slug && $term_slug !== '0' ) {
                    $tax_query = $query->get( 'tax_query' ) ?: [];
                    $tax_query[] = [
                        'taxonomy' => $taxonomy->name,
                        'field'    => 'slug',
                        'terms'    => $term_slug,
                    ];
                    $query->set( 'tax_query', $tax_query );
                }
            }
        }
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings  = $this->get_settings();
        $all_types = get_post_types( [ 'show_ui' => true ], 'objects' );
        $enabled   = (array) ( $settings['post_types'] ?? [] );

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable filters', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Show taxonomy filter dropdowns on post list screens', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Post types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $all_types as $type ) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="checkbox" name="wpt_post_types[]"
                                       value="<?php echo esc_attr( $type->name ); ?>"
                                       <?php checked( in_array( $type->name, $enabled, true ) ); ?>>
                                <?php echo esc_html( $type->labels->name ); ?>
                                <span style="color: #888;">(<?php echo esc_html( $type->name ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Select which post types should display taxonomy filter dropdowns.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        $valid_types = array_keys( get_post_types( [ 'show_ui' => true ] ) );
        $post_types  = array_filter(
            array_map( 'sanitize_key', (array) ( $raw['wpt_post_types'] ?? [] ) ),
            static function ( string $type ) use ( $valid_types ): bool {
                return in_array( $type, $valid_types, true );
            }
        );

        return [
            'enabled'    => ! empty( $raw['wpt_enabled'] ),
            'post_types' => array_values( $post_types ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }

    // -- Helpers --

    /**
     * Get the list of enabled post types, filtered to only valid registered types.
     *
     * @param array<string,mixed> $settings Module settings.
     * @return string[]
     */
    private function get_enabled_post_types( array $settings ): array {
        $configured  = (array) ( $settings['post_types'] ?? [] );
        $valid_types = get_post_types( [ 'show_ui' => true ] );

        return array_values( array_intersect( $configured, array_keys( $valid_types ) ) );
    }
}
