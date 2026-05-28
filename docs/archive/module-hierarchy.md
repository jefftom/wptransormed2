# WPTransformed Module Hierarchy
## 141 modules → 28 parents → 7 categories (v2 target)

This file defines the parent/sub-module grouping for the Modules page.
Each parent module renders as a card with an expandable sub-module panel.

v2 module additions (13 gap-filler modules from the approved expansion plan) are folded into their parent categories below. New v3 categories (Observability, Automation, Network, Content Intelligence, SEO Toolkit, Forms Pro, Media Intelligence, Editorial Collaboration) are deferred to `v3-roadmap.md` and do not appear here.

---

## CORE (blue) — 6 parents · 32 modules

| # | Parent | Badges | Sub-modules |
|---|--------|--------|-------------|
| 1 | Admin Bar Manager | POPULAR | clean-admin-bar, admin-bar-enhancer, hide-admin-bar, command-palette, wider-admin-menu |
| 2 | Dashboard Manager | — | hide-dashboard-widgets, dashboard-columns, activity-feed, admin-quick-notes, client-dashboard, duplicate-widget, dashboard-welcome-panel |
| 3 | Notifications & Notices | — | hide-admin-notices, notification-center |
| 4 | List Tables & Columns | NEW | enhance-list-tables, admin-columns-enhancer, admin-columns-pro, page-template-column, registration-date-column, search-visibility-status, taxonomy-filter, last-login-column, active-plugins-first |
| 5 | Keyboard Shortcuts & Bookmarks | — | keyboard-shortcuts, admin-bookmarks, admin-shortcut-cheatsheet |
| 6 | User & Role Manager | — | multiple-user-roles, user-role-editor, session-manager, temporary-user-access, view-as-role, per-role-ui-profiles |

## CONTENT (violet) — 6 parents · 29 modules

| # | Parent | Badges | Sub-modules |
|---|--------|--------|-------------|
| 7 | Content Tools | POPULAR | content-duplication, content-order, terms-order, bulk-edit-posts, post-type-switcher, public-preview, external-links-new-tab, external-permalinks, disable-comments, form-builder |
| 8 | Content Scheduling & Workflow | — | content-calendar, auto-publish-missed, workflow-automation |
| 9 | Editor Enhancements | — | disable-gutenberg, page-hierarchy-organizer, preserve-taxonomy-hierarchy |
| 10 | Media Library Pro | POPULAR | media-folders, media-replace, svg-upload, avif-upload, image-sizes-panel, media-visibility-control, media-infinite-scroll, local-user-avatar |
| 11 | Navigation & Menus | POPULAR · APP | admin-menu-editor, smart-menu-organizer, custom-nav-new-tab, duplicate-menu |
| 12 | Page Builder Cleanup | NEW | (dynamic per detected builder) |

## SECURITY (rose) — 4 parents · 20 modules

| # | Parent | Badges | Sub-modules |
|---|--------|--------|-------------|
| 13 | Firewall & Hardening | POPULAR | disable-xmlrpc, password-protection, disable-rest-api, disable-rest-fields, email-obfuscator, obfuscate-author-slugs, security-headers, honeypot-forms, suspicious-activity-alerts, user-enumeration-block |
| 14 | Login Protection | APP | limit-login-attempts, captcha-protection, change-login-url, login-id-type, login-logout-menu, redirect-after-login, strong-password-policy |
| 15 | Two-Factor Auth | PRO | two-factor-auth, passkey-auth |
| 16 | Audit Log | APP | audit-log |

## PERFORMANCE (green) — 4 parents · 18 modules

| # | Parent | Badges | Sub-modules |
|---|--------|--------|-------------|
| 17 | Asset Optimizer | POPULAR · NEW | minify-assets, disable-embeds, disable-emojis, auto-clear-caches |
| 18 | Image Optimizer | NEW | image-upload-control, image-srcset-control, lazy-load |
| 19 | Site Speed | — | heartbeat-control, disable-self-pingbacks, disable-attachment-pages, disable-author-archives, disable-feeds, revision-control, object-cache-status, prefetch-on-hover |
| 20 | Database Optimizer | APP | database-cleanup, autoloaded-options-audit, transient-cleanup |

## DESIGN (sky/teal) — 3 parents · 8 modules

| # | Parent | Badges | Sub-modules |
|---|--------|--------|-------------|
| 21 | Login Designer | POPULAR · APP | login-customizer, site-identity-login |
| 22 | White Label | PRO · APP | white-label, custom-admin-footer |
| 23 | Admin Theme | — | dark-mode, admin-color-schemes, environment-indicator, admin-body-classes |

## DEVELOPER (amber) — 5 parents · 29 modules

| # | Parent | Badges | Sub-modules |
|---|--------|--------|-------------|
| 24 | Code Snippets | POPULAR | code-snippets, custom-admin-css, custom-frontend-code, custom-frontend-css, custom-body-class |
| 25 | Debug Tools | PRO | plugin-profiler, error-log-viewer, system-summary, hook-inspector |
| 26 | Custom Post Types | PRO | custom-content-types |
| 27 | Site Utilities | — | maintenance-mode, redirect-manager, redirect-404, 404-monitor, broken-link-checker, email-smtp, email-log, disable-updates |
| 28 | Developer Tools | — | search-replace, cron-manager, file-manager, webhook-manager, ads-txt-manager, robots-txt-manager, export-import-settings, options-browser, transient-browser, rewrite-rules-viewer, capability-tester |

## ECOMMERCE (detected only) — 1 parent · 5 modules

| # | Parent | Badges | Sub-modules |
|---|--------|--------|-------------|
| 29 | WooCommerce Enhancements | — | woo-admin-cleanup, woo-custom-statuses, woo-disable-reviews, woo-empty-cart-button, woo-login-redirect |

## SYSTEM (internal, not shown)

- setup-wizard

---

## v3 Roadmap

The v2 target is 141 modules across 28 parents (this file). The v3 roadmap adds ~9 new parent modules and ~59 new sub-modules across 8 new category slots: **Observability**, **Automation**, **Network**, **Content Intelligence**, **SEO Toolkit**, **Forms Pro**, **Media Intelligence**, **Editorial Collaboration**. See `docs/v3-roadmap.md` for the full story.

Grand total after v3 ships: ~200 sub-modules across ~37 parents in 15 categories, 14 app pages.

v3 work does not begin until v2 ships and the v2 foundational architecture (event store, job runner, REST layer, settings history, multisite adapter) is live in production. See `docs/IMPROVEMENTS.md` §"v2 Architecture Upgrades" for the prerequisite list.
