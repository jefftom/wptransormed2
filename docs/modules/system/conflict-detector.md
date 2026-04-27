# Conflict Detector

## 1. Metadata

- ID: `conflict-detector`
- Category: `system`
- Tier: Core
- Risk: Safe
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-registry`, `module-loader`, `migration-center`, `module-library`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

Detects overlapping plugins and risky combinations so users can avoid double-running features that may conflict.

## 3. Scope

Conflict Detector identifies:

- Plugins WPTransformed can replace.
- Plugins that overlap with active modules.
- Plugins that should stay installed.
- Critical conflicts.
- Migration opportunities.
- Companion plugin opportunities.

## 4. Things NOT To Do

- Do not auto-disable third-party plugins.
- Do not shame users for using other plugins.
- Do not claim replacement unless WPTransformed actually covers the use case.
- Do not show scary warnings for harmless overlap.
- Do not block enablement unless conflict is critical.
- Do not detect plugins by display name only.

## 5. What the User Sees

Users see:

- Conflict cards.
- Replacement opportunities.
- Severity labels:
  - Info
  - Warning
  - Critical
- Recommended action.
- Links to Migration Center.
- “Keep installed” recommendation when appropriate.

Example:

```text
WP Mail SMTP detected.
WPTransformed Email Delivery can replace basic SMTP delivery.
Import is not available yet.
Recommended: Keep WP Mail SMTP active until Email Delivery is configured and tested.
```

## 6. Settings Schema

```json
{
  "conflict_detector": {
    "dismissed_conflicts": {
      "wp-mail-smtp:email-delivery": {
        "dismissed_at": "2026-04-26T15:00:00Z",
        "dismissed_by": 1
      }
    },
    "last_scan": "2026-04-26T15:00:00Z"
  }
}
```

## 7. Hooks & Implementation

Recommended class:

```php
WPT_Conflict_Detector
```

Conflict rule shape:

```json
{
  "plugin_file": "wp-mail-smtp/wp_mail_smtp.php",
  "plugin_name": "WP Mail SMTP",
  "wpt_module": "email-delivery",
  "severity": "info",
  "replacement_type": "partial",
  "recommendation": "Configure and test Email Delivery before disabling WP Mail SMTP."
}
```

Filter:

```php
apply_filters('wpt_conflict_rules', $rules);
```

## 8. REST API

Endpoints:

```text
GET /wp-json/wpt/v1/conflicts
POST /wp-json/wpt/v1/conflicts/{id}/dismiss
POST /wp-json/wpt/v1/conflicts/rescan
```

Permissions:

- Requires `manage_wpt`.

## 9. Database Usage

Uses Settings Storage for dismissals and scan metadata.

No custom table required in Phase 1.

## 10. Exact Behavior

Scan flow:

1. Read active plugins.
2. Read active theme if needed.
3. Load conflict rules.
4. Match by plugin basename and known constants/classes where useful.
5. Compare with active WPTransformed modules.
6. Generate conflict/replacement results.
7. Cache result for current request or short transient.
8. Show in Dashboard, Module Library, Migration Center.

Severity rules:

- Info: overlap but safe.
- Warning: double behavior may cause issues.
- Critical: can cause lockout/data loss/security risk.

## 11. Edge Cases

### Plugin inactive

- Do not show active conflict.
- May show migration opportunity if data exists later.

### Same plugin installed under different folder

- Detect by plugin headers/known classes if possible.

### User dismissed conflict

- Hide unless severity changes or plugin/module state changes.

### Conflict with Pro module not available

- Show as Pro replacement opportunity, not active conflict.

## 12. Conflicts & Dependencies

Depends on:

- Module Registry
- Settings Storage
- Permission Model

Used by:

- Dashboard
- Module Library
- Migration Center
- Setup Wizard
- Safety Check Modal

## 13. Security & Permissions

- Do not expose full plugin path publicly.
- Only authorized users can view conflicts.
- Escape plugin names in UI.
- Verify nonce for dismissals.

## 14. Mobile/Responsive Behavior

Conflict cards should stack on mobile.

Severity labels must remain visible without hover.

## 15. Data Retention / Uninstall

Dismissal data follows settings retention policy.

No third-party plugin data is modified.

## 16. Verification & Acceptance Criteria

- [ ] Detects active plugin by plugin basename.
- [ ] Shows overlap with corresponding WPT module.
- [ ] Distinguishes info/warning/critical.
- [ ] Dismissal persists.
- [ ] Dismissed conflict reappears if severity changes.
- [ ] Unauthorized user cannot view conflicts.
- [ ] No third-party plugin is auto-disabled.
- [ ] Pro replacement appears as locked opportunity, not active feature.
- [ ] Mobile conflict card is readable.

## 17. Deferred Features

- Full migration imports.
- Remote conflict rule updates.
- Deep plugin configuration analysis.

## 18. Known Gotchas

- Plugin folder names can vary.
- Some plugins have multiple entry files.
- Avoid absolute claims like “zero conflicts.”
