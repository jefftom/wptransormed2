<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Email SMTP -- Configure WordPress to send emails via SMTP instead of PHP mail().
 *
 * Features:
 *  - PHPMailer SMTP configuration via phpmailer_init
 *  - From email/name override with force option
 *  - AES-256-CBC password encryption using AUTH_KEY / SECURE_AUTH_KEY
 *  - Fallback to base64 when openssl unavailable (with admin warning)
 *  - Test email AJAX handler with debug output
 *  - Common SMTP provider help table
 *  - Password masking (never displayed after saving)
 *
 * @package WPTransformed
 */
class Email_SMTP extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'email-smtp';
    }

    public function get_title(): string {
        return __( 'Email SMTP', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Configure WordPress to send emails via SMTP instead of PHP mail(), with test email feature.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'from_email'     => '',
            'from_name'      => '',
            'smtp_host'      => '',
            'smtp_port'      => 587,
            'encryption'     => 'tls',
            'authentication' => true,
            'username'       => '',
            'password'       => '',
            'force_from'     => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        // Only configure SMTP if host is set.
        if ( ! empty( $settings['smtp_host'] ) ) {
            add_action( 'phpmailer_init', [ $this, 'configure_phpmailer' ], 10 );
        }

        // From email/name overrides.
        if ( ! empty( $settings['force_from'] ) ) {
            if ( ! empty( $settings['from_email'] ) ) {
                add_filter( 'wp_mail_from', [ $this, 'filter_mail_from' ], 999 );
            }
            add_filter( 'wp_mail_from_name', [ $this, 'filter_mail_from_name' ], 999 );
        }

        // Test email AJAX handler.
        add_action( 'wp_ajax_wpt_send_test_email', [ $this, 'ajax_send_test_email' ] );
    }

    // ── PHPMailer Configuration ───────────────────────────────

    /**
     * Configure PHPMailer to use SMTP.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
     */
    public function configure_phpmailer( $phpmailer ): void {
        $settings = $this->get_settings();

        $phpmailer->isSMTP();
        $phpmailer->Host = $settings['smtp_host'];
        $phpmailer->Port = (int) $settings['smtp_port'];

        // Encryption.
        switch ( $settings['encryption'] ) {
            case 'ssl':
                $phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'tls':
                $phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                break;
            default:
                $phpmailer->SMTPSecure = '';
                $phpmailer->SMTPAutoTLS = false;
                break;
        }

        // Authentication.
        if ( ! empty( $settings['authentication'] ) && ! empty( $settings['username'] ) ) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $settings['username'];
            $phpmailer->Password = $this->decrypt_password( $settings['password'] );
        } else {
            $phpmailer->SMTPAuth = false;
        }
    }

    // ── From Email/Name Filters ───────────────────────────────

    /**
     * Override the From email address.
     *
     * @param string $from_email Default From email.
     * @return string
     */
    public function filter_mail_from( string $from_email ): string {
        $settings = $this->get_settings();
        $custom   = $settings['from_email'] ?? '';

        return ! empty( $custom ) ? $custom : $from_email;
    }

    /**
     * Override the From name.
     *
     * @param string $from_name Default From name.
     * @return string
     */
    public function filter_mail_from_name( string $from_name ): string {
        $settings = $this->get_settings();
        $custom   = $settings['from_name'] ?? '';

        return ! empty( $custom ) ? $custom : get_bloginfo( 'name' );
    }

    // ── Test Email AJAX Handler ───────────────────────────────

    /**
     * Send a test email via AJAX.
     */
    public function ajax_send_test_email(): void {
        check_ajax_referer( 'wpt_test_email_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';

        if ( empty( $recipient ) || ! is_email( $recipient ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'wptransformed' ) ] );
        }

        // Capture PHPMailer debug output.
        $debug_output = '';
        add_action( 'phpmailer_init', function ( $phpmailer ) use ( &$debug_output ) {
            $phpmailer->SMTPDebug = 2;
            $phpmailer->Debugoutput = function ( $str ) use ( &$debug_output ) {
                $debug_output .= $str;
            };
        }, 999 );

        $subject = sprintf(
            /* translators: %s: site name */
            __( 'WPTransformed SMTP Test - %s', 'wptransformed' ),
            get_bloginfo( 'name' )
        );

        $message = sprintf(
            /* translators: 1: site name, 2: date/time */
            __( "This is a test email from %1\$s.\n\nSent at: %2\$s\n\nIf you received this email, your SMTP settings are working correctly.", 'wptransformed' ),
            get_bloginfo( 'name' ),
            current_time( 'mysql' )
        );

        $result = wp_mail( $recipient, $subject, $message );

        if ( $result ) {
            wp_send_json_success( [
                'message' => sprintf(
                    /* translators: %s: recipient email */
                    __( 'Test email sent successfully to %s.', 'wptransformed' ),
                    $recipient
                ),
            ] );
        } else {
            // Get the last PHPMailer error.
            global $phpmailer;
            $error_message = '';

            if ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
                $error_message = $phpmailer->ErrorInfo;
            }

            $message = __( 'Failed to send test email.', 'wptransformed' );

            if ( ! empty( $error_message ) ) {
                $message .= ' ' . sprintf(
                    /* translators: %s: error details */
                    __( 'Error: %s', 'wptransformed' ),
                    $error_message
                );
            }

            if ( ! empty( $debug_output ) ) {
                $message .= "\n\n" . __( 'Debug log:', 'wptransformed' ) . "\n" . $debug_output;
            }

            wp_send_json_error( [ 'message' => $message ] );
        }
    }

    // ── Password Encryption ───────────────────────────────────

    /**
     * Encrypt a password for storage.
     *
     * @param string $plain Plain text password.
     * @return string Encrypted password.
     */
    private function encrypt_password( string $plain ): string {
        if ( empty( $plain ) ) {
            return '';
        }

        if ( ! function_exists( 'openssl_encrypt' ) ) {
            // Fallback to base64 (not secure, but functional).
            return 'base64:' . base64_encode( $plain );
        }

        $key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $iv  = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 16 );

        $encrypted = openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, $iv );

        if ( $encrypted === false ) {
            // Encryption failed — fall back to base64.
            return 'base64:' . base64_encode( $plain );
        }

        return base64_encode( $encrypted );
    }

    /**
     * Decrypt a stored password.
     *
     * @param string $encrypted Encrypted password.
     * @return string Plain text password.
     */
    private function decrypt_password( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }

        // Check for base64 fallback prefix.
        if ( strpos( $encrypted, 'base64:' ) === 0 ) {
            return base64_decode( substr( $encrypted, 7 ) );
        }

        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }

        $key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $iv  = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 16 );

        $decrypted = openssl_decrypt( base64_decode( $encrypted ), 'AES-256-CBC', $key, 0, $iv );

        if ( $decrypted === false ) {
            // Decryption failed — likely AUTH_KEY changed.
            return '';
        }

        return $decrypted;
    }

    /**
     * Check if openssl is available for encryption.
     *
     * @return bool
     */
    private function has_openssl(): bool {
        return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
    }

    /**
     * Check if the stored password can be decrypted.
     *
     * @param string $encrypted Encrypted password string.
     * @return bool
     */
    private function can_decrypt_password( string $encrypted ): bool {
        if ( empty( $encrypted ) ) {
            return true; // Nothing to decrypt.
        }

        // Base64 fallback always decrypts.
        if ( strpos( $encrypted, 'base64:' ) === 0 ) {
            return true;
        }

        $decrypted = $this->decrypt_password( $encrypted );
        return $decrypted !== '';
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings     = $this->get_settings();
        $has_password = ! empty( $settings['password'] );
        $can_decrypt  = $has_password ? $this->can_decrypt_password( $settings['password'] ) : true;
        $has_ssl      = $this->has_openssl();

        ?>

        <?php if ( ! $has_ssl ) : ?>
        <div class="notice notice-warning inline" style="margin-bottom: 16px;">
            <p>
                <strong><?php esc_html_e( 'Warning:', 'wptransformed' ); ?></strong>
                <?php esc_html_e( 'OpenSSL extension is not available. SMTP passwords will be stored with basic encoding only. For proper encryption, enable the OpenSSL PHP extension.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if ( $has_password && ! $can_decrypt ) : ?>
        <div class="notice notice-error inline" style="margin-bottom: 16px;">
            <p>
                <strong><?php esc_html_e( 'Password needs re-entry:', 'wptransformed' ); ?></strong>
                <?php esc_html_e( 'The stored SMTP password cannot be decrypted. This usually happens when WordPress security keys (AUTH_KEY) have been changed. Please re-enter your SMTP password below.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <table class="form-table wpt-email-smtp-settings" role="presentation">
            <!-- From Email -->
            <tr>
                <th scope="row">
                    <label for="wpt-from-email"><?php esc_html_e( 'From Email', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="email" id="wpt-from-email" name="wpt_from_email"
                           value="<?php echo esc_attr( $settings['from_email'] ); ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'The email address that WordPress emails are sent from.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- From Name -->
            <tr>
                <th scope="row">
                    <label for="wpt-from-name"><?php esc_html_e( 'From Name', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-from-name" name="wpt_from_name"
                           value="<?php echo esc_attr( $settings['from_name'] ); ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'The name that emails are sent from. Defaults to site name.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Force From -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Force From', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_force_from" value="1"
                               <?php checked( ! empty( $settings['force_from'] ) ); ?>>
                        <?php esc_html_e( 'Force the From email and name for all outgoing emails', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Override From values set by other plugins. Uses high priority (999) to ensure it takes effect.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- SMTP Host -->
            <tr>
                <th scope="row">
                    <label for="wpt-smtp-host"><?php esc_html_e( 'SMTP Host', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-smtp-host" name="wpt_smtp_host"
                           value="<?php echo esc_attr( $settings['smtp_host'] ); ?>"
                           class="regular-text"
                           placeholder="smtp.example.com">
                </td>
            </tr>

            <!-- SMTP Port -->
            <tr>
                <th scope="row">
                    <label for="wpt-smtp-port"><?php esc_html_e( 'SMTP Port', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-smtp-port" name="wpt_smtp_port"
                           value="<?php echo esc_attr( (string) $settings['smtp_port'] ); ?>"
                           class="small-text"
                           min="1" max="65535"
                           placeholder="587">
                    <p class="description">
                        <?php esc_html_e( 'Common ports: 587 (TLS), 465 (SSL), 25 (None).', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <!-- Encryption -->
            <tr>
                <th scope="row">
                    <label for="wpt-encryption"><?php esc_html_e( 'Encryption', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-encryption" name="wpt_encryption">
                        <option value="none" <?php selected( $settings['encryption'], 'none' ); ?>>
                            <?php esc_html_e( 'None', 'wptransformed' ); ?>
                        </option>
                        <option value="ssl" <?php selected( $settings['encryption'], 'ssl' ); ?>>
                            <?php esc_html_e( 'SSL', 'wptransformed' ); ?>
                        </option>
                        <option value="tls" <?php selected( $settings['encryption'], 'tls' ); ?>>
                            <?php esc_html_e( 'TLS', 'wptransformed' ); ?>
                        </option>
                    </select>
                </td>
            </tr>

            <!-- Authentication -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Authentication', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_authentication" value="1"
                               id="wpt-authentication"
                               <?php checked( ! empty( $settings['authentication'] ) ); ?>>
                        <?php esc_html_e( 'Use SMTP authentication', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <!-- Username -->
            <tr class="wpt-auth-fields" <?php echo empty( $settings['authentication'] ) ? 'style="display:none;"' : ''; ?>>
                <th scope="row">
                    <label for="wpt-username"><?php esc_html_e( 'Username', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-username" name="wpt_username"
                           value="<?php echo esc_attr( $settings['username'] ); ?>"
                           class="regular-text"
                           autocomplete="off">
                </td>
            </tr>

            <!-- Password -->
            <tr class="wpt-auth-fields" <?php echo empty( $settings['authentication'] ) ? 'style="display:none;"' : ''; ?>>
                <th scope="row">
                    <label for="wpt-password"><?php esc_html_e( 'Password', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <div id="wpt-password-display" <?php echo ! $has_password ? 'style="display:none;"' : ''; ?>>
                        <code>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code>
                        <button type="button" class="button button-small" id="wpt-change-password">
                            <?php esc_html_e( 'Change', 'wptransformed' ); ?>
                        </button>
                    </div>
                    <div id="wpt-password-input" <?php echo $has_password ? 'style="display:none;"' : ''; ?>>
                        <input type="password" id="wpt-password" name="wpt_password"
                               value="" class="regular-text"
                               autocomplete="new-password">
                        <?php if ( $has_password ) : ?>
                        <button type="button" class="button button-small" id="wpt-cancel-password">
                            <?php esc_html_e( 'Cancel', 'wptransformed' ); ?>
                        </button>
                        <?php endif; ?>
                        <p class="description">
                            <?php esc_html_e( 'Password is encrypted before storage.', 'wptransformed' ); ?>
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Common SMTP Providers -->
        <div class="wpt-smtp-providers">
            <h3>
                <button type="button" class="button button-link" id="wpt-toggle-providers">
                    <?php esc_html_e( 'Common SMTP Provider Settings', 'wptransformed' ); ?>
                    <span class="dashicons dashicons-arrow-down-alt2" style="vertical-align: middle;"></span>
                </button>
            </h3>
            <div id="wpt-provider-table" style="display: none;">
                <table class="widefat fixed striped" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Provider', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Host', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Port', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Encryption', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Notes', 'wptransformed' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Gmail / Google Workspace</strong></td>
                            <td>smtp.gmail.com</td>
                            <td>587</td>
                            <td>TLS</td>
                            <td><?php esc_html_e( 'Requires App Password (2FA must be enabled)', 'wptransformed' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Outlook / Microsoft 365</strong></td>
                            <td>smtp.office365.com</td>
                            <td>587</td>
                            <td>TLS</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Yahoo Mail</strong></td>
                            <td>smtp.mail.yahoo.com</td>
                            <td>465</td>
                            <td>SSL</td>
                            <td><?php esc_html_e( 'Requires App Password', 'wptransformed' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>SendGrid</strong></td>
                            <td>smtp.sendgrid.net</td>
                            <td>587</td>
                            <td>TLS</td>
                            <td><?php esc_html_e( 'Username: apikey', 'wptransformed' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Mailgun</strong></td>
                            <td>smtp.mailgun.org</td>
                            <td>587</td>
                            <td>TLS</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Amazon SES</strong></td>
                            <td>email-smtp.[region].amazonaws.com</td>
                            <td>587</td>
                            <td>TLS</td>
                            <td><?php esc_html_e( 'Use SMTP credentials, not IAM', 'wptransformed' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Zoho Mail</strong></td>
                            <td>smtp.zoho.com</td>
                            <td>465</td>
                            <td>SSL</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Test Email Section -->
        <div class="wpt-test-email-section">
            <h3><?php esc_html_e( 'Send Test Email', 'wptransformed' ); ?></h3>
            <p class="description" style="margin-bottom: 10px;">
                <?php esc_html_e( 'Send a test email to verify your SMTP settings are working correctly. Save your settings first.', 'wptransformed' ); ?>
            </p>
            <div class="wpt-test-email-form">
                <input type="email" id="wpt-test-recipient"
                       value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
                       class="regular-text"
                       placeholder="<?php esc_attr_e( 'recipient@example.com', 'wptransformed' ); ?>">
                <button type="button" class="button button-secondary" id="wpt-send-test">
                    <?php esc_html_e( 'Send Test Email', 'wptransformed' ); ?>
                </button>
                <span class="spinner" id="wpt-test-spinner" style="float: none;"></span>
            </div>
            <div id="wpt-test-result" style="display: none; margin-top: 10px;"></div>
        </div>

        <?php
        // Hidden field for test email nonce.
        wp_nonce_field( 'wpt_test_email_nonce', 'wpt_test_email_nonce_field', false );
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // From email.
        $from_email = isset( $raw['wpt_from_email'] ) ? sanitize_email( $raw['wpt_from_email'] ) : '';

        // From name.
        $from_name = isset( $raw['wpt_from_name'] ) ? sanitize_text_field( $raw['wpt_from_name'] ) : '';

        // Force from.
        $force_from = ! empty( $raw['wpt_force_from'] );

        // SMTP host.
        $smtp_host = isset( $raw['wpt_smtp_host'] ) ? sanitize_text_field( $raw['wpt_smtp_host'] ) : '';

        // SMTP port -- must be numeric, between 1 and 65535.
        $smtp_port = isset( $raw['wpt_smtp_port'] ) ? absint( $raw['wpt_smtp_port'] ) : 587;
        if ( $smtp_port < 1 || $smtp_port > 65535 ) {
            $smtp_port = 587;
        }

        // Encryption.
        $encryption = isset( $raw['wpt_encryption'] ) ? sanitize_key( $raw['wpt_encryption'] ) : 'tls';
        if ( ! in_array( $encryption, [ 'none', 'ssl', 'tls' ], true ) ) {
            $encryption = 'tls';
        }

        // Authentication.
        $authentication = ! empty( $raw['wpt_authentication'] );

        // Username.
        $username = isset( $raw['wpt_username'] ) ? sanitize_text_field( $raw['wpt_username'] ) : '';

        // Password -- only update if a new password is provided.
        $current_settings = $this->get_settings();
        $password = $current_settings['password'] ?? '';

        if ( isset( $raw['wpt_password'] ) && $raw['wpt_password'] !== '' ) {
            $password = $this->encrypt_password( sanitize_text_field( $raw['wpt_password'] ) );
        }

        return [
            'from_email'     => $from_email,
            'from_name'      => $from_name,
            'force_from'     => $force_from,
            'smtp_host'      => $smtp_host,
            'smtp_port'      => $smtp_port,
            'encryption'     => $encryption,
            'authentication' => $authentication,
            'username'       => $username,
            'password'       => $password,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Only load on WPTransformed settings page.
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-email-smtp',
            WPT_URL . 'modules/utilities/css/email-smtp.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-email-smtp',
            WPT_URL . 'modules/utilities/js/email-smtp.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-email-smtp', 'wptEmailSmtp', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_test_email_nonce' ),
            'i18n'    => [
                'sending'       => __( 'Sending...', 'wptransformed' ),
                'send'          => __( 'Send Test Email', 'wptransformed' ),
                'emptyEmail'    => __( 'Please enter a recipient email address.', 'wptransformed' ),
                'invalidEmail'  => __( 'Please enter a valid email address.', 'wptransformed' ),
                'networkError'  => __( 'Network error. Please try again.', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
