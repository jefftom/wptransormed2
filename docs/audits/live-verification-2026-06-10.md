# Live Verification Pass — wpt-dev (Laragon)

**Date:** 2026-06-10 · **Branch:** `v5-foundation-alignment` @ `73b934b` (clean, synced) · **Result: PASS** (4 non-blocking findings)

First real-WordPress run of everything since the quick-fix batch: permission model, Safe Mode bypass, boundary capabilities, the full 6-slice registry workstream, and both one-time migrations — all previously harness-verified only. Method: MySQL 8.4.3 + Apache 2.4.62/PHP 8.3.16 started headless; pre/post DB snapshots via mysql client; authenticated HTTP battery via throwaway `wpt-verify-admin`/`wpt-verify-editor` users (created for the pass, deleted after); live-code CLI probes via `wp-load.php`. Site URL is `http://localhost/wpt-dev` (the `wpt-dev.test` hosts entry exists but WP's siteurl is localhost-based).

## 1. Environment ✓
Branch `v5-foundation-alignment`, working tree clean, synced with origin. Plugin junction confirmed live: `wp-content/plugins/wptransformed → C:\dev\wptransormed2`. DB `wpt_dev`, prefix `wp_`.

## 2. First boot / migrations ✓
- First HTTP request: **200, no fatal/critical errors**, login renders.
- `wpt_caps_version` = 1; administrator role gained `manage_wpt` + `run_wpt_dangerous_tools` (was absent pre-boot).
- `wpt_slug_version` = 1; `wpt_slug_migration_v1_backup` written (timestamp, 10-pair map, **10 legacy rows touched**, `skipped_unknown` = `custom-code`, `forms` — the orphan rows of the two deleted stubs, correctly untouched).
- **DB before/after:** 85 rows / 9 active both sides. Active legacy ids `admin-bar`, `login-branding` became `admin-bar-manager`, `login-designer` with active state + settings preserved; all 10 legacy ids gone; zero duplicate legacy/canonical rows. Second request: gates idempotent.

## 3. Module Library ✓
Modules page (as verification admin): 200, 425 KB, welcome banner, **26/26 parent cards**, active count **9/9**, canonical ids only (`role-manager` etc. rendered; zero legacy ids in markup), `is-pro` locked card present, chrome assets enqueued. (`database-optimizer` has no sub-toggle by design — APP parent renders an app link instead of a sub-panel.)

**Toggles tested (AJAX, real nonce):** `duplicate-widget` off→on round-trip success with DB restored; parent batch `admin-bar-manager` off→on (3/3 subs both ways, state restored); Pro toggle `white-label` rejected "Pro license required"; unknown id rejected "Unknown module"; settings persistence confirmed via DB.

## 4. Zero-load sanity ✓
Inactive `disable-comments` settings page lazily renders its real form (lazy loader live); stub settings URL does not render a form; module toggling on/off through the zero-load lifecycle works (export module activated → its AJAX handlers registered on next request → deactivated again). File-include counts aren't measurable over HTTP; the committed harness covers that dimension (boot = allowed-active exactly).

## 5. App pages ✓ (with 2 noted findings)
| Page | As admin | As editor |
|---|---|---|
| wpt-database | 200, no fatal | 403 |
| wpt-audit-log | 200, no fatal | 403 |
| wpt-menu-editor | 200, no fatal | 403 |
| wpt-login-designer | 200, no fatal | 403 |
| wptransformed (Modules) | 200 | 403 |
| wpt-temporary-access | — | 403 |
| wpt-dashboard (Editor Dashboard) | 200 | **200, renders** (by design) |
| wpt-setup-wizard | **403 — see finding F2** | — |

Capability gates proven live: every WPT admin surface denies the editor; the editor dashboard remains editor-visible.

## 6. Safe Mode ✓ (all six cases)
| Case | Result |
|---|---|
| Admin, no token | Normal admin: chrome + `wpt-admin` body class present, no notice |
| Admin, wrong token | Normal admin, no notice |
| **Admin, valid token** | **Safe Mode: chrome gone, body class gone, plain notice only** |
| Admin, after leaving (no token) | Normal admin returns |
| Editor, valid token | NOT activated (admin capability required) |
| Logged out, valid token | 302 → wp-login |

## 7. Import/export smoke ✓ (caveat confirmed, scoped)
Export module activated/deactivated live through zero-load. A live import was deliberately NOT run — per the known caveat it would reset real module settings. Caveat proven with live code instead (read-only sanitize round-trip on saved settings): `hide-dashboard-widgets` **RESETS (caveat confirmed)**; `admin-bar-manager` round-trips safely — per-module severity, exactly as tracked for the import/export rebuild (reframe step 12).

## 8. Chrome / mobile ✓ desktop, gap confirmed mobile
Desktop admin loads with full chrome. The `.quicklinks`-hiding rule is still served in `admin-global.css` with no replacement toggle — the checkpoint's mobile-admin gap stands (not fixed in this pass by constraint).

## Findings (none blocking)
- **F1 — Legacy-id toggle writes a legacy row back.** `wpt_toggle_module` with `module_id=database-cleanup` succeeds (definition resolves the alias) but `Settings::toggle_module()` writes the RAW legacy id, re-creating a legacy row (observed live; probe row deleted). UI never sends legacy ids; boot ignores legacy rows. Fix: resolve to `$def['id']` before the Settings write (same canonicalization already applied to import). Fold into the REST skeleton's enable/disable work or a tiny pre-commit.
- **F2 — Setup Wizard unreachable when its module is inactive** (page registration lives in the module's `init()`). Pre-existing, not a zero-load regression — but on a fresh install all modules are off, so the activation→wizard flow cannot run. Onboarding gap for the vertical-slice work.
- **F3 — Stub/unknown settings-page redirect degrades**: `wp_safe_redirect` fires after admin-header output → partial 200 page instead of a redirect (`exit` still prevents any settings render). Move the guard to a `load-{hook}` callback during surface remediation.
- **F4 — Mobile chrome gap re-confirmed** (tracked, step 13).

## Cleanup
Verification users deleted (1 user remains: `admin`), probe row removed, temp files purged; final DB identical to pre-pass except the intended migrations (85 rows / 9 active). Laragon services left running.

## Recommended next task
**REST skeleton (reframe step 9)** — the foundation is now live-verified end to end. Include the F1 canonicalization in the enable/disable route work (or as a one-line pre-commit), and land the lifecycle hooks (`wpt_module_enabled/disabled`, `wpt_module_settings_saved`) inside those routes as planned.
