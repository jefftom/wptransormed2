<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Passkey Authentication (WebAuthn) -- Passwordless login via platform authenticators.
 *
 * Features:
 *  - Register multiple passkeys per user via navigator.credentials.create()
 *  - Authenticate via navigator.credentials.get()
 *  - Challenge generation with random_bytes(32)
 *  - Signature verification via openssl_verify()
 *  - Credential storage in user meta (wpt_passkey_credentials)
 *  - Browser compat check: only show if window.PublicKeyCredential exists
 *  - Optional password fallback
 *  - Revoke individual passkeys from profile page
 *
 * @package WPTransformed
 */
class Passkey_Auth extends Module_Base {

    /**
     * User meta key for stored passkey credentials.
     */
    private const META_CREDENTIALS = 'wpt_passkey_credentials';

    /**
     * Transient prefix for challenges.
     */
    private const CHALLENGE_PREFIX = 'wpt_passkey_challenge_';

    /**
     * Challenge expiry in seconds (5 minutes).
     */
    private const CHALLENGE_EXPIRY = 300;

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'passkey-auth';
    }

    public function get_title(): string {
        return __( 'Passkey Authentication', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Enable passwordless login using WebAuthn passkeys for supported browsers and devices.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'enabled'                 => true,
            'allow_password_fallback' => true,
            'enabled_for'             => [ 'administrator' ],
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        // Profile page: passkey management.
        add_action( 'show_user_profile', [ $this, 'render_user_profile_section' ] );
        add_action( 'edit_user_profile', [ $this, 'render_user_profile_section' ] );

        // Login page: add passkey button.
        add_action( 'login_form', [ $this, 'render_login_button' ] );
        add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_assets' ] );

        // AJAX handlers for registration.
        add_action( 'wp_ajax_wpt_passkey_get_register_options',   [ $this, 'ajax_get_register_options' ] );
        add_action( 'wp_ajax_wpt_passkey_register',               [ $this, 'ajax_register_credential' ] );
        add_action( 'wp_ajax_wpt_passkey_revoke',                 [ $this, 'ajax_revoke_credential' ] );

        // AJAX handlers for authentication (nopriv for login page).
        add_action( 'wp_ajax_nopriv_wpt_passkey_get_auth_options', [ $this, 'ajax_get_auth_options' ] );
        add_action( 'wp_ajax_nopriv_wpt_passkey_authenticate',     [ $this, 'ajax_authenticate' ] );
        add_action( 'wp_ajax_wpt_passkey_get_auth_options',        [ $this, 'ajax_get_auth_options' ] );
        add_action( 'wp_ajax_wpt_passkey_authenticate',            [ $this, 'ajax_authenticate' ] );
    }

    // =====================================================================
    //  PROFILE: PASSKEY MANAGEMENT
    // =====================================================================

    /**
     * Render the passkey management section on the user profile page.
     *
     * @param \WP_User $user The user being edited.
     */
    public function render_user_profile_section( \WP_User $user ): void {
        if ( ! $this->is_user_eligible( $user ) ) {
            return;
        }

        $credentials = $this->get_user_credentials( $user->ID );
        ?>
        <h2><?php esc_html_e( 'Passkey Authentication', 'wptransformed' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Register passkeys for passwordless login. Each device can have its own passkey.', 'wptransformed' ); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Registered Passkeys', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-passkey-list">
                        <?php if ( empty( $credentials ) ) : ?>
                            <p class="description"><?php esc_html_e( 'No passkeys registered yet.', 'wptransformed' ); ?></p>
                        <?php else : ?>
                            <table class="widefat striped" style="max-width: 600px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Name', 'wptransformed' ); ?></th>
                                        <th><?php esc_html_e( 'Registered', 'wptransformed' ); ?></th>
                                        <th><?php esc_html_e( 'Last Used', 'wptransformed' ); ?></th>
                                        <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $credentials as $index => $cred ) : ?>
                                    <tr data-index="<?php echo esc_attr( (string) $index ); ?>">
                                        <td><?php echo esc_html( $cred['name'] ?? __( 'Passkey', 'wptransformed' ) ); ?></td>
                                        <td><?php echo esc_html( $cred['registered_at'] ?? '—' ); ?></td>
                                        <td><?php echo esc_html( $cred['last_used'] ?? __( 'Never', 'wptransformed' ) ); ?></td>
                                        <td>
                                            <button type="button" class="button button-small button-link-delete wpt-passkey-revoke"
                                                    data-credential-id="<?php echo esc_attr( $cred['credential_id'] ?? '' ); ?>">
                                                <?php esc_html_e( 'Revoke', 'wptransformed' ); ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <p style="margin-top: 15px;">
                        <button type="button" class="button button-secondary" id="wpt-passkey-register-btn">
                            <span class="dashicons dashicons-admin-network" style="vertical-align: middle;"></span>
                            <?php esc_html_e( 'Register New Passkey', 'wptransformed' ); ?>
                        </button>
                        <span class="spinner" id="wpt-passkey-spinner" style="float: none;"></span>
                    </p>
                    <div id="wpt-passkey-status" style="margin-top: 10px;"></div>
                    <div id="wpt-passkey-no-support" style="display: none; margin-top: 10px;">
                        <div class="notice notice-warning inline">
                            <p><?php esc_html_e( 'Your browser does not support passkeys (WebAuthn).', 'wptransformed' ); ?></p>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    // =====================================================================
    //  LOGIN PAGE
    // =====================================================================

    /**
     * Render the "Sign in with Passkey" button on the login form.
     */
    public function render_login_button(): void {
        $settings = $this->get_settings();
        ?>
        <div id="wpt-passkey-login-wrapper" style="display: none; margin: 15px 0; text-align: center;">
            <?php if ( ! empty( $settings['allow_password_fallback'] ) ) : ?>
                <hr style="margin: 15px 0;">
            <?php endif; ?>
            <button type="button" id="wpt-passkey-login-btn" class="button button-primary button-large"
                    style="width: 100%; margin-bottom: 10px;">
                <span class="dashicons dashicons-admin-network" style="vertical-align: middle; margin-right: 5px;"></span>
                <?php esc_html_e( 'Sign in with Passkey', 'wptransformed' ); ?>
            </button>
            <div id="wpt-passkey-login-status" style="margin-top: 5px; color: #72777c;"></div>
        </div>
        <?php
    }

    // =====================================================================
    //  AJAX: REGISTRATION
    // =====================================================================

    /**
     * Generate registration options (challenge + relying party info).
     */
    public function ajax_get_register_options(): void {
        check_ajax_referer( 'wpt_passkey_nonce', 'nonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user = wp_get_current_user();
        if ( ! $this->is_user_eligible( $user ) ) {
            wp_send_json_error( [ 'message' => __( 'Passkey authentication is not enabled for your role.', 'wptransformed' ) ] );
        }

        $challenge = random_bytes( 32 );
        $challenge_b64 = $this->base64url_encode( $challenge );

        // Store challenge in transient for verification.
        set_transient(
            self::CHALLENGE_PREFIX . $user->ID,
            $challenge_b64,
            self::CHALLENGE_EXPIRY
        );

        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! is_string( $site_url ) ) {
            $site_url = 'localhost';
        }

        // Get existing credential IDs to exclude.
        $existing = $this->get_user_credentials( $user->ID );
        $exclude = [];
        foreach ( $existing as $cred ) {
            if ( ! empty( $cred['credential_id'] ) ) {
                $exclude[] = [
                    'type' => 'public-key',
                    'id'   => $cred['credential_id'],
                ];
            }
        }

        wp_send_json_success( [
            'rp' => [
                'name' => get_bloginfo( 'name' ),
                'id'   => $site_url,
            ],
            'user' => [
                'id'          => $this->base64url_encode( (string) $user->ID ),
                'name'        => $user->user_login,
                'displayName' => $user->display_name,
            ],
            'challenge'            => $challenge_b64,
            'pubKeyCredParams'     => [
                [ 'type' => 'public-key', 'alg' => -7 ],   // ES256
                [ 'type' => 'public-key', 'alg' => -257 ],  // RS256
            ],
            'timeout'              => 60000,
            'excludeCredentials'   => $exclude,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'requireResidentKey'      => true,
                'residentKey'             => 'required',
                'userVerification'        => 'preferred',
            ],
            'attestation' => 'none',
        ] );
    }

    /**
     * Register a new passkey credential.
     */
    public function ajax_register_credential(): void {
        check_ajax_referer( 'wpt_passkey_nonce', 'nonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user = wp_get_current_user();

        // Validate challenge.
        $stored_challenge = get_transient( self::CHALLENGE_PREFIX . $user->ID );
        if ( ! $stored_challenge ) {
            wp_send_json_error( [ 'message' => __( 'Challenge expired. Please try again.', 'wptransformed' ) ] );
        }
        delete_transient( self::CHALLENGE_PREFIX . $user->ID );

        // Get the credential data from the request.
        $credential_id  = isset( $_POST['credential_id'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_id'] ) ) : '';
        $public_key_b64 = isset( $_POST['public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) : '';
        $client_data_b64 = isset( $_POST['client_data'] ) ? sanitize_text_field( wp_unslash( $_POST['client_data'] ) ) : '';
        $passkey_name   = isset( $_POST['passkey_name'] ) ? sanitize_text_field( wp_unslash( $_POST['passkey_name'] ) ) : '';
        $algorithm       = isset( $_POST['algorithm'] ) ? (int) $_POST['algorithm'] : -7;

        if ( empty( $credential_id ) || empty( $public_key_b64 ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid credential data.', 'wptransformed' ) ] );
        }

        // Verify the challenge in client data.
        $client_data_json = $this->base64url_decode( $client_data_b64 );
        if ( $client_data_json === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid client data.', 'wptransformed' ) ] );
        }

        $client_data = json_decode( $client_data_json, true );
        if ( ! is_array( $client_data ) || ! isset( $client_data['challenge'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid client data format.', 'wptransformed' ) ] );
        }

        if ( $client_data['challenge'] !== $stored_challenge ) {
            wp_send_json_error( [ 'message' => __( 'Challenge mismatch.', 'wptransformed' ) ] );
        }

        if ( empty( $passkey_name ) ) {
            $passkey_name = sprintf(
                /* translators: %s: date */
                __( 'Passkey %s', 'wptransformed' ),
                wp_date( 'Y-m-d H:i' )
            );
        }

        // Store the credential.
        $credentials = $this->get_user_credentials( $user->ID );
        $credentials[] = [
            'credential_id' => $credential_id,
            'public_key'    => $public_key_b64,
            'algorithm'     => $algorithm,
            'name'          => $passkey_name,
            'registered_at' => wp_date( 'Y-m-d H:i:s' ),
            'last_used'     => null,
        ];

        update_user_meta( $user->ID, self::META_CREDENTIALS, wp_json_encode( $credentials ) );

        wp_send_json_success( [ 'message' => __( 'Passkey registered successfully.', 'wptransformed' ) ] );
    }

    /**
     * Revoke (delete) a passkey credential.
     */
    public function ajax_revoke_credential(): void {
        check_ajax_referer( 'wpt_passkey_nonce', 'nonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $user = wp_get_current_user();
        $credential_id = isset( $_POST['credential_id'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_id'] ) ) : '';

        if ( empty( $credential_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid credential ID.', 'wptransformed' ) ] );
        }

        $credentials = $this->get_user_credentials( $user->ID );
        $filtered = [];
        $found = false;

        foreach ( $credentials as $cred ) {
            if ( ( $cred['credential_id'] ?? '' ) === $credential_id ) {
                $found = true;
                continue;
            }
            $filtered[] = $cred;
        }

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => __( 'Credential not found.', 'wptransformed' ) ] );
        }

        if ( empty( $filtered ) ) {
            delete_user_meta( $user->ID, self::META_CREDENTIALS );
        } else {
            update_user_meta( $user->ID, self::META_CREDENTIALS, wp_json_encode( $filtered ) );
        }

        wp_send_json_success( [ 'message' => __( 'Passkey revoked.', 'wptransformed' ) ] );
    }

    // =====================================================================
    //  AJAX: AUTHENTICATION
    // =====================================================================

    /**
     * Generate authentication options (challenge + allowed credentials).
     */
    public function ajax_get_auth_options(): void {
        // No nonce check here -- this is on the login page (nopriv).
        // The challenge itself provides CSRF protection.

        $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';

        $challenge = random_bytes( 32 );
        $challenge_b64 = $this->base64url_encode( $challenge );

        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! is_string( $site_url ) ) {
            $site_url = 'localhost';
        }

        $allowed_credentials = [];

        // If username provided, look up their credentials.
        if ( ! empty( $username ) ) {
            $user = get_user_by( 'login', $username );
            if ( $user instanceof \WP_User ) {
                $credentials = $this->get_user_credentials( $user->ID );
                foreach ( $credentials as $cred ) {
                    if ( ! empty( $cred['credential_id'] ) ) {
                        $allowed_credentials[] = [
                            'type' => 'public-key',
                            'id'   => $cred['credential_id'],
                        ];
                    }
                }

                // Store challenge keyed to user.
                set_transient(
                    self::CHALLENGE_PREFIX . 'auth_' . $user->ID,
                    $challenge_b64,
                    self::CHALLENGE_EXPIRY
                );
            }
        }

        // Store a general challenge for discoverable credential flow.
        set_transient(
            self::CHALLENGE_PREFIX . 'auth_general',
            $challenge_b64,
            self::CHALLENGE_EXPIRY
        );

        wp_send_json_success( [
            'challenge'          => $challenge_b64,
            'rpId'               => $site_url,
            'timeout'            => 60000,
            'allowCredentials'   => $allowed_credentials,
            'userVerification'   => 'preferred',
        ] );
    }

    /**
     * Authenticate with a passkey assertion.
     */
    public function ajax_authenticate(): void {
        $credential_id     = isset( $_POST['credential_id'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_id'] ) ) : '';
        $client_data_b64   = isset( $_POST['client_data'] ) ? sanitize_text_field( wp_unslash( $_POST['client_data'] ) ) : '';
        $auth_data_b64     = isset( $_POST['authenticator_data'] ) ? sanitize_text_field( wp_unslash( $_POST['authenticator_data'] ) ) : '';
        $signature_b64     = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
        $user_handle_b64   = isset( $_POST['user_handle'] ) ? sanitize_text_field( wp_unslash( $_POST['user_handle'] ) ) : '';

        if ( empty( $credential_id ) || empty( $client_data_b64 ) || empty( $auth_data_b64 ) || empty( $signature_b64 ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid authentication data.', 'wptransformed' ) ] );
        }

        // Find the user who owns this credential.
        $found_user = null;
        $found_cred = null;
        $found_index = null;

        // If user_handle is present, look up by user ID directly.
        if ( ! empty( $user_handle_b64 ) ) {
            $user_id_str = $this->base64url_decode( $user_handle_b64 );
            if ( $user_id_str !== false ) {
                $user_id = (int) $user_id_str;
                $user = get_user_by( 'id', $user_id );
                if ( $user instanceof \WP_User ) {
                    $credentials = $this->get_user_credentials( $user->ID );
                    foreach ( $credentials as $idx => $cred ) {
                        if ( ( $cred['credential_id'] ?? '' ) === $credential_id ) {
                            $found_user  = $user;
                            $found_cred  = $cred;
                            $found_index = $idx;
                            break;
                        }
                    }
                }
            }
        }

        // Fallback: search all users with passkey credentials.
        if ( ! $found_user ) {
            $meta_users = get_users( [
                'meta_key'   => self::META_CREDENTIALS,
                'meta_compare' => 'EXISTS',
                'fields'     => 'ids',
                'number'     => 100,
            ] );

            foreach ( $meta_users as $uid ) {
                $credentials = $this->get_user_credentials( (int) $uid );
                foreach ( $credentials as $idx => $cred ) {
                    if ( ( $cred['credential_id'] ?? '' ) === $credential_id ) {
                        $found_user  = get_user_by( 'id', (int) $uid );
                        $found_cred  = $cred;
                        $found_index = $idx;
                        break 2;
                    }
                }
            }
        }

        if ( ! $found_user || ! $found_cred ) {
            wp_send_json_error( [ 'message' => __( 'Passkey not recognized.', 'wptransformed' ) ] );
        }

        // Verify challenge.
        $stored_challenge = get_transient( self::CHALLENGE_PREFIX . 'auth_' . $found_user->ID );
        if ( ! $stored_challenge ) {
            $stored_challenge = get_transient( self::CHALLENGE_PREFIX . 'auth_general' );
        }

        if ( ! $stored_challenge ) {
            wp_send_json_error( [ 'message' => __( 'Challenge expired. Please try again.', 'wptransformed' ) ] );
        }

        // Decode and verify client data.
        $client_data_json = $this->base64url_decode( $client_data_b64 );
        if ( $client_data_json === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid client data.', 'wptransformed' ) ] );
        }

        $client_data = json_decode( $client_data_json, true );
        if ( ! is_array( $client_data ) || ! isset( $client_data['challenge'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid client data format.', 'wptransformed' ) ] );
        }

        if ( $client_data['challenge'] !== $stored_challenge ) {
            wp_send_json_error( [ 'message' => __( 'Challenge mismatch.', 'wptransformed' ) ] );
        }

        // Verify the signature.
        $auth_data = $this->base64url_decode( $auth_data_b64 );
        $signature = $this->base64url_decode( $signature_b64 );

        if ( $auth_data === false || $signature === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid signature data.', 'wptransformed' ) ] );
        }

        $public_key_der = $this->base64url_decode( $found_cred['public_key'] );
        if ( $public_key_der === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid stored public key.', 'wptransformed' ) ] );
        }

        // The signed data is authenticatorData + SHA256(clientDataJSON).
        $client_data_hash = hash( 'sha256', $client_data_json, true );
        $signed_data      = $auth_data . $client_data_hash;

        $algorithm = $found_cred['algorithm'] ?? -7;
        // ES256 (alg -7) uses SHA-256 with ECDSA; RS256 (alg -257) uses SHA-256 with RSA.
        $openssl_algo = OPENSSL_ALGO_SHA256;

        // Build PEM from DER-encoded public key.
        $pem = $this->der_to_pem( $public_key_der );
        if ( $pem === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to process public key.', 'wptransformed' ) ] );
        }

        $key = openssl_pkey_get_public( $pem );
        if ( $key === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid public key format.', 'wptransformed' ) ] );
        }

        $verify_result = openssl_verify( $signed_data, $signature, $key, $openssl_algo );

        if ( $verify_result !== 1 ) {
            wp_send_json_error( [ 'message' => __( 'Signature verification failed.', 'wptransformed' ) ] );
        }

        // Clean up challenges.
        delete_transient( self::CHALLENGE_PREFIX . 'auth_' . $found_user->ID );
        delete_transient( self::CHALLENGE_PREFIX . 'auth_general' );

        // Update last used timestamp.
        $credentials = $this->get_user_credentials( $found_user->ID );
        if ( isset( $credentials[ $found_index ] ) ) {
            $credentials[ $found_index ]['last_used'] = wp_date( 'Y-m-d H:i:s' );
            update_user_meta( $found_user->ID, self::META_CREDENTIALS, wp_json_encode( $credentials ) );
        }

        // Log the user in.
        wp_set_current_user( $found_user->ID );
        wp_set_auth_cookie( $found_user->ID, true );
        do_action( 'wp_login', $found_user->user_login, $found_user );

        wp_send_json_success( [
            'message'     => __( 'Authentication successful.', 'wptransformed' ),
            'redirect_to' => admin_url(),
        ] );
    }

    // =====================================================================
    //  RENDER SETTINGS
    // =====================================================================

    public function render_settings(): void {
        $settings = $this->get_settings();
        $roles = wp_roles()->get_names();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Password Fallback', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_allow_password_fallback" value="1"
                               <?php checked( ! empty( $settings['allow_password_fallback'] ) ); ?>>
                        <?php esc_html_e( 'Allow traditional password login alongside passkeys', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'If disabled, users with registered passkeys must use them to log in.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Enabled For Roles', 'wptransformed' ); ?></th>
                <td>
                    <?php foreach ( $roles as $role_key => $role_name ) : ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="wpt_enabled_for[]"
                                   value="<?php echo esc_attr( $role_key ); ?>"
                                   <?php checked( in_array( $role_key, $settings['enabled_for'], true ) ); ?>>
                            <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php esc_html_e( 'Select which user roles can register and use passkeys.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <hr>
        <h3><?php esc_html_e( 'Requirements', 'wptransformed' ); ?></h3>
        <ul style="list-style: disc; margin-left: 20px;">
            <li>
                <?php
                $has_openssl = extension_loaded( 'openssl' );
                echo $has_openssl
                    ? '<span style="color: green;">&#10003;</span> '
                    : '<span style="color: red;">&#10007;</span> ';
                esc_html_e( 'OpenSSL PHP extension', 'wptransformed' );
                ?>
            </li>
            <li>
                <?php
                $has_https = is_ssl();
                echo $has_https
                    ? '<span style="color: green;">&#10003;</span> '
                    : '<span style="color: orange;">&#9888;</span> ';
                esc_html_e( 'HTTPS connection (required for WebAuthn in production)', 'wptransformed' );
                ?>
            </li>
        </ul>
        <?php
    }

    // -- Sanitize Settings ------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        $enabled_for = [];
        if ( isset( $raw['wpt_enabled_for'] ) && is_array( $raw['wpt_enabled_for'] ) ) {
            $valid_roles = array_keys( wp_roles()->get_names() );
            foreach ( $raw['wpt_enabled_for'] as $role ) {
                $role = sanitize_key( $role );
                if ( in_array( $role, $valid_roles, true ) ) {
                    $enabled_for[] = $role;
                }
            }
        }

        return [
            'enabled'                 => true,
            'allow_password_fallback' => ! empty( $raw['wpt_allow_password_fallback'] ),
            'enabled_for'             => $enabled_for,
        ];
    }

    // -- Assets -----------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        // Load on profile pages and settings page.
        if ( $hook !== 'profile.php' && $hook !== 'user-edit.php' && strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        $this->enqueue_passkey_script( 'wpt-passkey-profile', 'profile' );
    }

    /**
     * Enqueue login page assets.
     */
    public function enqueue_login_assets(): void {
        $this->enqueue_passkey_script( 'wpt-passkey-login', 'login' );
    }

    /**
     * Enqueue the passkey JS with context-specific configuration.
     *
     * @param string $handle Script handle.
     * @param string $context Either 'profile' or 'login'.
     */
    private function enqueue_passkey_script( string $handle, string $context ): void {
        wp_enqueue_script(
            $handle,
            WPT_URL . 'modules/security/js/passkey-auth.js',
            [],
            WPT_VERSION,
            true
        );

        $localize_data = [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'context' => $context,
            'i18n'    => [
                'registering'    => __( 'Registering passkey...', 'wptransformed' ),
                'registered'     => __( 'Passkey registered! Reload the page to see it.', 'wptransformed' ),
                'authenticating' => __( 'Authenticating...', 'wptransformed' ),
                'confirmRevoke'  => __( 'Are you sure you want to revoke this passkey?', 'wptransformed' ),
                'revoked'        => __( 'Passkey revoked. Reload the page to see changes.', 'wptransformed' ),
                'notSupported'   => __( 'Your browser does not support passkeys.', 'wptransformed' ),
                'error'          => __( 'An error occurred. Please try again.', 'wptransformed' ),
                'namePrompt'     => __( 'Enter a name for this passkey (e.g., "MacBook Pro"):', 'wptransformed' ),
            ],
        ];

        if ( $context === 'profile' ) {
            $localize_data['nonce'] = wp_create_nonce( 'wpt_passkey_nonce' );
        }

        wp_localize_script( $handle, 'wptPasskeyAuth', $localize_data );
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            'user_meta' => [ self::META_CREDENTIALS ],
        ];
    }

    // =====================================================================
    //  HELPERS
    // =====================================================================

    /**
     * Check if a user is eligible for passkey authentication.
     *
     * @param \WP_User $user User to check.
     * @return bool
     */
    private function is_user_eligible( \WP_User $user ): bool {
        $settings = $this->get_settings();
        $enabled_roles = $settings['enabled_for'] ?? [];

        foreach ( $user->roles as $role ) {
            if ( in_array( $role, $enabled_roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get stored passkey credentials for a user.
     *
     * @param int $user_id User ID.
     * @return array Array of credential arrays.
     */
    private function get_user_credentials( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::META_CREDENTIALS, true );
        if ( empty( $raw ) ) {
            return [];
        }

        $decoded = json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        return $decoded;
    }

    /**
     * Base64url encode (no padding, URL-safe).
     *
     * @param string $data Raw data.
     * @return string Base64url-encoded string.
     */
    private function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Base64url decode.
     *
     * @param string $data Base64url-encoded string.
     * @return string|false Decoded data or false on failure.
     */
    private function base64url_decode( string $data ) {
        $padded = str_pad( strtr( $data, '-_', '+/' ), (int) ( ceil( strlen( $data ) / 4 ) * 4 ), '=', STR_PAD_RIGHT );
        $decoded = base64_decode( $padded, true );
        return $decoded;
    }

    /**
     * Convert DER-encoded public key to PEM format.
     *
     * @param string $der DER-encoded key bytes.
     * @return string|false PEM string or false on failure.
     */
    private function der_to_pem( string $der ) {
        if ( empty( $der ) ) {
            return false;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split( base64_encode( $der ), 64, "\n" )
             . "-----END PUBLIC KEY-----";

        return $pem;
    }
}
