# WPTransformed v1 — Complete Module Specifications

> This document specifies every feature, hook, setting, and verification step for each v1 module.
> Claude Code builds ONE module at a time. Each module must pass its verification before moving on.

---

## Module List (10 total)

| # | Module | Category | Tier |
|---|--------|----------|------|
| 1 | Content Duplication | Content Management | Free |
| 2 | Admin Menu Editor | Admin Interface | Free |
| 3 | Hide Admin Notices | Admin Interface | Free |
| 4 | SVG Upload | Content Management | Free |
| 5 | Clean Admin Bar | Admin Interface | Free |
| 6 | Dark Mode | Admin Interface | Free |
| 7 | Database Cleanup | Performance | Free |
| 8 | Heartbeat Control | Performance | Free |
| 9 | Disable Comments | Utilities | Free |
| 10 | Email SMTP | Utilities | Free |

All v1 modules are free tier. No Freemius gating needed yet.

---

## Module 1: Content Duplication

**One-line:** One-click clone of any post, page, or CPT with all metadata and taxonomies.

### What the User Sees

- A "Duplicate" link appears in the row actions on Posts, Pages, and all public CPTs (next to Edit | Quick Edit | Trash | View)
- Clicking "Duplicate" instantly creates a draft copy and redirects to the post list with an admin notice: "Post duplicated successfully."
- The duplicated post opens with "(Copy)" appended to the title
- A "Duplicate" link also appears in the admin bar when viewing/editing a single post on the frontend or backend

### Settings (stored in wpt_settings table as JSON)

```
content-duplication: {
    post_types: ['post', 'page'],     // Checkboxes — which post types show the Duplicate link
    copy_taxonomies: true,            // Clone categories, tags, custom taxonomies
    copy_meta: true,                  // Clone all post meta (custom fields, ACF, etc.)
    copy_featured_image: true,        // Clone the featured image assignment
    title_prefix: '',                 // Prepend to cloned title (e.g., "Copy of ")
    title_suffix: ' (Copy)',          // Append to cloned title
    new_status: 'draft',             // Status for cloned post: draft | pending | private
    redirect_after: 'list',          // Where to go after clone: list | edit
}
```

### WordPress Hooks Used

| Hook | Type | Purpose |
|------|------|---------|
| `post_row_actions` | filter | Add "Duplicate" link to posts |
| `page_row_actions` | filter | Add "Duplicate" link to pages |
| `admin_action_wpt_duplicate_post` | action | Handle the duplication when link is clicked |
| `admin_notices` | action | Show success/error message after duplication |
| `admin_bar_menu` | action | Add "Duplicate" to admin bar on single post views |

### Exact Behavior — Duplication Process

When the user clicks "Duplicate":

1. **Verify nonce** — `wp_verify_nonce( $_GET['wpt_nonce'], 'wpt_duplicate_' . $post_id )`
2. **Verify capability** — `current_user_can( 'edit_post', $post_id )` for that specific post
3. **Get the source post** — `get_post( $post_id )` — bail with error if not found
4. **Create the new post** with `wp_insert_post()`:
   - `post_title` → apply prefix/suffix from settings
   - `post_content` → exact copy
   - `post_excerpt` → exact copy
   - `post_status` → from settings (default: 'draft')
   - `post_type` → same as source
   - `post_author` → current user (NOT original author)
   - `post_parent` → same as source (preserves page hierarchy)
   - `menu_order` → same as source
   - `post_password` → same as source
   - `comment_status` → same as source
   - `ping_status` → same as source
   - Do NOT copy: `post_date`, `post_name` (slug), `guid`, `post_modified`
5. **Copy taxonomies** (if setting enabled):
   - Get all taxonomies for this post type via `get_object_taxonomies( $post_type )`
   - For each taxonomy: `wp_get_object_terms()` then `wp_set_object_terms()` on the new post
6. **Copy post meta** (if setting enabled):
   - `get_post_meta( $source_id )` — loop through all meta keys
   - Skip internal WP meta: `_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date`
   - For each meta key: `add_post_meta( $new_id, $key, $value )`
   - Handle serialized data correctly (WordPress does this automatically with `add_post_meta`)
7. **Copy featured image** (if setting enabled):
   - `get_post_thumbnail_id( $source_id )` → `set_post_thumbnail( $new_id, $thumb_id )`
   - Does NOT duplicate the media file — just assigns the same attachment
8. **Set transient** for admin notice: `set_transient( 'wpt_duplicate_notice_' . get_current_user_id(), $new_id, 30 )`
9. **Redirect** based on setting:
   - `list` → `wp_safe_redirect( admin_url( 'edit.php?post_type=' . $post_type ) )`
   - `edit` → `wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) )`

### Settings Page UI

On the WPTransformed settings page, under "Content Duplication" module:

- **Post Types** — Checkboxes for each registered public post type. Auto-populated via `get_post_types( ['public' => true] )`. Default: post + page checked.
- **Clone Options** — Three checkboxes: Copy Taxonomies ✓, Copy Custom Fields ✓, Copy Featured Image ✓
- **Title Format** — Two text inputs: Prefix (empty default), Suffix ("(Copy)" default)
- **New Post Status** — Dropdown: Draft (default), Pending Review, Private
- **After Duplication** — Radio: Return to post list (default), Open editor for new post

### Edge Cases to Handle

- **Multisite:** Works within current site only. No cross-site duplication in v1.
- **WooCommerce products:** If WooCommerce is active and "product" is in enabled post types, duplication should work. Product-specific meta (price, SKU, stock) copies via the general meta copy. NOT duplicating product variations in v1 (that's complex — skip for now).
- **Large meta:** Posts with hundreds of meta rows (ACF, page builders) — the loop handles this but may be slow. Acceptable for v1.
- **Permissions:** Only users who can `edit_post` on the source post see the Duplicate link. Only users who can `publish_posts` (or equivalent) for that post type can create new ones.

### Verification Steps

After building this module:

1. Activate WPTransformed — no PHP errors, no white screen
2. Go to Settings → WPTransformed — Content Duplication module appears and can be toggled on
3. Toggle it ON — go to Posts → All Posts
4. Hover over any post — "Duplicate" link appears in row actions
5. Click "Duplicate" — redirected to post list, admin notice shows "Post duplicated successfully"
6. Open the new post — title has "(Copy)" suffix, content matches, status is Draft
7. Check categories/tags — they match the original
8. Check custom fields — they match the original
9. Check featured image — same image assigned
10. Go to Pages — "Duplicate" link also appears
11. Disable the module — "Duplicate" links disappear everywhere
12. Test with a user who is an Editor (not Admin) — Duplicate works for their own posts, not others' unless they have `edit_others_posts`

---

## Module 2: Admin Menu Editor

**One-line:** Reorder, rename, and hide WordPress admin sidebar menu items via drag-and-drop.

### What the User Sees

- In WPTransformed settings, a visual editor showing all current admin menu items
- Each item shows: icon, current label, and a visibility toggle (eye icon)
- Items are draggable to reorder
- Clicking an item expands it to show: rename field, custom icon picker (dashicons), and a "Hide" checkbox
- Separators can be added between items
- A "Reset to Default" button restores the original WordPress menu order
- Changes apply immediately on save (no page reload needed for the settings page, but the admin menu updates on next page load)

### Settings (stored in wpt_settings table as JSON)

```
admin-menu-editor: {
    enabled: true,
    menu_order: [                    // Array of menu item slugs in desired order
        'index.php',                 // Dashboard
        'separator1',
        'edit.php',                  // Posts
        'edit.php?post_type=page',   // Pages
        'upload.php',                // Media
        // ... etc
    ],
    hidden_items: [                  // Menu items to hide (slugs)
        'edit-comments.php',
    ],
    renamed_items: {                 // slug → new label
        'index.php': 'Home',
        'edit.php': 'Blog Posts',
    },
    custom_icons: {                  // slug → dashicon class
        'edit.php': 'dashicons-edit-page',
    },
    separators: [                    // Insert separators after these slugs
        'upload.php',
    ],
}
```

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `custom_menu_order` | filter | 10 | Return `true` to enable custom ordering |
| `menu_order` | filter | 999 | Return the reordered menu slug array |
| `admin_menu` | action | 999 (late) | Hide items, rename items, change icons |
| `admin_enqueue_scripts` | action | 10 | Load drag-and-drop JS only on WPT settings page |

### Exact Behavior

**Reordering:**
1. Hook into `custom_menu_order` filter → return `true`
2. Hook into `menu_order` filter at priority 999 (after all plugins register their menus)
3. Return the saved `menu_order` array from settings
4. Any menu items NOT in the saved array (new plugins added after config) are appended at the end

**Hiding items:**
1. In `admin_menu` at priority 999:
2. Loop through `hidden_items` array
3. For each: `remove_menu_page( $slug )` — this removes the menu item but does NOT remove the capability to access the page directly via URL. This is intentional (hiding ≠ access control).

**Renaming:**
1. In `admin_menu` at priority 999:
2. Access `global $menu` array
3. Find each slug in `renamed_items` and update the label (index [0] of the menu array entry)

**Custom icons:**
1. In `admin_menu` at priority 999:
2. Access `global $menu` array
3. Find each slug in `custom_icons` and update the icon class (index [6] of the menu array entry)

**Settings page editor:**
The editor in WPT settings reads the current `global $menu` array and renders it as a sortable list. JavaScript handles:
- Drag-and-drop reordering (using native HTML5 drag API or a lightweight sortable library — NO jQuery UI)
- Inline rename (click label to edit)
- Icon picker (click icon to show dashicons grid)
- Hide toggle (eye icon)
- Save sends the full configuration as JSON to the WPT REST API

### What This Does NOT Do (v1 scope)

- No per-role menu configurations (everyone sees the same customized menu)
- No submenu editing (only top-level items)
- No custom menu items (only reorder/rename/hide existing items)
- No admin bar editing (that's Clean Admin Bar module)

### Settings Page UI

- **Menu Editor** — Full-width visual list of current menu items
  - Each row: [drag handle] [icon] [label (editable)] [eye toggle] [expand arrow]
  - Expanded: rename field, dashicons picker grid, "Hide this item" checkbox
  - Bottom: [+ Add Separator] button, [Reset to Default] button, [Save Changes] button
- **Note text:** "Hidden menu items are removed from the sidebar but remain accessible via direct URL. To restrict access, use role-based capabilities instead."

### Edge Cases

- **New plugins:** If a plugin is installed after menu is configured, its menu item appears at the bottom (appended to saved order). The user can then reorder it.
- **Removed plugins:** If a saved slug no longer exists in the actual menu, it's silently skipped.
- **Multisite:** Network admin menu is separate from site admin menu. v1 only handles site admin menu.
- **Other plugins that modify menus:** Our hooks run at priority 999 to be last. If another plugin also uses 999, results may vary. This is acceptable for v1.

### Verification Steps

1. Toggle Admin Menu Editor ON in WPT settings
2. The settings page shows a visual list of all current admin menu items
3. Drag "Posts" below "Pages" → Save → Refresh any admin page → sidebar shows Pages above Posts
4. Rename "Dashboard" to "Home" → Save → Refresh → sidebar shows "Home"
5. Hide "Comments" → Save → Refresh → Comments is gone from sidebar
6. Navigate directly to `edit-comments.php` → page still loads (not access-blocked)
7. Click "Reset to Default" → Save → menu returns to WordPress default order
8. Install a new plugin → its menu item appears at the bottom of the list
9. Disable the module → admin menu reverts to WordPress defaults

---

## Module 3: Hide Admin Notices

**One-line:** Collapse all admin notices into a single togglable panel so they don't clutter the admin.

### What the User Sees

- Instead of notices stacking at the top of every admin page, they're hidden behind a small bar: "📋 3 notices" (with a count)
- Clicking the bar expands a panel showing all notices
- Each notice retains its original type (success/warning/error/info) and content
- A "Dismiss All" button clears all dismissible notices
- On the WPT settings page, a toggle controls whether notices are hidden globally or only on specific pages

### Settings

```
hide-admin-notices: {
    enabled: true,
    scope: 'all',                    // 'all' | 'wpt-only' (only on WPT settings pages)
    show_count_badge: true,          // Show notice count in the collapsed bar
    auto_expand_errors: true,        // Auto-expand if there are error-type notices
    hide_for_roles: [],              // Empty = hide for everyone. Could be ['editor', 'author']
}
```

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `admin_enqueue_scripts` | action | 10 | Load CSS/JS on admin pages |
| `in_admin_header` | action | 999 | Inject the collapsed notice bar HTML |
| `admin_notices` | action | 1 (very early) | Start output buffering to capture notices |
| `all_admin_notices` | action | PHP_INT_MAX | End output buffering, move notices into panel |

### Exact Behavior

**The output buffering approach:**
1. Hook into `admin_notices` at priority 1 → `ob_start()`
2. Hook into `all_admin_notices` at priority PHP_INT_MAX → `$notices_html = ob_get_clean()`
3. Count the number of `.notice` divs in the captured HTML (regex or DOMDocument)
4. If count > 0:
   - Render the collapsed bar: `<div class="wpt-notice-bar">📋 {count} notices <button>Show</button></div>`
   - Render a hidden container: `<div class="wpt-notice-panel" style="display:none;">{$notices_html}<button class="wpt-dismiss-all">Dismiss All</button></div>`
5. If count === 0: render nothing
6. If `auto_expand_errors` is true AND any notice has class `notice-error`: auto-expand the panel

**JavaScript (inline, tiny — no build step):**
```js
// Toggle panel visibility on bar click
// "Dismiss All" clicks every .is-dismissible notice's dismiss button
// Store expanded/collapsed state in sessionStorage
```

**CSS (inline, < 30 lines):**
```css
/* Bar styling: subtle background, left border accent, count badge */
/* Panel: slides down, max-height with overflow scroll */
/* Preserves original notice colors (error=red, warning=yellow, etc.) */
```

### What This Does NOT Do (v1 scope)

- No per-notice dismissal memory (WordPress handles `is-dismissible` natively)
- No notice history/log (not storing past notices)
- No filtering by notice type
- No suppression of specific plugin notices

### Settings Page UI

- **Scope** — Radio: Hide notices on all admin pages (default), Only on WPTransformed pages
- **Auto-expand for errors** — Checkbox (default: checked). "Automatically show the notice panel if there are error-level notices"
- **Show count badge** — Checkbox (default: checked). "Show the number of hidden notices in the collapsed bar"

### Edge Cases

- **Notices added via JavaScript:** Some plugins inject notices via JS after page load. These won't be captured by output buffering. This is acceptable — the module catches PHP-rendered notices, which is 95%+ of them.
- **Notices outside the standard hooks:** Some poorly-coded plugins echo notices directly in `admin_head` or elsewhere. These won't be captured. Acceptable.
- **WooCommerce/Elementor persistent notices:** These often have their own dismiss logic. Our "Dismiss All" only clicks standard WP `.is-dismissible` buttons.
- **Empty state:** If there are zero notices, the bar doesn't render at all — no visual footprint.

### Verification Steps

1. Toggle Hide Admin Notices ON
2. Go to Dashboard (or any admin page with notices) — notices are collapsed into a bar showing count
3. Click the bar — panel expands showing all notices with their original styling
4. Click "Dismiss All" — all dismissible notices disappear
5. If a notice-error exists and auto_expand_errors is on — panel is already expanded on page load
6. Disable the module — notices appear normally at the top of admin pages again
7. Check that the notice bar doesn't appear when there are zero notices

---

## Module 4: SVG Upload

**One-line:** Allow SVG file uploads to the WordPress media library with basic sanitization.

### What the User Sees

- SVG files can be uploaded via the media library (drag-and-drop or file picker)
- SVG thumbnails display properly in the media library grid view
- SVGs can be inserted into posts/pages and used as featured images
- In WPT settings, admins can choose which roles are allowed to upload SVGs

### Settings

```
svg-upload: {
    enabled: true,
    allowed_roles: ['administrator'],   // Which roles can upload SVGs
    sanitize: true,                     // Remove potentially dangerous SVG elements
}
```

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `upload_mimes` | filter | 10 | Add `svg` and `svgz` to allowed MIME types |
| `wp_check_filetype_and_ext` | filter | 10 | Fix WordPress file type detection for SVGs |
| `wp_handle_upload_prefilter` | filter | 10 | Sanitize SVG content before saving |
| `wp_get_attachment_image_src` | filter | 10 | Return SVG dimensions for proper display |
| `wp_prepare_attachment_for_js` | filter | 10 | Add SVG dimensions to media library JS data |
| `admin_head` | action | 10 | Add CSS for SVG thumbnails in media library |

### Exact Behavior

**Enabling SVG uploads:**
```php
add_filter('upload_mimes', function($mimes) {
    // Check if current user's role is in allowed_roles
    $user = wp_get_current_user();
    $allowed = array_intersect($user->roles, $settings['allowed_roles']);
    if (empty($allowed)) return $mimes;
    
    $mimes['svg'] = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
});
```

**Fixing file type detection:**
WordPress's `wp_check_filetype_and_ext` can fail for SVGs because `finfo` doesn't always detect SVGs correctly:
```php
add_filter('wp_check_filetype_and_ext', function($data, $file, $filename, $mimes) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext === 'svg' || $ext === 'svgz') {
        $data['ext'] = $ext;
        $data['type'] = 'image/svg+xml';
    }
    return $data;
}, 10, 4);
```

**SVG Sanitization (critical for security):**

SVGs are XML documents that can contain:
- `<script>` tags (XSS)
- `on*` event handlers like `onclick`, `onload` (XSS)
- `<foreignObject>` elements (can embed arbitrary HTML)
- External references via `xlink:href` or `href` pointing to remote resources
- CSS `url()` references to external resources
- `<use>` elements referencing external files

**Sanitization approach (whitelist, not blacklist):**

On upload (via `wp_handle_upload_prefilter`):
1. Read the uploaded file content
2. Parse it as XML using `DOMDocument`
3. If XML parsing fails → reject the upload with error "Invalid SVG file"
4. Walk every element in the DOM tree
5. **Allowed elements** (whitelist): `svg`, `g`, `path`, `circle`, `ellipse`, `rect`, `line`, `polyline`, `polygon`, `text`, `tspan`, `defs`, `use` (local refs only), `symbol`, `clipPath`, `mask`, `pattern`, `linearGradient`, `radialGradient`, `stop`, `title`, `desc`, `image` (with data: URI only)
6. **Remove** any element not in the whitelist (including `script`, `foreignObject`, `iframe`, `embed`, `object`, `set`, `animate`, `animateTransform`)
7. **For every element:** remove any attribute starting with `on` (event handlers)
8. **For every `href` and `xlink:href`:** remove if it starts with `javascript:` or points to external URL (only allow `#` fragment references and `data:` URIs)
9. **Remove** any `<style>` elements that contain `url(` pointing to external resources
10. Save the sanitized SVG back to the file
11. If the SVG is empty after sanitization → reject with error "SVG contains no valid elements"

**SVG display in media library:**

SVGs don't have intrinsic pixel dimensions the way raster images do. WordPress needs dimensions for the media grid:

```php
add_filter('wp_prepare_attachment_for_js', function($response, $attachment) {
    if ($response['mime'] === 'image/svg+xml') {
        $file = get_attached_file($attachment->ID);
        $svg = simplexml_load_file($file);
        if ($svg) {
            $width = 0;
            $height = 0;
            // Try viewBox first, then width/height attributes
            if ($svg['viewBox']) {
                $viewbox = explode(' ', (string)$svg['viewBox']);
                if (count($viewbox) === 4) {
                    $width = (float)$viewbox[2];
                    $height = (float)$viewbox[3];
                }
            }
            if (!$width && $svg['width']) $width = (float)$svg['width'];
            if (!$height && $svg['height']) $height = (float)$svg['height'];
            
            if ($width && $height) {
                $response['sizes'] = [
                    'full' => [
                        'url' => $response['url'],
                        'width' => $width,
                        'height' => $height,
                        'orientation' => $width > $height ? 'landscape' : 'portrait',
                    ]
                ];
            }
        }
    }
    return $response;
}, 10, 2);
```

**CSS for media library thumbnails:**
```css
/* SVGs in the media grid need explicit sizing */
.attachment-preview .thumbnail img[src$=".svg"],
.attachment-preview .thumbnail img[src$=".svgz"] {
    width: 100%;
    height: auto;
    padding: 5px;
}
```

### What This Does NOT Do (v1)

- No SVG editing/optimization (minification, removing metadata)
- No SVG inline rendering option (always served as `<img>` tag)
- No SVG preview on upload dialog
- No batch scanning of existing SVGs for malicious content

### Settings Page UI

- **Allowed Roles** — Checkboxes for each role. Default: only Administrator checked. Note: "Only users with these roles can upload SVG files."
- **Sanitization** — Checkbox (default: checked, cannot be unchecked in v1). "Remove potentially dangerous elements from uploaded SVGs. Strongly recommended."

### Edge Cases

- **Malformed SVGs:** If `DOMDocument` can't parse the file, upload is rejected with a clear error.
- **SVGs with embedded raster images:** If the `<image>` element uses a base64 `data:` URI, it's allowed. If it references an external URL, the `href` is removed.
- **Compressed SVGs (.svgz):** These are gzip-compressed SVGs. We'd need to decompress before sanitizing. In v1, we allow the MIME type but only sanitize uncompressed `.svg` files. `.svgz` files only upload if the user is an administrator (extra safety).
- **Existing SVGs:** SVGs already in the media library (uploaded before module activation) are not retroactively sanitized.

### Verification Steps

1. Toggle SVG Upload ON
2. Go to Media → Add New → upload a `.svg` file → it uploads successfully
3. The SVG displays as a thumbnail in the media grid
4. Insert the SVG into a post → it renders in the editor and on the frontend
5. Upload an SVG containing a `<script>` tag → the script element is stripped, SVG still uploads
6. Upload a non-XML file renamed to `.svg` → upload rejected with "Invalid SVG file" error
7. Log in as an Editor (if Editor is not in `allowed_roles`) → SVG upload is blocked
8. Disable the module → SVG uploads return to WordPress default behavior (blocked)

---

## Module 5: Clean Admin Bar

**One-line:** Remove or hide specific items from the WordPress admin bar (toolbar).

### What the User Sees

- In WPT settings, a list of all current admin bar items with checkboxes to hide each one
- Common items listed: WordPress logo, site name/visit site link, updates counter, comments counter, new content menu, edit link, Howdy/user menu
- Third-party items (from plugins) also appear and can be hidden
- Changes take effect immediately on next page load

### Settings

```
clean-admin-bar: {
    enabled: true,
    hidden_nodes: [           // Admin bar node IDs to remove
        'wp-logo',           // WordPress logo dropdown
        'comments',          // Comments counter
        'updates',           // Updates counter
        'new-content',       // "+ New" dropdown
    ],
    hide_for_roles: [],      // If empty, applies to all roles
}
```

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `wp_before_admin_bar_render` | action | 999 | Remove nodes from admin bar |
| `admin_bar_menu` | action | 5 (early, for reading) | Capture current nodes for settings UI |

### Exact Behavior

**Removing admin bar items:**
```php
add_action('wp_before_admin_bar_render', function() {
    global $wp_admin_bar;
    $hidden = $settings['hidden_nodes'];
    foreach ($hidden as $node_id) {
        $wp_admin_bar->remove_node($node_id);
    }
}, 999);
```

**Known admin bar node IDs:**

| Node ID | What it is |
|---------|-----------|
| `wp-logo` | WordPress logo + dropdown (About, WP.org, Documentation, Support, Feedback) |
| `site-name` | Site name link → Visit Site (frontend) or Dashboard (backend) |
| `my-sites` | My Sites (multisite only) |
| `updates` | Update counter (shows pending updates count) |
| `comments` | Comment counter |
| `new-content` | "+ New" dropdown (Post, Media, Page, User) |
| `edit` | "Edit Page/Post" link (on frontend, for the current page) |
| `my-account` | "Howdy, Username" dropdown (Profile, Log Out) |
| `search` | Search field (if enabled) |
| `top-secondary` | Right side container (holds my-account) |

**Populating the settings page:**
On the WPT settings page, we need to list all admin bar nodes so the user can check which to hide. We do this by:
1. Hooking `admin_bar_menu` at a late priority to read all registered nodes
2. Storing the node list in a transient (refreshed daily or on settings page load)
3. Displaying each node with its ID and rendered title in the settings UI

### What This Does NOT Do (v1)

- No reordering admin bar items
- No adding custom admin bar items
- No per-role different configurations
- No custom CSS for admin bar styling

### Settings Page UI

- **Hidden Items** — Checklist of all detected admin bar nodes. Each row shows: checkbox, node title (human readable), node ID (gray, smaller). Pre-checked items are hidden.
- **Info note:** "Hiding items from the admin bar does not affect permissions. Users can still access these features via direct URL."
- **Refresh button:** "Scan Admin Bar" — re-scans to pick up items from newly installed plugins.

### Edge Cases

- **Multisite:** The `my-sites` node only exists on multisite installs. Only show it in the settings list if `is_multisite()`.
- **Plugin-added nodes:** Plugins like WooCommerce, Yoast, etc. add their own admin bar items. These should be detected and listable. We capture them via the transient-based scan.
- **The `my-account` node:** Hiding this removes the logout link. Show a warning: "Hiding this removes the logout option from the admin bar. Users can still log out via wp-login.php?action=logout."
- **Frontend admin bar:** The admin bar appears on the frontend too. Our removal applies to both contexts.

### Verification Steps

1. Toggle Clean Admin Bar ON
2. In settings, check "WordPress Logo" and "Comments" to hide
3. Save → refresh any admin page → WP logo and comments counter are gone from admin bar
4. Visit the frontend while logged in → same items hidden from frontend admin bar
5. Uncheck them → Save → items return
6. Disable the module → all admin bar items return to default
7. Install a new plugin that adds an admin bar item → it appears in the Clean Admin Bar settings list after scanning

---

## Module 6: Dark Mode

**One-line:** Add a dark color scheme to the entire WordPress admin with a toggle in the admin bar.

### What the User Sees

- A moon/sun icon toggle in the admin bar (right side, near the user menu)
- Clicking it switches the entire admin between light and dark themes instantly
- The preference persists per-user (saved to user meta)
- Optionally auto-detects the OS/browser dark mode preference on first visit
- The dark theme covers the sidebar, content area, admin bar, and WPT settings pages
- Third-party plugin pages get a best-effort dark treatment

### Settings

```
dark-mode: {
    enabled: true,
    auto_detect_os: true,        // Use prefers-color-scheme on first visit
    default_mode: 'light',       // Fallback: 'light' | 'dark'
    show_toggle: true,           // Show moon/sun in admin bar
}
```

**Per-user storage:** `wpt_dark_mode` user meta → `'on'` | `'off'` | `''` (empty = use default/auto)

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `admin_enqueue_scripts` | action | 10 | Enqueue dark mode CSS |
| `admin_bar_menu` | action | 100 | Add moon/sun toggle to admin bar |
| `wp_ajax_wpt_toggle_dark_mode` | action | 10 | Save user preference via AJAX |
| `admin_body_class` | filter | 10 | Add `wpt-dark` class to body when dark mode is active |
| `admin_head` | action | 1 (very early) | Inline critical CSS to prevent flash of wrong theme |

### Exact Behavior

**Determining if dark mode is active:**
1. Check user meta `wpt_dark_mode`:
   - `'on'` → dark mode active
   - `'off'` → dark mode inactive
   - `''` (empty/not set) → check `auto_detect_os` setting
2. If `auto_detect_os` is true and no user preference set:
   - Inject inline JS in `admin_head` that checks `window.matchMedia('(prefers-color-scheme: dark)')` and sets body class accordingly
   - On first toggle, save the preference to user meta

**Body class:**
```php
add_filter('admin_body_class', function($classes) {
    if (wpt_is_dark_mode()) {
        $classes .= ' wpt-dark';
    }
    return $classes;
});
```

**Preventing flash of wrong theme (CRITICAL):**
Without this, users see a flash of light theme before dark CSS loads. Solution:
```php
add_action('admin_head', function() {
    // Inline a tiny script that immediately sets the class before anything renders
    $dark = get_user_meta(get_current_user_id(), 'wpt_dark_mode', true);
    if ($dark === 'on') {
        echo '<script>document.documentElement.classList.add("wpt-dark");</script>';
    } elseif ($dark === '' && $settings['auto_detect_os']) {
        echo '<script>if(window.matchMedia("(prefers-color-scheme:dark)").matches)document.documentElement.classList.add("wpt-dark");</script>';
    }
}, 1);
```

**Admin bar toggle:**
```php
add_action('admin_bar_menu', function($wp_admin_bar) {
    $is_dark = get_user_meta(get_current_user_id(), 'wpt_dark_mode', true) === 'on';
    $wp_admin_bar->add_node([
        'id' => 'wpt-dark-mode-toggle',
        'title' => $is_dark ? '☀️' : '🌙',
        'meta' => ['class' => 'wpt-dark-toggle', 'onclick' => 'wptToggleDark(event)'],
    ]);
}, 100);
```

**AJAX toggle handler:**
```php
add_action('wp_ajax_wpt_toggle_dark_mode', function() {
    check_ajax_referer('wpt_dark_mode_nonce', 'nonce');
    $mode = sanitize_text_field($_POST['mode'] ?? '');
    if (!in_array($mode, ['on', 'off'])) wp_send_json_error();
    update_user_meta(get_current_user_id(), 'wpt_dark_mode', $mode);
    wp_send_json_success();
});
```

**Dark mode CSS approach:**

The CSS uses CSS custom properties scoped to `.wpt-dark`:

```css
/* Default (light) — these are WordPress defaults, mostly inherited */
:root {
    --wpt-bg: #f0f0f1;
    --wpt-surface: #ffffff;
    --wpt-text: #1d2327;
    --wpt-text-secondary: #50575e;
    --wpt-border: #c3c4c7;
    --wpt-sidebar-bg: #1d2327;
    --wpt-sidebar-text: #f0f0f1;
}

/* Dark mode overrides */
.wpt-dark {
    --wpt-bg: #1a1a2e;
    --wpt-surface: #16213e;
    --wpt-text: #e4e6eb;
    --wpt-text-secondary: #b0b3b8;
    --wpt-border: #3a3b3c;
    --wpt-sidebar-bg: #0f0f1a;
    --wpt-sidebar-text: #e4e6eb;
}

/* Apply to WordPress admin elements */
.wpt-dark #wpcontent,
.wpt-dark #wpbody-content {
    background: var(--wpt-bg);
    color: var(--wpt-text);
}

.wpt-dark .postbox,
.wpt-dark .stuffbox,
.wpt-dark .widefat,
.wpt-dark .card {
    background: var(--wpt-surface);
    border-color: var(--wpt-border);
    color: var(--wpt-text);
}

/* Admin bar */
.wpt-dark #wpadminbar {
    background: var(--wpt-sidebar-bg);
}

/* Tables */
.wpt-dark .wp-list-table th,
.wpt-dark .wp-list-table td {
    color: var(--wpt-text);
}
.wpt-dark .wp-list-table .alternate {
    background: var(--wpt-surface);
}
.wpt-dark .wp-list-table tr:hover td {
    background: rgba(255,255,255,0.05);
}

/* Inputs */
.wpt-dark input[type="text"],
.wpt-dark input[type="email"],
.wpt-dark input[type="password"],
.wpt-dark input[type="search"],
.wpt-dark input[type="url"],
.wpt-dark input[type="number"],
.wpt-dark select,
.wpt-dark textarea {
    background: var(--wpt-bg);
    border-color: var(--wpt-border);
    color: var(--wpt-text);
}

/* Buttons stay as-is (WP blue buttons remain readable) */

/* Sidebar — WP sidebar is already dark by default. Adjust slightly: */
.wpt-dark #adminmenuback,
.wpt-dark #adminmenuwrap {
    background: var(--wpt-sidebar-bg);
}

/* Notices */
.wpt-dark .notice {
    background: var(--wpt-surface);
    color: var(--wpt-text);
}

/* Editor (Classic editor only — Gutenberg has its own dark mode in WP 6.x) */
.wpt-dark .wp-editor-area {
    background: var(--wpt-surface);
    color: var(--wpt-text);
}

/* Dashboard widgets */
.wpt-dark #dashboard-widgets .postbox {
    background: var(--wpt-surface);
}
```

The CSS file is ~150-200 lines covering major WP admin elements. Third-party plugin pages get best-effort coverage through the base selectors (most plugins use standard WP classes).

### What This Does NOT Do (v1)

- No custom color scheme builder (just one dark theme)
- No frontend dark mode
- No Gutenberg/block editor dark mode (WP 6.x has some built-in support)
- No per-page dark mode exceptions
- No animated transition between modes (instant swap — keeps it simple and performant)

### Settings Page UI

- **Auto-detect OS preference** — Checkbox (default: checked). "Automatically use dark mode when the user's operating system is set to dark mode."
- **Default mode** — Radio: Light (default), Dark. "Used when auto-detect is off or when the user hasn't set a preference."
- **Show toggle in admin bar** — Checkbox (default: checked). "Show the moon/sun icon in the admin bar for quick switching."

### Edge Cases

- **Color scheme conflicts:** WordPress has built-in admin color schemes (Settings → Admin Color Scheme). Our dark mode overrides these. If a user has a custom color scheme, dark mode overlays on top. This is acceptable since dark mode is user-togglable.
- **Third-party plugin pages:** Pages from WooCommerce, Elementor, ACF etc. may have their own CSS. Our dark mode applies base-level dark backgrounds and text colors. Some elements may look imperfect. This is acceptable for v1.
- **Print stylesheets:** Dark mode should NOT apply to `@media print`. Add a reset in print media query.
- **Multisite:** Dark mode preference is per-user, per-site (user meta is site-specific by default).

### Verification Steps

1. Toggle Dark Mode ON
2. See the moon icon (🌙) appear in the admin bar
3. Click it → entire admin switches to dark theme instantly. Icon changes to sun (☀️)
4. Navigate to different admin pages → dark mode persists
5. Click sun → back to light mode
6. Log out, log back in → dark mode preference is remembered
7. Set OS to dark mode + enable auto-detect → admin loads in dark mode automatically
8. Disable the module → admin returns to default WordPress styling, toggle disappears
9. Check Posts list table, Dashboard, Settings pages — all have readable dark styling
10. Check that form inputs (text fields, dropdowns) are readable in dark mode

---

## Module 7: Database Cleanup

**One-line:** Remove bloat from the WordPress database: post revisions, trashed posts, spam comments, expired transients, and orphaned metadata.

### What the User Sees

- In WPT settings, a dashboard showing current database bloat with counts and estimated sizes:
  - Post revisions: 1,247 (est. 4.2 MB)
  - Auto-drafts: 23
  - Trashed posts: 15
  - Spam comments: 892
  - Trashed comments: 45
  - Expired transients: 234
  - Orphaned post meta: 67
  - Orphaned comment meta: 12
  - Orphaned relationship data: 8
- A "Clean All" button that removes everything with one click
- Individual "Clean" buttons per category
- A summary after cleanup: "Removed 2,543 items. Estimated space freed: 8.7 MB."

### Settings

```
database-cleanup: {
    enabled: true,
    items_to_clean: {
        revisions: true,
        auto_drafts: true,
        trashed_posts: true,
        spam_comments: true,
        trashed_comments: true,
        expired_transients: true,
        orphaned_postmeta: true,
        orphaned_commentmeta: true,
        orphaned_relationships: true,
    },
    keep_recent_revisions: 0,      // Keep last N revisions per post (0 = delete all)
    optimize_tables: false,         // Run OPTIMIZE TABLE after cleanup
}
```

### WordPress Hooks Used

| Hook | Type | Purpose |
|------|------|---------|
| `wp_ajax_wpt_db_cleanup_scan` | action | AJAX: scan and return counts |
| `wp_ajax_wpt_db_cleanup_run` | action | AJAX: execute cleanup |
| `admin_enqueue_scripts` | action | Load JS for the cleanup UI on WPT settings page only |

### Exact Behavior — Cleanup Queries

Each cleanup task uses `$wpdb` with prepared statements:

**Post revisions:**
```sql
-- If keep_recent_revisions = 0:
DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'

-- If keep_recent_revisions = N:
-- For each post, delete revisions beyond the most recent N
-- Uses a subquery to identify revisions to keep
DELETE r FROM {$wpdb->posts} r
WHERE r.post_type = 'revision'
AND r.ID NOT IN (
    SELECT ID FROM (
        SELECT ID FROM {$wpdb->posts}
        WHERE post_type = 'revision' AND post_parent = r.post_parent
        ORDER BY post_date DESC
        LIMIT %d
    ) AS recent
)
```

**Auto-drafts:**
```sql
DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'
```

**Trashed posts:**
```sql
DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'
-- Also clean their meta:
DELETE pm FROM {$wpdb->postmeta} pm
LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
WHERE p.ID IS NULL
```

**Spam comments:**
```sql
DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'
```

**Trashed comments:**
```sql
DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'
```

**Expired transients:**
```sql
DELETE FROM {$wpdb->options}
WHERE option_name LIKE '_transient_timeout_%'
AND option_value < %d  -- current unix timestamp

DELETE FROM {$wpdb->options}
WHERE option_name LIKE '_transient_%'
AND option_name NOT LIKE '_transient_timeout_%'
AND option_name NOT IN (
    SELECT REPLACE(option_name, '_transient_timeout_', '_transient_')
    FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_timeout_%'
    AND option_value >= %d
)
```

**Orphaned post meta:**
```sql
DELETE pm FROM {$wpdb->postmeta} pm
LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
WHERE p.ID IS NULL
```

**Orphaned comment meta:**
```sql
DELETE cm FROM {$wpdb->commentmeta} cm
LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
WHERE c.comment_ID IS NULL
```

**Orphaned relationship data:**
```sql
DELETE tr FROM {$wpdb->term_relationships} tr
LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
WHERE p.ID IS NULL
```

**Table optimization (optional):**
```sql
OPTIMIZE TABLE {$wpdb->posts}, {$wpdb->postmeta}, {$wpdb->comments},
    {$wpdb->commentmeta}, {$wpdb->options}, {$wpdb->term_relationships}
```

**Scan (count) approach:**
Each scan query is a `SELECT COUNT(*)` version of the delete query above. This shows the user what will be deleted before they click Clean.

**Size estimation:**
For the "est. X MB" display, we can't get exact per-row sizes easily. Instead, after scanning counts, estimate based on averages:
- Revisions: ~3KB per revision (post content average)
- Comments: ~500 bytes per comment
- Meta rows: ~200 bytes each
- Transients: ~500 bytes each

These are rough estimates shown as "est." — acceptable for a UX indicator.

### What This Does NOT Do (v1)

- No scheduled automatic cleanup (that's a Pro feature for later)
- No WP-Cron integration
- No cleanup of custom tables from other plugins
- No database table repair (only OPTIMIZE)
- No export-before-delete safety backup

### Settings Page UI

The cleanup page is a dashboard-style layout within the WPT settings:

- **Scan Results** — Table showing each category, count, estimated size, and a "Clean" button per row
- **Clean All** — Button at the top: "Clean All Selected" (respects the checkboxes in items_to_clean settings)
- **Keep Recent Revisions** — Number input (default: 0). "Keep the most recent N revisions per post. Set to 0 to delete all revisions."
- **Optimize Tables** — Checkbox (default: unchecked). "Run OPTIMIZE TABLE after cleanup. This can take several seconds on large databases."
- **Results** — After cleanup, show: "Cleaned: 1,247 revisions, 892 spam comments, 234 transients. Estimated space freed: 8.7 MB."

### Edge Cases

- **Large databases:** If there are 100K+ revisions, the DELETE query could time out. For v1, we run them in batches of 1,000 rows with `LIMIT 1000` in a loop. Show progress via AJAX polling.
- **Multisite:** Each site has its own tables. Cleanup runs on the current site only.
- **InnoDB vs MyISAM:** `OPTIMIZE TABLE` works differently on each. For InnoDB (WordPress default), it recreates the table. This can take time and briefly lock the table. The "may take several seconds" warning is important.
- **Transient cleanup race condition:** Some transients may be actively used. Only cleaning expired ones (timeout value < current time) prevents issues.
- **wp_options autoload:** This module doesn't clean non-transient options. That's a separate concern.

### Verification Steps

1. Toggle Database Cleanup ON
2. Go to the cleanup section in WPT settings
3. See counts for each category (revisions, spam, etc.)
4. Click "Clean" next to spam comments → count goes to 0, success message shown
5. Click "Clean All" → all categories cleaned, total count shown
6. Check the database directly → confirm rows are actually deleted
7. Create a post, add a revision, set keep_recent_revisions to 1, run cleanup → the one most recent revision remains
8. Toggle "Optimize Tables" and run → no errors, tables are optimized
9. Disable the module → cleanup UI disappears but cleaned data stays cleaned (not restored)

---

## Module 8: Heartbeat Control

**One-line:** Control the WordPress Heartbeat API frequency to reduce server load or disable it entirely on specific admin pages.

### What the User Sees

- In WPT settings, three areas to control: Dashboard, Post Editor, Frontend
- For each area: a dropdown with options: Default, Modified Frequency (15s / 30s / 60s / 120s), or Disabled
- A brief explanation of what Heartbeat does and why you might want to change it

### Settings

```
heartbeat-control: {
    enabled: true,
    dashboard: 'default',         // 'default' | '15' | '30' | '60' | '120' | 'disabled'
    post_editor: 'default',       // 'default' | '15' | '30' | '60' | '120' | 'disabled'
    frontend: 'disabled',         // 'default' | '15' | '30' | '60' | '120' | 'disabled'
}
```

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `init` | action | 10 | Deregister heartbeat script if disabled for current context |
| `heartbeat_settings` | filter | 10 | Modify heartbeat interval |
| `wp_enqueue_scripts` | action | 99 | Deregister heartbeat on frontend if disabled |
| `admin_enqueue_scripts` | action | 99 | Deregister heartbeat in admin if disabled |

### Exact Behavior

**Determining the current context:**
```php
function wpt_get_heartbeat_context() {
    if (!is_admin()) return 'frontend';
    
    global $pagenow;
    if ($pagenow === 'post.php' || $pagenow === 'post-new.php') return 'post_editor';
    
    return 'dashboard'; // All other admin pages
}
```

**Disabling heartbeat:**
```php
add_action('admin_enqueue_scripts', function() {
    $context = wpt_get_heartbeat_context();
    $setting = $settings[$context] ?? 'default';
    if ($setting === 'disabled') {
        wp_deregister_script('heartbeat');
    }
}, 99);

add_action('wp_enqueue_scripts', function() {
    if ($settings['frontend'] === 'disabled') {
        wp_deregister_script('heartbeat');
    }
}, 99);
```

**Modifying frequency:**
```php
add_filter('heartbeat_settings', function($settings_array) {
    $context = wpt_get_heartbeat_context();
    $setting = $settings[$context] ?? 'default';
    
    if ($setting !== 'default' && $setting !== 'disabled') {
        $settings_array['interval'] = (int)$setting;
    }
    
    return $settings_array;
});
```

### Important Warning

**Disabling heartbeat in the Post Editor disables auto-save and post locking.** The settings page must show a warning:

> ⚠️ **Post Editor:** Disabling Heartbeat in the post editor will disable auto-save and post locking (the feature that warns when two users edit the same post). We recommend setting it to 60 or 120 seconds instead of disabling it entirely.

**Disabling on the Dashboard** is safe — it just stops the real-time updates to the dashboard widgets.

**Disabling on the Frontend** is safe and recommended — most sites don't need it, and it fires on every frontend page load for logged-in users.

### What This Does NOT Do (v1)

- No per-page control (only per-context: dashboard/editor/frontend)
- No monitoring of heartbeat's actual server impact
- No per-role settings

### Settings Page UI

- **Dashboard** — Dropdown: Default (WordPress default ~15-60s), 15 seconds, 30 seconds, 60 seconds, 120 seconds, Disabled
- **Post Editor** — Dropdown: Same options + warning text about auto-save
- **Frontend** — Dropdown: Same options. Default: Disabled. "The Heartbeat API runs on every frontend page for logged-in users. Disabling it reduces server load."
- **Explainer text:** "The Heartbeat API sends requests to your server at regular intervals. It powers auto-save, post locking, and real-time dashboard updates. Reducing the frequency or disabling it on pages where it's not needed can significantly reduce server load, especially on shared hosting."

### Verification Steps

1. Toggle Heartbeat Control ON
2. Set Frontend to "Disabled" → visit frontend logged in → check browser Network tab → no heartbeat requests
3. Set Dashboard to "60" → visit Dashboard → check Network tab → heartbeat fires every ~60 seconds (not default ~15)
4. Set Post Editor to "Disabled" → edit a post → auto-save does NOT occur, warning was shown in settings
5. Set Post Editor back to "60" → edit a post → auto-save works, but at 60s intervals
6. Disable the module → heartbeat returns to WordPress defaults everywhere

---

## Module 9: Disable Comments

**One-line:** Disable the WordPress comment system entirely, or selectively per post type.

### What the User Sees

- In WPT settings: a master toggle to disable comments site-wide, or checkboxes per post type
- When enabled:
  - The "Comments" admin menu item disappears
  - The "Comments" column disappears from post list tables
  - The comment form disappears from the frontend
  - The comments count in the admin bar disappears
  - Discussion settings and metaboxes are hidden
  - Existing comments are NOT deleted (just hidden from display)

### Settings

```
disable-comments: {
    enabled: true,
    mode: 'everywhere',            // 'everywhere' | 'per_post_type'
    disabled_post_types: ['post', 'page'],  // Only used if mode = 'per_post_type'
    hide_existing: true,           // Hide existing comments from frontend
    remove_from_admin: true,       // Remove Comments menu, columns, metaboxes
}
```

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `comments_open` | filter | 20 | Return false to close comments |
| `pings_open` | filter | 20 | Return false to close pings/trackbacks |
| `comments_array` | filter | 20 | Return empty array to hide existing comments |
| `admin_menu` | action | 999 | Remove Comments menu item |
| `wp_before_admin_bar_render` | action | 999 | Remove Comments from admin bar |
| `admin_init` | action | 10 | Remove comments metabox, discussion settings, dashboard widget |
| `add_meta_boxes` | action | 999 | Remove comments metabox from post editor |
| `admin_enqueue_scripts` | action | 10 | Hide comments column via CSS (backup approach) |
| `wp_headers` | filter | 10 | Remove X-Pingback header |
| `xmlrpc_methods` | filter | 10 | Remove pingback XML-RPC methods |
| `template_redirect` | action | 10 | Redirect comments feed URLs to home |
| `init` | action | 10 | Remove comments support from post types |

### Exact Behavior

**Close comments on all (or selected) post types:**
```php
add_filter('comments_open', function($open, $post_id) {
    $post_type = get_post_type($post_id);
    if ($settings['mode'] === 'everywhere') return false;
    if (in_array($post_type, $settings['disabled_post_types'])) return false;
    return $open;
}, 20, 2);

add_filter('pings_open', function($open, $post_id) {
    // Same logic as above
}, 20, 2);
```

**Hide existing comments from frontend:**
```php
if ($settings['hide_existing']) {
    add_filter('comments_array', function($comments, $post_id) {
        $post_type = get_post_type($post_id);
        if ($settings['mode'] === 'everywhere') return [];
        if (in_array($post_type, $settings['disabled_post_types'])) return [];
        return $comments;
    }, 20, 2);
}
```

**Remove from admin:**
```php
// Remove Comments menu
add_action('admin_menu', function() {
    remove_menu_page('edit-comments.php');
}, 999);

// Remove Comments from admin bar
add_action('wp_before_admin_bar_render', function() {
    global $wp_admin_bar;
    $wp_admin_bar->remove_node('comments');
});

// Remove Comments metabox from post editor
add_action('add_meta_boxes', function() {
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $pt) {
        remove_meta_box('commentstatusdiv', $pt, 'normal');
        remove_meta_box('commentsdiv', $pt, 'normal');
    }
}, 999);

// Remove Discussion settings
add_action('admin_init', function() {
    // Redirect discussion settings page to general settings
    global $pagenow;
    if ($pagenow === 'options-discussion.php') {
        wp_safe_redirect(admin_url('options-general.php'));
        exit;
    }
});

// Remove comments column from post list tables
add_filter('manage_posts_columns', function($columns) {
    unset($columns['comments']);
    return $columns;
});
add_filter('manage_pages_columns', function($columns) {
    unset($columns['comments']);
    return $columns;
});

// Remove dashboard comments widget
add_action('wp_dashboard_setup', function() {
    remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
});
```

**Remove X-Pingback header:**
```php
add_filter('wp_headers', function($headers) {
    unset($headers['X-Pingback']);
    return $headers;
});
```

**Remove comment support from post types:**
```php
add_action('init', function() {
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $pt) {
        if ($settings['mode'] === 'everywhere' || in_array($pt, $settings['disabled_post_types'])) {
            remove_post_type_support($pt, 'comments');
            remove_post_type_support($pt, 'trackbacks');
        }
    }
}, 100);
```

### What This Does NOT Do (v1)

- Does NOT delete existing comments (they remain in the database, just hidden)
- Does NOT disable the Comments REST API endpoints (they return empty results)
- Does NOT affect WooCommerce product reviews unless "product" is in disabled_post_types

### Settings Page UI

- **Mode** — Radio: Disable comments everywhere (default), Disable per post type
- **Post Types** (shown only if "per post type" selected) — Checkboxes for each public post type
- **Hide Existing Comments** — Checkbox (default: checked). "Hide previously posted comments from the frontend. Comments are not deleted."
- **Remove from Admin** — Checkbox (default: checked). "Remove the Comments menu, admin bar counter, and discussion metaboxes."
- **Note:** "Existing comments are preserved in the database. If you re-enable comments, they will reappear."

### Edge Cases

- **WooCommerce reviews:** Product reviews use the WordPress comment system. If "product" post type is selected, reviews are disabled too. Show a note: "Disabling comments for Products will also disable product reviews."
- **Comment RSS feeds:** Redirect `/comments/feed/` to homepage when comments are disabled.
- **REST API:** The `/wp/v2/comments` endpoint still exists but returns empty results when comments are closed. Acceptable for v1.
- **BuddyPress/bbPress:** These use their own comment/discussion systems. This module doesn't affect them.

### Verification Steps

1. Toggle Disable Comments ON with "everywhere" mode
2. Visit a post on the frontend → no comment form, no existing comments shown
3. Go to admin → Comments menu is gone
4. Edit a post → no Discussion metabox
5. Check admin bar → no comments counter
6. Visit `wp-admin/edit-comments.php` directly → redirected away
7. Switch to "per post type" mode, only disable for Pages → post comments still work, page comments disabled
8. Check the database → existing comments are still there (just hidden)
9. Disable the module → comments reappear everywhere, including existing ones

---

## Module 10: Email SMTP

**One-line:** Configure WordPress to send emails via SMTP instead of the default PHP `mail()` function, with a test email feature.

### What the User Sees

- In WPT settings, an SMTP configuration form with:
  - From Email, From Name
  - SMTP Host, Port, Encryption (None / SSL / TLS), Authentication toggle
  - Username, Password (masked)
  - A "Send Test Email" button that sends a test to a specified address
  - A success/failure message after the test
- Once configured, ALL WordPress emails (password resets, notifications, plugin emails, WooCommerce emails, etc.) route through the configured SMTP server

### Settings

```
email-smtp: {
    enabled: true,
    from_email: 'hello@example.com',
    from_name: 'My Site',
    smtp_host: 'smtp.gmail.com',
    smtp_port: 587,
    encryption: 'tls',              // 'none' | 'ssl' | 'tls'
    authentication: true,
    username: 'hello@example.com',
    password: '',                    // Encrypted in database
    force_from: true,               // Override From in all emails (prevent plugins from changing it)
}
```

**Password storage:**
The SMTP password must be stored encrypted, not in plain text. Use `wp_encrypt()` if available (WP 6.x+), or a simple encrypt/decrypt using `AUTH_KEY` salt from wp-config.php:
```php
function wpt_encrypt_password($plain) {
    $key = substr(hash('sha256', AUTH_KEY), 0, 32);
    $iv = substr(hash('sha256', SECURE_AUTH_KEY), 0, 16);
    return base64_encode(openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv));
}

function wpt_decrypt_password($encrypted) {
    $key = substr(hash('sha256', AUTH_KEY), 0, 32);
    $iv = substr(hash('sha256', SECURE_AUTH_KEY), 0, 16);
    return openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, $iv);
}
```

### WordPress Hooks Used

| Hook | Type | Priority | Purpose |
|------|------|----------|---------|
| `phpmailer_init` | action | 10 | Configure PHPMailer with SMTP settings |
| `wp_mail_from` | filter | 999 | Override From email |
| `wp_mail_from_name` | filter | 999 | Override From name |
| `wp_ajax_wpt_send_test_email` | action | 10 | AJAX handler for test email |

### Exact Behavior

**SMTP Configuration:**

WordPress uses PHPMailer internally. The `phpmailer_init` action gives us direct access to configure it:

```php
add_action('phpmailer_init', function($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host = $settings['smtp_host'];
    $phpmailer->Port = (int) $settings['smtp_port'];
    
    // Encryption
    if ($settings['encryption'] === 'tls') {
        $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($settings['encryption'] === 'ssl') {
        $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $phpmailer->SMTPSecure = '';
        $phpmailer->SMTPAutoTLS = false;
    }
    
    // Authentication
    if ($settings['authentication']) {
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $settings['username'];
        $phpmailer->Password = wpt_decrypt_password($settings['password']);
    } else {
        $phpmailer->SMTPAuth = false;
    }
});
```

**From email/name override:**
```php
if ($settings['force_from'] && $settings['from_email']) {
    add_filter('wp_mail_from', function() use ($settings) {
        return $settings['from_email'];
    }, 999);
    
    add_filter('wp_mail_from_name', function() use ($settings) {
        return $settings['from_name'] ?: get_bloginfo('name');
    }, 999);
}
```

**Test email:**
```php
add_action('wp_ajax_wpt_send_test_email', function() {
    check_ajax_referer('wpt_test_email_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));
    if (!is_email($to)) {
        wp_send_json_error('Invalid email address');
    }
    
    $subject = 'WPTransformed — Test Email';
    $message = sprintf(
        "This is a test email from WPTransformed on %s.\n\nIf you received this, your SMTP settings are working correctly.\n\nSent at: %s",
        get_bloginfo('name'),
        current_time('mysql')
    );
    
    // Enable PHPMailer debugging for the test
    add_action('phpmailer_init', function($phpmailer) {
        $phpmailer->SMTPDebug = 2;
        $phpmailer->Debugoutput = function($str, $level) {
            // Capture debug output
            global $wpt_smtp_debug;
            $wpt_smtp_debug .= $str;
        };
    }, 999);
    
    global $wpt_smtp_debug;
    $wpt_smtp_debug = '';
    
    $result = wp_mail($to, $subject, $message);
    
    if ($result) {
        wp_send_json_success([
            'message' => 'Test email sent successfully to ' . $to,
        ]);
    } else {
        global $phpmailer;
        $error = '';
        if (isset($phpmailer) && $phpmailer->ErrorInfo) {
            $error = $phpmailer->ErrorInfo;
        }
        wp_send_json_error([
            'message' => 'Failed to send test email.',
            'error' => $error,
            'debug' => $wpt_smtp_debug,
        ]);
    }
});
```

### Common SMTP Configurations (for documentation/help text)

| Provider | Host | Port | Encryption |
|----------|------|------|------------|
| Gmail / Google Workspace | smtp.gmail.com | 587 | TLS |
| Outlook / Office 365 | smtp.office365.com | 587 | TLS |
| SendGrid | smtp.sendgrid.net | 587 | TLS |
| Mailgun | smtp.mailgun.org | 587 | TLS |
| Amazon SES | email-smtp.{region}.amazonaws.com | 587 | TLS |
| Zoho Mail | smtp.zoho.com | 587 | TLS |

### What This Does NOT Do (v1)

- No email logging (no record of sent emails)
- No email resend feature
- No HTML email templates
- No per-plugin email configuration
- No fallback to PHP mail() if SMTP fails (WordPress will report the error natively)
- No OAuth2 authentication (username/password only)
- No multiple SMTP profiles

### Settings Page UI

- **From Email** — Text input. "The email address that WordPress emails are sent from."
- **From Name** — Text input. Default: site name. "The name that appears as the sender."
- **Force From** — Checkbox (default: checked). "Override the From address on ALL WordPress emails, even those set by other plugins."
- **SMTP Host** — Text input. "Your SMTP server hostname."
- **SMTP Port** — Number input. Default: 587.
- **Encryption** — Dropdown: None, SSL, TLS (default).
- **Authentication** — Checkbox (default: checked). "SMTP server requires authentication."
- **Username** — Text input (shown when auth is on).
- **Password** — Password input (shown when auth is on). Masked. Never displayed after saving — only shows "••••••••" with a "Change" button.
- **Common providers** — Expandable help section with the table above.
- **Test Email** — Text input for recipient address + "Send Test Email" button. Shows success/failure with error details on failure.

### Edge Cases

- **Password security:** Stored encrypted with `AUTH_KEY`. If `AUTH_KEY` changes (site migration), the password becomes unreadable. The settings page shows "SMTP password needs to be re-entered" when decryption fails.
- **OpenSSL not available:** If `openssl_encrypt` isn't available, fall back to base64 encoding with a visible warning: "Passwords stored with basic encoding. Install the OpenSSL PHP extension for encrypted storage."
- **Gmail "Less Secure Apps":** Gmail blocks plain SMTP unless the user creates an App Password. Show a note: "Gmail users: You may need to create an App Password in your Google Account security settings."
- **Hosting restrictions:** Some hosts (like GoDaddy) block outgoing port 25 and 587. The test email feature helps diagnose this with the SMTP debug output.
- **Multisite:** SMTP settings are per-site. Each site in a multisite network has its own SMTP configuration.
- **WP_MAIL_SMTP conflicts:** If WP Mail SMTP or similar plugin is active, both will try to configure PHPMailer. Our hook runs at default priority (10). If there's a conflict, the user should deactivate the other plugin. We don't detect or warn about this in v1.

### Verification Steps

1. Toggle Email SMTP ON
2. Configure with valid SMTP credentials (e.g., Gmail + App Password)
3. Enter a test email address and click "Send Test Email" → email arrives ✓
4. Check the email headers → From address matches configured value
5. Trigger a WordPress email (e.g., reset password) → it arrives via SMTP
6. Enter incorrect SMTP password → Send Test Email → clear error message shown with SMTP debug info
7. Enter incorrect host → Send Test Email → timeout error shown
8. Disable the module → WordPress reverts to default PHP mail()
9. Password field shows "••••••••" after saving (not the actual password)
10. Check database → password is stored encrypted, not plain text

---

## Appendix: Settings Page Structure

All 10 modules share a single WPT settings page at **Settings → WPTransformed**.

**Layout:**
```
┌─ WPTransformed Settings ─────────────────────────┐
│                                                    │
│  [Module List — left sidebar or top tabs]           │
│                                                    │
│  ┌─ Content Duplication ──── [Toggle ON/OFF] ──┐  │
│  │                                              │  │
│  │  Post Types: ☑ Posts ☑ Pages ☐ Products     │  │
│  │  Clone Options: ☑ Taxonomies ☑ Meta ☑ Image │  │
│  │  Title Suffix: [(Copy)____________]          │  │
│  │  New Status: [Draft ▼]                       │  │
│  │  After Clone: ◉ Post list ○ Open editor      │  │
│  │                                              │  │
│  │  [Save Changes]                              │  │
│  └──────────────────────────────────────────────┘  │
│                                                    │
│  ┌─ Admin Menu Editor ──── [Toggle ON/OFF] ────┐  │
│  │  [Visual menu editor here]                   │  │
│  └──────────────────────────────────────────────┘  │
│                                                    │
│  ... etc for each module ...                       │
└────────────────────────────────────────────────────┘
```

Each module is a collapsible section. The toggle enables/disables the module. Settings within are only relevant when the module is enabled. Disabled modules show a collapsed single line with just the title, description, and toggle.

The settings page is rendered with **native WordPress admin PHP** — no React, no build step. Uses `add_options_page()`, `settings_fields()`, and standard WP admin form patterns. Each module's settings are loaded via AJAX when the section is expanded (to keep initial page load fast).

---

## Appendix: Module Build Order

Build in this exact order. Each module must pass ALL its verification steps before starting the next.

1. **Content Duplication** — Tests core architecture (module loading, settings, hooks)
2. **Hide Admin Notices** — Simple output buffering, validates CSS/JS enqueuing works
3. **SVG Upload** — Tests file upload filters and security sanitization
4. **Clean Admin Bar** — Tests admin bar manipulation
5. **Heartbeat Control** — Tests script deregistration and heartbeat filter
6. **Disable Comments** — Tests multiple hooks working together, menu removal
7. **Dark Mode** — Tests user meta, AJAX, CSS enqueuing, admin bar customization
8. **Admin Menu Editor** — Tests complex settings (arrays/objects), drag-drop JS
9. **Database Cleanup** — Tests direct database queries, AJAX scanning
10. **Email SMTP** — Tests PHPMailer configuration, encrypted settings, external connectivity

This order goes from simplest to most complex, with each module testing a new capability that later modules build on.
