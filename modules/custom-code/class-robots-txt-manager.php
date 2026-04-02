<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Robots.txt Manager — Customize your site's robots.txt content.
 *
 * Features:
 *  - Custom robots.txt content via textarea
 *  - Toggle between WordPress default and custom content
 *  - "Load Default" button to populate with WP defaults
 *  - Warning about Disallow: /
 *  - Detection of physical robots.txt file
 *  - Filters the robots_txt output when custom is enabled
 *
 * @package WPTransformed
 */
class Robots_Txt_Manager extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'robots-txt-manager';
    }

    public function get_title(): string {
        return __( 'Robots.txt Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'custom-code';
    }

    public function get_description(): string {
        return __( 'Customize the content of your site\'s virtual robots.txt file.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'custom_robots' => '',
            'use_custom'    => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( ! empty( $settings['use_custom'] ) && $settings['custom_robots'] !== '' ) {
            add_filter( 'robots_txt', [ $this, 'filter_robots_txt' ], 999, 2 );
        }

        // AJAX handler to get the default robots.txt content.
        add_action( 'wp_ajax_wpt_get_default_robots', [ $this, 'ajax_get_default_robots' ] );
    }

    // ── Robots.txt Filter ─────────────────────────────────────

    /**
     * Replace the robots.txt content with the custom content.
     *
     * @param string $output  Current robots.txt content.
     * @param bool   $public  Whether the site is public.
     * @return string Custom robots.txt content.
     */
    public function filter_robots_txt( string $output, bool $public ): string {
        $settings = $this->get_settings();
        $custom   = $settings['custom_robots'] ?? '';

        if ( $custom !== '' ) {
            return $custom;
        }

        return $output;
    }

    // ── AJAX: Get Default ─────────────────────────────────────

    /**
     * Return the default WordPress robots.txt content via AJAX.
     */
    public function ajax_get_default_robots(): void {
        check_ajax_referer( 'wpt_robots_txt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        // Generate the default robots.txt content.
        $public = (bool) get_option( 'blog_public' );

        // Temporarily remove our filter to get the true default.
        remove_filter( 'robots_txt', [ $this, 'filter_robots_txt' ], 999 );

        ob_start();
        do_robots();
        $default = ob_get_clean();

        // Re-add our filter.
        $settings = $this->get_settings();
        if ( ! empty( $settings['use_custom'] ) && $settings['custom_robots'] !== '' ) {
            add_filter( 'robots_txt', [ $this, 'filter_robots_txt' ], 999, 2 );
        }

        wp_send_json_success( [ 'content' => $default ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings      = $this->get_settings();
        $custom_robots = $settings['custom_robots'] ?? '';
        $use_custom    = ! empty( $settings['use_custom'] );

        // Detect physical robots.txt file.
        $physical_file = ABSPATH . 'robots.txt';
        $has_physical  = file_exists( $physical_file );

        // Check if custom content contains Disallow: /
        $has_disallow_all = $custom_robots !== '' && preg_match( '/^\s*Disallow\s*:\s*\/\s*$/mi', $custom_robots );

        if ( $has_physical ) : ?>
            <div class="notice notice-warning inline" style="margin: 0 0 16px 0; padding: 12px;">
                <p>
                    <strong><?php esc_html_e( 'Physical robots.txt file detected!', 'wptransformed' ); ?></strong>
                    <?php esc_html_e( 'A physical robots.txt file exists at your site root. WordPress (and this module) cannot override it. You must delete or rename the physical file for the virtual robots.txt to take effect.', 'wptransformed' ); ?>
                </p>
                <p>
                    <code><?php echo esc_html( $physical_file ); ?></code>
                </p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Use Custom robots.txt', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_use_custom" value="1"
                               <?php checked( $use_custom ); ?>>
                        <?php esc_html_e( 'Enable custom robots.txt content', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When disabled, WordPress generates the default robots.txt content.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Custom Content', 'wptransformed' ); ?></th>
                <td>
                    <textarea name="wpt_custom_robots" id="wpt-custom-robots" rows="15" cols="70"
                              class="large-text code" style="font-family: monospace;"><?php echo esc_textarea( $custom_robots ); ?></textarea>

                    <?php if ( $has_disallow_all ) : ?>
                        <div class="notice notice-error inline" style="margin: 8px 0; padding: 8px 12px;">
                            <p>
                                <strong><?php esc_html_e( 'Warning:', 'wptransformed' ); ?></strong>
                                <?php esc_html_e( 'Your robots.txt contains "Disallow: /" which blocks all search engines from crawling your entire site. This will negatively impact SEO.', 'wptransformed' ); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <p style="margin-top: 8px;">
                        <button type="button" class="button" id="wpt-load-default-robots">
                            <?php esc_html_e( 'Load Default', 'wptransformed' ); ?>
                        </button>
                        <span class="description" style="margin-left: 8px;">
                            <?php esc_html_e( 'Populate the textarea with the WordPress default robots.txt content.', 'wptransformed' ); ?>
                        </span>
                    </p>

                    <p class="description" style="margin-top: 8px;">
                        <?php
                        printf(
                            /* translators: %s: robots.txt URL */
                            esc_html__( 'View your current robots.txt: %s', 'wptransformed' ),
                            '<a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">' . esc_html( home_url( '/robots.txt' ) ) . '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <script>
        (function() {
            var btn = document.getElementById('wpt-load-default-robots');
            if (!btn) return;

            btn.addEventListener('click', function() {
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Loading...', 'wptransformed' ) ); ?>';

                var formData = new FormData();
                formData.append('action', 'wpt_get_default_robots');
                formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'wpt_robots_txt_nonce' ) ); ?>');

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success && result.data.content) {
                        document.getElementById('wpt-custom-robots').value = result.data.content;
                    } else {
                        alert('<?php echo esc_js( __( 'Failed to load default content.', 'wptransformed' ) ); ?>');
                    }
                })
                .catch(function() {
                    alert('<?php echo esc_js( __( 'Network error.', 'wptransformed' ) ); ?>');
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js( __( 'Load Default', 'wptransformed' ) ); ?>';
                });
            });
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $custom = $raw['wpt_custom_robots'] ?? '';

        // Sanitize: strip PHP tags, normalize line endings.
        $custom = wp_strip_all_tags( $custom );
        $custom = str_replace( "\r\n", "\n", $custom );
        $custom = str_replace( "\r", "\n", $custom );

        return [
            'custom_robots' => $custom,
            'use_custom'    => ! empty( $raw['wpt_use_custom'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Inline JS handles all interactions.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
