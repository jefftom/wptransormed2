# WPTransformed — Admin Transformation Spec v1.3

## Purpose

This document is the complete implementation specification for the global WordPress admin transformation. It contains every CSS value, every sidebar mapping rule, every plugin detection pattern, every design token, and every session build prompt needed to build the admin chrome.

This file is Layer 2b in the source-of-truth hierarchy. It answers:

> Exactly how does the admin transformation look, feel, and get built?

Use alongside:

- `wptransformed-canonical-feature-scope-v5-2-3.md` (scope authority)
- `wptransformed-build-authority-addendum-v5-3-5.md` (build rules)

## v1.3 Notes

This revision updates references to the clean current docs: v5.2.3 canonical scope and v5.3.5 build authority. It keeps the v1.2 profile/menu conflict fixes intact.

## v1.2 Notes

This revision resolves profile/menu conflicts. It references the canonical profile list from v5.2.2 instead of redefining profiles, keeps Comments visible in the native CONTENT grouping, relocates native Tools under TOOLS instead of hiding it, adds the module-hierarchy regeneration requirement, and adds reference HTML cleanup rules.

## v1.1 Notes

This revision clarifies that Global Admin Transformation must not hide or remove menu pages globally. Client-Safe Mode is the only layer allowed to hide or restrict menu items, and only by explicit role-based configuration. It also clarifies that the no React/Vite rule applies to global admin chrome, not necessarily to future isolated app pages.

---

# 1. Approach: Style, Don't Replace

The existing WordPress admin menu, admin bar, and page structure stay intact. WPTransformed applies CSS + JS + PHP on top.

## How the transformation works

1. **CSS overlay on `#adminmenu` / `#adminmenuwrap`** — gradient background, Outfit font, spacing, active states, hover effects, accent bars.
2. **PHP hook on `admin_menu` (priority 999)** — injects section label separators (CONTENT, SECURITY, DESIGN, TOOLS, CONFIGURE) as menu separator items with custom CSS classes.
3. **Smart Menu Organizer module** — reorders existing WP menu items into the correct groups via JS. Detects WooCommerce, page builders, BuiltRight Forms and slots them into the right sections.
4. **PHP/JS injection** — adds the search bar (triggers command palette), upgrade card, and user profile elements as additional DOM nodes inside `#adminmenuwrap`.
5. **Admin bar reskin** — `#wpadminbar` gets styled as the glassmorphic topbar with breadcrumbs, theme toggle, notification bell, and user avatar.

## Non-negotiable rules

- Never call `remove_menu_page()` or `remove_submenu_page()`.
- Never build a custom sidebar div that replaces `#adminmenu`.
- Never build a custom admin bar div that replaces `#wpadminbar`.
- Every third-party plugin's menu items must continue working.
- No React, no Vite, no build step for global admin chrome.
- Future complex app pages may use richer tooling only when explicitly approved and isolated.
- Client-Safe Mode is the only layer allowed to hide/restrict menu items, and only by explicit role/user configuration.

---

# 1.1 Admin Chrome Safety Contract

The global admin chrome may style and enhance WordPress, but it must not globally remove access.

## Global Admin Transformation may

- Style native WordPress admin chrome.
- Add section labels.
- Add sidebar search trigger.
- Add topbar enhancements.
- Apply visual grouping.
- Add dark/light/system theme.
- Add classes/data attributes for styling.

## Global Admin Transformation must not

- Replace `#adminmenu`.
- Replace `#wpadminbar`.
- Globally remove menu pages.
- Hide unrecognized third-party plugin menu items.
- Block access to native admin pages.
- Break plugin admin pages.
- Override editor canvas styles.
- Prevent Safe Mode recovery.

## Client-Safe Mode exception

Client-Safe Mode may hide or restrict menu items only when explicitly enabled and only according to role/user configuration.

This distinction is non-negotiable:

```text
Global Admin Transformation = style, organize, and relocate native menu items.
Client-Safe Mode = restrict or hide by role when explicitly configured.

Examples:
- Native Tools may be relocated to the TOOLS section, but not hidden.
- Native Comments may remain under CONTENT; hiding Comments requires Client-Safe Mode or Disable Comments configuration.
```

## CSS isolation

Global CSS must focus on native admin chrome and WPTransformed namespaces.

Prefer:

```css
#wpt-selector
.wpt-page .button
.wpt-app .postbox
.wpt-admin table
```

Avoid broad, unscoped styling of:

```css
.button
.postbox
table
input
.notice
.wrap
```

unless it is intentionally limited to WPTransformed pages or proven safe across core/plugin screens.

## Safe Mode bypass

Safe Mode must be able to disable global admin chrome assets and injections so the native WordPress admin can be recovered.


---

# 2. Design Reference Files

Located in `assets/admin/reference/` in the repo. These ARE the design. CSS values are extracted verbatim.

```text
assets/admin/reference/
├── dashboard/
│   ├── wp-transformation-final.html      ← PRIMARY reference for everything
│   ├── wp-transformation-content.html    ← More categories, module density
│   └── wp-transformation-editor.html    ← Editor/Content Dashboard
├── components/
│   ├── tooltip-reference.html            ← Tooltip CSS ONLY
│   └── command-palette-v3.html           ← ⌘K command palette
├── app-pages/
│   ├── database-optimizer-v3.html        ← DB Optimizer app
│   ├── audit-log-v3.html                 ← Audit Log app
│   ├── login-customizer-v3.html          ← Login Customizer app
│   ├── menu-editor-v3.html              ← Menu Editor app
│   └── white-label-v3.html              ← White Label app
└── mockups/
    └── (additional reference screenshots)
```


## Reference HTML Cleanup

The organized subfolder paths above are authoritative.

If the repo contains loose duplicate HTML files directly under `assets/admin/reference/`:

1. Delete loose duplicates when an organized copy exists.
2. Move `wp-transformation-dashboard.html` into `assets/admin/reference/dashboard/` if it is still needed.
3. Delete `wp-transformation-dashboard.html` if superseded by `wp-transformation-editor.html`.
4. Update prompts/docs to reference only organized paths.

Reason: duplicate reference files can cause Codex/Claude to copy stale CSS or the wrong layout.


### Which files to read per build session

| Session | Read these files |
|---------|-----------------|
| 1: Sidebar + Topbar + Global | `dashboard/wp-transformation-final.html` only |
| 2: Editor Dashboard | `dashboard/wp-transformation-editor.html` + shared CSS vars from final |
| 3: Module Grid | `dashboard/wp-transformation-final.html` + `dashboard/wp-transformation-content.html` + `components/tooltip-reference.html` |
| 4: DB Optimizer + Audit Log | `app-pages/database-optimizer-v3.html` + `app-pages/audit-log-v3.html` |
| 5: Login + Menu + White Label | `app-pages/login-customizer-v3.html` + `app-pages/menu-editor-v3.html` + `app-pages/white-label-v3.html` |
| 6: Command Palette | `components/command-palette-v3.html` |
| 7: Wizard | Spec section 7 below |
| 8: QA/Polish | All files |

---

# 3. Sidebar Specification

## Visual tokens (extracted from `dashboard/wp-transformation-final.html`)

| Property | Value |
|----------|-------|
| Background | `linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%)` |
| Dark mode bg | `linear-gradient(165deg, #071020 0%, #0d1f42 45%, #1e3a8a 100%)` |
| Width | 256px |
| Section labels | 9.5px uppercase, letter-spacing 1.1px, `rgba(255,255,255,0.3)` |
| Nav items | 13px, font-weight 500, `rgba(255,255,255,0.65)` default |
| Active item bg | `rgba(255,255,255,0.12)` |
| Active item text | white |
| Active accent bar | green `#06d6a0`, 3px left border |
| Hover bg | `rgba(255,255,255,0.08)` |
| Hover text | `rgba(255,255,255,0.9)` |
| Count badges | `rgba(6,214,160,0.15)` bg, `#06d6a0` text, 10px font, 6px radius |
| PRO badge | `rgba(245,158,11,0.18)` bg, `#f59e0b` text, 8.5px uppercase |
| Search bar | `rgba(255,255,255,0.06)` bg, 9px border-radius, triggers ⌘K |
| Upgrade card | `rgba(255,255,255,0.06)` bg, 11px border-radius, pinned bottom |
| Scrollbar | 4px width, `rgba(255,255,255,0.12)` thumb |
| Logo icon | 36px, `rgba(255,255,255,0.12)` bg, 10px border-radius |

## Sidebar section structure

```text
CONTENT
  Dashboard               → wpt-dashboard (Editor Dashboard)
  Posts              24   → edit.php
  Pages                   → edit.php?post_type=page
  Media                   → upload.php
  Comments                → edit-comments.php
  Menu Editor             → wpt-menu-editor (APP)
  ── if detected ──
  BuiltRight Forms        → forms plugin pages

ECOMMERCE (only if WooCommerce/EDD/SureCart active)
  Orders                  → WC orders
  Products                → WC products
  Customers               → WC customers

SECURITY
  Overview                → wpt-security (dashboard)
  Audit Log               → wpt-audit-log (APP)
  Login Protection        → wpt-login-protection (APP)

DESIGN
  Login Page              → wpt-login-designer (APP)
  White Label      PRO    → wpt-white-label (APP)
  Appearance              → themes.php
  ── if detected ──
  Elementor               → Elementor pages
  Divi Builder            → Divi pages
  Bricks / Oxygen / etc   → builder pages

TOOLS
  Tools                   → tools.php (native Import/Export/Site Health/tools)
  Performance             → wpt-performance (dashboard)
  Database                → wpt-database (APP)
  Developer        PRO    → wpt-developer

CONFIGURE
  Modules            28   → wpt-modules (parent/sub grid)
  Settings                → options-general.php
  Users                   → users.php
  Plugins                 → plugins.php
```

## WP default menu item mapping

| WP Default | Sidebar Section | Notes |
|---|---|---|
| Dashboard | Content → Dashboard | Replaced by Editor Dashboard |
| Posts | Content → Posts | Native, restyled |
| Pages | Content → Pages | Native, restyled |
| Media | Content → Media | Native, enhanced |
| Comments | Content → Comments | Native, restyled. May be hidden only by explicit Client-Safe Mode or Disable Comments configuration. |
| Appearance | Design → Appearance | Themes only |
| Plugins | Configure → Plugins | Admin-only |
| Users | Configure → Users | Native, restyled |
| Tools | Tools → Tools | Native Tools remains accessible. Import, Export, Site Health, and third-party tools are relocated/organized, not hidden. |
| Settings | Configure → Settings | Native, restyled |
| WooCommerce | eCommerce section | If WC active |
| Elementor/Divi/etc | Design section | If detected |
| Unrecognized plugins | Below CONFIGURE | Catch-all, never hidden |

## Plugin detection rules

Detection via `is_plugin_active()` or equivalent:

| Plugin | Detection | Sidebar Placement |
|--------|-----------|------------------|
| WooCommerce | `woocommerce/woocommerce.php` | ECOMMERCE section |
| Easy Digital Downloads | `easy-digital-downloads/easy-digital-downloads.php` | ECOMMERCE section |
| SureCart | `surecart/surecart.php` | ECOMMERCE section |
| Elementor | `elementor/elementor.php` | DESIGN section |
| Divi | Theme check + `divi-builder/divi-builder.php` | DESIGN section |
| Bricks | `bricks/bricks.php` or theme check | DESIGN section |
| Oxygen | `oxygen/ct_oxygen.php` | DESIGN section |
| WPBakery | `js_composer/js_composer.php` | DESIGN section |
| Breakdance | `breakdance/breakdance.php` | DESIGN section |
| Beaver Builder | `bb-plugin/fl-builder.php` | DESIGN section |
| BuiltRight Forms | `wpbuiltright-forms/wpbuiltright-forms.php` | CONTENT section |

---

# 4. Topbar Specification

## Layout

```text
┌──────────────────────────────────────────────────────────────────┐
│  [Page Title / Breadcrumb]                [Theme] [Bell] [Avatar]│
└──────────────────────────────────────────────────────────────────┘
```

## Visual tokens

| Property | Value |
|----------|-------|
| Height | 58px |
| Position | sticky, top 0 |
| Background | glass blur (`backdrop-filter: blur(12px)`) |
| Left side | Page title + breadcrumb navigation |
| Right side | Theme toggle (sun/moon), notification bell, user avatar + dropdown |
| Border bottom | subtle, `rgba(0,0,0,0.06)` |

## Breadcrumb format

`Parent > Section > Current Page` with clickable parents.

---

# 5. Editor Dashboard

`admin.php?page=wpt-dashboard` replaces the default WordPress dashboard as the landing page after the wizard.

## Content (real WordPress data)

- Welcome banner with user name and site stats
- Bento stat cards (posts, pages, comments, users)
- Recent posts list with edit links
- Recent drafts
- Upcoming scheduled content
- Quick actions (New Post, New Page, Duplicate, etc.)
- Site health summary
- Module activity overview

## This is NOT

- A module grid (that's at `wpt-modules`)
- A settings page
- Dummy/placeholder data

Reference mockup: `dashboard/wp-transformation-editor.html`

---

# 6. Global CSS Variables

All WPTransformed CSS uses `--wpt-*` prefixed custom properties. These are defined in `admin-global.css` and provide light/dark mode token sets.

```css
/* Sidebar */
--wpt-sidebar-gradient: linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%);
--wpt-sidebar-width: 256px;

/* Colors */
--wpt-primary: #2563eb;
--wpt-accent: #06d6a0;
--wpt-warning: #f59e0b;
--wpt-error: #f43f5e;
--wpt-violet: /* category accent */;

/* Typography */
--wpt-font-body: 'Outfit', -apple-system, sans-serif;
--wpt-font-mono: 'JetBrains Mono', monospace;

/* Surfaces */
--wpt-bg: /* light/dark mode values */;
--wpt-surface: /* card backgrounds */;
--wpt-border: /* border colors */;
--wpt-text: /* primary text */;
--wpt-text-secondary: /* muted text */;
```

Dark/light mode toggled via `localStorage` key + `wp_usermeta` for persistence across devices.

---

# 7. Activation Wizard

The Activation Wizard must use the canonical site profile list from `wptransformed-canonical-feature-scope-v5-2-3.md`.

Canonical profiles:

1. Personal / Blogger
2. Business Website
3. Ecommerce / Store
4. Agency Client Site
5. Content Team / Editors
6. Developer-Managed Site

## Sequence

1. Activate WPTransformed → fullscreen wizard overlay.
2. Step 1: "What describes you best?" → the six canonical profiles above.
3. Step 2: "Pick your vibe" → Dark Mode / Light Mode / Match System.
4. Step 3: "Here's what we're enabling" → pre-checked modules based on profile → [Apply & Transform →].
5. Redirect → Editor Dashboard (`admin.php?page=wpt-dashboard`).

Profile choices may recommend modules or Client-Safe settings. They must not cause Global Admin Transformation to hide native menu pages globally.

## Default active modules (all profiles)

Command Palette, Clean Admin Bar, Admin Bar Enhancer, Hide Admin Notices, Notification Center, Disable Emojis, Disable Self Pingbacks, Heartbeat Control (60s), SVG Upload, Content Duplication, Last Login Column, Enhance List Tables, Environment Indicator, Smart Menu Organizer, Dark Mode (auto from OS)

## Profile-based additions

- **Personal / Blogger:** revision-control, lazy-load, image-upload-control, optional comments workflow.
- **Business Website:** search-appearance, email-delivery, redirects, 404-monitor, client-safe-mode basics.
- **Ecommerce / Store:** email-delivery, login-protection, 404-monitor, image-upload-control, ecommerce sidebar detection.
- **Agency Client Site:** keyboard-shortcuts, admin-bookmarks, client-dashboard, database-cleanup, white-label, multiple-user-roles.
- **Content Team / Editors:** client-safe-mode, dashboard-manager, public-preview, content-duplication, list-table-enhancements.
- **Developer-Managed Site:** keyboard-shortcuts, code-snippets if explicitly selected, error-log-viewer, admin-body-classes, system-summary.

Note: If a profile should hide Comments or technical menus, the wizard should configure Client-Safe Mode or the relevant explicit module. Global Admin Transformation must not hide those menu items by profile alone.

## Never on by default

change-login-url, disable-gutenberg, disable-rest-api, disable-updates, password-protection, code-snippets (eval risk), search-replace, file-manager, any PRO module

---

# 8. App Pages

Purpose-built full-page admin experiences, not just toggle + settings.

| App Page | Slug | Reference Mockup | Key UI Elements |
|----------|------|-----------------|-----------------|
| Database Optimizer | `wpt-database` | `app-pages/database-optimizer-v3.html` | Bento stats, cleanup tasks, auto-cleanup sidebar, table sizes |
| Audit Log | `wpt-audit-log` | `app-pages/audit-log-v3.html` | Bento stats, event table, filters, pagination, export |
| Login Designer | `wpt-login-designer` | `app-pages/login-customizer-v3.html` | Settings + live preview, templates, device preview |
| Menu Editor | `wpt-menu-editor` | `app-pages/menu-editor-v3.html` | Three-panel: sidebar preview, drag-drop tree, edit properties |
| White Label | `wpt-white-label` | `app-pages/white-label-v3.html` | Settings + live preview, PRO gated |
| Editor Dashboard | `wpt-dashboard` | `dashboard/wp-transformation-editor.html` | Content workspace, real WP data, bento stats |
| Login Protection | `wpt-login-protection` | No mockup — match design system | Bento stats, login attempt log, settings |
| Security Overview | `wpt-security` | No mockup — match design system | Aggregate dashboard |
| Performance Overview | `wpt-performance` | No mockup — match design system | Aggregate dashboard |

---

# 9. Component Library

Reusable UI patterns shared across all pages.

## Bento Stat Cards

Row of 3-4 cards at top of app pages.

- Icon (colored circle background) + large number + label + optional trend
- Light card background, subtle border
- Responsive: stack on mobile

## Data Tables

- Sticky header on scroll
- Sortable columns
- Pagination: `Showing 1 to 10 of N` + page numbers
- Row actions: edit, duplicate, delete (icon buttons)
- Status toggles inline
- Horizontal scroll on mobile

## Contextual Right Sidebar

~280px, used for:
- Recommended Settings (badges + descriptions)
- Safety Tips (green checkmarks + descriptions)
- Placement Guide (for code/script modules)
- Popular Presets (clickable list items)
- Help/documentation links

## Step Wizard

Numbered steps with progress bar. Current step highlighted blue, completed steps checkmarked.

## Sticky Footer Bar

Pinned to bottom of content area:
- Left: Back button (outline)
- Center: (empty or skip link)
- Right: Secondary action (outline) + Primary action (filled blue)

## Toggle Switches

44×26px, spring cubic-bezier transition, blue when checked.

## Pro Upsell Card (sidebar)

Dark card pinned to sidebar bottom:
- "Unlock Pro Features ✨"
- One-line value prop
- Green CTA button

---

# 10. Animations

- `fadeUp` stagger on module cards and stat cards
- Counter animation on bento stat numbers
- `orb-drift` on dashboard banner background
- Sub-module expand: `max-height: 0 → 500px`, `cubic-bezier(0.22, 1, 0.36, 1)`
- Toggle switches: spring cubic-bezier
- Reduced motion: respect `prefers-reduced-motion`

---

# 11. Session Build Prompts

These are the exact prompts for each build session. Each session is a separate CC terminal session.

### Session 1: Sidebar + Topbar + Global

```
Read assets/admin/reference/dashboard/wp-transformation-final.html.
This is the PRIMARY and ONLY design reference for this session.

CRITICAL: You are NOT designing. Extract CSS VERBATIM.
CRITICAL: STYLE native WP admin. No remove_menu_page().

1. Update admin-global.css --wpt-* vars to match mockup.
   Sidebar gradient: linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%)
   Sidebar width: 256px

2. Style #adminmenu/#adminmenuwrap: gradient bg, Outfit font,
   nav spacing, hover, active green accent bar.

3. PHP admin_menu (priority 999): inject section separators.
   Map: CONTENT (Dashboard,Posts,Media,Pages,Comments),
   SECURITY (WPT security pages), DESIGN (Appearance, detected builders),
   TOOLS (Tools), CONFIGURE (Plugins,Users,Settings,WPTransformed).
   Unrecognized plugins go below CONFIGURE.

4. JS inject: search bar top, upgrade card bottom, user avatar bottom.

5. Restyle #wpadminbar: 58px, sticky, glass blur. Left: title+breadcrumb.
   Right: theme toggle, bell, avatar.

6. Enqueue Outfit + JetBrains Mono + FA6 on ALL admin pages.

7. Dark/light toggle. Persist localStorage + user_meta.

8. Global: Outfit body font, card postboxes, styled buttons/inputs.
   Max-width 1340px content areas.

Diff CSS against mockup after each task.
Commit: "Session 1: Sidebar reskin, topbar, global styling"
```

### Session 2: Editor Dashboard

```
Read assets/admin/reference/dashboard/wp-transformation-editor.html.
Read assets/admin/reference/dashboard/wp-transformation-final.html for shared CSS vars.

Build admin.php?page=wpt-dashboard — the default landing page.
Match editor mockup exactly. Real WP data, not dummy data.

Commit: "Session 2: Editor Dashboard with real data"
```

### Session 3: Module Grid

```
Read assets/admin/reference/dashboard/wp-transformation-final.html.
Read assets/admin/reference/dashboard/wp-transformation-content.html.
Read assets/admin/reference/components/tooltip-reference.html.
Before reading `docs/module-hierarchy.md`, regenerate it from v5.2.2 and the canonical slug registry if it still reflects the old 134/141-module structure.

Read the regenerated docs/module-hierarchy.md only after it has been aligned to v5.2.2.

Restructure admin.php?page=wpt-modules using the current canonical hierarchy:
expandable sub-module panels, pill tabs, search, tooltips, badges,
APP PAGE links, AJAX toggles. setup-wizard NOT shown.

Commit: "Session 3: Parent/sub-module grid"
```

### Session 4: DB Optimizer + Audit Log

```
Read assets/admin/reference/app-pages/database-optimizer-v3.html.
Read assets/admin/reference/app-pages/audit-log-v3.html.

Build both app pages matching mockups exactly. Real data.
Commit: "Session 4: DB Optimizer + Audit Log app pages"
```

### Session 5: Login + Menu + White Label

```
Read assets/admin/reference/app-pages/login-customizer-v3.html.
Read assets/admin/reference/app-pages/menu-editor-v3.html.
Read assets/admin/reference/app-pages/white-label-v3.html.
Read docs/session-5-wrapped-module-fields.md for field name conventions.

Build 3 app pages matching mockups. PRO gating on White Label.
Commit: "Session 5: Login Designer, Menu Editor, White Label"
```

### Session 6: Command Palette + Detection

```
Read assets/admin/reference/components/command-palette-v3.html.

Command palette on ALL admin pages. ⌘K/Ctrl+K. Fuzzy search.
Plugin detection: WooCommerce, Elementor, Divi, Bricks, Oxygen,
WPBakery, Breakdance, Beaver Builder, BuiltRight Forms.

Commit: "Session 6: Command palette + plugin detection"
```

### Session 7: Wizard

```
Build setup wizard per this spec Section 7. Profile, dark/light, defaults.
Redirect to Editor Dashboard.
Commit: "Session 7: Activation wizard"
```

### Session 8: QA

```
Polish: animations, responsive (1150px, 800px), accessibility,
plugin compat, performance audit, edge cases.
Commit: "Session 8: QA and polish"
```

---

# 12. Implementation Rules

1. STYLE native admin. No `remove_menu_page()` for global chrome. Client-Safe Mode may hide/restrict menus only by explicit role/user rules.
2. Module toggles: AJAX to `wp_options`, optimistic UI.
3. Sub-module expand: `max-height 0→500px`, `cubic-bezier(0.22,1,0.36,1)`.
4. Dark/light: `localStorage` + `wp_usermeta`.
5. Fonts + FA6: ALL admin pages.
6. Command palette: ALL admin pages.
7. App pages: real data, not dummy.
8. Parent toggle enables/disables all children.
9. Detected plugins (WC, builders, Forms): `is_plugin_active()`, conditional sidebar.
10. setup-wizard: internal, not in module grid.
11. `admin-global.css` loads on EVERY admin page — it is not module-gated, but Safe Mode must be able to bypass it.
12. Never modify reference HTML mockups — they are read-only design source.

---

# End of Admin Transformation Spec
