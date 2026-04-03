<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Content Calendar -- Monthly calendar grid for managing scheduled content.
 *
 * Features:
 *  - Admin submenu page under WPTransformed settings
 *  - Server-side monthly calendar grid showing posts by date
 *  - Color-coded by post status (published/scheduled/draft/pending)
 *  - AJAX month navigation (prev/next)
 *  - Drag-and-drop posts between dates (AJAX updates post_date)
 *  - Post type filter dropdown
 *  - Click date to create new post with that date
 *
 * @package WPTransformed
 */
class Content_Calendar extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'content-calendar';
    }

    public function get_title(): string {
        return __( 'Content Calendar', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Visual monthly calendar for planning and managing content with drag-and-drop scheduling.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'post_types'         => [ 'post', 'page' ],
            'show_drafts'        => true,
            'show_status_colors' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'wp_ajax_wpt_calendar_navigate', [ $this, 'ajax_navigate' ] );
        add_action( 'wp_ajax_wpt_calendar_move_post', [ $this, 'ajax_move_post' ] );
    }

    // -- Admin Page -------------------------------------------------------

    /**
     * Register the calendar submenu page.
     */
    public function register_admin_page(): void {
        $hook = add_submenu_page(
            'wptransformed',
            __( 'Content Calendar', 'wptransformed' ),
            __( 'Content Calendar', 'wptransformed' ),
            'edit_posts',
            'wpt-content-calendar',
            [ $this, 'render_admin_page' ]
        );

        // Assets are inlined in render_admin_page; no external files to enqueue.
    }

    /**
     * Render the calendar admin page.
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wptransformed' ) );
        }

        $settings   = $this->get_settings();
        $year       = isset( $_GET['cal_year'] ) ? absint( $_GET['cal_year'] ) : (int) gmdate( 'Y' );
        $month      = isset( $_GET['cal_month'] ) ? absint( $_GET['cal_month'] ) : (int) gmdate( 'n' );
        $filter_pt  = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';

        // Clamp year/month.
        $year  = max( 2000, min( 2100, $year ) );
        $month = max( 1, min( 12, $month ) );

        $post_types = $this->get_allowed_post_types();

        ?>
        <div class="wrap" id="wpt-content-calendar">
            <h1><?php esc_html_e( 'Content Calendar', 'wptransformed' ); ?></h1>

            <?php $this->render_inline_styles(); ?>

            <div class="wpt-cal-toolbar">
                <div class="wpt-cal-nav">
                    <?php $adj = $this->adjacent_months( $year, $month ); ?>
                    <a href="#" class="button wpt-cal-prev"
                       data-year="<?php echo esc_attr( (string) $adj['prev_year'] ); ?>"
                       data-month="<?php echo esc_attr( (string) $adj['prev_month'] ); ?>">
                        &laquo; <?php esc_html_e( 'Prev', 'wptransformed' ); ?>
                    </a>
                    <span class="wpt-cal-current-month">
                        <?php echo esc_html( gmdate( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ) ); ?>
                    </span>
                    <a href="#" class="button wpt-cal-next"
                       data-year="<?php echo esc_attr( (string) $adj['next_year'] ); ?>"
                       data-month="<?php echo esc_attr( (string) $adj['next_month'] ); ?>">
                        <?php esc_html_e( 'Next', 'wptransformed' ); ?> &raquo;
                    </a>
                </div>

                <div class="wpt-cal-filter">
                    <select id="wpt-cal-post-type">
                        <option value=""><?php esc_html_e( 'All Post Types', 'wptransformed' ); ?></option>
                        <?php foreach ( $post_types as $pt_slug => $pt_label ) : ?>
                            <option value="<?php echo esc_attr( $pt_slug ); ?>"
                                <?php selected( $filter_pt, $pt_slug ); ?>>
                                <?php echo esc_html( $pt_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="wpt-cal-legend">
                <span class="wpt-cal-legend-item wpt-status-publish"><?php esc_html_e( 'Published', 'wptransformed' ); ?></span>
                <span class="wpt-cal-legend-item wpt-status-future"><?php esc_html_e( 'Scheduled', 'wptransformed' ); ?></span>
                <span class="wpt-cal-legend-item wpt-status-draft"><?php esc_html_e( 'Draft', 'wptransformed' ); ?></span>
                <span class="wpt-cal-legend-item wpt-status-pending"><?php esc_html_e( 'Pending', 'wptransformed' ); ?></span>
            </div>

            <div id="wpt-cal-grid-container">
                <?php echo $this->render_calendar_grid( $year, $month, $filter_pt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside method ?>
            </div>
        </div>

        <?php $this->render_inline_script( $year, $month ); ?>
        <?php
    }

    // -- Calendar Grid ----------------------------------------------------

    /**
     * Build the HTML table for a given month.
     *
     * @param int    $year      Year.
     * @param int    $month     Month (1-12).
     * @param string $filter_pt Post type filter (empty = all).
     * @return string HTML table.
     */
    public function render_calendar_grid( int $year, int $month, string $filter_pt = '' ): string {
        $first_of_month = mktime( 0, 0, 0, $month, 1, $year );
        $days_in_month  = (int) gmdate( 't', $first_of_month );
        $first_day_dow  = (int) gmdate( 'w', $first_of_month );

        // Query posts for this month.
        $posts = $this->query_month_posts( $year, $month, $filter_pt );

        // Group posts by day.
        $posts_by_day = [];
        foreach ( $posts as $post ) {
            $day = (int) gmdate( 'j', strtotime( $post->post_date ) );
            $posts_by_day[ $day ][] = $post;
        }

        $today_year  = (int) gmdate( 'Y' );
        $today_month = (int) gmdate( 'n' );
        $today_day   = (int) gmdate( 'j' );

        $html  = '<table class="wpt-cal-table">';
        $html .= '<thead><tr>';

        $day_names = [
            __( 'Sun', 'wptransformed' ),
            __( 'Mon', 'wptransformed' ),
            __( 'Tue', 'wptransformed' ),
            __( 'Wed', 'wptransformed' ),
            __( 'Thu', 'wptransformed' ),
            __( 'Fri', 'wptransformed' ),
            __( 'Sat', 'wptransformed' ),
        ];
        foreach ( $day_names as $dn ) {
            $html .= '<th>' . esc_html( $dn ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $current_day = 1;

        for ( $row = 0; $row < 6; $row++ ) {
            if ( $current_day > $days_in_month ) {
                break;
            }
            $html .= '<tr>';
            for ( $col = 0; $col < 7; $col++ ) {
                if ( ( $row === 0 && $col < $first_day_dow ) || $current_day > $days_in_month ) {
                    $html .= '<td class="wpt-cal-empty"></td>';
                    continue;
                }

                $is_today = ( $year === $today_year && $month === $today_month && $current_day === $today_day );
                $classes  = 'wpt-cal-day';
                if ( $is_today ) {
                    $classes .= ' wpt-cal-today';
                }

                $date_str = sprintf( '%04d-%02d-%02d', $year, $month, $current_day );
                $new_post_url = admin_url( 'post-new.php?wpt_date=' . urlencode( $date_str ) );

                $html .= '<td class="' . esc_attr( $classes ) . '" data-date="' . esc_attr( $date_str ) . '">';
                $html .= '<div class="wpt-cal-day-header">';
                $html .= '<span class="wpt-cal-day-num">' . esc_html( (string) $current_day ) . '</span>';
                $html .= '<a href="' . esc_url( $new_post_url ) . '" class="wpt-cal-add-post" title="' . esc_attr__( 'New post on this date', 'wptransformed' ) . '">+</a>';
                $html .= '</div>';

                // Render posts for this day.
                if ( ! empty( $posts_by_day[ $current_day ] ) ) {
                    $html .= '<div class="wpt-cal-posts">';
                    foreach ( $posts_by_day[ $current_day ] as $p ) {
                        $status_class = 'wpt-status-' . sanitize_html_class( $p->post_status );
                        $edit_url     = get_edit_post_link( $p->ID, 'raw' );
                        $html .= '<div class="wpt-cal-post ' . esc_attr( $status_class ) . '" draggable="true" data-post-id="' . esc_attr( (string) $p->ID ) . '">';
                        $html .= '<a href="' . esc_url( (string) $edit_url ) . '" title="' . esc_attr( $p->post_title ) . '">';
                        $html .= esc_html( wp_trim_words( $p->post_title, 6, '...' ) );
                        $html .= '</a>';
                        $html .= '</div>';
                    }
                    $html .= '</div>';
                }

                $html .= '</td>';
                $current_day++;
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    // -- Query Posts -------------------------------------------------------

    /**
     * Query posts for a given month.
     *
     * @param int    $year      Year.
     * @param int    $month     Month.
     * @param string $filter_pt Post type filter.
     * @return array Post objects.
     */
    private function query_month_posts( int $year, int $month, string $filter_pt = '' ): array {
        $settings   = $this->get_settings();
        $post_types = $this->get_allowed_post_type_slugs();

        if ( ! empty( $filter_pt ) && in_array( $filter_pt, $post_types, true ) ) {
            $post_types = [ $filter_pt ];
        }

        $statuses = [ 'publish', 'future', 'pending' ];
        if ( ! empty( $settings['show_drafts'] ) ) {
            $statuses[] = 'draft';
        }

        $start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
        $days  = (int) gmdate( 't', mktime( 0, 0, 0, $month, 1, $year ) );
        $end   = sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, $days );

        $args = [
            'post_type'      => $post_types,
            'post_status'    => $statuses,
            'date_query'     => [
                [
                    'after'     => $start,
                    'before'    => $end,
                    'inclusive' => true,
                ],
            ],
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ];

        $query = new \WP_Query( $args );

        return $query->posts;
    }

    // -- AJAX Handlers ----------------------------------------------------

    /**
     * AJAX: Navigate to a different month.
     */
    public function ajax_navigate(): void {
        check_ajax_referer( 'wpt_content_calendar', '_nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $year      = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) gmdate( 'Y' );
        $month     = isset( $_POST['month'] ) ? absint( $_POST['month'] ) : (int) gmdate( 'n' );
        $filter_pt = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '';

        $year  = max( 2000, min( 2100, $year ) );
        $month = max( 1, min( 12, $month ) );

        $html  = $this->render_calendar_grid( $year, $month, $filter_pt );
        $label = gmdate( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
        $adj   = $this->adjacent_months( $year, $month );

        wp_send_json_success( array_merge(
            [ 'html' => $html, 'label' => $label, 'year' => $year, 'month' => $month ],
            $adj
        ) );
    }

    /**
     * AJAX: Move a post to a different date via drag-and-drop.
     */
    public function ajax_move_post(): void {
        check_ajax_referer( 'wpt_content_calendar', '_nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        $post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $new_date = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_date'] ) ) : '';

        if ( ! $post_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'wptransformed' ) ] );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'wptransformed' ) ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ], 403 );
        }

        // Preserve original time, update only the date.
        $old_time = gmdate( 'H:i:s', strtotime( $post->post_date ) );
        $new_datetime = $new_date . ' ' . $old_time;

        $result = wp_update_post( [
            'ID'            => $post_id,
            'post_date'     => $new_datetime,
            'post_date_gmt' => get_gmt_from_date( $new_datetime ),
        ], true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => __( 'Post moved successfully.', 'wptransformed' ) ] );
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Compute prev/next month from a given year and month.
     *
     * @param int $year  Year.
     * @param int $month Month (1-12).
     * @return array{prev_year: int, prev_month: int, next_year: int, next_month: int}
     */
    private function adjacent_months( int $year, int $month ): array {
        $prev_month = $month - 1;
        $prev_year  = $year;
        if ( $prev_month < 1 ) {
            $prev_month = 12;
            $prev_year--;
        }
        $next_month = $month + 1;
        $next_year  = $year;
        if ( $next_month > 12 ) {
            $next_month = 1;
            $next_year++;
        }
        return compact( 'prev_year', 'prev_month', 'next_year', 'next_month' );
    }

    /**
     * Get allowed post types as slug => label array.
     *
     * @return array<string, string>
     */
    private function get_allowed_post_types(): array {
        $settings = $this->get_settings();
        $slugs    = is_array( $settings['post_types'] ) ? $settings['post_types'] : [ 'post' ];
        $result   = [];

        foreach ( $slugs as $slug ) {
            $pt_obj = get_post_type_object( $slug );
            if ( $pt_obj ) {
                $result[ $slug ] = $pt_obj->labels->singular_name ?? $slug;
            }
        }

        return $result;
    }

    /**
     * Get allowed post type slugs.
     *
     * @return string[]
     */
    private function get_allowed_post_type_slugs(): array {
        $settings = $this->get_settings();
        return is_array( $settings['post_types'] ) ? array_map( 'sanitize_key', $settings['post_types'] ) : [ 'post' ];
    }

    // -- Inline CSS -------------------------------------------------------

    /**
     * Render inline styles for the calendar.
     */
    private function render_inline_styles(): void {
        ?>
        <style>
            .wpt-cal-toolbar { display: flex; justify-content: space-between; align-items: center; margin: 15px 0; }
            .wpt-cal-nav { display: flex; align-items: center; gap: 10px; }
            .wpt-cal-current-month { font-size: 18px; font-weight: 600; min-width: 180px; text-align: center; }
            .wpt-cal-legend { display: flex; gap: 15px; margin-bottom: 10px; }
            .wpt-cal-legend-item { padding: 3px 10px; border-radius: 3px; font-size: 12px; color: #fff; }
            .wpt-cal-legend-item.wpt-status-publish { background: #46b450; }
            .wpt-cal-legend-item.wpt-status-future { background: #0073aa; }
            .wpt-cal-legend-item.wpt-status-draft { background: #999; }
            .wpt-cal-legend-item.wpt-status-pending { background: #f0ad4e; }

            .wpt-cal-table { width: 100%; border-collapse: collapse; table-layout: fixed; background: #fff; }
            .wpt-cal-table th { padding: 8px; background: #f1f1f1; text-align: center; font-weight: 600; border: 1px solid #ddd; }
            .wpt-cal-table td { border: 1px solid #ddd; vertical-align: top; padding: 4px; min-height: 100px; height: 120px; }
            .wpt-cal-empty { background: #f9f9f9; }
            .wpt-cal-today { background: #fffde7; }

            .wpt-cal-day-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
            .wpt-cal-day-num { font-weight: 600; font-size: 14px; color: #23282d; }
            .wpt-cal-add-post { text-decoration: none; font-size: 16px; font-weight: 700; color: #0073aa; line-height: 1; padding: 2px 4px; }
            .wpt-cal-add-post:hover { background: #0073aa; color: #fff; border-radius: 2px; }

            .wpt-cal-posts { display: flex; flex-direction: column; gap: 2px; }
            .wpt-cal-post { padding: 2px 5px; border-radius: 3px; font-size: 11px; cursor: grab; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .wpt-cal-post a { color: #fff; text-decoration: none; }
            .wpt-cal-post a:hover { text-decoration: underline; }
            .wpt-status-publish { background: #46b450; }
            .wpt-status-future { background: #0073aa; }
            .wpt-status-draft { background: #999; }
            .wpt-status-pending { background: #f0ad4e; }

            .wpt-cal-day.wpt-drag-over { background: #e8f5e9; outline: 2px dashed #46b450; }
        </style>
        <?php
    }

    // -- Inline JS --------------------------------------------------------

    /**
     * Render inline JavaScript for calendar navigation and drag-and-drop.
     *
     * @param int $year  Current year.
     * @param int $month Current month.
     */
    private function render_inline_script( int $year, int $month ): void {
        $nonce = wp_create_nonce( 'wpt_content_calendar' );
        ?>
        <script>
        (function() {
            'use strict';

            var state = {
                year: <?php echo (int) $year; ?>,
                month: <?php echo (int) $month; ?>,
                nonce: <?php echo wp_json_encode( $nonce ); ?>,
                ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>
            };

            function getPostType() {
                var sel = document.getElementById('wpt-cal-post-type');
                return sel ? sel.value : '';
            }

            function navigate(year, month) {
                var data = new FormData();
                data.append('action', 'wpt_calendar_navigate');
                data.append('_nonce', state.nonce);
                data.append('year', year);
                data.append('month', month);
                data.append('post_type', getPostType());

                fetch(state.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success) return;
                        var d = res.data;
                        state.year  = d.year;
                        state.month = d.month;

                        var container = document.getElementById('wpt-cal-grid-container');
                        if (container) container.innerHTML = d.html;

                        var label = document.querySelector('.wpt-cal-current-month');
                        if (label) label.textContent = d.label;

                        var prev = document.querySelector('.wpt-cal-prev');
                        if (prev) {
                            prev.setAttribute('data-year', d.prev_year);
                            prev.setAttribute('data-month', d.prev_month);
                        }
                        var next = document.querySelector('.wpt-cal-next');
                        if (next) {
                            next.setAttribute('data-year', d.next_year);
                            next.setAttribute('data-month', d.next_month);
                        }

                        initDragDrop();
                    });
            }

            function movePost(postId, newDate) {
                var data = new FormData();
                data.append('action', 'wpt_calendar_move_post');
                data.append('_nonce', state.nonce);
                data.append('post_id', postId);
                data.append('new_date', newDate);

                fetch(state.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            navigate(state.year, state.month);
                        }
                    });
            }

            function initDragDrop() {
                var posts = document.querySelectorAll('.wpt-cal-post');
                posts.forEach(function(el) {
                    el.addEventListener('dragstart', function(e) {
                        e.dataTransfer.setData('text/plain', el.getAttribute('data-post-id'));
                        e.dataTransfer.effectAllowed = 'move';
                    });
                });

                var cells = document.querySelectorAll('.wpt-cal-day');
                cells.forEach(function(cell) {
                    cell.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        cell.classList.add('wpt-drag-over');
                    });
                    cell.addEventListener('dragleave', function() {
                        cell.classList.remove('wpt-drag-over');
                    });
                    cell.addEventListener('drop', function(e) {
                        e.preventDefault();
                        cell.classList.remove('wpt-drag-over');
                        var postId  = e.dataTransfer.getData('text/plain');
                        var newDate = cell.getAttribute('data-date');
                        if (postId && newDate) {
                            movePost(postId, newDate);
                        }
                    });
                });
            }

            document.addEventListener('click', function(e) {
                var prev = e.target.closest('.wpt-cal-prev');
                if (prev) {
                    e.preventDefault();
                    navigate(parseInt(prev.getAttribute('data-year'), 10), parseInt(prev.getAttribute('data-month'), 10));
                    return;
                }
                var next = e.target.closest('.wpt-cal-next');
                if (next) {
                    e.preventDefault();
                    navigate(parseInt(next.getAttribute('data-year'), 10), parseInt(next.getAttribute('data-month'), 10));
                    return;
                }
            });

            var ptSelect = document.getElementById('wpt-cal-post-type');
            if (ptSelect) {
                ptSelect.addEventListener('change', function() {
                    navigate(state.year, state.month);
                });
            }

            initDragDrop();
        })();
        </script>
        <?php
    }

    // -- Settings UI -------------------------------------------------------

    public function render_settings(): void {
        $settings      = $this->get_settings();
        $all_post_types = get_post_types( [ 'public' => true ], 'objects' );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Post Types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $all_post_types as $pt ) : ?>
                            <label style="display:block; margin-bottom:4px;">
                                <input type="checkbox"
                                       name="wpt_post_types[]"
                                       value="<?php echo esc_attr( $pt->name ); ?>"
                                       <?php checked( in_array( $pt->name, $settings['post_types'], true ) ); ?>>
                                <?php echo esc_html( $pt->labels->singular_name ); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'Select which post types appear on the calendar.', 'wptransformed' ); ?></p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Display', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="wpt_show_drafts" value="1" <?php checked( $settings['show_drafts'] ); ?>>
                            <?php esc_html_e( 'Show draft posts on the calendar.', 'wptransformed' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="wpt_show_status_colors" value="1" <?php checked( $settings['show_status_colors'] ); ?>>
                            <?php esc_html_e( 'Color-code posts by status.', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $post_types = [];
        if ( ! empty( $raw['wpt_post_types'] ) && is_array( $raw['wpt_post_types'] ) ) {
            $post_types = array_map( 'sanitize_key', $raw['wpt_post_types'] );
            $post_types = array_filter( $post_types );
        }
        if ( empty( $post_types ) ) {
            $post_types = [ 'post' ];
        }

        return [
            'post_types'         => array_values( $post_types ),
            'show_drafts'        => ! empty( $raw['wpt_show_drafts'] ),
            'show_status_colors' => ! empty( $raw['wpt_show_status_colors'] ),
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
