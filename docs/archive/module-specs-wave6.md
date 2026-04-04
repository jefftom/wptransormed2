# WPTransformed Wave 6 — The 125 Sprint

> 36 modules to hit 125 total. Split into:
> - Batch 6A: ASE parity gaps (12 small modules)
> - Batch 6B: ASE parity gaps continued (12 small modules)  
> - Batch 6C: Unique differentiators (12 modules nobody else has)
> Build with `/batch` — 3 runs of 12.

---

## Batch 6A: ASE Parity Gaps — Part 1 (12 modules)

| # | Module ID | One-Line | Tier |
|---|-----------|----------|------|
| 90 | avif-upload | Enable AVIF image upload in Media Library | Free |
| 91 | terms-order | Drag-and-drop reorder taxonomy terms | Pro |
| 92 | media-visibility-control | Limit media library visibility by user role — non-admins only see their own uploads | Free |
| 93 | external-links-new-tab | Force all external links in post content to open in new tab with rel="noopener noreferrer nofollow" | Free |
| 94 | custom-nav-new-tab | Allow custom navigation menu items to open in new tab | Free |
| 95 | two-factor-auth | TOTP authenticator app + recovery codes + email verification for login | Pro |
| 96 | hide-admin-bar | Hide admin bar on frontend and/or backend for specific user roles | Free |
| 97 | wider-admin-menu | Increase admin sidebar width to accommodate longer menu item labels | Free |
| 98 | image-sizes-panel | Show all available image sizes with URLs when viewing an image in media library | Free |
| 99 | registration-date-column | Display registration date in Users list table, sortable | Free |
| 100 | custom-frontend-css | Add custom CSS to all frontend pages (separate from Custom Frontend Code which handles scripts) | Free |
| 101 | redirect-404 | Redirect all 404 pages to homepage or custom URL with 301 status | Free |

### Module 90: AVIF Upload
**Hooks:** `upload_mimes` filter → add `'avif' => 'image/avif'`. `wp_check_filetype_and_ext` filter → allow AVIF. Check server support: `function_exists('imageavif')` or GD/Imagick AVIF support — warn if not available.
**Verification:** Upload `.avif` file → succeeds → displays in media library with correct thumbnail.

### Module 91: Terms Order
**Settings:** `{ enabled_for: ['category', 'post_tag'] }`
**Hooks:** Add `term_order` column support. Admin page per taxonomy showing drag-and-drop term list. AJAX save updates `term_order` in `wp_terms`. `get_terms` filter → order by `term_order` when on frontend. Hierarchical terms: drag within parent groups, reparent by dragging between groups.
**Pro Tier:** Frontend ordering and non-hierarchical taxonomy support are Pro.
**Verification:** Reorder categories via drag-and-drop → `wp_list_categories()` on frontend reflects new order.

### Module 92: Media Visibility Control
**Settings:** `{ enabled: true, restricted_roles: ['author', 'contributor', 'editor'] }`
**Hooks:** `ajax_query_attachments_args` filter → if user role is in `restricted_roles`, add `'author' => get_current_user_id()`. `pre_get_posts` on media list view → same filter. Admins always see everything.
**Verification:** Log in as Editor → Media Library shows only their uploads → log in as Admin → see everything.

### Module 93: External Links New Tab
**Settings:** `{ enabled: true, add_nofollow: true, exclude_domains: [] }`
**Hooks:** `the_content` filter priority 999 → parse HTML, find `<a>` tags with external `href` (different domain than `home_url()`), add `target="_blank"` and `rel="noopener noreferrer nofollow"`. Skip links to `exclude_domains`. Also filter `widget_text`, `comment_text`.
**Verification:** Post with external link → view source → has `target="_blank"` and proper `rel` attribute → internal links unchanged.

### Module 94: Custom Nav New Tab
**Settings:** `{ enabled: true }`
**Hooks:** `wp_nav_menu_objects` filter → for each menu item that is a custom link (not page/post), if URL is external, add `target="_blank"` and `rel` attributes to `$item->target` and `$item->xfn`.
**Verification:** Add custom link to menu → view frontend → link opens in new tab.

### Module 95: Two-Factor Authentication (2FA)
**Settings:** `{ enabled_for: ['administrator', 'editor'], methods: ['totp', 'email', 'recovery'], grace_period_days: 7 }`
**Implementation:** User profile section to set up 2FA. TOTP: generate secret via `random_bytes()`, display QR code using inline SVG (no external library), verify with `hash_equals(expected_otp, user_otp)` using time-based window. Recovery codes: generate 10, store hashed in user meta. Email: send 6-digit code via `wp_mail()`, verify on login. Grace period: after enabling for a role, users get N days to set up before it's enforced. Login flow: after password verified, show 2FA form. Store 2FA verified state in session token.
**Pro Tier:** Full module is Pro.
**Verification:** Enable for administrators → set up TOTP → log out → log in → prompted for code → correct code = access → wrong code = blocked → recovery code works once.

### Module 96: Hide Admin Bar
**Settings:** `{ hide_frontend: ['subscriber', 'contributor'], hide_backend: [], admin_always_visible: true }`
**Hooks:** `show_admin_bar` filter → return false if current user's role is in `hide_frontend` list. For backend hiding: `admin_head` CSS to hide `#wpadminbar` + adjust body padding. `admin_always_visible` ensures administrators always see the bar regardless.
**Verification:** Set to hide for subscribers on frontend → log in as subscriber → no admin bar on frontend → admin still sees it.

### Module 97: Wider Admin Menu
**Settings:** `{ width: 200 }` (default WordPress is 160px)
**Hooks:** `admin_head` → output `<style>#adminmenu, #adminmenu .wp-submenu { width: {$width}px; } #wpcontent, #wpfooter { margin-left: {$width}px; }</style>`. Adjust collapse breakpoint CSS too.
**Verification:** Set to 240px → admin sidebar is wider → all menu labels visible without truncation → collapsed menu still works.

### Module 98: Image Sizes Panel
**Settings:** `{ enabled: true, show_copy_button: true }`
**Hooks:** `attachment_fields_to_edit` filter → add a panel showing all registered image sizes for that attachment. For each size: dimensions, file size, URL, and "Copy URL" button (clipboard API). Get sizes via `wp_get_attachment_metadata()` and `wp_get_attachment_image_src()` for each registered size.
**Pro Tier:** Copy URL button is Pro.
**Verification:** View image in media library → panel shows all sizes (thumbnail, medium, large, full, custom) with dimensions and URLs → click copy → URL in clipboard.

### Module 99: Registration Date Column
**Settings:** `{ enabled: true, sortable: true }`
**Hooks:** `manage_users_columns` → add "Registered" column. `manage_users_custom_column` → output `$user->user_registered` formatted per site settings. `manage_users_sortable_columns` → make sortable. `pre_get_users` → if sorting by registration date, set `orderby => 'registered'`.
**Verification:** Users list shows "Registered" column → sortable by clicking header → dates display correctly.

### Module 100: Custom Frontend CSS
**Settings:** `{ css: '', enable_codemirror: true }`
**Hooks:** `wp_head` priority 999 → output `<style id="wpt-custom-frontend-css">` with saved CSS. Not loaded in admin. Settings page uses CodeMirror with CSS mode.
**Note:** This is separate from Custom Frontend Code (module 50) which handles scripts. This is pure CSS with a dedicated editor.
**Verification:** Add `body { border-top: 5px solid red; }` → frontend shows red border → admin area unaffected.

### Module 101: Redirect 404
**Settings:** `{ enabled: true, redirect_to: 'home', custom_url: '', status_code: 301 }`
**Hooks:** `template_redirect` → if `is_404()`, `wp_redirect(home_url(), 301)` or configured URL. Simple, one-hook module.
**Edge Cases:** Don't redirect admin 404s. Don't redirect REST API 404s. Don't create redirect loops (if custom URL itself 404s).
**Verification:** Visit `/nonexistent-page/` → redirects to homepage with 301 status.

---

## Batch 6B: ASE Parity Gaps — Part 2 + Admin Polish (12 modules)

| # | Module ID | One-Line | Tier |
|---|-----------|----------|------|
| 102 | search-visibility-status | Show admin bar warning + notice when search engines are discouraged | Free |
| 103 | active-plugins-first | Display active plugins at top of Installed Plugins list | Free |
| 104 | media-infinite-scroll | Re-enable infinite scrolling in media library grid view | Free |
| 105 | preserve-taxonomy-hierarchy | Preserve visual hierarchy of taxonomy term checklists in classic editor | Free |
| 106 | dashboard-columns | Enable manual dashboard columns layout (1-4 columns) in Screen Options | Free |
| 107 | admin-body-classes | Add user role slug and/or username to admin body classes for CSS targeting | Free |
| 108 | custom-admin-footer | Customize the admin footer text with rich editor | Free |
| 109 | admin-quick-notes | Sticky notes widget on dashboard for admin team reminders | Free |
| 110 | admin-bookmarks | Pin favorite admin pages to admin bar for quick access | Free |
| 111 | keyboard-shortcuts | Custom keyboard shortcuts for common WP admin actions beyond Cmd+K | Free |
| 112 | admin-color-schemes | Multiple admin color themes beyond just dark mode (Ocean, Forest, Sunset, Midnight, Custom) | Free |
| 113 | page-hierarchy-organizer | Collapsible tree view with drag-and-drop for Pages list — replaces flat indented list | Free |

### Module 102: Search Visibility Status
**Hooks:** `admin_bar_menu` → if `get_option('blog_public') == 0`, add red warning node. `admin_notices` → show dismissible warning banner. Optional: set "live site URL" to auto-disable search discouragement on production.
**Verification:** Check "Discourage search engines" in Reading settings → red indicator in admin bar → admin notice on all pages.

### Module 103: Active Plugins First
**Hooks:** `all_plugins` filter on `plugins.php` → sort array: active plugins first, then inactive. Maintain alphabetical order within each group. Add visual separator between active and inactive sections.
**Verification:** Plugins page shows all active plugins at top → deactivated plugins below → separator visible.

### Module 104: Media Infinite Scroll
**Hooks:** WordPress disabled infinite scroll in media grid around WP 6.4. Re-enable by: `admin_enqueue_scripts` on `upload.php` → enqueue JS that removes pagination and re-enables `wp.media.view.AttachmentsBrowser` infinite scroll behavior. Override `wp.media.view.AttachmentsBrowser.prototype.scroll` to trigger `this.collection.more()`.
**Verification:** Open Media Library grid view → scroll down → more images load automatically without pagination.

### Module 105: Preserve Taxonomy Hierarchy
**Hooks:** `wp_terms_checklist_args` filter → set `checked_ontop => false`. This prevents WordPress from pulling checked terms to the top of the checklist, which destroys the visual parent/child hierarchy.
**Verification:** Create nested categories (Parent > Child > Grandchild) → edit post, check Child → save → reopen → Child is still under Parent, not pulled to top.

### Module 106: Dashboard Columns
**Hooks:** `screen_layout_columns` filter for dashboard → allow 1-4 columns. Add custom Screen Options panel on dashboard with column selector. `admin_head` on dashboard → CSS grid override for selected column count.
**Verification:** Go to Dashboard → Screen Options → set to 3 columns → dashboard widgets arrange in 3 columns.

### Module 107: Admin Body Classes
**Settings:** `{ add_role: true, add_username: true }`
**Hooks:** `admin_body_class` filter → append `role-{role_slug}` and/or `user-{username}` to body classes. Useful for CSS targeting specific users/roles in Custom Admin CSS module.
**Verification:** Inspect `<body>` in admin → has `role-administrator user-jeff` classes → Custom Admin CSS can target `.role-editor #some-element { display: none; }`.

### Module 108: Custom Admin Footer
**Settings:** `{ left_text: 'Powered by Your Agency', right_text: '', use_editor: false }`
**Hooks:** `admin_footer_text` filter → return left text. `update_footer` filter → return right text (replaces WP version). If `use_editor`: settings page has TinyMCE for rich formatting.
**Pro Tier:** Rich editor with media upload is Pro.
**Verification:** Set left footer to "Built by Agency X" → admin footer shows custom text → WP version replaced.

### Module 109: Admin Quick Notes
**Settings:** Notes stored in `wp_options` as JSON array.
**Implementation:** Dashboard widget "Quick Notes" with add/edit/delete. Each note: text, color (yellow/blue/green/red/purple), created_by, created_at. Drag to reorder. Visible to all admins. Like sticky notes on a shared board.
**Verification:** Add note "Deploy Friday" with red color → appears on dashboard widget → other admins see it → delete removes it.

### Module 110: Admin Bookmarks
**Settings:** Bookmarks stored in user meta `wpt_bookmarks` as JSON array.
**Hooks:** `admin_bar_menu` → add "Bookmarks" node with submenu items for each saved bookmark. "Add Bookmark" button in admin bar that saves current page URL + title. AJAX add/remove/reorder. Per-user (each admin has their own bookmarks).
**Verification:** Navigate to a WooCommerce settings page → click "Bookmark This Page" in admin bar → bookmark appears in Bookmarks dropdown → click it → navigates there → remove works.

### Module 111: Keyboard Shortcuts
**Settings:** `{ shortcuts: { 'alt+n': 'edit.php?post_type=post', 'alt+p': 'edit.php?post_type=page', 'alt+m': 'upload.php', 'alt+s': 'options-general.php' }, custom_shortcuts: [] }`
**Hooks:** `admin_footer` → JS that listens for keyboard combinations and navigates. Settings page: visual shortcut editor with "Press keys" capture. Don't conflict with browser defaults or Gutenberg shortcuts. Show shortcut cheat sheet in Command Palette (when palette is open, press `?`).
**Verification:** Press Alt+N → navigates to Posts → press Alt+P → navigates to Pages → custom shortcut works.

### Module 112: Admin Color Schemes
**Settings:** `{ active_scheme: 'default', custom_scheme: { primary: '#1e1e2e', accent: '#89b4fa', ... } }`
**Implementation:** 6 built-in schemes: Default, Ocean (blue/teal), Forest (green/earth), Sunset (warm orange/red), Midnight (dark purple/indigo), Monochrome (grayscale). Each scheme: set of CSS custom properties applied via `admin_head`. User profile: scheme selector with live preview swatches. Custom scheme: color pickers for primary, accent, notification, text, sidebar.
**Note:** This extends Dark Mode (module 6) — Dark Mode becomes one scheme option rather than a standalone toggle.
**Verification:** Select "Ocean" → admin turns blue/teal → select "Sunset" → warm tones → custom scheme with picked colors works.

### Module 113: Page Hierarchy Organizer
**Settings:** `{ enabled: true, show_template: true, show_status: true }`
**Implementation:** Replace the default Pages list table with a collapsible tree view. Each page shows: title, template (if set), status, date. Click arrow to expand/collapse children. Drag-and-drop to reorder within parent or reparent (drop on another page to make it a child). Keyboard accessible: arrow keys to navigate, Enter to expand/collapse. "Flat view" toggle to switch back to default list. Works via `menu_order` updates and `post_parent` changes on drag.
**Edge Cases:** Sites with 1000+ pages → virtual scrolling or paginated tree. Draft pages → show with dim styling. Trashed pages → exclude.
**Verification:** Pages with parent/child relationships display as collapsible tree → drag Child under new Parent → save → page hierarchy updated → frontend menus reflect change.

---

## Batch 6C: Unique Differentiators (12 modules)

| # | Module ID | One-Line | Tier |
|---|-----------|----------|------|
| 114 | activity-feed | Real-time dashboard widget showing site activity (edits, publishes, logins, plugin changes) | Free |
| 115 | 404-monitor | Log and analyze 404 errors with referrer, user agent, and frequency data | Free |
| 116 | notification-center | Unified notification panel in admin bar replacing scattered admin notices | Free |
| 117 | content-calendar | Visual calendar showing scheduled, published, and draft posts | Free |
| 118 | environment-indicator | Colored banner/badge showing dev/staging/production environment | Free |
| 119 | temporary-user-access | Generate time-limited admin access URLs for support or collaboration | Pro |
| 120 | client-dashboard | Custom simplified dashboard for non-admin users with only relevant widgets | Pro |
| 121 | plugin-profiler | Measure and display each plugin's impact on page load time | Pro |
| 122 | webhook-manager | Send data to external URLs on WordPress events (post publish, user register, etc.) | Pro |
| 123 | error-log-viewer | View PHP error log from admin without FTP access | Free |
| 124 | passkey-auth | WebAuthn/passkey support for passwordless login | Pro |
| 125 | workflow-automation | Simple if-this-then-that rules for WordPress actions | Pro |

### Module 114: Activity Feed
**Implementation:** Dashboard widget showing chronological feed of site activity. Custom DB table `wpt_activity_feed` (id, user_id, action, object_type, object_id, object_title, details, created_at). Hooks into same events as Audit Log module but displays as a live feed widget with avatars, action icons, relative timestamps. If Audit Log is active, read from its table instead of maintaining a separate one. Auto-refresh every 60 seconds via AJAX. Filter by: all, content, users, plugins, settings. Show last 20 items with "Load more" button.
**Verification:** Edit a post → activity feed shows "[User] edited [Post Title] 2 minutes ago" → activate a plugin → shows in feed.

### Module 115: 404 Monitor
**Implementation:** Custom DB table `wpt_404_log` (id, url, referrer, user_agent, ip_hash, count, first_seen, last_seen). `template_redirect` → if `is_404()`, log the URL. Group by URL, show hit count. Dashboard widget with top 10 most-hit 404s. Admin page: full table with search, filter by date range, referrer domain. "Create Redirect" button integrates with Redirect Manager. "Dismiss" to hide known-ok 404s. Cron to prune entries older than configured days. Don't log bot 404s for common probe paths (`/wp-login.php`, `/xmlrpc.php`, etc.).
**Distinct from Broken Link Checker:** BLC scans YOUR content for broken outgoing links. 404 Monitor logs incoming requests that hit 404 on YOUR site. Complementary, not overlapping.
**Verification:** Visit `/fake-url/` → 404 Monitor logs it → admin page shows the URL with referrer → "Create Redirect" pre-fills Redirect Manager form.

### Module 116: Notification Center
**Settings:** `{ collect_notices: true, show_badge_count: true, dismiss_clears: true }`
**Implementation:** Replace scattered admin notices with a unified notification panel. `admin_notices` hook → capture all notices into a buffer, store in transient. Admin bar node "Notifications (3)" with dropdown panel. Click to expand full list. Notices categorized: errors (red), warnings (yellow), info (blue), success (green). "Dismiss All" button. Persisted across page loads until dismissed. Plugin update notifications, WooCommerce alerts, security warnings — all in one place instead of cluttering every page.
**Verification:** Plugin needs update → notification badge shows "1" → click → panel shows the update notice → dismiss → badge clears.

### Module 117: Content Calendar
**Settings:** `{ post_types: ['post', 'page'], show_drafts: true, show_status_colors: true }`
**Implementation:** Admin page: monthly calendar grid. Each day cell shows posts scheduled/published on that date. Color-coded by status: published (green), scheduled (blue), draft (gray), pending (orange). Click a date to create new post scheduled for that date. Drag posts between dates to reschedule (`wp_update_post` with new `post_date`). Filter by post type and category. Month/week toggle view.
**Verification:** View calendar → see published posts on their dates → drag a scheduled post to next week → post date updated → create new post by clicking empty date.

### Module 118: Environment Indicator
**Settings:** `{ environment: 'production', label: 'Production', color: '#dc3545', show_in_admin_bar: true, show_banner: false, auto_detect: true }`
**Implementation:** Auto-detect environment from `wp_get_environment_type()` (WP 5.5+) or URL patterns (contains 'staging', 'dev', 'local'). Display colored indicator in admin bar. Optional: full-width colored banner at top of admin. Colors: production=red, staging=yellow, development=green, local=blue. Prevent accidental work on production by making it visually obvious which environment you're on.
**Verification:** On local dev → green "Development" indicator → on staging → yellow "Staging" → on production → red "Production" in admin bar.

### Module 119: Temporary User Access
**Settings:** `{ default_role: 'administrator', default_duration: 24, max_duration: 168 }`
**Implementation:** Admin page: "Generate Access Link" button. Creates a temporary user account with: random username, specified role, expiration timestamp. Generates a unique login URL with token: `site.com/?wpt_temp_access={token}`. Visiting the URL logs the user in directly. After expiration: account is auto-deleted on next cron run. Dashboard table shows all active temporary access links with remaining time, role, and "Revoke" button. Email option: send the access link directly.
**Pro Tier:** Full module is Pro.
**Verification:** Generate 24-hour admin access link → open in incognito → logged in as temporary admin → 24 hours later → link dead, account deleted.

### Module 120: Client Dashboard
**Settings:** `{ enabled_for: ['editor', 'author', 'subscriber'], widgets: ['welcome_message', 'quick_links', 'recent_content'], welcome_message: 'Welcome to your site!', quick_links: [] }`
**Implementation:** Replace the default WordPress dashboard for non-admin roles with a simplified, customized version. Admin configures: welcome message, quick link buttons (Add Post, View Site, Get Help), which widgets to show. Hides all default dashboard widgets, WP news, site health. Clean, focused interface that doesn't overwhelm clients.
**Pro Tier:** Full module is Pro.
**Verification:** Log in as Editor → see clean client dashboard with welcome message and quick links → no WordPress news/events/site health → switch to admin → normal dashboard.

### Module 121: Plugin Profiler
**Settings:** `{ enabled: true, sample_pages: ['home', 'single_post', 'archive'] }`
**Implementation:** Measures each active plugin's impact on page load. Uses `timer_start()`/`timer_stop()` around plugin load, or hooks `plugin_loaded` action to measure time per plugin. Admin page: table of plugins sorted by load time impact. Shows: plugin name, avg load time (ms), memory usage (MB), hooks registered, DB queries added. "Benchmark Now" button runs a fresh measurement. Results cached for 24 hours. Highlight plugins adding >100ms or >5MB.
**Pro Tier:** Full module is Pro.
**Verification:** Run profiler → see table of plugins with load times → identify slowest plugin → deactivate it → re-run → load time improved.

### Module 122: Webhook Manager
**Settings:** Webhooks stored in custom DB table.
**Implementation:** Custom table `wpt_webhooks` (id, name, url, event, headers JSON, active, last_triggered, last_status). Admin page: CRUD for webhooks. Events: `post_published`, `post_updated`, `user_registered`, `comment_posted`, `plugin_activated`, `woocommerce_order_completed`, custom. On event: `wp_remote_post()` to configured URL with JSON payload containing event data. Retry once on failure. Log last 50 deliveries with status code and response body. Headers field for auth tokens.
**Pro Tier:** Full module is Pro.
**Verification:** Create webhook for `post_published` → URL = webhook.site → publish post → webhook.site shows received payload with post data.

### Module 123: Error Log Viewer
**Settings:** `{ enabled: true, log_path: '' }` (auto-detect `WP_DEBUG_LOG` path)
**Implementation:** Admin page showing PHP error log (`debug.log`). Auto-detect path from `WP_DEBUG_LOG` constant or default `wp-content/debug.log`. Display last 500 lines, most recent first. Search/filter by error type (Fatal, Warning, Notice, Deprecated). "Clear Log" button (truncates file). "Download" button. Auto-refresh toggle. Highlight fatal errors in red.
**Edge Cases:** Log file doesn't exist → show how to enable `WP_DEBUG_LOG`. Log file >10MB → show only last 500 lines, offer download for full file. File permissions → read-only, never write to error log from this module.
**Verification:** Enable WP_DEBUG_LOG → trigger a PHP notice → Error Log Viewer shows the entry → search finds it → clear log works.

### Module 124: Passkey Authentication
**Settings:** `{ enabled: true, allow_password_fallback: true, enabled_for: ['administrator'] }`
**Implementation:** WebAuthn API integration. User profile: "Register Passkey" button → browser prompts for biometric/security key → stores credential in user meta `wpt_passkey_credentials` (JSON array of credential IDs + public keys). Login page: "Sign in with Passkey" button alongside traditional form. JS calls `navigator.credentials.get()`, sends assertion to server, server verifies against stored public key. `allow_password_fallback` keeps traditional login as option.
**Pro Tier:** Full module is Pro.
**Edge Cases:** Browser support check — only show passkey option if `window.PublicKeyCredential` exists. Multiple passkeys per user (laptop + phone). Revoke passkeys from profile page.
**Verification:** Register passkey on Chrome → log out → click "Sign in with Passkey" → biometric prompt → logged in without password.

### Module 125: Workflow Automation
**Settings:** Rules stored in custom DB table.
**Implementation:** Custom table `wpt_automation_rules` (id, name, trigger, conditions JSON, actions JSON, active, last_run). Admin page: visual rule builder. Trigger: WordPress hook (post_published, user_registered, comment_posted, wc_order_completed, etc.). Conditions: field checks (post_type == 'post', user_role == 'subscriber'). Actions: send email, send webhook, set post meta, assign category, change status, run PHP snippet, clear cache. "When [trigger] and [conditions] then [actions]" format. Max 3 actions per rule. Log last 50 executions.
**Pro Tier:** Full module is Pro.
**Example rules:**
- When post published AND post_type is 'post' → clear all caches + send webhook to Zapier
- When user registered AND role is 'subscriber' → send welcome email + assign 'new-user' tag
- When WooCommerce order completed AND total > $100 → send notification to admin
**Verification:** Create rule: when post published → send email to admin → publish post → email received.

---

## Build Notes

### Run as 3 `/batch` sessions:
```
/batch build modules 90-101 from docs/module-specs-wave6.md (Batch 6A — ASE parity)
/batch build modules 102-113 from docs/module-specs-wave6.md (Batch 6B — admin polish)
/batch build modules 114-125 from docs/module-specs-wave6.md (Batch 6C — differentiators)
```

### Module Interactions
- Activity Feed (114) reads from Audit Log (20) table if active
- 404 Monitor (115) integrates with Redirect Manager (14) for quick-fix redirects
- Notification Center (116) captures output from Hide Admin Notices (3)
- Admin Color Schemes (112) extends/replaces Dark Mode (6)
- Keyboard Shortcuts (111) integrates with Command Palette (26)
- Client Dashboard (120) respects Hide Dashboard Widgets (58) settings
- Error Log Viewer (123) pairs with System Summary (78)
- Workflow Automation (125) can trigger Webhook Manager (122) actions

### New Pro Modules in This Wave (7):
91, 95, 119, 120, 121, 122, 124, 125
Total Pro modules across all waves: ~15

### Final Count After Wave 6:
- v1: 10 modules
- v2: 14 modules (dropped Minify)
- Wave 3: 5 modules (differentiators)
- Wave 4: 30 modules (ASE parity)
- Wave 5: 29 modules (beyond ASE)
- Wave 6: 36 modules (125 sprint)
- **Total: 124 modules** (one short — the mystery feature Jeff can't remember gets slot 125 or we count Export/Import Settings as the 125th)

### After All 3 Batches:
Run `/security-review` on entire codebase.
Run cross-model review on all 36 new modules.
Fix criticals. Push to origin main.
Then: styling phase.
