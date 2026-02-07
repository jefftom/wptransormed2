<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Hide Admin Notices — Collapse all admin notices into a togglable panel.
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
        add_action( 'admin_notices', [ $this, 'start_capture' ], 1 );
        add_action( 'all_admin_notices', [ $this, 'end_capture' ], PHP_INT_MAX );
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! $this->should_run( $hook ) ) {
            return;
        }

        wp_enqueue_style(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/css/hide-admin-notices.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-hide-admin-notices',
            WPT_URL . 'modules/admin-interface/js/hide-admin-notices.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-hide-admin-notices', 'wptHideNoticesI18n', [
            'show' => __( 'Show', 'wptransformed' ),
            'hide' => __( 'Hide', 'wptransformed' ),
        ] );
    }

    // ── Output Buffering ──────────────────────────────────────

    /**
     * Start capturing notices. Hooked at admin_notices priority 1.
     */
    public function start_capture(): void {
        $settings = $this->get_settings();

        // Determine current hook suffix to check scope
        if ( ! $this->should_run_on_current_page() ) {
            return;
        }

        ob_start();
    }

    /**
     * End capture and render the collapsed bar + hidden panel.
     * Hooked at all_admin_notices priority PHP_INT_MAX.
     */
    public function end_capture(): void {
        if ( ! $this->should_run_on_current_page() ) {
            return;
        }

        // Check that we actually have a buffer level from our ob_start
        if ( ob_get_level() < 1 ) {
            return;
        }

        $notices_html = ob_get_clean();

        // Nothing captured or only whitespace
        if ( empty( trim( $notices_html ) ) ) {
            return;
        }

        // Count notices
        $count = preg_match_all( '/<div[^>]*class="[^"]*\bnotice\b[^"]*"/', $notices_html );

        // If no actual .notice divs found, output the HTML as-is
        if ( $count === 0 ) {
            echo $notices_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        $settings    = $this->get_settings();
        $has_errors  = ( strpos( $notices_html, 'notice-error' ) !== false );
        $auto_expand = ! empty( $settings['auto_expand_errors'] ) && $has_errors;
        $show_badge  = ! empty( $settings['show_count_badge'] );

        // Badge text
        $badge_text = $show_badge
            /* translators: %d: number of notices */
            ? sprintf( _n( '%d notice', '%d notices', $count, 'wptransformed' ), $count )
            : __( 'Notices', 'wptransformed' );

        // Render collapsed bar
        ?>
        <div class="wpt-notice-bar<?php echo $auto_expand ? ' wpt-notice-bar-expanded' : ''; ?>"
             data-auto-expand="<?php echo $auto_expand ? '1' : '0'; ?>">
            <span class="wpt-notice-bar-label">
                <?php echo esc_html( $badge_text ); ?>
            </span>
            <button type="button" class="wpt-notice-toggle">
                <?php echo $auto_expand ? esc_html__( 'Hide', 'wptransformed' ) : esc_html__( 'Show', 'wptransformed' ); ?>
            </button>
        </div>
        <div class="wpt-notice-panel" style="<?php echo $auto_expand ? '' : 'display:none;'; ?>">
            <?php echo $notices_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — original WP notices ?>
            <div class="wpt-notice-panel-footer">
                <button type="button" class="button wpt-dismiss-all"><?php esc_html_e( 'Dismiss All', 'wptransformed' ); ?></button>
            </div>
        </div>
        <?php
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

    /**
     * Check scope without the $hook parameter (for output buffering hooks
     * which don't receive the hook suffix).
     */
    private function should_run_on_current_page(): bool {
        $settings = $this->get_settings();

        if ( $settings['scope'] === 'wpt-only' ) {
            $screen = get_current_screen();
            if ( $screen && strpos( $screen->id, 'wptransformed' ) !== false ) {
                return true;
            }
            return false;
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
