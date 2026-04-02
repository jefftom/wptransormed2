<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Media Folders — Organize media library with a virtual folder taxonomy.
 *
 * Registers a hidden hierarchical taxonomy on attachments and provides
 * a folder sidebar in both grid and list views of the media library,
 * plus drag-and-drop assignment.
 *
 * @package WPTransformed
 */
class Media_Folders extends Module_Base {

    /** Taxonomy name constant. */
    const TAXONOMY = 'wpt_media_folder';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'media-folders';
    }

    public function get_title(): string {
        return __( 'Media Folders', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Organize your media library into folders with drag-and-drop support.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Register taxonomy early.
        add_action( 'init', [ $this, 'register_taxonomy' ] );

        // List view: folder dropdown filter.
        add_action( 'restrict_manage_posts', [ $this, 'add_folder_filter_dropdown' ] );

        // Grid view (media modal): filter attachments by folder.
        add_filter( 'ajax_query_attachments_args', [ $this, 'filter_attachments_by_folder' ], 90 );

        // Enqueue assets on media pages.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX handlers — use upload_files capability (editors need access).
        add_action( 'wp_ajax_wpt_create_folder', [ $this, 'ajax_create_folder' ] );
        add_action( 'wp_ajax_wpt_rename_folder', [ $this, 'ajax_rename_folder' ] );
        add_action( 'wp_ajax_wpt_delete_folder', [ $this, 'ajax_delete_folder' ] );
        add_action( 'wp_ajax_wpt_move_folder',   [ $this, 'ajax_move_folder' ] );
        add_action( 'wp_ajax_wpt_assign_folder',  [ $this, 'ajax_assign_folder' ] );
        add_action( 'wp_ajax_wpt_get_folders',    [ $this, 'ajax_get_folders' ] );
    }

    // ── Taxonomy Registration ─────────────────────────────────

    /**
     * Register the hidden hierarchical taxonomy for media folders.
     */
    public function register_taxonomy(): void {
        register_taxonomy( self::TAXONOMY, 'attachment', [
            'labels'            => [
                'name'          => __( 'Media Folders', 'wptransformed' ),
                'singular_name' => __( 'Media Folder', 'wptransformed' ),
                'add_new_item'  => __( 'Add New Folder', 'wptransformed' ),
                'edit_item'     => __( 'Edit Folder', 'wptransformed' ),
                'search_items'  => __( 'Search Folders', 'wptransformed' ),
            ],
            'hierarchical'      => true,
            'show_ui'           => false,
            'show_in_rest'      => true,
            'show_admin_column' => false,
            'public'            => false,
            'rewrite'           => false,
        ] );
    }

    // ── List View Filter ──────────────────────────────────────

    /**
     * Add folder dropdown filter on the media list table (upload.php).
     */
    public function add_folder_filter_dropdown(): void {
        $screen = get_current_screen();

        if ( ! $screen || 'upload' !== $screen->id ) {
            return;
        }

        $terms = get_terms( [
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $selected = isset( $_GET[ self::TAXONOMY ] ) ? sanitize_key( $_GET[ self::TAXONOMY ] ) : '';

        echo '<select name="' . esc_attr( self::TAXONOMY ) . '" id="wpt-media-folder-filter">';
        echo '<option value="">' . esc_html__( 'All Folders', 'wptransformed' ) . '</option>';
        echo '<option value="__uncategorized"' . selected( $selected, '__uncategorized', false ) . '>' . esc_html__( 'Uncategorized', 'wptransformed' ) . '</option>';

        $this->render_term_options( $terms, 0, $selected );

        echo '</select>';
    }

    /**
     * Render hierarchical term options for the dropdown.
     *
     * @param array  $terms    All folder terms.
     * @param int    $parent   Parent term ID.
     * @param string $selected Currently selected term slug.
     * @param int    $depth    Indentation depth.
     */
    private function render_term_options( array $terms, int $parent, string $selected, int $depth = 0 ): void {
        foreach ( $terms as $term ) {
            if ( (int) $term->parent !== $parent ) {
                continue;
            }

            $indent = str_repeat( '&mdash; ', $depth );
            echo '<option value="' . esc_attr( $term->slug ) . '"' . selected( $selected, $term->slug, false ) . '>';
            echo esc_html( $indent . $term->name );
            echo '</option>';

            // Recurse for children.
            $this->render_term_options( $terms, (int) $term->term_id, $selected, $depth + 1 );
        }
    }

    // ── Grid View Filter ──────────────────────────────────────

    /**
     * Filter media grid view by selected folder term.
     * Uses late priority (90) to avoid conflicts with ACF/Elementor.
     *
     * @param array $query Query arguments for wp_query in ajax_query_attachments.
     * @return array
     */
    public function filter_attachments_by_folder( array $query ): array {
        if ( empty( $_REQUEST['wpt_media_folder'] ) ) {
            return $query;
        }

        $folder = sanitize_key( $_REQUEST['wpt_media_folder'] );

        if ( '__uncategorized' === $folder ) {
            // Show media with no folder assigned.
            $query['tax_query'] = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ],
            ];
        } else {
            $query['tax_query'] = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $folder,
                ],
            ];
        }

        return $query;
    }

    // ── AJAX: Create Folder ───────────────────────────────────

    /**
     * Create a new media folder term.
     * Expects: name (string), parent (int, optional).
     */
    public function ajax_create_folder(): void {
        check_ajax_referer( 'wpt_media_folders', '_nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Folder name is required.', 'wptransformed' ) ] );
        }

        $result = wp_insert_term( $name, self::TAXONOMY, [
            'parent' => $parent,
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        $term = get_term( $result['term_id'], self::TAXONOMY );

        wp_send_json_success( [
            'term_id' => $result['term_id'],
            'name'    => $term->name,
            'slug'    => $term->slug,
            'parent'  => $term->parent,
            'count'   => 0,
        ] );
    }

    // ── AJAX: Rename Folder ───────────────────────────────────

    /**
     * Rename an existing media folder term.
     * Expects: term_id (int), name (string).
     */
    public function ajax_rename_folder(): void {
        check_ajax_referer( 'wpt_media_folders', '_nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
        $name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( ! $term_id || empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid folder or name.', 'wptransformed' ) ] );
        }

        $result = wp_update_term( $term_id, self::TAXONOMY, [
            'name' => $name,
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        $term = get_term( $term_id, self::TAXONOMY );

        wp_send_json_success( [
            'term_id' => $term->term_id,
            'name'    => $term->name,
            'slug'    => $term->slug,
        ] );
    }

    // ── AJAX: Delete Folder ───────────────────────────────────

    /**
     * Delete a media folder term. Does NOT delete media items.
     * Expects: term_id (int).
     */
    public function ajax_delete_folder(): void {
        check_ajax_referer( 'wpt_media_folders', '_nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

        if ( ! $term_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid folder.', 'wptransformed' ) ] );
        }

        // Verify the term exists and belongs to our taxonomy.
        $term = get_term( $term_id, self::TAXONOMY );
        if ( ! $term || is_wp_error( $term ) ) {
            wp_send_json_error( [ 'message' => __( 'Folder not found.', 'wptransformed' ) ] );
        }

        $result = wp_delete_term( $term_id, self::TAXONOMY );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'deleted' => $term_id ] );
    }

    // ── AJAX: Move Folder ─────────────────────────────────────

    /**
     * Move a folder by changing its parent.
     * Expects: term_id (int), parent (int).
     */
    public function ajax_move_folder(): void {
        check_ajax_referer( 'wpt_media_folders', '_nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
        $parent  = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;

        if ( ! $term_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid folder.', 'wptransformed' ) ] );
        }

        // Prevent setting a folder as its own parent.
        if ( $term_id === $parent ) {
            wp_send_json_error( [ 'message' => __( 'A folder cannot be its own parent.', 'wptransformed' ) ] );
        }

        // Prevent circular references: parent cannot be a descendant.
        if ( $parent > 0 ) {
            $ancestors = get_ancestors( $parent, self::TAXONOMY, 'taxonomy' );
            if ( in_array( $term_id, $ancestors, true ) ) {
                wp_send_json_error( [ 'message' => __( 'Cannot move a folder into one of its own children.', 'wptransformed' ) ] );
            }
        }

        $result = wp_update_term( $term_id, self::TAXONOMY, [
            'parent' => $parent,
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'term_id' => $term_id,
            'parent'  => $parent,
        ] );
    }

    // ── AJAX: Assign Folder ───────────────────────────────────

    /**
     * Assign one or more attachments to a folder.
     * Expects: attachment_ids (array of int), folder_id (int).
     * folder_id = 0 means remove from all folders (Uncategorized).
     */
    public function ajax_assign_folder(): void {
        check_ajax_referer( 'wpt_media_folders', '_nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) && is_array( $_POST['attachment_ids'] )
            ? array_map( 'absint', $_POST['attachment_ids'] )
            : [];
        $folder_id      = isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0;

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No attachments specified.', 'wptransformed' ) ] );
        }

        // Validate folder exists (if not removing).
        if ( $folder_id > 0 ) {
            $term = get_term( $folder_id, self::TAXONOMY );
            if ( ! $term || is_wp_error( $term ) ) {
                wp_send_json_error( [ 'message' => __( 'Folder not found.', 'wptransformed' ) ] );
            }
        }

        $errors = [];
        foreach ( $attachment_ids as $att_id ) {
            // Verify attachment exists, is an attachment, and user can edit it.
            $post = get_post( $att_id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                $errors[] = $att_id;
                continue;
            }
            if ( ! current_user_can( 'edit_post', $att_id ) ) {
                $errors[] = $att_id;
                continue;
            }

            if ( $folder_id > 0 ) {
                wp_set_object_terms( $att_id, [ $folder_id ], self::TAXONOMY );
            } else {
                // Remove from all folders.
                wp_set_object_terms( $att_id, [], self::TAXONOMY );
            }
        }

        wp_send_json_success( [
            'assigned'  => count( $attachment_ids ) - count( $errors ),
            'folder_id' => $folder_id,
            'errors'    => $errors,
        ] );
    }

    // ── AJAX: Get Folders ─────────────────────────────────────

    /**
     * Return the full folder tree as JSON.
     */
    public function ajax_get_folders(): void {
        check_ajax_referer( 'wpt_media_folders', '_nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $terms = get_terms( [
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( [ 'message' => $terms->get_error_message() ] );
        }

        // Also count uncategorized media.
        $uncategorized_count = $this->count_uncategorized_media();

        $folders = [];
        foreach ( $terms as $term ) {
            $folders[] = [
                'term_id' => (int) $term->term_id,
                'name'    => $term->name,
                'slug'    => $term->slug,
                'parent'  => (int) $term->parent,
                'count'   => (int) $term->count,
            ];
        }

        wp_send_json_success( [
            'folders'              => $folders,
            'uncategorized_count'  => $uncategorized_count,
        ] );
    }

    /**
     * Count media items that have no folder term assigned.
     */
    private function count_uncategorized_media(): int {
        global $wpdb;

        // Efficient COUNT query instead of loading all IDs into memory.
        $taxonomy = self::TAXONOMY;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = %s
             )",
            $taxonomy
        ) );
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Load on media library pages and post editor (for media modal).
        $media_hooks = [ 'upload.php', 'post.php', 'post-new.php' ];

        if ( ! in_array( $hook, $media_hooks, true ) ) {
            return;
        }

        wp_enqueue_style(
            'wpt-media-folders',
            WPT_URL . 'modules/content-management/css/media-folders.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-media-folders',
            WPT_URL . 'modules/content-management/js/media-folders.js',
            [ 'jquery', 'media-views' ],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-media-folders', 'wptMediaFolders', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_media_folders' ),
            'i18n'    => [
                'allMedia'       => __( 'All Media', 'wptransformed' ),
                'uncategorized'  => __( 'Uncategorized', 'wptransformed' ),
                'newFolder'      => __( 'New Folder', 'wptransformed' ),
                'folderName'     => __( 'Folder name', 'wptransformed' ),
                'rename'         => __( 'Rename', 'wptransformed' ),
                'delete'         => __( 'Delete', 'wptransformed' ),
                'moveTo'         => __( 'Move to...', 'wptransformed' ),
                'confirmDelete'  => __( 'Delete this folder? Media items will not be deleted.', 'wptransformed' ),
                'createError'    => __( 'Could not create folder.', 'wptransformed' ),
                'renameError'    => __( 'Could not rename folder.', 'wptransformed' ),
                'deleteError'    => __( 'Could not delete folder.', 'wptransformed' ),
                'moveError'      => __( 'Could not move items.', 'wptransformed' ),
                'dropHint'       => __( 'Drop media here', 'wptransformed' ),
                'folders'        => __( 'Folders', 'wptransformed' ),
            ],
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e( 'Media Folders is active. Use the Media Library to create and manage folders.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'taxonomy', 'taxonomy' => self::TAXONOMY ],
        ];
    }
}
