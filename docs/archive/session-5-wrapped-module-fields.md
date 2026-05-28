# Session 5 — Wrapped Module Field-Name Cheat Sheet

Pre-flight recon for Session 5 Parts 2 and 3 (Menu Editor + White Label app pages), done 2026-04-11 after the Login Designer field-name mismatch bug (10fbdaf).

**Lesson from Part 1:** When a new app page wraps an existing module by reusing its `sanitize_settings()`, the form field names must match what the module's `$raw['*']` keys expect. The existing module's own settings page sets the convention; the new app page must follow it.

This file documents the exact field names (and their types + sanitization) for every module Session 5 wraps, so Parts 2 and 3 can be built without re-grepping.

---

## Session 5 Part 2 — Menu Editor

Wraps: `admin-menu-editor` at `modules/admin-interface/class-admin-menu-editor.php`
Settings slug: `admin-menu-editor`

### Settings array keys (stored in DB)

```php
[
    'menu_order'    => [],  // array<int,string> — slug order
    'hidden_items'  => [],  // array<int,string> — slugs to hide
    'renamed_items' => [],  // array<string,string> — slug => label
    'custom_icons'  => [],  // array<string,string> — slug => dashicon class
    'separators'    => [],  // array<int,string> — slugs AFTER which to insert a separator
]
```

### Form field names expected by `sanitize_settings()`

| Form `name` | Type | Transform | Notes |
|---|---|---|---|
| `wpt_menu_order` | string | Comma-separated slugs → explode → trim + `sanitize_text_field` each → rebuild array | Drop empty entries. Preserve order. |
| `wpt_hidden_items` | string | Same CSV → slugs pipeline as `menu_order` | |
| `wpt_renamed_json` | string | `wp_unslash` → `json_decode` → iterate `slug => label` → `sanitize_text_field` both | Must be valid JSON `{slug: label}`. Skip non-array. |
| `wpt_icons_json` | string | Same JSON decode → iterate `slug => icon` → `sanitize_html_class` on icon → regex-validate `/^dashicons-[a-z0-9-]+$/` | Invalid icons silently dropped. |
| `wpt_separators` | string | Same CSV → slugs pipeline | |

### Gotchas

- **No per-role visibility in schema.** Session 5 Part 2 reference mockup may show role-based hiding; if so, either (a) extend the module's schema with a new `role_visibility` array, or (b) omit the UI for now and log as deferred. Extending the schema is fine — just add the new key to `get_default_settings()`, `sanitize_settings()`, and the init() logic that reads the saved settings.
- **Menu order is CSV, not an array input.** The app page UI should build the order client-side (drag-drop reorder) and serialize to a comma-separated string in a single hidden input on submit.
- **Renamed items and icons are JSON blobs.** Same pattern — client-side edits in the UI, serialize to JSON in a hidden `<input>` on submit.
- **No bulk-reset.** `sanitize_settings()` has no "revert to defaults" path. To reset, app page would need to call `Settings::save('admin-menu-editor', $module->get_default_settings())` directly.

### Server-side init() hooks

Only register WP hooks when the corresponding settings are non-empty — the module does this to avoid touching `$menu` for unchanged installs. Reading the app page preview should reflect the same gating.

---

## Session 5 Part 3 — White Label

Wraps: `white-label` at `modules/admin-interface/class-white-label.php`
Settings slug: `white-label`

### Settings array keys

```php
[
    'admin_logo_url'     => '',                         // string
    'admin_footer_text'  => 'Powered by Your Agency',   // string (kses_post, <= 500 chars)
    'login_logo_url'     => '',                         // string
    'hide_wp_version'    => true,                       // bool
    'custom_admin_title' => '',                         // string (<= 100 chars)
]
```

### Form field names

| Form `name` | Type | Transform | Notes |
|---|---|---|---|
| `wpt_admin_logo_url` | url | `esc_url_raw` | Empty string allowed. |
| `wpt_login_logo_url` | url | `esc_url_raw` | Empty string allowed. |
| `wpt_admin_footer_text` | textarea | `wp_kses_post` → `mb_substr` to 500 chars | Allows basic HTML (links, strong, em). |
| `wpt_hide_wp_version` | checkbox | `! empty()` → bool | Unchecked = false. |
| `wpt_custom_admin_title` | text | `sanitize_text_field` → `mb_substr` to 100 chars | Shows in `<title>` via `admin_title` filter. |

### PRO gating

- Module is flagged `tier: 'pro'` in `Module_Hierarchy::get_parents()` (parent id `white-label`).
- App page save handler should reject with an `Unauthorized` error if `! Core::is_pro_licensed()` even if the user submits the form. The Pro toggle on the parent card in the Module Grid already shows as disabled when unlicensed, but a direct POST to `admin-post.php?action=wpt_save_white_label` could bypass that. Handler must re-check.

### Also wraps `custom-admin-footer` (bonus)

The White Label reference mockup also covers footer text customization, which has a **second dedicated module**:

Wraps: `custom-admin-footer` at `modules/admin-interface/class-custom-admin-footer.php`
Settings slug: `custom-admin-footer`

Settings array keys:

```php
[
    'left_text'  => 'Powered by Your Agency',  // string (kses_post)
    'right_text' => '',                        // string (kses_post)
]
```

Form field names:

| Form `name` | Type | Transform |
|---|---|---|
| `wpt_left_text` | textarea | `wp_unslash` → `wp_kses_post` |
| `wpt_right_text` | textarea | `wp_unslash` → `wp_kses_post` |

**Decision for Part 3**: the White Label app page should save to BOTH modules — `white-label` for admin chrome (logos, version, title) and `custom-admin-footer` for the footer left/right text. One form POST, handler writes both settings rows. Or: use only one module and route the settings through it. Prefer writing to both, since the two modules have non-overlapping responsibilities.

### Latent bug found during recon

`White_Label::is_login_customizer_active()` checks module id `login-customizer` but the actual registry id is `login-branding`. One-line fix — can be folded into Session 5 Part 3. Logged in `docs/IMPROVEMENTS.md`.

---

## Shared conventions (applies to all Session 5 parts)

1. **All field names use `wpt_` prefix** in `name=""`. This is historical convention dating from before the WP modules refactor — keep it for backwards compat with each module's own settings page.
2. **Settings array keys are unprefixed.** `$raw['wpt_foo']` goes into `['foo' => ...]`. App pages read the unprefixed keys when populating the form values for display.
3. **Nonce field**: use a dedicated name per app page (`wpt_login_designer_nonce`, `wpt_menu_editor_nonce`, etc.) and a matching action (`wpt_save_login_designer`, `wpt_save_menu_editor`, etc.) so the admin-post router can dispatch cleanly.
4. **Instantiate modules directly**, don't call `Core::instance()->get_module()`. That method only returns modules in the active-modules set, which would make the app pages fail when the backing module is toggled off. Use the same pattern as `Login_Designer_App::get_module_instance()`.
5. **Save handler redirects** back to the app page with `?wpt_saved=1` and a green `.wpt-app-notice-success` renders at the top. Existing pattern in `class-login-designer-app.php` Line ~99 (`handle_save()`).

---

## Verification checklist per Part

For each new app page, confirm:

1. Page loads without debug.log errors, module-inactive banner works
2. All form field names start with `wpt_`, verified via DevTools: `document.querySelectorAll('[name^="wpt_"]').length === expected_count`
3. No stale unprefixed field names: `[ 'setting_key_1', 'setting_key_2', ... ].forEach(k => console.assert(!document.querySelector('[name="' + k + '"]')))`
4. Modify 2–3 fields via JS, submit, check `?wpt_saved=1` in URL + green notice visible
5. Reload the page, verify the modified values are still there (not reverted to defaults)
6. Run `wp eval 'print_r(\WPTransformed\Core\Settings::get("MODULE_ID"));'` and confirm the DB row has the submitted values
7. If the wrapped module emits anything to wp-login.php / admin output, hit that output (curl or browser) and grep for the expected strings
8. Reset settings to defaults via CLI, deactivate module, confirm app page still renders in its gate state
