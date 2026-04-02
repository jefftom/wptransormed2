<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Post Type Switcher -- Change the post type of any post from the editor.
 *
 * @package WPTransformed
 */
class Post_Type_Switcher extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'post-type-switcher';
    }

    public function get_title(): string {
        return __( 'Post Type Switcher', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Switch a post between different post types from the editor Publish metabox.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'post_submitbox_misc_actions', [ $this, 'render_post_type_dropdown' ] );
        add_action( 'save_post', [ $this, 'handle_post_type_switch' ], 10, 2 );
        add_action( 'admin_notices', [ $this, 'show_switch_notice' ] );
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );
    }

    // -- Metabox Dropdown --

    /**
     * Add a post type dropdown to the Publish metabox.
     *
     * @param \WP_Post $post Current post object.
     */
    public function render_post_type_dropdown( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        // Only for existing posts (not new)
        if ( $post->post_status === 'auto-draft' ) {
            return;
        }

        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $post_types['attachment'] );

        // Filter to only post types the user can publish
        $post_types = array_filter( $post_types, function( $pt ) {
            return current_user_can( $pt->cap->publish_posts );
        } );

        if ( count( $post_types ) < 2 ) {
            return;
        }

        wp_nonce_field( 'wpt_switch_post_type_' . $post->ID, 'wpt_switch_post_type_nonce' );
        ?>
        <div class="misc-pub-section wpt-post-type-switcher">
            <label for="wpt_post_type">
                <strong><?php esc_html_e( 'Post Type:', 'wptransformed' ); ?></strong>
            </label>
            <select name="wpt_post_type" id="wpt_post_type" style="margin-left: 8px;">
                <?php foreach ( $post_types as $pt ) : ?>
                    <option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $post->post_type, $pt->name ); ?>>
                        <?php echo esc_html( $pt->labels->singular_name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    // -- Save Handler --

    /**
     * Handle post type switch on save.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function handle_post_type_switch( int $post_id, \WP_Post $post ): void {
        // Skip autosaves and revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Verify nonce
        if ( ! isset( $_POST['wpt_switch_post_type_nonce'] ) ||
             ! wp_verify_nonce( $_POST['wpt_switch_post_type_nonce'], 'wpt_switch_post_type_' . $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $new_type = isset( $_POST['wpt_post_type'] ) ? sanitize_key( $_POST['wpt_post_type'] ) : '';
        if ( empty( $new_type ) || $new_type === $post->post_type ) {
            return;
        }

        // Validate the target post type exists and is public
        $pt_object = get_post_type_object( $new_type );
        if ( ! $pt_object || ! $pt_object->public ) {
            return;
        }

        // Verify the user can publish in the target post type
        if ( ! current_user_can( $pt_object->cap->publish_posts ) ) {
            return;
        }

        // Unhook to prevent infinite loop
        remove_action( 'save_post', [ $this, 'handle_post_type_switch' ], 10 );

        // Switch the post type
        wp_update_post( [
            'ID'        => $post_id,
            'post_type' => $new_type,
        ] );

        // Re-hook
        add_action( 'save_post', [ $this, 'handle_post_type_switch' ], 10, 2 );

        // Flag rewrite rules for flush on next load
        update_option( 'wpt_flush_rewrite_rules', true );

        // Set transient for notice
        set_transient(
            'wpt_post_type_switched_' . get_current_user_id(),
            [
                'post_id'  => $post_id,
                'old_type' => $post->post_type,
                'new_type' => $new_type,
            ],
            30
        );
    }

    // -- Rewrite Flush --

    /**
     * Flush rewrite rules if flagged.
     * Called via init hook (registered in the main init method).
     */
    public function maybe_flush_rewrite_rules(): void {
        if ( get_option( 'wpt_flush_rewrite_rules' ) ) {
            delete_option( 'wpt_flush_rewrite_rules' );
            flush_rewrite_rules( false );
        }
    }

    // -- Notice --

    /**
     * Show admin notice after a successful post type switch.
     */
    public function show_switch_notice(): void {
        $data = get_transient( 'wpt_post_type_switched_' . get_current_user_id() );
        if ( ! $data ) {
            return;
        }

        delete_transient( 'wpt_post_type_switched_' . get_current_user_id() );

        $old_pt = get_post_type_object( $data['old_type'] );
        $new_pt = get_post_type_object( $data['new_type'] );

        $old_label = $old_pt ? $old_pt->labels->singular_name : $data['old_type'];
        $new_label = $new_pt ? $new_pt->labels->singular_name : $data['new_type'];

        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: 1: old post type label, 2: new post type label */
                    esc_html__( 'Post type changed from "%1$s" to "%2$s".', 'wptransformed' ),
                    esc_html( $old_label ),
                    esc_html( $new_label )
                );
                ?>
            </p>
        </div>
        <?php
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
                        <?php esc_html_e( 'Add Post Type dropdown to the Publish metabox.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => 'wpt_flush_rewrite_rules' ],
        ];
    }
}
