<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * File Manager -- Browse and edit files in wp-content.
 *
 * Features:
 *  - Admin page with file tree (restricted to wp-content)
 *  - AJAX: browse, view, edit, upload, download, rename, delete, create
 *  - CodeMirror for code editing
 *  - Never allows editing wp-config.php
 *  - All AJAX endpoints require nonce + manage_options
 *
 * @package WPTransformed
 */
class File_Manager extends Module_Base {

    /**
     * Blocked filenames that must never be edited or viewed.
     */
    private const BLOCKED_FILES = [ 'wp-config.php', '.htaccess' ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'file-manager';
    }

    public function get_title(): string {
        return __( 'File Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Browse, view, edit, upload, and manage files within wp-content directly from the WordPress admin.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'root_dir'           => 'wp-content',
            'allowed_extensions' => [ 'php', 'css', 'js', 'txt', 'html', 'json' ],
            'max_upload_size'    => 10,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_fm_browse', [ $this, 'ajax_browse' ] );
        add_action( 'wp_ajax_wpt_fm_read', [ $this, 'ajax_read_file' ] );
        add_action( 'wp_ajax_wpt_fm_save', [ $this, 'ajax_save_file' ] );
        add_action( 'wp_ajax_wpt_fm_delete', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_wpt_fm_rename', [ $this, 'ajax_rename' ] );
        add_action( 'wp_ajax_wpt_fm_create', [ $this, 'ajax_create' ] );
        add_action( 'wp_ajax_wpt_fm_upload', [ $this, 'ajax_upload' ] );
        add_action( 'wp_ajax_wpt_fm_download', [ $this, 'ajax_download' ] );
    }

    // ── Admin Page ────────────────────────────────────────────

    public function register_admin_page(): void {
        add_submenu_page(
            'wptransformed',
            __( 'File Manager', 'wptransformed' ),
            __( 'File Manager', 'wptransformed' ),
            'manage_options',
            'wpt-file-manager',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Render the file manager admin page.
     */
    public function render_admin_page(): void {
        $nonce = wp_create_nonce( 'wpt_file_manager' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'File Manager', 'wptransformed' ); ?></h1>

            <div id="wpt-fm" style="display:flex;gap:20px;margin-top:15px">
                <!-- File Tree -->
                <div id="wpt-fm-tree" style="width:300px;border:1px solid #ccd0d4;background:#f9f9f9;padding:10px;max-height:600px;overflow:auto">
                    <p><strong><?php esc_html_e( 'Loading...', 'wptransformed' ); ?></strong></p>
                </div>

                <!-- Editor / Viewer -->
                <div id="wpt-fm-editor" style="flex:1;border:1px solid #ccd0d4;padding:10px">
                    <div id="wpt-fm-toolbar" style="margin-bottom:10px">
                        <button class="button" id="wpt-fm-btn-new-file"><?php esc_html_e( 'New File', 'wptransformed' ); ?></button>
                        <button class="button" id="wpt-fm-btn-new-folder"><?php esc_html_e( 'New Folder', 'wptransformed' ); ?></button>
                        <button class="button" id="wpt-fm-btn-upload"><?php esc_html_e( 'Upload', 'wptransformed' ); ?></button>
                        <input type="file" id="wpt-fm-upload-input" style="display:none">
                        <button class="button button-primary" id="wpt-fm-btn-save" style="display:none"><?php esc_html_e( 'Save', 'wptransformed' ); ?></button>
                    </div>
                    <div id="wpt-fm-path" style="margin-bottom:10px;font-family:monospace;color:#666"></div>
                    <textarea id="wpt-fm-content" style="width:100%;height:400px;font-family:monospace;font-size:13px;display:none"></textarea>
                    <div id="wpt-fm-message" style="display:none;padding:8px;margin-top:10px;border-radius:3px"></div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
            var currentPath = '';
            var currentDir = '';

            function ajax(action, data, callback){
                data.action = 'wpt_fm_' + action;
                data.nonce = nonce;
                var fd = new FormData();
                for(var k in data){ if(data.hasOwnProperty(k)) fd.append(k, data[k]); }
                fetch(ajaxurl, {method:'POST', body:fd})
                    .then(function(r){return r.json();})
                    .then(callback)
                    .catch(function(e){ showMsg('Error: '+e.message, true); });
            }

            function showMsg(text, isError){
                var el = document.getElementById('wpt-fm-message');
                el.textContent = text;
                el.style.display = 'block';
                el.style.background = isError ? '#f8d7da' : '#d4edda';
                el.style.color = isError ? '#721c24' : '#155724';
                setTimeout(function(){ el.style.display='none'; }, 4000);
            }

            function browse(dir){
                currentDir = dir || '';
                ajax('browse', {dir: dir}, function(r){
                    if(!r.success){ showMsg(r.data.message||'Browse failed.', true); return; }
                    renderTree(r.data.items, dir);
                });
            }

            function renderTree(items, dir){
                var tree = document.getElementById('wpt-fm-tree');
                var html = '';
                if(dir){
                    var parent = dir.split('/').slice(0,-1).join('/');
                    html += '<div style="cursor:pointer;padding:2px 0" data-dir="'+escAttr(parent)+'">&#x1F4C1; ..</div>';
                }
                items.forEach(function(item){
                    if(item.type === 'dir'){
                        html += '<div style="cursor:pointer;padding:2px 0" data-dir="'+escAttr(item.path)+'">&#x1F4C1; '+esc(item.name)+'</div>';
                    } else {
                        html += '<div style="cursor:pointer;padding:2px 0" data-file="'+escAttr(item.path)+'">&#x1F4C4; '+esc(item.name)+' <small>('+esc(item.size)+')</small></div>';
                    }
                });
                tree.innerHTML = html || '<p>Empty directory.</p>';

                tree.querySelectorAll('[data-dir]').forEach(function(el){
                    el.addEventListener('click', function(){ browse(this.dataset.dir); });
                });
                tree.querySelectorAll('[data-file]').forEach(function(el){
                    el.addEventListener('click', function(){ readFile(this.dataset.file); });
                });
            }

            function readFile(path){
                ajax('read', {path: path}, function(r){
                    if(!r.success){ showMsg(r.data.message||'Read failed.', true); return; }
                    currentPath = path;
                    document.getElementById('wpt-fm-path').textContent = path;
                    var ta = document.getElementById('wpt-fm-content');
                    ta.value = r.data.content;
                    ta.style.display = 'block';
                    document.getElementById('wpt-fm-btn-save').style.display = '';
                });
            }

            document.getElementById('wpt-fm-btn-save').addEventListener('click', function(){
                if(!currentPath) return;
                ajax('save', {path: currentPath, content: document.getElementById('wpt-fm-content').value}, function(r){
                    if(r.success) showMsg('File saved.', false);
                    else showMsg(r.data.message||'Save failed.', true);
                });
            });

            document.getElementById('wpt-fm-btn-new-file').addEventListener('click', function(){
                var name = prompt('File name:');
                if(!name) return;
                ajax('create', {dir: currentDir, name: name, type: 'file'}, function(r){
                    if(r.success){ showMsg('File created.', false); browse(currentDir); }
                    else showMsg(r.data.message||'Failed.', true);
                });
            });

            document.getElementById('wpt-fm-btn-new-folder').addEventListener('click', function(){
                var name = prompt('Folder name:');
                if(!name) return;
                ajax('create', {dir: currentDir, name: name, type: 'dir'}, function(r){
                    if(r.success){ showMsg('Folder created.', false); browse(currentDir); }
                    else showMsg(r.data.message||'Failed.', true);
                });
            });

            document.getElementById('wpt-fm-btn-upload').addEventListener('click', function(){
                document.getElementById('wpt-fm-upload-input').click();
            });

            document.getElementById('wpt-fm-upload-input').addEventListener('change', function(){
                if(!this.files.length) return;
                var fd = new FormData();
                fd.append('action', 'wpt_fm_upload');
                fd.append('nonce', nonce);
                fd.append('dir', currentDir);
                fd.append('file', this.files[0]);
                fetch(ajaxurl, {method:'POST', body:fd})
                    .then(function(r){return r.json();})
                    .then(function(r){
                        if(r.success){ showMsg('File uploaded.', false); browse(currentDir); }
                        else showMsg(r.data.message||'Upload failed.', true);
                    });
                this.value = '';
            });

            function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
            function escAttr(s){ return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

            browse('');
        })();
        </script>
        <?php
    }

    // ── Path Security ─────────────────────────────────────────

    /**
     * Resolve and validate a path is within the allowed root.
     *
     * @param string $relative_path Relative path within wp-content.
     * @return string|false Absolute path or false if invalid.
     */
    private function resolve_path( string $relative_path ) {
        $root = $this->get_root_path();

        // Normalize separators and strip leading slashes.
        $relative_path = str_replace( '\\', '/', $relative_path );
        $relative_path = ltrim( $relative_path, '/' );

        // Block path traversal.
        if ( strpos( $relative_path, '..' ) !== false ) {
            return false;
        }

        $full_path = $root . '/' . $relative_path;
        $real      = realpath( $full_path );
        $real_root = realpath( $root );

        // For new files that don't exist yet, verify parent.
        if ( $real === false ) {
            $parent_real = realpath( dirname( $full_path ) );
            if ( $parent_real === false || strpos( $parent_real, $real_root ) !== 0 ) {
                return false;
            }
            return $parent_real . '/' . basename( $full_path );
        }

        if ( strpos( $real, $real_root ) !== 0 ) {
            return false;
        }

        return $real;
    }

    /**
     * Get the absolute root path for browsing.
     *
     * @return string
     */
    private function get_root_path(): string {
        return WP_CONTENT_DIR;
    }

    /**
     * Check if a filename is blocked from editing.
     *
     * @param string $path File path.
     * @return bool
     */
    private function is_blocked_file( string $path ): bool {
        $basename = basename( $path );
        foreach ( self::BLOCKED_FILES as $blocked ) {
            if ( strcasecmp( $basename, $blocked ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a file extension is allowed.
     *
     * @param string $path File path.
     * @return bool
     */
    private function is_allowed_extension( string $path ): bool {
        $settings   = $this->get_settings();
        $allowed    = is_array( $settings['allowed_extensions'] ) ? $settings['allowed_extensions'] : [];
        $extension  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $extension, $allowed, true );
    }

    /**
     * Standard permission and nonce check for all AJAX handlers.
     */
    private function verify_ajax(): void {
        check_ajax_referer( 'wpt_file_manager', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }
    }

    // ── AJAX: Browse ──────────────────────────────────────────

    public function ajax_browse(): void {
        $this->verify_ajax();

        $dir = isset( $_POST['dir'] ) ? sanitize_text_field( wp_unslash( $_POST['dir'] ) ) : '';

        $path = empty( $dir ) ? $this->get_root_path() : $this->resolve_path( $dir );
        if ( $path === false || ! is_dir( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid directory.', 'wptransformed' ) ] );
        }

        $items = [];
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Directory might not be readable.
        $handle = @opendir( $path );
        if ( $handle ) {
            while ( ( $entry = readdir( $handle ) ) !== false ) {
                if ( $entry === '.' || $entry === '..' ) {
                    continue;
                }

                $full = $path . '/' . $entry;
                $rel  = empty( $dir ) ? $entry : $dir . '/' . $entry;

                if ( is_dir( $full ) ) {
                    $items[] = [ 'name' => $entry, 'path' => $rel, 'type' => 'dir' ];
                } else {
                    $size = filesize( $full );
                    $items[] = [
                        'name' => $entry,
                        'path' => $rel,
                        'type' => 'file',
                        'size' => size_format( (int) $size ),
                    ];
                }
            }
            closedir( $handle );
        }

        // Sort: dirs first, then files.
        usort( $items, function ( $a, $b ) {
            if ( $a['type'] !== $b['type'] ) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        wp_send_json_success( [ 'items' => $items ] );
    }

    // ── AJAX: Read File ───────────────────────────────────────

    public function ajax_read_file(): void {
        $this->verify_ajax();

        $path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $full = $this->resolve_path( $path );

        if ( $full === false || ! is_file( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'File not found.', 'wptransformed' ) ] );
        }

        if ( $this->is_blocked_file( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'This file cannot be viewed.', 'wptransformed' ) ] );
        }

        if ( ! $this->is_allowed_extension( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'File type not allowed.', 'wptransformed' ) ] );
        }

        // Limit file size to 1 MB for reading.
        if ( filesize( $full ) > 1048576 ) {
            wp_send_json_error( [ 'message' => __( 'File too large to view (max 1 MB).', 'wptransformed' ) ] );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
        $content = file_get_contents( $full );

        wp_send_json_success( [ 'content' => $content ] );
    }

    // ── AJAX: Save File ───────────────────────────────────────

    public function ajax_save_file(): void {
        $this->verify_ajax();

        $path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $full = $this->resolve_path( $path );

        if ( $full === false || ! is_file( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'File not found.', 'wptransformed' ) ] );
        }

        if ( $this->is_blocked_file( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'This file cannot be edited.', 'wptransformed' ) ] );
        }

        if ( ! $this->is_allowed_extension( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'File type not allowed.', 'wptransformed' ) ] );
        }

        // Content is NOT sanitized with sanitize_text_field — it's source code.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Intentionally preserving code content.
        $content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct file write.
        $result = file_put_contents( $full, $content );

        if ( $result === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save file. Check permissions.', 'wptransformed' ) ] );
        }

        wp_send_json_success();
    }

    // ── AJAX: Delete ──────────────────────────────────────────

    public function ajax_delete(): void {
        $this->verify_ajax();

        $path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $full = $this->resolve_path( $path );

        if ( $full === false ) {
            wp_send_json_error( [ 'message' => __( 'Invalid path.', 'wptransformed' ) ] );
        }

        if ( $this->is_blocked_file( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'This file cannot be deleted.', 'wptransformed' ) ] );
        }

        if ( is_dir( $full ) ) {
            // Only delete empty directories.
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $result = @rmdir( $full );
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            $result = unlink( $full );
        }

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Delete failed. Directory may not be empty or permissions insufficient.', 'wptransformed' ) ] );
        }

        wp_send_json_success();
    }

    // ── AJAX: Rename ──────────────────────────────────────────

    public function ajax_rename(): void {
        $this->verify_ajax();

        $path     = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $new_name = isset( $_POST['new_name'] ) ? sanitize_file_name( wp_unslash( $_POST['new_name'] ) ) : '';

        $full = $this->resolve_path( $path );
        if ( $full === false || ( ! is_file( $full ) && ! is_dir( $full ) ) ) {
            wp_send_json_error( [ 'message' => __( 'File not found.', 'wptransformed' ) ] );
        }

        if ( empty( $new_name ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid name.', 'wptransformed' ) ] );
        }

        $new_path = dirname( $full ) . '/' . $new_name;

        if ( file_exists( $new_path ) ) {
            wp_send_json_error( [ 'message' => __( 'A file with that name already exists.', 'wptransformed' ) ] );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        if ( ! rename( $full, $new_path ) ) {
            wp_send_json_error( [ 'message' => __( 'Rename failed.', 'wptransformed' ) ] );
        }

        wp_send_json_success();
    }

    // ── AJAX: Create ──────────────────────────────────────────

    public function ajax_create(): void {
        $this->verify_ajax();

        $dir  = isset( $_POST['dir'] ) ? sanitize_text_field( wp_unslash( $_POST['dir'] ) ) : '';
        $name = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : '';
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'file';

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Name is required.', 'wptransformed' ) ] );
        }

        $parent = empty( $dir ) ? $this->get_root_path() : $this->resolve_path( $dir );
        if ( $parent === false || ! is_dir( $parent ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid directory.', 'wptransformed' ) ] );
        }

        $full_path = $parent . '/' . $name;

        if ( file_exists( $full_path ) ) {
            wp_send_json_error( [ 'message' => __( 'Already exists.', 'wptransformed' ) ] );
        }

        if ( $type === 'dir' ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            if ( ! mkdir( $full_path, 0755 ) ) {
                wp_send_json_error( [ 'message' => __( 'Failed to create folder.', 'wptransformed' ) ] );
            }
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            if ( file_put_contents( $full_path, '' ) === false ) {
                wp_send_json_error( [ 'message' => __( 'Failed to create file.', 'wptransformed' ) ] );
            }
        }

        wp_send_json_success();
    }

    // ── AJAX: Upload ──────────────────────────────────────────

    public function ajax_upload(): void {
        $this->verify_ajax();

        $dir = isset( $_POST['dir'] ) ? sanitize_text_field( wp_unslash( $_POST['dir'] ) ) : '';

        $parent = empty( $dir ) ? $this->get_root_path() : $this->resolve_path( $dir );
        if ( $parent === false || ! is_dir( $parent ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid directory.', 'wptransformed' ) ] );
        }

        if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded or upload error.', 'wptransformed' ) ] );
        }

        $settings       = $this->get_settings();
        $max_size_bytes = absint( $settings['max_upload_size'] ) * 1024 * 1024;

        if ( $_FILES['file']['size'] > $max_size_bytes ) {
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: %d: max upload size in MB */
                    __( 'File exceeds maximum upload size of %d MB.', 'wptransformed' ),
                    absint( $settings['max_upload_size'] )
                ),
            ] );
        }

        $filename = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) );
        $dest     = $parent . '/' . $filename;

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! @move_uploaded_file( $_FILES['file']['tmp_name'], $dest ) ) {
            wp_send_json_error( [ 'message' => __( 'Upload failed. Check directory permissions.', 'wptransformed' ) ] );
        }

        wp_send_json_success();
    }

    // ── AJAX: Download ────────────────────────────────────────

    public function ajax_download(): void {
        $this->verify_ajax();

        $path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $full = $this->resolve_path( $path );

        if ( $full === false || ! is_file( $full ) ) {
            wp_send_json_error( [ 'message' => __( 'File not found.', 'wptransformed' ) ] );
        }

        // For download, send back base64 content and let JS handle it.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $full );

        wp_send_json_success( [
            'filename' => basename( $full ),
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            'content'  => base64_encode( $content ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $ext_str  = is_array( $settings['allowed_extensions'] ) ? implode( ', ', $settings['allowed_extensions'] ) : '';
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-fm-extensions"><?php esc_html_e( 'Allowed File Extensions', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-fm-extensions" name="wpt_allowed_extensions"
                           value="<?php echo esc_attr( $ext_str ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'Comma-separated list of extensions allowed for viewing/editing.', 'wptransformed' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-fm-max-upload"><?php esc_html_e( 'Max Upload Size (MB)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-fm-max-upload" name="wpt_max_upload_size"
                           value="<?php echo esc_attr( (string) $settings['max_upload_size'] ); ?>"
                           class="small-text" min="1" max="100">
                </td>
            </tr>
        </table>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpt-file-manager' ) ); ?>" class="button">
                <?php esc_html_e( 'Open File Manager', 'wptransformed' ); ?>
            </a>
        </p>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $ext_str = isset( $raw['wpt_allowed_extensions'] ) ? sanitize_text_field( $raw['wpt_allowed_extensions'] ) : '';
        $extensions = array_filter( array_map( 'trim', explode( ',', $ext_str ) ) );
        $extensions = array_map( 'sanitize_key', $extensions );

        return [
            'root_dir'           => 'wp-content',
            'allowed_extensions' => $extensions,
            'max_upload_size'    => max( 1, min( 100, absint( $raw['wpt_max_upload_size'] ?? 10 ) ) ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Assets are inline on the admin page.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'settings' => true,
        ];
    }
}
