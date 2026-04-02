<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Duplicate Menu -- One-click duplication of WordPress navigation menus.
 *
 * @package WPTransformed
 */
class Duplicate_Menu extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'duplicate-menu';
    }

    public function get_title(): string {
        return __( 'Duplicate Menu', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'One-click duplication of navigation menus with full hierarchy preservation.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'admin_footer-nav-menus.php', [ $this, 'inject_duplicate_button' ] );
        add_action( 'wp_ajax_wpt_duplicate_menu', [ $this, 'ajax_duplicate_menu' ] );
    }

    // -- Inject Button --

    /**
     * Inject "Duplicate Menu" button into the nav menus page footer via JS.
     */
    public function inject_duplicate_button(): void {
        ?>
        <script>
        (function() {
            "use strict";

            var menuHeader = document.querySelector('.menu-edit .manage-menus');
            if (!menuHeader) return;

            var menuIdInput = document.getElementById('menu');
            if (!menuIdInput || !menuIdInput.value) return;

            var menuId = menuIdInput.value;

            // Create duplicate button
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button wpt-duplicate-menu-btn';
            btn.textContent = <?php echo wp_json_encode( __( 'Duplicate This Menu', 'wptransformed' ) ); ?>;
            btn.style.marginLeft = '8px';

            btn.addEventListener('click', function() {
                btn.disabled = true;
                btn.textContent = <?php echo wp_json_encode( __( 'Duplicating...', 'wptransformed' ) ); ?>;

                var data = new FormData();
                data.append('action', 'wpt_duplicate_menu');
                data.append('menu_id', menuId);
                data.append('wpt_nonce', <?php echo wp_json_encode( wp_create_nonce( 'wpt_duplicate_menu' ) ); ?>);

                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                })
                .then(function(resp) { return resp.json(); })
                .then(function(result) {
                    if (result.success) {
                        window.location.href = result.data.edit_url;
                    } else {
                        btn.disabled = false;
                        btn.textContent = <?php echo wp_json_encode( __( 'Duplicate This Menu', 'wptransformed' ) ); ?>;
                        window.alert(result.data && result.data.message ? result.data.message : <?php echo wp_json_encode( __( 'Duplication failed.', 'wptransformed' ) ); ?>);
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = <?php echo wp_json_encode( __( 'Duplicate This Menu', 'wptransformed' ) ); ?>;
                    window.alert(<?php echo wp_json_encode( __( 'An error occurred.', 'wptransformed' ) ); ?>);
                });
            });

            // Insert button after the menu selector
            var saveBtn = document.querySelector('.menu-edit .submit input[type="submit"], .menu-edit .major-publishing-actions .publishing-action input');
            if (saveBtn) {
                saveBtn.parentNode.insertBefore(btn, saveBtn.nextSibling);
            } else {
                menuHeader.appendChild(btn);
            }
        })();
        </script>
        <?php
    }

    // -- AJAX Handler --

    /**
     * Duplicate a nav menu via AJAX.
     */
    public function ajax_duplicate_menu(): void {
        check_ajax_referer( 'wpt_duplicate_menu', 'wpt_nonce' );

        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $menu_id = isset( $_POST['menu_id'] ) ? absint( $_POST['menu_id'] ) : 0;
        if ( ! $menu_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid menu ID.', 'wptransformed' ) ] );
        }

        $source_menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $source_menu ) {
            wp_send_json_error( [ 'message' => __( 'Source menu not found.', 'wptransformed' ) ] );
        }

        // Create new menu with suffixed name
        $new_name = $source_menu->name . ' ' . __( '(Copy)', 'wptransformed' );

        // Ensure unique name
        $counter = 1;
        while ( wp_get_nav_menu_object( $new_name ) ) {
            $counter++;
            $new_name = $source_menu->name . ' ' . sprintf(
                /* translators: %d: copy number */
                __( '(Copy %d)', 'wptransformed' ),
                $counter
            );
        }

        $new_menu_id = wp_create_nav_menu( $new_name );
        if ( is_wp_error( $new_menu_id ) ) {
            wp_send_json_error( [ 'message' => $new_menu_id->get_error_message() ] );
        }

        // Get source items
        $source_items = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'any' ] );
        if ( empty( $source_items ) ) {
            wp_send_json_success( [
                'menu_id'  => $new_menu_id,
                'edit_url' => admin_url( 'nav-menus.php?action=edit&menu=' . $new_menu_id ),
            ] );
        }

        // Map old item IDs to new item IDs (for parent preservation)
        $id_map = [];

        // Sort by menu_order to process parents first
        usort( $source_items, function( $a, $b ) {
            return (int) $a->menu_order - (int) $b->menu_order;
        } );

        foreach ( $source_items as $item ) {
            $args = [
                'menu-item-object-id'   => $item->object_id,
                'menu-item-object'      => $item->object,
                'menu-item-type'        => $item->type,
                'menu-item-title'       => $item->title,
                'menu-item-url'         => $item->url,
                'menu-item-description' => $item->description,
                'menu-item-attr-title'  => $item->attr_title,
                'menu-item-target'      => $item->target,
                'menu-item-classes'     => is_array( $item->classes ) ? implode( ' ', $item->classes ) : '',
                'menu-item-xfn'         => $item->xfn,
                'menu-item-position'    => $item->menu_order,
                'menu-item-status'      => 'publish',
            ];

            // Map parent ID
            $old_parent = (int) $item->menu_item_parent;
            if ( $old_parent && isset( $id_map[ $old_parent ] ) ) {
                $args['menu-item-parent-id'] = $id_map[ $old_parent ];
            }

            $new_item_id = wp_update_nav_menu_item( $new_menu_id, 0, $args );

            if ( ! is_wp_error( $new_item_id ) ) {
                $id_map[ (int) $item->ID ] = $new_item_id;
            }
        }

        wp_send_json_success( [
            'menu_id'  => $new_menu_id,
            'edit_url' => admin_url( 'nav-menus.php?action=edit&menu=' . $new_menu_id ),
        ] );
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
                        <?php esc_html_e( 'Add "Duplicate This Menu" button to the nav menus screen.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
