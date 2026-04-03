<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * External Permalinks — Redirect posts/pages to an external URL.
 *
 * @package WPTransformed
 */
class External_Permalinks extends Module_Base {

    /** @var string|null Cached meta key to avoid repeated get_settings() calls in hot-path filters. */
    private ?string $cached_meta_key = null;

    /**
     * Get the meta key, caching it on first access.
     */
    private function meta_key(): string {
        if ( $this->cached_meta_key === null ) {
            $settings = $this->get_settings();
            $this->cached_meta_key = $settings['meta_key'];
        }
        return $this->cached_meta_key;
    }

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'external-permalinks';
    }

    public function get_title(): string {
        return __( 'External Permalinks', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Replace any post or page permalink with an external URL, with automatic 301 redirect.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'  => true,
            'meta_key' => '_wpt_external_url',
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

        // Save the external URL on post save
        add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );

        // Filter permalinks
        add_filter( 'post_type_link', [ $this, 'filter_permalink' ], 10, 2 );
        add_filter( 'page_link', [ $this, 'filter_page_link' ], 10, 2 );
        add_filter( 'post_link', [ $this, 'filter_permalink' ], 10, 2 );

        // Redirect on frontend
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ] );

        // List table indicator column
        add_filter( 'manage_posts_columns', [ $this, 'add_column' ] );
        add_filter( 'manage_pages_columns', [ $this, 'add_column' ] );
        add_action( 'manage_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
        add_action( 'manage_pages_custom_column', [ $this, 'render_column' ], 10, 2 );
    }

    // ── Metabox ───────────────────────────────────────────────

    /**
     * Add the External URL metabox.
     */
    public function add_meta_box(): void {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        unset( $post_types['attachment'] );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'wpt-external-permalink',
                __( 'External Permalink', 'wptransformed' ),
                [ $this, 'render_meta_box' ],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the metabox content.
     *
     * @param \WP_Post $post Current post.
     */
    public function render_meta_box( \WP_Post $post ): void {
        $settings = $this->get_settings();
        $url      = get_post_meta( $post->ID, $settings['meta_key'], true );

        wp_nonce_field( 'wpt_external_url_' . $post->ID, 'wpt_external_url_nonce' );
        ?>
        <p>
            <label for="wpt-external-url"><?php esc_html_e( 'External URL', 'wptransformed' ); ?></label>
        </p>
        <p>
            <input type="url"
                   id="wpt-external-url"
                   name="wpt_external_url"
                   value="<?php echo esc_url( $url ); ?>"
                   class="widefat"
                   placeholder="https://example.com" />
        </p>
        <p class="description">
            <?php esc_html_e( 'If set, this post\'s permalink will point to this URL and visitors will be redirected with a 301.', 'wptransformed' ); ?>
        </p>
        <?php
    }

    /**
     * Save the external URL meta on post save.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function save_meta_box( int $post_id, \WP_Post $post ): void {
        // Skip autosaves and revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Verify nonce
        if ( ! isset( $_POST['wpt_external_url_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( $_POST['wpt_external_url_nonce'] ), 'wpt_external_url_' . $post_id ) ) {
            return;
        }

        // Check capability
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $settings = $this->get_settings();
        $url      = isset( $_POST['wpt_external_url'] ) ? esc_url_raw( wp_unslash( $_POST['wpt_external_url'] ) ) : '';

        if ( $url ) {
            update_post_meta( $post_id, $settings['meta_key'], $url );
        } else {
            delete_post_meta( $post_id, $settings['meta_key'] );
        }
    }

    // ── Permalink Filters ─────────────────────────────────────

    /**
     * Filter post permalinks to return external URL if set.
     *
     * @param string   $url  The post URL.
     * @param \WP_Post $post The post object.
     * @return string
     */
    public function filter_permalink( string $url, \WP_Post $post ): string {
        $external_url = get_post_meta( $post->ID, $this->meta_key(), true );

        if ( $external_url ) {
            return esc_url( $external_url );
        }

        return $url;
    }

    /**
     * Filter page links to return external URL if set.
     *
     * @param string $url     The page URL.
     * @param int    $page_id The page ID.
     * @return string
     */
    public function filter_page_link( string $url, int $page_id ): string {
        $external_url = get_post_meta( $page_id, $this->meta_key(), true );

        if ( $external_url ) {
            return esc_url( $external_url );
        }

        return $url;
    }

    // ── Frontend Redirect ─────────────────────────────────────

    /**
     * Redirect to external URL on direct post visit.
     */
    public function maybe_redirect(): void {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $external_url = get_post_meta( $post->ID, $this->meta_key(), true );

        if ( $external_url ) {
            wp_redirect( esc_url_raw( $external_url ), 301 );
            exit;
        }
    }

    // ── List Table Column ─────────────────────────────────────

    /**
     * Add an "External" indicator column to the post list table.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_column( array $columns ): array {
        $columns['wpt_external'] = '<span class="dashicons dashicons-admin-links" title="' . esc_attr__( 'External Link', 'wptransformed' ) . '"></span>';
        return $columns;
    }

    /**
     * Render the external indicator column content.
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public function render_column( string $column, int $post_id ): void {
        if ( $column !== 'wpt_external' ) {
            return;
        }

        $external_url = get_post_meta( $post_id, $this->meta_key(), true );

        if ( $external_url ) {
            echo '<span class="dashicons dashicons-external" title="' . esc_attr( $external_url ) . '"></span>';
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
                        <?php esc_html_e( 'Enable external permalinks for posts and pages.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Meta Key', 'wptransformed' ); ?></th>
                <td>
                    <input type="text"
                           name="wpt_meta_key"
                           value="<?php echo esc_attr( $settings['meta_key'] ); ?>"
                           class="regular-text" />
                    <p class="description"><?php esc_html_e( 'Post meta key used to store the external URL. Change only if migrating from another plugin.', 'wptransformed' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $meta_key = isset( $raw['wpt_meta_key'] ) ? sanitize_key( $raw['wpt_meta_key'] ) : '_wpt_external_url';
        if ( empty( $meta_key ) ) {
            $meta_key = '_wpt_external_url';
        }

        return [
            'enabled'  => ! empty( $raw['wpt_enabled'] ),
            'meta_key' => $meta_key,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        $settings = $this->get_settings();
        return [
            [ 'type' => 'post_meta', 'key' => $settings['meta_key'] ],
        ];
    }
}
