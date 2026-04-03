<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Nav New Tab -- Open external custom links in nav menus in a new tab.
 *
 * Automatically adds target="_blank" and rel="noopener noreferrer" to custom
 * link menu items that point to external URLs.
 *
 * @package WPTransformed
 */
class Custom_Nav_New_Tab extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'custom-nav-new-tab';
    }

    public function get_title(): string {
        return __( 'Custom Nav New Tab', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Automatically open external custom links in navigation menus in a new browser tab.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_filter( 'wp_nav_menu_objects', [ $this, 'add_new_tab_to_external_links' ], 10, 2 );
    }

    // -- Hook Callback ----------------------------------------------------

    /**
     * Add target="_blank" and rel="noopener noreferrer" to custom link items
     * that point to an external URL.
     *
     * @param array $items Sorted array of menu item objects.
     * @param object $args  Menu arguments.
     * @return array Modified menu items.
     */
    public function add_new_tab_to_external_links( array $items, $args ): array {
        $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        foreach ( $items as $item ) {
            // Only process custom link items (type = 'custom').
            if ( $item->type !== 'custom' ) {
                continue;
            }

            // Skip items that already have a target set.
            if ( ! empty( $item->target ) ) {
                continue;
            }

            $url = $item->url;

            // Skip empty URLs, anchors, and relative paths.
            if ( empty( $url ) || strpos( $url, '#' ) === 0 || strpos( $url, '/' ) === 0 ) {
                continue;
            }

            $link_host = wp_parse_url( $url, PHP_URL_HOST );

            // If it is an external URL (different host), open in new tab.
            if ( $link_host && $link_host !== $home_host ) {
                $item->target = '_blank';

                // Add noopener noreferrer to the xfn (rel attribute).
                $existing_rel = trim( (string) $item->xfn );
                $rel_parts    = $existing_rel !== '' ? explode( ' ', $existing_rel ) : [];

                if ( ! in_array( 'noopener', $rel_parts, true ) ) {
                    $rel_parts[] = 'noopener';
                }
                if ( ! in_array( 'noreferrer', $rel_parts, true ) ) {
                    $rel_parts[] = 'noreferrer';
                }

                $item->xfn = implode( ' ', $rel_parts );
            }
        }

        return $items;
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Auto New Tab', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_enabled"
                               value="1"
                               <?php checked( $settings['enabled'] ); ?>>
                        <?php esc_html_e( 'Automatically open external custom links in a new tab', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When enabled, any custom link in your navigation menus that points to an external site will automatically get target="_blank" and rel="noopener noreferrer".', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
