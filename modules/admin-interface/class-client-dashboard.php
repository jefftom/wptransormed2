<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Client Dashboard -- Replace the default dashboard with a simplified view for non-admin roles.
 *
 * Features:
 *  - Custom welcome widget with configurable message
 *  - Quick link buttons to common admin pages
 *  - Basic site statistics (posts, pages, comments)
 *  - Configurable per-role activation
 *  - Admins always see the normal dashboard
 *
 * @package WPTransformed
 */
class Client_Dashboard extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'client-dashboard';
    }

    public function get_title(): string {
        return __( 'Client Dashboard', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Replace the default dashboard with a clean, simplified view for non-admin roles.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled_for'     => [ 'editor', 'author', 'subscriber' ],
            'welcome_message' => 'Welcome to your site!',
            'quick_links'     => [
                [
                    'label' => 'Add Post',
                    'url'   => 'post-new.php',
                    'icon'  => 'dashicons-plus',
                ],
            ],
            'show_site_stats' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'wp_dashboard_setup', [ $this, 'setup_client_dashboard' ], 999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Dashboard Setup ───────────────────────────────────────

    /**
     * Replace dashboard widgets for users in enabled roles.
     */
    public function setup_client_dashboard(): void {
        // Admins always see normal dashboard.
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $user     = wp_get_current_user();

        if ( ! $this->user_has_enabled_role( $user, (array) $settings['enabled_for'] ) ) {
            return;
        }

        // Remove all default widgets.
        global $wp_meta_boxes;
        $wp_meta_boxes['dashboard'] = [];

        // Add our custom welcome widget.
        wp_add_dashboard_widget(
            'wpt_client_welcome',
            __( 'Welcome', 'wptransformed' ),
            [ $this, 'render_welcome_widget' ]
        );
    }

    /**
     * Check if user has any of the enabled roles.
     *
     * @param \WP_User $user        WordPress user object.
     * @param string[] $enabled_roles Array of role slugs.
     * @return bool
     */
    private function user_has_enabled_role( \WP_User $user, array $enabled_roles ): bool {
        if ( empty( $user->roles ) || empty( $enabled_roles ) ) {
            return false;
        }

        return ! empty( array_intersect( $user->roles, $enabled_roles ) );
    }

    // ── Welcome Widget ────────────────────────────────────────

    /**
     * Render the client welcome widget with message, quick links, and stats.
     */
    public function render_welcome_widget(): void {
        $settings = $this->get_settings();
        $user     = wp_get_current_user();

        ?>
        <div class="wpt-client-dashboard">
            <div class="wpt-client-welcome-message">
                <h2><?php echo esc_html( $settings['welcome_message'] ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s: user display name */
                        esc_html__( 'Hello, %s. Here are your quick actions and site overview.', 'wptransformed' ),
                        esc_html( $user->display_name )
                    );
                    ?>
                </p>
            </div>

            <?php $this->render_quick_links( (array) $settings['quick_links'] ); ?>

            <?php if ( ! empty( $settings['show_site_stats'] ) ) : ?>
                <?php $this->render_site_stats(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render quick link buttons.
     *
     * @param array $links Array of link configs.
     */
    private function render_quick_links( array $links ): void {
        if ( empty( $links ) ) {
            return;
        }

        ?>
        <div class="wpt-client-quick-links">
            <h3><?php esc_html_e( 'Quick Actions', 'wptransformed' ); ?></h3>
            <div class="wpt-client-links-grid">
                <?php foreach ( $links as $link ) : ?>
                    <?php
                    if ( empty( $link['label'] ) || empty( $link['url'] ) ) {
                        continue;
                    }

                    $icon = ! empty( $link['icon'] ) ? sanitize_html_class( $link['icon'] ) : 'dashicons-admin-links';
                    $url  = $link['url'];

                    // Relative URLs get prefixed with admin_url.
                    if ( strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 ) {
                        $url = admin_url( $url );
                    }
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="wpt-client-link-button">
                        <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                        <span class="wpt-client-link-label"><?php echo esc_html( $link['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render basic site statistics.
     */
    private function render_site_stats(): void {
        $post_count    = (int) wp_count_posts()->publish;
        $page_count    = (int) wp_count_posts( 'page' )->publish;
        $comment_count = (int) wp_count_comments()->approved;

        ?>
        <div class="wpt-client-stats">
            <h3><?php esc_html_e( 'Site Overview', 'wptransformed' ); ?></h3>
            <div class="wpt-client-stats-grid">
                <div class="wpt-client-stat-card">
                    <span class="dashicons dashicons-admin-post"></span>
                    <div class="wpt-client-stat-number"><?php echo esc_html( (string) $post_count ); ?></div>
                    <div class="wpt-client-stat-label"><?php esc_html_e( 'Published Posts', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-client-stat-card">
                    <span class="dashicons dashicons-admin-page"></span>
                    <div class="wpt-client-stat-number"><?php echo esc_html( (string) $page_count ); ?></div>
                    <div class="wpt-client-stat-label"><?php esc_html_e( 'Published Pages', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-client-stat-card">
                    <span class="dashicons dashicons-admin-comments"></span>
                    <div class="wpt-client-stat-number"><?php echo esc_html( (string) $comment_count ); ?></div>
                    <div class="wpt-client-stat-label"><?php esc_html_e( 'Approved Comments', 'wptransformed' ); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings    = $this->get_settings();
        $all_roles   = wp_roles()->role_names;
        $enabled_for = (array) $settings['enabled_for'];
        $quick_links = (array) $settings['quick_links'];

        // Remove administrator from the selectable roles.
        unset( $all_roles['administrator'] );

        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable for Roles', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $all_roles as $slug => $name ) : ?>
                            <label style="display: block; margin-bottom: 6px;">
                                <input type="checkbox" name="wpt_enabled_for[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, $enabled_for, true ) ); ?>>
                                <?php echo esc_html( $name ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Administrators always see the default dashboard.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-welcome-message"><?php esc_html_e( 'Welcome Message', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-welcome-message" name="wpt_welcome_message"
                           class="large-text"
                           value="<?php echo esc_attr( $settings['welcome_message'] ); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Show Site Stats', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_site_stats" value="1"
                               <?php checked( ! empty( $settings['show_site_stats'] ) ); ?>>
                        <?php esc_html_e( 'Display post, page, and comment counts', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Quick Links', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-quick-links-list">
                        <?php
                        $index = 0;
                        foreach ( $quick_links as $link ) :
                            if ( empty( $link['label'] ) && empty( $link['url'] ) ) {
                                continue;
                            }
                            ?>
                            <div class="wpt-quick-link-row" style="margin-bottom: 8px; display: flex; gap: 8px; align-items: center;">
                                <input type="text" name="wpt_quick_links[<?php echo (int) $index; ?>][label]"
                                       value="<?php echo esc_attr( $link['label'] ?? '' ); ?>"
                                       placeholder="<?php esc_attr_e( 'Label', 'wptransformed' ); ?>"
                                       style="width: 150px;">
                                <input type="text" name="wpt_quick_links[<?php echo (int) $index; ?>][url]"
                                       value="<?php echo esc_attr( $link['url'] ?? '' ); ?>"
                                       placeholder="<?php esc_attr_e( 'URL (e.g., post-new.php)', 'wptransformed' ); ?>"
                                       style="width: 250px;">
                                <input type="text" name="wpt_quick_links[<?php echo (int) $index; ?>][icon]"
                                       value="<?php echo esc_attr( $link['icon'] ?? '' ); ?>"
                                       placeholder="<?php esc_attr_e( 'dashicons-plus', 'wptransformed' ); ?>"
                                       style="width: 180px;">
                                <button type="button" class="button button-small wpt-remove-link"
                                        onclick="this.closest('.wpt-quick-link-row').remove();">
                                    <?php esc_html_e( 'Remove', 'wptransformed' ); ?>
                                </button>
                            </div>
                            <?php
                            $index++;
                        endforeach;
                        ?>
                    </div>
                    <button type="button" class="button button-secondary" id="wpt-add-quick-link">
                        <?php esc_html_e( 'Add Quick Link', 'wptransformed' ); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e( 'Use relative URLs like "post-new.php" or full URLs. Icons use Dashicons class names.', 'wptransformed' ); ?>
                    </p>

                    <script>
                    (function() {
                        var addBtn = document.getElementById('wpt-add-quick-link');
                        if (!addBtn) return;

                        addBtn.addEventListener('click', function() {
                            var list = document.getElementById('wpt-quick-links-list');
                            var rows = list.querySelectorAll('.wpt-quick-link-row');
                            var idx = rows.length;

                            var row = document.createElement('div');
                            row.className = 'wpt-quick-link-row';
                            row.style.cssText = 'margin-bottom: 8px; display: flex; gap: 8px; align-items: center;';
                            row.innerHTML =
                                '<input type="text" name="wpt_quick_links[' + idx + '][label]" placeholder="Label" style="width: 150px;">' +
                                '<input type="text" name="wpt_quick_links[' + idx + '][url]" placeholder="URL (e.g., post-new.php)" style="width: 250px;">' +
                                '<input type="text" name="wpt_quick_links[' + idx + '][icon]" placeholder="dashicons-plus" style="width: 180px;">' +
                                '<button type="button" class="button button-small wpt-remove-link" onclick="this.closest(\'.wpt-quick-link-row\').remove();">Remove</button>';

                            list.appendChild(row);
                        });
                    })();
                    </script>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $enabled_for = [];
        if ( isset( $raw['wpt_enabled_for'] ) && is_array( $raw['wpt_enabled_for'] ) ) {
            $valid_roles = array_keys( wp_roles()->role_names );
            foreach ( $raw['wpt_enabled_for'] as $role ) {
                $role = sanitize_key( $role );
                if ( 'administrator' !== $role && in_array( $role, $valid_roles, true ) ) {
                    $enabled_for[] = $role;
                }
            }
        }

        $welcome_message = isset( $raw['wpt_welcome_message'] )
            ? sanitize_text_field( wp_unslash( $raw['wpt_welcome_message'] ) )
            : $this->get_default_settings()['welcome_message'];

        $quick_links = [];
        if ( isset( $raw['wpt_quick_links'] ) && is_array( $raw['wpt_quick_links'] ) ) {
            foreach ( $raw['wpt_quick_links'] as $link ) {
                if ( ! is_array( $link ) ) {
                    continue;
                }

                $label = isset( $link['label'] ) ? sanitize_text_field( $link['label'] ) : '';
                $url   = isset( $link['url'] ) ? sanitize_text_field( $link['url'] ) : '';
                $icon  = isset( $link['icon'] ) ? sanitize_html_class( $link['icon'] ) : '';

                if ( empty( $label ) || empty( $url ) ) {
                    continue;
                }

                $quick_links[] = [
                    'label' => $label,
                    'url'   => $url,
                    'icon'  => $icon,
                ];
            }
        }

        // Cap at 20 quick links.
        $quick_links = array_slice( $quick_links, 0, 20 );

        $show_site_stats = ! empty( $raw['wpt_show_site_stats'] );

        return [
            'enabled_for'     => $enabled_for,
            'welcome_message' => $welcome_message,
            'quick_links'     => $quick_links,
            'show_site_stats' => $show_site_stats,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Only on dashboard page and for client dashboard users.
        if ( 'index.php' !== $hook ) {
            return;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $user     = wp_get_current_user();

        if ( ! $this->user_has_enabled_role( $user, (array) $settings['enabled_for'] ) ) {
            return;
        }

        wp_enqueue_style(
            'wpt-client-dashboard',
            WPT_URL . 'modules/admin-interface/css/client-dashboard.css',
            [],
            WPT_VERSION
        );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
