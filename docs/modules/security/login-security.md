# Login Security

## 1. Metadata

- ID: `login-security`
- Category: `security`
- Tier: Free
- Risk: High (can lock users out if misconfigured)
- Status: Post-Phase 1
- Replaces: Limit Login Attempts Reloaded, WPS Hide Login, Login LockDown, reCAPTCHA plugins
- Related modules: `login-notifications`, `two-factor-auth`, `session-manager`, `strong-passwords`
- Default enabled: false
- Onboarding profiles: All (recommended for every site)

## 2. One-Liner

Hardens the WordPress login page with attempt limiting, CAPTCHA integration, custom login URL, and login identifier restrictions.

## 3. Scope

Login Security consolidates four sub-features:

1. **Limit Login Attempts** -- track failed login attempts per IP and per username, enforce configurable lockout windows with progressive escalation.
2. **CAPTCHA Protection** -- integrate Google reCAPTCHA v2, reCAPTCHA v3, or hCaptcha on login, registration, lost-password, and comment forms.
3. **Change Login URL** -- move `wp-login.php` to a custom slug and return 404 (or redirect to homepage) for the default path.
4. **Login ID Type** -- restrict the login identifier to username only, email only, or both.

## 4. Things NOT To Do

- Do not modify core WordPress login files on disk.
- Do not store plain-text IPs in user-visible UI without masking last octet option.
- Do not auto-enable CAPTCHA without valid site key + secret key saved.
- Do not break WP-CLI `wp user` commands with lockout logic.
- Do not intercept Application Password authentication for Change Login URL (Application Passwords use REST, not wp-login.php).
- Do not lock out the currently logged-in administrator who is saving settings.
- Do not override `two-factor-auth` module logic -- they are complementary, not overlapping.
- Do not hide wp-admin for logged-in users when Change Login URL is active.

## 5. What the User Sees

**Settings tab with four collapsible sections:**

1. **Limit Login Attempts** -- toggle, max attempts slider, lockout duration, progressive lockout toggle, IP whitelist/blacklist textarea, lockout log table (last 50 entries).
2. **CAPTCHA Protection** -- provider dropdown (None / reCAPTCHA v2 / reCAPTCHA v3 / hCaptcha), site key field, secret key field, checkboxes for each form (login, registration, lost password, comments), reCAPTCHA v3 score threshold slider (0.1--1.0, default 0.5).
3. **Change Login URL** -- toggle, custom slug text field, behavior dropdown (404 page / redirect to homepage), recovery notice banner showing the safe-mode reset URL.
4. **Login ID Type** -- radio group (Username only / Email only / Both).

**Lockout log** visible in a sub-tab: timestamp, IP (masked or full per setting), username attempted, lockout duration, status (active / expired / manually cleared).

## 6. Settings Schema

```json
{
  "login_security": {
    "limit_attempts": {
      "enabled": true,
      "max_attempts": 5,
      "lockout_duration": 900,
      "progressive_lockout": true,
      "progressive_multiplier": 2,
      "max_lockout_duration": 86400,
      "track_by": "ip_and_username",
      "ip_whitelist": [],
      "ip_blacklist": [],
      "trust_proxy_headers": false,
      "count_xmlrpc": true,
      "count_rest_auth": true
    },
    "captcha": {
      "provider": "none",
      "site_key": "",
      "secret_key": "",
      "apply_login": true,
      "apply_registration": true,
      "apply_lost_password": true,
      "apply_comments": false,
      "v3_score_threshold": 0.5
    },
    "custom_login_url": {
      "enabled": false,
      "slug": "",
      "default_behavior": "404",
      "redirect_url": ""
    },
    "login_id_type": "both"
  }
}
```

Field notes:
- `lockout_duration`: integer, seconds, default 900 (15 minutes).
- `max_lockout_duration`: ceiling for progressive lockout, default 86400 (24 hours).
- `track_by`: enum `ip_only | username_only | ip_and_username`.
- `provider`: enum `none | recaptcha_v2 | recaptcha_v3 | hcaptcha`.
- `slug`: alphanumeric and hyphens only, 3--40 characters, validated on save.
- `login_id_type`: enum `username | email | both`.

## 7. Hooks & Implementation

Primary class: `WPT_Login_Security`

Sub-components:
- `WPT_Login_Limiter` -- hooks `wp_login_failed`, `wp_authenticate`, `authenticate`.
- `WPT_Login_Captcha` -- hooks `login_form`, `register_form`, `lostpassword_form`, `comment_form_after_fields`, `pre_comment_approved`.
- `WPT_Custom_Login_URL` -- hooks `init` (rewrite rules), `wp_redirect`, `login_url`, `site_url`, `network_site_url`.
- `WPT_Login_ID_Restriction` -- hooks `authenticate` to reject disallowed identifier types.

Filters:
```php
apply_filters( 'wpt_login_security_ip', $ip );
apply_filters( 'wpt_login_limiter_skip', false, $username, $ip );
apply_filters( 'wpt_captcha_required', true, $form_context );
apply_filters( 'wpt_custom_login_slug', $slug );
```

Actions:
```php
do_action( 'wpt_login_lockout', $ip, $username, $duration );
do_action( 'wpt_login_lockout_cleared', $ip, $username );
```

## 8. REST API

```text
GET    /wp-json/wpt/v1/login-security/lockouts       -- list active lockouts (paginated)
DELETE /wp-json/wpt/v1/login-security/lockouts/{id}   -- clear a specific lockout
POST   /wp-json/wpt/v1/login-security/lockouts/clear  -- clear all lockouts
GET    /wp-json/wpt/v1/login-security/log             -- lockout event log (paginated)
POST   /wp-json/wpt/v1/login-security/captcha/verify  -- test CAPTCHA keys are valid
```

Permissions: all endpoints require `manage_wpt`.

## 9. Database Usage

Custom table: `{prefix}wpt_login_attempts`

```sql
CREATE TABLE {prefix}wpt_login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  username    VARCHAR(60) NOT NULL,
  attempted_at DATETIME NOT NULL,
  locked_until DATETIME DEFAULT NULL,
  lockout_count TINYINT UNSIGNED DEFAULT 0,
  INDEX idx_ip (ip),
  INDEX idx_username (username),
  INDEX idx_locked_until (locked_until)
);
```

Rows with `locked_until` in the past are eligible for cleanup. A daily WP-Cron event (`wpt_login_attempts_cleanup`) deletes rows older than 30 days.

Settings stored via Settings Storage module (option `wpt_login_security`).

## 10. Exact Behavior

### Limit Login Attempts

1. On `authenticate` filter, check if IP or username is currently locked out. If yes, return `WP_Error` immediately (do not run further authenticate filters).
2. On `wp_login_failed`, increment attempt count for IP and username.
3. If attempt count >= `max_attempts`, insert lockout row with `locked_until = now + lockout_duration * (progressive_multiplier ^ previous_lockout_count)`, capped at `max_lockout_duration`.
4. Fire `wpt_login_lockout` action.
5. If `trust_proxy_headers` is true, read IP from `X-Forwarded-For` (first untrusted hop). Otherwise use `REMOTE_ADDR`.
6. If `count_xmlrpc` is true, hook `xmlrpc_call` for `wp.getUsersBlogs` and `wp.getProfile` to route through the same limiter.
7. If `count_rest_auth` is true, hook `rest_authentication_errors` to check lockout before REST auth proceeds.
8. WP-CLI context (`defined('WP_CLI') && WP_CLI`): skip lockout checks entirely.

### CAPTCHA Protection

1. If `provider` is `none` or keys are empty, do nothing.
2. Render CAPTCHA widget on selected forms via the corresponding form hooks.
3. On form submission, server-side verify the CAPTCHA token against the provider API.
4. For reCAPTCHA v3, compare returned score against `v3_score_threshold`. Fail if below.
5. On CAPTCHA failure, return `WP_Error` with translatable message. Do not count as a login attempt for lockout purposes.

### Change Login URL

1. On `init`, add rewrite rule: `{slug}` -> `wp-login.php` (internal rewrite).
2. On direct request to `/wp-login.php` (not via rewrite), if user is not logged in, return 404 or redirect to homepage based on `default_behavior`.
3. If user is logged in and requests `/wp-admin`, allow normally (WordPress handles redirect to login if session expired, which uses `login_url` filter).
4. Filter `login_url`, `site_url`, `network_site_url` to replace `wp-login.php` with custom slug in all generated URLs (password reset emails, login redirects).
5. Store slug in a standalone option `wpt_custom_login_slug` (not only in grouped settings) so Safe Mode can read it without loading the full module.

### Login ID Type

1. On `authenticate` filter (priority 20), inspect the submitted username field.
2. If `login_id_type` is `username` and value contains `@`, return `WP_Error`.
3. If `login_id_type` is `email` and value does not contain `@`, return `WP_Error`.
4. If `both`, pass through.

## 11. Edge Cases

### Shared IPs (corporate NAT)

`track_by: ip_and_username` prevents one user's failed attempts from locking out all users behind the same IP. The per-username track provides a second dimension. Admin UI warns about shared-IP environments when `ip_only` is selected.

### Proxy headers (X-Forwarded-For)

`trust_proxy_headers` defaults to `false`. When enabled, extract the rightmost untrusted IP from the X-Forwarded-For chain. Show a warning that this must match the server's proxy configuration.

### WP-CLI login bypass

All lockout checks are skipped when `WP_CLI` is defined. This is the primary recovery path for lockouts.

### XML-RPC brute force

When `count_xmlrpc` is true, XML-RPC authentication attempts count toward the same limiter. System.multicall is handled by counting each embedded auth attempt individually.

### Application Password authentication

Application Passwords authenticate via REST headers, not wp-login.php. If `count_rest_auth` is true, these count. Change Login URL does not affect REST authentication.

### CAPTCHA on AJAX login forms (WooCommerce)

CAPTCHA is rendered only on standard WordPress form hooks. For WooCommerce AJAX login, provide a `wpt_captcha_required` filter that WooCommerce-aware code can use. Document the integration pattern.

### CAPTCHA on cached pages

reCAPTCHA tokens are generated client-side via JavaScript. The server-side verify call uses a nonce that is per-submission, not per-pageload. Page caching does not affect functionality.

### Forgotten custom login URL

**Critical recovery path:** The custom slug is stored in `wpt_custom_login_slug` option. Recovery options:
1. WP-CLI: `wp option delete wpt_custom_login_slug`
2. Safe Mode URL: `?wpt-safe-mode=1` (if Recovery Center is active)
3. Direct database edit: `DELETE FROM wp_options WHERE option_name = 'wpt_custom_login_slug'`

The settings UI shows a persistent warning banner with the recovery instructions whenever Change Login URL is enabled.

### WooCommerce My Account login

WooCommerce uses `wp_login_url()` which is filtered to return the custom slug. The My Account page login form posts to `wp-login.php` internally via the rewrite, so it continues working.

### Password reset emails with custom URL

The `login_url` and `network_site_url` filters ensure that password reset links in emails use the custom slug instead of `wp-login.php`.

### Plugins that hardcode wp-login.php

Plugins that hardcode `wp-login.php` in links (instead of using `wp_login_url()`) will produce broken links. This is documented as a known limitation in the settings panel.

### WooCommerce email-based login

When `login_id_type` is `username`, WooCommerce customers who registered with email may be unable to log in. The settings panel shows a warning if WooCommerce is active and `login_id_type` is not `both` or `email`.

### Multisite custom login URL

Each site in a multisite network can have its own custom slug. The rewrite rules and option are per-site.

## 12. Conflicts & Dependencies

**Depends on:**
- `module-registry` (registration)
- `settings-storage` (persist settings)
- `permission-model` (capability checks)
- `recovery-center` (Safe Mode bypass for custom login URL)

**Conflicts with:**
- Limit Login Attempts Reloaded / Login LockDown / Loginizer -- double lockout enforcement. Severity: Warning. Recommendation: disable the third-party plugin.
- WPS Hide Login / Rename wp-login.php -- double URL rewrite. Severity: Critical. Recommendation: disable the third-party plugin before enabling Change Login URL.
- reCAPTCHA / hCaptcha standalone plugins -- double CAPTCHA rendering. Severity: Warning. Recommendation: disable the standalone plugin.
- iThemes Security / Wordfence login limits -- overlap on limit-attempts. Severity: Warning. Show only if both are active simultaneously.

**Complementary:**
- `two-factor-auth` -- Login Security handles pre-authentication hardening; 2FA handles post-credential verification. No overlap.
- `login-notifications` -- consumes `wpt_login_lockout` action to send email alerts.

## 13. Security & Permissions

- Settings require `manage_wpt` capability.
- Lockout log may contain IP addresses; respect privacy settings (mask last octet option).
- CAPTCHA secret keys are stored encrypted in the database via Settings Storage encryption if available, otherwise as plain option values.
- Rate-limit the CAPTCHA verify endpoint to prevent abuse.
- Custom login slug must not collide with existing WordPress slugs, pages, or registered rewrite rules. Validate on save.
- Sanitize all stored IPs, usernames, and slugs. Use `sanitize_text_field()` for IPs and usernames, `sanitize_title()` for the slug.

## 14. Mobile/Responsive Behavior

- CAPTCHA widgets (reCAPTCHA badge, hCaptcha checkbox) must not overflow narrow login forms. Use responsive container styles.
- Lockout log table scrolls horizontally on mobile.
- Settings sections use accordion layout, collapsed by default on mobile.

## 15. Data Retention / Uninstall

- `wpt_login_attempts` table rows older than 30 days are purged by daily cron.
- On module disable: settings are retained, lockout table is retained (no data loss).
- On plugin uninstall (clean uninstall option): drop `wpt_login_attempts` table, delete `wpt_login_security` option, delete `wpt_custom_login_slug` option.
- CAPTCHA keys are deleted on uninstall.

## 16. Verification & Acceptance Criteria

- [ ] Five failed logins lock out the IP for 15 minutes with default settings.
- [ ] Progressive lockout doubles duration on each subsequent lockout.
- [ ] Lockout duration does not exceed `max_lockout_duration`.
- [ ] Whitelisted IP is never locked out.
- [ ] Blacklisted IP is always rejected regardless of attempt count.
- [ ] WP-CLI `wp user` commands work during an active lockout.
- [ ] XML-RPC authentication attempts count toward lockout when enabled.
- [ ] reCAPTCHA v2 checkbox renders on login form and blocks submission on failure.
- [ ] reCAPTCHA v3 blocks login when score is below threshold.
- [ ] hCaptcha renders and validates correctly.
- [ ] CAPTCHA failure does not count as a lockout attempt.
- [ ] Custom login URL serves the login form.
- [ ] Default `/wp-login.php` returns 404 when custom URL is active and user is not logged in.
- [ ] Logged-in user accessing `/wp-admin` is not affected by custom login URL.
- [ ] Password reset email contains custom login URL, not `wp-login.php`.
- [ ] Recovery via WP-CLI `wp option delete wpt_custom_login_slug` restores default login.
- [ ] Login ID type `username` rejects email-based login.
- [ ] Login ID type `email` rejects username-based login.
- [ ] WooCommerce warning appears when `login_id_type` is `username` and WooCommerce is active.
- [ ] Unauthorized user cannot access lockout log or settings.
- [ ] Lockout entries older than 30 days are cleaned up by cron.

## 17. Deferred Features

- GeoIP-based blocking (block by country).
- Passwordless / magic-link login.
- Honeypot fields as a CAPTCHA alternative.
- Turnstile (Cloudflare) as additional CAPTCHA provider.
- Lockout notifications via Slack/webhook (beyond email via `login-notifications`).
- Import lockout history from Limit Login Attempts Reloaded.

## 18. Known Gotchas

- `trust_proxy_headers` can be spoofed if the server is not behind a real reverse proxy. Default is off for this reason.
- WordPress core sends password reset emails using `network_site_url()` in multisite and `site_url()` in single-site. Both must be filtered.
- Some hosting providers (WP Engine, Kinsta) have their own login-limiting at the infrastructure level. Double limiting may confuse users; add a note in the settings if known managed hosts are detected.
- Changing the login slug requires flushing rewrite rules. The module must call `flush_rewrite_rules()` on save but avoid calling it on every page load.
- reCAPTCHA v3 score threshold is subjective. Default 0.5 may be too aggressive for sites with unusual traffic patterns. Provide guidance text in the UI.
- If Recovery Center module is disabled, the Safe Mode bypass for forgotten custom login URL will not work. Warn the user if they enable Change Login URL without Recovery Center active.
