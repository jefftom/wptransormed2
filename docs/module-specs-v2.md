# WPTransformed v2 — Module Specifications

> 15 expansion modules. Each follows the same Module_Base pattern from v1.
> Build with `/batch` — one parallel agent per module in isolated git worktrees.
> All modules are Free tier unless marked (Pro).

---

## Module List (15 total)

| # | Module | Category | Replaces | Tier |
|---|--------|----------|----------|------|
| 11 | Custom Content Types | Content Management | Custom Post Type UI (1M) | Free |
| 12 | Media Folders | Content Management | FileBird (400K) | Free |
| 13 | User Role Editor | Security & Access | User Role Editor (800K) | Free |
| 14 | Redirect Manager | Utilities | Redirection (2M) | Free |
| 15 | Code Snippets | Custom Code | WPCode (2M) | Free |
| 16 | Cookie Consent | Compliance | CookieYes (1.5M) | Free |
| 17 | Login Customizer | Login/Logout | LoginPress (100K) | Free |
| 18 | Limit Login Attempts | Security & Access | Limit Login Attempts (2.5M) | Free |
| 19 | Session Manager | Security & Access | — (unique) | Free |
| 20 | Audit Log | Security & Access | — (usually Pro-only) | Free |
| 21 | Lazy Load | Performance | — (common utility) | Free |
| 22 | Image Upload Control | Performance | Smush (basic) | Free |
| 23 | Minify Assets | Performance | Autoptimize (partial) | Free |
| 24 | Cron Manager | Utilities | WP Crontrol (200K) | Free |
| 25 | Maintenance Mode | Utilities | — (common utility) | Free |

---

## Module 11: Custom Content Types

**ID:** `custom-content-types`
**Category:** `content-management`
**One-line:** Register custom post types and taxonomies through the admin UI — no code required.

### What the User Sees

- New admin page: WP Transformation → Content Types
- Two tabs: "Post Types" and "Taxonomies"
- Each tab has a list of registered custom types with Edit / Delete actions
- "Add New Post Type" button opens a form with fields for slug, labels, capabilities, supports, visibility, REST API, icon
- "Add New Taxonomy" button opens a form for slug, labels, associated post types, hierarchy, REST API
- Registered CPTs appear in the admin menu immediately after save
- Registered taxonomies appear on their associated post types

### Settings

```
custom-content-types: {
    post_types: [
        {
            slug: 'portfolio',
            singular: 'Portfolio',
            plural: 'Portfolios',
            public: true,
            has_archive: true,
            show_in_rest: true,
            menu_icon: 'dashicons-portfolio',
            supports: ['title', 'editor', 'thumbnail', 'excerpt'],
            rewrite: { slug: 'portfolio' }
        }
    ],
    taxonomies: [
        {
            slug: 'project-type',
            singular: 'Project Type',
            plural: 'Project Types',
            post_types: ['portfolio'],
            hierarchical: true,
            show_in_rest: true,
            rewrite: { slug: 'project-type' }
        }
    ]
}
```

### Hooks & Implementation

- `init` priority 5 — register_post_type() and register_taxonomy() from saved settings
- Settings page uses AJAX to add/edit/delete entries without full page reload
- Flush rewrite rules on save (transient flag checked on `init`, flushed once)
- Generate all labels automatically from singular/plural (with override option)
- CPT icons: dashicons picker in the form

### Edge Cases

- Slug conflicts with existing WP post types (post, page, attachment) — validate and reject
- Slug conflicts with existing plugins — warn but allow
- Taxonomy associated with non-existent post type — skip silently on registration
- Flush rewrite rules ONCE after save, not on every page load

### Verification

1. Activate module → "Content Types" submenu appears under WP Transformation
2. Add a CPT "portfolio" with title, editor, thumbnail support
3. "Portfolios" menu item appears in admin sidebar
4. Create a Portfolio post — title, editor, featured image all work
5. Add taxonomy "Project Type" associated with Portfolio, hierarchical
6. Edit a Portfolio post — Project Type metabox appears in sidebar
7. Deactivate module → CPTs and taxonomies stop registering (data preserved in DB)
8. Reactivate → everything comes back

---

## Module 12: Media Folders

**ID:** `media-folders`
**Category:** `content-management`
**One-line:** Organize media library into virtual folders with drag-and-drop.

### What the User Sees

- Media Library (both grid and list view) gets a folder sidebar on the left
- "New Folder" button at the top of the sidebar
- Drag media items onto folders to organize
- Click a folder to filter the library to only that folder's items
- "All Media" shows everything
- "Uncategorized" shows items not in any folder
- Folders can be nested (parent/child)
- Right-click folder: Rename, Delete, Move

### Settings

```
media-folders: {
    enabled: true
}
```
Minimal settings — folders stored as a custom taxonomy `wpt_media_folder`.

### Hooks & Implementation

- Register hidden taxonomy `wpt_media_folder` on `attachment` post type (`show_ui: false`, `show_in_rest: true`)
- `restrict_manage_posts` — add folder dropdown filter on media list view
- `ajax_query_attachments_args` — filter media grid view by selected folder term
- Enqueue custom JS on `upload.php` and media modal — renders folder sidebar, handles drag-and-drop
- Drag-and-drop: AJAX handler `wp_ajax_wpt_assign_folder` — updates attachment's `wpt_media_folder` term
- Folder CRUD: AJAX handlers for create/rename/delete/move using `wp_insert_term`, `wp_update_term`, `wp_delete_term`
- Folder tree: `get_terms()` with `parent` hierarchy support

### Edge Cases

- Deleting a folder does NOT delete media — items become "Uncategorized"
- Moving a parent folder moves all children
- Media uploaded from post editor — defaults to "Uncategorized" unless a folder context is set
- Grid view vs. List view have different hooks — must support both
- Third-party plugins that modify the media query (ACF, Elementor) — use late priority (90+)

### Verification

1. Activate module → folder sidebar appears in Media Library
2. Create folder "Photos" → appears in sidebar
3. Drag 3 images into "Photos" → clicking "Photos" shows only those 3
4. "All Media" still shows everything
5. Create subfolder "Photos > Headshots" → nest works
6. Delete "Headshots" → images return to "Uncategorized", not deleted
7. Works in both Grid and List view
8. Works in the media modal when inserting into a post

---

## Module 13: User Role Editor

**ID:** `user-role-editor`
**Category:** `security`
**One-line:** Edit WordPress user role capabilities through a visual interface.

### What the User Sees

- New admin page: Users → Role Editor
- List of all roles (Administrator, Editor, Author, Contributor, Subscriber, plus any custom)
- Click a role → expandable capability list grouped by category (Posts, Pages, Users, Plugins, Themes, etc.)
- Checkboxes to grant/revoke each capability
- "Add New Role" button — name + clone from existing role
- "Delete Role" — only custom roles, not WordPress defaults
- "Reset Role" — restore to WordPress defaults

### Settings

Capabilities stored directly in WordPress options (`wp_user_roles`) via `add_cap()` / `remove_cap()`. Module settings minimal:

```
user-role-editor: {
    show_admin_bar_switch: true    // Show "View as Role" in admin bar
}
```

### Hooks & Implementation

- Reads `wp_roles()->roles` for current state
- `add_cap()` / `remove_cap()` to modify — writes directly to `wp_user_roles` option
- Group capabilities by prefix: `edit_posts`, `publish_posts`, `delete_posts` → "Posts" group
- Custom capabilities (from plugins like WooCommerce, ACF) → "Other" group
- "View as Role" — temporary capability switch using `user_has_cap` filter + session transient
- Admin page uses native WordPress tables, not React

### Edge Cases

- NEVER allow removing `manage_options` from the last Administrator
- Custom capabilities from plugins should display but warn before modification
- Multisite: `super_admin` capabilities are separate — don't show them for non-multisite
- "View as Role" must have a visible "Switch Back" button and auto-expire after 1 hour

### Verification

1. Activate module → "Role Editor" appears under Users menu
2. Click "Editor" role → see all capabilities with checkboxes
3. Uncheck `delete_others_posts` → save → log in as Editor → cannot delete others' posts
4. Re-check it → Editor can delete again
5. Add new role "Content Manager" cloned from Editor
6. "Content Manager" appears in role list and user edit dropdown
7. Delete "Content Manager" → works. Try deleting "Editor" → blocked
8. "View as Role" → admin bar shows role switch, capability restrictions apply

---

## Module 14: Redirect Manager

**ID:** `redirect-manager`
**Category:** `utilities`
**One-line:** Create and manage URL redirects (301, 302, 307) with a simple interface.

### What the User Sees

- Admin page: WP Transformation → Redirects
- Table of all redirects: Source URL | Target URL | Type (301/302/307) | Hits | Last Hit | Status (Active/Disabled)
- "Add Redirect" form: source URL, target URL, redirect type dropdown
- Import/Export CSV
- 404 log: separate tab showing recent 404s with "Create Redirect" quick action

### Settings

Redirects stored in custom DB table `{$wpdb->prefix}wpt_redirects`:

```sql
CREATE TABLE {$wpdb->prefix}wpt_redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_url VARCHAR(2048) NOT NULL,
    target_url VARCHAR(2048) NOT NULL,
    redirect_type SMALLINT DEFAULT 301,
    hit_count BIGINT DEFAULT 0,
    last_hit DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source (source_url(191)),
    INDEX idx_active (is_active)
);
```

Module settings:
```
redirect-manager: {
    log_404s: true,
    max_404_log: 1000,
    auto_redirect_slugs: false     // Auto-redirect when post slug changes
}
```

### Hooks & Implementation

- `template_redirect` priority 1 — check request URI against redirect table, execute wp_redirect()
- Query: `$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_url = %s AND is_active = 1", $request_uri))`
- Cache active redirects in transient (invalidate on save/delete)
- 404 logging: `404_template` filter — log URI, referrer, user agent to `wpt_404_log` table
- Prune 404 log to `max_404_log` entries on daily cron
- Create table on activation: `dbDelta()` in `activate_module()`
- Drop table on cleanup: declared in `get_cleanup_tasks()`
- CSV import: parse, validate URLs, bulk insert with `$wpdb->insert()`
- CSV export: query all redirects, output as download

### Edge Cases

- Source URL must start with `/` (relative) — reject absolute URLs to other domains
- Duplicate source URLs — reject, show error
- Redirect loops (A→B, B→A) — detect and warn
- Source URL is an existing WordPress page — warn but allow
- Performance: cache all active redirects, don't query DB on every page load
- Batch import: process in chunks of 50 to avoid timeout

### Verification

1. Activate module → "Redirects" submenu appears
2. Add redirect: `/old-page` → `/new-page` (301)
3. Visit `/old-page` → browser redirects to `/new-page` with 301 status
4. Hit counter increments
5. Disable the redirect → `/old-page` returns 404
6. 404 tab shows the logged 404
7. Click "Create Redirect" on a 404 entry → pre-fills the form
8. Import CSV with 10 redirects → all appear in table
9. Export CSV → file downloads with all redirects

---

## Module 15: Code Snippets

**ID:** `code-snippets`
**Category:** `custom-code`
**One-line:** Add custom PHP, CSS, JS, and HTML snippets without editing theme files.

### What the User Sees

- Admin page: WP Transformation → Code Snippets
- Table: Snippet Name | Type (PHP/CSS/JS/HTML) | Scope (Everywhere/Admin/Frontend) | Status (Active/Inactive)
- "Add New" form: title, code editor (with syntax highlighting via CodeMirror), type selector, scope, priority, description, conditional logic (which pages/post types)
- Toggle on/off without deleting
- Error protection: if a PHP snippet causes a fatal error, auto-deactivate it

### Settings

Snippets stored in custom DB table `{$wpdb->prefix}wpt_snippets`:

```sql
CREATE TABLE {$wpdb->prefix}wpt_snippets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    code LONGTEXT NOT NULL,
    type ENUM('php', 'css', 'js', 'html') DEFAULT 'php',
    scope ENUM('everywhere', 'admin', 'frontend') DEFAULT 'everywhere',
    priority INT DEFAULT 10,
    is_active TINYINT(1) DEFAULT 0,
    description TEXT,
    conditional JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

Module settings:
```
code-snippets: {
    enable_php: true,
    enable_error_recovery: true,
    codemirror_theme: 'default'
}
```

### Hooks & Implementation

- PHP snippets: execute via `eval()` on `init` (priority from snippet) — wrapped in try/catch with error recovery
- CSS snippets: output via `wp_head` (frontend) or `admin_head` (admin) in `<style>` tags
- JS snippets: output via `wp_footer` (frontend) or `admin_footer` (admin) in `<script>` tags
- HTML snippets: output via `wp_head`, `wp_body_open`, or `wp_footer` based on position setting
- Error recovery for PHP: register shutdown function, detect fatal error, auto-deactivate the offending snippet, show admin notice
- CodeMirror: enqueue `wp-codemirror` (bundled with WordPress) for the code editor
- Conditional logic: `{ post_types: ['post'], pages: [5, 12] }` — check on execution

### Edge Cases

- PHP `eval()` is dangerous — add capability check (`manage_options` only for PHP snippets)
- Fatal error in PHP snippet: shutdown handler catches it, marks snippet inactive, sets transient with error message
- Snippet with syntax error: validate PHP syntax before saving using `php -l` or token_get_all()
- Large number of snippets: cache active snippets in transient, invalidate on save
- Import/Export: JSON format for sharing snippets between sites

### Verification

1. Activate module → "Code Snippets" submenu appears
2. Add PHP snippet: `add_filter('admin_footer_text', function() { return 'Custom Footer'; });`
3. Set scope: Admin, activate → admin footer changes
4. Add CSS snippet: `body { border-top: 3px solid red; }` scope: Frontend
5. Visit frontend → red border visible
6. Add JS snippet: `console.log('WPT Snippet Active');` scope: Everywhere
7. Check console on frontend and admin → log appears
8. Add broken PHP: `this is not valid php` → auto-deactivates, admin notice shown
9. Deactivate module → all snippets stop executing, no errors

---

## Module 16: Cookie Consent

**ID:** `cookie-consent`
**Category:** `compliance`
**One-line:** GDPR/CCPA cookie consent banner with customizable categories.

### What the User Sees

- Cookie banner appears on frontend for first-time visitors
- Banner shows: message, Accept All, Reject All, Customize buttons
- "Customize" opens modal with cookie categories: Necessary (always on), Analytics, Marketing, Preferences
- User choice saved in cookie `wpt_consent` for 365 days
- No scripts in Analytics/Marketing categories fire until consent given

### Settings

```
cookie-consent: {
    banner_position: 'bottom',           // bottom, top, bottom-left, bottom-right
    banner_style: 'bar',                 // bar, modal, floating
    message: 'We use cookies to improve your experience.',
    accept_text: 'Accept All',
    reject_text: 'Reject All',
    customize_text: 'Customize',
    privacy_url: '/privacy-policy',
    categories: {
        necessary: { label: 'Necessary', description: 'Required for the site to function.', required: true },
        analytics: { label: 'Analytics', description: 'Help us understand how you use the site.', required: false },
        marketing: { label: 'Marketing', description: 'Used for targeted advertising.', required: false },
        preferences: { label: 'Preferences', description: 'Remember your settings.', required: false }
    },
    auto_block_scripts: true,
    consent_duration: 365
}
```

### Hooks & Implementation

- Frontend only: `wp_footer` — output banner HTML + CSS + JS
- Consent state stored in cookie: `wpt_consent={"analytics":true,"marketing":false,"preferences":true}`
- Script blocking: `script_loader_tag` filter — add `type="text/plain"` and `data-consent="analytics"` to tagged scripts
- After consent: JS swaps `type` back to `text/javascript` and re-executes
- Settings page: tag known scripts to consent categories (Google Analytics → analytics, Facebook Pixel → marketing)
- Provide `wpt_consent_given` JS event for custom integrations
- CSS: self-contained, scoped to `.wpt-cookie-banner`, no external dependencies

### Edge Cases

- Consent already given → no banner shown (check cookie first)
- Bot/crawler → no banner (check user agent)
- Caching plugins: banner must work with page cache (JS-driven, not PHP-rendered decision)
- AMP pages → simplified consent or skip
- "Reject All" must actually prevent scripts from loading, not just set a cookie

### Verification

1. Activate module → cookie banner appears on frontend (incognito)
2. Click "Accept All" → banner disappears, `wpt_consent` cookie set with all true
3. Clear cookies → banner reappears
4. Click "Reject All" → only necessary scripts load
5. Click "Customize" → category modal appears
6. Enable Analytics only → Google Analytics fires, Facebook Pixel doesn't
7. Return visit → banner doesn't show, previous consent remembered
8. Settings page: change position to top → banner moves
9. Logged-in admin → banner still shows (admins are visitors too for consent)

---

## Module 17: Login Customizer

**ID:** `login-customizer`
**Category:** `login-logout`
**One-line:** Customize the WordPress login page appearance — logo, colors, background.

### What the User Sees

- Settings panel with live preview
- Customizable: logo image, logo URL, background color/image, form background color, button colors, text colors, custom CSS
- Login page reflects all customizations
- "Powered by WordPress" link optionally hidden

### Settings

```
login-customizer: {
    logo_url: '',                    // Media picker — custom logo image
    logo_link: '',                   // Where logo links to (default: site home)
    logo_width: 320,
    logo_height: 84,
    bg_color: '#f1f1f1',
    bg_image: '',
    form_bg_color: '#ffffff',
    form_border_radius: 4,
    button_bg_color: '#2271b1',
    button_text_color: '#ffffff',
    text_color: '#3c434a',
    link_color: '#2271b1',
    hide_back_to_blog: false,
    hide_privacy_policy: false,
    custom_css: ''
}
```

### Hooks & Implementation

- `login_enqueue_scripts` — enqueue custom CSS generated from settings
- `login_headerurl` — change logo link
- `login_headertext` — change logo alt text
- `login_head` — output inline CSS with all customizations
- CSS targets: `body.login`, `.login h1 a`, `#loginform`, `.wp-core-ui .button-primary`, `#nav a`, `#backtoblog`
- Logo: use `background-image` on `.login h1 a` with custom dimensions
- No JavaScript required — pure CSS customization
- Media uploader integration for logo and background image selection

### Edge Cases

- Large logo images — enforce max-width in CSS
- Background image — add `background-size: cover; background-position: center;`
- Dark backgrounds need light text — auto-suggest based on bg_color contrast
- Custom CSS — sanitize with `wp_strip_all_tags()` (no script injection)
- Multisite: settings apply per-site, not network-wide

### Verification

1. Activate module → login customizer settings appear
2. Upload custom logo → login page shows new logo
3. Change background to dark blue → login page background changes
4. Change button color to red → login button is red
5. Hide "Back to blog" link → gone from login page
6. Add custom CSS `#loginform { box-shadow: none; }` → shadow removed
7. Deactivate module → login page returns to WordPress default

---

## Module 18: Limit Login Attempts

**ID:** `limit-login-attempts`
**Category:** `security`
**One-line:** Block brute force attacks by limiting failed login attempts per IP.

### What the User Sees

- After X failed logins from the same IP, lock them out for Y minutes
- After Z lockouts, increase lockout duration
- Settings page shows recent lockout log
- Optional email notification on lockout

### Settings

```
limit-login-attempts: {
    max_attempts: 5,
    lockout_duration: 20,           // minutes
    max_lockouts: 3,
    extended_lockout: 1440,         // minutes (24 hours) after max_lockouts
    whitelist_ips: [],
    blacklist_ips: [],
    notify_email: '',               // blank = don't notify
    log_retention: 30               // days to keep log
}
```

### Hooks & Implementation

- Custom table `{$wpdb->prefix}wpt_login_attempts`:
```sql
CREATE TABLE {$wpdb->prefix}wpt_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(60),
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    lockout_until DATETIME NULL,
    lockout_count INT DEFAULT 0,
    INDEX idx_ip (ip_address),
    INDEX idx_lockout (lockout_until)
);
```
- `authenticate` filter priority 99 — check if IP is locked out before authentication
- `wp_login_failed` action — increment attempt count for IP
- On lockout: return `WP_Error` with message "Too many failed attempts. Try again in X minutes."
- IP detection: `$_SERVER['REMOTE_ADDR']` with proxy header checks (`HTTP_X_FORWARDED_FOR`, `HTTP_CF_CONNECTING_IP` for Cloudflare)
- Whitelist: skip lockout check for whitelisted IPs
- Blacklist: always block, don't even attempt auth
- Cron: daily cleanup of records older than `log_retention` days
- Email notification: `wp_mail()` on lockout with IP, username attempted, lockout duration

### Edge Cases

- Shared hosting / NAT: many users behind same IP — whitelist option important
- Cloudflare / proxy: detect real IP from headers
- XML-RPC brute force — also hook `xmlrpc_call` to count attempts
- REST API auth — hook `rest_authentication_errors`
- Whitelist must ALWAYS include current admin IP — warn if removing
- Don't store passwords in log — only username and IP

### Verification

1. Activate module → settings page shows attempt/lockout configuration
2. Attempt 5 wrong logins → locked out for 20 minutes
3. Lockout message displays with countdown
4. Wait (or manually clear) → can attempt again
5. Get locked out 3 times → extended 24-hour lockout
6. Add IP to whitelist → no lockout regardless of failures
7. Add IP to blacklist → immediately blocked, no login attempt processed
8. Notification email arrives on lockout (if configured)
9. Log shows all attempts with IP, username, timestamp

---

## Module 19: Session Manager

**ID:** `session-manager`
**Category:** `security`
**One-line:** View and manage all active WordPress user sessions.

### What the User Sees

- Admin page: Users → Sessions
- Table of all active sessions: User | Role | IP | Browser | Last Active | Location (approx) | Actions (Destroy)
- "Destroy All Other Sessions" button (keeps current session)
- Per-user session limit setting
- Idle timeout setting

### Settings

```
session-manager: {
    max_sessions_per_user: 3,
    idle_timeout: 480,               // minutes (8 hours)
    show_in_profile: true,           // Show active sessions on user profile page
    notify_on_new_login: false
}
```

### Hooks & Implementation

- Reads `WP_Session_Tokens` (WordPress native session system, stored in user meta `session_tokens`)
- Parse each session token: IP, user agent, login timestamp, expiration
- `auth_cookie_valid` action — track last activity per session
- Idle timeout: on `init`, check last activity against timeout, destroy if expired
- Session limit: on `wp_login`, count existing sessions, destroy oldest if over limit
- "Destroy" button: `WP_Session_Tokens::get_instance($user_id)->destroy($token_hash)`
- User agent parsing: basic detection (Chrome, Firefox, Safari, Edge, Mobile)
- Admin page: WP_List_Table subclass with sortable columns

### Edge Cases

- Current session must NEVER be destroyable (filter it out of the list, or warn)
- Admins can manage all sessions; non-admins can only see their own
- Session token hashes — display truncated, not full hash
- `max_sessions_per_user = 0` means unlimited
- Don't interfere with WooCommerce customer sessions or REST API auth tokens

### Verification

1. Activate module → "Sessions" appears under Users menu
2. Log in from two browsers → both sessions listed
3. "Destroy" one session → that browser gets logged out
4. Set max sessions to 1 → log in from new browser → oldest session destroyed
5. Set idle timeout to 1 minute → wait → session expires
6. "Destroy All Other Sessions" → only current session survives
7. Non-admin user profile → shows their sessions only (if show_in_profile enabled)

---

## Module 20: Audit Log

**ID:** `audit-log`
**Category:** `security`
**One-line:** Track user actions — post edits, plugin activations, settings changes, logins.

### What the User Sees

- Admin page: WP Transformation → Audit Log
- Table: Timestamp | User | Action | Object | Details | IP
- Filterable by user, action type, date range
- Tracks: post create/edit/delete/trash/restore, plugin activate/deactivate/install, theme switch, user create/edit/delete, login/logout, settings changes, media upload/delete, menu edits

### Settings

```
audit-log: {
    enabled_events: ['posts', 'plugins', 'themes', 'users', 'logins', 'settings', 'media', 'menus'],
    retention_days: 90,
    log_admins: true,
    exclude_users: []
}
```

### Hooks & Implementation

- Custom table `{$wpdb->prefix}wpt_audit_log`:
```sql
CREATE TABLE {$wpdb->prefix}wpt_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    action VARCHAR(50) NOT NULL,
    object_type VARCHAR(50),
    object_id BIGINT UNSIGNED,
    object_title VARCHAR(255),
    details TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
);
```

Hooks to monitor:
- `save_post` / `delete_post` / `wp_trash_post` / `untrash_post` → post events
- `activated_plugin` / `deactivated_plugin` → plugin events
- `switch_theme` → theme events
- `user_register` / `delete_user` / `profile_update` → user events
- `wp_login` / `wp_logout` → login events
- `update_option` (selective — `blogname`, `blogdescription`, `permalink_structure`, etc.) → settings events
- `add_attachment` / `delete_attachment` → media events
- `wp_update_nav_menu` → menu events

Each hook: `$wpdb->insert()` into audit log with current user, action, object, IP

- Admin page: `WP_List_Table` with filters
- Cron: daily cleanup of records older than `retention_days`
- Details column: JSON with before/after for settings changes

### Edge Cases

- High-traffic sites: audit log can grow fast — enforce retention aggressively
- Batch operations (bulk trash, bulk delete) — log each item but use a single transaction
- Settings changes: only log a curated list of options, not every transient update
- WP-CLI operations: capture user as "WP-CLI" (user_id = 0)
- Rest API changes: hook `rest_after_insert_{post_type}` for completeness

### Verification

1. Activate module → "Audit Log" submenu appears
2. Edit a post → audit log shows "Post Updated" with user and post title
3. Activate a plugin → logged as "Plugin Activated"
4. Log out and back in → both "Logout" and "Login" logged
5. Create a new user → logged
6. Delete a media item → logged
7. Filter by user → shows only that user's actions
8. Filter by date range → works
9. After 90 days → old entries auto-pruned (test by setting retention to 1 day)

---

## Module 21: Lazy Load

**ID:** `lazy-load`
**Category:** `performance`
**One-line:** Lazy load images, iframes, and videos for faster page loads.

### What the User Sees

- Images, iframes, and video embeds below the fold load only when scrolled into view
- No visible change to the user — content appears as they scroll
- First screen content loads immediately (no lazy load above the fold)

### Settings

```
lazy-load: {
    images: true,
    iframes: true,
    videos: true,
    exclude_classes: ['no-lazy'],
    threshold: '200px',              // Load 200px before element enters viewport
    skip_first_n_images: 3           // Don't lazy load first 3 images (above fold)
}
```

### Hooks & Implementation

- WordPress 5.5+ has native lazy loading (`loading="lazy"` on images). This module enhances it.
- `wp_get_attachment_image_attributes` — add `loading="lazy"` if not already present
- `the_content` filter priority 99 — parse HTML, add `loading="lazy"` to `<img>` and `<iframe>` tags
- Skip images with class in `exclude_classes`
- Skip first N images (`skip_first_n_images`) — counter in the content filter
- For videos: convert `<video src="...">` to placeholder with play button, load real video on click
- Use Intersection Observer API (native browser, no library needed) for custom threshold
- Fallback: `loading="lazy"` attribute works without JS in modern browsers

### Edge Cases

- AMP pages — skip, AMP handles its own lazy loading
- Already-lazy images (has `loading="lazy"`) — don't double-process
- Background images in CSS — NOT handled (too complex for v2, consider v3)
- Print stylesheets — all images should load for print
- Elementor/Gutenberg blocks may add their own lazy loading — detect and skip

### Verification

1. Activate module → images below fold get `loading="lazy"` attribute
2. Network tab: images below fold don't load on page load
3. Scroll down → images load as they approach viewport
4. First 3 images load immediately (skip_first_n_images)
5. Add class `no-lazy` to an image → not lazy loaded
6. iframes (YouTube embeds) → lazy loaded
7. Deactivate → all images load normally on page load

---

## Module 22: Image Upload Control

**ID:** `image-upload-control`
**Category:** `performance`
**One-line:** Auto-resize uploaded images, set max dimensions, and control JPEG quality.

### What the User Sees

- Images uploaded beyond max dimensions get auto-resized on upload
- JPEG quality configurable
- Optional WebP conversion on upload
- Existing images can be bulk-processed

### Settings

```
image-upload-control: {
    max_width: 2560,
    max_height: 2560,
    jpeg_quality: 82,
    convert_to_webp: false,
    strip_exif: true,
    exclude_original: false          // Keep original as backup
}
```

### Hooks & Implementation

- `wp_handle_upload` — after upload, check dimensions, resize if exceeds max
- `jpeg_quality` filter — return configured quality
- `wp_generate_attachment_metadata` — modify after thumbnail generation
- Resize: use `wp_get_image_editor()` → `resize()` → `save()`
- WebP: `wp_get_image_editor()` → `save()` with `image/webp` mime type, generate `.webp` alongside original
- EXIF stripping: `wp_get_image_editor()` handles this with `set_quality()`
- Bulk processing: AJAX handler that processes images in batches of 10 (WP Engine timeout safe)

### Edge Cases

- GIFs — don't resize animated GIFs (destroys animation)
- SVGs — skip (handled by SVG Upload module)
- WebP support: check `wp_image_editor_supports(['mime_type' => 'image/webp'])` before offering
- Already-small images — skip if already under max dimensions
- Very large uploads (50MB+) — may timeout even with resize. Handle gracefully.

### Verification

1. Activate module, set max 1920x1920
2. Upload a 4000x3000 image → saved as 1920x1440 (proportional)
3. Upload a 1000x800 image → unchanged (already under max)
4. Set JPEG quality to 60 → uploads are noticeably smaller file size
5. Enable WebP → `.webp` version generated alongside original
6. Enable strip EXIF → no location/camera data in uploaded images
7. Bulk process: run on existing library → resizes oversized images

---

## Module 23: Minify Assets

**ID:** `minify-assets`
**Category:** `performance`
**One-line:** Minify CSS and JavaScript files for faster page loads.

### What the User Sees

- CSS and JS files served minified automatically
- No visible change to the site
- Optional: combine multiple CSS/JS files into fewer requests

### Settings

```
minify-assets: {
    minify_css: true,
    minify_js: true,
    combine_css: false,
    combine_js: false,
    exclude_handles: [],             // WP script/style handles to skip
    cache_dir: 'wpt-cache'
}
```

### Hooks & Implementation

- `style_loader_tag` / `script_loader_tag` — intercept enqueued assets
- Read source file, minify using simple regex-based minification (not a full parser — remove comments, whitespace, newlines)
- Save minified version to `wp-content/wpt-cache/{hash}.min.css` or `.min.js`
- Serve cached version on subsequent requests
- Cache key: `md5(file_path . filemtime(file_path))`
- Combine: concatenate all same-group files into single file, output one `<link>` or `<script>`
- `wp_enqueue_scripts` priority 9999 — dequeue originals, enqueue combined version
- Cache invalidation: check filemtime on each request (cached), regenerate if changed

### Edge Cases

- Already-minified files (`.min.css`, `.min.js`) — skip minification
- Inline scripts/styles — skip
- External CDN files — skip (can't read them)
- JS minification can break code (regex-based is fragile) — provide exclude list
- `combine_js` is risky: script dependency order matters. Only combine if explicitly enabled and tested.
- Cache directory permissions — verify writable on activation
- WP Engine / managed hosting: some hosts clear `wp-content` subdirectories — warn in docs

### Verification

1. Activate module with minify_css on
2. View source → CSS files served from `/wp-content/wpt-cache/` with `.min.css`
3. File sizes smaller than originals
4. No visual changes to the site
5. Enable minify_js → JS files minified
6. Add a handle to exclude → that script served unminified
7. Clear cache (settings button) → files regenerated on next load
8. Deactivate → original files served again

---

## Module 24: Cron Manager

**ID:** `cron-manager`
**Category:** `utilities`
**One-line:** View, edit, and manage WordPress scheduled events (cron jobs).

### What the User Sees

- Admin page: WP Transformation → Cron Manager
- Table of all scheduled events: Hook Name | Schedule | Next Run | Interval | Actions (Run Now / Delete / Edit)
- "Add New Event" for custom cron jobs
- List of registered cron schedules (hourly, daily, twicedaily, weekly + custom)
- "Run Now" button executes the event immediately

### Settings

```
cron-manager: {
    show_system_crons: true,
    enable_custom_schedules: true
}
```

### Hooks & Implementation

- Read `_get_cron_array()` for all scheduled events
- Read `wp_get_schedules()` for schedule definitions
- "Run Now": `wp_schedule_single_event(time(), $hook, $args)` then `spawn_cron()`
- "Delete": `wp_unschedule_event($timestamp, $hook, $args)`
- "Add New": `wp_schedule_event(time(), $recurrence, $hook, $args)`
- Display: `WP_List_Table` with columns for hook, schedule name, next run (human-readable relative time), interval
- Custom schedules: `cron_schedules` filter to add user-defined intervals
- Highlight overdue events (next_run in the past) in red

### Edge Cases

- Some cron hooks have serialized args — display but warn about editing
- WP-Cron vs. real cron: show notice if `DISABLE_WP_CRON` is defined
- Deleting core WordPress crons (wp_version_check, wp_update_plugins) — warn but allow
- "Run Now" on a hook with no callback registered — will silently fail. Show warning.
- Large number of cron events (100+) — paginate

### Verification

1. Activate module → "Cron Manager" submenu appears
2. All WordPress scheduled events listed
3. Find `wp_scheduled_delete` → shows next run time
4. Click "Run Now" on a safe event → executes immediately
5. Add custom event with custom schedule → appears in list
6. Delete the custom event → removed
7. Overdue events highlighted
8. If `DISABLE_WP_CRON` defined → notice shown

---

## Module 25: Maintenance Mode

**ID:** `maintenance-mode`
**Category:** `utilities`
**One-line:** Show a maintenance page to visitors while you work on the site.

### What the User Sees

- Toggle: site in maintenance mode or live
- Logged-in admins see the site normally
- Everyone else sees a customizable maintenance page
- Optional: countdown timer, progress bar, allowed IPs

### Settings

```
maintenance-mode: {
    enabled: false,
    headline: 'Under Maintenance',
    message: 'We are currently performing scheduled maintenance. We will be back shortly.',
    show_countdown: false,
    countdown_end: '',               // ISO 8601 datetime
    bg_color: '#1a1a2e',
    text_color: '#ffffff',
    accent_color: '#e94560',
    custom_css: '',
    allowed_roles: ['administrator'],
    allowed_ips: [],
    allow_login_page: true,
    response_code: 503,
    retry_after: 3600                // seconds — Retry-After header for SEO
}
```

### Hooks & Implementation

- `template_redirect` priority 0 — check if maintenance enabled
- If enabled: check `current_user_can()` against allowed_roles, check IP against allowed_ips
- If not allowed: send `503` status, `Retry-After` header, output maintenance HTML, `exit`
- Allow login page: don't block `/wp-login.php` or `/wp-admin/` (so admins can log in)
- Maintenance page: self-contained HTML (no theme dependency, no external resources)
- Countdown: vanilla JS timer in the maintenance page
- Admin bar indicator: show "Maintenance Mode Active" warning in admin bar when enabled
- REST API: also return 503 for non-authenticated requests when maintenance is on

### Edge Cases

- Feed URLs should also return 503 during maintenance
- XML sitemap should return 503
- Admin AJAX (`admin-ajax.php`) — allow through (plugins may need it)
- WP-Cron — allow through (scheduled tasks should keep running)
- Don't cache the maintenance page — add `no-cache` headers
- `Retry-After` header is critical for SEO — search engines respect it and come back

### Verification

1. Activate module → maintenance settings appear
2. Toggle maintenance on → visit site in incognito → see maintenance page
3. Logged-in admin → sees site normally
4. Maintenance page shows custom headline and message
5. Change colors → maintenance page updates
6. Enable countdown → timer shows on maintenance page
7. Add IP to allowed list → that IP sees site normally
8. Check response code → 503 with Retry-After header
9. Login page still accessible → can log in during maintenance
10. Toggle off → site immediately live for everyone

---

## Build Notes for `/batch`

When running `/batch` on these 15 modules:

1. Each module builds in its own git worktree (isolated)
2. Each follows the wp-module-builder skill
3. Modules with custom DB tables (14, 15, 18, 20) need `dbDelta()` in `activate_module()` and cleanup in `get_cleanup_tasks()`
4. Modules with cron jobs (14, 18, 20) need to register/deregister schedules in activate/deactivate
5. All modules share the same Settings API — use `$this->get_settings()` and `$this->get_default_settings()`
6. Test each module in isolation — no module should depend on another
7. After build: merge all module branches to main, verify no file conflicts
8. Run full test suite: `composer test`

### Priority Order (if building sequentially)

Build order based on complexity and dependency:
1. Lazy Load (21) — simplest, content filter only
2. Image Upload Control (22) — upload hooks, straightforward
3. Maintenance Mode (25) — self-contained, no DB table
4. Login Customizer (17) — CSS only, no DB
5. Cron Manager (24) — read-only mostly, simple CRUD
6. Cookie Consent (16) — frontend-heavy but self-contained
7. Session Manager (19) — reads native WP session system
8. Limit Login Attempts (18) — custom DB table, cron cleanup
9. Redirect Manager (14) — custom DB table, request interception
10. Audit Log (20) — custom DB table, many hooks
11. Minify Assets (23) — file system caching, fragile
12. Media Folders (12) — custom taxonomy + JS-heavy (drag-drop)
13. Code Snippets (15) — eval() risk, error recovery needed
14. User Role Editor (13) — capability manipulation, high risk
15. Custom Content Types (11) — CPT/taxonomy registration, rewrite flush
