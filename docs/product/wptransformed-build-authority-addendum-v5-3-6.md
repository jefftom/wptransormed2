# WPTransformed — Build Authority Addendum v5.3.6

## Version Notes

v5.3.6 is an audit-template and existing-architecture clarification release.

- Adds a specific audit risk for the existing flat `includes/class-admin.php` admin chrome implementation versus the newer proposed namespaced `includes/Admin/*` structure.
- Clarifies that the audit must decide whether to refactor into the new namespace structure or adapt the existing flat class pattern.
- Updates audit-template references to the current v5.2.3 / v5.3.6 docs.

v5.3.6 is a consistency cleanup release.

It resolves the remaining doc conflicts before Codex/Claude Code implementation:

- Uses the v5.2.3 canonical site profile list everywhere.
- Clarifies that Comments and Tools must not be globally hidden by admin chrome.
- Clarifies that native Tools is relocated/organized under TOOLS, not hidden.
- Adds explicit build decision: continue from the existing repo on a v5 foundation alignment branch.
- Adds module hierarchy regeneration requirement before Session 3 / Module Grid work.
- Adds reference HTML cleanup instruction.
- Confirms Permalink / Slug Manager belongs under Content & Media and does not require a sidebar item.
- Preserves the Admin Chrome Safety Contract from v5.3.4.
- Points all source-of-truth references to the current files only.

## Purpose

This addendum converts the v5.2 canonical feature scope into a build-ready operating standard.

The v5.2 scope file defines **what belongs in WPTransformed**.
This v5.3 addendum defines **how the build should be planned, specified, and implemented**.

Use this file alongside:

- `wptransformed-canonical-feature-scope-v5-2-3.md`
- `wptransformed-admin-transformation-spec-v1-3.md`
- `docs/modules/_TEMPLATE.md`
- Individual module specs at `docs/modules/{category}/{slug}.md`

---

# 1. Source-of-Truth Hierarchy

WPTransformed now has four layers of documentation.

## Layer 1 — Product Scope Authority

**File:** `wptransformed-canonical-feature-scope-v5-2-3.md`

Purpose:

- Product positioning
- Core vs Pro decisions
- Module/category map
- Deferred/cut list
- Companion plugin strategy
- High-level launch scope

This file answers:

> Should this feature exist in WPTransformed?

It also owns the canonical site profile list:

1. Personal / Blogger
2. Business Website
3. Ecommerce / Store
4. Agency Client Site
5. Content Team / Editors
6. Developer-Managed Site

Other docs should reference this list and should not redefine profile options independently.

## Layer 2a — Build Authority

**File:** `wptransformed-build-authority-addendum-v5-3-5.md`

Purpose:

- Implementation rules
- Navigation model
- Migration strategy
- SEO conflict strategy
- Mobile/responsive rules
- Pro gating rules
- Existing repo strategy
- Hook/filter conventions
- Per-module spec requirements
- Global admin transformation principles
- Design system rules
- Tech stack contract

This file answers:

> How should Codex/Claude/developers interpret and implement the scope?

## Layer 2b — Admin Transformation Spec

**File:** `wptransformed-admin-transformation-spec-v1-3.md`

Purpose:

- Complete sidebar reskin specification (CSS values, gradients, typography)
- WordPress menu item → WPT section mapping
- Plugin detection and sidebar slotting rules
- Topbar / admin bar specification
- Editor Dashboard specification
- Design system tokens (every color, font, spacing, animation value)
- Session-by-session build prompts with exact file references
- Current build progress and session status

This file answers:

> Exactly how does the admin transformation look, feel, and get built session by session?

## Layer 3 — Per-Module Build Specs

**Files:** `docs/modules/{category}/{slug}.md`

Purpose:

- Exact settings schema
- Hooks and filters
- REST endpoints
- Database usage
- Edge cases
- Verification steps
- Conflicts/dependencies
- Uninstall/data behavior

These files answer:

> Exactly how should this module be built and verified?

## Non-Negotiable Rule

Do not build a module directly from the v5.2 scope file alone.

Every module must have a per-module spec before implementation begins.

---

# 2. Scope Authority vs Build Authority

The v5.2 document is intentionally high-level. It is the feature map, not the construction plan.

Codex/Claude should not infer:

- Hooks
- Database schema
- REST routes
- Settings defaults
- UI breakpoints
- Data retention behavior
- Pro gating behavior
- Conflict resolution behavior
- Verification steps

Those details belong in individual module specs.

## Example

The v5.2 scope may say:

> Change Login URL — Core Advanced

But the build spec must define:

- Rewrite rules
- Login URL storage
- Recovery behavior
- Email notification
- Safe Mode bypass
- Conflict detection with WPS Hide Login
- Multisite handling
- Verification steps
- Uninstall behavior

---

# 3. Final Admin Navigation Model

WPTransformed should not create 18–20 top-level WordPress admin sidebar items.

The product exists to clean up WordPress admin clutter. Its own admin IA must not recreate the same problem.

## WordPress Sidebar Structure

WPTransformed should register one top-level menu item:

```text
WPTransformed
```

Recommended submenu items:

1. Dashboard
2. Setup Wizard
3. Modules
4. Client-Safe Mode
5. Search & AI
6. Security & Login
7. Email Delivery
8. Performance & Database
9. Design
10. Code Manager
11. Utilities
12. Integrations
13. Reports
14. Developer Tools
15. Settings

## Internal App Navigation

Inside WPTransformed pages, use section tabs or app-level navigation for:

- Admin Experience
- Content & Media
- Users & Roles
- Migration Center
- Recovery Center
- Support Package

## App Page Rule

Major Pro/app experiences may receive direct submenu links only if they are true workspaces:

- Admin Menu Editor
- Login Designer
- Database Optimizer
- Schema Builder Pro
- Email Log
- Reports
- White Label
- Client Dashboard Builder

Even then, avoid overloading the WordPress sidebar. Prefer deep links from the Dashboard and Modules page.

## Smart Menu Organizer Consistency

The WPTransformed menu should follow its own Smart Menu Organizer philosophy:

- Content
- Design
- Security
- Tools
- Configure

Do not ship a cluttered plugin sidebar while claiming to declutter WordPress.

---

# 4. Disable Gutenberg / Classic Editor Must Be a Standalone Module

Disable Gutenberg is too important to be buried under a generic component-control section.

It should have its own module card, slug, settings, documentation, and search terms.

## Module

```json
{
  "id": "disable-gutenberg",
  "title": "Disable Gutenberg / Classic Editor",
  "category": "content-media",
  "tier": "core",
  "risk": "moderate",
  "default_enabled": false
}
```

## Search Terms

The module grid search index should include:

- disable gutenberg
- classic editor
- restore classic editor
- disable block editor
- turn off block editor
- old editor
- editor switcher

## Core Behavior

- Disable block editor globally.
- Disable block editor by post type.
- Disable block editor by role.
- Optional user choice if enabled.
- Never enabled by default.

## Replacement Positioning

This module replaces the common use case for Classic Editor.

Use cautious copy:

> Restore the Classic Editor for selected users or post types when Gutenberg is not the right fit.

---

# 5. Search Appearance Detection-First Contract

Search Appearance must be designed around conflict avoidance.

This is not optional.

## Detection-First Rule

On admin load and before output:

1. Detect active SEO plugins.
2. Detect active schema plugins.
3. Detect active Open Graph/social metadata owners.
4. Decide whether WPTransformed owns each output area.
5. Only output metadata if WPTransformed is the active owner.

## SEO Plugins to Detect

At minimum:

- Yoast SEO
- Rank Math
- All in One SEO
- SEOPress
- The SEO Framework
- Squirrly SEO
- Slim SEO

## If SEO Plugin Is Active

WPTransformed must:

- Disable SEO title output.
- Disable meta description output.
- Disable canonical output.
- Disable robots meta output.
- Disable Open Graph output if the detected plugin owns OG tags.
- Show a clear state:
  - "Search metadata is managed by Yoast SEO."
  - "Search metadata is managed by Rank Math."
  - etc.
- Keep WPTransformed fields hidden or read-only by default.
- Still allow read-only status columns where possible.
- Avoid duplicate tags.

## What Can Still Work Alongside SEO Plugins

Potentially safe alongside SEO plugins:

- llms.txt Generator
- Metadata status columns, read-only
- Missing metadata dashboard, if reading existing SEO plugin fields
- Search Console Insights
- GA4 Content Insights

## Schema Output Contract

Schema should also be detection-first.

If Yoast, Rank Math, AIOSEO, SEOPress, Schema Pro, or another schema plugin is outputting JSON-LD:

- Do not output duplicate schema by default.
- Show "Schema is managed by [plugin]."
- Allow WPTransformed Schema Builder only if the user explicitly enables it.
- Provide conflict warnings before enabling overlapping schema types.

## Output Ownership Model

Each search-related output area should have an owner:

```json
{
  "title_meta": "wpt|yoast|rank_math|aioseo|seopress|none",
  "description_meta": "wpt|yoast|rank_math|aioseo|seopress|none",
  "canonical": "wpt|yoast|rank_math|aioseo|seopress|none",
  "robots": "wpt|yoast|rank_math|aioseo|seopress|none",
  "open_graph": "wpt|yoast|rank_math|aioseo|seopress|none",
  "schema": "wpt|yoast|rank_math|aioseo|seopress|schema_pro|none",
  "llms_txt": "wpt"
}
```

Codex should not build Search Appearance as an always-on meta output layer.

---

# 6. Migration Center Scope Decisions

"Where practical" is not buildable. Each migration target must be explicitly marked as launch, detection-only, Pro, companion, deferred, or cut.

## Migration Center v1 — Core

### Must Build

- Detect ASE.
- Import selected ASE settings where direct mapping exists.
- Detect WP Mail SMTP.
- Detect Redirection.
- Detect WPCode.
- Detect Admin Menu Editor.
- Detect User Role Editor.
- Detect Classic Editor.
- Detect WPS Hide Login.
- Detect Limit Login Attempts.
- Detect Disable Comments.
- Detect Yoast Duplicate Post.
- Detect FileBird / Real Media Library.
- Detect companion CPT plugin.
- Detect companion Search & Replace plugin.
- Show "What WPTransformed can replace" list.
- Show safe migration recommendations.

### ASE Import Is Phase 2 Priority

ASE import is the highest-value migration feature because it directly supports switching from the strongest competitor.

Build an explicit mapping table for ASE options.

Example mapping format:

```json
{
  "ase_module_id": "clean_up_admin_bar",
  "wpt_module_id": "admin-bar-manager",
  "mapping_type": "direct",
  "supported": true,
  "notes": "Maps basic admin bar cleanup toggles."
}
```

### Detection-Only in v1

These should be detected in v1, but not fully imported unless a per-plugin import spec exists:

- WP Mail SMTP
- Redirection
- WPCode
- User Role Editor
- Admin Menu Editor
- CPT UI
- Better Search Replace
- FileBird / Real Media Library

### Pro / Later Imports

Potential Pro migration features:

- WP Mail SMTP credential import.
- Redirection rules import.
- WPCode snippets import.
- Admin Menu Editor configuration import.
- User Role Editor roles/capabilities import.
- FileBird folder import.
- CPT UI configuration import.
- Better Search Replace history/settings import.

## Migration UX

Migration Center should show:

- Detected plugin
- Replacement type:
  - Exact replacement
  - Partial replacement
  - Pro replacement
  - Companion integration
  - Keep installed
- Risk level
- Import availability
- Recommended action

---

# 7. Mobile and Responsive Admin Strategy

Every WPTransformed app page must be responsive.

Do not assume desktop-only admin usage.

## Global Breakpoints

Recommended breakpoints:

```css
desktop: >= 1200px
tablet: 768px - 1199px
mobile: < 768px
small-mobile: < 480px
```

## General Rules

### Desktop

- Multi-column app layouts are allowed.
- Side panels and drawers are allowed.
- Data tables can show full columns.

### Tablet

- Reduce to one or two columns.
- Sidebars collapse.
- Preview panels stack below controls.
- Tables should support horizontal scroll.

### Mobile

- Single-column layout.
- Full-screen panels instead of side drawers.
- Sticky action bars for important actions.
- Tables become cards where practical.
- Avoid hover-only interactions.
- Ensure touch targets are at least 44px.

## Component-Specific Rules

### Command Palette

Desktop: Centered modal, keyboard-first navigation.

Mobile: Full-screen search overlay, large search input, tap-friendly results, close button visible.

### Notification Center

Desktop: Right-side drawer.

Mobile: Full-screen panel, tabs or filters stacked, inline actions remain tappable.

### Module Grid

Desktop: Card grid.

Tablet: Two-column grid.

Mobile: Single-column list/card layout, filters collapse into drawer or dropdown.

### Setup Wizard

Desktop: Split panel allowed.

Mobile: Single step per screen, sticky bottom next/back controls.

### Admin Menu Editor

Desktop: Three-panel layout (sidebar preview, menu tree, settings panel).

Tablet: Two-panel layout.

Mobile: Step-based layout (menu list, item detail, preview).

### Login Designer

Desktop: Settings left, live preview right.

Tablet: Settings top/left, preview below/right.

Mobile: Settings first, preview collapsible, device preview simplified.

### Database Optimizer

Desktop: Bento stats + cleanup checklist + table sizes.

Mobile: Stacked stat cards, cleanup tasks as cards, table sizes as compact list.

### Schema Builder

Desktop: Schema type list + field builder + JSON preview.

Mobile: Step-by-step editor, JSON preview collapsed by default.

### Email Log

Desktop: Full table.

Mobile: Email cards with status, recipient, subject, time, actions.

### Reports

Desktop: Report builder with preview.

Mobile: Report settings first, preview collapsed or separate step.

---

# 8. GA4 and Google Search Console OAuth Decision

GA4 and GSC integrations require OAuth unless using a limited/manual approach.

This is not a small feature.

## Core Decision

GA4/GSC data pulling should not be a Core launch feature unless the OAuth architecture is fully resolved.

## Core Should Include

- Integrations Hub shell.
- Connection cards.
- Documentation links.
- Manual property ID fields if useful.
- Status placeholder:
  - "GA4 Insights available in Pro."
  - "Search Console Insights available in Pro."

## Pro Should Include

- Managed OAuth connection.
- Token refresh.
- Token revocation.
- Secure token storage.
- API error handling.
- Cached API responses.
- GA4 Content Insights.
- Search Console Insights.
- Report inclusion.

## Open Implementation Question

Decide before building:

1. Use a WPTransformed-managed Google OAuth app.
2. Let users bring their own Google Cloud OAuth credentials.
3. Use a hybrid model.

## Recommendation

Use a WPTransformed-managed OAuth app for Pro if possible.

Bring-your-own OAuth credentials should be an advanced fallback, not the main path.

---

# 9. Relationship to Existing Codebase

The existing repo/codebase contains significant work that should be audited, not blindly continued or blindly discarded.

## Known Existing Repo

```text
C:\dev\wptransormed2
```

Note: confirm final repo spelling before public launch.

## Strategy

Build into the existing repo, but do it through a controlled v5 foundation alignment branch.

Decision:

- Do not start from a blank repo.
- Do not continue feature work blindly on the existing repo.
- Create a branch such as `v5-foundation-alignment`.
- Audit the current codebase first.
- Keep, port, rewrite, or delete existing code according to the audit.
- Treat the existing repo as a valuable partial implementation, not final architecture.

Use the existing repo as a source of reusable code, not as unquestioned architecture.

## Existing Admin Architecture Reconciliation

The existing repo may already implement significant admin chrome behavior in a flat file/class pattern, especially:

```text
includes/class-admin.php
```

Some newer specs recommend namespaced files such as:

```text
includes/Admin/Admin_Chrome.php
includes/Admin/Topbar.php
includes/Admin/Sidebar_Sections.php
```

The audit must explicitly decide one of these paths:

1. Refactor the existing flat `class-admin.php` implementation into the newer namespaced structure.
2. Keep the existing flat class pattern and adapt the specs to match it.
3. Use a hybrid approach only if the boundaries are documented.

Do not create duplicate competing admin chrome systems.

The chosen architecture must be documented in:

```text
docs/audits/existing-codebase-audit.md
```

## Porting Rules

A module or class can be ported only if it passes:

1. v5.2 scope alignment.
2. v5.3 build authority alignment.
3. Security review.
4. Module isolation requirements.
5. Naming/registry standards.
6. No deprecated/deferred scope dependency.
7. Verification checklist.
8. UI consistency review.

## Do Not Port

Do not port code for:

- Cut features.
- Deferred features.
- Old "do everything" positioning.
- Desktop-only app UIs.
- Modules with no safe-mode compatibility.
- Modules with unclear data retention.
- Modules that load hooks/assets while inactive.

## Audit First

Before building Phase 1, create an existing-code audit:

```text
docs/audits/existing-codebase-audit.md
```

Audit columns:

- File/module
- Purpose
- Current status
- Keep / port / rewrite / delete
- Scope alignment
- Security notes
- Dependencies
- Required changes

---

# 10. Custom Admin Footer and White Label Relationship

Custom Admin Footer should not feel like a separate disconnected feature from White Label.

## Custom Admin Footer — Core

Simple version.

### Behavior

- Replace default WordPress admin footer text.
- Plain text or simple safe link.
- Admin-only configuration.
- No full HTML builder in Core.
- No per-role footer in Core.

## White Label — Pro

White Label subsumes Custom Admin Footer.

### Additional Behavior

- HTML footer mode.
- Per-role footer messages.
- Agency/client footer presets.
- Replace WPTransformed branding.
- Custom admin logo.
- Custom help tabs.
- Admin color branding.
- Hide upgrade prompts from client users.

## UI Rule

If Pro White Label is active, the Custom Admin Footer Core settings should appear as part of White Label rather than as an unrelated duplicate panel.

---

# 11. Developer Extensibility: Hooks and Filters

WPTransformed must expose documented hooks and filters.

This matters for:

- Companion Forms plugin.
- Companion CPT plugin.
- Companion Search & Replace plugin.
- Third-party developers.
- Agency customizations.
- Future module ecosystem.

## Naming Convention

All public hooks should use:

```php
wpt_*
```

Do not expose random inconsistent hook names.

## Public Hook Stability

Documented hooks are part of the public API.

Changing a documented hook is a breaking change and requires migration notes.

## Core Actions

Recommended action hooks:

```php
do_action('wpt_loaded');
do_action('wpt_modules_registered', $modules);
do_action('wpt_module_enabled', $module_id);
do_action('wpt_module_disabled', $module_id);
do_action('wpt_module_settings_saved', $module_id, $settings);
do_action('wpt_notification_created', $notification);
do_action('wpt_audit_log_event', $event);
do_action('wpt_email_before_send', $email);
do_action('wpt_email_after_send', $email, $result);
do_action('wpt_before_database_cleanup', $tasks);
do_action('wpt_after_database_cleanup', $results);
do_action('wpt_safe_mode_triggered', $context);
do_action('wpt_blueprint_applied', $blueprint_id);
do_action('wpt_migration_completed', $source_plugin, $result);
```

## Core Filters

Recommended filters:

```php
apply_filters('wpt_registered_modules', $modules);
apply_filters('wpt_module_categories', $categories);
apply_filters('wpt_module_settings_schema', $schema, $module_id);
apply_filters('wpt_module_default_settings', $settings, $module_id);
apply_filters('wpt_user_can_manage_module', $can, $user_id, $module_id);
apply_filters('wpt_notification_actions', $actions, $notification);
apply_filters('wpt_audit_event_data', $event);
apply_filters('wpt_schema_output', $json_ld, $context);
apply_filters('wpt_llms_txt_content', $content);
apply_filters('wpt_email_mailers', $mailers);
apply_filters('wpt_email_routing_rules', $rules);
apply_filters('wpt_conflict_rules', $rules);
apply_filters('wpt_dashboard_widgets', $widgets);
apply_filters('wpt_command_palette_items', $items);
apply_filters('wpt_client_safe_hidden_menus', $menus, $role);
```

## Hook Documentation

Create:

```text
docs/developer/hooks.md
```

Each documented hook should include:

- Name
- Type: action/filter
- Parameters
- Return value, if filter
- Since version
- Example usage
- Stability status

---

# 12. Canonical Module Slug Registry

Codex must not invent inconsistent module IDs.

Every module needs one canonical slug.

## Rules

- Lowercase.
- Kebab-case.
- Stable forever after release.
- Used for:
  - Settings keys
  - REST routes
  - Database references
  - Migration maps
  - Logs
  - Docs paths
  - Module registry

## Example

```json
{
  "id": "email-delivery",
  "title": "Email Delivery",
  "category": "email-delivery",
  "tier": "core",
  "risk": "moderate"
}
```

## Initial Slug Registry

### Setup / Core

- `setup-wizard`
- `blueprints`
- `dashboard`
- `module-library`
- `migration-center`
- `recovery-center`
- `support-package`

### Admin Experience

- `admin-bar-manager`
- `command-palette`
- `dashboard-manager`
- `notification-center`
- `list-table-enhancements`
- `keyboard-shortcuts`
- `admin-bookmarks`
- `smart-menu-organizer`
- `admin-menu-editor`
- `custom-admin-footer`

### Client-Safe / Users

- `client-safe-mode`
- `client-dashboard`
- `page-builder-restrictions`
- `plugin-theme-visibility`
- `multiple-user-roles`
- `view-as-role`
- `role-manager`
- `temporary-user-access`

### Content & Media

- `content-duplication`
- `public-preview`
- `disable-comments`
- `disable-gutenberg`
- `external-links-new-tab`
- `external-permalinks`
- `auto-publish-missed-schedule`
- `post-type-switcher`
- `content-order`
- `terms-order`
- `bulk-content-editor`
- `page-hierarchy-organizer`
- `preserve-taxonomy-hierarchy`
- `media-folders`
- `media-replace`
- `svg-upload`
- `avif-upload`
- `image-sizes-panel`
- `media-visibility-control`
- `media-infinite-scroll`
- `local-user-avatars`
- `custom-post-types`
- `permalink-slug-manager`

### Search & AI

- `search-appearance`
- `open-graph-basics`
- `basic-schema`
- `schema-builder`
- `llms-txt`
- `ai-visibility`
- `search-console-insights`
- `ga4-content-insights`

### Security & Login

- `login-protection`
- `change-login-url`
- `two-factor-authentication`
- `session-manager`
- `audit-log`
- `basic-hardening`
- `captcha-protection`
- `password-protection`
- `password-policy`
- `login-notifications`
- `login-id-type`
- `login-logout-menu`
- `redirect-after-login`

### Email

- `email-delivery`
- `email-log`
- `email-routing`
- `email-alerts`

### Performance / Database

- `asset-hygiene`
- `heartbeat-control`
- `revision-control`
- `image-upload-control`
- `auto-clear-caches`
- `disable-thin-archives`
- `object-cache-status`
- `database-optimizer`

### Design

- `login-designer`
- `admin-theme`
- `white-label`
- `environment-indicator`

### Code / Utilities

- `header-body-footer-code`
- `custom-css`
- `code-snippets`
- `redirect-manager`
- `404-monitor`
- `maintenance-mode`
- `robots-txt-manager`
- `ads-txt-manager`
- `cron-manager`
- `search-replace`

### Integrations / Reports / Developer

- `integrations-hub`
- `forms-bridge`
- `agency-client-reports`
- `client-handoff-checklist`
- `system-summary`
- `error-log-viewer`
- `conflict-detector`
- `environment-detector`
- `wp-cli`


## Module Hierarchy Regeneration Requirement

The existing repo may contain older hierarchy files such as:

```text
docs/module-hierarchy.md
```

Those files are not authoritative if they describe the older 134/141-module structure or the v3 roadmap.

Before building the Module Grid or running any Session 3 prompt, regenerate the module hierarchy from:

1. `wptransformed-canonical-feature-scope-v5-2-3.md`
2. the canonical slug registry in this addendum
3. the current Core/Pro/Deferred decisions

Do not build the Module Grid from the old 141-module hierarchy.

The regenerated hierarchy should clearly separate:

- Core launch modules
- Pro launch modules
- Companion integrations
- Deferred/cut modules
- App pages
- System-only services

## Permalink / Slug Manager Placement

`permalink-slug-manager` belongs under Content & Media.

It does not require a dedicated sidebar item in the Global Admin Transformation mapping. It should be discoverable through:

- Module Library
- Content & Media module category
- Command Palette
- Relevant post/page/list-table actions when enabled

---

# 13. Pro Gating Rules

Pro gating must be defined before implementation.

## Recommended Packaging Model

Use one of two models.

### Model A — Single Plugin with Licensed Pro Modules

- Free plugin available on WordPress.org.
- Pro code may be delivered via licensed update channel.
- Locked Pro cards appear in Core.
- Pro modules do not execute without valid license.

### Model B — Free Core Plugin + Pro Add-on Plugin

- `wptransformed`
- `wptransformed-pro`

Recommended for cleaner WordPress.org compliance and lower free-plugin size.

## Recommended Choice

Use **Model B** unless licensing/distribution strategy requires Model A.

## Locked Pro Module Behavior

If Pro is not active/licensed:

- Show Pro module cards as locked.
- Do not load Pro module files.
- Do not register Pro hooks.
- Do not create Pro-only tables unless needed by Core.
- Do not expose Pro REST endpoints.
- Preserve Pro settings if license expires.
- Disable Pro execution gracefully if license expires.

## License Expiration Behavior

When a license expires:

- Existing Pro settings remain stored.
- Pro modules stop running after a grace state if required.
- User sees clear admin notice.
- No destructive cleanup.
- No data deletion.
- White-label/client-safe critical settings should fail gracefully, not break admin access.

## Upgrade UX

Pro upsells should be:

- Clear.
- Minimal.
- Not shown to non-admin client/editor users.
- Hidden from Client-Safe Mode users.
- Not naggy.

---

# 14. Permission Model

WPTransformed needs its own capabilities.

Do not rely only on `manage_options` for everything.

## Recommended Capabilities

```text
manage_wpt
manage_wpt_modules
manage_wpt_settings
manage_wpt_client_safe
manage_wpt_security
manage_wpt_email
manage_wpt_search_visibility
manage_wpt_code
manage_wpt_database
manage_wpt_reports
manage_wpt_integrations
view_wpt_logs
export_wpt_data
run_wpt_dangerous_tools
manage_wpt_white_label
```

## Defaults

Administrators receive all capabilities by default.

Client/editor roles should receive none unless explicitly granted.

## Dangerous Tools

Require:

```text
run_wpt_dangerous_tools
```

for:

- Search & Replace
- Code Snippets
- Database delete operations
- Bulk content editor
- Role/capability edits
- Disable REST API
- Disable updates
- Login URL changes

---

# 15. Safety Check Modal

Risky actions must use a shared Safety Check Modal.

## Required For

- Search & Replace
- Database cleanup destructive tasks
- Code Snippets activation
- PHP snippet changes
- Change Login URL
- Disable REST API
- Disable Updates
- Bulk Content Editor
- Role/capability changes
- Media Replace
- Schema output override when SEO plugin exists
- Import/migration operations

## Modal Requirements

- Plain-English risk explanation.
- Affected data summary.
- Backup reminder.
- Dry-run option where supported.
- Confirmation checkbox.
- Typed confirmation for dangerous operations.
- Link to Recovery Center.
- Operation log entry after completion.

## Typed Confirmation Examples

- `CHANGE LOGIN URL`
- `RUN SEARCH REPLACE`
- `DELETE DATABASE DATA`
- `ENABLE PHP SNIPPET`

---

# 16. Per-Module Spec Template

Every module must have a spec file before build.

This template intentionally keeps **Verification & Acceptance Criteria** together. Verification steps are the binary test checklist; acceptance criteria define what "done" means. They belong in one section so module specs stay easier to maintain.

## Path Format

```text
docs/modules/{category}/{slug}.md
```

Example:

```text
docs/modules/email/email-delivery.md
```

## Required Sections

```markdown
# Module Name

## 1. Metadata
- ID:
- Category:
- Tier:
- Risk:
- Status:
- Replaces:
- Related modules:
- Default enabled:
- Onboarding profiles:

## 2. One-Liner
Plain-English module description.

## 3. Scope
What this module does.

## 4. Things NOT To Do
Non-negotiable boundaries.

## 5. What the User Sees
UI, screens, settings, notices, cards, actions.

## 6. Settings Schema
JSON schema with defaults.

## 7. Hooks & Implementation
WordPress hooks, filters, priorities, classes, services.

## 8. REST API
Endpoints, methods, permissions, payloads.

## 9. Database Usage
Tables, options, metadata, retention.

## 10. Exact Behavior
Step-by-step behavior.

## 11. Edge Cases
What can go wrong and how to handle it.

## 12. Conflicts & Dependencies
Known plugin conflicts, dependencies, module interactions.

## 13. Security & Permissions
Capabilities, nonces, sanitization, escaping, data protection.

## 14. Mobile/Responsive Behavior
Desktop, tablet, mobile layouts.

## 15. Data Retention / Uninstall
What data is kept/deleted.

## 16. Verification & Acceptance Criteria
Binary pass/fail checklist plus definition of done.

## 17. Deferred Features
Ideas not included in this build.

## 18. Known Gotchas
Implementation warnings.
```

## Verification Threshold

A module is not complete unless verification steps pass.

Recommended threshold:

```text
14+/18 verification steps pass before ship
```

If fewer pass:

- Do not ship.
- Log gaps.
- Fix or explicitly defer.

---

# 17. Build Phase Requirements

Each phase should have:

1. Phase objective.
2. Module list.
3. Required per-module specs.
4. Shared services needed.
5. Database migrations.
6. UI routes.
7. REST routes.
8. Acceptance criteria.
9. Regression tests.
10. Known non-goals.

## Phase 1 Must Not Build Random Modules

Phase 1 should build foundation only:

- Module registry.
- Module loader.
- Settings storage.
- Permission model.
- Recovery Center.
- Conflict Detector.
- Dashboard shell.
- Module Library.
- Basic UI system.
- Import/export skeleton.
- Existing code audit.
- **Admin chrome foundation (sidebar reskin + topbar + global CSS).**
- **Editor Dashboard shell (the landing page after wizard).**

## Phase 2 Should Build Onboarding/Admin Experience

- Setup Wizard.
- Presets.
- Admin Bar Manager.
- Dashboard Manager.
- Notification Center basics.
- Command Palette.
- List Table Enhancements.
- Smart Menu Organizer basics.
- Client-Safe Mode basics.
- Disable Gutenberg.
- **Plugin detection and sidebar slotting (WooCommerce, builders, Forms).**

## Phase 3 Should Build Everyday Utilities

- Content Duplication.
- Public Preview basics.
- Permalink / Slug Manager basics.
- Disable Comments.
- Media Replace.
- SVG Upload.
- Media Folders basics.
- Redirect Manager basics.
- 404 Monitor basics.
- Header/Footer Code.
- Custom CSS.
- Robots.txt.
- Ads.txt.

## Phase 4 Should Build Security/Email/Performance

- Login Protection.
- Change Login URL.
- Basic 2FA.
- Session Manager basics.
- Audit Log basics.
- Basic Hardening.
- Email Delivery Core.
- Heartbeat Control.
- Revision Control.
- Database Optimizer basics.
- Auto-Clear Caches.
- Object Cache Status.
- Login Notifications basics.
- Environment Detector.

## Phase 5 Should Build Search & AI Basics

- Search Appearance.
- Open Graph Basics.
- Basic Schema.
- llms.txt Generator.
- Metadata status columns.
- SEO plugin conflict detection.

## Phase 6 Should Build Pro App Pages

- Admin Menu Editor.
- White Label.
- Login Designer Pro.
- Schema Builder Pro.
- Email Log / Email Pro.
- Agency Reports.
- Agency Blueprints.
- Client Dashboard Builder.
- Search & Replace companion integration.
- Custom Post Types companion integration.

---

# 18. Existing Code Audit Template

Create:

```text
docs/audits/existing-codebase-audit.md
```

Template:

```markdown
# Existing Codebase Audit

## Repo
C:\dev\wptransormed2

## Audit Date

## Summary

## File / Module Inventory

| File/Module | Purpose | Current Status | Action | Scope Alignment | Security Notes | Dependencies | Required Changes |
|------------|---------|----------------|--------|-----------------|----------------|--------------|------------------|
|            |         |                | keep / port / rewrite / delete | aligned / partial / no | | | |

## Port Candidates

## Rewrite Candidates

## Delete Candidates

## Deferred/Cut Code Found

## Risks

## Recommended Next Build Step
```

---

# 19. Acceptance Rules for Codex / Claude Code

This section should be copied verbatim into the repo root `CLAUDE.md` so Claude Code reads it at session start. Do not assume Claude Code will discover this addendum automatically.

Recommended `CLAUDE.md` block:

```markdown
# WPTransformed Claude Code Rules

Before implementing any module, read:

1. `wptransformed-canonical-feature-scope-v5-2-3.md`
2. `wptransformed-build-authority-addendum-v5-3-5.md`
3. `wptransformed-admin-transformation-spec-v1-3.md`
4. The relevant per-module spec in `docs/modules/{category}/{slug}.md`

Do not build a module directly from the canonical scope file alone.

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
```

---

# 20. Immediate Next Steps

## Step 1

Adopt v5.2 + v5.3.6 + admin-transformation-spec as planning authority.

## Step 2

Create the existing codebase audit.

## Step 3

Create module specs for Phase 1 foundation.

Recommended first spec files:

```text
docs/modules/system/module-registry.md
docs/modules/system/module-loader.md
docs/modules/system/settings-storage.md
docs/modules/system/permission-model.md
docs/modules/system/recovery-center.md
docs/modules/system/conflict-detector.md
docs/modules/system/module-library.md
docs/modules/system/dashboard-shell.md
docs/modules/system/import-export.md
```

## Step 4

Create module specs for first user-facing Phase 2 modules:

```text
docs/modules/setup/setup-wizard.md
docs/modules/admin-experience/admin-bar-manager.md
docs/modules/admin-experience/command-palette.md
docs/modules/admin-experience/notification-center.md
docs/modules/client-safe/client-safe-mode.md
docs/modules/content-media/disable-gutenberg.md
```

## Step 5

Only then start Codex implementation.

---

# 21. Global Admin Transformation

This is not a module feature. This is the product identity.

WPTransformed does not add a settings page to WordPress. It transforms the entire WordPress admin experience. On activation + wizard completion, the user's wp-admin looks and feels like a modern SaaS application.

## Core Principle: Style, Don't Replace

The existing WordPress admin menu (`#adminmenu`), admin bar (`#wpadminbar`), and page structure stay intact under the hood. WPTransformed applies a CSS overhaul + JS enhancements + PHP-injected elements on top. This preserves 100% plugin compatibility — WooCommerce, Elementor, Yoast, ACF, Gravity Forms, and every other plugin keeps working because the underlying WordPress admin structure is untouched.

### What this means in practice

- `admin-global.css` reskins the NATIVE WordPress admin sidebar and chrome.
- We STYLE `#adminmenu` / `#adminmenuwrap` / `#wpadminbar`, we don't replace them.
- Never call `remove_menu_page()`. Third-party plugin menu items must continue working.
- Section separators (CONTENT, SECURITY, DESIGN, TOOLS, CONFIGURE) are injected via `admin_menu` hook at priority 999 as separator items with custom CSS classes.
- Smart Menu Organizer reorders existing WP menu items into the correct groups via JS.
- Plugin detection (WooCommerce, Elementor, Divi, Bricks, Oxygen, WPBakery, BuiltRight Forms) auto-slots detected plugins into the right sidebar section.

### Do NOT

- Do not build a custom sidebar div that replaces `#adminmenu`.
- Do not build a custom admin bar div that replaces `#wpadminbar`.
- Do not use `remove_menu_page()` or `remove_submenu_page()`.
- Do not break third-party plugin menu items.
- Do not require React, Vite, or any build step for the global admin chrome. Future complex app pages may use richer tooling only if explicitly approved and isolated from global chrome.

## The "Holy Shit" Moment

The activation sequence:

1. Activate WPTransformed.
2. Fullscreen wizard overlay (profile, vibe, modules).
3. Apply & Transform.
4. Redirect to Editor Dashboard (`admin.php?page=wpt-dashboard`).

The user's WordPress admin now looks completely different — gradient sidebar, glassmorphic topbar, organized sections, modern typography, dark mode — but nothing is broken. Every plugin still works. Every menu item still appears. The transformation is purely visual and organizational.

## Sidebar Reorganization

Smart Menu Organizer groups all WordPress admin menu items into five sections:

```text
CONTENT    — Dashboard, Posts, Pages, Media, Comments, Menu Editor, detected Forms plugin
ECOMMERCE  — detected WooCommerce/EDD/SureCart (conditional)
SECURITY   — WPT security pages, Audit Log, Login Protection
DESIGN     — Login Designer, White Label, Appearance, detected page builders
TOOLS      — Performance, Database, Developer
CONFIGURE  — Modules, Settings, Users, Plugins
```

Unrecognized third-party plugin menu items go below CONFIGURE as a catch-all. They are never hidden.

## Editor Dashboard

`admin.php?page=wpt-dashboard` replaces the default WordPress dashboard as the landing page. It shows real WordPress data — recent posts, drafts, scheduled content, quick actions, site health — in a modern bento layout. This is not a module grid. It is a content workspace.

The default WordPress dashboard (`index.php`) is still accessible but no longer the first thing users see.

## Relationship to v5.2 Modules

The admin transformation is delivered by these v5.2 modules working together:

- **Smart Menu Organizer** — sidebar section grouping and plugin detection
- **Admin Bar Manager** — topbar cleanup and custom items
- **Admin Theme** — dark mode, color schemes, environment indicator
- **Dashboard Manager** — widget control, column layout
- **Command Palette** — ⌘K navigation overlay
- **Notification Center** — notice drawer replacing inline notices
- **Client-Safe Mode** — role-based menu/widget hiding

But the global CSS reskin (`admin-global.css`) is not a module — it is foundational infrastructure that loads on every admin page regardless of which modules are active. It belongs in Phase 1 as part of the admin chrome foundation, not as a toggleable module.

## Full Implementation Details

The complete specification for the admin transformation — including exact CSS values, sidebar gradients, typography, animation curves, plugin detection rules, WP menu item mapping, topbar layout, and session-by-session build prompts — is in the companion document:

```text
wptransformed-admin-transformation-spec-v1-3.md
```

---

# 22. Design System & Visual Language

WPTransformed has a defined visual language. Every admin page, app page, module settings panel, and UI component must follow it.

## Tech Stack

- PHP 7.4+ (strict types)
- Native WordPress admin chrome — NO React, NO Vite, NO build step for global chrome
- Vanilla JS for global chrome (no jQuery dependency for new code)
- Future complex app pages may use richer tooling only when explicitly approved, isolated, and documented in their per-module specs
- MySQL/MariaDB with JSON columns
- CSS custom properties (`--wpt-*` namespace)

## Typography

- Body: Outfit (Google Fonts)
- Code/Monospace: JetBrains Mono (Google Fonts)
- Icons: Font Awesome 6

All three are enqueued on ALL admin pages via `admin-global.css`.

## Color Palette

### Sidebar

- Gradient: `linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%)`
- Dark mode: `linear-gradient(165deg, #071020 0%, #0d1f42 45%, #1e3a8a 100%)`

### Accent Colors

- Primary blue: `#2563eb`
- Accent green: `#06d6a0`
- Amber/warning: `#f59e0b`
- Rose/error: `#f43f5e`
- Violet: category accent

### Surface Colors

- Defined via `--wpt-*` CSS custom properties
- Light and dark mode token sets
- All values extracted verbatim from reference HTML mockups

## Component Patterns

### Bento Stat Cards

Row of 3-4 stat cards at top of app pages. Icon + large number + label + optional trend indicator.

### Glassmorphic Surfaces

`backdrop-filter: blur()` on cards and panels. Gradient border on hover.

### Toggle Switches

44×26px, spring `cubic-bezier` transition, blue when checked.

### Card Styles

Rounded corners, subtle shadow, gradient border on hover, consistent padding.

## Design Reference Files

All design values are extracted from HTML mockups at:

```text
assets/admin/reference/
├── dashboard/          — Main dashboard and editor dashboard
├── components/         — Tooltip, command palette
├── app-pages/          — DB Optimizer, Audit Log, Login Designer, Menu Editor, White Label
└── mockups/            — Additional reference screenshots
```

CSS values are copied verbatim from these files. Do not modify colors, spacing, typography, border-radius, or animations without explicit instruction.

---

# 23. Tech Stack & Module Architecture Contract

## File Structure

```text
wptransformed.php              → Bootstrap
includes/                      → Core classes (Core, Settings, Admin, Module_Base, Module_Registry, Safe_Mode)
modules/{category}/            → Module classes + their CSS/JS
assets/admin/css/admin.css     → Shared settings page styles
assets/admin/css/admin-global.css → Global admin reskin (sidebar, topbar, typography)
assets/admin/js/admin.js       → Shared settings page JS
assets/admin/reference/        → Design reference HTML mockups (read-only)
docs/                          → Specs, architecture, decisions
```

## Naming Conventions

- Files: `class-{slug}.php` (e.g., `class-content-duplication.php`)
- Classes: `WPTransformed\Modules\{CategoryNamespace}\{Class_Name}`
- Module IDs: lowercase with hyphens (e.g., `content-duplication`)
- CSS/JS handles: `wpt-{module-slug}` (e.g., `wpt-dark-mode`)
- DB table: `{$wpdb->prefix}wpt_settings`
- Nonces: `wpt_save_{module_id}` for settings, `wpt_{action}_{id}` for custom actions
- User meta keys: `wpt_{feature}` (e.g., `wpt_dark_mode`)
- Hooks/filters: `wpt_{description}` (e.g., `wpt_registered_modules`)

## Security Rules (non-negotiable)

1. Every PHP file starts with: `if ( ! defined( 'ABSPATH' ) ) exit;`
2. Every custom action: verify nonce + check capability before anything else
3. Every settings save: goes through module's `sanitize_settings()` — sanitize every value
4. Every echo: `esc_html()`, `esc_attr()`, `esc_url()` as appropriate
5. Every DB query: `$wpdb->prepare()` with placeholders

## Module Pattern

Every module extends `Module_Base` and implements:

- `get_id()`, `get_title()`, `get_category()`, `get_description()` — identity
- `init()` — add hooks/filters here, NOT in constructor
- `get_default_settings()` — return defaults array
- `render_settings()` — echo HTML form fields (framework handles form wrapper + save)
- `sanitize_settings($raw)` — clean and return settings array
- `enqueue_admin_assets($hook)` — conditional asset loading
- `get_cleanup_tasks()` — declare persistent data for uninstall

Read settings with: `$settings = $this->get_settings();` (returns saved merged with defaults)

## Build Rules

1. Build ONE module at a time.
2. Each module must pass ALL verification steps before starting the next.
3. Verification = manually confirm on a real WordPress install, not just "tests pass."
4. If something doesn't work, fix it before moving on.
5. When in doubt, follow the pattern of the last working module.

## Review Protocol

After completing any module, switch to /fast and run adversarial review for: WP Engine compat, SQL safety, hook conflicts, cache safety, PHP compat, test quality, input validation, error handling. Fix criticals before committing.

---

# 24. Existing Build Progress

The existing repo has significant work completed from the UI restructure sessions. This work should be audited against v5.2 scope alignment during the Phase 1 codebase audit, but represents real, tested code.

## Completed Sessions (from repo CLAUDE.md)

| Session | Scope | Status | Commit |
|---------|-------|--------|--------|
| 1 | Sidebar reskin + topbar + global styling | Complete, runtime-verified | afa2aae |
| 2 | Editor Dashboard with real data | Complete, runtime-verified | 7365020 |
| 3 | Module Grid — 28-parent hierarchy with expandable sub-panels | Complete, runtime-verified | 7b25021 + fixes |
| 4 | Database Optimizer + Audit Log app pages | Complete, runtime-verified | 6a28a91 + b35d44e |
| 5.1 | Login Designer app page | Complete, runtime-verified | 18a25d0 + fix |
| 5.2 | Menu Editor app page | Complete, runtime-verified | 88508b0 |
| 6 | Command palette + plugin detection | Partial | cd44f4e |

## Not Yet Built

- Session 5.3: White Label app page (pre-flight recon done, see `docs/session-5-wrapped-module-fields.md`)
- Session 7: Activation wizard
- Session 8: QA and polish
- Session 6 completion: plugin detection wiring

## Key Architecture Already In Place

- `admin-global.css` with `--wpt-*` CSS variables and native sidebar reskin
- `Module_Hierarchy` data layer as canonical parent/sub-module source
- `render_parent_card()` helper for module grid cards
- `wpt_toggle_parent` AJAX batch endpoint for parent activation
- App page registration pattern (`class-*-app.php` at `wpt-{slug}`)
- Login Designer, Menu Editor, Database Optimizer, Audit Log all have working app pages
- HTML5 native drag-drop (no jQuery UI) established as the drag pattern

## Known Issues to Address in Audit

- White_Label `is_login_customizer_active()` checks module id `login-customizer` but registry uses `login-branding`
- Module hierarchy count (141 in repo) differs from v5.2 scope — audit will reconcile
- v3 roadmap modules (200 total) in repo docs are out of scope for v5.2 build — mark as deferred
- Some session 6 plugin detection wiring may be incomplete

---


# 25. Admin Chrome Safety Contract

The Global Admin Transformation is foundational infrastructure and must remain compatibility-first.

## Global Chrome Rule

The global admin transformation may style and enhance native WordPress chrome, but it must not globally remove, hide, replace, or block native admin structures.

Global chrome may:

- Style `#adminmenu`, `#adminmenuwrap`, and `#wpadminbar`.
- Inject section labels.
- Inject search trigger, user area, and upgrade card.
- Reorder/slot menu items visually when safe.
- Add classes/data attributes for styling.
- Add breadcrumbs and topbar enhancements.
- Apply dark/light/system theme.

Global chrome must not:

- Replace `#adminmenu` with a custom sidebar.
- Replace `#wpadminbar` with a custom topbar.
- Globally call `remove_menu_page()` or `remove_submenu_page()`.
- Hide unrecognized third-party plugin menu items.
- Break access to native WordPress admin pages.
- Break plugin admin pages.
- Override editor canvas styles.
- Depend on a JavaScript build step.
- Prevent Safe Mode recovery.

## Client-Safe Mode Exception

Client-Safe Mode is the explicit role-based access layer.

Client-Safe Mode may hide or restrict menu items only when:

1. The module is explicitly enabled.
2. The rule is role/user based.
3. The affected role/user is not a full administrator unless explicitly configured.
4. Recovery access remains available to authorized administrators.
5. The hidden/restricted items are documented in the Client-Safe Mode settings UI.

This means:

- Global Admin Transformation must never globally hide menu pages.
- Client-Safe Mode may intentionally hide technical menus from editors, clients, or selected roles.

## CSS Isolation Rules

Global CSS should be defensive.

Allowed global styling targets:

- `#adminmenu`
- `#adminmenuwrap`
- `#wpadminbar`
- `body.wp-admin`
- WPTransformed chrome classes prefixed with `.wpt-`
- WPTransformed app page wrappers such as `.wpt-admin`, `.wpt-page`, `.wpt-app`

Avoid broad styling of:

- `.button`
- `.postbox`
- `table`
- `input`
- `.notice`
- `.wrap`
- editor canvas elements
- third-party plugin app internals

When styling generic WordPress elements, scope under WPTransformed pages whenever possible:

```css
.wpt-page .button { }
.wpt-app .postbox { }
.wpt-admin table { }
```

## Safe Mode Requirement

Safe Mode must be able to bypass the global admin chrome.

At minimum, Safe Mode should be able to:

- Stop loading `admin-global.css`.
- Stop loading `admin-global.js`.
- Stop injecting sidebar extras.
- Stop topbar enhancements.
- Preserve the native WordPress admin menu.
- Preserve administrator access to WPTransformed Recovery Center.

## Implementation Reminder

The admin chrome is a product-defining layer, but compatibility comes first.

A beautiful admin that breaks plugin pages is a failed implementation.



# 26. Reference HTML Cleanup

The repo currently may contain duplicate reference HTML files:

- organized copies under `assets/admin/reference/{dashboard,components,app-pages,mockups}/`
- loose copies directly under `assets/admin/reference/`

The organized subfolder paths are authoritative.

## Cleanup Decision

Before additional UI work:

1. Delete root-level duplicate reference HTML files if an equivalent organized copy exists.
2. Move `wp-transformation-dashboard.html` into `assets/admin/reference/dashboard/` if it is still needed.
3. Delete `wp-transformation-dashboard.html` if it is superseded by `wp-transformation-editor.html`.
4. Update docs/session prompts to reference only organized subfolder paths.
5. Do not modify reference HTML mockups during implementation; they are read-only design sources.

## Reason

Duplicate references cause Codex/Claude to read the wrong mockup or copy stale CSS.


# End of Build Authority Addendum v5.3.6
