# Slice 10a Decision Document — Module Settings Routes + validate_settings

Input contract for the 10a build prompt. Every decision below is final unless Jeff reopens it; the build prompt implements these exactly. All cited behavior was verified directly against the codebase at f40b9b4. Companion ground truth: docs/audits/wpt-verified-facts.md (v1.1+).

## Scope

IN: (1) Module_Base::validate_settings contract + Database_Cleanup implementation; (2) GET and POST /wpt/v1/modules/{id}/settings; (3) the Database Optimizer settings loop proof; (4) checkpoint/facts-sheet updates including the §8 divergence note.
OUT (explicitly, log where noted): REST-ifying wpt_db_cleanup_scan/run ajax (compliant per reframe §7; migrates when the DBO screen is rebuilt); any app-page UI changes; import/export changes — import keeps its known sanitize-shape caveat until slice 12, where the fix is now a one-line swap to validate_settings (log in checkpoint); the remaining §8 routes (safe-mode-url, import/export); slices 10b–10d surfaces; $context hook param (deferral unchanged).

## Background fact (drives the central decision)

sanitize_settings() across all modules takes RAW FORM input (keys like wpt_items_to_clean) and returns STORAGE shape (keys like items_to_clean) — verified in Module_Base (default returns []), Database_Cleanup, Disable_Comments, and the generic form handler Admin::handle_save (passes $_POST). Feeding it storage-shaped data silently returns defaults — the import bug in reframe §1. A storage-shape REST POST must therefore NOT call sanitize_settings.

## Contract 1 — Module_Base::validate_settings (PERMANENT)

`public function validate_settings( array $settings ): array` — input and output are both STORAGE shape. Distinct from sanitize_settings (form shape → storage shape), which is unchanged and stays serving the existing form paths.

Safe base implementation (Module_Base):
- Whitelist input keys to get_default_settings() keys; unknown keys are dropped.
- Per key, enforce the PHP type of the default value: scalars are coerced (bool/int/float/string casts); if the default is an array and the input isn't (or vice versa), fall back to the default value for that key.
- Missing keys fall back to the default value (output always contains exactly the default-settings keys).
- Modules whose settings can enable dangerous behavior MUST override with real validation; the base whitelist+type rule is the floor, not the ceiling. Document this in the method docblock with the import-security rationale (e.g. PHP snippet enabling).

Database_Cleanup::validate_settings (10a's one concrete override):
- items_to_clean: object of booleans keyed strictly to self::CATEGORIES — every category present, non-listed keys dropped, values cast bool.
- keep_recent_revisions: absint, clamped 0–100.
- optimize_tables: cast bool.
Output exactly { items_to_clean, keep_recent_revisions, optimize_tables }.

## Contract 2 — GET /wpt/v1/modules/{id}/settings

- Permission: manage_wpt_settings (Permission_Manager::CAP_SETTINGS) — parity with the existing Admin::handle_save form path, which checks CAP_SETTINGS only. Deliberately NO per-module def['capability'] check and NO wpt_user_can_manage_module filter on settings routes, because the existing settings-save surface applies neither; def['capability'] currently gates app pages, not settings writes. Record this as a named, decided parity choice AND as an open policy question in the checkpoint gaps ("should settings routes honor def capability? revisit at 10c").
- {id}: same pinned regex as existing routes; canonical or alias accepted; alias responses include meta canonicalized_from, payload id always canonical (skeleton convention).
- Gating ladder, in order (mirrors toggle): unknown definition → wpt_invalid_module 404 · pro and unlicensed → wpt_pro_locked 403 (file must not load) · status !== implemented → wpt_module_stub 400 · load_module() returns null after passing those gates → wpt_module_unavailable 500 ("Module could not be loaded.") — new stable code, add to checkpoint §16 code list.
- Success 200 payload: { "id": <canonical>, "settings": <get_settings() output — defaults-merged storage shape>, "defaults": <get_default_settings()> }.
- Zero-load amendment (PERMANENT): settings routes are the sanctioned single-module lazy-load exception — at most the target module's file is included, via Core::load_module(), which never calls init() (no hooks registered; verified docblock + implementation). /modules and /modules/{id} read routes stay strictly no-load. Pro/stub gating above guarantees their files still never load.

## Contract 3 — POST /wpt/v1/modules/{id}/settings

- Permission, {id} handling, gating ladder: identical to GET.
- Request body: { "settings": { ...storage shape... } } — the settings member is required and must be an object; validate via route args schema (missing/invalid → WP-native shapes, the ratified third response origin).
- Semantics: FULL REPLACE. The stored row becomes exactly validate_settings(body.settings) — not a merge into existing saved values. (Reads stay defaults-merged via get_settings(), so omitted keys behave as defaults; this matches how complete-form saves already work.)
- Flow: gate ladder → load_module → $validated = $module->validate_settings( $body ) → $pre = current stored encoding → Settings::save( canonical, $validated ) → respond.
- Success 200 payload: { "id": <canonical>, "settings": <the persisted, defaults-merged result of get_settings() after save>, "changed": <bool> }. changed = whether the persisted encoding differs from $pre; an identical save is a no-op at the storage layer (no write, no wpt_module_settings_saved — already built in f40b9b4) and returns changed false.
- Save failure (Settings::save false) → wpt_settings_save_failed 500 ("Failed to save module settings.") — new stable code, add to checkpoint.

## Loop proof (10a's definition of done — not just route tests)

Database Optimizer end-to-end on live wpt-dev: POST a settings change via REST (e.g. flip one items_to_clean category and set keep_recent_revisions to a distinct value) → GET returns it → the wpt-database app page server-render reflects the stored values (the app reads get_settings(); verify by fetching the page HTML or via the app's render data path) → the existing dry-run scan path executes against the live DB consistently with those settings → wpt_module_settings_saved observed firing exactly once for the change and zero times for an identical re-POST. Restore original values after, DB hash back to baseline.

## Documentation requirements (same commit)

- Checkpoint §16: add the two routes to the table; add wpt_module_unavailable and wpt_settings_save_failed to the stable-code list; record the zero-load amendment; record the validate/sanitize dual-contract; record the §8 divergence note: "reframe §8 prescribed POST /enable + /disable; built and ratified as POST /toggle (§16.1) — supersedes §8."
- Checkpoint gaps: import still calls sanitize_settings with storage-shape data (known caveat; slice-12 fix is a one-line swap to validate_settings); def['capability'] not enforced on any settings surface (decided parity, revisit 10c); remaining §8 routes unbuilt.
- Facts sheet: add the settings-route contracts and validate_settings under the contracts section; bump to v1.2 in the same style (these amendments only; provenance header untouched).

## Verification standard (live wpt-dev, real WP boot, pasted output mandatory, claims without output do not count)

php -l all touched files. Harness additions covering: base validate_settings whitelist/coercion/array-mismatch/missing-key rules; DBO validate clamp and category strictness; both routes' full gate ladder (unknown 404, pro 403 with class_exists false after, stub 400 with no include, unavailable 500 if simulable); alias canonicalization with canonicalized_from; full-replace semantics; changed true/false; POST schema rejection shapes. Live matrix: GET+POST as admin (200), a user with manage_wpt_settings only if role fixtures allow (200 — else note), capless editor (403), unauthenticated (401). Zero-load: included-files delta for GET settings on an inactive implemented module is exactly 1 and names class-database-cleanup.php is NOT it (use a different inactive module for the delta probe, e.g. public-preview) — separately, DBO's file loads only when DBO settings routes are hit; White_Label class_exists stays false after a pro settings attempt. Hook listener (probe process only): fires once on change, silent on identical re-POST. Loop proof per section above. Regression: prior matrix green, ajax byte-identical, Safe Mode unchanged, zero-load harness green. Cleanup: settings restored, DB hash = baseline, throwaway users removed, tree clean, diff only intended files.

## Commit (single)

feat(core): add wpt/v1 module settings routes and validate_settings contract
