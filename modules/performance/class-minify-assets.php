<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Minify Assets — Minify and optionally combine CSS/JS assets.
 *
 * Features:
 *  - Intercept style/script tags and replace src with minified cached version
 *  - Regex-based minification (no external parser)
 *  - Cache files in wp-content/wpt-cache/ keyed by md5(path + mtime)
 *  - Skip already-minified files (.min.css, .min.js)
 *  - Skip inline scripts/styles and external CDN files
 *  - Optional file combining (off by default — risky)
 *  - Clear Cache AJAX action
 *  - Admin notice if cache dir is not writable
 *
 * @package WPTransformed
 */
class Minify_Assets extends Module_Base {

    /**
     * Resolved cache directory path (no trailing slash).
     *
     * @var string
     */
    private string $cache_dir = '';

    /**
     * Resolved cache directory URL (no trailing slash).
     *
     * @var string
     */
    private string $cache_url = '';

    /**
     * Whether the cache directory is writable.
     *
     * @var bool|null
     */
    private ?bool $cache_writable = null;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'minify-assets';
    }

    public function get_title(): string {
        return __( 'Minify Assets', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Minify CSS and JS assets to reduce file size. Optionally combine files to reduce HTTP requests.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'minify_css'       => true,
            'minify_js'        => true,
            'combine_css'      => false,
            'combine_js'       => false,
            'exclude_handles'  => [],
            'cache_dir'        => 'wpt-cache',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $this->resolve_cache_paths();

        // Show admin notice if cache directory is not writable.
        add_action( 'admin_notices', [ $this, 'maybe_show_cache_notice' ] );

        // Only hook into frontend output if cache dir is writable.
        if ( $this->is_cache_writable() ) {
            $settings = $this->get_settings();

            if ( ! empty( $settings['minify_css'] ) ) {
                if ( ! empty( $settings['combine_css'] ) ) {
                    add_action( 'wp_print_styles', [ $this, 'combine_styles' ], 9999 );
                } else {
                    add_filter( 'style_loader_tag', [ $this, 'filter_style_tag' ], 10, 4 );
                }
            }

            if ( ! empty( $settings['minify_js'] ) ) {
                if ( ! empty( $settings['combine_js'] ) ) {
                    add_action( 'wp_print_scripts', [ $this, 'combine_scripts' ], 9999 );
                } else {
                    add_filter( 'script_loader_tag', [ $this, 'filter_script_tag' ], 10, 3 );
                }
            }
        }

        // AJAX handler for clearing cache.
        add_action( 'wp_ajax_wpt_minify_clear_cache', [ $this, 'ajax_clear_cache' ] );
    }

    // ── Cache Path Resolution ─────────────────────────────────

    /**
     * Resolve the cache directory path and URL from settings.
     */
    private function resolve_cache_paths(): void {
        $settings        = $this->get_settings();
        $dir_name        = sanitize_file_name( $settings['cache_dir'] ?: 'wpt-cache' );
        $this->cache_dir = WP_CONTENT_DIR . '/' . $dir_name;
        $this->cache_url = content_url( $dir_name );
    }

    /**
     * Check (and optionally create) the cache directory.
     *
     * @return bool
     */
    private function is_cache_writable(): bool {
        if ( $this->cache_writable !== null ) {
            return $this->cache_writable;
        }

        if ( ! is_dir( $this->cache_dir ) ) {
            wp_mkdir_p( $this->cache_dir );
        }

        $this->cache_writable = is_dir( $this->cache_dir ) && wp_is_writable( $this->cache_dir );

        return $this->cache_writable;
    }

    /**
     * Show an admin notice when the cache directory is not writable.
     */
    public function maybe_show_cache_notice(): void {
        if ( $this->is_cache_writable() ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html(
            sprintf(
                /* translators: %s: cache directory path */
                __( 'WPTransformed Minify Assets: The cache directory "%s" is not writable. Minification is disabled until this is resolved.', 'wptransformed' ),
                $this->cache_dir
            )
        );
        echo '</p></div>';
    }

    // ── Style Tag Filter ──────────────────────────────────────

    /**
     * Filter individual style tags — replace src with minified version.
     *
     * @param string $tag    The full <link> tag HTML.
     * @param string $handle The registered handle name.
     * @param string $href   The stylesheet URL.
     * @param string $media  The media attribute value.
     * @return string
     */
    public function filter_style_tag( string $tag, string $handle, string $href, string $media ): string {
        if ( $this->should_skip( $handle, $href, 'css' ) ) {
            return $tag;
        }

        $local_path = $this->url_to_path( $href );
        if ( $local_path === '' || ! is_readable( $local_path ) ) {
            return $tag;
        }

        $minified_url = $this->get_minified_url( $local_path, 'css' );
        if ( $minified_url === '' ) {
            return $tag;
        }

        return str_replace( $href, $minified_url, $tag );
    }

    // ── Script Tag Filter ─────────────────────────────────────

    /**
     * Filter individual script tags — replace src with minified version.
     *
     * @param string $tag    The full <script> tag HTML.
     * @param string $handle The registered handle name.
     * @param string $src    The script URL.
     * @return string
     */
    public function filter_script_tag( string $tag, string $handle, string $src ): string {
        if ( $this->should_skip( $handle, $src, 'js' ) ) {
            return $tag;
        }

        $local_path = $this->url_to_path( $src );
        if ( $local_path === '' || ! is_readable( $local_path ) ) {
            return $tag;
        }

        $minified_url = $this->get_minified_url( $local_path, 'js' );
        if ( $minified_url === '' ) {
            return $tag;
        }

        return str_replace( $src, $minified_url, $tag );
    }

    // ── Combine Styles ────────────────────────────────────────

    /**
     * Combine all enqueued stylesheets into a single file.
     */
    public function combine_styles(): void {
        global $wp_styles;

        if ( empty( $wp_styles->queue ) ) {
            return;
        }

        $settings        = $this->get_settings();
        $exclude_handles = $settings['exclude_handles'] ?? [];
        $combined        = '';
        $handles_done    = [];

        foreach ( $wp_styles->queue as $handle ) {
            if ( in_array( $handle, $exclude_handles, true ) ) {
                continue;
            }

            if ( empty( $wp_styles->registered[ $handle ] ) ) {
                continue;
            }

            $obj = $wp_styles->registered[ $handle ];
            $src = $obj->src ?? '';

            if ( $src === '' || $this->is_external_url( $src ) ) {
                continue;
            }

            $local_path = $this->url_to_path( $src );
            if ( $local_path === '' || ! is_readable( $local_path ) ) {
                continue;
            }

            $content = (string) file_get_contents( $local_path );
            if ( ! $this->is_already_minified( $local_path ) ) {
                $content = $this->minify_css( $content );
            }

            $combined .= "/* handle: {$handle} */\n" . $content . "\n";
            $handles_done[] = $handle;
        }

        if ( $combined === '' || empty( $handles_done ) ) {
            return;
        }

        $hash      = md5( $combined );
        $filename  = $hash . '.combined.min.css';
        $file_path = $this->cache_dir . '/' . $filename;

        if ( ! file_exists( $file_path ) ) {
            file_put_contents( $file_path, $combined );
        }

        // Dequeue individual handles and enqueue the combined file.
        foreach ( $handles_done as $handle ) {
            wp_dequeue_style( $handle );
        }

        wp_enqueue_style(
            'wpt-combined-css',
            $this->cache_url . '/' . $filename,
            [],
            null
        );
    }

    // ── Combine Scripts ───────────────────────────────────────

    /**
     * Combine all enqueued scripts into a single file.
     */
    public function combine_scripts(): void {
        global $wp_scripts;

        if ( empty( $wp_scripts->queue ) ) {
            return;
        }

        $settings        = $this->get_settings();
        $exclude_handles = $settings['exclude_handles'] ?? [];
        $combined        = '';
        $handles_done    = [];

        foreach ( $wp_scripts->queue as $handle ) {
            if ( in_array( $handle, $exclude_handles, true ) ) {
                continue;
            }

            if ( empty( $wp_scripts->registered[ $handle ] ) ) {
                continue;
            }

            $obj = $wp_scripts->registered[ $handle ];
            $src = $obj->src ?? '';

            if ( $src === '' || $this->is_external_url( $src ) ) {
                continue;
            }

            $local_path = $this->url_to_path( $src );
            if ( $local_path === '' || ! is_readable( $local_path ) ) {
                continue;
            }

            $content = (string) file_get_contents( $local_path );
            if ( ! $this->is_already_minified( $local_path ) ) {
                $content = $this->minify_js( $content );
            }

            $combined .= "/* handle: {$handle} */\n" . $content . ";\n";
            $handles_done[] = $handle;
        }

        if ( $combined === '' || empty( $handles_done ) ) {
            return;
        }

        $hash      = md5( $combined );
        $filename  = $hash . '.combined.min.js';
        $file_path = $this->cache_dir . '/' . $filename;

        if ( ! file_exists( $file_path ) ) {
            file_put_contents( $file_path, $combined );
        }

        // Dequeue individual handles and enqueue the combined file.
        foreach ( $handles_done as $handle ) {
            wp_dequeue_script( $handle );
        }

        wp_enqueue_script(
            'wpt-combined-js',
            $this->cache_url . '/' . $filename,
            [],
            null,
            true
        );
    }

    // ── Minification Helpers ──────────────────────────────────

    /**
     * Get the URL for a minified version of a local file.
     *
     * Creates the minified file in the cache dir if it doesn't exist.
     *
     * @param string $local_path Absolute path to the source file.
     * @param string $type       'css' or 'js'.
     * @return string Minified file URL, or empty string on failure.
     */
    private function get_minified_url( string $local_path, string $type ): string {
        $mtime    = (int) filemtime( $local_path );
        $hash     = md5( $local_path . (string) $mtime );
        $filename = $hash . '.min.' . $type;
        $cached   = $this->cache_dir . '/' . $filename;

        if ( file_exists( $cached ) ) {
            return $this->cache_url . '/' . $filename;
        }

        $source = (string) file_get_contents( $local_path );
        if ( $source === '' ) {
            return '';
        }

        $minified = $type === 'css'
            ? $this->minify_css( $source )
            : $this->minify_js( $source );

        if ( $minified === '' ) {
            return '';
        }

        $written = file_put_contents( $cached, $minified );
        if ( $written === false ) {
            return '';
        }

        return $this->cache_url . '/' . $filename;
    }

    /**
     * Minify CSS content using regex.
     *
     * Removes comments, collapses whitespace, removes unnecessary whitespace
     * around selectors and properties.
     *
     * @param string $css Raw CSS content.
     * @return string Minified CSS.
     */
    private function minify_css( string $css ): string {
        // Remove multi-line comments.
        $css = preg_replace( '#/\*.*?\*/#s', '', $css );

        // Collapse whitespace (spaces, tabs, newlines) to single space.
        $css = preg_replace( '/\s+/', ' ', $css );

        // Remove spaces around structural characters.
        $css = preg_replace( '/\s*([{}:;,>~+])\s*/', '$1', $css );

        // Remove trailing semicolons before closing braces.
        $css = str_replace( ';}', '}', $css );

        return trim( $css );
    }

    /**
     * Minify JS content using regex.
     *
     * Conservative approach: removes comments but preserves strings
     * and avoids aggressive whitespace removal that could break code.
     *
     * @param string $js Raw JS content.
     * @return string Minified JS.
     */
    private function minify_js( string $js ): string {
        // Protect string literals and regex by replacing them with tokens.
        $strings = [];
        $index   = 0;

        // Replace string literals (single-quoted, double-quoted, template literals)
        // and regex literals with placeholders to protect them.
        $js = preg_replace_callback(
            '/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|`(?:[^`\\\\]|\\\\.)*`)/',
            static function ( array $match ) use ( &$strings, &$index ): string {
                $token = "\x00STRING_{$index}\x00";
                $strings[ $token ] = $match[0];
                $index++;
                return $token;
            },
            $js
        );

        if ( $js === null ) {
            // Regex failed — return original to avoid breaking the file.
            return $js ?? '';
        }

        // Remove single-line comments (but not URLs with //).
        $js = preg_replace( '#(?<!:)//[^\n]*#', '', $js );

        // Remove multi-line comments.
        $js = preg_replace( '#/\*.*?\*/#s', '', $js );

        // Collapse multiple whitespace/newlines to single space.
        $js = preg_replace( '/[ \t]+/', ' ', $js );

        // Remove blank lines.
        $js = preg_replace( "/\n\s*\n/", "\n", $js );

        // Remove leading/trailing whitespace on each line.
        $js = preg_replace( '/^ +| +$/m', '', $js );

        // Restore string literals.
        $js = str_replace( array_keys( $strings ), array_values( $strings ), $js );

        return trim( $js );
    }

    // ── Skip Logic ────────────────────────────────────────────

    /**
     * Check if a handle/url should be skipped from minification.
     *
     * @param string $handle Asset handle name.
     * @param string $url    Asset URL.
     * @param string $type   'css' or 'js'.
     * @return bool
     */
    private function should_skip( string $handle, string $url, string $type ): bool {
        // Skip empty or inline-only assets.
        if ( $url === '' ) {
            return true;
        }

        // Skip external/CDN files.
        if ( $this->is_external_url( $url ) ) {
            return true;
        }

        // Skip already-minified files.
        if ( $this->is_already_minified( $url ) ) {
            return true;
        }

        // Skip excluded handles.
        $settings = $this->get_settings();
        $excluded = $settings['exclude_handles'] ?? [];
        if ( in_array( $handle, $excluded, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Check if a URL points to an external resource.
     *
     * @param string $url The URL to check.
     * @return bool
     */
    private function is_external_url( string $url ): bool {
        $site_url = site_url();

        // Protocol-relative URLs starting with // need full comparison.
        if ( strpos( $url, '//' ) === 0 ) {
            $url = 'https:' . $url;
        }

        // Relative URLs are local.
        if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
            return false;
        }

        // Compare hosts.
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        $site_host = wp_parse_url( $site_url, PHP_URL_HOST );

        if ( $url_host === null || $site_host === null ) {
            return true;
        }

        return strtolower( (string) $url_host ) !== strtolower( (string) $site_host );
    }

    /**
     * Check if a file path/URL indicates it's already minified.
     *
     * @param string $path File path or URL.
     * @return bool
     */
    private function is_already_minified( string $path ): bool {
        return (bool) preg_match( '/\.min\.(css|js)(\?.*)?$/', $path );
    }

    /**
     * Convert a URL to a local file path.
     *
     * @param string $url The asset URL.
     * @return string Local path, or empty string if not resolvable.
     */
    private function url_to_path( string $url ): string {
        // Strip query strings.
        $url = strtok( $url, '?' ) ?: $url;

        // Try content_url mapping first (covers wp-content/...).
        $content_url = content_url();
        if ( strpos( $url, $content_url ) === 0 ) {
            return WP_CONTENT_DIR . substr( $url, strlen( $content_url ) );
        }

        // Try site_url mapping (covers wp-includes, wp-admin, etc.).
        $site_url = site_url();
        if ( strpos( $url, $site_url ) === 0 ) {
            return ABSPATH . substr( $url, strlen( $site_url ) + 1 );
        }

        // Handle relative URLs.
        if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
            return ABSPATH . ltrim( $url, '/' );
        }

        return '';
    }

    // ── AJAX: Clear Cache ─────────────────────────────────────

    /**
     * AJAX handler — clear all files in the cache directory.
     */
    public function ajax_clear_cache(): void {
        check_ajax_referer( 'wpt_minify_clear_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $count = $this->clear_cache_dir();

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: number of files deleted */
                __( 'Cache cleared. %d file(s) removed.', 'wptransformed' ),
                $count
            ),
            'count' => $count,
        ] );
    }

    /**
     * Delete all files in the cache directory.
     *
     * @return int Number of files deleted.
     */
    private function clear_cache_dir(): int {
        if ( ! is_dir( $this->cache_dir ) ) {
            return 0;
        }

        $files   = glob( $this->cache_dir . '/*.{css,js}', GLOB_BRACE );
        $deleted = 0;

        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                if ( is_file( $file ) && wp_delete_file( $file ) !== false ) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $nonce    = wp_create_nonce( 'wpt_minify_clear_cache_nonce' );
        ?>

        <div class="wpt-minify-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Minify Assets removes unnecessary characters from CSS and JS files (comments, whitespace) to reduce file size and improve page load times. Cached files are stored in wp-content and automatically regenerated when source files change.', 'wptransformed' ); ?>
            </p>
        </div>

        <?php if ( ! $this->is_cache_writable() ) : ?>
            <div class="notice notice-error inline" style="margin-bottom: 16px;">
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: cache directory path */
                            __( 'Cache directory "%s" is not writable. Please check file permissions.', 'wptransformed' ),
                            $this->cache_dir
                        )
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Minification', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox"
                                   name="wpt_minify_css"
                                   value="1"
                                   <?php checked( ! empty( $settings['minify_css'] ) ); ?>>
                            <?php esc_html_e( 'Minify CSS files', 'wptransformed' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox"
                                   name="wpt_minify_js"
                                   value="1"
                                   <?php checked( ! empty( $settings['minify_js'] ) ); ?>>
                            <?php esc_html_e( 'Minify JavaScript files', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Combining', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox"
                                   name="wpt_combine_css"
                                   value="1"
                                   <?php checked( ! empty( $settings['combine_css'] ) ); ?>>
                            <?php esc_html_e( 'Combine CSS files into one', 'wptransformed' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox"
                                   name="wpt_combine_js"
                                   value="1"
                                   <?php checked( ! empty( $settings['combine_js'] ) ); ?>>
                            <?php esc_html_e( 'Combine JavaScript files into one', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                    <p class="description" style="color: #d63638;">
                        <?php esc_html_e( 'Warning: Combining files can break functionality on some sites. Only enable if you can test thoroughly. With HTTP/2, combining files has less benefit.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt_exclude_handles"><?php esc_html_e( 'Excluded Handles', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt_exclude_handles"
                              name="wpt_exclude_handles"
                              rows="4"
                              cols="50"
                              class="large-text code"
                              placeholder="jquery-core&#10;wp-block-library"><?php
                        echo esc_textarea( implode( "\n", $settings['exclude_handles'] ?? [] ) );
                    ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Enter one handle per line. These assets will not be minified or combined.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Cache', 'wptransformed' ); ?></th>
                <td>
                    <button type="button"
                            id="wpt-minify-clear-cache"
                            class="button button-secondary"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Clear Cache', 'wptransformed' ); ?>
                    </button>
                    <span id="wpt-minify-cache-status" style="margin-left: 8px;"></span>
                    <p class="description">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: cache directory path */
                                __( 'Cache directory: %s', 'wptransformed' ),
                                $this->cache_dir
                            )
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <script>
        (function() {
            var btn = document.getElementById('wpt-minify-clear-cache');
            var status = document.getElementById('wpt-minify-cache-status');
            if (!btn) return;

            btn.addEventListener('click', function() {
                btn.disabled = true;
                status.textContent = '<?php echo esc_js( __( 'Clearing...', 'wptransformed' ) ); ?>';

                var data = new FormData();
                data.append('action', 'wpt_minify_clear_cache');
                data.append('nonce', btn.getAttribute('data-nonce'));

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        status.textContent = resp.data.message;
                        status.style.color = '#00a32a';
                    } else {
                        status.textContent = resp.data && resp.data.message
                            ? resp.data.message
                            : '<?php echo esc_js( __( 'Error clearing cache.', 'wptransformed' ) ); ?>';
                        status.style.color = '#d63638';
                    }
                    btn.disabled = false;
                })
                .catch(function() {
                    status.textContent = '<?php echo esc_js( __( 'Network error.', 'wptransformed' ) ); ?>';
                    status.style.color = '#d63638';
                    btn.disabled = false;
                });
            });
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $clean = [];

        $clean['minify_css']  = ! empty( $raw['wpt_minify_css'] );
        $clean['minify_js']   = ! empty( $raw['wpt_minify_js'] );
        $clean['combine_css'] = ! empty( $raw['wpt_combine_css'] );
        $clean['combine_js']  = ! empty( $raw['wpt_combine_js'] );

        // Parse excluded handles from textarea (one per line).
        $handles_raw = $raw['wpt_exclude_handles'] ?? '';
        if ( is_string( $handles_raw ) ) {
            $handles = array_filter(
                array_map( 'sanitize_key', explode( "\n", $handles_raw ) )
            );
            $clean['exclude_handles'] = array_values( $handles );
        } else {
            $clean['exclude_handles'] = [];
        }

        // Cache dir is not user-editable from the form, keep existing.
        $current = $this->get_settings();
        $clean['cache_dir'] = $current['cache_dir'];

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom admin CSS/JS — settings UI is server-rendered with inline script.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'directories' => [ 'wp-content/wpt-cache' ],
        ];
    }
}
