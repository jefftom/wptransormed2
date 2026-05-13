<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Self Pingbacks — Prevent WordPress from pinging your own site.
 *
 * Iterates the pre_ping links array and removes any that point to
 * the site's home URL.
 *
 * @package WPTransformed
 */
class Disable_Self_Pingbacks extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-self-pingbacks';
    }

    public function get_title(): string {
        return __( 'Disable Self Pingbacks', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Prevent WordPress from sending pingback requests to your own site.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'pre_ping', [ $this, 'remove_self_pings' ] );
    }

    /**
     * Remove self-referencing URLs from the pingback links array.
     *
     * @param array &$links Array of URLs to ping (passed by reference).
     */
    public function remove_self_pings( array &$links ): void {
        $home_url = get_option( 'home' );

        foreach ( $links as $key => $link ) {
            if ( strpos( $link, $home_url ) === 0 ) {
                unset( $links[ $key ] );
            }
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Self Pingbacks', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_enabled"
                               value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Prevent self-referencing pingbacks', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
