<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Content Duplication — One-click clone of any post, page, or CPT.
 *
 * @package WPTransformed
 */
class Content_Duplication extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'content-duplication';
    }

    public function get_title(): string {
        return __( 'Content Duplication', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'One-click clone of any post, page, or CPT with all metadata and taxonomies.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'post_types'          => [ 'post', 'page' ],
            'copy_taxonomies'     => true,
            'copy_meta'           => true,
            'copy_featured_image' => true,
            'title_prefix'        => '',
            'title_suffix'        => ' (Copy)',
            'new_status'          => 'draft',
            'redirect_after'      => 'list',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Row actions — Duplicate link on posts and pages
        add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
        add_filter( 'page_row_actions', [ $this, 'add_row_action' ], 10, 2 );

        // Handle the duplication action
        add_action( 'admin_action_wpt_duplicate_post', [ $this, 'handle_duplicate' ] );

        // Admin notice after duplication
        add_action( 'admin_notices', [ $this, 'show_duplicate_notice' ] );

        // Admin bar link
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_link' ], 80 );

        // Gutenberg sidebar button
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
    }

    // ── Row Action ────────────────────────────────────────────

    /**
     * Add "Duplicate" link to post/page row actions.
     *
     * @param array    $actions Existing row actions.
     * @param \WP_Post $post    Current post object.
     * @return array
     */
    public function add_row_action( array $actions, \WP_Post $post ): array {
        $settings = $this->get_settings();

        // Only show for enabled post types
        if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
            return $actions;
        }

        // Only show if user can edit this post
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url( 'admin.php?action=wpt_duplicate_post&post=' . $post->ID ),
            'wpt_duplicate_' . $post->ID,
            'wpt_nonce'
        );

        $actions['wpt_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'wptransformed' ) . '</a>';

        return $actions;
    }

    // ── Admin Bar Link ────────────────────────────────────────

    /**
     * Add "Duplicate This" to the admin bar on single post views.
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     */
    public function add_admin_bar_link( \WP_Admin_Bar $wp_admin_bar ): void {
        $settings = $this->get_settings();

        // Determine the current post
        $post = null;

        if ( is_admin() ) {
            // In the post editor
            global $post;
            if ( ! $post instanceof \WP_Post ) {
                return;
            }
        } else {
            // On the frontend — single post/page view
            if ( ! is_singular() ) {
                return;
            }
            $post = get_queried_object();
            if ( ! $post instanceof \WP_Post ) {
                return;
            }
        }

        // Only for enabled post types
        if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
            return;
        }

        // Only if user can edit
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $url = wp_nonce_url(
            admin_url( 'admin.php?action=wpt_duplicate_post&post=' . $post->ID ),
            'wpt_duplicate_' . $post->ID,
            'wpt_nonce'
        );

        $wp_admin_bar->add_node( [
            'id'    => 'wpt-duplicate-post',
            'title' => esc_html__( 'Duplicate This', 'wptransformed' ),
            'href'  => esc_url( $url ),
            'meta'  => [
                'title' => esc_attr__( 'Duplicate this post', 'wptransformed' ),
            ],
        ] );
    }

    // ── Block Editor Sidebar Button ──────────────────────────

    /**
     * Enqueue a small inline script that adds a "Duplicate Post" button
     * to the Gutenberg Post Status panel via PluginPostStatusInfo slot.
     */
    public function enqueue_block_editor_assets(): void {
        $settings   = $this->get_settings();
        $screen     = get_current_screen();

        if ( ! $screen || ! $screen->post_type ) {
            return;
        }

        // Only for enabled post types
        if ( ! in_array( $screen->post_type, (array) $settings['post_types'], true ) ) {
            return;
        }

        // Register a dummy handle so we can attach inline script + localized data
        wp_register_script( 'wpt-content-duplication-editor', '', [], WPT_VERSION, true );
        wp_enqueue_script( 'wpt-content-duplication-editor' );

        // Pass data the JS needs to build the nonce URL
        global $post;
        $post_id = $post instanceof \WP_Post ? $post->ID : 0;

        wp_localize_script( 'wpt-content-duplication-editor', 'wptDuplicate', [
            'adminUrl'  => admin_url( 'admin.php' ),
            'nonceUrl'  => $post_id ? wp_nonce_url(
                admin_url( 'admin.php?action=wpt_duplicate_post&post=' . $post_id ),
                'wpt_duplicate_' . $post_id,
                'wpt_nonce'
            ) : '',
            'postId'    => $post_id,
            'label'     => __( 'Duplicate Post', 'wptransformed' ),
        ] );

        wp_add_inline_script( 'wpt-content-duplication-editor', $this->get_block_editor_inline_js() );
    }

    /**
     * Return the inline JS that registers the Gutenberg sidebar plugin.
     */
    private function get_block_editor_inline_js(): string {
        return <<<'JS'
(function() {
    var el = wp.element.createElement;
    var PluginPostStatusInfo = wp.editPost.PluginPostStatusInfo;
    var registerPlugin = wp.plugins.registerPlugin;
    var useSelect = wp.data.useSelect;
    var Button = wp.components.Button;

    function WptDuplicateButton() {
        var postId = useSelect(function(select) {
            return select('core/editor').getCurrentPostId();
        });

        if (!postId || !wptDuplicate.nonceUrl) {
            return null;
        }

        return el(PluginPostStatusInfo, {},
            el(
                'div',
                { style: { width: '100%' } },
                el(Button, {
                    variant: 'secondary',
                    href: wptDuplicate.nonceUrl,
                    style: { width: '100%', justifyContent: 'center' }
                }, wptDuplicate.label)
            )
        );
    }

    registerPlugin('wpt-duplicate-post', {
        render: WptDuplicateButton
    });
})();
JS;
    }

    // ── Duplication Handler ───────────────────────────────────

    /**
     * Handle the admin_action_wpt_duplicate_post hook.
     * Performs the 9-step duplication process from the spec.
     */
    public function handle_duplicate(): void {
        // Step 1: Verify nonce
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

        if ( ! $post_id || ! isset( $_GET['wpt_nonce'] ) || ! wp_verify_nonce( $_GET['wpt_nonce'], 'wpt_duplicate_' . $post_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wptransformed' ) );
        }

        // Step 2: Verify capability
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You do not have permission to duplicate this post.', 'wptransformed' ) );
        }

        // Step 3: Get source post
        $source = get_post( $post_id );
        if ( ! $source ) {
            wp_die( esc_html__( 'Source post not found.', 'wptransformed' ) );
        }

        // Steps 4–7 run in the shared implementation (the wpt/v1 action
        // route delegates to the same method — no second duplication
        // implementation exists).
        $new_id = $this->duplicate_post( $post_id );

        if ( is_wp_error( $new_id ) ) {
            wp_die( esc_html( $new_id->get_error_message() ) );
        }

        $settings = $this->get_settings();

        // Step 8: Set transient for admin notice
        set_transient( 'wpt_duplicate_notice_' . get_current_user_id(), $new_id, 30 );

        // Step 9: Redirect based on setting
        if ( $settings['redirect_after'] === 'edit' ) {
            wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) );
        } else {
            wp_safe_redirect( admin_url( 'edit.php?post_type=' . $source->post_type ) );
        }
        exit;
    }

    // ── Shared Duplication Implementation ─────────────────────

    /**
     * Duplicate a post, honoring the module settings (title
     * prefix/suffix, new_status, copy_taxonomies/meta/featured_image
     * with the standard skip keys) — the SOLE duplication
     * implementation, shared by the admin_action handler and the wpt/v1
     * duplicate action route.
     *
     * PURE with respect to UI (slice 10b, checkpoint §16.3): sets no
     * admin notices, transients, redirects, or other admin-screen
     * state — callers own all surface behavior. Callers also own
     * capability and post-type gating; this method only performs the
     * duplication.
     *
     * @param int $post_id Source post ID.
     * @return int|\WP_Error The new post ID, or WP_Error on failure.
     */
    public function duplicate_post( int $post_id ) {
        $source = get_post( $post_id );
        if ( ! $source ) {
            return new \WP_Error( 'wpt_source_missing', __( 'Source post not found.', 'wptransformed' ) );
        }

        $settings = $this->get_settings();

        // Step 4: Create new post via wp_insert_post()
        $new_title = $settings['title_prefix'] . $source->post_title . $settings['title_suffix'];

        $new_post_args = [
            'post_title'     => $new_title,
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_status'    => $settings['new_status'],
            'post_type'      => $source->post_type,
            'post_author'    => get_current_user_id(),
            'post_parent'    => $source->post_parent,
            'menu_order'     => $source->menu_order,
            'post_password'  => $source->post_password,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
        ];

        $new_id = wp_insert_post( $new_post_args, true );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        // Step 5: Copy taxonomies
        if ( ! empty( $settings['copy_taxonomies'] ) ) {
            $taxonomies = get_object_taxonomies( $source->post_type );
            foreach ( $taxonomies as $taxonomy ) {
                $terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    wp_set_object_terms( $new_id, $terms, $taxonomy );
                }
            }
        }

        // Step 6: Copy post meta
        if ( ! empty( $settings['copy_meta'] ) ) {
            $skip_keys = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' ];
            $all_meta  = get_post_meta( $post_id );

            if ( $all_meta ) {
                foreach ( $all_meta as $key => $values ) {
                    if ( in_array( $key, $skip_keys, true ) ) {
                        continue;
                    }
                    // Skip the featured image meta — handled separately in step 7
                    if ( $key === '_thumbnail_id' ) {
                        continue;
                    }
                    foreach ( $values as $value ) {
                        add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
                    }
                }
            }
        }

        // Step 7: Copy featured image
        if ( ! empty( $settings['copy_featured_image'] ) ) {
            $thumb_id = get_post_thumbnail_id( $post_id );
            if ( $thumb_id ) {
                set_post_thumbnail( $new_id, $thumb_id );
            }
        }

        // Store the original post ID as meta on the new post
        add_post_meta( $new_id, '_wpt_duplicated_from', $post_id );

        return $new_id;
    }

    // ── Admin Notice ──────────────────────────────────────────

    /**
     * Show success notice after duplication.
     */
    public function show_duplicate_notice(): void {
        $new_id = get_transient( 'wpt_duplicate_notice_' . get_current_user_id() );
        if ( ! $new_id ) {
            return;
        }

        // Delete the transient so it only shows once
        delete_transient( 'wpt_duplicate_notice_' . get_current_user_id() );

        $edit_link = get_edit_post_link( (int) $new_id );

        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php esc_html_e( 'Post duplicated successfully.', 'wptransformed' ); ?>
                <?php if ( $edit_link ) : ?>
                    <a href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit new post', 'wptransformed' ); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $post_types['attachment'] );
        ?>

        <table class="form-table" role="presentation">

            <?php // Post Types ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Post Types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $post_types as $pt ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_post_types[]"
                                       value="<?php echo esc_attr( $pt->name ); ?>"
                                       <?php checked( in_array( $pt->name, (array) $settings['post_types'], true ) ); ?>>
                                <?php echo esc_html( $pt->labels->singular_name ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'Select which post types show the Duplicate link.', 'wptransformed' ); ?></p>
                    </fieldset>
                </td>
            </tr>

            <?php // Clone Options ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Clone Options', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="wpt_copy_taxonomies" value="1" <?php checked( $settings['copy_taxonomies'] ); ?>>
                            <?php esc_html_e( 'Copy Taxonomies (categories, tags, custom taxonomies)', 'wptransformed' ); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="wpt_copy_meta" value="1" <?php checked( $settings['copy_meta'] ); ?>>
                            <?php esc_html_e( 'Copy Custom Fields (post meta)', 'wptransformed' ); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="wpt_copy_featured_image" value="1" <?php checked( $settings['copy_featured_image'] ); ?>>
                            <?php esc_html_e( 'Copy Featured Image', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <?php // Title Format ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Title Format', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <?php esc_html_e( 'Prefix:', 'wptransformed' ); ?>
                        <input type="text" name="wpt_title_prefix" value="<?php echo esc_attr( $settings['title_prefix'] ); ?>" class="regular-text">
                    </label><br><br>
                    <label>
                        <?php esc_html_e( 'Suffix:', 'wptransformed' ); ?>
                        <input type="text" name="wpt_title_suffix" value="<?php echo esc_attr( $settings['title_suffix'] ); ?>" class="regular-text">
                    </label>
                    <p class="description"><?php esc_html_e( 'Text added before/after the duplicated post title.', 'wptransformed' ); ?></p>
                </td>
            </tr>

            <?php // New Post Status ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'New Post Status', 'wptransformed' ); ?></th>
                <td>
                    <select name="wpt_new_status">
                        <option value="draft" <?php selected( $settings['new_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'wptransformed' ); ?></option>
                        <option value="pending" <?php selected( $settings['new_status'], 'pending' ); ?>><?php esc_html_e( 'Pending Review', 'wptransformed' ); ?></option>
                        <option value="private" <?php selected( $settings['new_status'], 'private' ); ?>><?php esc_html_e( 'Private', 'wptransformed' ); ?></option>
                    </select>
                </td>
            </tr>

            <?php // After Duplication ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'After Duplication', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="radio" name="wpt_redirect_after" value="list" <?php checked( $settings['redirect_after'], 'list' ); ?>>
                            <?php esc_html_e( 'Return to post list', 'wptransformed' ); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="wpt_redirect_after" value="edit" <?php checked( $settings['redirect_after'], 'edit' ); ?>>
                            <?php esc_html_e( 'Open editor for new post', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_statuses  = [ 'draft', 'pending', 'private' ];
        $valid_redirects = [ 'list', 'edit' ];

        // Sanitize post types — only allow registered public post types
        $allowed_post_types = array_diff( array_keys( get_post_types( [ 'public' => true ] ) ), [ 'attachment' ] );
        $post_types = isset( $raw['wpt_post_types'] ) && is_array( $raw['wpt_post_types'] )
            ? array_intersect( array_map( 'sanitize_key', $raw['wpt_post_types'] ), $allowed_post_types )
            : [];

        return [
            'post_types'          => array_values( $post_types ),
            'copy_taxonomies'     => ! empty( $raw['wpt_copy_taxonomies'] ),
            'copy_meta'           => ! empty( $raw['wpt_copy_meta'] ),
            'copy_featured_image' => ! empty( $raw['wpt_copy_featured_image'] ),
            'title_prefix'        => sanitize_text_field( $raw['wpt_title_prefix'] ?? '' ),
            'title_suffix'        => sanitize_text_field( $raw['wpt_title_suffix'] ?? '' ),
            'new_status'          => in_array( $raw['wpt_new_status'] ?? '', $valid_statuses, true )
                                     ? $raw['wpt_new_status'] : 'draft',
            'redirect_after'      => in_array( $raw['wpt_redirect_after'] ?? '', $valid_redirects, true )
                                     ? $raw['wpt_redirect_after'] : 'list',
        ];
    }

    // ── Validate Settings (storage shape) ─────────────────────

    /**
     * Storage-shape validation (slice 10b override — closes the base
     * floor's gaps for this module): post_types are sanitize_key'd and
     * filtered to registered post types (default [post, page] when the
     * result is empty), enums are pinned, text fields sanitized.
     *
     * @param array $settings Storage-shape settings (untrusted).
     * @return array Validated storage-shape settings.
     */
    public function validate_settings( array $settings ): array {
        $valid_statuses  = [ 'draft', 'publish', 'pending', 'private' ];
        $valid_redirects = [ 'list', 'edit' ];

        $str = static function ( $value ): string {
            return is_scalar( $value ) ? (string) $value : '';
        };

        $post_types = [];
        if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
            foreach ( $settings['post_types'] as $type ) {
                $key = sanitize_key( $str( $type ) );
                if ( '' !== $key && post_type_exists( $key ) ) {
                    $post_types[] = $key;
                }
            }
        }
        if ( [] === $post_types ) {
            $post_types = [ 'post', 'page' ];
        }

        return [
            'post_types'          => array_values( $post_types ),
            'copy_taxonomies'     => ! empty( $settings['copy_taxonomies'] ),
            'copy_meta'           => ! empty( $settings['copy_meta'] ),
            'copy_featured_image' => ! empty( $settings['copy_featured_image'] ),
            'title_prefix'        => sanitize_text_field( $str( $settings['title_prefix'] ?? '' ) ),
            'title_suffix'        => sanitize_text_field( $str( $settings['title_suffix'] ?? '' ) ),
            'new_status'          => in_array( $settings['new_status'] ?? '', $valid_statuses, true )
                                     ? $settings['new_status'] : 'draft',
            'redirect_after'      => in_array( $settings['redirect_after'] ?? '', $valid_redirects, true )
                                     ? $settings['redirect_after'] : 'list',
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'post_meta', 'key' => '_wpt_duplicated_from' ],
        ];
    }
}
