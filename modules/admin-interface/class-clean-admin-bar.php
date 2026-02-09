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

    // ── Settings UI ───────────────────────────────────────────

    /**
     * Render the settings page.
     *
     * Reads from the new profile-based data structure.
     * This is a transitional UI — will be fully rebuilt in 4B.
     */
    public function render_settings(): void {
        $settings = $this->get_settings();
        $groups   = $this->get_node_list();

        // Get the default profile's hidden nodes for the current UI.
        $default_profile = $settings['profiles']['default'] ?? [];
        $hidden_nodes    = $default_profile['hidden_nodes'] ?? [];

        // Custom links summary.
        $custom_links = $settings['custom_links'] ?? [];

        // Role profiles summary.
        $profiles     = $settings['profiles'] ?? [];
        $role_count   = count( $profiles ) - 1; // Subtract 'default'.

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $just_scanned = isset( $_GET['wpt_scanned'] );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Admin Bar Items', 'wptransformed' ); ?></th>
                <td>
                    <?php if ( $just_scanned ) : ?>
                        <div class="notice notice-success inline" style="margin: 0 0 12px;">
                            <p><?php esc_html_e( 'Admin bar scanned successfully.', 'wptransformed' ); ?></p>
                        </div>
                    <?php endif; ?>

                    <fieldset>
                        <p class="description" style="margin-bottom: 10px;">
                            <?php esc_html_e( 'Check items to hide from the admin bar. Context (admin/frontend/both) can be set per item in the full UI.', 'wptransformed' ); ?>
                        </p>

                        <?php $this->render_node_section( __( 'Left Side', 'wptransformed' ), $groups['left'], $hidden_nodes ); ?>
                        <?php $this->render_node_section( __( 'Right Side', 'wptransformed' ), $groups['right'], $hidden_nodes ); ?>
                        <?php if ( ! empty( $groups['plugin'] ) ) : ?>
                            <?php $this->render_node_section( __( 'Plugin & Theme Items', 'wptransformed' ), $groups['plugin'], $hidden_nodes ); ?>
                        <?php endif; ?>
                    </fieldset>

                    <?php if ( $role_count > 0 ) : ?>
                        <p class="description" style="margin-top: 12px;">
                            <?php
                            printf(
                                /* translators: %d: number of role-specific profiles */
                                esc_html( _n(
                                    '%d role-specific profile configured.',
                                    '%d role-specific profiles configured.',
                                    $role_count,
                                    'wptransformed'
                                ) ),
                                $role_count
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if ( ! empty( $custom_links ) ) : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: number of custom links */
                                esc_html( _n(
                                    '%d custom link configured.',
                                    '%d custom links configured.',
                                    count( $custom_links ),
                                    'wptransformed'
                                ) ),
                                count( $custom_links )
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Scan', 'wptransformed' ); ?></th>
                <td>
                    <?php wp_nonce_field( 'wpt_scan_admin_bar', 'wpt_scan_nonce' ); ?>
                    <button type="submit" name="wpt_scan_admin_bar" value="1" class="button">
                        <?php esc_html_e( 'Scan Admin Bar', 'wptransformed' ); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e( 'Re-scan to detect items added by newly installed plugins.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render a section of admin bar nodes with a heading.
     *
     * @param string $label        Section heading text.
     * @param array  $section_nodes Nodes in this section (with children arrays).
     * @param array  $hidden_nodes Hidden nodes map (node_id => context) from profile.
     */
    private function render_node_section( string $label, array $section_nodes, array $hidden_nodes ): void {
        $total  = count( $section_nodes );
        $hidden_count = 0;
        foreach ( $section_nodes as $id => $node ) {
            if ( isset( $hidden_nodes[ $id ] ) ) {
                $hidden_count++;
            }
        }
        ?>
        <h4 style="margin: 16px 0 8px; font-size: 13px; font-weight: 600; color: #1d2327;">
            <?php echo esc_html( $label ); ?>
            <span style="font-weight: 400; color: #888; font-size: 12px;">
                (<?php
                printf(
                    /* translators: 1: total items, 2: hidden items */
                    esc_html__( '%1$d items, %2$d hidden', 'wptransformed' ),
                    $total,
                    $hidden_count
                );
                ?>)
            </span>
        </h4>
        <div style="margin-left: 4px;">
            <?php foreach ( $section_nodes as $id => $node ) : ?>
                <?php $this->render_node_checkbox( $id, $node, $hidden_nodes ); ?>

                <?php
                $node_children = $node['children'] ?? [];
                if ( ! empty( $node_children ) ) : ?>
                    <div style="margin-left: 24px;">
                        <?php foreach ( $node_children as $child ) :
                            $child_id = $child['id'] ?? '';
                            if ( $child_id !== '' ) :
                                $this->render_node_checkbox( $child_id, $child, $hidden_nodes );
                            endif;
                        endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a single admin bar node checkbox row.
     *
     * For the transitional UI: checkbox = hidden when checked.
     * The context value is shown as a label when the node is hidden.
     *
     * @param string $id           Node ID.
     * @param array  $node         Node data.
     * @param array  $hidden_nodes Hidden nodes map (node_id => context).
     */
    private function render_node_checkbox( string $id, array $node, array $hidden_nodes ): void {
        $title        = ! empty( $node['title'] ) ? $node['title'] : $id;
        $is_hidden    = isset( $hidden_nodes[ $id ] );
        $hide_context = $hidden_nodes[ $id ] ?? 'both';
        ?>
        <label style="display: block; margin-bottom: 4px;">
            <input type="checkbox" name="wpt_hidden_nodes[]"
                   value="<?php echo esc_attr( $id ); ?>"
                   <?php checked( $is_hidden ); ?>>
            <?php echo esc_html( $title ); ?>
            <span style="color: #888; font-size: 12px; margin-left: 4px;"><?php echo esc_html( $id ); ?></span>
            <?php if ( $is_hidden ) : ?>
                <span style="color: #2271b1; font-size: 11px; margin-left: 6px;">[<?php echo esc_html( $hide_context ); ?>]</span>
            <?php endif; ?>
        </label>
        <?php if ( $id === 'my-account' && $is_hidden ) : ?>
            <p class="description" style="margin: 0 0 4px 24px; color: #d63638;">
                <?php esc_html_e( 'Hiding this removes the logout option from the admin bar. Users can still log out via wp-login.php?action=logout.', 'wptransformed' ); ?>
            </p>
        <?php endif; ?>
        <?php if ( $id === 'my-account' && ! $is_hidden ) : ?>
            <p class="description wpt-my-account-warning" style="margin: 0 0 4px 24px; color: #d63638; display: none;">
                <?php esc_html_e( 'Hiding this removes the logout option from the admin bar. Users can still log out via wp-login.php?action=logout.', 'wptransformed' ); ?>
            </p>
            <script>
            (function() {
                var cb = document.querySelector('input[name="wpt_hidden_nodes[]"][value="my-account"]');
                var warn = document.querySelector('.wpt-my-account-warning');
                if (cb && warn) {
                    cb.addEventListener('change', function() { warn.style.display = this.checked ? '' : 'none'; });
                }
            })();
            </script>
        <?php endif; ?>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    /**
     * Sanitize settings from the form submission.
     *
     * The transitional UI submits flat checkbox data. Convert to new format.
     * When the full UI ships (4B), this will handle the complete profile structure.
     */
    public function sanitize_settings( array $raw ): array {
        // Get existing settings to preserve profiles and custom links.
        $existing = $this->get_settings();

        // Handle transitional UI: flat checkbox list → default profile.
        if ( isset( $raw['wpt_hidden_nodes'] ) ) {
            $submitted_nodes = (array) $raw['wpt_hidden_nodes'];
            $new_hidden = [];
            foreach ( $submitted_nodes as $node_id ) {
                $clean_id = sanitize_key( (string) $node_id );
                if ( $clean_id !== '' ) {
                    // Preserve existing context if set, otherwise default to 'both'.
                    $old_context = $existing['profiles']['default']['hidden_nodes'][ $clean_id ] ?? 'both';
                    $new_hidden[ $clean_id ] = $old_context;
                }
            }

            $existing['profiles']['default']['hidden_nodes'] = $new_hidden;
        }

        // Handle full profile data (for 4B UI).
        if ( isset( $raw['wpt_profiles'] ) && is_array( $raw['wpt_profiles'] ) ) {
            $valid_contexts = [ 'admin', 'frontend', 'both' ];
            $sanitized_profiles = [];

            foreach ( $raw['wpt_profiles'] as $role_key => $profile_data ) {
                $role_key = sanitize_key( (string) $role_key );
                $hidden   = [];

                if ( isset( $profile_data['hidden_nodes'] ) && is_array( $profile_data['hidden_nodes'] ) ) {
                    foreach ( $profile_data['hidden_nodes'] as $node_id => $context ) {
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
                $sanitized_profiles['default'] = $existing['profiles']['default'] ?? [ 'hidden_nodes' => [] ];
            }

            $existing['profiles'] = $sanitized_profiles;
        }

        // Handle custom links (for 4B UI).
        if ( isset( $raw['wpt_custom_links'] ) && is_array( $raw['wpt_custom_links'] ) ) {
            $sanitized_links = [];
            $valid_roles     = array_keys( wp_roles()->get_names() );
            $max_links       = 10;
            $count           = 0;

            foreach ( $raw['wpt_custom_links'] as $link ) {
                if ( $count >= $max_links ) {
                    break;
                }
                if ( ! is_array( $link ) ) {
                    continue;
                }

                $title = sanitize_text_field( $link['title'] ?? '' );
                $url   = esc_url_raw( $link['url'] ?? '' );

                // Title and URL are required.
                if ( $title === '' || $url === '' ) {
                    continue;
                }

                $sanitized_links[] = [
                    'title'   => $title,
                    'url'     => $url,
                    'icon'    => sanitize_html_class( $link['icon'] ?? 'dashicons-admin-links' ),
                    'new_tab' => ! empty( $link['new_tab'] ),
                    'position' => in_array( $link['position'] ?? '', [ 'left', 'right' ], true )
                        ? $link['position']
                        : 'left',
                    'roles'   => array_values( array_intersect(
                        array_map( 'sanitize_text_field', (array) ( $link['roles'] ?? [] ) ),
                        $valid_roles
                    ) ),
                ];
                $count++;
            }

            $existing['custom_links'] = $sanitized_links;
        }

        // Ensure top-level structure is always valid.
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
