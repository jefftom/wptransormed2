<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Public Preview — Share draft/pending posts with non-logged-in users via token link.
 *
 * @package WPTransformed
 */
class Public_Preview extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'public-preview';
    }

    public function get_title(): string {
        return __( 'Public Preview', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Share draft or pending posts with non-logged-in users via a secure, expiring preview link.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'     => true,
            'link_expiry' => 48,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Metabox on edit screen
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );

        // AJAX handler to generate preview link
        add_action( 'wp_ajax_wpt_generate_preview_link', [ $this, 'ajax_generate_link' ] );

        // Frontend: allow non-logged-in users to view drafts with valid token
        add_action( 'pre_get_posts', [ $this, 'allow_preview_query' ] );
        add_filter( 'posts_results', [ $this, 'allow_preview_post' ], 10, 2 );

        // Add noindex meta on preview pages
        add_action( 'wp_head', [ $this, 'add_noindex_meta' ] );
    }

    // ── Metabox ───────────────────────────────────────────────

    /**
     * Add the preview link metabox on draft/pending post edit screens.
     */
    public function add_meta_box(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! $screen->post_type ) {
            return;
        }

        global $post;
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        // Only show for draft/pending posts
        if ( ! in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft' ], true ) ) {
            return;
        }

        add_meta_box(
            'wpt-public-preview',
            __( 'Public Preview', 'wptransformed' ),
            [ $this, 'render_meta_box' ],
            $screen->post_type,
            'side',
            'default'
        );
    }

    /**
     * Render the metabox content.
     *
     * @param \WP_Post $post Current post.
     */
    public function render_meta_box( \WP_Post $post ): void {
        $token     = get_post_meta( $post->ID, '_wpt_preview_token', true );
        $timestamp = (int) get_post_meta( $post->ID, '_wpt_preview_token_time', true );
        $settings  = $this->get_settings();
        $expiry_seconds = absint( $settings['link_expiry'] ) * HOUR_IN_SECONDS;
        $expired   = $token && $timestamp && ( time() > $timestamp + $expiry_seconds );
        $has_link  = $token && ! $expired;

        wp_nonce_field( 'wpt_preview_' . $post->ID, 'wpt_preview_nonce' );
        ?>
        <div id="wpt-preview-container">
            <?php if ( $has_link ) : ?>
                <p>
                    <input type="text"
                           id="wpt-preview-url"
                           value="<?php echo esc_attr( add_query_arg( 'wpt_preview', $token, get_permalink( $post->ID ) ) ); ?>"
                           readonly
                           class="widefat"
                           onclick="this.select();" />
                </p>
                <p class="description">
                    <?php
                    $remaining = ( $timestamp + $expiry_seconds ) - time();
                    $hours     = (int) ceil( $remaining / HOUR_IN_SECONDS );
                    printf(
                        /* translators: %d: hours remaining */
                        esc_html__( 'Expires in %d hour(s).', 'wptransformed' ),
                        $hours
                    );
                    ?>
                </p>
            <?php endif; ?>
            <p>
                <button type="button"
                        id="wpt-generate-preview"
                        class="button button-secondary"
                        data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
                    <?php echo $has_link
                        ? esc_html__( 'Regenerate Link', 'wptransformed' )
                        : esc_html__( 'Get Preview Link', 'wptransformed' ); ?>
                </button>
            </p>
        </div>
        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var postId = '<?php echo esc_js( (string) $post->ID ); ?>';
            var nonce = '<?php echo esc_js( wp_create_nonce( 'wpt_preview_' . $post->ID ) ); ?>';
            var expiryLabel = '<?php printf( esc_js( __( 'Expires in %d hour(s).', 'wptransformed' ) ), absint( $settings['link_expiry'] ) ); ?>';
            var regenLabel = '<?php echo esc_js( __( 'Regenerate Link', 'wptransformed' ) ); ?>';

            function bindButton() {
                var btn = document.getElementById('wpt-generate-preview');
                if (!btn) return;
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.success && resp.data && resp.data.url) {
                                    var container = document.getElementById('wpt-preview-container');
                                    container.innerHTML = '<p><input type="text" id="wpt-preview-url" value="' +
                                        resp.data.url + '" readonly class="widefat" onclick="this.select();" /></p>' +
                                        '<p class="description">' + expiryLabel + '</p>' +
                                        '<p><button type="button" id="wpt-generate-preview" class="button button-secondary" data-post-id="' + postId + '">' +
                                        regenLabel + '</button></p>';
                                    bindButton();
                                }
                            } catch(e) {}
                        }
                        btn.disabled = false;
                    };
                    xhr.send('action=wpt_generate_preview_link&post_id=' + postId + '&nonce=' + nonce);
                });
            }
            bindButton();
        })();
        </script>
        <?php
    }

    // ── AJAX: Generate Preview Link ───────────────────────────

    /**
     * Generate a new preview token via AJAX.
     */
    public function ajax_generate_link(): void {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $post_id || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ), 'wpt_preview_' . $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wptransformed' ) ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post.', 'wptransformed' ) ] );
        }

        $token = wp_generate_password( 20, false );

        update_post_meta( $post_id, '_wpt_preview_token', $token );
        update_post_meta( $post_id, '_wpt_preview_token_time', time() );

        $url = add_query_arg( 'wpt_preview', $token, get_permalink( $post_id ) );

        wp_send_json_success( [ 'url' => $url, 'token' => $token ] );
    }

    // ── Frontend Preview Access ───────────────────────────────

    /**
     * Modify the main query to include drafts/pending when a valid preview token is present.
     *
     * @param \WP_Query $query The current WP_Query instance.
     */
    public function allow_preview_query( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_singular() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['wpt_preview'] ) ) {
            return;
        }

        $query->set( 'post_status', [ 'publish', 'draft', 'pending', 'future' ] );
    }

    /**
     * Validate the preview token against the post and check expiry.
     *
     * @param array     $posts Array of posts from the query.
     * @param \WP_Query $query The current WP_Query instance.
     * @return array
     */
    public function allow_preview_post( array $posts, \WP_Query $query ): array {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_singular() ) {
            return $posts;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['wpt_preview'] ) ) {
            return $posts;
        }

        if ( empty( $posts ) ) {
            return $posts;
        }

        $post  = $posts[0];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = sanitize_text_field( wp_unslash( $_GET['wpt_preview'] ) );

        if ( ! in_array( $post->post_status, [ 'draft', 'pending', 'future' ], true ) ) {
            return $posts;
        }

        $stored_token = get_post_meta( $post->ID, '_wpt_preview_token', true );
        $timestamp    = (int) get_post_meta( $post->ID, '_wpt_preview_token_time', true );
        $settings     = $this->get_settings();
        $expiry       = absint( $settings['link_expiry'] ) * HOUR_IN_SECONDS;

        if ( ! $stored_token || ! hash_equals( $stored_token, $token ) ) {
            return [];
        }

        if ( $timestamp && time() > $timestamp + $expiry ) {
            return [];
        }

        // WP needs 'publish' status to render the template; the post remains a draft in the DB
        $posts[0]->post_status = 'publish';

        return $posts;
    }

    // ── Noindex Meta ──────────────────────────────────────────

    /**
     * Add noindex meta tag on preview pages to prevent search engine indexing.
     */
    public function add_noindex_meta(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['wpt_preview'] ) ) {
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
                        <?php esc_html_e( 'Enable public preview links for drafts and pending posts.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Link Expiry', 'wptransformed' ); ?></th>
                <td>
                    <input type="number"
                           name="wpt_link_expiry"
                           value="<?php echo esc_attr( (string) $settings['link_expiry'] ); ?>"
                           min="1"
                           max="720"
                           step="1"
                           class="small-text" />
                    <?php esc_html_e( 'hours', 'wptransformed' ); ?>
                    <p class="description"><?php esc_html_e( 'How long preview links remain valid (1-720 hours).', 'wptransformed' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $expiry = isset( $raw['wpt_link_expiry'] ) ? absint( $raw['wpt_link_expiry'] ) : 48;
        $expiry = max( 1, min( 720, $expiry ) );

        return [
            'enabled'     => ! empty( $raw['wpt_enabled'] ),
            'link_expiry' => $expiry,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'post_meta', 'key' => '_wpt_preview_token' ],
            [ 'type' => 'post_meta', 'key' => '_wpt_preview_token_time' ],
        ];
    }
}
