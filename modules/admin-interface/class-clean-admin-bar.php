<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Clean Admin Bar — Remove specific items from the WordPress admin bar.
 *
 * Core logic: hooks wp_before_admin_bar_render at priority 999 and
 * calls $wp_admin_bar->remove_node() for each hidden node ID.
 *
 * Settings page: scans all registered admin bar nodes via admin_bar_menu,
 * stores the list in a transient, and displays them as a checklist.
 *
 * @package WPTransformed
 */
class Clean_Admin_Bar extends Module_Base {

    /**
     * Well-known admin bar node IDs with human-readable labels.
     */
    private const KNOWN_NODES = [
        'wp-logo'      => 'WordPress Logo',
        'site-name'    => 'Site Name',
        'updates'      => 'Updates Counter',
        'comments'     => 'Comments Counter',
        'new-content'  => '+ New Dropdown',
        'edit'         => 'Edit Page/Post Link',
        'my-account'   => 'User Menu (Howdy)',
        'search'       => 'Search Field',
        'top-secondary' => 'Right Side Container',
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
        return __( 'Clean Admin Bar', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Remove or hide specific items from the WordPress admin bar.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'hidden_nodes'  => [],
            'hide_for_roles' => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Remove hidden nodes at very late priority so all nodes are registered.
        add_action( 'wp_before_admin_bar_render', [ $this, 'remove_hidden_nodes' ], 999 );

        // Capture node list when on the WPT settings page for scan.
        add_action( 'admin_bar_menu', [ $this, 'capture_admin_bar_nodes' ], 9999 );

        // Handle the manual scan button.
        add_action( 'admin_init', [ $this, 'handle_scan_action' ] );
    }

    // ── Core Logic: Remove Nodes ──────────────────────────────

    /**
     * Remove hidden admin bar nodes.
     *
     * Fires on wp_before_admin_bar_render at priority 999,
     * after all plugins have added their items.
     */
    public function remove_hidden_nodes(): void {
        global $wp_admin_bar;

        if ( ! $wp_admin_bar instanceof \WP_Admin_Bar ) {
            return;
        }

        // Check role restriction.
        if ( ! $this->should_apply_for_current_user() ) {
            return;
        }

        $settings     = $this->get_settings();
        $hidden_nodes = $settings['hidden_nodes'];

        if ( empty( $hidden_nodes ) ) {
            return;
        }

        foreach ( $hidden_nodes as $node_id ) {
            $wp_admin_bar->remove_node( sanitize_key( $node_id ) );
        }
    }

    // ── Node Scanning ─────────────────────────────────────────

    /**
     * Capture all admin bar nodes on the WPT settings page.
     *
     * Hooks admin_bar_menu at priority 9999 (very late) so all
     * plugins have had a chance to add their nodes.
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

        $node_list = [];
        foreach ( $nodes as $node ) {
            // Only list top-level nodes and first-level children.
            $node_list[ $node->id ] = [
                'id'     => $node->id,
                'title'  => wp_strip_all_tags( (string) $node->title ),
                'parent' => $node->parent ?? '',
            ];
        }

        set_transient( self::TRANSIENT_KEY, $node_list, DAY_IN_SECONDS );
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
     * Get the node list for the settings UI.
     *
     * Returns scanned nodes merged with known fallback nodes.
     *
     * @return array<string, array{id:string,title:string,parent:string}>
     */
    private function get_node_list(): array {
        $scanned = get_transient( self::TRANSIENT_KEY );
        $nodes   = is_array( $scanned ) ? $scanned : [];

        // Merge in known nodes as fallback (scanned data takes priority).
        foreach ( self::KNOWN_NODES as $id => $label ) {
            // Skip multisite-only node on non-multisite.
            if ( $id === 'my-sites' && ! is_multisite() ) {
                continue;
            }

            if ( ! isset( $nodes[ $id ] ) ) {
                $nodes[ $id ] = [
                    'id'     => $id,
                    'title'  => $label,
                    'parent' => '',
                ];
            }
        }

        // Add multisite node to known set if applicable.
        if ( is_multisite() && ! isset( $nodes['my-sites'] ) ) {
            $nodes['my-sites'] = [
                'id'     => 'my-sites',
                'title'  => 'My Sites',
                'parent' => '',
            ];
        }

        return $nodes;
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the current user's role matches the hide_for_roles restriction.
     *
     * If hide_for_roles is empty, applies to all roles.
     */
    private function should_apply_for_current_user(): bool {
        $settings       = $this->get_settings();
        $hide_for_roles = $settings['hide_for_roles'];

        // Empty = applies to all roles.
        if ( empty( $hide_for_roles ) ) {
            return true;
        }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        return ! empty( array_intersect( $user->roles, $hide_for_roles ) );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings     = $this->get_settings();
        $hidden_nodes = $settings['hidden_nodes'];
        $hide_for_roles = $settings['hide_for_roles'];
        $nodes        = $this->get_node_list();

        // Separate top-level nodes from children.
        $top_level = [];
        $children  = [];
        foreach ( $nodes as $id => $node ) {
            if ( empty( $node['parent'] ) ) {
                $top_level[ $id ] = $node;
            } else {
                $children[ $node['parent'] ][ $id ] = $node;
            }
        }

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
                            <?php esc_html_e( 'Check items to hide from the admin bar. Hiding items does not affect permissions — users can still access features via direct URL.', 'wptransformed' ); ?>
                        </p>

                        <?php foreach ( $top_level as $id => $node ) : ?>
                            <?php $this->render_node_checkbox( $id, $node, $hidden_nodes ); ?>

                            <?php if ( isset( $children[ $id ] ) ) : ?>
                                <div style="margin-left: 24px;">
                                    <?php foreach ( $children[ $id ] as $child_id => $child_node ) : ?>
                                        <?php $this->render_node_checkbox( $child_id, $child_node, $hidden_nodes ); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </fieldset>
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
            <tr>
                <th scope="row"><?php esc_html_e( 'Apply to Roles', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php
                        $roles = wp_roles()->get_names();
                        foreach ( $roles as $slug => $name ) : ?>
                            <label>
                                <input type="checkbox" name="wpt_hide_for_roles[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, $hide_for_roles, true ) ); ?>>
                                <?php echo esc_html( translate_user_role( $name ) ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Leave all unchecked to apply to all roles.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render a single admin bar node checkbox row.
     *
     * @param string                   $id           Node ID.
     * @param array{id:string,title:string,parent:string} $node Node data.
     * @param string[]                 $hidden_nodes Currently hidden node IDs.
     */
    private function render_node_checkbox( string $id, array $node, array $hidden_nodes ): void {
        $title   = ! empty( $node['title'] ) ? $node['title'] : $id;
        $checked = in_array( $id, $hidden_nodes, true );
        ?>
        <label style="display: block; margin-bottom: 4px;">
            <input type="checkbox" name="wpt_hidden_nodes[]"
                   value="<?php echo esc_attr( $id ); ?>"
                   <?php checked( $checked ); ?>>
            <?php echo esc_html( $title ); ?>
            <span style="color: #888; font-size: 12px; margin-left: 4px;"><?php echo esc_html( $id ); ?></span>
        </label>
        <?php if ( $id === 'my-account' && $checked ) : ?>
            <p class="description" style="margin: 0 0 4px 24px; color: #d63638;">
                <?php esc_html_e( 'Hiding this removes the logout option from the admin bar. Users can still log out via wp-login.php?action=logout.', 'wptransformed' ); ?>
            </p>
        <?php endif; ?>
        <?php if ( $id === 'my-account' && ! $checked ) : ?>
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

    public function sanitize_settings( array $raw ): array {
        // Hidden nodes: sanitize each ID.
        $submitted_nodes = $raw['wpt_hidden_nodes'] ?? [];
        $hidden_nodes    = array_values( array_map(
            'sanitize_key',
            (array) $submitted_nodes
        ) );

        // Hide for roles: validate against registered roles.
        $valid_roles     = array_keys( wp_roles()->get_names() );
        $submitted_roles = $raw['wpt_hide_for_roles'] ?? [];
        $hide_for_roles  = array_values( array_intersect(
            array_map( 'sanitize_text_field', (array) $submitted_roles ),
            $valid_roles
        ) );

        return [
            'hidden_nodes'   => $hidden_nodes,
            'hide_for_roles' => $hide_for_roles,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => 'wpt_admin_bar_nodes' ],
        ];
    }
}
