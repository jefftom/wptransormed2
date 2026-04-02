<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Broken Link Checker -- Background scan content for broken links.
 *
 * Features:
 *  - Extracts links from post_content (<a href> and <img src>)
 *  - WP-Cron batch processing with rate limiting per domain
 *  - Dashboard widget showing broken link count and last scan date
 *  - Fix actions: Edit, Unlink, Redirect (via Redirect Manager), Dismiss
 *  - Email notification after scan if new broken links found
 *  - Configurable scan schedule (daily, weekly, monthly, off)
 *  - Skips mailto:, tel:, anchor-only, javascript: links
 *  - Falls back to GET on 403/405 HEAD responses
 *
 * @package WPTransformed
 */
class Broken_Link_Checker extends Module_Base {

    /**
     * DB version option key -- uses get_option() for WP Engine compat.
     */
    private const DB_VERSION_KEY = 'wpt_broken_link_checker_db_version';

    /**
     * Current DB schema version.
     */
    private const DB_VERSION = '1.0';

    /**
     * Cron hook name.
     */
    private const CRON_HOOK = 'wpt_check_links';

    /**
     * Number of URLs to check per cron batch.
     */
    private const BATCH_SIZE = 20;

    /**
     * Max requests per domain per batch for rate limiting.
     */
    private const MAX_PER_DOMAIN = 2;

    /**
     * WHERE clause for broken/errored links (not dismissed, already checked).
     */
    private const BROKEN_WHERE = 'is_dismissed = 0 AND (status_code >= 400 OR status_code = 0) AND last_checked IS NOT NULL';

    /**
     * Get the link_checks table name.
     */
    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wpt_link_checks';
    }

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'broken-link-checker';
    }

    public function get_title(): string {
        return __( 'Broken Link Checker', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Background scan content for broken internal and external links with one-click fix options.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'scan_schedule'      => 'weekly',
            'scan_internal'      => true,
            'scan_external'      => true,
            'scan_images'        => true,
            'timeout'            => 10,
            'max_concurrent'     => 5,
            'exclude_domains'    => [],
            'notify_email'       => '',
            'check_post_types'   => [ 'post', 'page' ],
            'recheck_broken_days' => 3,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $this->maybe_create_tables();

        // Cron handler.
        add_action( self::CRON_HOOK, [ $this, 'process_link_batch' ] );

        // Schedule cron if not already set.
        $this->maybe_schedule_cron();

        // Dashboard widget.
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_blc_scan_now',   [ $this, 'ajax_scan_now' ] );
        add_action( 'wp_ajax_wpt_blc_unlink',      [ $this, 'ajax_unlink' ] );
        add_action( 'wp_ajax_wpt_blc_dismiss',     [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_wpt_blc_get_results', [ $this, 'ajax_get_results' ] );
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    // ── Table Creation ────────────────────────────────────────

    /**
     * Create custom table if needed. Uses get_option() for WP Engine compat.
     */
    private function maybe_create_tables(): void {
        $installed_version = get_option( self::DB_VERSION_KEY, '' );

        if ( $installed_version === self::DB_VERSION ) {
            return;
        }

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $this->table();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            url VARCHAR(2048) NOT NULL,
            url_hash VARCHAR(64) NOT NULL,
            status_code SMALLINT,
            status_text VARCHAR(255),
            link_type VARCHAR(20) DEFAULT 'external',
            found_in_post BIGINT UNSIGNED,
            found_in_field VARCHAR(100),
            anchor_text VARCHAR(500),
            first_found DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_checked DATETIME,
            last_status_change DATETIME,
            is_dismissed TINYINT(1) DEFAULT 0,
            INDEX idx_hash (url_hash),
            INDEX idx_status (status_code),
            INDEX idx_post (found_in_post),
            INDEX idx_checked (last_checked)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION, true );
    }

    // ── Cron Scheduling ───────────────────────────────────────

    /**
     * Schedule the link check cron if not already scheduled.
     */
    private function maybe_schedule_cron(): void {
        $settings = $this->get_settings();
        $schedule = $settings['scan_schedule'];

        if ( $schedule === 'off' ) {
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK );
            }
            return;
        }

        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        $recurrence = 'weekly';
        if ( $schedule === 'daily' ) {
            $recurrence = 'daily';
        } elseif ( $schedule === 'monthly' ) {
            // WP does not have a built-in monthly schedule; use weekly and check internally.
            $recurrence = 'weekly';
        }

        wp_schedule_event( time(), $recurrence, self::CRON_HOOK );
    }

    // ── Link Extraction ───────────────────────────────────────

    /**
     * Extract all links from a post's content.
     *
     * @param int    $post_id      Post ID.
     * @param string $post_content Post content HTML.
     * @return array Array of [ 'url' => string, 'type' => string, 'anchor' => string, 'field' => string ].
     */
    private function extract_links( int $post_id, string $post_content ): array {
        $links    = [];
        $settings = $this->get_settings();
        $site_url = site_url();

        // Extract <a href="..."> links.
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $post_content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $url    = trim( $match[1] );
                $anchor = wp_strip_all_tags( $match[2] );

                if ( $this->should_skip_url( $url ) ) {
                    continue;
                }

                $url  = $this->resolve_url( $url, $site_url );
                $type = $this->classify_link( $url, $site_url );

                if ( $type === 'internal' && empty( $settings['scan_internal'] ) ) {
                    continue;
                }
                if ( $type === 'external' && empty( $settings['scan_external'] ) ) {
                    continue;
                }

                $links[] = [
                    'url'    => $url,
                    'type'   => $type,
                    'anchor' => mb_substr( $anchor, 0, 500 ),
                    'field'  => 'post_content',
                ];
            }
        }

        // Extract <img src="..."> links.
        if ( ! empty( $settings['scan_images'] ) ) {
            if ( preg_match_all( '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*/is', $post_content, $img_matches, PREG_SET_ORDER ) ) {
                foreach ( $img_matches as $match ) {
                    $url = trim( $match[1] );

                    if ( $this->should_skip_url( $url ) ) {
                        continue;
                    }

                    $url = $this->resolve_url( $url, $site_url );

                    $links[] = [
                        'url'    => $url,
                        'type'   => 'image',
                        'anchor' => '',
                        'field'  => 'post_content',
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * Check if a URL should be skipped.
     *
     * @param string $url URL to check.
     * @return bool
     */
    private function should_skip_url( string $url ): bool {
        $url_lower = strtolower( trim( $url ) );

        // Skip empty, anchors, mailto, tel, javascript.
        if ( empty( $url_lower ) ) {
            return true;
        }
        if ( strpos( $url_lower, '#' ) === 0 ) {
            return true;
        }
        if ( strpos( $url_lower, 'mailto:' ) === 0 ) {
            return true;
        }
        if ( strpos( $url_lower, 'tel:' ) === 0 ) {
            return true;
        }
        if ( strpos( $url_lower, 'javascript:' ) === 0 ) {
            return true;
        }
        // Skip data: URIs.
        if ( strpos( $url_lower, 'data:' ) === 0 ) {
            return true;
        }

        return false;
    }

    /**
     * Resolve relative URLs against site URL.
     *
     * @param string $url      URL to resolve.
     * @param string $site_url Site URL.
     * @return string Resolved URL.
     */
    private function resolve_url( string $url, string $site_url ): string {
        // Already absolute.
        if ( preg_match( '#^https?://#i', $url ) ) {
            return $url;
        }

        // Protocol-relative.
        if ( strpos( $url, '//' ) === 0 ) {
            return 'https:' . $url;
        }

        // Relative URL -- resolve against site_url.
        return rtrim( $site_url, '/' ) . '/' . ltrim( $url, '/' );
    }

    /**
     * Classify a link as internal or external.
     *
     * @param string $url      URL to classify.
     * @param string $site_url Site URL.
     * @return string 'internal' or 'external'.
     */
    private function classify_link( string $url, string $site_url ): string {
        $site_host = wp_parse_url( $site_url, PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );

        if ( $url_host && $site_host && strtolower( $url_host ) === strtolower( $site_host ) ) {
            return 'internal';
        }

        return 'external';
    }

    // ── URL Checking ──────────────────────────────────────────

    /**
     * Check a single URL and return status information.
     *
     * @param string $url     URL to check.
     * @param int    $timeout Timeout in seconds.
     * @return array [ 'status_code' => int, 'status_text' => string ].
     */
    private function check_url( string $url, int $timeout ): array {
        // Try HEAD first.
        $response = wp_remote_head( $url, [
            'timeout'     => $timeout,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => 'WPTransformed Broken Link Checker',
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'status_code' => 0,
                'status_text' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        // Fall back to GET on 403/405 (some servers block HEAD).
        if ( in_array( $code, [ 403, 405 ], true ) ) {
            $response = wp_remote_get( $url, [
                'timeout'     => $timeout,
                'redirection' => 5,
                'sslverify'   => false,
                'user-agent'  => 'WPTransformed Broken Link Checker',
                'headers'     => [ 'Range' => 'bytes=0-1024' ],
            ] );

            if ( is_wp_error( $response ) ) {
                return [
                    'status_code' => 0,
                    'status_text' => $response->get_error_message(),
                ];
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
        }

        return [
            'status_code' => $code,
            'status_text' => wp_remote_retrieve_response_message( $response ),
        ];
    }

    // ── Batch Processing ──────────────────────────────────────

    /**
     * Process a batch of URLs via WP-Cron.
     */
    public function process_link_batch(): void {
        $settings = $this->get_settings();

        $this->extract_links_from_posts();
        $this->check_pending_urls( $settings );
        $this->maybe_send_notification( $settings );
    }

    /**
     * Extract links from published posts and store in the link_checks table.
     */
    private function extract_links_from_posts(): void {
        global $wpdb;

        $settings   = $this->get_settings();
        $post_types = $settings['check_post_types'];

        if ( empty( $post_types ) || ! is_array( $post_types ) ) {
            return;
        }

        $table = $this->table();

        // Get post types as placeholders.
        $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

        // Only scan posts not yet in the link_checks table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_content FROM {$wpdb->posts} AS p
                 LEFT JOIN {$table} AS lc ON lc.found_in_post = p.ID
                 WHERE p.post_status = 'publish'
                   AND p.post_type IN ({$placeholders})
                   AND lc.id IS NULL
                 ORDER BY p.ID ASC
                 LIMIT 50",
                ...$post_types
            )
        );

        if ( empty( $posts ) ) {
            return;
        }

        foreach ( $posts as $post ) {
            $links = $this->extract_links( (int) $post->ID, $post->post_content );

            foreach ( $links as $link ) {
                $url_hash = hash( 'sha256', $link['url'] );

                // Check if this URL+post combo already exists.
                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE url_hash = %s AND found_in_post = %d LIMIT 1",
                        $url_hash,
                        (int) $post->ID
                    )
                );

                if ( $exists ) {
                    continue;
                }

                // Check if domain is excluded.
                if ( $this->is_excluded_domain( $link['url'], $settings ) ) {
                    continue;
                }

                $wpdb->insert(
                    $table,
                    [
                        'url'           => mb_substr( $link['url'], 0, 2048 ),
                        'url_hash'      => $url_hash,
                        'link_type'     => $link['type'],
                        'found_in_post' => (int) $post->ID,
                        'found_in_field' => $link['field'],
                        'anchor_text'   => $link['anchor'],
                        'first_found'   => current_time( 'mysql', true ),
                    ],
                    [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
                );
            }
        }
    }

    /**
     * Check pending (unchecked or stale) URLs.
     *
     * @param array $settings Module settings.
     */
    private function check_pending_urls( array $settings ): void {
        global $wpdb;

        $table    = $this->table();
        $timeout  = max( 1, (int) $settings['timeout'] );
        $recheck  = max( 1, (int) $settings['recheck_broken_days'] );

        // Get unchecked URLs first, then stale broken URLs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url, url_hash, status_code FROM {$table}
                 WHERE is_dismissed = 0
                   AND (
                       last_checked IS NULL
                       OR (status_code >= 400 AND last_checked < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))
                       OR (status_code = 0 AND last_checked < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))
                   )
                 ORDER BY last_checked ASC
                 LIMIT %d",
                $recheck,
                $recheck,
                self::BATCH_SIZE
            )
        );

        if ( empty( $rows ) ) {
            return;
        }

        $domain_counts = [];

        foreach ( $rows as $row ) {
            $host = wp_parse_url( $row->url, PHP_URL_HOST );

            if ( $host ) {
                $host = strtolower( $host );

                if ( ! isset( $domain_counts[ $host ] ) ) {
                    $domain_counts[ $host ] = 0;
                }

                // Rate limit: max requests per domain per batch.
                if ( $domain_counts[ $host ] >= self::MAX_PER_DOMAIN ) {
                    continue;
                }

                $domain_counts[ $host ]++;
            }

            $result   = $this->check_url( $row->url, $timeout );
            $old_code = (int) $row->status_code;
            $new_code = $result['status_code'];

            $update_data   = [
                'status_code'  => $new_code,
                'status_text'  => mb_substr( $result['status_text'], 0, 255 ),
                'last_checked' => current_time( 'mysql', true ),
            ];
            $update_format = [ '%d', '%s', '%s' ];

            if ( $old_code !== $new_code ) {
                $update_data['last_status_change'] = current_time( 'mysql', true );
                $update_format[]                   = '%s';
            }

            $wpdb->update(
                $table,
                $update_data,
                [ 'id' => (int) $row->id ],
                $update_format,
                [ '%d' ]
            );
        }
    }

    /**
     * Check if a URL's domain is in the exclude list.
     *
     * @param string $url      URL to check.
     * @param array  $settings Module settings.
     * @return bool
     */
    private function is_excluded_domain( string $url, array $settings ): bool {
        if ( empty( $settings['exclude_domains'] ) || ! is_array( $settings['exclude_domains'] ) ) {
            return false;
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );

        if ( ! $host ) {
            return false;
        }

        $host = strtolower( $host );

        foreach ( $settings['exclude_domains'] as $excluded ) {
            if ( strtolower( trim( $excluded ) ) === $host ) {
                return true;
            }
        }

        return false;
    }

    // ── Email Notification ────────────────────────────────────

    /**
     * Send email notification if new broken links were found in this batch.
     *
     * @param array $settings Module settings.
     */
    private function maybe_send_notification( array $settings ): void {
        $email = trim( $settings['notify_email'] );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        global $wpdb;

        $table = $this->table();

        // Count broken links found/changed in the last hour (approximate scan window).
        $new_broken = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE is_dismissed = 0
               AND status_code >= 400
               AND last_status_change >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
        );

        if ( $new_broken < 1 ) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: number of broken links */
            __( '[%1$s] %2$d new broken link(s) found', 'wptransformed' ),
            get_bloginfo( 'name' ),
            $new_broken
        );

        $body = sprintf(
            /* translators: 1: count, 2: admin URL */
            __( "WPTransformed Broken Link Checker found %1\$d new broken link(s) on your site.\n\nView details: %2\$s", 'wptransformed' ),
            $new_broken,
            admin_url( 'admin.php?page=wptransformed&module=broken-link-checker' )
        );

        wp_mail( $email, $subject, $body );
    }

    // ── Dashboard Widget ──────────────────────────────────────

    /**
     * Register the dashboard widget.
     */
    public function register_dashboard_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'wpt_broken_links_widget',
            __( 'Broken Links', 'wptransformed' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }

    /**
     * Render the dashboard widget content.
     */
    public function render_dashboard_widget(): void {
        global $wpdb;

        $table = $this->table();

        $broken_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE " . self::BROKEN_WHERE
        );

        $last_checked = $wpdb->get_var(
            "SELECT MAX(last_checked) FROM {$table}"
        );

        $total_links = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}"
        );

        ?>
        <div class="wpt-blc-widget">
            <div class="wpt-blc-widget-stats">
                <div class="wpt-blc-stat">
                    <span class="wpt-blc-stat-number <?php echo $broken_count > 0 ? 'wpt-blc-broken' : 'wpt-blc-ok'; ?>">
                        <?php echo esc_html( number_format_i18n( $broken_count ) ); ?>
                    </span>
                    <span class="wpt-blc-stat-label"><?php esc_html_e( 'Broken Links', 'wptransformed' ); ?></span>
                </div>
                <div class="wpt-blc-stat">
                    <span class="wpt-blc-stat-number"><?php echo esc_html( number_format_i18n( $total_links ) ); ?></span>
                    <span class="wpt-blc-stat-label"><?php esc_html_e( 'Total Tracked', 'wptransformed' ); ?></span>
                </div>
            </div>
            <?php if ( $last_checked ) : ?>
            <p class="wpt-blc-widget-last-scan">
                <?php
                printf(
                    /* translators: %s: date/time of last scan */
                    esc_html__( 'Last scan: %s', 'wptransformed' ),
                    esc_html( $last_checked )
                );
                ?>
            </p>
            <?php else : ?>
            <p class="wpt-blc-widget-last-scan">
                <?php esc_html_e( 'No scan has been run yet.', 'wptransformed' ); ?>
            </p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed&module=broken-link-checker' ) ); ?>" class="button button-small">
                    <?php esc_html_e( 'View All', 'wptransformed' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // ── AJAX: Scan Now ────────────────────────────────────────

    /**
     * Trigger a manual scan via AJAX.
     */
    public function ajax_scan_now(): void {
        check_ajax_referer( 'wpt_broken_link_checker_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        // Run a batch immediately.
        $this->process_link_batch();

        // Return updated stats.
        global $wpdb;

        $table = $this->table();

        $broken_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE " . self::BROKEN_WHERE
        );

        $total_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}"
        );

        wp_send_json_success( [
            'message'      => __( 'Scan batch completed.', 'wptransformed' ),
            'broken_count' => $broken_count,
            'total_count'  => $total_count,
        ] );
    }

    // ── AJAX: Unlink ──────────────────────────────────────────

    /**
     * Remove a link from post content (keep anchor text).
     */
    public function ajax_unlink(): void {
        check_ajax_referer( 'wpt_broken_link_checker_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $link_id = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;

        if ( $link_id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid link ID.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $this->table();

        $link = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT url, found_in_post FROM {$table} WHERE id = %d",
                $link_id
            )
        );

        if ( ! $link ) {
            wp_send_json_error( [ 'message' => __( 'Link not found.', 'wptransformed' ) ] );
        }

        $post = get_post( (int) $link->found_in_post );

        if ( ! $post ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'wptransformed' ) ] );
        }

        // Strip <a> tags for this URL but preserve inner text.
        $escaped_url = preg_quote( $link->url, '/' );
        $pattern     = '/<a\s[^>]*href=["\']' . $escaped_url . '["\'][^>]*>(.*?)<\/a>/is';
        $new_content = preg_replace( $pattern, '$1', $post->post_content );

        if ( $new_content === null || $new_content === $post->post_content ) {
            wp_send_json_error( [ 'message' => __( 'Could not find the link in post content.', 'wptransformed' ) ] );
        }

        wp_update_post( [
            'ID'           => $post->ID,
            'post_content' => $new_content,
        ] );

        // Remove from tracking table.
        $wpdb->delete( $table, [ 'id' => $link_id ], [ '%d' ] );

        wp_send_json_success( [
            'message' => __( 'Link removed from post content.', 'wptransformed' ),
        ] );
    }

    // ── AJAX: Dismiss ─────────────────────────────────────────

    /**
     * Dismiss a broken link (hide from results but keep tracking).
     */
    public function ajax_dismiss(): void {
        check_ajax_referer( 'wpt_broken_link_checker_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $link_id = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;

        if ( $link_id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid link ID.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $this->table();

        $updated = $wpdb->update(
            $table,
            [ 'is_dismissed' => 1 ],
            [ 'id' => $link_id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to dismiss link.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => __( 'Link dismissed.', 'wptransformed' ),
        ] );
    }

    // ── AJAX: Get Results ─────────────────────────────────────

    /**
     * Get paginated link check results via AJAX.
     */
    public function ajax_get_results(): void {
        check_ajax_referer( 'wpt_broken_link_checker_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
        $per_page = 20;
        $offset   = ( $page - 1 ) * $per_page;
        $filter   = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'broken';

        global $wpdb;

        $table = $this->table();

        $where = 'WHERE is_dismissed = 0 AND last_checked IS NOT NULL';

        if ( $filter === 'broken' ) {
            $where .= ' AND (status_code >= 400 OR status_code = 0)';
        } elseif ( $filter === 'redirects' ) {
            $where .= ' AND status_code >= 300 AND status_code < 400';
        }
        // 'all' uses no additional filter.

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT lc.*, p.post_title
                 FROM {$table} AS lc
                 LEFT JOIN {$wpdb->posts} AS p ON lc.found_in_post = p.ID
                 {$where}
                 ORDER BY lc.status_code ASC, lc.last_checked DESC
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        wp_send_json_success( [
            'results' => $results ? $results : [],
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int) ceil( $total / $per_page ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        global $wpdb;

        $table = $this->table();

        // Single query for all summary stats.
        $stats = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN " . self::BROKEN_WHERE . " THEN 1 ELSE 0 END) AS broken_count,
                SUM(CASE WHEN is_dismissed = 0 AND status_code >= 300 AND status_code < 400 AND last_checked IS NOT NULL THEN 1 ELSE 0 END) AS redirect_count,
                SUM(CASE WHEN last_checked IS NOT NULL THEN 1 ELSE 0 END) AS total_count,
                MAX(last_checked) AS last_checked
             FROM {$table}"
        );

        $broken_count   = (int) ( $stats->broken_count ?? 0 );
        $redirect_count = (int) ( $stats->redirect_count ?? 0 );
        $total_count    = (int) ( $stats->total_count ?? 0 );
        $last_checked   = $stats->last_checked ?? null;

        // Broken links for the table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $broken_links = $wpdb->get_results(
            "SELECT lc.*, p.post_title
             FROM {$table} AS lc
             LEFT JOIN {$wpdb->posts} AS p ON lc.found_in_post = p.ID
             WHERE lc.is_dismissed = 0
               AND (lc.status_code >= 400 OR lc.status_code = 0)
               AND lc.last_checked IS NOT NULL
             ORDER BY lc.status_code ASC, lc.last_checked DESC
             LIMIT 50",
            ARRAY_A
        );

        if ( ! is_array( $broken_links ) ) {
            $broken_links = [];
        }

        // Check if Redirect Manager is active.
        $redirect_manager_active = false;
        if ( class_exists( '\WPTransformed\Core\Module_Registry' ) ) {
            $redirect_manager_active = \WPTransformed\Core\Module_Registry::is_active( 'redirect-manager' );
        }

        $available_post_types = get_post_types( [ 'public' => true ], 'objects' );
        ?>

        <!-- Summary -->
        <div class="wpt-blc-summary">
            <div class="wpt-blc-summary-card wpt-blc-card-broken">
                <span class="wpt-blc-card-number"><?php echo esc_html( number_format_i18n( $broken_count ) ); ?></span>
                <span class="wpt-blc-card-label"><?php esc_html_e( 'Broken', 'wptransformed' ); ?></span>
            </div>
            <div class="wpt-blc-summary-card wpt-blc-card-redirect">
                <span class="wpt-blc-card-number"><?php echo esc_html( number_format_i18n( $redirect_count ) ); ?></span>
                <span class="wpt-blc-card-label"><?php esc_html_e( 'Redirects', 'wptransformed' ); ?></span>
            </div>
            <div class="wpt-blc-summary-card">
                <span class="wpt-blc-card-number"><?php echo esc_html( number_format_i18n( $total_count ) ); ?></span>
                <span class="wpt-blc-card-label"><?php esc_html_e( 'Total Checked', 'wptransformed' ); ?></span>
            </div>
            <div class="wpt-blc-summary-actions">
                <button type="button" class="button button-primary" id="wpt-blc-scan-now">
                    <?php esc_html_e( 'Scan Now', 'wptransformed' ); ?>
                </button>
                <span class="spinner" id="wpt-blc-scan-spinner"></span>
                <?php if ( $last_checked ) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: date/time */
                        esc_html__( 'Last scan: %s', 'wptransformed' ),
                        esc_html( $last_checked )
                    );
                    ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settings -->
        <h3><?php esc_html_e( 'Settings', 'wptransformed' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-blc-schedule"><?php esc_html_e( 'Scan Schedule', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-blc-schedule" name="wpt_blc_scan_schedule">
                        <option value="daily" <?php selected( $settings['scan_schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'wptransformed' ); ?></option>
                        <option value="weekly" <?php selected( $settings['scan_schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wptransformed' ); ?></option>
                        <option value="monthly" <?php selected( $settings['scan_schedule'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'wptransformed' ); ?></option>
                        <option value="off" <?php selected( $settings['scan_schedule'], 'off' ); ?>><?php esc_html_e( 'Off (manual only)', 'wptransformed' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Link Types', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_blc_scan_internal" value="1"
                               <?php checked( ! empty( $settings['scan_internal'] ) ); ?>>
                        <?php esc_html_e( 'Scan internal links', 'wptransformed' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="wpt_blc_scan_external" value="1"
                               <?php checked( ! empty( $settings['scan_external'] ) ); ?>>
                        <?php esc_html_e( 'Scan external links', 'wptransformed' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="wpt_blc_scan_images" value="1"
                               <?php checked( ! empty( $settings['scan_images'] ) ); ?>>
                        <?php esc_html_e( 'Scan image sources', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-blc-timeout"><?php esc_html_e( 'Timeout (seconds)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-blc-timeout" name="wpt_blc_timeout"
                           value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>"
                           class="small-text" min="1" max="30" step="1">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-blc-recheck"><?php esc_html_e( 'Recheck Broken (days)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-blc-recheck" name="wpt_blc_recheck_broken_days"
                           value="<?php echo esc_attr( (string) $settings['recheck_broken_days'] ); ?>"
                           class="small-text" min="1" max="30" step="1">
                    <p class="description">
                        <?php esc_html_e( 'Re-verify broken links after this many days.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Post Types', 'wptransformed' ); ?></th>
                <td>
                    <?php foreach ( $available_post_types as $pt ) : ?>
                    <label>
                        <input type="checkbox" name="wpt_blc_check_post_types[]"
                               value="<?php echo esc_attr( $pt->name ); ?>"
                               <?php checked( in_array( $pt->name, $settings['check_post_types'], true ) ); ?>>
                        <?php echo esc_html( $pt->labels->name ); ?>
                    </label>
                    <br>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-blc-exclude"><?php esc_html_e( 'Exclude Domains', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-blc-exclude" name="wpt_blc_exclude_domains" rows="3" class="large-text code"
                              placeholder="<?php esc_attr_e( 'example.com (one per line)', 'wptransformed' ); ?>"
                    ><?php echo esc_textarea( implode( "\n", $settings['exclude_domains'] ) ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One domain per line. Links to these domains will not be checked.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-blc-email"><?php esc_html_e( 'Notification Email', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="email" id="wpt-blc-email" name="wpt_blc_notify_email"
                           value="<?php echo esc_attr( $settings['notify_email'] ); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'Leave empty to disable', 'wptransformed' ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'Send an email when new broken links are found.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <!-- Broken Links Table -->
        <h3>
            <?php esc_html_e( 'Broken Links', 'wptransformed' ); ?>
            <span class="wpt-blc-count">(<?php echo esc_html( number_format_i18n( $broken_count ) ); ?>)</span>
        </h3>

        <div class="wpt-blc-filters">
            <button type="button" class="button wpt-blc-filter active" data-filter="broken">
                <?php esc_html_e( 'Broken', 'wptransformed' ); ?>
                <span class="wpt-blc-filter-count"><?php echo esc_html( (string) $broken_count ); ?></span>
            </button>
            <button type="button" class="button wpt-blc-filter" data-filter="redirects">
                <?php esc_html_e( 'Redirects', 'wptransformed' ); ?>
                <span class="wpt-blc-filter-count"><?php echo esc_html( (string) $redirect_count ); ?></span>
            </button>
            <button type="button" class="button wpt-blc-filter" data-filter="all">
                <?php esc_html_e( 'All', 'wptransformed' ); ?>
                <span class="wpt-blc-filter-count"><?php echo esc_html( (string) $total_count ); ?></span>
            </button>
        </div>

        <table class="widefat fixed striped wpt-blc-table" id="wpt-blc-table">
            <thead>
                <tr>
                    <th class="wpt-blc-col-url"><?php esc_html_e( 'URL', 'wptransformed' ); ?></th>
                    <th class="wpt-blc-col-status"><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                    <th class="wpt-blc-col-source"><?php esc_html_e( 'Found In', 'wptransformed' ); ?></th>
                    <th class="wpt-blc-col-type"><?php esc_html_e( 'Type', 'wptransformed' ); ?></th>
                    <th class="wpt-blc-col-actions"><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody id="wpt-blc-tbody">
                <?php if ( empty( $broken_links ) ) : ?>
                <tr class="wpt-blc-no-results">
                    <td colspan="5"><?php esc_html_e( 'No broken links found. Run a scan to check your content.', 'wptransformed' ); ?></td>
                </tr>
                <?php else : ?>
                    <?php foreach ( $broken_links as $link ) : ?>
                    <tr data-id="<?php echo esc_attr( (string) $link['id'] ); ?>">
                        <td class="wpt-blc-col-url">
                            <code class="wpt-blc-url" title="<?php echo esc_attr( $link['url'] ); ?>">
                                <?php echo esc_html( mb_strlen( $link['url'] ) > 80 ? mb_substr( $link['url'], 0, 80 ) . '...' : $link['url'] ); ?>
                            </code>
                            <?php if ( ! empty( $link['anchor_text'] ) ) : ?>
                            <span class="wpt-blc-anchor"><?php echo esc_html( $link['anchor_text'] ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="wpt-blc-col-status">
                            <span class="wpt-blc-status wpt-blc-status-<?php echo esc_attr( $this->get_status_class( (int) $link['status_code'] ) ); ?>">
                                <?php echo esc_html( (string) $link['status_code'] ); ?>
                                <?php if ( ! empty( $link['status_text'] ) ) : ?>
                                    <?php echo esc_html( $link['status_text'] ); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="wpt-blc-col-source">
                            <?php if ( ! empty( $link['post_title'] ) ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( (int) $link['found_in_post'] ) ); ?>">
                                <?php echo esc_html( $link['post_title'] ); ?>
                            </a>
                            <?php else : ?>
                            <span class="wpt-blc-muted"><?php esc_html_e( 'Unknown', 'wptransformed' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="wpt-blc-col-type">
                            <span class="wpt-blc-type-badge wpt-blc-type-<?php echo esc_attr( $link['link_type'] ); ?>">
                                <?php echo esc_html( ucfirst( $link['link_type'] ) ); ?>
                            </span>
                        </td>
                        <td class="wpt-blc-col-actions">
                            <a href="<?php echo esc_url( get_edit_post_link( (int) $link['found_in_post'] ) ); ?>"
                               class="button button-small" title="<?php esc_attr_e( 'Edit post', 'wptransformed' ); ?>">
                                <?php esc_html_e( 'Edit', 'wptransformed' ); ?>
                            </a>
                            <button type="button" class="button button-small wpt-blc-unlink"
                                    data-id="<?php echo esc_attr( (string) $link['id'] ); ?>">
                                <?php esc_html_e( 'Unlink', 'wptransformed' ); ?>
                            </button>
                            <?php if ( $redirect_manager_active ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed&module=redirect-manager&source=' . rawurlencode( wp_parse_url( $link['url'], PHP_URL_PATH ) ?? $link['url'] ) ) ); ?>"
                               class="button button-small" title="<?php esc_attr_e( 'Create redirect', 'wptransformed' ); ?>">
                                <?php esc_html_e( 'Redirect', 'wptransformed' ); ?>
                            </a>
                            <?php endif; ?>
                            <button type="button" class="button button-small wpt-blc-dismiss"
                                    data-id="<?php echo esc_attr( (string) $link['id'] ); ?>">
                                <?php esc_html_e( 'Dismiss', 'wptransformed' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="wpt-blc-pagination" id="wpt-blc-pagination" style="display: none;">
            <button type="button" class="button" id="wpt-blc-prev" disabled>&laquo; <?php esc_html_e( 'Previous', 'wptransformed' ); ?></button>
            <span class="wpt-blc-page-info" id="wpt-blc-page-info"></span>
            <button type="button" class="button" id="wpt-blc-next"><?php esc_html_e( 'Next', 'wptransformed' ); ?> &raquo;</button>
        </div>

        <?php
    }

    /**
     * Get CSS class for a status code.
     *
     * @param int $code HTTP status code.
     * @return string CSS class suffix.
     */
    private function get_status_class( int $code ): string {
        if ( $code === 0 ) {
            return 'error';
        }
        if ( $code >= 200 && $code < 300 ) {
            return 'ok';
        }
        if ( $code >= 300 && $code < 400 ) {
            return 'redirect';
        }
        if ( $code >= 400 && $code < 500 ) {
            return 'broken';
        }
        if ( $code >= 500 ) {
            return 'server-error';
        }
        return 'unknown';
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $schedule = isset( $raw['wpt_blc_scan_schedule'] )
            ? sanitize_text_field( $raw['wpt_blc_scan_schedule'] )
            : 'weekly';

        $valid_schedules = [ 'daily', 'weekly', 'monthly', 'off' ];
        if ( ! in_array( $schedule, $valid_schedules, true ) ) {
            $schedule = 'weekly';
        }

        $timeout = isset( $raw['wpt_blc_timeout'] ) ? (int) $raw['wpt_blc_timeout'] : 10;
        $timeout = max( 1, min( 30, $timeout ) );

        $recheck = isset( $raw['wpt_blc_recheck_broken_days'] ) ? (int) $raw['wpt_blc_recheck_broken_days'] : 3;
        $recheck = max( 1, min( 30, $recheck ) );

        $exclude_raw = isset( $raw['wpt_blc_exclude_domains'] )
            ? sanitize_textarea_field( $raw['wpt_blc_exclude_domains'] )
            : '';
        $exclude_domains = array_filter( array_map( 'trim', explode( "\n", $exclude_raw ) ) );

        $check_post_types = [];
        if ( isset( $raw['wpt_blc_check_post_types'] ) && is_array( $raw['wpt_blc_check_post_types'] ) ) {
            $valid_types = get_post_types( [ 'public' => true ] );
            foreach ( $raw['wpt_blc_check_post_types'] as $pt ) {
                $pt = sanitize_key( $pt );
                if ( in_array( $pt, $valid_types, true ) ) {
                    $check_post_types[] = $pt;
                }
            }
        }

        if ( empty( $check_post_types ) ) {
            $check_post_types = [ 'post', 'page' ];
        }

        $notify_email = isset( $raw['wpt_blc_notify_email'] )
            ? sanitize_email( $raw['wpt_blc_notify_email'] )
            : '';

        return [
            'scan_schedule'       => $schedule,
            'scan_internal'       => ! empty( $raw['wpt_blc_scan_internal'] ),
            'scan_external'       => ! empty( $raw['wpt_blc_scan_external'] ),
            'scan_images'         => ! empty( $raw['wpt_blc_scan_images'] ),
            'timeout'             => $timeout,
            'max_concurrent'      => 5,
            'exclude_domains'     => $exclude_domains,
            'notify_email'        => $notify_email,
            'check_post_types'    => $check_post_types,
            'recheck_broken_days' => $recheck,
        ];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Settings page assets.
        if ( strpos( $hook, 'wptransformed' ) !== false ) {
            wp_enqueue_style(
                'wpt-broken-link-checker',
                WPT_URL . 'modules/utilities/css/broken-link-checker.css',
                [],
                WPT_VERSION
            );

            wp_enqueue_script(
                'wpt-broken-link-checker',
                WPT_URL . 'modules/utilities/js/broken-link-checker.js',
                [],
                WPT_VERSION,
                true
            );

            wp_localize_script( 'wpt-broken-link-checker', 'wptBrokenLinkChecker', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wpt_broken_link_checker_nonce' ),
                'i18n'    => [
                    'confirmUnlink'  => __( 'Remove this link from the post? The anchor text will be preserved.', 'wptransformed' ),
                    'confirmDismiss' => __( 'Dismiss this link? It will be hidden from results.', 'wptransformed' ),
                    'scanning'       => __( 'Scanning...', 'wptransformed' ),
                    'scanComplete'   => __( 'Scan complete.', 'wptransformed' ),
                    'networkError'   => __( 'Network error. Please try again.', 'wptransformed' ),
                    'noResults'      => __( 'No results found.', 'wptransformed' ),
                    'pageOf'         => __( 'Page %1$s of %2$s', 'wptransformed' ),
                ],
            ] );
        }
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        global $wpdb;

        return [
            'drop_table:' . $this->table(),
            'delete_option:' . self::DB_VERSION_KEY,
        ];
    }
}
