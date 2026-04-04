# WPTransformed UI Restructure — Final Spec v2
## Complete admin transformation blueprint

---

## 1. Vision

WPTransformed replaces the entire WordPress admin experience visually. On activation + wizard, the user's wp-admin looks and feels like a modern SaaS application. The gradient sidebar, glassmorphic surfaces, Outfit typography, command palette, and purpose-built module apps transform the default WordPress admin chrome.

**Critical approach: STYLE, don't replace.** The existing WordPress admin menu, admin bar, and page structure stay intact under the hood. We apply CSS overhaul + JS enhancements + PHP-injected elements on top. This preserves 100% plugin compatibility (WooCommerce, Elementor, Yoast, ACF, Gravity Forms — all keep working).

The "holy shit" moment: activate → wizard → redirect → Editor Dashboard showing their actual content. Not a module grid. A workspace. WordPress looks completely different, but nothing is broken.

---

## 2. Reference Files (9 files)

Place in `assets/admin/reference/`. These ARE the design. CSS is extracted verbatim.

| File | Purpose |
|------|---------|
| `wp-transformation-final.html` | PRIMARY. Dashboard + module grid with sub-module expand panels, category sections, sidebar, topbar, bento stats, welcome banner, pill tabs, toggles, badges, command palette, dark/light mode, all animations. |
| `wp-transformation-content.html` | Supplementary dashboard. Shows more categories (Media, Content sections) with better module density. |
| `tooltip-reference.html` | Renamed from v4. Extract ONLY the tooltip CSS/HTML pattern. Ignore everything else in this file. |
| `wp-transformation-editor.html` | Editor/Content Dashboard. Post stats, recent posts, upcoming scheduled, quick actions, writing tips. |
| `command-palette-v3.html` | ⌘K command palette overlay. Grouped results, keyboard nav, fuzzy search. |
| `database-optimizer-v3.html` | DB Optimizer app page. Bento stats, cleanup tasks, auto-cleanup sidebar, table sizes. |
| `audit-log-v3.html` | Audit Log app page. Bento stats, filterable event table, search, pagination. |
| `login-customizer-v3.html` | Login Customizer app page. Left settings + right live preview, template selector, device preview. |
| `menu-editor-v3.html` | Menu Editor app page. Three-panel: live sidebar preview, drag-drop menu items, edit properties. |
| `white-label-v3.html` | White Label app page. Settings form + live preview sidebar with branded admin. |

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
  Elementor / Divi / etc  → (goes under DESIGN, see below)

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
  Bricks                  → Bricks pages
  Oxygen                  → Oxygen pages
  WPBakery                → WPBakery pages

TOOLS
  Performance             → wpt-performance (dashboard)
  Database                → wpt-database (APP)
  Developer        PRO    → wpt-developer

CONFIGURE
  Modules            28   → wpt-modules (parent/sub grid)
  Settings                → options-general.php
  Users                   → users.php
```

### Sidebar styling rules (from mockups)
- Background: `linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%)`
- Dark mode bg: `linear-gradient(165deg, #071020 0%, #0d1f42 45%, #1e3a8a 100%)`
- Section labels: 9.5px uppercase, letter-spacing 1.1px, `rgba(255,255,255,0.3)`
- Nav items: 13px, font-weight 500, `rgba(255,255,255,0.65)` default
- Active item: `rgba(255,255,255,0.12)` bg, white text, green (#06d6a0) 3px left accent bar
- Hover: `rgba(255,255,255,0.08)` bg, `rgba(255,255,255,0.9)` text
- Count badges: `rgba(6,214,160,0.15)` bg, `#06d6a0` text, 10px, 6px border-radius
- PRO badge: `rgba(245,158,11,0.18)` bg, `#f59e0b` text, 8.5px uppercase
- Search bar: `rgba(255,255,255,0.06)` bg, 9px border-radius, triggers ⌘K palette
- Upgrade card: `rgba(255,255,255,0.06)` bg, 11px border-radius, pinned to bottom with `margin-top: auto`
- Upgrade button: `#06d6a0` bg, `#0f172a` text, 8px border-radius
- Scrollbar: 4px width, `rgba(255,255,255,0.12)` thumb
- Logo area: 36px icon with `rgba(255,255,255,0.12)` bg, 10px border-radius, backdrop-filter blur(8px)
- Dividers: `rgba(255,255,255,0.08)`
- User avatar at bottom: gradient bg `linear-gradient(135deg, var(--primary), var(--accent))`

### What WP admin items map where
| WP Default | Sidebar Section | Notes |
|---|---|---|
| Dashboard | Content → Dashboard | Replaced by Editor Dashboard page |
| Posts | Content → Posts | Native page, restyled |
| Pages | Content → Pages | Native page, restyled |
| Media | Content → Media | Native page, enhanced by Media Library Pro |
| Comments | Hidden by default | Shown if blog-focused wizard profile |
| Appearance → Themes | Design → Appearance | Just themes |
| Appearance → Menus | Content → Menu Editor | Replaced by WPT Menu Editor app |
| Plugins | Configure → Settings (nested) | Admin-only, collapsed |
| Users | Configure → Users | Native page, restyled |
| Tools | Hidden | Functions absorbed by modules |
| Settings | Configure → Settings | Native page, restyled |
| WooCommerce | eCommerce section | If WC active |
| Elementor | Design section | If Elementor active |

---

## 4. Module Hierarchy — All 125 Mapped

28 parent modules across 7 categories.

---

### CORE (6 parents · 25 modules)

**1. Admin Bar Manager** — POPULAR
- clean-admin-bar
- admin-bar-enhancer
- hide-admin-bar
- command-palette
- wider-admin-menu

**2. Dashboard Manager**
- hide-dashboard-widgets
- dashboard-columns
- activity-feed
- admin-quick-notes
- client-dashboard
- duplicate-widget

**3. Notifications & Notices**
- hide-admin-notices
- notification-center

**4. List Tables & Columns** — NEW
- enhance-list-tables
- admin-columns-enhancer
- admin-columns-pro
- page-template-column
- registration-date-column
- search-visibility-status
- taxonomy-filter
- last-login-column
- active-plugins-first

**5. Keyboard Shortcuts & Bookmarks**
- keyboard-shortcuts
- admin-bookmarks

**6. User & Role Manager**
- multiple-user-roles
- user-role-editor
- session-manager
- temporary-user-access
- view-as-role

---

### CONTENT (6 parents · 29 modules)

**7. Content Tools** — POPULAR
- content-duplication
- content-order
- terms-order
- bulk-edit-posts
- post-type-switcher
- public-preview
- external-links-new-tab
- external-permalinks
- disable-comments
- form-builder

**8. Content Scheduling & Workflow**
- content-calendar
- auto-publish-missed
- workflow-automation

**9. Editor Enhancements**
- disable-gutenberg
- page-hierarchy-organizer
- preserve-taxonomy-hierarchy

**10. Media Library Pro** — POPULAR
- media-folders
- media-replace
- svg-upload
- avif-upload
- image-sizes-panel
- media-visibility-control
- media-infinite-scroll
- local-user-avatar

**11. Navigation & Menus** — POPULAR · APP PAGE
- admin-menu-editor
- smart-menu-organizer
- custom-nav-new-tab
- duplicate-menu

**12. Page Builder Cleanup** — NEW
- (sub-modules dynamically generated per detected builder)
- (restrict builder by post type)
- (optimize builder CSS/JS loading)
- (clean builder shortcodes on deactivation)

---

### SECURITY (4 parents · 13 modules)

**13. Firewall & Hardening** — POPULAR
- disable-xmlrpc
- password-protection
- disable-rest-api
- disable-rest-fields
- email-obfuscator
- obfuscate-author-slugs

**14. Login Protection** — APP PAGE
- limit-login-attempts
- captcha-protection
- change-login-url
- login-id-type
- login-logout-menu
- redirect-after-login

**15. Two-Factor Auth** — PRO
- two-factor-auth
- passkey-auth

**16. Audit Log** — APP PAGE
- audit-log

---

### PERFORMANCE (4 parents · 15 modules)

**17. Asset Optimizer** — POPULAR · NEW
- minify-assets
- disable-embeds
- disable-emojis
- auto-clear-caches

**18. Image Optimizer** — NEW
- image-upload-control
- image-srcset-control
- lazy-load

**19. Site Speed**
- heartbeat-control
- disable-self-pingbacks
- disable-attachment-pages
- disable-author-archives
- disable-feeds
- revision-control

**20. Database Optimizer** — APP PAGE
- database-cleanup

---

### DESIGN (3 parents · 8 modules)

**21. Login Designer** — POPULAR · APP PAGE
- login-customizer
- site-identity-login

**22. White Label** — PRO · APP PAGE
- white-label
- custom-admin-footer

**23. Admin Theme**
- dark-mode
- admin-color-schemes
- environment-indicator
- admin-body-classes

---

### DEVELOPER (4 parents · 23 modules)

**24. Code Snippets** — POPULAR
- code-snippets
- custom-admin-css
- custom-frontend-code
- custom-frontend-css
- custom-body-class

**25. Debug Tools** — PRO
- plugin-profiler
- error-log-viewer
- system-summary

**26. Custom Post Types** — PRO
- custom-content-types

**27. Site Utilities**
- maintenance-mode
- redirect-manager
- redirect-404
- 404-monitor
- broken-link-checker
- cookie-consent
- email-smtp
- email-log
- disable-updates

**28. Developer Tools**
- search-replace
- cron-manager
- file-manager
- webhook-manager
- ads-txt-manager
- robots-txt-manager
- export-import-settings

---

### ECOMMERCE (1 parent · 5 modules · only if WC/EDD active)

**29. WooCommerce Enhancements**
- woo-admin-cleanup
- woo-custom-statuses
- woo-disable-reviews
- woo-empty-cart-button
- woo-login-redirect

---

### SYSTEM (internal, not shown as module card)
- setup-wizard

---

## 5. App Pages (Purpose-Built UIs)

These modules get their own full admin page with a purpose-built UI. Match the corresponding reference HTML file exactly.

| App Page | Reference File | Key UI Elements |
|----------|---------------|-----------------|
| Database Optimizer | database-optimizer-v3.html | Bento stats (DB size, tables, cleanable, savings), cleanup task list with counts/sizes/Clean buttons, auto-cleanup sidebar toggles, largest tables grid, last cleanup status |
| Audit Log | audit-log-v3.html | Bento stats (events, logins, failed, blocked), search + filter dropdowns, color-coded event table, pagination, export CSV |
| Login Designer | login-customizer-v3.html | Left panel (templates, colors, form style, option toggles), right panel live preview, device preview bar, Reset + Save |
| Menu Editor | menu-editor-v3.html | Three-panel: left live sidebar preview, center drag-drop menu list, right edit properties (title, URL, icon, role visibility) |
| White Label | white-label-v3.html | Branding (name, desc, icon/logo uploads), colors, developer info, hide elements toggles, live preview sidebar |
| Editor Dashboard | wp-transformation-editor.html | Welcome banner, bento stats (posts/drafts/scheduled/review), quick actions grid, recent posts list, upcoming calendar, writing tip |
| Login Protection | (no mockup — build matching design system) | Bento stats, login attempt log, settings toggles, blocked IPs |
| Security Overview | (no mockup — build matching design system) | Aggregate security dashboard: threat stats, recent events, hardening checklist |
| Performance Overview | (no mockup — build matching design system) | Aggregate speed dashboard: scores, recommendations, before/after |

---

## 6. Activation Sequence

```
1. Activate WPTransformed
   └→ Fullscreen wizard overlay (no WP chrome visible)

2. Wizard Step 1: "What describes you best?"
   [ Blogger ] [ Agency ] [ Store Owner ] [ Developer ]

3. Wizard Step 2: "Pick your vibe"
   [ Dark Mode ] [ Light Mode ] [ Match System ]

4. Wizard Step 3: "Here's what we're enabling"
   (pre-checked modules based on profile, user can uncheck)
   [ Apply & Transform → ]

5. REDIRECT → Editor Dashboard (admin.php?page=wpt-dashboard)
   - Sidebar is now the styled gradient nav
   - Menu items reorganized into sections by Smart Menu Organizer
   - Welcome banner greets by name with content stats
   - Bento cards show actual post counts
   - Quick Actions: New Post, New Page, Upload Media
   - ⌘K hint visible in sidebar search bar
```

### Default Active Modules (all profiles)
- Command Palette (⌘K)
- Clean Admin Bar + Admin Bar Enhancer (Howdy→Welcome)
- Hide Admin Notices → Notification Center
- Disable Emojis
- Disable Self Pingbacks
- Heartbeat Control (60s)
- SVG Upload
- Content Duplication
- Last Login Column
- Enhance List Tables (ID column, thumbnails)
- Environment Indicator (auto-detect)
- Smart Menu Organizer
- Admin Theme / Dark Mode (auto from OS preference)

### Profile-based additions
- **Blogger**: revision-control, content-calendar, lazy-load, image-upload-control
- **Agency**: keyboard-shortcuts, admin-bookmarks, code-snippets, client-dashboard, database-cleanup, white-label, multiple-user-roles
- **Store Owner**: woo-admin-cleanup, cookie-consent, lazy-load, image-upload-control
- **Developer**: keyboard-shortcuts, code-snippets, plugin-profiler, error-log-viewer, admin-body-classes

### Never on by default
change-login-url, disable-gutenberg, disable-rest-api, disable-updates, password-protection, code-snippets (eval risk), search-replace, file-manager, any PRO module

---

## 7. Free vs Pro

### Pro parent modules (entire parent gated)
- Two-Factor Auth
- Custom Post Types
- Debug Tools
- White Label

### Pro sub-modules (within free parents)
- avif-upload (Image Optimizer)
- passkey-auth (Two-Factor Auth)
- workflow-automation (Content Scheduling)
- admin-columns-pro (List Tables)
- webhook-manager (Developer Tools)
- temporary-user-access (User & Role Manager)

---

## 8. Claude Code Prompt

Copy everything in the code block below into Claude Code:

```
Read ALL HTML files in assets/admin/reference/. These are the 
design system mockups for WPTransformed's admin UI:

- wp-transformation-final.html — PRIMARY dashboard reference
- wp-transformation-content.html — Supplementary, more categories
- tooltip-reference.html — Tooltip CSS ONLY, merge into final
- wp-transformation-editor.html — Editor/Content Dashboard
- command-palette-v3.html — ⌘K command palette overlay
- database-optimizer-v3.html — DB Optimizer app page
- audit-log-v3.html — Audit Log app page  
- login-customizer-v3.html — Login Customizer app page
- menu-editor-v3.html — Menu Editor app page
- white-label-v3.html — White Label app page

═══════════════════════════════════════════════════════
CRITICAL DESIGN RULE
═══════════════════════════════════════════════════════

You are NOT designing anything. The design is DONE. It lives 
in these HTML files.

Your job is to EXTRACT the existing CSS from these files and 
port it into the plugin's admin stylesheets — VERBATIM. Same 
hex values, same border-radius values, same cubic-bezier curves, 
same font sizes, same padding, same backdrop-filter values, 
same gradients, same animation keyframes.

Do NOT simplify, "improve", or reinterpret any CSS. If the 
mockup says border-radius: 14px, you write 14px — not 12px, 
not 1rem. If the mockup uses cubic-bezier(0.22,1,0.36,1), use 
that exact curve. If the sidebar gradient is 
linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%), 
that is the gradient. Period.

The HTML structure can change to accommodate WordPress PHP 
rendering. The CSS values cannot change.

After writing any CSS, open each reference mockup and diff your 
output against the source. Any deviation is a bug.

═══════════════════════════════════════════════════════
SIDEBAR — STYLE, DON'T REPLACE
═══════════════════════════════════════════════════════

CRITICAL: Do NOT use remove_menu_page() or remove_submenu_page() 
to hide WordPress menu items. Do NOT build a custom sidebar from 
scratch. This would break every third-party plugin's menu items.

Instead, STYLE the existing WP admin sidebar:

1. admin-global.css already exists in the repo with --wpt-* CSS 
   variables and a native sidebar reskin approach. UPDATE its 
   values to match the mockup files exactly. The current sidebar 
   gradient is wrong (zinc/dark). The correct gradient from the 
   mockup is: linear-gradient(165deg, #0f2847 0%, #1a4180 45%, #2563eb 100%)

2. Apply CSS to #adminmenu, #adminmenuwrap, #adminmenuback to 
   achieve the mockup's sidebar appearance: gradient bg, Outfit 
   font, item spacing, hover states, active state with green 
   left accent bar, rounded items, section label styling.

3. PHP hook on admin_menu (priority 999) injects section label 
   separators into the menu: CONTENT, ECOMMERCE (conditional), 
   SECURITY, DESIGN, TOOLS, CONFIGURE. These are added as menu 
   separator items with custom CSS classes for the section label 
   styling from the mockup.

4. Smart Menu Organizer module handles reordering existing WP 
   menu items into the correct section groups via JS/PHP. It 
   detects WooCommerce, page builders (Elementor, Divi, Bricks, 
   Oxygen, WPBakery), and BuiltRight Forms — slotting their 
   menu items into the appropriate sections.

5. JS injects additional DOM elements into #adminmenuwrap:
   - Search bar at top (triggers command palette)
   - Upgrade card at bottom
   - User profile/avatar at very bottom
   These are injected elements, not replacements.

6. The WP admin bar (#wpadminbar) gets RESTYLED as a glassmorphic 
   topbar matching the mockup: sticky, glass blur bg, breadcrumb 
   left side, theme toggle + notification bell + user avatar on 
   right. Use existing admin bar hooks where possible.

Sidebar section order for Smart Menu Organizer:
- CONTENT: Dashboard(index.php), Posts(edit.php), Pages(edit.php?post_type=page), Media(upload.php), Menu Editor(wpt-menu-editor)
- ECOMMERCE: (WooCommerce/EDD items, only if active)
- SECURITY: Overview(wpt-security), Audit Log(wpt-audit-log), Login Protection(wpt-login-protection)
- DESIGN: Login Page(wpt-login-designer), White Label(wpt-white-label), Appearance(themes.php), (detected page builder items)
- TOOLS: Performance(wpt-performance), Database(wpt-database), Developer(wpt-developer)
- CONFIGURE: Modules(wpt-modules), Settings(options-general.php), Users(users.php)

Items not in this map get placed in a "More" section or hidden 
based on the user's wizard profile.

═══════════════════════════════════════════════════════
MODULE GRID (admin.php?page=wpt-modules)
═══════════════════════════════════════════════════════

Match wp-transformation-final.html layout exactly: parent module 
cards grouped by category section headers, with expandable 
sub-module panels.

Pill tab filters: All | Core | Content | Security | Performance | Design | Dev | eCommerce
Grid/list view toggle buttons
Search bar within modules section
Tooltip on card hover (from tooltip-reference.html)

7 categories, 28+ parent modules:

CORE (blue, 6 parents):
1. Admin Bar Manager [POPULAR] — 5 subs: clean-admin-bar, admin-bar-enhancer, hide-admin-bar, command-palette, wider-admin-menu
2. Dashboard Manager — 6 subs: hide-dashboard-widgets, dashboard-columns, activity-feed, admin-quick-notes, client-dashboard, duplicate-widget
3. Notifications & Notices — 2 subs: hide-admin-notices, notification-center
4. List Tables & Columns [NEW] — 9 subs: enhance-list-tables, admin-columns-enhancer, admin-columns-pro, page-template-column, registration-date-column, search-visibility-status, taxonomy-filter, last-login-column, active-plugins-first
5. Keyboard Shortcuts & Bookmarks — 2 subs: keyboard-shortcuts, admin-bookmarks
6. User & Role Manager — 5 subs: multiple-user-roles, user-role-editor, session-manager, temporary-user-access, view-as-role

CONTENT (violet, 6 parents):
7. Content Tools [POPULAR] — 10 subs: content-duplication, content-order, terms-order, bulk-edit-posts, post-type-switcher, public-preview, external-links-new-tab, external-permalinks, disable-comments, form-builder
8. Content Scheduling & Workflow — 3 subs: content-calendar, auto-publish-missed, workflow-automation
9. Editor Enhancements — 3 subs: disable-gutenberg, page-hierarchy-organizer, preserve-taxonomy-hierarchy
10. Media Library Pro [POPULAR] — 8 subs: media-folders, media-replace, svg-upload, avif-upload, image-sizes-panel, media-visibility-control, media-infinite-scroll, local-user-avatar
11. Navigation & Menus [POPULAR] [APP PAGE] — 4 subs: admin-menu-editor, smart-menu-organizer, custom-nav-new-tab, duplicate-menu

SECURITY (rose, 4 parents):
12. Firewall & Hardening [POPULAR] — 6 subs: disable-xmlrpc, password-protection, disable-rest-api, disable-rest-fields, email-obfuscator, obfuscate-author-slugs
13. Login Protection [APP PAGE] — 6 subs: limit-login-attempts, captcha-protection, change-login-url, login-id-type, login-logout-menu, redirect-after-login
14. Two-Factor Auth [PRO] — 2 subs: two-factor-auth, passkey-auth
15. Audit Log [APP PAGE] — 1 sub: audit-log

PERFORMANCE (green, 4 parents):
16. Asset Optimizer [POPULAR] [NEW] — 4 subs: minify-assets, disable-embeds, disable-emojis, auto-clear-caches
17. Image Optimizer [NEW] — 3 subs: image-upload-control, image-srcset-control, lazy-load
18. Site Speed — 6 subs: heartbeat-control, disable-self-pingbacks, disable-attachment-pages, disable-author-archives, disable-feeds, revision-control
19. Database Optimizer [APP PAGE] — 1 sub: database-cleanup

DESIGN (sky/teal, 3 parents):
20. Login Designer [POPULAR] [APP PAGE] — 2 subs: login-customizer, site-identity-login
21. White Label [PRO] [APP PAGE] — 2 subs: white-label, custom-admin-footer
22. Admin Theme — 4 subs: dark-mode, admin-color-schemes, environment-indicator, admin-body-classes

DEVELOPER (amber, 4 parents):
23. Code Snippets [POPULAR] — 5 subs: code-snippets, custom-admin-css, custom-frontend-code, custom-frontend-css, custom-body-class
24. Debug Tools [PRO] — 3 subs: plugin-profiler, error-log-viewer, system-summary
25. Custom Post Types [PRO] — 1 sub: custom-content-types
26. Site Utilities — 9 subs: maintenance-mode, redirect-manager, redirect-404, 404-monitor, broken-link-checker, cookie-consent, email-smtp, email-log, disable-updates
27. Developer Tools — 7 subs: search-replace, cron-manager, file-manager, webhook-manager, ads-txt-manager, robots-txt-manager, export-import-settings

ECOMMERCE (only if WC/EDD active, 1 parent):
28. WooCommerce Enhancements — 5 subs: woo-admin-cleanup, woo-custom-statuses, woo-disable-reviews, woo-empty-cart-button, woo-login-redirect

Each parent card has:
- Color-coded icon matching category color
- Toggle switch (enables/disables entire parent + all subs)
- Name + one-line description
- Sub-module count: "4/6 sub-modules" with puzzle-piece icon
- Badges: POPULAR (blue), NEW (green), PRO (amber)
- "Configure Sub-modules ▾" expandable button
- Expanded panel shows individual sub-module rows with their own toggles
- If module has APP PAGE: "Open [Name] →" link/button on the card

Category section headers with:
- Color-coded icon
- Category name (bold)
- "X of Y active" count (right-aligned, muted)
- Border-bottom divider

═══════════════════════════════════════════════════════
APP PAGES
═══════════════════════════════════════════════════════

Build purpose-built UIs matching reference HTML files exactly:

1. Database Optimizer → database-optimizer-v3.html
2. Audit Log → audit-log-v3.html
3. Login Designer → login-customizer-v3.html
4. Menu Editor → menu-editor-v3.html
5. White Label → white-label-v3.html
6. Editor Dashboard → wp-transformation-editor.html
7. Login Protection → no mockup, build matching design system
8. Security Overview → no mockup, aggregate dashboard
9. Performance Overview → no mockup, aggregate dashboard

Pages WITHOUT mockups (7, 8, 9) should use the same design 
system patterns: topbar header with action buttons, bento stat 
cards, content area with card containers, same CSS variables 
and component styles from the primary mockup.

═══════════════════════════════════════════════════════
COMMAND PALETTE
═══════════════════════════════════════════════════════

Match command-palette-v3.html exactly. Available on EVERY admin 
page, not just WPT pages.

Searches across:
- Quick Actions: New Post, New Page, New Product (if WC), Upload Media
- Navigate: All sidebar destinations
- Modules: Toggle any module on/off directly from palette
- WP Pages: Jump to any WP admin page

⌘K / Ctrl+K to open. Escape to close. Click outside to close.
Arrow keys + Enter for keyboard navigation.
Grouped results with section labels (QUICK ACTIONS, NAVIGATE, MODULES).
Fuzzy search matching.

═══════════════════════════════════════════════════════
TOPBAR
═══════════════════════════════════════════════════════

Restyle #wpadminbar as the glassmorphic topbar from the mockup:
- Sticky, glass blur background: backdrop-filter: blur(20px)
- Left side: page title (bold) + separator dot + breadcrumb (muted)
- Right side: theme toggle button (moon/sun), notification bell 
  (with red dot if admin notices), user avatar (initials on gradient)
- Height: 58px
- Border-bottom: 1px solid var(--border)
- All button styles from mockup: 36x36px, 9px radius, border, 
  hover shows primary glow

═══════════════════════════════════════════════════════
GLOBAL ADMIN RESTYLING
═══════════════════════════════════════════════════════

Every WP admin page inherits the design system:
- Outfit font family on all admin text
- Card-style containers (glassmorphic) for WP admin postboxes
- Styled buttons matching mockup (primary = blue, rounded)
- Form inputs: rounded corners, proper padding, border styling
- List tables get card treatment with proper spacing
- Google Fonts (Outfit + JetBrains Mono) + Font Awesome 6 
  enqueued on ALL admin pages, not just WPT pages

═══════════════════════════════════════════════════════
FILE STRUCTURE
═══════════════════════════════════════════════════════

- assets/admin/css/admin-global.css — EXISTS. Update CSS vars + 
  expand with full design system from mockups
- assets/admin/css/admin.css — WPT-specific page styles (dashboard, 
  module grid, app pages)
- assets/admin/js/admin.js — All interactions: module toggles, 
  command palette, theme toggle, animated counters, sub-module 
  expand/collapse
- includes/admin/class-admin.php — Page rendering, menu hooks, 
  sidebar injection, admin bar restyling
- includes/admin/class-editor-dashboard.php — Editor Dashboard
- includes/admin/class-module-grid.php — Parent/sub-module grid
- includes/admin/views/ — PHP templates for each app page

═══════════════════════════════════════════════════════
IMPLEMENTATION NOTES
═══════════════════════════════════════════════════════

1. DO NOT remove_menu_page(). Style native sidebar via CSS.
2. Module toggles save via AJAX to wp_options. Optimistic UI — 
   toggle immediately in JS, revert on AJAX failure.
3. Sub-module expand/collapse: max-height 0→500px transition 
   with cubic-bezier(0.22,1,0.36,1).
4. Dark/light mode persists to localStorage AND wp_usermeta.
5. Fonts + FA6 enqueued on ALL admin pages.
6. Command palette available on EVERY admin page.
7. App pages use real data from their modules, not dummy data.
8. Parent/sub-module grouping defined in a PHP registry array 
   that maps each module class file to its parent and category 
   per the hierarchy above.
9. Detected plugins (WooCommerce, page builders, BuiltRight 
   Forms) checked via is_plugin_active() — sidebar sections 
   and module grid categories conditionally rendered.
10. The parent toggle enables/disables ALL child sub-modules. 
    Individual sub-modules can be toggled independently.
11. setup-wizard is internal — not shown in module grid.
12. Page Builder Cleanup sub-modules are dynamically generated 
    based on which builders are detected.
```

---

## 9. CLAUDE.md Addition

Add this to the repo's CLAUDE.md:

```
## Design System

All admin UI styling is defined in assets/admin/reference/*.html.
These are the finished design mockups. CSS values are extracted 
verbatim — same hex, same px, same curves, same everything.

Never modify colors, spacing, typography, border-radius, or 
animations without explicit instruction. When in doubt, open the 
reference HTML file and copy the CSS exactly.

admin-global.css reskins the NATIVE WordPress admin sidebar and 
chrome. We STYLE, we don't REPLACE. No remove_menu_page(). 
Every third-party plugin's menu items must continue working.

Primary reference: wp-transformation-final.html
Tooltip pattern: tooltip-reference.html (extract tooltip only)
```

---

## 10. Session Plan

### Session 1: Sidebar Reskin + Topbar + Global Styling
- Update admin-global.css with mockup CSS values
- Style #adminmenu with gradient, fonts, spacing, active states
- Inject section labels (CONTENT, SECURITY, DESIGN, etc.)
- Inject search bar, upgrade card, user avatar into sidebar
- Restyle #wpadminbar as glassmorphic topbar
- Dark/light mode toggle
- Outfit + JetBrains Mono + FA6 on all admin pages

### Session 2: Editor Dashboard
- Build content workspace as default landing page
- Real WP queries: wp_count_posts, get_posts for recent/upcoming
- Bento stat cards with animated counters
- Quick Actions grid
- Welcome banner with dynamic greeting

### Session 3: Module Grid Restructure
- Build parent/sub-module PHP registry (28 parents, 125 modules)
- Category-grouped card grid with expandable sub-module panels
- Pill tab filtering + search + grid/list toggle
- AJAX toggles for parent and sub-module switches
- Badges, sub-module counts, APP PAGE links
- Tooltip on hover

### Session 4: App Pages — Database Optimizer + Audit Log
- DB Optimizer full page from mockup, real data
- Audit Log full page from mockup, real data

### Session 5: App Pages — Login Designer + Menu Editor + White Label
- Login Customizer with live preview
- Menu Editor with drag-drop and live preview
- White Label with live preview
- PRO badge and gating logic

### Session 6: Command Palette + Plugin Detection
- Command palette on all admin pages
- Fuzzy search across modules, pages, actions
- WooCommerce / page builder / Forms detection
- Conditional sidebar sections + eCommerce category
- Smart Menu Organizer reordering

### Session 7: Activation Wizard + Defaults
- Setup wizard with profile selection
- Default module activation per profile
- Dark/light mode preference
- Redirect to Editor Dashboard on completion

### Session 8: QA + Polish
- Animation polish (fadeUp stagger, counter, orb-drift)
- Responsive breakpoints (1150px, 800px)
- Plugin compatibility testing
- Accessibility (keyboard nav, screen readers, contrast)
- Performance audit (CSS/JS weight on admin pages)
- Edge cases: multisite, RTL, non-English
