# WPTransformed UI Restructure — Final Spec v3
## Complete admin transformation blueprint

---

## 1. Vision

WPTransformed replaces the entire WordPress admin experience visually. On activation + wizard, the user's wp-admin looks and feels like a modern SaaS application. The gradient sidebar, glassmorphic surfaces, Outfit typography, command palette, and purpose-built module apps transform the default WordPress admin chrome.

**Critical approach: STYLE, don't replace.** The existing WordPress admin menu, admin bar, and page structure stay intact under the hood. We apply CSS overhaul + JS enhancements + PHP-injected elements on top. This preserves 100% plugin compatibility (WooCommerce, Elementor, Yoast, ACF, Gravity Forms — all keep working).

The "holy shit" moment: activate → wizard → redirect → Editor Dashboard showing their actual content. Not a module grid. A workspace. WordPress looks completely different, but nothing is broken.

---

## 2. Reference Files

Located in `assets/admin/reference/` organized by subfolder. These ARE the design. CSS is extracted verbatim — same hex, same px, same cubic-bezier, same everything.

```
assets/admin/reference/
├── dashboard/
│   ├── wp-transformation-final.html      ← PRIMARY reference for everything
│   ├── wp-transformation-content.html    ← More categories, module density
│   └── wp-transformation-editor.html     ← Editor/Content Dashboard
├── components/
│   ├── tooltip-reference.html            ← Tooltip CSS ONLY
│   └── command-palette-v3.html           ← ⌘K command palette
└── app-pages/
    ├── database-optimizer-v3.html        ← DB Optimizer app
    ├── audit-log-v3.html                 ← Audit Log app
    ├── login-customizer-v3.html          ← Login Customizer app
    ├── menu-editor-v3.html               ← Menu Editor app
    └── white-label-v3.html               ← White Label app
```

### Which files to read per session

| Session | Read these files |
|---------|-----------------|
| 1: Sidebar + Topbar + Global | `dashboard/wp-transformation-final.html` only |
| 2: Editor Dashboard | `dashboard/wp-transformation-editor.html` + `dashboard/wp-transformation-final.html` (for shared CSS vars) |
| 3: Module Grid | `dashboard/wp-transformation-final.html` + `dashboard/wp-transformation-content.html` + `components/tooltip-reference.html` |
| 4: DB Optimizer + Audit Log | `app-pages/database-optimizer-v3.html` + `app-pages/audit-log-v3.html` |
| 5: Login + Menu + White Label | `app-pages/login-customizer-v3.html` + `app-pages/menu-editor-v3.html` + `app-pages/white-label-v3.html` |
| 6: Command Palette | `components/command-palette-v3.html` |

---

## 3. Sidebar Structure

**STYLE the existing WordPress admin sidebar — do NOT replace it.**

`admin-global.css` already exists in the repo with `--wpt-*` prefixed CSS variables and a native sidebar reskin approach. Update its values to match the mockup files exactly.

The sidebar transformation is achieved via:
1. **CSS overlay on `#adminmenu` / `#adminmenuwrap`** — gradient background, Outfit font, spacing, active states, hover effects, accent bars. All from the mockup.
2. **PHP hook on `admin_menu` (priority 999)** — injects section label separators (CONTENT, SECURITY, DESIGN, TOOLS, CONFIGURE) as menu separator items with custom CSS classes.
3. **Smart Menu Organizer module** — reorders existing WP menu items into the correct groups via JS. Detects WooCommerce, page builders, BuiltRight Forms and slots them into the right sections.
4. **PHP/JS injection** — adds the search bar (triggers command palette), upgrade card, and user profile elements as additional DOM nodes inside `#adminmenuwrap`.
5. **Admin bar reskin** — `#wpadminbar` gets styled as the glassmorphic topbar, OR hidden and replaced with a custom topbar div. Either approach works since WP admin bar has good hook support.

### Sidebar visual structure

```
CONTENT
  Dashboard               → wpt-dashboard (Editor Dashboard)
  Posts              24   → edit.php
  Pages                   → edit.php?post_type=page
  Media                   → upload.php
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
  Performance             → wpt-performance (dashboard)
  Database                → wpt-database (APP)
  Developer        PRO    → wpt-developer

CONFIGURE
  Modules            28   → wpt-modules (parent/sub grid)
  Settings                → options-general.php
  Users                   → users.php
  Plugins                 → plugins.php
```

### Sidebar styling rules (from dashboard/wp-transformation-final.html)
- Background: `linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%)`
- Dark mode bg: `linear-gradient(165deg, #071020 0%, #0d1f42 45%, #1e3a8a 100%)`
- Width: 256px
- Section labels: 9.5px uppercase, letter-spacing 1.1px, `rgba(255,255,255,0.3)`
- Nav items: 13px, font-weight 500, `rgba(255,255,255,0.65)` default
- Active item: `rgba(255,255,255,0.12)` bg, white text, green (#06d6a0) 3px left accent bar
- Hover: `rgba(255,255,255,0.08)` bg, `rgba(255,255,255,0.9)` text
- Count badges: `rgba(6,214,160,0.15)` bg, `#06d6a0` text, 10px, 6px border-radius
- PRO badge: `rgba(245,158,11,0.18)` bg, `#f59e0b` text, 8.5px uppercase
- Search bar: `rgba(255,255,255,0.06)` bg, 9px border-radius, triggers ⌘K palette
- Upgrade card: `rgba(255,255,255,0.06)` bg, 11px border-radius, pinned bottom
- Scrollbar: 4px width, `rgba(255,255,255,0.12)` thumb
- Logo: 36px icon, `rgba(255,255,255,0.12)` bg, 10px border-radius

### What WP admin items map where
| WP Default | Sidebar Section | Notes |
|---|---|---|
| Dashboard | Content → Dashboard | Replaced by Editor Dashboard |
| Posts | Content → Posts | Native, restyled |
| Pages | Content → Pages | Native, restyled |
| Media | Content → Media | Native, enhanced |
| Comments | Hidden by default | Shown if blog profile |
| Appearance | Design → Appearance | Themes only |
| Plugins | Configure → Plugins | Admin-only |
| Users | Configure → Users | Native, restyled |
| Tools | Hidden | Absorbed by modules |
| Settings | Configure → Settings | Native, restyled |
| WooCommerce | eCommerce section | If WC active |
| Elementor/Divi/etc | Design section | If detected |
| Unrecognized plugins | Below CONFIGURE | Catch-all |

---

## 4. Module Hierarchy — All 125 Mapped

28 parent modules across 7 categories. Full mapping in `docs/module-hierarchy.md`.

### CORE (6 parents · 25 modules)
1. Admin Bar Manager [POPULAR] — 5 subs
2. Dashboard Manager — 6 subs
3. Notifications & Notices — 2 subs
4. List Tables & Columns [NEW] — 9 subs
5. Keyboard Shortcuts & Bookmarks — 2 subs
6. User & Role Manager — 5 subs

### CONTENT (6 parents · 29 modules)
7. Content Tools [POPULAR] — 10 subs
8. Content Scheduling & Workflow — 3 subs
9. Editor Enhancements — 3 subs
10. Media Library Pro [POPULAR] — 8 subs
11. Navigation & Menus [POPULAR] [APP] — 4 subs
12. Page Builder Cleanup [NEW] — dynamic subs

### SECURITY (4 parents · 13 modules)
13. Firewall & Hardening [POPULAR] — 6 subs
14. Login Protection [APP] — 6 subs
15. Two-Factor Auth [PRO] — 2 subs
16. Audit Log [APP] — 1 sub

### PERFORMANCE (4 parents · 15 modules)
17. Asset Optimizer [POPULAR] [NEW] — 4 subs
18. Image Optimizer [NEW] — 3 subs
19. Site Speed — 6 subs
20. Database Optimizer [APP] — 1 sub

### DESIGN (3 parents · 8 modules)
21. Login Designer [POPULAR] [APP] — 2 subs
22. White Label [PRO] [APP] — 2 subs
23. Admin Theme — 4 subs

### DEVELOPER (4 parents · 23 modules)
24. Code Snippets [POPULAR] — 5 subs
25. Debug Tools [PRO] — 3 subs
26. Custom Post Types [PRO] — 1 sub
27. Site Utilities — 9 subs
28. Developer Tools — 7 subs

### ECOMMERCE (1 parent · 5 modules · detected only)
29. WooCommerce Enhancements — 5 subs

### SYSTEM (internal)
setup-wizard — not shown as module card

---

## 5. App Pages

| App Page | Reference | Key Elements |
|----------|-----------|-------------|
| Database Optimizer | `app-pages/database-optimizer-v3.html` | Bento stats, cleanup tasks, auto-cleanup sidebar |
| Audit Log | `app-pages/audit-log-v3.html` | Bento stats, event table, filters, pagination |
| Login Designer | `app-pages/login-customizer-v3.html` | Settings + live preview, templates |
| Menu Editor | `app-pages/menu-editor-v3.html` | Three-panel, drag-drop |
| White Label | `app-pages/white-label-v3.html` | Settings + live preview, PRO |
| Editor Dashboard | `dashboard/wp-transformation-editor.html` | Content workspace, real WP data |
| Login Protection | No mockup — match design system | Bento stats, login log |
| Security Overview | No mockup — match design system | Aggregate dashboard |
| Performance Overview | No mockup — match design system | Aggregate dashboard |

---

## 6. Activation Sequence

1. Activate → fullscreen wizard
2. Pick profile: Blogger / Agency / Store Owner / Developer
3. Pick vibe: Dark / Light / Match System
4. Review pre-checked modules → Apply & Transform
5. Redirect → Editor Dashboard

### Default Active (all profiles)
Command Palette, Clean Admin Bar, Admin Bar Enhancer, Hide Admin Notices, Notification Center, Disable Emojis, Disable Self Pingbacks, Heartbeat Control, SVG Upload, Content Duplication, Last Login Column, Enhance List Tables, Environment Indicator, Smart Menu Organizer, Dark Mode (OS auto)

### Never on by default
change-login-url, disable-gutenberg, disable-rest-api, disable-updates, password-protection, code-snippets, search-replace, file-manager, any PRO module

---

## 7. Free vs Pro

**Pro parents:** Two-Factor Auth, Custom Post Types, Debug Tools, White Label
**Pro subs:** avif-upload, passkey-auth, workflow-automation, admin-columns-pro, webhook-manager, temporary-user-access

---

## 8. Session Prompts

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
Read docs/module-hierarchy.md.

Restructure admin.php?page=wpt-modules: 28 parents, 7 categories,
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

Build 3 app pages matching mockups. PRO gating on White Label.
Commit: "Session 5: Login Designer, Menu Editor, White Label"
```

### Session 6: Command Palette + Detection

```
Read assets/admin/reference/components/command-palette-v3.html.

Command palette on ALL admin pages. ⌘K/Ctrl+K. Fuzzy search.
Plugin detection: WooCommerce, Elementor, Divi, Bricks, Oxygen, WPBakery, BuiltRight Forms.

Commit: "Session 6: Command palette + plugin detection"
```

### Session 7: Wizard

```
Build setup wizard per spec Section 6. Profile, dark/light, defaults.
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

## 9. Implementation Rules

1. STYLE native admin. No remove_menu_page().
2. Module toggles: AJAX to wp_options, optimistic UI.
3. Sub-module expand: max-height 0→500px, cubic-bezier(0.22,1,0.36,1).
4. Dark/light: localStorage + wp_usermeta.
5. Fonts + FA6: ALL admin pages.
6. Command palette: ALL admin pages.
7. App pages: real data, not dummy.
8. Parent toggle enables/disables all children.
9. Detected plugins (WC, builders, Forms): is_plugin_active(), conditional sidebar.
10. setup-wizard: internal, not in module grid.
