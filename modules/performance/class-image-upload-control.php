<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Image Upload Control -- Automatically resize, compress, and optimize images on upload.
 *
 * Features:
 *  - Max dimension enforcement (resize oversized uploads)
 *  - JPEG quality control
 *  - Optional WebP conversion (creates WebP copy alongside original)
 *  - EXIF stripping via image editor re-save
 *  - Skip GIFs (preserves animation) and SVGs
 *  - Bulk optimization AJAX handler (batches of 10)
 *
 * @package WPTransformed
 */
class Image_Upload_Control extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'image-upload-control';
    }

    public function get_title(): string {
        return __( 'Image Upload Control', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Automatically resize, compress, and optimize images on upload to reduce storage and improve performance.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'max_width'        => 2560,
            'max_height'       => 2560,
            'jpeg_quality'     => 82,
            'convert_to_webp'  => false,
            'strip_exif'       => true,
            'exclude_original' => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Resize oversized images after upload.
        add_filter( 'wp_handle_upload', [ $this, 'handle_upload' ] );

        // JPEG quality filter.
        add_filter( 'jpeg_quality', [ $this, 'filter_jpeg_quality' ] );
        add_filter( 'wp_editor_set_quality', [ $this, 'filter_jpeg_quality' ] );

        // WebP conversion after thumbnail generation.
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'after_thumbnail_generation' ], 10, 2 );

        // Bulk optimization AJAX handler.
        add_action( 'wp_ajax_wpt_bulk_optimize_images', [ $this, 'ajax_bulk_optimize' ] );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * After upload, check dimensions and resize if oversized.
     *
     * @param array<string,string> $upload Upload data with 'file', 'url', 'type'.
     * @return array<string,string>
     */
    public function handle_upload( array $upload ): array {
        // Skip on error.
        if ( isset( $upload['error'] ) && $upload['error'] ) {
            return $upload;
        }

        $file = $upload['file'] ?? '';
        $type = $upload['type'] ?? '';

        // Skip non-image types, GIFs, and SVGs.
        if ( ! $this->is_processable_image( $type ) ) {
            return $upload;
        }

        if ( ! file_exists( $file ) ) {
            return $upload;
        }

        $settings  = $this->get_settings();
        $max_w     = (int) $settings['max_width'];
        $max_h     = (int) $settings['max_height'];
        $strip     = ! empty( $settings['strip_exif'] );

        // Get current dimensions.
        $size = wp_getimagesize( $file );
        if ( ! $size ) {
            return $upload;
        }

        $orig_w = (int) $size[0];
        $orig_h = (int) $size[1];

        $needs_resize = ( $orig_w > $max_w || $orig_h > $max_h );

        // If no resize needed and no EXIF stripping, nothing to do.
        if ( ! $needs_resize && ! $strip ) {
            return $upload;
        }

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            return $upload;
        }

        if ( $needs_resize ) {
            $resized = $editor->resize( $max_w, $max_h );
            if ( is_wp_error( $resized ) ) {
                return $upload;
            }
        }

        // Re-saving through the editor strips EXIF data automatically.
        $saved = $editor->save( $file );
        if ( is_wp_error( $saved ) ) {
            return $upload;
        }

        return $upload;
    }

    /**
     * Filter JPEG quality for all image operations.
     *
     * @param int $quality Current quality.
     * @return int
     */
    public function filter_jpeg_quality( int $quality ): int {
        $settings = $this->get_settings();
        return (int) $settings['jpeg_quality'];
    }

    /**
     * After thumbnail generation, optionally create WebP copies.
     *
     * @param array<string,mixed> $metadata Attachment metadata.
     * @param int                 $attachment_id Attachment ID.
     * @return array<string,mixed>
     */
    public function after_thumbnail_generation( array $metadata, int $attachment_id ): array {
        $settings = $this->get_settings();

        if ( empty( $settings['convert_to_webp'] ) ) {
            return $metadata;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return $metadata;
        }

        $type = get_post_mime_type( $attachment_id );

        // Skip non-processable images.
        if ( ! $this->is_processable_image( (string) $type ) ) {
            return $metadata;
        }

        // Create WebP for the main image.
        $this->create_webp_copy( $file );

        // Create WebP for each thumbnail size.
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $upload_dir = dirname( $file );

            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $thumb_path = $upload_dir . '/' . $size_data['file'];
                    if ( file_exists( $thumb_path ) ) {
                        $this->create_webp_copy( $thumb_path );
                    }
                }
            }
        }

        return $metadata;
    }

    // ── AJAX: Bulk Optimize ───────────────────────────────────

    /**
     * Bulk optimize existing images via AJAX.
     * Processes images in batches of 10.
     */
    public function ajax_bulk_optimize(): void {
        check_ajax_referer( 'wpt_bulk_optimize_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $batch  = 3; // Small batch to stay within WP Engine 60s timeout.

        $settings = $this->get_settings();
        $max_w    = (int) $settings['max_width'];
        $max_h    = (int) $settings['max_height'];

        // Query attachments.
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp' ],
            'post_status'    => 'inherit',
            'posts_per_page' => $batch,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];

        $attachment_ids = get_posts( $args );

        if ( empty( $attachment_ids ) ) {
            wp_send_json_success( [
                'done'      => true,
                'processed' => 0,
                'optimized' => 0,
                'message'   => __( 'All images have been processed.', 'wptransformed' ),
            ] );
        }

        $processed = 0;
        $optimized = 0;

        foreach ( $attachment_ids as $id ) {
            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                $processed++;
                continue;
            }

            $size = wp_getimagesize( $file );
            if ( ! $size ) {
                $processed++;
                continue;
            }

            $orig_w = (int) $size[0];
            $orig_h = (int) $size[1];

            $needs_resize = ( $orig_w > $max_w || $orig_h > $max_h );

            if ( $needs_resize || ! empty( $settings['strip_exif'] ) ) {
                $editor = wp_get_image_editor( $file );
                if ( ! is_wp_error( $editor ) ) {
                    if ( $needs_resize ) {
                        $resized = $editor->resize( $max_w, $max_h );
                        if ( is_wp_error( $resized ) ) {
                            $processed++;
                            continue;
                        }
                    }

                    $saved = $editor->save( $file );
                    if ( ! is_wp_error( $saved ) ) {
                        // Update metadata without full thumbnail regeneration (avoids WP Engine timeout).
                        $metadata = wp_get_attachment_metadata( $id );
                        if ( is_array( $metadata ) && isset( $saved['width'], $saved['height'] ) ) {
                            $metadata['width']  = $saved['width'];
                            $metadata['height'] = $saved['height'];
                            if ( isset( $saved['file'] ) ) {
                                $metadata['file'] = _wp_relative_upload_path( $saved['file'] );
                            }
                            wp_update_attachment_metadata( $id, $metadata );
                        }
                        $optimized++;
                    }
                }
            }

            // Create WebP copy if enabled.
            if ( ! empty( $settings['convert_to_webp'] ) ) {
                $this->create_webp_copy( $file );
            }

            $processed++;
        }

        // Count total for progress using efficient COUNT query.
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status = 'inherit'
             AND post_mime_type IN ('image/jpeg','image/png','image/webp')"
        );

        $new_offset = $offset + $batch;
        $done       = $new_offset >= $total;

        wp_send_json_success( [
            'done'      => $done,
            'processed' => $processed,
            'optimized' => $optimized,
            'offset'    => $new_offset,
            'total'     => $total,
            'message'   => sprintf(
                /* translators: 1: current progress, 2: total images */
                __( 'Processed %1$d of %2$d images...', 'wptransformed' ),
                min( $new_offset, $total ),
                $total
            ),
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Check if a MIME type is a processable image (not GIF, not SVG).
     *
     * @param string $mime_type The MIME type.
     * @return bool
     */
    private function is_processable_image( string $mime_type ): bool {
        $skip = [ 'image/gif', 'image/svg+xml' ];

        if ( in_array( $mime_type, $skip, true ) ) {
            return false;
        }

        return strpos( $mime_type, 'image/' ) === 0;
    }

    /**
     * Create a WebP copy of an image file.
     *
     * @param string $file_path Full path to the source image.
     * @return bool True on success.
     */
    private function create_webp_copy( string $file_path ): bool {
        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $info     = pathinfo( $file_path );
        $dir      = $info['dirname'] ?? '';
        $filename = $info['filename'] ?? '';

        if ( empty( $dir ) || empty( $filename ) ) {
            return false;
        }

        $webp_path = $dir . '/' . $filename . '.webp';

        // Skip if WebP already exists and is newer than source.
        if ( file_exists( $webp_path ) && filemtime( $webp_path ) >= filemtime( $file_path ) ) {
            return true;
        }

        $saved = $editor->save( $webp_path, 'image/webp' );

        return ! is_wp_error( $saved );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>

        <div class="wpt-iuc-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Control how images are processed when uploaded to the Media Library. Oversized images are automatically resized, EXIF data can be stripped for privacy, and JPEG quality can be adjusted to reduce file size.', 'wptransformed' ); ?>
            </p>
        </div>

        <table class="form-table wpt-iuc-settings" role="presentation">
            <!-- Max Width -->
            <tr>
                <th scope="row">
                    <label for="wpt-max-width"><?php esc_html_e( 'Max Width (px)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-max-width" name="wpt_max_width"
                           value="<?php echo esc_attr( (string) $settings['max_width'] ); ?>"
                           class="small-text"
                           min="100" max="10000" step="1">
                    <p class="description">
                        <?php esc_html_e( 'Images wider than this will be proportionally resized. Default: 2560px.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Max Height -->
            <tr>
                <th scope="row">
                    <label for="wpt-max-height"><?php esc_html_e( 'Max Height (px)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-max-height" name="wpt_max_height"
                           value="<?php echo esc_attr( (string) $settings['max_height'] ); ?>"
                           class="small-text"
                           min="100" max="10000" step="1">
                    <p class="description">
                        <?php esc_html_e( 'Images taller than this will be proportionally resized. Default: 2560px.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- JPEG Quality -->
            <tr>
                <th scope="row">
                    <label for="wpt-jpeg-quality"><?php esc_html_e( 'JPEG Quality', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-jpeg-quality" name="wpt_jpeg_quality"
                           value="<?php echo esc_attr( (string) $settings['jpeg_quality'] ); ?>"
                           class="small-text"
                           min="1" max="100" step="1">
                    <p class="description">
                        <?php esc_html_e( 'JPEG compression quality (1-100). Lower = smaller files. Default: 82.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Convert to WebP -->
            <tr>
                <th scope="row"><?php esc_html_e( 'WebP Conversion', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_convert_to_webp" value="1"
                               <?php checked( ! empty( $settings['convert_to_webp'] ) ); ?>>
                        <?php esc_html_e( 'Create WebP copies alongside uploaded images', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'A .webp version will be generated for each image and its thumbnails. Requires server WebP support.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Strip EXIF -->
            <tr>
                <th scope="row"><?php esc_html_e( 'EXIF Data', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_strip_exif" value="1"
                               <?php checked( ! empty( $settings['strip_exif'] ) ); ?>>
                        <?php esc_html_e( 'Strip EXIF metadata from uploaded images', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Removes camera info, GPS coordinates, and other metadata for privacy and smaller files.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Exclude Original -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Original Image', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_exclude_original" value="1"
                               <?php checked( ! empty( $settings['exclude_original'] ) ); ?>>
                        <?php esc_html_e( 'Do not keep the original unmodified image', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'WordPress 5.3+ saves the original full-size image as a backup. Enable this to skip that backup and save disk space.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <!-- Bulk Optimization Section -->
        <div class="wpt-bulk-optimize-section">
            <h3><?php esc_html_e( 'Bulk Optimize Existing Images', 'wptransformed' ); ?></h3>
            <p class="description" style="margin-bottom: 10px;">
                <?php esc_html_e( 'Apply the current settings to all existing images in the Media Library. Images are processed in batches of 10.', 'wptransformed' ); ?>
            </p>
            <div class="wpt-bulk-optimize-controls">
                <button type="button" class="button button-secondary" id="wpt-bulk-optimize-start">
                    <?php esc_html_e( 'Start Bulk Optimization', 'wptransformed' ); ?>
                </button>
                <button type="button" class="button button-link-delete" id="wpt-bulk-optimize-stop" style="display: none;">
                    <?php esc_html_e( 'Stop', 'wptransformed' ); ?>
                </button>
                <span class="spinner" id="wpt-bulk-spinner" style="float: none;"></span>
            </div>
            <div id="wpt-bulk-progress" style="display: none; margin-top: 12px;">
                <div class="wpt-progress-bar-wrapper">
                    <div class="wpt-progress-bar" id="wpt-progress-bar" style="width: 0%;"></div>
                </div>
                <p id="wpt-bulk-status" class="description" style="margin-top: 8px;"></p>
            </div>
            <div id="wpt-bulk-result" style="display: none; margin-top: 10px;"></div>
        </div>

        <?php
        // Hidden nonce for bulk optimize.
        wp_nonce_field( 'wpt_bulk_optimize_nonce', 'wpt_bulk_optimize_nonce_field', false );
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // Max width.
        $max_width = isset( $raw['wpt_max_width'] ) ? absint( $raw['wpt_max_width'] ) : 2560;
        if ( $max_width < 100 || $max_width > 10000 ) {
            $max_width = 2560;
        }

        // Max height.
        $max_height = isset( $raw['wpt_max_height'] ) ? absint( $raw['wpt_max_height'] ) : 2560;
        if ( $max_height < 100 || $max_height > 10000 ) {
            $max_height = 2560;
        }

        // JPEG quality.
        $jpeg_quality = isset( $raw['wpt_jpeg_quality'] ) ? absint( $raw['wpt_jpeg_quality'] ) : 82;
        if ( $jpeg_quality < 1 || $jpeg_quality > 100 ) {
            $jpeg_quality = 82;
        }

        // Checkboxes.
        $convert_to_webp  = ! empty( $raw['wpt_convert_to_webp'] );
        $strip_exif       = ! empty( $raw['wpt_strip_exif'] );
        $exclude_original = ! empty( $raw['wpt_exclude_original'] );

        return [
            'max_width'        => $max_width,
            'max_height'       => $max_height,
            'jpeg_quality'     => $jpeg_quality,
            'convert_to_webp'  => $convert_to_webp,
            'strip_exif'       => $strip_exif,
            'exclude_original' => $exclude_original,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-image-upload-control',
            WPT_URL . 'modules/performance/css/image-upload-control.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-image-upload-control',
            WPT_URL . 'modules/performance/js/image-upload-control.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-image-upload-control', 'wptImageUploadControl', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_bulk_optimize_nonce' ),
            'i18n'    => [
                'starting'     => __( 'Starting optimization...', 'wptransformed' ),
                'processing'   => __( 'Processing...', 'wptransformed' ),
                'complete'     => __( 'Bulk optimization complete!', 'wptransformed' ),
                'stopped'      => __( 'Optimization stopped.', 'wptransformed' ),
                'error'        => __( 'An error occurred during optimization.', 'wptransformed' ),
                'networkError' => __( 'Network error. Please try again.', 'wptransformed' ),
                'start'        => __( 'Start Bulk Optimization', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
