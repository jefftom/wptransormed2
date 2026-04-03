<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Registration Date Column -- Add a "Registered" column to the Users list table.
 *
 * Displays the user registration date formatted per site settings, with optional
 * sortable column support.
 *
 * @package WPTransformed
 */
class Registration_Date_Column extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'registration-date-column';
    }

    public function get_title(): string {
        return __( 'Registration Date Column', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add a sortable registration date column to the Users list table.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enabled'  => true,
            'sortable' => true,
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_filter( 'manage_users_columns', [ $this, 'add_column' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_column' ], 10, 3 );

        if ( ! empty( $settings['sortable'] ) ) {
            add_filter( 'manage_users_sortable_columns', [ $this, 'sortable_column' ] );
            add_action( 'pre_get_users', [ $this, 'handle_sort' ] );
        }
    }

    /**
     * Add the Registered column.
     *
     * @param array<string,string> $columns Existing columns.
     * @return array<string,string>
     */
    public function add_column( array $columns ): array {
        $columns['wpt_registered'] = __( 'Registered', 'wptransformed' );
        return $columns;
    }

    /**
     * Render the column value.
     *
     * @param string $output      Current column output.
     * @param string $column_name Column identifier.
     * @param int    $user_id     User ID.
     * @return string
     */
    public function render_column( string $output, string $column_name, int $user_id ): string {
        if ( 'wpt_registered' !== $column_name ) {
            return $output;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_registered ) ) {
            return '—';
        }

        $timestamp  = strtotime( $user->user_registered );
        if ( false === $timestamp ) {
            return '—';
        }

        $date_format = get_option( 'date_format', 'Y-m-d' );
        $time_format = get_option( 'time_format', 'H:i' );

        return esc_html( date_i18n( $date_format . ' ' . $time_format, $timestamp ) );
    }

    /**
     * Make the column sortable.
     *
     * @param array<string,string> $columns Sortable columns.
     * @return array<string,string>
     */
    public function sortable_column( array $columns ): array {
        $columns['wpt_registered'] = 'registered';
        return $columns;
    }

    /**
     * Handle the sort query.
     *
     * @param \WP_User_Query $query User query.
     */
    public function handle_sort( \WP_User_Query $query ): void {
        if ( ! is_admin() ) {
            return;
        }

        $orderby = $query->get( 'orderby' );
        if ( 'registered' === $orderby ) {
            $query->set( 'orderby', 'registered' );
        }
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Show registration date column on Users list', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Sortable', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_sortable" value="1"
                               <?php checked( ! empty( $settings['sortable'] ) ); ?>>
                        <?php esc_html_e( 'Allow sorting by registration date', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled'  => ! empty( $raw['wpt_enabled'] ),
            'sortable' => ! empty( $raw['wpt_sortable'] ),
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
