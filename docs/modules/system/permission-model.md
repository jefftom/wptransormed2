# Permission Model

## 1. Metadata

- ID: `permission-model`
- Category: `system`
- Tier: Core
- Risk: Advanced
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-loader`, `settings-storage`, `client-safe-mode`, `role-manager`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

Defines WPTransformed-specific capabilities so access can be controlled safely without relying only on `manage_options`.

## 3. Scope

The Permission Model provides:

- Custom WPTransformed capabilities.
- Default capability assignments.
- Capability checks for REST/API/UI actions.
- Dangerous tool gating.
- Client/editor protection.
- Foundation for Role Manager Pro.

## 4. Things NOT To Do

- Do not use `manage_options` for every action.
- Do not allow editors to access WPTransformed controls by default.
- Do not expose code/database/security tools to clients.
- Do not let Pro modules bypass capability checks.
- Do not mutate roles during every request.
- Do not remove admin capabilities on uninstall without explicit user action.

## 5. What the User Sees

Administrators see WPTransformed normally.

Non-admins usually see nothing unless explicitly granted capabilities.

Client-Safe users should not see:

- Module Library
- Security settings
- Code Manager
- Database tools
- Integrations credentials
- White label upsells
- Pro notices

## 6. Settings Schema

Capability map example:

```json
{
  "capabilities": {
    "administrator": [
      "manage_wpt",
      "manage_wpt_modules",
      "manage_wpt_settings",
      "run_wpt_dangerous_tools"
    ],
    "editor": [],
    "author": []
  }
}
```

Do not expose role mutation UI in Phase 1 beyond capability checks unless Role Manager spec exists.

## 7. Hooks & Implementation

Recommended class:

```php
WPT_Permission_Manager
```

Recommended capabilities:

```text
manage_wpt
manage_wpt_modules
manage_wpt_settings
manage_wpt_client_safe
manage_wpt_security
manage_wpt_email
manage_wpt_search_visibility
manage_wpt_code
manage_wpt_database
manage_wpt_reports
manage_wpt_integrations
view_wpt_logs
export_wpt_data
run_wpt_dangerous_tools
manage_wpt_white_label
```

Note (2026-06-09): this 15-capability list (identical to build authority
addendum v5.3.6 §14) is authoritative. Canonical scope §18.4 contains an
older draft with different names (`view_wpt_dashboard`, `export_wpt_settings`,
`import_wpt_settings`, `manage_wpt_schema`, `manage_wpt_roles`) and a Core/Pro
grant split — superseded per the source-of-truth hierarchy (Layers 2a/3 own
implementation detail). Administrators receive ALL capabilities by default;
per-role Core/Pro assignment UI belongs to Role Manager Pro.

Filter:

```php
apply_filters('wpt_user_can_manage_module', $can, $user_id, $module_id);
```

## 8. REST API

Every WPTransformed REST endpoint must use explicit permission callbacks.

Example:

```php
'permission_callback' => function() {
    return current_user_can('manage_wpt_modules');
}
```

No endpoint should use `__return_true` unless it is explicitly public, such as a future frontend form submission endpoint from a companion plugin.

## 9. Database Usage

No custom table required for Phase 1.

Capabilities are stored in WordPress roles.

Future Role Manager Pro may store presets in WPTransformed tables.

## 10. Exact Behavior

On activation:

1. Add WPTransformed capabilities to Administrator role.
2. Do not add capabilities to Editor/Author/Contributor/Subscriber.
3. Register internal permission checks.
4. Make capabilities available to UI and REST services.

On deactivation:

- Do not remove capabilities.

On uninstall:

- Follow uninstall policy.
- Optionally remove capabilities if user chose full cleanup.

Dangerous action check:

1. Confirm base module capability.
2. Confirm `run_wpt_dangerous_tools`.
3. Require Safety Check Modal.
4. Verify nonce.
5. Log action.

## 11. Edge Cases

### Administrator role missing

- Show warning.
- Do not fatal.

### Multisite

- Super admins can manage network settings.
- Per-site admins need site-level capabilities.
- Full network behavior deferred unless multisite spec exists.

### Custom roles

- No capabilities by default.
- Pro Role Manager may grant later.

### User lacks capability but has manage_options

- In Phase 1, Administrators should have WPT capabilities assigned.
- Do not rely on manage_options fallback except for emergency recovery.

## 12. Conflicts & Dependencies

Used by:

- All modules
- REST API
- Module Loader
- Settings Storage
- Client-Safe Mode
- Role Manager Pro

## 13. Security & Permissions

This is the security foundation.

- Every admin action must check capability.
- Every REST route must check capability.
- Every destructive action must require dangerous capability.
- Nonces required for all state-changing actions.

## 14. Mobile/Responsive Behavior

No direct UI in Phase 1.

Future permission UI should be stacked/mobile-friendly.

## 15. Data Retention / Uninstall

Capabilities remain on deactivation.

On full uninstall, optionally remove custom capabilities from roles.

Do not remove capabilities silently if user chooses to keep data.

## 16. Verification & Acceptance Criteria

- [ ] Administrator receives all WPT capabilities on activation.
- [ ] Editor receives no WPT capabilities by default.
- [ ] REST route without capability is blocked.
- [ ] Module toggle requires `manage_wpt_modules`.
- [ ] Settings update requires `manage_wpt_settings`.
- [ ] Dangerous action requires `run_wpt_dangerous_tools`.
- [ ] Deactivation does not remove capabilities.
- [ ] Full uninstall can remove capabilities if selected.
- [ ] Client-Safe users cannot see WPTransformed admin pages.

## 17. Deferred Features

- Full role/capability editor.
- Capability presets.
- Network-wide capability management.

## 18. Known Gotchas

- Role mutation should happen on activation/migration, not every request.
- Do not accidentally grant editor access via menu callbacks.
