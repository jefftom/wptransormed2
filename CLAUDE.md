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

# WPT Pipeline — Standing Claude Code Routine (v1.1)

Supersedes CLAUDE-md-handoff-routine.md. Append to the repo's CLAUDE.md. Defines handoff behavior for every WPTransformed build session. The handoff medium is the Google Drive folder "WPT Handoff", reachable by TWO transports; use them in this order:

TRANSPORT 1 — local synced path (preferred, required for binaries): HANDOFF_DIR = G:\My Drive\WPT Handoff. Requires Google Drive for desktop running with the folder available offline.
TRANSPORT 2 — Google Drive connector (fallback, text artifacts only): if HANDOFF_DIR is absent or Drive desktop is not running, read and write handoff text files (decision docs, prompts, reports, status JSON, ledger) via the Google Drive MCP connector against the "WPT Handoff" folder. Zips and other binaries do NOT go over the connector: write them to the repo's parent directory, state the path in the final summary, and flag that Drive desktop needs starting.
If both transports are unavailable, complete the session normally and report handoff artifacts as pending. Never invent file contents that should have come from the handoff folder; blocked beats fabricated, always.

## Session start (before any task work)
1. Via the available transport, check "WPT Handoff" for any slice-*-decisions.md not yet present in docs/audits/. Copy each in verbatim and commit individually: docs(audit): slice {N} decision document. Never edit a decision doc while copying.
2. Check for a matching slice-*-prompt.md. Prompts are NEVER committed. If a prompt exists without its committed decision doc, stop and report.
3. Run the baseline gate the prompt specifies before coding.

## During the build
- The decision doc is the contract; the tiebreaker is the `## Current Verified Facts` section of `docs/audits/current-checkpoint.md` (no standalone facts sheet remains — it was folded in; on disagreement the facts win, by extraction-from-behavior not age, as that section states); pipeline/failure-patterns.md is the self-check ledger. Read all three — contract, facts section, ledger — before coding, and re-check the ledger before the final commit.
- Stop-and-report on any contradiction between contract and code. Never resolve contract contradictions unilaterally.

## Session end (after the slice commit, before the final summary)
Write four artifacts to "WPT Handoff" (Transport 1; binaries fall back per the transport rules):
1. report-slice-{N}.md — full final summary with pasted verification output, structured by the prompt's categories, ending with a RETRO section: for each deviation, gap, or near-miss, one line naming which pipeline stage should have caught it (decision doc / external audit / build / verification / human UAT) and whether it is a new failure pattern. New patterns are PROPOSED verbatim for pipeline/failure-patterns.md — never self-applied.
2. status-slice-{N}.json — { "slice", "commit", "branch", "files_changed", "insertions", "deletions", "harness_assertions", "harness_result", "live_checks_passed", "live_checks_total", "deviations": [], "stopped_on": null, "gaps_logged": [], "e2e_specs_passed": null, "e2e_specs_total": null, "mailpit_assertions": null, "next_recommended": "" } — every field from actual command output.
3. wptransformed-{version}-slice-{N}.zip — plugin tree only, for human UAT on wpt-uat.
4. wpt-repo-{commit}.zip — full tree at the slice commit, for external audit.

## Rules
- Never write secrets, credentials, or live DB dumps to the handoff folder over either transport.
- The folder is append-only; superseded artifacts stay.
- These routines move files and evidence only: no product decisions, no decision-doc edits, no ledger edits, no bypassing a stop-and-report.

