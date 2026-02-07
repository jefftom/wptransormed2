<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Hide Admin Notices — Dedicated notifications page approach.
 *
 * Uses output buffering on every admin page load to capture notices,
 * stores the count in a per-user transient for the sidebar bubble,
 * and stores the HTML in a per-user transient for the notifications page.
 *
 * Notifications page: renders all captured notices grouped by type.
 * Other pages: CSS hides notices, JS shows a text link with count.
 *
 * @package WPTransformed
 */
class Hide_Admin_Notices extends Module_Base {

    /**
     * Whether output buffering is currently active.
     */
    private bool $is_buffering = false;

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
        return __( 'Hide admin notices and collect them into a dedicated Notifications page under Dashboard.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'scope' => 'all',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_notifications_page' ] );
        add_action( 'admin_notices', [ $this, 'start_capture' ], 1 );
        add_action( 'all_admin_notices', [ $this, 'end_capture' ], PHP_INT_MAX );
    }

    // ── Output Buffering (capture + count) ────────────────────

    /**
     * Start capturing notice output.
     *
     * Runs on admin_notices at priority 1 (very early).
     * Skipped on our own notifications page — notices display there natively.
     */
    public function start_capture(): void {
        if ( $this->is_notifications_page() ) {
            return;
        }

        if ( ! $this->should_run_on_current_page() ) {
            return;
        }

        ob_start();
        $this->is_buffering = true;
    }

    /**
     * End capturing, store count + HTML in per-user transients.
     *
     * Runs on all_admin_notices at PHP_INT_MAX (very late).
     */
    public function end_capture(): void {
        if ( ! $this->is_buffering ) {
            return;
        }

        $html = ob_get_clean();
        $this->is_buffering = false;

        if ( $html === false ) {
            $html = '';
        }

        // Count notice elements in the captured HTML.
        $count     = 0;
        $has_error = false;

        if ( trim( $html ) !== '' ) {
            $count = preg_match_all(
                '/<div[^>]*class="[^"]*\b(?:notice|updated|error|update-nag)\b[^"]*"/',
                $html
            );
            $has_error = (bool) preg_match(
                '/<div[^>]*class="[^"]*\b(?:notice-error|error)\b[^"]*"/',
                $html
            );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        // Store per-user transient (short TTL — refreshed every page load).
        $data = [
            'count'     => $count,
            'has_error' => $has_error,
            'html'      => $html,
        ];
        set_transient( 'wpt_notices_' . $user_id, $data, 5 * MINUTE_IN_SECONDS );
    }

    // ── Admin Page ────────────────────────────────────────────

    /**
     * Register "Notifications" as a top-level menu item (position 3, after Dashboard).
     *
     * The count bubble reads from the transient stored on the previous
     * page load. It will be empty on the very first load.
     */
    public function register_notifications_page(): void {
        $bubble = $this->get_menu_bubble_markup();

        $hook = add_menu_page(
            __( 'Notifications', 'wptransformed' ),
            __( 'Notifications', 'wptransformed' ) . $bubble,
            'read',
            'wpt-notifications',
            [ $this, 'render_notifications_page' ],
            'dashicons-bell',
            3
        );

        // When on the notifications page, we need to re-fire notice hooks
        // so we can capture and display them.
        if ( $hook ) {
            add_action( 'load-' . $hook, [ $this, 'prepare_notifications_page' ] );
        }
    }

    /**
     * Build the count bubble markup from the stored transient.
     */
    private function get_menu_bubble_markup(): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '';
        }

        $data = get_transient( 'wpt_notices_' . $user_id );
        if ( ! is_array( $data ) || empty( $data['count'] ) ) {
            return '';
        }

        $count = (int) $data['count'];

        return ' <span class="awaiting-mod count-' . $count . '">'
            . '<span class="pending-count">' . $count . '</span></span>';
    }

    /**
     * Fires on `load-{page}` for the notifications page.
     *
     * We need notices to fire so we can capture them for display.
     * OB capture in start_capture() is skipped on this page,
     * so notices render directly into the page output.
     */
    public function prepare_notifications_page(): void {
        // Nothing needed — notices fire normally on this page since
        // start_capture() skips it. They will appear in the standard
        // notice area above our page content.
    }

    /**
     * Render the Notifications admin page.
     */
    public function render_notifications_page(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Notifications', 'wptransformed' ); ?></h1>

            <div id="wpt-notifications-page">
                <p class="wpt-notifications-description">
                    <?php esc_html_e( 'Admin notices from across your site are collected here.', 'wptransformed' ); ?>
                </p>
                <noscript>
                    <p><?php esc_html_e( 'Notices are displayed above this section by WordPress.', 'wptransformed' ); ?></p>
                </noscript>
                <div id="wpt-notifications-content">
                    <?php // JS will move and group notices here. ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        $is_notifications_page = ( $hook === 'toplevel_page_wpt-notifications' );

        if ( ! $is_notifications_page && ! $this->should_run_on_current_page() ) {
            return;
        }

        // CSS file for notifications page + text link styling.
        wp_enqueue_style(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/css/hide-admin-notices.css',
            [],
            WPT_VERSION
        );

        // On all pages EXCEPT the notifications page: hide notices with CSS.
        if ( ! $is_notifications_page ) {
            $hide_css = '#wpbody-content .notice,'
                . ' #wpbody-content .updated,'
                . ' #wpbody-content .error,'
                . ' #wpbody-content .update-nag'
                . ' { display: none !important; }';

            wp_add_inline_style( 'wpt-hide-admin-notices', $hide_css );
        }

        // JS for menu bubble updates + text link / notifications page logic.
        wp_enqueue_script(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/js/hide-admin-notices.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-hide-admin-notices', 'wptHideNotices', [
            'isNotificationsPage' => $is_notifications_page,
            'notificationsUrl'    => esc_url( admin_url( 'admin.php?page=wpt-notifications' ) ),
            'i18n'                => [
                'noNotifications'   => __( 'No notifications.', 'wptransformed' ),
                'dismissAll'        => __( 'Dismiss All', 'wptransformed' ),
                'oneNotification'   => __( '%d notification', 'wptransformed' ),
                'manyNotifications' => __( '%d notifications', 'wptransformed' ),
                'viewNotifications' => __( 'View Notifications', 'wptransformed' ),
                'errors'            => __( 'Errors', 'wptransformed' ),
                'warnings'          => __( 'Warnings', 'wptransformed' ),
                'other'             => __( 'Info & Success', 'wptransformed' ),
            ],
        ] );
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the current admin page is our Notifications page.
     */
    private function is_notifications_page(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( $_GET['page'] ) && $_GET['page'] === 'wpt-notifications';
    }

    /**
     * Check if the module should run on the current page based on scope.
     */
    private function should_run_on_current_page(): bool {
        $settings = $this->get_settings();

        if ( $settings['scope'] === 'wpt-only' ) {
            $hook = $GLOBALS['hook_suffix'] ?? '';
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

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => 'wpt_notices_%' ],
        ];
    }
}
