<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Environment Indicator -- Visual badge showing the current environment type.
 *
 * Features:
 *  - Auto-detect environment via wp_get_environment_type() (WP 5.5+) or URL patterns
 *  - Colored admin bar indicator node (production=red, staging=yellow, dev=green, local=blue)
 *  - Optional full-width banner via admin_notices
 *  - Inline CSS injected via admin_head
 *
 * @package WPTransformed
 */
class Environment_Indicator extends Module_Base {

    /** @var array|null Cached environment info for the current request. */
    private ?array $env_cache = null;

    /**
     * Default color map for environments.
     */
    private const COLOR_MAP = [
        'production'  => '#dc3545',
        'staging'     => '#ffc107',
        'development' => '#28a745',
        'local'       => '#007bff',
    ];

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'environment-indicator';
    }

    public function get_title(): string {
        return __( 'Environment Indicator', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Display a colored indicator in the admin bar showing the current environment (production, staging, development, local).', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'environment'      => 'production',
            'label'            => 'Production',
            'color'            => '#dc3545',
            'show_in_admin_bar' => true,
            'show_banner'      => false,
            'auto_detect'      => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() && ! is_admin_bar_showing() ) {
            return;
        }

        $settings = $this->get_settings();

        if ( ! empty( $settings['show_in_admin_bar'] ) ) {
            add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_node' ], 5 );
        }

        if ( ! empty( $settings['show_banner'] ) ) {
            add_action( 'admin_notices', [ $this, 'render_banner' ] );
        }

        add_action( 'admin_head', [ $this, 'render_inline_css' ] );
        add_action( 'wp_head', [ $this, 'render_inline_css' ] );
    }

    // -- Environment Detection --------------------------------------------

    /**
     * Detect the current environment.
     *
     * @return array{env: string, label: string, color: string}
     */
    public function detect_environment(): array {
        if ( $this->env_cache !== null ) {
            return $this->env_cache;
        }

        $settings = $this->get_settings();

        if ( empty( $settings['auto_detect'] ) ) {
            $this->env_cache = [
                'env'   => sanitize_key( $settings['environment'] ),
                'label' => sanitize_text_field( $settings['label'] ),
                'color' => sanitize_hex_color( $settings['color'] ) ?: '#dc3545',
            ];
            return $this->env_cache;
        }

        $env = $this->auto_detect_environment();

        $labels = [
            'production'  => __( 'Production', 'wptransformed' ),
            'staging'     => __( 'Staging', 'wptransformed' ),
            'development' => __( 'Development', 'wptransformed' ),
            'local'       => __( 'Local', 'wptransformed' ),
        ];

        $this->env_cache = [
            'env'   => $env,
            'label' => $labels[ $env ] ?? ucfirst( $env ),
            'color' => self::COLOR_MAP[ $env ] ?? '#dc3545',
        ];
        return $this->env_cache;
    }

    /**
     * Auto-detect the environment type.
     *
     * Priority: wp_get_environment_type() (WP 5.5+), then URL patterns.
     *
     * @return string Environment slug.
     */
    private function auto_detect_environment(): string {
        // WP 5.5+ native function.
        if ( function_exists( 'wp_get_environment_type' ) ) {
            $type = wp_get_environment_type();
            if ( in_array( $type, [ 'production', 'staging', 'development', 'local' ], true ) ) {
                return $type;
            }
        }

        // Fallback: URL pattern detection.
        $site_url = strtolower( site_url() );

        // Local patterns.
        $local_patterns = [ '.local', '.test', '.localhost', 'localhost', '127.0.0.1', '::1', '.ddev', '.lando' ];
        foreach ( $local_patterns as $pattern ) {
            if ( strpos( $site_url, $pattern ) !== false ) {
                return 'local';
            }
        }

        // Development patterns.
        $dev_patterns = [ 'dev.', '.dev.', '-dev.', 'develop.', 'development.' ];
        foreach ( $dev_patterns as $pattern ) {
            if ( strpos( $site_url, $pattern ) !== false ) {
                return 'development';
            }
        }

        // Staging patterns.
        $staging_patterns = [ 'staging.', '.staging.', '-staging.', 'stage.', 'stg.', 'preprod.' ];
        foreach ( $staging_patterns as $pattern ) {
            if ( strpos( $site_url, $pattern ) !== false ) {
                return 'staging';
            }
        }

        return 'production';
    }

    // -- Admin Bar Node ---------------------------------------------------

    /**
     * Add the environment indicator to the admin bar.
     *
     * @param \WP_Admin_Bar $admin_bar Admin bar instance.
     */
    public function add_admin_bar_node( \WP_Admin_Bar $admin_bar ): void {
        $env_info = $this->detect_environment();

        $admin_bar->add_node( [
            'id'    => 'wpt-environment-indicator',
            'title' => '<span class="wpt-env-badge">' . esc_html( $env_info['label'] ) . '</span>',
            'meta'  => [
                'class' => 'wpt-env-node wpt-env-' . esc_attr( $env_info['env'] ),
                'title' => sprintf(
                    /* translators: %s: environment name */
                    __( 'Environment: %s', 'wptransformed' ),
                    $env_info['label']
                ),
            ],
        ] );
    }

    // -- Banner -----------------------------------------------------------

    /**
     * Render a full-width banner above admin content.
     */
    public function render_banner(): void {
        $env_info = $this->detect_environment();

        // Do not show banner in production by default to avoid alarm fatigue.
        if ( $env_info['env'] === 'production' ) {
            $settings = $this->get_settings();
            if ( ! empty( $settings['auto_detect'] ) ) {
                return;
            }
        }

        $bg_color   = esc_attr( $env_info['color'] );
        $text_color = $this->get_contrast_color( $env_info['color'] );
        ?>
        <div class="notice wpt-env-banner" style="background:<?php echo $bg_color; ?>; color:<?php echo esc_attr( $text_color ); ?>; padding:8px 12px; margin:5px 0 15px; border:none; border-left:none; font-weight:600; text-align:center;">
            <?php
            printf(
                /* translators: %s: environment label */
                esc_html__( 'You are viewing the %s environment.', 'wptransformed' ),
                esc_html( $env_info['label'] )
            );
            ?>
        </div>
        <?php
    }

    // -- Inline CSS -------------------------------------------------------

    /**
     * Inject inline CSS for the admin bar badge.
     */
    public function render_inline_css(): void {
        $env_info   = $this->detect_environment();
        $bg_color   = esc_attr( $env_info['color'] );
        $text_color = $this->get_contrast_color( $env_info['color'] );
        ?>
        <style>
            #wpadminbar .wpt-env-node .ab-item { height: auto; }
            #wpadminbar .wpt-env-badge {
                display: inline-block;
                background: <?php echo $bg_color; ?>;
                color: <?php echo esc_attr( $text_color ); ?>;
                padding: 2px 10px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 700;
                line-height: 18px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            #wpadminbar .wpt-env-node:hover .wpt-env-badge { opacity: 0.85; }
        </style>
        <?php
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Get a contrasting text color (black or white) for a given background hex.
     *
     * @param string $hex Background color hex.
     * @return string '#000' or '#fff'.
     */
    private function get_contrast_color( string $hex ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '#fff';
        }

        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );

        // YIQ formula for perceived brightness.
        $yiq = ( ( $r * 299 ) + ( $g * 587 ) + ( $b * 114 ) ) / 1000;

        return $yiq >= 150 ? '#000' : '#fff';
    }

    // -- Settings UI -------------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        $env_info = $this->detect_environment();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Auto Detection', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_auto_detect" value="1" <?php checked( $settings['auto_detect'] ); ?>>
                        <?php esc_html_e( 'Auto-detect environment from WordPress settings or URL patterns.', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: detected environment */
                            esc_html__( 'Currently detected: %s', 'wptransformed' ),
                            '<strong>' . esc_html( $env_info['label'] ) . '</strong>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Manual Override', 'wptransformed' ); ?></th>
                <td>
                    <select name="wpt_environment">
                        <?php
                        $envs = [
                            'production'  => __( 'Production', 'wptransformed' ),
                            'staging'     => __( 'Staging', 'wptransformed' ),
                            'development' => __( 'Development', 'wptransformed' ),
                            'local'       => __( 'Local', 'wptransformed' ),
                        ];
                        foreach ( $envs as $val => $lbl ) :
                            ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['environment'], $val ); ?>>
                                <?php echo esc_html( $lbl ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Used when auto-detection is disabled.', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Custom Label', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_label" value="<?php echo esc_attr( $settings['label'] ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'Custom label shown in the admin bar (used when auto-detect is off).', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Custom Color', 'wptransformed' ); ?></th>
                <td>
                    <input type="text" name="wpt_color" value="<?php echo esc_attr( $settings['color'] ); ?>" class="small-text" placeholder="#dc3545">
                    <p class="description"><?php esc_html_e( 'Hex color for the badge (used when auto-detect is off).', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Display Options', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="wpt_show_in_admin_bar" value="1" <?php checked( $settings['show_in_admin_bar'] ); ?>>
                            <?php esc_html_e( 'Show indicator in the admin bar.', 'wptransformed' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="wpt_show_banner" value="1" <?php checked( $settings['show_banner'] ); ?>>
                            <?php esc_html_e( 'Show a full-width banner in the admin area.', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $valid_envs = [ 'production', 'staging', 'development', 'local' ];
        $env        = isset( $raw['wpt_environment'] ) ? sanitize_key( $raw['wpt_environment'] ) : 'production';
        if ( ! in_array( $env, $valid_envs, true ) ) {
            $env = 'production';
        }

        $color = isset( $raw['wpt_color'] ) ? sanitize_hex_color( $raw['wpt_color'] ) : '';
        if ( ! $color ) {
            $color = self::COLOR_MAP[ $env ] ?? '#dc3545';
        }

        return [
            'environment'       => $env,
            'label'             => isset( $raw['wpt_label'] ) ? sanitize_text_field( $raw['wpt_label'] ) : 'Production',
            'color'             => $color,
            'show_in_admin_bar' => ! empty( $raw['wpt_show_in_admin_bar'] ),
            'show_banner'       => ! empty( $raw['wpt_show_banner'] ),
            'auto_detect'       => ! empty( $raw['wpt_auto_detect'] ),
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
