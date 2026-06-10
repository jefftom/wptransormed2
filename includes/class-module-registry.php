<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Registry — Single source of truth for what modules exist.
 *
 * v1 scope: 83 modules across 8 categories.
 *
 * Two layers during the definition-array transition:
 *
 * 1. DEFINITIONS — canonical metadata (module-registry spec §6): keyed by
 *    CANONICAL slug (build authority addendum §12), carrying everything the
 *    Module Library needs to render cards without loading module code.
 *    Renamed modules carry their current runtime id in `legacy_ids`; the
 *    slug migration commit will flip runtime ids to canonical and migrate
 *    settings rows.
 * 2. get_all() — the legacy runtime id => file map, still consumed by the
 *    loader. Will be replaced by get_file_map() (derived from DEFINITIONS)
 *    when the registry becomes the loader's source of truth.
 *
 * Risk values are provisional (derived from build authority §14/§15
 * dangerous-tools lists) until each per-module spec lands.
 *
 * @package WPTransformed
 */
class Module_Registry {

    /** Allowed enum values for definition validation. */
    private const TIERS      = [ 'core', 'pro' ];
    private const RISKS      = [ 'safe', 'moderate', 'advanced' ];
    private const STATUSES   = [ 'implemented', 'stub', 'planned' ];
    private const CATEGORIES = [
        'admin-interface',
        'content-management',
        'performance',
        'security',
        'login-logout',
        'custom-code',
        'disable-components',
        'utilities',
    ];

    /** Per-definition defaults — DEFINITIONS entries carry only overrides. */
    private const DEFAULTS = [
        'tier'            => 'core',
        'risk'            => 'safe',
        'status'          => 'implemented',
        'default_enabled' => false,
        'legacy_ids'      => [],
        'app_page'        => null,
        'has_settings'    => true,
        'capability'      => null,
        'search_terms'    => [],
        'dependencies'    => [],
        'has_cleanup'     => false,
    ];

    private const DEFINITIONS = [

        'admin-bar-manager' => [
            'file'        => 'modules/admin-interface/class-clean-admin-bar.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Clean_Admin_Bar',
            'title'       => 'Admin Bar',
            'category'    => 'admin-interface',
            'description' => 'Manage admin bar visibility, hide for specific roles, add custom links, and create per-role toolbar profiles.',
            'legacy_ids'  => [ 'admin-bar' ],
            'has_cleanup' => true,
        ],

        'admin-columns' => [
            'file'        => 'modules/admin-interface/class-admin-columns-enhancer.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Admin_Columns_Enhancer',
            'title'       => 'Admin Columns',
            'category'    => 'admin-interface',
            'description' => 'Add ID, thumbnail, template, registration date, and last login columns to admin list tables.',
            'has_cleanup' => true,
        ],

        'admin-menu-editor' => [
            'file'        => 'modules/admin-interface/class-admin-menu-editor.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Admin_Menu_Editor',
            'title'       => 'Admin Menu Editor',
            'category'    => 'admin-interface',
            'description' => 'Reorder, rename, and hide WordPress admin sidebar menu items via drag-and-drop.',
            'app_page'    => 'wpt-menu-editor',
            'has_cleanup' => true,
        ],

        'hide-admin-notices' => [
            'file'        => 'modules/admin-interface/class-hide-admin-notices.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Hide_Admin_Notices',
            'title'       => 'Hide Admin Notices',
            'category'    => 'admin-interface',
            'description' => 'Hide admin notices and collect them into a dedicated Notifications page under Dashboard.',
            'app_page'    => 'wpt-notifications',
            'has_cleanup' => true,
        ],

        'dark-mode' => [
            'file'        => 'modules/admin-interface/class-dark-mode.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Dark_Mode',
            'title'       => 'Dark Mode',
            'category'    => 'admin-interface',
            'description' => 'Add a dark color scheme to the WordPress admin dashboard with per-user preferences.',
            'has_cleanup' => true,
        ],

        'command-palette' => [
            'file'        => 'modules/admin-interface/class-command-palette.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Command_Palette',
            'title'       => 'Command Palette',
            'category'    => 'admin-interface',
            'description' => 'Cmd+K / Ctrl+K opens a searchable command palette for instant access to any admin page, module setting, or quick action.',
            'has_cleanup' => true,
        ],

        'smart-menu-organizer' => [
            'file'        => 'modules/admin-interface/class-smart-menu-organizer.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Smart_Menu_Organizer',
            'title'       => 'Smart Menu Organizer',
            'category'    => 'admin-interface',
            'description' => 'Auto-groups the WordPress admin sidebar into logical categories with drag-and-drop reordering and per-role visibility.',
            'has_cleanup' => true,
        ],

        'setup-wizard' => [
            'file'        => 'modules/admin-interface/class-setup-wizard.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Setup_Wizard',
            'title'       => 'Setup Wizard',
            'category'    => 'admin-interface',
            'description' => 'Guided onboarding flow that runs on first activation to help configure your site.',
            'app_page'    => 'wpt-setup-wizard',
            'has_cleanup' => true,
        ],

        'list-table-enhancements' => [
            'file'        => 'modules/admin-interface/class-enhance-list-tables.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Enhance_List_Tables',
            'title'       => 'Enhance List Tables',
            'category'    => 'admin-interface',
            'description' => 'Add featured image, excerpt preview, and word count columns to post list tables.',
            'legacy_ids'  => [ 'enhance-list-tables' ],
            'has_cleanup' => true,
        ],

        'hide-dashboard-widgets' => [
            'file'        => 'modules/admin-interface/class-hide-dashboard-widgets.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Hide_Dashboard_Widgets',
            'title'       => 'Hide Dashboard Widgets',
            'category'    => 'admin-interface',
            'description' => 'Selectively hide WordPress dashboard widgets to declutter the admin dashboard.',
            'has_cleanup' => true,
        ],

        'white-label' => [
            'file'        => 'modules/admin-interface/class-white-label.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\White_Label',
            'title'       => 'White Label',
            'category'    => 'admin-interface',
            'description' => 'Rebrand the WordPress admin with custom logos, footer text, and branding.',
            'has_cleanup' => true,
        ],

        'view-as-role' => [
            'file'        => 'modules/admin-interface/class-view-as-role.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\View_As_Role',
            'title'       => 'View as Role',
            'category'    => 'admin-interface',
            'description' => 'Temporarily view the WordPress admin as a different user role without changing your actual role.',
            'has_cleanup' => true,
        ],

        'taxonomy-filter' => [
            'file'        => 'modules/admin-interface/class-taxonomy-filter.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Taxonomy_Filter',
            'title'       => 'Taxonomy Filter',
            'category'    => 'admin-interface',
            'description' => 'Add taxonomy filter dropdowns to post list tables for quick filtering by custom taxonomies.',
            'has_cleanup' => true,
        ],

        'custom-admin-footer' => [
            'file'        => 'modules/admin-interface/class-custom-admin-footer.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Custom_Admin_Footer',
            'title'       => 'Custom Admin Footer',
            'category'    => 'admin-interface',
            'description' => 'Replace the default WordPress admin footer text with your own custom branding.',
            'has_cleanup' => true,
        ],

        'admin-quick-notes' => [
            'file'        => 'modules/admin-interface/class-admin-quick-notes.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Admin_Quick_Notes',
            'title'       => 'Admin Quick Notes',
            'category'    => 'admin-interface',
            'description' => 'Add a shared sticky-notes widget to the WordPress dashboard for quick team communication.',
            'has_cleanup' => true,
        ],

        'admin-bookmarks' => [
            'file'        => 'modules/admin-interface/class-admin-bookmarks.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Admin_Bookmarks',
            'title'       => 'Admin Bookmarks',
            'category'    => 'admin-interface',
            'description' => 'Add a personal bookmarks bar to the admin toolbar for quick access to frequently used pages.',
            'has_cleanup' => true,
        ],

        'keyboard-shortcuts' => [
            'file'        => 'modules/admin-interface/class-keyboard-shortcuts.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Keyboard_Shortcuts',
            'title'       => 'Keyboard Shortcuts',
            'category'    => 'admin-interface',
            'description' => 'Navigate the WordPress admin with configurable keyboard shortcuts.',
            'has_cleanup' => true,
        ],

        'admin-color-schemes' => [
            'file'        => 'modules/admin-interface/class-admin-color-schemes.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Admin_Color_Schemes',
            'title'       => 'Admin Color Schemes',
            'category'    => 'admin-interface',
            'description' => 'Apply custom color schemes to the WordPress admin interface with built-in and user-defined themes.',
            'has_cleanup' => true,
        ],

        'page-hierarchy-organizer' => [
            'file'        => 'modules/admin-interface/class-page-hierarchy-organizer.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Page_Hierarchy_Organizer',
            'title'       => 'Page Hierarchy Organizer',
            'category'    => 'admin-interface',
            'description' => 'Transform the Pages list into a collapsible tree with drag-and-drop reordering.',
            'has_cleanup' => true,
        ],

        'activity-feed' => [
            'file'        => 'modules/admin-interface/class-activity-feed.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Activity_Feed',
            'title'       => 'Activity Feed',
            'category'    => 'admin-interface',
            'description' => 'Dashboard widget showing chronological site activity with auto-refresh and filtering.',
            'has_cleanup' => true,
        ],

        'notification-center' => [
            'file'        => 'modules/admin-interface/class-notification-center.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Notification_Center',
            'title'       => 'Notification Center',
            'category'    => 'admin-interface',
            'description' => 'Capture admin notices into a unified notification panel in the admin bar.',
            'has_cleanup' => true,
        ],

        'environment-indicator' => [
            'file'        => 'modules/admin-interface/class-environment-indicator.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Environment_Indicator',
            'title'       => 'Environment Indicator',
            'category'    => 'admin-interface',
            'description' => 'Display a colored indicator in the admin bar showing the current environment (production, staging, development, local).',
            'has_cleanup' => true,
        ],

        'client-dashboard' => [
            'file'        => 'modules/admin-interface/class-client-dashboard.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Client_Dashboard',
            'title'       => 'Client Dashboard',
            'category'    => 'admin-interface',
            'description' => 'Replace the default dashboard with a clean, simplified view for non-admin roles.',
            'tier'        => 'pro',
            'has_cleanup' => true,
        ],

        'dashboard-columns' => [
            'file'        => 'modules/admin-interface/class-dashboard-columns.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Dashboard_Columns',
            'title'       => 'Dashboard Columns',
            'category'    => 'admin-interface',
            'description' => 'Set a fixed number of columns (1-4) for the WordPress dashboard layout.',
            'has_cleanup' => true,
        ],

        'admin-body-classes' => [
            'file'        => 'modules/admin-interface/class-admin-body-classes.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Admin_Body_Classes',
            'title'       => 'Admin Body Classes',
            'category'    => 'admin-interface',
            'description' => 'Add user role and/or username CSS classes to the admin body tag for targeted styling.',
            'has_cleanup' => true,
        ],

        'wider-admin-menu' => [
            'file'        => 'modules/admin-interface/class-wider-admin-menu.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Wider_Admin_Menu',
            'title'       => 'Wider Admin Menu',
            'category'    => 'admin-interface',
            'description' => 'Increase the width of the WordPress admin sidebar menu beyond the default 160px.',
            'has_cleanup' => true,
        ],

        'search-visibility-status' => [
            'file'        => 'modules/admin-interface/class-search-visibility-status.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Search_Visibility_Status',
            'title'       => 'Search Visibility Status',
            'category'    => 'admin-interface',
            'description' => 'Show a prominent warning when search engine indexing is discouraged.',
            'has_cleanup' => true,
        ],

        'active-plugins-first' => [
            'file'        => 'modules/admin-interface/class-active-plugins-first.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Active_Plugins_First',
            'title'       => 'Active Plugins First',
            'category'    => 'admin-interface',
            'description' => 'Sort the plugins list so active plugins appear before inactive ones.',
            'has_cleanup' => true,
        ],

        'media-infinite-scroll' => [
            'file'        => 'modules/admin-interface/class-media-infinite-scroll.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Media_Infinite_Scroll',
            'title'       => 'Media Infinite Scroll',
            'category'    => 'admin-interface',
            'description' => 'Re-enable infinite scroll in the Media Library grid view.',
            'has_cleanup' => true,
        ],

        'preserve-taxonomy-hierarchy' => [
            'file'        => 'modules/admin-interface/class-preserve-taxonomy-hierarchy.php',
            'class'       => 'WPTransformed\\Modules\\AdminInterface\\Preserve_Taxonomy_Hierarchy',
            'title'       => 'Preserve Taxonomy Hierarchy',
            'category'    => 'admin-interface',
            'description' => 'Keep checked taxonomy terms in their hierarchical position instead of moving them to the top.',
            'has_cleanup' => true,
        ],

        'content-duplication' => [
            'file'        => 'modules/content-management/class-content-duplication.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Content_Duplication',
            'title'       => 'Content Duplication',
            'category'    => 'content-management',
            'description' => 'One-click clone of any post, page, or CPT with all metadata and taxonomies.',
            'has_cleanup' => true,
        ],

        'content-order' => [
            'file'        => 'modules/content-management/class-content-order.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Content_Order',
            'title'       => 'Content Order',
            'category'    => 'content-management',
            'description' => 'Drag-and-drop reordering of posts, pages, and custom post types in the admin list table.',
            'has_cleanup' => true,
        ],

        'media-library-pro' => [
            'file'        => 'modules/content-management/class-media-library-pro.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Media_Library_Pro',
            'title'       => 'Media Library Pro',
            'category'    => 'content-management',
            'description' => 'Folders, file replacement, SVG/AVIF uploads, local avatars, image size management, and media visibility control.',
            'status'      => 'stub',
        ],

        'public-preview' => [
            'file'        => 'modules/content-management/class-public-preview.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Public_Preview',
            'title'       => 'Public Preview',
            'category'    => 'content-management',
            'description' => 'Share draft or pending posts with non-logged-in users via a secure, expiring preview link.',
            'has_cleanup' => true,
        ],

        'external-permalinks' => [
            'file'        => 'modules/content-management/class-external-permalinks.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\External_Permalinks',
            'title'       => 'External Permalinks',
            'category'    => 'content-management',
            'description' => 'Replace any post or page permalink with an external URL, with automatic 301 redirect.',
            'has_cleanup' => true,
        ],

        'auto-publish-missed-schedule' => [
            'file'        => 'modules/content-management/class-auto-publish-missed.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Auto_Publish_Missed',
            'title'       => 'Auto Publish Missed',
            'category'    => 'content-management',
            'description' => 'Automatically publish scheduled posts that WordPress missed due to cron failures.',
            'legacy_ids'  => [ 'auto-publish-missed' ],
            'has_cleanup' => true,
        ],

        'bulk-content-editor' => [
            'file'        => 'modules/content-management/class-bulk-edit-posts.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Bulk_Edit_Posts',
            'title'       => 'Bulk Edit Posts',
            'category'    => 'content-management',
            'description' => 'Extended bulk editing with support for custom fields, author, date, and status changes.',
            'risk'        => 'advanced',
            'legacy_ids'  => [ 'bulk-edit-posts' ],
            'has_cleanup' => true,
        ],

        'duplicate-menu' => [
            'file'        => 'modules/content-management/class-duplicate-menu.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Duplicate_Menu',
            'title'       => 'Duplicate Menu',
            'category'    => 'content-management',
            'description' => 'One-click duplication of navigation menus with full hierarchy preservation.',
            'has_cleanup' => true,
        ],

        'post-type-switcher' => [
            'file'        => 'modules/content-management/class-post-type-switcher.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Post_Type_Switcher',
            'title'       => 'Post Type Switcher',
            'category'    => 'content-management',
            'description' => 'Switch a post between different post types from the editor Publish metabox.',
            'has_cleanup' => true,
        ],

        'terms-order' => [
            'file'        => 'modules/content-management/class-terms-order.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Terms_Order',
            'title'       => 'Terms Order',
            'category'    => 'content-management',
            'description' => 'Drag-and-drop reordering for taxonomy terms.',
            'tier'        => 'pro',
            'has_cleanup' => true,
        ],

        'external-links-new-tab' => [
            'file'        => 'modules/content-management/class-external-links-new-tab.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\External_Links_New_Tab',
            'title'       => 'External Links New Tab',
            'category'    => 'content-management',
            'description' => 'Automatically open external links in new tabs with security attributes.',
            'has_cleanup' => true,
        ],

        'custom-nav-new-tab' => [
            'file'        => 'modules/content-management/class-custom-nav-new-tab.php',
            'class'       => 'WPTransformed\\Modules\\ContentManagement\\Custom_Nav_New_Tab',
            'title'       => 'Custom Nav New Tab',
            'category'    => 'content-management',
            'description' => 'Automatically open external custom links in navigation menus in a new browser tab.',
            'has_cleanup' => true,
        ],

        'database-optimizer' => [
            'file'        => 'modules/performance/class-database-cleanup.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Database_Cleanup',
            'title'       => 'Database Cleanup',
            'category'    => 'performance',
            'description' => 'Remove bloat from WP database: post revisions, trashed posts, spam comments, expired transients, orphaned metadata.',
            'risk'        => 'advanced',
            'legacy_ids'  => [ 'database-cleanup' ],
            'app_page'    => 'wpt-database',
            'capability'  => 'manage_wpt_database',
            'has_cleanup' => true,
        ],

        'heartbeat-control' => [
            'file'        => 'modules/performance/class-heartbeat-control.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Heartbeat_Control',
            'title'       => 'Heartbeat Control',
            'category'    => 'performance',
            'description' => 'Control the WordPress Heartbeat API frequency to reduce server load or disable it entirely.',
            'has_cleanup' => true,
        ],

        'lazy-load' => [
            'file'        => 'modules/performance/class-lazy-load.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Lazy_Load',
            'title'       => 'Lazy Load',
            'category'    => 'performance',
            'description' => 'Add native lazy loading to images, iframes, and videos to improve page load performance.',
            'has_cleanup' => true,
        ],

        'image-upload-control' => [
            'file'        => 'modules/performance/class-image-upload-control.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Image_Upload_Control',
            'title'       => 'Image Upload Control',
            'category'    => 'performance',
            'description' => 'Automatically resize, compress, and optimize images on upload to reduce storage and improve performance.',
            'has_cleanup' => true,
        ],

        'minify-assets' => [
            'file'        => 'modules/performance/class-minify-assets.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Minify_Assets',
            'title'       => 'Minify Assets',
            'category'    => 'performance',
            'description' => 'Minify CSS and JS assets to reduce file size. Optionally combine files to reduce HTTP requests.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'revision-control' => [
            'file'        => 'modules/performance/class-revision-control.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Revision_Control',
            'title'       => 'Revision Control',
            'category'    => 'performance',
            'description' => 'Limit or disable post revisions per post type to reduce database bloat. Includes a bulk purge tool.',
            'has_cleanup' => true,
        ],

        'auto-clear-caches' => [
            'file'        => 'modules/performance/class-auto-clear-caches.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Auto_Clear_Caches',
            'title'       => 'Auto Clear Caches',
            'category'    => 'performance',
            'description' => 'Automatically purge caches from popular caching plugins when content is saved or menus are updated.',
            'has_cleanup' => true,
        ],

        'image-srcset-control' => [
            'file'        => 'modules/performance/class-image-srcset-control.php',
            'class'       => 'WPTransformed\\Modules\\Performance\\Image_Srcset_Control',
            'title'       => 'Image Srcset Control',
            'category'    => 'performance',
            'description' => 'Control responsive image srcset output to manage bandwidth and image delivery.',
            'has_cleanup' => true,
        ],

        'login-protection' => [
            'file'        => 'modules/security/class-login-security.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Login_Security',
            'title'       => 'Login Security',
            'category'    => 'security',
            'description' => 'Limit login attempts, add CAPTCHA protection, change login URL, and restrict login ID type.',
            'risk'        => 'advanced',
            'status'      => 'stub',
            'legacy_ids'  => [ 'login-security' ],
        ],

        'two-factor-authentication' => [
            'file'        => 'modules/security/class-two-factor-auth.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Two_Factor_Auth',
            'title'       => 'Two-Factor Authentication',
            'category'    => 'security',
            'description' => 'Add two-factor authentication to WordPress login with TOTP, email codes, and recovery codes.',
            'tier'        => 'pro',
            'risk'        => 'moderate',
            'legacy_ids'  => [ 'two-factor-auth' ],
            'has_cleanup' => true,
        ],

        'strong-passwords' => [
            'file'        => 'modules/security/class-strong-passwords.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Strong_Passwords',
            'title'       => 'Strong Password Enforcement',
            'category'    => 'security',
            'description' => 'Enforce minimum length, complexity requirements, and password history to prevent reuse.',
            'risk'        => 'moderate',
            'status'      => 'stub',
        ],

        'login-notifications' => [
            'file'        => 'modules/security/class-login-notifications.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Login_Notifications',
            'title'       => 'Login Notifications',
            'category'    => 'security',
            'description' => 'Email users when their account logs in from a new IP or device with instant lockout option.',
            'risk'        => 'moderate',
            'status'      => 'stub',
        ],

        'role-manager' => [
            'file'        => 'modules/security/class-user-role-editor.php',
            'class'       => 'WPTransformed\\Modules\\Security\\User_Role_Editor',
            'title'       => 'User Role Editor',
            'category'    => 'security',
            'description' => 'Edit user roles and capabilities, add custom roles, and temporarily view the site as any role.',
            'risk'        => 'advanced',
            'legacy_ids'  => [ 'user-role-editor' ],
            'capability'  => 'manage_wpt_security',
            'has_cleanup' => true,
        ],

        'session-manager' => [
            'file'        => 'modules/security/class-session-manager.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Session_Manager',
            'title'       => 'Session Manager',
            'category'    => 'security',
            'description' => 'Monitor and manage user sessions with idle timeouts, session limits, and remote session termination.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'audit-log' => [
            'file'        => 'modules/security/class-audit-log.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Audit_Log',
            'title'       => 'Audit Log',
            'category'    => 'security',
            'description' => 'Track important site activity including post changes, plugin events, logins, and more for security and compliance auditing.',
            'app_page'    => 'wpt-audit-log',
            'capability'  => 'view_wpt_logs',
            'has_cleanup' => true,
        ],

        'obfuscate-author-slugs' => [
            'file'        => 'modules/security/class-obfuscate-author-slugs.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Obfuscate_Author_Slugs',
            'title'       => 'Obfuscate Author Slugs',
            'category'    => 'security',
            'description' => 'Hide real usernames from author archive URLs to prevent user enumeration attacks.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'email-obfuscator' => [
            'file'        => 'modules/security/class-email-obfuscator.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Email_Obfuscator',
            'title'       => 'Email Obfuscator',
            'category'    => 'security',
            'description' => 'Protect email addresses from spam harvesters by encoding them in page content.',
            'has_cleanup' => true,
        ],

        'password-protection' => [
            'file'        => 'modules/security/class-password-protection.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Password_Protection',
            'title'       => 'Password Protection',
            'category'    => 'security',
            'description' => 'Require a password to access the entire site, with IP whitelist and page exclusions.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'multiple-user-roles' => [
            'file'        => 'modules/security/class-multiple-user-roles.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Multiple_User_Roles',
            'title'       => 'Multiple User Roles',
            'category'    => 'security',
            'description' => 'Assign multiple roles to a single WordPress user with checkbox-based role selection.',
            'has_cleanup' => true,
        ],

        'temporary-user-access' => [
            'file'        => 'modules/security/class-temporary-user-access.php',
            'class'       => 'WPTransformed\\Modules\\Security\\Temporary_User_Access',
            'title'       => 'Temporary User Access',
            'category'    => 'security',
            'description' => 'Generate time-limited login links for temporary admin or support access.',
            'tier'        => 'pro',
            'app_page'    => 'wpt-temporary-access',
            'capability'  => 'manage_wpt_security',
            'has_cleanup' => true,
        ],

        'login-designer' => [
            'file'        => 'modules/login-logout/class-login-customizer.php',
            'class'       => 'WPTransformed\\Modules\\LoginLogout\\Login_Customizer',
            'title'       => 'Login Branding',
            'category'    => 'login-logout',
            'description' => 'Brand your login page with custom logos, colors, styles, and site identity.',
            'legacy_ids'  => [ 'login-branding' ],
            'app_page'    => 'wpt-login-designer',
            'has_cleanup' => true,
        ],

        'login-logout-menu' => [
            'file'        => 'modules/login-logout/class-login-logout-menu.php',
            'class'       => 'WPTransformed\\Modules\\LoginLogout\\Login_Logout_Menu',
            'title'       => 'Login Logout Menu',
            'category'    => 'login-logout',
            'description' => 'Append login, logout, and register links to your navigation menus.',
            'has_cleanup' => true,
        ],

        'redirect-after-login' => [
            'file'        => 'modules/login-logout/class-redirect-after-login.php',
            'class'       => 'WPTransformed\\Modules\\LoginLogout\\Redirect_After_Login',
            'title'       => 'Redirect After Login',
            'category'    => 'login-logout',
            'description' => 'Redirect users to different URLs after login or logout based on their role.',
            'has_cleanup' => true,
        ],

        'code-snippets' => [
            'file'        => 'modules/custom-code/class-code-snippets.php',
            'class'       => 'WPTransformed\\Modules\\CustomCode\\Code_Snippets',
            'title'       => 'Code Snippets',
            'category'    => 'custom-code',
            'description' => 'Add custom PHP, CSS, JS, and HTML code snippets with error recovery and scoped execution.',
            'risk'        => 'advanced',
            'capability'  => 'manage_wpt_code',
            'has_cleanup' => true,
        ],

        'disable-frontend' => [
            'file'        => 'modules/disable-components/class-disable-frontend.php',
            'class'       => 'WPTransformed\\Modules\\DisableComponents\\Disable_Frontend',
            'title'       => 'Disable Frontend Features',
            'category'    => 'disable-components',
            'description' => 'Disable RSS feeds, oEmbed, emojis, self-pingbacks, attachment pages, and author archives.',
            'risk'        => 'moderate',
            'status'      => 'stub',
        ],

        'disable-backend' => [
            'file'        => 'modules/disable-components/class-disable-backend.php',
            'class'       => 'WPTransformed\\Modules\\DisableComponents\\Disable_Backend',
            'title'       => 'Disable Backend Features',
            'category'    => 'disable-components',
            'description' => 'Disable XML-RPC, REST API access, REST API fields, and update notifications.',
            'risk'        => 'advanced',
            'status'      => 'stub',
        ],

        'disable-gutenberg' => [
            'file'        => 'modules/disable-components/class-disable-gutenberg.php',
            'class'       => 'WPTransformed\\Modules\\DisableComponents\\Disable_Gutenberg',
            'title'       => 'Disable Gutenberg',
            'category'    => 'disable-components',
            'description' => 'Disable the block editor (Gutenberg) for selected post types and revert to the Classic Editor.',
            'risk'        => 'moderate',
            'search_terms' => [ 'disable gutenberg', 'classic editor', 'restore classic editor', 'disable block editor', 'turn off block editor', 'old editor', 'editor switcher' ],
            'has_cleanup' => true,
        ],

        'disable-comments' => [
            'file'        => 'modules/utilities/class-disable-comments.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Disable_Comments',
            'title'       => 'Disable Comments',
            'category'    => 'utilities',
            'description' => 'Disable the WordPress comment system entirely or selectively per post type.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'email-delivery' => [
            'file'        => 'modules/utilities/class-email-smtp.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Email_SMTP',
            'title'       => 'Email SMTP',
            'category'    => 'utilities',
            'description' => 'Configure WordPress to send emails via SMTP instead of PHP mail(), with test email feature.',
            'legacy_ids'  => [ 'email-smtp' ],
            'capability'  => 'manage_wpt_email',
            'has_cleanup' => true,
        ],

        'email-log' => [
            'file'        => 'modules/utilities/class-email-log.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Email_Log',
            'title'       => 'Email Log',
            'category'    => 'utilities',
            'description' => 'Log all outgoing WordPress emails with the ability to search, filter, and resend.',
            'tier'        => 'pro',
            'capability'  => 'manage_wpt_email',
            'has_cleanup' => true,
        ],

        'maintenance-mode' => [
            'file'        => 'modules/utilities/class-maintenance-mode.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Maintenance_Mode',
            'title'       => 'Maintenance Mode',
            'category'    => 'utilities',
            'description' => 'Show a maintenance page to visitors while you work on the site.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'redirect-manager' => [
            'file'        => 'modules/utilities/class-redirect-manager.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Redirect_Manager',
            'title'       => 'Redirect Manager',
            'category'    => 'utilities',
            'description' => 'Manage 301/302/307 redirects and log 404 errors with referrer tracking.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'redirect-404' => [
            'file'        => 'modules/utilities/class-redirect-404.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Redirect_404',
            'title'       => 'Redirect 404',
            'category'    => 'utilities',
            'description' => 'Automatically redirect 404 pages to the homepage or a custom URL.',
            'risk'        => 'moderate',
            'has_cleanup' => true,
        ],

        'cron-manager' => [
            'file'        => 'modules/utilities/class-cron-manager.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Cron_Manager',
            'title'       => 'Cron Manager',
            'category'    => 'utilities',
            'description' => 'View, run, delete, and add WordPress scheduled cron events from the admin.',
            'has_cleanup' => true,
        ],

        'search-replace' => [
            'file'        => 'modules/utilities/class-search-replace.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Search_Replace',
            'title'       => 'Search & Replace',
            'category'    => 'utilities',
            'description' => 'Database-wide search and replace with safe serialized data handling and undo support.',
            'risk'        => 'advanced',
            'has_cleanup' => true,
        ],

        'broken-link-checker' => [
            'file'        => 'modules/utilities/class-broken-link-checker.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Broken_Link_Checker',
            'title'       => 'Broken Link Checker',
            'category'    => 'utilities',
            'description' => 'Background scan of all content for broken internal and external links with one-click fix options.',
            'has_cleanup' => true,
        ],

        '404-monitor' => [
            'file'        => 'modules/utilities/class-four-oh-four-monitor.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Four_Oh_Four_Monitor',
            'title'       => '404 Monitor',
            'category'    => 'utilities',
            'description' => 'Log and analyze incoming 404 requests to find broken URLs and missing content.',
            'has_cleanup' => true,
        ],

        'duplicate-widget' => [
            'file'        => 'modules/utilities/class-duplicate-widget.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Duplicate_Widget',
            'title'       => 'Duplicate Widget',
            'category'    => 'utilities',
            'description' => 'Add a one-click Duplicate button to widgets on the classic Widgets screen.',
            'has_cleanup' => true,
        ],

        'export-import-settings' => [
            'file'        => 'modules/utilities/class-export-import-settings.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Export_Import_Settings',
            'title'       => 'Export / Import Settings',
            'category'    => 'utilities',
            'description' => 'Export, import, or reset all WPTransformed module settings.',
            'capability'  => 'export_wpt_data',
            'has_cleanup' => true,
        ],

        'system-summary' => [
            'file'        => 'modules/utilities/class-system-summary.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\System_Summary',
            'title'       => 'System Summary',
            'category'    => 'utilities',
            'description' => 'View comprehensive system information including PHP, MySQL, WordPress versions, server details, and more.',
            'has_cleanup' => true,
        ],

        'error-log-viewer' => [
            'file'        => 'modules/utilities/class-error-log-viewer.php',
            'class'       => 'WPTransformed\\Modules\\Utilities\\Error_Log_Viewer',
            'title'       => 'Error Log Viewer',
            'category'    => 'utilities',
            'description' => 'View, search, and manage the PHP error log directly from the WordPress admin.',
            'has_cleanup' => true,
        ],
    ];

    /**
     * Canonical module definitions with defaults merged.
     *
     * NOT filtered — the `wpt_registered_modules` filter applies at boot
     * (loader integration commit), followed by re-validation.
     */
    public static function get_definitions(): array {
        $out = [];
        foreach ( self::DEFINITIONS as $id => $def ) {
            $out[ $id ] = array_merge( self::DEFAULTS, $def );
        }
        return $out;
    }

    /**
     * Validate one merged definition. Returns a list of problems; empty
     * array = valid. Never throws — callers decide whether to skip + log.
     */
    public static function validate_definition( string $id, array $def ): array {
        $problems = [];

        if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) ) {
            $problems[] = 'id is not kebab-case';
        }

        foreach ( [ 'file', 'class', 'title', 'category', 'description' ] as $required ) {
            if ( empty( $def[ $required ] ) || ! is_string( $def[ $required ] ) ) {
                $problems[] = "missing or invalid '{$required}'";
            }
        }

        if ( isset( $def['tier'] ) && ! in_array( $def['tier'], self::TIERS, true ) ) {
            $problems[] = "unknown tier '{$def['tier']}'";
        }
        if ( isset( $def['risk'] ) && ! in_array( $def['risk'], self::RISKS, true ) ) {
            $problems[] = "unknown risk '{$def['risk']}'";
        }
        if ( isset( $def['status'] ) && ! in_array( $def['status'], self::STATUSES, true ) ) {
            $problems[] = "unknown status '{$def['status']}'";
        }
        if ( ! empty( $def['category'] ) && is_string( $def['category'] )
            && ! in_array( $def['category'], self::CATEGORIES, true ) ) {
            $problems[] = "unknown category '{$def['category']}'";
        }
        if ( isset( $def['default_enabled'] ) && ! is_bool( $def['default_enabled'] ) ) {
            $problems[] = 'default_enabled must be boolean';
        }

        // SECURITY: the file path is later require_once'd — it must point
        // at a module class file inside the plugin, no traversal, ever.
        if ( ! empty( $def['file'] ) && is_string( $def['file'] )
            && ! preg_match( '#^modules/[a-z0-9-]+/class-[a-z0-9-]+\.php$#', $def['file'] ) ) {
            $problems[] = "unsafe or non-conforming file path '{$def['file']}'";
        }

        // The class is later instantiated — restrict to the module namespace.
        if ( ! empty( $def['class'] ) && is_string( $def['class'] )
            && ! preg_match( '/^WPTransformed\\\\Modules\\\\[A-Za-z0-9_\\\\]+$/', $def['class'] ) ) {
            $problems[] = "class outside the WPTransformed\\Modules namespace";
        }

        if ( isset( $def['legacy_ids'] ) ) {
            if ( ! is_array( $def['legacy_ids'] ) ) {
                $problems[] = 'legacy_ids must be an array';
            } else {
                foreach ( $def['legacy_ids'] as $legacy ) {
                    if ( ! is_string( $legacy ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $legacy ) ) {
                        $problems[] = 'legacy_ids contains a non-kebab-case entry';
                        break;
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Validate every definition (defaults merged, duplicate legacy ids
     * included). Returns [ id => problems ] for invalid entries only.
     */
    public static function validate_all(): array {
        $invalid = [];
        $seen    = [];

        foreach ( self::get_definitions() as $id => $def ) {
            $problems = self::validate_definition( $id, $def );

            foreach ( array_merge( [ $id ], $def['legacy_ids'] ) as $key ) {
                if ( isset( $seen[ $key ] ) ) {
                    $problems[] = "id/legacy id '{$key}' already used by '{$seen[ $key ]}'";
                }
                $seen[ $key ] = $id;
            }

            if ( $problems ) {
                $invalid[ $id ] = $problems;
            }
        }

        return $invalid;
    }

    /**
     * Derived runtime id => file map.
     *
     * Transitional: keyed by the CURRENT runtime id (first legacy id when a
     * rename is pending, canonical id otherwise) so loader behavior is
     * byte-identical to the legacy get_all() map until the slug migration
     * flips runtime ids to canonical.
     */
    public static function get_file_map(): array {
        $map = [];
        foreach ( self::get_definitions() as $id => $def ) {
            $runtime_id         = $def['legacy_ids'][0] ?? $id;
            $map[ $runtime_id ] = $def['file'];
        }
        return $map;
    }

    /**
     * Returns the master list of all modules.
     * Key = module ID (string), Value = class file path relative to plugin root.
     *
     * LEGACY — kept byte-identical during the transition; the loader still
     * consumes this. Replaced by get_file_map() in the loader integration
     * commit. Do not add modules here; add a definition instead.
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

            // ── Custom Code (1 module) ───────────────────────────
            'code-snippets'               => 'modules/custom-code/class-code-snippets.php',

            // ── Disable Components (3 modules) ───────────────────
            'disable-frontend'            => 'modules/disable-components/class-disable-frontend.php',
            'disable-backend'             => 'modules/disable-components/class-disable-backend.php',
            'disable-gutenberg'           => 'modules/disable-components/class-disable-gutenberg.php',

            // ── Utilities (14 modules) ───────────────────────────
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

        ];
    }
}
