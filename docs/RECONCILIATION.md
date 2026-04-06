# Docs Reconciliation Plan

The goal: turn the scattered doc history (6 wave files, 2 architecture doc copies, 2 module-spec doc copies, 2 CLAUDE.md files, separate TODO.md, separate DECISIONS.md, separate wave files) into a single canonical `docs/` tree in the repo that Claude Code can reliably read when building any module.

This is a mechanical migration. No new content needs to be written — every piece of content already exists in one of the source files.

## Target structure

```
docs/
├── CLAUDE.md                        ← 80-line sticky note, points at the rest
├── ARCHITECTURE.md                  ← code patterns, base class, security, compliance
├── SPEC.md                          ← feature-level overview, module taxonomy, reconciliation status
├── MONETIZATION.md                  ← pricing, Freemius, GTM (unchanged from source)
├── DECISIONS.md                     ← architectural + hosting constraints log (live, append-only)
├── modules/
│   ├── _TEMPLATE.md                 ← canonical per-module spec template
│   ├── admin-interface/
│   │   ├── menu-editor.md           ← full v2 spec (exemplar exists)
│   │   ├── admin-bar-manager.md
│   │   ├── dark-mode.md
│   │   ├── command-palette.md
│   │   └── ...
│   ├── content-management/
│   │   ├── content-duplication.md
│   │   └── ...
│   ├── security/
│   ├── performance/
│   ├── developer/
│   ├── design/
│   └── utilities/
└── reference/
    ├── v1-module-specs.md           ← archived source, not read at build time
    ├── module-specs-v2.md           ← archived source
    ├── module-specs-wave3.md        ← archived source
    ├── module-specs-wave4.md        ← archived source
    ├── module-specs-wave5.md        ← archived source
    ├── module-specs-wave6.md        ← archived source
    ├── wptransformed-spec.md        ← archived feature overview
    ├── TODO.md                      ← archived (content migrated to per-module specs)
    ├── cc-prompts-v1/               ← archived 11 numbered prompts
    │   ├── 00-core-framework.md
    │   └── ...
    └── cc-prompts-phase-2-plus.md   ← archived (Form Builder + error isolation)
```

## Migration steps — do these in order

### Step 1: File consolidation and normalization (5 minutes, trivially safe)

- [ ] Drop duplicate file `CLAUDE__4_.md` (byte-identical to `CLAUDE.md`).
- [ ] Pick one of the duplicate architecture docs (same content, different line endings):
  - Delete `architecture.md` (CRLF) and keep `wptransformed-v1-architecture.md` (LF)
  - OR run `dos2unix architecture.md` on it and delete the other
- [ ] Same treatment for `module-specs.md` vs `wptransformed-v1-module-specs.md`.
- [ ] Rename the canonical v1 docs as they're moved to `docs/reference/`:
  - `wptransformed-v1-architecture.md` → `docs/ARCHITECTURE.md` (this is the actual architecture doc, not a historical archive)
  - `wptransformed-v1-module-specs.md` → `docs/reference/v1-module-specs.md`
- [ ] Normalize all files to LF line endings before committing (`git config core.autocrlf false` on this repo and run `dos2unix` on any remaining CRLF files).

### Step 2: Split the CLAUDE.md into two layers (20 minutes)

The existing 2849-line `CLAUDE.md` is doing too much. It's both a sticky-note auto-load context AND a comprehensive reference. Split it.

- [ ] Extract the "Architecture" + "Module Base Class Contract" + "Module Registration Pattern" + "Core Loader Pattern" sections from `CLAUDE.md` and merge with `wptransformed-v1-architecture.md` → **`docs/ARCHITECTURE.md`**. This becomes the single code-pattern reference. Sections 1.1–1.12 from the v1 architecture doc become the body; the CLAUDE.md additions (Freemius integration, REST API patterns) get appended as new sections.
- [ ] Extract the "Security Policy" + "WordPress Best Practices" + "WordPress.org Compliance Checklist" sections from `CLAUDE.md` → **append to `docs/ARCHITECTURE.md`** as "Part 5: Security & Compliance". These are build-time reference material, not context.
- [ ] Extract the "Module Categories & Counts" + "Pricing" + "Development Roadmap" sections from `CLAUDE.md` → **`docs/SPEC.md`**. This is the high-level feature and business overview. Keep it under 500 lines.
- [ ] Write a new lean **`docs/CLAUDE.md`** — 80 lines max — that just says: "Read `docs/ARCHITECTURE.md` for code patterns, `docs/modules/{category}/{slug}.md` for the current build target, `docs/DECISIONS.md` for constraints. Full feature spec in `docs/SPEC.md`." Plus the 5 non-negotiable rules (security, module pattern, style-not-replace, nonces, custom tables). This is the file Claude Code auto-loads.

### Step 3: Build the per-module spec files (incremental, start with 10)

Don't try to write all 125 at once. The template + exemplar (Menu Editor) are already done. Add new specs one at a time as modules come up for building or refactoring.

**Priority order for the first 10 specs:**

1. `admin-interface/menu-editor.md` ← **DONE** (exemplar)
2. `admin-interface/admin-bar-manager.md` — source: v1 Module 5 + `cc-command-04-expansion.md`
3. `admin-interface/dark-mode.md` — source: v1 Module 6
4. `admin-interface/command-palette.md` — source: `module-specs-wave3.md` Module 26 + `command-palette-v3.html`
5. `content-management/content-duplication.md` — source: v1 Module 1
6. `security/two-factor-auth.md` — source: `module-specs-wave6.md` Module 95 + today's locked-in decisions (TOTP + Email + Recovery codes + Trust device 30d)
7. `security/strong-passwords.md` — source: needs fresh spec, minimal
8. `security/login-notifications.md` — source: needs fresh spec, minimal
9. `design/white-label.md` — source: `wptransformed-spec.md` Category 7 + `white-label-v3.html` + AME v3.5 theme system
10. `utilities/email-smtp.md` — source: v1 Module 10

**For each spec, the mechanical process is:**

1. Start with `docs/modules/_TEMPLATE.md` as the scaffold
2. Read the primary source (v1 spec, wave spec, or chat history if nothing else)
3. If the module has an HTML mockup in `assets/admin/reference/app-pages/`, link it in Metadata and reference it in Settings Page UI
4. If the module has a reference code implementation (like AME v3.5), link it in Metadata and reference it in Exact Behavior
5. Check `TODO.md` for this module's deferred features and known bugs → migrate to the corresponding sections
6. Check `DECISIONS.md` for any entries that mention this module → migrate to Known Gotchas or Edge Cases
7. Fill in scope boundaries explicitly (what it does NOT do, which modules handle adjacent concerns)
8. Write verification steps that are binary pass/fail

### Step 4: Reconcile the 125 → 86 decision

Today's refinement pass removed 39 modules and consolidated 33 more into 8 parent modules. The spec layer still reflects 125. Pick one of three policies per module:

- **Removed** — Mark as removed in `docs/SPEC.md` with a reason. Do not create a per-module spec file. Historical source stays in `docs/reference/`.
- **Deferred to v3+** — Mark in `docs/SPEC.md` as `Phase 7+`. Do not create a per-module spec file yet. Move the wave-file content into a `docs/reference/deferred-modules/` archive.
- **Consolidated into parent** — The parent gets a per-module spec file. The sub-modules are documented as sections within the parent's spec (not separate files). Example: "Admin Bar Manager" parent module has sections for each sub-feature that used to be its own module (hide admin bar, custom admin bar links, per-role profiles, etc.).

**Explicit decisions required** (table — fill in one line per removed/consolidated module):

| Module | Removed / Deferred / Consolidated | Target |
|---|---|---|
| Cookie Consent | Removed | N/A — recommend Complianz in README |
| Forms | Deferred | Separate repo, `WPBuiltRight Forms` |
| File Manager | Removed | Security risk, no replacement |
| Workflow Automation (category) | Deferred | v3 |
| Plugin Profiler | Deferred | v3 |
| Content Calendar | Deferred | v3 |
| Custom Content Types | Deferred | v3 — recommend ACF/CPT UI |
| Admin Columns Pro | Removed | Trademark conflict |
| Webhook Manager | Removed | Folded into Forms |
| Passkey Authentication | Deferred | v1.1 of 2FA module |
| WooCommerce modules (5) | Removed | Separate repo if built |
| (... complete for all 39 removed + 33 consolidated ...) | | |

Commit this table as `docs/SPEC.md` "Module Reconciliation" section.

### Step 5: Migrate TODO.md into per-module specs

TODO.md currently contains:
- Module 01–10 deferred features → migrate to each module's **Deferred Features** section
- Module 01–10 known bugs → migrate to each module's **Known Issues** section
- "Prompt Revision Status" → delete (historical)
- "Standalone Plugin Architecture" → extract to `docs/SPEC.md` as "Distribution Strategy: Standalone + Bundled"
- "Competitive Positioning" → merge into `docs/SPEC.md` "Competitive Landscape" section
- "Future Module Ideas" → move to `docs/reference/deferred-modules/ideas.md`

After migration, `TODO.md` at the repo root is deleted. All its content lives in the appropriate canonical locations.

### Step 6: Activate DECISIONS.md as a live log

`DECISIONS.md` has 15 entries from April 1, 2026 and hasn't been updated since. It should be a live append-only log.

- [ ] Move to `docs/DECISIONS.md` if not already there
- [ ] Add a header explaining the format: "Append-only log of architectural decisions and hosting constraints. Never delete entries. When a decision is superseded, add a new entry referencing the old one."
- [ ] Add today's decisions from this conversation:
  - 2026-04-05 | v2 Scope | Refined 125 → 86 modules; reasoning in SPEC.md Module Reconciliation
  - 2026-04-05 | Cookie Consent | Removed — ASE version is cosmetic, real compliance needs scanning+logging+Consent Mode v2, support liability not worth it; recommend Complianz
  - 2026-04-05 | Menu Editor | Port from `advanced-admin-menu-editor-v3.5` zip verbatim; do not rewrite; architectural rule is "style don't replace" via `$menu` globals
  - 2026-04-05 | Spec Layer | Canonical docs now live in `docs/modules/{category}/{slug}.md`; historical wave files archived to `docs/reference/`; `TODO.md` deleted after content migration; `CLAUDE.md` split into lean auto-load + `ARCHITECTURE.md` + `SPEC.md`
  - 2026-04-05 | 2FA | TOTP primary + Email fallback + 10 recovery codes (forced download) + Admin override + Trust device 30 days; skip SMS (SIM swap + NIST deprecated) and WebAuthn/Passkeys (defer to v1.1)

### Step 7: Archive the historical sources

Move to `docs/reference/` and never read at build time:

- [ ] `wptransformed-v1-module-specs.md` → `docs/reference/v1-module-specs.md`
- [ ] `module-specs-v2.md` → `docs/reference/module-specs-v2.md`
- [ ] `module-specs-wave3.md` → `docs/reference/module-specs-wave3.md`
- [ ] `module-specs-wave4.md` → `docs/reference/module-specs-wave4.md`
- [ ] `module-specs-wave5.md` → `docs/reference/module-specs-wave5.md`
- [ ] `module-specs-wave6.md` → `docs/reference/module-specs-wave6.md`
- [ ] `wptransformed-spec.md` → `docs/reference/wptransformed-spec-feb-2026.md` (the original 115-module spec; content already extracted to new `docs/SPEC.md`)
- [ ] `wptransformed-claude-code-prompts.md` → `docs/reference/cc-prompts-phase-2-plus.md` (Form Builder spec + error isolation system lives here for now)
- [ ] 11 CC prompts (`00-core-framework.md` through `10-email-smtp.md`) → `docs/reference/cc-prompts-v1/`
- [ ] `cc-command-04-expansion.md`, `cc-command-06-expansion.md` → `docs/reference/cc-prompts-v1/expansions/`
- [ ] `ui-restructure-spec.md` → `docs/reference/ui-restructure-spec.md` (the 8-session build roadmap — still valid, but not per-module)
- [ ] `module-hierarchy.md` → **keep at `docs/module-hierarchy.md`** (canonical taxonomy for the module dashboard UI)
- [ ] `IMPROVEMENTS.md`, `rejected-proposals.md`, `evolution-log.md` — keep as-is (empty templates for the self-evolution protocol)

### Step 8: Commit the new docs structure in one go

Single atomic commit:

```
docs: reconcile historical sources into canonical docs/modules/ structure

- Consolidate 6 wave files, 2 architecture copies, 2 module-spec copies,
  and 2 CLAUDE.md files into single-source-of-truth per-module specs
- Archive historical wave files and CC prompts to docs/reference/
- Split 2849-line CLAUDE.md into lean auto-load + ARCHITECTURE.md + SPEC.md
- Migrate TODO.md content into per-module Deferred Features and
  Known Issues sections; delete root TODO.md
- Add docs/modules/_TEMPLATE.md as the canonical per-module spec format
- Seed docs/modules/admin-interface/menu-editor.md as exemplar
  (synthesized from v1 Module 2 + AME v3.5 zip + menu-editor-v3.html)
- Activate DECISIONS.md as live append-only log; add 5 new entries
  from 2026-04-05 refinement session

This is a no-code change. All spec content existed in scattered files
before this commit. The restructure makes Claude Code builds
deterministic: each build session reads ARCHITECTURE.md + one module
spec file + DECISIONS.md. No chat-history excavation required.
```

## Post-migration: the new Claude Code workflow

Once the docs tree is in place, the standard build prompt to Claude Code becomes:

```
Read docs/CLAUDE.md, docs/ARCHITECTURE.md sections 1.1–1.12, and 
docs/modules/{category}/{slug}.md. Read any reference HTML or code 
linked in the spec's Metadata section. Build the module to spec. 
Follow the Security Policy. Run verification steps before committing. 
Add any new constraints discovered to docs/DECISIONS.md. Log any 
bugs found to the Known Issues section of the module spec.
```

That's the deterministic loop. Every future module build follows the same pattern. No regression risk because the spec is committed to the repo, not trapped in chat history.

## What this migration does NOT do

- **Does not fix the current 86 modules in the v2 repo.** Those still need individual audit passes against their new specs as the specs get written. But the audit becomes much easier once each module has a spec file to audit against.
- **Does not write 125 module specs.** Only the template, the exemplar, and the first 10 priority specs. The rest get written as each module is built or refactored. Wave files stay archived as backlog reference.
- **Does not port the AME v3.5 zip into the v2 repo.** That's a separate step — the Menu Editor spec describes how to port, but the actual port happens in a Claude Code session when Menu Editor is next in the build queue.
- **Does not change any PHP code.** Zero code changes. Docs only.

## Validation — how you know the migration worked

After Step 8 is committed, open a fresh Claude Code session with this prompt:

```
Read docs/CLAUDE.md, docs/ARCHITECTURE.md, and 
docs/modules/admin-interface/menu-editor.md. Do not read any other 
files. Summarize in plain English: (1) what this module does, 
(2) what files it would create, (3) what verification tests would 
prove it works. Do not write code yet.
```

If Claude Code can answer all three questions without asking for additional context, the spec is self-sufficient. If it asks questions like "where is the settings schema?" or "how does Module_Base work?", the docs still have gaps.

Run the same validation with the next spec before writing more. This catches format problems early.
