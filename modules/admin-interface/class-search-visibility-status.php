<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Search Visibility Status -- Warn when search engines are discouraged.
 *
 * Adds a red warning node to the admin bar and a dismissible admin notice
 * when the "Discourage search engines" option is enabled in Settings > Reading.
 *
 * @package WPTransformed
 */
class Search_Visibility_Status extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'search-visibility-status';
    }

    public function get_title(): string {
        return __( 'Search Visibility Status', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Show a prominent warning when search engine indexing is discouraged.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() && ! is_admin_bar_showing() ) {
            return;
        }

        add_action( 'admin_bar_menu', [ $this, 'add_warning_node' ], 100 );
        add_action( 'admin_notices', [ $this, 'show_warning_notice' ] );
        add_action( 'admin_head', [ $this, 'output_admin_bar_css' ] );
        add_action( 'wp_head', [ $this, 'output_admin_bar_css' ] );
    }

    // -- Hook Callbacks ---------------------------------------------------

    /**
     * Whether search engines are currently discouraged.
     */
    private function is_search_discouraged(): bool {
        return '0' === (string) get_option( 'blog_public' );
    }

    /**
     * Add a red warning node to the admin bar when blog_public is 0.
     */
    public function add_warning_node( \WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! $this->is_search_discouraged() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $wp_admin_bar->add_node( [
            'id'    => 'wpt-search-visibility-warning',
            'title' => '<span class="ab-icon dashicons dashicons-warning" style="margin-top:2px;"></span>'
                     . esc_html__( 'Search Engines Discouraged', 'wptransformed' ),
            'href'  => esc_url( admin_url( 'options-reading.php' ) ),
            'meta'  => [
                'class' => 'wpt-search-visibility-warning',
                'title' => esc_attr__( 'Search engine visibility is set to discourage indexing', 'wptransformed' ),
            ],
        ] );
    }

    /**
     * Output CSS to style the admin bar warning node red.
     */
    public function output_admin_bar_css(): void {
        if ( ! $this->is_search_discouraged() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
<style id="wpt-search-visibility-status">
#wpadminbar .wpt-search-visibility-warning > .ab-item {
    background: #dc3232 !important;
    color: #fff !important;
}
#wpadminbar .wpt-search-visibility-warning:hover > .ab-item {
    background: #c62d2d !important;
    color: #fff !important;
}
#wpadminbar .wpt-search-visibility-warning .ab-icon:before {
    color: #fff !important;
    font-size: 16px;
    line-height: 1;
}
</style>
        <?php
    }

    /**
     * Show a dismissible warning banner on admin pages.
     */
    public function show_warning_notice(): void {
        if ( ! $this->is_search_discouraged() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $reading_url = esc_url( admin_url( 'options-reading.php' ) );

        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__( 'Search Engines Discouraged:', 'wptransformed' ),
            esc_html__( 'This site is currently set to discourage search engine indexing.', 'wptransformed' ),
            $reading_url,
            esc_html__( 'Change Reading Settings', 'wptransformed' )
        );
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                <td>
                    <?php if ( $this->is_search_discouraged() ) : ?>
                        <span style="color:#dc3232;font-weight:600;">
                            <?php esc_html_e( 'Search engines are currently discouraged from indexing this site.', 'wptransformed' ); ?>
                        </span>
                    <?php else : ?>
                        <span style="color:#46b450;font-weight:600;">
                            <?php esc_html_e( 'Search engine indexing is allowed.', 'wptransformed' ); ?>
                        </span>
                    <?php endif; ?>
                    <p class="description">
                        <?php esc_html_e( 'This module shows a warning in the admin bar and a notice banner when indexing is discouraged.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
