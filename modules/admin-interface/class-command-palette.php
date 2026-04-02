<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Command Palette — Cmd+K / Ctrl+K searchable command overlay.
 *
 * Indexes admin pages from the global menu, WPTransformed modules,
 * quick actions, and recent pages. Supports AJAX content search,
 * fuzzy matching, full keyboard navigation, and accessibility
 * (combobox role, aria attrs, focus trap).
 *
 * @package WPTransformed
 */
class Command_Palette extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'command-palette';
    }

    public function get_title(): string {
        return __( 'Command Palette', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Cmd+K / Ctrl+K opens a searchable command palette for instant access to any admin page, module setting, or quick action.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'shortcut'       => 'mod+k',
            'show_recent'    => true,
            'recent_count'   => 5,
            'search_content' => true,
            'custom_commands' => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'admin_footer', [ $this, 'render_palette_html' ] );
        add_action( 'wp_footer', [ $this, 'render_palette_html_frontend' ] );
        add_action( 'wp_ajax_wpt_palette_search', [ $this, 'ajax_content_search' ] );
        add_action( 'wp_ajax_wpt_palette_track_page', [ $this, 'ajax_track_recent_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
    }

    // ── Palette HTML ──────────────────────────────────────────

    /**
     * Output the palette HTML in admin footer.
     */
    public function render_palette_html(): void {
        $this->output_palette_markup();
    }

    /**
     * Output the palette HTML on the frontend (only if admin bar visible).
     */
    public function render_palette_html_frontend(): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $this->output_palette_markup();
    }

    /**
     * Shared palette markup.
     */
    private function output_palette_markup(): void {
        $input_id   = 'wpt-palette-input';
        $listbox_id = 'wpt-palette-results';
        ?>
        <div id="wpt-command-palette" class="wpt-palette-hidden" role="dialog" aria-label="<?php esc_attr_e( 'Command Palette', 'wptransformed' ); ?>">
            <div class="wpt-palette-overlay"></div>
            <div class="wpt-palette-dialog">
                <div class="wpt-palette-search">
                    <span class="wpt-palette-icon dashicons dashicons-search" aria-hidden="true"></span>
                    <input
                        type="text"
                        id="<?php echo esc_attr( $input_id ); ?>"
                        class="wpt-palette-input"
                        placeholder="<?php esc_attr_e( 'Search pages, actions, content...', 'wptransformed' ); ?>"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-owns="<?php echo esc_attr( $listbox_id ); ?>"
                        aria-controls="<?php echo esc_attr( $listbox_id ); ?>"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        aria-activedescendant=""
                    >
                    <kbd class="wpt-palette-shortcut">Esc</kbd>
                </div>
                <ul id="<?php echo esc_attr( $listbox_id ); ?>" class="wpt-palette-list" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'wptransformed' ); ?>">
                </ul>
                <div class="wpt-palette-footer">
                    <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> <?php esc_html_e( 'navigate', 'wptransformed' ); ?></span>
                    <span><kbd>&crarr;</kbd> <?php esc_html_e( 'select', 'wptransformed' ); ?></span>
                    <span><kbd>esc</kbd> <?php esc_html_e( 'close', 'wptransformed' ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        $this->enqueue_palette_assets( true );
    }

    public function enqueue_frontend_assets(): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $this->enqueue_palette_assets( false );
    }

    /**
     * Enqueue CSS, JS, and localized data for the palette.
     *
     * @param bool $is_admin Whether we are in the admin context.
     */
    private function enqueue_palette_assets( bool $is_admin ): void {
        wp_enqueue_style(
            'wpt-command-palette',
            WPT_URL . 'modules/admin-interface/css/command-palette.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-command-palette',
            WPT_URL . 'modules/admin-interface/js/command-palette.js',
            [],
            WPT_VERSION,
            true
        );

        $settings = $this->get_settings();

        wp_localize_script( 'wpt-command-palette', 'wptCommandPalette', [
            'ajaxUrl'       => esc_url( admin_url( 'admin-ajax.php' ) ),
            'nonce'         => wp_create_nonce( 'wpt_palette_search' ),
            'trackNonce'    => wp_create_nonce( 'wpt_palette_track_page' ),
            'shortcut'      => sanitize_text_field( $settings['shortcut'] ),
            'showRecent'    => (bool) $settings['show_recent'],
            'recentCount'   => (int) $settings['recent_count'],
            'searchContent' => (bool) $settings['search_content'],
            'isAdmin'       => $is_admin,
            'adminUrl'      => esc_url( admin_url() ),
            'items'         => $this->build_search_index(),
            'recentPages'   => $this->get_recent_pages(),
            'i18n'          => [
                'noResults'    => __( 'No results found.', 'wptransformed' ),
                'recentPages'  => __( 'Recent Pages', 'wptransformed' ),
                'adminPages'   => __( 'Admin Pages', 'wptransformed' ),
                'modules'      => __( 'Modules', 'wptransformed' ),
                'quickActions' => __( 'Quick Actions', 'wptransformed' ),
                'content'      => __( 'Content', 'wptransformed' ),
                'searching'    => __( 'Searching...', 'wptransformed' ),
            ],
        ] );
    }

    // ── Search Index ──────────────────────────────────────────

    /**
     * Build the static search index from admin menus, modules, and quick actions.
     *
     * @return array<int, array{type: string, icon: string, title: string, subtitle: string, url: string, action: string|null}>
     */
    private function build_search_index(): array {
        return array_merge(
            $this->get_admin_menu_items(),
            $this->get_module_items(),
            $this->get_quick_action_items()
        );
    }

    /**
     * Parse admin menu and submenu globals into search items.
     *
     * @return array
     */
    private function get_admin_menu_items(): array {
        $items = [];

        if ( empty( $GLOBALS['menu'] ) || ! is_array( $GLOBALS['menu'] ) ) {
            return $items;
        }

        $admin_url = admin_url();

        foreach ( $GLOBALS['menu'] as $menu_item ) {
            if ( empty( $menu_item[0] ) || empty( $menu_item[2] ) ) {
                continue;
            }

            if ( strpos( $menu_item[4] ?? '', 'wp-menu-separator' ) !== false ) {
                continue;
            }

            if ( ! current_user_can( $menu_item[1] ) ) {
                continue;
            }

            $title = wp_strip_all_tags( $menu_item[0] );
            $url   = $this->menu_slug_to_url( $menu_item[2], $admin_url );
            $icon  = 'dashicons-admin-generic';
            if ( ! empty( $menu_item[6] ) && strpos( $menu_item[6], 'dashicons-' ) === 0 ) {
                $icon = $menu_item[6];
            }

            $items[] = [
                'type'     => 'page',
                'icon'     => $icon,
                'title'    => $title,
                'subtitle' => __( 'Admin Pages', 'wptransformed' ),
                'url'      => $url,
                'action'   => null,
            ];

            if ( ! empty( $GLOBALS['submenu'][ $menu_item[2] ] ) && is_array( $GLOBALS['submenu'][ $menu_item[2] ] ) ) {
                foreach ( $GLOBALS['submenu'][ $menu_item[2] ] as $sub ) {
                    if ( empty( $sub[0] ) || empty( $sub[2] ) ) {
                        continue;
                    }
                    if ( ! current_user_can( $sub[1] ) ) {
                        continue;
                    }

                    $sub_title = wp_strip_all_tags( $sub[0] );

                    // Skip if identical to parent title (first submenu often duplicates).
                    if ( $sub_title === $title && $sub[2] === $menu_item[2] ) {
                        continue;
                    }

                    $sub_url = $this->submenu_slug_to_url( $sub[2], $menu_item[2], $admin_url );

                    $items[] = [
                        'type'     => 'page',
                        'icon'     => $icon,
                        'title'    => $sub_title,
                        'subtitle' => $title,
                        'url'      => $sub_url,
                        'action'   => null,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Convert a top-level menu slug to a full admin URL.
     *
     * @param string $slug     Menu slug.
     * @param string $admin_url Admin base URL.
     * @return string
     */
    private function menu_slug_to_url( string $slug, string $admin_url ): string {
        // Built-in pages contain a dot (e.g., edit.php, themes.php).
        if ( strpos( $slug, '.php' ) !== false ) {
            return $admin_url . $slug;
        }
        return $admin_url . 'admin.php?page=' . $slug;
    }

    /**
     * Convert a submenu slug to a full admin URL.
     *
     * @param string $sub_slug  Submenu slug.
     * @param string $parent    Parent slug.
     * @param string $admin_url Admin base URL.
     * @return string
     */
    private function submenu_slug_to_url( string $sub_slug, string $parent, string $admin_url ): string {
        if ( strpos( $sub_slug, '.php' ) !== false ) {
            return $admin_url . $sub_slug;
        }
        // If parent is a .php file, append as query param.
        if ( strpos( $parent, '.php' ) !== false ) {
            return $admin_url . $parent . '?page=' . $sub_slug;
        }
        return $admin_url . 'admin.php?page=' . $sub_slug;
    }

    /**
     * Get WPTransformed module items for the search index.
     *
     * @return array
     */
    private function get_module_items(): array {
        $items    = [];
        $registry = \WPTransformed\Core\Module_Registry::get_all();

        foreach ( $registry as $module_id => $file ) {
            // Build a readable title from the module ID.
            $title = ucwords( str_replace( '-', ' ', $module_id ) );

            $items[] = [
                'type'     => 'module',
                'icon'     => 'dashicons-admin-plugins',
                'title'    => $title,
                'subtitle' => __( 'Modules', 'wptransformed' ),
                'url'      => admin_url( 'admin.php?page=wptransformed&module=' . $module_id ),
                'action'   => null,
            ];
        }

        return $items;
    }

    /**
     * Get quick action items for the search index.
     *
     * @return array
     */
    private function get_quick_action_items(): array {
        $actions = [
            [
                'title'  => __( 'Toggle Dark Mode', 'wptransformed' ),
                'icon'   => 'dashicons-visibility',
                'action' => 'toggle-dark-mode',
            ],
            [
                'title'  => __( 'Toggle Maintenance Mode', 'wptransformed' ),
                'icon'   => 'dashicons-hammer',
                'action' => 'toggle-maintenance-mode',
            ],
            [
                'title'  => __( 'Clear Transients', 'wptransformed' ),
                'icon'   => 'dashicons-trash',
                'action' => 'clear-transients',
            ],
            [
                'title'  => __( 'Run Database Cleanup', 'wptransformed' ),
                'icon'   => 'dashicons-database',
                'action' => 'run-db-cleanup',
            ],
            [
                'title'  => __( 'Export Settings', 'wptransformed' ),
                'icon'   => 'dashicons-download',
                'action' => 'export-settings',
            ],
            [
                'title'  => __( 'View Audit Log', 'wptransformed' ),
                'icon'   => 'dashicons-list-view',
                'action' => 'view-audit-log',
                'url'    => admin_url( 'admin.php?page=wptransformed&module=audit-log' ),
            ],
        ];

        /**
         * Filter the command palette quick actions.
         *
         * @param array $actions Array of action definitions.
         */
        $actions = apply_filters( 'wpt_command_palette_actions', $actions );

        $items = [];
        foreach ( $actions as $act ) {
            $items[] = [
                'type'     => 'action',
                'icon'     => $act['icon'] ?? 'dashicons-admin-generic',
                'title'    => $act['title'],
                'subtitle' => __( 'Quick Actions', 'wptransformed' ),
                'url'      => $act['url'] ?? '',
                'action'   => $act['action'] ?? null,
            ];
        }

        return $items;
    }

    // ── Recent Pages ──────────────────────────────────────────

    /**
     * Get recent pages from user meta.
     *
     * @return array
     */
    private function get_recent_pages(): array {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return [];
        }

        $recent = get_user_meta( $user_id, 'wpt_recent_pages', true );
        if ( ! is_array( $recent ) ) {
            return [];
        }

        $settings = $this->get_settings();
        $count    = (int) $settings['recent_count'];

        return array_slice( $recent, 0, $count );
    }

    // ── AJAX: Content Search ──────────────────────────────────

    /**
     * AJAX handler for content search (posts/pages by title).
     */
    public function ajax_content_search(): void {
        check_ajax_referer( 'wpt_palette_search', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( [] );
        }

        $results = new \WP_Query( [
            's'              => $query,
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => [ 'publish', 'draft', 'pending' ],
            'posts_per_page' => 5,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );

        $items = [];
        if ( $results->have_posts() ) {
            foreach ( $results->posts as $post_id ) {
                $post_type_obj = get_post_type_object( get_post_type( $post_id ) );
                $icon = 'dashicons-admin-post';
                if ( $post_type_obj && $post_type_obj->menu_icon ) {
                    $icon = $post_type_obj->menu_icon;
                } elseif ( get_post_type( $post_id ) === 'page' ) {
                    $icon = 'dashicons-admin-page';
                }

                $items[] = [
                    'type'     => 'content',
                    'icon'     => $icon,
                    'title'    => get_the_title( $post_id ),
                    'subtitle' => $post_type_obj ? $post_type_obj->labels->singular_name : __( 'Content', 'wptransformed' ),
                    'url'      => get_edit_post_link( $post_id, 'raw' ),
                    'action'   => null,
                ];
            }
        }

        wp_send_json_success( $items );
    }

    // ── AJAX: Track Recent Page ───────────────────────────────

    /**
     * AJAX handler to track the current admin page as a recent page.
     */
    public function ajax_track_recent_page(): void {
        check_ajax_referer( 'wpt_palette_track_page', 'nonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        // esc_url_raw() strips javascript:/data: URIs without HTML-encoding ampersands.
        $url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

        if ( empty( $title ) || empty( $url ) ) {
            wp_send_json_error( [ 'message' => 'Missing data' ], 400 );
        }

        $user_id = get_current_user_id();
        $recent  = get_user_meta( $user_id, 'wpt_recent_pages', true );
        if ( ! is_array( $recent ) ) {
            $recent = [];
        }

        $recent = array_filter( $recent, function( $item ) use ( $url ) {
            return ( $item['url'] ?? '' ) !== $url;
        } );

        array_unshift( $recent, [
            'type'     => 'recent',
            'icon'     => 'dashicons-clock',
            'title'    => $title,
            'subtitle' => __( 'Recent Pages', 'wptransformed' ),
            'url'      => $url,
            'action'   => null,
        ] );

        $recent = array_slice( $recent, 0, 20 );

        update_user_meta( $user_id, 'wpt_recent_pages', $recent );

        wp_send_json_success();
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Keyboard Shortcut', 'wptransformed' ); ?></th>
                <td>
                    <select name="wpt_shortcut">
                        <option value="mod+k" <?php selected( $settings['shortcut'], 'mod+k' ); ?>>
                            <?php esc_html_e( 'Cmd+K / Ctrl+K', 'wptransformed' ); ?>
                        </option>
                        <option value="mod+p" <?php selected( $settings['shortcut'], 'mod+p' ); ?>>
                            <?php esc_html_e( 'Cmd+P / Ctrl+P', 'wptransformed' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Show Recent Pages', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_recent" value="1" <?php checked( $settings['show_recent'] ); ?>>
                        <?php esc_html_e( 'Show recently visited pages when search is empty', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Recent Pages Count', 'wptransformed' ); ?></th>
                <td>
                    <input type="number" name="wpt_recent_count" value="<?php echo esc_attr( (string) $settings['recent_count'] ); ?>" min="1" max="20" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Content Search', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_search_content" value="1" <?php checked( $settings['search_content'] ); ?>>
                        <?php esc_html_e( 'Include posts and pages in search results (via AJAX)', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_shortcuts = [ 'mod+k', 'mod+p' ];
        $shortcut        = $raw['wpt_shortcut'] ?? 'mod+k';

        return [
            'shortcut'        => in_array( $shortcut, $valid_shortcuts, true ) ? $shortcut : 'mod+k',
            'show_recent'     => ! empty( $raw['wpt_show_recent'] ),
            'recent_count'    => max( 1, min( 20, (int) ( $raw['wpt_recent_count'] ?? 5 ) ) ),
            'search_content'  => ! empty( $raw['wpt_search_content'] ),
            'custom_commands' => [],
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => 'wpt_recent_pages' ],
        ];
    }
}
