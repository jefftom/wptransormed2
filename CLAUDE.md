# CLAUDE.md — WPTransformed

## What This Plugin Does
Modular WordPress admin enhancement plugin. Replaces 15+ plugins with one. Currently building v2 (141-module target across 28 parents, 7 categories). 85 modules currently shipped.

## Tech Stack (v1)
- PHP 7.4+ (strict types)
- Native WordPress admin UI (NO React, NO Vite, NO build step)
- MySQL/MariaDB with JSON columns
- Vanilla JS (no jQuery dependency)

## File Locations
```
wptransformed.php              → Bootstrap
includes/                      → Core classes (Core, Settings, Admin, Module_Base, Module_Registry, Safe_Mode)
modules/{category}/            → Module classes + their CSS/JS
assets/admin/css/admin.css     → Shared settings page styles
assets/admin/js/admin.js       → Shared settings page JS



```

## Naming Conventions
- Files: `class-{slug}.php` (e.g., `class-content-duplication.php`)
- Classes: `WPTransformed\Modules\{CategoryNamespace}\{Class_Name}`
- Module IDs: lowercase with hyphens (e.g., `content-duplication`)
- CSS/JS handles: `wpt-{module-slug}` (e.g., `wpt-dark-mode`)
- DB table: `{$wpdb->prefix}wpt_settings`
- Nonces: `wpt_save_{module_id}` for settings, `wpt_{action}_{id}` for custom actions
- User meta keys: `wpt_{feature}` (e.g., `wpt_dark_mode`)
- Hooks/filters: `wpt_{description}` (e.g., `wpt_registered_modules`)

## Security Rules (non-negotiable)
1. Every PHP file starts with: `if ( ! defined( 'ABSPATH' ) ) exit;`
2. Every custom action: verify nonce + check capability before anything else
3. Every settings save: goes through module's `sanitize_settings()` — sanitize every value
4. Every echo: `esc_html()`, `esc_attr()`, `esc_url()` as appropriate
5. Every DB query: `$wpdb->prepare()` with placeholders

## Module Pattern
Every module extends `Module_Base` and implements:
- `get_id()`, `get_title()`, `get_category()`, `get_description()` — identity
- `init()` — add hooks/filters here, NOT in constructor
- `get_default_settings()` — return defaults array
- `render_settings()` — echo HTML form fields (framework handles form wrapper + save)
- `sanitize_settings($raw)` — clean and return settings array
- `enqueue_admin_assets($hook)` — conditional asset loading
- `get_cleanup_tasks()` — declare persistent data for uninstall

Read settings with: `$settings = $this->get_settings();` (returns saved merged with defaults)

## Architecture Reference
For core framework code (loader, settings, admin page, base class), read:
`docs/architecture.md` — sections 1.1 through 1.12

## Module Specifications
Module build specs are archived in docs/archive/. Canonical template at docs/modules/_TEMPLATE.md; Menu Editor exemplar at docs/modules/admin-interface/menu-editor.md.

85 modules currently shipped. v2 target: 141 modules (see docs/module-hierarchy.md). v3 roadmap adds ~59 modules across 8 new categories — Observability, Automation, Network, Content Intelligence, SEO Toolkit, Forms Pro, Media Intelligence, Editorial Collaboration (see docs/v3-roadmap.md).

v2 expansion plan (approved, awaiting execution): `.claude/plans/fuzzy-singing-moth.md`. Adds 17 new gap-filler modules + foundational architecture (composite-PK schema migration, unified event store, job runner, REST layer, multisite adapter, settings history, Module_Base trait additions). Tracked in docs/IMPROVEMENTS.md §"v2 Architecture Upgrades". Stage 2 (foundation) begins after UI restructure Sessions 1–8 complete.

Current phase is UI restructure.

## Build Rules
1. Build ONE module at a time
2. Each module must pass ALL verification steps before starting the next
3. Verification = manually confirm on a real WordPress install, not just "tests pass"
4. If something doesn't work, fix it before moving on
5. When in doubt, follow the pattern of the last working module

## Review Protocol
After completing any module, switch to /fast and run adversarial
review for: WP Engine compat, SQL safety, hook conflicts, cache
safety, PHP compat, test quality, input validation, error handling.
Fix criticals before committing. Add "Reviewed-by: sonnet-4.6" trailer.

## Evolution Zones
Green (auto): new tools, DECISIONS.md entries, test patterns
Yellow (Sonnet gate): CLAUDE.md changes, architecture docs
Red (human only): business decisions, credentials, security

## Current Task
<!-- Update this line before each Claude Code session -->
Phase: UI RESTRUCTURE

Progress:
- Session 1 (Sidebar reskin + topbar + global styling) — complete (afa2aae + fixes; runtime-verified on wpt-dev)
- Session 2 (Editor Dashboard with real data) — complete (7365020, runtime-verified)
- Session 3 (Module Grid — 28-parent hierarchy with expandable sub-panels) — complete (7b25021 + e94dad9 + 46a5965 + c8b4558). Module_Hierarchy data layer is the canonical source of truth; render_parent_card() helper renders cards with expand/collapse; wpt_toggle_parent AJAX batch endpoint handles parent activation atomically. Runtime-verified end-to-end.
- Session 4 (Database Optimizer + Audit Log app pages) — complete (6a28a91 + b35d44e). includes/class-database-optimizer-app.php + includes/class-audit-log-app.php register submenu pages at wpt-database and wpt-audit-log. Full bento-stat UI + cleanup task list + filterable event table + server-side pagination. Clean button AJAX verified against real transients.
- Session 5 Part 1 (Login Designer app page) — complete (18a25d0 + 10fbdaf fix). includes/class-login-designer-app.php at wpt-login-designer. Split-pane with 4 tabs, 3 templates, 6 color pickers, range slider, live HTML preview of wp-login.php. Dedicated wpt_save_login_designer admin-post handler reuses Login_Customizer::sanitize_settings. Full save → DB → wp-login.php CSS chain verified.
- Session 5 Part 2 (Menu Editor app page) — complete (88508b0). includes/class-menu-editor-app.php at wpt-menu-editor. Three-panel drag-drop: 280px gradient sidebar preview + tree of WP menu items + properties form (label / icon picker / hide / separator). Wraps Admin_Menu_Editor's 5-field schema. HTML5 native drag-drop (no jQuery UI). Dedicated wpt_save_menu_editor handler. Save → DB → real WP admin sidebar updates verified live (Posts renamed to "Articles", Comments hidden). Per-role visibility + theme system + multi-config tabs are v3 work per docs/modules/admin-interface/menu-editor.md.
- Session 6 (Command palette + plugin detection) — partial (cd44f4e reconciled with v3 reference mockup; plugin detection wiring may still be incomplete)

Backup: `session-3-complete` git tag at 4a4bee9; DB dump at C:\dev\wpt-backups\wpt-dev-20260410-163612.sql (pre-Session-4 rollback point).

Target: Session 5 Part 3 — White Label app page
Scope: Dedicated APP page referenced by the Session 3 Design category White Label parent card (wpt-white-label slug). Simpler than Menu Editor — closer to Login Designer's split-pane layout. PRO-gated (Core::is_pro_licensed).

Layout: settings form on left + live preview sidebar on right. Wraps TWO modules:
- `white-label`: admin_logo_url, login_logo_url, admin_footer_text, hide_wp_version, custom_admin_title (5 fields)
- `custom-admin-footer`: left_text, right_text (2 fields)

Session 5 Part 3 should save to BOTH modules in one form POST because they have non-overlapping responsibilities.

Pre-flight recon already done — see docs/session-5-wrapped-module-fields.md for field names, sanitization pipelines, and the dedicated per-page verification checklist. Preventive lesson from Part 1 still applies: use wpt_ prefixed form names to match sanitize_settings().

Also fold in the one-line latent bug fix: White_Label::is_login_customizer_active() checks module id 'login-customizer' but registry uses 'login-branding'. Logged in IMPROVEMENTS.md 2026-04-11.

Reference files:
- assets/admin/reference/app-pages/white-label-v3.html

After Part 3: Session 5 is complete. Then Sessions 7 (activation wizard), 8 (QA/polish), and finishing Session 6 plugin detection.



## Design System

All admin UI styling is defined in assets/admin/reference/ subfolders
(dashboard/, components/, app-pages/). These are the finished design
mockups. CSS values are extracted verbatim — same hex, same px, same
curves, same everything.

Only read the reference files specified for your current session.
See docs/ui-restructure-spec.md Section 2 for the per-session file map.

Never modify colors, spacing, typography, border-radius, or
animations without explicit instruction. When in doubt, open the
reference HTML file and copy the CSS exactly.

admin-global.css reskins the NATIVE WordPress admin sidebar and
chrome. We STYLE, we don't REPLACE. No remove_menu_page().
Every third-party plugin's menu items must continue working.

Primary reference: dashboard/wp-transformation-final.html
Tooltip pattern: components/tooltip-reference.html (extract tooltip only)

## UI Restructure Spec

The full UI restructure spec is at docs/ui-restructure-spec.md.
It contains:
- Sidebar structure (style-not-replace approach)
- v2 module hierarchy (141 modules → 28 parents → 7 categories); canonical source is docs/module-hierarchy.md
- App page references (which HTML mockup maps to which page)
- Module grid layout and interaction spec
- Command palette spec
- Activation wizard and default module selections

Read this spec before making any admin UI changes.

v3 scope (Observability, Automation, Network, Content Intelligence, SEO Toolkit, Forms Pro, Media Intelligence, Editorial Collaboration) is held separately in docs/v3-roadmap.md and does not apply to the current UI restructure.
