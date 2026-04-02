# WPTransformed Wave 3 — Differentiator Module Specifications

> 5 modules that no competitor has. These are what make WPTransformed
> worth switching to, not just another ASE clone.
> Build with `/batch` — one parallel agent per module in isolated worktrees.

---

## Module List (5 total)

| # | Module | Category | Replaces | Tier |
|---|--------|----------|----------|------|
| 26 | Command Palette | Admin Interface | — (unique) | Free |
| 27 | Smart Menu Organizer | Admin Interface | Admin Menu Editor (300K) | Free |
| 28 | Search Replace | Utilities | Better Search Replace (1M) | Free |
| 29 | Broken Link Checker | Utilities | Broken Link Checker (700K) | Free |
| 30 | Setup Wizard | Admin Interface | — (unique) | Free |

---

## Module 26: Command Palette

**ID:** `command-palette`
**Category:** `admin-interface`
**One-line:** Cmd+K (or Ctrl+K on Windows) opens a searchable command palette for instant access to any admin page, module setting, or quick action.

### What the User Sees

- Press Cmd+K (Mac) or Ctrl+K (Windows) anywhere in WP admin
- A centered modal overlay appears with a search input
- Type to search across:
  - All WP admin pages (Posts, Pages, Plugins, Settings, etc.)
  - All WPTransformed modules (toggle on/off, open settings)
  - Quick actions (Clear cache, Run DB cleanup, Toggle maintenance mode, Export settings)
  - Recently visited pages (top of results when empty)
  - Content search (find posts/pages by title)
- Arrow keys to navigate, Enter to select, Esc to close
- Results grouped by category with icons
- Fuzzy matching — "darkmo" finds "Dark Mode"

### Settings

```
command-palette: {
    enabled: true,
    shortcut: 'mod+k',           // mod = Cmd on Mac, Ctrl on Windows
    show_recent: true,
    recent_count: 5,
    search_content: true,         // Include posts/pages in search
    custom_commands: []            // User-defined quick actions
}
```

### Hooks & Implementation

- `admin_footer` — output palette HTML + JS on every admin page
- Vanilla JS — NO React, NO jQuery. ~8KB minified.
- HTML: `<div id="wpt-command-palette">` with input, results list, overlay
- CSS: fixed position overlay, z-index 999999 (above admin bar), blur backdrop
- Keyboard: `document.addEventListener('keydown', ...)` — detect Cmd/Ctrl+K
- Search index built on page load via `wp_localize_script()`:
  - Admin menu items: parse from `$GLOBALS['menu']` and `$GLOBALS['submenu']`
  - WPTransformed modules: from `Module_Registry::get_all()`
  - Quick actions: hardcoded list + filter `wpt_command_palette_actions`
  - Recent pages: stored in user meta `wpt_recent_pages`, updated via AJAX on navigation
- Content search: AJAX endpoint `wp_ajax_wpt_palette_search` → `WP_Query` with `s` parameter, limit 5
- Fuzzy matching: simple scoring — exact match > starts-with > contains > fuzzy character match
- Focus trap: Tab cycles within the palette while open
- Accessibility: `role="combobox"`, `aria-expanded`, `aria-activedescendant`

### Result Format

```javascript
{
    type: 'page',           // page | module | action | content
    icon: 'dashicons-admin-post',
    title: 'All Posts',
    subtitle: 'Posts',      // category/group name
    url: '/wp-admin/edit.php',
    action: null            // for type: 'action', a callback name
}
```

### Quick Actions (Built-in)

| Action | What It Does |
|--------|-------------|
| Toggle Dark Mode | Switches dark mode on/off |
| Toggle Maintenance Mode | Enables/disables maintenance |
| Clear Transients | Deletes expired transients |
| Run Database Cleanup | Triggers DB cleanup scan |
| Export Settings | Downloads WPT settings JSON |
| Import Settings | Opens import dialog |
| View Audit Log | Navigates to audit log |
| Toggle Module: {name} | Enables/disables any module |

### Edge Cases

- Multiple keyboard layouts — Cmd+K works on QWERTY, AZERTY, etc. (use `event.key === 'k'`)
- Gutenberg editor already uses Cmd+K for link insertion — detect if block editor is focused, yield to it
- Admin bar on frontend — palette should also work when viewing site as admin
- Multisite — include network admin pages when on network admin
- Third-party admin pages — automatically indexed from menu registration
- Performance: build index once on `admin_init`, cache in `wp_cache` for the page load
- Don't index hidden/restricted menu items the current user can't access

### Verification

1. Activate module → press Cmd+K → palette appears
2. Type "post" → "All Posts", "Add New Post" appear in results
3. Press Enter on "All Posts" → navigates to edit.php
4. Type "dark" → "Dark Mode" module appears with toggle action
5. Select "Toggle Dark Mode" → dark mode toggles, palette closes
6. Type "maint" → "Toggle Maintenance Mode" appears
7. Empty search → shows 5 recently visited pages
8. Press Esc → palette closes
9. Tab key cycles within palette (focus trap)
10. Works on Pages, Posts, and custom post type edit screens
11. Works on frontend admin bar (if user is logged-in admin)

---

## Module 27: Smart Menu Organizer

**ID:** `smart-menu-organizer`
**Category:** `admin-interface`
**One-line:** Auto-groups the WordPress admin sidebar into logical categories with drag-and-drop reordering and per-role visibility.

### What the User Sees

- On activation: admin sidebar is auto-organized into collapsible sections:
  - **Content** — Posts, Pages, Media, Comments, CPTs
  - **Build** — Appearance, Plugins, Widgets, Menus, Elementor/Bricks
  - **Manage** — Users, Tools, Settings, WPTransformed
  - **Commerce** — WooCommerce, EDD, payment plugins (if active)
- Each section is collapsible (click header to expand/collapse)
- Drag-and-drop to reorder items within and between sections
- Right-click menu item → Hide, Rename, Move to Section, Change Icon
- "New Plugin Installed" notice: "You just activated [Plugin]. Where should it go?" with section buttons
- Per-role visibility: hide menu items from specific roles
- Reset to WordPress defaults button

### Settings

```
smart-menu-organizer: {
    enabled: true,
    auto_organize_on_activate: true,
    sections: [
        {
            id: 'content',
            label: 'Content',
            icon: 'dashicons-edit',
            items: ['edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php'],
            collapsed: false
        },
        {
            id: 'build',
            label: 'Build',
            icon: 'dashicons-admin-appearance',
            items: ['themes.php', 'plugins.php', 'nav-menus.php', 'widgets.php'],
            collapsed: false
        },
        {
            id: 'manage',
            label: 'Manage',
            icon: 'dashicons-admin-settings',
            items: ['users.php', 'tools.php', 'options-general.php'],
            collapsed: true
        }
    ],
    hidden_items: {},            // { role: [menu_slugs] }
    renamed_items: {},           // { menu_slug: 'New Label' }
    custom_icons: {},            // { menu_slug: 'dashicons-xxx' }
    prompt_on_plugin_install: true,
    known_plugins: {}            // { plugin_slug: suggested_section }
}
```

### Hooks & Implementation

- `admin_menu` priority 9999 — read `$GLOBALS['menu']` and `$GLOBALS['submenu']`, reorder based on saved sections
- `admin_head` — inject CSS for section headers, collapsible groups, drag handles
- `admin_footer` — inject vanilla JS for drag-and-drop (HTML5 Drag and Drop API, ~6KB)
- Section headers: custom `<li>` elements inserted into `#adminmenu` with `section-header` class
- Collapse state: stored in user meta `wpt_menu_collapsed_sections`, toggled via AJAX
- Drag-and-drop: `dragstart`/`dragover`/`drop` events on `#adminmenu li` elements
- Save reorder: AJAX endpoint `wp_ajax_wpt_save_menu_order` → saves to user meta
- Auto-organize logic:
  - Parse each menu item's `$menu[x][2]` (slug) against a mapping table
  - Known WP core items → mapped to sections
  - Known plugins (WooCommerce → Commerce, Elementor → Build) → mapped
  - Unknown items → prompt user or place in "Other" section
- "New Plugin" notice: `activated_plugin` action → set transient → `admin_notices` shows placement prompt
- Per-role hiding: `admin_menu` filter → remove items from `$GLOBALS['menu']` based on `current_user_can()` and hidden_items config
- Rename: modify `$GLOBALS['menu'][x][0]` (the menu title)
- Custom icons: modify `$GLOBALS['menu'][x][6]` (the icon class)
- Right-click context menu: vanilla JS, positioned at cursor, with options

### Known Plugin Mapping (Built-in)

```php
$known_plugins = [
    'woocommerce'       => 'commerce',
    'easy-digital-downloads' => 'commerce',
    'elementor'         => 'build',
    'bricks'            => 'build',
    'advanced-custom-fields' => 'build',
    'jetengine'         => 'build',
    'wordfence'         => 'manage',
    'updraftplus'       => 'manage',
    'redirection'       => 'manage',
    'yoast'             => 'content',
    'rank-math'         => 'content',
    'gravityforms'      => 'content',
    'wpforms'           => 'content',
    'fluentform'        => 'content',
    // 50+ mappings for popular plugins
];
```

Filter for adding custom mappings: `wpt_known_plugin_sections`

### Edge Cases

- Multisite network admin has different menu items — maintain separate config per context
- Plugins that modify the admin menu at very late priorities (9999+) — use priority 99999
- Some plugins inject menu items via JavaScript — can't be caught by PHP reordering. Detect and handle via JS.
- Collapse state per-user, not global — each admin sees their own layout
- Reset must restore exact WordPress default menu without deactivating the module
- RTL languages — drag-and-drop and section headers must work RTL
- Admin menu items with no icon — assign a default based on section

### Verification

1. Activate module → admin sidebar reorganizes into Content/Build/Manage sections
2. Section headers appear with collapse arrows
3. Click "Content" header → section collapses
4. Drag "Media" from Content to Build → saves position
5. Refresh page → reordered menu persists
6. Activate a new plugin → notice appears asking where to place it
7. Click "Content" on the notice → plugin menu item moves to Content section
8. Right-click "Posts" → context menu with Hide/Rename/Move/Icon options
9. Rename "Posts" to "Articles" → menu shows "Articles"
10. Switch to Editor role → hidden items not visible
11. Click "Reset to Defaults" → WordPress default menu restored

---

## Module 28: Search Replace

**ID:** `search-replace`
**Category:** `utilities`
**One-line:** Search and replace across the entire WordPress database with safe serialized data handling. Supports all page builders.

### What the User Sees

- Admin page: WP Transformation → Search Replace
- Search field, Replace field, table selection (checkboxes)
- "Dry Run" button — shows all matches without changing anything
- "Run Replace" button — executes the replacement
- Progress bar with live count
- Results table: Table | Column | Row ID | Before | After
- Supports: plain text, serialized data (PHP serialize), JSON, base64
- Page builder aware: Elementor, DIVI, Bricks, Gutenberg blocks
- Multisite: option to run across all network sites (if network activated)

### Settings

```
search-replace: {
    enabled: true,
    max_batch_size: 50,
    backup_reminder: true,
    exclude_tables: [],
    log_replacements: true
}
```

### Hooks & Implementation

- Core class: `WPT_Search_Replace_Engine` (adapted from Universal Safe Search Replace plugin)
- Table list: `$wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}%'")` — shows all WP tables
- Batch processing: process `max_batch_size` rows per AJAX request to avoid WP Engine timeout
- Serialized data handling:
  ```php
  function safe_replace($data, $search, $replace) {
      if (is_serialized($data)) {
          $unserialized = @unserialize($data);
          $replaced = $this->recursive_replace($unserialized, $search, $replace);
          return serialize($replaced);
      }
      return str_replace($search, $replace, $data);
  }
  ```
- Recursive replacement: handles nested arrays, objects, JSON strings within serialized data
- JSON handling: detect JSON strings, decode → replace → re-encode
- Elementor awareness: `_elementor_data` meta key contains JSON — decode, walk tree, replace, re-encode
- DIVI awareness: `et_pb_*` fields contain shortcode-encoded data — parse, replace, rebuild
- Bricks awareness: `_bricks_page_content_2` meta key — JSON with nested content
- Gutenberg blocks: block comment delimiters `<!-- wp:... -->` with JSON attributes
- Dry run: same logic but `SELECT` only, no `UPDATE`
- Results: stored in transient for display, pruned after viewing
- Undo: store original values in `{$wpdb->prefix}wpt_replace_log` table for rollback
- Cache clearing after replace: `wp_cache_flush()`, clear Elementor CSS cache, clear known page caches

### Database Table

```sql
CREATE TABLE {$wpdb->prefix}wpt_replace_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(36) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    row_id BIGINT UNSIGNED NOT NULL,
    old_value LONGTEXT,
    new_value LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_run (run_id),
    INDEX idx_created (created_at)
);
```

### Multisite Support

- When network-activated: admin page appears under Network Admin → Tools
- Subsite selector: checkboxes for each site, or "All Sites"
- For each selected site: `switch_to_blog($blog_id)` → run replacement → `restore_current_blog()`
- Progress shows per-site status

### Edge Cases

- Serialized data with string length counts — MUST recalculate after replacement (this is the #1 reason Better Search Replace breaks sites)
- HTML entities: `&amp;` vs `&` — offer option to match both variations
- Case sensitivity toggle
- Regex support (advanced mode, off by default)
- UTF-8 multi-byte characters — use `mb_strlen()` for serialized length calculations
- Very large tables (1M+ rows) — chunk by primary key range, not OFFSET
- Replacement in `wp_options.option_value` where value is autoloaded — flush object cache after
- Don't replace in `wp_users.user_pass` — always exclude password fields

### Verification

1. Activate module → "Search Replace" submenu appears
2. Enter search: "http://old-domain.com", replace: "https://new-domain.com"
3. Select all tables → click "Dry Run"
4. Results show all matches across posts, postmeta, options
5. Click "Run Replace" → progress bar, replacements execute
6. Verify: Elementor pages still work (no broken serialized data)
7. Verify: featured images still display (attachment URLs updated)
8. Click "Undo Last Run" → all replacements reverted
9. Multisite: run across 3 subsites → all updated correctly
10. Serialized data in `widget_text` option → correctly handled

---

## Module 29: Broken Link Checker

**ID:** `broken-link-checker`
**Category:** `utilities`
**One-line:** Background scan of all content for broken internal and external links with one-click fix options.

### What the User Sees

- Admin page: WP Transformation → Broken Links
- Dashboard widget showing: X broken links found, last scan date
- Full page table: URL | Status Code | Found In (post title + link) | Type (internal/external/image) | Actions (Edit/Unlink/Redirect/Dismiss)
- Filterable by type, status code, post type
- "Scan Now" button for manual trigger
- Auto-scan configurable: daily, weekly, monthly, or off
- "Edit" opens the post editor with the link highlighted
- "Redirect" creates a redirect via Redirect Manager module (if active)
- "Unlink" removes the `<a>` tag but keeps the text
- Email notification option for new broken links

### Settings

```
broken-link-checker: {
    enabled: true,
    scan_schedule: 'weekly',      // daily, weekly, monthly, off
    scan_internal: true,
    scan_external: true,
    scan_images: true,
    timeout: 10,                   // seconds per URL check
    max_concurrent: 5,             // parallel HTTP checks
    exclude_domains: [],
    notify_email: '',
    check_post_types: ['post', 'page'],
    recheck_broken_days: 3         // re-verify broken links after N days
}
```

### Database Table

```sql
CREATE TABLE {$wpdb->prefix}wpt_link_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    url_hash VARCHAR(64) NOT NULL,
    status_code SMALLINT,
    status_text VARCHAR(255),
    link_type ENUM('internal', 'external', 'image', 'redirect') DEFAULT 'external',
    found_in_post BIGINT UNSIGNED,
    found_in_field VARCHAR(100),
    anchor_text VARCHAR(500),
    first_found DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_checked DATETIME,
    last_status_change DATETIME,
    is_dismissed TINYINT(1) DEFAULT 0,
    INDEX idx_hash (url_hash),
    INDEX idx_status (status_code),
    INDEX idx_post (found_in_post),
    INDEX idx_type (link_type),
    INDEX idx_checked (last_checked)
);
```

### Hooks & Implementation

- **Link extraction:** Parse `post_content` with DOMDocument or regex for `<a href="">` and `<img src="">`
- Also scan: custom fields (ACF), widget content, menu items (`wp_nav_menu_items`), Elementor data
- **URL checking:**
  - Internal links: `wp_remote_head()` with short timeout
  - External links: `wp_remote_head()` with configurable timeout, follow redirects
  - Images: `wp_remote_head()` checking for 200 + valid content-type
- **Batch processing:** WP-Cron job processes URLs in chunks of `max_concurrent`
  - Extract all URLs from all content → store in `wpt_link_checks`
  - Process unchecked URLs in batches via cron
  - Re-check broken URLs after `recheck_broken_days`
- **Status codes:**
  - 200 = OK (remove from broken list)
  - 301/302 = Redirect (flag as "redirect chain" if internal)
  - 403 = Forbidden (some sites block HEAD requests — retry with GET)
  - 404 = Broken
  - 500 = Server error (retry once)
  - 0 = Timeout/DNS failure
- **Fix actions:**
  - Edit: link to `post.php?action=edit&post={id}` with `#wpt-link-{url_hash}` anchor
  - Unlink: AJAX endpoint that strips `<a>` tag from post_content, preserves inner text
  - Redirect: if Redirect Manager module is active, pre-fills redirect form with broken URL as source
  - Dismiss: marks link as dismissed, won't show in results but tracked
- **Notifications:** After each scan, if new broken links found, `wp_mail()` summary
- **Dashboard widget:** `wp_add_dashboard_widget()` showing broken count, last scan, scan button
- **Integration with Redirect Manager:** Check if a broken URL already has a redirect configured — show "Redirected" badge instead of "Broken"

### Edge Cases

- External sites that block HEAD requests (return 403/405) — fall back to GET with small range header
- Rate limiting: don't hammer external domains — max 2 requests per domain per scan batch
- Login-protected pages returning 302 to wp-login.php — detect and mark as "requires auth", not "redirect"
- Anchor links (#section) — don't check, they're not broken links
- mailto: and tel: links — skip
- Relative URLs — resolve against site URL before checking
- Very large sites (10K+ posts) — full scan may take hours. Show progress, allow pause/resume.
- `nofollow` links — still check them (broken nofollow is still bad UX)
- Dynamic URLs with query parameters — check the base URL, cache result for variations

### Verification

1. Activate module → "Broken Links" submenu appears
2. Click "Scan Now" → progress indicator shows scanning
3. Results table populates with broken links
4. 404 link shows red status, 301 shows yellow
5. Click "Edit" on a broken link → post editor opens
6. Click "Unlink" → link text preserved, `<a>` tag removed
7. Click "Redirect" → Redirect Manager form opens with URL pre-filled (if module active)
8. Click "Dismiss" → link removed from active results
9. Dashboard widget shows "3 broken links found"
10. Auto-scan fires on schedule → email notification with new broken links
11. Re-scan after fixing → link shows as 200 OK, removed from broken list

---

## Module 30: Setup Wizard

**ID:** `setup-wizard`
**Category:** `admin-interface`
**One-line:** Guided onboarding flow on plugin activation that configures recommended modules and organizes the admin.

### What the User Sees

- On first activation: full-screen setup wizard (4 steps)
- **Step 1: Welcome** — "WPTransformed replaces 30+ plugins. Let's set up your site in 60 seconds."
- **Step 2: Quick Profile** — "What kind of site is this?" Radio buttons:
  - Blog / Content site
  - Business / Corporate
  - eCommerce (WooCommerce detected?)
  - Agency / Freelancer (managing client sites)
  - Developer
  - Each selection pre-selects a recommended module set
- **Step 3: Module Selection** — Grid of all modules with checkboxes, pre-checked based on profile. Grouped by category. Toggle all on/off per category. Shows which plugins each module replaces.
- **Step 4: Admin Cleanup** — "Want us to organize your admin menu?" Before/after preview. Options:
  - Auto-organize sidebar into sections (triggers Smart Menu Organizer)
  - Enable Command Palette (Cmd+K)
  - Enable Dark Mode
  - Clean up admin bar (remove WP logo, howdy, etc.)
- **Finish** → applies all selections, redirects to WPTransformed dashboard with "Setup complete!" notice

### Settings

```
setup-wizard: {
    completed: false,
    completed_at: '',
    selected_profile: '',
    skipped: false
}
```

### Hooks & Implementation

- `admin_init` — check if `setup-wizard.completed` is false AND plugin was just activated (check `wpt_activation_redirect` transient)
- If not completed: redirect to `admin.php?page=wpt-setup-wizard`
- Setup page: full-screen (no admin sidebar), custom CSS, step indicator
- Each step is a `<form>` section shown/hidden with vanilla JS
- No AJAX between steps — collect all answers, submit on final step
- Profile → Module mapping:
  ```php
  $profiles = [
      'blog' => ['content-duplication', 'dark-mode', 'heartbeat-control', 'disable-comments', 'lazy-load', 'seo-module', 'cookie-consent'],
      'business' => ['content-duplication', 'dark-mode', 'login-customizer', 'cookie-consent', 'redirect-manager', 'email-smtp', 'maintenance-mode'],
      'ecommerce' => ['content-duplication', 'dark-mode', 'cookie-consent', 'redirect-manager', 'email-smtp', 'lazy-load', 'image-upload-control', 'limit-login-attempts'],
      'agency' => ['content-duplication', 'dark-mode', 'command-palette', 'smart-menu-organizer', 'search-replace', 'database-cleanup', 'maintenance-mode', 'cron-manager', 'audit-log'],
      'developer' => ['command-palette', 'dark-mode', 'code-snippets', 'cron-manager', 'database-cleanup', 'search-replace', 'audit-log', 'user-role-editor'],
  ];
  ```
- On finish: batch-enable selected modules via `Module_Registry::enable_module($id)`
- Set `setup-wizard.completed = true` + timestamp
- Set `wpt_activation_redirect` transient to false
- "Skip Setup" link on every step — sets `skipped: true`, enables default minimal set

### "New Plugin Installed" Integration

After setup wizard is complete, the module also handles ongoing plugin organization:

- `activated_plugin` action → check if plugin is in known_plugins mapping
- If known: auto-assign to suggested section (if Smart Menu Organizer is active)
- If unknown: show admin notice: "You just activated [Plugin Name]. Where should it go?" with section buttons
- User clicks a section → plugin's menu item assigned to that section
- "Don't ask again for this plugin" option

### Edge Cases

- User deactivates and reactivates WPTransformed — don't re-run wizard (check `completed` flag)
- User manually navigates to wizard URL after completion — show "Already completed" with link to settings
- Multisite network activation — wizard runs per-site on first admin visit, not network-wide
- WooCommerce detection: check `is_plugin_active('woocommerce/woocommerce.php')` for profile suggestion
- No modules selected — warn but allow (user can enable later from settings)
- Very slow sites — wizard should load fast, no heavy queries during setup
- Mobile admin — wizard must be responsive

### Verification

1. Fresh install of WPTransformed → auto-redirect to setup wizard
2. Welcome screen shows → click Next
3. Select "Agency" profile → agency-recommended modules pre-checked
4. Module grid shows all 29 modules with checkboxes → adjust selections
5. Admin Cleanup step → enable menu organizer + command palette + dark mode
6. Click "Finish Setup" → all selected modules activate
7. Redirected to dashboard → "Setup complete!" notice
8. Command Palette works (Cmd+K)
9. Admin sidebar is organized into sections
10. Dark mode is active
11. Deactivate and reactivate → wizard does NOT re-run
12. Activate a new plugin → "Where should this go?" notice appears

---

## Build Notes for `/batch`

When running `/batch` on these 5 modules:

1. Each module builds in its own git worktree (isolated)
2. Each follows the wp-module-builder skill
3. Modules with custom DB tables (28, 29) need `dbDelta()` in `activate_module()` and cleanup in `get_cleanup_tasks()`
4. Module 26 (Command Palette) is pure vanilla JS — no build step, no npm
5. Module 27 (Smart Menu Organizer) is pure vanilla JS — HTML5 Drag and Drop API
6. Module 30 (Setup Wizard) depends on Module Registry API but NOT on specific modules being installed
7. Module 29 (Broken Link Checker) uses WP-Cron for background scanning — register/deregister in activate/deactivate
8. Module 28 (Search Replace) is the most complex — serialized data handling must be bulletproof
9. After build: merge all module branches to main, verify no file conflicts

### Priority Order (if building sequentially)

1. Setup Wizard (30) — standalone, depends only on Module_Registry
2. Command Palette (26) — standalone, vanilla JS
3. Smart Menu Organizer (27) — standalone, vanilla JS, integrates with Setup Wizard
4. Broken Link Checker (29) — custom DB table, WP-Cron, integrates with Redirect Manager
5. Search Replace (28) — most complex, serialized data handling, multisite support

### Module Interactions

- Setup Wizard (30) can enable Command Palette (26), Smart Menu Organizer (27), and Dark Mode (6)
- Smart Menu Organizer (27) receives "new plugin" events and suggests placement
- Broken Link Checker (29) can create redirects via Redirect Manager (14) if active
- Command Palette (26) indexes all modules including these 5
- Search Replace (28) clears caches for modules that cache (Cookie Consent, Redirect Manager, etc.)

These interactions are all optional — each module works independently. The integrations are detected at runtime via `Module_Registry::is_active($id)`.
