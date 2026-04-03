<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * System Summary — Display comprehensive system information.
 *
 * Features:
 *  - PHP/MySQL/WP versions
 *  - Active theme and server software
 *  - Memory limit, max execution time, upload limits
 *  - PHP extensions, active plugins
 *  - Debug constants, filesystem permissions
 *  - Cron status
 *  - Copy to Clipboard button
 *  - All read-only (no settings beyond enabled)
 *
 * @package WPTransformed
 */
class System_Summary extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'system-summary';
    }

    public function get_title(): string {
        return __( 'System Summary', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'View comprehensive system information including PHP, MySQL, WordPress versions, server details, and more.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Settings UI (doubles as the info page) ────────────────

    public function render_settings(): void {
        $info = $this->gather_system_info();

        ?>
        <div style="margin-bottom: 16px;">
            <button type="button" class="button button-secondary" id="wpt-copy-system-info">
                <?php esc_html_e( 'Copy to Clipboard', 'wptransformed' ); ?>
            </button>
            <span id="wpt-copy-status" style="margin-left: 8px; color: #46b450; display: none;">
                <?php esc_html_e( 'Copied!', 'wptransformed' ); ?>
            </span>
        </div>

        <div id="wpt-system-info-container">
            <?php $this->render_section( __( 'WordPress', 'wptransformed' ), $info['wordpress'] ); ?>
            <?php $this->render_section( __( 'Server', 'wptransformed' ), $info['server'] ); ?>
            <?php $this->render_section( __( 'PHP', 'wptransformed' ), $info['php'] ); ?>
            <?php $this->render_section( __( 'Database', 'wptransformed' ), $info['database'] ); ?>
            <?php $this->render_section( __( 'Active Theme', 'wptransformed' ), $info['theme'] ); ?>
            <?php $this->render_section( __( 'Active Plugins', 'wptransformed' ), $info['plugins'] ); ?>
            <?php $this->render_section( __( 'Debug Constants', 'wptransformed' ), $info['debug'] ); ?>
            <?php $this->render_section( __( 'Filesystem', 'wptransformed' ), $info['filesystem'] ); ?>
            <?php $this->render_section( __( 'Cron', 'wptransformed' ), $info['cron'] ); ?>
        </div>

        <textarea id="wpt-system-info-text" style="display:none;" readonly></textarea>

        <script>
        (function() {
            var btn = document.getElementById('wpt-copy-system-info');
            var status = document.getElementById('wpt-copy-status');
            if (!btn) return;

            btn.addEventListener('click', function() {
                var text = wptBuildPlainText();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function() {
                        showCopied();
                    });
                } else {
                    var ta = document.getElementById('wpt-system-info-text');
                    ta.style.display = 'block';
                    ta.value = text;
                    ta.select();
                    document.execCommand('copy');
                    ta.style.display = 'none';
                    showCopied();
                }
            });

            function showCopied() {
                status.style.display = 'inline';
                setTimeout(function() { status.style.display = 'none'; }, 2000);
            }

            function wptBuildPlainText() {
                var sections = document.querySelectorAll('#wpt-system-info-container .wpt-info-section');
                var lines = [];
                sections.forEach(function(section) {
                    var heading = section.querySelector('h3');
                    if (heading) lines.push('### ' + heading.textContent.trim());
                    var rows = section.querySelectorAll('tr');
                    rows.forEach(function(row) {
                        var th = row.querySelector('th');
                        var td = row.querySelector('td');
                        if (th && td) {
                            lines.push(th.textContent.trim() + ': ' + td.textContent.trim());
                        }
                    });
                    lines.push('');
                });
                return lines.join('\n');
            }
        })();
        </script>
        <?php
    }

    /**
     * Render a section of system info.
     *
     * @param string               $title Section title.
     * @param array<string,string> $items Key-value pairs.
     */
    private function render_section( string $title, array $items ): void {
        if ( empty( $items ) ) {
            return;
        }

        ?>
        <div class="wpt-info-section" style="margin-bottom: 20px;">
            <h3 style="margin: 0 0 8px 0; padding: 8px 12px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                <?php echo esc_html( $title ); ?>
            </h3>
            <table class="widefat striped" style="max-width: 800px;">
                <tbody>
                    <?php foreach ( $items as $label => $value ) : ?>
                        <tr>
                            <th style="width: 35%; font-weight: 600; padding: 8px 12px;"><?php echo esc_html( $label ); ?></th>
                            <td style="padding: 8px 12px;"><?php echo esc_html( $value ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Gather all system information.
     *
     * @return array<string,array<string,string>>
     */
    private function gather_system_info(): array {
        global $wpdb;

        return [
            'wordpress'  => $this->get_wordpress_info(),
            'server'     => $this->get_server_info(),
            'php'        => $this->get_php_info(),
            'database'   => $this->get_database_info(),
            'theme'      => $this->get_theme_info(),
            'plugins'    => $this->get_plugins_info(),
            'debug'      => $this->get_debug_info(),
            'filesystem' => $this->get_filesystem_info(),
            'cron'       => $this->get_cron_info(),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_wordpress_info(): array {
        global $wp_version;

        return [
            __( 'Version', 'wptransformed' )     => $wp_version,
            __( 'Site URL', 'wptransformed' )     => get_site_url(),
            __( 'Home URL', 'wptransformed' )     => get_home_url(),
            __( 'Multisite', 'wptransformed' )    => is_multisite() ? __( 'Yes', 'wptransformed' ) : __( 'No', 'wptransformed' ),
            __( 'Language', 'wptransformed' )     => get_locale(),
            __( 'Permalink', 'wptransformed' )    => get_option( 'permalink_structure' ) ?: __( 'Plain', 'wptransformed' ),
            __( 'Timezone', 'wptransformed' )     => wp_timezone_string(),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_server_info(): array {
        return [
            __( 'Server Software', 'wptransformed' ) => sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? __( 'Unknown', 'wptransformed' ) ) ),
            __( 'Server OS', 'wptransformed' )        => PHP_OS,
            __( 'Server Architecture', 'wptransformed' ) => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_php_info(): array {
        $extensions = get_loaded_extensions();
        sort( $extensions );

        return [
            __( 'Version', 'wptransformed' )           => PHP_VERSION,
            __( 'SAPI', 'wptransformed' )              => PHP_SAPI,
            __( 'Memory Limit', 'wptransformed' )      => ini_get( 'memory_limit' ) ?: __( 'Unknown', 'wptransformed' ),
            __( 'Max Execution Time', 'wptransformed' ) => ini_get( 'max_execution_time' ) . 's',
            __( 'Max Input Vars', 'wptransformed' )    => ini_get( 'max_input_vars' ) ?: __( 'Unknown', 'wptransformed' ),
            __( 'Upload Max Filesize', 'wptransformed' ) => ini_get( 'upload_max_filesize' ) ?: __( 'Unknown', 'wptransformed' ),
            __( 'Post Max Size', 'wptransformed' )     => ini_get( 'post_max_size' ) ?: __( 'Unknown', 'wptransformed' ),
            __( 'WP Max Upload', 'wptransformed' )     => size_format( wp_max_upload_size() ),
            __( 'Extensions', 'wptransformed' )        => implode( ', ', $extensions ),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_database_info(): array {
        global $wpdb;

        $db_version = $wpdb->get_var( 'SELECT VERSION()' ) ?? __( 'Unknown', 'wptransformed' );

        return [
            __( 'Server', 'wptransformed' )    => (string) $db_version,
            __( 'Client', 'wptransformed' )    => $wpdb->db_version() ?: __( 'Unknown', 'wptransformed' ),
            __( 'Charset', 'wptransformed' )   => $wpdb->charset ?: __( 'Unknown', 'wptransformed' ),
            __( 'Collation', 'wptransformed' ) => $wpdb->collate ?: __( 'Default', 'wptransformed' ),
            __( 'Prefix', 'wptransformed' )    => $wpdb->prefix,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_theme_info(): array {
        $theme = wp_get_theme();

        $info = [
            __( 'Name', 'wptransformed' )    => $theme->get( 'Name' ),
            __( 'Version', 'wptransformed' ) => $theme->get( 'Version' ),
            __( 'Author', 'wptransformed' )  => $theme->get( 'Author' ),
        ];

        if ( $theme->parent() ) {
            $info[ __( 'Parent Theme', 'wptransformed' ) ] = $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' );
        }

        return $info;
    }

    /**
     * @return array<string,string>
     */
    private function get_plugins_info(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = get_option( 'active_plugins', [] );
        $all_plugins    = get_plugins();
        $info           = [];

        foreach ( $active_plugins as $plugin_file ) {
            if ( isset( $all_plugins[ $plugin_file ] ) ) {
                $name    = $all_plugins[ $plugin_file ]['Name'] ?? $plugin_file;
                $version = $all_plugins[ $plugin_file ]['Version'] ?? '';
                $info[ $name ] = $version;
            }
        }

        if ( empty( $info ) ) {
            $info[ __( 'Active Plugins', 'wptransformed' ) ] = __( 'None', 'wptransformed' );
        }

        return $info;
    }

    /**
     * @return array<string,string>
     */
    private function get_debug_info(): array {
        return [
            'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
            'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
            'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
            'SCRIPT_DEBUG'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
            'WP_CACHE'         => defined( 'WP_CACHE' ) && WP_CACHE ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
            'CONCATENATE_SCRIPTS' => defined( 'CONCATENATE_SCRIPTS' ) && CONCATENATE_SCRIPTS ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
            'COMPRESS_SCRIPTS' => defined( 'COMPRESS_SCRIPTS' ) && COMPRESS_SCRIPTS ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
            'COMPRESS_CSS'     => defined( 'COMPRESS_CSS' ) && COMPRESS_CSS ? __( 'Enabled', 'wptransformed' ) : __( 'Disabled', 'wptransformed' ),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function get_filesystem_info(): array {
        $paths = [
            'wp-content'  => WP_CONTENT_DIR,
            'uploads'     => wp_upload_dir()['basedir'],
            'plugins'     => WP_PLUGIN_DIR,
            'themes'      => get_theme_root(),
        ];

        $info = [];

        foreach ( $paths as $label => $path ) {
            $writable = wp_is_writable( $path );
            $info[ $label ] = $writable
                ? sprintf( '%s (%s)', $path, __( 'Writable', 'wptransformed' ) )
                : sprintf( '%s (%s)', $path, __( 'Not Writable', 'wptransformed' ) );
        }

        return $info;
    }

    /**
     * @return array<string,string>
     */
    private function get_cron_info(): array {
        $crons = _get_cron_array();

        if ( ! is_array( $crons ) || empty( $crons ) ) {
            return [
                __( 'Status', 'wptransformed' ) => __( 'No scheduled events', 'wptransformed' ),
            ];
        }

        $total   = 0;
        $next_ts = PHP_INT_MAX;

        foreach ( $crons as $timestamp => $hooks ) {
            if ( is_array( $hooks ) ) {
                $total += count( $hooks );
                if ( $timestamp < $next_ts ) {
                    $next_ts = $timestamp;
                }
            }
        }

        $disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

        return [
            __( 'WP-Cron', 'wptransformed' )        => $disabled ? __( 'Disabled (DISABLE_WP_CRON)', 'wptransformed' ) : __( 'Enabled', 'wptransformed' ),
            __( 'Scheduled Events', 'wptransformed' ) => (string) $total,
            __( 'Next Event', 'wptransformed' )       => $next_ts < PHP_INT_MAX
                ? wp_date( 'Y-m-d H:i:s', $next_ts )
                : __( 'None', 'wptransformed' ),
        ];
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom assets needed — inline JS handles clipboard.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
