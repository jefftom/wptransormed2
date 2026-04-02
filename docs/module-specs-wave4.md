# WPTransformed Wave 4 — ASE Parity Sprint

> 30 modules to reach full ASE feature parity plus extras.
> Every module ASE has, we have. Then we surpass them with Wave 5.
> Build with `/batch` — split into 3 batches of 10 for stability.

---

## Batch 4A: Login, Security & Access (10 modules)

| # | Module ID | Category | One-Line | Tier |
|---|-----------|----------|----------|------|
| 31 | change-login-url | login-logout | Custom login slug, redirect default wp-login.php to 404 | Free |
| 32 | login-id-type | login-logout | Restrict login to email only, username only, or both | Free |
| 33 | site-identity-login | login-logout | Replace WordPress logo with site logo on login page | Free |
| 34 | login-logout-menu | login-logout | Add login/logout/register links to any nav menu | Free |
| 35 | last-login-column | login-logout | Show last login date on Users list table | Free |
| 36 | redirect-after-login | login-logout | Custom redirect URLs per role after login/logout | Free |
| 37 | disable-xmlrpc | security | Disable XML-RPC entirely with one toggle | Free |
| 38 | obfuscate-author-slugs | security | Replace author slug with hash to prevent user enumeration | Free |
| 39 | email-obfuscator | security | Replace emails in content with JS-decoded versions to prevent scraping | Free |
| 40 | password-protection | security | Sitewide password gate with cookie-based bypass | Free |

### Module 31: Change Login URL

**Settings:** `{ custom_slug: 'my-login', redirect_to: '404' }` (options: 404, home, custom URL)
**Hooks:** `login_url` filter returns custom slug. `init` checks request URI — if `/wp-login.php` and no valid redirect, serve 404 or redirect. `site_url` filter rewrites login URLs in password reset emails.
**Edge Cases:** Don't break `wp_login_url()` for plugins that rely on it. Exclude `wp-login.php?action=postpass` (post password form). Allow `wp-login.php?action=logout` to still work. Store custom slug in option (not transient) for reliability.
**Verification:** Set slug to "my-login" → `/my-login` shows login form → `/wp-login.php` returns 404 → password reset email has correct URL.

### Module 32: Login ID Type

**Settings:** `{ login_type: 'email_only' }` (options: both, email_only, username_only)
**Hooks:** `authenticate` filter priority 20 — if `email_only`, check if input is email format, reject usernames. If `username_only`, reject emails. Modify login form label via `login_head` CSS or `gettext` filter.
**Edge Cases:** Don't break WP-CLI logins. Don't break XML-RPC auth (if XML-RPC is enabled). Don't break REST API authentication.
**Verification:** Set to email_only → login with username fails with clear message → login with email works.

### Module 33: Site Identity Login

**Settings:** `{ logo_source: 'site_logo', custom_logo_url: '', logo_width: 84, logo_height: 84 }`
**Hooks:** `login_headerurl` → return `home_url()`. `login_headertext` → return `get_bloginfo('name')`. `login_head` → output CSS to replace `.login h1 a` background-image with site's custom logo (from Customizer) or uploaded image. Use `get_custom_logo()` or `get_theme_mod('custom_logo')`.
**Edge Cases:** No custom logo set → use site title as text fallback. Very wide logos → enforce max-width in CSS.
**Verification:** Activate → login page shows site logo instead of WordPress logo → logo links to site home.

### Module 34: Login Logout Menu

**Settings:** `{ show_login: true, show_logout: true, show_register: true, login_text: 'Log In', logout_text: 'Log Out' }`
**Hooks:** `wp_nav_menu_items` filter → append login/logout/register links based on `is_user_logged_in()`. `wp_setup_nav_menu_item` → add custom menu items to the menu editor (Appearance → Menus) as a new metabox "WPTransformed Links".
**Edge Cases:** Multiple menus on page → apply to selected menus only (setting to choose which menu locations). Don't add duplicate items if user manually added them. Registration link only if `get_option('users_can_register')` is true.
**Verification:** Logged out → menu shows "Log In" + "Register" → logged in → menu shows "Log Out" → links work correctly.

### Module 35: Last Login Column

**Settings:** `{ enabled: true, date_format: 'relative' }` (options: relative "2 hours ago", absolute "Apr 2, 2026 3:15 PM")
**Hooks:** `wp_login` action → `update_user_meta($user_id, 'wpt_last_login', current_time('mysql', true))`. `manage_users_columns` filter → add "Last Login" column. `manage_users_custom_column` filter → display the stored date. `manage_users_sortable_columns` → make it sortable.
**Edge Cases:** Users who never logged in → show "Never". Timezone handling → store UTC, display in site timezone. Sortable column → `pre_get_users` meta_key ordering.
**Verification:** Log in → check Users list → "Last Login" column shows correct time → sortable by click.

### Module 36: Redirect After Login

**Settings:** `{ redirects: { administrator: '/wp-admin/', editor: '/wp-admin/edit.php', subscriber: '/', default: '/' }, logout_redirect: '/' }`
**Hooks:** `login_redirect` filter priority 99 → check user role, return configured URL. `wp_logout` action → `wp_safe_redirect()` to configured logout URL, `exit`.
**Edge Cases:** User with multiple roles → use highest-priority role. Redirect URL must be on same domain → use `wp_safe_redirect()`, not `wp_redirect()`. Don't override `redirect_to` parameter if explicitly passed (e.g., from checkout page).
**Verification:** Set subscriber redirect to `/welcome/` → log in as subscriber → lands on /welcome/ → log out → lands on configured logout URL.

### Module 37: Disable XML-RPC

**Settings:** `{ disable_completely: true, disable_pingbacks_only: false }`
**Hooks:** If disable_completely: `add_filter('xmlrpc_enabled', '__return_false')` + `add_filter('xmlrpc_methods', '__return_empty_array')`. Remove `X-Pingback` header via `wp_headers` filter. If pingbacks_only: `add_filter('xmlrpc_methods', function($methods) { unset($methods['pingback.ping']); return $methods; })`.
**Edge Cases:** Some plugins require XML-RPC (Jetpack). Show warning if Jetpack is active. Don't break the REST API (different system entirely).
**Verification:** Activate → visit `/xmlrpc.php` → returns disabled message → `X-Pingback` header gone from page source.

### Module 38: Obfuscate Author Slugs

**Settings:** `{ enabled: true, method: 'hash' }` (options: hash, numeric_id, custom)
**Hooks:** `author_rewrite_rules` filter → rewrite author base to use hash/ID instead of nicename. `author_link` filter → replace slug in author URL. `init` → add rewrite rules for the obfuscated pattern. `template_redirect` → if someone requests `/author/real-username/`, redirect to obfuscated version or 404.
**Edge Cases:** Flush rewrite rules on settings change (once via transient flag). Don't break REST API user endpoints. Sitemap author URLs should use obfuscated version too.
**Verification:** View author page → URL uses hash instead of username → old `/author/admin/` URL returns 404 → author page content still displays correctly.

### Module 39: Email Obfuscator

**Settings:** `{ enabled: true, method: 'js_decode', protect_mailto: true, protect_plaintext: true }`
**Hooks:** `the_content` filter priority 999 → regex find emails → replace with JS-decoded `<span data-email="encoded">` elements. `wp_footer` → output small JS that decodes and renders mailto links. Also filter `widget_text`, `comment_text`.
**Implementation:** Encode email with simple ROT13 or base64 in HTML, decode with JS on page load. Non-JS fallback: `[at]` and `[dot]` replacement.
**Edge Cases:** Don't encode emails inside `<input>` fields or form values. Don't encode emails in admin area. Don't break `is_email()` validation elsewhere. RSS feeds → use `[at]` replacement since no JS.
**Verification:** View page with email address → source shows encoded version → browser displays clickable mailto link → bots see encoded gibberish.

### Module 40: Password Protection

**Settings:** `{ enabled: false, password: '', message: 'This site is password protected.', allowed_ips: [], exclude_pages: [], cookie_duration: 24 }`
**Hooks:** `template_redirect` priority 0 → check cookie `wpt_site_access`, if missing/invalid, show password form. Verify password → set cookie for `cookie_duration` hours → reload page. Exclude: login page, admin area, REST API, WP-Cron, allowed IPs, excluded page IDs.
**Edge Cases:** Don't block `wp-login.php` (admins need to log in). Don't block admin-ajax.php. Don't block xmlrpc.php. Cookie must be `httponly` and `secure` if on HTTPS. Don't interfere with Maintenance Mode module (they serve different purposes — password protection is ongoing access control, maintenance is temporary).
**Verification:** Enable with password "test123" → visit site → password form appears → enter password → site accessible → cookie set for 24 hours → new incognito window → password form again.

---

## Batch 4B: Disable Components & Admin Tweaks (10 modules)

| # | Module ID | Category | One-Line | Tier |
|---|-----------|----------|----------|------|
| 41 | disable-gutenberg | disable-components | Disable block editor per post type, restore classic editor | Free |
| 42 | disable-rest-api | disable-components | Disable REST API for non-authenticated users | Free |
| 43 | disable-feeds | disable-components | Disable all RSS/Atom feeds | Free |
| 44 | disable-embeds | disable-components | Remove oEmbed, disable embed discovery | Free |
| 45 | disable-updates | disable-components | Selectively disable auto-updates for core/plugins/themes | Free |
| 46 | disable-author-archives | disable-components | Remove author archive pages, redirect to home | Free |
| 47 | admin-columns-enhancer | admin-interface | Add ID, thumbnail, modified date columns to post list tables | Free |
| 48 | taxonomy-filter | admin-interface | Show custom taxonomy filters on post list tables | Free |
| 49 | custom-admin-css | custom-code | Add custom CSS to admin area | Free |
| 50 | custom-frontend-code | custom-code | Insert code into head, body open, or footer of frontend | Free |

### Module 41: Disable Gutenberg

**Settings:** `{ disable_for: ['post', 'page'], enable_classic: true, disable_block_widgets: true }`
**Hooks:** `use_block_editor_for_post_type` filter → return false for selected post types. If `enable_classic` → ensure Classic Editor is loaded (WP has built-in classic editor, no plugin needed). `use_widgets_block_editor` filter → return false if `disable_block_widgets`.
**Edge Cases:** Don't disable for post types that REQUIRE Gutenberg (like `wp_template`). Warn if a block theme is active. Don't break Full Site Editing if user selects only specific post types.
**Verification:** Disable for Posts → edit a post → classic editor loads → Pages still use Gutenberg if not in list.

### Module 42: Disable REST API

**Settings:** `{ mode: 'auth_only', allow_specific: [] }` (options: disabled, auth_only, enabled)
**Hooks:** `rest_authentication_errors` filter → if `auth_only` and user not logged in, return `WP_Error('rest_disabled', 'REST API restricted', ['status' => 401])`. Allow specific namespaces in `allow_specific` (e.g., `contact-form-7/v1`).
**Edge Cases:** Gutenberg REQUIRES REST API — if Gutenberg is active, always allow `wp/v2` namespace for authenticated users. WooCommerce REST API → always allow if WC is active. Don't break `wp-json/oembed` (or do, if embeds module also disabled).
**Verification:** Log out → visit `/wp-json/` → 401 error → log in → works normally.

### Module 43: Disable Feeds

**Settings:** `{ disable_all: true, redirect_to: 'home' }` (options: home, 404)
**Hooks:** `do_feed`, `do_feed_rss`, `do_feed_rss2`, `do_feed_atom`, `do_feed_rdf` → all hooked to redirect or 404. Remove feed links from `<head>` via `remove_action('wp_head', 'feed_links')` and `remove_action('wp_head', 'feed_links_extra')`.
**Edge Cases:** WooCommerce product feeds → option to exclude. Podcast plugins that use feeds → warn if detected. Comment feeds → also disabled.
**Verification:** Visit `/feed/` → redirects to home (or 404) → no feed links in page source `<head>`.

### Module 44: Disable Embeds

**Settings:** `{ disable_oembed: true, disable_embed_discovery: true, remove_embed_js: true }`
**Hooks:** Remove `wp-embed` script via `wp_deregister_script('wp-embed')`. Remove oEmbed discovery links from `<head>`. Remove `rest_oembed_link` from `wp_head`. Filter `embed_oembed_html` to return empty or plain URL. Remove `oembed_dataparse` filter.
**Edge Cases:** YouTube/Twitter embeds in existing content → they stop rendering as embeds, show as plain URLs. Warn user that existing embeds will break. Don't remove Embeds for admin area (Gutenberg uses them for previews).
**Verification:** Activate → page source has no `wp-embed.min.js` → no oEmbed discovery `<link>` tags → existing YouTube URLs show as plain text links.

### Module 45: Disable Updates

**Settings:** `{ disable_core: false, disable_plugins: false, disable_themes: false, disable_auto_core: true, disable_auto_plugins: true, disable_auto_themes: true, disable_translation_updates: false }`
**Hooks:** Auto-updates: `auto_update_core`, `auto_update_plugin`, `auto_update_theme`, `auto_update_translation` filters → return false per setting. Full disable: `pre_site_transient_update_core`, `pre_site_transient_update_plugins`, `pre_site_transient_update_themes` → return appropriate empty object to suppress update checks. Remove update nag: `remove_action('admin_notices', 'update_nag')`.
**Edge Cases:** Security risk warning — show prominent notice that disabling security updates is dangerous. Never disable on multisite unless network admin. Option to disable updates for specific plugins only (not all).
**Verification:** Disable all auto-updates → Dashboard shows no update notifications → Updates page shows checks are disabled → re-enable → updates appear again.

### Module 46: Disable Author Archives

**Settings:** `{ enabled: true, redirect_to: 'home' }` (options: home, 404)
**Hooks:** `template_redirect` → if `is_author()`, redirect or 404. Remove author rewrite rules via `author_rewrite_rules` filter returning empty array. Optionally remove author links from posts via `the_author_posts_link` filter.
**Edge Cases:** Don't break the Users admin page. Sitemap generators → remove author URLs from sitemap via compatible hooks. SEO plugins → noindex author pages as fallback.
**Verification:** Visit `/author/admin/` → redirects to home → author link removed from posts (if setting enabled).

### Module 47: Admin Columns Enhancer

**Settings:** `{ show_id: true, show_thumbnail: true, show_modified_date: true, show_slug: false, show_template: false, post_types: ['post', 'page'] }`
**Hooks:** `manage_{post_type}_posts_columns` → add columns. `manage_{post_type}_posts_custom_column` → render content. `manage_edit-{post_type}_sortable_columns` → make sortable. Thumbnail: use `get_the_post_thumbnail()` with 50x50 size. ID: just echo `$post_id`. Modified date: `get_the_modified_date()`. Slug: `$post->post_name`. Template: `get_page_template_slug()`.
**Edge Cases:** Custom post types → apply based on settings. WooCommerce products → careful not to break WC's custom columns. Very long slugs → truncate with ellipsis.
**Verification:** Activate → Posts list shows ID, thumbnail, modified date columns → sortable by ID and modified date.

### Module 48: Taxonomy Filter

**Settings:** `{ enabled: true, post_types: ['post', 'page'] }`
**Hooks:** `restrict_manage_posts` → for each non-hierarchical custom taxonomy on the current post type, output a `<select>` dropdown via `wp_dropdown_categories()` with `taxonomy` arg. `parse_query` → if taxonomy filter is set in `$_GET`, modify query to filter by that term.
**Edge Cases:** Post types with many taxonomies → show max 3 dropdowns, "More filters" expandable. Taxonomies with 1000+ terms → use AJAX search instead of full dropdown. Don't duplicate built-in category/tag filters.
**Verification:** Register a CPT with custom taxonomy → activate module → taxonomy dropdown appears on list table → filter works.

### Module 49: Custom Admin CSS

**Settings:** `{ css: '', enable_codemirror: true }`
**Hooks:** `admin_head` → output `<style id="wpt-custom-admin-css">` with saved CSS. Settings page uses WordPress bundled CodeMirror (`wp-codemirror`) for syntax highlighting.
**Edge Cases:** Sanitize CSS with `wp_strip_all_tags()` — no `<script>` injection via CSS. Don't load CodeMirror on non-settings pages. CSS errors shouldn't break admin — wrap in try/catch on the rendering side, or validate syntax before saving.
**Verification:** Add `#adminmenu { background: navy; }` → admin sidebar turns navy → remove CSS → reverts.

### Module 50: Custom Frontend Code

**Settings:** `{ head_code: '', body_open_code: '', footer_code: '', load_on: 'all' }` (load_on options: all, specific_pages)
**Hooks:** `wp_head` → output `head_code`. `wp_body_open` → output `body_open_code`. `wp_footer` → output `footer_code`. Capability check: only `manage_options` can edit. Settings use CodeMirror with HTML mode.
**Edge Cases:** Don't output in admin area. Allow `<script>`, `<style>`, `<meta>`, `<link>` tags (these are the primary use case — tracking pixels, analytics, chat widgets). Sanitize with `wp_unslash()` only, NOT `wp_kses` (that strips scripts). Store raw. Only admins can edit, so XSS risk is self-inflicted.
**Verification:** Add Google Analytics script to head_code → view frontend source → GA script present in `<head>` → admin pages don't have it.

---

## Batch 4C: Content, Performance & Utility Gaps (10 modules)

| # | Module ID | Category | One-Line | Tier |
|---|-----------|----------|----------|------|
| 51 | revision-control | performance | Limit revisions per post type or disable entirely | Free |
| 52 | content-order | content-management | Drag-and-drop reorder for posts, pages, and CPTs | Free |
| 53 | media-replace | content-management | Replace media files while keeping the same URL and ID | Free |
| 54 | public-preview | content-management | Share a secret URL to preview draft posts without login | Free |
| 55 | external-permalinks | content-management | Make nav menu items or posts redirect to external URLs | Free |
| 56 | auto-publish-missed | content-management | Auto-publish posts with missed schedule status | Free |
| 57 | enhance-list-tables | admin-interface | Add featured image, excerpt preview, word count to list tables | Free |
| 58 | hide-dashboard-widgets | admin-interface | Selectively hide default dashboard widgets | Free |
| 59 | admin-bar-enhancer | admin-interface | Add quick shortcuts, environment indicator, user switching to admin bar | Free |
| 60 | white-label | admin-interface | Replace WordPress branding throughout admin (logo, text, footer, login) | Free |

### Module 51: Revision Control

**Settings:** `{ max_revisions: 5, per_post_type: { post: 5, page: 3 }, disable_for: [] }`
**Hooks:** `wp_revisions_to_keep` filter → return configured max for the post type. If disabled for a type → return 0. Settings page shows current revision count per post type with "Purge Old Revisions" button (AJAX, batch delete, respects WP Engine timeout).
**Verification:** Set max 3 for posts → create post, edit 5 times → only 3 revisions saved → purge button removes excess revisions from DB.

### Module 52: Content Order

**Settings:** `{ enabled_for: ['page'], drag_drop: true }`
**Hooks:** Add `menu_order` support to selected post types. `pre_get_posts` → if on admin list and post type is enabled, order by `menu_order ASC`. AJAX endpoint `wpt_save_content_order` → updates `menu_order` for an array of post IDs. Vanilla JS drag-and-drop on the list table rows.
**Edge Cases:** Only works in list view, not excerpt/grid view. Save button or auto-save on drop. Page already supports menu_order — respect existing values. Very long lists → paginate drag-and-drop per page only.
**Verification:** Enable for Pages → Pages list shows drag handles → drag Page A below Page B → order persists after refresh → frontend `wp_list_pages()` reflects new order.

### Module 53: Media Replace

**Settings:** `{ enabled: true, keep_date: true }`
**Hooks:** `media_row_actions` filter → add "Replace Media" link. `wp_handle_upload` → overwrite the old file at the same path. Update `_wp_attached_file` meta. Regenerate thumbnails via `wp_generate_attachment_metadata()`. Keep same attachment ID, URL, and all post relationships.
**Edge Cases:** Different file type (replace JPEG with PNG) → update mime type in DB. Different dimensions → regenerate all thumbnail sizes. CDN users → old file may be cached. Add "Clear CDN cache" reminder notice. Don't allow replacing files currently being edited by another user.
**Verification:** Upload image A → note URL → replace with image B via "Replace Media" → same URL now serves image B → all thumbnails regenerated → posts using old image show new image.

### Module 54: Public Preview

**Settings:** `{ enabled: true, link_expiry: 48 }` (hours)
**Hooks:** Add "Public Preview" metabox on edit screen for draft/pending posts. Generate unique token: `wp_generate_password(20, false)`. Store in post meta: `_wpt_preview_token`. Preview URL: `get_permalink($post_id) . '?wpt_preview=' . $token`. `pre_get_posts` + `posts_results` → if `wpt_preview` param matches stored token AND within expiry → show post content to non-logged-in user. Auto-expire by checking stored timestamp.
**Edge Cases:** Token should be regeneratable (new token invalidates old). Private posts → also work with preview token. Password-protected posts → bypass password with valid token. Don't index preview URLs (add `noindex` meta).
**Verification:** Create draft post → click "Get Preview Link" → copy URL → open in incognito → draft post visible → wait past expiry → link returns 404.

### Module 55: External Permalinks

**Settings:** `{ enabled: true, meta_key: '_wpt_external_url' }`
**Hooks:** Add "External URL" field to post edit screen (metabox). `post_type_link` / `page_link` filter → if post has `_wpt_external_url` meta, return that URL instead. `template_redirect` → if visiting the post directly and external URL is set, `wp_redirect($url, 301)`. Show visual indicator on list table that post is an external link.
**Edge Cases:** Validate URL format before saving. Only 301 redirect on single post view, not in archives/feeds. Nav menu items → if post has external URL, menu link points there directly.
**Verification:** Create post, set external URL to `https://example.com` → post link in archives/menus goes to example.com → visiting original permalink redirects to example.com.

### Module 56: Auto Publish Missed

**Settings:** `{ enabled: true, check_interval: 5 }` (minutes)
**Hooks:** Register custom cron schedule with `check_interval`. Cron callback: `$wpdb->get_results("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date <= NOW()")` → for each, `wp_publish_post($id)`. Also hook into `wp_head` as a fallback trigger (checks on page load).
**Edge Cases:** Don't re-publish trashed or draft posts. Only publish posts with `future` status that are past their scheduled date. Log published posts to admin notice. WP-Cron unreliability → the `wp_head` fallback catches what cron misses.
**Verification:** Schedule a post for 2 minutes ago → wait for cron → post publishes → admin notice shows "1 missed schedule post published."

### Module 57: Enhance List Tables

**Settings:** `{ show_featured_image: true, show_excerpt_preview: true, show_word_count: true, image_size: 50, post_types: ['post', 'page'] }`
**Hooks:** `manage_{type}_posts_columns` → add columns. `manage_{type}_posts_custom_column` → render. Featured image: `get_the_post_thumbnail($post_id, [50, 50])`. Excerpt preview: `wp_trim_words(get_the_excerpt($post_id), 15)`. Word count: `str_word_count(wp_strip_all_tags(get_the_content(null, false, $post_id)))`. CSS for thumbnail column width.
**Edge Cases:** Posts without featured images → show placeholder dashicon. Performance: word count on large post lists (100+ posts) → can be slow. Consider caching word count in post meta on save.
**Verification:** Activate → Posts list shows thumbnail, excerpt preview, word count columns.

### Module 58: Hide Dashboard Widgets

**Settings:** `{ hidden_widgets: ['dashboard_primary', 'dashboard_quick_press', 'dashboard_site_health'], per_role: false }`
**Hooks:** `wp_dashboard_setup` → `remove_meta_box()` for each hidden widget ID. Settings page: list all registered dashboard widgets with checkboxes. Detect widgets dynamically from `$wp_meta_boxes['dashboard']`.
**Edge Cases:** Third-party widgets (WooCommerce, Yoast) → also listable and hideable. New widgets added by plugins → appear unchecked on next settings visit. Per-role option: different roles see different widgets.
**Verification:** Hide "WordPress Events and News" → refresh dashboard → widget gone → uncheck → widget returns.

### Module 59: Admin Bar Enhancer

**Settings:** `{ remove_wp_logo: true, remove_howdy: true, show_environment: true, environment_label: 'Production', environment_color: '#dc3545', show_user_switching: false, custom_links: [] }`
**Hooks:** `admin_bar_menu` priority 0 → remove WP logo node if setting. `admin_bar_menu` priority 999 → add environment indicator, custom links. `gettext` filter → replace "Howdy," with "Welcome," or custom text. Environment indicator: colored dot + label in admin bar (red=production, yellow=staging, green=dev). Custom links: user-defined admin bar shortcuts.
**Edge Cases:** Multisite → WP logo removal must also remove "My Sites" if desired (separate setting). Admin bar on frontend → same modifications apply. RTL → environment indicator positioning.
**Verification:** Activate → WP logo gone → "Howdy" says "Welcome" → red "Production" indicator in admin bar → custom link to Google Analytics appears.

### Module 60: White Label

**Settings:** `{ admin_logo_url: '', admin_footer_text: 'Powered by Your Agency', login_logo_url: '', hide_wp_version: true, custom_admin_title: '' }`
**Hooks:** `admin_footer_text` filter → return custom text. `update_footer` filter → return empty or custom version string. `login_headerurl` + `login_headertext` + `login_head` → custom logo on login (defers to Login Customizer if active, avoids duplication). `admin_head` → inject custom logo CSS for `#wpadminbar #wp-admin-bar-wp-logo > .ab-item::before`. `admin_title` filter → prepend custom title. Remove WP version from meta generator → `remove_action('wp_head', 'wp_generator')`.
**Edge Cases:** Login Customizer (module 17) already handles login page styling — White Label should detect it and skip login customization if Login Customizer is active. Don't break WP update checks by hiding version. Multisite → per-site branding.
**Verification:** Set custom footer → admin footer shows agency name → login page shows custom logo → `<meta name="generator">` removed from source → admin bar shows custom logo.

---

## Build Notes

### Split into 3 `/batch` runs:
```
/batch build 10 WPTransformed modules from docs/module-specs-wave4.md — Batch 4A (modules 31-40)
```
Wait for completion + review, then:
```
/batch build 10 WPTransformed modules from docs/module-specs-wave4.md — Batch 4B (modules 41-50)
```
Wait for completion + review, then:
```
/batch build 10 WPTransformed modules from docs/module-specs-wave4.md — Batch 4C (modules 51-60)
```

### After all 3 batches:
- Run cross-model review with /fast on all 30 new modules
- Fix criticals
- Update module registry
- Push to origin main

### Registry Categories to Add
- `login-logout` (new)
- `disable-components` (new)
- `custom-code` (new)

### Common Patterns Across These Modules
- Most are small (single PHP file, no JS, no DB table)
- Disable Components modules are the simplest — mostly single-hook toggles
- Login modules share patterns — test with actual login/logout flows
- Content modules often need `pre_get_posts` hooks — be careful with query modification scope (admin only vs frontend only vs both)
- All security modules need extra scrutiny — timing-safe comparisons, no credential logging
