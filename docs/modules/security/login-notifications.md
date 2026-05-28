# Login Notifications

## 1. Metadata

- ID: `login-notifications`
- Category: `security`
- Tier: Free
- Risk: Low
- Status: Post-Phase 1
- Replaces: WP Activity Log (login alerts only), Sucuri (login alerts only), Login Alerts
- Related modules: `audit-log`, `session-manager`, `login-security`
- Default enabled: false
- Onboarding profiles: Agency, Security-First

## 2. One-Liner

Sends email alerts when users log in from unrecognized IP addresses or devices, with a one-click "Not you?" link to lock out the attacker.

## 3. Scope

Login Notifications provides:

- Email notification on login from a new IP address.
- Device fingerprinting (User-Agent + screen resolution hash) to reduce false positives.
- Known device/IP tracking stored in user meta.
- Per-role configuration of which roles receive notifications.
- "Not you?" lockout link in the notification email that invalidates all sessions.

## 4. Things NOT To Do

- Do not block logins; this is notification-only (blocking belongs to `login-security`).
- Do not send notifications for failed login attempts (that belongs to `login-security` or `audit-log`).
- Do not store raw IP addresses in email bodies; display city/country via IP geolocation if available, fall back to partial IP (e.g., 192.168.x.x).
- Do not require device fingerprint JS to succeed; treat missing fingerprint as an unknown device.
- Do not send more than one notification email per user per login event.
- Do not store screen resolution or User-Agent in plaintext; store only the hash.
- Do not break login flow if email delivery fails.

## 5. What the User Sees

Admin settings panel under WPTransformed > Security > Login Notifications:

- Toggle: Enable login notifications.
- Role multi-select for which roles receive alerts (default: administrator).
- Maximum known devices per user (default: 10).
- "Not you?" link expiry duration (default: 24 hours).
- Toggle: Include approximate location in email (requires IP geolocation).

Notification email content:

```text
Subject: [Site Name] New login to your account

Hi {display_name},

A new login was detected on your account:

  Time:     April 26, 2026 at 3:15 PM UTC
  Location: New York, US (approximate)
  Device:   Chrome on Windows

If this was you, no action is needed.

If this was NOT you, click below to secure your account immediately:
{not_you_url}

This link expires in 24 hours.
```

## 6. Settings Schema

```json
{
  "login_notifications": {
    "enabled": {
      "type": "boolean",
      "default": true
    },
    "notified_roles": {
      "type": "array",
      "items": "string",
      "default": ["administrator"]
    },
    "max_known_devices": {
      "type": "integer",
      "default": 10,
      "min": 1,
      "max": 50
    },
    "lockout_link_expiry": {
      "type": "integer",
      "default": 86400,
      "min": 3600,
      "max": 604800,
      "description": "Seconds until the Not-you link expires. Default 24h."
    },
    "include_location": {
      "type": "boolean",
      "default": false,
      "description": "Show approximate city/country in email. Requires IP geolocation service."
    },
    "geolocation_provider": {
      "type": "string",
      "default": "none",
      "enum": ["none", "ip-api", "ipinfo", "maxmind-lite"],
      "description": "IP geolocation provider. 'none' disables location display."
    }
  }
}
```

## 7. Hooks & Implementation

Recommended class:

```php
WPT_Login_Notifications
```

Login detection hook:

```php
add_action('wp_login', [$this, 'on_login'], 10, 2);
```

Device fingerprint collection:

```php
add_action('login_footer', [$this, 'render_fingerprint_script']);
```

The fingerprint script sets a hidden field or cookie (`_wpt_device_fp`) containing a SHA-256 hash of `User-Agent + screen.width + screen.height`. The server reads this on login and combines it with the IP address to form a device identity.

"Not you?" handler:

```php
add_action('init', [$this, 'handle_lockout_link']);
```

Filters for external overrides:

```php
apply_filters('wpt_login_notification_skip', false, $user, $ip, $device_hash);
apply_filters('wpt_login_notification_email', $email_args, $user, $login_data);
apply_filters('wpt_known_device_match', $is_known, $user, $ip, $device_hash);
```

## 8. REST API

Endpoints:

```text
GET    /wp-json/wpt/v1/login-notifications/settings
POST   /wp-json/wpt/v1/login-notifications/settings
GET    /wp-json/wpt/v1/login-notifications/devices
DELETE /wp-json/wpt/v1/login-notifications/devices/{device_id}
```

The `devices` endpoint returns the current user's known devices list (hashed, with last-seen timestamp and approximate location label). Users can remove a device to force re-notification on next login from that device.

Permissions:

- Settings endpoints require `manage_wpt`.
- Devices endpoints require the user to be viewing their own devices, or `manage_wpt` to view another user's.

## 9. Database Usage

Known devices stored in user meta:

```text
meta_key: _wpt_known_devices
meta_value: JSON array of {
  "device_id": "<sha256 of ip+device_hash>",
  "ip_hash": "<sha256 of IP>",
  "device_hash": "<sha256 of UA+screen>",
  "first_seen": "<ISO 8601>",
  "last_seen": "<ISO 8601>",
  "label": "Chrome on Windows"
}
```

"Not you?" tokens stored as transients:

```text
transient key: _wpt_lockout_{user_id}_{token_hash}
transient value: { "user_id": int, "created_at": "<ISO 8601>" }
expiry: lockout_link_expiry setting value
```

No custom tables required.

## 10. Exact Behavior

Login notification flow:

1. User logs in successfully (fires `wp_login`).
2. Check if user's role is in `notified_roles`. If not, exit.
3. Check `wpt_login_notification_skip` filter. If true, exit.
4. Compute device identity:
   - Read IP from `$_SERVER['REMOTE_ADDR']` (respect proxy headers via `wpt_get_client_ip()` helper).
   - Read device fingerprint from `$_COOKIE['_wpt_device_fp']` or `$_POST['_wpt_device_fp']`. If absent, use `'unknown'`.
   - Compute `device_id = hash('sha256', $ip . '|' . $device_hash)`.
5. Load `_wpt_known_devices` from user meta.
6. Search for matching `device_id` in known devices array.
7. If match found: update `last_seen`, save meta, exit (no notification).
8. If no match: this is a new device.
   - Add device to known devices array.
   - Trim array to `max_known_devices` (remove oldest by `last_seen`).
   - Save updated meta.
   - Generate "Not you?" token: `$token = wp_generate_password(32, false)`.
   - Store HMAC-signed token: `$signed = hash_hmac('sha256', $token, wp_salt('auth'))`.
   - Store as transient with key `_wpt_lockout_{user_id}_{$signed}` and configured expiry.
   - Build lockout URL: `site_url('?wpt_lockout_action=1&user=' . $user_id . '&token=' . $token)`.
   - Resolve IP location if `include_location` is true and provider is configured.
   - Parse User-Agent for human-readable device label (browser + OS).
   - Send notification email via `wp_mail()`.

"Not you?" lockout flow:

1. On `init`, check for `wpt_lockout_action` query parameter.
2. Validate: `user` param is integer, `token` param is non-empty.
3. Compute `$signed = hash_hmac('sha256', $token, wp_salt('auth'))`.
4. Look up transient `_wpt_lockout_{user_id}_{$signed}`.
5. If transient missing or expired: show "This link has expired" message.
6. If valid:
   - Destroy all sessions for the user via `WP_Session_Tokens::destroy_all()`.
   - Delete the transient (single-use).
   - If `audit-log` module is active, log event: `login_notifications.lockout_triggered`.
   - Show confirmation page: "All sessions have been ended. Please reset your password."
   - Include a link to `wp_lostpassword_url()`.

## 11. Edge Cases

### Shared IP (office NAT)

Multiple users behind the same IP are distinguished by the device fingerprint hash. If two users share both IP and device (same browser, same screen resolution), they will share a device_id. This is acceptable because each user's known devices list is independent.

### VPN users (constantly changing IP)

VPN users will trigger frequent notifications because their IP changes often. Mitigation: if the device fingerprint matches a known device (same UA + screen) but the IP differs, check `wpt_known_device_match` filter. By default, both IP and device hash must match. A future deferred feature may allow device-hash-only matching.

### Email delivery failure

If `wp_mail()` returns false, the login still succeeds. The failure is logged as a PHP warning. The "Not you?" token is still created and stored so the link would work if the email is delayed/retried by a mail queue plugin.

### WP-CLI logins

WP-CLI does not trigger `wp_login` in the standard sense. If a CLI session calls `wp_set_auth_cookie()`, the hook fires but `$_SERVER['REMOTE_ADDR']` is `127.0.0.1` or absent. Detect CLI context via `defined('WP_CLI') && WP_CLI` and skip notification.

### Application Password logins (REST API)

Application Password authentication fires `application_password_did_authenticate` instead of `wp_login`. Hook into this action separately. Device fingerprint will be `'unknown'` (no browser context). Notify based on IP only.

### Multisite

Known devices are stored per-user (user meta), not per-site. Notifications fire on whichever site the login occurred on. The email references that site's name and URL. Settings are per-site via Settings Storage.

### First login after module activation

All devices are unknown on first activation. To avoid a flood of emails, on module activation, record the current device for all users in `notified_roles` who are currently logged in (read from `wp_usermeta` session tokens) as "pre-existing known devices." This is a one-time migration.

### User has no email address

If `$user->user_email` is empty, skip notification. Log a warning.

## 12. Conflicts & Dependencies

Depends on:

- Module Registry
- Settings Storage
- Permission Model

Optional integration:

- `audit-log` module: lockout events are logged if audit-log is active.
- `session-manager` module: provides richer session data if active.
- `login-security` module: complementary (login-security handles blocking, this handles alerting).

Conflicts with:

- WP Activity Log login notification feature (duplicate emails; show conflict-detector info).
- Sucuri login alerts (duplicate emails; recommend disabling Sucuri alerts).
- Login Alerts plugin (direct replacement; show conflict-detector warning).

## 13. Security & Permissions

- "Not you?" tokens are HMAC-signed with `wp_salt('auth')` and stored as transients with expiry.
- Tokens are single-use; the transient is deleted after the lockout action.
- Raw IP addresses are never stored in user meta; only SHA-256 hashes.
- Device fingerprint hashes are not reversible.
- The lockout URL does not require authentication (by design, the user may be locked out).
- The lockout endpoint is rate-limited: 5 requests per minute per IP to prevent abuse.
- Email content does not include the user's password or session tokens.
- Nonce verification on all admin settings form submissions.
- Settings changes require `manage_wpt` capability.
- Device list deletion requires ownership or `manage_wpt`.

## 14. Mobile/Responsive Behavior

- Notification emails use inline CSS compatible with major email clients (Gmail, Outlook, Apple Mail).
- The "Not you?" landing page is a simple, centered layout readable at 320px.
- Admin settings panel follows standard WPTransformed responsive grid.
- No mobile-specific behavior differences; all logic is server-side.

## 15. Data Retention / Uninstall

On module deactivation:

- Settings are retained in Settings Storage.
- Known device meta (`_wpt_known_devices`) is retained.
- Active lockout transients expire naturally.

On plugin uninstall (if clean uninstall enabled):

- Delete all `_wpt_known_devices` user meta entries.
- Delete all transients matching `_wpt_lockout_*`.
- Remove module settings from Settings Storage.

## 16. Verification & Acceptance Criteria

- [ ] Login from a new IP+device sends notification email to the user.
- [ ] Login from a known IP+device does not send a notification.
- [ ] User with a non-notified role does not receive email.
- [ ] "Not you?" link destroys all sessions for the user.
- [ ] "Not you?" link is single-use (second click shows expired).
- [ ] "Not you?" link expires after configured duration.
- [ ] Expired "Not you?" link shows a clear expiration message.
- [ ] Known devices list is trimmed to `max_known_devices`.
- [ ] Device can be removed via REST API, triggering re-notification on next login.
- [ ] WP-CLI login does not trigger notification.
- [ ] Application Password login triggers notification based on IP only.
- [ ] Email delivery failure does not block the login.
- [ ] First activation does not flood existing logged-in users with emails.
- [ ] Raw IP addresses are not stored in user meta (only hashes).
- [ ] Lockout event is logged in audit-log if that module is active.
- [ ] Unauthorized user cannot access another user's device list.
- [ ] Settings changes require `manage_wpt` capability.
- [ ] Email renders correctly in Gmail, Outlook, and Apple Mail.

## 17. Deferred Features

- Device-hash-only matching mode (ignore IP changes for recognized devices).
- SMS/push notification channel (in addition to email).
- Admin dashboard widget showing recent login alerts across all users.
- Configurable email template via Settings Storage.
- Trusted IP allowlist (e.g., office IP range) to suppress notifications.
- Integration with external threat intelligence APIs for IP reputation scoring.
- Slack/webhook notification channel.

## 18. Known Gotchas

- Proxy headers (`X-Forwarded-For`, `X-Real-IP`) can be spoofed. The `wpt_get_client_ip()` helper should only trust proxy headers when the server is behind a known reverse proxy. Provide a filter `wpt_trusted_proxy_headers` for configuration.
- Screen resolution fingerprinting requires JavaScript execution on the login page. Users with JS disabled will always have `device_hash = 'unknown'`, leading to IP-only matching and more frequent false-positive notifications.
- `wp_mail()` is overridden by many SMTP plugins; test with common ones (WP Mail SMTP, FluentSMTP, Post SMTP). If `wp_mail()` throws an exception instead of returning false, wrap in try/catch.
- WordPress transient storage may use `wp_options` if no object cache is active, which does not auto-expire. Use `delete_expired_transients` or check expiry manually in the lockout handler.
- Some security plugins (Wordfence, Sucuri) modify `$_SERVER['REMOTE_ADDR']` before WordPress loads. Document that IP detection may vary based on plugin load order.
- On multisite, `wp_salt('auth')` returns the same value across all sites. Tokens are scoped by `user_id`, which is network-wide, so this is safe.
