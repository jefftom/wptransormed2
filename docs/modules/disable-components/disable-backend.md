# Disable Backend Features

## 1. Metadata

- ID: `disable-backend`
- Category: `disable-components`
- Tier: Free
- Risk: Medium
- Status: Post-Phase 1
- Replaces: Disable XML-RPC (standalone plugins), Disable REST API, Perfmatters (partial)
- Related modules: `disable-frontend`, `disable-gutenberg`
- Default enabled: false
- Onboarding profiles: Performance, Developer

## 2. One-Liner

Lets users toggle off WordPress backend-facing features they do not need -- XML-RPC, public REST API access, REST link headers, and update notifications -- to reduce attack surface and admin noise.

## 3. Scope

Disable Backend Features provides four independent toggles, each removing a discrete piece of WordPress backend or API behavior:

1. **Disable XML-RPC** -- blocks all `xmlrpc.php` requests by returning 403, removes the RSD (Really Simple Discovery) link from `<head>`, removes the `X-Pingback` HTTP header.
2. **Disable REST API for non-authenticated users** -- blocks public (unauthenticated) REST API access while preserving full REST functionality for logged-in users. Gutenberg, WooCommerce, and other admin tools continue working. An explicit opt-in "Full Disable" mode is available with strong warnings.
3. **Disable REST API Link Headers** -- removes the `Link: <...>; rel="https://api.w.org/"` HTTP header and the corresponding `<link>` tag from `<head>`, preventing REST API endpoint discovery.
4. **Disable Update Notifications** -- hides WordPress core, plugin, and theme update nags for non-admin roles. Administrators always see update notices. On multisite, super admins always see notices regardless of this setting.

Each toggle operates independently. Enabling or disabling one has no effect on the others.

## 4. Things NOT To Do

- Do not fully disable the REST API by default. The default behavior is unauthenticated-only blocking.
- Do not break Gutenberg. The Block Editor requires REST API access for authenticated users.
- Do not block Application Passwords authentication when REST is restricted to authenticated users.
- Do not suppress update notifications for administrators or super admins.
- Do not remove the update check mechanism itself -- only hide the notification UI for non-admin roles.
- Do not block XML-RPC at the `.htaccess`/web server level -- this module operates at the PHP level only.
- Do not interfere with Jetpack's XML-RPC-based connection handshake without a clear warning.
- Do not remove REST API routes or controllers -- only gate access with an authentication check.

## 5. What the User Sees

In the module settings panel, four toggle switches with descriptions and contextual warnings:

```text
[x] Disable XML-RPC
    Blocks all xmlrpc.php requests (403) and removes RSD/pingback headers.
    ! Jetpack detected: Jetpack uses XML-RPC for site connection.
      Disabling may break Jetpack sync and stats.

[x] Restrict REST API (unauthenticated)
    Blocks public REST API access. Logged-in users are unaffected.
    [ ] Full Disable (breaks Gutenberg!)
        [!] WARNING: Fully disabling the REST API will break the Block
            Editor, WooCommerce admin, and any plugin that uses REST
            endpoints. Only enable this if you use the Classic Editor
            exclusively and have no REST-dependent plugins.

[ ] Disable REST API Link Headers
    Removes API discovery links from HTTP headers and <head>.

[x] Disable Update Notifications (non-admins)
    Hides update nags for editors, authors, and other non-admin roles.
    Administrators always see update notifications.
```

The "Full Disable" sub-option is only visible when "Restrict REST API" is enabled. It is rendered with a red warning border.

## 6. Settings Schema

```json
{
  "disable_backend": {
    "xmlrpc": {
      "enabled": false
    },
    "rest_api": {
      "restrict_unauthenticated": false,
      "full_disable": false,
      "full_disable_acknowledged": false
    },
    "rest_api_link_headers": {
      "enabled": false
    },
    "update_notifications": {
      "enabled": false
    }
  }
}
```

Field details:

| Field | Type | Default | Allowed Values |
|---|---|---|---|
| `xmlrpc.enabled` | bool | `false` | `true`, `false` |
| `rest_api.restrict_unauthenticated` | bool | `false` | `true`, `false` |
| `rest_api.full_disable` | bool | `false` | `true`, `false` |
| `rest_api.full_disable_acknowledged` | bool | `false` | `true`, `false` |
| `rest_api_link_headers.enabled` | bool | `false` | `true`, `false` |
| `update_notifications.enabled` | bool | `false` | `true`, `false` |

The `full_disable_acknowledged` field is set to `true` when the user explicitly confirms the warning modal. `full_disable` cannot be saved as `true` unless `full_disable_acknowledged` is also `true`. The server rejects the combination `full_disable: true, full_disable_acknowledged: false`.

## 7. Hooks & Implementation

Recommended class: `WPT_Disable_Backend`

### Disable XML-RPC

```php
add_filter('xmlrpc_enabled', '__return_false');
add_filter('xmlrpc_methods', '__return_empty_array');
add_filter('wp_headers', [$this, 'remove_x_pingback_header']);
remove_action('wp_head', 'rsd_link');
// Additionally, intercept xmlrpc.php requests early:
add_action('init', [$this, 'block_xmlrpc_request']);
// block_xmlrpc_request: if request URI is /xmlrpc.php, return 403 and exit.
```

### Restrict REST API (unauthenticated)

```php
add_filter('rest_authentication_errors', [$this, 'restrict_rest_api']);
// restrict_rest_api callback:
// If full_disable is true: return WP_Error for ALL requests (even authenticated).
// If restrict_unauthenticated is true: return WP_Error only if !is_user_logged_in().
// WP_Error code: 'rest_disabled', status: 401.
// Message: 'The REST API is restricted on this site.'
```

### Disable REST API Link Headers

```php
remove_action('wp_head', 'rest_output_link_wp_head');
remove_action('template_redirect', 'rest_output_link_header', 11);
add_filter('rest_url', [$this, 'suppress_rest_url_in_headers']);
```

### Disable Update Notifications

```php
add_action('admin_init', [$this, 'hide_update_nags_for_non_admins']);
// hide_update_nags_for_non_admins:
// If current_user_can('update_core') or current_user_can('update_plugins') or
//    current_user_can('update_themes'): do nothing (admins always see nags).
// Otherwise: remove_action('admin_notices', 'update_nag', 3);
//            add_filter('update_footer', '__return_empty_string', 11);
//            remove update bubble counts from admin menu for this user.
```

Filter for external override:

```php
apply_filters('wpt_disable_backend_active_features', $features);
apply_filters('wpt_rest_api_restriction_bypass', false, $request);
// Allows other modules or plugins to bypass REST restriction for specific requests.
```

## 8. REST API

Endpoints:

```text
GET  /wp-json/wpt/v1/modules/disable-backend/settings
POST /wp-json/wpt/v1/modules/disable-backend/settings
```

Permissions: requires `manage_wpt`.

**Critical note:** These module settings endpoints must be exempted from the REST API restriction. When `restrict_unauthenticated` is true, the WPT settings endpoints are still accessible to authenticated users with `manage_wpt`. When `full_disable` is true, these endpoints must still function for `manage_wpt` users so they can turn the setting off again. The `restrict_rest_api` filter must whitelist `/wp-json/wpt/v1/` routes for users with `manage_wpt`.

## 9. Database Usage

Uses Settings Storage exclusively. No custom tables.

Settings stored under the `disable_backend` key in the WPT options row.

## 10. Exact Behavior

### XML-RPC blocking flow

1. `init` hook fires at default priority.
2. `block_xmlrpc_request` checks if `$_SERVER['REQUEST_URI']` contains `xmlrpc.php`.
3. If matched and `xmlrpc.enabled` is true: send `HTTP/1.1 403 Forbidden` header and `wp_die('XML-RPC is disabled.', 'Forbidden', ['response' => 403])`.
4. Additionally, `xmlrpc_enabled` filter returns false and `xmlrpc_methods` returns empty array as defense-in-depth for requests that bypass the URI check.

### REST API restriction flow

1. `rest_authentication_errors` filter fires on every REST request.
2. If `full_disable` is true AND `full_disable_acknowledged` is true:
   - Check if the route matches `/wpt/v1/` AND user has `manage_wpt`. If so, allow (return null).
   - Otherwise, return `WP_Error('rest_disabled', 'REST API is fully disabled.', ['status' => 403])`.
3. If `restrict_unauthenticated` is true (and `full_disable` is false):
   - If `is_user_logged_in()` returns true, allow (return null).
   - Check `apply_filters('wpt_rest_api_restriction_bypass', false, $request)`. If true, allow.
   - Otherwise, return `WP_Error('rest_not_logged_in', 'REST API access requires authentication.', ['status' => 401])`.
4. If neither setting is active, return null (no restriction).

### Full disable acknowledgment flow

1. User enables "Restrict REST API" toggle.
2. Sub-option "Full Disable" checkbox appears.
3. User checks "Full Disable."
4. A confirmation modal appears: "Fully disabling the REST API will break the Block Editor (Gutenberg), WooCommerce admin, and many other plugins. Are you sure? This is not recommended unless you exclusively use the Classic Editor."
5. User clicks "I understand, disable completely."
6. `full_disable_acknowledged` is set to `true`.
7. `full_disable` is set to `true`.
8. Settings save.

If the user unchecks `full_disable` and later re-checks it, the acknowledgment modal appears again. `full_disable_acknowledged` is reset to `false` whenever `full_disable` is set to `false`.

### Update notification hiding flow

1. `admin_init` fires.
2. Module checks if `update_notifications.enabled` is true.
3. Module checks if current user has any update-related capability (`update_core`, `update_plugins`, `update_themes`).
4. If user has update capabilities: do nothing. Admins always see notifications.
5. If user lacks update capabilities: remove `update_nag` action, clear footer update text, remove update count bubbles from menu items.

## 11. Edge Cases

### Gutenberg requires REST API

This is the single most important edge case. The Block Editor makes dozens of REST calls per editing session (`/wp/v2/posts`, `/wp/v2/blocks`, `/wp/v2/media`, etc.). If `full_disable` is true, authenticated editors can no longer use Gutenberg. This is why `full_disable` defaults to false, requires explicit acknowledgment, and is hidden behind the primary toggle.

When `restrict_unauthenticated` is true (the safe default), Gutenberg works normally because all editor sessions are authenticated.

### WooCommerce REST API

WooCommerce exposes a public REST API at `/wp-json/wc/v3/` that external integrations (POS systems, mobile apps) use with API keys. WooCommerce API key authentication uses a custom auth handler that sets `is_user_logged_in()` context. When `restrict_unauthenticated` is true, WooCommerce API-key-authenticated requests will pass because they appear authenticated. If WooCommerce is detected and `full_disable` is true, show a warning: "WooCommerce REST API will stop working, breaking external integrations."

### Jetpack XML-RPC connection

Jetpack uses XML-RPC for site-to-cloud sync, stats, and several features. If Jetpack is active and `xmlrpc.enabled` is true, show a persistent warning: "Jetpack uses XML-RPC for site connection. Disabling XML-RPC will break Jetpack sync, stats, and related features." Do not auto-exempt Jetpack -- let the user decide.

### Contact Form 7 REST endpoints

CF7 uses `/wp-json/contact-form-7/v1/` for AJAX form submissions. These are unauthenticated requests from site visitors. If `restrict_unauthenticated` is true, CF7 form submissions will fail with a 401 error. Show a warning when CF7 is active: "Contact Form 7 uses the REST API for form submissions. Restricting unauthenticated REST access will break contact forms."

### Application Passwords

WordPress Application Passwords authenticate via REST API using `Authorization: Basic` headers. When `restrict_unauthenticated` is true, Application Password requests are authenticated (the auth handler runs before `rest_authentication_errors`), so they will pass. No special handling needed.

### Multisite super admin vs site admin notifications

On multisite, `update_core` is typically restricted to super admins. Site admins may have `update_plugins` and `update_themes`. The module checks the current user's actual capabilities, so it works correctly on multisite without special handling. Super admins always see all notifications.

### Managed hosting that handles updates

On hosts like WP Engine, Kinsta, or Pantheon, core updates may be managed by the host. Update notifications may still appear in WordPress even though the host handles updates. This module hides those nags for non-admin roles. The module does not detect managed hosting -- it simply hides the notification UI. This is correct behavior.

### Self-locking via full REST disable

If a user enables `full_disable` and then cannot access the admin (e.g., Gutenberg-based editing breaks), they can still access the Classic Editor or the WPT settings panel (which uses its own whitelisted REST route) to turn the setting off. As an additional safety net, the Recovery Center should detect `full_disable: true` and offer a one-click reset.

## 12. Conflicts & Dependencies

Depends on:

- Module Registry
- Settings Storage
- Module Loader
- Recovery Center (for full_disable safety net)

Potential conflicts:

| Plugin | Conflict | Severity |
|---|---|---|
| Perfmatters | Overlapping toggles for XML-RPC, REST headers | Warning |
| Disable XML-RPC | Duplicate XML-RPC blocking | Info |
| Disable REST API | Duplicate REST restriction | Warning |
| Jetpack | XML-RPC required for connection | Warning |
| Contact Form 7 | REST API required for form submissions | Warning |
| WooCommerce | REST API required for external integrations | Warning |
| iThemes Security | May also disable XML-RPC and REST discovery | Info |
| Wordfence | May have its own XML-RPC blocking | Info |

Conflict Detector should show replacement opportunity for single-purpose "disable" plugins and warn about REST-dependent plugins.

## 13. Security & Permissions

- Requires `manage_wpt` capability to change settings.
- XML-RPC blocking improves security by closing a common brute-force vector.
- REST API restriction reduces public information disclosure.
- `full_disable` requires explicit acknowledgment to prevent accidental lockout.
- The `full_disable_acknowledged` field prevents API-based bypass: the REST endpoint validates that both fields are true before accepting the combination.
- Update notification hiding does NOT suppress security-critical notices for administrators.
- XML-RPC 403 response does not reveal WordPress version or plugin information.
- Nonce verification required for all settings changes.
- The `wpt_rest_api_restriction_bypass` filter must be used carefully -- document that plugins using it should validate the request context.

## 14. Mobile/Responsive Behavior

This module has no frontend UI. Settings panel toggles must be tap-friendly (minimum 44px touch target). Warning notices and the full-disable confirmation modal must be readable and dismissible at 320px viewport width.

The full-disable warning modal must not rely on hover states for critical information.

## 15. Data Retention / Uninstall

- Settings are stored via Settings Storage and follow the global retention policy.
- On module disable: all hooks are removed, WordPress default behavior resumes immediately. XML-RPC becomes accessible, REST API becomes public, link headers reappear, update notifications show for all roles.
- On plugin uninstall: settings are deleted with all other WPT data per the global uninstall policy.
- No external data is stored or modified.
- `full_disable_acknowledged` is reset to `false` whenever the module is disabled, requiring re-acknowledgment if re-enabled with full disable.

## 16. Verification & Acceptance Criteria

- [ ] Disabling XML-RPC returns 403 for `POST /xmlrpc.php`.
- [ ] Disabling XML-RPC removes `<link rel="EditURI">` (RSD link) from `<head>`.
- [ ] Disabling XML-RPC removes `X-Pingback` HTTP header.
- [ ] Restricting unauthenticated REST API returns 401 for `GET /wp-json/wp/v2/posts` when not logged in.
- [ ] Restricting unauthenticated REST API allows `GET /wp-json/wp/v2/posts` when logged in.
- [ ] Gutenberg editor works normally with `restrict_unauthenticated: true, full_disable: false`.
- [ ] Full disable blocks REST API for all users except `manage_wpt` on `/wpt/v1/` routes.
- [ ] Full disable cannot be saved without `full_disable_acknowledged: true`.
- [ ] Confirmation modal appears when enabling full disable.
- [ ] Re-enabling full disable after disabling it requires re-acknowledgment.
- [ ] Disabling REST link headers removes `Link` HTTP header with `rel="https://api.w.org/"`.
- [ ] Disabling REST link headers removes `<link rel="https://api.w.org/">` from `<head>`.
- [ ] Update notifications are hidden for editor/author roles when enabled.
- [ ] Update notifications are visible for administrators regardless of setting.
- [ ] On multisite, super admins always see update notifications.
- [ ] Jetpack warning appears when XML-RPC is disabled and Jetpack is active.
- [ ] CF7 warning appears when REST is restricted and Contact Form 7 is active.
- [ ] WooCommerce warning appears when full disable is enabled and WooCommerce is active.
- [ ] WooCommerce API-key-authenticated requests pass when `restrict_unauthenticated` is true.
- [ ] Application Password requests pass when `restrict_unauthenticated` is true.
- [ ] Recovery Center can reset `full_disable` to `false`.
- [ ] Non-authorized users cannot change settings.
- [ ] Disabling the module restores all default WordPress behavior immediately.

## 17. Deferred Features

- Allowlist specific REST namespaces for unauthenticated access (e.g., allow `/contact-form-7/` but block `/wp/v2/`).
- Per-IP or per-user-agent XML-RPC allowlist (for specific integrations that need it).
- Disable WordPress heartbeat API (related but distinct; may become its own toggle).
- Disable application passwords entirely.
- Disable file editing via admin (DISALLOW_FILE_EDIT equivalent as a toggle).
- REST API rate limiting for unauthenticated requests (as an alternative to full blocking).
- Auto-detect REST-dependent plugins and show a compatibility checklist before enabling restrictions.

## 18. Known Gotchas

- The `rest_authentication_errors` filter runs after WordPress has already set up the REST infrastructure. It does not prevent REST route registration or schema generation -- it only gates the response. Some information about available routes may still be discoverable even with `restrict_unauthenticated` enabled.
- Caching plugins may cache REST API responses. A cached unauthenticated response from before restriction was enabled may still be served until the cache expires. Advise users to flush cache after changing REST settings.
- Some security plugins (Wordfence, iThemes) block XML-RPC at a higher level. If both WPT and a security plugin block XML-RPC, there is no functional conflict, but Conflict Detector should note the redundancy.
- The `xmlrpc.php` URI check uses `$_SERVER['REQUEST_URI']` which may not be reliable behind certain reverse proxies. The `xmlrpc_enabled` and `xmlrpc_methods` filters provide defense-in-depth.
- When `full_disable` is true, the WordPress admin bar's "Edit Post" links still appear but clicking them loads Gutenberg, which then fails to fetch post data via REST. This creates a confusing UX. The full-disable warning should mention switching to Classic Editor.
- Some managed WordPress hosts pre-block XML-RPC at the server level. Enabling the WPT XML-RPC toggle on these hosts is harmless but redundant. No detection of server-level blocking is attempted.
- Update notification hiding removes the bubble count from menu items (e.g., "Plugins (3)"). This is purely cosmetic for the affected roles -- it does not suppress any `do_action('admin_notices')` calls from plugins, only core WordPress update nags.
