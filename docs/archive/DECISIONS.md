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
| 2026-04-10 | v2 Product scope | No AI features. Differentiation comes from UX, architecture, and depth — not LLM integrations. Command palette stays fuzzy string match, anomaly detection is rule-based, no content assist / alt-text gen / semantic search. Keeps plugin self-hostable with zero external dependencies. |
| 2026-04-10 | v2 Multisite | Multisite is a first-class target, not a bolt-on. Agencies managing 10+ sites are the primary Pro buyer. Schema gains `site_id` column now (composite PK), network admin UI ships in v3. |
| 2026-04-10 | v2 Settings schema | `wp_wpt_settings` PK changes from `(module_id)` to `(site_id, module_id)` via additive migration. Existing rows get `site_id=0` (network default). Other new columns: `settings_version INT`, `schema_version VARCHAR(16)`, `inherit_network TINYINT(1)`. Migration is idempotent, auto-engages Safe Mode on failure. |
| 2026-04-10 | v2 Observability | Audit Log, 404 Monitor, Email Log, Error Log Viewer unify into a single `wp_wpt_events` table with typed events, severity, source_module, correlation_id, and JSON payload. Each existing log becomes a filtered view over the event store. Enables cross-module queries, live SSE streaming, rule-based alerting in v3. |
