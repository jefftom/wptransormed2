<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Enhance List Tables — Add thumbnail, excerpt, and word count columns to post list tables.
 *
 * Features:
 *  - Featured image thumbnail column (configurable size)
 *  - Excerpt preview column (15 words max)
 *  - Word count column
 *  - Configurable per post type
 *  - Placeholder dashicon when no featured image
 *
 * @package WPTransformed
 */
class Enhance_List_Tables extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'enhance-list-tables';
    }

    public function get_title(): string {
        return __( 'Enhance List Tables', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add featured image, excerpt preview, and word count columns to post list tables.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'show_featured_image'  => true,
            'show_excerpt_preview' => true,
            'show_word_count'      => true,
            'image_size'           => 50,
            'post_types'           => [ 'post', 'page' ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings   = $this->get_settings();
        $post_types = $this->get_valid_post_types( $settings );

        if ( empty( $post_types ) ) {
            return;
        }

        foreach ( $post_types as $type ) {
            add_filter( "manage_{$type}_posts_columns", [ $this, 'add_columns' ] );
            add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
        }

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Columns ───────────────────────────────────────────────

    /**
     * Add custom columns to the post list table.
     *
     * @param array<string,string> $columns Existing columns.
     * @return array<string,string> Modified columns.
     */
    public function add_columns( array $columns ): array {
        $settings    = $this->get_settings();
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            // Insert our columns after the checkbox (cb) column.
            if ( $key === 'title' && ! empty( $settings['show_featured_image'] ) ) {
                $new_columns['wpt_thumbnail'] = __( 'Image', 'wptransformed' );
            }

            $new_columns[ $key ] = $label;
        }

        if ( ! empty( $settings['show_excerpt_preview'] ) ) {
            $new_columns['wpt_excerpt'] = __( 'Excerpt', 'wptransformed' );
        }

        if ( ! empty( $settings['show_word_count'] ) ) {
            $new_columns['wpt_word_count'] = __( 'Words', 'wptransformed' );
        }

        return $new_columns;
    }

    /**
     * Render custom column content.
     *
     * @param string $column  Column ID.
     * @param int    $post_id Post ID.
     */
    public function render_column( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'wpt_thumbnail':
                $this->render_thumbnail( $post_id );
                break;

            case 'wpt_excerpt':
                $this->render_excerpt( $post_id );
                break;

            case 'wpt_word_count':
                $this->render_word_count( $post_id );
                break;
        }
    }

    /**
     * Render thumbnail or placeholder dashicon.
     *
     * @param int $post_id Post ID.
     */
    private function render_thumbnail( int $post_id ): void {
        $settings = $this->get_settings();
        $size     = $this->get_safe_image_size( $settings );

        if ( has_post_thumbnail( $post_id ) ) {
            $thumb = get_the_post_thumbnail(
                $post_id,
                [ $size, $size ],
                [
                    'style'   => sprintf( 'width:%dpx;height:%dpx;object-fit:cover;border-radius:4px;', $size, $size ),
                    'loading' => 'lazy',
                ]
            );
            // get_the_post_thumbnail already escapes output.
            echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            printf(
                '<span class="dashicons dashicons-format-image" style="width:%1$dpx;height:%1$dpx;font-size:%1$dpx;color:#ccc;line-height:%1$dpx;"></span>',
                $size
            );
        }
    }

    /**
     * Render excerpt preview (max 15 words).
     *
     * @param int $post_id Post ID.
     */
    private function render_excerpt( int $post_id ): void {
        $post = get_post( $post_id );

        if ( ! $post ) {
            echo '<span style="color:#999;">&mdash;</span>';
            return;
        }

        // Prefer the manual excerpt, fall back to content.
        $text = $post->post_excerpt;
        if ( empty( $text ) ) {
            $text = $post->post_content;
        }

        // Strip shortcodes and HTML.
        $text = wp_strip_all_tags( strip_shortcodes( $text ) );

        // Limit to 15 words.
        $words    = explode( ' ', $text );
        $ellipsis = '';
        if ( count( $words ) > 15 ) {
            $text     = implode( ' ', array_slice( $words, 0, 15 ) );
            $ellipsis = '…';
        }

        if ( empty( trim( $text ) ) ) {
            echo '<span style="color:#999;">&mdash;</span>';
            return;
        }

        echo '<span style="color:#666;font-size:12px;">' . esc_html( $text . $ellipsis ) . '</span>';
    }

    /**
     * Render word count.
     *
     * @param int $post_id Post ID.
     */
    private function render_word_count( int $post_id ): void {
        $post = get_post( $post_id );

        if ( ! $post ) {
            echo '<span style="color:#999;">0</span>';
            return;
        }

        $text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
        $count = str_word_count( $text );

        echo '<span style="color:#666;">' . esc_html( (string) number_format_i18n( $count ) ) . '</span>';
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'edit.php' ) {
            return;
        }

        $settings = $this->get_settings();
        $size     = $this->get_safe_image_size( $settings );

        // Inline CSS for column widths.
        $css = sprintf(
            '.column-wpt_thumbnail { width: %dpx; }' .
            '.column-wpt_excerpt { width: 200px; }' .
            '.column-wpt_word_count { width: 70px; }',
            $size + 20
        );

        wp_add_inline_style( 'list-tables', $css );

        // Fallback: if list-tables style is not enqueued, register a dummy handle.
        if ( ! wp_style_is( 'list-tables', 'enqueued' ) ) {
            wp_register_style( 'wpt-enhance-list-tables', false );
            wp_enqueue_style( 'wpt-enhance-list-tables' );
            wp_add_inline_style( 'wpt-enhance-list-tables', $css );
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings   = $this->get_settings();
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Columns to show', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_show_featured_image" value="1"
                                   <?php checked( ! empty( $settings['show_featured_image'] ) ); ?>>
                            <?php esc_html_e( 'Featured image thumbnail', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_show_excerpt_preview" value="1"
                                   <?php checked( ! empty( $settings['show_excerpt_preview'] ) ); ?>>
                            <?php esc_html_e( 'Excerpt preview (15 words)', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_show_word_count" value="1"
                                   <?php checked( ! empty( $settings['show_word_count'] ) ); ?>>
                            <?php esc_html_e( 'Word count', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_image_size"><?php esc_html_e( 'Thumbnail size (px)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt_image_size" name="wpt_image_size"
                           value="<?php echo esc_attr( (string) $settings['image_size'] ); ?>"
                           min="20" max="150" step="1" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Width and height of the thumbnail in pixels (20-150).', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Post types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php
                        $active_types = (array) $settings['post_types'];
                        foreach ( $post_types as $pt ) :
                            if ( $pt->name === 'attachment' ) {
                                continue;
                            }
                        ?>
                            <label style="display: block; margin-bottom: 6px;">
                                <input type="checkbox" name="wpt_post_types[]"
                                       value="<?php echo esc_attr( $pt->name ); ?>"
                                       <?php checked( in_array( $pt->name, $active_types, true ) ); ?>>
                                <?php echo esc_html( $pt->labels->name ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $show_featured_image  = ! empty( $raw['wpt_show_featured_image'] );
        $show_excerpt_preview = ! empty( $raw['wpt_show_excerpt_preview'] );
        $show_word_count      = ! empty( $raw['wpt_show_word_count'] );

        $image_size = isset( $raw['wpt_image_size'] ) ? absint( $raw['wpt_image_size'] ) : 50;
        $image_size = max( 20, min( 150, $image_size ) );

        // Sanitize post types.
        $submitted_types = isset( $raw['wpt_post_types'] ) && is_array( $raw['wpt_post_types'] )
            ? $raw['wpt_post_types']
            : [];

        $valid_types = array_keys( get_post_types( [ 'public' => true ] ) );
        $post_types  = [];

        foreach ( $submitted_types as $type ) {
            $type = sanitize_key( $type );
            if ( in_array( $type, $valid_types, true ) && $type !== 'attachment' ) {
                $post_types[] = $type;
            }
        }

        return [
            'show_featured_image'  => $show_featured_image,
            'show_excerpt_preview' => $show_excerpt_preview,
            'show_word_count'      => $show_word_count,
            'image_size'           => $image_size,
            'post_types'           => $post_types,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Get validated post types from settings.
     *
     * @param array<string,mixed> $settings Module settings.
     * @return string[] Valid post type slugs.
     */
    private function get_valid_post_types( array $settings ): array {
        $configured = (array) ( $settings['post_types'] ?? [] );
        $valid      = array_keys( get_post_types( [ 'public' => true ] ) );

        return array_values( array_intersect(
            array_map( 'sanitize_key', $configured ),
            $valid
        ) );
    }

    /**
     * Get safe image size from settings.
     *
     * @param array<string,mixed> $settings Module settings.
     * @return int Clamped image size.
     */
    private function get_safe_image_size( array $settings ): int {
        $size = isset( $settings['image_size'] ) ? absint( $settings['image_size'] ) : 50;
        return max( 20, min( 150, $size ) );
    }
}
