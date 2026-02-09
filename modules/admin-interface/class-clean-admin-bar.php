<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Bar Manager — Manage admin bar visibility, custom links, and per-role profiles.
 *
 * Core logic:
 * - wp_before_admin_bar_render at priority 999: remove hidden nodes per context + role profile
 * - admin_bar_menu at priority 100: add custom links
 * - admin_bar_menu at priority 9999: scan nodes for settings UI
 *
 * @package WPTransformed
 */
class Clean_Admin_Bar extends Module_Base {

    /**
     * Well-known admin bar node IDs with human-readable labels.
     */
    private const KNOWN_NODES = [
        'wp-logo'       => 'WordPress Logo',
        'site-name'     => 'Site Name',
        'updates'       => 'Updates Counter',
        'comments'      => 'Comments Counter',
        'new-content'   => '+ New Dropdown',
        'edit'          => 'Edit Page/Post Link',
        'my-account'    => 'User Menu (Howdy)',
        'search'        => 'Search Field',
        'top-secondary' => 'Right Side Container',
    ];

    /**
     * Top-level node IDs that belong to the left side of the admin bar.
     */
    private const LEFT_SIDE_NODES = [
        'menu-toggle',
        'wp-logo',
        'site-name',
        'updates',
        'comments',
        'new-content',
        'edit',
    ];

    /**
     * Top-level node IDs that belong to the right side of the admin bar.
     */
    private const RIGHT_SIDE_NODES = [
        'top-secondary',
        'my-account',
        'search',
    ];

    /**
     * Transient key for the scanned node list.
     */
    private const TRANSIENT_KEY = 'wpt_admin_bar_nodes';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'clean-admin-bar';
    }

    public function get_title(): string {
        return __( 'Admin Bar Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Manage admin bar visibility, add custom links, and create per-role toolbar profiles.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'profiles' => [
                'default' => [
                    'hidden_nodes' => [], // node_id => 'admin'|'frontend'|'both'
                ],
            ],
            'custom_links' => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Migrate old settings format on first load.
        $this->maybe_migrate_settings();

        // Remove hidden nodes at very late priority so all nodes are registered.
        add_action( 'wp_before_admin_bar_render', [ $this, 'process_admin_bar' ], 999 );

        // Add custom links to the admin bar.
        add_action( 'admin_bar_menu', [ $this, 'add_custom_links' ], 100 );

        // Capture node list when on the WPT settings page for scan.
        add_action( 'admin_bar_menu', [ $this, 'capture_admin_bar_nodes' ], 9999 );

        // Handle the manual scan button.
        add_action( 'admin_init', [ $this, 'handle_scan_action' ] );
    }

    // ── Migration ─────────────────────────────────────────────

    /**
     * Migrate from old flat settings format to new profile-based format.
     *
     * Old format: ['hidden_nodes' => ['wp-logo', 'comments'], 'hide_for_roles' => [...]]
     * New format: ['profiles' => ['default' => ['hidden_nodes' => ['wp-logo' => 'both', ...]]], 'custom_links' => []]
     */
    private function maybe_migrate_settings(): void {
        $settings = $this->get_settings();

        // Already migrated if 'profiles' key exists at the top level.
        if ( isset( $settings['profiles'] ) ) {
            return;
        }

        // Old format detected — has flat 'hidden_nodes' array with numeric keys.
        $old_hidden = $settings['hidden_nodes'] ?? [];
        if ( empty( $old_hidden ) || ! isset( $old_hidden[0] ) ) {
            // No old data or already associative — just save defaults.
            \WPTransformed\Core\Settings::save( $this->get_id(), $this->get_default_settings() );
            return;
        }

        // Convert flat array to associative with 'both' context.
        $new_hidden = [];
        foreach ( $old_hidden as $node_id ) {
            if ( is_string( $node_id ) && $node_id !== '' ) {
                $new_hidden[ sanitize_key( $node_id ) ] = 'both';
            }
        }

        $new_settings = [
            'profiles' => [
                'default' => [
                    'hidden_nodes' => $new_hidden,
                ],
            ],
            'custom_links' => [],
        ];

        \WPTransformed\Core\Settings::save( $this->get_id(), $new_settings );
    }

    // ── Core Logic: Remove Nodes ──────────────────────────────

    /**
     * Process admin bar: remove hidden nodes based on context and role profile.
     *
     * Fires on wp_before_admin_bar_render at priority 999.
     */
    public function process_admin_bar(): void {
        global $wp_admin_bar;

        if ( ! $wp_admin_bar instanceof \WP_Admin_Bar ) {
            return;
        }

        $context = is_admin() ? 'admin' : 'frontend';

        $user = wp_get_current_user();
        $role = ! empty( $user->roles ) ? $user->roles[0] : '';

        $settings = $this->get_settings();
        $profiles = $settings['profiles'] ?? [];

        // Role-specific profile takes priority, then fall back to 'default'.
        $profile = $profiles[ $role ] ?? $profiles['default'] ?? [];
        $hidden  = $profile['hidden_nodes'] ?? [];

        if ( empty( $hidden ) ) {
            return;
        }

        foreach ( $hidden as $node_id => $hide_context ) {
            $node_id = sanitize_key( (string) $node_id );
            if ( $hide_context === 'both' || $hide_context === $context ) {
                $wp_admin_bar->remove_node( $node_id );
            }
        }
    }

    // ── Custom Links ──────────────────────────────────────────

    /**
     * Add custom links to the admin bar.
     *
     * Fires on admin_bar_menu at priority 100.
     *
     * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
     */
    public function add_custom_links( \WP_Admin_Bar $wp_admin_bar ): void {
        $settings = $this->get_settings();
        $links    = $settings['custom_links'] ?? [];

        if ( empty( $links ) ) {
            return;
        }

        $user      = wp_get_current_user();
        $user_role = ! empty( $user->roles ) ? $user->roles[0] : '';

        foreach ( $links as $index => $link ) {
            // Skip if role-restricted and user doesn't match.
            $link_roles = $link['roles'] ?? [];
            if ( ! empty( $link_roles ) && ! in_array( $user_role, $link_roles, true ) ) {
                continue;
            }

            $icon    = sanitize_html_class( $link['icon'] ?? 'dashicons-admin-links' );
            $title   = esc_html( $link['title'] ?? '' );
            $url     = esc_url( $link['url'] ?? '#' );
            $new_tab = ! empty( $link['new_tab'] );

            $wp_admin_bar->add_node( [
                'id'     => 'wpt-custom-' . (int) $index,
                'title'  => '<span class="ab-icon dashicons ' . $icon . '"></span>' . $title,
                'href'   => $url,
                'parent' => ( $link['position'] ?? 'left' ) === 'right' ? 'top-secondary' : false,
                'meta'   => [
                    'target' => $new_tab ? '_blank' : '_self',
                    'rel'    => $new_tab ? 'noopener noreferrer' : '',
                ],
            ] );
        }
    }

    // ── Node Scanning ─────────────────────────────────────────

    /**
     * Capture all admin bar nodes on the WPT settings page.
     *
     * Stores nodes grouped by position (left, right, plugin) with
     * parent/child relationships.
     *
     * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
     */
    public function capture_admin_bar_nodes( \WP_Admin_Bar $wp_admin_bar ): void {
        // Only scan when on the WPT settings page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wptransformed' ) {
            return;
        }

        $nodes = $wp_admin_bar->get_nodes();
        if ( ! is_array( $nodes ) && ! is_object( $nodes ) ) {
            return;
        }

        // Build flat list with parent info.
        $flat = [];
        foreach ( $nodes as $node ) {
            $flat[ $node->id ] = [
                'id'     => $node->id,
                'title'  => wp_strip_all_tags( (string) $node->title ),
                'parent' => $node->parent ?? '',
            ];
        }

        // Build grouped structure: left, right, plugin.
        $groups = [
            'left'   => [],
            'right'  => [],
            'plugin' => [],
        ];

        // Identify top-level nodes and their children.
        $top_level = [];
        $children  = [];
        foreach ( $flat as $id => $node ) {
            if ( empty( $node['parent'] ) ) {
                $top_level[ $id ] = $node;
            } else {
                $children[ $node['parent'] ][] = $node;
            }
        }

        // Sort each top-level node into a group with its children.
        foreach ( $top_level as $id => $node ) {
            $entry = $node;
            $entry['children'] = $children[ $id ] ?? [];

            if ( in_array( $id, self::LEFT_SIDE_NODES, true ) ) {
                $groups['left'][ $id ] = $entry;
            } elseif ( in_array( $id, self::RIGHT_SIDE_NODES, true ) ) {
                $groups['right'][ $id ] = $entry;
            } else {
                // Check if it's a child of top-secondary (right side).
                if ( ! empty( $node['parent'] ) && $node['parent'] === 'top-secondary' ) {
                    $groups['right'][ $id ] = $entry;
                } else {
                    $groups['plugin'][ $id ] = $entry;
                }
            }
        }

        set_transient( self::TRANSIENT_KEY, $groups, DAY_IN_SECONDS );
    }

    /**
     * Handle the "Scan Admin Bar" button form submission.
     */
    public function handle_scan_action(): void {
        if ( ! isset( $_POST['wpt_scan_admin_bar'] ) ) {
            return;
        }

        if ( ! check_admin_referer( 'wpt_scan_admin_bar', 'wpt_scan_nonce' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Delete the transient so the next admin_bar_menu hook refreshes it.
        delete_transient( self::TRANSIENT_KEY );

        // Redirect back to the settings page (PRG pattern).
        wp_safe_redirect( add_query_arg( [
            'page'        => 'wptransformed',
            'tab'         => 'admin-interface',
            'module'      => 'clean-admin-bar',
            'wpt_scanned' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Get the node list for the settings UI, grouped by position.
     *
     * Returns ['left' => [...], 'right' => [...], 'plugin' => [...]]
     * Each entry has: id, title, parent, children[].
     *
     * @return array{left:array,right:array,plugin:array}
     */
    public function get_node_list(): array {
        $scanned = get_transient( self::TRANSIENT_KEY );

        // If scanned data exists in new grouped format, use it.
        if ( is_array( $scanned ) && isset( $scanned['left'] ) ) {
            $groups = $scanned;
        } else {
            // Handle old flat format or missing data — rebuild from known nodes.
            $groups = [
                'left'   => [],
                'right'  => [],
                'plugin' => [],
            ];

            // If old flat format exists, convert it.
            if ( is_array( $scanned ) && ! isset( $scanned['left'] ) ) {
                $top_level = [];
                $children  = [];
                foreach ( $scanned as $id => $node ) {
                    if ( empty( $node['parent'] ) ) {
                        $top_level[ $id ] = $node;
                    } else {
                        $children[ $node['parent'] ][] = $node;
                    }
                }

                foreach ( $top_level as $id => $node ) {
                    $entry = $node;
                    $entry['children'] = $children[ $id ] ?? [];

                    if ( in_array( $id, self::LEFT_SIDE_NODES, true ) ) {
                        $groups['left'][ $id ] = $entry;
                    } elseif ( in_array( $id, self::RIGHT_SIDE_NODES, true ) ) {
                        $groups['right'][ $id ] = $entry;
                    } else {
                        $groups['plugin'][ $id ] = $entry;
                    }
                }
            }
        }

        // Merge known nodes as fallback (only for IDs not already in groups).
        $existing_ids = array_merge(
            array_keys( $groups['left'] ),
            array_keys( $groups['right'] ),
            array_keys( $groups['plugin'] )
        );

        foreach ( self::KNOWN_NODES as $id => $label ) {
            if ( $id === 'my-sites' && ! is_multisite() ) {
                continue;
            }

            if ( in_array( $id, $existing_ids, true ) ) {
                continue;
            }

            $entry = [
                'id'       => $id,
                'title'    => $label,
                'parent'   => '',
                'children' => [],
            ];

            if ( in_array( $id, self::LEFT_SIDE_NODES, true ) ) {
                $groups['left'][ $id ] = $entry;
            } elseif ( in_array( $id, self::RIGHT_SIDE_NODES, true ) ) {
                $groups['right'][ $id ] = $entry;
            }
        }

        // Multisite.
        if ( is_multisite() && ! in_array( 'my-sites', $existing_ids, true ) ) {
            $groups['left']['my-sites'] = [
                'id'       => 'my-sites',
                'title'    => 'My Sites',
                'parent'   => '',
                'children' => [],
            ];
        }

        return $groups;
    }

    // ── Assets ─────────────────────────────────────────────

    /**
     * Enqueue admin assets only on the WPTransformed settings page.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_wptransformed' ) {
            return;
        }

        wp_enqueue_style(
            'wpt-admin-bar-manager',
            WPT_URL . 'modules/admin-interface/css/admin-bar-manager.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-admin-bar-manager',
            WPT_URL . 'modules/admin-interface/js/admin-bar-manager.js',
            [],
            WPT_VERSION,
            true
        );
    }

    // ── Settings UI ───────────────────────────────────────────

    /**
     * Render the full settings page UI.
     *
     * Outputs:
     * A. Status summary bar
     * B. Role tabs
     * C. Per-role profile panels (JS-rendered with sections and toggle rows)
     * D. Scan button
     * E. Hidden JSON input for all profiles data
     */
    public function render_settings(): void {
        $settings    = $this->get_settings();
        $profiles    = $settings['profiles'] ?? [ 'default' => [ 'hidden_nodes' => [] ] ];
        $node_groups = $this->get_node_list();
        $wp_roles    = wp_roles()->get_names();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $just_scanned = isset( $_GET['wpt_scanned'] );

        $custom_links = $settings['custom_links'] ?? [];

        // Build role names map for JS.
        $role_names = [];
        foreach ( $wp_roles as $slug => $name ) {
            $role_names[ $slug ] = translate_user_role( $name );
        }

        // FIX: Ensure empty hidden_nodes encode as JSON {} not [].
        // PHP json_encode([]) → "[]" which JS parses as Array,
        // causing string-keyed properties to be silently dropped
        // by JSON.stringify on the client side.
        foreach ( $profiles as $rk => &$pd ) {
            if ( empty( $pd['hidden_nodes'] ) || ( is_array( $pd['hidden_nodes'] ) && count( $pd['hidden_nodes'] ) === 0 ) ) {
                $pd['hidden_nodes'] = new \stdClass();
            }
        }
        unset( $pd );

        // Build the init data blob for JS.
        $init_data = [
            'profiles'    => $profiles,
            'nodeGroups'  => $node_groups,
            'customLinks' => $custom_links,
            'wpRoles'     => $role_names,
        ];
        ?>

        <?php if ( $just_scanned ) : ?>
            <div class="notice notice-success inline" style="margin: 0 0 12px;">
                <p><?php esc_html_e( 'Admin bar scanned successfully.', 'wptransformed' ); ?></p>
            </div>
        <?php endif; ?>

        <?php // A. Status summary bar ?>
        <div class="wpt-abm-status" id="wpt-abm-status">
            <?php esc_html_e( 'Loading...', 'wptransformed' ); ?>
        </div>

        <?php // B. Role tabs ?>
        <div class="wpt-abm-role-tabs">
            <button type="button" class="wpt-abm-role-tab active" data-role="default">
                <?php esc_html_e( 'All Roles', 'wptransformed' ); ?>
            </button>
            <?php foreach ( $wp_roles as $slug => $name ) : ?>
                <button type="button" class="wpt-abm-role-tab" data-role="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( translate_user_role( $name ) ); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php // C. Profile panels — one per role + default ?>
        <div class="wpt-abm-profile-panel active" id="wpt-abm-panel-default" data-role-name="<?php esc_attr_e( 'All Roles', 'wptransformed' ); ?>">
        </div>
        <?php foreach ( $wp_roles as $slug => $name ) : ?>
            <div class="wpt-abm-profile-panel" id="wpt-abm-panel-<?php echo esc_attr( $slug ); ?>" data-role-name="<?php echo esc_attr( translate_user_role( $name ) ); ?>">
            </div>
        <?php endforeach; ?>

        <?php // D. Custom Links section ?>
        <div class="wpt-abm-custom-links">
            <div class="wpt-abm-custom-links-header">
                <h3><?php esc_html_e( 'Custom Links', 'wptransformed' ); ?></h3>
                <p><?php esc_html_e( 'Add quick links to Google Analytics, your hosting dashboard, staging site, client CRM, or documentation.', 'wptransformed' ); ?></p>
            </div>

            <div id="wpt-abm-links-list" class="wpt-abm-links-list">
                <?php // JS renders link cards here. ?>
            </div>

            <button type="button" id="wpt-abm-add-link-btn" class="wpt-abm-add-link">
                + <?php esc_html_e( 'Add Custom Link', 'wptransformed' ); ?>
            </button>
            <span id="wpt-abm-links-max-msg" class="wpt-abm-links-max" style="display: none;">
                <?php esc_html_e( 'Maximum 10 custom links reached.', 'wptransformed' ); ?>
            </span>
        </div>

        <?php // E. Scan button ?>
        <div class="wpt-abm-scan-row">
            <?php wp_nonce_field( 'wpt_scan_admin_bar', 'wpt_scan_nonce' ); ?>
            <button type="submit" name="wpt_scan_admin_bar" value="1" class="button">
                <?php esc_html_e( 'Scan Admin Bar', 'wptransformed' ); ?>
            </button>
            <p class="description">
                <?php esc_html_e( 'Re-scan to detect items added by newly installed plugins.', 'wptransformed' ); ?>
            </p>
        </div>

        <?php // F. Hidden JSON inputs for profiles + custom links data ?>
        <input type="hidden" id="wpt-abm-profiles-json" name="wpt_profiles_json" value="<?php echo esc_attr( wp_json_encode( $profiles ) ); ?>">
        <input type="hidden" id="wpt-abm-custom-links-json" name="wpt_custom_links_json" value="<?php echo esc_attr( wp_json_encode( $custom_links ) ); ?>">

        <?php // Init data for JS. ?>
        <script type="application/json" id="wpt-abm-init-data"><?php echo wp_json_encode( $init_data ); ?></script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    /**
     * Sanitize settings from the form submission.
     *
     * Handles the JSON profiles input from the new UI,
     * plus the custom links data (for 4C UI).
     */
    public function sanitize_settings( array $raw ): array {
        $existing       = $this->get_settings();
        $valid_contexts = [ 'admin', 'frontend', 'both' ];

        // Handle JSON profiles input from new UI.
        if ( ! empty( $raw['wpt_profiles_json'] ) ) {
            $decoded = json_decode( wp_unslash( $raw['wpt_profiles_json'] ), true );

            if ( is_array( $decoded ) ) {
                $sanitized_profiles = [];

                foreach ( $decoded as $role_key => $profile_data ) {
                    $role_key = sanitize_key( (string) $role_key );
                    if ( $role_key === '' ) {
                        continue;
                    }

                    $hidden = [];
                    $nodes  = $profile_data['hidden_nodes'] ?? [];

                    if ( is_array( $nodes ) ) {
                        foreach ( $nodes as $node_id => $context ) {
                            $node_id = sanitize_key( (string) $node_id );
                            $context = in_array( $context, $valid_contexts, true ) ? $context : 'both';
                            if ( $node_id !== '' ) {
                                $hidden[ $node_id ] = $context;
                            }
                        }
                    }

                    $sanitized_profiles[ $role_key ] = [
                        'hidden_nodes' => $hidden,
                    ];
                }

                // Always ensure 'default' profile exists.
                if ( ! isset( $sanitized_profiles['default'] ) ) {
                    $sanitized_profiles['default'] = [ 'hidden_nodes' => [] ];
                }

                // FIX: Force empty hidden_nodes to encode as JSON {} not [].
                // PHP json_encode([]) → "[]" which JS parses as Array,
                // causing string-keyed properties to be silently dropped
                // by JSON.stringify. Using stdClass forces {} encoding.
                foreach ( $sanitized_profiles as $rk => $pd ) {
                    if ( empty( $pd['hidden_nodes'] ) ) {
                        $sanitized_profiles[ $rk ]['hidden_nodes'] = new \stdClass();
                    }
                }

                $existing['profiles'] = $sanitized_profiles;
            }
        }

        // Handle custom links JSON input.
        if ( ! empty( $raw['wpt_custom_links_json'] ) ) {
            $links_decoded = json_decode( wp_unslash( $raw['wpt_custom_links_json'] ), true );

            if ( is_array( $links_decoded ) ) {
                $sanitized_links = [];
                $valid_roles     = array_keys( wp_roles()->get_names() );
                $count           = 0;

                foreach ( $links_decoded as $link ) {
                    if ( $count >= 10 ) {
                        break;
                    }
                    if ( ! is_array( $link ) ) {
                        continue;
                    }

                    $title = sanitize_text_field( $link['title'] ?? '' );
                    $url   = esc_url_raw( $link['url'] ?? '' );

                    // Title and URL are required — skip invalid entries.
                    if ( $title === '' || $url === '' ) {
                        continue;
                    }

                    $icon = sanitize_html_class( $link['icon'] ?? '' );
                    if ( $icon === '' ) {
                        $icon = 'dashicons-admin-links';
                    }

                    $sanitized_links[] = [
                        'title'    => $title,
                        'url'      => $url,
                        'icon'     => $icon,
                        'new_tab'  => ! empty( $link['new_tab'] ),
                        'position' => in_array( $link['position'] ?? '', [ 'left', 'right' ], true )
                            ? $link['position']
                            : 'left',
                        'roles'    => array_values( array_intersect(
                            array_map( 'sanitize_text_field', (array) ( $link['roles'] ?? [] ) ),
                            $valid_roles
                        ) ),
                    ];
                    $count++;
                }

                $existing['custom_links'] = $sanitized_links;
            }
        }

        return [
            'profiles'     => $existing['profiles'] ?? [ 'default' => [ 'hidden_nodes' => [] ] ],
            'custom_links' => $existing['custom_links'] ?? [],
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => 'wpt_admin_bar_nodes' ],
        ];
    }
}
