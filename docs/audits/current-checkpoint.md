# WPTransformed — Current Checkpoint

> **Single living checkpoint.** This is the one authoritative checkpoint: update it in place every run — never create a `-vN` successor (this file replaced `foundation-checkpoint-v4-1.md`). The standing verified-facts sheet was retired (v3.2) and folded into [Current Verified Facts](#current-verified-facts) at the end of this document; there is no separate facts sheet.

**Origin:** 2026-06-10, branch `v5-foundation-alignment` — foundation snapshot at `2e729f7`, after the registry/hierarchy workstream (readiness gate for REST skeleton work, reframe v4.1 step 9). Ongoing state is carried in the numbered sections below (latest update: §16.3) and in Current Verified Facts.

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
- Stable codes so far: `wpt_forbidden` (401 logged-out / 403 capless), `wpt_invalid_module` (404), `wpt_pro_locked` (403), `wpt_module_stub` (400, toggle hardening §16.1), `wpt_module_unavailable` (500, slice 10a §16.2), `wpt_toggle_failed` (500), `wpt_settings_save_failed` (500, slice 10a §16.2), and the seven slice-10b codes (§16.3): `wpt_module_inactive` (409), `wpt_invalid_post` (404), `wpt_post_type_not_enabled` (400), `wpt_duplicate_failed` (500), `wpt_invalid_recipient` (400), `wpt_email_send_failed` (502), `wpt_invalid_settings` (400).

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

### 16.3 Slice 10b — action routes, secret settings, alias parity (2026-06-10)

**Secret-settings contract (PERMANENT; amends 10a's full-replace for declared secrets only):** `Module_Base::get_secret_settings_keys(): array` (default `[]`; Email_SMTP declares `['password']`) — the CONTROLLER applies the policy, not modules. GET masks each declared key: `''` when the stored value is empty, otherwise the literal sentinel `__WPT_SECRET__` (defaults member treated identically); ciphertext or plaintext never leaves over REST. POST resolves secrets BEFORE `validate_settings`: an absent or sentinel-valued key splices the stored value back in unchanged (the full-replace exemption); an explicit `''` clears; any other string flows to `validate_settings` as new input; non-string values reject `wpt_invalid_settings` 400 ("Secret settings values must be strings."). `changed` is computed on post-resolution encodings, so an untouched GET→POST round-trip is a clean no-op (no write, no hook). A literal secret VALUE of `__WPT_SECRET__` is unsupported by design — the token is reserved to mean keep-existing. `Email_SMTP::validate_settings` applies the password rule by IDENTITY, not prefix: input string-identical to the stored value passes through byte-unchanged; `''` clears; ANY other string — including `enc1:`-prefixed strings not matching the stored value — is encrypted as new plaintext (closes ciphertext injection). Legacy behavior (recorded): a stored legacy *plaintext* password splices through unchanged under the sentinel and upgrades to `enc1:` only when the user submits a new password.

**Action-route contract (PERMANENT):** path shape `POST /wpt/v1/modules/(?P<id>[a-z0-9-]+)/actions/(?P<action>[a-z0-9-]+)` — both args pin the existing strict pattern + `sanitize_key`; explicit per-action registrations only (generic dispatcher deferred until a third action exists — logged gap). Gating ladder extends the settings ladder by one rung: unknown → `wpt_invalid_module` 404 · pro unlicensed → `wpt_pro_locked` 403 · stub → `wpt_module_stub` 400 · NOT ACTIVE → `wpt_module_inactive` 409 ("Module is not active." — actions run module behavior; settings routes deliberately serve inactive modules, actions deliberately do not) · active instance unexpectedly null → `wpt_module_unavailable` 500. Handlers use the boot-loaded ACTIVE instance — never `load_module()`; the 10a zero-load exception is unchanged. Two-tier permissions (PERMANENT): `permission_callback` enforces the coarse capability (duplicate: `edit_posts`; send-test-email: `manage_wpt_email`); object-level checks live in the handler and fail `wpt_forbidden` 403. No `def['capability']` auto-wiring (the 10c revisit note now also covers action routes). An active module that does not own the addressed concrete action returns `wpt_invalid_module` 404 (edge not specified by the decision doc; closed with an existing code, no new code invented). Duplicate capability parity (decided, inherited risk): the only object-level check is `edit_post` on the SOURCE, matching the admin_action path; create/publish capability on the resulting post is not separately checked and `new_status` comes from settings — tightening is a per-module-spec question.

**Shared-method delegation rule (PERMANENT for dual-surface actions):** an action's implementation lives in exactly ONE public module method with a pinned contract; every surface delegates. `Content_Duplication::duplicate_post( int $post_id ): int|WP_Error` (PURE re UI — no notices/transients/redirects; `wp_insert_post` appears in the module only inside it) and `Email_SMTP::send_test_email( string $recipient ): array{sent: bool, debug: string}` (never WP_Error, never throws; `wp_mail` appears in the module only inside it). The legacy `admin_action`/ajax surfaces delegate with byte-unchanged request/response contracts and remain until their screens are rebuilt (logged gap).

**canonicalized_from parity (ratified convention, corrects 10a's "meta" wording):** every module-addressed route carries top-level `canonicalized_from: <requested alias>` on alias-addressed SUCCESS payloads only; canonical-addressed requests never carry it; error responses never carry it. `GET /modules/{id}` gained the member this slice (deliberate additive change to a ratified payload — non-breaking).

## Current Verified Facts

Quick-reference ground truth, folded in when the standing verified-facts sheet was retired (v3.2). **Provenance rule:** values here are read from implementation behavior, not docblocks — docblocks are NOT ground truth (a docblock-derived error once slipped through and was corrected against behavior). On any disagreement between a session report and these facts, re-verify against code; these facts win by extraction method, not age. The numbered sections above (especially §16–§16.3) hold the detailed contract prose; this section is the fast lookup. Keep it current in the same commit that changes the behavior it describes.

### Plugin identity
- Version constant: `WPT_VERSION = '1.1.0-session5p2.3'` (wptransformed.php)
- Plugin root: `wptransformed/` — `includes/` (core classes), `modules/{category}/` (9 categories: admin-interface, content-management, custom-code, disable-components, login-logout, performance, security, utilities + index)

### Module registry (includes/class-module-registry.php)
- Definitions: **83** total = 77 `implemented` + 6 `stub`. Status enum on disk: `implemented` (default via `DEFAULTS`) | `stub`. **No `deferred` status exists.**
- First definition: `admin-bar-manager` (registry order is meaningful for "first" assertions).
- Defaults merged via `Module_Registry::normalize()`: tier=core, risk=safe, status=implemented, default_enabled=false, has_settings=true, legacy_ids=[], app_page=null, capability=null, search_terms=[], dependencies=[], has_cleanup=false.
- Pro-tier modules (**7**; `Core::is_pro_licensed()` is hardcoded false in v1, so all 7 are pro_locked): white-label, client-dashboard, terms-order, two-factor-authentication, temporary-user-access, email-log, error-log-viewer.
- Stub modules (**6**): media-library-pro, login-protection, strong-passwords, login-notifications, disable-frontend, disable-backend. Some stub files are absent on disk (e.g. modules/security/class-login-security-pro.php); `Core::is_loadable()` blocks all non-implemented status before include, so no fatal.

### Legacy alias map (complete — 10 pairs, all kebab-case)
- admin-bar → admin-bar-manager
- enhance-list-tables → list-table-enhancements
- auto-publish-missed → auto-publish-missed-schedule
- bulk-edit-posts → bulk-content-editor
- database-cleanup → database-optimizer
- login-security → login-protection  ⚠ canonical target is a STUB
- two-factor-auth → two-factor-authentication  ⚠ canonical target is PRO
- user-role-editor → role-manager
- login-branding → login-designer
- email-smtp → email-delivery

All aliases match the pinned REST id regex. **Constraint going forward: new aliases must remain `^[a-z0-9]+(?:-[a-z0-9]+)*$` (no underscores/uppercase) or they will 404 at the routing layer.**

### Canonicalization
- `Core::get_definition( string $id )` resolves canonical IDs and legacy aliases via `runtime_index`; returns null for unknown. Returned `$def['id']` is always canonical. All persistence writes use canonical IDs only (F1 fix, commit 257ea19).
- Known quirk: title strings can differ from canonical id (`database-optimizer` → title "Database Cleanup"). Do not infer ids from titles.

### Capabilities (Permission_Manager::CAPS — exactly 15)
manage_wpt, manage_wpt_modules, manage_wpt_settings, manage_wpt_client_safe, manage_wpt_security, manage_wpt_email, manage_wpt_search_visibility, manage_wpt_code, manage_wpt_database, manage_wpt_reports, manage_wpt_integrations, view_wpt_logs, export_wpt_data, run_wpt_dangerous_tools, manage_wpt_white_label.
- Constants: CAP_MANAGE=manage_wpt, CAP_MODULES=manage_wpt_modules, CAP_SETTINGS, CAP_DANGEROUS, CAP_DATABASE, CAP_LOGS, CAP_EXPORT, CAP_CODE, CAP_SECURITY, CAP_EMAIL (see class for full constant list).
- Per-module gate: `Permission_Manager::user_can_manage_module( $module_id, $user_id )` = CAP_MODULES check wrapped in public filter `wpt_user_can_manage_module( $can, $user_id, $module_id )`.
- ✓ CLOSED (8176100): ajax filter drift fixed — ajax_toggle_module now canonicalizes before the per-module filter; check order is nonce → empty → definition lookup → canonicalize → filter → pro → status → toggle. Filter receives canonical ids on all surfaces that apply it.

### REST surface (includes/class-rest-controller.php — class `WPTransformed\Core\Rest_Controller`)
- Namespace: `wpt/v1`. Bootstrapped via `Rest_Controller::init()` from wptransformed.php; routes registered on `rest_api_init`.
- Routes: GET /system/status (manage_wpt) · GET /modules (manage_wpt_modules) · GET /modules/{id} (manage_wpt_modules) · GET /capabilities (manage_wpt) · POST /modules/{id}/toggle (manage_wpt_modules + per-module filter) · GET+POST /modules/{id}/settings (manage_wpt_settings) · POST /modules/{id}/actions/{duplicate,send-test-email}.
- {id} regex: route `(?P<id>[a-z0-9-]+)`, args pattern `^[a-z0-9]+(?:-[a-z0-9]+)*$`, sanitize_key.
- Envelope contract (PERMANENT, documented in controller docblock):
  - Success: WP_REST_Response, payload is the body directly — NO custom envelope.
  - Handler/permission errors: WP_Error → standard WP REST shape {code, message, data:{status}}.
  - Validation errors (args schema): WP core native shapes (rest_missing_callback_param / rest_invalid_param). Three sanctioned origins, no global interception.
- Auth status: 401 logged-out / 403 authenticated-without-cap via rest_authorization_required_code().
- Module payload fields: id, title, description, category, tier, risk, status, default_enabled, has_settings, app_page, dependencies, search_terms, legacy_ids, active, pro_locked. Private (never exposed): file, class, capability, has_cleanup.
- Full stable error codes (code · status · message):
  - wpt_forbidden · 401 logged-out / 403 capless · "Sorry, you are not allowed to do that." (object-level variant: "...manage this module.")
  - wpt_invalid_module · 404 · "Unknown module."
  - wpt_pro_locked · 403 · "Pro license required."
  - wpt_module_stub · 400 · "This module is not yet implemented."
  - wpt_module_inactive · 409 · "Module is not active."
  - wpt_module_unavailable · 500 · "Module could not be loaded."
  - wpt_toggle_failed · 500 · "Failed to update module state."
  - wpt_settings_save_failed · 500 · "Failed to save module settings."
  - wpt_invalid_settings · 400 · "Secret settings values must be strings."
  - wpt_invalid_post · 404 · "Post not found."
  - wpt_post_type_not_enabled · 400 · "Duplication is not enabled for this post type."
  - wpt_duplicate_failed · 500 · "Failed to duplicate post." (+ data.reason)
  - wpt_invalid_recipient · 400 · "Please enter a valid email address."
  - wpt_email_send_failed · 502 · "Test email could not be sent." (+ data.debug)
  - WP-native arg-schema failures: rest_missing_callback_param / rest_invalid_param.

### Toggle hardening contracts (commit 8176100; originally committed as the standalone facts sheet at d0a34ea, since folded here)
- No-op suppression: Settings::toggle_module returns true early when cached is_active equals requested state — no DB write, no lifecycle hook. Hooks fire on real transitions only (docblock updated to match).
- No-op SAVE suppression (f40b9b4): Settings::save returns true early when a cache entry exists for the module AND wp_json_encode(incoming) is strictly identical to wp_json_encode(cached settings) — no DB write, no wpt_module_settings_saved hook. Encoding string comparison: key-order/type differences that change the encoding are real writes. No cache entry = never a no-op (first saves create the row). is_active handling and sanitization untouched. Closed the §16.1 settings-save deferral.
- New helper: `Settings::is_module_active( string $module_id ): bool` — cache-primed read; the single source of pre-toggle state. Do not use Core::$active_ids (boot snapshot) for pre-toggle reads.
- REST toggle response (PERMANENT): `{ "id", "active", "previous_active", "changed" }`. No-op = HTTP 200, changed false. Ajax response contracts unchanged and frozen.
- Checkpoint §16.1 records ratifications (Rest_Controller naming, set_module_active signature, args-schema validation origin), these contracts, and decided deferrals ($context hook param until audit-log consumes hooks).
- ✓ CLOSED (f40b9b4): the stale ajax_toggle_parent DOCBLOCK now describes the implemented per-sub pro_locked skip (batch continues) plus the stub per-sub skip; the whole-batch pre-write rejection claim is gone.

### Slice 10a settings-route contracts (0e88845; decision doc: docs/audits/slice-10a-decisions.md)
- validate/sanitize dual contract: `Module_Base::validate_settings( array ): array` — STORAGE shape in and out (whitelist to default keys, scalar type coercion, array-mismatch/missing-key/unsupported-default-type → default value; floor not ceiling — dangerous modules MUST override). sanitize_settings stays raw-form→storage for form paths, untouched. REST POST never calls sanitize_settings. First override: Database_Cleanup (strict CATEGORIES booleans, keep_recent_revisions absint clamp 0–100, optimize_tables bool, exactly 3 output keys).
- Routes: GET + POST /wpt/v1/modules/{id}/settings — permission manage_wpt_settings ONLY (decided parity with Admin::handle_save; NO per-module filter, NO def['capability'] — revisit at 10c). Pinned id regex/args pattern; alias-addressed responses carry canonicalized_from, payload id always canonical.
- Gate ladder (both methods, toggle order): unknown → wpt_invalid_module 404 · pro → wpt_pro_locked 403 (no file load) · stub → wpt_module_stub 400 (no file load) · load_module null → wpt_module_unavailable 500 "Module could not be loaded.".
- GET 200: { id, settings: get_settings() defaults-merged, defaults: get_default_settings() }. POST body { settings: object } required via args schema (WP-native rejection shapes); FULL REPLACE — row becomes exactly validate_settings(body.settings); 200 { id, settings: get_settings() after save, changed }; identical save = storage no-op, changed false; save failure → wpt_settings_save_failed 500.
- Zero-load amendment (PERMANENT): settings routes are the sanctioned single-module lazy-load exception via Core::load_module (never init()); /modules and /modules/{id} stay strictly no-load.

### Slice 10b contracts (5eb764c; decision doc: docs/audits/slice-10b-decisions.md; live-verified on wpt-dev)
- Secret settings (PERMANENT; amends 10a full-replace for declared secrets only): `Module_Base::get_secret_settings_keys()` default [] — Email_SMTP declares ['password']; the CONTROLLER applies policy. GET masks declared keys ('' empty, else literal `__WPT_SECRET__`; defaults member too) — stored ciphertext/plaintext never leaves over REST, on GET AND POST responses. POST resolves before validate_settings: absent/sentinel → stored value spliced unchanged; '' clears; other strings = new input; non-string → wpt_invalid_settings 400. changed computed post-resolution (untouched round-trip = clean no-op). Literal secret value `__WPT_SECRET__` unsupported by design (reserved token).
- Email_SMTP::validate_settings password rule is by IDENTITY, not prefix: input identical to stored passes byte-unchanged; '' clears; ANY other string (incl. non-matching enc1:-prefixed) is encrypted via encrypt_password() — ciphertext injection closed. Legacy plaintext stores splice unchanged under the sentinel; they upgrade to enc1: only on a new submission.
- Action routes (PERMANENT shape): POST /wpt/v1/modules/{id}/actions/{action}, both args pinned to `^[a-z0-9]+(?:-[a-z0-9]+)*$` + sanitize_key; explicit per-action registrations (no dispatcher until a 3rd action — logged gap). Ladder: unknown 404 · pro 403 · stub 400 · NOT ACTIVE → wpt_module_inactive 409 · active instance null → wpt_module_unavailable 500. Handlers use the boot-loaded ACTIVE instance, never load_module(). Two-tier permissions: coarse cap in permission_callback (duplicate: edit_posts; send-test-email: manage_wpt_email), object-level checks in the handler (wpt_forbidden 403). Wrong-module-for-action → wpt_invalid_module 404 (closed with an existing code). Actions fire ZERO lifecycle hooks.
- Concrete actions: POST /modules/content-duplication/actions/duplicate (body post_id int≥1; ladder → edit_post object check → wpt_invalid_post 404 (missing/revision/attachment) → wpt_post_type_not_enabled 400 → 200 {source_id, new_id, new_status, edit_link}; failure wpt_duplicate_failed 500 + data.reason). POST /modules/email-delivery/actions/send-test-email (body recipient string; is_email → wpt_invalid_recipient 400; 200 {sent: true, recipient, debug}; failure wpt_email_send_failed 502 + data.debug). NOTE: content-duplication has NO legacy alias.
- Shared methods are the SOLE implementations (greps verified): Content_Duplication::duplicate_post(int): int|WP_Error — PURE re UI, wp_insert_post only there; Email_SMTP::send_test_email(string): {sent: bool, debug: string} — never WP_Error/throws, wp_mail only there. Legacy admin_action/ajax surfaces delegate, byte-unchanged contracts.
- canonicalized_from (ratified convention, corrects 10a's "meta" wording): top-level member on alias-addressed SUCCESS payloads of every module-addressed route; never on canonical requests; never on errors.

### Toggle architecture
- Shared method (as built): `Core::set_module_active( string $id, bool $active ): bool` — caller validates + canonicalizes first; method persists via Settings::toggle_module and runs $module->deactivate() cleanup on disable. NOT the v2-spec'd (input_id, active, source): array|WP_Error signature.
- Toggle surfaces: wpt/v1 REST · wp_ajax_wpt_toggle_module · wp_ajax_wpt_toggle_parent (batch: per-sub pro_locked skips with error entries, batch continues; also skips stub subs on enable) · setup wizard (calls Settings::toggle_module directly, enable-only, validates ids against canonical registry keys so aliases are dropped not resolved; since 8176100 also filters to status=implemented).
- wp_ajax_wpt_toggle_parent applies NO per-module wpt_user_can_manage_module filter (deliberate, commented in code); gates on manage_wpt_modules only. Recorded in §16.1 gaps.
- Existing ajax contracts (unchanged, preserved): success {"success":true,"data":{"active":bool}}; errors via wp_send_json_error strings ("Missing module ID", "Unauthorized" 403, "Unknown module", "Pro license required", "Failed to update", "Module not yet implemented").

### Lifecycle hooks (live in shared storage layer — includes/class-settings.php)
- `do_action( 'wpt_module_enabled'|'wpt_module_disabled', $module_id )` in Settings::toggle_module — fires AFTER successful $wpdb->replace only; canonical id only (guaranteed by callers); fires on real transitions only (no-op suppression added in 8176100) — single param, NO $context.
- `do_action( 'wpt_module_settings_saved', $module_id, $settings )` in Settings::save — after persistence, canonical id; no-op saves suppressed (f40b9b4).
- Failed persistence fires nothing.

### Storage
- Table: `{$wpdb->prefix}wpt_settings` — columns module_id, is_active, settings (JSON). Writes via $wpdb->replace (delete+insert semantics). Settings survive toggles (cache primed before write).

### Zero-load contract
- Loader gates (Core::is_loadable): status must be 'implemented'; pro requires license; quarantine list respected. Read routes render from definitions only.
- Probe-ready class names: WPTransformed\Modules\AdminInterface\White_Label (pro-locked), WPTransformed\Modules\ContentManagement\Public_Preview (implemented, inactive by default). Class name pattern: WPTransformed\Modules\{CategoryPascal}\{File_Pascal} derived from modules/{category}/class-{name}.php.

### Environment
- Live verification: wpt-dev on Laragon, http://localhost/wpt-dev, real WP boot via wp-load.php. MySQL/Apache may need headless start at session open.
- Harness files: tests/harness-rest.php, tests/harness-zero-load.php (in repo, not in plugin-only zips).
- This document is the single authoritative checkpoint (§16 = REST envelope + routes); these facts are its quick-reference view.
