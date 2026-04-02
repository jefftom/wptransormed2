<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Local User Avatar — Replace Gravatar with locally uploaded avatars.
 *
 * Features:
 *  - Upload avatar via media uploader on user profile page
 *  - Filter get_avatar_url to return local attachment URL
 *  - Option to disable Gravatar requests entirely
 *  - Default avatar setting (attachment ID)
 *  - Per-user avatar stored as _wpt_avatar user meta (attachment ID)
 *
 * @package WPTransformed
 */
class Local_User_Avatar extends Module_Base {

    /**
     * User meta key for the avatar attachment ID.
     */
    private const META_KEY = '_wpt_avatar';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'local-user-avatar';
    }

    public function get_title(): string {
        return __( 'Local User Avatar', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Allow users to upload custom avatars from the media library instead of using Gravatar.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'          => true,
            'disable_gravatar' => false,
            'default_avatar'   => '',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Filter avatar URL.
        add_filter( 'get_avatar_url', [ $this, 'filter_avatar_url' ], 10, 3 );

        // Block Gravatar requests if disabled.
        $settings = $this->get_settings();
        if ( ! empty( $settings['disable_gravatar'] ) ) {
            add_filter( 'pre_http_request', [ $this, 'block_gravatar_requests' ], 10, 3 );
            add_filter( 'option_show_avatars', '__return_true' );
        }

        // Profile page fields.
        add_action( 'show_user_profile', [ $this, 'render_profile_field' ] );
        add_action( 'edit_user_profile', [ $this, 'render_profile_field' ] );

        // Save profile.
        add_action( 'personal_options_update', [ $this, 'save_profile_field' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_profile_field' ] );

        // Enqueue media uploader on profile pages.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Avatar URL Filter ─────────────────────────────────────

    /**
     * Filter the avatar URL to use a local attachment if available.
     *
     * @param string $url         Current avatar URL.
     * @param mixed  $id_or_email User ID, email, or WP_Comment.
     * @param array  $args        Avatar arguments.
     * @return string Filtered avatar URL.
     */
    public function filter_avatar_url( string $url, $id_or_email, array $args ): string {
        $user_id = $this->resolve_user_id( $id_or_email );

        if ( $user_id ) {
            $attachment_id = (int) get_user_meta( $user_id, self::META_KEY, true );

            if ( $attachment_id > 0 ) {
                $size      = $args['size'] ?? 96;
                $image_url = wp_get_attachment_image_url( $attachment_id, [ $size, $size ] );

                if ( $image_url ) {
                    return $image_url;
                }
            }
        }

        // Fall back to default avatar if set.
        $settings         = $this->get_settings();
        $default_avatar   = (int) ( $settings['default_avatar'] ?? 0 );

        if ( $default_avatar > 0 ) {
            $size      = $args['size'] ?? 96;
            $image_url = wp_get_attachment_image_url( $default_avatar, [ $size, $size ] );

            if ( $image_url ) {
                return $image_url;
            }
        }

        // If Gravatar is disabled, return a blank/default.
        if ( ! empty( $settings['disable_gravatar'] ) && $this->is_gravatar_url( $url ) ) {
            return '';
        }

        return $url;
    }

    // ── Block Gravatar ────────────────────────────────────────

    /**
     * Block HTTP requests to Gravatar when disabled.
     *
     * @param false|array|\WP_Error $preempt Whether to preempt the request.
     * @param array                 $args    Request arguments.
     * @param string                $url     Request URL.
     * @return false|array|\WP_Error
     */
    public function block_gravatar_requests( $preempt, array $args, string $url ) {
        if ( $this->is_gravatar_url( $url ) ) {
            return new \WP_Error( 'gravatar_blocked', __( 'Gravatar requests are disabled by WPTransformed.', 'wptransformed' ) );
        }

        return $preempt;
    }

    // ── Profile Field ─────────────────────────────────────────

    /**
     * Render the avatar upload field on the user profile page.
     *
     * @param \WP_User $user User being edited.
     */
    public function render_profile_field( \WP_User $user ): void {
        $attachment_id = (int) get_user_meta( $user->ID, self::META_KEY, true );
        $preview_url   = '';

        if ( $attachment_id > 0 ) {
            $preview_url = wp_get_attachment_image_url( $attachment_id, [ 96, 96 ] );
        }

        wp_nonce_field( 'wpt_save_avatar_' . $user->ID, 'wpt_avatar_nonce' );

        ?>
        <h3><?php esc_html_e( 'Custom Avatar', 'wptransformed' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Avatar', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-avatar-preview" style="margin-bottom: 10px;">
                        <?php if ( $preview_url ) : ?>
                            <img src="<?php echo esc_url( $preview_url ); ?>" alt="" style="width: 96px; height: 96px; border-radius: 50%; object-fit: cover;">
                        <?php else : ?>
                            <?php echo get_avatar( $user->ID, 96 ); ?>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" id="wpt-avatar-id" name="wpt_avatar_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
                    <button type="button" class="button" id="wpt-upload-avatar">
                        <?php esc_html_e( 'Upload Avatar', 'wptransformed' ); ?>
                    </button>
                    <?php if ( $attachment_id > 0 ) : ?>
                        <button type="button" class="button" id="wpt-remove-avatar" style="margin-left: 8px;">
                            <?php esc_html_e( 'Remove Avatar', 'wptransformed' ); ?>
                        </button>
                    <?php endif; ?>
                    <p class="description">
                        <?php esc_html_e( 'Upload a custom avatar image. Recommended size: 256x256 pixels.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the avatar field from the user profile page.
     *
     * @param int $user_id User ID being saved.
     */
    public function save_profile_field( int $user_id ): void {
        if ( ! isset( $_POST['wpt_avatar_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpt_avatar_nonce'] ) ), 'wpt_save_avatar_' . $user_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $avatar_id = isset( $_POST['wpt_avatar_id'] ) ? absint( $_POST['wpt_avatar_id'] ) : 0;

        if ( $avatar_id > 0 ) {
            // Verify it's a valid attachment.
            if ( get_post_type( $avatar_id ) === 'attachment' ) {
                update_user_meta( $user_id, self::META_KEY, $avatar_id );
            }
        } else {
            delete_user_meta( $user_id, self::META_KEY );
        }
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            'wpt-local-user-avatar',
            WPT_URL . 'modules/content-management/js/local-user-avatar.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-local-user-avatar', 'wptLocalAvatar', [
            'title'  => __( 'Select Avatar', 'wptransformed' ),
            'button' => __( 'Use as Avatar', 'wptransformed' ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings       = $this->get_settings();
        $default_avatar = (int) ( $settings['default_avatar'] ?? 0 );
        $preview_url    = '';

        if ( $default_avatar > 0 ) {
            $preview_url = wp_get_attachment_image_url( $default_avatar, [ 96, 96 ] );
        }

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Gravatar', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_disable_gravatar" value="1"
                               <?php checked( ! empty( $settings['disable_gravatar'] ) ); ?>>
                        <?php esc_html_e( 'Block all external Gravatar requests', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When enabled, no requests will be made to gravatar.com. Users without a local avatar will see the default avatar.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Default Avatar', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-default-avatar-preview" style="margin-bottom: 10px;">
                        <?php if ( $preview_url ) : ?>
                            <img src="<?php echo esc_url( $preview_url ); ?>" alt="" style="width: 96px; height: 96px; border-radius: 50%; object-fit: cover;">
                        <?php else : ?>
                            <span style="color: #888;"><?php esc_html_e( 'No default avatar set.', 'wptransformed' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" id="wpt-default-avatar-id" name="wpt_default_avatar" value="<?php echo esc_attr( (string) $default_avatar ); ?>">
                    <button type="button" class="button" id="wpt-upload-default-avatar">
                        <?php esc_html_e( 'Select Default Avatar', 'wptransformed' ); ?>
                    </button>
                    <?php if ( $default_avatar > 0 ) : ?>
                        <button type="button" class="button" id="wpt-remove-default-avatar" style="margin-left: 8px;">
                            <?php esc_html_e( 'Remove', 'wptransformed' ); ?>
                        </button>
                    <?php endif; ?>
                    <p class="description">
                        <?php esc_html_e( 'Fallback avatar for users who have not uploaded one.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'          => true,
            'disable_gravatar' => ! empty( $raw['wpt_disable_gravatar'] ),
            'default_avatar'   => absint( $raw['wpt_default_avatar'] ?? 0 ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => self::META_KEY ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Resolve a user ID from various avatar identifier formats.
     *
     * @param mixed $id_or_email User ID, email string, or WP_Comment.
     * @return int User ID or 0.
     */
    private function resolve_user_id( $id_or_email ): int {
        if ( is_numeric( $id_or_email ) ) {
            return (int) $id_or_email;
        }

        if ( is_string( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            return $user ? $user->ID : 0;
        }

        if ( $id_or_email instanceof \WP_Comment ) {
            if ( ! empty( $id_or_email->user_id ) ) {
                return (int) $id_or_email->user_id;
            }

            if ( ! empty( $id_or_email->comment_author_email ) ) {
                $user = get_user_by( 'email', $id_or_email->comment_author_email );
                return $user ? $user->ID : 0;
            }
        }

        if ( $id_or_email instanceof \WP_User ) {
            return $id_or_email->ID;
        }

        if ( $id_or_email instanceof \WP_Post ) {
            return (int) $id_or_email->post_author;
        }

        return 0;
    }

    /**
     * Check if a URL is a Gravatar URL.
     *
     * @param string $url URL to check.
     * @return bool
     */
    private function is_gravatar_url( string $url ): bool {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return false;
        }

        return str_contains( $host, 'gravatar.com' );
    }
}
