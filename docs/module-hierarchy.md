# WPTransformed Module Hierarchy

> GENERATED FILE — do not hand-edit. Regenerate with: `php tests/generate-module-hierarchy.php`
> Generated: 2026-06-10 from Module_Registry definitions (canonical slugs per build
> authority v5.3.6 §12 / canonical scope v5.2.3) + Module_Hierarchy grouping + Phase 1 reframe
> v4.1 statuses. Supersedes the archived 141-module hierarchy (`docs/archive/module-hierarchy.md`),
> which is NOT implementation authority.

## Summary

- Registry modules: **83** (77 implemented, 6 stubs)
- Tiers: **76 Core**, **7 Pro**
- Display categories: 7; parent cards: 29 (26 visible, 3 hidden — zero built sub-modules)
- Implemented split: **70 Core implemented**, **7 Pro implemented**, 6 Core stubs
- Pro modules: `white-label`, `client-dashboard`, `terms-order`, `two-factor-authentication`, `temporary-user-access`, `email-log`, `error-log-viewer`
- Stub modules (spec exists, implementation pending): `media-library-pro`, `login-protection`, `strong-passwords`, `login-notifications`, `disable-frontend`, `disable-backend`
- Mixed-tier parent cards (Core + Pro sub-modules; per-sub Pro gating applies): **6**
- Companion integrations: none defined yet (no companion tier/status in definitions; companions register via the `wpt_registered_modules` filter when built)
- Not assigned to any parent card: `setup-wizard`

## Display Hierarchy

### Core (6 parents)

#### Admin Bar Manager — `admin-bar-manager` _[badges: popular]_

Customize the admin bar: strip branding, add useful nodes, hide for specific roles, and launch the command palette.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `admin-bar-manager` | Admin Bar | core | safe | implemented | `admin-bar` |
| `command-palette` | Command Palette | core | safe | implemented | — |
| `wider-admin-menu` | Wider Admin Menu | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `admin-bar-enhancer`, `hide-admin-bar`

#### Dashboard Manager — `dashboard-manager`

Replace or reshape the WP dashboard: hide widgets, force columns, activity feed, quick notes, client view.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `hide-dashboard-widgets` | Hide Dashboard Widgets | core | safe | implemented | — |
| `dashboard-columns` | Dashboard Columns | core | safe | implemented | — |
| `activity-feed` | Activity Feed | core | safe | implemented | — |
| `admin-quick-notes` | Admin Quick Notes | core | safe | implemented | — |
| `client-dashboard` | Client Dashboard | pro | safe | implemented | — |
| `duplicate-widget` | Duplicate Widget | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `dashboard-welcome-panel`

#### Notifications & Notices — `notifications-notices`

Collapse scattered admin notices into a single tray with a badge counter and per-plugin filter.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `hide-admin-notices` | Hide Admin Notices | core | safe | implemented | — |
| `notification-center` | Notification Center | core | safe | implemented | — |

#### List Tables & Columns — `list-tables-columns` _[badges: new]_

Enhance WP list tables: sticky headers, custom columns, sortable/filterable, inline-edit, last-login, template, and more.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `list-table-enhancements` | Enhance List Tables | core | safe | implemented | `enhance-list-tables` |
| `admin-columns` | Admin Columns | core | safe | implemented | — |
| `search-visibility-status` | Search Visibility Status | core | safe | implemented | — |
| `taxonomy-filter` | Taxonomy Filter | core | safe | implemented | — |
| `active-plugins-first` | Active Plugins First | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `admin-columns-pro`, `page-template-column`, `registration-date-column`, `last-login-column`

#### Keyboard Shortcuts & Bookmarks — `keyboard-shortcuts-bookmarks`

Global shortcuts, a searchable cheat sheet modal, and personal bookmarks for any admin URL.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `keyboard-shortcuts` | Keyboard Shortcuts | core | safe | implemented | — |
| `admin-bookmarks` | Admin Bookmarks | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `admin-shortcut-cheatsheet`

#### User & Role Manager — `user-role-manager`

Multiple roles per user, role editor, active sessions, temporary access, view-as-role preview, and per-role UI profiles.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `multiple-user-roles` | Multiple User Roles | core | safe | implemented | — |
| `role-manager` | User Role Editor | core | advanced | implemented | `user-role-editor` |
| `session-manager` | Session Manager | core | moderate | implemented | — |
| `temporary-user-access` | Temporary User Access | pro | safe | implemented | — |
| `view-as-role` | View as Role | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `per-role-ui-profiles`

### Content (5 parents)

#### Content Tools — `content-tools` _[badges: popular]_

Duplicate posts, reorder content, bulk-edit meta, switch post types, public preview links, disable comments, and a lightweight form builder.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `content-duplication` | Content Duplication | core | safe | implemented | — |
| `content-order` | Content Order | core | safe | implemented | — |
| `terms-order` | Terms Order | pro | safe | implemented | — |
| `bulk-content-editor` | Bulk Edit Posts | core | advanced | implemented | `bulk-edit-posts` |
| `post-type-switcher` | Post Type Switcher | core | safe | implemented | — |
| `public-preview` | Public Preview | core | safe | implemented | — |
| `external-links-new-tab` | External Links New Tab | core | safe | implemented | — |
| `external-permalinks` | External Permalinks | core | safe | implemented | — |
| `disable-comments` | Disable Comments | core | moderate | implemented | — |

#### Content Scheduling & Workflow — `content-scheduling-workflow`

Calendar view for scheduled and published posts, auto-publish missed schedules, and editorial workflow states.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `auto-publish-missed-schedule` | Auto Publish Missed | core | safe | implemented | `auto-publish-missed` |

Deferred (no definition yet — filtered from the UI): `content-calendar`, `workflow-automation`

#### Editor Enhancements — `editor-enhancements`

Gutenberg fallback, drag-drop page hierarchy editor, and taxonomy-checkbox hierarchy preservation.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `disable-gutenberg` | Disable Gutenberg | core | moderate | implemented | — |
| `page-hierarchy-organizer` | Page Hierarchy Organizer | core | safe | implemented | — |
| `preserve-taxonomy-hierarchy` | Preserve Taxonomy Hierarchy | core | safe | implemented | — |

#### Media Library Pro — `media-library-pro` _[badges: popular]_

Folders, replace-in-place, SVG/AVIF upload, per-image size regeneration, infinite scroll, and per-user attachment visibility.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `media-library-pro` | Media Library Pro | core | safe | stub | — |
| `media-infinite-scroll` | Media Infinite Scroll | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `media-folders`, `media-replace`, `svg-upload`, `avif-upload`, `image-sizes-panel`, `media-visibility-control`, `local-user-avatar`

#### Navigation & Menus — `navigation-menus` _[APP → `wpt-menu-editor`; badges: popular/app]_

Full drag-drop rebuild of the admin sidebar, auto-organize third-party plugin items, and custom nav menu tweaks.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `admin-menu-editor` | Admin Menu Editor | core | safe | implemented | — |
| `smart-menu-organizer` | Smart Menu Organizer | core | safe | implemented | — |
| `custom-nav-new-tab` | Custom Nav New Tab | core | safe | implemented | — |
| `duplicate-menu` | Duplicate Menu | core | safe | implemented | — |

### Security (4 parents)

#### Firewall & Hardening — `firewall-hardening` _[badges: popular]_

Disable XML-RPC and unauthenticated REST, obfuscate emails and author slugs, block user enumeration, ship security headers, and alert on suspicious activity.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `password-protection` | Password Protection | core | moderate | implemented | — |
| `email-obfuscator` | Email Obfuscator | core | safe | implemented | — |
| `obfuscate-author-slugs` | Obfuscate Author Slugs | core | moderate | implemented | — |

Deferred (no definition yet — filtered from the UI): `disable-xmlrpc`, `disable-rest-api`, `disable-rest-fields`, `security-headers`, `honeypot-forms`, `suspicious-activity-alerts`, `user-enumeration-block`

#### Login Protection — `login-protection`

Rate-limit failed logins, add a CAPTCHA, hide wp-login.php behind a custom slug, and enforce strong password policies per role.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `login-protection` | Login Security | core | advanced | stub | `login-security` |
| `login-logout-menu` | Login Logout Menu | core | safe | implemented | — |
| `redirect-after-login` | Redirect After Login | core | safe | implemented | — |
| `strong-passwords` | Strong Password Enforcement | core | moderate | stub | — |
| `login-notifications` | Login Notifications | core | moderate | stub | — |

Deferred (no definition yet — filtered from the UI): `limit-login-attempts`, `captcha-protection`, `change-login-url`, `login-id-type`, `strong-password-policy`

#### Two-Factor Auth — `two-factor-auth` _(grouping key only — not a module id)_ _[LOCKED (all sub-modules Pro)]_

TOTP (Google Authenticator, Authy, 1Password) as primary, email as fallback, recovery codes, and admin override for lockouts.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `two-factor-authentication` | Two-Factor Authentication | pro | moderate | implemented | `two-factor-auth` |

Deferred (no definition yet — filtered from the UI): `passkey-auth`

#### Audit Log — `audit-log` _[APP → `wpt-audit-log`; badges: app]_

Comprehensive event log: logins, edits, role changes, plugin activation, settings changes. Filterable, exportable, searchable.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `audit-log` | Audit Log | core | safe | implemented | — |

### Performance (4 parents)

#### Asset Optimizer — `asset-optimizer` _[badges: popular/new]_

Minify CSS/JS, disable WP embeds and emojis, and auto-flush the active cache plugin on post update.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `minify-assets` | Minify Assets | core | moderate | implemented | — |
| `auto-clear-caches` | Auto Clear Caches | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `disable-embeds`, `disable-emojis`

#### Image Optimizer — `image-optimizer` _[badges: new]_

Strip EXIF, cap upload dimensions, force WebP, control responsive srcset, and enforce native lazy-loading.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `image-upload-control` | Image Upload Control | core | safe | implemented | — |
| `image-srcset-control` | Image Srcset Control | core | safe | implemented | — |
| `lazy-load` | Lazy Load | core | safe | implemented | — |

#### Site Speed — `site-speed`

Heartbeat control, kill self-pingbacks, redirect attachment pages, disable feeds and author archives, and limit revisions.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `heartbeat-control` | Heartbeat Control | core | safe | implemented | — |
| `revision-control` | Revision Control | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `disable-self-pingbacks`, `disable-attachment-pages`, `disable-author-archives`, `disable-feeds`, `object-cache-status`, `prefetch-on-hover`

#### Database Optimizer — `database-optimizer` _[APP → `wpt-database`; badges: app]_

Cleanup tasks, auto-schedule, table-by-table size view, autoloaded-options audit, and transient garbage collection.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `database-optimizer` | Database Cleanup | core | advanced | implemented | `database-cleanup` |

Deferred (no definition yet — filtered from the UI): `autoloaded-options-audit`, `transient-cleanup`

### Design (3 parents)

#### Login Designer — `login-designer` _[APP → `wpt-login-designer`; badges: popular/app]_

Visual customizer for wp-login.php: logo, background, form styles, button colors, and layout templates. Live preview.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `login-designer` | Login Branding | core | safe | implemented | `login-branding` |

Deferred (no definition yet — filtered from the UI): `site-identity-login`

#### White Label — `white-label`

Hide WordPress branding, rename admin footer, replace login logo, custom admin color scheme, and custom help tab.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `white-label` | White Label | pro | safe | implemented | — |
| `custom-admin-footer` | Custom Admin Footer | core | safe | implemented | — |

#### Admin Theme — `admin-theme`

System-aware dark mode, extended color schemes, environment indicator bar, and contextual body classes.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `dark-mode` | Dark Mode | core | safe | implemented | — |
| `admin-color-schemes` | Admin Color Schemes | core | safe | implemented | — |
| `environment-indicator` | Environment Indicator | core | safe | implemented | — |
| `admin-body-classes` | Admin Body Classes | core | safe | implemented | — |

### Developer (4 parents)

#### Code Snippets — `code-snippets` _[badges: popular]_

Run PHP, CSS, JS, or HTML snippets without editing theme files. Per-snippet toggle, conditional loading, injection into head/footer.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `code-snippets` | Code Snippets | core | advanced | implemented | — |

Deferred (no definition yet — filtered from the UI): `custom-admin-css`, `custom-frontend-css`, `custom-body-class`

#### Debug Tools — `debug-tools`

In-admin error log viewer, system summary, plugin profiler, and a hook inspector for any admin request.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `error-log-viewer` | Error Log Viewer | pro | safe | implemented | — |
| `system-summary` | System Summary | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `plugin-profiler`, `hook-inspector`

#### Site Utilities — `site-utilities`

Maintenance mode, 301/302 redirects, 404 monitor, broken link checker, SMTP, email log, front/back-end kill-switches, and selective update control.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `maintenance-mode` | Maintenance Mode | core | moderate | implemented | — |
| `disable-frontend` | Disable Frontend Features | core | moderate | stub | — |
| `disable-backend` | Disable Backend Features | core | advanced | stub | — |
| `redirect-manager` | Redirect Manager | core | moderate | implemented | — |
| `redirect-404` | Redirect 404 | core | moderate | implemented | — |
| `404-monitor` | 404 Monitor | core | safe | implemented | — |
| `broken-link-checker` | Broken Link Checker | core | safe | implemented | — |
| `email-delivery` | Email SMTP | core | safe | implemented | `email-smtp` |
| `email-log` | Email Log | pro | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `disable-updates`

#### Developer Tools — `developer-tools`

Safe search-replace, cron manager, webhooks, ads.txt and robots.txt editors, options/transient/rewrite-rules browsers.

| Module | Title | Tier | Risk | Status | Legacy alias |
|---|---|---|---|---|---|
| `search-replace` | Search & Replace | core | advanced | implemented | — |
| `cron-manager` | Cron Manager | core | safe | implemented | — |
| `export-import-settings` | Export / Import Settings | core | safe | implemented | — |

Deferred (no definition yet — filtered from the UI): `file-manager`, `webhook-manager`, `ads-txt-manager`, `robots-txt-manager`, `options-browser`, `transient-browser`, `rewrite-rules-viewer`, `capability-tester`

## Hidden Parent Cards (zero built sub-modules)

- **Page Builder Cleanup** — `page-builder-cleanup` — deferred: 
- **Custom Post Types** — `custom-post-types` — deferred: `custom-content-types`
- **WooCommerce Enhancements** — `woocommerce-enhancements` — deferred: `woo-admin-cleanup`, `woo-custom-statuses`, `woo-disable-reviews`, `woo-empty-cart-button`, `woo-login-redirect`

## Canonical Slug Registry (83)

### Core launch modules — implemented (70)

`404-monitor`, `active-plugins-first`, `activity-feed`, `admin-bar-manager`, `admin-body-classes`, `admin-bookmarks`, `admin-color-schemes`, `admin-columns`, `admin-menu-editor`, `admin-quick-notes`, `audit-log`, `auto-clear-caches`, `auto-publish-missed-schedule`, `broken-link-checker`, `bulk-content-editor`, `code-snippets`, `command-palette`, `content-duplication`, `content-order`, `cron-manager`, `custom-admin-footer`, `custom-nav-new-tab`, `dark-mode`, `dashboard-columns`, `database-optimizer`, `disable-comments`, `disable-gutenberg`, `duplicate-menu`, `duplicate-widget`, `email-delivery`, `email-obfuscator`, `environment-indicator`, `export-import-settings`, `external-links-new-tab`, `external-permalinks`, `heartbeat-control`, `hide-admin-notices`, `hide-dashboard-widgets`, `image-srcset-control`, `image-upload-control`, `keyboard-shortcuts`, `lazy-load`, `list-table-enhancements`, `login-designer`, `login-logout-menu`, `maintenance-mode`, `media-infinite-scroll`, `minify-assets`, `multiple-user-roles`, `notification-center`, `obfuscate-author-slugs`, `page-hierarchy-organizer`, `password-protection`, `post-type-switcher`, `preserve-taxonomy-hierarchy`, `public-preview`, `redirect-404`, `redirect-after-login`, `redirect-manager`, `revision-control`, `role-manager`, `search-replace`, `search-visibility-status`, `session-manager`, `setup-wizard`, `smart-menu-organizer`, `system-summary`, `taxonomy-filter`, `view-as-role`, `wider-admin-menu`

### Pro launch modules (7)

`client-dashboard`, `email-log`, `error-log-viewer`, `temporary-user-access`, `terms-order`, `two-factor-authentication`, `white-label`

### Core stubs — spec exists, implementation pending (6)

`disable-backend`, `disable-frontend`, `login-notifications`, `login-protection`, `media-library-pro`, `strong-passwords`

## Deferred / Future — NOT implemented (62)

Aspirational hierarchy ids with NO definition. Filtered from the UI at render time;
archived-roadmap material only — never treat these as live modules.

`admin-bar-enhancer`, `admin-columns-pro`, `admin-shortcut-cheatsheet`, `ads-txt-manager`, `autoloaded-options-audit`, `avif-upload`, `capability-tester`, `captcha-protection`, `change-login-url`, `content-calendar`, `custom-admin-css`, `custom-body-class`, `custom-content-types`, `custom-frontend-css`, `dashboard-welcome-panel`, `disable-attachment-pages`, `disable-author-archives`, `disable-embeds`, `disable-emojis`, `disable-feeds`, `disable-rest-api`, `disable-rest-fields`, `disable-self-pingbacks`, `disable-updates`, `disable-xmlrpc`, `file-manager`, `hide-admin-bar`, `honeypot-forms`, `hook-inspector`, `image-sizes-panel`, `last-login-column`, `limit-login-attempts`, `local-user-avatar`, `login-id-type`, `media-folders`, `media-replace`, `media-visibility-control`, `object-cache-status`, `options-browser`, `page-template-column`, `passkey-auth`, `per-role-ui-profiles`, `plugin-profiler`, `prefetch-on-hover`, `registration-date-column`, `rewrite-rules-viewer`, `robots-txt-manager`, `security-headers`, `site-identity-login`, `strong-password-policy`, `suspicious-activity-alerts`, `svg-upload`, `transient-browser`, `transient-cleanup`, `user-enumeration-block`, `webhook-manager`, `woo-admin-cleanup`, `woo-custom-statuses`, `woo-disable-reviews`, `woo-empty-cart-button`, `woo-login-redirect`, `workflow-automation`

## App Pages

| Page slug | Boundary capability | Backing module |
|---|---|---|
| `wpt-dashboard` | `edit_posts` | — (core surface) |
| `wptransformed` | `manage_wpt_modules` | — (core surface) |
| `wpt-database` | `manage_wpt_database` | `database-optimizer` |
| `wpt-audit-log` | `view_wpt_logs` | `audit-log` |
| `wpt-menu-editor` | `manage_wpt_settings` | `admin-menu-editor` |
| `wpt-login-designer` | `manage_wpt_settings` | `login-designer` |
| `wpt-setup-wizard` | `manage_wpt_settings` | `setup-wizard` |
| `wpt-temporary-access` | `manage_wpt_security` | `temporary-user-access` |
| `wpt-notifications` | `read` | `hide-admin-notices` |

## System Foundation Services (Phase 1 — not registry modules)

Specified in `docs/modules/system/`: module-registry, module-loader, settings-storage,
permission-model, recovery-center (Safe Mode), conflict-detector (not yet built),
module-library, dashboard-shell, import-export, admin-chrome-foundation,
editor-dashboard-shell. These are always-available services, not toggleable modules.

## Migration, Loading & Gating Notes

- **Legacy slug migration:** 10 module ids were renamed to canonical slugs (see the
  Legacy alias columns above). The one-time, idempotent migration is gated by the
  `wpt_slug_version` option and audited in `wpt_slug_migration_v1_backup`. Legacy ids
  remain resolvable aliases for old exports/bookmarks but are never written back.
- **Zero-load:** module implementation files are included only when a module is active,
  status `implemented`, tier-allowed, and not quarantined. Inactive modules contribute
  zero file includes, instances, hooks, or assets; all cards/search render from definitions.
- **Pro gating:** locks derive from definition tiers. Unlicensed Pro implementation files
  never load; locked cards render from definitions. A parent card is fully locked only
  when every built sub-module is Pro; mixed parents gate per sub-module.
- **History:** the archived 125/141-module docs under `docs/archive/` are roadmap material
  only and must not be used as implementation authority.
