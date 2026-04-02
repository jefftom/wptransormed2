# Decisions Log

Discovered constraints, architectural decisions, and hosting-specific notes.

| Date | Context | Decision |
|------|---------|----------|
| 2026-04-01 | BigMarker | Use /register not /register_or_update |
| 2026-04-01 | WP Engine | 60s PHP timeout, batch AJAX for long ops |
| 2026-04-01 | WP Engine | Redis transients unreliable, always fallback |
| 2026-04-01 | Claude Code | Specify ALL admin UI touchpoints explicitly |
| 2026-04-01 | PHP | Declare Requires PHP 7.4, develop against 8.2+ |
| 2026-04-01 | v2 DB Tables | Modules 14,15,18,20 use dbDelta with transient flags instead of activate hook (Module_Base has no activate_module method) |
| 2026-04-01 | v2 Minify Assets | JS minification is regex-based and fragile; combine_js/combine_css off by default with warning |
| 2026-04-01 | v2 Code Snippets | PHP eval restricted to manage_options capability; error recovery via shutdown handler |
| 2026-04-01 | v2 User Role Editor | WP default roles (5) cannot be deleted; manage_options protected on last admin |
| 2026-04-01 | v2 Media Folders | Uses upload_files capability (not manage_options) so editors can use folders |
| 2026-04-01 | v2 Cookie Consent | Banner is JS-driven for cache compatibility; no PHP-side consent decision |
| 2026-04-01 | v2 Categories | Added security, custom-code, compliance, login-logout module categories |
| 2026-04-01 | v2 Custom Content Types | Settings stored in module settings JSON, not separate DB tables |
| 2026-04-01 | v2 Maintenance Mode | Administrator role always forced into allowed_roles as lockout prevention |
