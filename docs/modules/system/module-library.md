# Module Library

## 1. Metadata

- ID: `module-library`
- Category: `system`
- Tier: Core
- Risk: Safe
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-registry`, `module-loader`, `settings-storage`, `conflict-detector`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

A searchable, filterable control center for all WPTransformed modules.

## 3. Scope

Module Library provides:

- Module cards.
- Search.
- Category filters.
- Core/Pro labels.
- Risk labels.
- Enabled/disabled toggles.
- Configure links.
- Conflict warnings.
- Dependency warnings.
- Replacement opportunities.
- Pro locked states.

## 4. Things NOT To Do

- Do not overwhelm users with every internal system service.
- Do not show hidden system modules as normal user modules unless useful.
- Do not allow dangerous modules to be enabled without Safety Check Modal.
- Do not show Pro upsells to Client-Safe users.
- Do not enable modules if dependencies are missing.
- Do not bury search terms like “disable gutenberg.”

## 5. What the User Sees

Module Library UI:

- Search bar.
- Category pills/sidebar.
- Core/Pro/Advanced filters.
- Risk filters.
- Bundle filters.
- Module cards.

Each card:

- Icon.
- Title.
- Description.
- Tier badge.
- Risk badge.
- Enabled toggle.
- Configure button.
- Conflict/dependency indicator.
- Pro lock if unavailable.

Search should match:

- Module title.
- Description.
- Search terms.
- Replaced plugin names.
- User problems.

Example searches:

- “smtp”
- “disable gutenberg”
- “duplicate page”
- “hide login”
- “redirection”
- “llms.txt”
- “client safe”

## 6. Settings Schema

Uses Module Registry and Settings Storage.

UI preferences may be stored:

```json
{
  "module_library": {
    "last_category": "all",
    "view": "grid",
    "show_advanced": false
  }
}
```

## 7. Hooks & Implementation

Recommended frontend:

- React/TypeScript app or WordPress-native React.
- REST-backed module data.
- Client-side filtering after initial fetch.

Recommended filters:

```php
apply_filters('wpt_module_library_card_data', $card, $module_id);
```

## 8. REST API

Consumes:

```text
GET /wp-json/wpt/v1/modules
PATCH /wp-json/wpt/v1/modules/{id}
GET /wp-json/wpt/v1/conflicts
```

May expose UI preferences:

```text
GET /wp-json/wpt/v1/ui/module-library
PUT /wp-json/wpt/v1/ui/module-library
```

Permissions:

- Requires `manage_wpt_modules`.

## 9. Database Usage

Uses Settings Storage.

No custom Module Library table.

## 10. Exact Behavior

Load page:

1. Fetch modules.
2. Fetch module states.
3. Fetch conflicts.
4. Merge into card data.
5. Render filters and cards.

Search:

1. Search title.
2. Search description.
3. Search search_terms.
4. Search replaces.
5. Rank exact title matches highest.

Toggle module:

1. If safe/moderate, allow enable with confirmation if needed.
2. If advanced/dangerous, require Safety Check Modal.
3. If Pro locked, show upgrade/details.
4. If conflict exists, show warning before enable.
5. Call Module Loader REST endpoint.

## 11. Edge Cases

### No modules returned

- Show error state and support link.

### REST failure

- Show retry button.
- Do not show stale toggles as actionable.

### Pro module locked

- Show locked card.
- Do not show active toggle.

### Module quarantined

- Show quarantined state.
- Link to Recovery Center.

### Search no results

- Show suggestions and docs link.

## 12. Conflicts & Dependencies

Depends on:

- Module Registry
- Module Loader
- Conflict Detector
- Permission Model

## 13. Security & Permissions

- Only users with `manage_wpt_modules` can view/toggle.
- Non-admin/client users should not see Pro upsells.
- All state changes through REST must use nonce and capability checks.

## 14. Mobile/Responsive Behavior

Desktop:

- Grid of cards.
- Category sidebar/pills.

Tablet:

- Two-column cards.
- Collapsible filters.

Mobile:

- Single-column cards.
- Filters collapse into drawer/dropdown.
- Toggles must be large enough for touch.
- Search stays sticky at top if possible.

## 15. Data Retention / Uninstall

UI preferences follow settings retention policy.

Module enabled states are retained unless full uninstall.

## 16. Verification & Acceptance Criteria

- [ ] Module grid renders from registry.
- [ ] Search matches title, terms, replacements.
- [ ] Filters work.
- [ ] Core module can be enabled.
- [ ] Pro locked module cannot be enabled.
- [ ] Dangerous module requires Safety Check Modal.
- [ ] Conflict indicator displays.
- [ ] Quarantined module displays recovery link.
- [ ] Unauthorized user cannot access page.
- [ ] Mobile layout is single-column and usable.
- [ ] “Disable Gutenberg” search finds module.

## 17. Deferred Features

- User favorites.
- Module usage analytics.
- Marketplace/extensions browsing.

## 18. Known Gotchas

- Too many module cards can feel overwhelming; defaults should prioritize recommended modules.
- Keep copy short and benefit-focused.
