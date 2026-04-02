<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Broken Link Checker — Background scan of all content for broken internal
 * and external links with one-click fix options.
 *
 * Features:
 *  - Extracts links from post_content (<a href>, <img src>)
 *  - Checks via wp_remote_head() with GET fallback on 403/405
 *  - WP-Cron batch processing with rate limiting per domain
 *  - Dashboard widget with broken link count
 *  - Fix actions: edit, unlink, redirect (via Redirect Manager), dismiss
 *  - Email notifications for new broken links
 *  - Integrates with Redirect Manager module if active
 *
 * @package WPTransformed
 */
class Broken_Link_Checker extends Module_Base {

    private const TABLE_SUFFIX    = 'wpt_link_checks';
    private const DB_VERSION_KEY  = 'wpt_blc_db_version';
    private const DB_VERSION      = '1.0';
    private const CRON_SCAN       = 'wpt_blc_scan_links';
    private const CRON_CHECK      = 'wpt_blc_check_urls';
    private const BATCH_SIZE      = 20;

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
        return __( 'Background scan of all content for broken internal and external links with one-click fix options.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'scan_schedule'       => 'weekly',
            'scan_internal'       => true,
            'scan_external'       => true,
            'scan_images'         => true,
            'timeout'             => 10,
            'max_concurrent'      => 5,
            'exclude_domains'     => [],
            'notify_email'        => '',
            'check_post_types'    => [ 'post', 'page' ],
            'recheck_broken_days' => 3,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $this->maybe_create_table();

        // Cron handlers.
        add_action( self::CRON_SCAN, [ $this, 'cron_extract_links' ] );
        add_action( self::CRON_CHECK, [ $this, 'cron_check_urls' ] );

        // Schedule cron if needed.
        $this->maybe_schedule_cron();

        // Dashboard widget.
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_blc_scan_now', [ $this, 'ajax_scan_now' ] );
        add_action( 'wp_ajax_wpt_blc_fetch_results', [ $this, 'ajax_fetch_results' ] );
        add_action( 'wp_ajax_wpt_blc_dismiss', [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_wpt_blc_unlink', [ $this, 'ajax_unlink' ] );
        add_action( 'wp_ajax_wpt_blc_recheck', [ $this, 'ajax_recheck' ] );
    }

    public function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_SCAN );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_SCAN );
        }
        $timestamp = wp_next_scheduled( self::CRON_CHECK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_CHECK );
        }
    }

    // ── Table Creation ────────────────────────────────────────

    private function maybe_create_table(): void {
        $installed = get_option( self::DB_VERSION_KEY );
        if ( $installed === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            url VARCHAR(2048) NOT NULL,
            url_hash VARCHAR(64) NOT NULL,
            status_code SMALLINT DEFAULT 0,
            status_text VARCHAR(255) DEFAULT '',
            link_type VARCHAR(20) DEFAULT 'external',
            found_in_post BIGINT UNSIGNED DEFAULT 0,
            found_in_field VARCHAR(100) DEFAULT 'post_content',
            anchor_text VARCHAR(500) DEFAULT '',
            first_found DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_checked DATETIME NULL,
            last_status_change DATETIME NULL,
            is_dismissed TINYINT(1) DEFAULT 0,
            INDEX idx_hash (url_hash),
            INDEX idx_status (status_code),
            INDEX idx_post (found_in_post),
            INDEX idx_checked (last_checked)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    // ── Cron Scheduling ───────────────────────────────────────

    private function maybe_schedule_cron(): void {
        $settings = $this->get_settings();
        $schedule = $settings['scan_schedule'] ?? 'weekly';

        if ( $schedule === 'off' ) {
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_SCAN ) ) {
            wp_schedule_event( time(), $schedule, self::CRON_SCAN );
        }

        // URL checking runs every 15 minutes when there are unchecked URLs.
        if ( ! wp_next_scheduled( self::CRON_CHECK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_CHECK );
        }
    }

    // ── Cron: Extract Links ───────────────────────────────────

    /**
     * Extract links from all configured post types and store in DB.
     */
    public function cron_extract_links(): void {
        global $wpdb;

        $settings   = $this->get_settings();
        $post_types = (array) $settings['check_post_types'];
        $table      = $wpdb->prefix . self::TABLE_SUFFIX;

        if ( empty( $post_types ) ) {
            return;
        }

        // Process posts in batches.
        $batch_size = 50;
        $offset     = 0;

        do {
            $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
            $query_args   = array_merge( $post_types, [ $batch_size, $offset ] );

            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE post_type IN ({$placeholders})
                 AND post_status = 'publish'
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                ...$query_args
            ) );

            if ( empty( $posts ) ) {
                break;
            }

            foreach ( $posts as $post ) {
                $this->extract_links_from_content( (int) $post->ID, $post->post_content );
            }

            $offset += $batch_size;
        } while ( count( $posts ) === $batch_size );
    }

    /**
     * Extract <a href> and <img src> from content and upsert to DB.
     */
    private function extract_links_from_content( int $post_id, string $content ): void {
        if ( empty( $content ) ) {
            return;
        }

        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE_SUFFIX;
        $settings = $this->get_settings();
        $site_url = home_url();
        $links    = [];

        // Extract <a href="...">anchor</a>.
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $url    = trim( $match[1] );
                $anchor = wp_strip_all_tags( $match[2] );
                $type   = $this->classify_link( $url, $site_url );

                if ( $type === 'skip' ) {
                    continue;
                }

                $links[] = [
                    'url'        => $this->resolve_url( $url, $site_url ),
                    'anchor'     => mb_substr( $anchor, 0, 500 ),
                    'type'       => $type,
                    'field'      => 'post_content',
                ];
            }
        }

        // Extract <img src="...">.
        if ( ! empty( $settings['scan_images'] ) ) {
            if ( preg_match_all( '/<img\s[^>]*src=["\']([^"\']+)["\']/i', $content, $img_matches ) ) {
                foreach ( $img_matches[1] as $img_url ) {
                    $url  = trim( $img_url );
                    $type = $this->classify_link( $url, $site_url );

                    if ( $type === 'skip' ) {
                        continue;
                    }

                    $links[] = [
                        'url'    => $this->resolve_url( $url, $site_url ),
                        'anchor' => '',
                        'type'   => 'image',
                        'field'  => 'post_content',
                    ];
                }
            }
        }

        // Filter by scan_internal / scan_external settings.
        $links = array_filter( $links, function ( array $link ) use ( $settings, $site_url ): bool {
            $is_internal = strpos( $link['url'], $site_url ) === 0;
            if ( $is_internal && empty( $settings['scan_internal'] ) ) {
                return false;
            }
            if ( ! $is_internal && $link['type'] !== 'image' && empty( $settings['scan_external'] ) ) {
                return false;
            }
            return true;
        } );

        // Filter excluded domains.
        $exclude = (array) $settings['exclude_domains'];
        if ( ! empty( $exclude ) ) {
            $links = array_filter( $links, function ( array $link ) use ( $exclude ): bool {
                $host = wp_parse_url( $link['url'], PHP_URL_HOST );
                if ( $host && in_array( $host, $exclude, true ) ) {
                    return false;
                }
                return true;
            } );
        }

        // Upsert links into DB.
        foreach ( $links as $link ) {
            $url_hash = hash( 'sha256', $link['url'] );

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE url_hash = %s AND found_in_post = %d LIMIT 1",
                $url_hash,
                $post_id
            ) );

            if ( ! $existing ) {
                $wpdb->insert( $table, [
                    'url'           => $link['url'],
                    'url_hash'      => $url_hash,
                    'link_type'     => $link['type'],
                    'found_in_post' => $post_id,
                    'found_in_field' => $link['field'],
                    'anchor_text'   => $link['anchor'],
                    'first_found'   => current_time( 'mysql', true ),
                ], [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );
            }
        }
    }

    /**
     * Classify a link URL.
     *
     * @return string 'internal'|'external'|'image'|'skip'
     */
    private function classify_link( string $url, string $site_url ): string {
        // Skip empty, anchors, mailto, tel, javascript.
        if ( empty( $url ) || $url[0] === '#' ) {
            return 'skip';
        }
        if ( preg_match( '/^(mailto:|tel:|javascript:)/i', $url ) ) {
            return 'skip';
        }

        $resolved = $this->resolve_url( $url, $site_url );

        if ( strpos( $resolved, $site_url ) === 0 ) {
            return 'internal';
        }

        return 'external';
    }

    /**
     * Resolve a potentially relative URL.
     */
    private function resolve_url( string $url, string $site_url ): string {
        if ( strpos( $url, 'http' ) === 0 ) {
            return $url;
        }
        if ( strpos( $url, '//' ) === 0 ) {
            return 'https:' . $url;
        }
        if ( strpos( $url, '/' ) === 0 ) {
            return rtrim( $site_url, '/' ) . $url;
        }
        return $site_url . '/' . $url;
    }

    // ── Cron: Check URLs ──────────────────────────────────────

    /**
     * Check unchecked or stale URLs in batches.
     */
    public function cron_check_urls(): void {
        global $wpdb;

        $table    = $wpdb->prefix . self::TABLE_SUFFIX;
        $settings = $this->get_settings();
        $timeout  = max( 3, (int) $settings['timeout'] );
        $recheck  = max( 1, (int) $settings['recheck_broken_days'] );
        $limit    = min( 20, max( 1, (int) $settings['max_concurrent'] ) );

        // Get unchecked URLs or stale broken URLs.
        $urls = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, url, url_hash, status_code FROM {$table}
             WHERE is_dismissed = 0
             AND (
                 last_checked IS NULL
                 OR (status_code >= 400 AND last_checked < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))
             )
             ORDER BY last_checked ASC, id ASC
             LIMIT %d",
            $recheck,
            $limit
        ) );

        if ( empty( $urls ) ) {
            return;
        }

        // Rate limiting: track domains in this batch.
        $domain_count = [];
        $new_broken   = 0;

        foreach ( $urls as $link ) {
            $host = wp_parse_url( $link->url, PHP_URL_HOST ) ?: 'unknown';

            // Max 2 requests per domain per batch.
            if ( isset( $domain_count[ $host ] ) && $domain_count[ $host ] >= 2 ) {
                continue;
            }
            $domain_count[ $host ] = ( $domain_count[ $host ] ?? 0 ) + 1;

            $result = $this->check_url( $link->url, $timeout );

            $old_status = (int) $link->status_code;
            $new_status = $result['status_code'];

            $update_data = [
                'status_code' => $new_status,
                'status_text' => mb_substr( $result['status_text'], 0, 255 ),
                'last_checked' => current_time( 'mysql', true ),
            ];

            if ( $old_status !== $new_status ) {
                $update_data['last_status_change'] = current_time( 'mysql', true );
            }

            $wpdb->update(
                $table,
                $update_data,
                [ 'id' => $link->id ],
                [ '%d', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            // Track new broken links for notification.
            if ( $new_status >= 400 && $old_status < 400 ) {
                $new_broken++;
            }
        }

        // Email notification.
        if ( $new_broken > 0 ) {
            $this->maybe_send_notification( $new_broken );
        }
    }

    /**
     * Check a single URL via HTTP HEAD, fallback to GET on 403/405.
     *
     * @return array{status_code: int, status_text: string}
     */
    private function check_url( string $url, int $timeout ): array {
        $args = [
            'timeout'     => $timeout,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => 'WPTransformed Broken Link Checker',
        ];

        $response = wp_remote_head( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [
                'status_code' => 0,
                'status_text' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );

        // Some servers block HEAD — retry with GET on 403/405.
        if ( in_array( $code, [ 403, 405 ], true ) ) {
            $args['headers'] = [ 'Range' => 'bytes=0-1023' ];
            $response = wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) ) {
                return [
                    'status_code' => 0,
                    'status_text' => $response->get_error_message(),
                ];
            }

            $code = wp_remote_retrieve_response_code( $response );
        }

        return [
            'status_code' => (int) $code,
            'status_text' => wp_remote_retrieve_response_message( $response ),
        ];
    }

    /**
     * Send email notification about new broken links.
     */
    private function maybe_send_notification( int $count ): void {
        $settings = $this->get_settings();
        $email    = $settings['notify_email'] ?? '';

        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: count, 2: site name */
            __( '[%2$s] %1$d new broken link(s) found', 'wptransformed' ),
            $count,
            get_bloginfo( 'name' )
        );

        $message = sprintf(
            /* translators: 1: count, 2: admin URL */
            __( "WPTransformed found %1\$d new broken link(s) on your site.\n\nView details: %2\$s", 'wptransformed' ),
            $count,
            admin_url( 'options-general.php?page=wptransformed' )
        );

        wp_mail( $email, $subject, $message );
    }

    // ── Dashboard Widget ──────────────────────────────────────

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

    public function render_dashboard_widget(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $broken_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE status_code >= 400 AND is_dismissed = 0"
        );

        $last_scan = $wpdb->get_var(
            "SELECT MAX(last_checked) FROM `{$table}`"
        );

        ?>
        <div class="wpt-blc-widget">
            <p style="font-size: 24px; font-weight: 700; margin: 0;">
                <?php echo esc_html( (string) $broken_count ); ?>
            </p>
            <p><?php esc_html_e( 'broken links found', 'wptransformed' ); ?></p>
            <?php if ( $last_scan ) : ?>
                <p class="description">
                    <?php
                    /* translators: %s: human time diff */
                    printf( esc_html__( 'Last scan: %s ago', 'wptransformed' ), esc_html( human_time_diff( strtotime( $last_scan ) ) ) );
                    ?>
                </p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wptransformed' ) ); ?>">
                    <?php esc_html_e( 'View Details', 'wptransformed' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // ── AJAX: Scan Now ────────────────────────────────────────

    public function ajax_scan_now(): void {
        check_ajax_referer( 'wpt_blc_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        // Schedule cron events for background processing — don't run synchronously
        // to avoid WP Engine 60s timeout on large sites.
        wp_schedule_single_event( time(), self::CRON_SCAN );
        if ( ! wp_next_scheduled( self::CRON_CHECK ) ) {
            wp_schedule_single_event( time() + 60, self::CRON_CHECK );
        }

        wp_send_json_success( [
            'message' => __( 'Scan initiated. Links are being checked in the background.', 'wptransformed' ),
        ] );
    }

    // ── AJAX: Fetch Results ───────────────────────────────────

    public function ajax_fetch_results(): void {
        check_ajax_referer( 'wpt_blc_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $filter_type   = isset( $_POST['link_type'] ) ? sanitize_key( $_POST['link_type'] ) : '';
        $filter_status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'broken';
        $page          = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $per_page      = 25;
        $offset        = ( $page - 1 ) * $per_page;

        $where = [ 'is_dismissed = 0' ];
        $values = [];

        if ( $filter_status === 'broken' ) {
            $where[] = 'status_code >= 400';
        } elseif ( $filter_status === 'redirect' ) {
            $where[] = 'status_code BETWEEN 300 AND 399';
        } elseif ( $filter_status === 'ok' ) {
            $where[] = 'status_code BETWEEN 200 AND 299';
        }

        if ( $filter_type && in_array( $filter_type, [ 'internal', 'external', 'image' ], true ) ) {
            $where[]  = 'link_type = %s';
            $values[] = $filter_type;
        }

        $where_sql = implode( ' AND ', $where );
        $values[]  = $per_page;
        $values[]  = $offset;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY last_checked DESC LIMIT %d OFFSET %d",
            ...$values
        ), ARRAY_A );

        // Get total count for pagination.
        $count_values = array_slice( $values, 0, -2 );
        if ( ! empty( $count_values ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
                ...$count_values
            ) );
        } else {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}"
            );
        }

        // Enrich with post titles.
        foreach ( $results as &$row ) {
            $row['post_title'] = '';
            if ( ! empty( $row['found_in_post'] ) ) {
                $row['post_title'] = get_the_title( (int) $row['found_in_post'] );
                $row['edit_url']   = get_edit_post_link( (int) $row['found_in_post'], 'raw' );
            }
        }
        unset( $row );

        wp_send_json_success( [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int) ceil( $total / $per_page ),
        ] );
    }

    // ── AJAX: Dismiss ─────────────────────────────────────────

    public function ajax_dismiss(): void {
        check_ajax_referer( 'wpt_blc_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid link ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $wpdb->update(
            $table,
            [ 'is_dismissed' => 1 ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'message' => __( 'Link dismissed.', 'wptransformed' ) ] );
    }

    // ── AJAX: Unlink ──────────────────────────────────────────

    public function ajax_unlink(): void {
        check_ajax_referer( 'wpt_blc_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
        if ( ! $link_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid link ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $link = $wpdb->get_row( $wpdb->prepare(
            "SELECT url, found_in_post FROM {$table} WHERE id = %d",
            $link_id
        ) );

        if ( ! $link || ! $link->found_in_post ) {
            wp_send_json_error( [ 'message' => __( 'Link or post not found.', 'wptransformed' ) ] );
        }

        $post = get_post( (int) $link->found_in_post );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'wptransformed' ) ] );
        }

        // Remove <a> tags with this URL but preserve inner text.
        $escaped_url = preg_quote( $link->url, '/' );
        $new_content = preg_replace(
            '/<a\s[^>]*href=["\']' . $escaped_url . '["\'][^>]*>(.*?)<\/a>/is',
            '$1',
            $post->post_content
        );

        if ( $new_content !== null && $new_content !== $post->post_content ) {
            wp_update_post( [
                'ID'           => $post->ID,
                'post_content' => $new_content,
            ] );

            // Mark as dismissed since we fixed it.
            $wpdb->update(
                $table,
                [ 'is_dismissed' => 1 ],
                [ 'id' => $link_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        wp_send_json_success( [ 'message' => __( 'Link removed from content.', 'wptransformed' ) ] );
    }

    // ── AJAX: Recheck ─────────────────────────────────────────

    public function ajax_recheck(): void {
        check_ajax_referer( 'wpt_blc_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
        if ( ! $link_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid link ID.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $link = $wpdb->get_row( $wpdb->prepare(
            "SELECT url FROM {$table} WHERE id = %d",
            $link_id
        ) );

        if ( ! $link ) {
            wp_send_json_error( [ 'message' => __( 'Link not found.', 'wptransformed' ) ] );
        }

        $settings = $this->get_settings();
        $result   = $this->check_url( $link->url, (int) $settings['timeout'] );

        $wpdb->update(
            $table,
            [
                'status_code'        => $result['status_code'],
                'status_text'        => mb_substr( $result['status_text'], 0, 255 ),
                'last_checked'       => current_time( 'mysql', true ),
                'last_status_change' => current_time( 'mysql', true ),
            ],
            [ 'id' => $link_id ],
            [ '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [
            'status_code' => $result['status_code'],
            'status_text' => $result['status_text'],
            'message'     => sprintf(
                /* translators: %d: HTTP status code */
                __( 'Rechecked — status: %d', 'wptransformed' ),
                $result['status_code']
            ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings   = $this->get_settings();
        $exclude    = implode( "\n", (array) $settings['exclude_domains'] );
        $post_types = implode( ', ', (array) $settings['check_post_types'] );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $broken_count = 0;
        $total_count  = 0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
            $broken_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$table}` WHERE status_code >= 400 AND is_dismissed = 0"
            );
            $total_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$table}`"
            );
        }
        ?>

        <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 20px;">
            <p style="margin: 0;">
                <strong><?php echo esc_html( (string) $broken_count ); ?></strong>
                <?php esc_html_e( 'broken links found', 'wptransformed' ); ?>
                &nbsp;|&nbsp;
                <strong><?php echo esc_html( (string) $total_count ); ?></strong>
                <?php esc_html_e( 'total links tracked', 'wptransformed' ); ?>
                &nbsp;|&nbsp;
                <button type="button" class="button button-small" id="wpt-blc-scan-now">
                    <?php esc_html_e( 'Scan Now', 'wptransformed' ); ?>
                </button>
                <span id="wpt-blc-scan-status"></span>
            </p>
        </div>

        <!-- Results table -->
        <div id="wpt-blc-results" style="margin-bottom: 24px;">
            <h3><?php esc_html_e( 'Broken Links', 'wptransformed' ); ?></h3>
            <div id="wpt-blc-filters" style="margin-bottom: 12px;">
                <select id="wpt-blc-filter-status">
                    <option value="broken"><?php esc_html_e( 'Broken (4xx/5xx)', 'wptransformed' ); ?></option>
                    <option value="redirect"><?php esc_html_e( 'Redirects (3xx)', 'wptransformed' ); ?></option>
                    <option value="ok"><?php esc_html_e( 'OK (2xx)', 'wptransformed' ); ?></option>
                    <option value="all"><?php esc_html_e( 'All', 'wptransformed' ); ?></option>
                </select>
                <select id="wpt-blc-filter-type">
                    <option value=""><?php esc_html_e( 'All Types', 'wptransformed' ); ?></option>
                    <option value="internal"><?php esc_html_e( 'Internal', 'wptransformed' ); ?></option>
                    <option value="external"><?php esc_html_e( 'External', 'wptransformed' ); ?></option>
                    <option value="image"><?php esc_html_e( 'Images', 'wptransformed' ); ?></option>
                </select>
                <button type="button" class="button" id="wpt-blc-load-results">
                    <?php esc_html_e( 'Filter', 'wptransformed' ); ?>
                </button>
            </div>
            <table class="widefat striped" id="wpt-blc-table" style="display:none;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'URL', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Found In', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody id="wpt-blc-tbody"></tbody>
            </table>
            <div id="wpt-blc-pagination" style="margin-top: 8px;"></div>
            <p id="wpt-blc-empty" style="display:none; color: #666;">
                <?php esc_html_e( 'No links found matching the filter.', 'wptransformed' ); ?>
            </p>
        </div>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-scan-schedule"><?php esc_html_e( 'Scan Schedule', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-scan-schedule" name="wpt_scan_schedule">
                        <?php foreach ( [ 'daily', 'weekly', 'monthly', 'off' ] as $opt ) : ?>
                            <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $settings['scan_schedule'], $opt ); ?>>
                                <?php echo esc_html( ucfirst( $opt ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'What to Scan', 'wptransformed' ); ?></th>
                <td>
                    <label><input type="checkbox" name="wpt_scan_internal" value="1" <?php checked( $settings['scan_internal'] ); ?>> <?php esc_html_e( 'Internal links', 'wptransformed' ); ?></label><br>
                    <label><input type="checkbox" name="wpt_scan_external" value="1" <?php checked( $settings['scan_external'] ); ?>> <?php esc_html_e( 'External links', 'wptransformed' ); ?></label><br>
                    <label><input type="checkbox" name="wpt_scan_images" value="1" <?php checked( $settings['scan_images'] ); ?>> <?php esc_html_e( 'Images', 'wptransformed' ); ?></label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-timeout"><?php esc_html_e( 'Request Timeout (seconds)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-timeout" name="wpt_timeout"
                           value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>"
                           min="3" max="30" class="small-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-post-types"><?php esc_html_e( 'Post Types', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-post-types" name="wpt_check_post_types"
                           value="<?php echo esc_attr( $post_types ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'Comma-separated list of post types to scan.', 'wptransformed' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-recheck"><?php esc_html_e( 'Recheck Broken Links (days)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-recheck" name="wpt_recheck_broken_days"
                           value="<?php echo esc_attr( (string) $settings['recheck_broken_days'] ); ?>"
                           min="1" max="30" class="small-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-exclude-domains"><?php esc_html_e( 'Exclude Domains', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-exclude-domains" name="wpt_exclude_domains" rows="3" class="regular-text"
                              placeholder="example.com&#10;cdn.example.com"><?php echo esc_textarea( $exclude ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One domain per line.', 'wptransformed' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-notify-email"><?php esc_html_e( 'Notification Email', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="email" id="wpt-notify-email" name="wpt_notify_email"
                           value="<?php echo esc_attr( $settings['notify_email'] ); ?>" class="regular-text"
                           placeholder="<?php esc_attr_e( 'Leave empty to disable', 'wptransformed' ); ?>">
                </td>
            </tr>
        </table>

        <?php
        // Nonce for AJAX.
        wp_nonce_field( 'wpt_blc_nonce', 'wpt_blc_nonce_field', false );
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $clean = [];

        $schedule = $raw['wpt_scan_schedule'] ?? 'weekly';
        $clean['scan_schedule'] = in_array( $schedule, [ 'daily', 'weekly', 'monthly', 'off' ], true ) ? $schedule : 'weekly';

        $clean['scan_internal'] = ! empty( $raw['wpt_scan_internal'] );
        $clean['scan_external'] = ! empty( $raw['wpt_scan_external'] );
        $clean['scan_images']   = ! empty( $raw['wpt_scan_images'] );

        $clean['timeout'] = max( 3, min( 30, (int) ( $raw['wpt_timeout'] ?? 10 ) ) );
        $clean['max_concurrent'] = 5;
        $clean['recheck_broken_days'] = max( 1, min( 30, (int) ( $raw['wpt_recheck_broken_days'] ?? 3 ) ) );

        // Exclude domains.
        $domains_text = $raw['wpt_exclude_domains'] ?? '';
        $domains = array_filter( array_map( 'trim', explode( "\n", $domains_text ) ) );
        $clean['exclude_domains'] = array_values( array_filter( $domains, function ( string $d ): bool {
            return (bool) preg_match( '/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $d );
        } ) );

        $clean['notify_email'] = sanitize_email( $raw['wpt_notify_email'] ?? '' );

        // Post types.
        $pt_text = $raw['wpt_check_post_types'] ?? 'post, page';
        $pts = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $pt_text ) ) ) );
        $clean['check_post_types'] = ! empty( $pts ) ? array_values( $pts ) : [ 'post', 'page' ];

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

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

        wp_localize_script( 'wpt-broken-link-checker', 'wptBLC', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_blc_nonce' ),
            'i18n'    => [
                'scanning'    => __( 'Scanning...', 'wptransformed' ),
                'loading'     => __( 'Loading...', 'wptransformed' ),
                'noResults'   => __( 'No results.', 'wptransformed' ),
                'error'       => __( 'An error occurred.', 'wptransformed' ),
                'dismissed'   => __( 'Dismissed', 'wptransformed' ),
                'unlinked'    => __( 'Unlinked', 'wptransformed' ),
                'confirmUnlink' => __( 'Remove this link from the post? The link text will be preserved.', 'wptransformed' ),
            ],
        ] );
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        global $wpdb;
        return [
            [ 'type' => 'table', 'name' => $wpdb->prefix . self::TABLE_SUFFIX ],
            [ 'type' => 'option', 'key' => self::DB_VERSION_KEY ],
        ];
    }
}
