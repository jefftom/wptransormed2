<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Menu Editor — Reorder, rename, and hide admin sidebar menu items.
 *
 * Core logic:
 * - custom_menu_order filter (priority 10)  → return true to enable custom ordering
 * - menu_order filter (priority 999)        → return saved menu_order array
 * - admin_menu action (priority 999)        → hide items, rename items, change icons
 * - admin_enqueue_scripts (priority 10)     → load drag-and-drop JS on WPT settings page
 *
 * @package WPTransformed
 */
class Admin_Menu_Editor extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'admin-menu-editor';
    }

    public function get_title(): string {
        return __( 'Admin Menu Editor', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Reorder, rename, and hide WordPress admin sidebar menu items via drag-and-drop.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'menu_order'     => [],   // Array of menu slugs in desired order.
            'hidden_items'   => [],   // Menu item slugs to hide.
            'renamed_items'  => [],   // slug => new label.
            'custom_icons'   => [],   // slug => dashicon class.
            'separators'     => [],   // Insert separators after these slugs.
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        // Only hook menu modifications if there is something to modify.
        if ( ! empty( $settings['menu_order'] ) ) {
            add_filter( 'custom_menu_order', '__return_true', 10 );
            add_filter( 'menu_order', [ $this, 'filter_menu_order' ], 999 );
        }

        // Hide, rename, change icons, add separators.
        if (
            ! empty( $settings['hidden_items'] )
            || ! empty( $settings['renamed_items'] )
            || ! empty( $settings['custom_icons'] )
            || ! empty( $settings['separators'] )
        ) {
            add_action( 'admin_menu', [ $this, 'modify_admin_menu' ], 999 );
        }

        // Settings page assets.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Menu Order Filter ─────────────────────────────────────

    /**
     * Filter the admin menu order.
     *
     * Returns saved order with unknown items appended at the end.
     *
     * @param array $current_order Current WordPress menu order.
     * @return array Reordered menu slugs.
     */
    public function filter_menu_order( array $current_order ): array {
        $settings    = $this->get_settings();
        $saved_order = $settings['menu_order'] ?? [];

        if ( empty( $saved_order ) ) {
            return $current_order;
        }

        return $this->apply_menu_order( $saved_order, $current_order );
    }

    /**
     * Build final menu order: saved order first, then any new items appended.
     *
     * Public for testability.
     *
     * @param array $saved_order   Saved slug order.
     * @param array $current_order Current WP menu order.
     * @return array Final ordered slugs.
     */
    public function apply_menu_order( array $saved_order, array $current_order ): array {
        if ( empty( $saved_order ) ) {
            return $current_order;
        }

        $current_set = array_flip( $current_order );
        $result      = [];

        // Add saved items that still exist.
        foreach ( $saved_order as $slug ) {
            if ( isset( $current_set[ $slug ] ) ) {
                $result[] = $slug;
            }
        }

        // Append items not in saved order (new plugins, etc.).
        $saved_set = array_flip( $saved_order );
        foreach ( $current_order as $slug ) {
            if ( ! isset( $saved_set[ $slug ] ) ) {
                $result[] = $slug;
            }
        }

        return $result;
    }

    // ── Admin Menu Modifications ──────────────────────────────

    /**
     * Modify admin menu: hide items, rename items, change icons, add separators.
     *
     * Fires on admin_menu at priority 999 (after all plugins register).
     */
    public function modify_admin_menu(): void {
        $settings = $this->get_settings();

        // Hide items.
        $hidden = $settings['hidden_items'] ?? [];
        if ( ! empty( $hidden ) ) {
            $this->apply_hidden_items( $hidden );
        }

        // Rename items.
        $renamed = $settings['renamed_items'] ?? [];
        if ( ! empty( $renamed ) ) {
            $this->apply_renamed_items( $renamed );
        }

        // Custom icons.
        $icons = $settings['custom_icons'] ?? [];
        if ( ! empty( $icons ) ) {
            $this->apply_custom_icons( $icons );
        }

        // Separators.
        $separators = $settings['separators'] ?? [];
        if ( ! empty( $separators ) ) {
            $this->apply_separators( $separators );
        }
    }

    /**
     * Remove menu pages by slug.
     *
     * Public for testability.
     *
     * @param array $hidden Array of menu slugs to hide.
     */
    public function apply_hidden_items( array $hidden ): void {
        foreach ( $hidden as $slug ) {
            $slug = sanitize_text_field( $slug );
            if ( $slug !== '' ) {
                remove_menu_page( $slug );
            }
        }
    }

    /**
     * Rename menu items by slug.
     *
     * Public for testability.
     *
     * @param array $renamed slug => new label.
     */
    public function apply_renamed_items( array $renamed ): void {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return;
        }

        foreach ( $menu as $key => &$item ) {
            $slug = $item[2] ?? '';
            if ( isset( $renamed[ $slug ] ) ) {
                $item[0] = esc_html( $renamed[ $slug ] );
            }
        }
        unset( $item );
    }

    /**
     * Change menu icons by slug.
     *
     * Public for testability.
     *
     * @param array $icons slug => dashicon class.
     */
    public function apply_custom_icons( array $icons ): void {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return;
        }

        foreach ( $menu as $key => &$item ) {
            $slug = $item[2] ?? '';
            if ( isset( $icons[ $slug ] ) ) {
                $item[6] = sanitize_html_class( $icons[ $slug ] );
            }
        }
        unset( $item );
    }

    /**
     * Insert separator entries after specified slugs.
     *
     * @param array $separators Array of slugs after which to add a separator.
     */
    private function apply_separators( array $separators ): void {
        global $menu;

        if ( ! is_array( $menu ) || empty( $separators ) ) {
            return;
        }

        $separator_set = array_flip( $separators );
        $new_menu      = [];
        $sep_counter   = 100; // Start high to avoid collisions with core separators.

        foreach ( $menu as $key => $item ) {
            $new_menu[ $key ] = $item;
            $slug = $item[2] ?? '';

            if ( isset( $separator_set[ $slug ] ) ) {
                $sep_key = 'separator-wpt-' . $sep_counter;
                $new_menu[ $sep_key ] = [ '', 'read', $sep_key, '', 'wp-menu-separator' ];
                $sep_counter++;
            }
        }

        $menu = $new_menu;
    }

    // ── Assets ────────────────────────────────────────────────

    /**
     * Enqueue admin assets only on the WPTransformed settings page.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_wptransformed' ) {
            return;
        }

        wp_enqueue_style(
            'wpt-admin-menu-editor',
            WPT_URL . 'modules/admin-interface/css/admin-menu-editor.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-admin-menu-editor',
            WPT_URL . 'modules/admin-interface/js/admin-menu-editor.js',
            [],
            WPT_VERSION,
            true
        );
    }

    // ── Settings UI ───────────────────────────────────────────

    /**
     * Render the settings page UI.
     *
     * Outputs:
     * - Live menu editor with drag-and-drop reordering
     * - Inline rename, icon picker, hide toggle
     * - Hidden form fields for save
     */
    public function render_settings(): void {
        global $menu;

        $settings      = $this->get_settings();
        $saved_order   = $settings['menu_order'] ?? [];
        $hidden_items  = $settings['hidden_items'] ?? [];
        $renamed_items = $settings['renamed_items'] ?? [];
        $custom_icons  = $settings['custom_icons'] ?? [];
        $separators    = $settings['separators'] ?? [];

        // Build current menu data for JS.
        $menu_items = [];
        if ( is_array( $menu ) ) {
            foreach ( $menu as $key => $item ) {
                $slug = $item[2] ?? '';
                if ( $slug === '' ) {
                    continue;
                }

                // Skip WP separator entries.
                if ( strpos( $slug, 'separator' ) === 0 ) {
                    continue;
                }

                $menu_items[] = [
                    'slug'  => $slug,
                    'label' => wp_strip_all_tags( $item[0] ?? '' ),
                    'icon'  => $item[6] ?? 'dashicons-admin-generic',
                ];
            }
        }

        // Build init data for JS.
        $init_data = [
            'menuItems'    => $menu_items,
            'menuOrder'    => $saved_order,
            'hiddenItems'  => $hidden_items,
            'renamedItems' => (object) $renamed_items, // Force JSON {}.
            'customIcons'  => (object) $custom_icons,  // Force JSON {}.
            'separators'   => $separators,
        ];

        ?>
        <p class="description" style="margin-bottom: 12px;">
            <?php esc_html_e( 'Drag items to reorder, click labels to rename, toggle visibility with the eye icon. Hidden items remain accessible via direct URL.', 'wptransformed' ); ?>
        </p>

        <div id="wpt-ame-editor" class="wpt-ame-editor">
            <div id="wpt-ame-list" class="wpt-ame-list">
                <?php esc_html_e( 'Loading menu items...', 'wptransformed' ); ?>
            </div>
        </div>

        <div class="wpt-ame-actions" style="margin-top: 12px;">
            <button type="button" id="wpt-ame-reset" class="button">
                <?php esc_html_e( 'Reset to Default', 'wptransformed' ); ?>
            </button>
        </div>

        <?php // Hidden form fields — JS populates these before submit. ?>
        <input type="hidden" id="wpt-ame-menu-order" name="wpt_menu_order" value="<?php echo esc_attr( implode( ',', $saved_order ) ); ?>">
        <input type="hidden" id="wpt-ame-hidden-items" name="wpt_hidden_items" value="<?php echo esc_attr( implode( ',', $hidden_items ) ); ?>">
        <input type="hidden" id="wpt-ame-renamed-json" name="wpt_renamed_json" value="<?php echo esc_attr( wp_json_encode( $renamed_items ) ); ?>">
        <input type="hidden" id="wpt-ame-icons-json" name="wpt_icons_json" value="<?php echo esc_attr( wp_json_encode( $custom_icons ) ); ?>">
        <input type="hidden" id="wpt-ame-separators" name="wpt_separators" value="<?php echo esc_attr( implode( ',', $separators ) ); ?>">

        <?php // Init data for JS. ?>
        <?php
        $json = wp_json_encode( $init_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
        if ( $json === false ) {
            $json = '{}';
        }
        ?>
        <script type="application/json" id="wpt-ame-init-data"><?php echo $json; ?></script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    /**
     * Sanitize all settings from the form submission.
     *
     * @param array $raw Raw form data.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( array $raw ): array {
        // Menu order — comma-separated slugs.
        $menu_order = [];
        $order_raw  = sanitize_text_field( $raw['wpt_menu_order'] ?? '' );
        if ( $order_raw !== '' ) {
            $slugs = explode( ',', $order_raw );
            foreach ( $slugs as $slug ) {
                $slug = sanitize_text_field( trim( $slug ) );
                if ( $slug !== '' ) {
                    $menu_order[] = $slug;
                }
            }
        }

        // Hidden items — comma-separated slugs.
        $hidden_items = [];
        $hidden_raw   = sanitize_text_field( $raw['wpt_hidden_items'] ?? '' );
        if ( $hidden_raw !== '' ) {
            $slugs = explode( ',', $hidden_raw );
            foreach ( $slugs as $slug ) {
                $slug = sanitize_text_field( trim( $slug ) );
                if ( $slug !== '' ) {
                    $hidden_items[] = $slug;
                }
            }
        }

        // Renamed items — JSON { slug: label }.
        $renamed_items = [];
        $renamed_raw   = $raw['wpt_renamed_json'] ?? '';
        if ( $renamed_raw !== '' ) {
            $decoded = json_decode( wp_unslash( $renamed_raw ), true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $slug => $label ) {
                    $slug  = sanitize_text_field( (string) $slug );
                    $label = sanitize_text_field( (string) $label );
                    if ( $slug !== '' && $label !== '' ) {
                        $renamed_items[ $slug ] = $label;
                    }
                }
            }
        }

        // Custom icons — JSON { slug: dashicon-class }.
        $custom_icons = [];
        $icons_raw    = $raw['wpt_icons_json'] ?? '';
        if ( $icons_raw !== '' ) {
            $decoded = json_decode( wp_unslash( $icons_raw ), true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $slug => $icon ) {
                    $slug = sanitize_text_field( (string) $slug );
                    $icon = sanitize_html_class( (string) $icon );
                    if ( $slug !== '' && $icon !== '' && preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
                        $custom_icons[ $slug ] = $icon;
                    }
                }
            }
        }

        // Separators — comma-separated slugs.
        $separators = [];
        $sep_raw    = sanitize_text_field( $raw['wpt_separators'] ?? '' );
        if ( $sep_raw !== '' ) {
            $slugs = explode( ',', $sep_raw );
            foreach ( $slugs as $slug ) {
                $slug = sanitize_text_field( trim( $slug ) );
                if ( $slug !== '' ) {
                    $separators[] = $slug;
                }
            }
        }

        return [
            'menu_order'    => $menu_order,
            'hidden_items'  => $hidden_items,
            'renamed_items' => $renamed_items,
            'custom_icons'  => $custom_icons,
            'separators'    => $separators,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => 'wpt_admin_menu_editor' ],
        ];
    }
}
