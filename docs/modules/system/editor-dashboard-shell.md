# Editor Dashboard Shell

## 1. Metadata

- ID: `editor-dashboard-shell`
- Category: `system`
- Tier: Core
- Risk: Moderate
- Status: Phase 1 Foundation
- Replaces: Default WordPress dashboard landing experience
- Related modules: `dashboard`, `dashboard-manager`, `client-safe-mode`, `module-library`, `notification-center`
- Default enabled: Enabled after wizard completion
- Onboarding profiles: All

## 2. One-Liner

Creates the modern WPTransformed editor dashboard at `admin.php?page=wpt-dashboard`, using real WordPress data as the post-wizard landing page.

## 3. Scope

The Editor Dashboard Shell is the user-facing home of the transformed admin.

It provides:

- Modern content workspace.
- Real WordPress data.
- Bento stat cards.
- Recent content lists.
- Drafts and scheduled content.
- Quick actions.
- Site health summary.
- Module activity overview.
- Links into WPTransformed app pages.

This is different from the Module Library.

## 4. Things NOT To Do

- Do not use dummy data.
- Do not make this a module grid.
- Do not hide the native WordPress dashboard permanently.
- Do not remove `index.php`.
- Do not show fake performance/security scores.
- Do not build full analytics here.
- Do not show client/editor users tools they cannot access.

## 5. What the User Sees

After wizard completion, user lands on:

```text
admin.php?page=wpt-dashboard
```

Dashboard sections:

- Welcome banner with user name.
- Site/content stats.
- Recent posts/pages.
- Recent drafts.
- Upcoming scheduled content.
- Quick actions:
  - New Post
  - New Page
  - Upload Media
  - Duplicate Content, if module active
  - Open Modules
  - Open Settings
- Site health summary.
- Notifications preview.
- Module activity summary.
- Client-safe/editor-safe workspace depending on role.

The default WordPress dashboard remains accessible.

## 6. Settings Schema

```json
{
  "editor_dashboard_shell": {
    "enabled": true,
    "redirect_after_wizard": true,
    "redirect_default_dashboard": false,
    "show_welcome_banner": true,
    "show_content_stats": true,
    "show_recent_content": true,
    "show_scheduled_content": true,
    "show_quick_actions": true,
    "show_site_health": true,
    "show_module_activity": true
  }
}
```

Notes:

- `redirect_default_dashboard` should remain false in Phase 1 unless explicitly enabled.
- Role-specific widget visibility belongs to Dashboard Manager / Client-Safe Mode later.

## 7. Hooks & Implementation

Recommended files:

```text
includes/Admin/Editor_Dashboard.php
assets/admin/css/editor-dashboard.css
assets/admin/js/editor-dashboard.js
```

Recommended class:

```php
WPTransformed\Admin\Editor_Dashboard
```

WordPress hooks:

```php
add_action('admin_menu', [Editor_Dashboard::class, 'register_page']);
add_action('admin_enqueue_scripts', [Editor_Dashboard::class, 'enqueue_assets']);
```

Filters:

```php
apply_filters('wpt_editor_dashboard_cards', $cards, $user_id);
apply_filters('wpt_editor_dashboard_quick_actions', $actions, $user_id);
apply_filters('wpt_editor_dashboard_query_args', $args, $context);
```

## 8. REST API

Initial implementation may render server-side.

Optional endpoints:

```text
GET /wp-json/wpt/v1/editor-dashboard
```

Permissions:

- Requires logged-in user.
- Response filtered by current user capability.

## 9. Database Usage

No custom table required.

Data sources:

- `wp_posts`
- `wp_comments`
- `wp_users`
- Site Health where available
- Module registry/settings where available

Do not store dashboard data in Phase 1 except dismissed widget preferences.

## 10. Exact Behavior

Register page:

1. Add WPTransformed dashboard page at `admin.php?page=wpt-dashboard`.
2. Ensure it appears as the primary WPTransformed Dashboard link.
3. After wizard completion, redirect user to this page.
4. Do not remove native `index.php`.

Render dashboard:

1. Get current user.
2. Query recent posts/pages user can edit or view.
3. Query drafts.
4. Query scheduled posts.
5. Count posts/pages/comments/users according to capability.
6. Build quick actions based on capabilities and active modules.
7. Render cards using WPTransformed design system.
8. Escape all output.

## 11. Edge Cases

### Fresh site with no content

- Show empty states with helpful CTAs.

### User is editor

- Show content actions only.
- Hide module/security/developer controls.

### User lacks edit_posts

- Show limited dashboard or redirect to profile.

### Site has many posts

- Limit queries.
- Avoid expensive counts where possible.

### Multisite

- Show current site data only in Phase 1.

## 12. Conflicts & Dependencies

Depends on:

- Admin Chrome Foundation
- Permission Model

Related:

- Dashboard Manager
- Client-Safe Mode
- Module Library
- Notification Center

## 13. Security & Permissions

- Use capability checks for every action link.
- Escape all post titles, URLs, user names.
- Do not expose drafts to users who cannot edit/read them.
- Use `current_user_can()` checks for counts and links.
- Nonces required for any quick action that changes state.

## 14. Mobile/Responsive Behavior

Desktop:

- Bento card layout.
- Two/three-column sections.

Tablet:

- Two-column cards.
- Lists stack below stats.

Mobile:

- Single-column cards.
- Quick actions as stacked buttons.
- Avoid wide tables.
- Use cards for recent content.
- Sticky primary action optional.

## 15. Data Retention / Uninstall

Dashboard preferences follow Settings Storage.

On deactivation:

- `admin.php?page=wpt-dashboard` becomes unavailable.
- WordPress default dashboard remains available.

On uninstall:

- Remove dashboard preference settings if full cleanup selected.

## 16. Verification & Acceptance Criteria

- [ ] `admin.php?page=wpt-dashboard` exists.
- [ ] Wizard can redirect to the editor dashboard.
- [ ] Default WordPress dashboard remains accessible.
- [ ] Dashboard uses real WordPress data.
- [ ] Empty state works on fresh site.
- [ ] Recent posts list links to edit screens.
- [ ] Drafts list respects user capabilities.
- [ ] Scheduled content list works.
- [ ] Quick actions respect capabilities.
- [ ] Editor role does not see admin-only controls.
- [ ] No dummy data is rendered.
- [ ] Mobile layout stacks cleanly.
- [ ] No expensive unbounded queries.
- [ ] Output is escaped.

## 17. Deferred Features

- Full dashboard widget builder.
- Per-role dashboard layout editor.
- Client Dashboard Builder Pro.
- GA4/GSC cards.
- White-labeled client report preview.

## 18. Known Gotchas

- Avoid confusing this with the Module Library.
- Keep it useful for editors, not only administrators.
- Query counts carefully on large sites.
