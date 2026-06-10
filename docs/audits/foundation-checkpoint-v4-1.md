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

## 7. Lifecycle Hook Gaps

**Zero `do_action( 'wpt_*' )` calls exist.** Missing: `wpt_module_enabled` / `wpt_module_disabled` / `wpt_module_quarantined`, `wpt_module_settings_saved`, `wpt_safe_mode_triggered`, `wpt_settings_exported` / `wpt_settings_imported`, `wpt_loaded`. Only 3 `wpt_*` filters exist (`wpt_registered_modules` with the new definition payload, `wpt_command_palette_actions`, `wpt_known_plugin_sections`) plus `wpt_user_can_manage_module`. Recovery Center / Conflict Detector / Audit Log integration still have nothing to subscribe to — good candidate to land alongside the REST skeleton's enable/disable routes.

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

1. **Live verification pass on wpt-dev** (start Laragon: real boot exercises caps + slug migrations; click through Modules page, toggles, app pages, Safe Mode URL) — cheap, de-risks everything above.
2. **REST skeleton** (reframe step 9): `wpt/v1` namespace, shared response/error format, `Permission_Manager` callbacks, the §8 early routes — and land the lifecycle hooks (`wpt_module_enabled/disabled`, `wpt_module_settings_saved`) inside the enable/disable/settings routes.
3. **Product-proof vertical slice** (step 10).
4. **Conflict Detector** (step 11) — definitions now provide the metadata it reasons over.
5. **Import/export rebuild + uninstall retention matrix** (step 12) — fixes risk #2.
6. **Surface remediation** (step 13): dashboard/modules split, fake metrics, self-hosted assets, `wpt_theme_mode` migration, mobile chrome fix.
