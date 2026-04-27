# Recovery Center / Safe Mode

## 1. Metadata

- ID: `recovery-center`
- Category: `system`
- Tier: Core
- Risk: Advanced
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-loader`, `settings-storage`, `conflict-detector`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

A recovery system that helps administrators disable problematic modules, restore known-good configuration, and regain access if something breaks.

## 3. Scope

Recovery Center handles:

- Safe Mode URL.
- Disable all modules for one request.
- Disable last activated module.
- Quarantine crashing modules.
- Restore previous known-good configuration.
- Recovery email.
- Admin recovery UI.
- Operation log entries for recovery actions.

## 4. Things NOT To Do

- Do not expose Safe Mode to public users.
- Do not make recovery token guessable.
- Do not permanently delete settings during recovery.
- Do not require Pro license for recovery.
- Do not depend on optional modules to load recovery UI.
- Do not hide crash information from administrators.

## 5. What the User Sees

Admins may see:

- Recovery Center page.
- “Safe Mode is active” banner.
- Quarantined module list.
- Buttons:
  - Disable all modules
  - Disable last activated module
  - Restore last known-good configuration
  - Clear quarantine
  - Re-enable module
- Recovery email with safe link after crash.

## 6. Settings Schema

```json
{
  "recovery": {
    "safe_mode_token_hash": "hash",
    "last_known_good_config_id": "abc123",
    "last_enabled_module": "change-login-url",
    "quarantined_modules": {
      "code-snippets": {
        "reason": "Fatal error during init",
        "error_message": "Redacted message",
        "quarantined_at": "2026-04-26T15:00:00Z"
      }
    }
  }
}
```

## 7. Hooks & Implementation

Recommended classes:

```php
WPT_Recovery_Center
WPT_Safe_Mode
WPT_Module_Sandbox
```

Safe Mode activation methods:

- Query param with signed token.
- Admin UI toggle.
- Recovery link sent to admin email.

Action hooks:

```php
do_action('wpt_safe_mode_triggered', $context);
do_action('wpt_module_quarantined', $module_id, $error);
do_action('wpt_recovery_action_completed', $action, $result);
```

## 8. REST API

Endpoints:

```text
GET /wp-json/wpt/v1/recovery/status
POST /wp-json/wpt/v1/recovery/disable-all
POST /wp-json/wpt/v1/recovery/disable-last
POST /wp-json/wpt/v1/recovery/restore-known-good
POST /wp-json/wpt/v1/recovery/clear-quarantine
POST /wp-json/wpt/v1/recovery/modules/{id}/reenable
```

Permissions:

- Requires `manage_wpt`.
- Dangerous recovery actions may require `run_wpt_dangerous_tools`.

## 9. Database Usage

Uses Settings Storage.

Optional future table:

- Operation log table for recovery actions.

## 10. Exact Behavior

Safe Mode request:

1. Validate token.
2. Set request-level safe mode.
3. Prevent optional modules from loading.
4. Load only core recovery services.
5. Show Safe Mode banner.
6. Allow admin to disable/repair modules.

Crash quarantine:

1. Module Loader marks module as “loading.”
2. If fatal occurs, marker remains.
3. On next request, Recovery Center detects stale marker.
4. Module is quarantined.
5. Admin notice is shown.
6. Recovery email is sent, rate-limited.

Disable last module:

1. Read last enabled module.
2. Disable it.
3. Save settings.
4. Log action.
5. Show success message.

Restore known-good:

1. Load last known-good config.
2. Apply it.
3. Preserve current config as rollback point.
4. Log action.

## 11. Edge Cases

### Admin locked out by Change Login URL

- Safe Mode URL must bypass login URL module behavior.

### Fatal in Pro module with expired license

- Quarantine and disable Pro module.
- Preserve settings.

### Recovery email cannot send

- Show recovery link in admin where possible.
- Log email failure.

### Multisite

- Safe Mode should apply to current site in Phase 1.
- Network-wide recovery deferred.

## 12. Conflicts & Dependencies

Depends on:

- Module Loader
- Settings Storage
- Permission Model

Must load before optional modules.

## 13. Security & Permissions

- Recovery token must be signed/hash-based.
- Do not store raw token.
- Nonces required for UI actions.
- Recovery URL should expire or be rotatable.
- Sensitive errors should be redacted for display.

## 14. Mobile/Responsive Behavior

Recovery UI should be simple and mobile-friendly:

- Single-column cards.
- Large action buttons.
- Clear warning text.
- No complex tables required.

## 15. Data Retention / Uninstall

- Quarantine records kept until cleared or uninstall.
- Recovery tokens removed on uninstall.
- Known-good configs follow settings retention policy.

## 16. Verification & Acceptance Criteria

- [ ] Safe Mode URL disables optional modules for request.
- [ ] Invalid token does not enable Safe Mode.
- [ ] Last enabled module can be disabled.
- [ ] Crashing module is quarantined on next load.
- [ ] Quarantined module does not load.
- [ ] Recovery Center is accessible in Safe Mode.
- [ ] Recovery email is sent after crash when email works.
- [ ] Recovery email is rate-limited.
- [ ] Known-good config can be restored.
- [ ] Settings are not deleted during recovery.
- [ ] Mobile layout is usable.

## 17. Deferred Features

- MU-plugin recovery drop-in.
- Network-wide Safe Mode.
- One-click backup integration before restore.

## 18. Known Gotchas

- PHP fatal detection is imperfect; use shutdown markers.
- Recovery cannot help if the whole plugin cannot load at all unless MU-plugin recovery is implemented.
