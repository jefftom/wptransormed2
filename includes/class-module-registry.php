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
            'database-cleanup'     => 'modules/performance/class-database-cleanup.php',
            'heartbeat-control'    => 'modules/performance/class-heartbeat-control.php',

            // Utilities
            'disable-comments'     => 'modules/utilities/class-disable-comments.php',
            'email-smtp'           => 'modules/utilities/class-email-smtp.php',
        ];
    }
}
