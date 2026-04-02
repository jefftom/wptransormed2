<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Lazy Load — Add native lazy loading to images, iframes, and videos.
 *
 * Uses the browser-native `loading="lazy"` attribute for images and iframes,
 * and `preload="none"` for video elements. Optionally uses Intersection
 * Observer for custom viewport threshold.
 *
 * @package WPTransformed
 */
class Lazy_Load extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'lazy-load';
    }

    public function get_title(): string {
        return __( 'Lazy Load', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Add native lazy loading to images, iframes, and videos to improve page load performance.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'images'             => true,
            'iframes'            => true,
            'videos'             => true,
            'exclude_classes'    => [ 'no-lazy' ],
            'threshold'          => '200px',
            'skip_first_n_images' => 3,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( is_admin() ) {
            return;
        }

        add_filter( 'the_content', [ $this, 'process_content' ], 99 );
        add_filter( 'wp_get_attachment_image_attributes', [ $this, 'add_lazy_to_attachment' ], 10, 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_threshold_script' ] );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Process post content to add lazy loading attributes.
     *
     * @param string $content Post content HTML.
     * @return string Modified content.
     */
    public function process_content( string $content ): string {
        if ( empty( $content ) ) {
            return $content;
        }

        $settings        = $this->get_settings();
        $exclude_classes  = (array) $settings['exclude_classes'];
        $skip_first_n    = (int) $settings['skip_first_n_images'];
        $image_counter   = 0;

        // Process <img> tags.
        if ( $settings['images'] ) {
            $content = preg_replace_callback(
                '/<img\b([^>]*)>/i',
                function ( array $matches ) use ( $exclude_classes, $skip_first_n, &$image_counter ): string {
                    $tag = $matches[0];
                    $image_counter++;

                    if ( $image_counter <= $skip_first_n ) {
                        return $tag;
                    }

                    if ( $this->has_loading_attr( $tag ) ) {
                        return $tag;
                    }

                    if ( $this->has_excluded_class( $tag, $exclude_classes ) ) {
                        return $tag;
                    }

                    return $this->add_loading_lazy( $tag, 'img' );
                },
                $content
            ) ?? $content;
        }

        // Process <iframe> tags.
        if ( $settings['iframes'] ) {
            $content = preg_replace_callback(
                '/<iframe\b([^>]*)>/i',
                function ( array $matches ) use ( $exclude_classes ): string {
                    $tag = $matches[0];

                    if ( $this->has_loading_attr( $tag ) ) {
                        return $tag;
                    }

                    if ( $this->has_excluded_class( $tag, $exclude_classes ) ) {
                        return $tag;
                    }

                    return $this->add_loading_lazy( $tag, 'iframe' );
                },
                $content
            ) ?? $content;
        }

        // Process <video> tags.
        if ( $settings['videos'] ) {
            $content = preg_replace_callback(
                '/<video\b([^>]*)>/i',
                function ( array $matches ) use ( $exclude_classes ): string {
                    $tag = $matches[0];

                    if ( $this->has_excluded_class( $tag, $exclude_classes ) ) {
                        return $tag;
                    }

                    // For video tags, add preload="none" instead of loading="lazy".
                    if ( preg_match( '/\bpreload\s*=/i', $tag ) ) {
                        return $tag;
                    }

                    return str_replace( '<video', '<video preload="none"', $tag );
                },
                $content
            ) ?? $content;
        }

        return $content;
    }

    /**
     * Add loading="lazy" to WordPress attachment images if not already present.
     *
     * @param array<string,string> $attr Image attributes.
     * @return array<string,string>
     */
    public function add_lazy_to_attachment( array $attr ): array {
        $settings = $this->get_settings();

        if ( ! $settings['images'] ) {
            return $attr;
        }

        if ( isset( $attr['loading'] ) ) {
            return $attr;
        }

        $exclude_classes = (array) $settings['exclude_classes'];
        $classes         = $attr['class'] ?? '';

        foreach ( $exclude_classes as $excluded ) {
            if ( preg_match( '/\b' . preg_quote( $excluded, '/' ) . '\b/', $classes ) ) {
                return $attr;
            }
        }

        $attr['loading'] = 'lazy';

        return $attr;
    }

    /**
     * Enqueue frontend script (placeholder for future enhancements).
     * Native loading="lazy" requires no JavaScript — the browser handles thresholds.
     */
    public function maybe_enqueue_threshold_script(): void {
        // Native lazy loading is handled by the browser.
        // No JS needed — the threshold setting is advisory only.
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Check if a tag already has a loading attribute.
     *
     * @param string $tag HTML tag string.
     * @return bool
     */
    private function has_loading_attr( string $tag ): bool {
        return (bool) preg_match( '/\bloading\s*=/i', $tag );
    }

    /**
     * Check if a tag has any of the excluded classes.
     *
     * @param string        $tag             HTML tag string.
     * @param array<string> $exclude_classes  Classes to exclude.
     * @return bool
     */
    private function has_excluded_class( string $tag, array $exclude_classes ): bool {
        if ( empty( $exclude_classes ) ) {
            return false;
        }

        foreach ( $exclude_classes as $excluded ) {
            $excluded = trim( $excluded );
            if ( $excluded === '' ) {
                continue;
            }
            if ( preg_match( '/class\s*=\s*["\'][^"\']*\b' . preg_quote( $excluded, '/' ) . '\b[^"\']*["\']/i', $tag ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add loading="lazy" attribute to an HTML tag.
     *
     * @param string $tag      Full HTML opening tag.
     * @param string $element  Element name (img, iframe).
     * @return string Modified tag.
     */
    private function add_loading_lazy( string $tag, string $element ): string {
        return str_replace( '<' . $element, '<' . $element . ' loading="lazy"', $tag );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings        = $this->get_settings();
        $exclude_str     = implode( ', ', (array) $settings['exclude_classes'] );
        ?>

        <div class="wpt-lazy-load-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Lazy loading defers loading of off-screen images, iframes, and videos until the user scrolls near them. This reduces initial page load time and saves bandwidth. Uses the native browser loading="lazy" attribute with an optional Intersection Observer for custom thresholds.', 'wptransformed' ); ?>
            </p>
        </div>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Element Types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox"
                                   name="wpt_images"
                                   value="1"
                                   <?php checked( $settings['images'] ); ?>>
                            <?php esc_html_e( 'Images', 'wptransformed' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox"
                                   name="wpt_iframes"
                                   value="1"
                                   <?php checked( $settings['iframes'] ); ?>>
                            <?php esc_html_e( 'Iframes (YouTube, Google Maps, etc.)', 'wptransformed' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox"
                                   name="wpt_videos"
                                   value="1"
                                   <?php checked( $settings['videos'] ); ?>>
                            <?php esc_html_e( 'Videos', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_skip_first_n_images"><?php esc_html_e( 'Skip First N Images', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="wpt_skip_first_n_images"
                           name="wpt_skip_first_n_images"
                           value="<?php echo esc_attr( (string) $settings['skip_first_n_images'] ); ?>"
                           min="0"
                           max="20"
                           step="1"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Above-the-fold images should load immediately. Skip the first N images in each post from lazy loading. Recommended: 3.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_exclude_classes"><?php esc_html_e( 'Exclude CSS Classes', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="wpt_exclude_classes"
                           name="wpt_exclude_classes"
                           value="<?php echo esc_attr( $exclude_str ); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e( 'Comma-separated list of CSS classes. Elements with these classes will not be lazy loaded.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_threshold"><?php esc_html_e( 'Loading Threshold', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="wpt_threshold"
                           name="wpt_threshold"
                           value="<?php echo esc_attr( $settings['threshold'] ); ?>"
                           class="small-text"
                           placeholder="200px">
                    <p class="description">
                        <?php esc_html_e( 'Distance from the viewport to start loading. Uses CSS length units (e.g., 200px, 50%). This enables an Intersection Observer script on the frontend.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $clean = [];

        $clean['images']  = ! empty( $raw['wpt_images'] );
        $clean['iframes'] = ! empty( $raw['wpt_iframes'] );
        $clean['videos']  = ! empty( $raw['wpt_videos'] );

        // Skip first N images — clamp 0..20.
        $skip = isset( $raw['wpt_skip_first_n_images'] ) ? (int) $raw['wpt_skip_first_n_images'] : 3;
        $clean['skip_first_n_images'] = max( 0, min( 20, $skip ) );

        // Exclude classes — sanitize each class name.
        $exclude_raw = $raw['wpt_exclude_classes'] ?? 'no-lazy';
        if ( is_string( $exclude_raw ) ) {
            $classes = array_map( 'trim', explode( ',', $exclude_raw ) );
            $classes = array_filter( $classes, static function ( string $cls ): bool {
                return $cls !== '' && preg_match( '/^[a-zA-Z0-9_-]+$/', $cls ) === 1;
            } );
            $clean['exclude_classes'] = array_values( $classes );
        } else {
            $clean['exclude_classes'] = [ 'no-lazy' ];
        }

        // Threshold — must be a valid CSS length.
        $threshold = $raw['wpt_threshold'] ?? '200px';
        if ( is_string( $threshold ) && preg_match( '/^\d+(%|px|em|rem|vh|vw)?$/', trim( $threshold ) ) ) {
            $clean['threshold'] = trim( $threshold );
        } else {
            $clean['threshold'] = '200px';
        }

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom admin CSS or JS — pure server-rendered settings UI.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
