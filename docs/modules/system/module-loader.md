# Module Loader

## 1. Metadata

- ID: `module-loader`
- Category: `system`
- Tier: Core
- Risk: Advanced
- Status: Phase 1 Foundation
- Replaces: N/A
- Related modules: `module-registry`, `settings-storage`, `recovery-center`, `conflict-detector`
- Default enabled: Always available
- Onboarding profiles: All

## 2. One-Liner

Loads only active WPTransformed modules while guaranteeing inactive modules execute zero hooks, enqueue zero assets, and perform zero database work.

## 3. Scope

The Module Loader is responsible for:

- Reading active module state.
- Loading only active Core module classes.
- Loading Pro module classes only when Pro is available.
- Enforcing dependencies.
- Checking conflicts.
- Wrapping module initialization in crash protection.
- Quarantining crashing modules.
- Exposing module lifecycle hooks.

## 4. Things NOT To Do

- Do not include all module files on every request.
- Do not run inactive module hooks.
- Do not enqueue inactive module assets.
- Do not auto-enable risky modules.
- Do not silently ignore crashing modules.
- Do not let one module fatal crash the entire site if recoverable.
- Do not load Pro implementation files in Core-only installations.

## 5. What the User Sees

Most behavior is invisible.

Users may see:

- Module enabled/disabled states in Module Library.
- Dependency warnings.
- Conflict warnings.
- Quarantined module alerts.
- Recovery Center notices.
- “Module failed and was disabled” notification.

## 6. Settings Schema

Module enabled state should live in Settings Storage.

Recommended shape:

```json
{
  "modules": {
    "admin-bar-manager": {
      "enabled": true,
      "enabled_at": "2026-04-26T15:00:00Z",
      "enabled_by": 1,
      "last_known_good": true
    },
    "change-login-url": {
      "enabled": false
    }
  },
  "quarantined_modules": {
    "example-module": {
      "reason": "Fatal error during init",
      "error_hash": "abc123",
      "quarantined_at": "2026-04-26T15:05:00Z"
    }
  }
}
```

## 7. Hooks & Implementation

Recommended classes:

```php
WPT_Module_Loader
WPT_Module_Interface
WPT_Abstract_Module
WPT_Module_Sandbox
```

Recommended files:

```text
includes/System/ModuleLoader.php
includes/System/AbstractModule.php
includes/System/ModuleSandbox.php
```

Lifecycle:

```php
do_action('wpt_before_module_load', $module_id);
do_action('wpt_module_loaded', $module_id);
do_action('wpt_module_enabled', $module_id);
do_action('wpt_module_disabled', $module_id);
do_action('wpt_module_quarantined', $module_id, $error);
```

Module interface:

```php
interface WPT_Module_Interface {
    public function id(): string;
    public function init(): void;
}
```

## 8. REST API

Endpoints:

```text
PATCH /wp-json/wpt/v1/modules/{id}
POST /wp-json/wpt/v1/modules/{id}/enable
POST /wp-json/wpt/v1/modules/{id}/disable
POST /wp-json/wpt/v1/modules/{id}/reset
```

Permissions:

- Enable/disable requires `manage_wpt_modules`.
- Dangerous modules require `run_wpt_dangerous_tools`.

Request example:

```json
{
  "enabled": true
}
```

Response example:

```json
{
  "id": "admin-bar-manager",
  "enabled": true,
  "status": "active"
}
```

## 9. Database Usage

Uses Settings Storage.

No separate loader table required.

May write operation entries to an operation log if available.

## 10. Exact Behavior

On plugin boot:

1. Load registry.
2. Load settings.
3. Determine enabled modules.
4. Remove quarantined modules from load list.
5. Check dependencies.
6. Check Pro availability.
7. Check Safe Mode state.
8. Include only active module files.
9. Instantiate module classes.
10. Initialize each module through sandbox.
11. Record load errors and quarantine if needed.

Enable module:

1. Validate module exists.
2. Validate user capability.
3. Validate Pro availability if Pro.
4. Validate dependencies.
5. Show conflicts/risk warnings if needed.
6. Save enabled state.
7. Attempt module init.
8. If init fails, rollback enabled state and quarantine.

Disable module:

1. Validate user capability.
2. Save disabled state.
3. Call module deactivation cleanup if defined.
4. Do not delete settings by default.

## 11. Edge Cases

### Module dependency disabled

- Prevent enabling dependent module.
- Show required dependency.

### Conflict detected

- Allow enable only after explicit confirmation, unless conflict is critical.

### Module fatal error

- Quarantine module.
- Disable it.
- Show recovery notice.

### Safe Mode active

- Do not load optional modules.
- Load only core admin recovery services.

### License expired

- Do not run Pro modules.
- Preserve Pro settings.

### Multisite

- Network-level behavior deferred unless multisite strategy is active.
- Do not assume network-wide settings in Phase 1.

## 12. Conflicts & Dependencies

Depends on:

- Module Registry
- Settings Storage
- Permission Model
- Recovery Center

Used by every module.

## 13. Security & Permissions

- All enable/disable actions require nonce and capability.
- Dangerous modules require additional capability and Safety Check Modal.
- Do not allow editors/clients to toggle modules.
- Validate module IDs against registry only.

## 14. Mobile/Responsive Behavior

Module Loader has no UI.

Module Library controls responsive UI.

## 15. Data Retention / Uninstall

- Disabled module settings remain stored.
- Quarantine history may be retained until uninstall or manual clear.
- On uninstall, follow global uninstall policy.

## 16. Verification & Acceptance Criteria

- [ ] Disabled modules do not include files.
- [ ] Disabled modules do not add hooks.
- [ ] Disabled modules do not enqueue assets.
- [ ] Enabled safe module loads correctly.
- [ ] Invalid module ID cannot be enabled.
- [ ] Unauthorized user cannot enable/disable modules.
- [ ] Dependency checks block invalid enable.
- [ ] Pro module does not load without Pro.
- [ ] Crashing module is quarantined.
- [ ] Safe Mode prevents optional module loading.
- [ ] Module state persists after reload.
- [ ] Module disable preserves settings.

## 17. Deferred Features

- Remote module marketplace.
- Auto-installing dependencies.
- Per-request performance profiling.

## 18. Known Gotchas

- PHP fatals cannot always be caught; use shutdown/transient-based crash detection.
- Avoid Composer autoload pulling every module file if inactive.
- Do not use WordPress options autoload for large module settings.
