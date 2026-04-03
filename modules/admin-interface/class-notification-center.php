<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Notification Center -- Capture admin notices into a unified panel.
 *
 * Features:
 *  - Hooks admin_notices at priority 0 (start buffer) and 9999 (end buffer)
 *  - Parses and categorizes notices: error, warning, info, success
 *  - Stores in user transient, persists across pages until dismissed
 *  - Admin bar node "Notifications (N)" with dropdown
 *  - AJAX fetch and dismiss
 *
 * @package WPTransformed
 */
class Notification_Center extends Module_Base {

    /**
     * Transient key prefix (per-user).
     */
    private const TRANSIENT_PREFIX = 'wpt_notices_';

    /**
     * Max notices to store per user.
     */
    private const MAX_NOTICES = 50;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'notification-center';
    }

    public function get_title(): string {
        return __( 'Notification Center', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Capture admin notices into a unified notification panel in the admin bar.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'collect_notices'  => true,
            'show_badge_count' => true,
            'dismiss_clears'   => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        $settings = $this->get_settings();

        // Capture admin notices.
        if ( ! empty( $settings['collect_notices'] ) ) {
            add_action( 'admin_notices', [ $this, 'start_capture' ], 0 );
            add_action( 'admin_notices', [ $this, 'end_capture' ], 9999 );
        }

        // Admin bar node.
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_node' ], 999 );

        // Admin head: styles and scripts.
        add_action( 'admin_head', [ $this, 'output_assets' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_nc_fetch', [ $this, 'ajax_fetch' ] );
        add_action( 'wp_ajax_wpt_nc_dismiss', [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_wpt_nc_dismiss_all', [ $this, 'ajax_dismiss_all' ] );
    }

    // ── Notice Capture ───────────────────────────────────────

    /**
     * Start output buffer to capture admin notices.
     */
    public function start_capture(): void {
        ob_start();
    }

    /**
     * End output buffer, parse notices, merge with stored ones.
     */
    public function end_capture(): void {
        $output = ob_get_clean();
        if ( $output === false || trim( $output ) === '' ) {
            return;
        }

        $new_notices = $this->parse_notices( $output );
        if ( empty( $new_notices ) ) {
            // Re-echo the original output if no parseable notices.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- re-echoing WP admin notices as-is
            echo $output;
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $output;
            return;
        }

        // Merge with existing.
        $stored = $this->get_stored_notices( $user_id );
        foreach ( $new_notices as $notice ) {
            // De-duplicate by content hash.
            $hash = md5( $notice['message'] );
            $found = false;
            foreach ( $stored as $s ) {
                if ( isset( $s['hash'] ) && $s['hash'] === $hash ) {
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $notice['hash']       = $hash;
                $notice['created_at'] = current_time( 'mysql', true );
                $stored[]             = $notice;
            }
        }

        // Trim to max.
        if ( count( $stored ) > self::MAX_NOTICES ) {
            $stored = array_slice( $stored, -self::MAX_NOTICES );
        }

        $this->save_stored_notices( $user_id, $stored );

        // Do NOT re-echo the notices -- they are now in the notification center.
    }

    /**
     * Parse HTML admin notices into structured array.
     *
     * @return array<int, array{type: string, message: string, dismissible: bool}>
     */
    private function parse_notices( string $html ): array {
        $notices = [];

        // Match standard WP notice divs.
        if ( ! preg_match_all( '/<div[^>]*class="[^"]*notice[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches, PREG_SET_ORDER ) ) {
            return $notices;
        }

        foreach ( $matches as $match ) {
            $class_html = $match[0];
            $message    = wp_strip_all_tags( $match[1] );
            $message    = trim( $message );

            if ( $message === '' ) {
                continue;
            }

            // Determine type using WP standard notice classes.
            $type = 'info';
            if ( strpos( $class_html, 'notice-error' ) !== false ) {
                $type = 'error';
            } elseif ( strpos( $class_html, 'notice-warning' ) !== false || strpos( $class_html, 'update-nag' ) !== false ) {
                $type = 'warning';
            } elseif ( strpos( $class_html, 'notice-success' ) !== false || strpos( $class_html, 'updated' ) !== false ) {
                $type = 'success';
            }

            $dismissible = strpos( $class_html, 'is-dismissible' ) !== false;

            $notices[] = [
                'type'        => $type,
                'message'     => mb_substr( $message, 0, 500 ),
                'dismissible' => $dismissible,
            ];
        }

        return $notices;
    }

    // ── Notice Storage ───────────────────────────────────────

    /**
     * Get stored notices for a user.
     *
     * @return array<int, array{type: string, message: string, hash: string, created_at: string}>
     */
    private function get_stored_notices( int $user_id ): array {
        $key    = self::TRANSIENT_PREFIX . $user_id;
        $stored = get_user_meta( $user_id, $key, true );
        return is_array( $stored ) ? $stored : [];
    }

    /**
     * Save notices for a user.
     *
     * @param array<int, array{type: string, message: string, hash: string, created_at: string}> $notices
     */
    private function save_stored_notices( int $user_id, array $notices ): void {
        $key = self::TRANSIENT_PREFIX . $user_id;
        update_user_meta( $user_id, $key, $notices );
    }

    // ── Admin Bar ────────────────────────────────────────────

    /**
     * Add notification center node to admin bar.
     *
     * @param \WP_Admin_Bar $admin_bar
     */
    public function add_admin_bar_node( \WP_Admin_Bar $admin_bar ): void {
        if ( ! is_admin() || ! is_user_logged_in() ) {
            return;
        }

        $settings = $this->get_settings();
        $user_id  = get_current_user_id();
        $notices  = $this->get_stored_notices( $user_id );
        $count    = count( $notices );

        $title = __( 'Notifications', 'wptransformed' );
        if ( ! empty( $settings['show_badge_count'] ) && $count > 0 ) {
            $title .= ' <span class="wpt-nc-badge">' . esc_html( (string) $count ) . '</span>';
        }

        $admin_bar->add_node( [
            'id'    => 'wpt-notification-center',
            'title' => $title,
            'href'  => '#',
            'meta'  => [
                'class' => 'wpt-nc-node',
            ],
        ] );
    }

    // ── Admin Assets ─────────────────────────────────────────

    /**
     * Output inline styles and JS for the notification dropdown.
     */
    public function output_assets(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $nonce    = wp_create_nonce( 'wpt_nc_nonce' );
        $settings = $this->get_settings();
        ?>
        <style id="wpt-notification-center-css">
            .wpt-nc-badge {
                background: #d63638;
                color: #fff;
                border-radius: 50%;
                padding: 0 6px;
                font-size: 11px;
                line-height: 18px;
                display: inline-block;
                min-width: 18px;
                text-align: center;
                margin-left: 4px;
                vertical-align: middle;
            }
            #wpt-nc-dropdown {
                display: none;
                position: fixed;
                top: 32px;
                right: 10px;
                width: 380px;
                max-height: 500px;
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 3px 10px rgba(0,0,0,.15);
                z-index: 100001;
                border-radius: 4px;
                overflow: hidden;
            }
            #wpt-nc-dropdown.wpt-nc-open { display: block; }
            .wpt-nc-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 14px;
                border-bottom: 1px solid #f0f0f1;
                background: #f6f7f7;
            }
            .wpt-nc-header h3 { margin: 0; font-size: 14px; }
            .wpt-nc-list { max-height: 420px; overflow-y: auto; }
            .wpt-nc-item {
                display: flex;
                gap: 10px;
                padding: 10px 14px;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
                align-items: flex-start;
            }
            .wpt-nc-item:last-child { border-bottom: none; }
            .wpt-nc-dot {
                flex-shrink: 0;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                margin-top: 4px;
            }
            .wpt-nc-dot-error   { background: #d63638; }
            .wpt-nc-dot-warning { background: #dba617; }
            .wpt-nc-dot-info    { background: #72aee6; }
            .wpt-nc-dot-success { background: #00a32a; }
            .wpt-nc-msg { flex: 1; word-break: break-word; }
            .wpt-nc-time { color: #787c82; font-size: 11px; margin-top: 2px; }
            .wpt-nc-dismiss-btn {
                flex-shrink: 0;
                background: none;
                border: none;
                cursor: pointer;
                color: #787c82;
                font-size: 16px;
                line-height: 1;
                padding: 0 4px;
            }
            .wpt-nc-dismiss-btn:hover { color: #d63638; }
            .wpt-nc-empty { text-align: center; padding: 30px 14px; color: #787c82; }
        </style>
        <div id="wpt-nc-dropdown">
            <div class="wpt-nc-header">
                <h3><?php esc_html_e( 'Notifications', 'wptransformed' ); ?></h3>
                <button type="button" id="wpt-nc-clear-all" class="button button-small">
                    <?php esc_html_e( 'Clear All', 'wptransformed' ); ?>
                </button>
            </div>
            <div class="wpt-nc-list" id="wpt-nc-list">
                <div class="wpt-nc-empty"><?php esc_html_e( 'Loading...', 'wptransformed' ); ?></div>
            </div>
        </div>
        <script>
        (function() {
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var dropdown = document.getElementById('wpt-nc-dropdown');
            var list     = document.getElementById('wpt-nc-list');

            // Toggle dropdown on admin bar click.
            document.addEventListener('click', function(e) {
                var node = e.target.closest('#wp-admin-bar-wpt-notification-center');
                if (node) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropdown.classList.toggle('wpt-nc-open');
                    if (dropdown.classList.contains('wpt-nc-open')) {
                        fetchNotices();
                    }
                    return;
                }
                // Close if clicking outside.
                if (!e.target.closest('#wpt-nc-dropdown')) {
                    dropdown.classList.remove('wpt-nc-open');
                }
            });

            function fetchNotices() {
                var fd = new FormData();
                fd.append('action', 'wpt_nc_fetch');
                fd.append('nonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) return;
                        renderNotices(resp.data.notices);
                        updateBadge(resp.data.notices.length);
                    });
            }

            function renderNotices(notices) {
                list.innerHTML = '';
                if (notices.length === 0) {
                    list.innerHTML = '<div class="wpt-nc-empty">' + <?php echo wp_json_encode( esc_html__( 'No notifications.', 'wptransformed' ) ); ?> + '</div>';
                    return;
                }
                notices.forEach(function(n, i) {
                    var el = document.createElement('div');
                    el.className = 'wpt-nc-item';
                    el.innerHTML = '<span class="wpt-nc-dot wpt-nc-dot-' + n.type + '"></span>'
                        + '<div class="wpt-nc-msg">' + n.message + '<div class="wpt-nc-time">' + n.time_ago + '</div></div>'
                        + '<button type="button" class="wpt-nc-dismiss-btn" data-index="' + i + '" title="' + <?php echo wp_json_encode( esc_attr__( 'Dismiss', 'wptransformed' ) ); ?> + '">&times;</button>';
                    list.appendChild(el);
                });
            }

            function updateBadge(count) {
                var badge = document.querySelector('#wp-admin-bar-wpt-notification-center .wpt-nc-badge');
                if (badge) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? '' : 'none';
                }
            }

            // Dismiss single.
            list.addEventListener('click', function(e) {
                var btn = e.target.closest('.wpt-nc-dismiss-btn');
                if (!btn) return;
                var fd = new FormData();
                fd.append('action', 'wpt_nc_dismiss');
                fd.append('nonce', nonce);
                fd.append('index', btn.getAttribute('data-index'));
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function() { fetchNotices(); });
            });

            // Clear all.
            document.getElementById('wpt-nc-clear-all').addEventListener('click', function() {
                var fd = new FormData();
                fd.append('action', 'wpt_nc_dismiss_all');
                fd.append('nonce', nonce);
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function() { fetchNotices(); });
            });

            // Initial badge count update (skip if badge is disabled).
            if (<?php echo wp_json_encode( ! empty( $settings['show_badge_count'] ) ); ?>) {
                var fd0 = new FormData();
                fd0.append('action', 'wpt_nc_fetch');
                fd0.append('nonce', nonce);
                fetch(ajaxUrl, { method: 'POST', body: fd0, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.success) updateBadge(resp.data.notices.length);
                    });
            }
        })();
        </script>
        <?php
    }

    // ── AJAX Handlers ────────────────────────────────────────

    public function ajax_fetch(): void {
        check_ajax_referer( 'wpt_nc_nonce', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user_id = get_current_user_id();
        $notices = $this->get_stored_notices( $user_id );

        // Format for display.
        $formatted = [];
        foreach ( array_reverse( $notices ) as $notice ) {
            $formatted[] = [
                'type'    => esc_attr( $notice['type'] ?? 'info' ),
                'message' => esc_html( $notice['message'] ?? '' ),
                'time_ago' => isset( $notice['created_at'] )
                    ? esc_html( human_time_diff( strtotime( $notice['created_at'] ), time() ) . ' ' . __( 'ago', 'wptransformed' ) )
                    : '',
            ];
        }

        wp_send_json_success( [ 'notices' => $formatted ] );
    }

    public function ajax_dismiss(): void {
        check_ajax_referer( 'wpt_nc_nonce', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
        $user_id = get_current_user_id();
        $notices = $this->get_stored_notices( $user_id );

        // Since displayed reversed, convert index.
        $real_index = count( $notices ) - 1 - $index;
        if ( $real_index >= 0 && $real_index < count( $notices ) ) {
            array_splice( $notices, $real_index, 1 );
            $this->save_stored_notices( $user_id, $notices );
        }

        wp_send_json_success();
    }

    public function ajax_dismiss_all(): void {
        check_ajax_referer( 'wpt_nc_nonce', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user_id = get_current_user_id();
        $this->save_stored_notices( $user_id, [] );
        wp_send_json_success();
    }

    // ── Admin UI (Settings) ──────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Collect Notices', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_nc_collect" value="1"
                               <?php checked( ! empty( $settings['collect_notices'] ) ); ?>>
                        <?php esc_html_e( 'Capture admin notices and display them in the notification center.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Show Badge Count', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_nc_badge" value="1"
                               <?php checked( ! empty( $settings['show_badge_count'] ) ); ?>>
                        <?php esc_html_e( 'Display the number of unread notifications in the admin bar.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Dismiss Clears', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_nc_dismiss_clears" value="1"
                               <?php checked( ! empty( $settings['dismiss_clears'] ) ); ?>>
                        <?php esc_html_e( 'Dismissing a notice removes it permanently instead of just hiding it.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'collect_notices'  => ! empty( $raw['wpt_nc_collect'] ),
            'show_badge_count' => ! empty( $raw['wpt_nc_badge'] ),
            'dismiss_clears'   => ! empty( $raw['wpt_nc_dismiss_clears'] ),
        ];
    }

    // ── Cleanup ──────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'user_meta' => [ self::TRANSIENT_PREFIX . '*' ],
        ];
    }
}
