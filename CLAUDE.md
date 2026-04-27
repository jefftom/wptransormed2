# WPTransformed Claude Code Rules

Before implementing anything, read:

1. `docs/product/wptransformed-canonical-feature-scope-v5-2-3.md`
2. `docs/product/wptransformed-build-authority-addendum-v5-3-6.md`
3. `docs/product/wptransformed-admin-transformation-spec-v1-3.md`
4. The relevant per-module spec in `docs/modules/{category}/{slug}.md`

Do not build a module directly from the canonical scope file alone.

Critical product framing:

- WPTransformed is reskinning and reorganizing the entire WordPress admin experience.
- This is not merely a plugin settings dashboard.
- The global admin chrome is foundational infrastructure.
- `admin-global.css` loads on every admin page.
- Style native WordPress admin; do not replace native admin structures.
- Never build a custom sidebar that replaces `#adminmenu`.
- Never build a custom admin bar that replaces `#wpadminbar`.
- Never call `remove_menu_page()` or `remove_submenu_page()` for the global transformation.
- Client-Safe Mode is the only layer allowed to hide/restrict menus, and only by explicit role/user rules.
- Third-party plugin menu items must continue working.

Do not proceed if:
- No per-module spec exists.
- Module slug is not in the canonical registry.
- Tier/risk/default-enabled status is undefined.
- Settings schema is missing.
- Permission model is missing.
- Data retention is undefined.
- Mobile behavior is undefined for app pages.
- Conflict behavior is undefined.
- Verification & acceptance criteria are missing.

Build only what is in the referenced module spec.
Do not add adjacent features.
Do not infer missing behavior.
If a required behavior is undefined, add a TODO in the module spec rather than inventing behavior.

For Phase 1, build only foundation/system and admin chrome pieces:
- module-registry
- module-loader
- settings-storage
- permission-model
- recovery-center
- conflict-detector
- module-library
- dashboard-shell
- import-export
- admin-chrome-foundation
- editor-dashboard-shell
- existing-codebase-audit

Do not implement customer-facing utility modules until their module specs exist.


Admin safety contract:
- Global chrome may style and organize; Client-Safe Mode may restrict by role.
- Global CSS must be scoped defensively and Safe Mode must bypass admin chrome assets.


Additional consistency rules:
- Use only the canonical site profiles from v5.2.2.
- Do not globally hide Comments or Tools through admin chrome.
- Tools may be relocated to TOOLS; Comments may remain under CONTENT.
- If menu items need to be hidden, that belongs to Client-Safe Mode or explicit module settings.
- Regenerate `docs/module-hierarchy.md` from v5.2.2 before Module Grid work.
- Continue from existing repo on a v5 foundation alignment branch after codebase audit.


Current source-of-truth files only:
- `docs/product/wptransformed-canonical-feature-scope-v5-2-3.md`
- `docs/product/wptransformed-build-authority-addendum-v5-3-6.md`
- `docs/product/wptransformed-admin-transformation-spec-v1-3.md`

Ignore older product docs unless explicitly auditing history.
