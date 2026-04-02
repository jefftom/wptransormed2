<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Revision Control — Limit or disable post revisions per post type,
 * with a bulk purge tool for old revisions.
 *
 * @package WPTransformed
 */
class Revision_Control extends Module_Base {

    /**
     * Batch size for purge DELETE queries (prevents timeout).
     */
    private const BATCH_SIZE = 100;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'revision-control';
    }

    public function get_title(): string {
        return __( 'Revision Control', 'wptransformed' );
    }

    public function get_category(): string {
        return 'performance';
    }

    public function get_description(): string {
        return __( 'Limit or disable post revisions per post type to reduce database bloat. Includes a bulk purge tool.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'max_revisions'  => 5,
            'per_post_type'  => [
                'post' => 5,
                'page' => 3,
            ],
            'disable_for'    => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_filter( 'wp_revisions_to_keep', [ $this, 'filter_revisions_to_keep' ], 10, 2 );
        add_action( 'wp_ajax_wpt_purge_revisions', [ $this, 'ajax_purge_revisions' ] );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Filter the number of revisions to keep for a given post.
     *
     * @param int      $num  Number of revisions to keep.
     * @param \WP_Post $post The post object.
     * @return int
     */
    public function filter_revisions_to_keep( int $num, \WP_Post $post ): int {
        $settings  = $this->get_settings();
        $post_type = $post->post_type;

        // Disabled types get zero revisions.
        $disable_for = $settings['disable_for'] ?? [];
        if ( is_array( $disable_for ) && in_array( $post_type, $disable_for, true ) ) {
            return 0;
        }

        // Per-post-type override.
        $per_post_type = $settings['per_post_type'] ?? [];
        if ( is_array( $per_post_type ) && isset( $per_post_type[ $post_type ] ) ) {
            return max( 0, (int) $per_post_type[ $post_type ] );
        }

        // Global default.
        return max( 0, (int) ( $settings['max_revisions'] ?? 5 ) );
    }

    // ── AJAX: Purge ───────────────────────────────────────────

    /**
     * AJAX handler — purge old revisions in batches.
     */
    public function ajax_purge_revisions(): void {
        check_ajax_referer( 'wpt_purge_revisions_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        global $wpdb;

        $start_time    = time();
        $timeout        = 55; // Stay well under 60s.
        $total_deleted  = 0;

        do {
            // Find revision IDs in batches.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $revision_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT %d",
                    'revision',
                    self::BATCH_SIZE
                )
            );

            if ( empty( $revision_ids ) ) {
                break;
            }

            foreach ( $revision_ids as $rev_id ) {
                wp_delete_post_revision( (int) $rev_id );
                $total_deleted++;
            }

            // Check if we're running out of time.
            if ( ( time() - $start_time ) >= $timeout ) {
                // More revisions may remain — tell the client.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $remaining = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                        'revision'
                    )
                );

                wp_send_json_success( [
                    'deleted'   => $total_deleted,
                    'remaining' => $remaining,
                    'complete'  => false,
                ] );
            }
        } while ( ! empty( $revision_ids ) );

        wp_send_json_success( [
            'deleted'   => $total_deleted,
            'remaining' => 0,
            'complete'  => true,
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings      = $this->get_settings();
        $max_revisions = (int) ( $settings['max_revisions'] ?? 5 );
        $per_post_type = $settings['per_post_type'] ?? [];
        $disable_for   = $settings['disable_for'] ?? [];

        if ( ! is_array( $per_post_type ) ) {
            $per_post_type = [];
        }
        if ( ! is_array( $disable_for ) ) {
            $disable_for = [];
        }

        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        // Count current revisions for the purge summary.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $revision_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                'revision'
            )
        );
        ?>

        <div class="wpt-revision-explainer" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <?php esc_html_e( 'WordPress saves a new revision every time you update a post. Over time this can bloat your database. Use these settings to limit how many revisions are kept per post type, or disable them entirely for certain types.', 'wptransformed' ); ?>
            </p>
        </div>

        <!-- Global Default -->
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_max_revisions"><?php esc_html_e( 'Default Max Revisions', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="wpt_max_revisions"
                           name="wpt_max_revisions"
                           value="<?php echo esc_attr( (string) $max_revisions ); ?>"
                           min="0"
                           max="100"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Default limit for post types without a specific override. Set to 0 to disable revisions globally.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <!-- Per Post Type -->
        <h3><?php esc_html_e( 'Per Post Type Limits', 'wptransformed' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Override the default limit for specific post types. Leave blank to use the global default. Check "Disable" to turn off revisions entirely for that type.', 'wptransformed' ); ?>
        </p>

        <table class="widefat striped" style="max-width: 600px; margin-top: 12px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Post Type', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Max Revisions', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Disable', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $post_types as $pt ) : ?>
                    <?php
                    $pt_slug      = $pt->name;
                    $pt_limit     = $per_post_type[ $pt_slug ] ?? '';
                    $is_disabled  = in_array( $pt_slug, $disable_for, true );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $pt->labels->singular_name ); ?></td>
                        <td>
                            <input type="number"
                                   name="wpt_per_post_type[<?php echo esc_attr( $pt_slug ); ?>]"
                                   value="<?php echo esc_attr( (string) $pt_limit ); ?>"
                                   min="0"
                                   max="100"
                                   class="small-text"
                                   placeholder="<?php echo esc_attr( (string) $max_revisions ); ?>"
                                   <?php echo $is_disabled ? 'disabled' : ''; ?>>
                        </td>
                        <td>
                            <input type="checkbox"
                                   name="wpt_disable_for[]"
                                   value="<?php echo esc_attr( $pt_slug ); ?>"
                                   <?php checked( $is_disabled ); ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Purge Tool -->
        <h3 style="margin-top: 24px;"><?php esc_html_e( 'Purge Old Revisions', 'wptransformed' ); ?></h3>
        <div class="wpt-purge-section" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 16px; max-width: 600px;">
            <p>
                <?php
                printf(
                    /* translators: %s: number of revisions */
                    esc_html__( 'Your database currently contains %s revision(s).', 'wptransformed' ),
                    '<strong>' . esc_html( number_format_i18n( $revision_count ) ) . '</strong>'
                );
                ?>
            </p>
            <button type="button"
                    id="wpt-purge-revisions-btn"
                    class="button button-secondary"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpt_purge_revisions_nonce' ) ); ?>"
                    <?php echo $revision_count === 0 ? 'disabled' : ''; ?>>
                <?php esc_html_e( 'Purge All Revisions', 'wptransformed' ); ?>
            </button>
            <span id="wpt-purge-status" style="margin-left: 12px;"></span>
        </div>

        <script>
        (function() {
            var btn = document.getElementById('wpt-purge-revisions-btn');
            var status = document.getElementById('wpt-purge-status');
            if (!btn) return;

            btn.addEventListener('click', function() {
                if (!confirm(<?php echo wp_json_encode( __( 'This will permanently delete ALL revisions from the database. This cannot be undone. Continue?', 'wptransformed' ) ); ?>)) {
                    return;
                }

                btn.disabled = true;
                status.textContent = <?php echo wp_json_encode( __( 'Purging...', 'wptransformed' ) ); ?>;
                doPurge();
            });

            function doPurge() {
                var formData = new FormData();
                formData.append('action', 'wpt_purge_revisions');
                formData.append('nonce', btn.getAttribute('data-nonce'));

                fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) {
                            status.textContent = resp.data && resp.data.message ? resp.data.message : <?php echo wp_json_encode( __( 'Error during purge.', 'wptransformed' ) ); ?>;
                            btn.disabled = false;
                            return;
                        }

                        if (resp.data.complete) {
                            status.textContent = <?php echo wp_json_encode( __( 'Done! Deleted ', 'wptransformed' ) ); ?> + resp.data.deleted + <?php echo wp_json_encode( __( ' revision(s).', 'wptransformed' ) ); ?>;
                        } else {
                            status.textContent = <?php echo wp_json_encode( __( 'Deleted ', 'wptransformed' ) ); ?> + resp.data.deleted + <?php echo wp_json_encode( __( ' so far, ', 'wptransformed' ) ); ?> + resp.data.remaining + <?php echo wp_json_encode( __( ' remaining...', 'wptransformed' ) ); ?>;
                            doPurge();
                        }
                    })
                    .catch(function() {
                        status.textContent = <?php echo wp_json_encode( __( 'Network error. Please try again.', 'wptransformed' ) ); ?>;
                        btn.disabled = false;
                    });
            }
        })();
        </script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $clean = [];

        // Global max.
        $clean['max_revisions'] = isset( $raw['wpt_max_revisions'] )
            ? max( 0, min( 100, (int) $raw['wpt_max_revisions'] ) )
            : 5;

        // Disabled post types.
        $disable_for = $raw['wpt_disable_for'] ?? [];
        if ( ! is_array( $disable_for ) ) {
            $disable_for = [];
        }
        $valid_types = array_keys( get_post_types( [ 'public' => true ] ) );
        $clean['disable_for'] = array_values( array_intersect( $disable_for, $valid_types ) );

        // Per post type limits.
        $per_post_type_raw = $raw['wpt_per_post_type'] ?? [];
        if ( ! is_array( $per_post_type_raw ) ) {
            $per_post_type_raw = [];
        }
        $clean['per_post_type'] = [];
        foreach ( $per_post_type_raw as $pt_slug => $limit ) {
            if ( ! in_array( $pt_slug, $valid_types, true ) ) {
                continue;
            }
            // Skip disabled types and empty values.
            if ( in_array( $pt_slug, $clean['disable_for'], true ) ) {
                continue;
            }
            if ( $limit === '' || $limit === null ) {
                continue;
            }
            $clean['per_post_type'][ $pt_slug ] = max( 0, min( 100, (int) $limit ) );
        }

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Inline JS is rendered in render_settings(). No external assets.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
