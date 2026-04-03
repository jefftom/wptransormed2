<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Preserve Taxonomy Hierarchy -- Keep checked terms in their original position.
 *
 * By default WordPress moves checked taxonomy terms to the top of the checklist,
 * breaking the visual hierarchy. This module sets `checked_ontop` to false
 * so checked terms stay in their original position within the tree.
 *
 * @package WPTransformed
 */
class Preserve_Taxonomy_Hierarchy extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'preserve-taxonomy-hierarchy';
    }

    public function get_title(): string {
        return __( 'Preserve Taxonomy Hierarchy', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Keep checked taxonomy terms in their hierarchical position instead of moving them to the top.', 'wptransformed' );
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

        add_filter( 'wp_terms_checklist_args', [ $this, 'disable_checked_ontop' ] );
    }

    // -- Hook Callbacks ---------------------------------------------------

    /**
     * Set checked_ontop to false so checked terms stay in place.
     *
     * @param array<string, mixed> $args Terms checklist arguments.
     * @return array<string, mixed> Modified arguments.
     */
    public function disable_checked_ontop( array $args ): array {
        $args['checked_ontop'] = false;
        return $args;
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Info', 'wptransformed' ); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e( 'Checked categories and other hierarchical taxonomy terms will remain in their original position in the checklist rather than being moved to the top.', 'wptransformed' ); ?>
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
