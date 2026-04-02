# CLAUDE.md — WPTransformed

## What This Plugin Does
Modular WordPress admin enhancement plugin. Replaces 15+ plugins with one. Currently building v1 with 10 modules.

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
For individual module behavior, hooks, settings, and verification steps, read:
`docs/module-specs.md` — one section per module

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
Phase: MODULE BUILDING
Task: Build remaining 3 modules (2, 7, 10) using TDD with cross-model review
Reference: docs/module-specs.md
