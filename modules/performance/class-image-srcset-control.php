<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Image Srcset Control — Control or disable responsive image srcset
 * attributes to manage bandwidth and image sizes served to browsers.
 *
 * Options:
 *  - Disable srcset entirely
 *  - Set a max srcset width
 *  - Limit which image sizes appear in srcset
 *
 * @package WPTransformed
 */
class Image_Srcset_Control extends Module_Base {

    /** @var array|null Cached image sizes to avoid repeated lookups. */
    private ?array $cached_image_sizes = null;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'image-srcset-control';
    }

    public function get_title(): string {
        return __( 'Image Srcset Control', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Control responsive image srcset output to manage bandwidth and image delivery.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'disable_srcset'    => false,
            'max_srcset_width'  => 2048,
            'limit_sizes'       => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( ! empty( $settings['disable_srcset'] ) ) {
            // Disable srcset entirely by returning false.
            add_filter( 'wp_calculate_image_srcset', '__return_false' );
            return;
        }

        // Max srcset width.
        $max_width = (int) ( $settings['max_srcset_width'] ?? 2048 );
        if ( $max_width > 0 && $max_width !== 2048 ) {
            add_filter( 'max_srcset_image_width', function () use ( $max_width ): int {
                return $max_width;
            } );
        }

        // Limit specific sizes from srcset.
        $limit_sizes = (array) ( $settings['limit_sizes'] ?? [] );
        if ( ! empty( $limit_sizes ) ) {
            add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_srcset_sizes' ], 10, 5 );
        }
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Remove unwanted image sizes from the srcset array.
     *
     * @param array  $sources       Array of image sources with width as key.
     * @param array  $size_array    Requested image size [width, height].
     * @param string $image_src     Source URL of the image.
     * @param array  $image_meta    Image attachment metadata.
     * @param int    $attachment_id Attachment ID.
     * @return array Filtered sources.
     */
    public function filter_srcset_sizes( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
        $settings    = $this->get_settings();
        $limit_sizes = (array) ( $settings['limit_sizes'] ?? [] );

        if ( empty( $limit_sizes ) ) {
            return $sources;
        }

        // Build a list of widths to exclude based on registered image sizes.
        $excluded_widths = $this->get_excluded_widths( $limit_sizes );

        foreach ( $sources as $width => $source ) {
            if ( in_array( (int) $width, $excluded_widths, true ) ) {
                unset( $sources[ $width ] );
            }
        }

        return $sources;
    }

    /**
     * Get widths of image sizes that should be excluded from srcset.
     *
     * @param array $excluded_size_names Size names to exclude.
     * @return array<int> Array of widths.
     */
    private function get_excluded_widths( array $excluded_size_names ): array {
        $all_sizes = $this->get_all_image_sizes();
        $widths    = [];

        foreach ( $excluded_size_names as $name ) {
            if ( isset( $all_sizes[ $name ]['width'] ) ) {
                $widths[] = (int) $all_sizes[ $name ]['width'];
            }
        }

        return $widths;
    }

    /**
     * Get all registered image sizes with their dimensions.
     *
     * @return array<string,array{width:int,height:int,crop:bool}>
     */
    private function get_all_image_sizes(): array {
        if ( $this->cached_image_sizes !== null ) {
            return $this->cached_image_sizes;
        }

        global $_wp_additional_image_sizes;

        $sizes = [];

        foreach ( get_intermediate_image_sizes() as $size ) {
            if ( in_array( $size, [ 'thumbnail', 'medium', 'medium_large', 'large' ], true ) ) {
                $sizes[ $size ] = [
                    'width'  => (int) get_option( $size . '_size_w' ),
                    'height' => (int) get_option( $size . '_size_h' ),
                    'crop'   => (bool) get_option( $size . '_crop' ),
                ];
            } elseif ( isset( $_wp_additional_image_sizes[ $size ] ) ) {
                $sizes[ $size ] = [
                    'width'  => (int) $_wp_additional_image_sizes[ $size ]['width'],
                    'height' => (int) $_wp_additional_image_sizes[ $size ]['height'],
                    'crop'   => (bool) $_wp_additional_image_sizes[ $size ]['crop'],
                ];
            }
        }

        $this->cached_image_sizes = $sizes;
        return $sizes;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings   = $this->get_settings();
        $all_sizes  = $this->get_all_image_sizes();
        ?>

        <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Control how WordPress generates responsive image srcset attributes. Reducing srcset output can save bandwidth but may affect image quality on some devices.', 'wptransformed' ); ?>
            </p>
        </div>

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Srcset', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_disable_srcset" value="1" <?php checked( $settings['disable_srcset'] ); ?>>
                        <?php esc_html_e( 'Completely disable responsive image srcset attributes', 'wptransformed' ); ?>
                    </label>
                    <div style="background: #fcf9e8; border-left: 4px solid #dba617; padding: 8px 12px; margin-top: 8px;">
                        <p style="margin: 0;">
                            <strong><?php esc_html_e( 'Warning:', 'wptransformed' ); ?></strong>
                            <?php esc_html_e( 'Disabling srcset means browsers will always load the full-size image. This increases bandwidth usage and may slow page load times, especially on mobile devices.', 'wptransformed' ); ?>
                        </p>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_max_srcset_width"><?php esc_html_e( 'Max Srcset Width', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="wpt_max_srcset_width"
                           name="wpt_max_srcset_width"
                           value="<?php echo esc_attr( (string) $settings['max_srcset_width'] ); ?>"
                           min="0"
                           max="9999"
                           step="1"
                           class="small-text">
                    <span><?php esc_html_e( 'pixels', 'wptransformed' ); ?></span>
                    <p class="description">
                        <?php esc_html_e( 'Maximum width of images included in srcset. WordPress default is 2048px. Set to 0 to use the default. Ignored when srcset is disabled.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <?php if ( ! empty( $all_sizes ) ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Exclude Sizes from Srcset', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $all_sizes as $name => $size_data ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_limit_sizes[]"
                                       value="<?php echo esc_attr( $name ); ?>"
                                       <?php checked( in_array( $name, (array) $settings['limit_sizes'], true ) ); ?>>
                                <?php
                                echo esc_html( sprintf(
                                    '%s (%d x %d)',
                                    $name,
                                    $size_data['width'],
                                    $size_data['height']
                                ) );
                                ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Checked sizes will be excluded from srcset output. Ignored when srcset is fully disabled.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            <?php endif; ?>

        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $max_width = isset( $raw['wpt_max_srcset_width'] ) ? absint( $raw['wpt_max_srcset_width'] ) : 2048;
        if ( $max_width > 9999 ) {
            $max_width = 2048;
        }

        // Validate limit_sizes against registered sizes.
        $registered = array_keys( $this->get_all_image_sizes() );
        $limit      = isset( $raw['wpt_limit_sizes'] ) && is_array( $raw['wpt_limit_sizes'] )
            ? array_intersect( array_map( 'sanitize_key', $raw['wpt_limit_sizes'] ), $registered )
            : [];

        return [
            'disable_srcset'   => ! empty( $raw['wpt_disable_srcset'] ),
            'max_srcset_width' => $max_width,
            'limit_sizes'      => array_values( $limit ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom CSS or JS — pure server-rendered settings UI.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
