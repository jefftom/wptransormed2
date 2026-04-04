# WPTransformed Wave 5 — Beyond ASE

> 29 modules to surpass ASE and hit 89 total.
> Includes WooCommerce modules (ASE doesn't touch WC), Pro-tier features,
> and unique modules that justify the "we replace 35+ plugins" claim.
> Build with `/batch` — split into 3 batches of ~10.

---

## Batch 5A: WooCommerce & Advanced Content (10 modules)

| # | Module ID | Category | One-Line | Tier |
|---|-----------|----------|----------|------|
| 61 | woo-admin-cleanup | woocommerce | Remove WooCommerce admin bloat (dashboard widgets, nags, unused menus) | Free |
| 62 | woo-custom-statuses | woocommerce | Add custom order statuses with colors and email triggers | Pro |
| 63 | woo-login-redirect | woocommerce | Redirect customers to My Account or Shop after login | Free |
| 64 | woo-disable-reviews | woocommerce | Disable product reviews site-wide or per product | Free |
| 65 | woo-empty-cart-button | woocommerce | Add "Empty Cart" button to cart page | Free |
| 66 | bulk-edit-posts | content-management | Bulk edit custom fields, categories, status for multiple posts | Free |
| 67 | duplicate-menu | content-management | One-click duplicate any WordPress nav menu | Free |
| 68 | post-type-switcher | content-management | Change a post from one post type to another without losing data | Free |
| 69 | custom-body-class | custom-code | Add custom CSS classes to body tag per page/post or globally | Free |
| 70 | ads-txt-manager | custom-code | Manage ads.txt and app-ads.txt from the admin | Free |

### Module 61: WooCommerce Admin Cleanup
**Settings:** `{ remove_marketplace: true, remove_connect_nag: true, remove_dashboard_widgets: true, hide_unused_menus: ['wc-admin', 'wc-reports'], remove_order_nags: true }`
**Hooks:** `woocommerce_admin_features` filter → remove 'marketing', 'analytics'. `admin_menu` priority 999 → `remove_menu_page()` for selected items. `wp_dashboard_setup` → remove WC dashboard widgets. Check `is_plugin_active('woocommerce/woocommerce.php')` — module auto-disables if WC not active.
**Verification:** WooCommerce active → enable module → Marketing menu gone, dashboard widgets gone, connect nag gone.

### Module 62: WooCommerce Custom Order Statuses
**Settings:** `{ statuses: [{ slug: 'wc-awaiting-parts', label: 'Awaiting Parts', color: '#f0ad4e', send_email: true, email_template: '' }] }`
**Hooks:** `init` → `register_post_status()` for each custom status. `wc_order_statuses` filter → add custom statuses to WC dropdown. `woocommerce_email_classes` → register email classes for custom statuses. Admin page: CRUD for custom statuses with color picker.
**Pro Tier:** Requires Freemius or custom licensing check.
**Verification:** Create "Awaiting Parts" status → change order to it → status shows with custom color → email sends (if configured).

### Module 63: WooCommerce Login Redirect
**Settings:** `{ customer_redirect: 'my_account', redirect_url: '' }` (options: my_account, shop, cart, custom_url)
**Hooks:** `woocommerce_login_redirect` filter → return configured URL based on user role (only apply to customers, not admins). `woocommerce_registration_redirect` filter → same.
**Verification:** Log in as customer → lands on My Account (or configured page) → admin login unaffected.

### Module 64: WooCommerce Disable Reviews
**Settings:** `{ disable_all: true, disable_per_product: false }`
**Hooks:** If `disable_all`: `add_filter('woocommerce_product_tabs', function($tabs) { unset($tabs['reviews']); return $tabs; })`. Remove `comments_open` for products: `add_filter('comments_open', '__return_false', 20, 2)` where post_type is product. Also `remove_post_type_support('product', 'comments')`.
**Verification:** Disable all → product pages have no review tab → existing reviews hidden → re-enable → reviews return.

### Module 65: WooCommerce Empty Cart Button
**Settings:** `{ button_text: 'Empty Cart', button_style: 'link', confirm: true }`
**Hooks:** `woocommerce_cart_actions` or `woocommerce_after_cart` → output button. Button submits to `?wpt_empty_cart=1`. `template_redirect` → if `wpt_empty_cart` set, verify nonce → `WC()->cart->empty_cart()` → redirect to cart. JS confirm dialog if `confirm` setting is on.
**Verification:** Add items to cart → "Empty Cart" button appears → click → confirm dialog → cart emptied → redirect to empty cart page.

### Module 66: Bulk Edit Posts
**Settings:** `{ enabled_for: ['post', 'page'], custom_fields: [] }`
**Hooks:** Extend WordPress bulk edit screen. `bulk_edit_custom_box` → add custom field inputs to bulk edit row. `save_post` during bulk edit → apply custom field values from `$_REQUEST`. Support: status change, category assign/remove, custom field set, author change, date change for multiple posts at once.
**Edge Cases:** Don't overwrite fields that weren't changed (empty field ≠ clear field). ACF fields → detect and include in bulk edit if configured.
**Verification:** Select 5 posts → Bulk Edit → change category → all 5 updated. Change custom field → all 5 updated.

### Module 67: Duplicate Menu
**Settings:** `{ enabled: true }`
**Hooks:** `admin_footer-nav-menus.php` → inject "Duplicate" button next to each menu. AJAX handler: read all `wp_get_nav_menu_items($menu_id)`, create new menu via `wp_create_nav_menu($name . ' (Copy)')`, iterate items and `wp_update_nav_menu_item()` for each, preserving parent/child relationships and classes.
**Edge Cases:** Nested menu items → preserve hierarchy (process parents first, map old→new IDs for children). Menu locations → don't assign duplicate to any location. Custom link items vs. page/post items → both should copy.
**Verification:** Go to Menus → click "Duplicate" on primary menu → new menu "Primary Menu (Copy)" created with identical items and hierarchy.

### Module 68: Post Type Switcher
**Settings:** `{ enabled: true, allowed_switches: {} }`
**Hooks:** Add "Post Type" dropdown to the Publish metabox showing all public post types. On save: `wp_update_post(['ID' => $post_id, 'post_type' => $new_type])`. Also add bulk action: "Change Post Type" dropdown in list table.
**Edge Cases:** Taxonomy compatibility — if new post type doesn't support the old taxonomies, warn but keep terms in DB. Meta fields → preserved regardless (they're post meta, not type-specific). Permalinks → may change based on new type's rewrite rules. Flush rewrite rules after switch.
**Verification:** Create a Post → switch to Page → URL changes to page format → content and meta preserved → switch back → works.

### Module 69: Custom Body Class
**Settings:** `{ global_classes: '', per_page_field: true }`
**Hooks:** `body_class` filter → add global classes from settings. If `per_page_field`, add metabox to post editor with "Custom Body Classes" text input → stored in post meta `_wpt_body_class` → added to body_class filter on frontend.
**Verification:** Set global class "agency-site" → every page has `agency-site` in body tag → set per-page class "hero-layout" on About page → only About page has it.

### Module 70: Ads.txt Manager
**Settings:** `{ ads_txt: '', app_ads_txt: '' }`
**Hooks:** `init` → if request is for `/ads.txt` or `/app-ads.txt`, output saved content with `text/plain` header, `exit`. Settings page: two textarea fields with CodeMirror (plain text mode). Validate format: each line should be `domain, pub-id, relationship, cert-id` or a comment.
**Edge Cases:** Don't conflict with actual `ads.txt` file in web root — check `file_exists(ABSPATH . 'ads.txt')` and warn. If a real file exists, virtual version won't override it (Apache/Nginx serves static files first).
**Verification:** Add ad network entry → visit `/ads.txt` → entry appears → edit → changes reflected → app-ads.txt works too.

---

## Batch 5B: Pro Features & Advanced Tools (10 modules)

| # | Module ID | Category | One-Line | Tier |
|---|-----------|----------|----------|------|
| 71 | admin-columns-pro | admin-interface | Drag-and-drop column manager with ACF field support and horizontal scroll | Pro |
| 72 | captcha-protection | security | reCAPTCHA v2/v3, hCaptcha, Turnstile on login, register, comments | Pro |
| 73 | form-builder | utilities | Basic form builder with drag-and-drop, email notifications, entries storage | Pro |
| 74 | file-manager | utilities | Server file browser in admin with edit, upload, download, permissions | Pro |
| 75 | multiple-user-roles | security | Assign multiple roles to a single user | Free |
| 76 | view-as-role | admin-interface | Temporarily switch to another role to test permissions | Free |
| 77 | local-user-avatar | content-management | Upload custom avatars instead of Gravatar | Free |
| 78 | system-summary | utilities | Dashboard page showing server info, WP config, active plugins, PHP info | Free |
| 79 | robots-txt-manager | custom-code | Edit robots.txt from admin without FTP | Free |
| 80 | email-log | utilities | Log all emails sent by WordPress with resend capability | Pro |

### Module 71: Admin Columns Pro
**Settings:** `{ columns: { post: [{ type: 'acf_field', field: 'price', width: 100 }, ...] }, enable_horizontal_scroll: true }`
**Hooks:** Full column management: add, remove, reorder columns per post type. Support ACF fields, taxonomy terms, custom meta. Horizontal scroll: CSS `overflow-x: auto` on `.wp-list-table` wrapper with sticky first column. Admin page: visual column configurator with drag-and-drop ordering and width control.
**Pro Tier:** ACF field columns, horizontal scroll, and column width control are Pro features.
**Verification:** Configure columns for Posts → add ACF "Price" column → visible on post list → drag to reorder → horizontal scroll works with many columns.

### Module 72: CAPTCHA Protection
**Settings:** `{ provider: 'turnstile', site_key: '', secret_key: '', enable_on: ['login', 'register', 'comments', 'lost_password'] }`
**Hooks:** Support 3 providers: Google reCAPTCHA v2/v3, hCaptcha, Cloudflare Turnstile. `login_form`, `register_form`, `comment_form` actions → output CAPTCHA widget. `authenticate`, `registration_errors`, `pre_comment_on_post` filters → verify CAPTCHA via HTTP POST to provider. Provider-specific JS loaded only on pages with forms.
**Pro Tier:** Multiple provider support and custom placement are Pro.
**Verification:** Configure Turnstile → login page shows challenge → invalid response blocks login → valid response allows login.

### Module 73: Form Builder (Basic)
**Settings:** Forms stored in custom table. Not full NexusForms — simplified version.
**Implementation:** Custom DB table `wpt_forms` (id, title, fields JSON, settings JSON, created_at). Custom table `wpt_form_entries` (id, form_id, data JSON, ip, created_at). Admin page for form CRUD. PHP-rendered form builder (drag-and-drop field list with vanilla JS). 10 field types: text, email, textarea, select, checkbox, radio, phone, number, date, file upload. Shortcode `[wpt_form id="X"]` + Gutenberg block. Email notifications with merge tags. Entries list table with CSV export. Honeypot + time-based spam protection.
**Pro Tier:** Form builder, file upload field, multi-step, conditional logic are Pro.
**Verification:** Create contact form → embed via shortcode → submit → entry appears in admin → notification email received.

### Module 74: File Manager
**Settings:** `{ root_dir: 'wp-content', allowed_extensions: ['php', 'css', 'js', 'txt', 'html', 'json'], max_upload_size: 10 }`
**Implementation:** Admin page with file/folder tree (vanilla JS tree view). Operations: browse, view, edit (CodeMirror), upload, download, rename, delete, create file/folder, change permissions (chmod). Root restricted to `wp-content` by default — never allow access above WordPress root. All operations via AJAX with nonce + capability check.
**Pro Tier:** Edit and write operations are Pro. Browse is Free.
**Edge Cases:** NEVER allow editing `wp-config.php`. Backup file before editing. File locking during edit. Max file size for editing (2MB). Binary files → download only, no edit.
**Verification:** Open File Manager → browse wp-content/themes → edit style.css → save → verify change on frontend → create new file → download it.

### Module 75: Multiple User Roles
**Settings:** `{ enabled: true }`
**Hooks:** `edit_user_profile` + `show_user_profile` → add multi-select role dropdown (replacing single dropdown). `profile_update` → `$user->set_role('')` then `$user->add_role()` for each selected role. Display on Users list: show all roles comma-separated.
**Edge Cases:** Always keep at least one role assigned. Removing Administrator from a user who has it → confirm dialog. Don't break WooCommerce's Customer role assignment on purchase.
**Verification:** Edit user → assign Editor + Author → user has capabilities of both → Users list shows "Editor, Author."

### Module 76: View as Role
**Settings:** `{ enabled: true, allowed_roles: [] }`
**Implementation:** Admin bar button "View as: [Role Dropdown]". On selection: store original role in user meta `_wpt_original_role`. Apply new role via `user_has_cap` filter (don't actually change the DB role). Show persistent "You are viewing as [Role]" bar with "Switch Back" button. Auto-expire after 1 hour (check timestamp). Only `manage_options` users can use this.
**Verification:** As admin → select "View as Editor" → admin menus change to Editor view → can't access Plugins page → click "Switch Back" → full admin restored.

### Module 77: Local User Avatar
**Settings:** `{ enabled: true, disable_gravatar: false, default_avatar: '' }`
**Hooks:** `get_avatar_url` filter → if user has local avatar stored, return that URL instead of Gravatar. Add "Upload Avatar" button to user profile page using `wp_media_uploader`. Store attachment ID in user meta `_wpt_avatar`. If `disable_gravatar` → all external Gravatar requests blocked (privacy benefit). Default avatar: fallback for users without custom avatar.
**Verification:** Upload avatar on profile → avatar appears everywhere (comments, admin bar, user list) → disable Gravatar → no external requests to gravatar.com.

### Module 78: System Summary
**Settings:** `{ enabled: true }`
**Implementation:** Admin page showing: PHP version, MySQL version, WordPress version, active theme, server software, PHP memory limit, max execution time, upload max filesize, post max size, PHP extensions loaded, active plugins list with versions, debug mode status, WP constants (WP_DEBUG, SCRIPT_DEBUG, etc.), filesystem permissions on key directories, cron status (next scheduled, is DISABLE_WP_CRON set). All read-only, no settings to configure. "Copy to Clipboard" button for support sharing.
**Verification:** Open System Summary → all server info displayed → copy to clipboard → paste in text editor → formatted correctly.

### Module 79: Robots.txt Manager
**Settings:** `{ custom_robots: '', use_custom: false }`
**Hooks:** If `use_custom`: `robots_txt` filter → return custom content instead of WordPress default. Settings page: textarea with current robots.txt content. "Load Default" button to populate with WordPress default. Validate: check for `Disallow: /` which would block all crawling (warn, don't block save).
**Edge Cases:** Physical `robots.txt` file takes priority over virtual — detect and warn. Multisite → per-site robots.txt via virtual.
**Verification:** Enable custom → add `Disallow: /wp-admin/` → visit `/robots.txt` → custom content shown → disable → WordPress default returns.

### Module 80: Email Log
**Settings:** `{ enabled: true, retention_days: 30, log_content: true, resend_enabled: true }`
**Implementation:** Custom table `wpt_email_log` (id, to, subject, message, headers, attachments, status, sent_at). Hook `wp_mail` filter → capture args, store in table, return args unchanged. Admin page: list table with search, filter by status/date. Click entry → view full email content. "Resend" button → `wp_mail()` with stored args. Cron job: purge entries older than `retention_days`.
**Pro Tier:** Content viewing and resend are Pro.
**Verification:** Send password reset email → appears in Email Log → view content → click Resend → email sent again → old logs auto-purge after retention period.

---

## Batch 5C: Final Stretch — Unique & Polish (9 modules)

| # | Module ID | Category | One-Line | Tier |
|---|-----------|----------|----------|------|
| 81 | disable-rest-fields | disable-components | Remove specific fields from REST API responses (user emails, etc.) | Free |
| 82 | disable-emojis | disable-components | Remove WordPress emoji scripts and styles | Free |
| 83 | disable-self-pingbacks | disable-components | Prevent WordPress from sending pingbacks to itself | Free |
| 84 | disable-attachment-pages | disable-components | Redirect attachment pages to parent post or file URL | Free |
| 85 | page-template-column | admin-interface | Show page template in Pages list table | Free |
| 86 | auto-clear-caches | performance | Clear popular cache plugin caches on content save | Free |
| 87 | image-srcset-control | performance | Control responsive image srcset attribute behavior | Free |
| 88 | duplicate-widget | utilities | One-click duplicate any widget in the widget area | Free |
| 89 | export-import-settings | utilities | Export/import all WPTransformed settings as JSON | Free |

### Module 81: Disable REST Fields
**Settings:** `{ remove_fields: ['email', 'registered_date', 'capabilities', 'extra_capabilities'], remove_users_endpoint: false }`
**Hooks:** `rest_prepare_user` filter → unset selected fields from response. If `remove_users_endpoint`: `rest_endpoints` filter → unset `wp/v2/users` entirely. This is a security hardening module — prevents user enumeration via REST API.
**Verification:** API call to `/wp-json/wp/v2/users/1` → email field missing → enable remove endpoint → `/wp-json/wp/v2/users` returns 404.

### Module 82: Disable Emojis
**Settings:** `{ enabled: true }`
**Hooks:** Remove all emoji-related hooks and scripts: `remove_action('wp_head', 'print_emoji_detection_script', 7)`, `remove_action('admin_print_scripts', 'print_emoji_detection_script')`, `remove_action('wp_print_styles', 'print_emoji_styles')`, `remove_filter('the_content_feed', 'wp_staticize_emoji')`, `remove_filter('comment_text_rss', 'wp_staticize_emoji')`, `remove_filter('wp_mail', 'wp_staticize_emoji_for_email')`, add `'svg' => 'image/svg+xml'` is NOT needed (that's SVG Upload module). `tiny_mce_plugins` filter → remove `wpemoji`.
**Verification:** Activate → page source has no `wp-emoji-release.min.js` → no emoji-related inline CSS.

### Module 83: Disable Self Pingbacks
**Settings:** `{ enabled: true }`
**Hooks:** `pre_ping` action → iterate `$links`, unset any that point to `get_option('home')`. One of the simplest possible modules — single hook, single check.
**Verification:** Create post with internal link → publish → no self-pingback comment created → external pingbacks still work.

### Module 84: Disable Attachment Pages
**Settings:** `{ redirect_to: 'parent' }` (options: parent, file, home, 404)
**Hooks:** `template_redirect` → if `is_attachment()`, redirect based on setting. If parent: `wp_redirect(get_permalink($post->post_parent), 301)`. If file: `wp_redirect(wp_get_attachment_url($post->ID), 301)`. If home: `wp_redirect(home_url(), 301)`. If 404: set `$wp_query->set_404()`.
**Edge Cases:** Orphan attachments (no parent) → fall back to home or file URL. Media items used in galleries → redirect to parent post. Yoast/RankMath already have this feature — detect and defer if active.
**Verification:** Upload image → visit attachment page URL → redirects to parent post (or configured destination).

### Module 85: Page Template Column
**Settings:** `{ enabled: true }`
**Hooks:** `manage_pages_columns` → add "Template" column. `manage_pages_custom_column` → `get_page_template_slug($post_id)`, display template name. If no template → show "Default". Look up `wp_get_theme()->get_page_templates()` for human-readable names.
**Verification:** Pages list shows "Template" column → pages with custom templates show the template name → default pages show "Default."

### Module 86: Auto Clear Caches
**Settings:** `{ clear_on_save: true, clear_on_menu_save: true, supported_plugins: ['wp-super-cache', 'w3-total-cache', 'wp-rocket', 'litespeed-cache', 'autoptimize'] }`
**Hooks:** `save_post`, `wp_update_nav_menu` → detect which cache plugin is active and call its clear function. WP Super Cache: `wp_cache_clear_cache()`. W3 Total Cache: `w3tc_flush_all()`. WP Rocket: `rocket_clean_domain()`. LiteSpeed: `do_action('litespeed_purge_all')`. Autoptimize: `autoptimize_flush_pagecache()`. Also clear WP object cache: `wp_cache_flush()`. WP Engine: use their purge API if `is_wpe()`.
**Verification:** Edit post → save → cache plugin's cache is cleared → no stale content on frontend.

### Module 87: Image Srcset Control
**Settings:** `{ disable_srcset: false, max_srcset_width: 2048, limit_sizes: [] }`
**Hooks:** If `disable_srcset`: `wp_calculate_image_srcset` filter → return false (removes srcset entirely). If `max_srcset_width`: `max_srcset_image_width` filter → return configured width. If `limit_sizes`: `wp_calculate_image_srcset` filter → remove unwanted sizes from the srcset array.
**Edge Cases:** Disabling srcset means all visitors load the full-size image — warn about performance impact. Some themes depend on srcset for art direction — removing it breaks responsive images.
**Verification:** Set max width 1024 → image srcset only includes sizes up to 1024px wide → disable entirely → srcset attribute gone from `<img>` tags.

### Module 88: Duplicate Widget
**Settings:** `{ enabled: true }`
**Hooks:** `in_widget_form` action → add "Duplicate" link below each widget in the widget admin. AJAX handler: read widget settings from `get_option('widget_{type}')`, add new instance with same settings, assign to same sidebar via `wp_set_sidebars_widgets()`.
**Edge Cases:** Block widgets (WordPress 5.8+) → different duplication method using block parsing. Legacy widgets → classic instance duplication. Widget IDs must be unique — auto-increment the instance number.
**Verification:** Go to Widgets → click "Duplicate" on a text widget → identical widget appears below → different widget ID.

### Module 89: Export Import Settings
**Settings:** N/A — this IS the settings management tool.
**Implementation:** Admin page under WP Transformation → Export/Import. "Export" button → generates JSON file containing: all module enabled/disabled states, all module settings, all custom DB table data (redirect rules, login attempt configs, etc.). Download as `wptransformed-settings-{date}.json`. "Import" button → file upload, validate JSON structure, option to select which modules to import, apply settings. "Reset All" button → restore all modules to defaults with confirmation.
**Edge Cases:** Version mismatch — if exporting from v2 and importing into v3, handle missing/new settings gracefully. Large exports (many redirect rules, audit log entries) → may be large file. Don't export sensitive data (SMTP passwords) in plaintext — either exclude or encrypt.
**Verification:** Configure 10 modules with custom settings → export → reset all → import → all 10 modules restored with exact same settings.

---

## Build Notes

### Run as 3 `/batch` sessions:
```
/batch build modules 61-70 from docs/module-specs-wave5.md (Batch 5A)
/batch build modules 71-80 from docs/module-specs-wave5.md (Batch 5B)
/batch build modules 81-89 from docs/module-specs-wave5.md (Batch 5C)
```

### New Categories to Register:
- `woocommerce` (check WC active before showing these modules)

### Pro Tier Modules (7 total):
62, 71, 72, 73, 74, 80 + parts of other modules
Pro gating is NOT built in this wave — just add `// PRO` comment markers.
Pro licensing (Freemius integration) is a separate task after all modules work.

### WooCommerce Modules (5):
Modules 61-65 should auto-hide from module list if WooCommerce is not active.
Check with `class_exists('WooCommerce')` or `is_plugin_active('woocommerce/woocommerce.php')`.

### After All 3 Batches:
Total module count: 89
- v1: 10 modules
- v2: 14 modules (dropped Minify)
- Wave 3: 5 modules (differentiators)
- Wave 4: 30 modules (ASE parity)
- Wave 5: 29 modules (beyond ASE)
- Plus 1 module that runs it all: Export/Import Settings

Run `/security-review` on the entire codebase before proceeding to styling.
