<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Cron Manager -- View, run, delete, and add WordPress cron events.
 *
 * Features:
 *  - List all scheduled cron events with hook, schedule, next run, and args
 *  - Run any event immediately via AJAX
 *  - Delete any event via AJAX
 *  - Add new scheduled events via AJAX
 *  - Highlight overdue events (next_run in the past)
 *  - Show notice when DISABLE_WP_CRON is defined
 *  - Optional: hide system/core cron events
 *  - Optional: register custom cron schedules
 *
 * @package WPTransformed
 */
class Cron_Manager extends Module_Base {

    /**
     * Core WordPress cron hooks that ship with every install.
     *
     * @var string[]
     */
    private const SYSTEM_HOOKS = [
        'wp_version_check',
        'wp_update_plugins',
        'wp_update_themes',
        'wp_scheduled_delete',
        'wp_scheduled_auto_draft_delete',
        'delete_expired_transients',
        'wp_privacy_delete_old_export_files',
        'wp_site_health_scheduled_check',
        'recovery_mode_clean_expired_keys',
    ];

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'cron-manager';
    }

    public function get_title(): string {
        return __( 'Cron Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'View, run, delete, and add WordPress scheduled cron events from the admin.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'show_system_crons'      => true,
            'enable_custom_schedules' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        $settings = $this->get_settings();

        // Register custom cron schedules when enabled.
        if ( ! empty( $settings['enable_custom_schedules'] ) ) {
            add_filter( 'cron_schedules', [ $this, 'register_custom_schedules' ] );
        }

        // AJAX handlers -- admin only.
        add_action( 'wp_ajax_wpt_cron_run_now', [ $this, 'ajax_run_now' ] );
        add_action( 'wp_ajax_wpt_cron_delete',  [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_wpt_cron_add',     [ $this, 'ajax_add' ] );
    }

    // -- Custom Schedules -------------------------------------------------

    /**
     * Register commonly-needed custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function register_custom_schedules( array $schedules ): array {
        if ( ! isset( $schedules['wpt_every_5_minutes'] ) ) {
            $schedules['wpt_every_5_minutes'] = [
                'interval' => 300,
                'display'  => __( 'Every 5 Minutes', 'wptransformed' ),
            ];
        }
        if ( ! isset( $schedules['wpt_every_15_minutes'] ) ) {
            $schedules['wpt_every_15_minutes'] = [
                'interval' => 900,
                'display'  => __( 'Every 15 Minutes', 'wptransformed' ),
            ];
        }
        if ( ! isset( $schedules['wpt_every_30_minutes'] ) ) {
            $schedules['wpt_every_30_minutes'] = [
                'interval' => 1800,
                'display'  => __( 'Every 30 Minutes', 'wptransformed' ),
            ];
        }
        if ( ! isset( $schedules['wpt_weekly'] ) ) {
            $schedules['wpt_weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'wptransformed' ),
            ];
        }
        if ( ! isset( $schedules['wpt_monthly'] ) ) {
            $schedules['wpt_monthly'] = [
                'interval' => MONTH_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'wptransformed' ),
            ];
        }
        return $schedules;
    }

    // -- AJAX: Run Now ----------------------------------------------------

    /**
     * Run a cron event immediately.
     */
    public function ajax_run_now(): void {
        check_ajax_referer( 'wpt_cron_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        // Use sanitize_text_field (not sanitize_key) to preserve uppercase hook names.
        $hook      = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
        $args_json = isset( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : '[]';
        $timestamp = isset( $_POST['timestamp'] ) ? absint( $_POST['timestamp'] ) : 0;

        if ( empty( $hook ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid hook name.', 'wptransformed' ) ] );
        }

        // Validate hook exists in current cron schedule.
        $crons = _get_cron_array();
        $hook_exists = false;
        if ( is_array( $crons ) ) {
            foreach ( $crons as $ts => $events ) {
                if ( isset( $events[ $hook ] ) ) {
                    $hook_exists = true;
                    break;
                }
            }
        }
        if ( ! $hook_exists ) {
            wp_send_json_error( [ 'message' => __( 'Hook not found in cron schedule.', 'wptransformed' ) ] );
        }

        $args = json_decode( (string) $args_json, true, 10 );
        if ( ! is_array( $args ) || count( $args, COUNT_RECURSIVE ) > 50 ) {
            $args = [];
        }

        // Execute the hook directly — spawn_cron() is unreliable on WP Engine
        // (loopback HTTP may be blocked or hit 60s timeout).
        do_action_ref_array( $hook, $args );

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: hook name */
                __( 'Event "%s" triggered.', 'wptransformed' ),
                $hook
            ),
        ] );
    }

    // -- AJAX: Delete Event -----------------------------------------------

    /**
     * Delete (unschedule) a cron event.
     */
    public function ajax_delete(): void {
        check_ajax_referer( 'wpt_cron_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $hook      = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
        $args_json = isset( $_POST['args'] ) ? sanitize_text_field( wp_unslash( $_POST['args'] ) ) : '[]';
        $timestamp = isset( $_POST['timestamp'] ) ? absint( $_POST['timestamp'] ) : 0;

        if ( empty( $hook ) || empty( $timestamp ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid event data.', 'wptransformed' ) ] );
        }

        $args = json_decode( $args_json, true );
        if ( ! is_array( $args ) ) {
            $args = [];
        }

        $result = wp_unschedule_event( $timestamp, $hook, $args );

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete event.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: hook name */
                __( 'Event "%s" deleted.', 'wptransformed' ),
                $hook
            ),
        ] );
    }

    // -- AJAX: Add Event --------------------------------------------------

    /**
     * Add a new recurring cron event.
     */
    public function ajax_add(): void {
        check_ajax_referer( 'wpt_cron_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $hook       = isset( $_POST['hook'] ) ? sanitize_key( wp_unslash( $_POST['hook'] ) ) : '';
        $recurrence = isset( $_POST['recurrence'] ) ? sanitize_key( wp_unslash( $_POST['recurrence'] ) ) : '';

        if ( empty( $hook ) ) {
            wp_send_json_error( [ 'message' => __( 'Hook name is required.', 'wptransformed' ) ] );
        }

        if ( empty( $recurrence ) ) {
            wp_send_json_error( [ 'message' => __( 'Schedule is required.', 'wptransformed' ) ] );
        }

        // Validate the recurrence exists.
        $schedules = wp_get_schedules();
        if ( ! isset( $schedules[ $recurrence ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid schedule.', 'wptransformed' ) ] );
        }

        $result = wp_schedule_event( time(), $recurrence, $hook );

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to add event. It may already be scheduled.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %s: hook name */
                __( 'Event "%s" scheduled.', 'wptransformed' ),
                $hook
            ),
        ] );
    }

    // -- Render Settings --------------------------------------------------

    public function render_settings(): void {
        $settings  = $this->get_settings();
        $crons     = _get_cron_array();
        $schedules = wp_get_schedules();
        $now       = time();

        if ( ! is_array( $crons ) ) {
            $crons = [];
        }

        // Build a flat list of events.
        $events = $this->flatten_cron_array( $crons, $settings );

        // Sort by next run ascending.
        usort( $events, static function ( $a, $b ) {
            return $a['timestamp'] <=> $b['timestamp'];
        } );

        ?>
        <!-- DISABLE_WP_CRON notice -->
        <?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
        <div class="notice notice-warning inline" style="margin: 10px 0;">
            <p>
                <strong><?php esc_html_e( 'Notice:', 'wptransformed' ); ?></strong>
                <?php esc_html_e( 'DISABLE_WP_CRON is defined. WordPress built-in cron is disabled. Events will only run if an external cron job calls wp-cron.php.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Module Settings -->
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Show System Crons', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_system_crons" value="1"
                               <?php checked( ! empty( $settings['show_system_crons'] ) ); ?>>
                        <?php esc_html_e( 'Display WordPress core cron events in the list below', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Custom Schedules', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enable_custom_schedules" value="1"
                               <?php checked( ! empty( $settings['enable_custom_schedules'] ) ); ?>>
                        <?php esc_html_e( 'Register additional cron schedules (5 min, 15 min, 30 min, weekly, monthly)', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <hr>

        <!-- Add New Event -->
        <h3><?php esc_html_e( 'Add New Cron Event', 'wptransformed' ); ?></h3>
        <div class="wpt-cron-add-form">
            <label for="wpt-cron-new-hook"><?php esc_html_e( 'Hook Name:', 'wptransformed' ); ?></label>
            <input type="text" id="wpt-cron-new-hook" class="regular-text"
                   placeholder="<?php esc_attr_e( 'my_custom_hook', 'wptransformed' ); ?>">

            <label for="wpt-cron-new-schedule"><?php esc_html_e( 'Schedule:', 'wptransformed' ); ?></label>
            <select id="wpt-cron-new-schedule">
                <?php foreach ( $schedules as $key => $schedule ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>">
                    <?php echo esc_html( $schedule['display'] ); ?> (<?php echo esc_html( $this->human_interval( $schedule['interval'] ) ); ?>)
                </option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="button button-secondary" id="wpt-cron-add-btn">
                <?php esc_html_e( 'Add Event', 'wptransformed' ); ?>
            </button>
            <span class="spinner" id="wpt-cron-add-spinner" style="float: none;"></span>
        </div>

        <hr>

        <!-- Cron Events Table -->
        <h3><?php esc_html_e( 'Scheduled Events', 'wptransformed' ); ?>
            <span class="wpt-cron-count">(<?php echo esc_html( (string) count( $events ) ); ?>)</span>
        </h3>

        <?php if ( empty( $events ) ) : ?>
        <p><?php esc_html_e( 'No cron events found.', 'wptransformed' ); ?></p>
        <?php else : ?>
        <table class="wpt-cron-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Hook Name', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Schedule', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Next Run', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Args', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $events as $event ) : ?>
                <tr class="<?php echo $event['timestamp'] < $now ? 'wpt-cron-overdue' : ''; ?>
                           <?php echo $event['is_system'] ? 'wpt-cron-system' : ''; ?>">
                    <td>
                        <code><?php echo esc_html( $event['hook'] ); ?></code>
                        <?php if ( $event['is_system'] ) : ?>
                            <span class="wpt-cron-badge wpt-cron-badge-system"><?php esc_html_e( 'core', 'wptransformed' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $event['schedule_display'] ); ?></td>
                    <td>
                        <?php
                        $next_run_utc   = $event['timestamp'];
                        $next_run_local = get_date_from_gmt(
                            gmdate( 'Y-m-d H:i:s', $next_run_utc ),
                            'Y-m-d H:i:s'
                        );
                        echo esc_html( $next_run_local );

                        if ( $next_run_utc < $now ) :
                            $overdue_seconds = $now - $next_run_utc;
                            ?>
                            <br><span class="wpt-cron-overdue-label">
                                <?php
                                printf(
                                    /* translators: %s: human-readable time difference */
                                    esc_html__( 'Overdue by %s', 'wptransformed' ),
                                    esc_html( human_time_diff( $next_run_utc, $now ) )
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( ! empty( $event['args'] ) ) : ?>
                            <code class="wpt-cron-args"><?php echo esc_html( wp_json_encode( $event['args'] ) ); ?></code>
                        <?php else : ?>
                            <em><?php esc_html_e( 'none', 'wptransformed' ); ?></em>
                        <?php endif; ?>
                    </td>
                    <td class="wpt-cron-actions">
                        <button type="button" class="button button-small wpt-cron-run"
                                data-hook="<?php echo esc_attr( $event['hook'] ); ?>"
                                data-args="<?php echo esc_attr( wp_json_encode( $event['args'] ) ); ?>"
                                data-timestamp="<?php echo esc_attr( (string) $event['timestamp'] ); ?>">
                            <?php esc_html_e( 'Run Now', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="button button-small button-link-delete wpt-cron-delete"
                                data-hook="<?php echo esc_attr( $event['hook'] ); ?>"
                                data-args="<?php echo esc_attr( wp_json_encode( $event['args'] ) ); ?>"
                                data-timestamp="<?php echo esc_attr( (string) $event['timestamp'] ); ?>">
                            <?php esc_html_e( 'Delete', 'wptransformed' ); ?>
                        </button>
                        <span class="spinner wpt-cron-spinner" style="float: none;"></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Available Schedules Reference -->
        <div class="wpt-cron-schedules-ref" style="margin-top: 20px;">
            <h3>
                <button type="button" class="button button-link" id="wpt-toggle-schedules">
                    <?php esc_html_e( 'Available Schedules', 'wptransformed' ); ?>
                    <span class="dashicons dashicons-arrow-down-alt2" style="vertical-align: middle;"></span>
                </button>
            </h3>
            <div id="wpt-schedules-table" style="display: none;">
                <table class="widefat fixed striped" style="max-width: 500px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Key', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Display', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Interval', 'wptransformed' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $schedules as $key => $schedule ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $key ); ?></code></td>
                            <td><?php echo esc_html( $schedule['display'] ); ?></td>
                            <td><?php echo esc_html( $this->human_interval( $schedule['interval'] ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
    }

    // -- Sanitize Settings ------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        return [
            'show_system_crons'      => ! empty( $raw['wpt_show_system_crons'] ),
            'enable_custom_schedules' => ! empty( $raw['wpt_enable_custom_schedules'] ),
        ];
    }

    // -- Assets -----------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        // Only load on WPTransformed settings page.
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-cron-manager',
            WPT_URL . 'modules/utilities/css/cron-manager.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-cron-manager',
            WPT_URL . 'modules/utilities/js/cron-manager.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-cron-manager', 'wptCronManager', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_cron_nonce' ),
            'i18n'    => [
                'confirmDelete' => __( 'Are you sure you want to delete this cron event?', 'wptransformed' ),
                'confirmRun'    => __( 'Run this event now?', 'wptransformed' ),
                'emptyHook'     => __( 'Please enter a hook name.', 'wptransformed' ),
                'networkError'  => __( 'Network error. Please try again.', 'wptransformed' ),
                'running'       => __( 'Running...', 'wptransformed' ),
                'deleting'      => __( 'Deleting...', 'wptransformed' ),
                'adding'        => __( 'Adding...', 'wptransformed' ),
            ],
        ] );
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Flatten the WordPress cron array into a simple list of events.
     *
     * @param array $crons Raw _get_cron_array() output.
     * @param array $settings Module settings.
     * @return array Flat list of event arrays.
     */
    private function flatten_cron_array( array $crons, array $settings ): array {
        $events = [];

        $schedules      = wp_get_schedules();
        $show_system    = ! empty( $settings['show_system_crons'] );

        foreach ( $crons as $timestamp => $hooks ) {
            if ( ! is_array( $hooks ) ) {
                continue;
            }
            foreach ( $hooks as $hook => $entries ) {
                if ( ! is_array( $entries ) ) {
                    continue;
                }

                $is_system = in_array( $hook, self::SYSTEM_HOOKS, true );

                // Skip system crons if setting is off.
                if ( $is_system && ! $show_system ) {
                    continue;
                }

                foreach ( $entries as $key => $entry ) {
                    $schedule_key = $entry['schedule'] ?? false;
                    if ( $schedule_key && isset( $schedules[ $schedule_key ] ) ) {
                        $schedule_display = $schedules[ $schedule_key ]['display'];
                    } elseif ( $schedule_key ) {
                        $schedule_display = $schedule_key;
                    } else {
                        $schedule_display = __( 'One-time', 'wptransformed' );
                    }

                    $events[] = [
                        'timestamp'        => (int) $timestamp,
                        'hook'             => $hook,
                        'args'             => $entry['args'] ?? [],
                        'schedule'         => $schedule_key ?: '',
                        'schedule_display' => $schedule_display,
                        'interval'         => $entry['interval'] ?? 0,
                        'is_system'        => $is_system,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * Convert seconds to a human-readable interval string.
     *
     * @param int $seconds Number of seconds.
     * @return string E.g. "1 hour", "5 minutes".
     */
    private function human_interval( int $seconds ): string {
        if ( $seconds < 60 ) {
            return sprintf(
                /* translators: %d: number of seconds */
                _n( '%d second', '%d seconds', $seconds, 'wptransformed' ),
                $seconds
            );
        }

        if ( $seconds < HOUR_IN_SECONDS ) {
            $minutes = (int) round( $seconds / MINUTE_IN_SECONDS );
            return sprintf(
                /* translators: %d: number of minutes */
                _n( '%d minute', '%d minutes', $minutes, 'wptransformed' ),
                $minutes
            );
        }

        if ( $seconds < DAY_IN_SECONDS ) {
            $hours = (int) round( $seconds / HOUR_IN_SECONDS );
            return sprintf(
                /* translators: %d: number of hours */
                _n( '%d hour', '%d hours', $hours, 'wptransformed' ),
                $hours
            );
        }

        $days = (int) round( $seconds / DAY_IN_SECONDS );
        return sprintf(
            /* translators: %d: number of days */
            _n( '%d day', '%d days', $days, 'wptransformed' ),
            $days
        );
    }
}
