# Module: {Module Title}

<!--
This is the canonical per-module spec template for WPTransformed v2+.
Every module in docs/modules/{category}/{slug}.md MUST follow this structure.

When building a module, Claude Code reads ONLY this file + the Module_Base
contract in docs/architecture.md + any referenced HTML mockups. No other
docs are required at build time.

Sections marked (required) must be filled in before build.
Sections marked (optional) can be omitted if not applicable, but keep
the header as a placeholder so the structure stays consistent.
-->

## Metadata (required)

| Field | Value |
|---|---|
| Module ID | `module-slug` |
| Category | `admin-interface` / `content-management` / `security` / `performance` / `developer` / `design` / `utilities` |
| Parent module | `parent-slug` (if this is a sub-module) or `none` |
| Tier | `free` / `pro` |
| App page | `yes` / `no` — is this a full-page app module with its own HTML reference? |
| Replaces | Name of plugin this replaces, e.g. "Admin Menu Editor Pro ($39/yr)" |
| Reference files | Paths to HTML mockups in `assets/admin/reference/` |
| Reference code | Paths to existing working implementations in `vendor/reference/` |
| Spec source | Which wave file this was originally specced in: `v1`, `v2`, `wave3`, etc. |
| Status | `stub` / `partial` / `complete` / `deprecated` |

## One-line description (required)

One sentence describing what the module does from the user's perspective. This is the string shown under the module title in the module dashboard card.

## What the User Sees (required)

Bulleted list of every user-facing behavior in order of how the user encounters them. Be concrete. Name the exact UI surface (admin bar, row action, settings tab, sidebar item, settings field, button label) and what happens when the user interacts with it.

Example format:
- When the user activates the module, a "New Item" link appears in X
- Clicking the link opens a modal with Y
- After confirming, Z happens and a toast notification appears

## Settings Schema (required)

The exact PHP array stored in `wp_wpt_settings` under this module's key. Show defaults inline.

```php
'module-slug' => [
    'enabled'        => true,
    'some_setting'   => 'default-value',
    'some_array'     => [],
    'nested_config'  => [
        'sub_key' => 'default',
    ],
]
```

Explain any non-obvious field in a bulleted list below the code block.

## WordPress Hooks Used (required)

Table format. Every hook the module registers.

| Hook | Type | Priority | Purpose |
|---|---|---|---|
| `hook_name` | filter/action | `10` | What it does |

## Exact Behavior (required)

Numbered step-by-step description of the module's core logic. This is where you describe the PHP implementation in prose — what functions are called in what order, what data transformations happen, what edge cases are handled inline. Not code, but enough detail that two different developers would write substantially the same implementation.

For modules with multiple distinct behaviors (e.g., a settings save vs an AJAX endpoint), split into named subsections:

### Behavior 1: {Name}
1. Step
2. Step

### Behavior 2: {Name}
1. Step
2. Step

## Settings Page UI (required)

Describe the admin-side settings page for this module. Include:
- Section hierarchy (what groups of fields exist)
- Field types (toggle, text, select, checkbox group, drag-drop list, color picker, live preview, etc.)
- Warnings and inline help text
- Conditional visibility (field X appears only when setting Y is on)
- Save behavior (AJAX, form submit, optimistic UI)

For APP modules, describe the full page layout referencing the HTML mockup.

## Database Tables (optional)

If the module creates custom DB tables, show the exact schema:

```sql
CREATE TABLE {$wpdb->prefix}wpt_{table_name} (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ...
    INDEX idx_{name} (column)
);
```

Include migration notes: when to run `dbDelta`, what transient flag to set, cleanup on uninstall.

## REST Endpoints (optional)

If the module exposes REST endpoints, list them:

```
GET    /wp-json/wpt/v1/{module}/{resource}   — permission: manage_options
POST   /wp-json/wpt/v1/{module}/{resource}   — permission: manage_options
```

Each endpoint should have: permission callback, argument schema (sanitize + validate), response shape.

## Scope — What This Module Does NOT Do (required)

Explicit list of things a reasonable reader might expect this module to do that it does NOT do, with a one-line reason for each exclusion and a pointer to the module that handles it (if any).

- Does NOT do X — because that belongs to module Y
- Does NOT do Z — deferred to v3, see Deferred Features section below

## Things NOT To Do (required)

Implementation guardrails for Claude Code. Explicit prohibitions with a reason.

- Do NOT use jQuery UI Sortable (we use vanilla HTML5 drag API)
- Do NOT call `remove_menu_page()` (we style, we don't replace)
- Do NOT hook at priority 10 for X (must be 999 to run after all plugins)
- Do NOT store credentials in plain text (use `openssl_encrypt` with AUTH_KEY)

## Known WordPress Gotchas (optional)

Hard-won WordPress knowledge specific to this module's domain. Things that will burn you if you don't know about them.

- WordPress enforces minimum 15s heartbeat interval even if you set lower
- `$menu` global is only populated after `admin_menu` fires, not before
- `wp_get_current_user()` returns empty user before `init` runs
- On WP Engine, Redis transients can return stale data after updates — always fall back

## Edge Cases (optional)

Non-obvious scenarios the code must handle gracefully:

- What happens when a referenced entity no longer exists (plugin uninstalled, post deleted)
- What happens when settings array is empty or malformed
- Multisite behavior (network admin vs site admin)
- What happens during plugin upgrade when schema changes
- What happens when two users edit the same setting concurrently

## Verification Steps (required)

Numbered list of observable tests that prove the module works. Each step must be something a human can physically verify on a real WordPress install. Binary pass/fail, not "check that it works correctly."

1. Activate the module → no PHP errors in `debug.log`
2. Navigate to X → Y element appears
3. Click Z → observable effect W happens
4. Disable the module → X reverts to WordPress default
5. (Edge case) With condition E, verify behavior F

## Deferred Features (optional)

Features explicitly held back from this version. Each item has a target (v2, v3, Pro, never) and a short reason.

- **Feature name** *(v3)* — Reason it's deferred. What it would require to implement.
- **Feature name** *(Pro)* — Competitive positioning, why it's a Pro differentiator.

## Known Issues (optional)

Open bugs in the current implementation that need to be fixed. Migrated from TODO.md when the spec is built.

- **Bug description** — When it triggers, what the symptom is, suspected cause, where to start debugging.

## References (required)

- **v1 spec source**: `wptransformed-v1-module-specs.md` lines N–M (if applicable)
- **Wave spec source**: `module-specs-waveN.md` Module # (if applicable)
- **Reference HTML**: `assets/admin/reference/app-pages/{file}.html` (if APP module)
- **Reference code**: `vendor/reference/{plugin}/` (if porting from existing plugin)
- **Related modules**: Links to other module specs that share architecture or data
- **Decisions**: `docs/DECISIONS.md` entries that affect this module

## Change Log (optional)

Track significant spec changes over time.

- **2026-04-05** — Initial spec synthesized from v1 + AME v3.5 zip
- **2026-04-06** — Added per-role profiles section based on TODO.md v2 ideas
