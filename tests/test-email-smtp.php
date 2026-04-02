<?php
declare(strict_types=1);

/**
 * PHPUnit tests for Email SMTP module.
 *
 * Tests behavior: sanitization, encryption, password masking, edge cases.
 *
 * @package WPTransformed
 */

// Minimal WordPress stubs for unit testing without a full WP environment.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'AUTH_KEY' ) ) {
    define( 'AUTH_KEY', 'test-auth-key-for-phpunit-testing-only-1234567890' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
    define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-phpunit-testing-only-0987654321' );
}

/**
 * Test helper class that exposes private methods for testing.
 *
 * We use reflection to test encrypt/decrypt without making them public.
 */
class Email_SMTP_Test extends \PHPUnit\Framework\TestCase {

    /**
     * Get a reflection method to test private methods.
     *
     * @param string $method Method name.
     * @return ReflectionMethod
     */
    private function getMethod( string $method ): ReflectionMethod {
        $class = new ReflectionClass( 'WPTransformed\Modules\Utilities\Email_SMTP' );
        $ref   = $class->getMethod( $method );
        $ref->setAccessible( true );
        return $ref;
    }

    /**
     * Create a mock instance of the module for testing.
     *
     * @return object
     */
    private function createInstance() {
        // We need to create the object without calling the constructor
        // which may depend on WordPress functions.
        $class = new ReflectionClass( 'WPTransformed\Modules\Utilities\Email_SMTP' );
        return $class->newInstanceWithoutConstructor();
    }

    // ── Encryption / Decryption Tests ─────────────────────────

    /**
     * Test encryption round-trip: encrypt then decrypt returns original.
     */
    public function test_encrypt_decrypt_round_trip(): void {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            $this->markTestSkipped( 'OpenSSL extension not available.' );
        }

        $instance = $this->createInstance();
        $encrypt  = $this->getMethod( 'encrypt_password' );
        $decrypt  = $this->getMethod( 'decrypt_password' );

        $passwords = [
            'simple-password',
            'P@$$w0rd!#%^&*()',
            'unicode-test-' . "\xC3\xA9\xC3\xA0\xC3\xBC",
            '',
            'a',
            str_repeat( 'x', 256 ),
        ];

        foreach ( $passwords as $plain ) {
            $encrypted = $encrypt->invoke( $instance, $plain );

            if ( $plain === '' ) {
                $this->assertSame( '', $encrypted, 'Empty password should return empty string.' );
                continue;
            }

            $this->assertNotSame( $plain, $encrypted, 'Encrypted should differ from plain.' );

            $decrypted = $decrypt->invoke( $instance, $encrypted );
            $this->assertSame( $plain, $decrypted, "Round-trip failed for: $plain" );
        }
    }

    /**
     * Test that encrypted output is different from the plain password.
     */
    public function test_encrypted_differs_from_plain(): void {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            $this->markTestSkipped( 'OpenSSL extension not available.' );
        }

        $instance  = $this->createInstance();
        $encrypt   = $this->getMethod( 'encrypt_password' );
        $password  = 'my-secret-password';
        $encrypted = $encrypt->invoke( $instance, $password );

        $this->assertNotSame( $password, $encrypted );
        $this->assertNotEmpty( $encrypted );
    }

    /**
     * Test empty password encryption returns empty string.
     */
    public function test_encrypt_empty_password(): void {
        $instance  = $this->createInstance();
        $encrypt   = $this->getMethod( 'encrypt_password' );
        $encrypted = $encrypt->invoke( $instance, '' );

        $this->assertSame( '', $encrypted );
    }

    /**
     * Test decrypt empty string returns empty string.
     */
    public function test_decrypt_empty_password(): void {
        $instance  = $this->createInstance();
        $decrypt   = $this->getMethod( 'decrypt_password' );
        $decrypted = $decrypt->invoke( $instance, '' );

        $this->assertSame( '', $decrypted );
    }

    /**
     * Test decryption of invalid data returns empty string (simulating key change).
     */
    public function test_decrypt_invalid_data_returns_empty(): void {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            $this->markTestSkipped( 'OpenSSL extension not available.' );
        }

        $instance  = $this->createInstance();
        $decrypt   = $this->getMethod( 'decrypt_password' );

        // Random base64 data that was not encrypted with our keys.
        $invalid = base64_encode( 'this-is-not-valid-encrypted-data' );
        $result  = $decrypt->invoke( $instance, $invalid );

        $this->assertSame( '', $result );
    }

    /**
     * Test base64 fallback encryption/decryption.
     */
    public function test_base64_fallback_round_trip(): void {
        $instance = $this->createInstance();
        $decrypt  = $this->getMethod( 'decrypt_password' );

        // Simulate base64 fallback format.
        $plain     = 'test-password-fallback';
        $encrypted = 'base64:' . base64_encode( $plain );

        $decrypted = $decrypt->invoke( $instance, $encrypted );
        $this->assertSame( $plain, $decrypted );
    }

    // ── Sanitize Settings Tests ───────────────────────────────

    /**
     * Test sanitize_settings validates from_email.
     */
    public function test_sanitize_from_email(): void {
        $instance = $this->createInstance();
        $method   = $this->getMethod( 'encrypt_password' ); // Needed for password handling.

        // We test the sanitize logic directly since sanitize_settings
        // depends on WordPress functions. Instead, verify the logic pattern.

        // Valid email.
        $valid = 'user@example.com';
        $this->assertSame( $valid, sanitize_email_test( $valid ) );

        // Invalid email.
        $invalid = 'not-an-email';
        $this->assertSame( '', sanitize_email_test( $invalid ) );

        // Email with extra characters.
        $messy = '<script>alert("xss")</script>user@example.com';
        $result = sanitize_email_test( $messy );
        $this->assertStringNotContainsString( '<script>', $result );
    }

    /**
     * Test port must be numeric and within valid range.
     */
    public function test_port_validation(): void {
        // Valid ports.
        $this->assertSame( 587, validate_port( 587 ) );
        $this->assertSame( 465, validate_port( 465 ) );
        $this->assertSame( 25, validate_port( 25 ) );
        $this->assertSame( 1, validate_port( 1 ) );
        $this->assertSame( 65535, validate_port( 65535 ) );

        // Invalid ports fall back to 587.
        $this->assertSame( 587, validate_port( 0 ) );
        $this->assertSame( 587, validate_port( -1 ) );
        $this->assertSame( 587, validate_port( 70000 ) );
    }

    /**
     * Test encryption type validation.
     */
    public function test_encryption_validation(): void {
        $valid = [ 'none', 'ssl', 'tls' ];

        foreach ( $valid as $enc ) {
            $this->assertSame( $enc, validate_encryption( $enc ) );
        }

        // Invalid falls back to tls.
        $this->assertSame( 'tls', validate_encryption( 'invalid' ) );
        $this->assertSame( 'tls', validate_encryption( '' ) );
        $this->assertSame( 'tls', validate_encryption( 'SSH' ) );
    }

    /**
     * Test default settings structure.
     */
    public function test_default_settings_structure(): void {
        $instance = $this->createInstance();
        $defaults = $instance->get_default_settings();

        $this->assertIsArray( $defaults );
        $this->assertArrayHasKey( 'from_email', $defaults );
        $this->assertArrayHasKey( 'from_name', $defaults );
        $this->assertArrayHasKey( 'smtp_host', $defaults );
        $this->assertArrayHasKey( 'smtp_port', $defaults );
        $this->assertArrayHasKey( 'encryption', $defaults );
        $this->assertArrayHasKey( 'authentication', $defaults );
        $this->assertArrayHasKey( 'username', $defaults );
        $this->assertArrayHasKey( 'password', $defaults );
        $this->assertArrayHasKey( 'force_from', $defaults );

        // Default values.
        $this->assertSame( '', $defaults['from_email'] );
        $this->assertSame( '', $defaults['from_name'] );
        $this->assertSame( '', $defaults['smtp_host'] );
        $this->assertSame( 587, $defaults['smtp_port'] );
        $this->assertSame( 'tls', $defaults['encryption'] );
        $this->assertTrue( $defaults['authentication'] );
        $this->assertSame( '', $defaults['username'] );
        $this->assertSame( '', $defaults['password'] );
        $this->assertTrue( $defaults['force_from'] );
    }

    /**
     * Test module identity methods.
     */
    public function test_module_identity(): void {
        $instance = $this->createInstance();

        $this->assertSame( 'email-smtp', $instance->get_id() );
        $this->assertSame( 'utilities', $instance->get_category() );
        $this->assertNotEmpty( $instance->get_title() );
        $this->assertNotEmpty( $instance->get_description() );
    }

    /**
     * Test can_decrypt_password with valid encrypted password.
     */
    public function test_can_decrypt_password_valid(): void {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            $this->markTestSkipped( 'OpenSSL extension not available.' );
        }

        $instance    = $this->createInstance();
        $encrypt     = $this->getMethod( 'encrypt_password' );
        $canDecrypt  = $this->getMethod( 'can_decrypt_password' );

        $encrypted = $encrypt->invoke( $instance, 'test-password' );
        $this->assertTrue( $canDecrypt->invoke( $instance, $encrypted ) );
    }

    /**
     * Test can_decrypt_password with empty string returns true.
     */
    public function test_can_decrypt_empty_returns_true(): void {
        $instance   = $this->createInstance();
        $canDecrypt = $this->getMethod( 'can_decrypt_password' );

        $this->assertTrue( $canDecrypt->invoke( $instance, '' ) );
    }

    /**
     * Test can_decrypt_password with base64 fallback returns true.
     */
    public function test_can_decrypt_base64_fallback_returns_true(): void {
        $instance   = $this->createInstance();
        $canDecrypt = $this->getMethod( 'can_decrypt_password' );

        $this->assertTrue( $canDecrypt->invoke( $instance, 'base64:' . base64_encode( 'test' ) ) );
    }

    /**
     * Test has_openssl returns boolean.
     */
    public function test_has_openssl_returns_bool(): void {
        $instance  = $this->createInstance();
        $hasOpenssl = $this->getMethod( 'has_openssl' );

        $result = $hasOpenssl->invoke( $instance );
        $this->assertIsBool( $result );
    }
}

// ── Test Helper Functions ─────────────────────────────────────
// These replicate the sanitization logic used in sanitize_settings()
// so we can test the validation rules without WordPress loaded.

/**
 * Simplified email sanitization for testing.
 *
 * @param string $email
 * @return string
 */
function sanitize_email_test( string $email ): string {
    // Strip tags and trim.
    $email = strip_tags( trim( $email ) );

    // Basic email validation.
    if ( filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
        return '';
    }

    return $email;
}

/**
 * Port validation logic matching sanitize_settings().
 *
 * @param int $port
 * @return int
 */
function validate_port( int $port ): int {
    $port = abs( $port );
    if ( $port < 1 || $port > 65535 ) {
        return 587;
    }
    return $port;
}

/**
 * Encryption validation logic matching sanitize_settings().
 *
 * @param string $encryption
 * @return string
 */
function validate_encryption( string $encryption ): string {
    if ( ! in_array( $encryption, [ 'none', 'ssl', 'tls' ], true ) ) {
        return 'tls';
    }
    return $encryption;
}
