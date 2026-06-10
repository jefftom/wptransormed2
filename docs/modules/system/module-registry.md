# Module Registry

## 1. Metadata

- ID: `module-registry`
- Category: `system`
- Tier: Core
- Risk: Safe
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-loader`, `module-library`, `settings-storage`, `permission-model`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

The canonical source of truth for all WPTransformed modules, including IDs, titles, categories, tiers, risk levels, dependencies, conflicts, defaults, and onboarding relationships.

## 3. Scope

The Module Registry defines every module WPTransformed knows about.

It must:

- Provide a stable module ID for every module.
- Define Core vs Pro.
- Define risk level.
- Define category.
- Define default enabled state.
- Define dependencies.
- Define known conflicts.
- Define onboarding profile recommendations.
- Provide searchable metadata for Module Library.
- Expose module data to REST API.
- Allow Pro/companion modules to register themselves through filters.

## 4. Things NOT To Do

- Do not execute module behavior.
- Do not load module files directly.
- Do not store user settings.
- Do not infer module slugs dynamically from class names.
- Do not allow duplicate module IDs.
- Do not let Pro module files load in Core-only installs.
- Do not register modules with missing tier/risk/category values.

## 5. What the User Sees

The user does not interact with the registry directly.

They see registry data through:

- Module Library cards.
- Dashboard active-module summaries.
- Setup Wizard recommendations.
- Conflict Detector warnings.
- Import/export screens.
- Search results in Command Palette.

Each module card should show:

- Name
- Short description
- Category
- Tier badge
- Risk badge
- Enabled/disabled state
- Configure button
- Related modules or conflicts when relevant

## 6. Settings Schema

The registry itself does not store per-user settings.

Each module registry item should support this shape:

```json
{
  "id": "email-delivery",
  "title": "Email Delivery",
  "description": "Fix WordPress email delivery with mailer support, test emails, and connection health.",
  "category": "email-delivery",
  "tier": "core",
  "risk": "moderate",
  "status": "planned",
  "default_enabled": false,
  "dependencies": [],
  "conflicts": ["wp-mail-smtp"],
  "replaces": ["WP Mail SMTP basic use cases"],
  "search_terms": ["smtp", "email", "mail", "sendgrid", "mailgun"],
  "onboarding_profiles": ["business", "agency", "developer"],
  "settings_schema": {},
  "docs_path": "docs/modules/email/email-delivery.md"
}
```

Required fields:

- `id`
- `title`
- `description`
- `category`
- `tier`
- `risk`
- `default_enabled`

Optional fields:

- `dependencies`
- `conflicts`
- `replaces`
- `search_terms`
- `onboarding_profiles`
- `settings_schema`
- `docs_path`
- `pro_required`
- `companion_plugin`
- `migration_sources`

## 7. Hooks & Implementation

Recommended classes:

```php
WPT_Module_Registry
WPT_Module_Definition
```

Recommended files:

```text
includes/System/ModuleRegistry.php
includes/System/ModuleDefinition.php
config/modules.php
```

Core registration flow:

1. Load base registry array from `config/modules.php`.
2. Validate every definition.
3. Apply `wpt_registered_modules` filter.
4. Validate filtered definitions.
5. Cache registry in memory for current request.
6. Expose registry to Module Loader, Module Library, Setup Wizard, REST API.

Filters:

```php
apply_filters('wpt_registered_modules', $modules);
apply_filters('wpt_module_categories', $categories);
apply_filters('wpt_module_definition', $definition, $module_id);
```

CHANGELOG (2026-06-10, pre-launch breaking change): the
`wpt_registered_modules` payload changed from `id => file path` to
`id => definition array` (canonical-keyed, schema per §6). Definitions
are the single source of truth; filtered input is normalized and
re-validated after the filter, and invalid entries are skipped with a
debug log + admin notice — never `require_once`'d and never fatal. The
runtime `id => file` map remains available internally via
`Module_Registry::get_file_map()`; it is not a public contract.

Validation rules:

- ID must be kebab-case.
- ID must be unique.
- Tier must be one of:
  - `core`
  - `pro`
  - `advanced-core`
  - `pro-advanced`
  - `companion`
  - `deferred`
- Risk must be one of:
  - `safe`
  - `moderate`
  - `advanced`
  - `dangerous`
- Category must be known or registered.
- Default enabled must be boolean.

## 8. REST API

Endpoints:

```text
GET /wp-json/wpt/v1/modules
GET /wp-json/wpt/v1/modules/{id}
GET /wp-json/wpt/v1/module-categories
```

Permissions:

- `GET /modules`: requires `manage_wpt` or `manage_wpt_modules`.
- Public access is not allowed.

Response example:

```json
{
  "modules": [
    {
      "id": "email-delivery",
      "title": "Email Delivery",
      "category": "email-delivery",
      "tier": "core",
      "risk": "moderate",
      "enabled": false,
      "available": true,
      "locked": false
    }
  ]
}
```

## 9. Database Usage

The registry should not require a database table.

Source of truth should be code/config.

Runtime enabled/disabled state belongs to Settings Storage.

## 10. Exact Behavior

On plugin boot:

1. Load registry service.
2. Load canonical module definitions.
3. Validate definitions.
4. Apply public filter.
5. Validate again.
6. Make registry available to all services.

If validation fails:

- Log the validation error.
- Do not load the invalid module.
- Show admin notice to users with `manage_wpt`.
- Continue loading valid modules.

## 11. Edge Cases

### Duplicate module ID

- Reject later definition.
- Log error.
- Show admin notice.

### Missing required field

- Reject module.
- Log error.

### Unknown category

- Assign to `uncategorized` only in development.
- In production, reject module or show admin warning.

### Pro module registered without Pro plugin/license

- Show as locked if configured.
- Do not load implementation.

### Companion module registered without companion plugin active

- Show as “available via companion plugin.”
- Do not load implementation.

## 12. Conflicts & Dependencies

Dependencies:

- None.

Used by:

- Module Loader
- Module Library
- Setup Wizard
- Import/Export
- Conflict Detector
- Command Palette
- Dashboard Shell

## 13. Security & Permissions

- Registry data is admin-only by default.
- Do not expose internal file paths in REST responses.
- Sanitize/escape registry values before rendering.
- Validate external modules registered through filters.

## 14. Mobile/Responsive Behavior

Registry has no UI.

Module Library is responsible for rendering registry data responsively.

## 15. Data Retention / Uninstall

No persistent registry data to delete.

If external modules were registered, they disappear when their code is inactive.

## 16. Verification & Acceptance Criteria

- [ ] Registry loads without fatal errors.
- [ ] All canonical module IDs are unique.
- [ ] Invalid modules are rejected.
- [ ] Required fields are validated.
- [ ] REST endpoint returns module definitions to authorized users.
- [ ] Unauthorized users cannot access module registry REST data.
- [ ] Pro modules can be represented as locked without loading implementation files.
- [ ] Companion modules can be represented without companion plugin active.
- [ ] `wpt_registered_modules` filter works.
- [ ] Module Library can consume registry output.

## 17. Deferred Features

- Remote registry updates.
- Third-party marketplace.
- Module dependency auto-installers.

## 18. Known Gotchas

- Do not let registry validation become too permissive.
- Do not register modules from arbitrary database values.
- Do not couple registry data to UI labels only; IDs must remain stable forever.
