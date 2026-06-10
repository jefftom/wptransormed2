# WPTransformed — Verified Facts Sheet

Source of truth for build prompts and reviews. Extracted directly from the committed codebase at `1.1.0-session5p2.3` (commit 1148452, REST Skeleton v1 landed). Every value below was read from the repo, not recalled. Refresh this file when foundations change; until then, prompts cite this sheet instead of asking or guessing.

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
- ⚠ Known drift (open): ajax_toggle_module passes the RAW (possibly alias) id to this filter before canonicalization; REST passes canonical. Fix pending.

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
- ⚠ Known gap (open): toggle does NOT reject stub-status modules; persists inert active state. Pro rejected, unknown 404'd, stub allowed.

## Toggle architecture
- Shared method (as built): `Core::set_module_active( string $id, bool $active ): bool` — caller validates + canonicalizes first; method persists via Settings::toggle_module and runs $module->deactivate() cleanup on disable. NOT the v2-spec'd (input_id, active, source): array|WP_Error signature.
- Toggle surfaces: wpt/v1 REST · wp_ajax_wpt_toggle_module · wp_ajax_wpt_toggle_parent (batch; Pro sub-module anywhere rejects whole batch pre-write) · setup wizard (calls Settings::toggle_module directly, enable-only, validates ids against canonical registry keys so aliases are dropped not resolved).
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
