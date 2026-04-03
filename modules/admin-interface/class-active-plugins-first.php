<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Active Plugins First -- Sort plugins list with active plugins on top.
 *
 * Filters the `all_plugins` list on the plugins page so that active plugins
 * appear first (alphabetically), followed by inactive plugins (alphabetically),
 * with a visual separator between the two groups.
 *
 * @package WPTransformed
 */
class Active_Plugins_First extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'active-plugins-first';
    }

    public function get_title(): string {
        return __( 'Active Plugins First', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Sort the plugins list so active plugins appear before inactive ones.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_filter( 'all_plugins', [ $this, 'sort_active_first' ] );
        add_action( 'admin_head-plugins.php', [ $this, 'output_separator_css' ] );
    }

    // -- Hook Callbacks ---------------------------------------------------

    /**
     * Sort plugins: active first (alpha), then inactive (alpha).
     *
     * @param array<string, array<string, mixed>> $plugins All plugins keyed by file.
     * @return array<string, array<string, mixed>> Sorted plugins.
     */
    public function sort_active_first( array $plugins ): array {
        $active_plugins = (array) get_option( 'active_plugins', [] );
        $active_lookup  = array_flip( $active_plugins );

        $active   = [];
        $inactive = [];

        foreach ( $plugins as $file => $data ) {
            if ( isset( $active_lookup[ $file ] ) ) {
                $active[ $file ] = $data;
            } else {
                $inactive[ $file ] = $data;
            }
        }

        uasort( $active, [ $this, 'compare_by_name' ] );
        uasort( $inactive, [ $this, 'compare_by_name' ] );

        return array_merge( $active, $inactive );
    }

    /**
     * Compare two plugins by name for sorting.
     */
    public function compare_by_name( array $a, array $b ): int {
        return strnatcasecmp( $a['Name'] ?? '', $b['Name'] ?? '' );
    }

    /**
     * Output CSS for the visual separator between active and inactive groups.
     */
    public function output_separator_css(): void {
        ?>
<style id="wpt-active-plugins-first">
.plugins tr.wpt-active-separator td,
.plugins tr.wpt-active-separator th {
    border-bottom: 3px solid #2271b1;
    box-shadow: 0 2px 0 rgba(34, 113, 177, 0.15);
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var rows = document.querySelectorAll('.plugins .active');
    if (!rows.length) return;
    var lastActive = rows[rows.length - 1];
    if (lastActive) {
        lastActive.classList.add('wpt-active-separator');
    }
});
</script>
        <?php
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Info', 'wptransformed' ); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e( 'Active plugins will be sorted to the top of the plugins list, with a visual separator between active and inactive groups.', 'wptransformed' ); ?>
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
