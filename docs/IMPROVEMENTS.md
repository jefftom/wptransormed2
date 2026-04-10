# Improvements

Architectural debt and module-level issues that need future attention.

---

## v2 Architecture Upgrades (foundational)

These are the v2 foundational changes from the approved expansion plan at `.claude/plans/fuzzy-singing-moth.md`. They don't ship as user-facing features but unblock every v3 category. Stage 2 work — begins after UI restructure Sessions 1–8 complete.

| # | Area | Change | Why | Priority |
|---|------|--------|-----|----------|
| 1 | Schema | Add `site_id BIGINT UNSIGNED NOT NULL DEFAULT 0` to `wp_wpt_settings`. Change PK to composite `(site_id, module_id)`. Existing rows get `site_id=0` (network default). | First-class multisite support. Agencies are the primary Pro buyer. | P0 |
| 2 | Schema | Add `settings_version INT`, `schema_version VARCHAR(16)`, `inherit_network TINYINT(1)` to `wp_wpt_settings`. Additive migration via new `class-schema.php`. | Settings versioning + per-module schema migrations + per-site override toggle. | P0 |
| 3 | Schema | New table `wp_wpt_settings_history` with `(site_id, module_id, version, settings JSON, changed_by, created_at)`. | Enables settings rollback, diff view, audit trail of config changes. | P0 |
| 4 | Schema | New table `wp_wpt_events` with `id, site_id, type, severity, source_module, actor_user_id, subject, correlation_id, payload JSON, created_at DATETIME(3)`. Indexes `(site_id, type, created_at)`, `(site_id, severity, created_at)`, `(actor_user_id, created_at)`, `(correlation_id)`. | Unified event store — foundation for observability, alerting, automation, collaboration. Audit Log / 404 Monitor / Email Log / Error Log Viewer all migrate to be consumers of this table. | P0 |
| 5 | Schema | New table `wp_wpt_jobs` with `id, site_id, queue, handler, payload JSON, attempts, run_after, locked_until, status`. | Durable queue for deferrable work (CDN uploads, cleanup scans, broken link crawl, undo window executions, webhook dispatches). | P0 |
| 6 | Schema | New table `wp_wpt_module_meta` with `(site_id, module_id, meta_key, meta_value LONGTEXT, updated_at)`. Unique `(site_id, module_id, meta_key)`. | Sidecar k/v for modules that need secondary data without widening settings JSON. | P1 |
| 7 | Core | New `includes/class-schema.php::migrate(int $target)`. Idempotent, forward-only, wrapped in try/catch. On fatal failure, auto-engage Safe Mode. Tracks `wpt_db_version` option. | Safe schema evolution path for 85 existing modules + all future migrations. | P0 |
| 8 | Core | New `includes/events/` package: `class-event.php` (value object), `class-event-bus.php` (pub/sub wrapper over `do_action`), `class-event-store.php` (insert/query/stream, with rate limiting per `(type, site_id)` per minute to prevent DoS via 404 floods), `class-event-query.php` (fluent query builder). | Event store producers and consumers. | P0 |
| 9 | Core | New `includes/class-job-runner.php`. `wpt_job_tick` WP-Cron hook + opportunistic admin-request runs. Exponential backoff, dead-letter, retention. | Background work infrastructure. | P0 |
| 10 | Core | New `includes/class-multisite-adapter.php`. Encapsulates all `is_multisite()` / `switch_to_blog()` branching. `get_effective_site_id()`, `fan_out(callable, $site_ids)`, `get_network_default(string $module_id)`, `get_all_site_ids()`. Used internally by Settings; existing modules never call directly. | Multisite support is free at the settings layer without touching module code. | P0 |
| 11 | Core | New `includes/class-hosting-profile.php`. Detects WP Engine, Kinsta, Cloudways, Pressable, SpinupWP, SiteGround via env vars + file probes. `get_profile(): string`, `get_tuning_hints(): array`. Modules consult in `get_default_settings()` for host-aware defaults. | Out-of-box tuning per hosting environment. | P1 |
| 12 | Core | `Core::boot()` gains topological sort for module init order based on `get_dependencies()`. Modules currently don't care about order; stable sort (preserve input order when no dep relationship). Debug-mode logging for one release before enforcing. | Correct init ordering for modules that will declare dependencies. | P1 |
| 13 | Core | `Core::boot()` switches to lazy instantiation — register-only on boot, instantiate-on-activate. Target 30–50ms reduction in admin request boot time. | Performance. Currently every module's class is constructed even if inactive. | P1 |
| 14 | Module_Base | Add 9 optional methods (all non-breaking, default-implemented): `get_schema_version()`, `upgrade_settings()`, `get_subscribed_events()`, `get_conflicts()`, `get_rest_routes()`, `get_capabilities()`, `get_multisite_scope()`, `get_health_checks()`, `get_cli_commands()`. | Extension surface for event subscribers, REST routes, conflicts, health checks, CLI commands. Zero breakage for existing 85 modules. | P0 |
| 15 | Module_Base | New traits: `With_Event_Emitter` (`$this->emit()` auto-stamps source_module), `With_REST_Routes` (boilerplate collapser), `With_Async_Jobs` (`$this->enqueue(handler, payload, delay)`). Opt-in via `use` in module classes. | Reduce boilerplate for modules adopting new infrastructure. | P1 |
| 16 | Settings | `Settings::get()` gains object cache layer (`wp_cache_get` before DB). Cache key `wpt:settings:{site_id}:{module_id}`. Invalidate on save. No API change. | Performance — currently single query per boot, but hitting DB on every page load. Works with Redis/Memcached, silent no-op otherwise. | P0 |
| 17 | Settings | `Settings::save()` writes row to `wp_wpt_settings_history` + emits `settings.changed` event. New methods: `Settings::get_history()`, `Settings::rollback(version)`, `Settings::get_version()`, `Settings::broadcast(module_id, settings, site_ids)`. | Enables per-module rollback, diff view, multisite fan-out. | P0 |
| 18 | REST | New `includes/rest/` package. `class-rest-server.php` registers `wpt/v1` namespace on `rest_api_init`. Controllers: `Modules_Controller`, `Settings_Controller`, `Events_Controller`, `Health_Controller`. Auto-register per-module routes from `get_rest_routes()` with try/catch isolation. Auth: cookie+nonce for admin UI, application passwords for external, per-route capability check. | Every module's settings addressable via REST. SSE event stream in v3. | P0 |
| 19 | Module Registry | Add `register_external(string $id, string $file, array $meta)` for runtime registration of add-on packs. `get_dependency_graph()` returns DAG. `validate_conflicts()` returns activation errors before `init()`. `get_categories()` becomes filterable so new categories (Observability, Automation, Network, Content Intelligence, SEO Toolkit, Forms Pro, Media Intelligence, Collab) don't require class edits. | Extensibility for third-party packs and new v3 categories. | P1 |
| 20 | Assets | Split `assets/admin/css/admin.css` into `admin-core.css` (global) + `admin-{app-page}.css` (lazy per page). Target <50KB per-page delta over core. Font subset Outfit + JetBrains Mono to Latin+numerals (~12KB each target). SVG sprite for all icons. | Performance — current shared CSS is ~200KB+, fonts ~80KB each unsubsetted. | P1 |
| 21 | Assets | New `admin-skeletons.css`, `admin-toasts.css`, `admin-tour.js`, `admin-shortcuts.js`. Skeleton loaders replace all spinners. Toast system with 10s undo window queued via job runner. Tour component for per-app-page first-run tours. `?` key opens keyboard cheat sheet modal. | Cross-cutting UX consistency. | P1 |
| 22 | Assets | `prefers-reduced-motion` respected across all animations. Visible focus rings on every interactive element. High-contrast mode toggle (distinct from dark mode, AAA contrast). | Accessibility. WCAG 2.1 AA baseline. | P1 |
| 23 | Audit Log | Migrate from dedicated table to consumer of `wp_wpt_events` (filtered view). Prove the pattern. Keyset pagination (`WHERE id < cursor LIMIT 50`), not OFFSET. Handles 1M+ event tables. | First consumer of unified event store. | P0 |
| 24 | 404 Monitor, Email Log, Error Log Viewer | Migrate to emit events into `wp_wpt_events`. Their own tables deprecated with migration path. | Complete the event store unification. | P1 |

---

## v2 New Modules (non-foundational, ship in Stage 4)

From the approved plan §3.2 — gap-filler modules beyond the existing 125-module scope.

| # | Category | Module | Why |
|---|----------|--------|-----|
| 1 | Security | `security-headers` | HTTP security headers (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS). Fixes a gaping WP default. |
| 2 | Security | `honeypot-forms` | Honeypot field injection into all comment/login/registration forms. Defeats 90% of bots without CAPTCHA. |
| 3 | Security | `suspicious-activity-alerts` | Rule-based alerts (N failed logins/minute, new admin, plugin uploaded, core file modified). No ML. |
| 4 | Security | `strong-password-policy` | Per-role minimum complexity enforcement on password change. |
| 5 | Security | `user-enumeration-block` | Hardens `?author=N`, REST `/wp/v2/users`, author sitemaps. Complements `obfuscate-author-slugs`. |
| 6 | Performance | `autoloaded-options-audit` | Find and prune bloated autoloaded options. Huge real-world impact. |
| 7 | Performance | `transient-cleanup` | Automatic transient garbage collection. Scheduled via job runner. |
| 8 | Performance | `object-cache-status` | Detect Redis/Memcached, surface stats, warn on misconfig. |
| 9 | Performance | `prefetch-on-hover` | `<link rel="prefetch">` injection on admin menu hover for instant nav. |
| 10 | Developer | `hook-inspector` | Instrument `do_action`/`apply_filters` on current request, show timeline + timing. |
| 11 | Developer | `options-browser` | Search/edit `wp_options` with safety warnings on autoloaded rows. |
| 12 | Developer | `transient-browser` | View/clear transients. |
| 13 | Developer | `rewrite-rules-viewer` | Display active rewrite rules, test URL against them. |
| 14 | Developer | `capability-tester` | Matrix of roles × capabilities. Test current user against a URL. |
| 15 | Admin Interface | `dashboard-welcome-panel` | Replacement for hidden WP welcome panel, per-role, editable. |
| 16 | Admin Interface | `admin-shortcut-cheatsheet` | `?` key opens searchable modal of every keyboard shortcut. |
| 17 | Admin Interface | `per-role-ui-profiles` | Profile layer above `hide-dashboard-widgets`, `clean-admin-bar`, etc. Orchestrate per-role hiding of menu items, widgets, meta boxes, admin bar items. Adminimize's killer feature. |

---

## Cross-cutting UX Pass (v2 polish, before release)

From the approved plan §3.4.

- Skeleton loaders replace all spinners (new `.wpt-skeleton` CSS class in `admin-global.css`)
- Toast system with 10s undo window for destructive actions (new `.wpt-toast` component, queued via job runner)
- Optimistic UI everywhere (extend beyond module toggles to all settings forms)
- Inline validation with actionable error messages (no generic "Save failed")
- Empty states with illustrations + guidance on every list/table
- First-run tours per app page (dismissable, persistent per user)
- `?` keyboard cheat sheet modal on every admin page
- `prefers-reduced-motion` gate on all animations
- High-contrast accessibility mode (distinct from dark mode)
- Focus ring discipline — visible on every interactive element

## Cross-cutting Performance Pass (v2 polish, before release)

From the approved plan §3.5.

- Lazy module instantiation in `Core::boot()` (target 40% boot time reduction on 200-module install)
- Object cache layer on `Settings::get()` (target 80% p95 reduction)
- CSS split: `admin-core.css` + `admin-{app-page}.css`
- Font subset Outfit + JetBrains Mono (target <30KB combined)
- SVG sprite for icons
- Batched async writes via job runner for audit log / event store on high-volume sites
- Keyset pagination on audit log / event store
- Composite indexes validated against EXPLAIN before shipping

---

## Module-level issues

Per-module bugs and improvements discovered during module building.

| Date | Module | Issue | Status |
|------|--------|-------|--------|
| 2026-04-10 | 404-monitor | `Core::resolve_class_name()` produced invalid PHP class name `404_Monitor` from `class-404-monitor.php` (PHP classes cannot start with a digit). File actually declared `class Four_Oh_Four_Monitor` but the resolver couldn't reach it. Result: `WPTransformed: Class not found` error on every admin request. Discovered during first runtime verification on local WP install (Laragon/wpt-dev). Fixed by renaming file to `class-four-oh-four-monitor.php` and updating the registry path. Module ID stays `404-monitor`. Related resolver weakness: no validation that resolved class name is a valid PHP identifier — silently produces broken names for any future file starting with a digit. | Fixed |
| 2026-04-10 | admin (sidebar) | `Admin::inject_section_labels()` injected all 5 section labels (CONTENT, SECURITY, DESIGN, TOOLS, CONFIGURE) unconditionally at fixed menu positions. WP core's "prevent adjacent separators" cleanup pass in `wp-admin/includes/menu.php` line 343 silently unsets any separator that immediately follows another separator. On a fresh install with no WPT security modules active, the SECURITY label at position 41 was adjacent to the DESIGN label at 59 (nothing in the 42–58 range), so WP removed DESIGN. Result: Appearance fell under the SECURITY header instead of DESIGN, and DESIGN was entirely absent from the rendered sidebar. Discovered during runtime verification on Laragon/wpt-dev. Diagnosed with a temporary mu-plugin logging `$menu[59]` at admin_menu priorities 998, 1000, and admin_head — confirmed the label was written correctly at 1000 and unset before admin_head. **Fixed** by rewriting `inject_section_labels()` to check each section's content range for a real (non-separator) menu item via a new `menu_range_has_items()` helper, and skipping injection of empty sections. Also unsets WP's `separator2` at position 59 explicitly so nothing collides with the DESIGN label. The adaptive behavior is correct: SECURITY appears only when security modules register menu items in positions 42–58; the sidebar stays clean otherwise. | Fixed |
| 2026-04-10 | setup-wizard | `add_submenu_page()` was called with `null` as the parent slug to register a hidden admin page. WP 6.7+ deprecated this pattern: passing null causes `plugin_basename(null)` → `wp_normalize_path(null)` → `wp_is_stream(null)` → `strpos(null, '://')` and `str_replace('\\', '/', null)`, both producing PHP deprecation notices on every admin request. Discovered during the all-modules activation sweep on Laragon/wpt-dev. Diagnosed with a temporary mu-plugin set_error_handler that captured `debug_backtrace()` for the strpos/str_replace deprecations. **Fixed** by changing the parent slug from `null` to `''` (empty string), which is the WP 6.7+ safe pattern for hidden admin pages — keeps the page accessible by URL but invisible from any submenu. Audit confirmed setup-wizard was the only module using `add_submenu_page(null, ...)`. | Fixed |
| 2026-04-10 | environment-indicator, search-visibility-status | Both modules called `is_admin_bar_showing()` inside `init()` as an early performance gate: `if ( ! is_admin() && ! is_admin_bar_showing() ) return;`. `is_admin_bar_showing()` transitively calls `is_embed()`, which is a conditional query tag that must not be called before WP's main query runs. Since `init()` runs on `plugins_loaded` (well before `WP::parse_request()`), this triggered "Function is_embed was called incorrectly" notices on every front-end request. Discovered during the all-modules activation sweep when hitting the front-end home page. Diagnosed with a `doing_it_wrong_run` action handler that captured backtraces — pinpointed to `class-environment-indicator.php:70` and `class-search-visibility-status.php:49`. **Fixed** by removing the early-return gate from both modules' `init()` methods. The hooks they register (`admin_bar_menu`, `admin_notices`, `admin_head`, `wp_head`) all fire after the query runs, so any runtime gating can happen safely inside the callbacks. Audit of the remaining 4 `is_admin_bar_showing()` call sites in dark-mode, command-palette, and white-label confirmed they're all in hook callbacks (frontend_admin_bar_styles, enqueue_frontend_toggle, render_palette_html_frontend, enqueue_frontend_assets, admin_logo_css), not in `init()` — safe. | Fixed |
| 2026-04-10 | smart-menu-organizer + admin (sidebar) | Smart Menu Organizer (PHP `organize_admin_menu` at priority 99999 + JS label injection) created its own set of section headers (Content / Build / Manage / Other) and reordered WP menu items into them, while `Admin::inject_section_labels()` (PHP at priority 999) created a different set of canonical section separators (CONTENT / SECURITY / DESIGN / TOOLS / CONFIGURE). When both ran together with all 85 modules active, the sidebar showed duplicate "CONTENT" headers, Dashboard ended up under the wrong section, WPTransformed landed under DESIGN instead of in Smart Menu Organizer's "Other" catch-all, and the menu hierarchy was visually muddled. Per `docs/ui-restructure-spec.md` Section 3, Smart Menu Organizer is supposed to own section organization completely and align on the canonical group labels. **Fixed** via three coordinated changes: (1) `Smart_Menu_Organizer::get_default_sections()` rewritten to return the canonical 5 sections (content, security, design, tools, configure) matching the spec, with proper WP core item slotting (Dashboard/Posts/Media/Pages/Comments → Content, Appearance/Menus/Widgets → Design, Tools → Tools, Settings/Plugins/Users → Configure, empty Security → populates when security modules register menu items); (2) Smart Menu Organizer's `organize_admin_menu()` unassigned-items loop now also skips items whose slug starts with `wpt-sep-` as a defensive measure against Admin's separators leaking into "Other"; (3) `Admin::inject_section_labels()` now defers entirely (early return) when `smart-menu-organizer` is active, making Smart Menu Organizer the single source of truth for section organization when on. Runtime-verified on Laragon/wpt-dev in both paths: Path A (SMO active) shows 5 canonical sections with correct item slotting and zero duplicates; Path B (SMO inactive) shows the adaptive Admin fallback with CONTENT/DESIGN/TOOLS/CONFIGURE (SECURITY hidden correctly because it has no items). Zero errors in debug.log in either path. | Fixed |
| 2026-04-10 | wptransformed (text domain) | **DEFERRED** — On the very first admin request after bulk-activating all 85 modules, WP 6.7+ logged "Function `_load_textdomain_just_in_time` was called incorrectly. Translation loading for the `wptransformed` domain was triggered too early." The warning fires once during the activation flow but does not reproduce on regular page loads after that. Likely cause: a module calls `__()` / `_e()` / similar in its `__construct()` or before the `init` action — typical culprits are translatable strings used in property defaults or constants. Catching it precisely requires setting up a `doing_it_wrong_run` handler, deactivating all modules, and reactivating them one at a time to bisect. Notice-level only, not breaking. Worth fixing during a polish pass but not blocking. | Open |
| 2026-04-10 | Settings + module lifecycle | **DEFERRED architectural risk** — `Settings::toggle_module(string $module_id, bool $active)` is the static API for activating/deactivating modules. It only flips the `is_active` row in `wp_wpt_settings`. The full lifecycle — calling `$module->deactivate()` for cleanup of cron events, transients, custom tables, etc. — is implemented inside `Admin::ajax_toggle_module()` (the WP-AJAX handler the UI uses). Any code that calls `Settings::toggle_module()` directly (CLI scripts, REST endpoints, programmatic toggles) silently bypasses lifecycle cleanup. Discovered when a CLI eval that bulk-deactivated all 85 modules left 5 orphaned `wpt_*` cron events behind (`wpt_blc_check_urls`, `wpt_404_monitor_prune`, `wpt_prune_audit_log`, `wpt_prune_404_log`, `wpt_blc_scan_links`). Production AJAX path is fine. **Fix direction**: introduce `Core::toggle_module(string $id, bool $active)` that wraps `Settings::toggle_module()` + lifecycle (`activate()` / `deactivate()`) and refactor `Admin::ajax_toggle_module()` to call it. Settings stays a thin DB layer. All future callers (CLI commands, REST endpoints, batch tools) get correct lifecycle handling for free. | Open |
