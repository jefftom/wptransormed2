<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Comments — Comprehensively disable the WordPress comment system.
 *
 * Touches ~15 hooks to ensure every trace of comments is removed:
 * frontend forms, existing comment display, admin menu, admin bar,
 * metaboxes, list table columns, dashboard widgets, comment feeds,
 * X-Pingback header, XML-RPC pingback methods, and post type support.
 *
 * Never deletes existing comments — only hides/disables them.
 *
 * @package WPTransformed
 */
class Disable_Comments extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-comments';
    }

    public function get_title(): string {
        return __( 'Disable Comments', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Disable the WordPress comment system entirely or selectively per post type.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'mode'                 => 'everywhere',
            'disabled_post_types'  => [ 'post', 'page' ],
            'hide_existing'        => true,
            'remove_from_admin'    => true,
            'disable_pingbacks'    => true,
            'remove_discussion'    => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        // ── Frontend hooks ────────────────────────────────────

        // 1. Close comments on affected post types.
        add_filter( 'comments_open', [ $this, 'filter_comments_open' ], 20, 2 );

        // 2. Close pingbacks/trackbacks.
        if ( ! empty( $settings['disable_pingbacks'] ) ) {
            add_filter( 'pings_open', [ $this, 'filter_comments_open' ], 20, 2 );
        }

        // 3. Hide existing comments from frontend display.
        if ( ! empty( $settings['hide_existing'] ) ) {
            add_filter( 'comments_array', [ $this, 'filter_comments_array' ], 20, 2 );
        }

        // 4. Belt-and-suspenders: block comment form output for themes that don't check comments_open.
        add_action( 'comment_form', [ $this, 'block_comment_form' ] );

        // 5. Remove X-Pingback header.
        if ( ! empty( $settings['disable_pingbacks'] ) ) {
            add_filter( 'wp_headers', [ $this, 'remove_pingback_header' ] );
        }

        // 6. Redirect comment feed URLs to homepage.
        add_action( 'template_redirect', [ $this, 'redirect_comment_feeds' ] );

        // 7. Remove XML-RPC pingback methods.
        if ( ! empty( $settings['disable_pingbacks'] ) ) {
            add_filter( 'xmlrpc_methods', [ $this, 'remove_xmlrpc_pingback' ] );
        }

        // ── Admin hooks ───────────────────────────────────────

        if ( ! empty( $settings['remove_from_admin'] ) ) {
            // 9. Remove Comments menu.
            add_action( 'admin_menu', [ $this, 'remove_comments_menu' ], 999 );

            // 10. Remove Comments from admin bar.
            add_action( 'wp_before_admin_bar_render', [ $this, 'remove_admin_bar_comments' ], 999 );

            // 11. Remove Discussion/Comments metaboxes from post editor.
            add_action( 'add_meta_boxes', [ $this, 'remove_comment_metaboxes' ], 999 );

            // 13. Remove comments column from post/page list tables.
            add_filter( 'manage_posts_columns', [ $this, 'remove_comments_column' ] );
            add_filter( 'manage_pages_columns', [ $this, 'remove_comments_column' ] );

            // 14. Remove Recent Comments dashboard widget.
            add_action( 'wp_dashboard_setup', [ $this, 'remove_dashboard_comments_widget' ] );
        }

        // 12. Redirect Discussion settings page if option is enabled.
        if ( ! empty( $settings['remove_discussion'] ) ) {
            add_action( 'admin_init', [ $this, 'redirect_discussion_page' ] );
            add_action( 'admin_menu', [ $this, 'remove_discussion_menu' ], 999 );
        }

        // 15. Remove comment/trackback support from post types (priority 100 so types are registered).
        add_action( 'init', [ $this, 'remove_post_type_support' ], 100 );
    }

    // ── Frontend Callbacks ────────────────────────────────────

    /**
     * Return false for comments_open / pings_open on affected post types.
     *
     * @param bool $open    Current open status.
     * @param int  $post_id Post ID.
     * @return bool
     */
    public function filter_comments_open( bool $open, int $post_id ): bool {
        if ( $this->is_post_type_disabled( (string) get_post_type( $post_id ) ) ) {
            return false;
        }

        return $open;
    }

    /**
     * Return empty array for comments on affected post types.
     *
     * @param array<int,\WP_Comment> $comments Existing comments.
     * @param int                    $post_id  Post ID.
     * @return array<int,\WP_Comment>
     */
    public function filter_comments_array( array $comments, int $post_id ): array {
        if ( $this->is_post_type_disabled( (string) get_post_type( $post_id ) ) ) {
            return [];
        }

        return $comments;
    }

    /**
     * Block comment form output for themes that ignore comments_open.
     */
    public function block_comment_form(): void {
        $post = get_post();
        if ( $post && $this->is_post_type_disabled( (string) $post->post_type ) ) {
            // Output a closing tag to cut off any form markup already started,
            // then use output buffering to swallow the rest.
            ob_start();
            add_action( 'comment_form_after', function (): void {
                ob_end_clean();
            } );
        }
    }

    /**
     * Remove X-Pingback header from HTTP responses.
     *
     * @param array<string,string> $headers HTTP headers.
     * @return array<string,string>
     */
    public function remove_pingback_header( array $headers ): array {
        unset( $headers['X-Pingback'] );
        return $headers;
    }

    /**
     * Redirect comment feed URLs to homepage with 301.
     */
    public function redirect_comment_feeds(): void {
        if ( is_comment_feed() ) {
            wp_safe_redirect( home_url(), 301 );
            exit;
        }
    }

    /**
     * Remove XML-RPC pingback methods.
     *
     * @param array<string,mixed> $methods XML-RPC methods.
     * @return array<string,mixed>
     */
    public function remove_xmlrpc_pingback( array $methods ): array {
        unset(
            $methods['pingback.ping'],
            $methods['pingback.extensions.getPingbacks']
        );
        return $methods;
    }

    // ── Admin Callbacks ───────────────────────────────────────

    /**
     * Remove Comments menu item from admin sidebar.
     */
    public function remove_comments_menu(): void {
        remove_menu_page( 'edit-comments.php' );
    }

    /**
     * Remove comments node from admin bar.
     */
    public function remove_admin_bar_comments(): void {
        global $wp_admin_bar;
        if ( $wp_admin_bar ) {
            $wp_admin_bar->remove_node( 'comments' );
        }
    }

    /**
     * Remove Discussion (commentstatusdiv) and Comments (commentsdiv)
     * metaboxes from all affected post types.
     */
    public function remove_comment_metaboxes(): void {
        $post_types = $this->get_disabled_post_types();

        foreach ( $post_types as $pt ) {
            remove_meta_box( 'commentstatusdiv', $pt, 'normal' );
            remove_meta_box( 'commentsdiv', $pt, 'normal' );
        }
    }

    /**
     * Remove comments column from post/page list tables.
     *
     * @param array<string,string> $columns List table columns.
     * @return array<string,string>
     */
    public function remove_comments_column( array $columns ): array {
        unset( $columns['comments'] );
        return $columns;
    }

    /**
     * Remove Recent Comments dashboard widget.
     */
    public function remove_dashboard_comments_widget(): void {
        remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
    }

    /**
     * Redirect the Discussion settings page to General settings.
     */
    public function redirect_discussion_page(): void {
        global $pagenow;

        if ( $pagenow === 'options-discussion.php' ) {
            wp_safe_redirect( admin_url( 'options-general.php' ) );
            exit;
        }
    }

    /**
     * Remove Discussion submenu from Settings menu.
     */
    public function remove_discussion_menu(): void {
        remove_submenu_page( 'options-general.php', 'options-discussion.php' );
    }

    /**
     * Remove comments and trackbacks support from post types.
     */
    public function remove_post_type_support(): void {
        $post_types = $this->get_disabled_post_types();

        foreach ( $post_types as $pt ) {
            remove_post_type_support( $pt, 'comments' );
            remove_post_type_support( $pt, 'trackbacks' );
        }
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if a given post type has comments disabled.
     *
     * @param string $post_type Post type slug.
     * @return bool
     */
    private function is_post_type_disabled( string $post_type ): bool {
        if ( $post_type === '' ) {
            return false;
        }

        $settings = $this->get_settings();

        if ( $settings['mode'] === 'everywhere' ) {
            return true;
        }

        $disabled = $settings['disabled_post_types'] ?? [];
        return in_array( $post_type, $disabled, true );
    }

    /**
     * Get the list of post types that should have comments disabled.
     *
     * @return string[]
     */
    private function get_disabled_post_types(): array {
        $settings    = $this->get_settings();
        $public_types = get_post_types( [ 'public' => true ] );

        if ( $settings['mode'] === 'everywhere' ) {
            return array_values( $public_types );
        }

        $disabled = $settings['disabled_post_types'] ?? [];
        return array_values( array_intersect( $disabled, $public_types ) );
    }

    /**
     * Bulk close existing comments on affected post types.
     *
     * @return int Number of posts updated.
     */
    private function close_existing_comments(): int {
        global $wpdb;

        $post_types = $this->get_disabled_post_types();
        if ( empty( $post_types ) ) {
            return 0;
        }

        $total = 0;
        foreach ( $post_types as $pt ) {
            $count = (int) $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed' WHERE post_type = %s AND (comment_status = 'open' OR ping_status = 'open')",
                    $pt
                )
            );
            $total += $count;
        }

        return $total;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings    = $this->get_settings();
        $mode        = $settings['mode'];
        $disabled_pt = $settings['disabled_post_types'] ?? [];

        // Get all public post types with labels.
        $public_types = get_post_types( [ 'public' => true ], 'objects' );

        // Total existing comments count.
        $total_comments = (int) wp_count_comments()->total_comments;

        // Build per-post-type comment counts.
        $pt_comment_counts = [];
        foreach ( $public_types as $pt_obj ) {
            $pt_comment_counts[ $pt_obj->name ] = $this->count_comments_for_post_type( $pt_obj->name );
        }

        // ── Status Summary ────────────────────────────────────
        $this->render_status_summary( $settings, $public_types, $total_comments );

        ?>

        <table class="form-table" role="presentation">
            <!-- Mode Selection -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Mode', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="radio" name="wpt_mode" value="everywhere"
                                   <?php checked( $mode, 'everywhere' ); ?>
                                   id="wpt-mode-everywhere">
                            <strong><?php esc_html_e( 'Disable comments everywhere', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'All post types, no exceptions. The simplest option — comments are completely gone site-wide.', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="radio" name="wpt_mode" value="per_post_type"
                                   <?php checked( $mode, 'per_post_type' ); ?>
                                   id="wpt-mode-per-type">
                            <strong><?php esc_html_e( 'Disable for specific post types', 'wptransformed' ); ?></strong>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Choose which post types have comments disabled. Unchecked types keep their comments.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <!-- Post Type Checkboxes (shown only for per_post_type mode) -->
            <tr id="wpt-post-types-row" <?php echo $mode === 'everywhere' ? 'style="display:none;"' : ''; ?>>
                <th scope="row"><?php esc_html_e( 'Post Types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $public_types as $pt_obj ) :
                            $count = $pt_comment_counts[ $pt_obj->name ];
                            $count_label = $count > 0
                                ? sprintf( '(%s)', sprintf( _n( '%d comment', '%d comments', $count, 'wptransformed' ), $count ) )
                                : '(' . __( 'no comments', 'wptransformed' ) . ')';
                        ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="checkbox" name="wpt_disabled_post_types[]"
                                       value="<?php echo esc_attr( $pt_obj->name ); ?>"
                                       <?php checked( in_array( $pt_obj->name, $disabled_pt, true ) ); ?>>
                                <?php echo esc_html( $pt_obj->labels->name ); ?>
                                <span style="color: #888;"><?php echo esc_html( $count_label ); ?></span>
                                <?php if ( $pt_obj->name === 'product' ) : ?>
                                    <em style="color: #b26200; margin-left: 4px;">
                                        <?php esc_html_e( 'Disabling comments for Products will also disable product reviews.', 'wptransformed' ); ?>
                                    </em>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>

            <!-- Additional Options -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Options', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_close_existing" value="1">
                            <?php esc_html_e( 'Close existing comments on affected post types', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Set comment_status and ping_status to "closed" on all existing posts of the disabled types. This is a one-time database update — it does NOT delete any comments.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_remove_discussion" value="1"
                                   <?php checked( ! empty( $settings['remove_discussion'] ) ); ?>>
                            <?php esc_html_e( 'Remove "Discussion" from Settings menu', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Hides the Settings → Discussion page since it\'s not needed when comments are disabled.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_pingbacks" value="1"
                                   <?php checked( ! empty( $settings['disable_pingbacks'] ) ); ?>>
                            <?php esc_html_e( 'Disable pingbacks and trackbacks', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Removes X-Pingback HTTP header and disables XML-RPC pingback methods. Recommended for security.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="wpt_hide_existing" value="1"
                                   <?php checked( ! empty( $settings['hide_existing'] ) ); ?>>
                            <?php esc_html_e( 'Hide existing comments from frontend', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Hide previously posted comments from display. Comments are not deleted — they will reappear if you re-enable.', 'wptransformed' ); ?>
                            </p>
                        </label>

                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="wpt_remove_from_admin" value="1"
                                   <?php checked( ! empty( $settings['remove_from_admin'] ) ); ?>>
                            <?php esc_html_e( 'Remove comments from admin', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Remove the Comments menu, admin bar counter, list table columns, and discussion metaboxes.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <p class="description" style="margin-top: 12px; font-style: italic;">
            <?php esc_html_e( 'Existing comments are preserved in the database. If you disable this module or re-enable comments, they will reappear.', 'wptransformed' ); ?>
        </p>

        <script>
        (function() {
            var radios = document.querySelectorAll('input[name="wpt_mode"]');
            var row = document.getElementById('wpt-post-types-row');
            if (!radios.length || !row) return;
            function toggle() {
                var mode = document.querySelector('input[name="wpt_mode"]:checked');
                row.style.display = (mode && mode.value === 'per_post_type') ? '' : 'none';
            }
            radios.forEach(function(r) { r.addEventListener('change', toggle); });
        })();
        </script>
        <?php
    }

    /**
     * Render status summary at top of settings.
     *
     * @param array<string,mixed>                    $settings     Module settings.
     * @param array<string,\WP_Post_Type>            $public_types Public post type objects.
     * @param int                                    $total_comments Total comment count.
     */
    private function render_status_summary( array $settings, array $public_types, int $total_comments ): void {
        $mode = $settings['mode'];
        ?>
        <div style="background: #fff; border: 1px solid #ddd; border-left: 4px solid #d63638; border-radius: 4px; padding: 12px 16px; margin-bottom: 16px;">
            <?php if ( $mode === 'everywhere' ) : ?>
                <p style="margin: 0 0 4px 0;">
                    <strong><?php esc_html_e( 'Comments are disabled on all post types.', 'wptransformed' ); ?></strong>
                </p>
            <?php else :
                $disabled_pt = $settings['disabled_post_types'] ?? [];
                $enabled_names  = [];
                $disabled_names = [];
                foreach ( $public_types as $pt_obj ) {
                    if ( in_array( $pt_obj->name, $disabled_pt, true ) ) {
                        $disabled_names[] = $pt_obj->labels->name;
                    } else {
                        $enabled_names[] = $pt_obj->labels->name;
                    }
                }
                ?>
                <p style="margin: 0 0 4px 0;">
                    <?php if ( ! empty( $disabled_names ) ) : ?>
                        <strong><?php esc_html_e( 'Comments disabled on:', 'wptransformed' ); ?></strong>
                        <?php echo esc_html( implode( ', ', $disabled_names ) ); ?><br>
                    <?php endif; ?>
                    <?php if ( ! empty( $enabled_names ) ) : ?>
                        <strong><?php esc_html_e( 'Comments still enabled on:', 'wptransformed' ); ?></strong>
                        <?php echo esc_html( implode( ', ', $enabled_names ) ); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <p style="margin: 4px 0 0 0; color: #666;">
                <?php
                printf(
                    /* translators: %s: total number of existing comments */
                    esc_html__( 'Total existing comments in database: %s (these are preserved — disable does not delete)', 'wptransformed' ),
                    '<strong>' . esc_html( number_format_i18n( $total_comments ) ) . '</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Count comments for a specific post type.
     *
     * @param string $post_type Post type slug.
     * @return int
     */
    private function count_comments_for_post_type( string $post_type ): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID WHERE p.post_type = %s",
                $post_type
            )
        );
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // Mode.
        $mode = ( $raw['wpt_mode'] ?? 'everywhere' ) === 'per_post_type' ? 'per_post_type' : 'everywhere';

        // Disabled post types — only accept valid public post types.
        $valid_types   = array_keys( get_post_types( [ 'public' => true ] ) );
        $submitted_pt  = (array) ( $raw['wpt_disabled_post_types'] ?? [] );
        $disabled_pt   = array_values( array_intersect(
            array_map( 'sanitize_key', $submitted_pt ),
            $valid_types
        ) );

        // Boolean options.
        $hide_existing    = ! empty( $raw['wpt_hide_existing'] );
        $remove_from_admin = ! empty( $raw['wpt_remove_from_admin'] );
        $disable_pingbacks = ! empty( $raw['wpt_disable_pingbacks'] );
        $remove_discussion = ! empty( $raw['wpt_remove_discussion'] );

        // Build clean settings.
        $clean = [
            'mode'                => $mode,
            'disabled_post_types' => $disabled_pt,
            'hide_existing'       => $hide_existing,
            'remove_from_admin'   => $remove_from_admin,
            'disable_pingbacks'   => $disable_pingbacks,
            'remove_discussion'   => $remove_discussion,
        ];

        // One-time bulk close: if checkbox was checked, execute it now.
        if ( ! empty( $raw['wpt_close_existing'] ) ) {
            // Temporarily update settings so get_disabled_post_types() uses the new values.
            // We need to save first, then run the bulk close.
            \WPTransformed\Core\Settings::set( $this->get_id(), $clean );

            $count = $this->close_existing_comments();

            if ( $count > 0 ) {
                add_settings_error(
                    'wpt_settings',
                    'wpt_comments_closed',
                    sprintf(
                        /* translators: %d: number of posts updated */
                        _n(
                            'Closed comments on %d post.',
                            'Closed comments on %d posts.',
                            $count,
                            'wptransformed'
                        ),
                        $count
                    ),
                    'success'
                );
            }
        }

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom CSS or JS — pure server-rendered settings UI.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
