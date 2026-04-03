<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Image Sizes Panel -- Show all registered image sizes on attachment edit screens.
 *
 * Adds a panel to image attachment fields listing every registered size
 * with dimensions, file size, and URL. Includes a clipboard copy button.
 *
 * @package WPTransformed
 */
class Image_Sizes_Panel extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'image-sizes-panel';
    }

    public function get_title(): string {
        return __( 'Image Sizes Panel', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Display all registered image sizes with dimensions, file size, and URL on attachment edit screens.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enabled'          => true,
            'show_copy_button' => true,
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_filter( 'attachment_fields_to_edit', [ $this, 'add_sizes_panel' ], 10, 2 );
    }

    /**
     * Add the image sizes panel to attachment fields.
     *
     * @param array<string,array<string,mixed>> $form_fields Existing fields.
     * @param \WP_Post                          $post        Attachment post.
     * @return array<string,array<string,mixed>>
     */
    public function add_sizes_panel( array $form_fields, \WP_Post $post ): array {
        if ( ! wp_attachment_is_image( $post->ID ) ) {
            return $form_fields;
        }

        $metadata = wp_get_attachment_metadata( $post->ID );
        if ( ! $metadata || empty( $metadata['sizes'] ) ) {
            return $form_fields;
        }

        $settings        = $this->get_settings();
        $show_copy       = ! empty( $settings['show_copy_button'] );
        $upload_dir      = wp_get_upload_dir();
        $base_dir        = trailingslashit( $upload_dir['basedir'] );
        $file_dir        = trailingslashit( dirname( $metadata['file'] ) );
        $html = '<div class="wpt-image-sizes-panel">';
        $html .= '<table class="widefat striped" style="margin-top:6px;">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__( 'Size', 'wptransformed' ) . '</th>';
        $html .= '<th>' . esc_html__( 'Dimensions', 'wptransformed' ) . '</th>';
        $html .= '<th>' . esc_html__( 'File Size', 'wptransformed' ) . '</th>';
        $html .= '<th>' . esc_html__( 'URL', 'wptransformed' ) . '</th>';
        if ( $show_copy ) {
            $html .= '<th></th>';
        }
        $html .= '</tr></thead><tbody>';

        // Full size first.
        $full_src = wp_get_attachment_image_src( $post->ID, 'full' );
        if ( $full_src ) {
            $full_path = $base_dir . $metadata['file'];
            $full_file_size = file_exists( $full_path ) ? size_format( (int) filesize( $full_path ) ) : '—';
            $html .= '<tr>';
            $html .= '<td><strong>' . esc_html__( 'Full', 'wptransformed' ) . '</strong></td>';
            $html .= '<td>' . esc_html( $full_src[1] . ' × ' . $full_src[2] ) . '</td>';
            $html .= '<td>' . esc_html( $full_file_size ) . '</td>';
            $html .= '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                    . '<code style="font-size:11px;">' . esc_html( $full_src[0] ) . '</code></td>';
            if ( $show_copy ) {
                $html .= '<td><button type="button" class="button button-small wpt-copy-url" data-url="'
                        . esc_attr( $full_src[0] ) . '">' . esc_html__( 'Copy', 'wptransformed' ) . '</button></td>';
            }
            $html .= '</tr>';
        }

        // Each registered size.
        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            $src = wp_get_attachment_image_src( $post->ID, $size_name );
            if ( ! $src ) {
                continue;
            }

            $file_path = $base_dir . $file_dir . $size_data['file'];
            $file_size = file_exists( $file_path ) ? size_format( (int) filesize( $file_path ) ) : '—';

            $html .= '<tr>';
            $html .= '<td>' . esc_html( $size_name ) . '</td>';
            $html .= '<td>' . esc_html( $size_data['width'] . ' × ' . $size_data['height'] ) . '</td>';
            $html .= '<td>' . esc_html( $file_size ) . '</td>';
            $html .= '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                    . '<code style="font-size:11px;">' . esc_html( $src[0] ) . '</code></td>';
            if ( $show_copy ) {
                $html .= '<td><button type="button" class="button button-small wpt-copy-url" data-url="'
                        . esc_attr( $src[0] ) . '">' . esc_html__( 'Copy', 'wptransformed' ) . '</button></td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        if ( $show_copy ) {
            $html .= '<script>'
                    . 'if(!window._wptImageSizesCopyInit){'
                    . 'window._wptImageSizesCopyInit=true;'
                    . 'document.addEventListener("click",function(e){'
                    . 'var b=e.target.closest(".wpt-copy-url");'
                    . 'if(!b)return;'
                    . 'e.preventDefault();'
                    . 'var u=b.getAttribute("data-url");'
                    . 'if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){'
                    . 'b.textContent="' . esc_js( __( 'Copied!', 'wptransformed' ) ) . '";'
                    . 'setTimeout(function(){b.textContent="' . esc_js( __( 'Copy', 'wptransformed' ) ) . '";},1500);'
                    . '});}'
                    . '});'
                    . '}'
                    . '</script>';
        }

        $html .= '</div>';

        $form_fields['wpt_image_sizes'] = [
            'label' => __( 'Image Sizes', 'wptransformed' ),
            'input' => 'html',
            'html'  => $html,
        ];

        return $form_fields;
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Show image sizes panel on attachment edit screens', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Copy Button', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_copy_button" value="1"
                               <?php checked( ! empty( $settings['show_copy_button'] ) ); ?>>
                        <?php esc_html_e( 'Show copy-to-clipboard button for each URL', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'          => ! empty( $raw['wpt_enabled'] ),
            'show_copy_button' => ! empty( $raw['wpt_show_copy_button'] ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
