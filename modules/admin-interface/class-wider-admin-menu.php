<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Wider Admin Menu -- Increase the WordPress admin sidebar width.
 *
 * Outputs CSS on admin_head to override the default 160px menu width,
 * adjusting #adminmenu, submenus, #wpcontent, and #wpfooter margins.
 * Handles the responsive collapse breakpoint.
 *
 * @package WPTransformed
 */
class Wider_Admin_Menu extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'wider-admin-menu';
    }

    public function get_title(): string {
        return __( 'Wider Admin Menu', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Increase the width of the WordPress admin sidebar menu beyond the default 160px.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'width' => 200,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_head', [ $this, 'output_menu_width_css' ] );
    }

    // -- Hook Callback ----------------------------------------------------

    /**
     * Output CSS that widens the admin menu.
     */
    public function output_menu_width_css(): void {
        $settings = $this->get_settings();
        $width    = (int) $settings['width'];

        // Clamp to a reasonable range.
        $width = max( 160, min( 400, $width ) );

        // No change needed if at the WordPress default.
        if ( $width === 160 ) {
            return;
        }

        $width_px = $width . 'px';

        ?>
<style id="wpt-wider-admin-menu">
#adminmenuback,
#adminmenuwrap,
#adminmenu,
#adminmenu .wp-submenu {
    width: <?php echo esc_attr( $width_px ); ?>;
}
#wpcontent,
#wpfooter {
    margin-left: <?php echo esc_attr( $width_px ); ?>;
}
#adminmenu .wp-submenu {
    left: <?php echo esc_attr( $width_px ); ?>;
}
#adminmenu .wp-not-current-submenu .wp-submenu,
.folded #adminmenu .wp-has-current-submenu .wp-submenu {
    min-width: <?php echo esc_attr( $width_px ); ?>;
}
/* Handle collapsed/folded state: keep WP defaults when folded */
@media screen and (max-width: 960px) {
    .auto-fold #adminmenuback,
    .auto-fold #adminmenuwrap,
    .auto-fold #adminmenu,
    .auto-fold #adminmenu .wp-submenu {
        width: 36px;
    }
    .auto-fold #wpcontent,
    .auto-fold #wpfooter {
        margin-left: 36px;
    }
    .auto-fold #adminmenu .wp-submenu {
        left: 36px;
    }
}
.folded #adminmenuback,
.folded #adminmenuwrap,
.folded #adminmenu,
.folded #adminmenu .wp-submenu {
    width: 36px;
}
.folded #wpcontent,
.folded #wpfooter {
    margin-left: 36px;
}
.folded #adminmenu .wp-submenu {
    left: 36px;
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
                    <label for="wpt_width"><?php esc_html_e( 'Menu Width (px)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="wpt_width"
                           name="wpt_width"
                           value="<?php echo esc_attr( (string) $settings['width'] ); ?>"
                           min="160"
                           max="400"
                           step="10"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'WordPress default is 160px. Recommended range: 180-280px.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $width = isset( $raw['wpt_width'] ) ? (int) $raw['wpt_width'] : 200;
        $width = max( 160, min( 400, $width ) );

        return [
            'width' => $width,
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
