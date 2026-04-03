<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Auto Clear Caches — Automatically purge caches from popular caching
 * plugins when content is saved or menus are updated.
 *
 * Supported plugins: WP Super Cache, W3 Total Cache, WP Rocket,
 * LiteSpeed Cache, Autoptimize, and WP Engine hosting cache.
 *
 * @package WPTransformed
 */
class Auto_Clear_Caches extends Module_Base {

    /**
     * Supported cache plugin identifiers.
     */
    private const SUPPORTED_PLUGINS = [
        'wp-super-cache',
        'w3-total-cache',
        'wp-rocket',
        'litespeed-cache',
        'autoptimize',
    ];

    /**
     * Plugin slug => display name mapping.
     */
    private const PLUGIN_LABELS = [
        'wp-super-cache'  => 'WP Super Cache',
        'w3-total-cache'  => 'W3 Total Cache',
        'wp-rocket'       => 'WP Rocket',
        'litespeed-cache' => 'LiteSpeed Cache',
        'autoptimize'     => 'Autoptimize',
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'auto-clear-caches';
    }

    public function get_title(): string {
        return __( 'Auto Clear Caches', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Automatically purge caches from popular caching plugins when content is saved or menus are updated.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'clear_on_save'      => true,
            'clear_on_menu_save' => true,
            'supported_plugins'  => self::SUPPORTED_PLUGINS,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( ! empty( $settings['clear_on_save'] ) ) {
            add_action( 'save_post', [ $this, 'handle_post_save' ], 99, 2 );
        }

        if ( ! empty( $settings['clear_on_menu_save'] ) ) {
            add_action( 'wp_update_nav_menu', [ $this, 'handle_menu_save' ], 99 );
        }
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Clear caches after a post is saved.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function handle_post_save( int $post_id, \WP_Post $post ): void {
        // Skip autosaves and revisions.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        $this->purge_all_caches();
    }

    /**
     * Clear caches after a nav menu is updated.
     *
     * @param int $menu_id Menu ID.
     */
    public function handle_menu_save( int $menu_id ): void {
        $this->purge_all_caches();
    }

    // ── Cache Purge Logic ─────────────────────────────────────

    /**
     * Purge all detected caches based on configured supported plugins.
     */
    private function purge_all_caches(): void {
        $settings          = $this->get_settings();
        $supported_plugins = (array) ( $settings['supported_plugins'] ?? [] );

        $purge_map = [
            'wp-super-cache'  => 'purge_wp_super_cache',
            'w3-total-cache'  => 'purge_w3_total_cache',
            'wp-rocket'       => 'purge_wp_rocket',
            'litespeed-cache' => 'purge_litespeed_cache',
            'autoptimize'     => 'purge_autoptimize',
        ];

        foreach ( $purge_map as $slug => $method ) {
            if ( in_array( $slug, $supported_plugins, true ) ) {
                $this->$method();
            }
        }

        $this->purge_wp_engine();
        wp_cache_flush();
    }

    /**
     * Purge WP Super Cache.
     */
    private function purge_wp_super_cache(): void {
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
    }

    /**
     * Purge W3 Total Cache.
     */
    private function purge_w3_total_cache(): void {
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }
    }

    /**
     * Purge WP Rocket.
     */
    private function purge_wp_rocket(): void {
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
    }

    /**
     * Purge LiteSpeed Cache.
     */
    private function purge_litespeed_cache(): void {
        if ( has_action( 'litespeed_purge_all' ) ) {
            do_action( 'litespeed_purge_all' );
        }
    }

    /**
     * Purge Autoptimize.
     */
    private function purge_autoptimize(): void {
        if ( function_exists( 'autoptimize_flush_pagecache' ) ) {
            autoptimize_flush_pagecache();
        }
    }

    /**
     * Purge WP Engine hosting cache if running on WP Engine.
     */
    private function purge_wp_engine(): void {
        if ( function_exists( 'is_wpe' ) && is_wpe() ) {
            if ( class_exists( 'WpeCommon' ) ) {
                if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
                    \WpeCommon::purge_memcached();
                }
                if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
                    \WpeCommon::purge_varnish_cache();
                }
            }
        }
    }

    // ── Detect Active Cache Plugins ───────────────────────────

    /**
     * Return a list of detected active caching plugins/hosts.
     *
     * @return array<string,string> slug => display name
     */
    private function detect_active_plugins(): array {
        $active = [];

        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            $active['wp-super-cache'] = 'WP Super Cache';
        }
        if ( function_exists( 'w3tc_flush_all' ) ) {
            $active['w3-total-cache'] = 'W3 Total Cache';
        }
        if ( function_exists( 'rocket_clean_domain' ) ) {
            $active['wp-rocket'] = 'WP Rocket';
        }
        if ( has_action( 'litespeed_purge_all' ) ) {
            $active['litespeed-cache'] = 'LiteSpeed Cache';
        }
        if ( function_exists( 'autoptimize_flush_pagecache' ) ) {
            $active['autoptimize'] = 'Autoptimize';
        }
        if ( function_exists( 'is_wpe' ) && is_wpe() ) {
            $active['wp-engine'] = 'WP Engine';
        }

        return $active;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings       = $this->get_settings();
        $active_plugins = $this->detect_active_plugins();
        ?>

        <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Automatically clears caches from detected caching plugins when you save posts or update menus. This ensures visitors always see fresh content.', 'wptransformed' ); ?>
            </p>
        </div>

        <?php if ( ! empty( $active_plugins ) ) : ?>
            <div style="background: #ecf7ed; border-left: 4px solid #00a32a; padding: 12px 16px; margin-bottom: 20px;">
                <strong><?php esc_html_e( 'Detected:', 'wptransformed' ); ?></strong>
                <?php echo esc_html( implode( ', ', $active_plugins ) ); ?>
            </div>
        <?php else : ?>
            <div style="background: #fcf9e8; border-left: 4px solid #dba617; padding: 12px 16px; margin-bottom: 20px;">
                <p style="margin: 0;">
                    <?php esc_html_e( 'No supported caching plugin detected. Settings will take effect once a supported cache plugin is installed.', 'wptransformed' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Trigger Events', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="wpt_clear_on_save" value="1" <?php checked( $settings['clear_on_save'] ); ?>>
                            <?php esc_html_e( 'Clear caches when a post/page is saved', 'wptransformed' ); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="wpt_clear_on_menu_save" value="1" <?php checked( $settings['clear_on_menu_save'] ); ?>>
                            <?php esc_html_e( 'Clear caches when a navigation menu is updated', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Supported Plugins', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php
                        foreach ( self::PLUGIN_LABELS as $slug => $label ) :
                            $is_active = isset( $active_plugins[ $slug ] );
                            ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_supported_plugins[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, (array) $settings['supported_plugins'], true ) ); ?>>
                                <?php echo esc_html( $label ); ?>
                                <?php if ( $is_active ) : ?>
                                    <span style="color: #00a32a; font-weight: 600;"><?php esc_html_e( '(active)', 'wptransformed' ); ?></span>
                                <?php endif; ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Select which cache plugins to integrate with. WP Engine hosting cache and WordPress object cache are always cleared.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $plugins = isset( $raw['wpt_supported_plugins'] ) && is_array( $raw['wpt_supported_plugins'] )
            ? array_intersect( array_map( 'sanitize_key', $raw['wpt_supported_plugins'] ), self::SUPPORTED_PLUGINS )
            : [];

        return [
            'clear_on_save'      => ! empty( $raw['wpt_clear_on_save'] ),
            'clear_on_menu_save' => ! empty( $raw['wpt_clear_on_menu_save'] ),
            'supported_plugins'  => array_values( $plugins ),
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
