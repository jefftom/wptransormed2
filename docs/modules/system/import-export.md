# Import / Export

## 1. Metadata

- ID: `import-export`
- Category: `system`
- Tier: Core
- Risk: Moderate
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `settings-storage`, `blueprints`, `migration-center`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

Safely export and import WPTransformed settings so configurations can be reused across sites.

## 3. Scope

Import/Export handles:

- Exporting Core settings.
- Importing WPTransformed settings.
- Selective import by module.
- Redaction/exclusion of secrets.
- Version compatibility.
- Dry-run import preview.
- Basic validation.

This is not the same as third-party plugin migration, though Migration Center may use it later.

## 4. Things NOT To Do

- Do not export secrets by default.
- Do not import unknown module settings silently.
- Do not overwrite dangerous settings without confirmation.
- Do not enable Change Login URL via import without explicit confirmation.
- Do not enable Code Snippets via import without explicit confirmation.
- Do not import Pro settings as active if Pro is unavailable.
- Do not import from third-party plugins unless migration spec exists.

## 5. What the User Sees

Import/Export screen:

- Export settings button.
- Select modules to export.
- Include disabled module settings checkbox.
- Exclude secrets notice.
- Import file upload.
- Dry-run preview.
- Import summary.
- Warnings for dangerous settings.
- Confirmation before apply.

## 6. Settings Schema

Export file shape:

```json
{
  "type": "wptransformed-settings-export",
  "version": "1.0.0",
  "created_at": "2026-04-26T15:00:00Z",
  "site_url_hash": "abc123",
  "modules": {
    "admin-bar-manager": {
      "enabled": true,
      "settings": {}
    }
  },
  "meta": {
    "contains_secrets": false,
    "exported_by": 1
  }
}
```

## 7. Hooks & Implementation

Recommended class:

```php
WPT_Import_Export_Service
```

Filters:

```php
apply_filters('wpt_export_settings', $export);
apply_filters('wpt_import_settings_preview', $preview, $import);
```

Actions:

```php
do_action('wpt_settings_exported', $export_meta);
do_action('wpt_settings_imported', $result);
```

## 8. REST API

Endpoints:

```text
POST /wp-json/wpt/v1/export-settings
POST /wp-json/wpt/v1/import-settings/preview
POST /wp-json/wpt/v1/import-settings/apply
```

Permissions:

- Export requires `export_wpt_data`.
- Import requires `manage_wpt_settings`.
- Dangerous import requires `run_wpt_dangerous_tools`.

## 9. Database Usage

Reads/writes Settings Storage.

May write operation log entry if operation log exists.

No separate table required.

## 10. Exact Behavior

Export:

1. Confirm capability.
2. Read selected settings.
3. Remove/redact secrets by default.
4. Build versioned JSON export.
5. Return downloadable file.

Preview import:

1. Validate file type/JSON.
2. Validate export type.
3. Validate version.
4. Compare modules/settings.
5. Identify missing Pro/companion modules.
6. Identify dangerous settings.
7. Show preview.

Apply import:

1. Confirm capability.
2. Require Safety Check Modal if dangerous settings present.
3. Apply selected settings.
4. Skip unavailable modules unless user chooses to store inactive settings.
5. Log action.
6. Show summary.

## 11. Edge Cases

### Export from newer version

- Show compatibility warning.
- Import only known safe settings.

### Unknown module ID

- Skip by default.
- Show in preview.

### Pro settings imported without Pro

- Store inactive settings if selected.
- Do not enable Pro module.

### Secrets included

- Block unless encrypted import/export is explicitly supported later.

### Change Login URL included

- Require typed confirmation before enabling.

## 12. Conflicts & Dependencies

Depends on:

- Settings Storage
- Permission Model
- Module Registry
- Safety Check Modal

Related:

- Blueprints
- Migration Center

## 13. Security & Permissions

- Nonce required.
- Capabilities enforced.
- Uploaded JSON size limited.
- Validate all data against schemas.
- Do not execute imported code/snippets.
- Do not store raw uploaded file permanently.

## 14. Mobile/Responsive Behavior

Desktop:

- Two-column export/import panels.

Mobile:

- Stacked cards.
- Import preview as expandable sections.
- Sticky apply/cancel actions.

## 15. Data Retention / Uninstall

Export files are downloaded, not stored by default.

Import history may be stored in future operation log.

Settings follow global uninstall policy.

## 16. Verification & Acceptance Criteria

- [ ] Settings export downloads valid JSON.
- [ ] Secrets are excluded/redacted by default.
- [ ] Invalid JSON import is rejected.
- [ ] Preview shows changed modules.
- [ ] Unknown modules are skipped.
- [ ] Pro module settings do not activate without Pro.
- [ ] Dangerous imports require confirmation.
- [ ] Unauthorized user cannot import/export.
- [ ] Imported settings save correctly.
- [ ] Import does not execute code snippets automatically.
- [ ] Mobile preview is usable.

## 17. Deferred Features

- Encrypted secret export/import.
- Blueprint marketplace.
- Remote sync.
- Third-party plugin imports.

## 18. Known Gotchas

- Importing settings can break access if risky modules are activated blindly.
- Treat import as a potentially dangerous operation.
