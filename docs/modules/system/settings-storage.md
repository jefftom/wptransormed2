# Settings Storage

## 1. Metadata

- ID: `settings-storage`
- Category: `system`
- Tier: Core
- Risk: Advanced
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-registry`, `module-loader`, `import-export`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

A centralized settings service that stores WPTransformed configuration safely, validates schemas, avoids autoload bloat, and supports import/export.

## 3. Scope

Settings Storage handles:

- Module enabled state.
- Module settings.
- Global WPTransformed settings.
- Blueprint/import/export data.
- Schema validation.
- Default settings.
- Redaction of secrets.
- Migration/versioning of settings.

## 4. Things NOT To Do

- Do not dump all settings into one autoloaded `wp_options` row.
- Do not store API keys unmasked in UI responses.
- Do not accept unsanitized settings.
- Do not allow modules to write arbitrary option names directly.
- Do not delete settings on module disable.
- Do not expose secrets through REST.

## 5. What the User Sees

Users see:

- Settings forms.
- Saved state.
- Import/export success messages.
- Redacted secrets.
- Reset settings options.
- Validation errors.

## 6. Settings Schema

Global settings example:

```json
{
  "version": "1.0.0",
  "modules": {},
  "ui": {
    "theme": "system",
    "reduced_motion": false
  },
  "data_retention": {
    "audit_log_days": 90,
    "login_log_days": 90,
    "notifications_days": 30,
    "email_log_days": 30
  }
}
```

Module settings example:

```json
{
  "module_id": "admin-bar-manager",
  "settings": {
    "remove_wp_logo": true,
    "remove_comments": true
  },
  "updated_at": "2026-04-26T15:00:00Z",
  "updated_by": 1
}
```

## 7. Hooks & Implementation

Recommended classes:

```php
WPT_Settings_Repository
WPT_Settings_Schema_Validator
WPT_Secrets_Manager
```

Recommended table:

```text
{prefix}wpt_settings
```

Filters:

```php
apply_filters('wpt_module_default_settings', $settings, $module_id);
apply_filters('wpt_module_settings_schema', $schema, $module_id);
apply_filters('wpt_settings_before_save', $settings, $module_id);
```

Actions:

```php
do_action('wpt_module_settings_saved', $module_id, $settings);
```

## 8. REST API

Endpoints:

```text
GET /wp-json/wpt/v1/settings
GET /wp-json/wpt/v1/settings/{module_id}
PUT /wp-json/wpt/v1/settings/{module_id}
POST /wp-json/wpt/v1/settings/{module_id}/reset
```

Permissions:

- Read settings: `manage_wpt_settings`
- Update settings: `manage_wpt_settings`
- Dangerous settings: module-specific capability + `run_wpt_dangerous_tools`

Secrets must be redacted:

```json
{
  "api_key": "••••••••••••abcd",
  "has_api_key": true
}
```

## 9. Database Usage

Recommended table:

```sql
CREATE TABLE {$prefix}wpt_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_id VARCHAR(100) NOT NULL,
  setting_key VARCHAR(100) NOT NULL DEFAULT 'default',
  settings LONGTEXT NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY module_setting (module_id, setting_key),
  KEY module_id (module_id)
);
```

Use JSON-encoded arrays with WordPress sanitization/validation.

Avoid autoloaded options for large settings.

## 10. Exact Behavior

Save settings:

1. Confirm module exists.
2. Confirm capability.
3. Load schema.
4. Sanitize input.
5. Validate input.
6. Redact secrets in response.
7. Save full settings object.
8. Fire settings saved action.
9. Log audit event if Audit Log exists.

Get settings:

1. Confirm capability.
2. Load module schema.
3. Load saved settings.
4. Merge with defaults.
5. Redact secrets.
6. Return response.

Reset settings:

1. Confirm capability.
2. Restore defaults.
3. Preserve secrets only if module spec says so.
4. Log action.

## 11. Edge Cases

### Missing settings row

Return defaults.

### Corrupted JSON

- Log error.
- Return defaults.
- Show warning.
- Preserve corrupted value until admin resets or repair runs.

### Schema changed after update

- Run migration if available.
- Unknown keys remain stored but not returned unless module allows.

### Secret field blank on save

- Preserve existing secret unless explicit clear flag is passed.

## 12. Conflicts & Dependencies

Depends on:

- Permission Model
- Module Registry

Used by:

- Every configurable module
- Import/Export
- Blueprints
- Setup Wizard

## 13. Security & Permissions

- Escape all output.
- Sanitize all input by schema.
- Verify nonces.
- Enforce capabilities.
- Redact secrets from REST.
- Never include secrets in export unless explicitly requested and encrypted.

## 14. Mobile/Responsive Behavior

Settings forms should be responsive:

- Desktop: multi-column where useful.
- Mobile: stacked fields.
- Secret fields must remain readable and tappable.
- Save buttons should be sticky on long settings pages.

## 15. Data Retention / Uninstall

Global uninstall options should support:

- Keep all WPTransformed data.
- Delete settings only.
- Delete logs only.
- Delete all WPTransformed tables.

Settings are kept on plugin deactivation.

## 16. Verification & Acceptance Criteria

- [ ] Settings save and reload.
- [ ] Defaults apply when no settings exist.
- [ ] Invalid values are rejected.
- [ ] Unknown module settings cannot be saved.
- [ ] Unauthorized user cannot read/write settings.
- [ ] Secrets are redacted in REST.
- [ ] Blank secret save preserves existing secret.
- [ ] Reset restores defaults.
- [ ] Corrupted settings do not fatal.
- [ ] Settings table is created on activation.
- [ ] Settings are not autoloaded through wp_options.

## 17. Deferred Features

- Encrypted secrets at rest using libsodium.
- Remote synced settings.
- Settings diff viewer.

## 18. Known Gotchas

- WordPress salts are not encryption keys.
- Do not overuse JSON if querying individual fields becomes important.
- Avoid storing huge logs in settings.
