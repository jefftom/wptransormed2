# Module: Admin Menu Editor

## Metadata

| Field | Value |
|---|---|
| Module ID | `admin-menu-editor` |
| Category | `admin-interface` |
| Parent module | `navigation-and-menus` |
| Tier | `free` (base) / `pro` (custom themes beyond 5 presets) |
| App page | `yes` — full three-panel application page |
| Replaces | Admin Menu Editor Pro by Janis Elsts ($39/yr), WP Adminify menu editor, ASE Pro's admin menu organizer |
| Reference files | `assets/admin/reference/app-pages/menu-editor-v3.html` |
| Reference code | `vendor/reference/advanced-admin-menu-editor/` (v3.5.0, ~4000 lines, production-tested) |
| Spec source | `wptransformed-v1-module-specs.md` Module 2 (base) + AME v3.5 port (full depth) |
| Status | `stub` (v2 rebuild required — current repo implementation is partial) |

## One-line description

Reorder, rename, hide, re-icon, and style WordPress admin sidebar menu items with drag-and-drop, per-role multi-config profiles, and a full theme system — all while preserving 100% plugin compatibility by styling the native `$menu` global instead of replacing it.

## What the User Sees

**Navigation to module:**
- Menu Editor appears under CONTENT → Navigation & Menus → Menu Editor in the WPTransformed sidebar
- Clicking opens a dedicated three-panel app page at `admin.php?page=wpt-menu-editor`

**Three-panel layout (from `menu-editor-v3.html`):**
- **Left panel — Live Sidebar Preview**: A real-time mini-preview of the admin sidebar showing exactly what the current role will see. Updates instantly as the user edits in the center panel. Has a role selector dropdown at the top ("Preview as: Administrator / Editor / Author / Contributor / Subscriber") so the user can verify how each role will experience the menu.
- **Center panel — Menu Items (the editor)**: A drag-drop sortable list of every top-level menu item with its current icon, label, slug, and eye toggle. Each item has an "expand" chevron that reveals its submenu items as an indented sortable sublist. Clicking any item selects it and loads its details into the right panel.
- **Right panel — Edit: {selected item}**: Settings for the currently selected menu item: Menu Title (text input), Custom URL (optional — lets user point a menu item at a different destination), Icon picker (48-icon Dashicons grid + "Upload SVG" option), Visible to Roles (checkbox list of all roles).

**Top bar:**
- Title: "Admin Menu Editor" with subtitle "Drag and drop to reorder, click to edit, toggle to show/hide"
- Back arrow → WPTransformed dashboard
- **Reset** button (with confirm dialog) — restores the current configuration to WordPress defaults for the selected role
- **Preview** button — opens a full-page preview of the admin with the current config applied (read-only, doesn't save)
- **Save Changes** button — persists the current configuration via AJAX

**Multi-configuration system:**
- Above the three panels, a horizontal tab bar lets the user switch between named configurations: `All Roles | Administrator | Editor | Author | Contributor | Subscriber | + New Config`
- Each tab represents a saved configuration with its own menu order, hidden items, renamed items, custom icons, and role assignments
- When the user selects a role tab, the editor loads that role's configuration. If no role-specific config exists, the "Default" config is shown with a "Create custom profile for [Role]" button.
- Unlike ASE and Admin Menu Editor Pro, **multiple configs can be saved and activated simultaneously** — different roles can have entirely different menu structures.

**Theme system (separate section, collapsible at bottom of page):**
- "Admin Theme" panel lets the user pick from 5 built-in themes (NIN, Apple Light, Apple Dark, Blueprint, Minimal) OR build a custom theme
- Custom theme builder: 10 color slots (menu bg, menu text, menu hover bg, menu hover text, menu active bg, menu active text, submenu bg, submenu text, submenu hover bg, submenu hover text)
- Sidebar gradient builder: toggle on/off, start color, middle color, end color, angle (0–360°)
- Typography: font family dropdown, font weight, font size, content font (separate from menu font), content heading size
- Separators: color, style (solid/dashed/dotted), width
- Global admin styles: 11 color slots for page bg, body text, admin bar, card surfaces, buttons, links, inputs, headings
- "Save as preset" button — stores the current theme as a custom preset with a name for later reuse
- Custom CSS textarea for edge cases

## Settings Schema

```php
'admin-menu-editor' => [
    'enabled'        => true,

    // Multi-config system — each config has its own menu structure
    // Keyed by config_id, which is either a role slug or a custom name
    'configs'        => [
        'default' => [
            'name'           => 'Default (All Roles)',
            'assigned_roles' => ['administrator', 'editor', 'author', 'contributor', 'subscriber'],
            'menu_items'     => [
                // Each top-level menu item
                [
                    'slug'         => 'index.php',
                    'custom_label' => '',           // empty = use original
                    'custom_icon'  => '',           // empty = use original
                    'custom_url'   => '',           // empty = use original
                    'hidden'       => false,
                    'submenu'      => [
                        // Each submenu item follows same shape
                        ['slug' => '...', 'custom_label' => '', 'hidden' => false],
                    ],
                ],
                // ... in user-defined order
            ],
        ],
        // Additional configs keyed by role or custom name
    ],

    // Active theme (separate from menu configs)
    'active_theme'   => 'default',  // or 'nin', 'apple-dark', 'custom:preset-key'
    'custom_themes'  => [
        // preset_key => [ name, colors, typography, gradient, global, extra_css ]
    ],
    'theme_settings' => [
        'preset'       => 'default',
        'colors'       => [],       // 10 color slots keyed by mBg/mTxt/mHBg/etc.
        'gradOn'       => false,
        'gradStart'    => '#0f2847',
        'gradMid'      => '#1a4180',
        'gradEnd'      => '#2563eb',
        'gradAngle'    => 165,
        'radius'       => 0,
        'speed'        => 0.2,
        'spacing'      => 8,
        'font'         => '',
        'fontWeight'   => '',
        'fontSize'     => 14,
        'cFont'        => '',
        'cFontWeight'  => '',
        'cFontSize'    => 13,
        'cHeadingSize' => 23,
        'sepClr'       => '#3c3c3c',
        'sepStyle'     => 'solid',
        'sepWidth'     => 1,
        'global'       => [],       // 11 global color slots
        'extra_css'    => '',
    ],
]
```

Notes on the schema:
- The `configs` array is the canonical source of truth — `default` is the fallback config used when a user's role has no matching config.
- `custom_themes` stores user-saved theme presets separately from the active theme so the user can switch between presets without losing their custom work.
- `theme_settings` is kept flat (not nested inside a preset) because the active settings can diverge from any preset — the UI syncs back to the preset when the user clicks "Save preset."

## WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|---|---|---|---|
| `admin_menu` | action | `9998` | Cache the raw `$menu` / `$submenu` globals BEFORE any modification, so the editor UI can always show the real unfiltered menu |
| `admin_menu` | action | `9999` | Apply menu modifications — resolves the current user's active config, reorders `$menu`, removes hidden items, rewrites labels/icons/URLs, modifies `$submenu` in place |
| `admin_enqueue_scripts` | action | `10` | Load editor CSS/JS **only** on the Menu Editor app page (`$hook === 'wpt-admin_page_wpt-menu-editor'`) — never globally |
| `admin_head` | action | `10` | Output the active theme's generated CSS (inline, not enqueued, so theme changes apply without a cache-bust dance) |
| `wp_ajax_wpt_menu_save_config` | action | `10` | AJAX: save a config (nonce + `manage_options`) |
| `wp_ajax_wpt_menu_load_config` | action | `10` | AJAX: load a config into the editor |
| `wp_ajax_wpt_menu_delete_config` | action | `10` | AJAX: delete a role-specific config (revert to default) |
| `wp_ajax_wpt_menu_reset` | action | `10` | AJAX: reset the current config to WordPress defaults |
| `wp_ajax_wpt_menu_get_current` | action | `10` | AJAX: return the raw cached `$menu` structure for the editor to render |
| `wp_ajax_wpt_menu_save_theme` | action | `10` | AJAX: save theme settings |
| `wp_ajax_wpt_menu_save_preset` | action | `10` | AJAX: save current theme settings as a named custom preset |
| `wp_ajax_wpt_menu_delete_preset` | action | `10` | AJAX: delete a custom preset |
| `plugin_action_links_*` | filter | `10` | Add a "Settings" link to the WPTransformed row on the Plugins page |

## Exact Behavior

### Behavior 1: Applying menu modifications on every admin page load

1. Hook `admin_menu` at priority 9998 runs `cache_admin_menu()` which stashes the raw `$menu` and `$submenu` globals into a class property. This is the unfiltered truth the editor UI will display.
2. Hook `admin_menu` at priority 9999 runs `apply_menu_modifications()`:
   1. Early return if `$this->menu_processed` is already true (guard against double-apply)
   2. Early return if `configs` array is empty
   3. Determine which config applies to the current user: loop through `configs`, for each config check if any of `$user->roles` intersects with `$config['assigned_roles']`. First match wins. If no match, use the `default` config.
   4. Build a lookup map `$menu_by_slug` from the global `$menu` array, keyed by slug (index 2 of each menu array entry).
   5. Build a new `$new_menu` array by walking the saved `menu_items` in order:
      - For each saved item, look up the slug in `$menu_by_slug`.
      - Skip if slug no longer exists (plugin was uninstalled — silent skip).
      - If `hidden` is true, also unset `$submenu[$slug]` and continue (don't add to new menu).
      - Apply `custom_label` to original `[0]` if set.
      - Apply `custom_icon` to original `[6]` if set.
      - Apply `custom_url` to original `[2]` if set (be careful: changing the slug breaks submenu lookups — only overwrite if user explicitly set it).
      - Add to `$new_menu` at the next position.
      - Track slug in `$processed_slugs` set.
   6. Handle submenus: for each saved top-level item that has a `submenu` array, rebuild `$submenu[$slug]` in the saved order. For each submenu entry in the saved order, find the matching entry in the original `$submenu[$slug]` by slug and apply `custom_label` / `hidden`. Then append any submenu items that exist in the original but weren't in the saved config (new items added by plugin updates).
   7. **Critical: append any NEW top-level menu items** from `$menu_by_slug` that weren't in `$processed_slugs`. This is the graceful handling of newly installed plugins — their menu items appear at the bottom instead of disappearing.
   8. Assign `$new_menu` back to `global $menu`.

### Behavior 2: Rendering the editor UI

1. On the Menu Editor app page, the module enqueues its CSS/JS (only here).
2. PHP `render_app_page()` outputs the three-panel HTML shell matching `menu-editor-v3.html` exactly.
3. JS fires an AJAX request to `wpt_menu_get_current` which returns the raw cached `$menu` / `$submenu` as JSON.
4. JS also fires `wpt_menu_load_config` for the currently selected config tab.
5. The editor merges the two: starts with the raw menu, overlays the saved config's customizations, renders as sortable list.
6. Drag-drop uses vanilla HTML5 drag API (`dragstart`, `dragover`, `drop`), NOT jQuery UI Sortable.
7. On Save, JS collects the full editor state and POSTs it to `wpt_menu_save_config`.
8. The Live Sidebar Preview panel re-renders on every edit using the same template, but scoped to the role selected in its own role dropdown.

### Behavior 3: Theme CSS generation

1. On every admin page load (via `admin_head` hook), the module reads `active_theme` and `theme_settings`.
2. If `active_theme` is a built-in preset (`nin`, `apple-dark`, etc.), it enqueues the corresponding pre-built CSS file from `modules/admin-interface/themes/`.
3. If `active_theme` starts with `custom:`, it generates CSS dynamically from the `theme_settings` array and outputs it inline in `<head>`.
4. The generated CSS targets native WordPress admin selectors: `#adminmenu`, `#adminmenuwrap`, `#adminmenu li.menu-top`, `#adminmenu .wp-submenu`, `#adminmenu .wp-menu-name`, `#wpcontent`, `#wpbody`, `.wrap`, etc. It uses CSS custom properties (`--wpt-menu-bg`, `--wpt-menu-text`, etc.) so dark mode and other modules can override without specificity wars.
5. Custom CSS from `extra_css` is appended last (highest precedence for user overrides).

## Settings Page UI

See `assets/admin/reference/app-pages/menu-editor-v3.html` — that file IS the design. Extract CSS verbatim, match the three-panel layout exactly. Do not redesign.

Key UI rules beyond the mockup:

- **Role tab bar**: above the three panels, horizontal scroll if more than 6 tabs. "+ New Config" at the end opens a modal to create a named custom config not tied to a specific role.
- **Unsaved changes indicator**: if the user edits anything, the Save Changes button gets a pulse animation and an "Unsaved changes" badge appears. Navigating away shows a `beforeunload` confirmation.
- **Preview as role dropdown** (left panel): changes what the preview shows but doesn't change the active editor. This lets admins verify before saving.
- **Per-item role visibility**: when checking/unchecking roles for an item in the right panel, show an inline note explaining the effect: "This item will be hidden for Editor, Author, Contributor."
- **Live sidebar preview fidelity**: the preview must render using the same CSS as the real admin sidebar, not a simplified mock. Copy the admin sidebar DOM structure and apply the current theme CSS.
- **Theme panel** collapses by default — don't overwhelm first-time users. Expand on click with a smooth height transition.

## REST Endpoints

None in v1 — all editor interactions go through `wp_ajax_*` handlers. REST endpoints may be added in v3 if third-party integrations need programmatic config management.

## Scope — What This Module Does NOT Do

- **Does NOT modify the admin bar (top toolbar)** — that's the `admin-bar-manager` module. Admin bar and admin menu are separate WordPress systems.
- **Does NOT block access to hidden pages** — hiding a menu item removes it from the sidebar but does not revoke the capability. Users can still access `wp-admin/edit-comments.php` by direct URL even if Comments is hidden. This is intentional (hiding ≠ access control). For access control, use `user-role-editor` module.
- **Does NOT handle Network Admin menu** — Network Admin uses a different menu system. v1 scope is site admin only. Multisite Network Admin editor deferred to v3.
- **Does NOT add entirely new menu items pointing at external URLs** — use the `custom-admin-pages` module (deferred to v2) for that. This module only modifies existing menu items.
- **Does NOT edit the Customizer menu** — that's a separate WordPress UI and out of scope.

## Things NOT To Do

- **Do NOT call `remove_menu_page()`** — style the `$menu` global directly. We keep the item in memory (so capabilities resolve correctly) but skip it during rendering. Calling `remove_menu_page()` breaks any plugin that depends on finding the page by slug.
- **Do NOT use jQuery UI Sortable** — vanilla HTML5 drag API only. jQuery UI Sortable is deprecated and adds 40KB for no reason.
- **Do NOT modify `$menu` before priority 9999** — other plugins register their menu items on `admin_menu` at various priorities. Running at 9999 ensures we see the final state before modifying.
- **Do NOT overwrite `$menu` if the saved config is empty** — if a user deletes all items in their config (or settings are corrupted), fall back to the native `$menu` instead of showing an empty sidebar.
- **Do NOT assume the editor always has the raw menu** — if the cached menu is empty (e.g., the editor is loaded via AJAX before `admin_menu` has fired), fetch it by bootstrapping a synthetic `admin_menu` cycle.
- **Do NOT store config keys as numeric indexes** — use slugs. Indexes shift when WordPress reorders menus internally during plugin install/uninstall.
- **Do NOT enqueue editor assets globally** — they're heavy (drag-drop library weight + large sortable lists). Scope to the Menu Editor app page only.
- **Do NOT load all themes' CSS files on every page** — only the active theme. Custom themes render inline in `admin_head`.

## Known WordPress Gotchas

- **`$menu` array indexes are string positions, not sequential integers.** WordPress uses sparse arrays like `[0 => dashboard, 5 => posts, 10 => media, 20 => pages]`. When rebuilding, always use a running counter (`$position++`) to avoid collisions, and assign to `$new_menu[$position]` explicitly.
- **Some menu items are separators with `$menu[$i][4] === 'wp-menu-separator'`**. Don't treat these as editable items. Filter them out of the editor UI unless the user explicitly adds a custom separator.
- **Submenu items use a completely different array shape** from top-level items. Top-level: `[title, cap, slug, page_title, classes, hookname, icon]`. Submenu: `[title, cap, slug, page_title]`. Index 2 is the slug in both, but the rest differs.
- **The slug for submenu items is sometimes a parent-file-relative path** (like `edit-tags.php?taxonomy=category&post_type=post`). When looking up submenu items by slug, match the full string including query args.
- **`current_user_can()` reads capabilities set in `$menu[$i][1]`** — if you rewrite a menu item's label but change the slug too, the capability check may fail and the item will be unreachable. Only change `custom_url` if the user explicitly asks for it.
- **Plugins can add menu items at non-default priorities.** Elementor adds its menu at `admin_menu` priority 40, WooCommerce at 56. Running at 9999 always catches them.
- **The `wp_menu_parent_file` filter affects menu "active" state** — if you change a parent slug, WP may not highlight the current page. Avoid changing parent slugs unless necessary.
- **Multisite: switching to a blog via `switch_to_blog()` reloads menus.** The module's cached menu may become stale. Don't cache across blog switches.

## Edge Cases

- **Plugin uninstalled between saves**: The saved config references a slug that no longer exists in `$menu_by_slug`. Silent skip — don't error out.
- **Plugin installed between saves**: New slug exists in `$menu_by_slug` but not in saved config. Append to the bottom of the new menu (Behavior 1, step 7).
- **User has multiple roles**: WordPress returns roles in `$user->roles` array. Use first-match-wins against `assigned_roles` — the first config whose assigned_roles contains any of the user's roles wins. Admins should configure this intentionally.
- **User has a custom role not in any config**: Falls back to the `default` config. If `default` is also missing, the native `$menu` is left untouched.
- **User deletes the `default` config**: Not allowed — UI must prevent this. On AJAX save, server-side validation rejects attempts to remove the `default` key.
- **Corrupted settings JSON**: Catch exceptions in `apply_menu_modifications()` and log to `wpt_audit_log` if the Audit Log module is active. Fall back to native `$menu`.
- **Theme CSS applied before menu HTML exists**: The inline `<style>` in `admin_head` runs before `#adminmenu` renders. This is correct — the browser resolves CSS against DOM at paint time regardless of source order.
- **User sets all items to hidden for a role**: The role sees an empty sidebar. This is a valid configuration (agencies sometimes want clients to only access one specific page via bookmark). Don't prevent it, but show a warning in the editor: "This config hides every menu item — users with this role will have no sidebar navigation."
- **Extremely large menus** (50+ top-level items from lots of plugins): Editor UI must virtualize or paginate. Sortable drag-drop can lag with large lists. Performance budget: editor must render in < 500ms even with 50 items.

## Verification Steps

1. Activate the Menu Editor module → no PHP errors → the module card shows "Active" in the dashboard.
2. Navigate to Navigation & Menus → Menu Editor → three-panel app page loads.
3. Left panel shows a live preview of the current admin sidebar with all items visible.
4. Center panel shows a drag-drop list of all top-level menu items.
5. Drag "Posts" below "Pages" → Save → navigate to any other admin page → Pages appears above Posts in the real sidebar.
6. Select "Dashboard" in the center → right panel loads its details → change Menu Title to "Home" → Save → refresh → sidebar shows "Home".
7. Toggle the eye icon on "Comments" → Save → refresh → Comments is gone from the sidebar.
8. Navigate directly to `wp-admin/edit-comments.php` → page still loads (hiding is not access control, documented in inline note).
9. Click Reset → confirm dialog → accept → menu returns to WordPress default order.
10. Install a new plugin that adds a menu item → the new item appears at the bottom of the editor list and at the bottom of the real sidebar (graceful new-item handling).
11. Switch to the "Editor" role tab → "Using default settings" shown with "Create custom profile" button → click it → hide "Plugins" and "Updates" for Editors → Save.
12. Log in as an Editor user → sidebar does not contain Plugins or Updates. Log in as Admin → both are visible.
13. Expand Posts in the editor → submenu items appear as indented sublist → drag "Categories" above "Tags" → Save → refresh → Posts submenu reflects new order.
14. Open the theme panel → select "Apple Dark" → Save → refresh → sidebar uses dark grey background with white text (from `theme-apple-dark.css`).
15. Custom theme: open color picker for Menu BG → set to `#1a1a2e` → toggle gradient on → set gradient angle to 165° → Save → refresh → sidebar shows gradient.
16. Save custom theme as preset "My Theme" → switch to NIN theme → switch back to "My Theme" → all custom settings restored.
17. Deactivate the module → sidebar reverts to WordPress defaults, all custom styling gone, all custom menu configs ignored (but preserved in settings for reactivation).
18. Uninstall the module via safe mode → no PHP errors, options cleaned per uninstall contract.

## Deferred Features

- **Full drag-and-drop reordering of items between parent menus** *(v3)* — Moving "Tools → Import" to become a top-level menu item. The current module only reorders within existing parent-child relationships.
- **Completely new menu items pointing to external URLs or custom admin pages** *(v3)* — "Add a custom menu item named Google Analytics linking to analytics.google.com." Requires a separate Custom Admin Pages module to handle the rendering side.
- **Menu item capability overrides** *(v3)* — Change which capability is required to see an item (e.g., "Only users with `manage_woocommerce` can see Tools"). Requires careful interaction with User Role Editor module.
- **Import / export config as JSON** *(v2)* — Already a goal in the broader Blueprint System; Menu Editor should contribute its configs to the unified export/import flow.
- **Multi-site Network Admin menu editor** *(Pro)* — Network Admin uses a separate `$menu` global (`$menu` is per-blog). Non-trivial to implement because multisite WordPress loads menus in a different context.
- **Per-user (not just per-role) overrides** *(Pro)* — Specific users get a custom menu regardless of their role. Useful for agencies where one user needs special access.
- **Menu "folders" that expand/collapse client-side** *(Pro)* — Turn the sidebar into a collapsible accordion for sites with 30+ menu items.
- **Live preview strip showing ALL affected role previews simultaneously** *(Pro)* — Currently the left panel shows one role at a time. Pro could show a grid of all roles for at-a-glance comparison.

## Known Issues

*(Migrated from `TODO.md` Module 08 — none filed at the time of spec write. Any bugs discovered during the v2 build should be logged here.)*

## References

- **v1 spec source**: `wptransformed-v1-module-specs.md` Module 2 (lines 135–253) — base 9-step spec for basic reorder/rename/hide
- **Wave spec source**: `module-specs-wave3.md` Module 27 (Smart Menu Organizer — related but distinct module for auto-grouping)
- **Reference HTML**: `assets/admin/reference/app-pages/menu-editor-v3.html` — three-panel app page design, CSS extract verbatim
- **Reference code**: `vendor/reference/advanced-admin-menu-editor/` — v3.5.0 production-tested implementation, port mechanics verbatim (multi-config, theme system, AJAX handlers, apply_menu_modifications), adapt to Module_Base shape
- **Related modules**:
  - `docs/modules/admin-interface/admin-bar-manager.md` — parallel module for the top admin bar (separate WordPress system)
  - `docs/modules/admin-interface/smart-menu-organizer.md` — complementary auto-grouping module (detects plugins, groups menu items into sections)
  - `docs/modules/admin-interface/dark-mode.md` — shares the CSS custom properties layer; Menu Editor themes must respect dark mode when active
  - `docs/modules/design/white-label.md` — shares the theme system primitives (colors, fonts, global surfaces)
  - `docs/modules/security/user-role-editor.md` — the module that handles actual capability changes (Menu Editor only handles visibility)
- **Decisions**:
  - `DECISIONS.md` 2026-04-01 "Claude Code: Specify ALL admin UI touchpoints explicitly" — this spec lists every UI touchpoint per the rule
  - Architectural rule (from `ui-restructure-spec.md` and `CLAUDE.md`): "STYLE the existing WordPress admin sidebar — do NOT replace it"
- **Conversation history** (for recovery only, not a build dependency):
  - Feb 2026 "Recreating the WordPress admin menu plugin" chat — initial AME v3.0 rebuild
  - Feb 2026 "WordPress admin plugin transformation research" — v1 Module 2 specced
  - Apr 2026 "Continuing a looped conversation" — APP module + reference HTML added

## Change Log

- **2026-04-05** — Initial v2 spec synthesized from: v1 Module 2 spec, AME v3.5 production zip (~4000 lines), `menu-editor-v3.html` mockup, `TODO.md` Module 08 deferred features, `DECISIONS.md` architectural rules, `ui-restructure-spec.md` "style don't replace" rule. This replaces the scattered historical sources as the single canonical spec for Menu Editor.
