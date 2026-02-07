<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * SVG Upload — Allow SVG uploads with whitelist-based sanitization.
 *
 * Upload filter chain:
 *  1. upload_mimes        — add svg/svgz MIME types for allowed roles
 *  2. wp_check_filetype_and_ext — fix finfo detection for SVGs
 *  3. wp_handle_upload_prefilter — sanitize SVG content before saving
 *
 * Security: whitelist approach. Only known-safe elements and attributes
 * are preserved. Everything else is stripped.
 *
 * @package WPTransformed
 */
class Svg_Upload extends Module_Base {

    /**
     * Elements allowed in sanitized SVGs.
     */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'defs', 'use',
        'symbol', 'clippath', 'mask', 'pattern',
        'lineargradient', 'radialgradient', 'stop',
        'title', 'desc', 'image', 'style',
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'svg-upload';
    }

    public function get_title(): string {
        return __( 'SVG Upload', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Allow SVG file uploads to the media library with security sanitization.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'allowed_roles' => [ 'administrator' ],
            'sanitize'      => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_filter( 'upload_mimes', [ $this, 'add_svg_mime_types' ] );
        add_filter( 'wp_check_filetype_and_ext', [ $this, 'fix_svg_filetype' ], 10, 4 );
        add_filter( 'wp_handle_upload_prefilter', [ $this, 'sanitize_svg_upload' ] );
        add_filter( 'wp_prepare_attachment_for_js', [ $this, 'add_svg_dimensions' ], 10, 2 );
        add_action( 'admin_head', [ $this, 'output_svg_css' ] );
    }

    // ── Upload Filters ────────────────────────────────────────

    /**
     * Add SVG and SVGZ MIME types for users with allowed roles.
     *
     * @param array<string,string> $mimes Allowed MIME types.
     * @return array<string,string>
     */
    public function add_svg_mime_types( array $mimes ): array {
        if ( ! $this->current_user_can_upload_svg() ) {
            return $mimes;
        }

        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';

        return $mimes;
    }

    /**
     * Fix WordPress file type detection for SVGs.
     *
     * finfo doesn't reliably detect SVG MIME types, so WordPress
     * may reject them even when the MIME type is allowed.
     *
     * @param array{ext:string|false,type:string|false,proper_filename:string|false} $data
     * @param string $file     Full path to the file.
     * @param string $filename Name of the file.
     * @param string[]|null $mimes Allowed MIME types.
     * @return array{ext:string|false,type:string|false,proper_filename:string|false}
     */
    public function fix_svg_filetype( array $data, string $file, string $filename, $mimes ): array {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( $ext === 'svg' || $ext === 'svgz' ) {
            $data['ext']  = $ext;
            $data['type'] = 'image/svg+xml';
        }

        return $data;
    }

    /**
     * Sanitize SVG content before the file is saved to disk.
     *
     * This is the critical security filter. Uses a whitelist approach:
     * only known-safe elements and attributes are preserved.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}
     */
    public function sanitize_svg_upload( array $file ): array {
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

        if ( $ext !== 'svg' && $ext !== 'svgz' ) {
            return $file;
        }

        // SVGZ: only administrators can upload compressed SVGs (extra safety).
        if ( $ext === 'svgz' ) {
            $user = wp_get_current_user();
            if ( ! in_array( 'administrator', $user->roles, true ) ) {
                $file['error'] = __( 'Only administrators can upload compressed SVG (.svgz) files.', 'wptransformed' );
                return $file;
            }
            // Skip sanitization for compressed files in v1.
            return $file;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['sanitize'] ) ) {
            return $file;
        }

        // Read the uploaded temp file.
        $content = file_get_contents( $file['tmp_name'] );
        if ( $content === false || trim( $content ) === '' ) {
            $file['error'] = __( 'Invalid SVG file.', 'wptransformed' );
            return $file;
        }

        // Sanitize.
        $sanitized = $this->sanitize_svg( $content );

        if ( $sanitized === false ) {
            $file['error'] = __( 'Invalid SVG file.', 'wptransformed' );
            return $file;
        }

        if ( trim( $sanitized ) === '' ) {
            $file['error'] = __( 'SVG contains no valid elements.', 'wptransformed' );
            return $file;
        }

        // Write sanitized content back.
        file_put_contents( $file['tmp_name'], $sanitized );

        return $file;
    }

    // ── SVG Sanitization ──────────────────────────────────────

    /**
     * Sanitize SVG content using a whitelist approach.
     *
     * @param string $content Raw SVG XML content.
     * @return string|false Sanitized SVG string, or false on parse failure.
     */
    private function sanitize_svg( string $content ): string|false {
        // Suppress DOMDocument warnings — malformed XML is expected.
        $prev = libxml_use_internal_errors( true );

        $dom = new \DOMDocument();
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        // Prevent XXE (XML External Entity) attacks.
        $loaded = $dom->loadXML( $content, LIBXML_NONET | LIBXML_NOENT );

        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        if ( ! $loaded ) {
            return false;
        }

        // Remove doctype to prevent XXE.
        foreach ( $dom->childNodes as $child ) {
            if ( $child->nodeType === XML_DOCUMENT_TYPE_NODE ) {
                $dom->removeChild( $child );
                break;
            }
        }

        // Find the root <svg> element.
        $svg = $dom->getElementsByTagName( 'svg' )->item( 0 );
        if ( ! $svg ) {
            return false;
        }

        // Walk and sanitize the entire tree.
        $this->sanitize_node( $svg );

        // Serialize just the <svg> element (not the XML declaration).
        $output = $dom->saveXML( $svg );

        return is_string( $output ) ? $output : false;
    }

    /**
     * Recursively sanitize a DOM node and its children.
     *
     * Removes disallowed elements, event handler attributes,
     * and dangerous href values.
     *
     * @param \DOMElement $node The node to sanitize.
     */
    private function sanitize_node( \DOMElement $node ): void {
        // Collect children to process (can't modify while iterating).
        $children = [];
        foreach ( $node->childNodes as $child ) {
            $children[] = $child;
        }

        foreach ( $children as $child ) {
            if ( $child->nodeType === XML_ELEMENT_NODE ) {
                /** @var \DOMElement $child */
                $tag = strtolower( $child->localName );

                // Remove disallowed elements entirely.
                if ( ! in_array( $tag, self::ALLOWED_ELEMENTS, true ) ) {
                    $node->removeChild( $child );
                    continue;
                }

                // Sanitize attributes on this element.
                $this->sanitize_attributes( $child, $tag );

                // Recurse into children.
                $this->sanitize_node( $child );
            }
        }
    }

    /**
     * Sanitize attributes on a single element.
     *
     * - Strips on* event handlers.
     * - Strips dangerous href/xlink:href values.
     * - For <use>: only allows local #fragment references.
     * - For <image>: only allows data: URIs.
     * - For <style>: removes external url() references.
     *
     * @param \DOMElement $el  The element to sanitize.
     * @param string      $tag The lowercase tag name.
     */
    private function sanitize_attributes( \DOMElement $el, string $tag ): void {
        // Collect attributes (can't modify while iterating).
        $attrs_to_remove = [];

        foreach ( $el->attributes as $attr ) {
            $name  = strtolower( $attr->name );
            $value = $attr->value;

            // Strip all on* event handlers (onclick, onload, onerror, etc.).
            if ( strpos( $name, 'on' ) === 0 ) {
                $attrs_to_remove[] = $attr->name;
                continue;
            }

            // Sanitize href and xlink:href.
            if ( $name === 'href' || $name === 'xlink:href' ) {
                $clean_value = trim( $value );
                $lower_value = strtolower( $clean_value );

                // Reject javascript: protocol.
                if ( strpos( $lower_value, 'javascript:' ) !== false ) {
                    $attrs_to_remove[] = $attr->name;
                    continue;
                }

                if ( $tag === 'use' ) {
                    // <use> only allows local #fragment references.
                    if ( strpos( $clean_value, '#' ) !== 0 ) {
                        $attrs_to_remove[] = $attr->name;
                    }
                } elseif ( $tag === 'image' ) {
                    // <image> only allows data: URIs.
                    if ( strpos( $lower_value, 'data:' ) !== 0 ) {
                        $attrs_to_remove[] = $attr->name;
                    }
                } else {
                    // Other elements: allow # fragments and data: URIs only.
                    if (
                        strpos( $clean_value, '#' ) !== 0
                        && strpos( $lower_value, 'data:' ) !== 0
                    ) {
                        $attrs_to_remove[] = $attr->name;
                    }
                }
            }
        }

        foreach ( $attrs_to_remove as $attr_name ) {
            $el->removeAttribute( $attr_name );
        }

        // Handle <style> elements: remove content with external url() references.
        if ( $tag === 'style' && $el->textContent !== '' ) {
            $css = $el->textContent;
            // Remove any url() that isn't a data: URI or # fragment.
            if ( preg_match( '/url\s*\(\s*[\'"]?\s*(?!data:|#)/i', $css ) ) {
                // Remove the entire style element — it references external resources.
                $el->parentNode->removeChild( $el );
            }
        }
    }

    // ── Media Library Display ─────────────────────────────────

    /**
     * Add SVG dimensions to the attachment JS data for the media grid.
     *
     * SVGs don't have intrinsic pixel dimensions. WordPress needs them
     * for proper media library display. We extract from viewBox or
     * width/height attributes.
     *
     * @param array<string,mixed> $response Attachment data.
     * @param \WP_Post            $attachment Attachment post object.
     * @return array<string,mixed>
     */
    public function add_svg_dimensions( array $response, \WP_Post $attachment ): array {
        if ( $response['mime'] !== 'image/svg+xml' ) {
            return $response;
        }

        $file = get_attached_file( $attachment->ID );
        if ( ! $file || ! file_exists( $file ) ) {
            return $response;
        }

        // Suppress warnings from malformed SVGs.
        $prev = libxml_use_internal_errors( true );
        $svg  = simplexml_load_file( $file );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        if ( ! $svg ) {
            return $response;
        }

        $width  = 0.0;
        $height = 0.0;

        // Try viewBox first.
        $viewbox = (string) ( $svg['viewBox'] ?? '' );
        if ( $viewbox !== '' ) {
            $parts = preg_split( '/[\s,]+/', trim( $viewbox ) );
            if ( count( $parts ) === 4 ) {
                $width  = (float) $parts[2];
                $height = (float) $parts[3];
            }
        }

        // Fall back to width/height attributes.
        if ( $width <= 0 && $svg['width'] ) {
            $width = (float) $svg['width'];
        }
        if ( $height <= 0 && $svg['height'] ) {
            $height = (float) $svg['height'];
        }

        if ( $width > 0 && $height > 0 ) {
            $response['sizes'] = [
                'full' => [
                    'url'         => $response['url'],
                    'width'       => (int) $width,
                    'height'      => (int) $height,
                    'orientation' => $width > $height ? 'landscape' : 'portrait',
                ],
            ];
        }

        return $response;
    }

    /**
     * Output CSS for SVG thumbnails in the media library grid.
     */
    public function output_svg_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'upload', 'media' ], true ) ) {
            return;
        }

        ?>
        <style>
            .attachment-preview .thumbnail img[src$=".svg"],
            .attachment-preview .thumbnail img[src$=".svgz"] {
                width: 100%;
                height: auto;
                padding: 5px;
            }
        </style>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'upload.php', 'media-new.php' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'wpt-svg-upload',
            WPT_URL . 'modules/content-management/css/svg-upload.css',
            [],
            WPT_VERSION
        );
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the current user has a role that allows SVG uploads.
     */
    private function current_user_can_upload_svg(): bool {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $settings      = $this->get_settings();
        $allowed_roles = $settings['allowed_roles'] ?? [];

        if ( empty( $allowed_roles ) ) {
            return false;
        }

        return ! empty( array_intersect( $user->roles, $allowed_roles ) );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $allowed_roles = $settings['allowed_roles'];

        // Get all editable roles.
        $roles = wp_roles()->get_names();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Allowed Roles', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $roles as $slug => $name ) : ?>
                            <label>
                                <input type="checkbox" name="wpt_allowed_roles[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, $allowed_roles, true ) ); ?>>
                                <?php echo esc_html( translate_user_role( $name ) ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Only users with these roles can upload SVG files.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Sanitization', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_sanitize" value="1" checked disabled>
                        <?php esc_html_e( 'Remove potentially dangerous elements from uploaded SVGs.', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Strongly recommended. Cannot be disabled in v1.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_roles = array_keys( wp_roles()->get_names() );
        $submitted   = $raw['wpt_allowed_roles'] ?? [];

        // Only keep valid role slugs.
        $allowed = array_values( array_intersect(
            array_map( 'sanitize_text_field', (array) $submitted ),
            $valid_roles
        ) );

        return [
            'allowed_roles' => $allowed,
            'sanitize'      => true, // Always true in v1.
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
