# WPTransformed — Canonical Feature Scope v5.2.3

## Version Notes

v5.2.3 consolidates the deep-dive review and adds build-readiness details and several missed utility modules:

- Permalink / Slug Manager
- Login Notifications
- Object Cache Status
- Custom Admin Footer
- Environment Detector / Hosting Awareness
- Plugin Replacement Matrix
- Architecture non-negotiables
- Database table plan
- Admin UI / accessibility requirements
- QA and verification requirements
- Packaging/pricing notes
- Codex build cautions

## Document Purpose

This file is the current source of truth for the next WPTransformed build cycle.

## v5.2.3 Notes

- Establishes the canonical site profile list used by onboarding, admin transformation, module recommendations, and docs.
- Adds Ecommerce / Store as an explicit profile.
- Clarifies that comments/tools visibility is not a global chrome concern; role/profile restrictions belong to Client-Safe Mode or explicit module settings.

It consolidates the latest product decisions into a clean, buildable scope for Codex / Claude / developer implementation. Older docs may contain broader roadmap ideas, but this file should be treated as the working product specification unless superseded.

---

# Product Positioning

## Product Name

**WPTransformed**

## Core Promise

**WordPress, finished.**

WPTransformed turns the default WordPress admin into a cleaner, safer, more modern, more client-friendly control center. It replaces the everyday utility plugin stack agencies and site owners install on nearly every WordPress site, while staying compatible with dedicated caching, backup, SEO, security, and forms tools.

## Primary User Segments

1. **Agencies and freelancers** managing client WordPress sites.
2. **Site owners** who want WordPress to feel more complete out of the box.
3. **Editors/content teams** who need a safer, cleaner admin experience.
4. **Developers/power users** who want standardized utilities, safer tooling, and fewer plugins.

## Product Thesis

Most WordPress sites accumulate 10–25 single-purpose utility plugins for basic admin cleanup, login protection, redirects, SMTP, duplicate posts, media organization, snippets, and white labeling.

WPTransformed consolidates those everyday utilities into one modular admin layer with:

- Modern UX
- Safer defaults
- Role-aware editor/client controls
- Core vs Pro clarity
- Recovery/safe mode
- Agency presets
- Zero-load inactive modules

---

# Product Guardrails

## WPTransformed Should Replace

WPTransformed should replace the common utility plugin stack:

- Duplicate post plugins
- Admin cleanup plugins
- Basic login hardening plugins
- Basic SMTP plugins
- Redirect plugins
- Media folder/replace plugins
- Disable comments / disable components plugins
- Basic database cleanup plugins
- Header/footer code plugins
- Login customization plugins
- White label/admin branding plugins
- Basic SEO metadata helpers
- Basic schema/llms.txt utilities

## WPTransformed Should Not Replace

WPTransformed should not try to fully replace:

- Full caching plugins
- CDN systems
- Backup/migration plugins
- Malware scanners
- WAF/security suites
- Full SEO suites
- Full compliance/cookie consent platforms
- Full form plugins
- Project management systems
- Analytics platforms
- MainWP/ManageWP-style multi-site SaaS platforms

## Compatibility Position

WPTransformed should be compatible with:

- Yoast
- Rank Math
- AIOSEO
- SEOPress
- WP Rocket
- LiteSpeed Cache
- Wordfence
- Patchstack
- Sucuri
- UpdraftPlus
- BlogVault
- Elementor
- Divi
- Bricks
- Beaver Builder
- Oxygen
- Breakdance
- WPBakery
- Gutenberg
- WP BuiltRight Forms / companion forms plugin

---

# Tier Labels

## Core

Included in the free/base plugin.

Core features should feel like things WordPress should reasonably include out of the box.

## Pro

Paid features for agencies, teams, advanced workflows, reporting, automation, role control, white labeling, advanced schema, and power users.

## Advanced Core

Included in Core, but hidden behind warnings, confirmations, advanced settings, or onboarding safeguards.

## Pro Advanced

Paid feature with higher risk or complexity. Requires warnings, dry-runs, recovery support, and/or backup prompts.

## Companion Plugin

Handled by another WPTransformed / WP BuiltRight ecosystem plugin, but integrated into WPTransformed.

## Deferred

Good idea, but not part of the near-term build.

## Cut

Not planned for WPTransformed because it creates too much liability or category drift.

---

# Top-Level Admin Navigation

WPTransformed should use a clean top-level navigation model.

1. Dashboard
2. Setup Wizard
3. Migration Center
4. Client-Safe Mode
5. Modules
6. Admin Experience
7. Content & Media
8. Search & AI Visibility
9. Security & Login
10. Users & Roles
11. Email Delivery
12. Performance
13. Database
14. Design
15. Code Manager
16. Utilities
17. Integrations
18. Reports
19. Developer Tools
20. Settings

---

# 1. Setup Wizard

## 1.1 First-Run Setup Wizard — Core

A guided onboarding experience that prevents users from facing a giant module grid on first activation.

### Canonical Site Profiles

These six profiles are canonical across all WPTransformed docs and implementation:

1. Personal / Blogger
2. Business Website
3. Ecommerce / Store
4. Agency Client Site
5. Content Team / Editors
6. Developer-Managed Site

Other docs must reference this list instead of redefining profile options.

Profile selection may recommend modules, presets, and Client-Safe rules, but it must not cause Global Admin Transformation to hide native WordPress menu pages. Menu hiding/restriction belongs to Client-Safe Mode or explicit module settings.

### Subfeatures

- Site profile selection:
  - Personal / Blogger
  - Business Website
  - Ecommerce / Store
  - Agency Client Site
  - Content Team / Editors
  - Developer-Managed Site
- Goal selection:
  - Clean up WordPress
  - Protect the login
  - Make editors safer
  - Improve client handoff
  - Replace utility plugins
  - Prepare for agency standardization
- Editor lockdown level:
  - Standard WordPress
  - Guided Editor
  - Client-Safe Mode
  - Locked Content-Only Mode
- Preset selection:
  - Clean WordPress
  - Client-Safe WordPress
  - Agency Handoff
  - Developer Toolkit
  - Security Basics
  - Search Appearance Basics
- Plain-English review before applying changes.
- Safe defaults only.
- Risky features require explicit opt-in.
- Ability to skip and configure manually.

## 1.2 Presets / Blueprints — Core + Pro

Reusable configuration profiles for agencies and repeat site builds.

### Core Subfeatures

- Apply built-in presets.
- Export settings as JSON.
- Import settings from JSON.
- Reset to safe defaults.
- Import/export module states and settings.

### Pro Subfeatures

- Agency Blueprints.
- Save current configuration as named blueprint.
- Lock selected blueprint settings.
- Compare current site against blueprint.
- Show drift from agency standard.
- Blueprint version history.
- Blueprint notes.
- Apply blueprint after onboarding.

---

# 2. WPTransformed Dashboard

## 2.1 Main Dashboard — Core

The home base for WPTransformed.

### Subfeatures

- Transformation Score.
- Active module summary.
- Quick actions.
- Recent activity.
- Client-Safe status.
- Security snapshot.
- Database health snapshot.
- Email delivery status.
- Notification Center preview.
- Search/AI visibility status.
- Connected integrations status.

## 2.2 Quick Actions — Core

Fast links to common actions.

### Subfeatures

- Open Command Palette.
- Duplicate a page.
- Add redirect.
- Send test email.
- Run database cleanup.
- View failed logins.
- Open Client-Safe Mode.
- Open Search Appearance.
- Export settings.

## 2.3 Site Health Snapshot — Core

Plain-English site status summary.

### Subfeatures

- WordPress version.
- PHP version.
- MySQL/MariaDB version.
- SSL status.
- Memory limit.
- Upload limit.
- Active theme.
- Detected builder.
- Plugin conflict warnings.
- Login protection status.
- Email delivery status.
- Database cleanup status.

---

# 3. Module Library

## 3.1 Module Grid — Core

Searchable module control center.

### Subfeatures

- Module cards.
- Category filters.
- Core/Pro/Advanced badges.
- Safe/Moderate/Advanced/Dangerous risk labels.
- Search by module name.
- Search by user problem.
- Configure button per module.
- Enable/disable toggles.
- Related module suggestions.

## 3.2 Module Bundles — Core + Pro

One-click feature groups.

### Core Bundles

- Clean Admin
- Basic Security
- Content Essentials
- Performance Hygiene
- Search Appearance Basics
- Email Delivery Basics

### Pro Bundles

- Agency Handoff
- Client-Safe Pro
- White Label Agency
- Schema Builder Pro
- AI Visibility Pro
- Reports & Integrations
- Developer Advanced

---

# 4. Client-Safe Mode

## 4.1 Client-Safe Mode — Core

A dedicated experience for keeping editors and clients away from dangerous or confusing WordPress areas.

### Subfeatures

- Guided editor access.
- Hide technical menus from non-admins.
- Hide plugin/theme/settings areas from editors.
- Hide plugin notices from editors.
- Simplified client/editor dashboard.
- Safe content workspace.
- View admin as another role.
- Role-based admin menu visibility.
- Role-based dashboard widget visibility.
- Client-Safe status card on main dashboard.

## 4.2 Client Dashboard — Core + Pro

A cleaner dashboard for editors and clients.

### Core Subfeatures

- Welcome message.
- Assigned content links.
- Recent drafts.
- Recent pages.
- Help/support links.
- Basic quick actions.

### Pro Subfeatures

- Branded dashboard builder.
- Custom widgets:
  - Video
  - HTML
  - Shortcode
  - Iframe
  - Support card
  - Documentation card
- Per-role dashboard layouts.
- Agency support contact panel.
- Training/SOP links.
- Client-specific notes.

## 4.3 Page Builder Restrictions — Core + Pro

Restrict builders where editors should not use them.

### Core Subfeatures

- Detect active builder globally.
- Detect builder per post.
- Restrict builder access by post type.
- Warn when duplicating builder-based content.

### Pro Subfeatures

- Role-based builder access.
- Lock builder editing for specific pages.
- Builder-specific safety rules.
- Agency builder access presets.

## 4.4 Plugin / Theme Visibility — Pro

Hide sensitive plugins and themes from client/admin users.

### Subfeatures

- Hide specific plugins from plugin list.
- Lock critical plugins from deactivation.
- Hide selected themes.
- Agency-safe plugin visibility rules.
- Audit log when protected plugins are changed.

---

# 5. Admin Experience

## 5.1 Admin Bar Manager — Core

Clean and customize the WordPress admin bar.

### Subfeatures

- Remove unwanted default admin bar items.
- Hide WordPress logo.
- Hide comments shortcut.
- Hide new content shortcut.
- Hide customize shortcut.
- Add custom admin bar links.
- Role-based admin bar visibility.
- Hide frontend admin bar by role.

## 5.2 Command Palette — Core

Keyboard-first navigation for WordPress.

### Subfeatures

- Ctrl+K / Cmd+K launcher.
- Search admin pages.
- Search posts/pages.
- Search users.
- Search media.
- Search WPTransformed modules.
- Quick actions:
  - Create post
  - Create page
  - Open settings
  - Toggle safe modules
  - Run safe utilities
- Recent admin destinations.
- Keyboard navigation.
- Extensible command registration.

## 5.3 Dashboard Manager — Core

Clean up the default WordPress dashboard.

### Subfeatures

- Hide default dashboard widgets.
- Set dashboard column layout.
- Role-based dashboard widget control.
- Activity feed widget.
- Admin quick notes.
- Duplicate widgets.

## 5.4 Notification Center — Core + Pro

Centralized alert drawer for notices and important site events.

### Core Subfeatures

- Hide noisy admin notices.
- Move notices into drawer/panel.
- Read/unread state.
- Dismiss notices.
- Filter by type.
- Bell icon in admin bar.
- Failed email notifications.
- Login lockout notifications.
- Database cleanup notifications.

### Pro Subfeatures

- Notification rules by role.
- Email notification preferences.
- Quiet hours.
- Notification digests.
- Inline quick actions for supported modules.
- Slack / Teams / webhook notification routing via Integrations Hub.

## 5.5 List Tables & Columns — Core

Improve Posts, Pages, Users, Plugins, and CPT list screens.

### Subfeatures

- Featured image column.
- ID column.
- Word count column.
- Modified date column.
- Slug column.
- Page template column.
- Registration date column.
- Last login column.
- Search visibility/noindex column.
- Taxonomy filters.
- Active plugins first.
- Sticky list table header.

## 5.6 Keyboard Shortcuts & Bookmarks — Core

Make WordPress faster for power users.

### Subfeatures

- Save shortcut.
- Publish shortcut.
- Toggle sidebar shortcut.
- Open command palette shortcut.
- Shortcut cheat sheet.
- Admin bookmarks.
- Bookmark groups.
- Role-based bookmarks.

## 5.7 Smart Menu Organizer — Core + Pro

Automatically organize the WordPress admin sidebar into cleaner sections.

### Core Subfeatures

- Group admin menu into:
  - Content
  - Design
  - Security
  - Tools
  - Configure
- Detect page builders and place them correctly.
- Detect companion forms plugin and place under Content.
- Detect ecommerce plugins but do not deeply manage them.

### Pro Subfeatures

- Save multiple sidebar configurations.
- Role-based menu configurations.
- Per-user menu configurations.
- Agency sidebar presets.

## 5.8 Admin Menu Editor — Pro

Full visual admin menu editor.

### Subfeatures

- Drag-and-drop menu ordering.
- Rename menu items.
- Change icons.
- Hide items by role.
- Hide items by user.
- Add custom links.
- Add separators.
- Live sidebar preview.
- Multiple saved configurations.
- Agency menu templates.

---

# 6. Content & Media

## 6.1 Content Duplication — Core

Duplicate posts, pages, and selected custom post types.

### Subfeatures

- Duplicate as draft.
- Copy title.
- Copy content.
- Copy excerpt.
- Copy featured image.
- Copy taxonomies.
- Copy custom fields.
- Copy builder metadata safely.
- Row action.
- Admin bar action.
- Configure allowed post types.

## 6.2 Public Preview — Core + Pro

Share drafts with people who are not logged in.

### Core Subfeatures

- Generate expiring preview links.
- Revoke preview links.

### Pro Subfeatures

- Password-protected preview links.
- Preview access logs.
- Client review links.

## 6.3 Disable Comments — Core

Turn off comments where they are not needed.

### Subfeatures

- Disable comments globally.
- Disable comments by post type.
- Hide comment admin pages.
- Hide dashboard comment widgets.
- Remove frontend comment forms.

## 6.4 External Links in New Tab — Core

Automatically open external links safely.

### Subfeatures

- Add `target="_blank"`.
- Add `rel="noopener noreferrer"`.
- Domain allowlist.
- Domain denylist.

## 6.5 External Permalinks — Core

Let posts/pages point to external URLs.

### Subfeatures

- External URL field.
- Redirect single post/page requests.
- Archive title links point externally.


## 6.5.1 Permalink / Slug Manager — Core + Pro

Clean up and manage URL slugs without needing a separate utility plugin.

### Core Subfeatures

- Show slug quality warnings on posts/pages.
- Detect duplicate or near-duplicate slugs.
- Detect overly long slugs.
- Detect uppercase characters and unsafe characters.
- Suggest clean lowercase hyphenated slugs.
- Optional slug history when a slug changes.
- Offer to create redirect when slug changes if Redirect Manager is active.

### Pro Subfeatures

- Bulk slug cleanup.
- Slug pattern templates by post type.
- Import/export slug changes.
- Dry-run preview before bulk changes.
- Automatic redirect creation for bulk slug changes.
- Slug change audit log.

### Guardrails

- Never change slugs automatically without review.
- Bulk changes require Safety Check Modal.
- Always offer redirect creation when changing published URLs.

## 6.6 Auto-Publish Missed Schedule — Core

Fix missed scheduled posts.

### Subfeatures

- Detect missed scheduled posts.
- Publish automatically.
- Optional notification when fixed.

## 6.7 Post Type Switcher — Core

Convert content between post types.

### Subfeatures

- Change post to page.
- Change page to post.
- Support selected CPTs.
- Capability checks.

## 6.8 Content Order — Core + Pro

Drag-and-drop content ordering.

### Core Subfeatures

- Reorder hierarchical pages and CPTs.

### Pro Subfeatures

- Reorder non-hierarchical post types.
- Frontend ordering controls.
- Import/export order.

## 6.9 Terms Order — Pro

Drag-and-drop taxonomy term ordering.

### Subfeatures

- Reorder categories.
- Reorder tags.
- Reorder custom taxonomies.
- Preserve order in admin.
- Optional frontend order output.

## 6.10 Bulk Content Editor — Pro Advanced

Batch-edit content safely.

### Subfeatures

- Bulk change author.
- Bulk change status.
- Bulk change categories.
- Bulk change tags.
- Bulk change dates.
- Bulk change selected custom fields.
- Dry-run preview.
- Reversible operation log where possible.
- Strong confirmation before applying.

## 6.11 Page Hierarchy Organizer — Pro

Visual tree for large page structures.

### Subfeatures

- Nested page tree.
- Drag-and-drop hierarchy.
- Collapse/expand branches.
- Search within hierarchy.

## 6.12 Preserve Taxonomy Hierarchy — Core

Keep checked categories in their original tree position.

### Subfeatures

- Prevent checked categories from jumping to top.
- Preserve visual hierarchy in editor metabox.

## 6.13 Media Folders — Core + Pro

Organize media without moving files.

### Core Subfeatures

- Virtual media folders.
- Folder tree.
- Drag media into folders.
- Filter library by folder.
- No physical file moves.

### Pro Subfeatures

- Role-based folder access.
- Smart folders.
- Bulk folder operations.

## 6.14 Media Replace — Core

Replace a file without changing its URL.

### Subfeatures

- Replace image/PDF/file.
- Keep attachment ID.
- Keep existing URL where possible.
- Regenerate image sizes.
- Preserve existing references.

## 6.15 SVG Upload — Core

Allow safer SVG uploads.

### Subfeatures

- Enable SVG MIME type.
- Sanitize SVG files.
- SVG preview in Media Library.
- Role-based SVG upload permission.

## 6.16 AVIF Upload — Core

Support AVIF where possible.

### Subfeatures

- Enable AVIF MIME type.
- Server support check.
- Admin warning if unsupported.

## 6.17 Image Sizes Panel — Core

Show registered image sizes.

### Subfeatures

- List theme/plugin image sizes.
- Show dimensions.
- Show crop status.
- Regenerate thumbnails.

## 6.18 Media Visibility Control — Pro

Restrict media access.

### Subfeatures

- Hide media from non-admins.
- Folder-based permissions.
- Per-role media visibility.

## 6.19 Local User Avatars — Core

Use Media Library avatars instead of only Gravatar.

### Subfeatures

- Upload local avatar.
- Per-user avatar setting.
- Fallback to Gravatar.

---

# 7. Search & AI Visibility

## 7.1 Search Appearance — Core

Basic search metadata WordPress should include.

### Subfeatures

- SEO title field.
- Meta description field.
- SERP preview.
- Canonical URL field.
- Robots meta:
  - index/noindex
  - follow/nofollow
- Post type title templates.
- Post type description templates.
- Metadata status columns.
- Missing metadata view.
- SEO plugin detection to avoid duplicate output.

## 7.2 Open Graph Basics — Core

Basic social sharing metadata.

### Subfeatures

- Open Graph title.
- Open Graph description.
- Open Graph image.
- Default social image.
- Disable when SEO plugin owns social metadata.

## 7.3 Basic Schema — Core

Safe, minimal JSON-LD output.

### Subfeatures

- Website schema.
- Organization schema.
- Article schema for posts.
- WebPage schema for pages.
- Breadcrumb schema when not already handled.
- Schema plugin detection.

## 7.4 Schema Builder Pro — Pro

Advanced structured data builder.

### Subfeatures

- Visual schema builder.
- JSON-LD preview.
- Schema templates by post type.
- Conditional schema rules.
- ACF/custom field mapping.
- Per-post overrides.
- LocalBusiness schema.
- LegalService schema.
- Service schema.
- Product schema.
- FAQPage schema.
- HowTo schema.
- Event schema.
- JobPosting schema.
- Person schema.
- VideoObject schema.
- Import/export schema templates.

## 7.5 llms.txt Generator — Core

Create a basic AI-readable site summary using the emerging llms.txt convention.

### Subfeatures

- Enable `/llms.txt`.
- Manual editor.
- Site summary.
- Important pages list.
- Sitemap link.
- Contact/about links.
- Preview output.
- Regenerate button.

## 7.6 AI Visibility Pro — Pro

Advanced AI-readability and LLM visibility tools.

### Subfeatures

- Auto-generate llms.txt from selected content.
- Generate llms-full.txt.
- Include/exclude post types.
- Exclude noindex/private/thin content.
- Prioritize cornerstone pages.
- Add page summaries.
- Add service/product summaries.
- Compare llms.txt against sitemap.
- Detect missing important pages.
- Scheduled regeneration.

## 7.7 Search Console Insights — Pro

Pull search performance from Google Search Console.

### Subfeatures

- Top queries.
- Top pages.
- Clicks.
- Impressions.
- CTR.
- Average position.
- Declining pages.
- Pages with impressions but low CTR.
- Include in client reports.

## 7.8 GA4 Content Insights — Pro

Pull high-level content data from GA4.

### Subfeatures

- Top pages.
- Traffic trends.
- Engagement summaries.
- Landing page performance.
- Include in client reports.

---

# 8. Security & Login

## 8.1 Login Protection — Core

Basic protection against common login abuse.

### Subfeatures

- Limit login attempts.
- Progressive lockouts.
- Failed login log.
- IP allowlist.
- IP blocklist.
- Admin notification on lockout.

## 8.2 Change Login URL — Core Advanced

Move WordPress login to a custom URL.

### Subfeatures

- Custom login slug.
- Block or redirect default wp-login.php.
- Recovery warning.
- Copy URL step.
- Email new login URL to admin.
- Safe Mode compatibility.
- Never enabled automatically.

## 8.3 Change Login URL Pro Enhancements — Pro

Agency and advanced login URL controls.

### Subfeatures

- Honeypot on old login URL.
- Login URL access logs.
- Temporary support login links.
- Agency blueprint enforcement.

## 8.4 Two-Factor Authentication — Core

Basic 2FA for stronger account security.

### Subfeatures

- TOTP app support.
- QR code setup.
- Recovery codes.
- Email fallback if SMTP is configured.
- Optional per-user setup.
- Require 2FA for administrators.
- Admin reset for locked-out users.

## 8.5 Two-Factor Authentication Pro — Pro

Team and agency enforcement.

### Subfeatures

- Enforce 2FA by role.
- Grace period before enforcement.
- Trusted devices.
- Remember device for 30 days.
- 2FA status reports.
- Require 2FA for new admin users.
- Disable email fallback for selected roles.

## 8.6 Session Manager — Core + Pro

See and control active sessions.

### Core Subfeatures

- View own sessions.
- Destroy other sessions.
- Show browser.
- Show IP.
- Show last active time.

### Pro Subfeatures

- Admin view of all user sessions.
- Session limits per role.
- Idle timeout.
- Destroy sessions for any user.

## 8.7 Audit Log — Core + Pro

Track important site activity.

### Core Subfeatures

- Log logins.
- Log logouts.
- Log failed logins.
- Log post saves.
- Log post deletes.
- Basic searchable event table.

### Pro Subfeatures

- Plugin activation logs.
- Theme activation logs.
- Settings change logs.
- User/role change logs.
- Export CSV.
- Retention policies.
- Email digest.
- Report inclusion.

## 8.8 Basic Hardening — Core

Low-risk WordPress security hygiene.

### Subfeatures

- Disable XML-RPC.
- Disable pingbacks.
- Obfuscate author slugs.
- Email obfuscator.
- Disable plugin/theme editor.

## 8.9 CAPTCHA Protection — Pro

Optional CAPTCHA for login forms.

### Subfeatures

- Cloudflare Turnstile.
- hCaptcha.
- reCAPTCHA.
- Login form.
- Registration form.
- Lost password form.

## 8.10 Password Protection — Core

Temporarily protect a site or selected content.

### Subfeatures

- Sitewide password protection.
- Page/post type protection.
- Admin bypass.
- Never enabled by default.

## 8.11 Password Policy — Pro

Stronger password rules for teams.

### Subfeatures

- Minimum length.
- Complexity requirements.
- Force password reset.
- Prevent password reuse.
- Role-based policy.


## 8.12 Login Notifications — Core + Pro

Notify users and administrators about important login-related events.

### Core Subfeatures

- Email user on successful login from a new device/browser where detectable.
- Email admin on administrator login if enabled.
- Email admin on lockout event.
- Email admin when a new administrator account is created.
- Notification Center events for failed login spikes.

### Pro Subfeatures

- Per-role login notification rules.
- Slack/Teams/webhook login alerts via Integrations Hub.
- Suspicious login digest.
- New device trust workflow when paired with 2FA Pro.
- Include login summary in agency reports.

### Guardrails

- Avoid noisy defaults.
- Do not email every normal login by default.
- Respect notification preferences and quiet hours.

---

# 9. Email Delivery

## 9.1 Email Delivery / SMTP — Core + Pro

Reliable WordPress transactional email without a crippled free tier.

### Core Goal

Core should cover everything a normal site owner expects from a serious free SMTP plugin: authenticated sending, common mailer support, generic SMTP, test emails, sender identity, error visibility, and a clean setup wizard.

### Core Subfeatures

- Email setup wizard.
- Force From Email.
- Force From Name.
- Return-path / bounce email option where supported.
- Test email tool.
- Email deliverability status card.
- Email error tracking in admin.
- Basic weekly email summary:
  - Sent count
  - Failed count
  - Mailer used
- Generic SMTP support.
- SMTP host.
- SMTP port.
- Encryption.
- Authentication.
- Username.
- Password.
- Secure credential storage with masking in UI.
- Mailer connection health check.
- Setup documentation links per mailer.
- Failed-send notice in Notification Center.
- Integration with 2FA email fallback.

### Core Mailers

- Generic SMTP / Other SMTP.
- SendLayer.
- SMTP.com.
- Brevo.
- Google Workspace / Gmail manual connection.
- Mailgun.
- Postmark.
- SendGrid.
- SMTP2GO.
- SparkPost.
- Mailjet.
- Elastic Email.
- MailerSend.

### Pro Mailers

- Microsoft 365 / Outlook.
- Amazon SES.
- Zoho Mail.
- Gmail one-click OAuth setup.

### Pro Subfeatures

- Advanced email log.
- Store email content.
- Store attachment metadata.
- Source/plugin detection for each email.
- Resend email from log.
- Open tracking.
- Click tracking.
- Advanced email reports.
- Email deliverability graphs.
- Failed email alerts by:
  - Email
  - Slack
  - Microsoft Teams
  - Discord
  - SMS/Twilio
  - Webhook
- Backup mailer connections.
- Automatic failover to backup mailer.
- Smart email routing.
- Route by:
  - Email type
  - Recipient
  - Subject
  - Source plugin
  - Site event
- Manage default WordPress notification emails.
- Disable selected core emails.
- Email digest scheduling.
- Agency report inclusion.

## 9.2 Email Log — Pro

Track outgoing WordPress emails.

### Subfeatures

- Recipient.
- Subject.
- Timestamp.
- Status.
- Mailer.
- Delivery error details.
- Email body preview.
- Attachment metadata.
- Source/plugin that triggered the email.
- Search and filters.
- Resend button.
- Export logs.
- Retention settings.

---

# 10. Performance Hygiene

## 10.1 Lightweight Asset Hygiene — Core

Small WordPress cleanup features that do not replace caching plugins.

### Subfeatures

- Disable emojis.
- Disable embeds.
- Remove generator tag.
- Remove WLW manifest.
- Remove RSD link.
- Remove shortlinks.
- Optional jQuery migrate control.

## 10.2 Heartbeat Control — Core

Reduce unnecessary admin-ajax heartbeat activity.

### Subfeatures

- Dashboard heartbeat interval.
- Editor heartbeat interval.
- Frontend heartbeat control.
- Builder-safe exceptions.

## 10.3 Revision Control — Core

Control revision-related database growth.

### Subfeatures

- Limit revisions by post type.
- Disable revisions by post type.
- Cleanup old revisions.

## 10.4 Image Upload Control — Core + Pro

Prevent oversized uploads.

### Core Subfeatures

- Strip EXIF/metadata.
- Max upload dimensions.
- Image quality settings.

### Pro Subfeatures

- WebP conversion.
- AVIF conversion where supported.
- Per-role upload limits.

## 10.5 Auto-Clear Caches — Core

Clear common caching layers after content changes.

### Subfeatures

- Detect common caching plugins.
- Clear cache after post save.
- Clear cache after menu update.
- Object cache flush option.
- Hosting-aware cache hooks.

## 10.6 Disable Thin Archives — Core

Reduce unwanted thin pages and clutter.

### Subfeatures

- Disable attachment pages.
- Disable author archives.
- Disable feeds.
- Disable self-pingbacks.


## 10.6.1 Object Cache Status — Core

Passive visibility into persistent object cache health without trying to replace hosting/cache plugins.

### Subfeatures

- Detect persistent object cache availability.
- Detect Redis, Memcached, APCu, or host-provided object cache when possible.
- Show connected/disconnected status.
- Show basic drop-in status for `object-cache.php`.
- Warn when object cache is expected but not active.
- Link to host/plugin documentation where appropriate.

### Guardrails

- Do not manage Redis/Memcached configuration directly.
- Do not flush persistent object cache automatically unless user explicitly requests it.

## 10.7 Asset Manager — Deferred / Pro Advanced

Power tool for disabling scripts/styles per page.

### Status

- Defer until core product is stable.
- High support risk.
- Should include safe exclusions and rollback if built.

---

# 11. Database Optimizer

## 11.1 Database Optimizer — Core + Pro

Clean common WordPress database bloat.

### Core Subfeatures

- Delete spam comments.
- Delete trashed comments.
- Delete trashed posts.
- Delete auto-drafts.
- Delete expired transients.
- Delete old revisions.
- Table size view.
- Dry-run preview.

### Pro Subfeatures

- Scheduled cleanup.
- Cleanup history.
- Orphaned metadata cleanup with stronger safeguards.
- Table optimization.
- Email cleanup reports.
- Report inclusion.

---

# 12. Design & White Label

## 12.1 Login Designer — Core + Pro

Customize the WordPress login page.

### Core Subfeatures

- Site logo on login.
- Basic background color/image.
- Basic button color.
- Site identity sync.

### Pro Subfeatures

- Visual login customizer.
- Live preview.
- Preset templates.
- Form styling.
- Gradient backgrounds.
- Device preview.
- Agency login presets.

## 12.2 Admin Theme — Core

Modernize the WordPress admin.

### Subfeatures

- Dark mode.
- Match system preference.
- Manual theme toggle.
- Admin color schemes.
- Environment indicator.
- Admin body classes.

## 12.3 White Label — Pro

Agency-grade rebranding.

### Subfeatures

- Replace WordPress branding.
- Replace admin footer.
- Custom admin logo.
- Custom plugin branding.
- Hide WPTransformed branding.
- Custom help tabs.
- Custom admin colors.
- Hide upgrade prompts from client users.
- Agency support links.


## 12.4 Custom Admin Footer — Core + Pro

Replace the default WordPress admin footer text with something more useful.

### Core Subfeatures

- Replace footer text.
- Plain text footer mode.
- Simple agency/support credit.

### Pro Subfeatures

- HTML footer mode.
- Per-role footer messages.
- Support link insertion.
- White Label integration.

---

# 13. Code Manager

## 13.1 Header / Body / Footer Code Manager — Core

Add common scripts without editing theme files.

### Subfeatures

- Header code.
- Body open code.
- Footer code.
- Global placement.
- Conditional placement.
- Common use cases:
  - GTM
  - GA4
  - Pixels
  - Chat widgets
  - Verification tags

## 13.2 Custom CSS — Core

Add admin or frontend CSS safely.

### Subfeatures

- Admin CSS.
- Frontend CSS.
- Code editor.
- Optional page/post type targeting.

## 13.3 Code Snippets Manager — Pro Advanced

Run PHP, JS, CSS, or HTML snippets with safety protections.

### Subfeatures

- PHP snippets.
- JS snippets.
- CSS snippets.
- HTML snippets.
- Enable/disable snippets.
- Scope by frontend/admin.
- Conditional loading.
- Fatal error recovery.
- Version history.
- Import/export snippets.
- Never enabled by default.

---

# 14. Utilities

## 14.1 Redirect Manager — Core + Pro

Manage redirects without a separate plugin.

### Core Subfeatures

- 301 redirects.
- 302 redirects.
- Redirect hit counter.
- Search/filter redirects.

### Pro Subfeatures

- Regex redirects.
- Import/export.
- Redirect groups.
- Bulk redirect creation.

## 14.2 404 Monitor — Core + Pro

Find broken URLs.

### Core Subfeatures

- Log 404 URLs.
- Show referrer.
- Show timestamp.
- One-click create redirect.

### Pro Subfeatures

- 404 spike alerts.
- Export logs.
- Auto-suggest nearest match.
- Report inclusion.

## 14.3 Maintenance Mode — Core + Pro

Temporarily show a maintenance page.

### Core Subfeatures

- Basic maintenance page.
- Admin bypass.
- SEO-safe 503 status.

### Pro Subfeatures

- Visual maintenance page builder.
- Coming soon mode.
- Role bypass links.
- Launch countdown.
- Email capture.
- Scheduled activation.

## 14.4 Robots.txt Manager — Core

Edit virtual robots.txt.

### Subfeatures

- View current robots rules.
- Edit rules.
- Preview output.
- Syntax hints.

## 14.5 Ads.txt Manager — Core

Edit ads.txt without FTP.

### Subfeatures

- Edit ads.txt.
- Validate line format.
- Preview output.

## 14.6 Cron Manager — Pro Advanced

Manage WP-Cron events.

### Subfeatures

- View cron jobs.
- Run cron event manually.
- Add cron events.
- Edit cron events.
- Delete cron events.
- Missed cron warnings.
- Execution history.

## 14.7 Search & Replace — Pro Advanced

Safe database search and replace.

### Integration Note

The user already has a good plugin/build for this. WPTransformed should tie into it as a Pro Advanced module or companion integration rather than rebuilding from scratch if the existing code is solid.

### Subfeatures

- Serialized data support.
- Dry run.
- Affected rows preview.
- Strong confirmation.
- Backup warning.
- Undo only where feasible.
- Integration with Recovery Center where possible.

## 14.8 Custom Post Types — Pro / Companion Integration

Visual CPT and taxonomy management.

### Integration Note

The user already has good plugin work in this area. WPTransformed should connect to that existing plugin/build as a Pro feature or companion module, rather than bloating Core.

### Subfeatures

- Custom post type builder.
- Custom taxonomy builder.
- Field group support if available in companion plugin.
- Export generated code.
- Import/export CPT configurations.
- Show CPT status in WPTransformed dashboard.
- Include CPTs in content tools where appropriate.

---

# 15. Integrations Hub

## 15.1 Integrations Hub — Core + Pro

Central place to connect external services.

### Core Subfeatures

- Connection cards.
- Connection health.
- API key vault.
- Test connection.
- GA4 connection.
- Google Search Console connection.
- Cloudflare connection status.

### Pro Subfeatures

- Slack notifications.
- Mailchimp connection.
- Webhook manager.
- Retry logs.
- Integration event history.
- Include integration data in reports.

## 15.2 Forms Bridge — Companion Plugin

Connect WPTransformed to the separate forms plugin suite.

### Subfeatures

- Detect installed forms plugin.
- Install/activate free forms plugin.
- Show forms shortcut in dashboard.
- Show entry notifications in Notification Center.
- Include form summaries in reports.
- Route form events to integrations if Pro integration features are active.

---

# 16. Reports & Agency Tools

## 16.1 Agency Client Reports — Pro

White-labeled maintenance and site activity reporting.

### Subfeatures

- Weekly reports.
- Monthly reports.
- Performance hygiene summary.
- Security summary.
- Content activity summary.
- Redirect and 404 summary.
- Metadata/schema/llms.txt summary.
- GA4/GSC summary if connected.
- Email delivery summary.
- White-labeled PDF export.
- Scheduled email delivery.

## 16.2 Client Handoff Checklist — Pro

Structured launch/handoff checklist for agencies.

### Subfeatures

- SSL check.
- Login protection check.
- Email delivery check.
- Metadata basics check.
- Redirects/404 check.
- Admin cleanup check.
- Client-Safe Mode check.
- Export handoff PDF.

## 16.3 Temporary User Access — Pro

Grant access that expires automatically.

### Subfeatures

- Create temporary access for contractors/support.
- Expiration date/time.
- Auto-disable user.
- Access log.
- Reminder before expiration.

---

# 17. Developer & Diagnostics

## 17.1 System Summary — Core

Show key system information for troubleshooting.

### Subfeatures

- WordPress version.
- PHP version.
- MySQL/MariaDB version.
- Memory limit.
- Upload limit.
- Server software.
- Active plugins count.
- Theme detection.
- Builder detection.
- Copy to clipboard.

## 17.2 Error Log Viewer — Pro

View PHP errors without FTP/SSH.

### Subfeatures

- View debug.log.
- Filter by severity.
- Search logs.
- Tail mode.
- Download log.
- Clear log.

## 17.3 Conflict Detector — Core

Warn about overlapping or conflicting plugins.

### Subfeatures

- Detect duplicate functionality.
- Warn about stacked login/security tools.
- Suggest safe migration path.
- Show conflict severity.
- Show replacement opportunities.

## 17.4 Recovery Center / Safe Mode — Core

Help users recover if a module causes problems.

### Subfeatures

- Disable all modules for one request.
- Disable last activated module.
- Quarantine crashing modules.
- Recovery URL.
- Recovery email.
- Restore previous known-good configuration.

## 17.5 Support Package — Core + Pro

Generate a redacted support bundle.

### Core Subfeatures

- System summary.
- Active modules.
- Recent errors.
- Conflict warnings.
- Redacted settings export.

### Pro Subfeatures

- Audit log excerpt.
- Report-ready diagnostics PDF.
- Agency support branding.

## 17.6 WP-CLI Commands — Pro

Developer/agency automation tools.

### Subfeatures

- List modules.
- Enable modules.
- Disable modules.
- Import presets.
- Export presets.
- Run database cleanup dry-run.
- Export audit logs.
- Enable safe mode.


## 17.7 Environment Detector / Hosting Awareness — Core

Detect the hosting and server environment so modules can adapt safely.

### Subfeatures

- Detect common hosts where possible:
  - WP Engine
  - Kinsta
  - Cloudways
  - Pantheon
  - Flywheel
  - SiteGround
  - LiteSpeed-based hosts
- Detect server type:
  - Apache
  - Nginx
  - LiteSpeed
- Detect filesystem writability.
- Detect disabled PHP functions that affect advanced tools.
- Detect WP-Cron status.
- Detect object cache drop-in.
- Detect multisite.
- Surface environment warnings in System Summary.

### Usage By Other Modules

- Change Login URL avoids unsafe `.htaccess` assumptions.
- Auto-Clear Caches chooses the correct purge method.
- Database Optimizer adjusts batch sizes on low-memory sites.
- Code Manager / Search & Replace warn when recovery paths are limited.
- Media/Image tools warn when image libraries are missing required formats.

---


# 18. Migration, Safety & Data Governance

## 18.1 Migration Center — Core + Pro

Help users safely move away from overlapping utility plugins and into WPTransformed.

### Core Subfeatures

- Detect overlapping plugins.
- Show “What WPTransformed can replace on this site.”
- Show replacement confidence:
  - Exact replacement
  - Partial replacement
  - Compatible companion
  - Keep existing plugin
- Import/migration checklist.
- Migration log.
- Safe recommendation to deactivate old plugin only after equivalent WPTransformed module is configured.
- Conflict warnings during onboarding.

### Core Import Targets

- Admin Site Enhancements basic settings where practical.
- WP Mail SMTP basic SMTP settings where practical.
- Redirection basic redirects where practical.
- Yoast Duplicate Post settings where practical.
- WPS Hide Login login slug where practical.
- Limit Login Attempts basic settings where practical.
- Header/footer code plugins where practical.

### Pro Subfeatures

- WPCode snippet import.
- Admin Menu Editor configuration import.
- User Role Editor role/capability import.
- Better Search Replace companion integration.
- Custom Post Type UI / CPT companion integration.
- Redirect import/export mapping.
- Migration dry run.
- Migration rollback notes.
- Agency migration report.


## 18.1.1 Plugin Replacement Matrix — Core

A structured “What You Can Delete” map used by the dashboard, onboarding wizard, migration center, and landing page.

### Subfeatures

- Detect installed plugins WPTransformed can fully or partially replace.
- Mark each plugin with replacement confidence:
  - Exact replacement
  - Partial replacement
  - Pro replacement
  - Companion integration
  - Keep installed
- Show which WPTransformed module covers each feature.
- Show whether migration is available.
- Warn when replacement is intentionally not recommended.

### Initial Replacement Targets

- WP Mail SMTP → Email Delivery.
- Yoast Duplicate Post → Content Duplication.
- Redirection → Redirect Manager + 404 Monitor.
- WPS Hide Login → Change Login URL.
- Limit Login Attempts Reloaded → Login Protection.
- Disable Comments → Disable Comments.
- Enable Media Replace → Media Replace.
- SVG Support / Safe SVG → SVG Upload.
- FileBird / Real Media Library basic use cases → Media Folders.
- LoginPress basic use cases → Login Designer.
- White Label CMS → White Label Pro.
- Admin Menu Editor → Admin Menu Editor Pro.
- User Role Editor basic/pro use cases → Users & Role Manager.
- WPCode header/footer use cases → Header / Body / Footer Code Manager.
- Code Snippets / WPCode snippets → Code Snippets Manager Pro.
- WP-Optimize database cleanup use cases → Database Optimizer.
- Better Search Replace → Search & Replace Pro / Companion.
- Custom Post Type UI → Custom Post Types Pro / Companion.

### Do Not Claim Direct Replacement For

- Cookie consent/compliance plugins.
- Full caching plugins.
- Malware scanners/WAFs.
- Backup plugins.
- Full SEO suites.
- Full form plugins, except the companion forms plugin bridge.
- Query Monitor.
- MainWP/ManageWP.

## 18.2 Safety Check Modal — Core

A standard confirmation flow for risky actions.

### Subfeatures

- Risk-level explanation before applying.
- “Backup recommended” warning for destructive changes.
- Dry-run requirement where available.
- Affected item count before execution.
- Typed confirmation for dangerous actions.
- Recovery Center link before applying high-risk changes.
- Operation log after completion.

### Must Use Safety Check For

- Search & Replace.
- Bulk Content Editor.
- Database cleanup of orphaned data.
- Change Login URL.
- Disable REST API.
- Disable Updates.
- Code Snippets.
- Role/capability edits.
- Media Replace.
- Permanent deletion actions.

## 18.3 Data Retention & Uninstall Policy — Core

Give users control over WPTransformed data.

### Retention Controls

- Audit logs:
  - 30 days
  - 90 days
  - 180 days
  - 365 days
  - Forever
- Login logs:
  - 30 days
  - 90 days
  - 180 days
- Notifications:
  - 30 days default
- 404 logs:
  - 30 days
  - 90 days
  - 180 days
- Email logs:
  - 7 days
  - 30 days
  - 90 days
  - 180 days
- Report history:
  - 90 days
  - 365 days
  - Forever

### Uninstall Options

- Keep all data.
- Delete settings only.
- Delete temporary/log data only.
- Delete all WPTransformed tables and settings.
- Explicit confirmation required before deleting data.

## 18.4 WPTransformed Permission Model — Core + Pro

WPTransformed needs its own capabilities so agencies can restrict access to sensitive tools.

### Core Capabilities

- `manage_wpt`
- `manage_wpt_modules`
- `view_wpt_dashboard`
- `view_wpt_logs`
- `manage_wpt_client_safe`
- `export_wpt_settings`
- `import_wpt_settings`

### Pro Capabilities

- `manage_wpt_security`
- `manage_wpt_code`
- `manage_wpt_reports`
- `manage_wpt_white_label`
- `manage_wpt_integrations`
- `manage_wpt_schema`
- `manage_wpt_email`
- `manage_wpt_roles`
- `run_wpt_dangerous_tools`

### Behavior

- Administrators get all Core capabilities by default.
- Pro capabilities can be assigned by role.
- Dangerous tools require explicit capability.
- Client/editor roles should never receive dangerous capabilities by default.

## 18.5 Multisite Strategy — Pro / Deferred Advanced

WPTransformed should be multisite-aware, but full MainWP-style centralized site management is deferred.

### Pro Multisite Subfeatures

- Network activation compatibility.
- Network-level default settings.
- Per-site overrides.
- Network-wide module availability controls.
- Network export/import of blueprints.
- Network-level white label defaults.
- Site-level opt-out where allowed.

### Deferred

- Centralized external dashboard for many separate WordPress installs.
- Remote update management across independent sites.
- MainWP / ManageWP replacement.

---

# 19. Small WordPress Utilities & Admin Extras

These are smaller features that still fit the “WordPress should already do this” principle.

## 19.1 Users & Role Manager — Core + Pro

User and role utilities for safer editor/client control.

### Core Subfeatures

- Multiple roles per user.
- View admin as role.
- Basic role templates:
  - Content Editor
  - Client Editor
  - SEO Manager
  - Support User
- Show last login in Users table.
- Show registration date in Users table.

### Pro Subfeatures

- Full role editor.
- Capability editor.
- Clone roles.
- Delete custom roles.
- Role import/export.
- Role change audit log.
- Per-role dashboard/menu blueprint.
- Temporary User Access integration.

## 19.2 Editor & Component Controls — Core Advanced

Control WordPress features that many sites do not use.

### Core Subfeatures

- Disable Gutenberg by post type.
- Disable Gutenberg by role.
- Disable REST API for unauthenticated users.
- Disable selected REST fields for unauthenticated users.
- Disable plugin/theme file editor.
- Disable updates selectively:
  - Core auto-updates
  - Plugin auto-updates
  - Theme auto-updates
- Disable feeds.
- Disable comments.
- Disable XML-RPC.

### Guardrails

- Never enabled automatically.
- Must show compatibility warnings.
- Must avoid breaking authenticated REST usage needed by Gutenberg, WooCommerce, builders, forms, or APIs.

## 19.3 Login Routing & Menu Utilities — Core + Pro

Small login/logout features users often install separate plugins for.

### Core Subfeatures

- Login ID type:
  - Username only
  - Email only
  - Username or email
- Login/logout nav menu item.
- Basic redirect after login by role.
- Basic redirect after logout by role.

### Pro Subfeatures

- Conditional login redirects.
- First-login redirects.
- Device-based redirects.
- Specific-page redirects.
- WooCommerce-aware redirects if WooCommerce is active.

## 19.4 Navigation Menu Utilities — Core

Simple menu tools WordPress should include.

### Subfeatures

- Nav menu item “open in new tab” checkbox.
- Duplicate navigation menu.
- Preserve nested menu structure when duplicating.
- Copy menu locations optionally.

## 19.5 Admin Columns Pro Extension — Pro / Deferred

Core includes useful built-in columns. A more advanced custom column builder can come later.

### Pro / Deferred Subfeatures

- Custom admin column builder.
- Sortable custom columns.
- Filterable custom columns.
- ACF/meta columns.
- Inline editing.
- Bulk column actions.
- Export visible columns to CSV.

## 19.6 Media Infinite Scroll — Core

Improve Media Library browsing.

### Subfeatures

- Replace “Load More” in media grid with infinite scroll.
- Load in batches.
- Respect search/filter state.
- Avoid loading on attachment modal screens where it causes conflicts.

## 19.7 Duplicate Widget — Core

Duplicate widgets without manually rebuilding settings.

### Subfeatures

- Duplicate legacy widgets.
- Duplicate block widgets where possible.
- Preserve widget settings.
- Assign duplicate to same sidebar.
- Generate unique widget IDs.

---



# 20. Architecture & Implementation Requirements

These are non-negotiable build rules for Codex / Claude / developers.

## 20.1 Zero-Load Module Architecture

Inactive modules must not register hooks, enqueue assets, query custom tables, or load heavy classes.

### Requirements

- Module registry can load metadata without initializing module behavior.
- Module code initializes only when the module is active.
- Admin UI assets load only on WPTransformed screens unless a module explicitly needs global admin behavior.
- Frontend assets load only when required.
- Global admin features must be lightweight and conditional.

## 20.2 Module Sandbox and Crash Recovery

One broken module should not take down the site.

### Requirements

- Wrap module initialization in recoverable error handling where possible.
- Track modules currently loading.
- Quarantine modules that repeatedly fail during initialization.
- Recovery Center can disable the last enabled module.
- Safe Mode can disable all WPTransformed modules for a recovery request.
- Recovery email includes a safe-mode link when possible.

## 20.3 Settings Storage

WPTransformed should avoid dumping complex module settings into autoloaded `wp_options` rows.

### Requirements

- Use a custom settings table for module settings.
- Keep autoloaded options minimal.
- Settings must be exportable/importable as JSON.
- Version settings with migration support.
- Validate settings against each module schema before saving.

## 20.4 Custom Database Table Plan

Initial table plan:

- `wpt_settings` — module settings and global preferences.
- `wpt_audit_log` — audit events.
- `wpt_login_log` — login attempts and lockouts.
- `wpt_notifications` — notification center events.
- `wpt_404_log` — 404 monitor records.
- `wpt_email_log` — Pro email logs.
- `wpt_redirects` — redirects if not stored as CPT/options.
- `wpt_operation_log` — risky operation history.
- `wpt_blueprints` — saved presets/agency blueprints.
- `wpt_schema_templates` — Pro schema templates.
- `wpt_llms_snapshots` — AI visibility generation history if needed.

### Requirements

- All custom tables must use the current site prefix.
- Multisite must isolate per-site data unless explicitly network-level.
- Tables need dbDelta-compatible migrations.
- Add indexes for common filters.
- Retention cleanup must be scheduled for log tables.

## 20.5 REST API Requirements

The React admin UI should use WordPress REST endpoints with strict permissions.

### Requirements

- Every endpoint checks a WPTransformed capability.
- Nonce validation for admin requests.
- Sanitize all incoming data.
- Validate against settings schema.
- No public write endpoints except companion/plugin-specific public endpoints if explicitly needed.
- Dangerous operations require Safety Check token or confirmation payload.

## 20.6 Security Requirements

### Requirements

- Escape all output.
- Sanitize all input.
- Verify nonces for all admin actions.
- Use capabilities, not role names, for authorization.
- Never log secrets, SMTP passwords, API keys, TOTP secrets, backup codes, or OAuth tokens.
- Mask secrets in UI and exports.
- Redact secrets in support packages.
- Encrypt sensitive credentials where feasible using WordPress salts/keys.

## 20.7 Admin UI Requirements

### Requirements

- Responsive admin UI.
- Keyboard navigable controls.
- WCAG-minded contrast and focus states.
- Reduced-motion support for animations.
- No fake metrics.
- No fake changelog.
- No fake testimonials.
- No exact performance improvement claims unless benchmarked.
- Use consistent Core/Pro/Advanced badges.
- Use risk labels on all advanced modules.

## 20.8 Internationalization

### Requirements

- All PHP strings translatable.
- All JS UI strings translatable.
- Text domain should be consistent.
- Avoid hardcoded English strings in module logic.

---

# 21. Packaging, Pricing & Licensing Notes

This file is primarily a feature spec, but packaging affects build decisions.

## 21.1 Suggested Packaging

### Core / Free

Generous free plugin with real utility value. Core should not feel crippled.

### Pro

Advanced agency, reporting, white-label, schema, integrations, role controls, logs, snippets, and high-risk power tools.

### Agency

Higher site-count plan for agencies. Avoid unlimited unless support economics are proven.

## 21.2 Pricing Notes

Current working assumptions:

- Free core forever.
- Pro should likely start around $79/year.
- Agency should be higher site-count, not necessarily unlimited.
- Lifetime deals should be limited/capped if offered.

## 21.3 Licensing Guardrails

- Core should be WordPress.org-safe.
- Pro code should degrade gracefully if license is inactive.
- Never break a site because a license expires.
- Hide client-facing upsells in Client-Safe/White Label contexts.

---

# 22. QA & Verification Requirements

Every module should eventually have a module spec with verification steps.

## 22.1 Per-Module Verification Checklist

Each module spec should include:

- Activation test.
- Deactivation test.
- Settings save test.
- Capability/permission test.
- Conflict test where relevant.
- Multisite note where relevant.
- Safe Mode recovery note where relevant.
- Data cleanup/uninstall note where relevant.
- Frontend impact check if frontend behavior exists.
- Builder compatibility check where relevant.

## 22.2 Risk-Based QA

### Safe modules

- Basic functional tests.
- UI tests.

### Moderate modules

- Functional tests.
- Conflict tests.
- Rollback/deactivation tests.

### Advanced/Dangerous modules

- Dry-run tests.
- Confirmation tests.
- Recovery tests.
- Permission tests.
- Operation log tests.

## 22.3 Launch Readiness Gate

A module should not be considered launch-ready until:

- It has a clear Core/Pro tier.
- It has a risk label.
- It has default-enabled behavior defined.
- It has settings schema defined.
- It has conflicts/dependencies documented.
- It has verification steps.
- It has uninstall/data behavior documented.

---

# 23. Open Decisions

These items still need final product decisions before build or launch.

## 23.1 Final Free/Pro Pricing

Decide site counts and whether Agency is capped or unlimited.

## 23.2 Companion Plugin Packaging

Decide whether Forms, CPTs, and Search & Replace are bundled installers, separate plugins, or Pro modules that reuse existing code.

## 23.3 Mailer OAuth Scope

Decide exactly which OAuth mailers ship in Pro v1 and whether Gmail OAuth requires a managed app/proxy or user-created Google app credentials.

## 23.4 Schema Scope

Decide which advanced schema types ship in Pro v1 versus later.

## 23.5 Search Console / GA4 API Setup

Decide whether users bring their own Google app credentials or WPTransformed provides a managed OAuth app.

## 23.6 WordPress.org Free Version Scope

Confirm which Core modules are acceptable for WordPress.org guidelines and which should be held for Pro or companion distribution.

---

# Companion / Integration Strategy

## Forms

Handled by the separate forms plugin suite.

WPTransformed should include a Forms Bridge, not a full form builder.

## Custom Post Types

The user already has good CPT plugin work. Integrate it as Pro/Companion.

## Search & Replace

The user already has good Search & Replace plugin work. Integrate it as Pro Advanced/Companion.

---

# Deferred Features

These are useful ideas but should not be part of the near-term build.

- Full workflow automation.
- Task manager.
- Content approval chains.
- Full content calendar.
- Native analytics tracking.
- Uptime monitor.
- Plugin performance profiler.
- Query Monitor Lite.
- Core Web Vitals monitoring.
- Full asset/script manager.
- Broken link checker.
- WooCommerce enhancements.
- Builder compatibility layer.
- Page builder shortcode cleanup.
- Deep builder asset optimization.
- AI auto-tagging.
- Social login.
- Full caching.
- Malware scanner / WAF.
- Cookie consent compliance system.
- File manager.
- Full accessibility scanner.
- Privacy policy generator.
- MainWP-style centralized multi-site SaaS.

---

# Cut / Avoid

## Do Not Build

- Full cookie consent/compliance platform.
- Malware scanner.
- Web application firewall.
- Full page caching.
- Backup/migration system.
- Full SEO suite.
- Full analytics tracker.
- Full form builder inside WPTransformed.
- Full project management/task system.
- Fake performance claims.
- Fake testimonials.
- Fake changelog/release history.

---

# Landing Page v5 Alignment Notes

The landing page should emphasize:

1. WordPress, finished.
2. Replace the everyday utility plugin stack.
3. Client-Safe Mode.
4. Command Palette.
5. Notification Center.
6. Email Delivery.
7. Search & AI Visibility.
8. Basic security and login protection.
9. Agency Blueprints and Reports.
10. Safe Mode / Recovery Center.

The landing page should avoid:

- Claiming it replaces everything.
- Claiming zero conflicts.
- Claiming exact speed gains unless benchmarked.
- Mentioning fake releases or fake testimonials.
- Presenting deferred modules as already built.
- Listing CookieYes, MainWP, Query Monitor, or full caching plugins as directly replaced.

---

# Suggested Core Launch Scope

Core should feel generous and genuinely useful.

## Core Launch Modules

- Setup Wizard
- Presets import/export
- Main Dashboard
- Module Library
- Client-Safe Mode basics
- Admin Bar Manager
- Command Palette
- Dashboard Manager
- Notification Center basics
- List Table Enhancements
- Keyboard Shortcuts
- Admin Bookmarks
- Smart Menu Organizer basics
- Content Duplication
- Public Preview basics
- Disable Comments
- External Links New Tab
- External Permalinks
- Auto-Publish Missed Schedule
- Post Type Switcher
- Preserve Taxonomy Hierarchy
- Media Folders basics
- Media Replace
- SVG Upload
- AVIF Upload
- Image Sizes Panel
- Local User Avatars
- Search Appearance
- Open Graph Basics
- Basic Schema
- llms.txt Generator
- Login Protection
- Change Login URL
- Basic 2FA
- Session Manager basics
- Audit Log basics
- Basic Hardening
- Password Protection
- Email Delivery Core
- Performance Hygiene
- Heartbeat Control
- Revision Control
- Image Upload Control basics
- Auto-Clear Caches
- Disable Thin Archives
- Database Optimizer basics
- Login Designer basics
- Admin Theme
- Header/Body/Footer Code
- Custom CSS
- Redirect Manager basics
- 404 Monitor basics
- Maintenance Mode basics
- Robots.txt Manager
- Ads.txt Manager
- Integrations Hub basics
- Migration Center basics
- Safety Check Modal
- Data Retention & Uninstall controls
- WPTransformed Permission Model
- Multiple User Roles
- View Admin as Role
- Editor & Component Controls
- Disable Gutenberg controls
- Disable REST API guest controls
- Disable Updates controls
- Login ID Type
- Login/Logout Menu Item
- Redirect After Login/Logout basics
- Navigation Menu New Tab
- Duplicate Navigation Menu
- Media Infinite Scroll
- Duplicate Widget
- Permalink / Slug Manager basics
- Forms Bridge
- System Summary
- Conflict Detector
- Recovery Center
- Support Package basics

---

# Suggested Pro Launch Scope

Pro should focus on agencies, teams, reporting, advanced controls, and higher-risk power tools.

## Pro Launch Modules

- Agency Blueprints
- Client Dashboard Builder
- Page Builder Restrictions Pro
- Plugin/Theme Visibility
- Notification Center Pro
- Smart Menu Organizer Pro
- Admin Menu Editor
- Public Preview Pro
- Terms Order
- Bulk Content Editor
- Page Hierarchy Organizer
- Media Folders Pro
- Media Visibility Control
- Schema Builder Pro
- AI Visibility Pro
- Search Console Insights
- GA4 Content Insights
- Change Login URL Pro
- 2FA Pro
- Session Manager Pro
- Audit Log Pro
- CAPTCHA Protection
- Password Policy
- Email Log
- Email Delivery Pro
- Database Optimizer Pro
- Login Designer Pro
- White Label
- Code Snippets Manager
- Redirect Manager Pro
- 404 Monitor Pro
- Maintenance Mode Pro
- Cron Manager
- Search & Replace Pro Advanced / Companion
- Custom Post Types Pro / Companion
- Integrations Hub Pro
- Agency Client Reports
- Client Handoff Checklist
- Temporary User Access
- Error Log Viewer
- Support Package Pro
- Migration Center Pro
- Role Editor / Capability Editor
- Admin Columns Pro Extension
- Multisite network defaults
- Safety reporting for advanced operations
- Data retention advanced controls
- Permalink / Slug Manager Pro
- WP-CLI Commands

---

# Build Strategy

## Phase 1 — Foundation

- Module loader.
- Settings system.
- Module registry.
- Core dashboard shell.
- Module grid.
- Safe Mode / Recovery Center.
- Conflict detector.
- Basic design system.
- Import/export settings.
- User capability model.

## Phase 2 — Onboarding and Admin Experience

- Setup Wizard.
- Presets.
- Admin Bar Manager.
- Dashboard Manager.
- Notification Center basics.
- Command Palette.
- List Table Enhancements.
- Smart Menu Organizer basics.
- Client-Safe Mode basics.

## Phase 3 — Everyday Utility Core

- Content Duplication.
- Public Preview basics.
- Permalink / Slug Manager basics.
- Disable Comments.
- Media Replace.
- SVG Upload.
- Media Folders basics.
- Redirect Manager basics.
- 404 Monitor basics.
- Header/Footer Code.
- Custom CSS.
- Robots.txt / Ads.txt.

## Phase 4 — Security, Email, and Performance Hygiene

- Login Protection.
- Change Login URL.
- Basic 2FA.
- Session Manager basics.
- Audit Log basics.
- Disable XML-RPC.
- Email Delivery Core.
- Heartbeat Control.
- Revision Control.
- Database Optimizer basics.
- Auto-Clear Caches.
- Object Cache Status.
- Login Notifications basics.
- Environment Detector.

## Phase 5 — Search & AI Visibility

- Search Appearance.
- Open Graph Basics.
- Basic Schema.
- llms.txt Generator.
- Metadata status columns.
- SEO plugin conflict detection.

## Phase 6 — Pro App Pages

- Admin Menu Editor.
- White Label.
- Login Designer Pro.
- Schema Builder Pro.
- Email Log / Email Pro.
- Reports.
- Agency Blueprints.
- Client Dashboard Builder.
- Search & Replace integration.
- CPT integration.

---

# Codex Build Notes

## Module Registry Fields

Each module should include:

```json
{
  "id": "module-id",
  "title": "Human Module Name",
  "category": "admin-experience",
  "tier": "core",
  "risk": "safe",
  "status": "planned",
  "description": "Plain-English benefit statement.",
  "dependencies": [],
  "conflicts": [],
  "settings_schema": {},
  "default_enabled": false,
  "onboarding_profiles": []
}
```

## Risk Levels

- safe
- moderate
- advanced
- dangerous

## Default Enabled Rules

Never enable these automatically:

- Change Login URL
- Disable REST API
- Disable Updates
- Search & Replace
- Code Snippets
- Password Protection
- Any dangerous database operation
- Any Pro Advanced operation

Safe defaults can include:

- Command Palette
- Admin Bar cleanup
- Hide admin notices into Notification Center
- Disable Emojis
- Disable Embeds
- Heartbeat Control at safe interval
- Content Duplication
- Last Login Column
- Basic List Table Enhancements
- Environment Indicator
- Dashboard Manager basics

---

# End of Canonical v5.2 Scope
