<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Duplicate Widget — Add a "Duplicate" action to widgets in the
 * classic Widgets screen, supporting both legacy and block widgets.
 *
 * @package WPTransformed
 */
class Duplicate_Widget extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'duplicate-widget';
    }

    public function get_title(): string {
        return __( 'Duplicate Widget', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Add a one-click Duplicate button to widgets on the classic Widgets screen.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Add duplicate link inside widget forms (classic widgets screen).
        add_action( 'in_widget_form', [ $this, 'render_duplicate_link' ], 10, 3 );

        // AJAX handler for duplication.
        add_action( 'wp_ajax_wpt_duplicate_widget', [ $this, 'ajax_duplicate_widget' ] );

        // Enqueue inline JS on the widgets page.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Widget Form Link ──────────────────────────────────────

    /**
     * Render a "Duplicate" link inside each widget form.
     *
     * @param \WP_Widget $widget   Widget instance.
     * @param mixed      $return   Return value (unused).
     * @param array      $instance Widget settings.
     */
    public function render_duplicate_link( \WP_Widget $widget, $return, array $instance ): void {
        $widget_id = $widget->id;
        ?>
        <div class="wpt-duplicate-widget-wrap" style="padding: 8px 0 4px; border-top: 1px solid #ddd; margin-top: 8px;">
            <button type="button"
                    class="button wpt-duplicate-widget-btn"
                    data-widget-id="<?php echo esc_attr( $widget_id ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpt_duplicate_widget_' . $widget_id ) ); ?>">
                <?php esc_html_e( 'Duplicate', 'wptransformed' ); ?>
            </button>
            <span class="wpt-duplicate-status" style="margin-left: 8px;"></span>
        </div>
        <?php
    }

    // ── AJAX Handler ──────────────────────────────────────────

    /**
     * Handle AJAX request to duplicate a widget.
     */
    public function ajax_duplicate_widget(): void {
        // Verify nonce.
        $widget_id = isset( $_POST['widget_id'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_id'] ) ) : '';
        $nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $widget_id || ! wp_verify_nonce( $nonce, 'wpt_duplicate_widget_' . $widget_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wptransformed' ) ] );
        }

        // Verify capability.
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        // Parse the widget ID: format is {type}-{number} (e.g., "text-3").
        $parsed = $this->parse_widget_id( $widget_id );
        if ( ! $parsed ) {
            wp_send_json_error( [ 'message' => __( 'Invalid widget ID.', 'wptransformed' ) ] );
        }

        $widget_type = $parsed['type'];
        $instance_number = $parsed['number'];

        // Get all instances for this widget type.
        $option_name = 'widget_' . $widget_type;
        $instances   = get_option( $option_name, [] );

        if ( ! is_array( $instances ) || ! isset( $instances[ $instance_number ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Widget instance not found.', 'wptransformed' ) ] );
        }

        // Find the next available instance number.
        $new_number = $this->get_next_instance_number( $instances );

        // Copy the instance settings.
        $instances[ $new_number ] = $instances[ $instance_number ];

        // Update the option.
        update_option( $option_name, $instances );

        // Find which sidebar the original widget is in and add the clone there.
        $sidebars = wp_get_sidebars_widgets();
        $new_widget_id = $widget_type . '-' . $new_number;
        $placed = false;

        foreach ( $sidebars as $sidebar_id => $widgets ) {
            if ( ! is_array( $widgets ) ) {
                continue;
            }
            $position = array_search( $widget_id, $widgets, true );
            if ( $position !== false ) {
                // Insert the new widget right after the original.
                array_splice( $sidebars[ $sidebar_id ], $position + 1, 0, [ $new_widget_id ] );
                $placed = true;
                break;
            }
        }

        if ( ! $placed ) {
            // If not found in any sidebar, add to wp_inactive_widgets.
            if ( ! isset( $sidebars['wp_inactive_widgets'] ) ) {
                $sidebars['wp_inactive_widgets'] = [];
            }
            $sidebars['wp_inactive_widgets'][] = $new_widget_id;
        }

        wp_set_sidebars_widgets( $sidebars );

        wp_send_json_success( [
            'message'       => __( 'Widget duplicated successfully. Reload the page to see the copy.', 'wptransformed' ),
            'new_widget_id' => $new_widget_id,
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Parse a widget ID into type and instance number.
     *
     * Widget IDs follow the format: {type}-{number}
     * Some widget types contain hyphens (e.g., "nav_menu-2", "custom_html-5").
     *
     * @param string $widget_id Full widget ID.
     * @return array{type:string,number:int}|null
     */
    private function parse_widget_id( string $widget_id ): ?array {
        // The instance number is always the last segment after the final hyphen.
        $last_dash = strrpos( $widget_id, '-' );
        if ( $last_dash === false || $last_dash === 0 ) {
            return null;
        }

        $type   = substr( $widget_id, 0, $last_dash );
        $number = substr( $widget_id, $last_dash + 1 );

        if ( ! is_numeric( $number ) || (int) $number < 1 ) {
            return null;
        }

        return [
            'type'   => $type,
            'number' => (int) $number,
        ];
    }

    /**
     * Get the next available instance number for a widget option array.
     *
     * @param array $instances Existing instances.
     * @return int Next instance number.
     */
    private function get_next_instance_number( array $instances ): int {
        $max = 0;
        foreach ( array_keys( $instances ) as $key ) {
            if ( is_int( $key ) && $key > $max ) {
                $max = $key;
            }
        }
        return $max + 1;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>

        <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Adds a "Duplicate" button to each widget on the classic Widgets screen, allowing you to clone widget settings with one click.', 'wptransformed' ); ?>
            </p>
        </div>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
                        <?php esc_html_e( 'Show "Duplicate" button on widgets', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'widgets.php' ) {
            return;
        }

        wp_register_script( 'wpt-duplicate-widget', '', [], WPT_VERSION, true );
        wp_enqueue_script( 'wpt-duplicate-widget' );

        wp_localize_script( 'wpt-duplicate-widget', 'wptDuplicateWidget', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );

        wp_add_inline_script( 'wpt-duplicate-widget', $this->get_inline_js() );
    }

    /**
     * Return inline JS for the duplicate widget button.
     */
    private function get_inline_js(): string {
        return <<<'JS'
(function() {
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.wpt-duplicate-widget-btn');
        if (!btn) return;

        e.preventDefault();

        var widgetId = btn.getAttribute('data-widget-id');
        var nonce    = btn.getAttribute('data-nonce');
        var status   = btn.nextElementSibling;

        btn.disabled = true;
        if (status) status.textContent = '...';

        var data = new FormData();
        data.append('action', 'wpt_duplicate_widget');
        data.append('widget_id', widgetId);
        data.append('nonce', nonce);

        fetch(wptDuplicateWidget.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success) {
                if (status) {
                    status.textContent = resp.data.message;
                    status.style.color = '#00a32a';
                }
            } else {
                if (status) {
                    status.textContent = resp.data.message || 'Error';
                    status.style.color = '#d63638';
                }
                btn.disabled = false;
            }
        })
        .catch(function() {
            if (status) {
                status.textContent = 'Request failed.';
                status.style.color = '#d63638';
            }
            btn.disabled = false;
        });
    });
})();
JS;
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
