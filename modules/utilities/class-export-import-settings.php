<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Export/Import Settings — Export all WPTransformed module states
 * and settings as JSON, import from a previously exported file,
 * or reset everything to defaults.
 *
 * Sensitive data (SMTP passwords) is excluded from exports.
 *
 * @package WPTransformed
 */
class Export_Import_Settings extends Module_Base {

    /**
     * Settings keys that should be masked/excluded from exports
     * because they contain sensitive data.
     *
     * Format: module_id => [ setting_key, ... ]
     */
    private const SENSITIVE_KEYS = [
        'email-smtp' => [ 'password', 'smtp_password' ],
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'export-import-settings';
    }

    public function get_title(): string {
        return __( 'Export / Import Settings', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Export, import, or reset all WPTransformed module settings.', 'wptransformed' );
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // AJAX handlers for export, import, and reset.
        add_action( 'wp_ajax_wpt_export_settings', [ $this, 'ajax_export' ] );
        add_action( 'wp_ajax_wpt_import_settings', [ $this, 'ajax_import' ] );
        add_action( 'wp_ajax_wpt_reset_settings', [ $this, 'ajax_reset' ] );
    }

    // ── Export Handler ────────────────────────────────────────

    /**
     * AJAX: Export all module settings as a JSON download.
     */
    public function ajax_export(): void {
        if ( ! check_ajax_referer( 'wpt_export_settings', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wptransformed' ) ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT module_id, is_active, settings FROM {$table}",
            ARRAY_A
        );

        $export = [
            'plugin'     => 'wptransformed',
            'version'    => defined( 'WPT_VERSION' ) ? WPT_VERSION : '1.0.0',
            'exported_at' => gmdate( 'c' ),
            'modules'    => [],
        ];

        if ( $rows ) {
            foreach ( $rows as $row ) {
                $module_id = $row['module_id'];
                $settings  = json_decode( $row['settings'], true ) ?: [];

                // Strip sensitive data.
                $settings = $this->strip_sensitive( $module_id, $settings );

                $export['modules'][ $module_id ] = [
                    'is_active' => (bool) $row['is_active'],
                    'settings'  => $settings,
                ];
            }
        }

        wp_send_json_success( [ 'data' => $export ] );
    }

    // ── Import Handler ────────────────────────────────────────

    /**
     * AJAX: Import settings from an uploaded JSON file.
     */
    public function ajax_import(): void {
        if ( ! check_ajax_referer( 'wpt_import_settings', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wptransformed' ) ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        if ( empty( $_FILES['import_file'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'wptransformed' ) ] );
        }

        $file = $_FILES['import_file'];

        // Validate file type.
        $finfo = wp_check_filetype( $file['name'], [ 'json' => 'application/json' ] );
        if ( empty( $finfo['ext'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid file type. Only JSON files are accepted.', 'wptransformed' ) ] );
        }

        // Read and parse JSON.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_get_contents( $file['tmp_name'] );
        if ( $contents === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to read uploaded file.', 'wptransformed' ) ] );
        }

        // Limit file size to 1MB.
        if ( strlen( $contents ) > 1048576 ) {
            wp_send_json_error( [ 'message' => __( 'File too large. Maximum 1MB.', 'wptransformed' ) ] );
        }

        $data = json_decode( $contents, true );

        if ( ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid JSON format.', 'wptransformed' ) ] );
        }

        // Validate structure.
        if ( empty( $data['plugin'] ) || $data['plugin'] !== 'wptransformed' ) {
            wp_send_json_error( [ 'message' => __( 'This file does not appear to be a WPTransformed export.', 'wptransformed' ) ] );
        }

        if ( empty( $data['modules'] ) || ! is_array( $data['modules'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No module data found in the import file.', 'wptransformed' ) ] );
        }

        // Apply settings.
        global $wpdb;
        $table   = $wpdb->prefix . 'wpt_settings';
        $updated = 0;
        $skipped = 0;

        foreach ( $data['modules'] as $module_id => $module_data ) {
            // Sanitize module_id.
            $module_id = sanitize_key( $module_id );
            if ( strlen( $module_id ) > 64 || strlen( $module_id ) < 1 ) {
                $skipped++;
                continue;
            }

            $is_active = ! empty( $module_data['is_active'] );
            $settings  = isset( $module_data['settings'] ) && is_array( $module_data['settings'] )
                ? $module_data['settings']
                : [];

            // SECURITY: Pass imported settings through module's sanitize_settings()
            // to prevent injection of unsanitized values (e.g., enabling PHP snippets).
            $core = \WPTransformed\Core\Core::get_instance();
            if ( $core && method_exists( $core, 'get_module' ) ) {
                $module = $core->get_module( $module_id );
                if ( $module ) {
                    $settings = $module->sanitize_settings( $settings );
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $result = $wpdb->replace(
                $table,
                [
                    'module_id' => $module_id,
                    'is_active' => (int) $is_active,
                    'settings'  => wp_json_encode( $settings ),
                ],
                [ '%s', '%d', '%s' ]
            );

            if ( $result !== false ) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: 1: number of modules imported, 2: number skipped */
                __( 'Import complete. %1$d module(s) imported, %2$d skipped.', 'wptransformed' ),
                $updated,
                $skipped
            ),
        ] );
    }

    // ── Reset Handler ─────────────────────────────────────────

    /**
     * AJAX: Reset all module settings to defaults.
     */
    public function ajax_reset(): void {
        if ( ! check_ajax_referer( 'wpt_reset_settings', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wptransformed' ) ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        // Delete all rows — modules will recreate defaults on next load.
        // Uses DELETE instead of TRUNCATE for broader hosting compatibility.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DELETE FROM {$table}" );

        wp_send_json_success( [
            'message' => __( 'All settings have been reset to defaults. The page will reload.', 'wptransformed' ),
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Remove sensitive keys from a module's settings before export.
     *
     * @param string $module_id Module ID.
     * @param array  $settings  Module settings.
     * @return array Sanitized settings.
     */
    private function strip_sensitive( string $module_id, array $settings ): array {
        if ( ! isset( self::SENSITIVE_KEYS[ $module_id ] ) ) {
            return $settings;
        }

        foreach ( self::SENSITIVE_KEYS[ $module_id ] as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $settings[ $key ] = '***REMOVED***';
            }
        }

        return $settings;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $export_nonce = wp_create_nonce( 'wpt_export_settings' );
        $import_nonce = wp_create_nonce( 'wpt_import_settings' );
        $reset_nonce  = wp_create_nonce( 'wpt_reset_settings' );
        $ajax_url     = admin_url( 'admin-ajax.php' );
        ?>

        <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Export your settings to transfer them to another site, import a previously exported file, or reset everything to defaults.', 'wptransformed' ); ?>
            </p>
        </div>

        <div style="display: grid; gap: 20px; max-width: 700px;">

            <?php // Export Card ?>
            <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px;">
                <h3 style="margin: 0 0 8px;"><?php esc_html_e( 'Export Settings', 'wptransformed' ); ?></h3>
                <p class="description" style="margin: 0 0 12px;">
                    <?php esc_html_e( 'Download all module states and settings as a JSON file. Sensitive data (e.g., SMTP passwords) is excluded automatically.', 'wptransformed' ); ?>
                </p>
                <button type="button" class="button button-primary" id="wpt-export-btn">
                    <?php esc_html_e( 'Export Settings', 'wptransformed' ); ?>
                </button>
                <span id="wpt-export-status" style="margin-left: 8px;"></span>
            </div>

            <?php // Import Card ?>
            <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px;">
                <h3 style="margin: 0 0 8px;"><?php esc_html_e( 'Import Settings', 'wptransformed' ); ?></h3>
                <p class="description" style="margin: 0 0 12px;">
                    <?php esc_html_e( 'Upload a previously exported JSON file to restore settings. This will overwrite current settings for imported modules.', 'wptransformed' ); ?>
                </p>
                <input type="file" id="wpt-import-file" accept=".json" style="margin-bottom: 8px;">
                <br>
                <button type="button" class="button" id="wpt-import-btn" disabled>
                    <?php esc_html_e( 'Import Settings', 'wptransformed' ); ?>
                </button>
                <span id="wpt-import-status" style="margin-left: 8px;"></span>
            </div>

            <?php // Reset Card ?>
            <div style="background: #fff; border: 1px solid #d63638; border-radius: 4px; padding: 20px;">
                <h3 style="margin: 0 0 8px; color: #d63638;"><?php esc_html_e( 'Reset All Settings', 'wptransformed' ); ?></h3>
                <p class="description" style="margin: 0 0 12px;">
                    <?php esc_html_e( 'Reset all module settings to their defaults and deactivate all modules. This cannot be undone.', 'wptransformed' ); ?>
                </p>
                <button type="button" class="button" id="wpt-reset-btn" style="color: #d63638; border-color: #d63638;">
                    <?php esc_html_e( 'Reset All Settings', 'wptransformed' ); ?>
                </button>
                <span id="wpt-reset-status" style="margin-left: 8px;"></span>
            </div>

        </div>

        <script>
        (function() {
            var ajaxUrl     = <?php echo wp_json_encode( $ajax_url ); ?>;
            var exportNonce = <?php echo wp_json_encode( $export_nonce ); ?>;
            var importNonce = <?php echo wp_json_encode( $import_nonce ); ?>;
            var resetNonce  = <?php echo wp_json_encode( $reset_nonce ); ?>;

            // ── Export ──
            var exportBtn    = document.getElementById('wpt-export-btn');
            var exportStatus = document.getElementById('wpt-export-status');

            exportBtn.addEventListener('click', function() {
                exportBtn.disabled = true;
                exportStatus.textContent = '<?php echo esc_js( __( 'Exporting...', 'wptransformed' ) ); ?>';

                var data = new FormData();
                data.append('action', 'wpt_export_settings');
                data.append('nonce', exportNonce);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.success) {
                            var json = JSON.stringify(resp.data.data, null, 2);
                            var blob = new Blob([json], { type: 'application/json' });
                            var url  = URL.createObjectURL(blob);
                            var a    = document.createElement('a');
                            a.href     = url;
                            a.download = 'wptransformed-settings-' + new Date().toISOString().slice(0,10) + '.json';
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                            exportStatus.textContent = '<?php echo esc_js( __( 'Downloaded!', 'wptransformed' ) ); ?>';
                            exportStatus.style.color = '#00a32a';
                        } else {
                            exportStatus.textContent = resp.data.message || 'Error';
                            exportStatus.style.color = '#d63638';
                        }
                        exportBtn.disabled = false;
                    })
                    .catch(function() {
                        exportStatus.textContent = '<?php echo esc_js( __( 'Request failed.', 'wptransformed' ) ); ?>';
                        exportStatus.style.color = '#d63638';
                        exportBtn.disabled = false;
                    });
            });

            // ── Import ──
            var importFile   = document.getElementById('wpt-import-file');
            var importBtn    = document.getElementById('wpt-import-btn');
            var importStatus = document.getElementById('wpt-import-status');

            importFile.addEventListener('change', function() {
                importBtn.disabled = !importFile.files.length;
                importStatus.textContent = '';
            });

            importBtn.addEventListener('click', function() {
                if (!importFile.files.length) return;

                importBtn.disabled = true;
                importStatus.textContent = '<?php echo esc_js( __( 'Importing...', 'wptransformed' ) ); ?>';

                var data = new FormData();
                data.append('action', 'wpt_import_settings');
                data.append('nonce', importNonce);
                data.append('import_file', importFile.files[0]);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.success) {
                            importStatus.textContent = resp.data.message;
                            importStatus.style.color = '#00a32a';
                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            importStatus.textContent = resp.data.message || 'Error';
                            importStatus.style.color = '#d63638';
                            importBtn.disabled = false;
                        }
                    })
                    .catch(function() {
                        importStatus.textContent = '<?php echo esc_js( __( 'Request failed.', 'wptransformed' ) ); ?>';
                        importStatus.style.color = '#d63638';
                        importBtn.disabled = false;
                    });
            });

            // ── Reset ──
            var resetBtn    = document.getElementById('wpt-reset-btn');
            var resetStatus = document.getElementById('wpt-reset-status');

            resetBtn.addEventListener('click', function() {
                var confirmed = confirm('<?php echo esc_js( __( 'Are you sure you want to reset ALL settings to defaults? This cannot be undone.', 'wptransformed' ) ); ?>');
                if (!confirmed) return;

                // Double confirmation for safety.
                var doubleConfirmed = confirm('<?php echo esc_js( __( 'This will deactivate all modules and erase all saved settings. Continue?', 'wptransformed' ) ); ?>');
                if (!doubleConfirmed) return;

                resetBtn.disabled = true;
                resetStatus.textContent = '<?php echo esc_js( __( 'Resetting...', 'wptransformed' ) ); ?>';

                var data = new FormData();
                data.append('action', 'wpt_reset_settings');
                data.append('nonce', resetNonce);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.success) {
                            resetStatus.textContent = resp.data.message;
                            resetStatus.style.color = '#00a32a';
                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            resetStatus.textContent = resp.data.message || 'Error';
                            resetStatus.style.color = '#d63638';
                            resetBtn.disabled = false;
                        }
                    })
                    .catch(function() {
                        resetStatus.textContent = '<?php echo esc_js( __( 'Request failed.', 'wptransformed' ) ); ?>';
                        resetStatus.style.color = '#d63638';
                        resetBtn.disabled = false;
                    });
            });
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // This module has no configurable settings of its own.
        return [];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // JS is inlined in render_settings() — no external assets needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
