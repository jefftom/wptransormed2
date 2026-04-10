<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Registry — Single source of truth for what modules exist.
 *
 * v1 scope: 86 modules across 8 categories.
 * Adding a module = adding one line here. Nothing else.
 *
 * @package WPTransformed
 */
class Module_Registry {

    /**
     * Returns the master list of all modules.
     * Key = module ID (string), Value = class file path relative to plugin root.
     */
    public static function get_all(): array {
        return [

            // ── Admin Interface (30 modules) ─────────────────────
            'admin-bar'                   => 'modules/admin-interface/class-clean-admin-bar.php',
            'admin-columns'               => 'modules/admin-interface/class-admin-columns-enhancer.php',
            'admin-menu-editor'           => 'modules/admin-interface/class-admin-menu-editor.php',
            'hide-admin-notices'          => 'modules/admin-interface/class-hide-admin-notices.php',
            'dark-mode'                   => 'modules/admin-interface/class-dark-mode.php',
            'command-palette'             => 'modules/admin-interface/class-command-palette.php',
            'smart-menu-organizer'        => 'modules/admin-interface/class-smart-menu-organizer.php',
            'setup-wizard'                => 'modules/admin-interface/class-setup-wizard.php',
            'enhance-list-tables'         => 'modules/admin-interface/class-enhance-list-tables.php',
            'hide-dashboard-widgets'      => 'modules/admin-interface/class-hide-dashboard-widgets.php',
            'white-label'                 => 'modules/admin-interface/class-white-label.php',
            'view-as-role'                => 'modules/admin-interface/class-view-as-role.php',
            'taxonomy-filter'             => 'modules/admin-interface/class-taxonomy-filter.php',
            'custom-admin-footer'         => 'modules/admin-interface/class-custom-admin-footer.php',
            'admin-quick-notes'           => 'modules/admin-interface/class-admin-quick-notes.php',
            'admin-bookmarks'             => 'modules/admin-interface/class-admin-bookmarks.php',
            'keyboard-shortcuts'          => 'modules/admin-interface/class-keyboard-shortcuts.php',
            'admin-color-schemes'         => 'modules/admin-interface/class-admin-color-schemes.php',
            'page-hierarchy-organizer'    => 'modules/admin-interface/class-page-hierarchy-organizer.php',
            'activity-feed'               => 'modules/admin-interface/class-activity-feed.php',
            'notification-center'         => 'modules/admin-interface/class-notification-center.php',
            'environment-indicator'       => 'modules/admin-interface/class-environment-indicator.php',
            'client-dashboard'            => 'modules/admin-interface/class-client-dashboard.php',
            'dashboard-columns'           => 'modules/admin-interface/class-dashboard-columns.php',
            'admin-body-classes'          => 'modules/admin-interface/class-admin-body-classes.php',
            'wider-admin-menu'            => 'modules/admin-interface/class-wider-admin-menu.php',
            'search-visibility-status'    => 'modules/admin-interface/class-search-visibility-status.php',
            'active-plugins-first'        => 'modules/admin-interface/class-active-plugins-first.php',
            'media-infinite-scroll'       => 'modules/admin-interface/class-media-infinite-scroll.php',
            'preserve-taxonomy-hierarchy' => 'modules/admin-interface/class-preserve-taxonomy-hierarchy.php',

            // ── Content Management (12 modules) ──────────────────
            'content-duplication'         => 'modules/content-management/class-content-duplication.php',
            'content-order'               => 'modules/content-management/class-content-order.php',
            'media-library-pro'           => 'modules/content-management/class-media-library-pro.php',
            'public-preview'              => 'modules/content-management/class-public-preview.php',
            'external-permalinks'         => 'modules/content-management/class-external-permalinks.php',
            'auto-publish-missed'         => 'modules/content-management/class-auto-publish-missed.php',
            'bulk-edit-posts'             => 'modules/content-management/class-bulk-edit-posts.php',
            'duplicate-menu'              => 'modules/content-management/class-duplicate-menu.php',
            'post-type-switcher'          => 'modules/content-management/class-post-type-switcher.php',
            'terms-order'                 => 'modules/content-management/class-terms-order.php',
            'external-links-new-tab'      => 'modules/content-management/class-external-links-new-tab.php',
            'custom-nav-new-tab'          => 'modules/content-management/class-custom-nav-new-tab.php',

            // ── Performance (8 modules) ──────────────────────────
            'database-cleanup'            => 'modules/performance/class-database-cleanup.php',
            'heartbeat-control'           => 'modules/performance/class-heartbeat-control.php',
            'lazy-load'                   => 'modules/performance/class-lazy-load.php',
            'image-upload-control'        => 'modules/performance/class-image-upload-control.php',
            'minify-assets'               => 'modules/performance/class-minify-assets.php',
            'revision-control'            => 'modules/performance/class-revision-control.php',
            'auto-clear-caches'           => 'modules/performance/class-auto-clear-caches.php',
            'image-srcset-control'        => 'modules/performance/class-image-srcset-control.php',

            // ── Security (12 modules) ────────────────────────────
            'login-security'              => 'modules/security/class-login-security.php',
            'two-factor-auth'             => 'modules/security/class-two-factor-auth.php',
            'strong-passwords'            => 'modules/security/class-strong-passwords.php',
            'login-notifications'         => 'modules/security/class-login-notifications.php',
            'user-role-editor'            => 'modules/security/class-user-role-editor.php',
            'session-manager'             => 'modules/security/class-session-manager.php',
            'audit-log'                   => 'modules/security/class-audit-log.php',
            'obfuscate-author-slugs'      => 'modules/security/class-obfuscate-author-slugs.php',
            'email-obfuscator'            => 'modules/security/class-email-obfuscator.php',
            'password-protection'         => 'modules/security/class-password-protection.php',
            'multiple-user-roles'         => 'modules/security/class-multiple-user-roles.php',
            'temporary-user-access'       => 'modules/security/class-temporary-user-access.php',

            // ── Login & Logout (3 modules) ───────────────────────
            'login-branding'              => 'modules/login-logout/class-login-customizer.php',
            'login-logout-menu'           => 'modules/login-logout/class-login-logout-menu.php',
            'redirect-after-login'        => 'modules/login-logout/class-redirect-after-login.php',

            // ── Custom Code (2 modules) ──────────────────────────
            'code-snippets'               => 'modules/custom-code/class-code-snippets.php',
            'custom-code'                 => 'modules/custom-code/class-custom-code.php',

            // ── Disable Components (3 modules) ───────────────────
            'disable-frontend'            => 'modules/disable-components/class-disable-frontend.php',
            'disable-backend'             => 'modules/disable-components/class-disable-backend.php',
            'disable-gutenberg'           => 'modules/disable-components/class-disable-gutenberg.php',

            // ── Utilities (15 modules) ───────────────────────────
            'disable-comments'            => 'modules/utilities/class-disable-comments.php',
            'email-smtp'                  => 'modules/utilities/class-email-smtp.php',
            'email-log'                   => 'modules/utilities/class-email-log.php',
            'maintenance-mode'            => 'modules/utilities/class-maintenance-mode.php',
            'redirect-manager'            => 'modules/utilities/class-redirect-manager.php',
            'redirect-404'                => 'modules/utilities/class-redirect-404.php',
            'cron-manager'                => 'modules/utilities/class-cron-manager.php',
            'search-replace'              => 'modules/utilities/class-search-replace.php',
            'broken-link-checker'         => 'modules/utilities/class-broken-link-checker.php',
            '404-monitor'                 => 'modules/utilities/class-four-oh-four-monitor.php',
            'duplicate-widget'            => 'modules/utilities/class-duplicate-widget.php',
            'export-import-settings'      => 'modules/utilities/class-export-import-settings.php',
            'system-summary'              => 'modules/utilities/class-system-summary.php',
            'error-log-viewer'            => 'modules/utilities/class-error-log-viewer.php',
            'forms'                       => 'modules/utilities/class-forms.php',

        ];
    }
}
