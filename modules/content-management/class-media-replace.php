<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Media Replace — Replace an existing media file while keeping the same
 * attachment ID and URL. Regenerates thumbnails automatically.
 *
 * @package WPTransformed
 */
class Media_Replace extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'media-replace';
    }

    public function get_title(): string {
        return __( 'Media Replace', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Replace media files in-place while keeping the same URL and attachment ID. Thumbnails are regenerated automatically.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'   => true,
            'keep_date' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Add "Replace Media" link to media list row actions.
        add_filter( 'media_row_actions', [ $this, 'add_replace_action' ], 10, 2 );

        // AJAX: render the replace upload form.
        add_action( 'wp_ajax_wpt_media_replace_form', [ $this, 'ajax_render_form' ] );

        // AJAX: handle the file upload and replacement.
        add_action( 'wp_ajax_wpt_media_replace_upload', [ $this, 'ajax_handle_upload' ] );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Add "Replace Media" link to the media library list view.
     *
     * @param array<string,string> $actions Row actions.
     * @param \WP_Post             $post    The attachment post.
     * @return array<string,string>
     */
    public function add_replace_action( array $actions, \WP_Post $post ): array {
        if ( ! current_user_can( 'upload_files' ) ) {
            return $actions;
        }

        $nonce = wp_create_nonce( 'wpt_media_replace_form_' . $post->ID );
        $actions['wpt_replace'] = sprintf(
            '<a href="#" class="wpt-media-replace-link" data-id="%d" data-nonce="%s">%s</a>',
            (int) $post->ID,
            esc_attr( $nonce ),
            esc_html__( 'Replace Media', 'wptransformed' )
        );

        return $actions;
    }

    /**
     * AJAX handler — render the replacement upload form as HTML.
     */
    public function ajax_render_form(): void {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        check_ajax_referer( 'wpt_media_replace_form_' . $attachment_id, 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
            wp_send_json_error( [ 'message' => 'Invalid attachment.' ] );
        }

        $upload_nonce = wp_create_nonce( 'wpt_media_replace_upload_' . $attachment_id );
        $current_file = basename( get_attached_file( $attachment_id ) ?: '' );
        $current_type = get_post_mime_type( $attachment_id );

        ob_start();
        ?>
        <div class="wpt-media-replace-form" style="padding: 16px; background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px; margin: 8px 0;">
            <p style="margin: 0 0 8px;">
                <strong><?php esc_html_e( 'Current file:', 'wptransformed' ); ?></strong>
                <?php echo esc_html( $current_file ); ?>
                (<?php echo esc_html( $current_type ?: 'unknown' ); ?>)
            </p>
            <input type="file"
                   class="wpt-replace-file-input"
                   data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>"
                   data-nonce="<?php echo esc_attr( $upload_nonce ); ?>">
            <button type="button" class="button button-primary wpt-replace-upload-btn" disabled style="margin-left: 8px;">
                <?php esc_html_e( 'Upload Replacement', 'wptransformed' ); ?>
            </button>
            <button type="button" class="button wpt-replace-cancel-btn" style="margin-left: 4px;">
                <?php esc_html_e( 'Cancel', 'wptransformed' ); ?>
            </button>
            <span class="wpt-replace-status" style="margin-left: 12px;"></span>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * AJAX handler — process the replacement file upload.
     */
    public function ajax_handle_upload(): void {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        check_ajax_referer( 'wpt_media_replace_upload_' . $attachment_id, 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
            wp_send_json_error( [ 'message' => 'Invalid attachment.' ] );
        }

        if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
            $error_code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            wp_send_json_error( [ 'message' => $this->get_upload_error_message( (int) $error_code ) ] );
        }

        // Validate the uploaded file using WP's built-in check.
        $file_info = wp_check_filetype_and_ext(
            $_FILES['file']['tmp_name'],
            sanitize_file_name( $_FILES['file']['name'] )
        );

        if ( empty( $file_info['ext'] ) || empty( $file_info['type'] ) ) {
            wp_send_json_error( [ 'message' => __( 'File type not allowed.', 'wptransformed' ) ] );
        }

        // Get the current file path.
        $old_file_path = get_attached_file( $attachment_id );
        if ( ! $old_file_path ) {
            wp_send_json_error( [ 'message' => __( 'Cannot determine current file path.', 'wptransformed' ) ] );
        }

        $old_dir = dirname( $old_file_path );

        // Build the new file path — keep the same directory, use the old filename
        // but with the new extension if it changed.
        $old_ext    = pathinfo( $old_file_path, PATHINFO_EXTENSION );
        $new_ext    = $file_info['ext'];
        $old_name   = pathinfo( $old_file_path, PATHINFO_FILENAME );
        $new_path   = $old_dir . '/' . $old_name . '.' . $new_ext;

        // Delete old thumbnails before replacing.
        $old_metadata = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $old_metadata ) && ! empty( $old_metadata['sizes'] ) ) {
            foreach ( $old_metadata['sizes'] as $size_data ) {
                wp_delete_file( $old_dir . '/' . $size_data['file'] );
            }
        }

        // Delete the old main file if the extension changed.
        if ( strtolower( $old_ext ) !== strtolower( $new_ext ) ) {
            wp_delete_file( $old_file_path );
        }

        // Move the uploaded file to the old location.
        if ( ! move_uploaded_file( $_FILES['file']['tmp_name'], $new_path ) ) {
            wp_send_json_error( [ 'message' => __( 'Failed to move uploaded file.', 'wptransformed' ) ] );
        }

        // Set standard file permissions.
        $perms = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
        chmod( $new_path, $perms );

        // Update attachment metadata.
        $relative_path = _wp_relative_upload_path( $new_path );
        update_post_meta( $attachment_id, '_wp_attached_file', $relative_path );

        // Build a single wp_update_post call for mime type and/or date changes.
        $post_update = [ 'ID' => $attachment_id ];
        $new_mime    = $file_info['type'];
        $needs_update = false;

        if ( $new_mime !== $attachment->post_mime_type ) {
            $post_update['post_mime_type'] = $new_mime;
            $needs_update = true;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['keep_date'] ) ) {
            $now = current_time( 'mysql', true );
            $post_update['post_date']         = get_date_from_gmt( $now );
            $post_update['post_date_gmt']     = $now;
            $post_update['post_modified']     = get_date_from_gmt( $now );
            $post_update['post_modified_gmt'] = $now;
            $needs_update = true;
        }

        if ( $needs_update ) {
            wp_update_post( $post_update );
        }

        // Regenerate attachment metadata (thumbnails, etc.).
        $new_metadata = wp_generate_attachment_metadata( $attachment_id, $new_path );
        wp_update_attachment_metadata( $attachment_id, $new_metadata );

        wp_send_json_success( [
            'message'  => __( 'Media replaced successfully.', 'wptransformed' ),
            'filename' => basename( $new_path ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings  = $this->get_settings();
        $enabled   = ! empty( $settings['enabled'] );
        $keep_date = ! empty( $settings['keep_date'] );
        ?>

        <div class="wpt-media-replace-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Replace media files in-place while keeping the same attachment ID and URL. Useful for updating images or documents without breaking existing links.', 'wptransformed' ); ?>
            </p>
        </div>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_enabled"
                               value="1"
                               <?php checked( $enabled ); ?>>
                        <?php esc_html_e( 'Add "Replace Media" option to the media library', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Keep Original Date', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_keep_date"
                               value="1"
                               <?php checked( $keep_date ); ?>>
                        <?php esc_html_e( 'Keep the original upload date when replacing a file', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When unchecked, the attachment date will be updated to the current time.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'   => ! empty( $raw['wpt_enabled'] ),
            'keep_date' => ! empty( $raw['wpt_keep_date'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'upload.php' ) {
            return;
        }

        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        // Inline JS for the media list page.
        add_action( 'admin_footer', [ $this, 'render_media_list_js' ] );
    }

    /**
     * Render inline JS for the media library list view.
     */
    public function render_media_list_js(): void {
        ?>
        <script>
        (function() {
            document.addEventListener('click', function(e) {
                var link = e.target.closest('.wpt-media-replace-link');
                if (!link) return;
                e.preventDefault();

                var id = link.getAttribute('data-id');
                var nonce = link.getAttribute('data-nonce');
                var row = link.closest('tr');
                if (!row) return;

                // Remove any existing form.
                var existing = row.querySelector('.wpt-media-replace-form');
                if (existing) {
                    existing.parentNode.removeChild(existing);
                    return;
                }

                // Fetch the form via AJAX.
                var formData = new FormData();
                formData.append('action', 'wpt_media_replace_form');
                formData.append('attachment_id', id);
                formData.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) return;

                        var titleCol = row.querySelector('.column-title') || row.querySelector('td');
                        if (!titleCol) return;

                        var wrapper = document.createElement('div');
                        wrapper.innerHTML = resp.data.html;
                        titleCol.appendChild(wrapper.firstElementChild);

                        // Wire up the form controls.
                        var form = titleCol.querySelector('.wpt-media-replace-form');
                        var fileInput = form.querySelector('.wpt-replace-file-input');
                        var uploadBtn = form.querySelector('.wpt-replace-upload-btn');
                        var cancelBtn = form.querySelector('.wpt-replace-cancel-btn');
                        var statusEl = form.querySelector('.wpt-replace-status');

                        fileInput.addEventListener('change', function() {
                            uploadBtn.disabled = !this.files.length;
                        });

                        cancelBtn.addEventListener('click', function() {
                            form.parentNode.removeChild(form);
                        });

                        uploadBtn.addEventListener('click', function() {
                            if (!fileInput.files.length) return;

                            uploadBtn.disabled = true;
                            statusEl.textContent = <?php echo wp_json_encode( __( 'Uploading...', 'wptransformed' ) ); ?>;

                            var uploadData = new FormData();
                            uploadData.append('action', 'wpt_media_replace_upload');
                            uploadData.append('attachment_id', fileInput.getAttribute('data-attachment-id'));
                            uploadData.append('nonce', fileInput.getAttribute('data-nonce'));
                            uploadData.append('file', fileInput.files[0]);

                            fetch(ajaxurl, { method: 'POST', body: uploadData, credentials: 'same-origin' })
                                .then(function(r) { return r.json(); })
                                .then(function(resp) {
                                    if (resp.success) {
                                        statusEl.style.color = '#00a32a';
                                        statusEl.textContent = resp.data.message;
                                        setTimeout(function() { location.reload(); }, 1500);
                                    } else {
                                        statusEl.style.color = '#d63638';
                                        statusEl.textContent = resp.data.message || <?php echo wp_json_encode( __( 'Upload failed.', 'wptransformed' ) ); ?>;
                                        uploadBtn.disabled = false;
                                    }
                                })
                                .catch(function() {
                                    statusEl.style.color = '#d63638';
                                    statusEl.textContent = <?php echo wp_json_encode( __( 'Network error.', 'wptransformed' ) ); ?>;
                                    uploadBtn.disabled = false;
                                });
                        });
                    });
            });
        })();
        </script>
        <?php
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Get a human-readable upload error message.
     *
     * @param int $error_code PHP upload error code.
     * @return string
     */
    private function get_upload_error_message( int $error_code ): string {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => __( 'File exceeds the upload_max_filesize directive.', 'wptransformed' ),
            UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds the MAX_FILE_SIZE directive.', 'wptransformed' ),
            UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'wptransformed' ),
            UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'wptransformed' ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'Missing temporary folder.', 'wptransformed' ),
            UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'wptransformed' ),
            UPLOAD_ERR_EXTENSION  => __( 'Upload stopped by a PHP extension.', 'wptransformed' ),
        ];

        return $messages[ $error_code ] ?? __( 'Unknown upload error.', 'wptransformed' );
    }
}
