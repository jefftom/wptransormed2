<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Hide Admin Notices — Dashboard widget + text link approach.
 *
 * Dashboard page: notices are hidden via CSS, then JS moves them into a
 * "Notifications" dashboard widget at the top of the normal column.
 *
 * Other admin pages: notices are hidden via CSS, a small text link shows
 * the count and links to the Dashboard.
 *
 * No output buffering.
 *
 * @package WPTransformed
 */
class Hide_Admin_Notices extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'hide-admin-notices';
    }

    public function get_title(): string {
        return __( 'Hide Admin Notices', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Hide admin notices and collect them into a Dashboard widget. Other pages show a minimal notification link.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'scope' => 'all',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
    }

    // ── Dashboard Widget ──────────────────────────────────────

    /**
     * Register the Notifications widget and move it to the top.
     */
    public function register_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'wpt_notices_widget',
            __( 'Notifications', 'wptransformed' ),
            [ $this, 'render_dashboard_widget' ]
        );

        // Move widget to top of the normal column.
        global $wp_meta_boxes;
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        $page = $screen->id; // 'dashboard'

        if ( ! isset( $wp_meta_boxes[ $page ]['normal']['core']['wpt_notices_widget'] ) ) {
            return;
        }

        $widget = $wp_meta_boxes[ $page ]['normal']['core']['wpt_notices_widget'];
        unset( $wp_meta_boxes[ $page ]['normal']['core']['wpt_notices_widget'] );

        // Prepend to the 'high' priority bucket so it renders first.
        if ( ! isset( $wp_meta_boxes[ $page ]['normal']['high'] ) ) {
            $wp_meta_boxes[ $page ]['normal']['high'] = [];
        }
        $wp_meta_boxes[ $page ]['normal']['high'] = array_merge(
            [ 'wpt_notices_widget' => $widget ],
            $wp_meta_boxes[ $page ]['normal']['high']
        );
    }

    /**
     * Render the widget content.
     * JS will populate this container with moved notices.
     */
    public function render_dashboard_widget(): void {
        ?>
        <div id="wpt-notices-widget-content">
            <p class="wpt-no-notices"><?php esc_html_e( 'No notifications.', 'wptransformed' ); ?></p>
        </div>
        <div id="wpt-notices-widget-footer" style="display:none;">
            <button type="button" class="button wpt-dismiss-all">
                <?php esc_html_e( 'Dismiss All', 'wptransformed' ); ?>
            </button>
        </div>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! $this->should_run( $hook ) ) {
            return;
        }

        $is_dashboard = ( $hook === 'index.php' );

        // CSS file for widget + text link styling
        wp_enqueue_style(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/css/hide-admin-notices.css',
            [],
            WPT_VERSION
        );

        // Inline CSS that immediately hides notices (no flash)
        $hide_css = '#wpbody-content .notice,'
            . ' #wpbody-content .updated,'
            . ' #wpbody-content .error,'
            . ' #wpbody-content .update-nag'
            . ' { display: none !important; }';

        // On the dashboard, also keep notices inside the widget visible
        if ( $is_dashboard ) {
            $hide_css = '#wpbody-content .notice:not(#wpt-notices-widget-content .notice),'
                . ' #wpbody-content .updated:not(#wpt-notices-widget-content .updated),'
                . ' #wpbody-content .error:not(#wpt-notices-widget-content .error),'
                . ' #wpbody-content .update-nag:not(#wpt-notices-widget-content .update-nag)'
                . ' { display: none !important; }';
        }

        wp_add_inline_style( 'wpt-hide-admin-notices', $hide_css );

        // JS that collects hidden notices
        wp_enqueue_script(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/js/hide-admin-notices.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-hide-admin-notices', 'wptHideNotices', [
            'isDashboard'  => $is_dashboard,
            'dashboardUrl' => esc_url( admin_url( 'index.php' ) ),
            'i18n'         => [
                'noNotifications' => __( 'No notifications.', 'wptransformed' ),
                'dismissAll'      => __( 'Dismiss All', 'wptransformed' ),
                /* translators: %d: number of notices */
                'oneNotification' => __( '%d notification', 'wptransformed' ),
                /* translators: %d: number of notices */
                'manyNotifications' => __( '%d notifications', 'wptransformed' ),
                'viewDashboard'   => __( 'View Dashboard', 'wptransformed' ),
            ],
        ] );
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the module should run on this page based on scope setting.
     */
    private function should_run( string $hook ): bool {
        $settings = $this->get_settings();

        if ( $settings['scope'] === 'wpt-only' ) {
            return ( strpos( $hook, 'wptransformed' ) !== false );
        }

        return true; // scope = 'all'
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">

            <tr>
                <th scope="row"><?php esc_html_e( 'Scope', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="radio" name="wpt_scope" value="all" <?php checked( $settings['scope'], 'all' ); ?>>
                            <?php esc_html_e( 'Hide notices on all admin pages', 'wptransformed' ); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="wpt_scope" value="wpt-only" <?php checked( $settings['scope'], 'wpt-only' ); ?>>
                            <?php esc_html_e( 'Only on WPTransformed pages', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_scopes = [ 'all', 'wpt-only' ];

        return [
            'scope' => in_array( $raw['wpt_scope'] ?? '', $valid_scopes, true )
                        ? $raw['wpt_scope'] : 'all',
        ];
    }
}
