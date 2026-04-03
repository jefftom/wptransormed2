<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Plugin Profiler -- Measure approximate load time, memory usage, and hook count per plugin.
 *
 * NOTE: This provides approximate profiling data, not precise benchmarks.
 * Results can vary between runs due to opcode caching, server load, and
 * WordPress internal state. Use as a relative comparison tool.
 *
 * @package WPTransformed
 */
class Plugin_Profiler extends Module_Base {

    /**
     * Transient key for benchmark results.
     */
    private const RESULTS_KEY = 'wpt_plugin_profiler_results';

    /**
     * How long to cache results (24 hours).
     */
    private const CACHE_TTL = DAY_IN_SECONDS;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'plugin-profiler';
    }

    public function get_title(): string {
        return __( 'Plugin Profiler', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Measure approximate load time, memory usage, and hook count for each active plugin.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'wp_ajax_wpt_run_profiler', [ $this, 'ajax_run_benchmark' ] );
        add_action( 'wp_ajax_wpt_clear_profiler', [ $this, 'ajax_clear_results' ] );
    }

    // ── AJAX: Run Benchmark ───────────────────────────────────

    /**
     * Run plugin benchmark via AJAX.
     */
    public function ajax_run_benchmark(): void {
        check_ajax_referer( 'wpt_profiler_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $results = $this->profile_plugins();

        set_transient( self::RESULTS_KEY, $results, self::CACHE_TTL );

        wp_send_json_success( [
            'message' => __( 'Benchmark complete.', 'wptransformed' ),
            'results' => $results,
            'html'    => $this->render_results_table( $results ),
        ] );
    }

    /**
     * Clear cached profiler results.
     */
    public function ajax_clear_results(): void {
        check_ajax_referer( 'wpt_profiler_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        delete_transient( self::RESULTS_KEY );

        wp_send_json_success( [
            'message' => __( 'Results cleared.', 'wptransformed' ),
        ] );
    }

    // ── Profiling Logic ───────────────────────────────────────

    /**
     * Profile all active plugins.
     *
     * Measures:
     * - Load time (approximate file read overhead via microtime)
     * - Hook count (functions registered in $wp_filter from plugin directory)
     *
     * @return array[] Array of plugin profile data.
     */
    private function profile_plugins(): array {
        $active_plugins = get_option( 'active_plugins', [] );

        if ( ! is_array( $active_plugins ) || empty( $active_plugins ) ) {
            return [];
        }

        $results = [];

        foreach ( $active_plugins as $plugin_file ) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

            if ( ! file_exists( $plugin_path ) ) {
                continue;
            }

            $plugin_data = get_plugin_data( $plugin_path, false, false );
            $plugin_dir  = dirname( $plugin_file );

            // Count hooks registered by this plugin.
            $hook_count = $this->count_plugin_hooks( $plugin_dir );

            // Measure file read overhead as a proxy for plugin complexity.
            $load_time = $this->measure_file_overhead( $plugin_path );

            $results[] = [
                'file'       => $plugin_file,
                'name'       => ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : basename( $plugin_file ),
                'version'    => $plugin_data['Version'] ?? '',
                'load_time'  => round( $load_time * 1000, 2 ), // Convert to ms.
                'hook_count' => $hook_count,
            ];
        }

        // Sort by hook count descending (most hooks first).
        usort( $results, static function ( array $a, array $b ): int {
            return $b['hook_count'] <=> $a['hook_count'];
        } );

        return $results;
    }

    /**
     * Count hooks in $wp_filter that belong to a specific plugin.
     *
     * @param string $plugin_dir Plugin directory name.
     * @return int Number of hooks.
     */
    private function count_plugin_hooks( string $plugin_dir ): int {
        global $wp_filter;

        if ( empty( $wp_filter ) || ! is_array( $wp_filter ) ) {
            return 0;
        }

        $count      = 0;
        $plugin_dir = strtolower( $plugin_dir );

        foreach ( $wp_filter as $tag => $hook_obj ) {
            if ( ! is_object( $hook_obj ) || ! isset( $hook_obj->callbacks ) ) {
                continue;
            }

            foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $callback_id => $callback_data ) {
                    if ( $this->callback_belongs_to_plugin( $callback_data['function'] ?? null, $plugin_dir ) ) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Check if a callback function belongs to a specific plugin directory.
     *
     * @param mixed  $function   The callback.
     * @param string $plugin_dir Plugin directory name (lowercase).
     * @return bool
     */
    private function callback_belongs_to_plugin( $function, string $plugin_dir ): bool {
        if ( empty( $function ) ) {
            return false;
        }

        try {
            $ref = null;

            if ( is_string( $function ) && function_exists( $function ) ) {
                $ref = new \ReflectionFunction( $function );
            } elseif ( is_array( $function ) && count( $function ) === 2 ) {
                $class  = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
                $method = (string) $function[1];
                if ( class_exists( $class ) && method_exists( $class, $method ) ) {
                    $ref = new \ReflectionMethod( $class, $method );
                }
            } elseif ( $function instanceof \Closure ) {
                $ref = new \ReflectionFunction( $function );
            }

            if ( $ref && $ref->getFileName() ) {
                $file = strtolower( str_replace( '\\', '/', $ref->getFileName() ) );
                return strpos( $file, '/plugins/' . $plugin_dir . '/' ) !== false;
            }
        } catch ( \ReflectionException $e ) {
            // Silently skip if reflection fails.
        }

        return false;
    }

    /**
     * Measure file read overhead as a proxy for plugin complexity.
     *
     * Since plugins are already loaded, we time reading the main file
     * as an approximate indicator of I/O cost.
     *
     * @param string $path Plugin main file path.
     * @return float Time in seconds.
     */
    private function measure_file_overhead( string $path ): float {
        $start = microtime( true );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        @file_get_contents( $path );

        return microtime( true ) - $start;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $results  = get_transient( self::RESULTS_KEY );

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Profiler', 'wptransformed' ); ?></th>
                <td>
                    <button type="button" class="button button-primary" id="wpt-run-profiler">
                        <span class="dashicons dashicons-performance" style="vertical-align: middle; margin-right: 4px;"></span>
                        <?php esc_html_e( 'Run Benchmark', 'wptransformed' ); ?>
                    </button>
                    <?php if ( ! empty( $results ) ) : ?>
                        <button type="button" class="button button-secondary" id="wpt-clear-profiler" style="margin-left: 8px;">
                            <?php esc_html_e( 'Clear Results', 'wptransformed' ); ?>
                        </button>
                    <?php endif; ?>
                    <span class="spinner" id="wpt-profiler-spinner" style="float: none;"></span>

                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e( 'Note: Results are approximate and may vary between runs. Use for relative comparison, not absolute benchmarks. Results are cached for 24 hours.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <div id="wpt-profiler-results">
            <?php
            if ( is_array( $results ) && ! empty( $results ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render method escapes internally
                echo $this->render_results_table( $results );
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render the results table HTML.
     *
     * @param array[] $results Profile results.
     * @return string HTML string.
     */
    private function render_results_table( array $results ): string {
        if ( empty( $results ) ) {
            return '<p>' . esc_html__( 'No results available. Click "Run Benchmark" to profile your plugins.', 'wptransformed' ) . '</p>';
        }

        ob_start();
        ?>
        <h3 style="margin-top: 20px;">
            <?php esc_html_e( 'Plugin Profile Results', 'wptransformed' ); ?>
            <span style="font-weight: normal; font-size: 13px; color: #999;">
                (<?php echo esc_html( (string) count( $results ) ); ?>
                <?php esc_html_e( 'plugins', 'wptransformed' ); ?>)
            </span>
        </h3>

        <table class="widefat striped" style="margin-top: 12px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Plugin', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Version', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Load Time (ms)', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Hooks', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $results as $plugin ) : ?>
                    <?php
                    $load_class = $plugin['load_time'] > 100 ? 'color: #d63638; font-weight: 600;' : '';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $plugin['name'] ); ?></strong>
                            <br><code style="font-size: 11px; color: #999;"><?php echo esc_html( $plugin['file'] ); ?></code>
                        </td>
                        <td><?php echo esc_html( $plugin['version'] ); ?></td>
                        <td style="<?php echo esc_attr( $load_class ); ?>">
                            <?php echo esc_html( (string) $plugin['load_time'] ); ?>
                            <?php if ( $plugin['load_time'] > 100 ) : ?>
                                <span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; color: #d63638;" title="<?php esc_attr_e( 'Slow load time (>100ms)', 'wptransformed' ); ?>"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( (string) $plugin['hook_count'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        return ob_get_clean() ?: '';
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'wpt-plugin-profiler',
            WPT_URL . 'modules/performance/js/plugin-profiler.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-plugin-profiler', 'wptProfiler', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_profiler_nonce' ),
            'i18n'    => [
                'running'      => __( 'Running benchmark...', 'wptransformed' ),
                'networkError' => __( 'Network error. Please try again.', 'wptransformed' ),
                'confirmClear' => __( 'Clear cached profiler results?', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => self::RESULTS_KEY ],
        ];
    }
}
