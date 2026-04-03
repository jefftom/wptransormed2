<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Temporary User Access -- Generate time-limited admin login links.
 *
 * Features:
 *  - Admin page: "Generate Access Link" form
 *  - Creates temporary user with random username wpt_temp_{hash}
 *  - Stores expiration in user meta _wpt_temp_expires
 *  - Stores access token in user meta _wpt_temp_token
 *  - Login URL: site.com/?wpt_temp_access={token}
 *  - init hook: validate token, set auth cookie, redirect to admin
 *  - Daily cron: delete expired temp users
 *  - Admin table: active links with remaining time, role, revoke button
 *  - AJAX for generate and revoke
 *
 * @package WPTransformed
 */
class Temporary_User_Access extends Module_Base {

    /** @var string Cron hook name. */
    private const CRON_HOOK = 'wpt_cleanup_temp_users';

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'temporary-user-access';
    }

    public function get_title(): string {
        return __( 'Temporary User Access', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Generate time-limited login links for temporary admin or support access.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'default_role'     => 'administrator',
            'default_duration' => 24,
            'max_duration'     => 168,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        // Handle temp access login on init (both admin and front-end).
        add_action( 'init', [ $this, 'handle_temp_login' ] );

        // Schedule daily cleanup cron.
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, [ $this, 'cleanup_expired_users' ] );

        // Admin page and AJAX.
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
            add_action( 'wp_ajax_wpt_temp_access_generate', [ $this, 'ajax_generate' ] );
            add_action( 'wp_ajax_wpt_temp_access_revoke', [ $this, 'ajax_revoke' ] );
        }
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

    // -- Temp Login Handler -----------------------------------------------

    /**
     * Validate a temporary access token and log the user in.
     */
    public function handle_temp_login(): void {
        if ( ! isset( $_GET['wpt_temp_access'] ) ) {
            return;
        }

        $token = sanitize_text_field( wp_unslash( $_GET['wpt_temp_access'] ) );
        if ( empty( $token ) || strlen( $token ) < 32 ) {
            wp_die(
                esc_html__( 'Invalid access link.', 'wptransformed' ),
                esc_html__( 'Access Denied', 'wptransformed' ),
                [ 'response' => 403 ]
            );
        }

        // Find user by token.
        $users = get_users( [
            'meta_key'   => '_wpt_temp_token',
            'meta_value' => $token,
            'number'     => 1,
        ] );

        if ( empty( $users ) ) {
            wp_die(
                esc_html__( 'Invalid or expired access link.', 'wptransformed' ),
                esc_html__( 'Access Denied', 'wptransformed' ),
                [ 'response' => 403 ]
            );
        }

        $user = $users[0];

        // Check expiration.
        $expires = (int) get_user_meta( $user->ID, '_wpt_temp_expires', true );
        if ( $expires < time() ) {
            // Expired -- clean up the user.
            $this->delete_temp_user( $user->ID );
            wp_die(
                esc_html__( 'This access link has expired.', 'wptransformed' ),
                esc_html__( 'Access Expired', 'wptransformed' ),
                [ 'response' => 403 ]
            );
        }

        // Log the user in.
        wp_set_auth_cookie( $user->ID, false );
        wp_set_current_user( $user->ID );
        do_action( 'wp_login', $user->user_login, $user );

        wp_safe_redirect( admin_url() );
        exit;
    }

    // -- Cron: Cleanup Expired Users --------------------------------------

    /**
     * Delete all expired temporary users.
     */
    public function cleanup_expired_users(): void {
        $users = get_users( [
            'meta_key'     => '_wpt_temp_expires',
            'meta_compare' => '<',
            'meta_value'   => time(),
            'meta_type'    => 'NUMERIC',
            'number'       => 50,
        ] );

        foreach ( $users as $user ) {
            // Double-check this is a temp user.
            if ( $this->is_temp_user( $user->ID ) ) {
                $this->delete_temp_user( $user->ID );
            }
        }
    }

    // -- Admin Page -------------------------------------------------------

    /**
     * Register admin submenu page.
     */
    public function register_admin_page(): void {
        $hook = add_submenu_page(
            'wptransformed',
            __( 'Temporary Access', 'wptransformed' ),
            __( 'Temporary Access', 'wptransformed' ),
            'manage_options',
            'wpt-temporary-access',
            [ $this, 'render_admin_page' ]
        );

        // Assets are inlined in render_admin_page; no external files to enqueue.
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wptransformed' ) );
        }

        $settings   = $this->get_settings();
        $temp_users = $this->get_active_temp_users();
        $nonce      = wp_create_nonce( 'wpt_temp_access' );
        $roles      = wp_roles()->get_names();
        ?>
        <div class="wrap" id="wpt-temp-access">
            <h1><?php esc_html_e( 'Temporary User Access', 'wptransformed' ); ?></h1>

            <style>
                .wpt-ta-generate { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 15px 0; }
                .wpt-ta-generate h2 { margin-top: 0; }
                .wpt-ta-form { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
                .wpt-ta-form label { display: block; margin-bottom: 5px; font-weight: 600; }
                .wpt-ta-result { margin-top: 10px; padding: 10px; background: #e7f3e7; border: 1px solid #46b450; border-radius: 3px; display: none; }
                .wpt-ta-result input { width: 100%; font-family: monospace; margin-top: 5px; }
                .wpt-ta-table { margin-top: 15px; }
                .wpt-ta-expired { color: #dc3232; font-weight: 600; }
                .wpt-ta-active { color: #46b450; font-weight: 600; }
            </style>

            <div class="wpt-ta-generate">
                <h2><?php esc_html_e( 'Generate Access Link', 'wptransformed' ); ?></h2>
                <div class="wpt-ta-form">
                    <div>
                        <label for="wpt-ta-role"><?php esc_html_e( 'Role', 'wptransformed' ); ?></label>
                        <select id="wpt-ta-role">
                            <?php foreach ( $roles as $role_slug => $role_name ) : ?>
                                <option value="<?php echo esc_attr( $role_slug ); ?>"
                                    <?php selected( $settings['default_role'], $role_slug ); ?>>
                                    <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="wpt-ta-duration"><?php esc_html_e( 'Duration (hours)', 'wptransformed' ); ?></label>
                        <input type="number" id="wpt-ta-duration"
                               value="<?php echo esc_attr( (string) $settings['default_duration'] ); ?>"
                               min="1" max="<?php echo esc_attr( (string) $settings['max_duration'] ); ?>"
                               class="small-text">
                    </div>
                    <div>
                        <button type="button" id="wpt-ta-generate-btn" class="button button-primary">
                            <?php esc_html_e( 'Generate Link', 'wptransformed' ); ?>
                        </button>
                    </div>
                </div>
                <div class="wpt-ta-result" id="wpt-ta-result">
                    <strong><?php esc_html_e( 'Access Link (copy and share):', 'wptransformed' ); ?></strong>
                    <input type="text" id="wpt-ta-link" readonly onclick="this.select();">
                </div>
            </div>

            <?php if ( ! empty( $temp_users ) ) : ?>
            <div class="wpt-ta-table">
                <h2><?php esc_html_e( 'Active Temporary Users', 'wptransformed' ); ?></h2>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Username', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Role', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Expires', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Remaining', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $temp_users as $tu ) :
                            $expires   = (int) get_user_meta( $tu->ID, '_wpt_temp_expires', true );
                            $remaining = $expires - time();
                            $is_active = $remaining > 0;
                            $user_roles = $tu->roles;
                            $role_name  = ! empty( $user_roles ) ? translate_user_role( ucfirst( reset( $user_roles ) ) ) : '---';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $tu->user_login ); ?></td>
                            <td><?php echo esc_html( $role_name ); ?></td>
                            <td><?php echo esc_html( $tu->user_registered ); ?></td>
                            <td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $expires ) ); ?></td>
                            <td>
                                <?php if ( $is_active ) : ?>
                                    <span class="wpt-ta-active"><?php echo esc_html( $this->format_remaining( $remaining ) ); ?></span>
                                <?php else : ?>
                                    <span class="wpt-ta-expired"><?php esc_html_e( 'Expired', 'wptransformed' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button wpt-ta-revoke" data-user-id="<?php echo esc_attr( (string) $tu->ID ); ?>">
                                    <?php esc_html_e( 'Revoke', 'wptransformed' ); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            'use strict';

            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var maxDur  = <?php echo (int) $settings['max_duration']; ?>;

            var genBtn = document.getElementById('wpt-ta-generate-btn');
            if (genBtn) {
                genBtn.addEventListener('click', function() {
                    var role     = document.getElementById('wpt-ta-role').value;
                    var duration = parseInt(document.getElementById('wpt-ta-duration').value, 10);
                    if (duration > maxDur) duration = maxDur;
                    if (duration < 1) duration = 1;

                    var data = new FormData();
                    data.append('action', 'wpt_temp_access_generate');
                    data.append('_nonce', nonce);
                    data.append('role', role);
                    data.append('duration', duration);

                    fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.success) {
                                var result = document.getElementById('wpt-ta-result');
                                var link   = document.getElementById('wpt-ta-link');
                                result.style.display = 'block';
                                link.value = res.data.url;
                                link.select();
                            } else {
                                alert(res.data && res.data.message ? res.data.message : 'Error generating link.');
                            }
                        });
                });
            }

            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.wpt-ta-revoke');
                if (!btn) return;

                if (!confirm(<?php echo wp_json_encode( __( 'Are you sure you want to revoke this access?', 'wptransformed' ) ); ?>)) return;

                var userId = btn.getAttribute('data-user-id');
                var data   = new FormData();
                data.append('action', 'wpt_temp_access_revoke');
                data.append('_nonce', nonce);
                data.append('user_id', userId);

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            var row = btn.closest('tr');
                            if (row) row.remove();
                        }
                    });
            });
        })();
        </script>
        <?php
    }

    // -- AJAX Handlers ----------------------------------------------------

    /**
     * AJAX: Generate a new temporary access link.
     */
    public function ajax_generate(): void {
        check_ajax_referer( 'wpt_temp_access', '_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $settings = $this->get_settings();
        $role     = isset( $_POST['role'] ) ? sanitize_key( $_POST['role'] ) : $settings['default_role'];
        $duration = isset( $_POST['duration'] ) ? absint( $_POST['duration'] ) : $settings['default_duration'];

        // Validate role exists.
        if ( ! wp_roles()->is_role( $role ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid role.', 'wptransformed' ) ] );
        }

        // Clamp duration.
        $max_dur  = max( 1, absint( $settings['max_duration'] ) );
        $duration = max( 1, min( $max_dur, $duration ) );

        // Generate random credentials.
        $hash     = wp_generate_password( 12, false );
        $username = 'wpt_temp_' . $hash;
        $email    = $username . '@' . wp_parse_url( site_url(), PHP_URL_HOST );
        $password = wp_generate_password( 24, true );
        $token    = wp_generate_password( 48, false );

        // Create user.
        $user_id = wp_insert_user( [
            'user_login' => $username,
            'user_email' => sanitize_email( $email ),
            'user_pass'  => $password,
            'role'       => $role,
        ] );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
        }

        // Store meta.
        $expires = time() + ( $duration * HOUR_IN_SECONDS );
        update_user_meta( $user_id, '_wpt_temp_token', $token );
        update_user_meta( $user_id, '_wpt_temp_expires', $expires );
        update_user_meta( $user_id, '_wpt_is_temp_user', '1' );

        $url = add_query_arg( 'wpt_temp_access', $token, site_url( '/' ) );

        wp_send_json_success( [
            'url'      => $url,
            'username' => $username,
            'expires'  => gmdate( 'Y-m-d H:i:s', $expires ),
        ] );
    }

    /**
     * AJAX: Revoke (delete) a temporary user.
     */
    public function ajax_revoke(): void {
        check_ajax_referer( 'wpt_temp_access', '_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid user ID.', 'wptransformed' ) ] );
        }

        if ( ! $this->is_temp_user( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Not a temporary user.', 'wptransformed' ) ] );
        }

        $this->delete_temp_user( $user_id );

        wp_send_json_success( [ 'message' => __( 'User access revoked.', 'wptransformed' ) ] );
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Check if a user is a temporary user created by this module.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    private function is_temp_user( int $user_id ): bool {
        return (string) get_user_meta( $user_id, '_wpt_is_temp_user', true ) === '1';
    }

    /**
     * Delete a temporary user and all related meta.
     *
     * @param int $user_id User ID.
     */
    private function delete_temp_user( int $user_id ): void {
        // Require user functions for wp_delete_user in cron context.
        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        wp_delete_user( $user_id );
    }

    /**
     * Get all active temporary users.
     *
     * @return \WP_User[]
     */
    private function get_active_temp_users(): array {
        return get_users( [
            'meta_key'   => '_wpt_is_temp_user',
            'meta_value' => '1',
            'number'     => 100,
            'orderby'    => 'registered',
            'order'      => 'DESC',
        ] );
    }

    /**
     * Format remaining seconds into a human-readable string.
     *
     * @param int $seconds Remaining seconds.
     * @return string
     */
    private function format_remaining( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return __( 'Expired', 'wptransformed' );
        }

        $hours   = (int) floor( $seconds / HOUR_IN_SECONDS );
        $minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

        if ( $hours > 0 ) {
            return sprintf(
                /* translators: 1: hours, 2: minutes */
                __( '%1$dh %2$dm', 'wptransformed' ),
                $hours,
                $minutes
            );
        }

        return sprintf(
            /* translators: %d: minutes */
            __( '%dm', 'wptransformed' ),
            $minutes
        );
    }

    // -- Settings UI -------------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        $roles    = wp_roles()->get_names();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Default Role', 'wptransformed' ); ?></th>
                <td>
                    <select name="wpt_default_role">
                        <?php foreach ( $roles as $role_slug => $role_name ) : ?>
                            <option value="<?php echo esc_attr( $role_slug ); ?>"
                                <?php selected( $settings['default_role'], $role_slug ); ?>>
                                <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Default role for new temporary users.', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Default Duration', 'wptransformed' ); ?></th>
                <td>
                    <input type="number" name="wpt_default_duration"
                           value="<?php echo esc_attr( (string) $settings['default_duration'] ); ?>"
                           min="1" max="<?php echo esc_attr( (string) $settings['max_duration'] ); ?>"
                           class="small-text">
                    <?php esc_html_e( 'hours', 'wptransformed' ); ?>
                    <p class="description"><?php esc_html_e( 'Default duration for newly generated access links.', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Maximum Duration', 'wptransformed' ); ?></th>
                <td>
                    <input type="number" name="wpt_max_duration"
                           value="<?php echo esc_attr( (string) $settings['max_duration'] ); ?>"
                           min="1" max="720"
                           class="small-text">
                    <?php esc_html_e( 'hours', 'wptransformed' ); ?>
                    <p class="description"><?php esc_html_e( 'Maximum allowed duration for temporary access links (up to 720 hours / 30 days).', 'wptransformed' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $default_role = isset( $raw['wpt_default_role'] ) ? sanitize_key( $raw['wpt_default_role'] ) : 'administrator';
        if ( ! wp_roles()->is_role( $default_role ) ) {
            $default_role = 'administrator';
        }

        $max_dur     = isset( $raw['wpt_max_duration'] ) ? absint( $raw['wpt_max_duration'] ) : 168;
        $max_dur     = max( 1, min( 720, $max_dur ) );

        $default_dur = isset( $raw['wpt_default_duration'] ) ? absint( $raw['wpt_default_duration'] ) : 24;
        $default_dur = max( 1, min( $max_dur, $default_dur ) );

        return [
            'default_role'     => $default_role,
            'default_duration' => $default_dur,
            'max_duration'     => $max_dur,
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'cron', 'hook' => self::CRON_HOOK ],
            [ 'type' => 'user_meta', 'key' => '_wpt_temp_token' ],
            [ 'type' => 'user_meta', 'key' => '_wpt_temp_expires' ],
            [ 'type' => 'user_meta', 'key' => '_wpt_is_temp_user' ],
        ];
    }
}
