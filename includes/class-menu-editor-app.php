<?php
declare(strict_types=1);

namespace WPTransformed\Core;

use WPTransformed\Modules\AdminInterface\Admin_Menu_Editor;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Menu Editor — dedicated APP page.
 *
 * Session 5 Part 2. Matches the reference mockup at
 * assets/admin/reference/app-pages/menu-editor-v3.html.
 *
 * Linked from the Session 3 Module Grid's Navigation & Menus parent card
 * (wpt-menu-editor slug). Wraps the existing Admin_Menu_Editor module
 * (module ID: `admin-menu-editor`) with a three-panel UI:
 *
 * - LEFT pane (280px): live sidebar preview that matches the WP admin
 *   gradient sidebar styling, showing exactly what the current menu
 *   will look like after save. Updates reactively as the user edits
 *   in the center panel.
 * - CENTER pane (flex 1): drag-drop list of top-level menu items, each
 *   with icon badge, title, slug, hide/show toggle, and a drag handle.
 *   Selecting an item loads its details into the right pane.
 * - RIGHT pane (300px): item properties form — title, icon picker
 *   (dashicons grid), and an "Add separator after this item" button.
 *
 * Scope for Session 5 Part 2 (v1):
 * - Wraps the module's existing 5-field schema: menu_order, hidden_items,
 *   renamed_items, custom_icons, separators. Nothing more.
 * - No submenu editing (module schema doesn't support it yet)
 * - No per-role visibility (module schema doesn't support it yet)
 * - No theme system (post-v2 work per docs/modules/admin-interface/menu-editor.md)
 * - No multi-config tabs (also post-v2)
 * - No custom menu items pointing at external URLs (also post-v2)
 *
 * The spec (docs/modules/admin-interface/menu-editor.md) envisions a
 * much larger feature set based on the AME v3.5 port — that's post-v2.
 * This session delivers a genuinely useful Menu Editor on top of the
 * currently-shipping module without requiring a module rebuild.
 *
 * Data strategy:
 * - At render time, snapshot the global `$menu` array (populated by WP
 *   core + all registered plugins by admin_menu priority 999). Use it
 *   to build the live tree for the center panel and the preview for
 *   the left panel.
 * - Current settings pulled via direct module instantiation so the
 *   editor works whether or not the module is toggled on.
 * - Save goes through a dedicated admin-post action
 *   wpt_save_menu_editor that reuses Admin_Menu_Editor::sanitize_settings().
 *
 * Form field names match the module's sanitize_settings() expectations
 * (all `wpt_` prefixed per the convention documented in
 * docs/session-5-wrapped-module-fields.md):
 *
 *   wpt_menu_order      — comma-separated slug list (final display order)
 *   wpt_hidden_items    — comma-separated slug list (hidden items)
 *   wpt_renamed_json    — JSON {slug: label}
 *   wpt_icons_json      — JSON {slug: dashicon class}
 *   wpt_separators      — comma-separated slug list (slugs after which to add a separator)
 *
 * @package WPTransformed
 */
class Menu_Editor_App {

    /** Module ID the app page wraps. */
    private const MODULE_ID = 'admin-menu-editor';

    /**
     * Dashicon palette shown in the right-pane icon picker.
     *
     * A hand-picked subset of common WP dashicons covering navigation,
     * content, users, settings, and decoration. Users can still type
     * custom classes manually via the text input next to the grid, so
     * this is just a starting palette, not a hard allowlist.
     *
     * @var array<int,string>
     */
    private const ICON_PALETTE = [
        'dashicons-admin-home',
        'dashicons-admin-post',
        'dashicons-admin-page',
        'dashicons-admin-media',
        'dashicons-admin-links',
        'dashicons-admin-comments',
        'dashicons-admin-appearance',
        'dashicons-admin-plugins',
        'dashicons-admin-users',
        'dashicons-admin-tools',
        'dashicons-admin-settings',
        'dashicons-admin-network',
        'dashicons-admin-generic',
        'dashicons-dashboard',
        'dashicons-menu',
        'dashicons-chart-bar',
        'dashicons-chart-pie',
        'dashicons-store',
        'dashicons-cart',
        'dashicons-email',
        'dashicons-calendar-alt',
        'dashicons-location',
        'dashicons-lock',
        'dashicons-unlock',
        'dashicons-shield',
        'dashicons-awards',
        'dashicons-star-filled',
        'dashicons-heart',
        'dashicons-hammer',
        'dashicons-download',
        'dashicons-upload',
        'dashicons-cloud',
        'dashicons-format-gallery',
        'dashicons-format-image',
        'dashicons-format-video',
        'dashicons-analytics',
        'dashicons-filter',
        'dashicons-search',
        'dashicons-tag',
        'dashicons-category',
        'dashicons-archive',
        'dashicons-backup',
        'dashicons-update',
        'dashicons-sos',
        'dashicons-info',
        'dashicons-warning',
        'dashicons-yes',
        'dashicons-no',
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ], 20 );
        add_action( 'admin_post_wpt_save_menu_editor', [ $this, 'handle_save' ] );
    }

    public function register_page(): void {
        $hook = add_submenu_page(
            'wpt-dashboard',
            __( 'Menu Editor', 'wptransformed' ),
            __( 'Menu Editor', 'wptransformed' ),
            'manage_options',
            'wpt-menu-editor',
            [ $this, 'render' ]
        );

        add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        add_action( 'admin_enqueue_scripts', function () {
            wp_enqueue_style(
                'wpt-admin',
                WPT_URL . 'assets/admin/css/admin.css',
                [ 'wpt-admin-global' ],
                WPT_VERSION
            );
            wp_enqueue_script(
                'wpt-admin',
                WPT_URL . 'assets/admin/js/admin.js',
                [ 'wpt-admin-global' ],
                WPT_VERSION,
                true
            );
        } );
    }

    /**
     * Handle the form POST from the Menu Editor app page.
     */
    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptransformed' ) );
        }
        check_admin_referer( 'wpt_save_menu_editor', 'wpt_menu_editor_nonce' );

        $module = $this->get_module_instance();
        if ( $module ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
            $clean = $module->sanitize_settings( $_POST );
            Settings::save( self::MODULE_ID, $clean );
        }

        wp_safe_redirect( add_query_arg( [
            'page'      => 'wpt-menu-editor',
            'wpt_saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptransformed' ) );
        }

        $module        = $this->get_module_instance();
        $module_active = Core::instance()->is_active( self::MODULE_ID );
        $settings      = $module ? $module->get_settings() : $this->get_default_settings();
        $just_saved    = isset( $_GET['wpt_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Snapshot the current $menu globals. At this point in admin
        // request lifecycle, admin_menu has already fired so $menu is
        // fully populated by WP core + every active plugin.
        $menu_snapshot = $this->get_menu_snapshot();

        // Apply the user's saved config to build the initial tree
        // ordering: saved items first (in order), then any newly
        // registered items appended at the bottom.
        $ordered_items = $this->apply_saved_order( $menu_snapshot, $settings['menu_order'] ?? [] );

        // Decorate each item with its current customizations so the
        // center tree + left preview both reflect the saved state.
        $tree_items = $this->decorate_items( $ordered_items, $settings );

        // Build the editor init data blob for JS hydration.
        $init_data = [
            'items'        => $tree_items,
            'savedOrder'   => array_values( $settings['menu_order'] ?? [] ),
            'hiddenItems'  => array_values( $settings['hidden_items'] ?? [] ),
            'renamedItems' => (object) ( $settings['renamed_items'] ?? [] ),
            'customIcons'  => (object) ( $settings['custom_icons'] ?? [] ),
            'separators'   => array_values( $settings['separators'] ?? [] ),
            'iconPalette'  => self::ICON_PALETTE,
        ];

        ?>
        <div class="wpt-dashboard wpt-app-page wpt-menu-editor" id="wptMenuEditor">

            <?php if ( $just_saved ) : ?>
                <div class="wpt-app-notice wpt-app-notice-success" role="status">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong><?php esc_html_e( 'Menu configuration saved', 'wptransformed' ); ?></strong>
                        <p><?php esc_html_e( 'Your changes are live across the admin sidebar. Reload any admin page to see them.', 'wptransformed' ); ?></p>
                    </div>
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url() ); ?>">
                        <?php esc_html_e( 'Open admin', 'wptransformed' ); ?>
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ( ! $module_active ) : ?>
                <div class="wpt-app-notice wpt-app-notice-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong><?php esc_html_e( 'Admin Menu Editor module is inactive', 'wptransformed' ); ?></strong>
                        <p><?php esc_html_e( 'You can edit the configuration here, but it won\'t apply to the real admin sidebar until the module is activated.', 'wptransformed' ); ?></p>
                    </div>
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>">
                        <?php esc_html_e( 'Activate', 'wptransformed' ); ?>
                    </a>
                </div>
            <?php endif; ?>

            <form method="post"
                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  class="wpt-me-layout"
                  id="wptMenuEditorForm">

                <input type="hidden" name="action" value="wpt_save_menu_editor">
                <?php wp_nonce_field( 'wpt_save_menu_editor', 'wpt_menu_editor_nonce' ); ?>

                <!-- Hidden fields — JS populates these before submit -->
                <input type="hidden" id="wptMeMenuOrder"    name="wpt_menu_order">
                <input type="hidden" id="wptMeHiddenItems"  name="wpt_hidden_items">
                <input type="hidden" id="wptMeRenamedJson"  name="wpt_renamed_json">
                <input type="hidden" id="wptMeIconsJson"    name="wpt_icons_json">
                <input type="hidden" id="wptMeSeparators"   name="wpt_separators">

                <!-- ════════════════════════════════════════
                     HEADER — title + action buttons
                ════════════════════════════════════════ -->
                <header class="wpt-me-header">
                    <div class="wpt-me-header-left">
                        <a class="wpt-me-back" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>" aria-label="<?php esc_attr_e( 'Back to Modules', 'wptransformed' ); ?>">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <div class="wpt-me-title">
                            <h1>
                                <span class="wpt-page-header-icon violet"><i class="fas fa-list"></i></span>
                                <?php esc_html_e( 'Menu Editor', 'wptransformed' ); ?>
                            </h1>
                            <p><?php esc_html_e( 'Drag to reorder, click to edit, toggle to show or hide.', 'wptransformed' ); ?></p>
                        </div>
                    </div>
                    <div class="wpt-me-header-right">
                        <button type="button" class="btn btn-secondary" id="wptMeReset">
                            <i class="fas fa-undo"></i> <?php esc_html_e( 'Reset', 'wptransformed' ); ?>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php esc_html_e( 'Save Changes', 'wptransformed' ); ?>
                        </button>
                    </div>
                </header>

                <!-- ════════════════════════════════════════
                     MAIN GRID — 3 columns
                ════════════════════════════════════════ -->
                <div class="wpt-me-main">

                    <!-- LEFT: Live sidebar preview -->
                    <aside class="wpt-me-preview">
                        <div class="wpt-me-preview-header">
                            <h3><i class="fas fa-eye"></i> <?php esc_html_e( 'Live Preview', 'wptransformed' ); ?></h3>
                        </div>
                        <nav class="wpt-me-preview-menu" id="wptMePreviewMenu" aria-label="<?php esc_attr_e( 'Menu preview', 'wptransformed' ); ?>">
                            <!-- Populated by JS on load + on every edit -->
                        </nav>
                    </aside>

                    <!-- CENTER: Tree editor -->
                    <div class="wpt-me-tree-panel">
                        <div class="wpt-me-tree-header">
                            <h3><?php esc_html_e( 'Menu Items', 'wptransformed' ); ?></h3>
                            <span class="wpt-me-tree-count" id="wptMeTreeCount">
                                <?php
                                printf(
                                    /* translators: %d: item count */
                                    esc_html( _n( '%d item', '%d items', count( $tree_items ), 'wptransformed' ) ),
                                    (int) count( $tree_items )
                                );
                                ?>
                            </span>
                        </div>
                        <div class="wpt-me-tree" id="wptMeTree" role="listbox" aria-label="<?php esc_attr_e( 'Menu items tree', 'wptransformed' ); ?>">
                            <!-- Populated by JS on load -->
                        </div>
                    </div>

                    <!-- RIGHT: Selected item properties -->
                    <aside class="wpt-me-props" id="wptMeProps">
                        <div class="wpt-me-props-header">
                            <h3 id="wptMePropsTitle"><?php esc_html_e( 'Item Properties', 'wptransformed' ); ?></h3>
                            <p id="wptMePropsSubtitle"><?php esc_html_e( 'Select a menu item to edit its settings.', 'wptransformed' ); ?></p>
                        </div>

                        <div class="wpt-me-props-body" id="wptMePropsBody" hidden>
                            <div class="wpt-me-form-group">
                                <label class="wpt-me-label" for="wptMeEditLabel"><?php esc_html_e( 'Menu Label', 'wptransformed' ); ?></label>
                                <input type="text" id="wptMeEditLabel" class="wpt-me-input">
                                <small class="wpt-me-hint"><?php esc_html_e( 'Leave empty to use the default label.', 'wptransformed' ); ?></small>
                            </div>

                            <div class="wpt-me-form-group">
                                <label class="wpt-me-label" for="wptMeEditSlug"><?php esc_html_e( 'Slug', 'wptransformed' ); ?></label>
                                <input type="text" id="wptMeEditSlug" class="wpt-me-input" readonly>
                                <small class="wpt-me-hint"><?php esc_html_e( 'WordPress menu slug — read-only for compatibility.', 'wptransformed' ); ?></small>
                            </div>

                            <div class="wpt-me-form-group">
                                <label class="wpt-me-label"><?php esc_html_e( 'Icon', 'wptransformed' ); ?></label>
                                <div class="wpt-me-icon-picker" id="wptMeIconPicker">
                                    <?php foreach ( self::ICON_PALETTE as $icon_slug ) : ?>
                                        <button type="button"
                                                class="wpt-me-icon-option"
                                                data-icon="<?php echo esc_attr( $icon_slug ); ?>"
                                                aria-label="<?php echo esc_attr( $icon_slug ); ?>"
                                                title="<?php echo esc_attr( $icon_slug ); ?>">
                                            <span class="dashicons <?php echo esc_attr( $icon_slug ); ?>" aria-hidden="true"></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="text"
                                       id="wptMeEditIcon"
                                       class="wpt-me-input wpt-me-icon-manual"
                                       placeholder="dashicons-...">
                                <small class="wpt-me-hint"><?php esc_html_e( 'Pick from the grid above, or type any dashicon class manually.', 'wptransformed' ); ?></small>
                            </div>

                            <div class="wpt-me-form-group">
                                <label class="wpt-me-toggle-row">
                                    <span><?php esc_html_e( 'Hide this item', 'wptransformed' ); ?></span>
                                    <span class="toggle">
                                        <input type="checkbox" id="wptMeEditHidden">
                                        <span class="toggle-track"></span>
                                    </span>
                                </label>
                                <small class="wpt-me-hint"><?php esc_html_e( 'Hiding removes the item from the sidebar but does not revoke access — direct URLs still work.', 'wptransformed' ); ?></small>
                            </div>

                            <div class="wpt-me-form-group">
                                <label class="wpt-me-toggle-row">
                                    <span><?php esc_html_e( 'Insert separator after this item', 'wptransformed' ); ?></span>
                                    <span class="toggle">
                                        <input type="checkbox" id="wptMeEditSeparator">
                                        <span class="toggle-track"></span>
                                    </span>
                                </label>
                            </div>

                            <button type="button" class="wpt-me-props-revert btn btn-secondary" id="wptMeRevertItem">
                                <i class="fas fa-undo"></i> <?php esc_html_e( 'Revert this item', 'wptransformed' ); ?>
                            </button>
                        </div>

                        <div class="wpt-me-props-empty" id="wptMePropsEmpty">
                            <i class="fas fa-hand-pointer"></i>
                            <p><?php esc_html_e( 'Click any menu item in the center panel to edit its properties.', 'wptransformed' ); ?></p>
                        </div>
                    </aside>

                </div>
            </form>

            <!-- Init data for JS -->
            <?php
            $json = wp_json_encode( $init_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
            if ( $json === false ) {
                $json = '{}';
            }
            ?>
            <script type="application/json" id="wptMenuEditorData"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════
       Data helpers
    ══════════════════════════════════════════ */

    /**
     * Snapshot the current WordPress `$menu` global as a list of top-level
     * items suitable for the editor tree. Separator entries are filtered
     * out (the editor manages its own separators via the settings).
     *
     * @return array<int,array{slug:string, original_label:string, original_icon:string, capability:string}>
     */
    private function get_menu_snapshot(): array {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return [];
        }

        $items = [];
        foreach ( $menu as $position => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $slug    = isset( $entry[2] ) ? (string) $entry[2] : '';
            $classes = isset( $entry[4] ) ? (string) $entry[4] : '';

            if ( $slug === '' ) {
                continue;
            }
            // Skip separator entries; user-added separators are tracked in settings.
            if ( strpos( $classes, 'wp-menu-separator' ) !== false ) {
                continue;
            }
            // Skip WPT's own injected section labels from Session 1.
            if ( strpos( $slug, 'wpt-sep-' ) === 0 ) {
                continue;
            }

            $items[] = [
                'slug'           => $slug,
                'position'       => (int) $position,
                'original_label' => wp_strip_all_tags( (string) ( $entry[0] ?? '' ) ),
                'original_icon'  => (string) ( $entry[6] ?? 'dashicons-admin-generic' ),
                'capability'     => (string) ( $entry[1] ?? 'read' ),
            ];
        }

        return $items;
    }

    /**
     * Apply the saved slug order to the fresh menu snapshot.
     *
     * Walks the saved order first, then appends any items in the
     * snapshot that weren't in the saved order (newly installed
     * plugins get appended at the end). Mirrors the module's own
     * apply_menu_order() logic.
     *
     * @param array<int,array<string,mixed>> $snapshot   Fresh `$menu` snapshot.
     * @param array<int,string>              $saved_order Saved slug order.
     * @return array<int,array<string,mixed>>
     */
    private function apply_saved_order( array $snapshot, array $saved_order ): array {
        if ( empty( $saved_order ) ) {
            return $snapshot;
        }

        $by_slug = [];
        foreach ( $snapshot as $item ) {
            $by_slug[ $item['slug'] ] = $item;
        }

        $result     = [];
        $seen_slugs = [];
        foreach ( $saved_order as $slug ) {
            if ( isset( $by_slug[ $slug ] ) ) {
                $result[]              = $by_slug[ $slug ];
                $seen_slugs[ $slug ]   = true;
            }
        }
        foreach ( $snapshot as $item ) {
            if ( ! isset( $seen_slugs[ $item['slug'] ] ) ) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Decorate each snapshot item with its saved customizations so the
     * tree view + preview both reflect the current state.
     *
     * Added keys per item:
     *   - label        : effective display label (renamed if set, else original)
     *   - icon         : effective icon (custom if set, else original)
     *   - hidden       : bool — user has toggled hide
     *   - separator    : bool — user has set "add separator after this"
     *   - renamed      : bool — label differs from original
     *   - icon_overridden : bool — icon differs from original
     *
     * @param array<int,array<string,mixed>> $items    Ordered snapshot.
     * @param array<string,mixed>            $settings Module settings.
     * @return array<int,array<string,mixed>>
     */
    private function decorate_items( array $items, array $settings ): array {
        $hidden  = array_flip( (array) ( $settings['hidden_items'] ?? [] ) );
        $renamed = (array) ( $settings['renamed_items'] ?? [] );
        $icons   = (array) ( $settings['custom_icons'] ?? [] );
        $seps    = array_flip( (array) ( $settings['separators'] ?? [] ) );

        foreach ( $items as &$item ) {
            $slug                    = $item['slug'];
            $item['label']           = isset( $renamed[ $slug ] ) ? (string) $renamed[ $slug ] : $item['original_label'];
            $item['icon']            = isset( $icons[ $slug ] )   ? (string) $icons[ $slug ]   : $item['original_icon'];
            $item['hidden']          = isset( $hidden[ $slug ] );
            $item['separator']       = isset( $seps[ $slug ] );
            $item['renamed']         = isset( $renamed[ $slug ] );
            $item['icon_overridden'] = isset( $icons[ $slug ] );
        }
        unset( $item );

        return $items;
    }

    /**
     * Lazy-instantiate the Admin_Menu_Editor module class so we can call
     * get_settings() and sanitize_settings() even when the module isn't
     * in the active-modules set.
     */
    private function get_module_instance(): ?Admin_Menu_Editor {
        $class = '\WPTransformed\Modules\AdminInterface\Admin_Menu_Editor';
        if ( ! class_exists( $class ) ) {
            $file = WPT_PATH . 'modules/admin-interface/class-admin-menu-editor.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( $class ) ) {
            return null;
        }
        return new $class();
    }

    /**
     * Fallback defaults if the module can't be instantiated.
     *
     * @return array<string,mixed>
     */
    private function get_default_settings(): array {
        return [
            'menu_order'    => [],
            'hidden_items'  => [],
            'renamed_items' => [],
            'custom_icons'  => [],
            'separators'    => [],
        ];
    }
}
