<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Gutenberg — Revert to the Classic Editor for selected post types.
 *
 * Features:
 *  - Disable block editor per post type (never disables for wp_template)
 *  - Optionally disable the block-based widget editor
 *  - Warn when a block theme is active (block editor cannot be fully removed)
 *  - Enable Classic Editor loading for compatibility
 *
 * @package WPTransformed
 */
class Disable_Gutenberg extends Module_Base {

    /**
     * Cached list of post types to disable the block editor for.
     *
     * @var string[]|null
     */
    private ?array $disable_for_cache = null;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-gutenberg';
    }

    public function get_title(): string {
        return __( 'Disable Gutenberg', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Disable the block editor (Gutenberg) for selected post types and revert to the Classic Editor.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'disable_for'          => [ 'post', 'page' ],
            'enable_classic'       => true,
            'disable_block_widgets' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        add_filter( 'use_block_editor_for_post_type', [ $this, 'filter_block_editor_for_post_type' ], 10, 2 );

        if ( ! empty( $settings['disable_block_widgets'] ) ) {
            add_filter( 'use_widgets_block_editor', '__return_false' );
        }

        if ( ! empty( $settings['enable_classic'] ) ) {
            add_action( 'admin_init', [ $this, 'maybe_dequeue_block_assets' ] );
        }
    }

    // ── Filters ───────────────────────────────────────────────

    /**
     * Disable the block editor for configured post types.
     *
     * Never disables for wp_template — that is required for
     * Full Site Editing and block themes to function.
     *
     * @param bool   $use_block_editor Whether the block editor should be used.
     * @param string $post_type        The post type being checked.
     * @return bool
     */
    public function filter_block_editor_for_post_type( bool $use_block_editor, string $post_type ): bool {
        if ( $post_type === 'wp_template' || $post_type === 'wp_template_part' ) {
            return $use_block_editor;
        }

        if ( $this->disable_for_cache === null ) {
            $settings = $this->get_settings();
            $this->disable_for_cache = (array) ( $settings['disable_for'] ?? [] );
        }

        if ( in_array( $post_type, $this->disable_for_cache, true ) ) {
            return false;
        }

        return $use_block_editor;
    }

    /**
     * Dequeue Gutenberg block library CSS/JS on screens where
     * the classic editor is used, to reduce asset overhead.
     */
    public function maybe_dequeue_block_assets(): void {
        add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
            if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
                return;
            }

            $screen = get_current_screen();
            if ( ! $screen || empty( $screen->post_type ) ) {
                return;
            }

            if ( $this->disable_for_cache === null ) {
                $settings = $this->get_settings();
                $this->disable_for_cache = (array) ( $settings['disable_for'] ?? [] );
            }

            if ( in_array( $screen->post_type, $this->disable_for_cache, true ) ) {
                wp_dequeue_script( 'wp-edit-blocks' );
                wp_dequeue_style( 'wp-edit-blocks' );
            }
        } );
    }

    // ── Admin UI ──────────────────────────────────────────────

    public function render_settings(): void {
        $settings         = $this->get_settings();
        $disable_for      = (array) ( $settings['disable_for'] ?? [] );
        $is_block_theme   = $this->is_block_theme_active();
        $post_types       = $this->get_eligible_post_types();
        ?>

        <?php if ( $is_block_theme ) : ?>
            <div class="notice notice-warning inline" style="margin: 10px 0;">
                <p>
                    <strong><?php esc_html_e( 'Warning:', 'wptransformed' ); ?></strong>
                    <?php esc_html_e( 'Your active theme is a block theme. Disabling Gutenberg may cause layout and editing issues. The block editor for wp_template and wp_template_part will remain enabled regardless of settings.', 'wptransformed' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable For Post Types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $post_types as $pt_obj ) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="checkbox" name="wpt_disable_for[]"
                                       value="<?php echo esc_attr( $pt_obj->name ); ?>"
                                       <?php checked( in_array( $pt_obj->name, $disable_for, true ) ); ?>>
                                <?php echo esc_html( $pt_obj->labels->singular_name ); ?>
                                <span style="color: #888;">(<?php echo esc_html( $pt_obj->name ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Select post types where the block editor should be replaced with the Classic Editor. wp_template is always excluded.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Classic Editor', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enable_classic" value="1"
                               <?php checked( ! empty( $settings['enable_classic'] ) ); ?>>
                        <?php esc_html_e( 'Dequeue block editor assets on classic editor screens', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Reduces unnecessary CSS/JS loading when the block editor is disabled for a post type.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Block Widgets', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_disable_block_widgets" value="1"
                               <?php checked( ! empty( $settings['disable_block_widgets'] ) ); ?>>
                        <?php esc_html_e( 'Disable the block-based widget editor (revert to classic widgets)', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Restores the traditional widget interface under Appearance > Widgets.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $eligible     = array_keys( $this->get_eligible_post_types() );
        $submitted    = (array) ( $raw['wpt_disable_for'] ?? [] );
        $disable_for  = array_values( array_intersect(
            array_map( 'sanitize_key', $submitted ),
            $eligible
        ) );

        return [
            'disable_for'          => $disable_for,
            'enable_classic'       => ! empty( $raw['wpt_enable_classic'] ),
            'disable_block_widgets' => ! empty( $raw['wpt_disable_block_widgets'] ),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Check if the active theme is a block theme.
     */
    private function is_block_theme_active(): bool {
        if ( function_exists( 'wp_is_block_theme' ) ) {
            return wp_is_block_theme();
        }

        return false;
    }

    /**
     * Get post types eligible for editor control.
     *
     * Returns public post types that support the editor,
     * excluding wp_template and wp_template_part.
     *
     * @return array<string, \WP_Post_Type>
     */
    private function get_eligible_post_types(): array {
        $post_types = get_post_types( [ 'show_ui' => true ], 'objects' );
        $eligible   = [];

        foreach ( $post_types as $pt_obj ) {
            // Skip FSE template types — block editor must stay enabled for these.
            if ( in_array( $pt_obj->name, [ 'wp_template', 'wp_template_part' ], true ) ) {
                continue;
            }

            // Only include types that actually show an editor.
            if ( post_type_supports( $pt_obj->name, 'editor' ) ) {
                $eligible[ $pt_obj->name ] = $pt_obj;
            }
        }

        return $eligible;
    }

    // ── Cleanup ──────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'options' => [],
        ];
    }
}
