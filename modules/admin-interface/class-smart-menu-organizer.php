<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Smart Menu Organizer — Auto-groups admin sidebar into collapsible sections.
 *
 * Core logic:
 * - admin_menu priority 99999      → reorder menu into sections, apply per-role hiding/rename/icons
 * - admin_head                     → inject section header CSS
 * - admin_footer                   → inject drag-and-drop JS, context menu
 * - wp_ajax_wpt_save_menu_order    → persist reordered menu
 * - wp_ajax_wpt_toggle_section     → persist collapse state per user
 * - activated_plugin               → set transient for placement prompt
 * - admin_notices                  → show "new plugin" placement prompt
 *
 * @package WPTransformed
 */
class Smart_Menu_Organizer extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'smart-menu-organizer';
    }

    public function get_title(): string {
        return __( 'Smart Menu Organizer', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Auto-groups the WordPress admin sidebar into logical categories with drag-and-drop reordering and per-role visibility.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'                   => true,
            'auto_organize_on_activate' => true,
            'sections'                  => $this->get_default_sections(),
            'hidden_items'              => [],  // { role => [menu_slugs] }
            'renamed_items'             => [],  // { menu_slug => 'New Label' }
            'custom_icons'              => [],  // { menu_slug => 'dashicons-xxx' }
            'prompt_on_plugin_install'  => true,
            'known_plugins'             => [],  // { plugin_slug => suggested_section }
        ];
    }

    /**
     * Return default section definitions.
     *
     * Aligned with the canonical CONTENT / SECURITY / DESIGN / TOOLS / CONFIGURE
     * groups defined in docs/ui-restructure-spec.md Section 3 and used by
     * Admin::inject_section_labels() as the fallback injector. Keeping both
     * systems on the same labels avoids the duplicate-header collision that
     * occurred when Smart Menu Organizer invented its own set.
     *
     * Items are WP core menu slugs. The plugin's own WPT admin pages are
     * unassigned so they fall into "Other" by default — which we want,
     * because they sit under the CONFIGURE range in the target layout.
     *
     * Security / Design sections start empty because WPT doesn't register
     * any core menu items under them yet; they populate dynamically as
     * security modules and detected page builders add menu items.
     *
     * @return array
     */
    public function get_default_sections(): array {
        return [
            [
                'id'        => 'content',
                'label'     => 'Content',
                'icon'      => 'dashicons-edit',
                'items'     => [ 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php' ],
                'collapsed' => false,
            ],
            [
                'id'        => 'security',
                'label'     => 'Security',
                'icon'      => 'dashicons-shield',
                'items'     => [],
                'collapsed' => false,
            ],
            [
                'id'        => 'design',
                'label'     => 'Design',
                'icon'      => 'dashicons-admin-appearance',
                'items'     => [ 'themes.php', 'nav-menus.php', 'widgets.php' ],
                'collapsed' => false,
            ],
            [
                'id'        => 'tools',
                'label'     => 'Tools',
                'icon'      => 'dashicons-admin-tools',
                'items'     => [ 'tools.php' ],
                'collapsed' => false,
            ],
            [
                'id'        => 'configure',
                'label'     => 'Configure',
                'icon'      => 'dashicons-admin-settings',
                'items'     => [ 'options-general.php', 'plugins.php', 'users.php' ],
                'collapsed' => false,
            ],
        ];
    }

    // ── Known Plugin Mapping ──────────────────────────────────

    /**
     * Return the built-in mapping of plugin slugs to section IDs.
     *
     * Filterable via `wpt_known_plugin_sections`.
     *
     * @return array
     */
    public function get_known_plugin_map(): array {
        $map = [
            // Commerce
            'woocommerce'                  => 'commerce',
            'easy-digital-downloads'       => 'commerce',
            'surecart'                     => 'commerce',
            'wc-gateway-stripe'            => 'commerce',
            'woocommerce-payments'         => 'commerce',
            'woo-gutenberg-products-block' => 'commerce',
            'cartflows'                    => 'commerce',
            'funnel-builder'               => 'commerce',
            'affiliate-wp'                 => 'commerce',
            'lifterlms'                    => 'commerce',
            'learnpress'                   => 'commerce',
            'learndash'                    => 'commerce',
            'tutor'                        => 'commerce',
            'memberpress'                  => 'commerce',
            'restrict-content'             => 'commerce',
            'paid-memberships-pro'         => 'commerce',

            // Build
            'elementor'                    => 'build',
            'bricks'                       => 'build',
            'advanced-custom-fields'       => 'build',
            'acf-pro'                      => 'build',
            'jetengine'                    => 'build',
            'metabox'                      => 'build',
            'pods'                         => 'build',
            'custom-post-type-ui'          => 'build',
            'oxygen'                       => 'build',
            'generateblocks'              => 'build',
            'kadence-blocks'               => 'build',
            'spectra'                      => 'build',
            'stackable-ultimate-gutenberg-blocks' => 'build',
            'beaver-builder-lite-version'  => 'build',
            'brizy'                        => 'build',
            'divi-builder'                 => 'build',
            'thrive-visual-editor'         => 'build',
            'wpbakery'                     => 'build',
            'fusion-builder'               => 'build',

            // Content
            'wordpress-seo'                => 'content',
            'seo-by-rank-math'             => 'content',
            'all-in-one-seo-pack'          => 'content',
            'the-seo-framework'            => 'content',
            'gravityforms'                 => 'content',
            'wpforms-lite'                 => 'content',
            'fluentform'                   => 'content',
            'formidable'                   => 'content',
            'ninja-forms'                  => 'content',
            'contact-form-7'               => 'content',
            'tablepress'                   => 'content',
            'shortpixel-image-optimiser'   => 'content',
            'smush'                        => 'content',
            'imagify'                      => 'content',

            // Manage
            'wordfence'                    => 'manage',
            'updraftplus'                  => 'manage',
            'redirection'                  => 'manage',
            'all-in-one-wp-migration'      => 'manage',
            'duplicator'                   => 'manage',
            'wp-mail-smtp'                 => 'manage',
            'litespeed-cache'              => 'manage',
            'w3-total-cache'               => 'manage',
            'wp-super-cache'               => 'manage',
            'wp-fastest-cache'             => 'manage',
            'autoptimize'                  => 'manage',
            'google-site-kit'              => 'manage',
            'matomo'                       => 'manage',
            'better-wp-security'           => 'manage',
            'sucuri-scanner'               => 'manage',
            'limit-login-attempts-reloaded' => 'manage',
        ];

        /**
         * Filter the known plugin-to-section mapping.
         *
         * @param array $map Plugin slug => section ID.
         */
        return apply_filters( 'wpt_known_plugin_sections', $map );
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'admin_menu', [ $this, 'organize_admin_menu' ], 99999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        add_action( 'wp_ajax_wpt_save_menu_order', [ $this, 'ajax_save_menu_order' ] );
        add_action( 'wp_ajax_wpt_toggle_section', [ $this, 'ajax_toggle_section' ] );
        add_action( 'wp_ajax_wpt_menu_context_action', [ $this, 'ajax_context_action' ] );

        if ( ! empty( $settings['prompt_on_plugin_install'] ) ) {
            add_action( 'activated_plugin', [ $this, 'on_plugin_activated' ], 10, 1 );
            add_action( 'admin_notices', [ $this, 'show_plugin_placement_notice' ] );
            add_action( 'wp_ajax_wpt_dismiss_plugin_notice', [ $this, 'ajax_dismiss_plugin_notice' ] );
            add_action( 'wp_ajax_wpt_place_plugin_menu', [ $this, 'ajax_place_plugin_menu' ] );
        }
    }

    // ── Admin Menu Organization ───────────────────────────────

    /**
     * Reorganize the admin menu into sections.
     *
     * Reads $GLOBALS['menu'], groups items by section, applies per-role
     * hiding, renames, and custom icons.
     */
    public function organize_admin_menu(): void {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return;
        }

        $settings = $this->get_settings();
        $sections = $settings['sections'] ?? $this->get_default_sections();

        // Apply per-role hiding.
        $this->apply_role_hiding( $settings );

        // Apply renames.
        $renamed = $settings['renamed_items'] ?? [];
        if ( ! empty( $renamed ) ) {
            $this->apply_renames( $renamed );
        }

        // Apply custom icons.
        $icons = $settings['custom_icons'] ?? [];
        if ( ! empty( $icons ) ) {
            $this->apply_custom_icons( $icons );
        }

        // Get user's collapsed sections.
        $user_id   = get_current_user_id();
        $collapsed = get_user_meta( $user_id, 'wpt_menu_collapsed_sections', true );
        if ( ! is_array( $collapsed ) ) {
            // Use default collapsed state from sections.
            $collapsed = [];
            foreach ( $sections as $section ) {
                if ( ! empty( $section['collapsed'] ) ) {
                    $collapsed[] = $section['id'];
                }
            }
        }

        // Build organized menu.
        $organized      = [];
        $assigned_slugs = [];
        $position       = 1;

        foreach ( $sections as $section ) {
            $section_id   = $section['id'];
            $is_collapsed = in_array( $section_id, $collapsed, true );

            // Insert section header.
            $organized[ $position ] = [
                '',
                'read',
                'wpt-section-' . sanitize_key( $section_id ),
                '',
                'wpt-section-header' . ( $is_collapsed ? ' wpt-collapsed' : '' ),
                '',
                esc_attr( $section['icon'] ?? 'dashicons-admin-generic' ),
            ];
            $position++;

            // Collect items for this section from the current menu.
            $section_menu_items = $this->get_section_menu_items( $menu, $section['items'] ?? [] );

            foreach ( $section_menu_items as $item ) {
                $slug = $item[2] ?? '';
                $organized[ $position ] = $item;
                $assigned_slugs[ $slug ] = true;
                $position++;
            }
        }

        // Collect unassigned items (plugins not in any section).
        $unassigned = [];
        foreach ( $menu as $item ) {
            $slug = $item[2] ?? '';
            if ( $slug === '' ) {
                continue;
            }
            // Skip separators.
            if ( strpos( $slug, 'separator' ) === 0 ) {
                continue;
            }
            // Skip our own section headers.
            if ( strpos( $slug, 'wpt-section-' ) === 0 ) {
                continue;
            }
            // Skip Admin::inject_section_labels() separators. Normally that
            // injector defers entirely when this module is active, but this
            // filter is defensive — it prevents the labels from leaking into
            // the Other bucket if the defer check ever fails.
            if ( strpos( $slug, 'wpt-sep-' ) === 0 ) {
                continue;
            }
            if ( ! isset( $assigned_slugs[ $slug ] ) ) {
                $unassigned[] = $item;
            }
        }

        // If there are unassigned items, add an "Other" section.
        if ( ! empty( $unassigned ) ) {
            $is_other_collapsed = in_array( 'other', $collapsed, true );
            $organized[ $position ] = [
                '',
                'read',
                'wpt-section-other',
                '',
                'wpt-section-header' . ( $is_other_collapsed ? ' wpt-collapsed' : '' ),
                '',
                'dashicons-admin-plugins',
            ];
            $position++;

            foreach ( $unassigned as $item ) {
                $organized[ $position ] = $item;
                $position++;
            }
        }

        $menu = $organized;
    }

    /**
     * Get menu items matching the given slugs, preserving slug order.
     *
     * Public for testability.
     *
     * @param array $menu_array  The global $menu array.
     * @param array $item_slugs  Ordered slug list for this section.
     * @return array Matching menu items in slug order.
     */
    public function get_section_menu_items( array $menu_array, array $item_slugs ): array {
        // Build lookup: slug => menu item.
        $by_slug = [];
        foreach ( $menu_array as $item ) {
            $slug = $item[2] ?? '';
            if ( $slug !== '' ) {
                $by_slug[ $slug ] = $item;
            }
        }

        $result = [];
        foreach ( $item_slugs as $slug ) {
            if ( isset( $by_slug[ $slug ] ) ) {
                $result[] = $by_slug[ $slug ];
            }
        }

        return $result;
    }

    /**
     * Apply per-role menu item hiding.
     *
     * @param array $settings Module settings.
     */
    private function apply_role_hiding( array $settings ): void {
        $hidden = $settings['hidden_items'] ?? [];

        if ( empty( $hidden ) || ! is_array( $hidden ) ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return;
        }

        $user_roles = $user->roles;

        foreach ( $hidden as $role => $slugs ) {
            // "all" applies to every user; otherwise match by role.
            if ( $role !== 'all' && ! in_array( $role, $user_roles, true ) ) {
                continue;
            }
            if ( ! is_array( $slugs ) ) {
                continue;
            }
            foreach ( $slugs as $slug ) {
                $slug = sanitize_text_field( $slug );
                if ( $slug !== '' ) {
                    remove_menu_page( $slug );
                }
            }
        }
    }

    /**
     * Apply renamed menu items.
     *
     * @param array $renamed slug => new label.
     */
    private function apply_renames( array $renamed ): void {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return;
        }

        foreach ( $menu as $key => &$item ) {
            $slug = $item[2] ?? '';
            if ( isset( $renamed[ $slug ] ) ) {
                $item[0] = esc_html( sanitize_text_field( $renamed[ $slug ] ) );
            }
        }
        unset( $item );
    }

    /**
     * Apply custom icons to menu items.
     *
     * @param array $icons slug => dashicon class.
     */
    private function apply_custom_icons( array $icons ): void {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return;
        }

        foreach ( $menu as $key => &$item ) {
            $slug = $item[2] ?? '';
            if ( isset( $icons[ $slug ] ) ) {
                $icon = sanitize_html_class( $icons[ $slug ] );
                if ( preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
                    $item[6] = $icon;
                }
            }
        }
        unset( $item );
    }

    // ── AJAX: Save Menu Order ─────────────────────────────────

    /**
     * AJAX handler: save reordered menu.
     */
    public function ajax_save_menu_order(): void {
        check_ajax_referer( 'wpt_smart_menu_organizer', '_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        // Don't sanitize_text_field before json_decode -- it corrupts JSON.
        // Individual fields are sanitized in sanitize_sections().
        $sections_json = wp_unslash( $_POST['sections'] ?? '' );

        // Guard against memory exhaustion from oversized payloads.
        if ( is_string( $sections_json ) && strlen( $sections_json ) > 500000 ) {
            wp_send_json_error( [ 'message' => 'Payload too large.' ], 400 );
        }

        $sections_data = json_decode( $sections_json, true );

        if ( ! is_array( $sections_data ) ) {
            wp_send_json_error( [ 'message' => 'Invalid sections data.' ], 400 );
        }

        $settings = $this->get_settings();
        $settings['sections'] = $this->sanitize_sections( $sections_data );
        \WPTransformed\Core\Settings::save( $this->get_id(), $settings );

        wp_send_json_success( [ 'message' => 'Menu order saved.' ] );
    }

    // ── AJAX: Toggle Section Collapse ─────────────────────────

    /**
     * AJAX handler: toggle a section's collapse state per user.
     */
    public function ajax_toggle_section(): void {
        check_ajax_referer( 'wpt_smart_menu_organizer', '_nonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $section_id = sanitize_key( wp_unslash( $_POST['section_id'] ?? '' ) );
        $collapsed  = ! empty( $_POST['collapsed'] );

        if ( $section_id === '' ) {
            wp_send_json_error( [ 'message' => 'Missing section ID.' ], 400 );
        }

        $user_id          = get_current_user_id();
        $collapsed_states = get_user_meta( $user_id, 'wpt_menu_collapsed_sections', true );

        if ( ! is_array( $collapsed_states ) ) {
            $collapsed_states = [];
        }

        if ( $collapsed ) {
            if ( ! in_array( $section_id, $collapsed_states, true ) ) {
                $collapsed_states[] = $section_id;
            }
        } else {
            $collapsed_states = array_values( array_diff( $collapsed_states, [ $section_id ] ) );
        }

        update_user_meta( $user_id, 'wpt_menu_collapsed_sections', $collapsed_states );

        wp_send_json_success( [ 'collapsed' => $collapsed_states ] );
    }

    // ── AJAX: Context Menu Actions ────────────────────────────

    /**
     * AJAX handler: process right-click context menu actions (hide, rename, move, icon).
     */
    public function ajax_context_action(): void {
        check_ajax_referer( 'wpt_smart_menu_organizer', '_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $action_type = sanitize_key( wp_unslash( $_POST['action_type'] ?? '' ) );
        $menu_slug   = sanitize_text_field( wp_unslash( $_POST['menu_slug'] ?? '' ) );

        if ( $action_type === '' || $menu_slug === '' ) {
            wp_send_json_error( [ 'message' => 'Missing parameters.' ], 400 );
        }

        $settings = $this->get_settings();

        switch ( $action_type ) {
            case 'hide':
                $role = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
                if ( $role === '' ) {
                    $role = 'all';
                }
                if ( ! isset( $settings['hidden_items'][ $role ] ) ) {
                    $settings['hidden_items'][ $role ] = [];
                }
                if ( ! in_array( $menu_slug, $settings['hidden_items'][ $role ], true ) ) {
                    $settings['hidden_items'][ $role ][] = $menu_slug;
                }
                break;

            case 'unhide':
                $role = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
                if ( $role === '' ) {
                    $role = 'all';
                }
                if ( isset( $settings['hidden_items'][ $role ] ) ) {
                    $settings['hidden_items'][ $role ] = array_values(
                        array_diff( $settings['hidden_items'][ $role ], [ $menu_slug ] )
                    );
                }
                break;

            case 'rename':
                $new_label = sanitize_text_field( wp_unslash( $_POST['new_label'] ?? '' ) );
                if ( $new_label !== '' ) {
                    $settings['renamed_items'][ $menu_slug ] = $new_label;
                }
                break;

            case 'move':
                $target_section = sanitize_key( wp_unslash( $_POST['target_section'] ?? '' ) );
                if ( $target_section !== '' ) {
                    // Remove from current section.
                    foreach ( $settings['sections'] as &$section ) {
                        $section['items'] = array_values(
                            array_diff( $section['items'] ?? [], [ $menu_slug ] )
                        );
                    }
                    unset( $section );

                    // Add to target section.
                    foreach ( $settings['sections'] as &$section ) {
                        if ( $section['id'] === $target_section ) {
                            $section['items'][] = $menu_slug;
                            break;
                        }
                    }
                    unset( $section );
                }
                break;

            case 'icon':
                $new_icon = sanitize_html_class( wp_unslash( $_POST['new_icon'] ?? '' ) );
                if ( $new_icon !== '' && preg_match( '/^dashicons-[a-z0-9-]+$/', $new_icon ) ) {
                    $settings['custom_icons'][ $menu_slug ] = $new_icon;
                }
                break;

            default:
                wp_send_json_error( [ 'message' => 'Unknown action type.' ], 400 );
        }

        \WPTransformed\Core\Settings::save( $this->get_id(), $settings );

        wp_send_json_success( [ 'message' => 'Action applied.' ] );
    }

    // ── New Plugin Detection ──────────────────────────────────

    /**
     * Fires when a plugin is activated. Stores a transient for the admin notice.
     *
     * @param string $plugin Plugin file relative path.
     */
    public function on_plugin_activated( string $plugin ): void {
        $slug = dirname( $plugin );
        if ( $slug === '.' ) {
            $slug = basename( $plugin, '.php' );
        }

        set_transient( 'wpt_new_plugin_' . get_current_user_id(), $slug, 300 );
    }

    /**
     * Show an admin notice asking the user to place a newly activated plugin.
     */
    public function show_plugin_placement_notice(): void {
        $plugin_slug = get_transient( 'wpt_new_plugin_' . get_current_user_id() );

        if ( ! $plugin_slug || ! is_string( $plugin_slug ) ) {
            return;
        }

        // Only show to users who can manage options.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $sections = $settings['sections'] ?? $this->get_default_sections();

        // Check known mapping for suggestion.
        $known_map  = $this->get_known_plugin_map();
        $suggestion = $known_map[ $plugin_slug ] ?? '';

        $plugin_name = ucwords( str_replace( [ '-', '_' ], ' ', $plugin_slug ) );

        $nonce = wp_create_nonce( 'wpt_smart_menu_organizer' );
        ?>
        <div class="notice notice-info wpt-plugin-placement-notice" id="wpt-plugin-placement-notice"
             data-plugin-slug="<?php echo esc_attr( $plugin_slug ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-suggestion="<?php echo esc_attr( $suggestion ); ?>">
            <p>
                <strong><?php esc_html_e( 'WPTransformed', 'wptransformed' ); ?>:</strong>
                <?php
                printf(
                    /* translators: %s: plugin name */
                    esc_html__( 'You just activated %s. Where should it go in your admin menu?', 'wptransformed' ),
                    '<strong>' . esc_html( $plugin_name ) . '</strong>'
                );
                ?>
            </p>
            <p class="wpt-placement-buttons">
                <?php foreach ( $sections as $section ) : ?>
                    <button type="button"
                            class="button wpt-place-plugin-btn<?php echo $suggestion === $section['id'] ? ' button-primary' : ''; ?>"
                            data-section="<?php echo esc_attr( $section['id'] ); ?>">
                        <span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
                        <?php echo esc_html( $section['label'] ); ?>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="button wpt-dismiss-plugin-notice">
                    <?php esc_html_e( 'Dismiss', 'wptransformed' ); ?>
                </button>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX handler: dismiss the plugin placement notice.
     */
    public function ajax_dismiss_plugin_notice(): void {
        check_ajax_referer( 'wpt_smart_menu_organizer', '_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        delete_transient( 'wpt_new_plugin_' . get_current_user_id() );
        wp_send_json_success();
    }

    /**
     * AJAX handler: place a newly activated plugin's menu into a section.
     */
    public function ajax_place_plugin_menu(): void {
        check_ajax_referer( 'wpt_smart_menu_organizer', '_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $plugin_slug    = sanitize_key( wp_unslash( $_POST['plugin_slug'] ?? '' ) );
        $target_section = sanitize_key( wp_unslash( $_POST['target_section'] ?? '' ) );
        $menu_slug      = sanitize_text_field( wp_unslash( $_POST['menu_slug'] ?? '' ) );

        if ( $target_section === '' ) {
            wp_send_json_error( [ 'message' => 'Missing target section.' ], 400 );
        }

        // If a specific menu slug was provided, add it to the section.
        if ( $menu_slug !== '' ) {
            $settings = $this->get_settings();
            foreach ( $settings['sections'] as &$section ) {
                if ( $section['id'] === $target_section ) {
                    if ( ! in_array( $menu_slug, $section['items'], true ) ) {
                        $section['items'][] = $menu_slug;
                    }
                    break;
                }
            }
            unset( $section );
            \WPTransformed\Core\Settings::save( $this->get_id(), $settings );
        }

        delete_transient( 'wpt_new_plugin_' . get_current_user_id() );
        wp_send_json_success( [ 'message' => 'Plugin placed.' ] );
    }

    // ── Assets ────────────────────────────────────────────────

    /**
     * Enqueue assets on all admin pages (for section headers, drag-drop, context menu).
     */
    public function enqueue_admin_assets( string $hook ): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        wp_enqueue_style(
            'wpt-smart-menu-organizer',
            WPT_URL . 'modules/admin-interface/css/smart-menu-organizer.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-smart-menu-organizer',
            WPT_URL . 'modules/admin-interface/js/smart-menu-organizer.js',
            [],
            WPT_VERSION,
            true
        );

        $sections = $settings['sections'] ?? $this->get_default_sections();

        wp_localize_script( 'wpt-smart-menu-organizer', 'wptSmartMenu', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpt_smart_menu_organizer' ),
            'sections' => $sections,
            'labels'   => [
                'hide'          => __( 'Hide', 'wptransformed' ),
                'unhide'        => __( 'Show', 'wptransformed' ),
                'rename'        => __( 'Rename', 'wptransformed' ),
                'moveTo'        => __( 'Move to...', 'wptransformed' ),
                'changeIcon'    => __( 'Change Icon', 'wptransformed' ),
                'renamePrompt'  => __( 'Enter new name:', 'wptransformed' ),
                'other'         => __( 'Other', 'wptransformed' ),
            ],
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    /**
     * Render the settings page UI.
     */
    public function render_settings(): void {
        $settings = $this->get_settings();
        $sections = $settings['sections'] ?? $this->get_default_sections();
        ?>
        <p class="description" style="margin-bottom: 12px;">
            <?php esc_html_e( 'Configure how your admin sidebar is organized. Sections group related menu items. Drag and drop items between sections on the live admin sidebar, or configure them here.', 'wptransformed' ); ?>
        </p>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Auto-Organize on Activate', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_auto_organize" value="1"
                               <?php checked( ! empty( $settings['auto_organize_on_activate'] ) ); ?>>
                        <?php esc_html_e( 'Automatically organize the sidebar when this module is activated.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Plugin Install Prompt', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_prompt_on_install" value="1"
                               <?php checked( ! empty( $settings['prompt_on_plugin_install'] ) ); ?>>
                        <?php esc_html_e( 'Show a prompt to place new plugin menu items when a plugin is activated.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Sections', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-smo-sections-editor">
                        <?php foreach ( $sections as $index => $section ) : ?>
                            <div class="wpt-smo-section-row" data-section-id="<?php echo esc_attr( $section['id'] ); ?>">
                                <span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
                                <strong><?php echo esc_html( $section['label'] ); ?></strong>
                                <span class="description">
                                    (<?php echo esc_html( count( $section['items'] ?? [] ) ); ?> <?php esc_html_e( 'items', 'wptransformed' ); ?>)
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">
                        <?php esc_html_e( 'Sections are configured via the live admin sidebar. Drag items between sections and right-click for more options.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <div class="wpt-smo-actions" style="margin-top: 12px;">
            <button type="button" id="wpt-smo-reset" class="button">
                <?php esc_html_e( 'Reset to Defaults', 'wptransformed' ); ?>
            </button>
        </div>

        <input type="hidden" id="wpt-smo-sections-json" name="wpt_sections_json"
               value="<?php echo esc_attr( wp_json_encode( $sections ) ); ?>">
        <input type="hidden" id="wpt-smo-hidden-json" name="wpt_hidden_json"
               value="<?php echo esc_attr( wp_json_encode( $settings['hidden_items'] ?? [] ) ); ?>">
        <input type="hidden" id="wpt-smo-renamed-json" name="wpt_renamed_json"
               value="<?php echo esc_attr( wp_json_encode( $settings['renamed_items'] ?? [] ) ); ?>">
        <input type="hidden" id="wpt-smo-icons-json" name="wpt_icons_json"
               value="<?php echo esc_attr( wp_json_encode( $settings['custom_icons'] ?? [] ) ); ?>">

        <?php
        $init_data = wp_json_encode( [
            'sections'     => $sections,
            'hiddenItems'  => (object) ( $settings['hidden_items'] ?? [] ),
            'renamedItems' => (object) ( $settings['renamed_items'] ?? [] ),
            'customIcons'  => (object) ( $settings['custom_icons'] ?? [] ),
            'defaults'     => $this->get_default_sections(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
        if ( $init_data === false ) {
            $init_data = '{}';
        }
        ?>
        <script type="application/json" id="wpt-smo-init-data"><?php echo $init_data; ?></script>
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
        $settings = $this->get_settings();

        // Auto organize.
        $settings['auto_organize_on_activate'] = ! empty( $raw['wpt_auto_organize'] );

        // Plugin install prompt.
        $settings['prompt_on_plugin_install'] = ! empty( $raw['wpt_prompt_on_install'] );

        // Sections JSON.
        $sections_raw = $raw['wpt_sections_json'] ?? '';
        if ( $sections_raw !== '' ) {
            $decoded = json_decode( wp_unslash( $sections_raw ), true );
            if ( is_array( $decoded ) ) {
                $settings['sections'] = $this->sanitize_sections( $decoded );
            }
        }

        // Hidden items JSON.
        $hidden_raw = $raw['wpt_hidden_json'] ?? '';
        if ( $hidden_raw !== '' ) {
            $decoded = json_decode( wp_unslash( $hidden_raw ), true );
            if ( is_array( $decoded ) ) {
                $sanitized = [];
                foreach ( $decoded as $role => $slugs ) {
                    $role = sanitize_key( (string) $role );
                    if ( $role === '' || ! is_array( $slugs ) ) {
                        continue;
                    }
                    $sanitized[ $role ] = array_values( array_filter(
                        array_map( 'sanitize_text_field', $slugs )
                    ) );
                }
                $settings['hidden_items'] = $sanitized;
            }
        }

        // Renamed items JSON.
        $renamed_raw = $raw['wpt_renamed_json'] ?? '';
        if ( $renamed_raw !== '' ) {
            $decoded = json_decode( wp_unslash( $renamed_raw ), true );
            if ( is_array( $decoded ) ) {
                $sanitized = [];
                foreach ( $decoded as $slug => $label ) {
                    $slug  = sanitize_text_field( (string) $slug );
                    $label = sanitize_text_field( (string) $label );
                    if ( $slug !== '' && $label !== '' ) {
                        $sanitized[ $slug ] = $label;
                    }
                }
                $settings['renamed_items'] = $sanitized;
            }
        }

        // Custom icons JSON.
        $icons_raw = $raw['wpt_icons_json'] ?? '';
        if ( $icons_raw !== '' ) {
            $decoded = json_decode( wp_unslash( $icons_raw ), true );
            if ( is_array( $decoded ) ) {
                $sanitized = [];
                foreach ( $decoded as $slug => $icon ) {
                    $slug = sanitize_text_field( (string) $slug );
                    $icon = sanitize_html_class( (string) $icon );
                    if ( $slug !== '' && $icon !== '' && preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
                        $sanitized[ $slug ] = $icon;
                    }
                }
                $settings['custom_icons'] = $sanitized;
            }
        }

        return $settings;
    }

    /**
     * Sanitize sections array.
     *
     * @param array $sections Raw sections.
     * @return array Sanitized sections.
     */
    private function sanitize_sections( array $sections ): array {
        $sanitized = [];

        foreach ( $sections as $section ) {
            if ( ! is_array( $section ) || ! isset( $section['id'] ) ) {
                continue;
            }

            $items = [];
            if ( isset( $section['items'] ) && is_array( $section['items'] ) ) {
                foreach ( $section['items'] as $slug ) {
                    $slug = sanitize_text_field( (string) $slug );
                    if ( $slug !== '' ) {
                        $items[] = $slug;
                    }
                }
            }

            $sanitized[] = [
                'id'        => sanitize_key( $section['id'] ),
                'label'     => sanitize_text_field( $section['label'] ?? '' ),
                'icon'      => sanitize_html_class( $section['icon'] ?? 'dashicons-admin-generic' ),
                'items'     => $items,
                'collapsed' => ! empty( $section['collapsed'] ),
            ];
        }

        return $sanitized;
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => 'wpt_smart_menu_organizer' ],
            [ 'type' => 'user_meta', 'key' => 'wpt_menu_collapsed_sections' ],
            [ 'type' => 'transient', 'key' => 'wpt_new_plugin_' . get_current_user_id() ],
        ];
    }
}
