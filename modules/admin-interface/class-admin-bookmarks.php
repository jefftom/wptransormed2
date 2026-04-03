<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Bookmarks — Per-user bookmark bar for quick admin page access.
 *
 * Core logic:
 * - admin_bar_menu: adds "Bookmarks" top-level node with submenu items
 * - admin_footer: outputs "Bookmark This Page" button
 * - AJAX handlers: add/remove bookmarks stored in user meta
 *
 * @package WPTransformed
 */
class Admin_Bookmarks extends Module_Base {

    /** User meta key for bookmarks storage. */
    private const META_KEY = 'wpt_bookmarks';

    /** Maximum bookmarks per user. */
    private const MAX_BOOKMARKS = 50;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'admin-bookmarks';
    }

    public function get_title(): string {
        return __( 'Admin Bookmarks', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add a personal bookmarks bar to the admin toolbar for quick access to frequently used pages.', 'wptransformed' );
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

        add_action( 'admin_bar_menu', [ $this, 'add_bookmarks_menu' ], 80 );
        add_action( 'admin_footer', [ $this, 'render_bookmark_button' ] );
        add_action( 'wp_ajax_wpt_add_bookmark', [ $this, 'ajax_add_bookmark' ] );
        add_action( 'wp_ajax_wpt_remove_bookmark', [ $this, 'ajax_remove_bookmark' ] );
    }

    // ── Admin Bar Menu ────────────────────────────────────────

    /**
     * Add "Bookmarks" node to the admin bar with saved bookmarks as children.
     *
     * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
     */
    public function add_bookmarks_menu( \WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! is_admin() || ! current_user_can( 'read' ) ) {
            return;
        }

        $bookmarks = $this->get_user_bookmarks();

        $count_label = count( $bookmarks ) > 0
            ? ' <span class="wpt-bookmark-count">' . esc_html( (string) count( $bookmarks ) ) . '</span>'
            : '';

        $wp_admin_bar->add_node( [
            'id'    => 'wpt-bookmarks',
            'title' => '<span class="ab-icon dashicons dashicons-star-filled"></span>'
                     . esc_html__( 'Bookmarks', 'wptransformed' ) . $count_label,
            'href'  => '#',
            'meta'  => [
                'class' => 'wpt-bookmarks-menu',
            ],
        ] );

        if ( empty( $bookmarks ) ) {
            $wp_admin_bar->add_node( [
                'id'     => 'wpt-bookmarks-empty',
                'parent' => 'wpt-bookmarks',
                'title'  => esc_html__( 'No bookmarks yet', 'wptransformed' ),
                'href'   => '#',
                'meta'   => [
                    'class' => 'wpt-bookmark-empty-msg',
                ],
            ] );
            return;
        }

        foreach ( $bookmarks as $index => $bookmark ) {
            $title = isset( $bookmark['title'] ) ? $bookmark['title'] : '';
            $url   = isset( $bookmark['url'] ) ? $bookmark['url'] : '';

            if ( $title === '' || $url === '' ) {
                continue;
            }

            $wp_admin_bar->add_node( [
                'id'     => 'wpt-bookmark-' . $index,
                'parent' => 'wpt-bookmarks',
                'title'  => esc_html( $title ),
                'href'   => esc_url( $url ),
            ] );
        }
    }

    // ── Bookmark Button ───────────────────────────────────────

    /**
     * Render the "Bookmark This Page" floating button and inline JS/CSS in the admin footer.
     */
    public function render_bookmark_button(): void {
        if ( ! current_user_can( 'read' ) ) {
            return;
        }

        $current_url   = $this->get_current_admin_url();
        $is_bookmarked = $this->is_url_bookmarked( $current_url );
        ?>
        <style>
            #wpt-bookmark-btn {
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 99999;
                background: #2271b1;
                color: #fff;
                border: none;
                border-radius: 50%;
                width: 44px;
                height: 44px;
                cursor: pointer;
                box-shadow: 0 2px 8px rgba(0,0,0,0.25);
                font-size: 20px;
                line-height: 1;
                transition: background 0.2s, transform 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            #wpt-bookmark-btn:hover {
                background: #135e96;
                transform: scale(1.1);
            }
            #wpt-bookmark-btn.is-bookmarked {
                background: #dba617;
            }
            #wpt-bookmark-btn.is-bookmarked:hover {
                background: #c09200;
            }
            #wpt-bookmark-btn .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
            }
            .wpt-bookmark-count {
                display: inline-block;
                background: #d63638;
                color: #fff;
                border-radius: 10px;
                font-size: 9px;
                line-height: 1;
                padding: 2px 5px;
                margin-left: 4px;
                vertical-align: middle;
            }
            #wpt-bookmark-toast {
                position: fixed;
                bottom: 74px;
                right: 20px;
                z-index: 99999;
                background: #1d2327;
                color: #fff;
                padding: 8px 16px;
                border-radius: 4px;
                font-size: 13px;
                opacity: 0;
                transition: opacity 0.3s;
                pointer-events: none;
            }
            #wpt-bookmark-toast.visible {
                opacity: 1;
            }
        </style>

        <button type="button" id="wpt-bookmark-btn"
                class="<?php echo esc_attr( $is_bookmarked ? 'is-bookmarked' : '' ); ?>"
                title="<?php echo $is_bookmarked
                    ? esc_attr__( 'Remove Bookmark', 'wptransformed' )
                    : esc_attr__( 'Bookmark This Page', 'wptransformed' ); ?>">
            <span class="dashicons <?php echo esc_attr( $is_bookmarked ? 'dashicons-star-filled' : 'dashicons-star-empty' ); ?>"></span>
        </button>
        <div id="wpt-bookmark-toast"></div>

        <script>
        (function() {
            var btn   = document.getElementById('wpt-bookmark-btn');
            var toast = document.getElementById('wpt-bookmark-toast');
            if (!btn) return;

            var isBookmarked = btn.classList.contains('is-bookmarked');
            var ajaxUrl      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var addNonce     = <?php echo wp_json_encode( wp_create_nonce( 'wpt_add_bookmark' ) ); ?>;
            var removeNonce  = <?php echo wp_json_encode( wp_create_nonce( 'wpt_remove_bookmark' ) ); ?>;
            var currentUrl   = <?php echo wp_json_encode( $current_url ); ?>;
            var currentTitle = document.title.replace(/ \u2039.*$/, '').replace(/ \u2014.*$/, '').trim();

            function showToast(msg) {
                toast.textContent = msg;
                toast.classList.add('visible');
                setTimeout(function() { toast.classList.remove('visible'); }, 2000);
            }

            function updateBtn(bookmarked) {
                isBookmarked = bookmarked;
                var icon = btn.querySelector('.dashicons');
                if (bookmarked) {
                    btn.classList.add('is-bookmarked');
                    btn.title = <?php echo wp_json_encode( __( 'Remove Bookmark', 'wptransformed' ) ); ?>;
                    if (icon) { icon.className = 'dashicons dashicons-star-filled'; }
                } else {
                    btn.classList.remove('is-bookmarked');
                    btn.title = <?php echo wp_json_encode( __( 'Bookmark This Page', 'wptransformed' ) ); ?>;
                    if (icon) { icon.className = 'dashicons dashicons-star-empty'; }
                }
            }

            btn.addEventListener('click', function(e) {
                e.preventDefault();
                btn.disabled = true;

                var data = new FormData();
                data.append('url', currentUrl);

                if (isBookmarked) {
                    data.append('action', 'wpt_remove_bookmark');
                    data.append('_wpnonce', removeNonce);
                } else {
                    data.append('action', 'wpt_add_bookmark');
                    data.append('_wpnonce', addNonce);
                    data.append('title', currentTitle);
                }

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        btn.disabled = false;
                        if (resp.success) {
                            updateBtn(!isBookmarked);
                            showToast(resp.data.message || 'Done');
                        } else {
                            showToast(resp.data || 'Error');
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        showToast('Request failed');
                    });
            });
        })();
        </script>
        <?php
    }

    // ── AJAX Handlers ─────────────────────────────────────────

    /**
     * AJAX: Add a bookmark for the current user.
     */
    public function ajax_add_bookmark(): void {
        check_ajax_referer( 'wpt_add_bookmark' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

        if ( $url === '' || $title === '' ) {
            wp_send_json_error( __( 'URL and title are required.', 'wptransformed' ) );
        }

        // Ensure URL is within admin — validate both prefix and host to prevent spoofing.
        $admin_url  = admin_url();
        $url_host   = wp_parse_url( $url, PHP_URL_HOST );
        $admin_host = wp_parse_url( $admin_url, PHP_URL_HOST );
        $url_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( strpos( $url, $admin_url ) !== 0 || $url_host !== $admin_host || ! in_array( $url_scheme, [ 'http', 'https' ], true ) ) {
            wp_send_json_error( __( 'Only admin pages can be bookmarked.', 'wptransformed' ) );
        }

        $bookmarks = $this->get_user_bookmarks();

        // Check for duplicate.
        foreach ( $bookmarks as $bm ) {
            if ( isset( $bm['url'] ) && $bm['url'] === $url ) {
                wp_send_json_error( __( 'This page is already bookmarked.', 'wptransformed' ) );
            }
        }

        // Enforce limit.
        if ( count( $bookmarks ) >= self::MAX_BOOKMARKS ) {
            wp_send_json_error(
                sprintf(
                    /* translators: %d: maximum bookmarks */
                    __( 'Maximum %d bookmarks reached.', 'wptransformed' ),
                    self::MAX_BOOKMARKS
                )
            );
        }

        $bookmarks[] = [
            'url'   => $url,
            'title' => $title,
            'added' => time(),
        ];

        $this->save_user_bookmarks( $bookmarks );

        wp_send_json_success( [
            'message' => __( 'Bookmark added!', 'wptransformed' ),
            'count'   => count( $bookmarks ),
        ] );
    }

    /**
     * AJAX: Remove a bookmark for the current user.
     */
    public function ajax_remove_bookmark(): void {
        check_ajax_referer( 'wpt_remove_bookmark' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( $url === '' ) {
            wp_send_json_error( __( 'URL is required.', 'wptransformed' ) );
        }

        $bookmarks = $this->get_user_bookmarks();
        $found     = false;

        foreach ( $bookmarks as $index => $bm ) {
            if ( isset( $bm['url'] ) && $bm['url'] === $url ) {
                unset( $bookmarks[ $index ] );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            wp_send_json_error( __( 'Bookmark not found.', 'wptransformed' ) );
        }

        $bookmarks = array_values( $bookmarks );
        $this->save_user_bookmarks( $bookmarks );

        wp_send_json_success( [
            'message' => __( 'Bookmark removed.', 'wptransformed' ),
            'count'   => count( $bookmarks ),
        ] );
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Get bookmarks for the current user.
     *
     * @return array<int, array{url: string, title: string, added: int}>
     */
    private function get_user_bookmarks(): array {
        $user_id = get_current_user_id();
        if ( $user_id === 0 ) {
            return [];
        }

        $raw = get_user_meta( $user_id, self::META_KEY, true );
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        if ( is_array( $raw ) ) {
            return $raw;
        }

        return [];
    }

    /**
     * Save bookmarks for the current user.
     *
     * @param array $bookmarks The bookmarks array.
     */
    private function save_user_bookmarks( array $bookmarks ): void {
        $user_id = get_current_user_id();
        if ( $user_id === 0 ) {
            return;
        }

        update_user_meta( $user_id, self::META_KEY, wp_json_encode( $bookmarks ) );
    }

    /**
     * Get the current admin page URL.
     *
     * Parses REQUEST_URI to extract the admin-relative path and query string,
     * then reconstructs a full admin URL.
     *
     * @return string
     */
    private function get_current_admin_url(): string {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( $uri === '' ) {
            return admin_url();
        }

        // Extract path relative to /wp-admin/.
        $admin_path = 'wp-admin/';
        $pos = strpos( $uri, $admin_path );
        if ( $pos === false ) {
            return admin_url();
        }

        $relative = substr( $uri, $pos + strlen( $admin_path ) );
        return admin_url( $relative );
    }

    /**
     * Check if a URL is in the current user's bookmarks.
     *
     * @param string $url The URL to check.
     * @return bool
     */
    private function is_url_bookmarked( string $url ): bool {
        $bookmarks = $this->get_user_bookmarks();
        foreach ( $bookmarks as $bm ) {
            if ( isset( $bm['url'] ) && $bm['url'] === $url ) {
                return true;
            }
        }
        return false;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e( 'Admin Bookmarks Settings', 'wptransformed' ); ?></legend>

            <p>
                <label>
                    <input type="checkbox" name="wpt_enabled" value="1"
                        <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                    <?php esc_html_e( 'Enable Admin Bookmarks toolbar menu', 'wptransformed' ); ?>
                </label>
            </p>

            <p class="description">
                <?php esc_html_e( 'When enabled, a "Bookmarks" menu appears in the admin bar. Use the floating star button to bookmark any admin page. Each user has their own bookmark list.', 'wptransformed' ); ?>
            </p>
        </fieldset>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => self::META_KEY ],
        ];
    }
}
