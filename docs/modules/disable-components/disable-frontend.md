# Disable Frontend Features

## 1. Metadata

- ID: `disable-frontend`
- Category: `disable-components`
- Tier: Free
- Risk: Medium
- Status: Post-Phase 1
- Replaces: Disable Emojis (standalone plugins), Disable Embeds, Perfmatters (partial)
- Related modules: `disable-backend`
- Default enabled: false
- Onboarding profiles: Performance, Developer

## 2. One-Liner

Lets users toggle off WordPress frontend features they do not need -- RSS feeds, oEmbed, emojis, self-pingbacks, attachment pages, and author archives -- to reduce page weight and close unnecessary public endpoints.

## 3. Scope

Disable Frontend Features provides six independent toggles, each removing a discrete piece of WordPress frontend output:

1. **Disable RSS Feeds** -- removes all feed `<link>` tags from `<head>`, intercepts `/feed/`, `/comments/feed/`, and per-post feed URLs and returns a redirect or 404.
2. **Disable oEmbed** -- removes embed discovery `<link>` tags, dequeues `wp-embed.js`, disables the oEmbed REST discovery endpoint, prevents the site from being embedded elsewhere.
3. **Disable Emojis** -- removes `wp-emoji-release.min.js`, the inline emoji detection script, and the `s.w.org` DNS prefetch hint.
4. **Disable Self-Pingbacks** -- prevents WordPress from sending pingbacks to its own URLs when an author links to another post on the same site.
5. **Disable Attachment Pages** -- redirects attachment permalink URLs to the parent post (or homepage if unattached), preventing thin-content pages from being indexed.
6. **Disable Author Archives** -- redirects `/author/{username}/` URLs to the homepage or returns 404, and removes author archive links from post meta output.

Each toggle operates independently. Enabling or disabling one has no effect on the others.

## 4. Things NOT To Do

- Do not remove feed functionality from `wp_get_feeds()` or internal feed generation -- only suppress public output and endpoints.
- Do not break the Block Editor embed block -- only disable the legacy oEmbed discovery/provider behavior.
- Do not remove author data from REST API responses (that belongs to `disable-backend`).
- Do not touch XML sitemaps -- feed removal and author archive removal must not affect Yoast/RankMath sitemaps.
- Do not hard-delete any rewrite rules -- only intercept and redirect at runtime.
- Do not affect admin-side feeds (e.g., dashboard RSS widgets).
- Do not break WooCommerce product feeds or structured data.

## 5. What the User Sees

In the module settings panel, six toggle switches with brief descriptions:

```text
[x] Disable RSS Feeds
    Removes feed links from <head> and returns 404 for /feed/ URLs.
    Redirect behavior: [Homepage v] | [404]

[ ] Disable oEmbed
    Removes embed discovery links and dequeues wp-embed.js.

[x] Disable Emojis
    Removes emoji detection script and DNS prefetch (~3 KB saved).

[x] Disable Self-Pingbacks
    Prevents WordPress from pinging its own URLs.

[ ] Disable Attachment Pages
    Redirect behavior: [Parent Post v] | [Homepage] | [404]

[ ] Disable Author Archives
    Redirect behavior: [Homepage v] | [404]
```

Each toggle that redirects shows a radio/select for the redirect target. Redirect options are only visible when the toggle is enabled.

## 6. Settings Schema

```json
{
  "disable_frontend": {
    "rss_feeds": {
      "enabled": false,
      "redirect_target": "homepage"
    },
    "oembed": {
      "enabled": false
    },
    "emojis": {
      "enabled": false
    },
    "self_pingbacks": {
      "enabled": false
    },
    "attachment_pages": {
      "enabled": false,
      "redirect_target": "parent"
    },
    "author_archives": {
      "enabled": false,
      "redirect_target": "homepage"
    }
  }
}
```

Field details:

| Field | Type | Default | Allowed Values |
|---|---|---|---|
| `rss_feeds.enabled` | bool | `false` | `true`, `false` |
| `rss_feeds.redirect_target` | string | `"homepage"` | `"homepage"`, `"404"` |
| `oembed.enabled` | bool | `false` | `true`, `false` |
| `emojis.enabled` | bool | `false` | `true`, `false` |
| `self_pingbacks.enabled` | bool | `false` | `true`, `false` |
| `attachment_pages.enabled` | bool | `false` | `true`, `false` |
| `attachment_pages.redirect_target` | string | `"parent"` | `"parent"`, `"homepage"`, `"404"` |
| `author_archives.enabled` | bool | `false` | `true`, `false` |
| `author_archives.redirect_target` | string | `"homepage"` | `"homepage"`, `"404"` |

## 7. Hooks & Implementation

Recommended class: `WPT_Disable_Frontend`

### Disable RSS Feeds

```php
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'feed_links_extra', 3);
add_action('template_redirect', [$this, 'intercept_feed_requests']);
// intercept_feed_requests: if is_feed(), wp_redirect() or wp_die(404)
```

### Disable oEmbed

```php
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_oembed_add_host_js');
wp_deregister_script('wp-embed');
add_filter('embed_oembed_discover', '__return_false');
remove_action('rest_api_init', 'wp_oembed_register_route');
add_filter('tiny_mce_plugins', [$this, 'remove_oembed_tinymce_plugin']);
```

### Disable Emojis

```php
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles', 'print_emoji_styles');
add_filter('wp_resource_hints', [$this, 'remove_emoji_dns_prefetch'], 10, 2);
add_filter('emoji_svg_url', '__return_false');
```

### Disable Self-Pingbacks

```php
add_action('pre_ping', [$this, 'disable_self_pingbacks']);
// Removes own site URLs from the $links array before pings are sent.
```

### Disable Attachment Pages

```php
add_action('template_redirect', [$this, 'redirect_attachment_pages']);
// If is_attachment(), redirect to parent post permalink or homepage or 404.
// Use 301 redirect for SEO.
```

### Disable Author Archives

```php
add_action('template_redirect', [$this, 'redirect_author_archives']);
// If is_author(), redirect to homepage or 404.
// Use 301 redirect for SEO.
add_filter('author_link', [$this, 'neutralize_author_link'], 10, 3);
// Return homepage URL instead of author archive URL.
```

Filter for external override:

```php
apply_filters('wpt_disable_frontend_active_features', $features);
```

## 8. REST API

Endpoints:

```text
GET  /wp-json/wpt/v1/modules/disable-frontend/settings
POST /wp-json/wpt/v1/modules/disable-frontend/settings
```

Permissions: requires `manage_wpt`.

Response shape follows Settings Schema (section 6).

No module-specific REST endpoints beyond settings CRUD.

## 9. Database Usage

Uses Settings Storage exclusively. No custom tables.

Settings stored under the `disable_frontend` key in the WPT options row.

## 10. Exact Behavior

### Toggle activation flow

1. User enables a toggle in the module settings panel.
2. Settings are saved via Settings Storage.
3. On the next page load, `WPT_Disable_Frontend` reads the settings and conditionally hooks into WordPress.
4. Each sub-feature registers its hooks independently based on its `enabled` flag.

### Feed interception

1. `template_redirect` fires at priority 10.
2. If `is_feed()` returns true and `rss_feeds.enabled` is true:
   - If `redirect_target` is `"homepage"`: issue `wp_redirect(home_url('/'), 301)` and exit.
   - If `redirect_target` is `"404"`: set 404 status and load the theme 404 template.
3. Feed `<link>` tags in `<head>` are removed regardless of redirect target.

### Attachment page redirection

1. `template_redirect` fires.
2. If `is_attachment()` returns true and `attachment_pages.enabled` is true:
   - `"parent"`: get `$post->post_parent`. If parent exists, redirect 301 to parent permalink. If no parent, fall through to homepage.
   - `"homepage"`: redirect 301 to `home_url('/')`.
   - `"404"`: set 404 status and load theme 404 template.

### Author archive redirection

1. `template_redirect` fires.
2. If `is_author()` returns true and `author_archives.enabled` is true:
   - `"homepage"`: redirect 301 to `home_url('/')`.
   - `"404"`: set 404 status and load theme 404 template.

## 11. Edge Cases

### WooCommerce product feeds

WooCommerce uses `/feed/` for product feeds and Google Shopping integrations. If WooCommerce is active and `rss_feeds.enabled` is true, show a warning in the settings panel: "WooCommerce is active. Disabling RSS feeds may break product feeds used by Google Shopping and other integrations." Do not auto-exempt WooCommerce feeds -- let the user decide.

### Yoast/RankMath sitemaps

XML sitemaps use their own rewrite rules and do not use `is_feed()`. Feed disabling must not affect sitemaps. Author archive disabling must not affect author sitemaps -- sitemaps are served by the SEO plugin, not by `is_author()`.

### Jetpack oEmbed

Jetpack registers its own oEmbed providers. Disabling oEmbed removes WordPress core discovery only. Jetpack-specific embed types (e.g., Jetpack Slideshow embed) may still function if Jetpack handles them independently. This is acceptable -- document in Known Gotchas.

### Block Editor embed blocks

The `/embed` block uses the server-side oEmbed proxy (`/wp-json/oembed/1.0/proxy`). When oEmbed is disabled, this endpoint is removed. Pasting a YouTube URL in the editor will no longer auto-embed. The raw URL remains as a link. Show a notice in settings: "Disabling oEmbed will prevent auto-embedding of YouTube, Twitter, and other URLs in the Block Editor."

### RSS-dependent plugins (Mailchimp RSS campaigns)

Mailchimp and similar services fetch the site feed externally. If feeds are disabled and return 404, RSS-to-email campaigns will break. Show a warning: "External services that read your RSS feed (e.g., Mailchimp RSS campaigns) will stop working."

### Multisite author archives

On multisite, author archives exist per-site. The redirect applies only to the current site. Super admin author archives on the network admin are unaffected (network admin does not use `template_redirect`).

### Unattached media

If `attachment_pages.redirect_target` is `"parent"` and the attachment has no parent post (`post_parent === 0`), fall through to homepage redirect rather than erroring.

## 12. Conflicts & Dependencies

Depends on:

- Module Registry
- Settings Storage
- Module Loader

Potential conflicts:

| Plugin | Conflict | Severity |
|---|---|---|
| Perfmatters | Overlapping toggles for emojis, embeds, feeds | Warning |
| Disable Emojis (FLAVOR plugin) | Duplicate emoji removal | Info |
| Jepack | oEmbed provider overlap | Info |
| WooCommerce | Product feeds may break | Warning |
| Yoast SEO / RankMath | No conflict -- sitemaps unaffected | Info |

Conflict Detector should show replacement opportunity for single-purpose "disable" plugins.

## 13. Security & Permissions

- Requires `manage_wpt` capability to change settings.
- Feed and archive redirects execute for all visitors (no auth check needed -- these are public-facing).
- Redirect targets are validated against an allowlist (`"homepage"`, `"404"`, `"parent"`). No arbitrary URL redirects.
- No user input is reflected in redirect URLs.
- Self-pingback disabling does not affect external pingbacks or trackbacks.

## 14. Mobile/Responsive Behavior

This module has no UI beyond the settings panel. Settings panel toggles must be tap-friendly (minimum 44px touch target). Warning notices must be readable at 320px viewport width.

No frontend-visible UI is rendered by this module.

## 15. Data Retention / Uninstall

- Settings are stored via Settings Storage and follow the global retention policy.
- On module disable: all hooks are removed, WordPress default behavior resumes immediately. No redirect rules persist.
- On plugin uninstall: settings are deleted with all other WPT data per the global uninstall policy.
- No external data is stored or modified.

## 16. Verification & Acceptance Criteria

- [ ] Each of the six toggles can be enabled/disabled independently.
- [ ] Disabling RSS feeds removes all `<link rel="alternate" type="application/rss+xml">` tags from `<head>`.
- [ ] Visiting `/feed/` with feeds disabled returns 301 to homepage (or 404 per setting).
- [ ] Disabling oEmbed removes `wp-embed.js` from the page source.
- [ ] Disabling oEmbed removes the `<link rel="alternate" type="application/json+oembed">` tag.
- [ ] Disabling emojis removes `wp-emoji-release.min.js` and inline emoji script from page source.
- [ ] Disabling emojis removes `s.w.org` DNS prefetch.
- [ ] Self-pingback disable prevents pingbacks when linking to own posts; external pingbacks still work.
- [ ] Attachment page redirect sends 301 to parent post when `redirect_target` is `"parent"`.
- [ ] Unattached media redirects to homepage instead of erroring when target is `"parent"`.
- [ ] Author archive redirect sends 301 to homepage (or 404 per setting).
- [ ] WooCommerce warning appears when feeds are disabled and WooCommerce is active.
- [ ] oEmbed warning mentions Block Editor embed block impact.
- [ ] Yoast/RankMath XML sitemaps are unaffected by any toggle.
- [ ] Non-authorized users cannot change settings.
- [ ] Disabling the module restores all default WordPress behavior immediately.

## 17. Deferred Features

- Per-feed-type granularity (disable post feed but keep comments feed).
- Custom redirect URL for attachment pages (beyond parent/homepage/404).
- Allowlist specific oEmbed providers (e.g., keep YouTube but disable others).
- Disable Gravatar DNS prefetch (related but distinct concern).
- Disable Windows Live Writer manifest link.
- Disable WordPress version generator tag (may fit better in a security module).

## 18. Known Gotchas

- `remove_action('wp_head', 'feed_links', 2)` must match the exact priority WordPress uses. If a theme or plugin re-adds feed links at a different priority, feeds may still appear in `<head>`.
- Emoji removal also removes admin-side emoji styles. This is intentional and consistent with all major "disable emojis" plugins, but some users may notice missing emoji rendering in admin comments.
- Jetpack may re-register oEmbed routes after WPT removes them, depending on load order. Test with Jetpack active.
- Caching plugins will serve stale pages after toggling. Users should be advised to flush cache after changing settings. Consider hooking into `wpt_settings_updated` to auto-purge if a cache plugin API is available.
- 301 redirects are cached aggressively by browsers. If a user disables author archives, then re-enables them, visitors with cached 301s will still be redirected until their browser cache expires. Consider using 302 during a testing period, then switching to 301.
