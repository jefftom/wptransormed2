<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Hierarchy — Canonical parent/sub-module grouping.
 *
 * This is the single source of truth the Modules page renders against.
 * It mirrors docs/module-hierarchy.md: ~28 parent modules across 7 categories,
 * each parent containing a list of sub-module IDs. Sub-module IDs point at
 * real Module_Registry entries — any that don't exist yet (v2/v3 aspirational
 * modules from the expansion plan) are silently filtered out at render time
 * via filter_existing_sub_modules(), so parents only show what's actually
 * built. As new modules are added to Module_Registry, they appear in the
 * grid automatically without touching this file.
 *
 * Parents with zero built sub-modules are hidden from the grid entirely
 * (see has_any_built_sub_module()) so the UI never shows empty shells.
 *
 * Adding a new parent: add an entry to get_parents().
 * Adding a new sub-module to an existing parent: add the module ID to the
 * parent's sub_modules array.
 *
 * Registry ID vs spec ID mismatch: docs/module-hierarchy.md occasionally
 * uses different slugs (clean-admin-bar vs admin-bar, login-customizer vs
 * login-branding, form-builder vs forms, database-optimizer vs
 * database-cleanup, admin-columns-enhancer vs admin-columns,
 * custom-frontend-code vs custom-code). This file uses the REAL registry
 * IDs — the built implementation is the source of truth. Where the doc
 * lists an aspirational slug that doesn't exist yet, it's included below
 * (for v2 forward-compatibility) and will be filtered out until the module
 * is actually built.
 *
 * @package WPTransformed
 */
class Module_Hierarchy {

    // Canonical category slugs matching docs/ui-restructure-spec.md Section 3.
    // These drive the pill-tab filter at the top of the Modules page and map
    // to the color tokens used by mod-icon / category-icon helpers.
    public const CATEGORY_CORE        = 'core';
    public const CATEGORY_CONTENT     = 'content';
    public const CATEGORY_SECURITY    = 'security';
    public const CATEGORY_PERFORMANCE = 'performance';
    public const CATEGORY_DESIGN      = 'design';
    public const CATEGORY_DEVELOPER   = 'developer';
    public const CATEGORY_ECOMMERCE   = 'ecommerce';

    /**
     * Return the canonical 7 top-level categories in display order.
     *
     * Each entry provides label (pill-tab text), color token (for
     * category-icon / mod-icon background), and fa icon.
     *
     * @return array
     */
    public static function get_categories(): array {
        return [
            self::CATEGORY_CORE        => [ 'label' => __( 'Core', 'wptransformed' ),        'color' => 'core',   'icon' => 'fa-sliders-h' ],
            self::CATEGORY_CONTENT     => [ 'label' => __( 'Content', 'wptransformed' ),     'color' => 'violet', 'icon' => 'fa-cube' ],
            self::CATEGORY_SECURITY    => [ 'label' => __( 'Security', 'wptransformed' ),    'color' => 'sec',    'icon' => 'fa-shield-alt' ],
            self::CATEGORY_PERFORMANCE => [ 'label' => __( 'Performance', 'wptransformed' ), 'color' => 'perf',   'icon' => 'fa-rocket' ],
            self::CATEGORY_DESIGN      => [ 'label' => __( 'Design', 'wptransformed' ),      'color' => 'media',  'icon' => 'fa-palette' ],
            self::CATEGORY_DEVELOPER   => [ 'label' => __( 'Developer', 'wptransformed' ),   'color' => 'dev',    'icon' => 'fa-code' ],
            self::CATEGORY_ECOMMERCE   => [ 'label' => __( 'eCommerce', 'wptransformed' ),   'color' => 'perf',   'icon' => 'fa-shopping-cart' ],
        ];
    }

    /**
     * Return the full parent hierarchy. Ordered for display.
     *
     * Each parent entry has:
     *   - id           : kebab-case unique slug (not a module ID)
     *   - label        : display title
     *   - description  : one-sentence description for the card
     *   - category     : one of the CATEGORY_* constants
     *   - icon         : fa icon class (without 'fa-' prefix)
     *   - badges       : array of 'popular' | 'new' | 'app' | 'pro' (display order)
     *   - tier         : 'free' | 'pro' — drives Pro gating on the parent toggle
     *   - app_page     : admin page slug for APP parents (e.g. 'wpt-menu-editor'),
     *                    null for non-APP parents. When set, the parent card
     *                    renders an "Open app page →" link instead of the
     *                    expand/sub-modules panel.
     *   - sub_modules  : array of module IDs from Module_Registry::get_all()
     *                    IDs that don't exist yet are filtered out at render.
     *
     * @return array
     */
    public static function get_parents(): array {
        return [

            // ══════════════════════════════════════════
            // CORE — 6 parents
            // ══════════════════════════════════════════

            [
                'id'          => 'admin-bar-manager',
                'label'       => __( 'Admin Bar Manager', 'wptransformed' ),
                'description' => __( 'Customize the admin bar: strip branding, add useful nodes, hide for specific roles, and launch the command palette.', 'wptransformed' ),
                'category'    => self::CATEGORY_CORE,
                'icon'        => 'fa-bars',
                'badges'      => [ 'popular' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'admin-bar',          // clean-admin-bar in docs
                    'admin-bar-enhancer', // v2 — not built yet
                    'hide-admin-bar',     // v2 — not built yet
                    'command-palette',
                    'wider-admin-menu',
                ],
            ],

            [
                'id'          => 'dashboard-manager',
                'label'       => __( 'Dashboard Manager', 'wptransformed' ),
                'description' => __( 'Replace or reshape the WP dashboard: hide widgets, force columns, activity feed, quick notes, client view.', 'wptransformed' ),
                'category'    => self::CATEGORY_CORE,
                'icon'        => 'fa-tachometer-alt',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'hide-dashboard-widgets',
                    'dashboard-columns',
                    'activity-feed',
                    'admin-quick-notes',
                    'client-dashboard',
                    'duplicate-widget',
                    'dashboard-welcome-panel', // v2 — not built yet
                ],
            ],

            [
                'id'          => 'notifications-notices',
                'label'       => __( 'Notifications & Notices', 'wptransformed' ),
                'description' => __( 'Collapse scattered admin notices into a single tray with a badge counter and per-plugin filter.', 'wptransformed' ),
                'category'    => self::CATEGORY_CORE,
                'icon'        => 'fa-bell',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'hide-admin-notices',
                    'notification-center',
                ],
            ],

            [
                'id'          => 'list-tables-columns',
                'label'       => __( 'List Tables & Columns', 'wptransformed' ),
                'description' => __( 'Enhance WP list tables: sticky headers, custom columns, sortable/filterable, inline-edit, last-login, template, and more.', 'wptransformed' ),
                'category'    => self::CATEGORY_CORE,
                'icon'        => 'fa-table',
                'badges'      => [ 'new' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'enhance-list-tables',
                    'admin-columns',              // admin-columns-enhancer in docs
                    'admin-columns-pro',          // v2 Pro — not built yet
                    'page-template-column',       // v2 — not built yet
                    'registration-date-column',   // v2 — not built yet
                    'search-visibility-status',
                    'taxonomy-filter',
                    'last-login-column',          // v2 — not built yet
                    'active-plugins-first',
                ],
            ],

            [
                'id'          => 'keyboard-shortcuts-bookmarks',
                'label'       => __( 'Keyboard Shortcuts & Bookmarks', 'wptransformed' ),
                'description' => __( 'Global shortcuts, a searchable cheat sheet modal, and personal bookmarks for any admin URL.', 'wptransformed' ),
                'category'    => self::CATEGORY_CORE,
                'icon'        => 'fa-keyboard',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'keyboard-shortcuts',
                    'admin-bookmarks',
                    'admin-shortcut-cheatsheet', // v2 — not built yet
                ],
            ],

            [
                'id'          => 'user-role-manager',
                'label'       => __( 'User & Role Manager', 'wptransformed' ),
                'description' => __( 'Multiple roles per user, role editor, active sessions, temporary access, view-as-role preview, and per-role UI profiles.', 'wptransformed' ),
                'category'    => self::CATEGORY_CORE,
                'icon'        => 'fa-users-cog',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'multiple-user-roles',
                    'user-role-editor',
                    'session-manager',
                    'temporary-user-access',
                    'view-as-role',
                    'per-role-ui-profiles', // v2 — not built yet
                ],
            ],

            // ══════════════════════════════════════════
            // CONTENT — 6 parents
            // ══════════════════════════════════════════

            [
                'id'          => 'content-tools',
                'label'       => __( 'Content Tools', 'wptransformed' ),
                'description' => __( 'Duplicate posts, reorder content, bulk-edit meta, switch post types, public preview links, disable comments, and a lightweight form builder.', 'wptransformed' ),
                'category'    => self::CATEGORY_CONTENT,
                'icon'        => 'fa-pen-alt',
                'badges'      => [ 'popular' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'content-duplication',
                    'content-order',
                    'terms-order',
                    'bulk-edit-posts',
                    'post-type-switcher',
                    'public-preview',
                    'external-links-new-tab',
                    'external-permalinks',
                    'disable-comments',
                    'forms', // form-builder in docs
                ],
            ],

            [
                'id'          => 'content-scheduling-workflow',
                'label'       => __( 'Content Scheduling & Workflow', 'wptransformed' ),
                'description' => __( 'Calendar view for scheduled and published posts, auto-publish missed schedules, and editorial workflow states.', 'wptransformed' ),
                'category'    => self::CATEGORY_CONTENT,
                'icon'        => 'fa-calendar-alt',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'content-calendar',     // v2 — not built yet
                    'auto-publish-missed',
                    'workflow-automation',  // deferred — not built yet
                ],
            ],

            [
                'id'          => 'editor-enhancements',
                'label'       => __( 'Editor Enhancements', 'wptransformed' ),
                'description' => __( 'Gutenberg fallback, drag-drop page hierarchy editor, and taxonomy-checkbox hierarchy preservation.', 'wptransformed' ),
                'category'    => self::CATEGORY_CONTENT,
                'icon'        => 'fa-edit',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'disable-gutenberg',
                    'page-hierarchy-organizer',
                    'preserve-taxonomy-hierarchy',
                ],
            ],

            [
                'id'          => 'media-library-pro',
                'label'       => __( 'Media Library Pro', 'wptransformed' ),
                'description' => __( 'Folders, replace-in-place, SVG/AVIF upload, per-image size regeneration, infinite scroll, and per-user attachment visibility.', 'wptransformed' ),
                'category'    => self::CATEGORY_CONTENT,
                'icon'        => 'fa-photo-film',
                'badges'      => [ 'popular' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'media-library-pro',       // monolithic module that encompasses the feature set
                    'media-folders',           // v2 — not built yet
                    'media-replace',           // v2 — not built yet
                    'svg-upload',              // v2 — not built yet
                    'avif-upload',             // v2 Pro — not built yet
                    'image-sizes-panel',       // v2 — not built yet
                    'media-visibility-control',// v2 — not built yet
                    'media-infinite-scroll',
                    'local-user-avatar',       // v2 — not built yet
                ],
            ],

            [
                'id'          => 'navigation-menus',
                'label'       => __( 'Navigation & Menus', 'wptransformed' ),
                'description' => __( 'Full drag-drop rebuild of the admin sidebar, auto-organize third-party plugin items, and custom nav menu tweaks.', 'wptransformed' ),
                'category'    => self::CATEGORY_CONTENT,
                'icon'        => 'fa-list',
                'badges'      => [ 'popular', 'app' ],
                'tier'        => 'free',
                'app_page'    => 'wpt-menu-editor', // APP page — deferred until Session 5 builds it
                'sub_modules' => [
                    'admin-menu-editor',
                    'smart-menu-organizer',
                    'custom-nav-new-tab',
                    'duplicate-menu',
                ],
            ],

            [
                'id'          => 'page-builder-cleanup',
                'label'       => __( 'Page Builder Cleanup', 'wptransformed' ),
                'description' => __( 'Restrict builders per post type, conditional asset loading, and shortcode cleanup when a builder is deactivated.', 'wptransformed' ),
                'category'    => self::CATEGORY_CONTENT,
                'icon'        => 'fa-puzzle-piece',
                'badges'      => [ 'new' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    // Dynamic per detected builder — no fixed subs yet.
                    // Will populate when page-builder-cleanup modules are built.
                ],
            ],

            // ══════════════════════════════════════════
            // SECURITY — 4 parents
            // ══════════════════════════════════════════

            [
                'id'          => 'firewall-hardening',
                'label'       => __( 'Firewall & Hardening', 'wptransformed' ),
                'description' => __( 'Disable XML-RPC and unauthenticated REST, obfuscate emails and author slugs, block user enumeration, ship security headers, and alert on suspicious activity.', 'wptransformed' ),
                'category'    => self::CATEGORY_SECURITY,
                'icon'        => 'fa-shield-alt',
                'badges'      => [ 'popular' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'disable-xmlrpc',             // v2 — not built yet
                    'password-protection',
                    'disable-rest-api',           // v2 — not built yet
                    'disable-rest-fields',        // v2 — not built yet
                    'email-obfuscator',
                    'obfuscate-author-slugs',
                    'security-headers',           // v2 — not built yet
                    'honeypot-forms',             // v2 — not built yet
                    'suspicious-activity-alerts', // v2 — not built yet
                    'user-enumeration-block',     // v2 — not built yet
                ],
            ],

            [
                'id'          => 'login-protection',
                'label'       => __( 'Login Protection', 'wptransformed' ),
                'description' => __( 'Rate-limit failed logins, add a CAPTCHA, hide wp-login.php behind a custom slug, and enforce strong password policies per role.', 'wptransformed' ),
                'category'    => self::CATEGORY_SECURITY,
                'icon'        => 'fa-sign-in-alt',
                'badges'      => [ 'app' ],
                'tier'        => 'free',
                'app_page'    => 'wpt-login-protection', // APP page — deferred until built
                'sub_modules' => [
                    'limit-login-attempts',   // not built (login-security is related but not identical)
                    'login-security',         // existing catch-all
                    'captcha-protection',     // v2 — not built yet
                    'change-login-url',       // v2 — not built yet
                    'login-id-type',          // v2 — not built yet
                    'login-logout-menu',
                    'redirect-after-login',
                    'strong-passwords',       // existing
                    'strong-password-policy', // v2 — not built yet
                    'login-notifications',    // existing
                ],
            ],

            [
                'id'          => 'two-factor-auth',
                'label'       => __( 'Two-Factor Auth', 'wptransformed' ),
                'description' => __( 'TOTP (Google Authenticator, Authy, 1Password) as primary, email as fallback, recovery codes, and admin override for lockouts.', 'wptransformed' ),
                'category'    => self::CATEGORY_SECURITY,
                'icon'        => 'fa-key',
                'badges'      => [ 'pro' ],
                'tier'        => 'pro',
                'app_page'    => null,
                'sub_modules' => [
                    'two-factor-auth',
                    'passkey-auth', // deferred to Pro/v3 — not built yet
                ],
            ],

            [
                'id'          => 'audit-log',
                'label'       => __( 'Audit Log', 'wptransformed' ),
                'description' => __( 'Comprehensive event log: logins, edits, role changes, plugin activation, settings changes. Filterable, exportable, searchable.', 'wptransformed' ),
                'category'    => self::CATEGORY_SECURITY,
                'icon'        => 'fa-clipboard-list',
                'badges'      => [ 'app' ],
                'tier'        => 'free',
                'app_page'    => 'wpt-audit-log', // APP page — deferred until Session 4 builds it
                'sub_modules' => [
                    'audit-log',
                ],
            ],

            // ══════════════════════════════════════════
            // PERFORMANCE — 4 parents
            // ══════════════════════════════════════════

            [
                'id'          => 'asset-optimizer',
                'label'       => __( 'Asset Optimizer', 'wptransformed' ),
                'description' => __( 'Minify CSS/JS, disable WP embeds and emojis, and auto-flush the active cache plugin on post update.', 'wptransformed' ),
                'category'    => self::CATEGORY_PERFORMANCE,
                'icon'        => 'fa-compress-arrows-alt',
                'badges'      => [ 'popular', 'new' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'minify-assets',
                    'disable-embeds', // v2 — not built yet
                    'disable-emojis', // v2 — not built yet
                    'auto-clear-caches',
                ],
            ],

            [
                'id'          => 'image-optimizer',
                'label'       => __( 'Image Optimizer', 'wptransformed' ),
                'description' => __( 'Strip EXIF, cap upload dimensions, force WebP, control responsive srcset, and enforce native lazy-loading.', 'wptransformed' ),
                'category'    => self::CATEGORY_PERFORMANCE,
                'icon'        => 'fa-image',
                'badges'      => [ 'new' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'image-upload-control',
                    'image-srcset-control',
                    'lazy-load',
                ],
            ],

            [
                'id'          => 'site-speed',
                'label'       => __( 'Site Speed', 'wptransformed' ),
                'description' => __( 'Heartbeat control, kill self-pingbacks, redirect attachment pages, disable feeds and author archives, and limit revisions.', 'wptransformed' ),
                'category'    => self::CATEGORY_PERFORMANCE,
                'icon'        => 'fa-rocket',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'heartbeat-control',
                    'disable-self-pingbacks',   // v2 — not built yet
                    'disable-attachment-pages', // v2 — not built yet
                    'disable-author-archives',  // v2 — not built yet
                    'disable-feeds',            // v2 — not built yet
                    'revision-control',
                    'object-cache-status',      // v2 — not built yet
                    'prefetch-on-hover',        // v2 — not built yet
                ],
            ],

            [
                'id'          => 'database-optimizer',
                'label'       => __( 'Database Optimizer', 'wptransformed' ),
                'description' => __( 'Cleanup tasks, auto-schedule, table-by-table size view, autoloaded-options audit, and transient garbage collection.', 'wptransformed' ),
                'category'    => self::CATEGORY_PERFORMANCE,
                'icon'        => 'fa-database',
                'badges'      => [ 'app' ],
                'tier'        => 'free',
                'app_page'    => 'wpt-database', // APP page — deferred until Session 4 builds it
                'sub_modules' => [
                    'database-cleanup', // database-optimizer in docs
                    'autoloaded-options-audit', // v2 — not built yet
                    'transient-cleanup',        // v2 — not built yet
                ],
            ],

            // ══════════════════════════════════════════
            // DESIGN — 3 parents
            // ══════════════════════════════════════════

            [
                'id'          => 'login-designer',
                'label'       => __( 'Login Designer', 'wptransformed' ),
                'description' => __( 'Visual customizer for wp-login.php: logo, background, form styles, button colors, and layout templates. Live preview.', 'wptransformed' ),
                'category'    => self::CATEGORY_DESIGN,
                'icon'        => 'fa-paint-brush',
                'badges'      => [ 'popular', 'app' ],
                'tier'        => 'free',
                'app_page'    => 'wpt-login-designer', // APP page — deferred until Session 5 builds it
                'sub_modules' => [
                    'login-branding',      // login-customizer in docs
                    'site-identity-login', // v2 — not built yet
                ],
            ],

            [
                'id'          => 'white-label',
                'label'       => __( 'White Label', 'wptransformed' ),
                'description' => __( 'Hide WordPress branding, rename admin footer, replace login logo, custom admin color scheme, and custom help tab.', 'wptransformed' ),
                'category'    => self::CATEGORY_DESIGN,
                'icon'        => 'fa-tag',
                'badges'      => [ 'pro', 'app' ],
                'tier'        => 'pro',
                'app_page'    => 'wpt-white-label', // APP page — deferred until Session 5 builds it
                'sub_modules' => [
                    'white-label',
                    'custom-admin-footer',
                ],
            ],

            [
                'id'          => 'admin-theme',
                'label'       => __( 'Admin Theme', 'wptransformed' ),
                'description' => __( 'System-aware dark mode, extended color schemes, environment indicator bar, and contextual body classes.', 'wptransformed' ),
                'category'    => self::CATEGORY_DESIGN,
                'icon'        => 'fa-moon',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'dark-mode',
                    'admin-color-schemes',
                    'environment-indicator',
                    'admin-body-classes',
                ],
            ],

            // ══════════════════════════════════════════
            // DEVELOPER — 5 parents (includes Code Snippets + Debug + CPT + Utilities + Dev Tools)
            // ══════════════════════════════════════════

            [
                'id'          => 'code-snippets',
                'label'       => __( 'Code Snippets', 'wptransformed' ),
                'description' => __( 'Run PHP, CSS, JS, or HTML snippets without editing theme files. Per-snippet toggle, conditional loading, injection into head/footer.', 'wptransformed' ),
                'category'    => self::CATEGORY_DEVELOPER,
                'icon'        => 'fa-code',
                'badges'      => [ 'popular' ],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'code-snippets',
                    'custom-admin-css',     // v2 — not built yet
                    'custom-code',          // custom-frontend-code in docs
                    'custom-frontend-css',  // v2 — not built yet
                    'custom-body-class',    // v2 — not built yet
                ],
            ],

            [
                'id'          => 'debug-tools',
                'label'       => __( 'Debug Tools', 'wptransformed' ),
                'description' => __( 'In-admin error log viewer, system summary, plugin profiler, and a hook inspector for any admin request.', 'wptransformed' ),
                'category'    => self::CATEGORY_DEVELOPER,
                'icon'        => 'fa-bug',
                'badges'      => [ 'pro' ],
                'tier'        => 'pro',
                'app_page'    => null,
                'sub_modules' => [
                    'plugin-profiler', // v2 — not built yet
                    'error-log-viewer',
                    'system-summary',
                    'hook-inspector',  // v2 — not built yet
                ],
            ],

            [
                'id'          => 'custom-post-types',
                'label'       => __( 'Custom Post Types', 'wptransformed' ),
                'description' => __( 'GUI builder for custom post types, taxonomies, and meta fields. Export as code.', 'wptransformed' ),
                'category'    => self::CATEGORY_DEVELOPER,
                'icon'        => 'fa-cubes',
                'badges'      => [ 'pro' ],
                'tier'        => 'pro',
                'app_page'    => null,
                'sub_modules' => [
                    'custom-content-types', // v2 — not built yet
                ],
            ],

            [
                'id'          => 'site-utilities',
                'label'       => __( 'Site Utilities', 'wptransformed' ),
                'description' => __( 'Maintenance mode, 301/302 redirects, 404 monitor, broken link checker, SMTP, email log, front/back-end kill-switches, and selective update control.', 'wptransformed' ),
                'category'    => self::CATEGORY_DEVELOPER,
                'icon'        => 'fa-tools',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'maintenance-mode',
                    'disable-frontend', // legacy kill-switch, grouped with maintenance-mode family
                    'disable-backend',  // legacy kill-switch, grouped with maintenance-mode family
                    'redirect-manager',
                    'redirect-404',
                    '404-monitor',
                    'broken-link-checker',
                    'email-smtp',
                    'email-log',
                    'disable-updates', // v2 — not built yet
                ],
            ],

            [
                'id'          => 'developer-tools',
                'label'       => __( 'Developer Tools', 'wptransformed' ),
                'description' => __( 'Safe search-replace, cron manager, webhooks, ads.txt and robots.txt editors, options/transient/rewrite-rules browsers.', 'wptransformed' ),
                'category'    => self::CATEGORY_DEVELOPER,
                'icon'        => 'fa-wrench',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'search-replace',
                    'cron-manager',
                    'file-manager',          // v2 — not built yet (locked-down)
                    'webhook-manager',       // v2 — not built yet
                    'ads-txt-manager',       // v2 — not built yet
                    'robots-txt-manager',    // v2 — not built yet
                    'export-import-settings',
                    'options-browser',       // v2 — not built yet
                    'transient-browser',     // v2 — not built yet
                    'rewrite-rules-viewer',  // v2 — not built yet
                    'capability-tester',     // v2 — not built yet
                ],
            ],

            // ══════════════════════════════════════════
            // ECOMMERCE — 1 parent (conditional)
            // Only shown when WooCommerce/EDD/SureCart is detected. The parent
            // entry is returned unconditionally here; the renderer decides
            // whether to display it based on is_plugin_active() checks. Sub-
            // modules are all v2 — not built yet — so the parent is hidden
            // automatically via has_any_built_sub_module() on a fresh install.
            // ══════════════════════════════════════════

            [
                'id'          => 'woocommerce-enhancements',
                'label'       => __( 'WooCommerce Enhancements', 'wptransformed' ),
                'description' => __( 'Clean up WooCommerce admin notices, custom order statuses, disable reviews, empty-cart button, and per-customer login redirects.', 'wptransformed' ),
                'category'    => self::CATEGORY_ECOMMERCE,
                'icon'        => 'fa-store',
                'badges'      => [],
                'tier'        => 'free',
                'app_page'    => null,
                'sub_modules' => [
                    'woo-admin-cleanup',    // v2 — not built yet
                    'woo-custom-statuses',  // v2 — not built yet
                    'woo-disable-reviews',  // v2 — not built yet
                    'woo-empty-cart-button',// v2 — not built yet
                    'woo-login-redirect',   // v2 — not built yet
                ],
            ],
        ];
    }

    /**
     * Return only the sub-module IDs that actually exist in Module_Registry.
     *
     * Used by the renderer to skip aspirational v2/v3 entries until they're
     * actually built. A parent with an empty filtered list is hidden by
     * has_any_built_sub_module().
     *
     * @param array $sub_ids Sub-module IDs to filter.
     * @return array Filtered list preserving input order.
     */
    public static function filter_existing_sub_modules( array $sub_ids ): array {
        $registry = Module_Registry::get_all();
        $out      = [];
        foreach ( $sub_ids as $id ) {
            if ( isset( $registry[ $id ] ) ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Whether a parent has at least one sub-module that's actually built.
     *
     * Parents with zero built sub-modules are hidden from the grid entirely
     * so the UI never shows empty shells for not-yet-implemented scope.
     *
     * @param array $parent A parent entry from get_parents().
     */
    public static function has_any_built_sub_module( array $parent ): bool {
        $filtered = self::filter_existing_sub_modules( $parent['sub_modules'] ?? [] );
        return ! empty( $filtered );
    }

    /**
     * Return parents that have at least one built sub-module, in display order.
     *
     * This is the list the Modules page actually renders.
     *
     * @return array
     */
    public static function get_visible_parents(): array {
        $out = [];
        foreach ( self::get_parents() as $parent ) {
            if ( self::has_any_built_sub_module( $parent ) ) {
                $out[] = $parent;
            }
        }
        return $out;
    }

    /**
     * Return the parent ID that contains the given module ID, or null.
     *
     * Useful for module settings pages that want to link back to their
     * parent card, and for keeping the parent toggle in sync when a
     * sub-module toggle changes.
     *
     * @param string $module_id A Module_Registry ID.
     * @return string|null Parent ID (not a module ID) or null if unassigned.
     */
    public static function get_parent_for_module( string $module_id ): ?string {
        foreach ( self::get_parents() as $parent ) {
            if ( in_array( $module_id, $parent['sub_modules'] ?? [], true ) ) {
                return $parent['id'];
            }
        }
        return null;
    }
}
