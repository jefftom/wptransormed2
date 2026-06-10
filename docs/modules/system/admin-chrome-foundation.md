# Admin Chrome Foundation

## 1. Metadata

- ID: `admin-chrome-foundation`
- Category: `system`
- Tier: Core
- Risk: Advanced
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `smart-menu-organizer`, `admin-bar-manager`, `admin-theme`, `notification-center`, `command-palette`, `client-safe-mode`
- Default enabled: Always available after wizard completion
- Onboarding profiles: All

## 2. One-Liner

Transforms the native WordPress admin chrome — sidebar, topbar, typography, layout, and section grouping — without replacing WordPress core admin structures.

## 3. Scope

This is foundational infrastructure, not a normal module.

It applies the WPTransformed global admin experience across wp-admin:

- Reskin native `#adminmenu` and `#adminmenuwrap`.
- Reskin native `#wpadminbar`.
- Enqueue global typography and design tokens.
- Inject sidebar search trigger, user area, and upgrade card.
- Inject section labels into the native sidebar.
- Provide dark/light/system theme support.
- Establish the CSS variable system.
- Prepare hooks for Smart Menu Organizer and plugin detection.
- Preserve third-party plugin menu compatibility.

## 4. Things NOT To Do

- Do not build a custom sidebar div that replaces `#adminmenu`.
- Do not build a custom admin bar div that replaces `#wpadminbar`.
- Do not call `remove_menu_page()` or `remove_submenu_page()` for global transformation. Client-Safe Mode is the only layer allowed to hide/restrict menus, and only by explicit role/user rules.
- Do not hide unrecognized third-party plugin menu items.
- Do not require React, Vite, or any build step for admin chrome.
- Do not make `admin-global.css` module-gated.
- Do not alter reference mockups.
- Do not use hover-only interactions for core navigation.
- Do not make the admin unusable on mobile.

## 5. What the User Sees

After activation and wizard completion, wp-admin looks transformed:

- Gradient 256px sidebar.
- Organized sections: CONTENT, ECOMMERCE if detected, SECURITY, DESIGN, TOOLS, CONFIGURE.
- Modern nav items with active green accent bar.
- Search bar at top of sidebar that opens command palette.
- Optional upgrade card at bottom of sidebar.
- Modern glassmorphic topbar.
- Breadcrumb/page title on left.
- Theme toggle, notification bell, and avatar on right.
- Outfit font across admin UI.
- Dark/light/system theme support.

The underlying WordPress admin menu remains native and functional.

## 6. Settings Schema

```json
{
  "admin_chrome_foundation": {
    "enabled": true,
    "theme_mode": "system",
    "sidebar_width": 256,
    "show_sidebar_search": true,
    "show_upgrade_card": true,
    "show_user_area": true,
    "section_grouping": true,
    "reduced_motion": false
  }
}
```

Notes:

- `enabled` is not a public module toggle in Phase 1.
- Safe Mode bypasses ALL global chrome (decision 2026-06-09): when Safe Mode is active, `admin-global.css`/`admin-global.js`, font/icon enqueues, topbar injection, section labels, and chrome body classes must not load — stock WP admin renders.
- `theme_mode` persists via localStorage and the `wpt_theme_mode` user meta.
- Theme ownership (decision 2026-06-09): the chrome owns the base light/dark/system theme on `wpt_theme_mode` (`'light'|'dark'|'system'`). A one-time migration converts legacy `wpt_dark_mode` values — both the chrome vocabulary (`'1'/'0'`) and the dark-mode module vocabulary (`'dark'/'light'`) — to `wpt_theme_mode`. Theme-related modules (dark-mode / admin-theme) layer extras on the same key and must not define a parallel preference key.

## 7. Hooks & Implementation

Recommended files:

```text
assets/admin/css/admin-global.css
assets/admin/js/admin-global.js
includes/Admin/Admin_Chrome.php
includes/Admin/Topbar.php
includes/Admin/Sidebar_Sections.php
```

Recommended classes:

```php
WPTransformed\Admin\Admin_Chrome
WPTransformed\Admin\Topbar
WPTransformed\Admin\Sidebar_Sections
```

WordPress hooks:

```php
add_action('admin_enqueue_scripts', [Admin_Chrome::class, 'enqueue_global_assets'], 1);
add_action('admin_menu', [Sidebar_Sections::class, 'inject_section_separators'], 999);
add_action('admin_bar_menu', [Topbar::class, 'customize_topbar'], 999);
add_action('admin_footer', [Admin_Chrome::class, 'inject_sidebar_extras']);
```

Filters:

```php
apply_filters('wpt_admin_chrome_enabled', $enabled);
apply_filters('wpt_sidebar_sections', $sections);
apply_filters('wpt_sidebar_plugin_slotting_rules', $rules);
apply_filters('wpt_admin_topbar_items', $items);
```

## 8. REST API

No REST API is required for initial CSS/chrome rendering.

Optional endpoints later:

```text
GET /wp-json/wpt/v1/admin-chrome/preferences
PUT /wp-json/wpt/v1/admin-chrome/preferences
```

Permissions:

- Preferences require logged-in user.
- Global chrome settings require `manage_wpt_settings`.

## 9. Database Usage

Use user meta for per-user UI preferences:

```text
wpt_theme_mode
wpt_sidebar_collapsed
wpt_reduced_motion
```

Use Settings Storage for global admin chrome defaults.

No custom table required.

## 10. Exact Behavior

On admin page load:

1. Check whether Safe Mode bypass is active.
2. Enqueue `admin-global.css` on all admin pages.
3. Enqueue Outfit, JetBrains Mono, and Font Awesome 6 from bundled plugin assets — self-hosted, no CDN (decision 2026-06-09; WordPress.org guideline 8 + GDPR).
4. Enqueue `admin-global.js`.
5. Apply CSS variables using `--wpt-*`.
6. Style native `#adminmenu`, `#adminmenuwrap`, and `#wpadminbar`.
7. Inject section separators through `admin_menu` at priority 999.
8. Inject sidebar extras in `admin_footer`.
9. Apply persisted theme mode.
10. Respect `prefers-reduced-motion`.

Sidebar grouping:

1. Map default WP menu items to CONTENT, DESIGN, TOOLS, CONFIGURE. Comments remain under CONTENT. Native Tools remains accessible under TOOLS.
2. Detect ecommerce plugins and create ECOMMERCE section only if needed.
3. Detect builders and slot them into DESIGN.
4. Detect BuiltRight Forms and slot into CONTENT.
5. Put unrecognized plugin items below CONFIGURE.
6. Never hide unrecognized items.

## 11. Edge Cases

### Plugin adds menu item late

- It should remain visible.
- If not recognized, place below CONFIGURE/catch-all.

### Admin page uses unusual markup

- Global styling should be defensive and not break layout.

### User disables JS

- Sidebar should still be usable.
- Section labels may remain basic.

### Mobile wp-admin

- Sidebar should not trap focus or break WordPress mobile menu behavior.
- Avoid fixed desktop-only widths on small screens.

### Safe Mode

- Safe Mode must be able to bypass custom chrome if chrome causes layout issue.

### Accessibility

- Search trigger must be keyboard reachable.
- Theme toggle must have accessible label.
- Color contrast must be checked.

## 12. Conflicts & Dependencies

Depends on:

- Settings Storage
- Permission Model, for global settings only

Related modules:

- Smart Menu Organizer
- Admin Bar Manager
- Admin Theme
- Command Palette
- Notification Center

## 13. Security & Permissions

- Do not echo unsanitized user/site data into the topbar.
- Escape breadcrumbs/page titles.
- Escape avatar/profile URLs.
- Verify nonces for any preference save.
- Do not expose admin-only URLs to users lacking capability.

## 14. Mobile/Responsive Behavior

Desktop:

- Sidebar width 256px.
- Topbar height 58px.
- Content max-width 1340px where applicable.

Tablet:

- Sidebar may collapse or overlay using native WP behavior.
- Topbar items reduce spacing.

Mobile:

- Do not force 256px sidebar over content.
- Search/command palette opens as full-screen overlay.
- Topbar may stack or hide secondary labels.
- Touch targets at least 44px.
- No hover-only navigation.

## 15. Data Retention / Uninstall

On deactivation:

- Stop loading global CSS/JS.
- Native WordPress admin returns.

On uninstall:

- Remove user meta keys if full cleanup selected.
- Remove global admin chrome settings if full cleanup selected.

## 16. Verification & Acceptance Criteria

- [ ] `admin-global.css` loads on every admin page.
- [ ] Native `#adminmenu` remains present.
- [ ] Native `#wpadminbar` remains present.
- [ ] No custom replacement sidebar is created.
- [ ] No `remove_menu_page()` is used for global transformation.
- [ ] Default WP menu items still work.
- [ ] Unrecognized plugin menu items remain visible.
- [ ] Sidebar section labels render.
- [ ] WooCommerce/EDD/SureCart section appears only when detected.
- [ ] Builder plugins slot into DESIGN when detected.
- [ ] BuiltRight Forms slots into CONTENT when detected.
- [ ] Search trigger appears and can open command palette when command palette exists.
- [ ] Theme toggle persists preference.
- [ ] Dark mode works.
- [ ] Reduced motion is respected.
- [ ] Mobile admin remains usable.
- [ ] Safe Mode can bypass global chrome.
- [ ] No PHP notices/fatals on unrelated admin pages.


### Additional Safety Contract

Global Admin Transformation is allowed to style, visually organize, and relocate native admin chrome/menu items.

It is not allowed to globally restrict access.

Client-Safe Mode is the only layer allowed to hide or restrict menu items, and only when explicitly enabled for a role/user. Comments and Tools must not be hidden by global chrome.

### CSS Isolation

Global CSS should focus on:

- `#adminmenu`
- `#adminmenuwrap`
- `#wpadminbar`
- `body.wp-admin`
- `.wpt-*` namespaced elements
- `.wpt-page`, `.wpt-app`, `.wpt-admin` wrappers

Avoid broad styling of `.button`, `.postbox`, `table`, `input`, `.notice`, `.wrap`, or editor canvas elements unless scoped under WPTransformed pages.


## 17. Deferred Features

- Full per-role admin chrome presets.
- Drag/drop section customization.
- User-defined custom sections.
- White-labeling all chrome labels in Core.

## 18. Known Gotchas

- Styling WordPress native admin is fragile; selectors must be defensive.
- Some plugins add unusual menu markup.
- Do not over-style plugin app pages so heavily that they become unusable.
- Global CSS must be scoped carefully to avoid breaking editor canvases.
