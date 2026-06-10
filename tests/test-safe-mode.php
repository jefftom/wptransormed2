<?php
declare(strict_types=1);

/**
 * PHPUnit tests for Safe_Mode activation and chrome bypass.
 *
 * Tests behavioral correctness: token validation, the decision-3
 * requirement that activation needs token AND an administrator-capable
 * user (manage_wpt primary, manage_options emergency fallback), and the
 * Admin Chrome Safety Contract bypass (no chrome hooks in Safe Mode).
 *
 * @package WPTransformed
 */

use WPTransformed\Core\Admin;
use WPTransformed\Core\Permission_Manager;
use WPTransformed\Core\Safe_Mode;

class Test_Safe_Mode extends WP_UnitTestCase {

    private string $token;

    public function setUp(): void {
        parent::setUp();
        $this->token = Safe_Mode::generate_token();
        // is_admin() must be true for Safe Mode checks.
        set_current_screen( 'dashboard' );
        Permission_Manager::grant_to_administrator();
    }

    public function tearDown(): void {
        unset( $_GET['wpt_safe_mode'], $GLOBALS['current_screen'] );
        Permission_Manager::remove_from_all_roles();
        delete_option( 'wpt_caps_version' );
        parent::tearDown();
    }

    private function login_as( string $role ): int {
        $user_id = self::factory()->user->create( [ 'role' => $role ] );
        wp_set_current_user( $user_id );
        return $user_id;
    }

    // ── Activation requirements ───────────────────────────────

    public function test_inactive_without_param(): void {
        $this->login_as( 'administrator' );
        $this->assertFalse( Safe_Mode::is_active() );
    }

    public function test_inactive_with_wrong_token(): void {
        $this->login_as( 'administrator' );
        $_GET['wpt_safe_mode'] = 'wrong-token';
        $this->assertFalse( Safe_Mode::is_active() );
    }

    public function test_inactive_for_anonymous_user_with_valid_token(): void {
        wp_set_current_user( 0 );
        $_GET['wpt_safe_mode'] = $this->token;
        $this->assertFalse( Safe_Mode::is_active() );
    }

    public function test_inactive_for_editor_with_valid_token(): void {
        $this->login_as( 'editor' );
        $_GET['wpt_safe_mode'] = $this->token;
        $this->assertFalse( Safe_Mode::is_active(), 'A bare token must not be sufficient for non-admin users.' );
    }

    public function test_active_for_administrator_with_valid_token(): void {
        $this->login_as( 'administrator' );
        $_GET['wpt_safe_mode'] = $this->token;
        $this->assertTrue( Safe_Mode::is_active() );
    }

    public function test_manage_options_fallback_when_wpt_caps_missing(): void {
        // Emergency-recovery scenario: WPT caps never granted.
        Permission_Manager::remove_from_all_roles();
        $this->login_as( 'administrator' ); // still has manage_options
        $_GET['wpt_safe_mode'] = $this->token;
        $this->assertTrue( Safe_Mode::is_active() );
    }

    public function test_inactive_with_empty_stored_token(): void {
        update_option( Safe_Mode::TOKEN_OPTION, '' );
        $this->login_as( 'administrator' );
        $_GET['wpt_safe_mode'] = $this->token;
        $this->assertFalse( Safe_Mode::is_active() );
    }

    // ── Chrome bypass (Admin Chrome Safety Contract) ──────────

    public function test_chrome_hooks_absent_in_safe_mode(): void {
        $this->login_as( 'administrator' );
        $_GET['wpt_safe_mode'] = $this->token;

        $admin = new Admin();

        $this->assertFalse( has_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_global_assets' ] ) );
        $this->assertFalse( has_action( 'admin_menu', [ $admin, 'inject_section_labels' ] ) );
        $this->assertFalse( has_filter( 'admin_body_class', [ $admin, 'add_body_classes' ] ) );
        $this->assertFalse( has_action( 'admin_head', [ $admin, 'inject_topbar_space' ] ) );
    }

    public function test_chrome_hooks_present_outside_safe_mode(): void {
        $this->login_as( 'administrator' );

        $admin = new Admin();

        $this->assertNotFalse( has_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_global_assets' ] ) );
        $this->assertNotFalse( has_action( 'admin_menu', [ $admin, 'inject_section_labels' ] ) );
        $this->assertNotFalse( has_filter( 'admin_body_class', [ $admin, 'add_body_classes' ] ) );
        $this->assertNotFalse( has_action( 'admin_head', [ $admin, 'inject_topbar_space' ] ) );
    }
}
