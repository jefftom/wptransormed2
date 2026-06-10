# Foundation Checkpoint — Reframe v4.1 Workstream Complete

**Date:** 2026-06-10
**Branch:** `v5-foundation-alignment` @ `2e729f7` (all commits pushed)
**Purpose:** state of the foundation after the registry/hierarchy workstream; readiness gate for REST skeleton work (reframe v4.1 step 9). Documentation only — no runtime changes in this commit.

## 1. Commit List (quick fixes → Slice 6)

| Commit | Subject |
|---|---|
| `5412fb7` | fix: quick-fix batch — six live bugs from Phase 1 gap analysis |
| `9018ce0` | docs(audit): mark quick-fix batch bugs resolved in gap analysis |
| `60c078f` | feat(module): Add Permission Model module |
| `729663a` | docs(audit): adopt Phase 1 Reframe v4.1 as execution addendum |
| `b7fbfa6` | feat(core): Safe Mode bypasses admin chrome, requires admin capability |
| `6686b44` | feat(core): boundary permission migration micro-pass |
| `f65d446` | feat(registry): definition arrays + validation (slice 1 of 6) |
| `e8ef03e` | fix(core): align database optimizer ajax capabilities |
| `ee28e8c` | feat(registry): definitions become source of truth + filter contract (slice 2) |
| `67b9870` | feat(registry): hierarchy consumes definitions (slice 3) |
| `533eb67` | fix(registry): white-label definition tier is Pro per product authority |
| `1c5b312` | test(loader): zero-load instrumentation harness + baseline (slice 4A) |
| `b223490` | feat(loader): zero-load module loader (slice 4B) |
| `a79775f` | fix(registry): error-log-viewer definition tier is Pro per scope §17.2 |
| `b16695b` | feat(registry): canonical slug migration (slice 5) |
| `3dc08e8` | docs(registry): regenerate module-hierarchy.md from definitions (slice 6) |
| `2e729f7` | docs(registry): module-hierarchy.md meets full slice-6 spec |

## 2. Module Definition Count

**83** canonical definitions in `Module_Registry::DEFINITIONS`, all passing `validate_all()`.

## 3. Tier / Status Counts (from definitions)

- Core implemented: **70**
- Pro: **7** (`client-dashboard`, `email-log`, `error-log-viewer`, `temporary-user-access`, `terms-order`, `two-factor-authentication`, `white-label`) — all implemented
- Core stubs (spec exists, empty init): **6** (`media-library-pro`, `login-protection`, `strong-passwords`, `login-notifications`, `disable-frontend`, `disable-backend`)
- Mixed-tier parent cards (per-sub Pro gating): **6**; fully-locked parents: **1** (`two-factor-auth` group)
- Deferred/aspirational hierarchy ids (no definition, filtered from UI): **62**
- Companion integrations: none defined yet
- Provisional risk labels: `advanced` 7, `moderate` 13, rest `safe` — pending per-module specs

## 4. Boot Include Counts (harness-measured, `tests/harness-zero-load.php`)

| Scenario | Module implementation files included |
|---|---|
| Safe Mode (boot skipped — pre-boot equivalence) | **0** |
| Normal boot, harness seed (14 active rows; 9 pass gates) | **9** (= allowed-active exactly) |
| Module Library render path | **+0** (cards/palette/search render from definitions only) |
| Pre-workstream baseline (slice 4A) | 83 on every request |

Lazy admin-operation loads (`load_module`) include at most one extra file per explicit settings/import operation, never call `init()`, and refuse stubs/unlicensed-Pro/quarantined.

## 5. Remaining `current_user_can( 'manage_options' )` — 62 across 27 files

- **2 deliberate** (keep): `Permission_Manager::render_missing_role_notice()` (emergency-recovery audience), `Safe_Mode::is_active()` fallback (spec §11).
- **60 module-internal guards** awaiting per-module capability assignment as each module gets its spec: utilities 29 (redirect-manager 7, broken-link-checker 6, search-replace read-only pair, cron-manager 3, email-log 3, 404-monitor 3, error-log-viewer 2, maintenance-mode 1, + others), admin-interface 19, security 8 (incl. temporary-user-access AJAX pair), performance 4, code-snippets runtime execution gate (:177, documented TODO — execution semantics, not a boundary).
- All WPT page/action **boundaries** already use `wpt_*` capabilities (micro-pass `6686b44` + `e8ef03e`).

## 6. Remaining Old AJAX Endpoints

- **88 `wp_ajax_*` registrations** (85 in feature modules + 3 core: `wpt_toggle_module`, `wpt_toggle_parent`, `wpt_save_dark_mode`) and **2 `admin_post_*`** (menu-editor, login-designer saves).
- All are nonce + capability checked; dangerous endpoints carry `run_wpt_dangerous_tools`.
- Per reframe §7: REST is canonical for NEW work; this surface migrates screen-by-screen, no new major admin-ajax endpoints.
- *Update 2026-06-10:* the `wpt/v1` REST skeleton exists (§16). Admin-ajax request/response contracts are unchanged; the two core toggle handlers now persist through the shared `Core::set_module_active()` path, so REST and admin-ajax fire identical lifecycle hooks.

## 7. Lifecycle Hook Gaps

*Updated 2026-06-10 (REST skeleton commit).* The first three lifecycle hooks now exist, in the shared storage layer so every surface fires them identically (admin-ajax single/parent toggles, `wpt/v1` REST toggle, setup wizard, app-page saves, import):

- `wpt_module_enabled` / `wpt_module_disabled` — fired by `Settings::toggle_module()` after a successful persist, canonical module id only, never before persistence. *Amended by toggle hardening (§16.1): fires only on actual state transitions — no-op re-assertions are suppressed (no write, no hook).*
- `wpt_module_settings_saved` — fired by `Settings::save()` after a successful persist, canonical module id + persisted settings.

Still missing: `wpt_module_quarantined`, `wpt_safe_mode_triggered`, `wpt_settings_exported` / `wpt_settings_imported`, `wpt_loaded`. Filters unchanged: `wpt_registered_modules`, `wpt_command_palette_actions`, `wpt_known_plugin_sections`, `wpt_user_can_manage_module`. Recovery Center / Conflict Detector / Audit Log now have the module enable/disable/settings events to subscribe to.

## 8. Safe Mode / Recovery Center Gaps

Done: chrome bypass (`b7fbfa6`), token + admin-capability activation, quarantine list read (structural, always empty), zero-load in Safe Mode.
Remaining: no Recovery Center UI (the 5 recovery actions); no quarantine WRITER (no shutdown-handler crash detection); token stored raw (spec wants hash-only) with no rotation/expiry; token never surfaced to admins (DB-only discovery); no recovery email; the Modules page in Safe Mode still renders a dead grid (needs Settings-Storage-direct reads); `wpt_safe_mode_triggered` never fires.

## 9. Import/Export Gaps

Done: fatal fixed, unknown-ID skip, zero-load lazy sanitize, canonical-ID write-back (old exports can't recreate legacy rows), `export_wpt_data` / dual-gated reset boundaries.
Remaining: **the sanitize-shape mismatch** — `sanitize_settings()` is form-POST shaped, so imported settings VALUES for known modules still reset to defaults (needs a `sanitize_imported_settings()` contract); no preview/dry-run or Safety Check Modal; no export-format versioning or `site_url_hash`; secrets policy is a hardcoded 2-key list (email-delivery only); no selective export; system-service rebuild per spec is reframe step 12.

## 10. Admin Chrome / CSS Scope Gaps

`wpt-admin` body class still applied to every admin page, defeating the "scoped" broad selectors (`.button`, `.notice`, `.postbox`, tables) — contract violation; native `#wpadminbar` quicklinks still sr-only-hidden, including WP's responsive menu toggle → mobile admin likely unusable; Google Fonts + cdnjs Font Awesome still CDN-loaded (decision 7: self-host); no chrome settings object (upgrade card/search/user area hardcoded, shown to all roles); no `prefers-reduced-motion`; dark-mode meta collision pending the `wpt_theme_mode` migration (decision 6); sidebar search trigger still dispatches an event nothing listens to.

## 11. Dashboard / Modules Split + Fake Metrics

Both pending (decision 5; reframe step 13): the status dashboard and Module Library remain one merged page, and the hardcoded Performance 94/"+8", Security "A+ / All clear", "Optimized" memory tiles still render — the spec's "no fake metrics" rule remains violated until surface remediation.

## 12. Orphaned Implemented Modules

**`setup-wizard`** — implemented, registered, in no parent card (reachable only via the hidden page + activation redirect). All other 82 modules are parented.

## 13. Legacy Slug Literals Still Present (all approved exceptions)

- `legacy_ids` arrays in the 10 renamed definitions — alias resolution + migration map source.
- `tests/harness-zero-load.php` — migration test seeds/rename map by design.
- `two-factor-auth` hierarchy parent key — documented grouping key, not a module id (annotated in code and in the generated doc).
- `docs/module-hierarchy.md` — Legacy alias columns + migration notes (generator self-check enforces this confinement).
- Historical docs under `docs/archive/` and audit records — history, not code.

## 14. Top 10 Risks Before Public Alpha

1. **Zero live-install verification of the entire workstream** — Laragon/MySQL has been down since the quick-fix batch; everything since is harness-verified only. A full click-through on wpt-dev (which will also exercise the real slug migration + caps migration on its existing rows) is the single highest-value next action.
2. **Import silently resets known modules' settings values** (sanitize-shape mismatch) — data-loss class bug behind an admin button.
3. **Mobile admin likely unusable** (hidden responsive toggle) + globally-scoped chrome CSS on third-party pages — compatibility contract violations.
4. **No crash quarantine** — a fataling module still takes down wp-admin until the (undiscoverable) Safe Mode token is fished from the DB.
5. **No CI / no runnable PHPUnit** — the standalone harnesses are good but run manually; nothing prevents regressions on push.
6. **Fake metrics** on the dashboard — credibility risk if any outsider sees an alpha.
7. **Pro gating untested against real licensing** — `is_pro_licensed()` hardcoded false; Freemius integration will exercise untrodden paths (grace states, expiry).
8. **Downgrade/parallel-install hazards** — pre-slice-5 zips and the stale `wpt-test` tree are incompatible with a migrated DB.
9. **CDN fonts/icons** — wp.org guideline 8 + GDPR exposure; blocks any public distribution.
10. **62 aspirational ids + 6 stubs visible in planning surfaces** — scope-confusion risk; wizard/marketing must never promise unimplemented modules.

## 15. Recommended Next Build Order

1. **Live verification pass on wpt-dev** — DONE 2026-06-10 (`docs/audits/live-verification-2026-06-10.md`, PASS with findings F1–F4; F1 fixed in `257ea19`).
2. **REST skeleton** (reframe step 9) — DONE 2026-06-10, see §16: `wpt/v1` namespace, shared response/error contract, `Permission_Manager` callbacks, first routes, and the first lifecycle hooks (`wpt_module_enabled/disabled`, `wpt_module_settings_saved`) in the shared toggle/save paths.
3. **Product-proof vertical slice** (step 10).
4. **Conflict Detector** (step 11) — definitions now provide the metadata it reasons over.
5. **Import/export rebuild + uninstall retention matrix** (step 12) — fixes risk #2.
6. **Surface remediation** (step 13): dashboard/modules split, fake metrics, self-hosted assets, `wpt_theme_mode` migration, mobile chrome fix.

## 16. wpt/v1 REST Skeleton (landed 2026-06-10)

**Status: LANDED.** `includes/class-rest-controller.php` (`Core\Rest_Controller`), registered on `rest_api_init` from the main plugin file for every request type (Safe Mode is a tokened wp-admin gate and never applies to REST dispatches). Unit harness: `tests/harness-rest.php`. Live-verified on wpt-dev: full HTTP 200/403/401 matrix as admin / capless editor / unauthenticated, plus internal-dispatch battery with hook listeners and include accounting.

**Envelope contract (permanent — every future AJAX→REST migration uses the shared helpers; nothing returns raw arrays):**

- Success: `WP_REST_Response` carrying the data payload directly with the HTTP status. No `{success: true}` envelope.
- Error: `WP_Error` with a stable `wpt_*` code, human-readable message, and `data.status` — WP core renders the standard `{code, message, data: {status}}` shape.
- Stable codes so far: `wpt_forbidden` (401 logged-out / 403 capless), `wpt_invalid_module` (404), `wpt_pro_locked` (403), `wpt_module_stub` (400, toggle hardening §16.1), `wpt_module_unavailable` (500, slice 10a §16.2), `wpt_toggle_failed` (500), `wpt_settings_save_failed` (500, slice 10a §16.2).

**Routes:**

| Route | Method | Capability | Behavior |
|---|---|---|---|
| `/wpt/v1/system/status` | GET | `manage_wpt` | version, module counts (total/active/pro-locked), request-scoped safe-mode flag |
| `/wpt/v1/modules` | GET | `manage_wpt_modules` | definition-backed metadata + active state for all 83; zero module-file loads |
| `/wpt/v1/modules/{id}` | GET | `manage_wpt_modules` | accepts canonical id or legacy alias; payload always carries the canonical id |
| `/wpt/v1/capabilities` | GET | `manage_wpt` | current user's `wpt_*` capability booleans; no role/user enumeration |
| `/wpt/v1/modules/{id}/toggle` | POST | `manage_wpt_modules` + `wpt_user_can_manage_module` filter (canonical id) | same validation order as admin-ajax; persists via shared `Core::set_module_active()` |
| `/wpt/v1/modules/{id}/settings` | GET | `manage_wpt_settings` | defaults-merged stored settings + defaults; sanctioned single-module lazy load (§16.2) |
| `/wpt/v1/modules/{id}/settings` | POST | `manage_wpt_settings` | FULL REPLACE via `validate_settings()`; `changed` semantics; never `sanitize_settings()` (§16.2) |

- No public unauthenticated routes; every route has a real permission callback through `Permission_Manager` capabilities.
- Pro-locked module metadata is returned (`pro_locked: true`), Pro files/classes never load; Pro toggle rejects `wpt_pro_locked` before any write.
- Internal wiring fields (`file`, `class`, `capability`, `has_cleanup`) are never exposed in payloads.
- Toggle persistence + deactivate lifecycle is `Core::set_module_active()`, shared with both admin-ajax toggle handlers — lifecycle hooks cannot drift between surfaces (see §7).

### 16.1 Toggle hardening (2026-06-10, post-skeleton audit)

**Ratified as decided (as-built):**

- `Core\Rest_Controller` in `includes/class-rest-controller.php` is the permanent name/location for the REST foundation.
- `Core::set_module_active( string $id, bool $active ): bool` keeps its as-built signature; validation and canonicalization stay caller-side at the boundary handlers (the F1 pattern). The v2-spec'd `(input_id, active, source): array|WP_Error` signature is rejected.
- The toggle `active` argument stays validated by the route args schema, so missing/invalid-body errors use the WP-native `rest_missing_callback_param` / `rest_invalid_param` shapes — the third sanctioned response origin alongside handler errors and permission errors.

**New permanent contracts:**

- **No-op suppression** (`Settings::toggle_module`): re-asserting the current persisted state writes nothing and fires no lifecycle hook; absent rows read as inactive, so a row-less disable creates no row. `Settings::is_module_active()` is the single source of pre-toggle state (the loader's active id list is a boot snapshot; never use it for this).
- **Toggle response shape (four fields, PERMANENT):** `{ "id": <canonical>, "active": <bool>, "previous_active": <bool>, "changed": <bool> }`. A no-op returns HTTP 200 with `changed: false`. Admin-ajax responses gained nothing — their contracts are frozen.
- **Stub gating (status asymmetry):** non-implemented definitions are inert. ENABLE rejects: REST `wpt_module_stub` ("This module is not yet implemented.", 400); ajax single-toggle `'Module not yet implemented'`; parent batch skips with per-sub error `'not implemented'` (batch continues, existing per-sub failure semantics); setup wizard filters `$modules_to_enable` to `status === 'implemented'` before its batch loop. DISABLE stays allowed so previously-persisted inert active rows clean up. Unknown ids keep the 404/'Unknown module' behavior; pro keeps `wpt_pro_locked`, checked before status. Gating lives at the validation boundaries — `Settings`/`Core::set_module_active` stay policy-free apart from no-op suppression.
- **ajax_toggle_module check order:** nonce → empty → definition lookup ('Unknown module') → canonicalize → per-module filter ('Unauthorized', 403) → pro gate → status gate → toggle. Accepted consequence: `wpt_user_can_manage_module` now always receives the canonical id (parity with REST), and a filter-denied user probing an unknown id sees 'Unknown module' instead of reaching the filter.

**Decided deferrals / gaps:**

- `$context` parameter on lifecycle hooks: deferred until the audit-log module actually consumes them. Decided deferral, not an oversight.
- ✓ Settings-save no-op semantics CLOSED (settings-save hardening commit, 2026-06-10) — **no-op save suppression is a permanent contract**: when a cache entry exists for the module AND `wp_json_encode()` of the incoming settings is strictly identical to the encoding of the cached settings, `Settings::save()` returns `true` with no database write and no `wpt_module_settings_saved` hook. The comparison is a string comparison of the two encodings — key-order or type differences that change the encoding are real changes and write normally. No cache entry = never a no-op (first saves create the row). `is_active` handling and sanitization behavior unchanged; the method compares already-sanitized input as-is.
- `wp_ajax_wpt_toggle_parent` applies **no per-module filter** — the batch path requires `manage_wpt_modules` outright; `wpt_user_can_manage_module` applies to single-module toggles only. Recorded as a known gap, unchanged by this pass.

### 16.2 Slice 10a — module settings routes + validate_settings (2026-06-10)

**The validate/sanitize dual contract (PERMANENT):** `Module_Base::sanitize_settings( array $raw ): array` maps RAW FORM input (`wpt_*` field names) to storage shape and keeps serving the existing form save paths, unchanged. `Module_Base::validate_settings( array $settings ): array` (new) takes and returns STORAGE shape — the contract for REST and, at slice 12, import (whose known caveat becomes a one-line swap to `validate_settings`). Feeding storage-shape data to `sanitize_settings()` silently returns defaults — the import bug class; the REST POST path never calls it. Base implementation is a whitelist + type floor (default-key whitelist, scalar coercion to the default's PHP type, array/non-array mismatch → default, missing key → default, unsupported default types → default); modules whose settings can enable dangerous behavior MUST override with real validation. `Database_Cleanup::validate_settings` is the first override: strict category booleans, `keep_recent_revisions` absint-clamped 0–100, `optimize_tables` bool, output exactly the three storage keys.

**Settings-route contracts (PERMANENT):**

- **Permission:** `manage_wpt_settings` only — named, decided parity with the `Admin::handle_save` form path, which checks CAP_SETTINGS and nothing else. Deliberately NO `wpt_user_can_manage_module` filter and NO `def['capability']` check on settings routes (`def['capability']` currently gates app pages, not settings writes).
- **{id} handling:** the pinned id regex/args pattern; canonical ids or legacy aliases accepted; payload `id` always canonical; alias-addressed responses carry `canonicalized_from: <requested alias>` (absent on canonical requests).
- **Gating ladder (both methods, toggle order):** unknown → `wpt_invalid_module` 404 · pro unlicensed → `wpt_pro_locked` 403 (file never loads) · status ≠ implemented → `wpt_module_stub` 400 (file never loads) · `load_module()` null after those gates → `wpt_module_unavailable` 500 ("Module could not be loaded.").
- **GET 200 payload:** `{ id, settings: get_settings() (defaults-merged storage shape), defaults: get_default_settings() }`.
- **POST:** body `{ "settings": { …storage shape… } }`, required object validated via route args schema (missing/invalid → WP-native `rest_missing_callback_param`/`rest_invalid_param`, the ratified third response origin). Semantics are FULL REPLACE: the stored row becomes exactly `validate_settings( body.settings )` — never a merge; reads stay defaults-merged so omitted keys behave as defaults. 200 payload `{ id, settings: get_settings() after save, changed }`; `changed` = persisted encoding differs from the pre-save encoding; an identical save is a storage-layer no-op (no write, no `wpt_module_settings_saved`) returning `changed: false`. `Settings::save()` failure → `wpt_settings_save_failed` 500.
- **Zero-load amendment (PERMANENT):** settings routes are the sanctioned single-module lazy-load exception — at most the target module's file is included, via `Core::load_module()`, which never calls `init()` (no hooks register). `/modules` and `/modules/{id}` stay strictly no-load; the pro/stub gates guarantee those files still never load.

**§8 divergence note:** reframe §8 prescribed `POST /enable` + `/disable`; built and ratified as `POST /toggle` (§16.1) — supersedes §8.

**Gaps (slice 10a):**

- Import still calls `sanitize_settings()` with storage-shape data (known caveat) — the slice-12 fix is now a one-line swap to `validate_settings()`.
- `def['capability']` is not enforced on any settings surface (form path or REST) — decided parity choice; open policy question: should settings routes honor def capability? Revisit at 10c.
- Remaining reframe §8 routes unbuilt: `GET /system/safe-mode-url`, `POST /import-export/export`, `POST /import-export/import`.
