<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Database Cleanup — Remove bloat from WP database.
 *
 * Cleans: post revisions, auto-drafts, trashed posts, spam comments,
 * trashed comments, expired transients, orphaned postmeta,
 * orphaned commentmeta, orphaned term relationships.
 *
 * Features:
 *  - Dashboard-style scan with counts and size estimates
 *  - Per-category or bulk cleanup
 *  - Batch processing (LIMIT 1000) for large databases
 *  - AJAX polling for progress on large datasets
 *  - Optional table optimization (OPTIMIZE TABLE)
 *
 * @package WPTransformed
 */
class Database_Cleanup extends Module_Base {

    /**
     * Batch size for DELETE queries (prevents timeout on large DBs).
     */
    private const BATCH_SIZE = 1000;

    /**
     * Rough size estimates per row in bytes.
     */
    private const SIZE_ESTIMATES = [
        'revisions'               => 3072,   // ~3KB
        'auto_drafts'             => 3072,
        'trashed_posts'           => 3072,
        'spam_comments'           => 512,    // ~500 bytes
        'trashed_comments'        => 512,
        'expired_transients'      => 512,
        'orphaned_postmeta'       => 200,
        'orphaned_commentmeta'    => 200,
        'orphaned_relationships'  => 200,
    ];

    /**
     * All supported cleanup categories.
     */
    private const CATEGORIES = [
        'revisions',
        'auto_drafts',
        'trashed_posts',
        'spam_comments',
        'trashed_comments',
        'expired_transients',
        'orphaned_postmeta',
        'orphaned_commentmeta',
        'orphaned_relationships',
    ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'database-cleanup';
    }

    public function get_title(): string {
        return __( 'Database Cleanup', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Remove bloat from WP database: post revisions, trashed posts, spam comments, expired transients, orphaned metadata.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'items_to_clean'         => [
                'revisions'              => true,
                'auto_drafts'            => true,
                'trashed_posts'          => true,
                'spam_comments'          => true,
                'trashed_comments'       => true,
                'expired_transients'     => true,
                'orphaned_postmeta'      => true,
                'orphaned_commentmeta'   => true,
                'orphaned_relationships' => true,
            ],
            'keep_recent_revisions'  => 0,
            'optimize_tables'        => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // AJAX handlers.
        add_action( 'wp_ajax_wpt_db_cleanup_scan', [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_wpt_db_cleanup_run',  [ $this, 'ajax_run' ] );
    }

    // ── AJAX: Scan ────────────────────────────────────────────

    /**
     * AJAX handler — scan database and return counts per category.
     */
    public function ajax_scan(): void {
        check_ajax_referer( 'wpt_db_cleanup_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $settings = $this->get_settings();
        $results  = [];

        foreach ( self::CATEGORIES as $category ) {
            $count = $this->get_count( $category, $settings );
            $size  = $count * ( self::SIZE_ESTIMATES[ $category ] ?? 200 );

            $results[ $category ] = [
                'count' => $count,
                'size'  => $size,
                'label' => $this->get_category_label( $category ),
            ];
        }

        wp_send_json_success( [
            'categories' => $results,
            'total_size' => array_sum( array_column( $results, 'size' ) ),
        ] );
    }

    // ── AJAX: Run Cleanup ─────────────────────────────────────

    /**
     * AJAX handler — execute cleanup for specified category or all.
     *
     * Supports batch processing: if more rows remain after LIMIT,
     * returns 'continue' = true so the client can poll again.
     */
    public function ajax_run(): void {
        check_ajax_referer( 'wpt_db_cleanup_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $category = isset( $_POST['category'] ) ? sanitize_key( $_POST['category'] ) : '';

        if ( $category === 'optimize_tables' ) {
            $result = $this->optimize_tables();
            wp_send_json_success( $result );
        }

        if ( $category === 'all' ) {
            $settings     = $this->get_settings();
            $items        = $settings['items_to_clean'] ?? [];
            $total        = 0;
            $has_more     = false;
            $per_category = [];

            foreach ( self::CATEGORIES as $cat ) {
                if ( empty( $items[ $cat ] ) ) {
                    continue;
                }

                $deleted = $this->run_cleanup( $cat, $settings );
                $total  += $deleted;

                $per_category[ $cat ] = $deleted;

                // Check if more remain.
                if ( $deleted >= self::BATCH_SIZE ) {
                    $has_more = true;
                }
            }

            wp_send_json_success( [
                'deleted'      => $total,
                'per_category' => $per_category,
                'continue'     => $has_more,
            ] );
        }

        if ( ! in_array( $category, self::CATEGORIES, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid category.' ] );
        }

        $settings = $this->get_settings();
        $deleted  = $this->run_cleanup( $category, $settings );
        $remaining = $this->get_count( $category, $settings );

        wp_send_json_success( [
            'deleted'   => $deleted,
            'remaining' => $remaining,
            'continue'  => $remaining > 0,
        ] );
    }

    // ── Count Queries ─────────────────────────────────────────

    /**
     * Get count of items for a specific category.
     *
     * @param string               $category Cleanup category.
     * @param array<string,mixed>  $settings Module settings.
     * @return int
     */
    public function get_count( string $category, array $settings ): int {
        global $wpdb;

        $keep_revisions = (int) ( $settings['keep_recent_revisions'] ?? 0 );

        switch ( $category ) {
            case 'revisions':
                if ( $keep_revisions > 0 ) {
                    // Count revisions beyond the most recent N per post.
                    // Use PHP-side approach for MySQL 5.7 compat (avoids correlated subquery).
                    $total = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                        'revision'
                    ) );

                    // Count revisions to keep: N per distinct parent.
                    $parents = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(DISTINCT post_parent) FROM {$wpdb->posts} WHERE post_type = %s",
                        'revision'
                    ) );

                    // Rough estimate: total minus (parents * keep_per_post).
                    $to_keep = $parents * $keep_revisions;
                    return max( 0, $total - $to_keep );
                }
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                    'revision'
                ) );

            case 'auto_drafts':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
                    'auto-draft'
                ) );

            case 'trashed_posts':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
                    'trash'
                ) );

            case 'spam_comments':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
                    'spam'
                ) );

            case 'trashed_comments':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
                    'trash'
                ) );

            case 'expired_transients':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options}
                     WHERE option_name LIKE %s
                     AND option_value < %d",
                    $wpdb->esc_like( '_transient_timeout_' ) . '%',
                    time()
                ) );

            case 'orphaned_postmeta':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                     LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.ID IS NULL"
                );

            case 'orphaned_commentmeta':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm
                     LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                     WHERE c.comment_ID IS NULL"
                );

            case 'orphaned_relationships':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                     LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE p.ID IS NULL"
                );

            default:
                return 0;
        }
    }

    // ── Cleanup Queries ───────────────────────────────────────

    /**
     * Run cleanup for a single category. Returns number of rows deleted.
     *
     * All deletes use LIMIT to prevent timeouts on large databases.
     *
     * @param string               $category Cleanup category.
     * @param array<string,mixed>  $settings Module settings.
     * @return int Number of rows deleted.
     */
    public function run_cleanup( string $category, array $settings ): int {
        global $wpdb;

        $limit          = self::BATCH_SIZE;
        $keep_revisions = (int) ( $settings['keep_recent_revisions'] ?? 0 );

        switch ( $category ) {
            case 'revisions':
                if ( $keep_revisions > 0 ) {
                    // PHP-side approach for MySQL 5.7 compat (no correlated subquery).
                    // Get all post parents that have revisions.
                    $parents = $wpdb->get_col( $wpdb->prepare(
                        "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s",
                        'revision'
                    ) );

                    $ids_to_delete = [];
                    foreach ( $parents as $parent_id ) {
                        // Get IDs to keep (most recent N).
                        $keep_ids = $wpdb->get_col( $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts}
                             WHERE post_type = %s AND post_parent = %d
                             ORDER BY post_date DESC
                             LIMIT %d",
                            'revision',
                            (int) $parent_id,
                            $keep_revisions
                        ) );

                        // Get IDs to delete (all except the kept ones).
                        if ( ! empty( $keep_ids ) ) {
                            $keep_placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
                            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                            $delete_ids = $wpdb->get_col( $wpdb->prepare(
                                "SELECT ID FROM {$wpdb->posts}
                                 WHERE post_type = %s AND post_parent = %d AND ID NOT IN ($keep_placeholders)",
                                array_merge( [ 'revision', (int) $parent_id ], array_map( 'intval', $keep_ids ) )
                            ) );
                        } else {
                            $delete_ids = $wpdb->get_col( $wpdb->prepare(
                                "SELECT ID FROM {$wpdb->posts}
                                 WHERE post_type = %s AND post_parent = %d",
                                'revision',
                                (int) $parent_id
                            ) );
                        }

                        if ( ! empty( $delete_ids ) ) {
                            $ids_to_delete = array_merge( $ids_to_delete, $delete_ids );
                        }

                        // Enforce batch limit.
                        if ( count( $ids_to_delete ) >= $limit ) {
                            $ids_to_delete = array_slice( $ids_to_delete, 0, $limit );
                            break;
                        }
                    }

                    if ( empty( $ids_to_delete ) ) {
                        return 0;
                    }

                    return $this->delete_posts_with_meta( $ids_to_delete );
                }

                // Delete all revisions.
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT %d",
                    'revision',
                    $limit
                ) );

                if ( empty( $ids ) ) {
                    return 0;
                }

                return $this->delete_posts_with_meta( $ids );

            case 'auto_drafts':
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT %d",
                    'auto-draft',
                    $limit
                ) );

                if ( empty( $ids ) ) {
                    return 0;
                }

                return $this->delete_posts_with_meta( $ids );

            case 'trashed_posts':
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT %d",
                    'trash',
                    $limit
                ) );

                if ( empty( $ids ) ) {
                    return 0;
                }

                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

                // Clean meta first, check for errors.
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $meta_result = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                    ...$ids
                ) );
                if ( $meta_result === false ) {
                    return 0; // Abort — don't orphan data further.
                }

                // Clean term relationships.
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)",
                    ...$ids
                ) );

                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $deleted = (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
                    ...$ids
                ) );

                // Invalidate post caches.
                foreach ( $ids as $id ) {
                    clean_post_cache( (int) $id );
                }

                return $deleted;

            case 'spam_comments':
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d",
                    'spam',
                    $limit
                ) );

                if ( empty( $ids ) ) {
                    return 0;
                }

                return $this->delete_comments_with_meta( $ids );

            case 'trashed_comments':
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d",
                    'trash',
                    $limit
                ) );

                if ( empty( $ids ) ) {
                    return 0;
                }

                return $this->delete_comments_with_meta( $ids );

            case 'expired_transients':
                return $this->clean_expired_transients();

            case 'orphaned_postmeta':
                // Fetch IDs first, then delete (LIMIT doesn't work on multi-table DELETE).
                $meta_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT pm.meta_id FROM {$wpdb->postmeta} pm
                     LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.ID IS NULL
                     LIMIT %d",
                    $limit
                ) );

                if ( empty( $meta_ids ) ) {
                    return 0;
                }

                $placeholders = implode( ',', array_fill( 0, count( $meta_ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                return (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($placeholders)",
                    ...$meta_ids
                ) );

            case 'orphaned_commentmeta':
                $meta_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT cm.meta_id FROM {$wpdb->commentmeta} cm
                     LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                     WHERE c.comment_ID IS NULL
                     LIMIT %d",
                    $limit
                ) );

                if ( empty( $meta_ids ) ) {
                    return 0;
                }

                $placeholders = implode( ',', array_fill( 0, count( $meta_ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                return (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->commentmeta} WHERE meta_id IN ($placeholders)",
                    ...$meta_ids
                ) );

            case 'orphaned_relationships':
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $orphan_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT tr.object_id FROM {$wpdb->term_relationships} tr
                     LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE p.ID IS NULL
                     LIMIT %d",
                    $limit
                ) );

                if ( empty( $orphan_ids ) ) {
                    return 0;
                }

                $placeholders = implode( ',', array_fill( 0, count( $orphan_ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                return (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)",
                    ...$orphan_ids
                ) );

            default:
                return 0;
        }
    }

    // ── Shared Delete Helpers ────────────────────────────────

    /**
     * Delete posts and their associated meta, with error checking and cache invalidation.
     *
     * @param array $ids Post IDs to delete.
     * @return int Number of posts deleted.
     */
    private function delete_posts_with_meta( array $ids ): int {
        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Delete meta first — abort if meta delete fails.
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $meta_result = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
            ...$ids
        ) );
        if ( $meta_result === false ) {
            return 0;
        }

        // Delete the posts.
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $deleted = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
            ...$ids
        ) );

        // Invalidate post caches.
        foreach ( $ids as $id ) {
            clean_post_cache( (int) $id );
        }

        return $deleted;
    }

    /**
     * Delete comments and their associated meta, with error checking and cache invalidation.
     *
     * @param array $ids Comment IDs to delete.
     * @return int Number of comments deleted.
     */
    private function delete_comments_with_meta( array $ids ): int {
        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Delete comment meta first — abort if it fails.
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $meta_result = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($placeholders)",
            ...$ids
        ) );
        if ( $meta_result === false ) {
            return 0;
        }

        // Delete the comments.
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $deleted = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($placeholders)",
            ...$ids
        ) );

        // Invalidate comment caches.
        foreach ( $ids as $id ) {
            clean_comment_cache( (int) $id );
        }

        return $deleted;
    }

    // ── Transient Cleanup ─────────────────────────────────────

    /**
     * Clean expired transients: remove timeout rows, then orphaned value rows.
     *
     * @return int Total rows deleted.
     */
    private function clean_expired_transients(): int {
        global $wpdb;

        $deleted = 0;

        // Get expired transient names.
        $expired = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d
             LIMIT %d",
            $wpdb->esc_like( '_transient_timeout_' ) . '%',
            time(),
            self::BATCH_SIZE
        ) );

        if ( empty( $expired ) ) {
            return 0;
        }

        foreach ( $expired as $timeout_key ) {
            // Derive the transient value key from the timeout key.
            $transient_key = str_replace( '_transient_timeout_', '_transient_', $timeout_key );

            // Delete both the timeout and the value entry.
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                $timeout_key
            ) );
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                $transient_key
            ) );
        }

        // Also handle site transients on single-site.
        $expired_site = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d
             LIMIT %d",
            $wpdb->esc_like( '_site_transient_timeout_' ) . '%',
            time(),
            self::BATCH_SIZE
        ) );

        foreach ( $expired_site as $timeout_key ) {
            $transient_key = str_replace( '_site_transient_timeout_', '_site_transient_', $timeout_key );

            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                $timeout_key
            ) );
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                $transient_key
            ) );
        }

        return $deleted;
    }

    // ── Table Optimization ────────────────────────────────────

    /**
     * Run OPTIMIZE TABLE on main WP tables.
     *
     * @return array<string,mixed> Result summary.
     */
    /**
     * Get the list of WP core tables eligible for optimization.
     *
     * @return array<string>
     */
    private function get_optimizable_tables(): array {
        global $wpdb;

        return [
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->comments,
            $wpdb->commentmeta,
            $wpdb->options,
            $wpdb->term_relationships,
            $wpdb->term_taxonomy,
            $wpdb->terms,
            $wpdb->termmeta,
        ];
    }

    /**
     * Optimize one table at a time to avoid WP Engine 60s timeout.
     *
     * Accepts an optional 'table_index' from the AJAX request to process
     * tables one-by-one. Returns 'continue' = true if more tables remain.
     *
     * @return array<string,mixed> Result summary.
     */
    private function optimize_tables(): array {
        global $wpdb;

        $allowed_tables = $this->get_optimizable_tables();
        $table_index    = isset( $_POST['table_index'] ) ? absint( $_POST['table_index'] ) : 0;

        if ( $table_index >= count( $allowed_tables ) ) {
            return [
                'optimized' => 0,
                'continue'  => false,
            ];
        }

        $table = $allowed_tables[ $table_index ];

        // Validate table name is in our allowlist.
        if ( ! in_array( $table, $allowed_tables, true ) ) {
            return [
                'optimized' => 0,
                'continue'  => false,
            ];
        }

        // Verify table exists.
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like( $table )
        ) );

        $optimized = 0;
        if ( $exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "OPTIMIZE TABLE `{$table}`" );
            $optimized = 1;
        }

        $next_index = $table_index + 1;

        return [
            'optimized'   => $optimized,
            'table_index' => $next_index,
            'continue'    => $next_index < count( $allowed_tables ),
        ];
    }

    // ── Category Labels ───────────────────────────────────────

    /**
     * Get human-readable label for a cleanup category.
     *
     * @param string $category Category key.
     * @return string Translated label.
     */
    private function get_category_label( string $category ): string {
        $labels = [
            'revisions'              => __( 'Post Revisions', 'wptransformed' ),
            'auto_drafts'            => __( 'Auto-Drafts', 'wptransformed' ),
            'trashed_posts'          => __( 'Trashed Posts', 'wptransformed' ),
            'spam_comments'          => __( 'Spam Comments', 'wptransformed' ),
            'trashed_comments'       => __( 'Trashed Comments', 'wptransformed' ),
            'expired_transients'     => __( 'Expired Transients', 'wptransformed' ),
            'orphaned_postmeta'      => __( 'Orphaned Post Meta', 'wptransformed' ),
            'orphaned_commentmeta'   => __( 'Orphaned Comment Meta', 'wptransformed' ),
            'orphaned_relationships' => __( 'Orphaned Term Relationships', 'wptransformed' ),
        ];

        return $labels[ $category ] ?? $category;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings       = $this->get_settings();
        $items_to_clean = $settings['items_to_clean'] ?? [];
        $keep_revisions = (int) ( $settings['keep_recent_revisions'] ?? 0 );
        $optimize       = ! empty( $settings['optimize_tables'] );
        ?>

        <div class="wpt-db-cleanup-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'Select the types of data to clean, then use the Scan button to check how much bloat is in your database. You can clean individual categories or everything at once.', 'wptransformed' ); ?>
            </p>
        </div>

        <!-- Cleanup Dashboard (populated by JS) -->
        <div id="wpt-db-cleanup-dashboard" style="margin-bottom: 24px;">
            <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
                <button type="button" class="button button-primary" id="wpt-db-scan-btn">
                    <?php esc_html_e( 'Scan Database', 'wptransformed' ); ?>
                </button>
                <button type="button" class="button" id="wpt-db-clean-all-btn" disabled>
                    <?php esc_html_e( 'Clean All Selected', 'wptransformed' ); ?>
                </button>
            </div>

            <div id="wpt-db-progress" style="display: none;">
                <div class="wpt-db-progress-bar" style="background: #f0f0f0; border-radius: 4px; height: 24px; margin-bottom: 12px; overflow: hidden;">
                    <div class="wpt-db-progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
                </div>
                <p class="wpt-db-progress-text" style="margin: 0; color: #666;"></p>
            </div>

            <table class="widefat fixed" id="wpt-db-results-table" style="display: none;">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e( 'Category', 'wptransformed' ); ?></th>
                        <th style="width: 15%;"><?php esc_html_e( 'Items Found', 'wptransformed' ); ?></th>
                        <th style="width: 20%;"><?php esc_html_e( 'Est. Size', 'wptransformed' ); ?></th>
                        <th style="width: 15%;"><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                        <th style="width: 20%;"><?php esc_html_e( 'Action', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody id="wpt-db-results-body">
                    <!-- Populated by JS -->
                </tbody>
                <tfoot>
                    <tr>
                        <th><strong><?php esc_html_e( 'Total', 'wptransformed' ); ?></strong></th>
                        <th id="wpt-db-total-count">-</th>
                        <th id="wpt-db-total-size">-</th>
                        <th></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>

            <div id="wpt-db-cleanup-results" style="display: none; margin-top: 16px;">
                <div style="background: #e7f5e7; border-left: 4px solid #00a32a; padding: 12px 16px; border-radius: 4px;">
                    <p id="wpt-db-cleanup-summary" style="margin: 0; font-weight: 600;"></p>
                </div>
            </div>
        </div>

        <hr>

        <!-- Settings Form (saved via standard form submit) -->
        <h3 style="margin-top: 16px;"><?php esc_html_e( 'Cleanup Options', 'wptransformed' ); ?></h3>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Items to clean', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php
                        $category_labels = [
                            'revisions'              => __( 'Post Revisions', 'wptransformed' ),
                            'auto_drafts'            => __( 'Auto-Drafts', 'wptransformed' ),
                            'trashed_posts'          => __( 'Trashed Posts', 'wptransformed' ),
                            'spam_comments'          => __( 'Spam Comments', 'wptransformed' ),
                            'trashed_comments'       => __( 'Trashed Comments', 'wptransformed' ),
                            'expired_transients'     => __( 'Expired Transients', 'wptransformed' ),
                            'orphaned_postmeta'      => __( 'Orphaned Post Meta', 'wptransformed' ),
                            'orphaned_commentmeta'   => __( 'Orphaned Comment Meta', 'wptransformed' ),
                            'orphaned_relationships' => __( 'Orphaned Term Relationships', 'wptransformed' ),
                        ];
                        foreach ( $category_labels as $key => $label ) :
                            $checked = ! empty( $items_to_clean[ $key ] );
                        ?>
                            <label style="display: block; margin-bottom: 6px;">
                                <input type="checkbox"
                                       name="wpt_items_to_clean[<?php echo esc_attr( $key ); ?>]"
                                       value="1"
                                       <?php checked( $checked ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt_keep_recent_revisions">
                        <?php esc_html_e( 'Keep recent revisions', 'wptransformed' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number"
                           id="wpt_keep_recent_revisions"
                           name="wpt_keep_recent_revisions"
                           value="<?php echo esc_attr( (string) $keep_revisions ); ?>"
                           min="0"
                           max="100"
                           step="1"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Keep the last N revisions per post. Set to 0 to delete all revisions.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Optimize tables', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_optimize_tables"
                               value="1"
                               <?php checked( $optimize ); ?>>
                        <?php esc_html_e( 'Enable table optimization button', 'wptransformed' ); ?>
                    </label>
                    <div style="background: #fcf9e8; border-left: 4px solid #dba617; padding: 8px 12px; margin-top: 8px; max-width: 600px;">
                        <p style="margin: 0;">
                            <strong><?php esc_html_e( 'Warning:', 'wptransformed' ); ?></strong>
                            <?php esc_html_e( 'OPTIMIZE TABLE on InnoDB recreates the entire table. This can take significant time on large databases and may cause temporary locks. Only run during low-traffic periods.', 'wptransformed' ); ?>
                        </p>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $items_to_clean = [];
        $submitted      = (array) ( $raw['wpt_items_to_clean'] ?? [] );

        foreach ( self::CATEGORIES as $cat ) {
            $items_to_clean[ $cat ] = ! empty( $submitted[ $cat ] );
        }

        $keep_revisions = isset( $raw['wpt_keep_recent_revisions'] )
            ? absint( $raw['wpt_keep_recent_revisions'] )
            : 0;

        if ( $keep_revisions > 100 ) {
            $keep_revisions = 100;
        }

        return [
            'items_to_clean'        => $items_to_clean,
            'keep_recent_revisions' => $keep_revisions,
            'optimize_tables'       => ! empty( $raw['wpt_optimize_tables'] ),
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Only load on the WPTransformed settings page.
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wpt-database-cleanup',
            WPT_URL . 'modules/performance/css/database-cleanup.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-database-cleanup',
            WPT_URL . 'modules/performance/js/database-cleanup.js',
            [],
            WPT_VERSION,
            true
        );

        $settings = $this->get_settings();

        wp_localize_script( 'wpt-database-cleanup', 'wptDbCleanup', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wpt_db_cleanup_nonce' ),
            'optimizeEnabled' => ! empty( $settings['optimize_tables'] ),
            'i18n'           => [
                'scanning'       => __( 'Scanning database...', 'wptransformed' ),
                'cleaning'       => __( 'Cleaning up...', 'wptransformed' ),
                'cleaningBatch'  => __( 'Processing batch, please wait...', 'wptransformed' ),
                'complete'       => __( 'Cleanup complete!', 'wptransformed' ),
                'noItems'        => __( 'No items to clean.', 'wptransformed' ),
                'clean'          => __( 'Clean', 'wptransformed' ),
                'cleaned'        => __( 'Cleaned', 'wptransformed' ),
                'error'          => __( 'An error occurred. Please try again.', 'wptransformed' ),
                'optimizing'     => __( 'Optimizing tables...', 'wptransformed' ),
                'optimized'      => __( 'Tables optimized.', 'wptransformed' ),
                'optimizeBtn'    => __( 'Optimize Tables', 'wptransformed' ),
                'confirmCleanAll' => __( 'Are you sure you want to clean all selected categories? This cannot be undone.', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }

    // ── Helper: Format Bytes (for JS) ─────────────────────────

    /**
     * Get the size estimates constant for external use (e.g., tests).
     *
     * @return array<string,int>
     */
    public function get_size_estimates(): array {
        return self::SIZE_ESTIMATES;
    }

    /**
     * Get the batch size constant for external use (e.g., tests).
     *
     * @return int
     */
    public function get_batch_size(): int {
        return self::BATCH_SIZE;
    }
}
