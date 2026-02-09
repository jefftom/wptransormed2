<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Heartbeat Control — Control the WordPress Heartbeat API frequency
 * or disable it entirely on specific admin pages / frontend.
 *
 * Three contexts:
 *  - Dashboard  (all admin pages except post editor)
 *  - Post Editor (post.php, post-new.php)
 *  - Frontend   (all non-admin pages)
 *
 * Each context can be: default | 15 | 30 | 60 | 120 | disabled
 *
 * @package WPTransformed
 */
class Heartbeat_Control extends Module_Base {

    /**
     * Valid frequency values for settings.
     */
    private const VALID_VALUES = [ 'default', '15', '30', '60', '120', 'disabled' ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'heartbeat-control';
    }

    public function get_title(): string {
        return __( 'Heartbeat Control', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Control the WordPress Heartbeat API frequency to reduce server load or disable it entirely.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'dashboard'   => 'default',
            'post_editor' => 'default',
            'frontend'    => 'disabled',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Deregister heartbeat script in admin if disabled for current context.
        add_action( 'admin_enqueue_scripts', [ $this, 'maybe_disable_admin_heartbeat' ], 99 );

        // Deregister heartbeat script on frontend if disabled.
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_disable_frontend_heartbeat' ], 99 );

        // Modify heartbeat interval via the heartbeat_settings filter.
        add_filter( 'heartbeat_settings', [ $this, 'modify_heartbeat_interval' ] );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Deregister heartbeat script in admin if disabled for this context.
     */
    public function maybe_disable_admin_heartbeat(): void {
        $context  = $this->get_admin_context();
        $settings = $this->get_settings();
        $value    = $settings[ $context ] ?? 'default';

        if ( $value === 'disabled' ) {
            wp_deregister_script( 'heartbeat' );
        }
    }

    /**
     * Deregister heartbeat script on the frontend if disabled.
     */
    public function maybe_disable_frontend_heartbeat(): void {
        $settings = $this->get_settings();

        if ( ( $settings['frontend'] ?? 'default' ) === 'disabled' ) {
            wp_deregister_script( 'heartbeat' );
        }
    }

    /**
     * Modify the heartbeat interval for the current context.
     *
     * @param array<string,mixed> $heartbeat_settings Heartbeat settings from WP.
     * @return array<string,mixed>
     */
    public function modify_heartbeat_interval( array $heartbeat_settings ): array {
        $context  = $this->get_current_context();
        $settings = $this->get_settings();
        $value    = $settings[ $context ] ?? 'default';

        if ( $value !== 'default' && $value !== 'disabled' ) {
            $heartbeat_settings['interval'] = (int) $value;
        }

        return $heartbeat_settings;
    }

    // ── Context Detection ─────────────────────────────────────

    /**
     * Determine the current context across admin and frontend.
     *
     * @return string 'dashboard' | 'post_editor' | 'frontend'
     */
    private function get_current_context(): string {
        if ( ! is_admin() ) {
            return 'frontend';
        }

        return $this->get_admin_context();
    }

    /**
     * Determine the admin context — post editor vs dashboard.
     *
     * @return string 'post_editor' | 'dashboard'
     */
    private function get_admin_context(): string {
        global $pagenow;

        if ( $pagenow === 'post.php' || $pagenow === 'post-new.php' ) {
            return 'post_editor';
        }

        return 'dashboard';
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        $contexts = [
            'dashboard' => [
                'label'       => __( 'Dashboard', 'wptransformed' ),
                'description' => __( 'All admin pages except the post editor. Heartbeat powers real-time dashboard widget updates.', 'wptransformed' ),
                'warning'     => '',
            ],
            'post_editor' => [
                'label'       => __( 'Post Editor', 'wptransformed' ),
                'description' => __( 'The post/page editing screen (post.php, post-new.php). Heartbeat powers auto-save and post locking.', 'wptransformed' ),
                'warning'     => __( 'Disabling Heartbeat in the post editor will disable auto-save and post locking (the feature that warns when two users edit the same post). We recommend setting it to 60 or 120 seconds instead of disabling it entirely.', 'wptransformed' ),
            ],
            'frontend' => [
                'label'       => __( 'Frontend', 'wptransformed' ),
                'description' => __( 'The Heartbeat API runs on every frontend page for logged-in users. Disabling it reduces server load.', 'wptransformed' ),
                'warning'     => '',
            ],
        ];

        $frequency_options = [
            'default'  => __( 'WordPress Default', 'wptransformed' ),
            '15'       => __( '15 seconds', 'wptransformed' ),
            '30'       => __( '30 seconds', 'wptransformed' ),
            '60'       => __( '60 seconds', 'wptransformed' ),
            '120'      => __( '120 seconds', 'wptransformed' ),
            'disabled' => __( 'Disabled', 'wptransformed' ),
        ];
        ?>

        <div class="wpt-heartbeat-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'The Heartbeat API sends requests to your server at regular intervals. It powers auto-save, post locking, and real-time dashboard updates. Reducing the frequency or disabling it on pages where it\'s not needed can significantly reduce server load, especially on shared hosting.', 'wptransformed' ); ?>
            </p>
        </div>

        <?php $this->render_impact_summary( $settings ); ?>

        <div class="wpt-heartbeat-contexts" style="display: grid; gap: 16px; margin-top: 16px;">
            <?php foreach ( $contexts as $key => $ctx ) : ?>
                <?php $current_value = $settings[ $key ] ?? 'default'; ?>
                <div class="wpt-heartbeat-card" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 16px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 14px;">
                        <?php echo esc_html( $ctx['label'] ); ?>
                    </h3>
                    <p class="description" style="margin: 0 0 12px 0;">
                        <?php echo esc_html( $ctx['description'] ); ?>
                    </p>

                    <fieldset>
                        <?php foreach ( $frequency_options as $value => $label ) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="radio"
                                       name="wpt_<?php echo esc_attr( $key ); ?>"
                                       value="<?php echo esc_attr( $value ); ?>"
                                       <?php checked( $current_value, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <?php if ( $ctx['warning'] !== '' ) : ?>
                        <div class="wpt-heartbeat-warning" style="background: #fcf9e8; border-left: 4px solid #dba617; padding: 8px 12px; margin-top: 12px;">
                            <p style="margin: 0;">
                                <strong><?php esc_html_e( 'Warning:', 'wptransformed' ); ?></strong>
                                <?php echo esc_html( $ctx['warning'] ); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a summary bar showing current impact.
     *
     * @param array<string,string> $settings Current settings.
     */
    private function render_impact_summary( array $settings ): void {
        $disabled_count = 0;
        $modified_count = 0;

        foreach ( [ 'dashboard', 'post_editor', 'frontend' ] as $ctx ) {
            $val = $settings[ $ctx ] ?? 'default';
            if ( $val === 'disabled' ) {
                $disabled_count++;
            } elseif ( $val !== 'default' ) {
                $modified_count++;
            }
        }

        $parts = [];
        if ( $disabled_count > 0 ) {
            /* translators: %d: number of contexts where heartbeat is disabled */
            $parts[] = sprintf( _n( '%d context disabled', '%d contexts disabled', $disabled_count, 'wptransformed' ), $disabled_count );
        }
        if ( $modified_count > 0 ) {
            /* translators: %d: number of contexts with modified frequency */
            $parts[] = sprintf( _n( '%d frequency modified', '%d frequencies modified', $modified_count, 'wptransformed' ), $modified_count );
        }

        if ( empty( $parts ) ) {
            $summary = __( 'All contexts using WordPress defaults', 'wptransformed' );
            $color   = '#ddd';
        } else {
            $summary = implode( ', ', $parts );
            $color   = '#2271b1';
        }
        ?>
        <div class="wpt-heartbeat-summary" style="background: #fff; border: 1px solid #ddd; border-left: 4px solid <?php echo esc_attr( $color ); ?>; border-radius: 4px; padding: 12px 16px;">
            <strong><?php esc_html_e( 'Server Impact:', 'wptransformed' ); ?></strong>
            <?php echo esc_html( $summary ); ?>
        </div>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $clean = [];

        foreach ( [ 'dashboard', 'post_editor', 'frontend' ] as $ctx ) {
            $value = $raw[ 'wpt_' . $ctx ] ?? 'default';

            if ( ! in_array( $value, self::VALID_VALUES, true ) ) {
                $value = 'default';
            }

            $clean[ $ctx ] = $value;
        }

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No custom CSS or JS — pure server-rendered settings UI.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
