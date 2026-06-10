<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Permission Model — WPTransformed capabilities (permission-model module).
 *
 * Defines the wpt_* capability set so access can be controlled without
 * relying only on manage_options. Capabilities are granted to the
 * Administrator role on activation (and via a version-gated migration for
 * installs that update without re-activating); they are never removed on
 * deactivation, and removed on uninstall only via explicit full cleanup.
 *
 * Spec: docs/modules/system/permission-model.md
 *
 * @package WPTransformed
 */
final class Permission_Manager {

    /** Umbrella capability for WPTransformed administration. */
    public const CAP_MANAGE = 'manage_wpt';

    /** Enable/disable modules and view the Module Library. */
    public const CAP_MODULES = 'manage_wpt_modules';

    /** Save module settings. */
    public const CAP_SETTINGS = 'manage_wpt_settings';

    /** Required IN ADDITION to a base capability for destructive tools. */
    public const CAP_DANGEROUS = 'run_wpt_dangerous_tools';

    /**
     * The full capability set (build authority addendum v5.3.6 §14 /
     * permission-model spec §7 — supersedes the older draft list in
     * canonical scope §18.4).
     *
     * Order is part of the public contract; append new caps and bump
     * CAPS_VERSION rather than reordering.
     */
    public const CAPS = [
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

    /** Bump when CAPS changes so maybe_upgrade() re-grants once. */
    private const CAPS_VERSION = '1';

    private const VERSION_OPTION      = 'wpt_caps_version';
    private const MISSING_ROLE_OPTION = 'wpt_admin_role_missing';

    /**
     * Boot-time hookup. Called on plugins_loaded — must stay cheap:
     * role mutation only happens when the caps version is stale.
     */
    public static function init(): void {
        self::maybe_upgrade();

        if ( get_option( self::MISSING_ROLE_OPTION ) ) {
            add_action( 'admin_notices', [ self::class, 'render_missing_role_notice' ] );
        }
    }

    /**
     * Version-gated migration for installs that update without
     * re-activating (activation hooks do not re-run on plugin updates).
     */
    public static function maybe_upgrade(): void {
        if ( get_option( self::VERSION_OPTION ) === self::CAPS_VERSION ) {
            return;
        }
        self::grant_to_administrator();
    }

    /**
     * Grant every WPT capability to the Administrator role.
     * Editors/authors/contributors/subscribers receive nothing (spec §10).
     *
     * Missing Administrator role: warn, do not fatal (spec §11).
     */
    public static function grant_to_administrator(): void {
        $role = get_role( 'administrator' );

        if ( ! $role ) {
            update_option( self::MISSING_ROLE_OPTION, '1' );
            return;
        }

        foreach ( self::CAPS as $cap ) {
            $role->add_cap( $cap );
        }

        delete_option( self::MISSING_ROLE_OPTION );
        update_option( self::VERSION_OPTION, self::CAPS_VERSION );
    }

    /**
     * Strip every WPT capability from every role.
     *
     * Only for explicit full cleanup on uninstall (spec §15) — never
     * called on deactivation.
     */
    public static function remove_from_all_roles(): void {
        foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( self::CAPS as $cap ) {
                $role->remove_cap( $cap );
            }
        }

        delete_option( self::VERSION_OPTION );
    }

    /**
     * Can this user manage (toggle/configure) the given module?
     *
     * Wraps the manage_wpt_modules check in the documented public filter
     * so Role Manager Pro / third parties can grant or deny per module.
     *
     * @param string $module_id Canonical module ID.
     * @param int    $user_id   User ID; defaults to the current user.
     */
    public static function user_can_manage_module( string $module_id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $can     = user_can( $user_id, self::CAP_MODULES );

        /** Documented public filter — see build authority addendum §11. */
        return (bool) apply_filters( 'wpt_user_can_manage_module', $can, $user_id, $module_id );
    }

    /**
     * Warning shown when capabilities could not be granted because the
     * Administrator role is missing. manage_options is the deliberate
     * emergency-recovery audience here (spec §11): wpt caps may not exist.
     */
    public static function render_missing_role_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__( 'WPTransformed could not find the Administrator role, so its capabilities were not granted. Access to WPTransformed screens may be limited until the role exists and the plugin is re-activated.', 'wptransformed' )
            . '</p></div>';
    }
}
