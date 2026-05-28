# Media Library Pro

## 1. Metadata

- ID: `media-library-pro`
- Category: `content-management`
- Tier: Free
- Risk: Medium (file operations)
- Status: Post-Phase 1
- Replaces: FileBird, Enable Media Replace, Safe SVG, Media Library Organizer, WP User Avatar
- Related modules: `image-upload-control`
- Default enabled: false
- Onboarding profiles: Agency, Content-Heavy, Designer

## 2. One-Liner

Organizes the media library with virtual folders, file replacement, SVG/AVIF support, local avatars, image size management, and per-attachment visibility control.

## 3. Scope

Media Library Pro consolidates seven sub-features:

1. **Media Folders** -- virtual taxonomy-based folder tree for organizing attachments without moving files on disk.
2. **Media Replace** -- swap a file in-place, preserving the attachment ID and all references across content.
3. **SVG Upload** -- allow sanitized SVG uploads for administrators with inline preview in the media library.
4. **AVIF Upload** -- register AVIF mime type and generate thumbnails when server imaging libraries support it.
5. **Local User Avatars** -- upload a custom avatar per user from the profile page, replacing Gravatar lookups.
6. **Image Sizes Panel** -- list all registered image sizes, disable individual sizes from generation, regenerate thumbnails.
7. **Media Visibility** -- per-attachment public/private toggle to exclude attachments from frontend queries.

## 4. Things NOT To Do

- Do not move physical files on disk for folder assignment (taxonomy only).
- Do not delete original files during media replace (keep backup until confirmed).
- Do not allow SVG uploads for non-administrator roles by default.
- Do not render unsanitized SVGs in the admin (always sanitize before display).
- Do not block AVIF uploads when thumbnail generation fails (serve the original).
- Do not remove Gravatar as an option; local avatar overrides it when set.
- Do not delete registered image sizes from other plugins; only skip generation.
- Do not make private attachments return 403 on direct URL (visibility controls query inclusion only, not access to the file URL).
- Do not break the Gutenberg media inserter or block editor media flows.

## 5. What the User Sees

**Media Library enhancements:**

1. **Folder sidebar** -- collapsible tree panel on the left of the media library grid/list view. Drag-drop attachments onto folders. "All Media" and "Uncategorized" virtual roots. Folder count badges.
2. **Replace button** -- new "Replace Media" button on the attachment detail screen. Upload replacement file dialog with preview of old vs new. Warning banner if file type changes.
3. **SVG thumbnails** -- SVG files render as inline previews in the media grid instead of generic file icons. Full SVG preview on attachment detail page.
4. **AVIF badge** -- AVIF files display with a small format badge in the grid. Thumbnail shown if generated; original shown otherwise.
5. **Avatar uploader** -- on user profile page, a "Local Avatar" section with upload button, crop tool, and "Remove" link. Replaces Gravatar image when set.
6. **Image Sizes page** -- accessible under Media submenu. Table listing size name, dimensions, crop mode, source (theme/plugin/core). Toggle to disable generation per size. "Regenerate" button per size and bulk "Regenerate All" button with progress bar.
7. **Visibility toggle** -- on attachment detail screen, a "Visibility" metabox with Public/Private radio buttons. Private attachments show a lock icon overlay in the media grid.

## 6. Settings Schema

```json
{
  "media_library_pro": {
    "folders": {
      "enabled": true,
      "show_in_upload": true,
      "default_folder": ""
    },
    "replace": {
      "enabled": true,
      "keep_backup": true,
      "allow_type_change": false
    },
    "svg": {
      "enabled": false,
      "allowed_roles": ["administrator"],
      "sanitize_on_upload": true
    },
    "avif": {
      "enabled": true,
      "generate_thumbnails": true
    },
    "avatars": {
      "enabled": true,
      "max_upload_size": 2097152,
      "default_avatar_id": 0
    },
    "image_sizes": {
      "enabled": true,
      "disabled_sizes": []
    },
    "visibility": {
      "enabled": false,
      "default_visibility": "public"
    }
  }
}
```

Field notes:
- `default_folder`: string, term slug of the folder to auto-assign on upload. Empty string means uncategorized.
- `allowed_roles`: array of role slugs permitted to upload SVGs. Default `["administrator"]`.
- `max_upload_size`: integer, bytes. Default 2097152 (2 MB) for avatar uploads.
- `disabled_sizes`: array of registered image size names to skip during thumbnail generation (e.g., `["medium_large", "1536x1536"]`).
- `default_visibility`: enum `public | private`.
- `allow_type_change`: boolean. When false, replacement file must match the original MIME type.

## 7. Hooks & Implementation

Primary class: `WPT_Media_Library_Pro`

Sub-components:
- `WPT_Media_Folders` -- registers `wpt_media_folder` taxonomy on `attachment` post type. Hooks: `restrict_manage_posts`, `ajax_query_attachments_args`, `add_attachment`.
- `WPT_Media_Replace` -- hooks `attachment_submitbox_misc_actions`, custom AJAX handler `wpt_replace_media`.
- `WPT_SVG_Support` -- hooks `upload_mimes`, `wp_check_filetype_and_ext`, `wp_prepare_attachment_for_js`, `wp_get_attachment_image_src`.
- `WPT_AVIF_Support` -- hooks `upload_mimes`, `wp_check_filetype_and_ext`, `wp_generate_attachment_metadata`.
- `WPT_Local_Avatars` -- hooks `get_avatar`, `get_avatar_url`, `user_profile_update_errors`, custom profile fields.
- `WPT_Image_Sizes` -- hooks `intermediate_image_sizes_advanced` to skip disabled sizes. Admin page registered via `admin_menu`.
- `WPT_Media_Visibility` -- hooks `pre_get_posts` to exclude private attachments on frontend. Adds metabox via `add_meta_boxes`.

Filters:
```php
apply_filters( 'wpt_media_folder_taxonomy_args', $args );
apply_filters( 'wpt_svg_sanitizer_allowed_tags', $tags );
apply_filters( 'wpt_svg_sanitizer_allowed_attrs', $attrs );
apply_filters( 'wpt_media_replace_backup', true, $attachment_id );
apply_filters( 'wpt_avatar_max_size', 2097152 );
apply_filters( 'wpt_disabled_image_sizes', $sizes );
apply_filters( 'wpt_media_visibility_query', $is_excluded, $query );
```

Actions:
```php
do_action( 'wpt_media_replaced', $attachment_id, $old_file, $new_file );
do_action( 'wpt_svg_sanitized', $attachment_id, $removed_elements );
do_action( 'wpt_avatar_updated', $user_id, $attachment_id );
do_action( 'wpt_image_sizes_regenerated', $size_name, $count );
```

## 8. REST API

```text
GET    /wp-json/wpt/v1/media/folders                      -- list folder tree
POST   /wp-json/wpt/v1/media/folders                      -- create folder
PUT    /wp-json/wpt/v1/media/folders/{id}                  -- rename/move folder
DELETE /wp-json/wpt/v1/media/folders/{id}                  -- delete folder (unassigns attachments)
POST   /wp-json/wpt/v1/media/folders/{id}/assign           -- assign attachment(s) to folder
POST   /wp-json/wpt/v1/media/{id}/replace                  -- replace media file
POST   /wp-json/wpt/v1/media/{id}/visibility               -- set visibility (public/private)
GET    /wp-json/wpt/v1/media/image-sizes                   -- list registered sizes with disable status
POST   /wp-json/wpt/v1/media/image-sizes/regenerate        -- trigger bulk regeneration
GET    /wp-json/wpt/v1/media/image-sizes/regenerate/status -- poll regeneration progress
```

Permissions: all endpoints require `upload_files`. Folder management and image size regeneration require `manage_wpt`. SVG upload requires membership in `allowed_roles`.

## 9. Database Usage

**Taxonomy:** `wpt_media_folder` registered as a hierarchical taxonomy on the `attachment` post type. Uses core `wp_terms`, `wp_term_taxonomy`, and `wp_term_relationships` tables. No custom tables for folders.

**Media Replace backup:** temporary copy stored in `wp-content/uploads/wpt-replace-backups/` with a 24-hour expiry. A daily WP-Cron event (`wpt_cleanup_replace_backups`) deletes expired backups.

**Local Avatars:** avatar attachment ID stored as user meta key `wpt_local_avatar_id` (integer, attachment post ID).

**Media Visibility:** stored as post meta key `_wpt_visibility` on the attachment (values: `public`, `private`). Default is no meta row, treated as `public`.

**Image Size Regeneration:** progress tracked via transient `wpt_regen_progress_{batch_id}` containing `{ total, completed, failed, size_name }`.

**Settings:** stored via Settings Storage module (option `wpt_media_library_pro`).

## 10. Exact Behavior

### Media Folders

1. On module enable, register `wpt_media_folder` taxonomy with `show_in_rest: true`, `hierarchical: true`, `show_ui: false` (custom UI in media library).
2. Inject folder sidebar into the media library via `wp_enqueue_media` and a JavaScript panel that queries the taxonomy via REST.
3. When user drags attachment(s) onto a folder, assign the taxonomy term via AJAX. An attachment belongs to exactly one folder (re-assignment removes the previous term).
4. "Uncategorized" is a virtual root representing attachments with no `wpt_media_folder` term.
5. If `show_in_upload` is true, the upload dialog shows a folder dropdown to pre-assign on upload.
6. If `default_folder` is set, new uploads are auto-assigned to that folder unless overridden.

### Media Replace

1. On the attachment edit screen, show "Replace Media" button.
2. User selects new file. If `allow_type_change` is false, validate MIME type matches. If types differ, show confirmation warning.
3. If `keep_backup` is true, copy original file to `wpt-replace-backups/` with a timestamped name.
4. Delete existing thumbnail files for the attachment.
5. Move uploaded file to the original file path (same directory, same filename by default).
6. Update `_wp_attached_file` and `_wp_attachment_metadata` post meta.
7. Regenerate all thumbnail sizes for the new file.
8. Fire `wpt_media_replaced` action.
9. If the new file has different dimensions, `srcset` attributes in existing block content will regenerate from updated metadata on next render.

### SVG Upload

1. When enabled, add `svg` and `svgz` to allowed upload MIME types for roles in `allowed_roles`.
2. On upload, read the SVG content and pass through the sanitizer.
3. **Sanitizer whitelist approach:** allow only known-safe SVG elements (`svg`, `g`, `path`, `rect`, `circle`, `ellipse`, `line`, `polyline`, `polygon`, `text`, `tspan`, `defs`, `use`, `clipPath`, `mask`, `pattern`, `linearGradient`, `radialGradient`, `stop`, `image`, `title`, `desc`).
4. Strip all elements not in the whitelist. Strip all event handler attributes (`on*`). Strip `<script>` elements. Strip `javascript:` in attribute values. Strip `data:` URIs with non-image MIME types. Strip external entity references (`<!ENTITY`, `<!DOCTYPE` with external DTD). Strip `<foreignObject>`.
5. Fire `wpt_svg_sanitized` with a list of removed elements for logging.
6. Store the sanitized SVG. If sanitization removed elements that structurally broke the SVG, reject the upload with an error.
7. In media library grid, render SVG as an inline `<img>` tag with the sanitized file URL. On detail page, show full inline SVG preview.

### AVIF Upload

1. When enabled, add `avif` to allowed upload MIME types.
2. On upload, attempt thumbnail generation via `wp_generate_attachment_metadata`.
3. Check `wp_image_editor_supports(['mime_type' => 'image/avif'])`. If GD or Imagick supports AVIF, thumbnails generate normally.
4. If thumbnail generation fails, log a notice and store the original as the only available size. The attachment remains usable.

### Local User Avatars

1. Add a "Local Avatar" section to the user profile edit page (`show_user_profile`, `edit_user_profile`).
2. Upload button opens the media library filtered to images. Selected image is cropped to square (256x256 default) via `wp_crop_image` or client-side cropper.
3. Store the attachment ID in `wpt_local_avatar_id` user meta.
4. Hook `get_avatar_url` (priority 10): if user has `wpt_local_avatar_id`, return the attachment URL. Otherwise fall through to Gravatar.
5. On avatar removal, delete the user meta key. The attachment itself is not deleted (it remains in the media library).
6. If `default_avatar_id` is set in settings, use that attachment as the fallback for users without Gravatar or local avatar.

### Image Sizes Panel

1. Register an admin page under the Media submenu: "Image Sizes".
2. Query all registered sizes via `wp_get_registered_image_subsizes()` (WP 5.3+).
3. Display table: Name, Width, Height, Crop (yes/no), Source (core/theme name/plugin name via backtrace or registration hook), Disabled toggle.
4. Toggling "Disabled" adds the size name to `disabled_sizes` in settings.
5. Hook `intermediate_image_sizes_advanced` to remove disabled sizes from the generation list.
6. "Regenerate" per size: spawn a background process (via WP-Cron or AJAX batch) that iterates all attachments and regenerates just that size. Track progress in transient.
7. "Regenerate All" regenerates all enabled sizes for all attachments. Process in batches of 50 attachments per AJAX request. Show progress bar in admin.

### Media Visibility

1. Add a "Visibility" metabox to the attachment edit screen with Public/Private radio buttons.
2. On save, store `_wpt_visibility` post meta.
3. On frontend `pre_get_posts` for attachment queries, add `meta_query` to exclude posts where `_wpt_visibility = private`.
4. In admin media library, show a lock icon overlay on private attachments.
5. Direct URL access to private media files is NOT blocked (this module controls query visibility, not server-level access). Document this limitation.

## 11. Edge Cases

### WooCommerce product gallery

WooCommerce product galleries use attachment IDs. Media Folders does not affect gallery functionality. Folder assignment is purely organizational. Media Replace updates the attachment in-place, so gallery references continue to work.

### Gutenberg media inserter

The Gutenberg media inserter queries attachments via REST API. The folder sidebar filters must pass the `wpt_media_folder` taxonomy term as a query parameter on the REST request to filter correctly in the block editor context.

### REST API media upload

Uploads via REST API respect `default_folder` assignment. The `wpt_media_folder` term can also be passed as a parameter on the REST upload request.

### Bulk upload folder assignment

When using the bulk uploader with `show_in_upload` enabled, all uploaded files in the batch receive the selected folder assignment.

### SVG bomb (billion laughs attack)

The sanitizer imposes a maximum file size of 1 MB for SVGs and a maximum parsed DOM node count of 50,000 nodes. Files exceeding either limit are rejected with an error before sanitization.

### SVG with embedded raster images

`<image>` elements with `href` pointing to external URLs are stripped (external references). Data URIs with `image/*` MIME types are allowed; all other data URI types are stripped.

### SVG as featured image

Sanitized SVGs can be set as featured images. The `wp_get_attachment_image_src` filter returns the SVG URL directly. Width/height are read from the SVG `viewBox` attribute.

### Replacing a PDF attachment

PDF replacement follows the same flow. Thumbnail regeneration for PDFs uses the Imagick PDF-to-image conversion if available. If not, the old thumbnail is removed and no new one is generated.

### CDN cache invalidation on replace

Media Replace does not handle CDN cache purging. A `wpt_media_replaced` action is fired so CDN integration plugins can hook into it. Document this for users with CDN setups.

### Replacing image used in Gutenberg blocks

Gutenberg blocks reference images by attachment ID. Because replacement preserves the ID, existing blocks continue to work. `srcset` attributes regenerate from the updated `_wp_attachment_metadata` on next page render.

### Multisite avatars

Local avatar attachment IDs are per-site user meta. On multisite, a user switching sites may not see their avatar if the attachment exists on a different site. Store avatar on the main site or document the per-site limitation.

### BuddyPress profile photos

BuddyPress uses its own avatar system. If BuddyPress is active, the Local Avatars feature detects it and shows a notice recommending users manage avatars through BuddyPress instead.

### WooCommerce product image sizes

WooCommerce registers its own image sizes (`woocommerce_thumbnail`, `woocommerce_single`, `woocommerce_gallery_thumbnail`). These appear in the Image Sizes Panel. Disabling them shows a warning that product images may not display correctly.

### Regeneration on large media libraries

Regeneration processes in batches of 50 via AJAX. A progress bar shows completion percentage. If the browser tab is closed, the regeneration stops (no background process). User must restart from where it left off; the transient tracks the last processed attachment ID.

### Private media direct URL access

Media Visibility only controls inclusion in WordPress queries. If a user knows the direct file URL, they can still access private media. This is by design; server-level file protection requires `.htaccess` rules that are outside this module's scope. Document clearly.

## 12. Conflicts & Dependencies

**Depends on:**
- `module-registry` (registration)
- `settings-storage` (persist settings)
- `permission-model` (capability checks)

**Conflicts with:**
- FileBird / Real Media Library / MediaPress -- duplicate media folder taxonomies. Severity: Warning. Recommendation: disable the third-party folder plugin. Migration of folder assignments is a deferred feature.
- Enable Media Replace -- duplicate replace functionality. Severity: Warning. Recommendation: disable Enable Media Replace.
- Safe SVG / SVG Support -- duplicate SVG handling. Severity: Warning. Recommendation: disable the standalone SVG plugin.
- ShortPixel / Imagify (image optimization) -- no conflict. These plugins hook into `wp_generate_attachment_metadata` after thumbnail generation. Compatible.

**Complementary:**
- `image-upload-control` -- controls max upload dimensions and compression. Runs before Media Library Pro processes the upload. No overlap.

## 13. Security & Permissions

- SVG upload restricted to roles in `allowed_roles` (default: administrator only). Enforced server-side on upload, not just in UI.
- SVG sanitizer uses a strict element and attribute whitelist. Any element or attribute not in the whitelist is removed.
- SVG sanitizer strips all `on*` event handlers, `<script>` elements, `javascript:` pseudo-protocol, external entity declarations, `<foreignObject>`, and `data:` URIs with non-image MIME types.
- Media Replace requires `edit_post` capability for the specific attachment.
- Media Visibility changes require `edit_post` capability for the attachment.
- Folder management (create, rename, delete) requires `manage_wpt`.
- Attachment-to-folder assignment requires `edit_post` for the attachment.
- Image size regeneration requires `manage_wpt`.
- Local avatar upload respects `upload_files` capability. Editing another user's avatar requires `edit_users`.
- Rate-limit the regeneration endpoint to prevent abuse (one active regeneration job per user).

## 14. Mobile/Responsive Behavior

- Folder sidebar collapses to a dropdown on screens below 782px (WordPress mobile breakpoint).
- Drag-drop folder assignment is disabled on touch devices; a "Move to Folder" dropdown is shown instead.
- Media Replace upload dialog is full-width on mobile.
- Image Sizes Panel table scrolls horizontally on mobile. Regenerate buttons remain accessible.
- Avatar uploader crop tool works on touch devices via the WordPress cropper (which supports touch).
- Visibility toggle is inline on mobile, no metabox collapse needed.

## 15. Data Retention / Uninstall

- On module disable: all settings retained. Taxonomy terms retained. User meta retained. Post meta retained. Media files untouched.
- On plugin uninstall (clean uninstall option):
  - Delete `wpt_media_folder` taxonomy terms and term relationships.
  - Delete `_wpt_visibility` post meta from all attachments.
  - Delete `wpt_local_avatar_id` user meta from all users.
  - Delete `wpt_media_library_pro` option.
  - Delete the `wpt-replace-backups/` directory and contents.
  - Do not delete uploaded avatar images or any media files (they are standard attachments).
  - Do not delete or modify image sizes registered by other plugins/themes.

## 16. Verification & Acceptance Criteria

- [ ] Folder tree renders in the media library with drag-drop assignment.
- [ ] Assigning an attachment to a folder removes it from the previous folder.
- [ ] "Uncategorized" shows attachments with no folder term.
- [ ] Filtering by folder in the media library shows only that folder's attachments.
- [ ] Folders appear in the Gutenberg media inserter with correct filtering.
- [ ] Replacing a media file preserves the attachment ID.
- [ ] All references (featured image, block content, galleries) resolve to the new file after replace.
- [ ] Backup of original file is created when `keep_backup` is true.
- [ ] Replacing with a different MIME type is blocked when `allow_type_change` is false.
- [ ] SVG upload is rejected for non-administrator roles (default config).
- [ ] SVG with `<script>` tag is sanitized (script removed, SVG retained).
- [ ] SVG with `onclick` attribute is sanitized (attribute removed).
- [ ] SVG exceeding 1 MB or 50,000 DOM nodes is rejected.
- [ ] SVG renders as inline preview in the media grid.
- [ ] AVIF file uploads successfully and displays in media library.
- [ ] AVIF thumbnails are generated when server supports it.
- [ ] AVIF upload succeeds gracefully when thumbnail generation fails.
- [ ] Local avatar replaces Gravatar on the user profile and in comments.
- [ ] Removing local avatar falls back to Gravatar.
- [ ] Image Sizes Panel lists all registered sizes with correct source attribution.
- [ ] Disabling an image size prevents its generation on new uploads.
- [ ] Regenerating a single size creates thumbnails for all existing attachments.
- [ ] Regeneration progress bar updates during bulk regeneration.
- [ ] Private attachment is excluded from frontend attachment queries.
- [ ] Private attachment remains visible in the admin media library.
- [ ] Lock icon overlay appears on private attachments in the media grid.
- [ ] Unauthorized user cannot manage folders, replace media, or change visibility.

## 17. Deferred Features

- Folder migration from FileBird / Real Media Library (import existing folder structure).
- Smart folders (auto-populate by file type, date, author).
- Media Replace: rollback to previous version (version history).
- SVG optimization (SVGO integration for reducing file size).
- AVIF conversion (convert existing JPEG/PNG to AVIF).
- Bulk visibility change for multiple attachments.
- Server-level private media access control via `.htaccess` generation.
- Duplicate media detection.
- AI-powered alt-text generation.

## 18. Known Gotchas

- The `wpt_media_folder` taxonomy must have `show_in_rest: true` for the block editor media inserter integration, but `show_ui: false` to prevent the default taxonomy metabox from appearing on the attachment edit screen.
- WordPress does not natively support AVIF in all environments. GD support requires PHP 8.1+ compiled with AVIF. Imagick support requires ImageMagick 7.0.25+. Check before enabling and show a notice if unsupported.
- SVG files have no intrinsic pixel dimensions. Width and height must be read from the `viewBox` attribute. If no `viewBox` is present, dimension-dependent features (responsive images, srcset) will not work correctly.
- The Image Sizes Panel shows sizes registered at the time of page load. Sizes registered conditionally (e.g., only on the frontend by a theme) may not appear.
- Regenerating thumbnails on a site with 50,000+ images can take a very long time. The AJAX-batch approach means the browser must stay open. Consider documenting WP-CLI `wp media regenerate` as an alternative for very large libraries.
- Some page builders (Elementor, Beaver Builder) cache image URLs in their own metadata. Media Replace updates WordPress metadata but cannot update third-party caches automatically. The `wpt_media_replaced` action is provided for integration.
- `wp-content/uploads/wpt-replace-backups/` must be writable. If the uploads directory has restrictive permissions, backup creation will fail silently and the replace proceeds without backup.
