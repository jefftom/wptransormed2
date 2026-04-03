<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Color Schemes — Custom color schemes for the WordPress admin.
 *
 * Core logic:
 * - admin_head: injects CSS custom properties for the active scheme
 * - Per-user scheme selection via user profile
 * - 6 built-in schemes + custom scheme with color pickers
 *
 * @package WPTransformed
 */
class Admin_Color_Schemes extends Module_Base {

    /** User meta key for the selected scheme. */
    private const META_KEY = 'wpt_color_scheme';

    /** User meta key for custom scheme colors. */
    private const CUSTOM_META_KEY = 'wpt_custom_color_scheme';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'admin-color-schemes';
    }

    public function get_title(): string {
        return __( 'Admin Color Schemes', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Apply custom color schemes to the WordPress admin interface with built-in and user-defined themes.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'       => true,
            'active_scheme' => 'default',
            'custom_scheme' => [],
        ];
    }

    /**
     * Built-in color scheme definitions.
     *
     * Each scheme defines CSS custom property values.
     *
     * @return array<string, array{label: string, colors: array<string, string>}>
     */
    private function get_builtin_schemes(): array {
        return [
            'default' => [
                'label'  => __( 'Default', 'wptransformed' ),
                'colors' => [],
            ],
            'ocean' => [
                'label'  => __( 'Ocean', 'wptransformed' ),
                'colors' => [
                    '--wpt-admin-bg'          => '#1a3a4a',
                    '--wpt-admin-menu-bg'     => '#0d2b3a',
                    '--wpt-admin-menu-text'   => '#b8d4e3',
                    '--wpt-admin-menu-hover'  => '#1e6fa0',
                    '--wpt-admin-accent'      => '#2196f3',
                    '--wpt-admin-link'        => '#4fc3f7',
                    '--wpt-admin-button-bg'   => '#1976d2',
                    '--wpt-admin-button-text' => '#ffffff',
                ],
            ],
            'forest' => [
                'label'  => __( 'Forest', 'wptransformed' ),
                'colors' => [
                    '--wpt-admin-bg'          => '#1a3a1a',
                    '--wpt-admin-menu-bg'     => '#0d2b0d',
                    '--wpt-admin-menu-text'   => '#b8e3b8',
                    '--wpt-admin-menu-hover'  => '#2e7d32',
                    '--wpt-admin-accent'      => '#4caf50',
                    '--wpt-admin-link'        => '#81c784',
                    '--wpt-admin-button-bg'   => '#388e3c',
                    '--wpt-admin-button-text' => '#ffffff',
                ],
            ],
            'sunset' => [
                'label'  => __( 'Sunset', 'wptransformed' ),
                'colors' => [
                    '--wpt-admin-bg'          => '#3e2723',
                    '--wpt-admin-menu-bg'     => '#2c1810',
                    '--wpt-admin-menu-text'   => '#ffccbc',
                    '--wpt-admin-menu-hover'  => '#d84315',
                    '--wpt-admin-accent'      => '#ff5722',
                    '--wpt-admin-link'        => '#ff8a65',
                    '--wpt-admin-button-bg'   => '#e64a19',
                    '--wpt-admin-button-text' => '#ffffff',
                ],
            ],
            'midnight' => [
                'label'  => __( 'Midnight', 'wptransformed' ),
                'colors' => [
                    '--wpt-admin-bg'          => '#1a1a2e',
                    '--wpt-admin-menu-bg'     => '#0f0f1e',
                    '--wpt-admin-menu-text'   => '#c8c8e8',
                    '--wpt-admin-menu-hover'  => '#4a148c',
                    '--wpt-admin-accent'      => '#7c4dff',
                    '--wpt-admin-link'        => '#b388ff',
                    '--wpt-admin-button-bg'   => '#651fff',
                    '--wpt-admin-button-text' => '#ffffff',
                ],
            ],
            'monochrome' => [
                'label'  => __( 'Monochrome', 'wptransformed' ),
                'colors' => [
                    '--wpt-admin-bg'          => '#2c2c2c',
                    '--wpt-admin-menu-bg'     => '#1a1a1a',
                    '--wpt-admin-menu-text'   => '#cccccc',
                    '--wpt-admin-menu-hover'  => '#444444',
                    '--wpt-admin-accent'      => '#888888',
                    '--wpt-admin-link'        => '#aaaaaa',
                    '--wpt-admin-button-bg'   => '#555555',
                    '--wpt-admin-button-text' => '#ffffff',
                ],
            ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'admin_head', [ $this, 'inject_scheme_css' ], 999 );
        add_action( 'admin_footer', [ $this, 'render_scheme_selector' ] );
        add_action( 'wp_ajax_wpt_save_color_scheme', [ $this, 'ajax_save_scheme' ] );
    }

    // ── CSS Injection ─────────────────────────────────────────

    /**
     * Inject CSS custom properties for the active color scheme.
     */
    public function inject_scheme_css(): void {
        $scheme_id = $this->get_user_scheme();

        if ( $scheme_id === 'default' ) {
            return;
        }

        $colors = [];

        if ( $scheme_id === 'custom' ) {
            $colors = $this->get_user_custom_scheme();
        } else {
            $schemes = $this->get_builtin_schemes();
            $colors  = isset( $schemes[ $scheme_id ] ) ? $schemes[ $scheme_id ]['colors'] : [];
        }

        if ( empty( $colors ) ) {
            return;
        }

        echo '<style id="wpt-color-scheme">' . "\n";
        echo ':root {' . "\n";
        foreach ( $colors as $prop => $value ) {
            // Sanitize: property must start with --, value must be valid CSS color.
            $prop  = preg_replace( '/[^a-z0-9\-]/', '', $prop );
            $value = sanitize_hex_color( $value );
            if ( $prop !== '' && $value !== null && $value !== '' ) {
                echo '  ' . esc_html( $prop ) . ': ' . esc_html( $value ) . ';' . "\n";
            }
        }
        echo '}' . "\n";

        // Apply variables to admin elements.
        echo '#adminmenuback, #adminmenuwrap, #adminmenu { background: var(--wpt-admin-menu-bg); }' . "\n";
        echo '#adminmenu a, #adminmenu .wp-submenu a { color: var(--wpt-admin-menu-text); }' . "\n";
        echo '#adminmenu li.menu-top:hover, #adminmenu li.opensub > a.menu-top, #adminmenu li > a.menu-top:focus { background: var(--wpt-admin-menu-hover); }' . "\n";
        echo '#adminmenu .current a, #adminmenu .wp-has-current-submenu .wp-submenu .wp-submenu-head, #adminmenu .wp-menu-arrow div { background: var(--wpt-admin-menu-hover); }' . "\n";
        echo '.wp-core-ui .button-primary { background: var(--wpt-admin-button-bg); border-color: var(--wpt-admin-button-bg); color: var(--wpt-admin-button-text); }' . "\n";
        echo 'a { color: var(--wpt-admin-link); }' . "\n";
        echo '</style>' . "\n";
    }

    // ── Scheme Selector ───────────────────────────────────────

    /**
     * Render the scheme selector widget in admin footer.
     */
    public function render_scheme_selector(): void {
        if ( ! current_user_can( 'read' ) ) {
            return;
        }

        $current_scheme = $this->get_user_scheme();
        $custom_colors  = $this->get_user_custom_scheme();
        $schemes        = $this->get_builtin_schemes();
        $color_props    = [
            '--wpt-admin-bg'          => __( 'Background', 'wptransformed' ),
            '--wpt-admin-menu-bg'     => __( 'Menu Background', 'wptransformed' ),
            '--wpt-admin-menu-text'   => __( 'Menu Text', 'wptransformed' ),
            '--wpt-admin-menu-hover'  => __( 'Menu Hover', 'wptransformed' ),
            '--wpt-admin-accent'      => __( 'Accent', 'wptransformed' ),
            '--wpt-admin-link'        => __( 'Links', 'wptransformed' ),
            '--wpt-admin-button-bg'   => __( 'Button Background', 'wptransformed' ),
            '--wpt-admin-button-text' => __( 'Button Text', 'wptransformed' ),
        ];
        ?>
        <div id="wpt-scheme-picker" style="display:none;">
            <style>
                #wpt-scheme-picker-panel {
                    position: fixed;
                    bottom: 60px;
                    left: 20px;
                    z-index: 99998;
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 8px;
                    padding: 16px;
                    width: 320px;
                    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
                    display: none;
                    max-height: 70vh;
                    overflow-y: auto;
                }
                #wpt-scheme-picker-panel.visible { display: block; }
                .wpt-scheme-option {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 8px;
                    border: 2px solid transparent;
                    border-radius: 4px;
                    cursor: pointer;
                    margin-bottom: 4px;
                }
                .wpt-scheme-option:hover { background: #f6f7f7; }
                .wpt-scheme-option.active { border-color: #2271b1; background: #f0f6fc; }
                .wpt-scheme-swatches {
                    display: flex;
                    gap: 3px;
                }
                .wpt-scheme-swatch {
                    width: 18px;
                    height: 18px;
                    border-radius: 3px;
                    border: 1px solid rgba(0,0,0,0.1);
                }
                .wpt-scheme-label { font-size: 13px; font-weight: 500; }
                .wpt-custom-colors {
                    margin-top: 12px;
                    padding-top: 12px;
                    border-top: 1px solid #f0f0f0;
                    display: none;
                }
                .wpt-custom-colors.visible { display: block; }
                .wpt-custom-color-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 6px;
                }
                .wpt-custom-color-row label { font-size: 12px; color: #50575e; }
                .wpt-custom-color-row input[type="color"] {
                    width: 32px;
                    height: 26px;
                    border: 1px solid #c3c4c7;
                    padding: 0;
                    cursor: pointer;
                }
                #wpt-scheme-toggle {
                    position: fixed;
                    bottom: 20px;
                    left: 70px;
                    z-index: 99997;
                    background: #50575e;
                    color: #fff;
                    border: none;
                    border-radius: 50%;
                    width: 36px;
                    height: 36px;
                    cursor: pointer;
                    font-size: 16px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    opacity: 0.7;
                    transition: opacity 0.2s;
                }
                #wpt-scheme-toggle:hover { opacity: 1; }
            </style>

            <button type="button" id="wpt-scheme-toggle" title="<?php esc_attr_e( 'Color Scheme', 'wptransformed' ); ?>">
                <span class="dashicons dashicons-art" style="font-size:18px;width:18px;height:18px;"></span>
            </button>

            <div id="wpt-scheme-picker-panel">
                <h4 style="margin: 0 0 12px; font-size: 14px;"><?php esc_html_e( 'Color Scheme', 'wptransformed' ); ?></h4>

                <?php foreach ( $schemes as $id => $scheme ) :
                    $swatches = array_slice( array_values( $scheme['colors'] ), 0, 4 );
                ?>
                    <div class="wpt-scheme-option <?php echo $current_scheme === $id ? 'active' : ''; ?>"
                         data-scheme="<?php echo esc_attr( $id ); ?>">
                        <div class="wpt-scheme-swatches">
                            <?php if ( $id === 'default' ) : ?>
                                <div class="wpt-scheme-swatch" style="background: #1d2327;"></div>
                                <div class="wpt-scheme-swatch" style="background: #2271b1;"></div>
                                <div class="wpt-scheme-swatch" style="background: #135e96;"></div>
                            <?php else :
                                foreach ( $swatches as $color ) : ?>
                                    <div class="wpt-scheme-swatch" style="background: <?php echo esc_attr( $color ); ?>;"></div>
                                <?php endforeach;
                            endif; ?>
                        </div>
                        <span class="wpt-scheme-label"><?php echo esc_html( $scheme['label'] ); ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="wpt-scheme-option <?php echo $current_scheme === 'custom' ? 'active' : ''; ?>"
                     data-scheme="custom">
                    <div class="wpt-scheme-swatches">
                        <div class="wpt-scheme-swatch" style="background: linear-gradient(135deg, #ff6b6b, #4ecdc4, #45b7d1);"></div>
                    </div>
                    <span class="wpt-scheme-label"><?php esc_html_e( 'Custom', 'wptransformed' ); ?></span>
                </div>

                <div class="wpt-custom-colors <?php echo $current_scheme === 'custom' ? 'visible' : ''; ?>" id="wpt-custom-colors">
                    <?php foreach ( $color_props as $prop => $label ) :
                        $value = $custom_colors[ $prop ] ?? '#333333';
                    ?>
                        <div class="wpt-custom-color-row">
                            <label><?php echo esc_html( $label ); ?></label>
                            <input type="color" data-prop="<?php echo esc_attr( $prop ); ?>"
                                   value="<?php echo esc_attr( $value ); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var picker  = document.getElementById('wpt-scheme-picker');
            var panel   = document.getElementById('wpt-scheme-picker-panel');
            var toggle  = document.getElementById('wpt-scheme-toggle');
            var custom  = document.getElementById('wpt-custom-colors');
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wpt_save_color_scheme' ) ); ?>;

            if (!picker || !panel || !toggle) return;
            picker.style.display = '';

            toggle.addEventListener('click', function() {
                panel.classList.toggle('visible');
            });

            // Close on outside click.
            document.addEventListener('click', function(e) {
                if (!panel.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
                    panel.classList.remove('visible');
                }
            });

            // Scheme selection.
            var options = panel.querySelectorAll('.wpt-scheme-option');
            for (var i = 0; i < options.length; i++) {
                options[i].addEventListener('click', function() {
                    var scheme = this.getAttribute('data-scheme');

                    // Update active state.
                    for (var j = 0; j < options.length; j++) {
                        options[j].classList.remove('active');
                    }
                    this.classList.add('active');

                    // Show/hide custom colors.
                    if (scheme === 'custom') {
                        custom.classList.add('visible');
                    } else {
                        custom.classList.remove('visible');
                    }

                    saveScheme(scheme);
                });
            }

            // Custom color changes.
            var colorInputs = custom.querySelectorAll('input[type="color"]');
            var debounceTimer = null;
            for (var k = 0; k < colorInputs.length; k++) {
                colorInputs[k].addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(function() { saveScheme('custom'); }, 500);
                });
            }

            function saveScheme(scheme) {
                var data = new FormData();
                data.append('action', 'wpt_save_color_scheme');
                data.append('_wpnonce', nonce);
                data.append('scheme', scheme);

                if (scheme === 'custom') {
                    var colors = {};
                    for (var c = 0; c < colorInputs.length; c++) {
                        colors[colorInputs[c].getAttribute('data-prop')] = colorInputs[c].value;
                    }
                    data.append('custom_colors', JSON.stringify(colors));
                }

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.success) {
                            window.location.reload();
                        }
                    });
            }
        })();
        </script>
        <?php
    }

    // ── AJAX Handler ──────────────────────────────────────────

    /**
     * AJAX: Save the selected color scheme for the current user.
     */
    public function ajax_save_scheme(): void {
        check_ajax_referer( 'wpt_save_color_scheme' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $scheme  = isset( $_POST['scheme'] ) ? sanitize_key( wp_unslash( $_POST['scheme'] ) ) : 'default';
        $user_id = get_current_user_id();

        // Validate scheme ID.
        $valid_schemes = array_merge( array_keys( $this->get_builtin_schemes() ), [ 'custom' ] );
        if ( ! in_array( $scheme, $valid_schemes, true ) ) {
            $scheme = 'default';
        }

        update_user_meta( $user_id, self::META_KEY, $scheme );

        // Save custom colors if applicable.
        if ( $scheme === 'custom' && isset( $_POST['custom_colors'] ) ) {
            $raw_colors = json_decode( wp_unslash( $_POST['custom_colors'] ), true );
            if ( is_array( $raw_colors ) ) {
                $sanitized_colors = [];
                foreach ( $raw_colors as $prop => $value ) {
                    $prop = preg_replace( '/[^a-z0-9\-]/', '', $prop );
                    $hex  = sanitize_hex_color( $value );
                    if ( $prop !== '' && $hex !== null && $hex !== '' ) {
                        $sanitized_colors[ $prop ] = $hex;
                    }
                }
                update_user_meta( $user_id, self::CUSTOM_META_KEY, wp_json_encode( $sanitized_colors ) );
            }
        }

        wp_send_json_success( [ 'scheme' => $scheme ] );
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Get the current user's selected scheme.
     *
     * @return string
     */
    private function get_user_scheme(): string {
        $user_id = get_current_user_id();
        if ( $user_id === 0 ) {
            return 'default';
        }

        $scheme = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_string( $scheme ) || $scheme === '' ) {
            return 'default';
        }

        return $scheme;
    }

    /**
     * Get the current user's custom scheme colors.
     *
     * @return array<string, string>
     */
    private function get_user_custom_scheme(): array {
        $user_id = get_current_user_id();
        if ( $user_id === 0 ) {
            return [];
        }

        $raw = get_user_meta( $user_id, self::CUSTOM_META_KEY, true );
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return [];
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e( 'Admin Color Schemes Settings', 'wptransformed' ); ?></legend>

            <p>
                <label>
                    <input type="checkbox" name="wpt_enabled" value="1"
                        <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                    <?php esc_html_e( 'Enable admin color schemes', 'wptransformed' ); ?>
                </label>
            </p>

            <p class="description">
                <?php esc_html_e( 'When enabled, a color palette icon appears in the admin footer. Each user can pick their own color scheme from 6 built-in options or create a custom one.', 'wptransformed' ); ?>
            </p>
        </fieldset>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'       => ! empty( $raw['wpt_enabled'] ),
            'active_scheme' => 'default',
            'custom_scheme' => [],
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => self::META_KEY ],
            [ 'type' => 'user_meta', 'key' => self::CUSTOM_META_KEY ],
        ];
    }
}
