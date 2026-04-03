<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Media Visibility Control — Restrict media library to own uploads for non-admin roles.
 *
 * Filters both the AJAX media grid and the list-table view so that
 * restricted roles only see their own uploads. Administrators always
 * see all media.
 *
 * @package WPTransformed
 */
class Media_Visibility_Control extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'media-visibility-control';
    }

    public function get_title(): string {
        return __( 'Media Visibility Control', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Restrict media library visibility so non-admin users only see their own uploads.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'          => true,
            'restricted_roles' => [ 'author', 'contributor', 'editor' ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // AJAX media grid (modal and media library grid view).
        add_filter( 'ajax_query_attachments_args', [ $this, 'filter_ajax_attachments' ] );

        // List-table view (upload.php).
        add_action( 'pre_get_posts', [ $this, 'filter_media_list_query' ] );
    }

    // ── Query Filters ─────────────────────────────────────────

    /**
     * Filter AJAX attachment queries for restricted roles.
     *
     * @param array<string,mixed> $query Attachment query args.
     * @return array<string,mixed>
     */
    public function filter_ajax_attachments( array $query ): array {
        if ( $this->is_user_restricted() ) {
            $query['author'] = get_current_user_id();
        }

        return $query;
    }

    /**
     * Filter the media list-table query for restricted roles.
     *
     * @param \WP_Query $query The query object.
     */
    public function filter_media_list_query( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'upload' ) {
            return;
        }

        if ( $this->is_user_restricted() ) {
            $query->set( 'author', get_current_user_id() );
        }
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the current user is restricted from seeing all media.
     *
     * Administrators always see all media.
     */
    private function is_user_restricted(): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return false;
        }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $settings         = $this->get_settings();
        $restricted_roles = (array) ( $settings['restricted_roles'] ?? [] );

        if ( empty( $restricted_roles ) ) {
            return false;
        }

        return ! empty( array_intersect( $user->roles, $restricted_roles ) );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings         = $this->get_settings();
        $restricted_roles = (array) ( $settings['restricted_roles'] ?? [] );

        // Get non-admin roles.
        $all_roles = wp_roles()->get_names();
        unset( $all_roles['administrator'] );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Restrict media library visibility for non-admin roles.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Restricted Roles', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $all_roles as $slug => $name ) : ?>
                            <label>
                                <input type="checkbox" name="wpt_restricted_roles[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, $restricted_roles, true ) ); ?>>
                                <?php echo esc_html( translate_user_role( $name ) ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Users with these roles will only see their own media uploads. Administrators always see all media.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_roles = array_keys( wp_roles()->get_names() );
        $submitted   = $raw['wpt_restricted_roles'] ?? [];

        // Only keep valid, non-admin role slugs.
        $restricted = array_values( array_filter(
            array_intersect(
                array_map( 'sanitize_text_field', (array) $submitted ),
                $valid_roles
            ),
            static fn( string $role ): bool => $role !== 'administrator'
        ) );

        return [
            'enabled'          => ! empty( $raw['wpt_enabled'] ),
            'restricted_roles' => $restricted,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
