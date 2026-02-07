<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Hide Admin Notices — Collapse all admin notices into a togglable panel.
 *
 * Uses CSS to instantly hide notices (no flash), then JS moves them
 * into a collapsible panel. No output buffering.
 *
 * @package WPTransformed
 */
class Hide_Admin_Notices extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'hide-admin-notices';
    }

    public function get_title(): string {
        return __( 'Hide Admin Notices', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Collapse all admin notices into a single togglable panel so they don\'t clutter the admin.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'scope'              => 'all',
            'show_count_badge'   => true,
            'auto_expand_errors' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // No hooks needed — everything runs through enqueue_admin_assets.
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! $this->should_run( $hook ) ) {
            return;
        }

        // CSS file for bar/panel styling
        wp_enqueue_style(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/css/hide-admin-notices.css',
            [],
            WPT_VERSION
        );

        // Inline CSS that immediately hides notices (no flash)
        $hide_css = '.wrap > .notice, .wrap > .updated, .wrap > .error, .wrap > .update-nag,'
            . ' #wpbody-content > .notice, #wpbody-content > .updated,'
            . ' #wpbody-content > .error, #wpbody-content > .update-nag'
            . ' { display: none !important; }';
        wp_add_inline_style( 'wpt-hide-admin-notices', $hide_css );

        // JS that collects hidden notices and builds the toggle panel
        wp_enqueue_script(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/js/hide-admin-notices.js',
            [],
            WPT_VERSION,
            true
        );

        $settings = $this->get_settings();
        wp_localize_script( 'wpt-hide-admin-notices', 'wptHideNotices', [
            'autoExpandErrors' => ! empty( $settings['auto_expand_errors'] ),
            'showCountBadge'   => ! empty( $settings['show_count_badge'] ),
            'i18n'             => [
                'show'       => __( 'Show', 'wptransformed' ),
                'hide'       => __( 'Hide', 'wptransformed' ),
                'notices'    => __( 'Notices', 'wptransformed' ),
                /* translators: %d: number of notices */
                'oneNotice'  => __( '%d notice', 'wptransformed' ),
                /* translators: %d: number of notices */
                'manyNotices' => __( '%d notices', 'wptransformed' ),
                'dismissAll' => __( 'Dismiss All', 'wptransformed' ),
            ],
        ] );
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if the module should run on this page based on scope setting.
     */
    private function should_run( string $hook ): bool {
        $settings = $this->get_settings();

        if ( $settings['scope'] === 'wpt-only' ) {
            return ( strpos( $hook, 'wptransformed' ) !== false );
        }

        return true; // scope = 'all'
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">

            <tr>
                <th scope="row"><?php esc_html_e( 'Scope', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="radio" name="wpt_scope" value="all" <?php checked( $settings['scope'], 'all' ); ?>>
                            <?php esc_html_e( 'Hide notices on all admin pages', 'wptransformed' ); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="wpt_scope" value="wpt-only" <?php checked( $settings['scope'], 'wpt-only' ); ?>>
                            <?php esc_html_e( 'Only on WPTransformed pages', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Auto-expand for errors', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_auto_expand_errors" value="1" <?php checked( $settings['auto_expand_errors'] ); ?>>
                        <?php esc_html_e( 'Automatically show the notice panel if there are error-level notices.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Show count badge', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_count_badge" value="1" <?php checked( $settings['show_count_badge'] ); ?>>
                        <?php esc_html_e( 'Show the number of hidden notices in the collapsed bar.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_scopes = [ 'all', 'wpt-only' ];

        return [
            'scope'              => in_array( $raw['wpt_scope'] ?? '', $valid_scopes, true )
                                    ? $raw['wpt_scope'] : 'all',
            'show_count_badge'   => ! empty( $raw['wpt_show_count_badge'] ),
            'auto_expand_errors' => ! empty( $raw['wpt_auto_expand_errors'] ),
        ];
    }
}
