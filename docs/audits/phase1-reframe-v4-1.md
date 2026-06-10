# WPTransformed — Phase 1 Reframe v4.1

**Status:** adopted — execution addendum to `phase1-gap-analysis.md`. This file reorders and re-scopes the gap analysis build order for early product proof; it does NOT replace the gap analysis, which remains the findings/bugs/decisions record.  
**Created:** 2026-06-10  
**Applies to:** `v5-foundation-alignment` / `wptransformed-1.1.0-session5p2.3` and later  

## 0. Why this file exists

The quick-fix batch is complete and safe to keep. The next originally proposed task was the full Permission Model pass: create the `Permission_Manager`, grant the `wpt_*` capabilities, migrate activation/upgrade behavior, and swap a large number of `manage_options` call sites.

That work is necessary, but it is large enough to set the architecture for everything after it. Before making that mutation, Phase 1 should be reframed so it does not become an invisible architecture-only slog.

The new goal for Phase 1 is:

> Build the minimum safe platform, make it model-ready, then prove the product with one vertical slice.

## 1. Current state from the quick-fix batch

The latest reported quick-fix batch fixed:

1. Import fatal: `Core::get_instance()` → `Core::instance()`.
2. Unknown imported module IDs now skip instead of being written raw.
3. Settings cold-cache save/toggle no longer wipes active state or module settings.
4. Dead app-page links corrected for login protection and white label.
5. Bento Active Modules count now snapshots APP-parent contribution at load.
6. Activation → wizard → dashboard redirect revived.
7. Dark-mode AJAX now has a capability check with the nonce.

Known caveats still tracked:

- Import still resets known modules' setting values to defaults because of the `sanitize_settings()` shape mismatch.
- Dark-mode meta collision still needs the `wpt_theme_mode` migration.
- Full live boot needs Laragon/MySQL running for final verification.

## 2. Phase 1 non-negotiables that do not change

These are still required:

1. Continue from the existing repo; do not rebuild from scratch.
2. Style native WordPress admin; do not replace `#adminmenu` or `#wpadminbar`.
3. Global admin transformation must not globally hide/remove third-party menu pages.
4. Client-Safe Mode is the explicit layer for role/user restrictions.
5. Safe Mode must recover the native WordPress admin.
6. Inactive modules should have zero-load behavior.
7. Pro cards may render, but Pro files/hooks/routes must not load without Pro.
8. Do not build random modules from broad feature docs.
9. Do not implement a module without a per-module spec.
10. Search/SEO/schema output is detection-first.
11. Forms are a companion ecosystem integration, not a giant WPTransformed Core form-builder build right now.
12. No violet as primary visual language.
13. No CDN fonts/icons in production; bundle/self-host assets.
14. Avoid MySQL-native `JSON` columns unless specifically justified.

## 3. Current proposed build order from the gap analysis

The current build order after the quick-fix batch was:

1. Quick-fix batch.
2. Permission Model.
3. Registry definition arrays + zero-load + canonical slug renames + hierarchy regeneration.
4. `wpt/v1` REST layer + lifecycle hooks + AJAX migration.
5. Recovery Center.
6. Conflict Detector.
7. Import/export rebuild + uninstall retention matrix.
8. Surface remediation: dashboard/page split, fake metrics removal, fonts, theme migration, mobile fixes.

This order is mostly correct, but it risks delaying visible product proof too long.

## 4. Revised Phase 1 build order

### 1. Phase 1 Reframe

Create this file and commit it before the next architecture mutation.

### 2. Safe Mode chrome bypass

Implement the hard recovery behavior first.

Acceptance criteria:

- Safe Mode requires a valid token and logged-in admin.
- When Safe Mode is active:
  - no WPTransformed admin chrome loads;
  - no WPTransformed global CSS loads;
  - no WPTransformed global JS loads;
  - no sidebar/topbar/search/upgrade/avatar DOM injections run;
  - no modules load;
  - native WordPress admin remains usable.
- Show only a plain WordPress admin notice explaining Safe Mode is active.
- Do not build the full Recovery Center UI yet.
- Commit separately.

Reason: if admin chrome breaks the site, Safe Mode must still recover the native admin.

### 3. Permission Model kernel

Implement or verify the `Permission_Manager` as a kernel before broad call-site migration.

Acceptance criteria:

- Define the canonical `wpt_*` capabilities.
- Grant all canonical capabilities to Administrators on activation/upgrade.
- Do not grant capabilities to client/editor roles by default.
- Add helper methods for common checks.
- Add tests or runtime harness where possible.
- Do not blindly rewrite every `manage_options` use in one sweep.

### 4. Boundary permission migration

Migrate boundary checks first:

- WPTransformed admin page registration.
- AJAX handlers.
- REST permission callbacks as they are added.
- module enable/disable.
- settings save.
- import/export.
- dangerous actions.
- admin app pages.

Internal call sites can migrate as files/modules are touched.

### 5. Registry definition arrays

Create definition arrays/manifests so the Module Library can render metadata without loading module code.

Acceptance criteria:

- Module metadata is available without including module files.
- Metadata includes: id, title, category, tier, risk, status, default enabled, search terms, replaces, settings/app page flags, file path, class, capabilities.
- No runtime hooks or business logic live in definition arrays.
- Pro module definitions can display locked cards without loading Pro code.

### 6. Zero-load module loader

Refactor loader so inactive modules do not load files/hooks/assets.

Acceptance criteria:

- Inactive module files are not required/included.
- Pro module files do not load in Core/free installs.
- Quarantined modules do not load.
- Safe Mode bypass prevents all module loading.
- Active module loading remains stable.

### 7. Canonical slug migration

Rename legacy slugs to canonical slugs now, pre-launch.

Acceptance criteria:

- Create a migration map from legacy IDs to canonical IDs.
- Migrate settings and active states.
- Skip unknown IDs safely.
- Preserve settings where possible.
- Regenerate hierarchy after migration.

### 8. Regenerate module hierarchy

Regenerate `docs/module-hierarchy.md` from:

1. v5.2.3 canonical scope.
2. v5.3.6 canonical slug registry.
3. v4.1 triage statuses.
4. definition arrays.

Do not use old 125/141-module hierarchy docs as implementation authority.

### 9. REST skeleton

Build REST as the canonical API for new work, but do not force a huge all-at-once AJAX migration.

Acceptance criteria:

- Namespace: `wpt/v1`.
- Shared response/error format.
- Route-level permission callbacks use `Permission_Manager`.
- New work uses REST.
- Existing AJAX may stay temporarily until the related screen/module is touched.

### 10. Product-proof vertical slice

Add a visible slice inside Phase 1 so the product can be judged early.

Minimum slice:

1. Admin Theme / premade themes.
2. Module Library.
3. Content Duplication.
4. Email Delivery shell/basic status/test-email path.
5. Database Optimizer basic dry-run.
6. Smart Menu Organizer shell.
7. Editor Dashboard shell.

Acceptance criteria:

- Uses real module definitions.
- Uses permission boundaries.
- Uses Safe Mode correctly.
- Uses settings pages for medium modules.
- Demonstrates utilities + modern admin + role/editor direction.

### 11. Conflict Detector

Build after definitions/replacement matrix exist, so it can reason from actual module metadata.

### 12. Import/export rebuild + uninstall matrix

Rebuild import/export after canonical slugs and settings schema decisions are stable.

### 13. Surface remediation

Then address:

- Dashboard vs Module Library page split.
- Fake metrics removal.
- Self-hosted fonts/icons.
- `wpt_theme_mode` migration.
- Mobile admin fix.
- No-violet primary design cleanup.

## 5. Hard prerequisites

These must happen before deeper feature work:

1. Safe Mode chrome bypass.
2. Permission Model kernel.
3. Boundary permission checks.
4. Registry definitions.
5. Zero-load loader.
6. Canonical slug migration.
7. Module hierarchy regeneration.

## 6. Items that should not block product proof

These are important, but should not block the vertical slice:

- Full migration of every AJAX call.
- Full Recovery Center UI.
- Full Conflict Detector rule library.
- Full import/export rewrite.
- Full uninstall retention UI.
- Full Pro add-on structure.
- Full dashboard reporting/analytics.
- Full command palette search across every entity.

## 7. AJAX vs REST decision

REST is canonical for new work.

Existing AJAX can remain temporarily if:

- it has nonce checks;
- it has capability checks;
- it does not touch dangerous tools without `run_wpt_dangerous_tools`;
- it is scheduled to migrate when that screen is rebuilt.

Do not add new major admin-ajax endpoints unless explicitly justified.

## 8. Immediate REST routes

Required early:

- `GET /wpt/v1/modules`
- `POST /wpt/v1/modules/{id}/enable`
- `POST /wpt/v1/modules/{id}/disable`
- `GET /wpt/v1/modules/{id}/settings`
- `POST /wpt/v1/modules/{id}/settings`
- `GET /wpt/v1/system/status`
- `GET /wpt/v1/system/safe-mode-url`
- `POST /wpt/v1/import-export/export`
- `POST /wpt/v1/import-export/import`

Can wait:

- full reports routes;
- full conflict rule editing;
- GA4/GSC routes;
- Pro routes;
- advanced migration/import routes.

## 9. Product-proof slice definition

The first visible slice should prove three claims:

### Claim 1 — WPTransformed replaces everyday utility plugins

Proof modules:

- Content Duplication.
- Email Delivery shell/basic.
- Database Optimizer dry-run.

### Claim 2 — WPTransformed makes wp-admin feel modern

Proof modules/surfaces:

- Admin Theme / premade themes.
- Editor Dashboard shell.
- Module Library.

### Claim 3 — WPTransformed organizes WordPress better

Proof modules/surfaces:

- Smart Menu Organizer shell.
- expanded sidebar categories.
- WPTransformed above Misc / Uncategorized.
- unknown plugins below WPTransformed.

## 10. Notes from `wptransformed-1.1.0-session5p2.3.zip`

This package already appears to contain:

- `includes/class-permission-manager.php`
- `includes/class-safe-mode.php`
- `includes/class-module-registry.php`
- `includes/class-core.php`
- no `docs/` directory in the uploaded zip

Therefore:

- Do not create a second competing Permission Manager.
- Verify and adapt the existing `Permission_Manager` instead.
- Do not create a second competing Safe Mode system.
- Fix Safe Mode chrome bypass in the current boot/admin architecture.
- Add docs under `docs/audits/` before continuing.

## 11. Suggested next Codex task

```md
Create docs/audits/phase1-reframe-v4-1.md using the current Phase 1 reframe decisions.

Then implement Safe Mode chrome bypass only.

Acceptance criteria:
1. Safe Mode requires valid token + logged-in admin.
2. When Safe Mode is active, no WPTransformed chrome/global CSS/global JS/modules/injections load.
3. Native WordPress admin remains usable.
4. Show a plain WP admin notice only.
5. Do not build full Recovery Center UI yet.
6. Do not implement unrelated permission/REST/registry work in this commit.
7. Verify with php -l and, if possible, a lightweight boot/harness test.
8. Commit separately.
```

## 12. What to tell Codex not to do yet

Do not do any of these in the next commit:

- Full permission-model call-site sweep.
- Full REST migration.
- Full Recovery Center UI.
- Full Conflict Detector implementation.
- New feature modules.
- Forms builder in core.
- Search/SEO/schema output.
- Pro add-on implementation.
- Broad visual redesign.

The next commit should be small and safety-focused.
