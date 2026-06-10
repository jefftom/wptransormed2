<?php
declare(strict_types=1);

/**
 * PHPUnit tests for the Permission Model (Permission_Manager).
 *
 * Tests behavioral correctness: capability registry, activation grants,
 * version-gated upgrade migration, the wpt_user_can_manage_module filter,
 * and full-cleanup removal.
 *
 * @package WPTransformed
 */

use WPTransformed\Core\Permission_Manager;

class Test_Permission_Model extends WP_UnitTestCase {

    /**
     * Reset role mutations and options between tests.
     */
    public function tearDown(): void {
        Permission_Manager::remove_from_all_roles();
        delete_option( 'wpt_caps_version' );
        delete_option( 'wpt_admin_role_missing' );
        parent::tearDown();
    }

    // ── Capability registry ───────────────────────────────────

    public function test_caps_constant_matches_spec(): void {
        $expected = [
            'manage_wpt',
            'manage_wpt_modules',
            'manage_wpt_settings',
            'manage_wpt_client_safe',
            'manage_wpt_security',
            'manage_wpt_email',
            'manage_wpt_search_visibility',
            'manage_wpt_code',
            'manage_wpt_database',
            'manage_wpt_reports',
            'manage_wpt_integrations',
            'view_wpt_logs',
            'export_wpt_data',
            'run_wpt_dangerous_tools',
            'manage_wpt_white_label',
        ];
        $this->assertSame( $expected, Permission_Manager::CAPS );
    }

    // ── Activation grants ─────────────────────────────────────

    public function test_administrator_receives_all_caps(): void {
        Permission_Manager::grant_to_administrator();
        $role = get_role( 'administrator' );
        foreach ( Permission_Manager::CAPS as $cap ) {
            $this->assertTrue( $role->has_cap( $cap ), "administrator missing {$cap}" );
        }
    }

    public function test_editor_receives_no_caps(): void {
        Permission_Manager::grant_to_administrator();
        $editor = get_role( 'editor' );
        foreach ( Permission_Manager::CAPS as $cap ) {
            $this->assertFalse( $editor->has_cap( $cap ), "editor unexpectedly has {$cap}" );
        }
    }

    public function test_grant_records_caps_version(): void {
        Permission_Manager::grant_to_administrator();
        $this->assertNotFalse( get_option( 'wpt_caps_version' ) );
    }

    // ── Upgrade migration ─────────────────────────────────────

    public function test_maybe_upgrade_grants_when_version_missing(): void {
        delete_option( 'wpt_caps_version' );
        Permission_Manager::maybe_upgrade();
        $this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_wpt' ) );
    }

    public function test_maybe_upgrade_is_noop_when_version_current(): void {
        Permission_Manager::grant_to_administrator();
        // Simulate a manual revoke: a current version must NOT re-grant on
        // every request (spec gotcha: no role mutation per request).
        get_role( 'administrator' )->remove_cap( 'manage_wpt' );
        Permission_Manager::maybe_upgrade();
        $this->assertFalse( get_role( 'administrator' )->has_cap( 'manage_wpt' ) );
    }

    // ── Module-manage check + filter ──────────────────────────

    public function test_admin_user_can_manage_module(): void {
        Permission_Manager::grant_to_administrator();
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertTrue( Permission_Manager::user_can_manage_module( 'dark-mode', $admin ) );
    }

    public function test_editor_cannot_manage_module(): void {
        Permission_Manager::grant_to_administrator();
        $editor = self::factory()->user->create( [ 'role' => 'editor' ] );
        $this->assertFalse( Permission_Manager::user_can_manage_module( 'dark-mode', $editor ) );
    }

    public function test_filter_can_grant_module_access(): void {
        Permission_Manager::grant_to_administrator();
        $editor = self::factory()->user->create( [ 'role' => 'editor' ] );

        $grant = function ( $can, $user_id, $module_id ) use ( $editor ) {
            if ( 'dark-mode' === $module_id && $user_id === $editor ) {
                return true;
            }
            return $can;
        };
        add_filter( 'wpt_user_can_manage_module', $grant, 10, 3 );

        $this->assertTrue( Permission_Manager::user_can_manage_module( 'dark-mode', $editor ) );
        $this->assertFalse( Permission_Manager::user_can_manage_module( 'code-snippets', $editor ) );

        remove_filter( 'wpt_user_can_manage_module', $grant );
    }

    // ── Full-cleanup removal ──────────────────────────────────

    public function test_remove_from_all_roles_clears_everything(): void {
        Permission_Manager::grant_to_administrator();
        Permission_Manager::remove_from_all_roles();

        $role = get_role( 'administrator' );
        foreach ( Permission_Manager::CAPS as $cap ) {
            $this->assertFalse( $role->has_cap( $cap ), "administrator still has {$cap}" );
        }
        $this->assertFalse( get_option( 'wpt_caps_version' ) );
    }
}
