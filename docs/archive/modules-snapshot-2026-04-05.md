# WPTransformed Module Snapshot — 2026-04-05

Save point before v1 scope refinement. This document captures the exact
state of all modules, architecture, and dashboard features.

---

## 1. Module Count & Category Breakdown

**Total modules: 125**

| Category | Count | Fully Built | Stubs | % Complete |
|----------|-------|-------------|-------|------------|
| admin-interface | 35 | 13 | 22 | 37% |
| content-management | 20 | 8 | 12 | 40% |
| utilities | 18 | 14 | 4 | 78% |
| security | 13 | 8 | 5 | 62% |
| disable-components | 10 | 4 | 6 | 40% |
| performance | 9 | 8 | 1 | 89% |
| login-logout | 7 | 4 | 3 | 57% |
| custom-code | 7 | 5 | 2 | 71% |
| woocommerce | 5 | 0 | 5 | 0% |
| compliance | 1 | 1 | 0 | 100% |
| **TOTAL** | **125** | **65** | **60** | **52%** |

"Fully built" = init() has real hooks/filters, logic, and settings.
"Stub" = scaffolded with correct Module_Base interface but minimal/no init() logic.

---

## 2. Full Module List

### admin-interface (35 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| active-plugins-first | Active Plugins First | Stub | Yes | Move active plugins to the top of the plugins list for faster management. |
| activity-feed | Activity Feed | Stub | Yes | Real-time admin activity feed showing recent changes across the site. |
| admin-bar-enhancer | Admin Bar Enhancer | Stub | Yes | Add custom menus, shortcuts, and quick actions to the WordPress admin bar. |
| admin-body-classes | Admin Body Classes | Stub | Yes | Add custom CSS classes to the admin body tag for targeted styling. |
| admin-bookmarks | Admin Bookmarks | Stub | Yes | Save and organize bookmarks to frequently-used admin pages. |
| admin-color-schemes | Admin Color Schemes | Stub | Yes | Custom admin color schemes with modern palettes and one-click switching. |
| admin-columns-enhancer | Admin Columns Enhancer | Stub | Yes | Add, remove, and reorder columns in admin list tables with drag-and-drop. |
| admin-columns-pro | Admin Columns Pro | Stub | Yes | Advanced column types including ACF fields, taxonomy terms, and custom fields. |
| admin-menu-editor | Admin Menu Editor | Built | Yes | Reorganize, rename, and hide admin menu items for cleaner navigation. |
| admin-quick-notes | Admin Quick Notes | Stub | No | Sticky notes widget in the admin area for quick team communication. |
| clean-admin-bar | Admin Bar Manager | Built | Yes | Customize and declutter the WordPress admin bar with granular visibility controls. |
| client-dashboard | Client Dashboard | Stub | Yes | Simplified dashboard experience for clients with role-based content. |
| command-palette | Command Palette | Built | Yes | Keyboard-driven command palette (Ctrl+K) for instant navigation and actions. |
| custom-admin-footer | Custom Admin Footer | Built | Yes | Replace the default WordPress admin footer with custom text and branding. |
| dark-mode | Dark Mode | Built | Yes | Toggle dark mode for the entire WordPress admin interface. |
| dashboard-columns | Dashboard Columns | Stub | Yes | Control the number of columns on the WordPress dashboard. |
| enhance-list-tables | Enhance List Tables | Stub | Yes | Enhanced list tables with inline editing, bulk actions, and improved search. |
| environment-indicator | Environment Indicator | Built | Yes | Visual indicator showing current environment (dev/staging/production). |
| hide-admin-bar | Hide Admin Bar | Built | Yes | Hide the WordPress admin bar on the frontend for specific user roles. |
| hide-admin-notices | Hide Admin Notices | Built | Yes | Clean up admin notices with a collapsible notification center. |
| hide-dashboard-widgets | Hide Dashboard Widgets | Built | Yes | Remove unwanted default and third-party dashboard widgets. |
| keyboard-shortcuts | Keyboard Shortcuts | Stub | Yes | Custom keyboard shortcuts for common WordPress admin actions. |
| media-infinite-scroll | Media Infinite Scroll | Stub | Yes | Replace pagination with infinite scroll in the media library grid view. |
| notification-center | Notification Center | Stub | Yes | Centralized notification hub for all admin alerts and updates. |
| page-hierarchy-organizer | Page Hierarchy Organizer | Stub | Yes | Visual drag-and-drop organizer for page parent-child relationships. |
| page-template-column | Page Template Column | Stub | Yes | Display the assigned page template in the Pages list table. |
| preserve-taxonomy-hierarchy | Preserve Taxonomy Hierarchy | Stub | Yes | Maintain category/taxonomy hierarchy order in post editor metaboxes. |
| registration-date-column | Registration Date Column | Stub | Yes | Add a registration date column to the Users list table. |
| search-visibility-status | Search Visibility Status | Stub | Yes | Show search engine visibility status in the admin bar and dashboard. |
| setup-wizard | Setup Wizard | Stub | Yes | Guided activation wizard for first-time plugin configuration. |
| smart-menu-organizer | Smart Menu Organizer | Built | Yes | Auto-categorize and reorder admin sidebar with intelligent grouping. |
| taxonomy-filter | Taxonomy Filter | Stub | Yes | Add taxonomy dropdown filters to post list tables for quick filtering. |
| view-as-role | View as Role | Built | Yes | Preview the admin interface as any user role without switching accounts. |
| white-label | White Label | Built | Yes | Rebrand the WordPress admin with custom logos, names, and colors. |
| wider-admin-menu | Wider Admin Menu | Built | Yes | Increase the width of the WordPress admin sidebar menu. |

### content-management (20 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| auto-publish-missed | Auto Publish Missed | Stub | Yes | Automatically publish posts that missed their scheduled publish date. |
| avif-upload | AVIF Upload | Stub | Yes | Enable AVIF image format uploads in the WordPress media library. |
| bulk-edit-posts | Bulk Edit Posts | Stub | Yes | Extended bulk editing with custom fields, taxonomies, and post attributes. |
| content-calendar | Content Calendar | Stub | Yes | Visual content calendar with drag-and-drop scheduling for posts and pages. |
| content-duplication | Content Duplication | Built | Yes | One-click clone of any post, page, or CPT with all metadata and taxonomies. |
| content-order | Content Order | Built | Yes | Drag-and-drop reordering of posts, pages, and custom post types in the admin list table. |
| custom-content-types | Custom Content Types | Built | Yes | Register custom post types and taxonomies with a visual interface. |
| custom-nav-new-tab | Custom Nav New Tab | Stub | Yes | Force custom navigation menu items to open in a new browser tab. |
| duplicate-menu | Duplicate Menu | Stub | Yes | One-click duplication of WordPress navigation menus with all items and settings. |
| external-links-new-tab | External Links New Tab | Stub | Yes | Automatically open external links in new tabs with rel=noopener security. |
| external-permalinks | External Permalinks | Stub | Yes | Replace any post or page permalink with an external URL, with automatic 301 redirect. |
| image-sizes-panel | Image Sizes Panel | Stub | Yes | View and manage all registered image sizes with regeneration support. |
| local-user-avatar | Local User Avatar | Stub | Yes | Upload custom user avatars locally instead of relying on Gravatar. |
| media-folders | Media Folders | Stub | Yes | Organize your media library into folders with drag-and-drop support. |
| media-replace | Media Replace | Built | Yes | Replace media files in-place while keeping the same URL and attachment ID. |
| media-visibility-control | Media Visibility Control | Stub | Yes | Control media file visibility and restrict access based on user roles. |
| post-type-switcher | Post Type Switcher | Built | Yes | Switch post types (post, page, CPT) from the post editor or quick edit. |
| public-preview | Public Preview | Built | Yes | Share draft or pending posts with non-logged-in users via a secure, expiring preview link. |
| svg-upload | SVG Upload | Built | Yes | Allow SVG file uploads to the media library with security sanitization. |
| terms-order | Terms Order | Stub | Yes | Custom ordering for taxonomy terms with drag-and-drop in admin. |

### performance (9 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| auto-clear-caches | Auto Clear Caches | Built | Yes | Automatically purge page/object caches when content is updated. |
| database-cleanup | Database Cleanup | Built | Yes | Purge post revisions, expired transients, spam comments, and stale auto-drafts. |
| heartbeat-control | Heartbeat Control | Built | Yes | Control the WordPress Heartbeat API frequency or disable it on specific screens. |
| image-srcset-control | Image Srcset Control | Built | Yes | Control WordPress responsive image srcset output for performance optimization. |
| image-upload-control | Image Upload Control | Built | Yes | Set maximum upload dimensions and auto-resize oversized images on upload. |
| lazy-load | Lazy Load | Built | Yes | Native lazy loading for images and iframes with customizable thresholds. |
| minify-assets | Minify Assets | Built | Yes | Minify and combine CSS/JS assets for faster page loads. |
| plugin-profiler | Plugin Profiler | Stub | Yes | Profile plugin load times and identify performance bottlenecks. |
| revision-control | Revision Control | Built | Yes | Limit the number of post revisions stored per post type. |

### security (13 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| audit-log | Audit Log | Built | Yes | Comprehensive activity logging for posts, users, plugins, and settings changes. |
| captcha-protection | CAPTCHA Protection | Stub | Yes | Add CAPTCHA verification to login, registration, and comment forms. |
| disable-xmlrpc | Disable XML-RPC | Built | Yes | Completely disable XML-RPC or restrict to specific methods/IP addresses. |
| email-obfuscator | Email Obfuscator | Built | Yes | Obfuscate email addresses on the frontend to prevent spam harvesting. |
| limit-login-attempts | Limit Login Attempts | Built | Yes | Limit failed login attempts with configurable lockout duration and whitelist. |
| multiple-user-roles | Multiple User Roles | Stub | Yes | Assign multiple roles to a single WordPress user account. |
| obfuscate-author-slugs | Obfuscate Author Slugs | Stub | Yes | Replace author URL slugs with random strings to prevent username enumeration. |
| passkey-authentication | Passkey Authentication | Stub | Yes | WebAuthn/passkey-based passwordless login for WordPress. |
| password-protection | Password Protection | Built | Yes | Password-protect specific pages, posts, or entire sections of your site. |
| session-manager | Session Manager | Built | Yes | View and manage active user sessions with one-click termination. |
| temporary-user-access | Temporary User Access | Stub | Yes | Create temporary admin access links that expire after a set duration. |
| two-factor-auth | Two-Factor Authentication | Built | Yes | TOTP-based 2FA for any user role with backup codes and app support. |
| user-role-editor | User Role Editor | Built | Yes | Create, edit, and manage WordPress user roles and capabilities. |

### login-logout (7 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| change-login-url | Change Login URL | Built | Yes | Change the default wp-login.php URL to a custom login path. |
| last-login-column | Last Login Column | Built | Yes | Display the last login date and IP address in the Users list table. |
| login-customizer | Login Customizer | Built | Yes | Brand your login page with custom logos, backgrounds, colors, and layouts. |
| login-id-type | Login ID Type | Stub | Yes | Restrict login to email-only or username-only instead of both. |
| login-logout-menu | Login Logout Menu | Stub | Yes | Add login/logout/register links to any WordPress navigation menu. |
| redirect-after-login | Redirect After Login | Stub | Yes | Redirect users to a custom URL after login based on their role. |
| site-identity-login | Site Identity Login | Built | Yes | Display site title and tagline on the login page for brand consistency. |

### custom-code (7 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| ads-txt-manager | Ads.txt Manager | Built | Yes | Manage your ads.txt and app-ads.txt files directly from WordPress admin. |
| code-snippets | Code Snippets | Built | Yes | Inject custom PHP, CSS, and JS safely without touching your theme files. |
| custom-admin-css | Custom Admin CSS | Built | Yes | Add custom CSS to the WordPress admin area without editing theme files. |
| custom-body-class | Custom Body Class | Stub | Yes | Add custom CSS classes to the frontend body tag per post/page. |
| custom-frontend-code | Custom Frontend Code | Built | Yes | Inject custom code into the site header, footer, or body. |
| custom-frontend-css | Custom Frontend CSS | Built | Yes | Add custom CSS to the frontend without editing theme files. |
| robots-txt-manager | Robots.txt Manager | Built | Yes | Manage your robots.txt file directly from WordPress admin. |

### disable-components (10 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| disable-attachment-pages | Disable Attachment Pages | Built | Yes | Redirect attachment pages to the parent post or homepage. |
| disable-author-archives | Disable Author Archives | Stub | Yes | Disable author archive pages to prevent username enumeration. |
| disable-embeds | Disable Embeds | Built | Yes | Remove oEmbed discovery and prevent others from embedding your content. |
| disable-emojis | Disable Emojis | Built | Yes | Remove WordPress emoji scripts and styles for cleaner page loads. |
| disable-feeds | Disable Feeds | Stub | Yes | Disable all RSS/Atom feeds or redirect them to your site homepage. |
| disable-gutenberg | Disable Gutenberg | Built | Yes | Disable the block editor and restore the classic editor for specific post types. |
| disable-rest-api | Disable REST API | Stub | Yes | Restrict or disable the WordPress REST API for non-authenticated users. |
| disable-rest-fields | Disable REST Fields | Stub | Yes | Remove specific fields from REST API responses to reduce data exposure. |
| disable-self-pingbacks | Disable Self Pingbacks | Stub | Yes | Prevent WordPress from sending pingbacks to your own site. |
| disable-updates | Disable Updates | Stub | Yes | Selectively disable WordPress core, plugin, or theme update notifications. |

### utilities (18 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| 404-monitor | 404 Monitor | Built | Yes | Track and analyze 404 errors with referrer data and redirect suggestions. |
| broken-link-checker | Broken Link Checker | Built | Yes | Scan your site for broken links with background checking and one-click fixes. |
| cron-manager | Cron Manager | Built | Yes | View, edit, run, and delete WordPress scheduled cron events. |
| disable-comments | Disable Comments | Built | Yes | Globally disable comments and trackbacks, or per post type. |
| duplicate-widget | Duplicate Widget | Stub | Yes | One-click duplication of widgets in the WordPress widget editor. |
| email-log | Email Log | Built | Yes | Log all outgoing emails sent by WordPress with search and resend. |
| email-smtp | Email SMTP | Built | Yes | Configure SMTP for reliable email delivery with provider presets. |
| error-log-viewer | Error Log Viewer | Built | Yes | View and manage PHP error logs directly from the WordPress admin. |
| export-import-settings | Export/Import Settings | Built | No | Export and import all WPTransformed settings as a JSON file. |
| file-manager | File Manager | Built | Yes | Browse, edit, and manage files on your server from the WordPress admin. |
| form-builder | Form Builder | Stub | Yes | Drag-and-drop form builder with email notifications and entry management. |
| maintenance-mode | Maintenance Mode | Built | Yes | Enable maintenance mode with a customizable coming-soon page. |
| redirect-404 | Redirect 404 | Built | Yes | Automatically redirect 404 pages to a custom URL or the homepage. |
| redirect-manager | Redirect Manager | Built | Yes | Create and manage URL redirects (301, 302, 307) with regex support. |
| search-replace | Search & Replace | Built | Yes | Database-wide search and replace with support for serialized data. |
| system-summary | System Summary | Built | Yes | Comprehensive system information dashboard for debugging and support. |
| webhook-manager | Webhook Manager | Built | Yes | Send webhook notifications to external services on WordPress events. |
| workflow-automation | Workflow Automation | Stub | Yes | Trigger-condition-action automation engine for WordPress workflows. |

### woocommerce (5 modules)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| woo-admin-cleanup | WooCommerce Admin Cleanup | Stub | Yes | Remove unnecessary WooCommerce admin notices and dashboard widgets. |
| woo-custom-order-statuses | WooCommerce Custom Order Statuses | Stub | Yes | Create and manage custom order statuses for WooCommerce workflows. |
| woo-disable-reviews | WooCommerce Disable Reviews | Stub | Yes | Selectively disable product reviews and ratings in WooCommerce. |
| woo-empty-cart-button | WooCommerce Empty Cart Button | Stub | Yes | Add an 'Empty Cart' button to the WooCommerce cart page. |
| woo-login-redirect | WooCommerce Login Redirect | Stub | Yes | Redirect WooCommerce customers to a custom page after login. |

### compliance (1 module)

| Module ID | Title | Status | Has Settings | Description |
|-----------|-------|--------|--------------|-------------|
| cookie-consent | Cookie Consent | Built | Yes | GDPR/CCPA cookie consent banner with customizable styles and settings. |

---

## 3. Module Consolidation Map

Major modules that consolidate what would previously be separate plugins:

| Parent Module | Consolidates | Lines |
|---------------|-------------|-------|
| redirect-manager | URL redirects (301/302/307) + 404 logging + referrer tracking | 1257 |
| audit-log | Activity logging for posts, plugins, logins, settings, users | 1172 |
| code-snippets | PHP execution + CSS injection + JS injection + HTML insertion + error recovery | 1172 |
| workflow-automation | Trigger engine + condition evaluator + action executor | 1128 |
| two-factor-auth | TOTP + email codes + recovery codes + multi-method MFA | 1103 |
| search-replace | DB-wide search/replace + serialized data handling + dry-run preview | 1093 |
| database-cleanup | Revisions + trashed posts + spam comments + transients + orphaned metadata | 1039 |
| broken-link-checker | Background link scanner + status reporting + one-click fix | 1031 |
| custom-content-types | CPT builder + taxonomy registration + visual UI | 1030 |
| smart-menu-organizer | Auto-grouping sidebar + drag-and-drop reorder + plugin detection | 984 |
| clean-admin-bar | Admin bar visibility controls + item management + role-based rules | ~600 |
| login-customizer | Custom logo + background + colors + CSS + layout options | ~500 |

---

## 4. V4 Dashboard Features (Built)

### Global Admin Theme (all pages)
- Gradient sidebar reskin on native `#adminmenuwrap` (165deg, #0f2847 -> #1a4180 -> #2563eb)
- Glassmorphic topbar reskin on native `#wpadminbar` (58px, backdrop-filter blur(20px))
- Logo + search bar + upgrade card injected into WP sidebar via JS
- Section labels (CONTENT, SECURITY, DESIGN, TOOLS, CONFIGURE) via PHP menu manipulation
- Outfit + JetBrains Mono fonts, Font Awesome 6.4 icons
- Dark mode toggle (localStorage + user_meta AJAX persistence)
- Grain noise texture overlay (SVG feTurbulence)
- Restyled: buttons, inputs, selects, textareas, checkboxes, radios
- Restyled: .postbox (glassmorphic cards, 14px radius)
- Restyled: .wp-list-table (rounded, styled headers, hover rows)
- Restyled: .notice (10px radius, color-coded left borders)
- Restyled: pagination, screen options, footer
- Folded sidebar support
- Responsive: 1200px / 900px / 600px breakpoints

### WPTransformed Dashboard Page (admin.php?page=wptransformed)
- Welcome banner: gradient bg, animated orb drift (14s), dynamic greeting
- Ring stats: Performance (94), Security (A+), Modules active %
- Bento stat cards: 4-column grid (Active Modules, Performance Score, Memory Usage, Security Modules)
- Animated counters (data-count + data-suffix, 35 steps at 28ms)
- Pill tab filtering by category (All + 10 categories)
- Category sections with icon, title, "X of Y active" counter, 2px divider
- Module card grid: repeat(auto-fill, minmax(300px, 1fr))
- Module cards: icon, toggle switch, name, description, footer meta, badges (Pro), Configure Settings button
- Tooltips: absolute positioned above card, opacity transition on hover
- Bottom panels: System Status (6 items) + Quick Actions (4 links)
- Command palette: Ctrl+K, search modules + WP pages, keyboard navigation

### Editor Dashboard (admin.php?page=wpt-dashboard)
- Welcome card with dynamic greeting + content summary
- Stats row: Published, Drafts, Scheduled, In Review (wp_count_posts)
- Quick actions: New Post, New Page, Upload Media, Block Patterns
- Recent posts panel: 5 most recent with thumbnails, status badges
- Upcoming scheduled panel: date badge, title, scheduled time
- Writing tips card: 8 rotating tips based on day of year

### Module Settings Pages (admin.php?page=wptransformed&module=xxx)
- Back link to dashboard
- Module header: icon, title, description, toggle
- Settings form: form-table styling, save button
- Wrapped in .wpt-dashboard for consistent styling

---

## 5. Architecture Decisions

### Core Approach
- **STYLE, don't replace.** Restyle native WP admin elements via CSS, not custom DOM replacement.
- **No remove_menu_page().** All third-party plugin menus keep working.
- **Global admin theme.** admin-global.css/js loads on ALL admin pages.
- **Page-specific assets.** admin.css/js only loads on WPTransformed pages.
- **Variable aliasing.** admin.css aliases `var(--primary)` to `var(--wpt-primary)` for dark mode cascade.

### Tech Stack
- PHP 7.4+ (strict types, develop against 8.2+)
- Native WordPress admin UI (NO React, NO Vite, NO build step)
- MySQL/MariaDB with JSON columns
- Vanilla JS (no jQuery dependency)

### Module System
- Module_Base abstract class with strict contract
- Explicit registry (Module_Registry) instead of auto-discovery
- Settings stored in `{$wpdb->prefix}wpt_settings` table
- Module activation via Settings::toggle_module()
- Safe Mode URL to disable all modules on incompatible hosts

### Security (non-negotiable)
1. Every PHP file: `if ( ! defined( 'ABSPATH' ) ) exit;`
2. Every action: verify nonce + check capability
3. Every settings save: sanitize via module's `sanitize_settings()`
4. Every echo: `esc_html()`, `esc_attr()`, `esc_url()`
5. Every DB query: `$wpdb->prepare()` with placeholders

### Sidebar Width
- `--wpt-sidebar-width: 256px` defined in admin-global.css
- WP native sidebar restyled, not replaced
- Folded state: 36px width

### Menu Organization
- PHP `inject_section_labels()` at priority 999
- Moves Plugins (65->81) and Users (70->82) into CONFIGURE
- 5 sections: CONTENT (1), SECURITY (41), DESIGN (59), TOOLS (74), CONFIGURE (79)

---

## 6. Known Issues / In-Progress

### Confirmed Issues
- Performance Score ring shows static "94" (not calculated from real data)
- Memory Usage bento card shows WP_MEMORY_LIMIT (setting) not actual usage
- Welcome banner says "1 modules" (grammar: should be "1 module")
- Module settings page renders bare (no sidebar context) when navigated directly
- 60 modules are stubs with no real init() logic

### In-Progress
- UI Restructure sessions 2-8 not yet started:
  - Session 2: Editor Dashboard (done separately via class-editor-dashboard.php)
  - Session 3: Module Grid (partially done via V4 reconciliation)
  - Session 4: DB Optimizer + Audit Log app pages
  - Session 5: Login + Menu + White Label app pages
  - Session 6: Command Palette with full detection
  - Session 7: Activation Wizard
  - Session 8: QA & Polish

### Technical Debt
- admin.css still has submodules-panel CSS (unused — our modules don't have sub-modules)
- render_topbar() method deleted but SHARED COMPONENTS comment section remains
- WooCommerce category: all 5 modules are stubs (0% implementation)
- Freemius integration planned for v2 but not started

---

## 7. Pending Decisions

### 2FA Methods
- Currently: TOTP (authenticator apps) + email codes + recovery codes
- Pending: WebAuthn/Passkey (passkey-authentication module exists as stub)
- Decision needed: Ship both TOTP + Passkey in v1, or TOTP only?

### Forms Bundling
- form-builder module exists as a stub
- Decision: Is a basic form builder in scope for v1, or defer to v2?
- Risk: Feature creep — forms are a deep category (Gravity Forms, WPForms, etc.)

### WooCommerce Integration
- 5 stub modules exist (admin cleanup, custom order statuses, disable reviews, empty cart, login redirect)
- Decision: Include in v1 or defer? They only load when WooCommerce is active.
- Conditional detection already in the spec (ECOMMERCE section in sidebar)

### Module Tier System
- Module_Base has get_tier() method returning 'free' or 'pro'
- Freemius integration not built yet
- Decision: How many modules are Pro? Which ones?
- Current: No modules explicitly flagged as Pro except via dashboard badge rendering

### Sub-Module Architecture
- Reference mockup shows expandable sub-module panels per module
- Current implementation: Configure Settings button links to settings page instead
- Decision: Add sub-module UI in v1, or keep settings-page approach?

### Activation Wizard
- Spec defines 4 profiles: Blogger, Agency, Store, Developer
- Each pre-selects different module sets
- setup-wizard module exists as stub
- Decision: Ship wizard in v1 or post-launch?

### Performance Score
- Dashboard shows "94" as a static value
- Decision: Calculate from real metrics (page speed, caching status, etc.) or keep static?
- Risk: Inaccurate metric worse than no metric

---

## File Inventory

```
includes/
  class-admin.php          — Admin page registration, dashboard rendering, global hooks
  class-core.php           — Singleton loader, module activation, bootstrap
  class-editor-dashboard.php — Editor Dashboard (content workspace)
  class-module-base.php    — Abstract base for all modules
  class-module-registry.php — Explicit module registry (125 entries)
  class-safe-mode.php      — Emergency safe mode URL handler
  class-settings.php       — DB settings (wpt_settings table)

assets/admin/css/
  admin-global.css         — Global WP admin reskin (1168 lines, 12 sections)
  admin.css                — Dashboard page content styles (905 lines)
  editor-dashboard.css     — Editor Dashboard styles

assets/admin/js/
  admin-global.js          — Global JS: sidebar/topbar injection, dark mode, keyboard shortcuts
  admin.js                 — Dashboard JS: module toggles, pill tabs, command palette, counters
  editor-dashboard.js      — Editor Dashboard JS: animated counters

modules/                   — 125 module class files across 10 subdirectories
docs/                      — Architecture, spec, decisions, improvements
```
