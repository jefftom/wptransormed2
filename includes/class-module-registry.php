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

            // Wave 4A — Login, Security & Access
            'change-login-url'        => 'modules/login-logout/class-change-login-url.php',
            'login-id-type'           => 'modules/login-logout/class-login-id-type.php',
            'site-identity-login'     => 'modules/login-logout/class-site-identity-login.php',
            'login-logout-menu'       => 'modules/login-logout/class-login-logout-menu.php',
            'last-login-column'       => 'modules/login-logout/class-last-login-column.php',
            'redirect-after-login'    => 'modules/login-logout/class-redirect-after-login.php',
            'disable-xmlrpc'          => 'modules/security/class-disable-xmlrpc.php',
            'obfuscate-author-slugs'  => 'modules/security/class-obfuscate-author-slugs.php',
            'email-obfuscator'        => 'modules/security/class-email-obfuscator.php',
            'password-protection'     => 'modules/security/class-password-protection.php',

            // Wave 4B — Disable Components & Admin Tweaks
            'disable-gutenberg'       => 'modules/disable-components/class-disable-gutenberg.php',
            'disable-rest-api'        => 'modules/disable-components/class-disable-rest-api.php',
            'disable-feeds'           => 'modules/disable-components/class-disable-feeds.php',
            'disable-embeds'          => 'modules/disable-components/class-disable-embeds.php',
            'disable-updates'         => 'modules/disable-components/class-disable-updates.php',
            'disable-author-archives' => 'modules/disable-components/class-disable-author-archives.php',
            'admin-columns-enhancer'  => 'modules/admin-interface/class-admin-columns-enhancer.php',
            'taxonomy-filter'         => 'modules/admin-interface/class-taxonomy-filter.php',
            'custom-admin-css'        => 'modules/custom-code/class-custom-admin-css.php',
            'custom-frontend-code'    => 'modules/custom-code/class-custom-frontend-code.php',

            // Wave 4C — Content, Performance & Utility
            'revision-control'        => 'modules/performance/class-revision-control.php',
            'content-order'           => 'modules/content-management/class-content-order.php',
            'media-replace'           => 'modules/content-management/class-media-replace.php',
            'public-preview'          => 'modules/content-management/class-public-preview.php',
            'external-permalinks'     => 'modules/content-management/class-external-permalinks.php',
            'auto-publish-missed'     => 'modules/content-management/class-auto-publish-missed.php',
            'enhance-list-tables'     => 'modules/admin-interface/class-enhance-list-tables.php',
            'hide-dashboard-widgets'  => 'modules/admin-interface/class-hide-dashboard-widgets.php',
            'admin-bar-enhancer'      => 'modules/admin-interface/class-admin-bar-enhancer.php',
            'white-label'             => 'modules/admin-interface/class-white-label.php',

            // Wave 5A — WooCommerce & Advanced Content
            'woo-admin-cleanup'       => 'modules/woocommerce/class-woo-admin-cleanup.php',
            'woo-custom-statuses'     => 'modules/woocommerce/class-woo-custom-statuses.php',
            'woo-login-redirect'      => 'modules/woocommerce/class-woo-login-redirect.php',
            'woo-disable-reviews'     => 'modules/woocommerce/class-woo-disable-reviews.php',
            'woo-empty-cart-button'   => 'modules/woocommerce/class-woo-empty-cart-button.php',
            'bulk-edit-posts'         => 'modules/content-management/class-bulk-edit-posts.php',
            'duplicate-menu'          => 'modules/content-management/class-duplicate-menu.php',
            'post-type-switcher'      => 'modules/content-management/class-post-type-switcher.php',
            'custom-body-class'       => 'modules/custom-code/class-custom-body-class.php',
            'ads-txt-manager'         => 'modules/custom-code/class-ads-txt-manager.php',

            // Wave 5B — Pro Features & Advanced Tools
            'admin-columns-pro'       => 'modules/admin-interface/class-admin-columns-pro.php',
            'captcha-protection'      => 'modules/security/class-captcha-protection.php',
            'form-builder'            => 'modules/utilities/class-form-builder.php',
            'file-manager'            => 'modules/utilities/class-file-manager.php',
            'multiple-user-roles'     => 'modules/security/class-multiple-user-roles.php',
            'view-as-role'            => 'modules/admin-interface/class-view-as-role.php',
            'local-user-avatar'       => 'modules/content-management/class-local-user-avatar.php',
            'system-summary'          => 'modules/utilities/class-system-summary.php',
            'robots-txt-manager'      => 'modules/custom-code/class-robots-txt-manager.php',
            'email-log'               => 'modules/utilities/class-email-log.php',

            // Wave 5C — Final Stretch
            'disable-rest-fields'     => 'modules/disable-components/class-disable-rest-fields.php',
            'disable-emojis'          => 'modules/disable-components/class-disable-emojis.php',
            'disable-self-pingbacks'  => 'modules/disable-components/class-disable-self-pingbacks.php',
            'disable-attachment-pages' => 'modules/disable-components/class-disable-attachment-pages.php',
            'page-template-column'    => 'modules/admin-interface/class-page-template-column.php',
            'auto-clear-caches'       => 'modules/performance/class-auto-clear-caches.php',
            'image-srcset-control'    => 'modules/performance/class-image-srcset-control.php',
            'duplicate-widget'        => 'modules/utilities/class-duplicate-widget.php',
            'export-import-settings'  => 'modules/utilities/class-export-import-settings.php',

            // Wave 6A — ASE Parity Gaps
            'avif-upload'             => 'modules/content-management/class-avif-upload.php',
            'terms-order'             => 'modules/content-management/class-terms-order.php',
            'media-visibility-control' => 'modules/content-management/class-media-visibility-control.php',
            'external-links-new-tab'  => 'modules/content-management/class-external-links-new-tab.php',
            'custom-nav-new-tab'      => 'modules/content-management/class-custom-nav-new-tab.php',
            'two-factor-auth'         => 'modules/security/class-two-factor-auth.php',
            'hide-admin-bar'          => 'modules/admin-interface/class-hide-admin-bar.php',
            'wider-admin-menu'        => 'modules/admin-interface/class-wider-admin-menu.php',
            'image-sizes-panel'       => 'modules/content-management/class-image-sizes-panel.php',
            'registration-date-column' => 'modules/admin-interface/class-registration-date-column.php',
            'custom-frontend-css'     => 'modules/custom-code/class-custom-frontend-css.php',
            'redirect-404'            => 'modules/utilities/class-redirect-404.php',

            // Wave 6B — Admin Polish
            'search-visibility-status' => 'modules/admin-interface/class-search-visibility-status.php',
            'active-plugins-first'    => 'modules/admin-interface/class-active-plugins-first.php',
            'media-infinite-scroll'   => 'modules/admin-interface/class-media-infinite-scroll.php',
            'preserve-taxonomy-hierarchy' => 'modules/admin-interface/class-preserve-taxonomy-hierarchy.php',
            'dashboard-columns'       => 'modules/admin-interface/class-dashboard-columns.php',
            'admin-body-classes'      => 'modules/admin-interface/class-admin-body-classes.php',
            'custom-admin-footer'     => 'modules/admin-interface/class-custom-admin-footer.php',
            'admin-quick-notes'       => 'modules/admin-interface/class-admin-quick-notes.php',
            'admin-bookmarks'         => 'modules/admin-interface/class-admin-bookmarks.php',
            'keyboard-shortcuts'      => 'modules/admin-interface/class-keyboard-shortcuts.php',
            'admin-color-schemes'     => 'modules/admin-interface/class-admin-color-schemes.php',
            'page-hierarchy-organizer' => 'modules/admin-interface/class-page-hierarchy-organizer.php',
        ];
    }
}
