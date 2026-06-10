# Phase 1 Gap Analysis — System Specs vs. Existing Code

## Date

2026-06-09

## Method

Static spec-vs-code comparison on branch `v5-foundation-alignment` (after `ed2f86a`, product docs in place). Each of the 11 specs in `docs/modules/system/` was compared requirement-by-requirement against the implementation the existing-codebase audit mapped to it. No runtime verification was performed; file:line references for the two headline bugs were independently re-verified, the rest are from focused per-module review.

This document is the build list for Phase 1. The existing-codebase audit (`docs/audits/existing-codebase-audit.md`) answered "what code exists and is it safe to keep" — this answers "what does each spec still require."

## Executive Summary

| Spec | Verdict | Verification outlook (static) |
|---|---|---|
| module-registry | PARTIAL | ~4 of 10 criteria would pass |
| module-loader | PARTIAL | ~8 of 12 would pass |
| settings-storage | PARTIAL | ~8 of 11 would pass |
| permission-model | **MISSING** | 2 of 9 (both vacuously) |
| recovery-center | PARTIAL (~15–20% built) | 3 of 11 would pass |
| conflict-detector | **MISSING** | 1 of 9 (vacuously) |
| module-library | PARTIAL | ~5 of 11 would pass |
| dashboard-shell | PARTIAL | ~4 of 10 would pass |
| import-export | PARTIAL (**import is shipping-broken**) | ~4 of 11 would pass |
| admin-chrome-foundation | PARTIAL | ~11 of 18 would pass |
| editor-dashboard-shell | PARTIAL | ~9 of 14 would pass |

The codebase is a strong partial implementation: the storage core, loader skeleton, chrome reskin, dashboards, and module grid all exist and are security-hygienic (nonces, capability checks, escaping, prepared statements throughout). What's missing is concentrated in a handful of **systemic** gaps that repeat across every spec, plus two entirely unbuilt modules.

## Cross-Cutting Gaps (the real Phase 1 build items)

These appear in nearly every per-module report. Fixing them per-module would be wasted motion — they are shared infrastructure.

### 1. No permission model (blocks everything)

Zero `wpt_*` capabilities exist. 134 `current_user_can()` call sites repo-wide; 88 check `manage_options`. No grant/revoke lifecycle on activation/uninstall, no `WPT_Permission_Manager`, no `wpt_user_can_manage_module` filter. Every spec's permission requirement (`manage_wpt`, `manage_wpt_modules`, `manage_wpt_settings`, `run_wpt_dangerous_tools`, …) fails until this lands.

Hook-in points identified: grant in the activation hook at `wptransformed.php:61-65` plus a `wpt_db_version`-gated migration on `plugins_loaded` for existing installs; revoke in `uninstall.php` (conditional on a full-cleanup choice that also doesn't exist yet); central checks in a new `includes/class-permission-manager.php` (`WPTransformed\Core\Permission_Manager` — autoloader already maps `Core\*`).

### 2. No REST API layer at all

Zero `register_rest_route` calls in the plugin. Every spec's §8 (the `wpt/v1` namespace) is unbuilt; all server I/O is admin-ajax + admin-post (which IS consistently nonce- and capability-checked). **Architecture decision needed:** build a thin `wpt/v1` REST layer once, or amend all 11 specs to accept admin-ajax for Phase 1. Do not decide per-module.

### 3. No lifecycle hooks

Zero `do_action( 'wpt_*' )` in the repo; only 3 `wpt_*` filters exist (`wpt_registered_modules`, `wpt_command_palette_actions`, `wpt_known_plugin_sections`). The spec'd actions (`wpt_module_enabled/disabled/quarantined`, `wpt_module_settings_saved`, `wpt_safe_mode_triggered`, `wpt_settings_exported/imported`, …) are missing — which means Recovery Center, Conflict Detector, and Audit Log integration have nothing to subscribe to.

### 4. Safe Mode does not bypass the admin chrome (safety-contract violation)

Found independently by three reviews. `new Admin()` runs unconditionally even in Safe Mode (`wptransformed.php:89-93`), so `admin-global.css`/`admin-global.js`, fonts, Font Awesome, topbar injection, and section labels all still load (`class-admin.php:104-108`). If the chrome itself breaks the admin, Safe Mode cannot recover it — the exact scenario Safe Mode exists for. Smallest high-value fix in the list: gate the chrome hooks on `! Safe_Mode::is_active()`.

Related: during a Safe Mode request the modules page renders an empty/zero-state grid (Core never boots, `class-admin.php:457-462`) while the Safe Mode banner tells the admin to go there and disable modules — the recovery loop is broken. The page should read state directly from Settings Storage in Safe Mode.

### 5. Registry carries no metadata (structural; registry + loader share this)

`Module_Registry` is a pure `id => file-path` map (`class-module-registry.php:22-125`). Title/description/category/tier/dependencies are only obtainable by **including and instantiating every module class** — which is why `Core::boot()` `require_once`s and instantiates all ~83 modules on every request (`class-core.php:38-43, 71, 85`), violating the zero-load rule, loading Pro module files in Core-only installs, and making "show locked Pro card without loading implementation" impossible. There is no risk metadata, no `default_enabled`, no `search_terms`, no validation of registry entries (including filter-injected ones — relative-path traversal is possible via `class-core.php:60`), no tier enum beyond `free|pro`.

This is the largest structural change: move to definition objects (or a sidecar metadata array) so cards/search/REST can render without loading module code, and only active modules' files are included.

### 6. No crash quarantine, and Safe Mode is undiscoverable

No shutdown-handler fatal detection, no quarantine state, no rollback-on-failed-enable, no admin notices on module load failure (errors are `error_log`'d only when `WP_DEBUG`). A module that fatals uncatchably takes down wp-admin and is retried every request. Meanwhile the Safe Mode token URL is never surfaced anywhere — `get_safe_mode_url()` has zero callers, the activation-time token is discarded, no recovery email exists; an admin can only get the token by reading the database. The transient-based recovery pattern in `class-code-snippets.php:161-200` is a good template to generalize.

### 7. Global CSS scoping is defeated by design

`admin-global.css` styles `.button`, inputs, `.notice`, `.postbox`, `.wp-list-table`, `.wrap` — exactly the contract's avoid-list — "scoped" under `body.wpt-admin`, but that class is added to **every** admin page (`class-admin.php:893-894`), so the scoping is a no-op and these rules hit third-party plugin pages. Likewise all native `#wpadminbar` quicklinks (including third-party admin-bar items and WP's mobile menu toggle) are sr-only-hidden (`admin-global.css:683-691`). Consequence: **mobile admin is likely unusable** — the off-canvas sidebar's only opener is the hidden toggle, and no replacement is injected.

## Confirmed Live Bugs (independent of spec compliance)

> Status update 2026-06-09: bugs #1, #4, #6, #7, #8, #9 fixed in `5412fb7` (build-order step 1). #2 deferred to the import-export rebuild (step 7), #3 to the `wpt_theme_mode` migration (decision 6, step 8), #5 to chrome/palette remediation (step 8), #10 resolves with the registry rework (step 3).

| # | Bug | Location | Impact |
|---|---|---|---|
| 1 | Settings import fatals: calls `Core::get_instance()`, but Core only defines `instance()` | `modules/utilities/class-export-import-settings.php:180` vs `includes/class-core.php:23` | Every import 500s before writing anything (verified) |
| 2 | Even with #1 fixed, import corrupts: every module's `sanitize_settings()` expects `$_POST`-shaped `wpt_*` form keys, but the importer feeds canonical settings arrays → known modules reset to defaults; unknown module IDs are written raw, unsanitized | `class-export-import-settings.php:165-204` | Import round-trip is lossy + Section 4 violation |
| 3 | Dark-mode meta collision: chrome writes `wpt_dark_mode` = `'1'/'0'`; dark-mode module writes/reads `'dark'/'light'` on the same key | `includes/class-admin.php:897,922` vs `modules/admin-interface/class-dark-mode.php:125-226,710` | Each system misreads the other's saved preference (verified) |
| 4 | Dead app-page links: `wpt-login-protection` and `wpt-white-label` slugs never registered; APP-type parents also suppress their sub-module panels, so login-protection's 6 built modules can't be toggled from the grid | `class-module-hierarchy.php:371,516` | Cards link to nonexistent pages |
| 5 | Sidebar search trigger is dangling: dispatches `wpt-open-palette` CustomEvent with no listener; chrome looks for `#wptCmdOverlay`, palette module renders `#wpt-command-palette`; both bind Ctrl+K independently | `admin-global.js:99,225` | Search button does nothing on non-WPT pages |
| 6 | Active-modules bento count recounts only DOM checkboxes after a toggle; APP-parent cards render no checkboxes, so their modules drop from the count | `assets/admin/js/admin.js:279-288` | Count goes wrong after first interaction |
| 7 | Latent cache hazard: `Settings::save()`/`toggle_module()` read `self::$cache` without ensuring `load()` ran; if ever invoked before a read primes the cache, `REPLACE INTO` wipes settings/active state | `includes/class-settings.php:77,108` | Currently shielded only by boot ordering |
| 8 | Activation→wizard redirect chain dead: `wpt_activation_redirect` transient is read but never set; wizard completion links to `admin_url()` instead of the editor dashboard | `class-setup-wizard.php:102,160` | "Holy shit moment" flow doesn't happen |
| 9 | `ajax_save_dark_mode` checks nonce but no capability (writes only own user meta — low risk) | `includes/class-admin.php:918-925` | Spec §13 hygiene violation |
| 10 | Registry docblock claims 86 modules; actual count 83 (utilities comment says 15, lists 14) | `class-module-registry.php:9` | Cosmetic |

Also previously known (build authority §24): White_Label checks module id `login-customizer` but registry uses `login-branding`.

## Per-Module Findings (condensed)

### permission-model — MISSING

Nothing exists: no capabilities, no manager class, no filter, no role grants, no uninstall cleanup. See cross-cutting #1. Additional notes: dangerous tools (Search & Replace, Code Snippets, DB cleanup, role editor) gate on `manage_options` only, with JS `confirm()` instead of the Safety Check Modal; the WPT top-level menu is visible to any `edit_posts` user (by editor-dashboard design — needs reconciling when caps land).

### conflict-detector — MISSING

No class, no registry entry, no `wpt_conflict_rules` filter, no rules, no scan, no UI, no dismissals. README already advertises it. Highest-overlap modules ship with zero rival detection (`email-smtp` hooks `phpmailer_init` unconditionally with no WP Mail SMTP check; redirect-manager/login-security/media-library-pro similar). Reusable seeds for the build:

- `class-auto-clear-caches.php:209-232` — constant/function/class detection technique (rename-proof)
- `class-smart-menu-organizer.php:127-209` — ~70-slug known-plugin map + `wpt_known_plugin_sections` filter (best seed list) and its notice + AJAX-dismiss flow as structural template
- `class-setup-wizard.php:919-924` — `is_plugin_active()` basename pattern
- `class-system-summary.php:277-299` — active-plugin inventory builder

### module-registry — PARTIAL

Stable IDs, single-source loading, and the `wpt_registered_modules` filter exist; everything metadata-shaped is missing (see cross-cutting #5). No validation layer, no REST, duplicate filter entries silently overwrite, unvalidated filter input is `require_once`'d. Implemented IDs diverge from canonical slugs (`email-smtp` vs `email-delivery`, `admin-bar` vs `clean-admin-bar`, `login-branding` vs `login-customizer`, `database-cleanup` vs `database-optimizer` — self-documented at `class-module-hierarchy.php:27-35`); per the spec IDs are stable-forever, so reconcile against the canonical registry **before** more modules ship.

### module-loader — PARTIAL

Solid: single-query active-state read, init-only-when-active, asset enqueues gated on active IDs, disable preserves settings, Safe Mode skips boot entirely, per-module try/catch. Gaps: zero-load violated (cross-cutting #5), no quarantine (#6), no lifecycle hooks (#3), enable path checks nothing (no dependency validation — deps only soft-skip init at next boot leaving enabled-but-not-running with no UI surface; no conflict/risk warnings; no immediate-init-with-rollback), no `/reset` equivalent, `is_pro_licensed()` hardcoded false (Freemius TODO).

### settings-storage — PARTIAL

The core is sound (custom table, request cache, `$wpdb->replace`, defaults merging in `Module_Base`) and satisfies the anti-autoload motive. Missing: schema validation layer, central secrets manager (redaction is a hardcoded 2-key list for email-smtp; password-protection's hash exports unredacted; a stored SMTP password can never be cleared), global settings object, settings versioning/migration (`wpt_db_version` is write-only), per-module reset (only a nuclear delete-all that also wipes active states), the four spec'd hooks, uninstall retention options, `updated_by`. Table schema diverges (no `setting_key` → one row per module; `JSON` column type + `ON UPDATE CURRENT_TIMESTAMP` are dbDelta/compat risks). Corrupted JSON silently returns defaults (spec wants log + warning); unknown stored keys ARE returned to modules (spec says don't).

### recovery-center — PARTIAL (~15–20%)

What exists is good: token gate with `hash_equals`, 32-char token, request-scoped module bypass, banner, uninstall cleanup. Missing: everything else — quarantine, Recovery Center UI/page with the 5 recovery actions, last-enabled-module tracking, known-good config snapshots, recovery email, REST, hooks, logging. Plus: raw token stored (spec says hash only), token never expires/rotates, no capability check on activation (any logged-in user with the token gets a modules-disabled request), docblock claims a WP-CLI regenerate command that doesn't exist, and the chrome-bypass + safe-mode-modules-page issues (cross-cutting #4).

### module-library — PARTIAL

Grid, category pills, sub/parent toggles with nonce+cap, server-side Pro rejection all work. Gaps: no on-page search at all (palette matches title/category substring only, multi-word queries fail); no tier/risk/bundle filters; no risk metadata → no Safety Check Modal (search-replace/code-snippets enable instantly — explicit spec violation); no conflict/dependency/quarantine surfacing; Pro sub-modules inside free parents look toggleable and fail silently, while `debug-tools`/`white-label` parents are Pro-locked over all-free sub-modules (contradictory gating); pills hidden on mobile with no fallback; sub-toggles 36×20px (below 44px touch target); no UI-prefs persistence; empty-category pills dead-end. Library is hardcoded in Admin, not registry-listed (consistent with always-available, worth a spec note).

### dashboard-shell — PARTIAL

Structural divergence: spec's status dashboard and the module library are one merged page (`admin.php?page=wptransformed`) — either split or amend both specs. **Fake metrics violate the spec's hardest rule:** hardcoded Performance 94/+8, Security "A+ / All clear", "Optimized" memory, "all systems green" (`class-admin.php:269-303`). Missing: setup-progress card (setup-wizard module is orphaned — no hierarchy parent, no CTA), email/database/conflict/recovery status cards, recommended-next-steps, recent-activity, dismissible-card framework + `wpt_dashboard_widgets` filter, 7 of 9 spec'd quick actions.

### import-export — PARTIAL (shipping-broken)

See live bugs #1–2. Structurally the spec wants an always-available **system service** (`WPT_Import_Export_Service` with preview/apply flow, format versioning, `site_url_hash`, secrets policy, selective export); what exists is an off-by-default utilities module with none of that plus an out-of-spec "Reset All Settings." Recommendation from review: build the system service fresh in `includes/`, thin the utilities module into a UI shell (or retire it), fix the `get_instance` fatal immediately regardless, and add a settings-shaped `sanitize_imported_settings()` contract to `Module_Base` (the form-POST-shaped `sanitize_settings()` cannot be reused for import).

### admin-chrome-foundation — PARTIAL

The reskin itself is real and contract-compliant in its core mechanics: native `#adminmenu`/`#wpadminbar` styled not replaced, zero `remove_menu_page()` in the chrome layer, section separators at `admin_menu` 999, vanilla JS, no build step, correct defer-to-Smart-Menu-Organizer. Gaps beyond cross-cutting #4/#7: no chrome settings object at all (nothing configurable — upgrade card, search, user area, theme default all hardcoded on); upgrade card with "$99/yr" shown to every role; no `prefers-reduced-motion` support; no ECOMMERCE section or builder/forms detection in the chrome layer (fixed integer position ranges that miss float positions like WooCommerce's `'55.5'`); none of the four spec'd filters; Google Fonts + cdnjs Font Awesome from CDN on every admin page (GDPR/intranet decision needed); JS-off leaves empty section labels and a blank topbar; breakpoint 800px vs WP's 782px.

### editor-dashboard-shell — PARTIAL

Core loop works well (real data, `perm => 'editable'` on recent posts, bounded queries, escaping, empty states). Gaps: not a module (hard-instantiated, no settings, can't be disabled, no registry entry); wizard redirect dead (live bug #8); quick actions have zero capability checks (Block Patterns → site-editor.php shown to users who lack `edit_theme_options`; New Page/Upload Media lack `edit_pages`/`upload_files` checks) and the spec'd Duplicate-Content/Modules/Settings actions are missing; scheduled-content query has no `perm` filter (contributors see other authors' scheduled titles); no site-health/notifications/module-activity sections; no dedicated drafts list; never collapses to single column ≤768px; unspecced "Writing Tip" card with marketing claims (adjacent-feature violation); "View Calendar" links to a list table, not a calendar.

## Architecture Decisions (resolved 2026-06-09)

All 8 open decisions were resolved with the product owner. Notably, the spec-compliant option was chosen in every case where one existed — so the specs build as written; only the two decisions that ADDED definition (3 and 6) required spec amendments, recorded in `recovery-center.md` and `admin-chrome-foundation.md`.

1. **API layer → Full `wpt/v1` REST in Phase 1.** Every spec's §8 builds as written. Existing admin-ajax toggle/save endpoints (and their JS) migrate to REST and retire. The REST layer lands after its prerequisites (permission-model, registry metadata). Conflict rule to record: the disable-backend module's "disable REST API" feature must whitelist `wpt/v1` for authenticated admins.
2. **Registry metadata → definition arrays in the registry.** Each entry becomes `id => { file, title, category, tier, risk, default_enabled, search_terms, dependencies, … }`. Class getters become derived/deprecated. Enables zero-load, locked-Pro-without-loading, validation, REST exposure, on-page search.
3. **Safe Mode → native admin + admin-only token.** Safe Mode strips ALL WPT chrome assets/injections (stock WP admin renders); only the WPT settings page and Recovery Center load, reading module state directly from Settings Storage; activation requires a valid token AND a logged-in administrator (`manage_options` until permission-model ships, then `manage_wpt`). Recorded in recovery-center spec §7/§10 and admin-chrome spec §6.
4. **Uninstall → full 4-choice retention matrix in Phase 1** (keep all / settings only / logs only / delete all) per settings-storage §15. `uninstall.php` branches on the stored choice; default = keep all.
5. **Dashboard/library → split into two pages.** Modules page becomes a pure library; a new Dashboard submenu page hosts status cards (setup progress, conflicts, recovery alerts, real stats); welcome banner/bento move there. Matches both specs and the addendum §3 nav model.
6. **Dark mode → chrome owns the base theme.** Single user meta `wpt_theme_mode` ∈ `light|dark|system`, with a one-time migration converting both legacy `wpt_dark_mode` vocabularies (`'1'/'0'` and `'dark'/'light'`). Theme modules layer on the same key; no parallel preference keys. Recorded in admin-chrome spec §6.
7. **Fonts/icons → self-host/bundle** Outfit + JetBrains Mono + Font Awesome Free; remove the Google Fonts / cdnjs enqueues (WordPress.org guideline 8, GDPR, offline/intranet). Recorded in admin-chrome spec §10.
8. **Slugs → rename code IDs to canonical now**, pre-launch, with a one-time `wpt_settings` row migration — done together with the registry restructure and the required `docs/module-hierarchy.md` regeneration from v5.2.3 + addendum §12 slug registry.

## Build Order (updated for resolved decisions)

> Execution note: sequencing is now governed by [phase1-reframe-v4-1.md](phase1-reframe-v4-1.md) (execution addendum) — Safe Mode bypass moves ahead of registry/REST work and a product-proof vertical slice is added. The findings, bug table, and resolved decisions in THIS file remain authoritative.

1. **Quick-fix batch (live bugs):** `Core::get_instance()` fatal; dead app-page links; bento count; `Settings` cache-priming guard; activation→wizard→dashboard redirect chain; dark-mode AJAX capability check. Small, independent, immediately shippable. (The dark-mode meta collision and Safe Mode chrome gate are *not* patched here — their proper fixes are decisions 6 and 3, implemented in steps 5 and 8.)
2. **permission-model** (MISSING; everything depends on it, now including REST): `Permission_Manager` + capability grants on activation + `wpt_db_version`-gated migration for existing installs + swap the 88 `manage_options` sites incrementally (menu/AJAX gates first).
   — *Done 2026-06-09:* `includes/class-permission-manager.php` (15 caps, activation grant + version-gated migration, missing-role warning, `wpt_user_can_manage_module` filter, full-cleanup removal API), PHPUnit-style tests at `tests/test-permission-model.php`. Swapped: WPT Modules page menu/render → `manage_wpt_modules`, settings save → `manage_wpt_settings`, both toggle AJAX handlers (single-module path applies the filter), and 11 destructive endpoints now also require `run_wpt_dangerous_tools` (search-replace run/undo, 4 snippet mutations, 4 role mutations, DB cleanup run). Remaining `manage_options` sites in feature modules swap as those modules get spec'd; Safety Check Modal still step 8.
3. **Registry definition arrays + loader zero-load + canonical slug renames + hierarchy regeneration** (one structural workstream, one settings migration): definition-object registry → include only active module files → Pro/locked cards from metadata → validation + admin notices → rename IDs to canonical slugs → regenerate `docs/module-hierarchy.md` from v5.2.3.
4. **`wpt/v1` REST layer (decision 1):** namespace bootstrap, base controller, permission-callback plumbing; modules + settings routes per spec; migrate existing toggle/save JS off admin-ajax and retire those actions. Lifecycle hooks (`wpt_module_enabled/disabled/quarantined`, `wpt_module_settings_saved`, …) land here alongside the loader/settings touch points.
5. **recovery-center** (REST-first, per decision 3): Safe Mode chrome bypass + admin-only activation + hash-stored token + token surfacing; crash quarantine (generalize the shutdown-handler pattern from `class-code-snippets.php:161-200`); Recovery Center UI; Settings-Storage-direct reads for the Safe Mode modules page.
6. **conflict-detector** (REST-first, greenfield on the seeds listed above): rules for the headline overlaps first (WP Mail SMTP, Redirection, login plugins, FileBird) + `wpt/v1` whitelist rule for disable-backend.
7. **import-export system service** (rebuild per spec, REST preview/apply flow; retire/thin the utilities module) + settings-storage hardening + the 4-choice uninstall retention matrix (decision 4).
8. **Surface remediation:** Dashboard/Modules page split (decision 5); remove fake metrics; library on-page search + risk badges + Safety Check Modal; chrome settings object + self-hosted fonts (decision 7) + `wpt_theme_mode` migration (decision 6) + CSS scoping fix + mobile menu toggle; editor-dashboard capability checks + settings.

Steps 1–4 unblock everything else; 5–8 then proceed module-at-a-time per the build rules (one module, full verification on the live install, then next).
