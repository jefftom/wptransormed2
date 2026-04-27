# Existing Codebase Audit

## Repo

```text
C:\dev\wptransormed2
```

## Audit Date

TBD

## Purpose

Audit the existing codebase before porting anything into the new WPTransformed architecture.

The existing repo may contain useful work, but it should not be treated as the final architecture. Port only what aligns with the v5.2.3 canonical scope and v5.3.6 build authority.

## Audit Rules

A file/module may be ported only if it passes:

1. v5.2.3 scope alignment.
2. v5.3.6 build authority alignment.
3. Security review.
4. Module isolation requirements.
5. Naming/registry standards.
6. No dependency on deferred/cut scope.
7. Verification checklist.
8. UI consistency review.


## Required Architecture Decision: Admin Chrome Structure

The existing repo may already have major admin chrome work inside:

```text
includes/class-admin.php
```

The newer specs may recommend namespaced files such as:

```text
includes/Admin/Admin_Chrome.php
includes/Admin/Topbar.php
includes/Admin/Sidebar_Sections.php
```

During audit, decide one path:

- **Refactor:** split the existing `class-admin.php` behavior into the namespaced structure.
- **Adapt:** keep the existing flat file/class pattern and update implementation notes to match.
- **Hybrid:** only if boundaries are clearly documented.

Do not create two competing admin chrome systems.

Record the decision in the Risks section and Recommended Next Build Step.

## File / Module Inventory

| File/Module | Purpose | Current Status | Action | Scope Alignment | Security Notes | Dependencies | Required Changes |
|------------|---------|----------------|--------|-----------------|----------------|--------------|------------------|
| TBD | TBD | TBD | keep / port / rewrite / delete | aligned / partial / no | TBD | TBD | TBD |

## Port Candidates

TBD

## Rewrite Candidates

TBD

## Delete Candidates

TBD

## Deferred/Cut Code Found

TBD

## Risks

TBD

## Recommended Next Build Step

TBD

## Verification & Acceptance Criteria

- [ ] Existing repo has been scanned.
- [ ] Every major file/module is listed in the inventory table.
- [ ] Each item has an action: keep, port, rewrite, delete, defer.
- [ ] No code is ported before security review.
- [ ] No deferred/cut module code is ported into Phase 1.
- [ ] Findings are summarized before implementation begins.
