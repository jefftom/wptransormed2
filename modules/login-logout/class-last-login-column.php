<?php
declare(strict_types=1);

namespace WPTransformed\Modules\LoginLogout;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Last Login Column — Show when each user last logged in.
 *
 * Features:
 *  - Records login timestamp on every successful authentication
 *  - Adds a sortable "Last Login" column to the Users list table
 *  - Supports relative ("2 hours ago") and absolute date formats
 *  - Shows "Never" for users who have not logged in since activation
 *
 * @package WPTransformed
 */
class Last_Login_Column extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'last-login-column';
    }

    public function get_title(): string {
        return __( 'Last Login Column', 'wptransformed' );
    }

    public function get_category(): string {
        return 'login-logout';
    }

    public function get_description(): string {
        return __( 'Display the last login date for each user in a sortable column on the Users screen.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'     => true,
            'date_format' => 'relative',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'wp_login', [ $this, 'record_login' ], 10, 2 );
        add_filter( 'manage_users_columns', [ $this, 'add_column' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_column' ], 10, 3 );
        add_filter( 'manage_users_sortable_columns', [ $this, 'sortable_column' ] );
        add_action( 'pre_get_users', [ $this, 'handle_sorting' ] );
    }

    // ── Login Recording ───────────────────────────────────────

    /**
     * Store the current UTC time as user meta on login.
     *
     * @param string   $user_login Username.
     * @param \WP_User $user       User object.
     */
    public function record_login( string $user_login, \WP_User $user ): void {
        update_user_meta( $user->ID, 'wpt_last_login', current_time( 'mysql', true ) );
    }

    // ── Column Registration ───────────────────────────────────

    /**
     * Add the "Last Login" column header.
     *
     * @param  array $columns Existing columns.
     * @return array
     */
    public function add_column( array $columns ): array {
        $columns['wpt_last_login'] = __( 'Last Login', 'wptransformed' );
        return $columns;
    }

    /**
     * Make the column sortable.
     *
     * @param  array $columns Sortable columns.
     * @return array
     */
    public function sortable_column( array $columns ): array {
        $columns['wpt_last_login'] = 'wpt_last_login';
        return $columns;
    }

    /**
     * Render the column value for a given user.
     *
     * @param  string $output      Current column output.
     * @param  string $column_name Column identifier.
     * @param  int    $user_id     User ID.
     * @return string
     */
    public function render_column( string $output, string $column_name, int $user_id ): string {
        if ( 'wpt_last_login' !== $column_name ) {
            return $output;
        }

        $last_login = get_user_meta( $user_id, 'wpt_last_login', true );

        if ( empty( $last_login ) ) {
            return esc_html__( 'Never', 'wptransformed' );
        }

        $timestamp = strtotime( $last_login );
        if ( false === $timestamp ) {
            return esc_html__( 'Never', 'wptransformed' );
        }

        $datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $absolute_str    = wp_date( $datetime_format, $timestamp );
        $settings        = $this->get_settings();

        if ( 'relative' === $settings['date_format'] ) {
            /* translators: %s: human-readable time difference */
            $relative = sprintf(
                __( '%s ago', 'wptransformed' ),
                human_time_diff( $timestamp, time() )
            );
            return '<span title="' . esc_attr( $absolute_str ) . '">' . esc_html( $relative ) . '</span>';
        }

        return esc_html( $absolute_str );
    }

    // ── Sorting ───────────────────────────────────────────────

    /**
     * Handle orderby for the custom meta column.
     *
     * @param \WP_User_Query $query User query.
     */
    public function handle_sorting( \WP_User_Query $query ): void {
        if ( ! is_admin() ) {
            return;
        }

        $orderby = $query->get( 'orderby' );

        if ( 'wpt_last_login' === $orderby ) {
            $query->set( 'meta_key', 'wpt_last_login' );
            $query->set( 'orderby', 'meta_value' );
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-last-login-enabled"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <label>
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" id="wpt-last-login-enabled" name="enabled" value="1"
                            <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Show "Last Login" column on the Users screen', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-last-login-format"><?php esc_html_e( 'Date Format', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-last-login-format" name="date_format">
                        <option value="relative" <?php selected( $settings['date_format'], 'relative' ); ?>>
                            <?php esc_html_e( 'Relative (e.g., "2 hours ago")', 'wptransformed' ); ?>
                        </option>
                        <option value="absolute" <?php selected( $settings['date_format'], 'absolute' ); ?>>
                            <?php esc_html_e( 'Absolute (site date/time format)', 'wptransformed' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'     => ! empty( $raw['enabled'] ),
            'date_format' => in_array( $raw['date_format'] ?? '', [ 'relative', 'absolute' ], true )
                ? $raw['date_format']
                : 'relative',
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [
                'type'        => 'user_meta',
                'meta_key'    => 'wpt_last_login',
                'description' => __( 'Last login timestamps for all users', 'wptransformed' ),
            ],
        ];
    }
}
