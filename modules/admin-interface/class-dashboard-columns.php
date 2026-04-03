<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Dashboard Columns -- Override the WordPress dashboard layout column count.
 *
 * Injects CSS grid rules on the dashboard screen to force a specific
 * number of columns (1-4) instead of the default responsive behavior.
 *
 * @package WPTransformed
 */
class Dashboard_Columns extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'dashboard-columns';
    }

    public function get_title(): string {
        return __( 'Dashboard Columns', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Set a fixed number of columns (1-4) for the WordPress dashboard layout.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'columns' => 2,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_head', [ $this, 'output_dashboard_columns_css' ] );
    }

    // -- Hook Callback ----------------------------------------------------

    /**
     * Output CSS to force dashboard column count on the dashboard screen only.
     */
    public function output_dashboard_columns_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'dashboard' !== $screen->id ) {
            return;
        }

        $settings = $this->get_settings();
        $columns  = max( 1, min( 4, (int) $settings['columns'] ) );

        ?>
<style id="wpt-dashboard-columns">
#dashboard-widgets-wrap {
    display: grid !important;
    grid-template-columns: repeat(<?php echo esc_attr( (string) $columns ); ?>, 1fr) !important;
    gap: 20px;
}
#dashboard-widgets-wrap .postbox-container {
    width: 100% !important;
    float: none !important;
}
#dashboard-widgets-wrap .meta-box-sortables {
    min-height: 0 !important;
}
@media screen and (max-width: 799px) {
    #dashboard-widgets-wrap {
        grid-template-columns: 1fr !important;
    }
}
</style>
        <?php
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_columns"><?php esc_html_e( 'Number of Columns', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt_columns" name="wpt_columns">
                        <?php for ( $i = 1; $i <= 4; $i++ ) : ?>
                            <option value="<?php echo esc_attr( (string) $i ); ?>"
                                    <?php selected( (int) $settings['columns'], $i ); ?>>
                                <?php echo esc_html( (string) $i ); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Choose how many columns to display on the WordPress dashboard (default: 2).', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $columns = isset( $raw['wpt_columns'] ) ? (int) $raw['wpt_columns'] : 2;
        $columns = max( 1, min( 4, $columns ) );

        return [
            'columns' => $columns,
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
