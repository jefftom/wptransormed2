<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Auto Publish Missed — Automatically publish scheduled posts that WordPress missed.
 *
 * @package WPTransformed
 */
class Auto_Publish_Missed extends Module_Base {

    /** @var string Custom cron hook name. */
    private const CRON_HOOK = 'wpt_check_missed_schedule';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'auto-publish-missed';
    }

    public function get_title(): string {
        return __( 'Auto Publish Missed', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Automatically publish scheduled posts that WordPress missed due to cron failures.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'        => true,
            'check_interval' => 5,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Register custom cron schedule
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

        // Schedule the cron event if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'wpt_missed_schedule_interval', self::CRON_HOOK );
        }

        // Cron callback
        add_action( self::CRON_HOOK, [ $this, 'check_and_publish_missed' ] );

        // Fallback: also check on wp_head for sites with unreliable cron
        add_action( 'wp_head', [ $this, 'fallback_check' ] );

        // Admin notice showing published count
        add_action( 'admin_notices', [ $this, 'show_admin_notice' ] );
    }

    /**
     * Clean up cron on deactivation.
     */
    public function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    // ── Cron Schedule ─────────────────────────────────────────

    /**
     * Register a custom cron interval based on settings.
     *
     * @param array $schedules Existing cron schedules.
     * @return array
     */
    public function add_cron_schedule( array $schedules ): array {
        $settings = $this->get_settings();
        $minutes  = max( 1, absint( $settings['check_interval'] ) );

        $schedules['wpt_missed_schedule_interval'] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf(
                /* translators: %d: number of minutes */
                __( 'Every %d minute(s) (WPTransformed)', 'wptransformed' ),
                $minutes
            ),
        ];

        return $schedules;
    }

    // ── Core: Check and Publish ───────────────────────────────

    /**
     * Query for missed scheduled posts and publish them.
     */
    public function check_and_publish_missed(): void {
        global $wpdb;

        $missed_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_date <= %s LIMIT 100",
                'future',
                current_time( 'mysql' )
            )
        );

        if ( empty( $missed_posts ) ) {
            return;
        }

        $count = 0;

        foreach ( $missed_posts as $missed ) {
            $post_id = (int) $missed->ID;

            $post = get_post( $post_id );
            if ( ! $post || $post->post_status !== 'future' ) {
                continue;
            }

            wp_publish_post( $post_id );
            $count++;
        }

        if ( $count > 0 ) {
            $existing = (int) get_transient( 'wpt_missed_published_count' );
            set_transient( 'wpt_missed_published_count', $existing + $count, HOUR_IN_SECONDS );
        }
    }

    // ── Fallback wp_head Trigger ──────────────────────────────

    /**
     * Fallback check triggered on wp_head for sites where WP-Cron is unreliable.
     * Rate-limited via transient to avoid running on every page load.
     */
    public function fallback_check(): void {
        $settings = $this->get_settings();
        $interval = max( 1, absint( $settings['check_interval'] ) ) * MINUTE_IN_SECONDS;

        if ( get_transient( 'wpt_missed_fallback_lock' ) ) {
            return;
        }

        set_transient( 'wpt_missed_fallback_lock', 1, $interval );

        $this->check_and_publish_missed();
    }

    // ── Admin Notice ──────────────────────────────────────────

    /**
     * Display admin notice if missed posts were recently published.
     */
    public function show_admin_notice(): void {
        $count = (int) get_transient( 'wpt_missed_published_count' );
        if ( ! $count ) {
            return;
        }

        // Only show to users who can edit posts
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        delete_transient( 'wpt_missed_published_count' );

        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: %d: number of posts published */
                    esc_html( _n(
                        'WPTransformed: %d missed scheduled post was automatically published.',
                        'WPTransformed: %d missed scheduled posts were automatically published.',
                        $count,
                        'wptransformed'
                    ) ),
                    $count
                );
                ?>
            </p>
        </div>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
                        <?php esc_html_e( 'Automatically publish missed scheduled posts.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Check Interval', 'wptransformed' ); ?></th>
                <td>
                    <input type="number"
                           name="wpt_check_interval"
                           value="<?php echo esc_attr( (string) $settings['check_interval'] ); ?>"
                           min="1"
                           max="60"
                           step="1"
                           class="small-text" />
                    <?php esc_html_e( 'minutes', 'wptransformed' ); ?>
                    <p class="description"><?php esc_html_e( 'How often to check for missed scheduled posts (1-60 minutes). A wp_head fallback also runs at this interval.', 'wptransformed' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $interval = isset( $raw['wpt_check_interval'] ) ? absint( $raw['wpt_check_interval'] ) : 5;
        $interval = max( 1, min( 60, $interval ) );

        return [
            'enabled'        => ! empty( $raw['wpt_enabled'] ),
            'check_interval' => $interval,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => 'wpt_missed_published_count' ],
            [ 'type' => 'transient', 'key' => 'wpt_missed_fallback_lock' ],
            [ 'type' => 'cron', 'hook' => self::CRON_HOOK ],
        ];
    }
}
