<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * CAPTCHA Protection -- Protect forms with reCAPTCHA v2/v3, hCaptcha, or Turnstile.
 *
 * Features:
 *  - Support for reCAPTCHA v2, reCAPTCHA v3, hCaptcha, Cloudflare Turnstile
 *  - Protect login, registration, comments, lost password forms
 *  - Server-side verification via HTTP POST to provider
 *  - Provider JS loaded only on pages with forms
 *
 * @package WPTransformed
 */
class Captcha_Protection extends Module_Base {

    /**
     * Provider configuration: script URL and verify URL templates.
     */
    private const PROVIDERS = [
        'recaptcha_v2' => [
            'script' => 'https://www.google.com/recaptcha/api.js',
            'verify' => 'https://www.google.com/recaptcha/api/siteverify',
            'field'  => 'g-recaptcha-response',
        ],
        'recaptcha_v3' => [
            'script' => 'https://www.google.com/recaptcha/api.js?render=%s',
            'verify' => 'https://www.google.com/recaptcha/api/siteverify',
            'field'  => 'g-recaptcha-response',
        ],
        'hcaptcha' => [
            'script' => 'https://js.hcaptcha.com/1/api.js',
            'verify' => 'https://hcaptcha.com/siteverify',
            'field'  => 'h-captcha-response',
        ],
        'turnstile' => [
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'field'  => 'cf-turnstile-response',
        ],
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'captcha-protection';
    }

    public function get_title(): string {
        return __( 'CAPTCHA Protection', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Protect login, registration, comment, and lost password forms with reCAPTCHA, hCaptcha, or Cloudflare Turnstile.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'provider'   => 'turnstile',
            'site_key'   => '',
            'secret_key' => '',
            'enable_on'  => [ 'login', 'register', 'comments', 'lost_password' ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['site_key'] ) || empty( $settings['secret_key'] ) ) {
            return;
        }

        $enabled = is_array( $settings['enable_on'] ) ? $settings['enable_on'] : [];

        // Output CAPTCHA widget on forms.
        if ( in_array( 'login', $enabled, true ) ) {
            add_action( 'login_form', [ $this, 'render_widget' ] );
            add_filter( 'authenticate', [ $this, 'verify_login' ], 999, 3 );
        }

        if ( in_array( 'register', $enabled, true ) ) {
            add_action( 'register_form', [ $this, 'render_widget' ] );
            add_filter( 'registration_errors', [ $this, 'verify_registration' ], 10, 3 );
        }

        if ( in_array( 'comments', $enabled, true ) ) {
            add_action( 'comment_form_after_fields', [ $this, 'render_widget' ] );
            add_action( 'comment_form_logged_in_after', [ $this, 'render_widget' ] );
            add_filter( 'pre_comment_on_post', [ $this, 'verify_comment' ] );
        }

        if ( in_array( 'lost_password', $enabled, true ) ) {
            add_action( 'lostpassword_form', [ $this, 'render_widget' ] );
            add_action( 'lostpassword_post', [ $this, 'verify_lost_password' ] );
        }

        // Enqueue provider JS on login pages.
        add_action( 'login_enqueue_scripts', [ $this, 'enqueue_captcha_script' ] );

        // Enqueue on frontend for comments.
        if ( in_array( 'comments', $enabled, true ) ) {
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_captcha_script_frontend' ] );
        }
    }

    // ── Widget Rendering ──────────────────────────────────────

    /**
     * Render the CAPTCHA widget HTML.
     */
    public function render_widget(): void {
        $settings = $this->get_settings();
        $provider = $settings['provider'];
        $site_key = $settings['site_key'];

        switch ( $provider ) {
            case 'recaptcha_v2':
                echo '<div class="g-recaptcha" data-sitekey="' . esc_attr( $site_key ) . '" style="margin:10px 0"></div>';
                break;

            case 'recaptcha_v3':
                echo '<input type="hidden" name="g-recaptcha-response" id="wpt-recaptcha-v3-token">';
                echo '<script>
                    grecaptcha.ready(function(){
                        grecaptcha.execute(' . wp_json_encode( $site_key ) . ',{action:"submit"}).then(function(t){
                            document.getElementById("wpt-recaptcha-v3-token").value=t;
                        });
                    });
                </script>';
                break;

            case 'hcaptcha':
                echo '<div class="h-captcha" data-sitekey="' . esc_attr( $site_key ) . '" style="margin:10px 0"></div>';
                break;

            case 'turnstile':
                echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '" style="margin:10px 0"></div>';
                break;
        }
    }

    // ── Script Enqueue ────────────────────────────────────────

    public function enqueue_captcha_script(): void {
        $this->do_enqueue_script();
    }

    public function enqueue_captcha_script_frontend(): void {
        if ( ! is_singular() || ! comments_open() ) {
            return;
        }
        $this->do_enqueue_script();
    }

    private function do_enqueue_script(): void {
        $url = $this->get_script_url();
        if ( $url ) {
            wp_enqueue_script( 'wpt-captcha-provider', $url, [], null, true );
        }
    }

    /**
     * Get the script URL for the current provider.
     *
     * @return string
     */
    private function get_script_url(): string {
        $settings = $this->get_settings();
        $provider = $settings['provider'];

        if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
            return '';
        }

        $script = self::PROVIDERS[ $provider ]['script'];

        if ( $provider === 'recaptcha_v3' ) {
            return sprintf( $script, rawurlencode( $settings['site_key'] ) );
        }

        return $script;
    }

    // ── Verification ──────────────────────────────────────────

    /**
     * Verify CAPTCHA on login.
     *
     * @param mixed  $user     User or WP_Error.
     * @param string $username Username.
     * @param string $password Password.
     * @return mixed
     */
    public function verify_login( $user, $username, $password ) {
        if ( empty( $username ) ) {
            return $user;
        }

        if ( ! $this->verify_response() ) {
            return new \WP_Error(
                'wpt_captcha_failed',
                __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'wptransformed' )
            );
        }

        return $user;
    }

    /**
     * Verify CAPTCHA on registration.
     *
     * @param \WP_Error $errors Errors object.
     * @param string    $login  Sanitized username.
     * @param string    $email  User email.
     * @return \WP_Error
     */
    public function verify_registration( $errors, $login, $email ) {
        if ( ! $this->verify_response() ) {
            $errors->add(
                'wpt_captcha_failed',
                __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'wptransformed' )
            );
        }
        return $errors;
    }

    /**
     * Verify CAPTCHA on comment submission.
     *
     * @param int $comment_post_id Post ID.
     */
    public function verify_comment( $comment_post_id ): void {
        if ( is_user_logged_in() && current_user_can( 'moderate_comments' ) ) {
            return;
        }

        if ( ! $this->verify_response() ) {
            wp_die(
                esc_html__( 'CAPTCHA verification failed. Please go back and try again.', 'wptransformed' ),
                esc_html__( 'Comment Submission Failed', 'wptransformed' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }
    }

    /**
     * Verify CAPTCHA on lost password.
     *
     * @param \WP_Error $errors Errors object.
     */
    public function verify_lost_password( $errors ): void {
        if ( ! $this->verify_response() ) {
            $errors->add(
                'wpt_captcha_failed',
                __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'wptransformed' )
            );
        }
    }

    /**
     * Verify the CAPTCHA response token with the provider.
     *
     * @return bool
     */
    private function verify_response(): bool {
        $settings   = $this->get_settings();
        $provider   = $settings['provider'];
        $secret_key = $settings['secret_key'];

        if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
            return false;
        }

        $field_name = self::PROVIDERS[ $provider ]['field'];
        $verify_url = self::PROVIDERS[ $provider ]['verify'];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- CAPTCHA token comes from provider widget.
        $token = isset( $_POST[ $field_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) ) : '';

        if ( empty( $token ) ) {
            return false;
        }

        $body = [
            'secret'   => $secret_key,
            'response' => $token,
        ];

        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $body['remoteip'] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        $response = wp_remote_post( $verify_url, [
            'body'    => $body,
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        return ! empty( $result['success'] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings  = $this->get_settings();
        $enable_on = is_array( $settings['enable_on'] ) ? $settings['enable_on'] : [];
        $forms     = [
            'login'         => __( 'Login Form', 'wptransformed' ),
            'register'      => __( 'Registration Form', 'wptransformed' ),
            'comments'      => __( 'Comment Form', 'wptransformed' ),
            'lost_password' => __( 'Lost Password Form', 'wptransformed' ),
        ];
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-captcha-provider"><?php esc_html_e( 'CAPTCHA Provider', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-captcha-provider" name="wpt_provider">
                        <option value="turnstile" <?php selected( $settings['provider'], 'turnstile' ); ?>>
                            <?php esc_html_e( 'Cloudflare Turnstile', 'wptransformed' ); ?>
                        </option>
                        <option value="recaptcha_v2" <?php selected( $settings['provider'], 'recaptcha_v2' ); ?>>
                            <?php esc_html_e( 'reCAPTCHA v2', 'wptransformed' ); ?>
                        </option>
                        <option value="recaptcha_v3" <?php selected( $settings['provider'], 'recaptcha_v3' ); ?>>
                            <?php esc_html_e( 'reCAPTCHA v3', 'wptransformed' ); ?>
                        </option>
                        <option value="hcaptcha" <?php selected( $settings['provider'], 'hcaptcha' ); ?>>
                            <?php esc_html_e( 'hCaptcha', 'wptransformed' ); ?>
                        </option>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-captcha-site-key"><?php esc_html_e( 'Site Key', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-captcha-site-key" name="wpt_site_key"
                           value="<?php echo esc_attr( $settings['site_key'] ); ?>"
                           class="regular-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-captcha-secret-key"><?php esc_html_e( 'Secret Key', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="password" id="wpt-captcha-secret-key" name="wpt_secret_key"
                           value="<?php echo esc_attr( $settings['secret_key'] ); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e( 'Obtain keys from your CAPTCHA provider dashboard.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Enable On', 'wptransformed' ); ?></th>
                <td>
                    <?php foreach ( $forms as $key => $label ) : ?>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" name="wpt_enable_on[]" value="<?php echo esc_attr( $key ); ?>"
                                   <?php checked( in_array( $key, $enable_on, true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $valid_providers = [ 'turnstile', 'recaptcha_v2', 'recaptcha_v3', 'hcaptcha' ];
        $valid_forms     = [ 'login', 'register', 'comments', 'lost_password' ];

        $provider = isset( $raw['wpt_provider'] ) ? sanitize_text_field( $raw['wpt_provider'] ) : 'turnstile';

        $enable_on = [];
        if ( isset( $raw['wpt_enable_on'] ) && is_array( $raw['wpt_enable_on'] ) ) {
            foreach ( $raw['wpt_enable_on'] as $form ) {
                $form = sanitize_text_field( $form );
                if ( in_array( $form, $valid_forms, true ) ) {
                    $enable_on[] = $form;
                }
            }
        }

        return [
            'provider'   => in_array( $provider, $valid_providers, true ) ? $provider : 'turnstile',
            'site_key'   => sanitize_text_field( $raw['wpt_site_key'] ?? '' ),
            'secret_key' => sanitize_text_field( $raw['wpt_secret_key'] ?? '' ),
            'enable_on'  => $enable_on,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No admin-specific assets needed.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'settings' => true,
        ];
    }
}
