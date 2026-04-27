# WPTransformed Phase 1 Foundation Spec Pack v5

This spec pack reflects the v5.3.5 clarification: WPTransformed is not just a settings dashboard. It also transforms the entire WordPress admin chrome and reorganizes the WordPress admin experience.

Phase 1 now has two foundation tracks:

## Track A — System Foundation

1. Module Registry
2. Module Loader
3. Settings Storage
4. Permission Model
5. Recovery Center / Safe Mode
6. Conflict Detector
7. Module Library
8. Dashboard Shell
9. Import / Export
10. Existing Codebase Audit

## Track B — Admin Transformation Foundation

1. Admin Chrome Foundation
2. Editor Dashboard Shell

The admin transformation is foundational infrastructure, not a toggleable feature module.

## Required Product Docs

Place these files under `docs/product/`:

```text
wptransformed-canonical-feature-scope-v5-2-3.md
wptransformed-build-authority-addendum-v5-3-6.md
wptransformed-admin-transformation-spec-v1-3.md
```

## Phase 1 Definition of Done

Phase 1 is complete when:

- WPTransformed can register modules from a canonical registry.
- Inactive modules load zero hooks/assets.
- Settings can be saved and retrieved safely.
- Permissions are enforced through WPTransformed capabilities.
- Recovery Center can disable modules and restore known-good configuration.
- Conflict Detector can identify overlapping plugins.
- Module Library can display/search/filter modules.
- Dashboard Shell can show core status cards and quick links.
- Import/export can move safe configuration between sites.
- Existing codebase has been audited for keep/port/rewrite/delete.
- Native WordPress admin chrome is reskinned without replacing `#adminmenu` or `#wpadminbar`.
- Sidebar section grouping works without hiding third-party plugin menu items.
- The editor dashboard exists as the post-wizard landing page using real WordPress data.


## v3 Safety Clarification

Global Admin Transformation styles and organizes native WordPress admin chrome. It must not globally hide or remove menu pages. Client-Safe Mode is the explicit role-based layer that may hide/restrict menus when configured.


## v4 Consistency Updates

- Canonical site profiles are owned by v5.2.2.
- Comments and Tools are not globally hidden by admin chrome.
- Native Tools is relocated/organized under TOOLS, not removed.
- Existing repo should be continued on a v5 foundation alignment branch after audit.
- Old `docs/module-hierarchy.md` must be regenerated from v5.2.2 before Module Grid work.
- Root-level duplicate reference HTML files should be cleaned up.


## v5 Clean Pack Notes

This pack removes older product-doc versions from `docs/product/` so Codex/Claude Code cannot accidentally read stale guidance. Use only the three current files listed in `docs/product/README.md`.
