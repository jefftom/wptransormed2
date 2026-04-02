<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Registry — Single source of truth for what modules exist.
 *
 * Adding a module = adding one line here. Nothing else.
 *
 * @package WPTransformed
 */
class Module_Registry {

    /**
     * Returns the master list of all modules.
     * Key = module ID (string), Value = class file path relative to plugin root.
     *
     * To add a new module:
     * 1. Create the module class file in modules/{category}/
     * 2. Add one line to this array
     * 3. That's it. The loader handles the rest.
     */
    public static function get_all(): array {
        return [
            // Content Management
            'content-duplication'  => 'modules/content-management/class-content-duplication.php',
            'svg-upload'           => 'modules/content-management/class-svg-upload.php',

            // Admin Interface
            'admin-menu-editor'    => 'modules/admin-interface/class-admin-menu-editor.php',
            'hide-admin-notices'   => 'modules/admin-interface/class-hide-admin-notices.php',
            'clean-admin-bar'      => 'modules/admin-interface/class-clean-admin-bar.php',
            'dark-mode'            => 'modules/admin-interface/class-dark-mode.php',

            // Performance
            'database-cleanup'        => 'modules/performance/class-database-cleanup.php',
            'heartbeat-control'       => 'modules/performance/class-heartbeat-control.php',
            'lazy-load'               => 'modules/performance/class-lazy-load.php',
            'image-upload-control'    => 'modules/performance/class-image-upload-control.php',
            'minify-assets'           => 'modules/performance/class-minify-assets.php',

            // Utilities
            'disable-comments'        => 'modules/utilities/class-disable-comments.php',
            'email-smtp'              => 'modules/utilities/class-email-smtp.php',
            'maintenance-mode'        => 'modules/utilities/class-maintenance-mode.php',
            'redirect-manager'        => 'modules/utilities/class-redirect-manager.php',
            'cron-manager'            => 'modules/utilities/class-cron-manager.php',

            // Security
            'user-role-editor'        => 'modules/security/class-user-role-editor.php',
            'limit-login-attempts'    => 'modules/security/class-limit-login-attempts.php',
            'session-manager'         => 'modules/security/class-session-manager.php',
            'audit-log'               => 'modules/security/class-audit-log.php',

            // Content Management (v2)
            'custom-content-types'    => 'modules/content-management/class-custom-content-types.php',
            'media-folders'           => 'modules/content-management/class-media-folders.php',

            // Custom Code
            'code-snippets'           => 'modules/custom-code/class-code-snippets.php',

            // Compliance
            'cookie-consent'          => 'modules/compliance/class-cookie-consent.php',

            // Login/Logout
            'login-customizer'        => 'modules/login-logout/class-login-customizer.php',

            // Wave 3 — Differentiators
            'command-palette'         => 'modules/admin-interface/class-command-palette.php',
            'smart-menu-organizer'    => 'modules/admin-interface/class-smart-menu-organizer.php',
            'search-replace'          => 'modules/utilities/class-search-replace.php',
            'broken-link-checker'     => 'modules/utilities/class-broken-link-checker.php',
            'setup-wizard'            => 'modules/admin-interface/class-setup-wizard.php',
        ];
    }
}
