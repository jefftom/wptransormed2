<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Hide Dashboard Widgets — Selectively remove dashboard widgets.
 *
 * Features:
 *  - Remove any registered dashboard widget
 *  - Dynamically lists all registered widgets in settings
 *  - Configurable default hidden widgets
 *  - Per-role option (future use)
 *
 * @package WPTransformed
 */
class Hide_Dashboard_Widgets extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'hide-dashboard-widgets';
    }

    public function get_title(): string {
        return __( 'Hide Dashboard Widgets', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Selectively hide WordPress dashboard widgets to declutter the admin dashboard.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'hidden_widgets' => [ 'dashboard_primary', 'dashboard_quick_press', 'dashboard_site_health' ],
            'per_role'       => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Priority 999 to run after all widgets are registered.
        add_action( 'wp_dashboard_setup', [ $this, 'remove_widgets' ], 999 );

        // Capture available widgets for settings page (must run before removal on settings page).
        add_action( 'wp_dashboard_setup', [ $this, 'capture_widgets' ], 998 );
    }

    // ── Widget Removal ────────────────────────────────────────

    /**
     * Remove hidden widgets from the dashboard.
     */
    public function remove_widgets(): void {
        $settings       = $this->get_settings();
        $hidden_widgets = (array) ( $settings['hidden_widgets'] ?? [] );

        if ( empty( $hidden_widgets ) ) {
            return;
        }

        foreach ( $hidden_widgets as $widget_id ) {
            $widget_id = sanitize_key( $widget_id );
            if ( empty( $widget_id ) ) {
                continue;
            }

            // Try removing from all standard contexts and priorities.
            remove_meta_box( $widget_id, 'dashboard', 'normal' );
            remove_meta_box( $widget_id, 'dashboard', 'side' );
            remove_meta_box( $widget_id, 'dashboard', 'column3' );
            remove_meta_box( $widget_id, 'dashboard', 'column4' );
        }
    }

    /**
     * Capture registered widgets for display in settings.
     * Stores them in a transient so the settings page can list them.
     */
    public function capture_widgets(): void {
        global $wp_meta_boxes;

        if ( empty( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
            return;
        }

        $widgets = [];

        foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
            if ( ! is_array( $priorities ) ) {
                continue;
            }
            foreach ( $priorities as $priority => $boxes ) {
                if ( ! is_array( $boxes ) ) {
                    continue;
                }
                foreach ( $boxes as $id => $box ) {
                    if ( ! is_array( $box ) || empty( $box['title'] ) ) {
                        continue;
                    }
                    $widgets[ $id ] = wp_strip_all_tags( $box['title'] );
                }
            }
        }

        if ( ! empty( $widgets ) ) {
            set_transient( 'wpt_dashboard_widgets_list', $widgets, HOUR_IN_SECONDS );
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings       = $this->get_settings();
        $hidden_widgets = (array) ( $settings['hidden_widgets'] ?? [] );

        // Get cached widget list.
        $widgets = get_transient( 'wpt_dashboard_widgets_list' );

        // Fallback: list common WordPress dashboard widgets.
        if ( ! is_array( $widgets ) || empty( $widgets ) ) {
            $widgets = $this->get_known_widgets();
        }

        // Ensure any currently hidden widgets appear in the list.
        foreach ( $hidden_widgets as $widget_id ) {
            if ( ! isset( $widgets[ $widget_id ] ) ) {
                $widgets[ $widget_id ] = $widget_id;
            }
        }

        ksort( $widgets );

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Hide these widgets', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php if ( empty( $widgets ) ) : ?>
                            <p class="description">
                                <?php esc_html_e( 'No dashboard widgets detected. Visit the Dashboard page first to populate this list.', 'wptransformed' ); ?>
                            </p>
                        <?php else : ?>
                            <?php foreach ( $widgets as $id => $title ) : ?>
                                <label style="display: block; margin-bottom: 6px;">
                                    <input type="checkbox" name="wpt_hidden_widgets[]"
                                           value="<?php echo esc_attr( $id ); ?>"
                                           <?php checked( in_array( $id, $hidden_widgets, true ) ); ?>>
                                    <?php echo esc_html( $title ); ?>
                                    <code style="font-size: 11px; color: #999; margin-left: 4px;"><?php echo esc_html( $id ); ?></code>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e( 'Visit the Dashboard page at least once to detect all registered widgets.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Per-role settings', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_per_role" value="1"
                               <?php checked( ! empty( $settings['per_role'] ) ); ?>>
                        <?php esc_html_e( 'Enable per-role widget visibility (coming soon)', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $hidden_widgets = [];

        if ( isset( $raw['wpt_hidden_widgets'] ) && is_array( $raw['wpt_hidden_widgets'] ) ) {
            foreach ( $raw['wpt_hidden_widgets'] as $widget_id ) {
                $widget_id = sanitize_key( $widget_id );
                if ( ! empty( $widget_id ) ) {
                    $hidden_widgets[] = $widget_id;
                }
            }
        }

        $per_role = ! empty( $raw['wpt_per_role'] );

        return [
            'hidden_widgets' => $hidden_widgets,
            'per_role'       => $per_role,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => 'wpt_dashboard_widgets_list' ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Get list of known WordPress core dashboard widgets.
     *
     * @return array<string,string> Widget ID => title.
     */
    private function get_known_widgets(): array {
        return [
            'dashboard_primary'       => __( 'WordPress Events and News', 'wptransformed' ),
            'dashboard_quick_press'   => __( 'Quick Draft', 'wptransformed' ),
            'dashboard_right_now'     => __( 'At a Glance', 'wptransformed' ),
            'dashboard_activity'      => __( 'Activity', 'wptransformed' ),
            'dashboard_site_health'   => __( 'Site Health Status', 'wptransformed' ),
            'dashboard_incoming_links' => __( 'Incoming Links', 'wptransformed' ),
            'dashboard_plugins'       => __( 'Plugins', 'wptransformed' ),
            'dashboard_recent_drafts' => __( 'Recent Drafts', 'wptransformed' ),
            'dashboard_recent_comments' => __( 'Recent Comments', 'wptransformed' ),
        ];
    }
}
