<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Columns Enhancer -- Add useful columns to post list tables.
 *
 * Features:
 *  - Post ID column
 *  - Featured image thumbnail (50x50)
 *  - Modified date column
 *  - Slug column
 *  - Page template column
 *  - Sortable ID and modified date columns
 *  - Configurable per post type
 *
 * @package WPTransformed
 */
class Admin_Columns_Enhancer extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'admin-columns-enhancer';
    }

    public function get_title(): string {
        return __( 'Admin Columns Enhancer', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add ID, thumbnail, modified date, slug, and template columns to post list tables.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'show_id'            => true,
            'show_thumbnail'     => true,
            'show_modified_date' => true,
            'show_slug'          => false,
            'show_template'      => false,
            'post_types'         => [ 'post', 'page' ],
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        add_action( 'admin_init', [ $this, 'register_column_hooks' ] );
    }

    /**
     * Register column hooks for each enabled post type.
     */
    public function register_column_hooks(): void {
        $settings   = $this->get_settings();
        $post_types = $this->get_enabled_post_types( $settings );

        foreach ( $post_types as $type ) {
            add_filter( "manage_{$type}_posts_columns", [ $this, 'add_columns' ] );
            add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
            add_filter( "manage_edit-{$type}_sortable_columns", [ $this, 'sortable_columns' ] );
        }

    }

    // -- Column Registration --

    /**
     * Add custom columns to the post list table.
     *
     * @param array<string,string> $columns Existing columns.
     * @return array<string,string>
     */
    public function add_columns( array $columns ): array {
        $settings    = $this->get_settings();
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            // Insert ID column after the checkbox.
            if ( $key === 'cb' && ! empty( $settings['show_id'] ) ) {
                $new_columns[ $key ] = $label;
                $new_columns['wpt_id'] = __( 'ID', 'wptransformed' );
                continue;
            }

            // Insert thumbnail after the title.
            if ( $key === 'title' && ! empty( $settings['show_thumbnail'] ) ) {
                $new_columns[ $key ] = $label;
                $new_columns['wpt_thumbnail'] = __( 'Thumbnail', 'wptransformed' );
                continue;
            }

            $new_columns[ $key ] = $label;
        }

        if ( ! empty( $settings['show_slug'] ) ) {
            $new_columns['wpt_slug'] = __( 'Slug', 'wptransformed' );
        }

        if ( ! empty( $settings['show_template'] ) ) {
            $new_columns['wpt_template'] = __( 'Template', 'wptransformed' );
        }

        if ( ! empty( $settings['show_modified_date'] ) ) {
            $new_columns['wpt_modified'] = __( 'Modified', 'wptransformed' );
        }

        return $new_columns;
    }

    // -- Column Rendering --

    /**
     * Render custom column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public function render_column( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'wpt_id':
                echo esc_html( (string) $post_id );
                break;

            case 'wpt_thumbnail':
                $thumb = get_the_post_thumbnail( $post_id, [ 50, 50 ] );
                if ( $thumb ) {
                    // get_the_post_thumbnail already escapes output.
                    echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    echo '<span aria-hidden="true">&mdash;</span>';
                }
                break;

            case 'wpt_modified':
                $post = get_post( $post_id );
                if ( $post ) {
                    $modified = get_the_modified_date( '', $post );
                    $time     = get_the_modified_time( '', $post );
                    echo esc_html( $modified . ' ' . $time );
                }
                break;

            case 'wpt_slug':
                $post = get_post( $post_id );
                if ( $post ) {
                    echo '<code>' . esc_html( $post->post_name ) . '</code>';
                }
                break;

            case 'wpt_template':
                $template = get_page_template_slug( $post_id );
                if ( $template ) {
                    echo esc_html( $template );
                } else {
                    echo esc_html__( 'Default', 'wptransformed' );
                }
                break;
        }
    }

    // -- Sortable Columns --

    /**
     * Make ID and modified date columns sortable.
     *
     * @param array<string,string> $columns Sortable columns.
     * @return array<string,string>
     */
    public function sortable_columns( array $columns ): array {
        $settings = $this->get_settings();

        if ( ! empty( $settings['show_id'] ) ) {
            $columns['wpt_id'] = 'ID';
        }

        if ( ! empty( $settings['show_modified_date'] ) ) {
            $columns['wpt_modified'] = 'modified';
        }

        return $columns;
    }

    // -- Assets --

    public function enqueue_admin_assets( string $hook ): void {
        // Only on list table screens.
        if ( ! in_array( $hook, [ 'edit.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $settings   = $this->get_settings();
        $post_types = $this->get_enabled_post_types( $settings );

        if ( ! in_array( $screen->post_type, $post_types, true ) ) {
            return;
        }

        // Inline styles for thumbnail column width.
        wp_add_inline_style( 'wp-admin', '
            .column-wpt_id { width: 50px; }
            .column-wpt_thumbnail { width: 60px; }
            .column-wpt_thumbnail img { border-radius: 3px; }
            .column-wpt_slug code { font-size: 12px; }
        ' );
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings   = $this->get_settings();
        $all_types  = get_post_types( [ 'show_ui' => true ], 'objects' );
        $enabled    = (array) ( $settings['post_types'] ?? [] );

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Columns to show', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="wpt_show_id" value="1"
                                   <?php checked( ! empty( $settings['show_id'] ) ); ?>>
                            <?php esc_html_e( 'Post ID', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="wpt_show_thumbnail" value="1"
                                   <?php checked( ! empty( $settings['show_thumbnail'] ) ); ?>>
                            <?php esc_html_e( 'Featured image thumbnail (50x50)', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="wpt_show_modified_date" value="1"
                                   <?php checked( ! empty( $settings['show_modified_date'] ) ); ?>>
                            <?php esc_html_e( 'Modified date', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="wpt_show_slug" value="1"
                                   <?php checked( ! empty( $settings['show_slug'] ) ); ?>>
                            <?php esc_html_e( 'Slug', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="wpt_show_template" value="1"
                                   <?php checked( ! empty( $settings['show_template'] ) ); ?>>
                            <?php esc_html_e( 'Page template', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
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
                        <?php esc_html_e( 'Select which post types should display the enhanced columns.', 'wptransformed' ); ?>
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
            'show_id'            => ! empty( $raw['wpt_show_id'] ),
            'show_thumbnail'     => ! empty( $raw['wpt_show_thumbnail'] ),
            'show_modified_date' => ! empty( $raw['wpt_show_modified_date'] ),
            'show_slug'          => ! empty( $raw['wpt_show_slug'] ),
            'show_template'      => ! empty( $raw['wpt_show_template'] ),
            'post_types'         => array_values( $post_types ),
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
