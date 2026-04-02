<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Session Manager -- View and control WordPress user sessions.
 *
 * Features:
 *  - Reads WP_Session_Tokens (native WordPress session system)
 *  - Parses session token data: IP, user agent, login timestamp, expiration
 *  - Tracks last activity per session via user meta wpt_session_activity
 *  - Idle timeout: destroys sessions that exceed idle threshold
 *  - Session limit: caps concurrent sessions per user, destroys oldest on new login
 *  - AJAX handlers for destroying individual sessions or all-except-current
 *  - Basic user agent detection (Chrome, Firefox, Safari, Edge, Mobile)
 *  - Admin settings page with current sessions table
 *  - Optional user profile section showing own sessions
 *
 * @package WPTransformed
 */
class Session_Manager extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'session-manager';
    }

    public function get_title(): string {
        return __( 'Session Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Monitor and manage user sessions with idle timeouts, session limits, and remote session termination.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'max_sessions_per_user' => 3,
            'idle_timeout'          => 480, // minutes (8 hours)
            'show_in_profile'       => true,
            'notify_on_new_login'   => false,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        // Track last activity on every authenticated page load.
        add_action( 'auth_cookie_valid', [ $this, 'track_activity' ], 10, 2 );

        // Enforce idle timeout on init.
        add_action( 'init', [ $this, 'enforce_idle_timeout' ] );

        // Enforce session limit on login.
        add_action( 'wp_login', [ $this, 'enforce_session_limit' ], 10, 2 );

        // Optionally notify user on new login.
        add_action( 'wp_login', [ $this, 'maybe_notify_new_login' ], 20, 2 );

        // AJAX handlers -- admin only.
        add_action( 'wp_ajax_wpt_destroy_session',        [ $this, 'ajax_destroy_session' ] );
        add_action( 'wp_ajax_wpt_destroy_other_sessions', [ $this, 'ajax_destroy_other_sessions' ] );

        // User profile sections.
        $settings = $this->get_settings();
        if ( ! empty( $settings['show_in_profile'] ) ) {
            add_action( 'show_user_profile', [ $this, 'render_profile_sessions' ] );
            add_action( 'edit_user_profile', [ $this, 'render_profile_sessions' ] );
        }
    }

    // -- Activity Tracking ------------------------------------------------

    /**
     * Track last activity timestamp per session verifier.
     *
     * Fires on auth_cookie_valid which runs on every authenticated request.
     *
     * @param string[] $cookie_elements Parsed cookie elements.
     * @param \WP_User $user            The authenticated user.
     */
    public function track_activity( array $cookie_elements, \WP_User $user ): void {
        $token = $this->get_current_session_token();
        if ( empty( $token ) ) {
            return;
        }

        $verifier  = $this->hash_token( $token );
        $activity  = get_user_meta( $user->ID, 'wpt_session_activity', true );

        if ( ! is_array( $activity ) ) {
            $activity = [];
        }

        // Only update once per minute to reduce DB writes.
        $now = time();
        if ( isset( $activity[ $verifier ] ) && ( $now - $activity[ $verifier ] ) < 60 ) {
            return;
        }

        $activity[ $verifier ] = $now;
        update_user_meta( $user->ID, 'wpt_session_activity', $activity );
    }

    // -- Idle Timeout Enforcement -----------------------------------------

    /**
     * On init, check if the current user's session has exceeded idle timeout.
     */
    public function enforce_idle_timeout(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $settings = $this->get_settings();
        $timeout  = absint( $settings['idle_timeout'] );

        // 0 means no idle timeout.
        if ( $timeout === 0 ) {
            return;
        }

        $user_id  = get_current_user_id();
        $token    = $this->get_current_session_token();

        if ( empty( $token ) ) {
            return;
        }

        $verifier = $this->hash_token( $token );
        $activity = get_user_meta( $user_id, 'wpt_session_activity', true );

        if ( ! is_array( $activity ) || ! isset( $activity[ $verifier ] ) ) {
            return;
        }

        $last_active    = $activity[ $verifier ];
        $timeout_seconds = $timeout * 60;

        if ( ( time() - $last_active ) > $timeout_seconds ) {
            // Destroy this session.
            $manager = \WP_Session_Tokens::get_instance( $user_id );
            $manager->destroy( $token );

            // Clean up activity tracking for this session.
            unset( $activity[ $verifier ] );
            update_user_meta( $user_id, 'wpt_session_activity', $activity );

            // Force logout — skip redirect during AJAX/REST to avoid broken responses.
            wp_logout();
            if ( ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) {
                wp_safe_redirect( wp_login_url() );
                exit;
            }
        }
    }

    // -- Session Limit Enforcement ----------------------------------------

    /**
     * On login, enforce the max sessions per user limit.
     *
     * @param string   $user_login The username.
     * @param \WP_User $user       The user object.
     */
    public function enforce_session_limit( string $user_login, \WP_User $user ): void {
        $settings     = $this->get_settings();
        $max_sessions = absint( $settings['max_sessions_per_user'] );

        // 0 means unlimited.
        if ( $max_sessions === 0 ) {
            return;
        }

        $manager  = \WP_Session_Tokens::get_instance( $user->ID );
        $sessions = $manager->get_all();

        if ( count( $sessions ) <= $max_sessions ) {
            return;
        }

        // Sort sessions by login time ascending (oldest first).
        uasort( $sessions, static function ( $a, $b ) {
            $a_login = $a['login'] ?? 0;
            $b_login = $b['login'] ?? 0;
            return $a_login <=> $b_login;
        } );

        // Destroy oldest sessions until we're at the limit.
        $to_destroy = count( $sessions ) - $max_sessions;
        $destroyed  = 0;

        foreach ( $sessions as $verifier => $session ) {
            if ( $destroyed >= $to_destroy ) {
                break;
            }
            $manager->destroy( $verifier );

            // Clean up activity tracking.
            $activity = get_user_meta( $user->ID, 'wpt_session_activity', true );
            if ( is_array( $activity ) && isset( $activity[ $verifier ] ) ) {
                unset( $activity[ $verifier ] );
                update_user_meta( $user->ID, 'wpt_session_activity', $activity );
            }

            $destroyed++;
        }
    }

    // -- New Login Notification -------------------------------------------

    /**
     * Optionally send an email when a new session is created.
     *
     * @param string   $user_login The username.
     * @param \WP_User $user       The user object.
     */
    public function maybe_notify_new_login( string $user_login, \WP_User $user ): void {
        $settings = $this->get_settings();

        if ( empty( $settings['notify_on_new_login'] ) ) {
            return;
        }

        $ip         = $this->get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $browser    = $this->parse_user_agent( $user_agent );
        $time       = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

        $subject = sprintf(
            /* translators: %s: site name */
            __( '[%s] New Login Detected', 'wptransformed' ),
            get_bloginfo( 'name' )
        );

        $message = sprintf(
            /* translators: 1: username, 2: site name, 3: IP, 4: browser, 5: date/time */
            __( "Hello %1\$s,\n\nA new login to your account on %2\$s was detected.\n\nIP Address: %3\$s\nBrowser: %4\$s\nTime: %5\$s\n\nIf this was not you, please change your password immediately.", 'wptransformed' ),
            $user->display_name,
            get_bloginfo( 'name' ),
            $ip,
            $browser,
            $time
        );

        wp_mail( $user->user_email, $subject, $message );
    }

    // -- AJAX: Destroy Session --------------------------------------------

    /**
     * Destroy a specific session token for a user.
     */
    public function ajax_destroy_session(): void {
        check_ajax_referer( 'wpt_session_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user_id  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $verifier = isset( $_POST['verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['verifier'] ) ) : '';

        if ( empty( $user_id ) || empty( $verifier ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid session data.', 'wptransformed' ) ] );
        }

        // Validate verifier is a valid SHA-256 hash.
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $verifier ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid session verifier.', 'wptransformed' ) ] );
        }

        // Prevent destroying the current admin's own session through this handler.
        $current_token = $this->get_current_session_token();
        if ( $current_token && $this->hash_token( $current_token ) === $verifier && $user_id === get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'You cannot destroy your current session.', 'wptransformed' ) ] );
        }

        $manager = \WP_Session_Tokens::get_instance( $user_id );
        $manager->destroy( $verifier );

        // Clean up activity tracking.
        $activity = get_user_meta( $user_id, 'wpt_session_activity', true );
        if ( is_array( $activity ) && isset( $activity[ $verifier ] ) ) {
            unset( $activity[ $verifier ] );
            update_user_meta( $user_id, 'wpt_session_activity', $activity );
        }

        wp_send_json_success( [
            'message' => __( 'Session destroyed.', 'wptransformed' ),
        ] );
    }

    // -- AJAX: Destroy Other Sessions -------------------------------------

    /**
     * Destroy all sessions except the current user's active session.
     */
    public function ajax_destroy_other_sessions(): void {
        check_ajax_referer( 'wpt_session_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        if ( empty( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid user.', 'wptransformed' ) ] );
        }

        $current_token = $this->get_current_session_token();
        $manager       = \WP_Session_Tokens::get_instance( $user_id );
        $sessions      = $manager->get_all();

        // If destroying other sessions for the current user, keep the current one.
        // If destroying for a different user, destroy all.
        $current_verifier = '';
        if ( $current_token && $user_id === get_current_user_id() ) {
            $current_verifier = $this->hash_token( $current_token );
        }

        $destroyed = 0;
        foreach ( $sessions as $verifier => $session ) {
            if ( $verifier === $current_verifier ) {
                continue;
            }
            $manager->destroy( $verifier );
            $destroyed++;
        }

        // Clean up activity tracking.
        if ( $current_verifier ) {
            $activity = get_user_meta( $user_id, 'wpt_session_activity', true );
            if ( is_array( $activity ) ) {
                $kept = isset( $activity[ $current_verifier ] ) ? $activity[ $current_verifier ] : null;
                $activity = [];
                if ( $kept !== null ) {
                    $activity[ $current_verifier ] = $kept;
                }
                update_user_meta( $user_id, 'wpt_session_activity', $activity );
            }
        } else {
            delete_user_meta( $user_id, 'wpt_session_activity' );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: number of sessions destroyed */
                _n( '%d session destroyed.', '%d sessions destroyed.', $destroyed, 'wptransformed' ),
                $destroyed
            ),
        ] );
    }

    // -- Render Settings --------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-max-sessions"><?php esc_html_e( 'Max Sessions Per User', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-max-sessions" name="wpt_max_sessions_per_user"
                           value="<?php echo esc_attr( (string) $settings['max_sessions_per_user'] ); ?>"
                           min="0" max="50" step="1" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Maximum number of concurrent sessions per user. Set to 0 for unlimited.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-idle-timeout"><?php esc_html_e( 'Idle Timeout (minutes)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-idle-timeout" name="wpt_idle_timeout"
                           value="<?php echo esc_attr( (string) $settings['idle_timeout'] ); ?>"
                           min="0" max="43200" step="1" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Destroy sessions after this many minutes of inactivity. Set to 0 to disable.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Show in User Profile', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_show_in_profile" value="1"
                               <?php checked( ! empty( $settings['show_in_profile'] ) ); ?>>
                        <?php esc_html_e( 'Show active sessions section on user profile pages', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Login Notifications', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_notify_on_new_login" value="1"
                               <?php checked( ! empty( $settings['notify_on_new_login'] ) ); ?>>
                        <?php esc_html_e( 'Send email notification when a new login session is created', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <hr>

        <!-- Active Sessions Overview -->
        <h3><?php esc_html_e( 'Active Sessions', 'wptransformed' ); ?></h3>
        <?php $this->render_sessions_table(); ?>
        <?php
    }

    // -- Sessions Table (Admin) -------------------------------------------

    /**
     * Render the sessions table for the admin settings page.
     * Shows sessions for all users (admin-level view).
     */
    private function render_sessions_table(): void {
        $users = get_users( [
            'meta_key'     => 'session_tokens',
            'meta_compare' => 'EXISTS',
            'fields'       => 'ID',
        ] );

        $all_sessions = [];

        foreach ( $users as $user_id ) {
            $user_id  = (int) $user_id;
            $manager  = \WP_Session_Tokens::get_instance( $user_id );
            $sessions = $manager->get_all();
            $activity = get_user_meta( $user_id, 'wpt_session_activity', true );
            $user     = get_userdata( $user_id );

            if ( ! $user || ! is_array( $sessions ) ) {
                continue;
            }

            foreach ( $sessions as $verifier => $session ) {
                // Skip expired sessions.
                if ( isset( $session['expiration'] ) && $session['expiration'] < time() ) {
                    continue;
                }

                $last_active = ( is_array( $activity ) && isset( $activity[ $verifier ] ) )
                    ? $activity[ $verifier ]
                    : ( $session['login'] ?? 0 );

                $all_sessions[] = [
                    'user_id'      => $user_id,
                    'username'     => $user->user_login,
                    'display_name' => $user->display_name,
                    'verifier'     => $verifier,
                    'ip'           => $session['ip'] ?? __( 'Unknown', 'wptransformed' ),
                    'ua'           => $session['ua'] ?? '',
                    'browser'      => $this->parse_user_agent( $session['ua'] ?? '' ),
                    'login'        => $session['login'] ?? 0,
                    'expiration'   => $session['expiration'] ?? 0,
                    'last_active'  => $last_active,
                    'is_current'   => $this->is_current_session( $verifier, $user_id ),
                ];
            }
        }

        // Sort by last active descending.
        usort( $all_sessions, static function ( $a, $b ) {
            return $b['last_active'] <=> $a['last_active'];
        } );

        if ( empty( $all_sessions ) ) : ?>
            <p><?php esc_html_e( 'No active sessions found.', 'wptransformed' ); ?></p>
        <?php else : ?>
            <table class="wpt-session-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'User', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'IP Address', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Browser', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Logged In', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Last Active', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Expires', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $all_sessions as $session ) : ?>
                    <tr class="<?php echo $session['is_current'] ? 'wpt-session-current' : ''; ?>">
                        <td>
                            <?php echo esc_html( $session['display_name'] ); ?>
                            <br><small><?php echo esc_html( $session['username'] ); ?></small>
                        </td>
                        <td><code><?php echo esc_html( $session['ip'] ); ?></code></td>
                        <td><?php echo esc_html( $session['browser'] ); ?></td>
                        <td>
                            <?php
                            if ( $session['login'] ) {
                                echo esc_html( $this->format_time( $session['login'] ) );
                            } else {
                                esc_html_e( 'Unknown', 'wptransformed' );
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if ( $session['last_active'] ) {
                                echo esc_html( human_time_diff( $session['last_active'], time() ) );
                                echo ' ';
                                esc_html_e( 'ago', 'wptransformed' );
                            } else {
                                esc_html_e( 'Unknown', 'wptransformed' );
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if ( $session['expiration'] ) {
                                echo esc_html( $this->format_time( $session['expiration'] ) );
                            } else {
                                esc_html_e( 'Unknown', 'wptransformed' );
                            }
                            ?>
                        </td>
                        <td class="wpt-session-actions">
                            <?php if ( $session['is_current'] ) : ?>
                                <span class="wpt-session-badge wpt-session-badge-current">
                                    <?php esc_html_e( 'Current', 'wptransformed' ); ?>
                                </span>
                            <?php else : ?>
                                <button type="button" class="button button-small wpt-session-destroy"
                                        data-user-id="<?php echo esc_attr( (string) $session['user_id'] ); ?>"
                                        data-verifier="<?php echo esc_attr( $session['verifier'] ); ?>">
                                    <?php esc_html_e( 'Destroy', 'wptransformed' ); ?>
                                </button>
                            <?php endif; ?>
                            <span class="spinner wpt-session-spinner" style="float: none;"></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // Group sessions by user for the "Destroy Other Sessions" buttons.
            $user_session_counts = [];
            foreach ( $all_sessions as $session ) {
                $uid = $session['user_id'];
                if ( ! isset( $user_session_counts[ $uid ] ) ) {
                    $user_session_counts[ $uid ] = [
                        'display_name' => $session['display_name'],
                        'count'        => 0,
                    ];
                }
                $user_session_counts[ $uid ]['count']++;
            }
            ?>

            <?php foreach ( $user_session_counts as $uid => $info ) :
                if ( $info['count'] <= 1 ) continue;
            ?>
            <p>
                <button type="button" class="button button-secondary wpt-session-destroy-others"
                        data-user-id="<?php echo esc_attr( (string) $uid ); ?>">
                    <?php
                    printf(
                        /* translators: %s: user display name */
                        esc_html__( 'Destroy All Other Sessions for %s', 'wptransformed' ),
                        esc_html( $info['display_name'] )
                    );
                    ?>
                </button>
                <span class="spinner wpt-session-spinner" style="float: none;"></span>
            </p>
            <?php endforeach; ?>

        <?php endif;
    }

    // -- User Profile Sessions --------------------------------------------

    /**
     * Render the sessions section on user profile pages.
     *
     * @param \WP_User $user The user being viewed.
     */
    public function render_profile_sessions( \WP_User $user ): void {
        $manager  = \WP_Session_Tokens::get_instance( $user->ID );
        $sessions = $manager->get_all();
        $activity = get_user_meta( $user->ID, 'wpt_session_activity', true );

        if ( ! is_array( $sessions ) || empty( $sessions ) ) {
            return;
        }
        ?>
        <h2><?php esc_html_e( 'Active Sessions', 'wptransformed' ); ?></h2>
        <table class="wpt-session-table widefat striped" style="max-width: 800px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'IP Address', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Browser', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Logged In', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Last Active', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sessions as $verifier => $session ) :
                    // Skip expired sessions.
                    if ( isset( $session['expiration'] ) && $session['expiration'] < time() ) {
                        continue;
                    }

                    $is_current  = $this->is_current_session( $verifier, $user->ID );
                    $last_active = ( is_array( $activity ) && isset( $activity[ $verifier ] ) )
                        ? $activity[ $verifier ]
                        : ( $session['login'] ?? 0 );
                ?>
                <tr class="<?php echo $is_current ? 'wpt-session-current' : ''; ?>">
                    <td><code><?php echo esc_html( $session['ip'] ?? __( 'Unknown', 'wptransformed' ) ); ?></code></td>
                    <td><?php echo esc_html( $this->parse_user_agent( $session['ua'] ?? '' ) ); ?></td>
                    <td>
                        <?php
                        if ( ! empty( $session['login'] ) ) {
                            echo esc_html( $this->format_time( $session['login'] ) );
                        } else {
                            esc_html_e( 'Unknown', 'wptransformed' );
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if ( $last_active ) {
                            echo esc_html( human_time_diff( $last_active, time() ) );
                            echo ' ';
                            esc_html_e( 'ago', 'wptransformed' );
                        } else {
                            esc_html_e( 'Unknown', 'wptransformed' );
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ( $is_current ) : ?>
                            <span class="wpt-session-badge wpt-session-badge-current">
                                <?php esc_html_e( 'Current Session', 'wptransformed' ); ?>
                            </span>
                        <?php else : ?>
                            <span class="wpt-session-badge wpt-session-badge-active">
                                <?php esc_html_e( 'Active', 'wptransformed' ); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // -- Sanitize Settings ------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        $max = isset( $raw['wpt_max_sessions_per_user'] ) ? absint( $raw['wpt_max_sessions_per_user'] ) : 3;
        $idle = isset( $raw['wpt_idle_timeout'] ) ? absint( $raw['wpt_idle_timeout'] ) : 480;

        return [
            'max_sessions_per_user' => min( $max, 50 ),
            'idle_timeout'          => min( $idle, 43200 ), // max 30 days in minutes
            'show_in_profile'       => ! empty( $raw['wpt_show_in_profile'] ),
            'notify_on_new_login'   => ! empty( $raw['wpt_notify_on_new_login'] ),
        ];
    }

    // -- Assets -----------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        // Load on WPTransformed settings page and user profile pages.
        $is_settings = strpos( $hook, 'wptransformed' ) !== false;
        $is_profile  = in_array( $hook, [ 'profile.php', 'user-edit.php' ], true );

        if ( ! $is_settings && ! $is_profile ) {
            return;
        }

        wp_enqueue_style(
            'wpt-session-manager',
            WPT_URL . 'modules/security/css/session-manager.css',
            [],
            WPT_VERSION
        );

        // Only enqueue JS with AJAX on admin settings page (not profile).
        if ( $is_settings ) {
            wp_enqueue_script(
                'wpt-session-manager',
                WPT_URL . 'modules/security/js/session-manager.js',
                [],
                WPT_VERSION,
                true
            );

            wp_localize_script( 'wpt-session-manager', 'wptSessionManager', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wpt_session_nonce' ),
                'i18n'    => [
                    'confirmDestroy'       => __( 'Are you sure you want to destroy this session?', 'wptransformed' ),
                    'confirmDestroyOthers' => __( 'Destroy all other sessions for this user?', 'wptransformed' ),
                    'destroying'           => __( 'Destroying...', 'wptransformed' ),
                    'networkError'         => __( 'Network error. Please try again.', 'wptransformed' ),
                ],
            ] );
        }
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            'user_meta' => [
                'description' => __( 'Session activity tracking data (wpt_session_activity) in user meta.', 'wptransformed' ),
                'type'        => 'user_meta',
                'key'         => 'wpt_session_activity',
            ],
        ];
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Get the current session token from the logged-in cookie.
     *
     * @return string The raw session token, or empty string.
     */
    private function get_current_session_token(): string {
        $cookie = wp_parse_auth_cookie( '', 'logged_in' );
        return $cookie['token'] ?? '';
    }

    /**
     * Hash a session token to produce the verifier used as the array key.
     *
     * WordPress uses the same hashing internally for session token storage.
     *
     * @param string $token Raw session token.
     * @return string Hashed verifier.
     */
    private function hash_token( string $token ): string {
        return hash( 'sha256', $token );
    }

    /**
     * Check if a verifier matches the current user's active session.
     *
     * @param string $verifier The session verifier hash.
     * @param int    $user_id  The user ID that owns the session.
     * @return bool
     */
    private function is_current_session( string $verifier, int $user_id ): bool {
        if ( $user_id !== get_current_user_id() ) {
            return false;
        }

        $token = $this->get_current_session_token();
        if ( empty( $token ) ) {
            return false;
        }

        return $this->hash_token( $token ) === $verifier;
    }

    /**
     * Parse a user agent string into a human-readable browser name.
     *
     * Basic detection for the most common browsers and mobile devices.
     *
     * @param string $ua Raw user agent string.
     * @return string Detected browser name.
     */
    private function parse_user_agent( string $ua ): string {
        if ( empty( $ua ) ) {
            return __( 'Unknown', 'wptransformed' );
        }

        // Mobile detection first.
        $is_mobile = (bool) preg_match( '/Mobile|Android|iPhone|iPad/i', $ua );
        $platform  = $is_mobile ? 'Mobile ' : '';

        // Browser detection -- order matters (Edge contains Chrome, Chrome contains Safari).
        if ( preg_match( '/Edg(?:e|A|iOS)?\/(\d+)/i', $ua, $m ) ) {
            return $platform . 'Edge ' . $m[1];
        }
        if ( preg_match( '/OPR\/(\d+)/i', $ua, $m ) ) {
            return $platform . 'Opera ' . $m[1];
        }
        if ( preg_match( '/Chrome\/(\d+)/i', $ua, $m ) ) {
            return $platform . 'Chrome ' . $m[1];
        }
        if ( preg_match( '/Firefox\/(\d+)/i', $ua, $m ) ) {
            return $platform . 'Firefox ' . $m[1];
        }
        if ( preg_match( '/Safari\/(\d+)/i', $ua, $m ) && preg_match( '/Version\/(\d+)/i', $ua, $v ) ) {
            return $platform . 'Safari ' . $v[1];
        }

        return $is_mobile ? __( 'Mobile Browser', 'wptransformed' ) : __( 'Unknown Browser', 'wptransformed' );
    }

    /**
     * Format a Unix timestamp into a localized date/time string.
     *
     * @param int $timestamp Unix timestamp.
     * @return string Formatted date/time.
     */
    private function format_time( int $timestamp ): string {
        return wp_date(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            $timestamp
        ) ?: '';
    }

    /**
     * Get the client IP address, with proxy header support.
     *
     * @return string IP address.
     */
    private function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                // X-Forwarded-For can contain multiple IPs; take the first.
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
