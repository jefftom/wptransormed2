<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Bar Enhancer — Customize the WordPress admin bar.
 *
 * Features:
 *  - Remove WP logo from admin bar
 *  - Replace "Howdy," greeting with "Welcome,"
 *  - Show environment indicator (Production/Staging/Dev)
 *  - Add custom links to admin bar
 *
 * @package WPTransformed
 */
class Admin_Bar_Enhancer extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'admin-bar-enhancer';
    }

    public function get_title(): string {
        return __( 'Admin Bar Enhancer', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Customize the WordPress admin bar with environment indicators, custom links, and greeting changes.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'remove_wp_logo'    => true,
            'remove_howdy'      => true,
            'show_environment'  => true,
            'environment_label' => 'Production',
            'environment_color' => '#dc3545',
            'custom_links'      => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        // Remove WP logo — early priority to run before other nodes.
        if ( ! empty( $settings['remove_wp_logo'] ) ) {
            add_action( 'admin_bar_menu', [ $this, 'remove_wp_logo' ], 0 );
        }

        // Add environment indicator and custom links — late priority.
        $has_env   = ! empty( $settings['show_environment'] );
        $has_links = ! empty( $settings['custom_links'] );
        if ( $has_env || $has_links ) {
            add_action( 'admin_bar_menu', [ $this, 'add_custom_nodes' ], 999 );
        }

        // Replace "Howdy," greeting via gettext filter.
        if ( ! empty( $settings['remove_howdy'] ) ) {
            add_filter( 'gettext', [ $this, 'replace_howdy' ], 10, 3 );
        }

        // Enqueue inline styles for environment indicator.
        if ( ! empty( $settings['show_environment'] ) ) {
            add_action( 'admin_head', [ $this, 'inline_environment_styles' ] );
            add_action( 'wp_head', [ $this, 'inline_environment_styles' ] );
        }
    }

    // ── Admin Bar Modifications ───────────────────────────────

    /**
     * Remove the WordPress logo from the admin bar.
     *
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
     */
    public function remove_wp_logo( \WP_Admin_Bar $wp_admin_bar ): void {
        $wp_admin_bar->remove_node( 'wp-logo' );
    }

    /**
     * Add environment indicator and custom links to the admin bar.
     *
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
     */
    public function add_custom_nodes( \WP_Admin_Bar $wp_admin_bar ): void {
        $settings = $this->get_settings();

        // Environment indicator.
        if ( ! empty( $settings['show_environment'] ) ) {
            $label = sanitize_text_field( $settings['environment_label'] ?? 'Production' );

            if ( ! empty( $label ) ) {
                $wp_admin_bar->add_node( [
                    'id'    => 'wpt-environment',
                    'title' => esc_html( $label ),
                    'href'  => false,
                    'meta'  => [
                        'class' => 'wpt-environment-indicator',
                        'title' => sprintf(
                            /* translators: %s: environment name */
                            __( 'Environment: %s', 'wptransformed' ),
                            $label
                        ),
                    ],
                ] );
            }
        }

        // Custom links.
        $custom_links = (array) ( $settings['custom_links'] ?? [] );

        foreach ( $custom_links as $index => $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }

            $title = sanitize_text_field( $link['title'] ?? '' );
            $url   = esc_url( $link['url'] ?? '' );

            if ( empty( $title ) || empty( $url ) ) {
                continue;
            }

            $wp_admin_bar->add_node( [
                'id'    => 'wpt-custom-link-' . $index,
                'title' => esc_html( $title ),
                'href'  => $url,
                'meta'  => [
                    'target' => ! empty( $link['new_tab'] ) ? '_blank' : '',
                    'rel'    => ! empty( $link['new_tab'] ) ? 'noopener noreferrer' : '',
                ],
            ] );
        }
    }

    /**
     * Replace "Howdy," with "Welcome," in the admin bar greeting.
     *
     * @param string $translated Translated text.
     * @param string $text       Original text.
     * @param string $domain     Text domain.
     * @return string Modified text.
     */
    public function replace_howdy( string $translated, string $text, string $domain ): string {
        if ( $domain !== 'default' ) {
            return $translated;
        }

        if ( strpos( $translated, 'Howdy,' ) !== false ) {
            $translated = str_replace( 'Howdy,', 'Welcome,', $translated );
        }

        return $translated;
    }

    // ── Inline Styles ─────────────────────────────────────────

    /**
     * Output inline CSS for the environment indicator.
     */
    public function inline_environment_styles(): void {
        if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
            return;
        }

        $settings = $this->get_settings();
        $color    = $this->sanitize_hex_color( $settings['environment_color'] ?? '#dc3545' );

        ?>
        <style id="wpt-admin-bar-enhancer">
        #wpadminbar .wpt-environment-indicator .ab-item {
            background-color: <?php echo esc_attr( $color ); ?>;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 10px;
        }
        #wpadminbar .wpt-environment-indicator:hover .ab-item {
            background-color: <?php echo esc_attr( $color ); ?>;
            color: #fff;
        }
        </style>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Admin bar cleanup', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_remove_wp_logo" value="1"
                                   <?php checked( ! empty( $settings['remove_wp_logo'] ) ); ?>>
                            <?php esc_html_e( 'Remove WordPress logo from admin bar', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_remove_howdy" value="1"
                                   <?php checked( ! empty( $settings['remove_howdy'] ) ); ?>>
                            <?php esc_html_e( 'Replace "Howdy," with "Welcome,"', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Environment indicator', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_show_environment" value="1"
                                   <?php checked( ! empty( $settings['show_environment'] ) ); ?>>
                            <?php esc_html_e( 'Show environment indicator in admin bar', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                    <div style="margin-top: 8px;">
                        <label for="wpt_environment_label" style="display: block; margin-bottom: 4px;">
                            <?php esc_html_e( 'Label:', 'wptransformed' ); ?>
                        </label>
                        <input type="text" id="wpt_environment_label" name="wpt_environment_label"
                               value="<?php echo esc_attr( $settings['environment_label'] ); ?>"
                               class="regular-text" placeholder="Production">
                    </div>
                    <div style="margin-top: 8px;">
                        <label for="wpt_environment_color" style="display: block; margin-bottom: 4px;">
                            <?php esc_html_e( 'Color:', 'wptransformed' ); ?>
                        </label>
                        <input type="color" id="wpt_environment_color" name="wpt_environment_color"
                               value="<?php echo esc_attr( $settings['environment_color'] ); ?>">
                        <span style="margin-left: 8px; color: #666;">
                            <?php echo esc_html( $settings['environment_color'] ); ?>
                        </span>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Custom links', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-custom-links-wrapper">
                        <?php
                        $custom_links = (array) ( $settings['custom_links'] ?? [] );
                        if ( empty( $custom_links ) ) {
                            $custom_links = [ [ 'title' => '', 'url' => '', 'new_tab' => false ] ];
                        }

                        foreach ( $custom_links as $i => $link ) :
                            if ( ! is_array( $link ) ) {
                                continue;
                            }
                        ?>
                            <div class="wpt-custom-link-row" style="margin-bottom: 8px; padding: 8px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                                <input type="text" name="wpt_custom_links[<?php echo (int) $i; ?>][title]"
                                       value="<?php echo esc_attr( $link['title'] ?? '' ); ?>"
                                       placeholder="<?php esc_attr_e( 'Link title', 'wptransformed' ); ?>"
                                       style="width: 200px; margin-right: 8px;">
                                <input type="url" name="wpt_custom_links[<?php echo (int) $i; ?>][url]"
                                       value="<?php echo esc_attr( $link['url'] ?? '' ); ?>"
                                       placeholder="<?php esc_attr_e( 'https://example.com', 'wptransformed' ); ?>"
                                       style="width: 300px; margin-right: 8px;">
                                <label>
                                    <input type="checkbox" name="wpt_custom_links[<?php echo (int) $i; ?>][new_tab]"
                                           value="1"
                                           <?php checked( ! empty( $link['new_tab'] ) ); ?>>
                                    <?php esc_html_e( 'New tab', 'wptransformed' ); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="wpt-add-custom-link" class="button button-secondary" style="margin-top: 4px;">
                        <?php esc_html_e( '+ Add Link', 'wptransformed' ); ?>
                    </button>
                    <script>
                    (function() {
                        var btn = document.getElementById('wpt-add-custom-link');
                        if (!btn) return;
                        btn.addEventListener('click', function() {
                            var wrapper = document.getElementById('wpt-custom-links-wrapper');
                            var count = wrapper.querySelectorAll('.wpt-custom-link-row').length;
                            var row = document.createElement('div');
                            row.className = 'wpt-custom-link-row';
                            row.style.cssText = 'margin-bottom:8px;padding:8px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;';
                            row.innerHTML = '<input type="text" name="wpt_custom_links[' + count + '][title]" placeholder="Link title" style="width:200px;margin-right:8px;">' +
                                '<input type="url" name="wpt_custom_links[' + count + '][url]" placeholder="https://example.com" style="width:300px;margin-right:8px;">' +
                                '<label><input type="checkbox" name="wpt_custom_links[' + count + '][new_tab]" value="1"> New tab</label>';
                            wrapper.appendChild(row);
                        });
                    })();
                    </script>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $remove_wp_logo   = ! empty( $raw['wpt_remove_wp_logo'] );
        $remove_howdy     = ! empty( $raw['wpt_remove_howdy'] );
        $show_environment = ! empty( $raw['wpt_show_environment'] );

        $environment_label = sanitize_text_field( $raw['wpt_environment_label'] ?? 'Production' );
        if ( mb_strlen( $environment_label ) > 50 ) {
            $environment_label = mb_substr( $environment_label, 0, 50 );
        }

        $environment_color = $this->sanitize_hex_color( $raw['wpt_environment_color'] ?? '#dc3545' );

        // Custom links.
        $submitted_links = isset( $raw['wpt_custom_links'] ) && is_array( $raw['wpt_custom_links'] )
            ? $raw['wpt_custom_links']
            : [];

        $custom_links = [];
        $max_links    = 10;

        foreach ( $submitted_links as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }

            $title = sanitize_text_field( $link['title'] ?? '' );
            $url   = esc_url_raw( $link['url'] ?? '' );

            if ( empty( $title ) && empty( $url ) ) {
                continue;
            }

            $custom_links[] = [
                'title'   => $title,
                'url'     => $url,
                'new_tab' => ! empty( $link['new_tab'] ),
            ];

            if ( count( $custom_links ) >= $max_links ) {
                break;
            }
        }

        return [
            'remove_wp_logo'    => $remove_wp_logo,
            'remove_howdy'      => $remove_howdy,
            'show_environment'  => $show_environment,
            'environment_label' => $environment_label,
            'environment_color' => $environment_color,
            'custom_links'      => $custom_links,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Sanitize a hex color string.
     *
     * @param string $color Hex color.
     * @return string Sanitized hex color or default.
     */
    private function sanitize_hex_color( string $color ): string {
        $color = sanitize_text_field( $color );

        if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
            return $color;
        }

        if ( preg_match( '/^#[0-9a-fA-F]{3}$/', $color ) ) {
            return $color;
        }

        return '#dc3545';
    }
}
