<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Body Class -- Add custom CSS classes to the body tag globally or per page.
 *
 * @package WPTransformed
 */
class Custom_Body_Class extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'custom-body-class';
    }

    public function get_title(): string {
        return __( 'Custom Body Class', 'wptransformed' );
    }

    public function get_category(): string {
        return 'custom-code';
    }

    public function get_description(): string {
        return __( 'Add custom CSS classes to the body tag globally and per page/post.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'global_classes' => '',
            'per_page_field' => true,
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        // Frontend: add classes to body tag
        add_filter( 'body_class', [ $this, 'filter_body_class' ] );

        // Admin: add metabox for per-page classes
        $settings = $this->get_settings();
        if ( ! empty( $settings['per_page_field'] ) ) {
            add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
            add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );
        }
    }

    // -- Body Class Filter --

    /**
     * Add custom classes to the body tag.
     *
     * @param array $classes Existing body classes.
     * @return array
     */
    public function filter_body_class( array $classes ): array {
        $settings = $this->get_settings();

        $classes = $this->merge_classes( $classes, $settings['global_classes'] );

        if ( ! empty( $settings['per_page_field'] ) && is_singular() ) {
            $page_class = get_post_meta( get_queried_object_id(), '_wpt_body_class', true );
            $classes = $this->merge_classes( $classes, $page_class );
        }

        return $classes;
    }

    /**
     * Parse a space/comma-separated class string and merge into existing classes.
     *
     * @param array  $classes  Existing classes array.
     * @param string $raw_list Space or comma separated class names.
     * @return array
     */
    private function merge_classes( array $classes, string $raw_list ): array {
        $raw_list = trim( $raw_list );
        if ( empty( $raw_list ) ) {
            return $classes;
        }

        $parsed = preg_split( '/[\s,]+/', $raw_list, -1, PREG_SPLIT_NO_EMPTY );
        foreach ( $parsed as $class ) {
            $sanitized = sanitize_html_class( $class );
            if ( ! empty( $sanitized ) ) {
                $classes[] = $sanitized;
            }
        }

        return $classes;
    }

    // -- Meta Box --

    /**
     * Register the Custom Body Classes metabox.
     */
    public function register_meta_box(): void {
        $post_types = get_post_types( [ 'public' => true ] );
        unset( $post_types['attachment'] );

        foreach ( $post_types as $pt ) {
            add_meta_box(
                'wpt-custom-body-class',
                __( 'Custom Body Classes', 'wptransformed' ),
                [ $this, 'render_meta_box' ],
                $pt,
                'side',
                'low'
            );
        }
    }

    /**
     * Render the metabox content.
     *
     * @param \WP_Post $post Current post.
     */
    public function render_meta_box( \WP_Post $post ): void {
        $value = get_post_meta( $post->ID, '_wpt_body_class', true );
        wp_nonce_field( 'wpt_body_class_' . $post->ID, 'wpt_body_class_nonce' );
        ?>
        <label for="wpt_body_class" class="screen-reader-text">
            <?php esc_html_e( 'Custom Body Classes', 'wptransformed' ); ?>
        </label>
        <input type="text"
               id="wpt_body_class"
               name="wpt_body_class"
               value="<?php echo esc_attr( $value ); ?>"
               class="widefat"
               placeholder="<?php esc_attr_e( 'e.g. my-class another-class', 'wptransformed' ); ?>">
        <p class="description" style="margin-top: 4px;">
            <?php esc_html_e( 'Space-separated CSS class names added to the body tag on this page.', 'wptransformed' ); ?>
        </p>
        <?php
    }

    /**
     * Save metabox value on post save.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function save_meta_box( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['wpt_body_class_nonce'] ) ||
             ! wp_verify_nonce( $_POST['wpt_body_class_nonce'], 'wpt_body_class_' . $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $value = isset( $_POST['wpt_body_class'] ) ? sanitize_text_field( wp_unslash( $_POST['wpt_body_class'] ) ) : '';

        if ( empty( $value ) ) {
            delete_post_meta( $post_id, '_wpt_body_class' );
        } else {
            update_post_meta( $post_id, '_wpt_body_class', $value );
        }
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_global_classes"><?php esc_html_e( 'Global Body Classes', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="wpt_global_classes"
                           name="wpt_global_classes"
                           value="<?php echo esc_attr( $settings['global_classes'] ); ?>"
                           class="large-text"
                           placeholder="<?php esc_attr_e( 'e.g. site-wide-class another-class', 'wptransformed' ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'Space-separated CSS class names added to the body tag on every page.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Per-Page Field', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_per_page_field" value="1" <?php checked( $settings['per_page_field'] ); ?>>
                        <?php esc_html_e( 'Show "Custom Body Classes" metabox on post/page editor screens.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        return [
            'global_classes' => sanitize_text_field( $raw['wpt_global_classes'] ?? '' ),
            'per_page_field' => ! empty( $raw['wpt_per_page_field'] ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'post_meta', 'key' => '_wpt_body_class' ],
        ];
    }
}
