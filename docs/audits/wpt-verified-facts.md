# WPTransformed — Verified Facts Sheet

Source of truth for build prompts and reviews. v1.2 — independently extracted from the committed codebase (1148452, REST Skeleton v1), updated for toggle hardening (8176100) and slice 10a settings routes (this commit). Provenance rule: facts here are read from implementation behavior unless marked **[doc-derived]**; docblocks are NOT ground truth (v1.0 carried one docblock-derived error on batch pro semantics, caught against behavior and corrected below). Refresh when foundations change; until then, prompts cite this sheet instead of asking or guessing. If a session report and this sheet disagree, re-verify against code — this sheet is the tiebreaker only because of its extraction method, not its age.

## Plugin identity
- Version constant: `WPT_VERSION = '1.1.0-session5p2.3'` (wptransformed.php)
- Plugin root: `wptransformed/` — `includes/` (core classes), `modules/{category}/` (9 categories: admin-interface, content-management, custom-code, disable-components, login-logout, performance, security, utilities + index)

## Module registry (includes/class-module-registry.php)
- Definitions: **83** total = 77 `implemented` + 6 `stub`. Status enum on disk: `implemented` (default via `DEFAULTS`) | `stub`. **No `deferred` status exists.**
- First definition: `admin-bar-manager` (registry order is meaningful for "first" assertions).
- Defaults merged via `Module_Registry::normalize()`: tier=core, risk=safe, status=implemented, default_enabled=false, has_settings=true, legacy_ids=[], app_page=null, capability=null, search_terms=[], dependencies=[], has_cleanup=false.
- Pro-tier modules (**7**; `Core::is_pro_licensed()` is hardcoded false in v1, so all 7 are pro_locked): white-label, client-dashboard, terms-order, two-factor-authentication, temporary-user-access, email-log, error-log-viewer.
- Stub modules (**6**): media-library-pro, login-protection, strong-passwords, login-notifications, disable-frontend, disable-backend. Some stub files are absent on disk (e.g. modules/security/class-login-security-pro.php); `Core::is_loadable()` blocks all non-implemented status before include, so no fatal.

## Legacy alias map (complete — 10 pairs, all kebab-case)
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

## Canonicalization
- `Core::get_definition( string $id )` resolves canonical IDs and legacy aliases via `runtime_index`; returns null for unknown. Returned `$def['id']` is always canonical. All persistence writes use canonical IDs only (F1 fix, commit 257ea19).
- Known quirk: title strings can differ from canonical id (`database-optimizer` → title "Database Cleanup"). Do not infer ids from titles.

## Capabilities (Permission_Manager::CAPS — exactly 15)
manage_wpt, manage_wpt_modules, manage_wpt_settings, manage_wpt_client_safe, manage_wpt_security, manage_wpt_email, manage_wpt_search_visibility, manage_wpt_code, manage_wpt_database, manage_wpt_reports, manage_wpt_integrations, view_wpt_logs, export_wpt_data, run_wpt_dangerous_tools, manage_wpt_white_label.
- Constants: CAP_MANAGE=manage_wpt, CAP_MODULES=manage_wpt_modules, CAP_SETTINGS, CAP_DANGEROUS, CAP_DATABASE, CAP_LOGS, CAP_EXPORT, CAP_CODE, CAP_SECURITY, CAP_EMAIL (see class for full constant list).
- Per-module gate: `Permission_Manager::user_can_manage_module( $module_id, $user_id )` = CAP_MODULES check wrapped in public filter `wpt_user_can_manage_module( $can, $user_id, $module_id )`.
- ✓ CLOSED (8176100): ajax filter drift fixed — ajax_toggle_module now canonicalizes before the per-module filter; check order is nonce → empty → definition lookup → canonicalize → filter → pro → status → toggle. Filter receives canonical ids on all surfaces that apply it.

## REST surface (includes/class-rest-controller.php — class `WPTransformed\Core\Rest_Controller`)
- Namespace: `wpt/v1`. Bootstrapped via `Rest_Controller::init()` from wptransformed.php; routes registered on `rest_api_init`.
- Routes: GET /system/status (manage_wpt) · GET /modules (manage_wpt_modules) · GET /modules/{id} (manage_wpt_modules) · GET /capabilities (manage_wpt) · POST /modules/{id}/toggle (manage_wpt_modules + per-module filter).
- {id} regex: route `(?P<id>[a-z0-9-]+)`, args pattern `^[a-z0-9]+(?:-[a-z0-9]+)*$`, sanitize_key.
- Envelope contract (PERMANENT, documented in controller docblock):
  - Success: WP_REST_Response, payload is the body directly — NO custom envelope.
  - Handler/permission errors: WP_Error → standard WP REST shape {code, message, data:{status}}.
  - Validation errors (toggle `active` arg uses args schema, type boolean, required): WP core native shapes (rest_missing_callback_param / rest_invalid_param). Three sanctioned origins, no global interception.
- Stable error codes: wpt_forbidden ("Sorry, you are not allowed to do that." / "...manage this module."), wpt_invalid_module ("Unknown module.", 404), wpt_pro_locked ("Pro license required.", 403), wpt_toggle_failed ("Failed to update module state.", 500).
- Auth status: 401 logged-out / 403 authenticated-without-cap via rest_authorization_required_code().
- Module payload fields: id, title, description, category, tier, risk, status, default_enabled, has_settings, app_page, dependencies, search_terms, legacy_ids, active, pro_locked. Private (never exposed): file, class, capability, has_cleanup.
- ✓ CLOSED (8176100): stub gating added. Stub ENABLE rejects — REST: wpt_module_stub, "This module is not yet implemented.", 400 (after pro gate, via shared error helper); ajax single: wp_send_json_error('Module not yet implemented'); ajax parent batch: per-sub skip with error 'not implemented', counted in failed, batch continues; wizard: pre-loop filter to status=implemented. Stub DISABLE is allowed (cleans up pre-fix inert active rows). Unknown→404 and pro→wpt_pro_locked unchanged.

## Toggle hardening contracts (commit 8176100; facts sheet committed separately as d0a34ea)
- No-op suppression: Settings::toggle_module returns true early when cached is_active equals requested state — no DB write, no lifecycle hook. Hooks fire on real transitions only (docblock updated to match).
- No-op SAVE suppression (this commit — fix(core): suppress no-op settings saves): Settings::save returns true early when a cache entry exists for the module AND wp_json_encode(incoming) is strictly identical to wp_json_encode(cached settings) — no DB write, no wpt_module_settings_saved hook. Encoding string comparison: key-order/type differences that change the encoding are real writes. No cache entry = never a no-op (first saves create the row). is_active handling and sanitization untouched. Closes the §16.1 settings-save deferral.
- New helper: `Settings::is_module_active( string $module_id ): bool` — cache-primed read; the single source of pre-toggle state. Do not use Core::$active_ids (boot snapshot) for pre-toggle reads.
- REST toggle response (PERMANENT): `{ "id", "active", "previous_active", "changed" }`. No-op = HTTP 200, changed false. Ajax response contracts unchanged and frozen.
- Stable REST error codes now: wpt_forbidden, wpt_invalid_module, wpt_pro_locked, wpt_toggle_failed, wpt_module_stub.
- Checkpoint §16.1 records ratifications (Rest_Controller naming, set_module_active signature, args-schema validation origin), these contracts, and decided deferrals ($context hook param until audit-log consumes hooks; settings-save no-op semantics undecided).
- Harness: tests/harness-rest.php at 28 assertions post-hardening.
- ✓ CLOSED (f40b9b4 — fix(core): suppress no-op settings saves): the stale ajax_toggle_parent DOCBLOCK now describes the implemented per-sub pro_locked skip (batch continues) plus the stub per-sub skip; the whole-batch pre-write rejection claim is gone.

## Slice 10a settings-route contracts (this commit; decision doc: docs/audits/slice-10a-decisions.md)
- validate/sanitize dual contract: `Module_Base::validate_settings( array ): array` — STORAGE shape in and out (whitelist to default keys, scalar type coercion, array-mismatch/missing-key/unsupported-default-type → default value; floor not ceiling — dangerous modules MUST override). sanitize_settings stays raw-form→storage for form paths, untouched. REST POST never calls sanitize_settings. First override: Database_Cleanup (strict CATEGORIES booleans, keep_recent_revisions absint clamp 0–100, optimize_tables bool, exactly 3 output keys).
- Routes: GET + POST /wpt/v1/modules/{id}/settings — permission manage_wpt_settings ONLY (decided parity with Admin::handle_save; NO per-module filter, NO def['capability'] — revisit at 10c). Pinned id regex/args pattern; alias-addressed responses carry canonicalized_from, payload id always canonical.
- Gate ladder (both methods, toggle order): unknown → wpt_invalid_module 404 · pro → wpt_pro_locked 403 (no file load) · stub → wpt_module_stub 400 (no file load) · load_module null → wpt_module_unavailable 500 "Module could not be loaded.".
- GET 200: { id, settings: get_settings() defaults-merged, defaults: get_default_settings() }. POST body { settings: object } required via args schema (WP-native rejection shapes); FULL REPLACE — row becomes exactly validate_settings(body.settings); 200 { id, settings: get_settings() after save, changed }; identical save = storage no-op, changed false; save failure → wpt_settings_save_failed 500.
- Zero-load amendment (PERMANENT): settings routes are the sanctioned single-module lazy-load exception via Core::load_module (never init()); /modules and /modules/{id} stay strictly no-load.
- Stable REST error codes now: wpt_forbidden, wpt_invalid_module, wpt_pro_locked, wpt_module_stub, wpt_module_unavailable, wpt_toggle_failed, wpt_settings_save_failed.

## Toggle architecture
- Shared method (as built): `Core::set_module_active( string $id, bool $active ): bool` — caller validates + canonicalizes first; method persists via Settings::toggle_module and runs $module->deactivate() cleanup on disable. NOT the v2-spec'd (input_id, active, source): array|WP_Error signature.
- Toggle surfaces: wpt/v1 REST · wp_ajax_wpt_toggle_module · wp_ajax_wpt_toggle_parent (batch: per-sub pro_locked skips with error entries, batch continues — the class-admin.php method DOCBLOCK claiming whole-batch pre-write rejection is STALE and pending correction) · setup wizard (calls Settings::toggle_module directly, enable-only, validates ids against canonical registry keys so aliases are dropped not resolved; since 8176100 also filters to status=implemented).
- wp_ajax_wpt_toggle_parent applies NO per-module wpt_user_can_manage_module filter (deliberate, commented in code); gates on manage_wpt_modules only. Recorded in checkpoint §16.1 gaps.
- Existing ajax contracts (unchanged, preserved): success {"success":true,"data":{"active":bool}}; errors via wp_send_json_error strings ("Missing module ID", "Unauthorized" 403, "Unknown module", "Pro license required", "Failed to update").

## Lifecycle hooks (live in shared storage layer — includes/class-settings.php)
- `do_action( 'wpt_module_enabled'|'wpt_module_disabled', $module_id )` in Settings::toggle_module — fires AFTER successful $wpdb->replace only; canonical id only (guaranteed by callers); **fires on re-assert writes too (no no-op suppression)** — single param, NO $context.
- `do_action( 'wpt_module_settings_saved', $module_id, $settings )` in Settings::save — after persistence, sanitized settings, canonical id.
- Failed persistence fires nothing.

## Storage
- Table: `{$wpdb->prefix}wpt_settings` — columns module_id, is_active, settings (JSON). Writes via $wpdb->replace (delete+insert semantics). Settings survive toggles (cache primed before write).

## Zero-load contract
- Loader gates (Core::is_loadable): status must be 'implemented'; pro requires license; quarantine list respected. Read routes render from definitions only.
- Probe-ready class names: WPTransformed\Modules\AdminInterface\White_Label (pro-locked), WPTransformed\Modules\ContentManagement\Public_Preview (implemented, inactive by default). Class name pattern: WPTransformed\Modules\{CategoryPascal}\{File_Pascal} derived from modules/{category}/class-{name}.php.

## Environment
- Live verification: wpt-dev on Laragon, http://localhost/wpt-dev, real WP boot via wp-load.php. MySQL/Apache may need headless start at session open.
- Harness files: tests/harness-rest.php, tests/harness-zero-load.php (in repo, not in plugin-only zips).
- docs/audits/foundation-checkpoint-v4-1.md is the authoritative checkpoint (§16 = REST envelope + routes).
