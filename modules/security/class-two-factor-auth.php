<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Two-Factor Authentication -- TOTP, Email, and Recovery code 2FA for WordPress.
 *
 * Supports three verification methods:
 *  - TOTP (Time-based One-Time Password) via HMAC-SHA1
 *  - Email codes sent via wp_mail()
 *  - Recovery codes (10 random codes, stored hashed)
 *
 * Login flow:
 *  1. User passes username/password via WordPress authenticate filter
 *  2. If 2FA is enabled for the user's role and they have set up 2FA,
 *     return a WP_Error with a special code to pause login
 *  3. Render a 2FA input form on the login page via login_form action
 *  4. Verify the submitted code and complete authentication
 *
 * @package WPTransformed
 */
class Two_Factor_Auth extends Module_Base {

    /**
     * Base32 alphabet used for TOTP secret encoding.
     */
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * TOTP time step in seconds.
     */
    private const TOTP_PERIOD = 30;

    /**
     * Number of digits in a TOTP code.
     */
    private const TOTP_DIGITS = 6;

    /**
     * Number of recovery codes to generate.
     */
    private const RECOVERY_CODE_COUNT = 10;

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'two-factor-auth';
    }

    public function get_title(): string {
        return __( 'Two-Factor Authentication', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Add two-factor authentication to WordPress login with TOTP, email codes, and recovery codes.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'enabled_for'      => [ 'administrator', 'editor' ],
            'methods'          => [ 'totp', 'email', 'recovery' ],
            'grace_period_days' => 7,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        // Login interception.
        add_filter( 'authenticate', [ $this, 'intercept_login' ], 99, 3 );
        add_action( 'login_form_wpt_2fa_verify', [ $this, 'handle_2fa_login_page' ] );

        // User profile: setup 2FA section.
        add_action( 'show_user_profile', [ $this, 'render_user_profile_section' ] );
        add_action( 'edit_user_profile', [ $this, 'render_user_profile_section' ] );

        // AJAX handlers for 2FA setup.
        add_action( 'wp_ajax_wpt_2fa_generate_totp', [ $this, 'ajax_generate_totp_secret' ] );
        add_action( 'wp_ajax_wpt_2fa_verify_totp', [ $this, 'ajax_verify_totp_setup' ] );
        add_action( 'wp_ajax_wpt_2fa_generate_recovery', [ $this, 'ajax_generate_recovery_codes' ] );
        add_action( 'wp_ajax_wpt_2fa_disable', [ $this, 'ajax_disable_2fa' ] );
        add_action( 'wp_ajax_wpt_2fa_send_email_code', [ $this, 'ajax_send_email_code' ] );
    }

    // =====================================================================
    //  LOGIN INTERCEPTION
    // =====================================================================

    /**
     * Intercept the authenticate filter to check for 2FA requirement.
     *
     * @param \WP_User|\WP_Error|null $user     User object or error.
     * @param string                   $username Username.
     * @param string                   $password Password.
     * @return \WP_User|\WP_Error|null
     */
    public function intercept_login( $user, $username, $password ) {
        // Only process successful authentications.
        if ( ! $user instanceof \WP_User ) {
            return $user;
        }

        // Check if 2FA is required for this user's role.
        if ( ! $this->is_2fa_required_for_user( $user ) ) {
            return $user;
        }

        // Check if user has set up 2FA.
        $totp_secret = get_user_meta( $user->ID, 'wpt_2fa_totp_secret', true );
        if ( empty( $totp_secret ) ) {
            // Check grace period.
            $setup_prompted = get_user_meta( $user->ID, 'wpt_2fa_setup_prompted', true );
            if ( empty( $setup_prompted ) ) {
                update_user_meta( $user->ID, 'wpt_2fa_setup_prompted', time() );
                return $user; // Allow login, will be prompted to set up.
            }

            $settings     = $this->get_settings();
            $grace_days   = (int) $settings['grace_period_days'];
            $grace_expiry = (int) $setup_prompted + ( $grace_days * DAY_IN_SECONDS );

            if ( time() < $grace_expiry ) {
                return $user; // Still within grace period.
            }

            // Grace period expired - block login until 2FA is set up.
            return new \WP_Error(
                'wpt_2fa_setup_required',
                __( 'Two-factor authentication setup is required. Please contact an administrator.', 'wptransformed' )
            );
        }

        // 2FA is set up - store user ID in transient and redirect to 2FA form.
        $token = wp_generate_password( 32, false );
        set_transient( 'wpt_2fa_login_' . $token, $user->ID, 10 * MINUTE_IN_SECONDS );

        // Redirect to the 2FA verification page.
        wp_safe_redirect( wp_login_url() . '?action=wpt_2fa_verify&token=' . urlencode( $token ) );
        exit;
    }

    /**
     * Handle the 2FA verification login page.
     */
    public function handle_2fa_login_page(): void {
        // Verify token.
        $token   = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
        $user_id = $this->get_user_id_from_token( $token );

        if ( ! $user_id ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        // Handle form submission.
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['wpt_2fa_code'] ) ) {
            $this->process_2fa_verification( $token, $user_id );
            return;
        }

        // Render the 2FA form.
        $this->render_2fa_login_form( $token, $user_id );
        exit;
    }

    /**
     * Process 2FA code verification during login.
     *
     * @param string $token   Login token.
     * @param int    $user_id User ID.
     */
    private function process_2fa_verification( string $token, int $user_id ): void {
        if ( ! wp_verify_nonce( $_POST['wpt_2fa_nonce'] ?? '', 'wpt_2fa_verify_' . $token ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wptransformed' ) );
        }

        $code   = sanitize_text_field( wp_unslash( $_POST['wpt_2fa_code'] ?? '' ) );
        $method = sanitize_key( wp_unslash( $_POST['wpt_2fa_method'] ?? 'totp' ) );

        $verified = false;

        switch ( $method ) {
            case 'totp':
                $verified = $this->verify_totp_code( $user_id, $code );
                break;
            case 'email':
                $verified = $this->verify_email_code( $user_id, $code );
                break;
            case 'recovery':
                $verified = $this->verify_recovery_code( $user_id, $code );
                break;
        }

        if ( ! $verified ) {
            $this->render_2fa_login_form( $token, $user_id, __( 'Invalid verification code. Please try again.', 'wptransformed' ) );
            exit;
        }

        // Delete the transient.
        delete_transient( 'wpt_2fa_login_' . $token );

        // Log the user in.
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        wp_set_auth_cookie( $user_id, false );
        wp_set_current_user( $user_id );

        /** This action is documented in wp-includes/user.php */
        do_action( 'wp_login', $user->user_login, $user );

        $redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url();
        wp_safe_redirect( $redirect_to );
        exit;
    }

    /**
     * Render the 2FA login form.
     *
     * @param string $token   Login token.
     * @param int    $user_id User ID.
     * @param string $error   Error message to display.
     */
    private function render_2fa_login_form( string $token, int $user_id, string $error = '' ): void {
        $settings = $this->get_settings();
        $methods  = (array) $settings['methods'];

        // Determine available methods for this user.
        $has_totp     = ! empty( get_user_meta( $user_id, 'wpt_2fa_totp_secret', true ) );
        $has_recovery = ! empty( get_user_meta( $user_id, 'wpt_2fa_recovery_codes', true ) );

        login_header( __( 'Two-Factor Authentication', 'wptransformed' ), '', $error ? new \WP_Error( 'wpt_2fa_error', $error ) : null );
        ?>
        <form name="wpt_2fa_form" id="wpt_2fa_form" action="<?php echo esc_url( wp_login_url() . '?action=wpt_2fa_verify&token=' . urlencode( $token ) ); ?>" method="post">
            <?php wp_nonce_field( 'wpt_2fa_verify_' . $token, 'wpt_2fa_nonce' ); ?>
            <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr( isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url() ); ?>">

            <div id="wpt-2fa-totp" <?php echo ( ! $has_totp || ! in_array( 'totp', $methods, true ) ) ? 'style="display:none;"' : ''; ?>>
                <p>
                    <label for="wpt_2fa_code_totp"><?php esc_html_e( 'Authentication Code', 'wptransformed' ); ?></label>
                    <input type="text" name="wpt_2fa_code" id="wpt_2fa_code_totp" class="input" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" size="6" autofocus>
                </p>
                <input type="hidden" name="wpt_2fa_method" value="totp">
                <p class="description"><?php esc_html_e( 'Enter the 6-digit code from your authenticator app.', 'wptransformed' ); ?></p>
            </div>

            <div id="wpt-2fa-email" style="display:none;">
                <p>
                    <label for="wpt_2fa_code_email"><?php esc_html_e( 'Email Code', 'wptransformed' ); ?></label>
                    <input type="text" name="wpt_2fa_code" id="wpt_2fa_code_email" class="input" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" size="6" disabled>
                </p>
                <p>
                    <button type="button" id="wpt-2fa-send-email" class="button"><?php esc_html_e( 'Send Code to Email', 'wptransformed' ); ?></button>
                </p>
                <p class="description"><?php esc_html_e( 'A 6-digit code will be sent to your email address.', 'wptransformed' ); ?></p>
            </div>

            <div id="wpt-2fa-recovery" style="display:none;">
                <p>
                    <label for="wpt_2fa_code_recovery"><?php esc_html_e( 'Recovery Code', 'wptransformed' ); ?></label>
                    <input type="text" name="wpt_2fa_code" id="wpt_2fa_code_recovery" class="input" autocomplete="off" maxlength="10" size="10" disabled>
                </p>
                <p class="description"><?php esc_html_e( 'Enter one of your recovery codes.', 'wptransformed' ); ?></p>
            </div>

            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'wptransformed' ); ?>">
            </p>

            <?php if ( count( $methods ) > 1 ) : ?>
            <p class="wpt-2fa-method-switch">
                <?php if ( in_array( 'email', $methods, true ) ) : ?>
                    <a href="#" data-method="email"><?php esc_html_e( 'Use email code', 'wptransformed' ); ?></a>
                <?php endif; ?>
                <?php if ( $has_recovery && in_array( 'recovery', $methods, true ) ) : ?>
                    <a href="#" data-method="recovery"><?php esc_html_e( 'Use recovery code', 'wptransformed' ); ?></a>
                <?php endif; ?>
                <?php if ( $has_totp && in_array( 'totp', $methods, true ) ) : ?>
                    <a href="#" data-method="totp" style="display:none;"><?php esc_html_e( 'Use authenticator app', 'wptransformed' ); ?></a>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </form>

        <script>
        (function(){
            var methods = document.querySelectorAll('.wpt-2fa-method-switch a[data-method]');
            var sections = {
                totp: document.getElementById('wpt-2fa-totp'),
                email: document.getElementById('wpt-2fa-email'),
                recovery: document.getElementById('wpt-2fa-recovery')
            };

            function switchMethod(method) {
                for (var key in sections) {
                    if (sections[key]) {
                        sections[key].style.display = 'none';
                        var input = sections[key].querySelector('input[name="wpt_2fa_code"]');
                        if (input) { input.disabled = true; input.name = ''; }
                        var hiddenMethod = sections[key].querySelector('input[name="wpt_2fa_method"]');
                        if (hiddenMethod) { hiddenMethod.remove(); }
                    }
                }
                if (sections[method]) {
                    sections[method].style.display = '';
                    var input = sections[method].querySelector('input[type="text"]');
                    if (input) { input.disabled = false; input.name = 'wpt_2fa_code'; input.focus(); }
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'wpt_2fa_method';
                    hidden.value = method;
                    sections[method].appendChild(hidden);
                }
                methods.forEach(function(link) {
                    link.style.display = link.getAttribute('data-method') === method ? 'none' : '';
                });
            }

            methods.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    switchMethod(this.getAttribute('data-method'));
                });
            });

            var sendEmailBtn = document.getElementById('wpt-2fa-send-email');
            if (sendEmailBtn) {
                sendEmailBtn.addEventListener('click', function() {
                    this.disabled = true;
                    this.textContent = '<?php echo esc_js( __( 'Sending...', 'wptransformed' ) ); ?>';
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    var btn = this;
                    xhr.onload = function() {
                        btn.textContent = '<?php echo esc_js( __( 'Code Sent!', 'wptransformed' ) ); ?>';
                        setTimeout(function(){ btn.disabled = false; btn.textContent = '<?php echo esc_js( __( 'Resend Code', 'wptransformed' ) ); ?>'; }, 30000);
                    };
                    xhr.send('action=wpt_2fa_send_email_code&user_id=<?php echo (int) $user_id; ?>&token=<?php echo esc_js( $token ); ?>&_wpnonce=<?php echo esc_js( wp_create_nonce( 'wpt_2fa_send_email_' . $user_id ) ); ?>');
                });
            }
        })();
        </script>
        <?php
        login_footer();
    }

    // =====================================================================
    //  TOTP IMPLEMENTATION
    // =====================================================================

    /**
     * Generate a random TOTP secret (20 bytes, base32-encoded).
     *
     * @return string Base32-encoded secret.
     */
    private function generate_totp_secret(): string {
        $bytes = random_bytes( 20 );
        return $this->base32_encode( $bytes );
    }

    /**
     * Base32-encode a binary string.
     *
     * @param string $data Raw binary data.
     * @return string Base32-encoded string.
     */
    private function base32_encode( string $data ): string {
        $binary = '';
        foreach ( str_split( $data ) as $char ) {
            $binary .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
        }

        $result = '';
        $chunks = str_split( $binary, 5 );
        foreach ( $chunks as $chunk ) {
            $chunk   = str_pad( $chunk, 5, '0', STR_PAD_RIGHT );
            $result .= self::BASE32_CHARS[ bindec( $chunk ) ];
        }

        return $result;
    }

    /**
     * Base32-decode a string to raw bytes.
     *
     * @param string $data Base32-encoded string.
     * @return string Raw binary data.
     */
    private function base32_decode( string $data ): string {
        $data   = strtoupper( $data );
        $data   = rtrim( $data, '=' );
        $binary = '';

        foreach ( str_split( $data ) as $char ) {
            $pos = strpos( self::BASE32_CHARS, $char );
            if ( $pos === false ) {
                continue;
            }
            $binary .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
        }

        $result = '';
        $bytes  = str_split( $binary, 8 );
        foreach ( $bytes as $byte ) {
            if ( strlen( $byte ) < 8 ) {
                break;
            }
            $result .= chr( (int) bindec( $byte ) );
        }

        return $result;
    }

    /**
     * Generate a TOTP code for the given secret and time.
     *
     * @param string $secret Base32-encoded secret.
     * @param int|null $time  Unix timestamp (null = current time).
     * @return string 6-digit TOTP code.
     */
    private function generate_totp_code( string $secret, ?int $time = null ): string {
        $time    = $time ?? time();
        $counter = (int) floor( $time / self::TOTP_PERIOD );

        $key     = $this->base32_decode( $secret );
        $message = pack( 'N*', 0 ) . pack( 'N*', $counter );

        $hash   = hash_hmac( 'sha1', $message, $key, true );
        $offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;

        $binary = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
                 | ( ord( $hash[ $offset + 1 ] ) << 16 )
                 | ( ord( $hash[ $offset + 2 ] ) << 8 )
                 | ord( $hash[ $offset + 3 ] );

        $otp = $binary % ( 10 ** self::TOTP_DIGITS );

        return str_pad( (string) $otp, self::TOTP_DIGITS, '0', STR_PAD_LEFT );
    }

    /**
     * Verify a TOTP code for a user (reads secret from user meta).
     *
     * @param int    $user_id User ID.
     * @param string $code    The 6-digit code to verify.
     * @return bool
     */
    private function verify_totp_code( int $user_id, string $code ): bool {
        $secret = get_user_meta( $user_id, 'wpt_2fa_totp_secret', true );
        if ( empty( $secret ) ) {
            return false;
        }

        return $this->verify_totp_code_against_secret( $secret, $code, $user_id );
    }

    /**
     * Verify a TOTP code against a given secret with a +/- 1 step window.
     *
     * @param string $secret Base32-encoded TOTP secret.
     * @param string $code   The 6-digit code to verify.
     * @return bool
     */
    private function verify_totp_code_against_secret( string $secret, string $code, int $user_id = 0 ): bool {
        $code = preg_replace( '/\s+/', '', $code );
        $time = time();

        for ( $offset = -1; $offset <= 1; $offset++ ) {
            $check_time = $time + ( $offset * self::TOTP_PERIOD );
            $counter    = (int) floor( $check_time / self::TOTP_PERIOD );
            $valid_code = $this->generate_totp_code( $secret, $check_time );
            if ( hash_equals( $valid_code, $code ) ) {
                // Replay protection: reject codes at or before the last used counter.
                if ( $user_id > 0 ) {
                    $last_counter = (int) get_user_meta( $user_id, 'wpt_2fa_last_totp_counter', true );
                    if ( $counter <= $last_counter ) {
                        return false;
                    }
                    update_user_meta( $user_id, 'wpt_2fa_last_totp_counter', $counter );
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an otpauth:// URI for QR code display.
     *
     * @param string $secret Base32-encoded secret.
     * @param string $email  User email.
     * @return string otpauth URI.
     */
    private function get_totp_uri( string $secret, string $email ): string {
        $issuer = rawurlencode( get_bloginfo( 'name' ) );
        $label  = rawurlencode( $email );

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $issuer,
            $label,
            $secret,
            $issuer,
            self::TOTP_DIGITS,
            self::TOTP_PERIOD
        );
    }

    /**
     * Render TOTP setup HTML with the secret key and an otpauth link.
     *
     * @param string $uri    The otpauth:// URI for authenticator apps.
     * @param string $secret Base32-encoded secret for manual entry.
     * @return string HTML markup.
     */
    private function render_totp_setup_html( string $uri, string $secret ): string {
        $formatted_secret = implode( ' ', str_split( $secret, 4 ) );

        return sprintf(
            '<div class="wpt-2fa-setup">'
            . '<p><strong>%s</strong></p>'
            . '<code class="wpt-2fa-secret" style="display:block;padding:10px;background:#f0f0f1;font-size:16px;letter-spacing:2px;word-break:break-all;user-select:all;">%s</code>'
            . '<p class="description">%s</p>'
            . '<p class="description"><a href="%s" style="word-break:break-all;">%s</a></p>'
            . '</div>',
            esc_html__( 'Enter this key in your authenticator app:', 'wptransformed' ),
            esc_html( $formatted_secret ),
            esc_html__( 'Or scan the QR code if your app supports it. You can click the link below to open in a compatible app.', 'wptransformed' ),
            esc_url( $uri ),
            esc_html__( 'Open in authenticator app', 'wptransformed' )
        );
    }

    // =====================================================================
    //  EMAIL CODE
    // =====================================================================

    /**
     * Send a 6-digit email verification code.
     *
     * @param int $user_id User ID.
     * @return bool Whether the email was sent.
     */
    private function send_email_code( int $user_id ): bool {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return false;
        }

        $code = (string) wp_rand( 100000, 999999 );

        // Store the code with expiration (10 minutes).
        update_user_meta( $user_id, 'wpt_2fa_email_code', wp_hash( $code ) );
        update_user_meta( $user_id, 'wpt_2fa_email_code_expires', time() + ( 10 * MINUTE_IN_SECONDS ) );

        $subject = sprintf(
            /* translators: %s: Site name */
            __( '[%s] Your verification code', 'wptransformed' ),
            get_bloginfo( 'name' )
        );

        $message = sprintf(
            /* translators: 1: Verification code, 2: Expiration minutes */
            __( "Your verification code is: %1\$s\n\nThis code will expire in %2\$d minutes.\n\nIf you did not request this code, please ignore this email.", 'wptransformed' ),
            $code,
            10
        );

        return wp_mail( $user->user_email, $subject, $message );
    }

    /**
     * Verify an email code.
     *
     * @param int    $user_id User ID.
     * @param string $code    The code to verify.
     * @return bool
     */
    private function verify_email_code( int $user_id, string $code ): bool {
        $stored_hash = get_user_meta( $user_id, 'wpt_2fa_email_code', true );
        $expires     = (int) get_user_meta( $user_id, 'wpt_2fa_email_code_expires', true );

        if ( empty( $stored_hash ) || time() > $expires ) {
            return false;
        }

        $code = preg_replace( '/\s+/', '', $code );

        if ( hash_equals( wp_hash( $code ), $stored_hash ) ) {
            // Delete used code.
            delete_user_meta( $user_id, 'wpt_2fa_email_code' );
            delete_user_meta( $user_id, 'wpt_2fa_email_code_expires' );
            delete_user_meta( $user_id, 'wpt_2fa_email_fails' );
            return true;
        }

        // Brute-force protection: lock out after 5 failed attempts.
        $fails = (int) get_user_meta( $user_id, 'wpt_2fa_email_fails', true ) + 1;
        update_user_meta( $user_id, 'wpt_2fa_email_fails', $fails );
        if ( $fails >= 5 ) {
            delete_user_meta( $user_id, 'wpt_2fa_email_code' );
            delete_user_meta( $user_id, 'wpt_2fa_email_code_expires' );
            delete_user_meta( $user_id, 'wpt_2fa_email_fails' );
        }

        return false;
    }

    // =====================================================================
    //  RECOVERY CODES
    // =====================================================================

    /**
     * Generate recovery codes for a user.
     *
     * @param int $user_id User ID.
     * @return array Plain-text recovery codes (to show to the user once).
     */
    private function generate_recovery_codes( int $user_id ): array {
        $codes  = [];
        $hashed = [];

        for ( $i = 0; $i < self::RECOVERY_CODE_COUNT; $i++ ) {
            $code    = wp_generate_password( 8, false );
            $codes[] = $code;
            $hashed[] = wp_hash_password( $code );
        }

        update_user_meta( $user_id, 'wpt_2fa_recovery_codes', $hashed );

        return $codes;
    }

    /**
     * Verify a recovery code and remove it if valid.
     *
     * @param int    $user_id User ID.
     * @param string $code    Recovery code to verify.
     * @return bool
     */
    private function verify_recovery_code( int $user_id, string $code ): bool {
        $stored_hashes = get_user_meta( $user_id, 'wpt_2fa_recovery_codes', true );
        if ( ! is_array( $stored_hashes ) || empty( $stored_hashes ) ) {
            return false;
        }

        $code = trim( $code );

        foreach ( $stored_hashes as $index => $hash ) {
            if ( wp_check_password( $code, $hash ) ) {
                // Remove used code.
                unset( $stored_hashes[ $index ] );
                update_user_meta( $user_id, 'wpt_2fa_recovery_codes', array_values( $stored_hashes ) );
                return true;
            }
        }

        return false;
    }

    // =====================================================================
    //  HELPER METHODS
    // =====================================================================

    /**
     * Check whether 2FA is required for a given user.
     *
     * @param \WP_User $user User object.
     * @return bool
     */
    private function is_2fa_required_for_user( \WP_User $user ): bool {
        $settings = $this->get_settings();
        $enabled_roles = (array) $settings['enabled_for'];

        return (bool) array_intersect( $user->roles, $enabled_roles );
    }

    /**
     * Get user ID from a 2FA login token.
     *
     * @param string $token Token string.
     * @return int|false User ID or false.
     */
    private function get_user_id_from_token( string $token ) {
        if ( empty( $token ) ) {
            return false;
        }

        $user_id = get_transient( 'wpt_2fa_login_' . $token );
        return $user_id ? (int) $user_id : false;
    }

    // =====================================================================
    //  AJAX HANDLERS
    // =====================================================================

    /**
     * AJAX: Generate a new TOTP secret and return setup HTML.
     */
    public function ajax_generate_totp_secret(): void {
        check_ajax_referer( 'wpt_2fa_setup', '_wpnonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $user = wp_get_current_user();
        $secret = $this->generate_totp_secret();

        // Store temporarily until verified.
        update_user_meta( $user->ID, 'wpt_2fa_totp_pending', $secret );

        $uri  = $this->get_totp_uri( $secret, $user->user_email );
        $html = $this->render_totp_setup_html( $uri, $secret );

        // Don't expose raw secret in JSON — it's already in the HTML for manual entry.
        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * AJAX: Verify a TOTP code to confirm setup.
     */
    public function ajax_verify_totp_setup(): void {
        check_ajax_referer( 'wpt_2fa_setup', '_wpnonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $user   = wp_get_current_user();
        $code   = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
        $secret = get_user_meta( $user->ID, 'wpt_2fa_totp_pending', true );

        if ( empty( $secret ) ) {
            wp_send_json_error( __( 'No pending TOTP secret found. Please start setup again.', 'wptransformed' ) );
        }

        // Verify the code directly against the pending secret.
        $valid = $this->verify_totp_code_against_secret( $secret, $code );

        if ( ! $valid ) {
            wp_send_json_error( __( 'Invalid code. Please check your authenticator app and try again.', 'wptransformed' ) );
        }

        // Confirmed -- promote pending secret to active and clean up.
        update_user_meta( $user->ID, 'wpt_2fa_totp_secret', $secret );
        delete_user_meta( $user->ID, 'wpt_2fa_totp_pending' );

        wp_send_json_success( __( 'Two-factor authentication has been enabled.', 'wptransformed' ) );
    }

    /**
     * AJAX: Generate new recovery codes.
     */
    public function ajax_generate_recovery_codes(): void {
        check_ajax_referer( 'wpt_2fa_setup', '_wpnonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $user  = wp_get_current_user();
        $codes = $this->generate_recovery_codes( $user->ID );

        wp_send_json_success( [ 'codes' => $codes ] );
    }

    /**
     * AJAX: Disable 2FA for the current user.
     */
    public function ajax_disable_2fa(): void {
        check_ajax_referer( 'wpt_2fa_setup', '_wpnonce' );

        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wptransformed' ) );
        }

        $user_id = get_current_user_id();

        delete_user_meta( $user_id, 'wpt_2fa_totp_secret' );
        delete_user_meta( $user_id, 'wpt_2fa_totp_pending' );
        delete_user_meta( $user_id, 'wpt_2fa_recovery_codes' );
        delete_user_meta( $user_id, 'wpt_2fa_setup_prompted' );
        delete_user_meta( $user_id, 'wpt_2fa_email_code' );
        delete_user_meta( $user_id, 'wpt_2fa_email_code_expires' );

        wp_send_json_success( __( 'Two-factor authentication has been disabled.', 'wptransformed' ) );
    }

    /**
     * AJAX: Send an email verification code (for login flow).
     */
    public function ajax_send_email_code(): void {
        $token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

        // Derive user_id from token (login flow) or nonce (profile flow).
        // NEVER trust user_id from POST alone — always derive from auth.
        $user_id = 0;
        if ( $token ) {
            $user_id = (int) $this->get_user_id_from_token( $token );
        }
        if ( ! $user_id && is_user_logged_in() ) {
            $post_user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
            if ( $post_user_id && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wpt_2fa_send_email_' . $post_user_id ) ) {
                $user_id = $post_user_id;
            }
        }

        if ( ! $user_id ) {
            wp_send_json_error( __( 'Security check failed.', 'wptransformed' ) );
        }

        if ( $this->send_email_code( $user_id ) ) {
            wp_send_json_success( __( 'Verification code sent to your email.', 'wptransformed' ) );
        }

        wp_send_json_error( __( 'Failed to send verification code.', 'wptransformed' ) );
    }

    // =====================================================================
    //  USER PROFILE SECTION
    // =====================================================================

    /**
     * Render the 2FA setup section on the user profile page.
     *
     * @param \WP_User $user The user being edited.
     */
    public function render_user_profile_section( \WP_User $user ): void {
        // Only show for users who can set up 2FA.
        if ( ! $this->is_2fa_required_for_user( $user ) ) {
            return;
        }

        // Only allow users to manage their own 2FA, or admins.
        if ( get_current_user_id() !== $user->ID && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $has_totp     = ! empty( get_user_meta( $user->ID, 'wpt_2fa_totp_secret', true ) );
        $has_recovery = ! empty( get_user_meta( $user->ID, 'wpt_2fa_recovery_codes', true ) );
        $nonce        = wp_create_nonce( 'wpt_2fa_setup' );
        ?>
        <h2><?php esc_html_e( 'Two-Factor Authentication', 'wptransformed' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-2fa-status">
                        <?php if ( $has_totp ) : ?>
                            <span style="color:#00a32a;font-weight:600;"><?php esc_html_e( 'Enabled', 'wptransformed' ); ?></span>
                            <button type="button" class="button" id="wpt-2fa-disable"><?php esc_html_e( 'Disable 2FA', 'wptransformed' ); ?></button>
                        <?php else : ?>
                            <span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'Not Configured', 'wptransformed' ); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php if ( ! $has_totp ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Setup Authenticator', 'wptransformed' ); ?></th>
                <td>
                    <button type="button" class="button button-primary" id="wpt-2fa-setup-totp"><?php esc_html_e( 'Set Up Authenticator App', 'wptransformed' ); ?></button>
                    <div id="wpt-2fa-totp-setup-area" style="display:none;margin-top:15px;">
                        <div id="wpt-2fa-totp-secret-display"></div>
                        <p style="margin-top:10px;">
                            <label for="wpt-2fa-verify-code"><?php esc_html_e( 'Verification Code:', 'wptransformed' ); ?></label><br>
                            <input type="text" id="wpt-2fa-verify-code" class="regular-text" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000">
                            <button type="button" class="button" id="wpt-2fa-confirm-totp"><?php esc_html_e( 'Verify & Enable', 'wptransformed' ); ?></button>
                        </p>
                        <div id="wpt-2fa-setup-result" style="margin-top:10px;"></div>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Recovery Codes', 'wptransformed' ); ?></th>
                <td>
                    <?php if ( $has_recovery ) : ?>
                        <p><?php esc_html_e( 'Recovery codes are configured.', 'wptransformed' ); ?></p>
                    <?php endif; ?>
                    <button type="button" class="button" id="wpt-2fa-gen-recovery">
                        <?php echo $has_recovery ? esc_html__( 'Regenerate Recovery Codes', 'wptransformed' ) : esc_html__( 'Generate Recovery Codes', 'wptransformed' ); ?>
                    </button>
                    <div id="wpt-2fa-recovery-codes" style="display:none;margin-top:10px;">
                        <p><strong><?php esc_html_e( 'Save these codes in a secure place. Each code can only be used once.', 'wptransformed' ); ?></strong></p>
                        <pre id="wpt-2fa-recovery-list" style="background:#f0f0f1;padding:15px;font-size:14px;"></pre>
                    </div>
                </td>
            </tr>
        </table>

        <script>
        (function(){
            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            function post(action, data, callback) {
                var params = 'action=' + action + '&_wpnonce=' + nonce;
                for (var key in data) { params += '&' + key + '=' + encodeURIComponent(data[key]); }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    var resp;
                    try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = { success: false }; }
                    callback(resp);
                };
                xhr.send(params);
            }

            var setupBtn = document.getElementById('wpt-2fa-setup-totp');
            if (setupBtn) {
                setupBtn.addEventListener('click', function() {
                    this.disabled = true;
                    post('wpt_2fa_generate_totp', {}, function(resp) {
                        if (resp.success) {
                            document.getElementById('wpt-2fa-totp-setup-area').style.display = '';
                            document.getElementById('wpt-2fa-totp-secret-display').innerHTML = resp.data.html;
                        }
                        setupBtn.disabled = false;
                    });
                });
            }

            var confirmBtn = document.getElementById('wpt-2fa-confirm-totp');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function() {
                    var code = document.getElementById('wpt-2fa-verify-code').value;
                    if (!code) return;
                    this.disabled = true;
                    var btn = this;
                    post('wpt_2fa_verify_totp', { code: code }, function(resp) {
                        var result = document.getElementById('wpt-2fa-setup-result');
                        if (resp.success) {
                            result.innerHTML = '<div class="notice notice-success inline"><p>' + resp.data + '</p></div>';
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            result.innerHTML = '<div class="notice notice-error inline"><p>' + resp.data + '</p></div>';
                            btn.disabled = false;
                        }
                    });
                });
            }

            var disableBtn = document.getElementById('wpt-2fa-disable');
            if (disableBtn) {
                disableBtn.addEventListener('click', function() {
                    if (!confirm('<?php echo esc_js( __( 'Are you sure you want to disable two-factor authentication?', 'wptransformed' ) ); ?>')) return;
                    post('wpt_2fa_disable', {}, function(resp) {
                        if (resp.success) { location.reload(); }
                    });
                });
            }

            var recoveryBtn = document.getElementById('wpt-2fa-gen-recovery');
            if (recoveryBtn) {
                recoveryBtn.addEventListener('click', function() {
                    this.disabled = true;
                    var btn = this;
                    post('wpt_2fa_generate_recovery', {}, function(resp) {
                        if (resp.success) {
                            var container = document.getElementById('wpt-2fa-recovery-codes');
                            container.style.display = '';
                            document.getElementById('wpt-2fa-recovery-list').textContent = resp.data.codes.join('\n');
                        }
                        btn.disabled = false;
                    });
                });
            }
        })();
        </script>
        <?php
    }

    // =====================================================================
    //  ADMIN UI (MODULE SETTINGS)
    // =====================================================================

    public function render_settings(): void {
        $settings = $this->get_settings();
        $roles    = wp_roles()->get_names();
        $methods  = [
            'totp'     => __( 'Authenticator App (TOTP)', 'wptransformed' ),
            'email'    => __( 'Email Code', 'wptransformed' ),
            'recovery' => __( 'Recovery Codes', 'wptransformed' ),
        ];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enabled for Roles', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $roles as $role_slug => $role_name ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_enabled_for[]"
                                       value="<?php echo esc_attr( $role_slug ); ?>"
                                       <?php checked( in_array( $role_slug, (array) $settings['enabled_for'], true ) ); ?>>
                                <?php echo esc_html( translate_user_role( $role_name ) ); ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'Users with these roles will be required to set up 2FA.', 'wptransformed' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Allowed Methods', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $methods as $method_key => $method_label ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_methods[]"
                                       value="<?php echo esc_attr( $method_key ); ?>"
                                       <?php checked( in_array( $method_key, (array) $settings['methods'], true ) ); ?>>
                                <?php echo esc_html( $method_label ); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt_grace_period_days"><?php esc_html_e( 'Grace Period (days)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="wpt_grace_period_days"
                           name="wpt_grace_period_days"
                           value="<?php echo esc_attr( (string) $settings['grace_period_days'] ); ?>"
                           min="0"
                           max="90"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Number of days users can log in without 2FA after being prompted to set it up. Set to 0 to require immediately.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $valid_roles   = array_keys( wp_roles()->get_names() );
        $valid_methods = [ 'totp', 'email', 'recovery' ];

        $enabled_for = isset( $raw['wpt_enabled_for'] ) && is_array( $raw['wpt_enabled_for'] )
            ? array_values( array_intersect( array_map( 'sanitize_key', $raw['wpt_enabled_for'] ), $valid_roles ) )
            : [];

        $methods = isset( $raw['wpt_methods'] ) && is_array( $raw['wpt_methods'] )
            ? array_values( array_intersect( array_map( 'sanitize_key', $raw['wpt_methods'] ), $valid_methods ) )
            : [ 'totp' ];

        $grace_days = isset( $raw['wpt_grace_period_days'] ) ? (int) $raw['wpt_grace_period_days'] : 7;
        $grace_days = max( 0, min( 90, $grace_days ) );

        return [
            'enabled_for'       => $enabled_for,
            'methods'           => $methods,
            'grace_period_days' => $grace_days,
        ];
    }

    // =====================================================================
    //  CLEANUP
    // =====================================================================

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => 'wpt_2fa_totp_secret' ],
            [ 'type' => 'user_meta', 'key' => 'wpt_2fa_totp_pending' ],
            [ 'type' => 'user_meta', 'key' => 'wpt_2fa_recovery_codes' ],
            [ 'type' => 'user_meta', 'key' => 'wpt_2fa_setup_prompted' ],
            [ 'type' => 'user_meta', 'key' => 'wpt_2fa_email_code' ],
            [ 'type' => 'user_meta', 'key' => 'wpt_2fa_email_code_expires' ],
        ];
    }
}
