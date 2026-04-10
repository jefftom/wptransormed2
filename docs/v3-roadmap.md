# WPTransformed v3 Roadmap

This file is the canonical location for everything that's approved for v3 but not yet scheduled. Source of truth: `.claude/plans/fuzzy-singing-moth.md` §4.

**v3 work does not begin until v2 ships and is verified.** v2 ship conditions (from the approved plan §7):

- Event store live and populated by Audit Log, 404 Monitor, Email Log, Error Log Viewer
- Settings history recording for all 85+ modules
- REST `wpt/v1` namespace exposing modules, settings, and events
- Multisite schema migration deployed with zero rollbacks
- Job runner processing background work without drops

---

## Strategic Intent

v2 makes WPTransformed the most comprehensive WordPress admin plugin. v3 makes it genuinely higher-end by crossing three thresholds no competitor has crossed:

1. **Observability, not just settings.** A unified event store cross-queryable by any module, with live SSE dashboards and rule-based alerting.
2. **Agency/network first-class.** Real network admin UI, per-site overrides, cross-site audit, module broadcast, site templating.
3. **Depth over breadth in flagship modules.** 10 modules so deep they replace entire categories of dedicated plugins.

v3 respects all v2 constraints: PHP 7.4+, no React, no build step, vanilla JS, style-don't-replace native WP admin, **no AI features**.

---

## New Categories

### 29. Observability (2 parents · 8 sub-modules)

The moat. Nobody else has this.

**Event Stream & Alerting** [NEW] [APP]
- `event-stream` — Live SSE feed of the event store. Admin bar dropdown + dedicated app page with filters (type, severity, source, user, time range). Auto-pause on scroll, replay from cursor.
- `event-query-builder` — Fluent UI for building event queries without SQL. Saved queries per user.
- `alert-rules` — Rule-based alerts. Matches `event_type` + `count in window` + triggers an action (email, webhook, admin notice, Slack/Discord via outbound webhook). No ML, pure threshold rules.
- `event-export` — CSV / JSON / NDJSON export of query results. For compliance audits.
- `retention-policy` — Per-event-type retention (e.g., debug events 7d, security events 1yr). Auto-prune via job runner.

**Metrics & Dashboards** [NEW] [APP]
- `custom-dashboards` — Build custom bento dashboards pulling from event store + Settings + WP queries. Drag-drop widgets. Save per user or per role.
- `metric-widgets` — Library of pre-built widgets (events/day, failed logins, slow queries, disk usage, comment spam). Reusable across dashboards.
- `anomaly-rules` — Rule-based baselines (e.g., "alert if today's 404 count > 2× rolling 7d average"). Still no ML.

### 30. Automation (1 parent · 5 sub-modules)

Agency-grade automation without leaving WordPress.

**Workflow Builder** [NEW] [APP]
- `workflow-builder` — Visual if-this-then-that builder. Triggers (WP events, WPT events, cron, inbound webhook) → Conditions (field compare, role check, post type, meta) → Actions (email, webhook, user role change, post action, module toggle, snippet execute). Vanilla JS drag-drop, no build step.
- `workflow-library` — Pre-built templates (welcome email on user registration, notify editor on post published, email admin on plugin update, etc.).
- `scheduled-actions` — UI wrapper around the job runner for user-scheduled one-off tasks ("publish this post on Friday," "email this report every Monday").
- `inbound-webhooks` — Receive HTTP POST, parse JSON, trigger a workflow. Signed with shared secret.
- `workflow-history` — Execution log for every workflow run, sourced from the event store.

### 31. Network (multisite flagship · 1 parent · 7 sub-modules)

Makes the Network tier worth $249/yr/unlimited-sites.

**Network Dashboard** [NEW] [APP] [NETWORK]
- `network-dashboard` — Bento stats across all sites: total users, active plugins, disk usage, recent issues, failed logins network-wide.
- `network-audit` — Aggregate audit log across all sites, filterable by site.
- `site-health-matrix` — Grid of sites × critical checks (SSL, PHP version, plugin updates, core updates, disk space, error count). Color-coded.
- `network-users` — Manage users across all sites, bulk role change, cross-site search.
- `module-broadcast` — Push a module's settings to N sites in one action. Preview diff, apply, rollback.
- `site-templates` — Template new sites from existing configs (settings, active modules, theme, users, content). Clone-on-create.
- `network-search` — Content search across all sites (posts, pages, media, users).

### 32. Content Intelligence (1 parent · 7 sub-modules)

Rule-based, no AI.

**Content Intelligence** [NEW] [APP]
- `content-inventory` — Per-post stats (word count, image count, internal links, outbound links, last updated). Admin columns + reports.
- `duplicate-detection` — MinHash-based text similarity. Find near-duplicate posts. Pure PHP, no external service.
- `orphan-finder` — Pages with zero inbound internal links.
- `outdated-content` — Posts not updated in N days, rank by age × length.
- `readability-scoring` — Flesch-Kincaid, SMOG index. Per-post admin column. Rule-based.
- `internal-link-graph` — SVG visualization of internal link structure (vanilla JS, no D3). Click a node to see inbound/outbound.
- `broken-anchor-check` — `#anchor` link validation against actual heading IDs.

### 33. SEO Toolkit (1 parent · 8 sub-modules)

Not a Yoast-killer, but enough to skip a separate SEO plugin on simple sites.

**SEO Toolkit** [NEW]
- `seo-meta` — Per-post title/description meta box + frontend filter.
- `schema-markup` — Schema.org generator per post type (Article, Product, LocalBusiness, etc.). Template-based.
- `og-tags` — Open Graph meta tags from title/excerpt/featured image.
- `xml-sitemap` — Enhanced sitemap (news, image, video). Overrides WP's basic sitemap.
- `breadcrumbs` — Breadcrumb generator from hierarchy + taxonomies. Shortcode + block.
- `focus-keyword` — Rule-based keyword scoring (Yoast-style, no AI).
- `canonical-control` — Per-post canonical URL override.
- `redirect-smart` — Auto-suggest redirects for 404s using URL similarity + content matching (string-based, no AI).

### 34. Forms Pro (1 parent · 6 sub-modules · PRO tier)

Promotes the lightweight `form-builder` v2 module into a full parent.

**Forms Pro** [NEW] [APP] [PRO]
- `form-builder-advanced` — Multi-step + conditional logic.
- `submissions-db` — Store submissions with search / filter / export.
- `form-payments` — Stripe Checkout integration (no API key storage in DB — uses WP application passwords pattern).
- `form-autoresponder` — Template-based automated responses.
- `form-analytics` — Views / starts / completions funnel, powered by the event store.
- `form-uploads` — File upload with extension allowlist + size limit.

### 35. Media Intelligence (1 parent · 6 sub-modules)

Extends Media Library Pro with deeper intelligence.

**Media Intelligence** [NEW]
- `duplicate-media` — File-hash based detection + bulk delete of duplicates.
- `unused-media` — Find attachments not referenced in any post/page.
- `focal-point` — Picker UI for featured image crop centering.
- `cdn-offload` — S3 / Bunny / R2 upload + URL rewrite (credentials via application passwords, not DB).
- `bulk-alt-text-editor` — Dedicated bulk-edit UI for missing alt text (accessibility).
- `webp-on-upload` — Local conversion with fallback chain.

### 36. Editorial Collaboration (1 parent · 4 sub-modules · PRO tier)

Editorial teams' missing layer.

**Editorial Collaboration** [NEW] [PRO]
- `editorial-comments` — Internal comments on posts (admin-only, not frontend).
- `review-queue` — Assignments + status tracking, per-user inbox.
- `presence-indicators` — Show which users are currently editing a post (SSE-powered).
- `change-requests` — Suggest-mode for editors: propose changes without publishing.

---

## Extensions to Existing v2 Parents

These add to existing parent modules rather than creating new ones.

### Debug Tools (Developer) — extensions
- `query-profiler` — Slow query log with EXPLAIN plans, no external plugin required.
- `rest-explorer` — Postman-lite for WP's REST API, stored collections per user.
- `cron-profiler` — Track cron event execution time, find slow recurring jobs.

### Developer Tools (Developer) — extensions
- `filesystem-monitor` — Watch `wp-content` for file changes (audit + alert). Hooks into event store.
- `scss-snippets` — Allow SCSS in custom CSS snippets with PHP-side compile cache (pure PHP compiler vendored).
- `php-error-console` — In-admin PHP error console, live tailing via SSE.
- `wp-config-editor` — Safe editor with backup + restart detection.
- `wp-cli-parity` — Every WPT admin action also available via `wp wpt *` commands. Full CLI coverage.

---

## v3 Summary

| Category | New parents | New sub-modules |
|---|---|---|
| Observability | 2 | 8 |
| Automation | 1 | 5 |
| Network | 1 | 7 |
| Content Intelligence | 1 | 7 |
| SEO Toolkit | 1 | 8 |
| Forms Pro | 1 | 6 |
| Media Intelligence | 1 | 6 |
| Editorial Collaboration | 1 | 4 |
| Dev Tools extensions | (existing parents) | 8 |
| **v3 additions** | **9 new parents** | **~59 new sub-modules** |

**Grand total after v2 + v3**: ~200 sub-modules, ~37 parent modules, 14 app pages (vs. 9 today).

---

## Tier Strategy

From the approved plan §5. Three tiers total:

### Free (wins the install)
- Every current scope module not marked Pro
- All v2 security additions (`security-headers`, `honeypot-forms`, `suspicious-activity-alerts`, `strong-password-policy`, `user-enumeration-block`)
- All v2 performance additions
- `hook-inspector`, `options-browser`, `transient-browser`, `rewrite-rules-viewer`, `capability-tester`
- Per-role UI profiles, cheat sheet, dashboard welcome panel
- Event store + audit log (30-day retention)
- REST API (read-only)

### Pro ($99/yr/site — individual premium)
- All current Pro modules (Two-Factor Auth, CPT Builder, Debug Tools, White Label + existing Pro sub-modules)
- Observability: `alert-rules`, `retention-policy` (long retention), `custom-dashboards`, `workflow-builder`, `inbound-webhooks`
- Content Intelligence: all
- SEO Toolkit: all
- Forms Pro: all
- Media Intelligence: `cdn-offload`, `unused-media`, `bulk-alt-text-editor`
- Editorial Collaboration: all
- REST API (write + full access)
- Settings history (unlimited versions)

### Network ($249/yr/unlimited sites on 1 network — agency tier)
- Everything in Pro
- Network Dashboard + all 7 sub-modules
- Module broadcast, site templates, network audit
- Network-scoped REST API
- Priority support SLA (if support is in scope)

Rationale: agencies running 20+ sites get Network for $249/yr vs. stitching together a Frankenstein of plugins + spreadsheets. Individual site owners get Pro. Hobbyists get Free, which is already genuinely useful at v2.

---

## What's Explicitly NOT in v3

Proposed and rejected during the v2 expansion planning (approved plan §8):

- ❌ AI content assistance, rewrite, summarize
- ❌ AI alt text generation (keep manual bulk UI)
- ❌ Semantic search via embeddings (command palette stays fuzzy string match)
- ❌ Anomaly detection via ML (rule-based only)
- ❌ Internal linking suggestions via LLM (graph visualization only)
- ❌ Auto-tagging of content
- ❌ Chatbot admin assistant
- ❌ Document Q&A
- ❌ Any external API requiring an API key beyond optional opt-in integrations (Stripe for forms, S3/Bunny/R2 for CDN offload)

Also deferred / cut for scope discipline:

- **Passkey (WebAuthn) auth** — Deferred to post-v3. Complex UX, limited browser support in WP admin.
- **Inline analytics** (GA / Matomo integration) — Out of scope. User goes to their analytics provider.
- **Page builder replacement** — Absolutely not. Cleanup only.
- **Theme builder** — Not a theme plugin.
- **Backup plugin** — Settings versioning only. Not a backup product.
- **File manager** — Security risk, already flagged as deferred in current scope.
- **Plugin installer replacement** — Stay out of `plugins.php` beyond `active-plugins-first`.

---

## Sequenced Execution

v3 categories are built in this order, each as a separate build cycle (spec → build → verify → ship):

1. **Observability** — Event Stream app + Alerting + Metrics dashboards. Proves the event store investment from v2.
2. **Automation** — Workflow Builder. Agencies need this; depends on event store + job runner.
3. **Network** — Network Dashboard + module broadcast + site templates. The Network tier becomes real.
4. **Content Intelligence** — Rule-based content scoring. Independent of v3 foundations.
5. **SEO Toolkit** — Per-post meta, schema, sitemaps. Independent.
6. **Forms Pro** — Promote `form-builder` to full parent. Depends on submissions DB (job runner handles exports).
7. **Media Intelligence** — CDN offload + dedup + unused cleanup. Depends on job runner.
8. **Editorial Collaboration** — Comments, review queue, presence. Depends on event store (presence via SSE) and REST layer.
9. **Advanced Developer Tools** — Query profiler, REST explorer, filesystem monitor, SCSS, PHP error console, wp-config editor, WP-CLI parity. Can land piecemeal throughout v3.

---

## References

- Plan of record: `.claude/plans/fuzzy-singing-moth.md`
- v2 architecture upgrades: `docs/IMPROVEMENTS.md` §"v2 Architecture Upgrades"
- v2 module hierarchy: `docs/module-hierarchy.md`
- Architectural decisions: `docs/DECISIONS.md` (composite PK, no-AI, multisite-first, event store unification)
- Canonical module spec template: `docs/modules/_TEMPLATE.md`
