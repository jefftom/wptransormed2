# Dashboard Shell

## 1. Metadata

- ID: `dashboard`
- Category: `system`
- Tier: Core
- Risk: Safe
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-library`, `setup-wizard`, `conflict-detector`, `recovery-center`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

The internal WPTransformed status dashboard shell for module/system status. The transformed user landing page is defined separately in `editor-dashboard-shell.md`.

## 3. Scope

Dashboard Shell provides the internal WPTransformed status screen. It should not be confused with the Editor Dashboard Shell, which is the post-wizard transformed admin landing page.

It should show:

- Transformation Score placeholder.
- Active module summary.
- Quick actions.
- Recent activity placeholder.
- Client-Safe status.
- Email delivery status.
- Security snapshot.
- Database status.
- Search/AI status.
- Conflict warnings.
- Recovery/quarantine notices.
- Setup Wizard resume/start link.

## 4. Things NOT To Do

- Do not build full analytics in Phase 1.
- Do not show fake performance scores.
- Do not show fake testimonials.
- Do not show fake release history.
- Do not claim exact speed gains.
- Do not show unavailable Pro data as real data.
- Do not load heavy charts before data exists.

## 5. What the User Sees

Dashboard sections:

1. Welcome banner.
2. Setup progress.
3. Status cards:
   - Modules active
   - Security basics
   - Email delivery
   - Database cleanup
   - Conflicts
4. Quick actions.
5. Recommended next steps.
6. Recent WPTransformed activity placeholder.
7. Recovery/quarantine alerts.

Example copy:

```text
Your WordPress admin is 42% transformed.
Finish setup to enable safer editor access, login protection, and email delivery.
```

## 6. Settings Schema

Dashboard preferences:

```json
{
  "dashboard": {
    "dismissed_cards": [],
    "show_setup_progress": true,
    "last_seen_version": "1.0.0"
  }
}
```

## 7. Hooks & Implementation

Recommended class:

```php
WPT_Dashboard_Controller
```

Dashboard cards should be registered through filter:

```php
apply_filters('wpt_dashboard_widgets', $widgets);
```

Action:

```php
do_action('wpt_dashboard_loaded');
```

## 8. REST API

Endpoints:

```text
GET /wp-json/wpt/v1/dashboard
POST /wp-json/wpt/v1/dashboard/dismiss-card
```

Permissions:

- Requires `manage_wpt`.

Response example:

```json
{
  "setup_complete": false,
  "active_modules": 8,
  "total_modules": 58,
  "conflicts": 2,
  "quarantined_modules": 0,
  "cards": []
}
```

## 9. Database Usage

Uses Settings Storage.

No custom table in Phase 1.

Future activity feed may use Audit Log table.

## 10. Exact Behavior

Load dashboard:

1. Check onboarding/setup status.
2. Fetch module states.
3. Fetch conflicts.
4. Fetch recovery/quarantine state.
5. Fetch high-level settings statuses.
6. Build cards.
7. Render quick actions.

Quick actions should link to:

- Setup Wizard
- Module Library
- Client-Safe Mode
- Email Delivery
- Security & Login
- Database Optimizer
- Search Appearance
- Import/Export
- Recovery Center

## 11. Edge Cases

### First activation

- Show Setup Wizard CTA prominently.

### Safe Mode active

- Show Safe Mode banner.
- Prioritize Recovery Center actions.

### Quarantined module exists

- Show critical alert.

### No modules active

- Show recommended preset cards.

### Pro inactive

- Do not over-promote Pro on every card.

## 12. Conflicts & Dependencies

Depends on:

- Module Registry
- Settings Storage
- Module Loader
- Conflict Detector
- Recovery Center

## 13. Security & Permissions

- Dashboard requires `manage_wpt`.
- Do not show sensitive settings values.
- Redact email/API credentials.
- Non-admin/client users should not see dashboard unless explicitly granted.

## 14. Mobile/Responsive Behavior

Desktop:

- Bento card layout.
- Two/three-column sections allowed.

Tablet:

- Two-column cards.

Mobile:

- Single-column cards.
- Quick actions as stacked buttons.
- Avoid dense tables.
- No hover-only interactions.

## 15. Data Retention / Uninstall

Dismissed dashboard cards follow settings retention.

No special uninstall behavior.

## 16. Verification & Acceptance Criteria

- [ ] Dashboard loads for administrator.
- [ ] Unauthorized user cannot access.
- [ ] First activation shows setup CTA.
- [ ] Active module count is accurate.
- [ ] Conflict count is accurate.
- [ ] Quarantined module alert appears.
- [ ] Quick actions link to valid pages.
- [ ] No fake metrics are shown.
- [ ] Mobile layout stacks correctly.
- [ ] Dismissed cards remain dismissed.

## 17. Deferred Features

- Real analytics charts.
- GA4/GSC summaries.
- Full activity feed.
- Client report previews.

## 18. Known Gotchas

- Dashboard must not become a dumping ground.
- Use honest placeholders if data source is not built.
