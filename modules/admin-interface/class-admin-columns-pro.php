<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Columns Pro -- Full column management per post type.
 *
 * Features:
 *  - Custom column configuration per post type
 *  - Support for ACF fields, taxonomy terms, custom meta
 *  - Horizontal scroll on wide list tables
 *  - Per-type column show/hide settings
 *
 * @package WPTransformed
 */
class Admin_Columns_Pro extends Module_Base {

    /**
     * Cached settings to avoid repeated DB reads during column rendering.
     *
     * @var array|null
     */
    private ?array $cached_settings = null;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'admin-columns-pro';
    }

    public function get_title(): string {
        return __( 'Admin Columns Pro', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Full column management per post type. Support ACF fields, taxonomy terms, and custom meta with horizontal scroll.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'columns'                  => [],
            'enable_horizontal_scroll' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'admin_init', [ $this, 'register_column_hooks' ] );
    }

    public function register_column_hooks(): void {
        $settings             = $this->get_settings();
        $this->cached_settings = $settings;
        $columns               = $settings['columns'];
        $post_types = $this->get_manageable_post_types();

        foreach ( $post_types as $type ) {
            if ( empty( $columns[ $type ] ) || ! is_array( $columns[ $type ] ) ) {
                continue;
            }

            add_filter( "manage_{$type}_posts_columns", function ( $existing ) use ( $type, $columns ) {
                return $this->filter_columns( $existing, $type, $columns[ $type ] );
            } );

            add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_custom_column' ], 10, 2 );
        }

        if ( ! empty( $settings['enable_horizontal_scroll'] ) ) {
            add_action( 'admin_head', [ $this, 'output_horizontal_scroll_css' ] );
        }
    }

    private function filter_columns( array $existing, string $type, array $config ): array {
        foreach ( $config as $col ) {
            if ( empty( $col['key'] ) || empty( $col['label'] ) || empty( $col['enabled'] ) ) {
                continue;
            }
            $key = sanitize_key( $col['key'] );
            $existing[ 'wpt_' . $key ] = esc_html( $col['label'] );
        }

        return $existing;
    }

    public function render_custom_column( string $column, int $post_id ): void {
        if ( strpos( $column, 'wpt_' ) !== 0 ) {
            return;
        }

        $settings  = $this->cached_settings ?? $this->get_settings();
        $post_type = get_post_type( $post_id );
        $columns   = $settings['columns'];
        $meta_key  = substr( $column, 4 ); // Remove wpt_ prefix.

        if ( empty( $columns[ $post_type ] ) ) {
            return;
        }

        $col_config = null;
        foreach ( $columns[ $post_type ] as $col ) {
            if ( isset( $col['key'] ) && sanitize_key( $col['key'] ) === $meta_key ) {
                $col_config = $col;
                break;
            }
        }

        if ( ! $col_config ) {
            return;
        }

        $col_type = $col_config['type'] ?? 'meta';

        switch ( $col_type ) {
            case 'taxonomy':
                $terms = get_the_terms( $post_id, $meta_key );
                if ( is_array( $terms ) ) {
                    $names = wp_list_pluck( $terms, 'name' );
                    echo esc_html( implode( ', ', $names ) );
                } else {
                    echo '&mdash;';
                }
                break;

            case 'acf':
                if ( function_exists( 'get_field' ) ) {
                    $value = get_field( $meta_key, $post_id );
                    if ( is_array( $value ) ) {
                        echo esc_html( implode( ', ', array_map( 'strval', $value ) ) );
                    } else {
                        echo esc_html( (string) $value );
                    }
                } else {
                    echo '&mdash;';
                }
                break;

            case 'meta':
            default:
                $value = get_post_meta( $post_id, $meta_key, true );
                echo esc_html( is_scalar( $value ) ? (string) $value : '' );
                break;
        }
    }

    public function output_horizontal_scroll_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'edit' ) {
            return;
        }
        echo '<style>.wp-list-table-wrap,.wrap{overflow-x:auto}.wp-list-table{min-width:800px}</style>';
    }

    private function get_manageable_post_types(): array {
        $types = get_post_types( [ 'show_ui' => true ], 'names' );
        return is_array( $types ) ? array_values( $types ) : [];
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings   = $this->get_settings();
        $post_types = $this->get_manageable_post_types();
        $columns    = $settings['columns'];
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-horizontal-scroll">
                        <?php esc_html_e( 'Horizontal Scroll', 'wptransformed' ); ?>
                    </label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wpt-horizontal-scroll"
                               name="wpt_enable_horizontal_scroll" value="1"
                               <?php checked( ! empty( $settings['enable_horizontal_scroll'] ) ); ?>>
                        <?php esc_html_e( 'Enable horizontal scrolling on wide list tables.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Column Configuration', 'wptransformed' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Add custom columns per post type. Type can be "meta" (post meta key), "taxonomy" (taxonomy slug), or "acf" (ACF field name).', 'wptransformed' ); ?>
        </p>

        <?php foreach ( $post_types as $type ) :
            $type_obj    = get_post_type_object( $type );
            $type_label  = $type_obj ? $type_obj->labels->name : $type;
            $type_cols   = isset( $columns[ $type ] ) && is_array( $columns[ $type ] ) ? $columns[ $type ] : [];
        ?>
            <h4><?php echo esc_html( $type_label ); ?> (<code><?php echo esc_html( $type ); ?></code>)</h4>
            <div class="wpt-columns-config" data-post-type="<?php echo esc_attr( $type ); ?>">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Enabled', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Key', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Label', 'wptransformed' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'wptransformed' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = ! empty( $type_cols ) ? $type_cols : [ [ 'key' => '', 'label' => '', 'type' => 'meta', 'enabled' => false ] ];
                        foreach ( $rows as $i => $col ) :
                            $prefix = 'wpt_columns[' . esc_attr( $type ) . '][' . $i . ']';
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( $prefix . '[enabled]' ); ?>" value="1"
                                       <?php checked( ! empty( $col['enabled'] ) ); ?>>
                            </td>
                            <td>
                                <input type="text"
                                       name="<?php echo esc_attr( $prefix . '[key]' ); ?>"
                                       value="<?php echo esc_attr( $col['key'] ?? '' ); ?>"
                                       class="regular-text" placeholder="meta_key or taxonomy_slug">
                            </td>
                            <td>
                                <input type="text"
                                       name="<?php echo esc_attr( $prefix . '[label]' ); ?>"
                                       value="<?php echo esc_attr( $col['label'] ?? '' ); ?>"
                                       class="regular-text" placeholder="Column Label">
                            </td>
                            <td>
                                <select name="<?php echo esc_attr( $prefix . '[type]' ); ?>">
                                    <option value="meta" <?php selected( ( $col['type'] ?? 'meta' ), 'meta' ); ?>>
                                        <?php esc_html_e( 'Meta', 'wptransformed' ); ?>
                                    </option>
                                    <option value="taxonomy" <?php selected( ( $col['type'] ?? '' ), 'taxonomy' ); ?>>
                                        <?php esc_html_e( 'Taxonomy', 'wptransformed' ); ?>
                                    </option>
                                    <option value="acf" <?php selected( ( $col['type'] ?? '' ), 'acf' ); ?>>
                                        <?php esc_html_e( 'ACF Field', 'wptransformed' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $sanitized = [];

        $sanitized['enable_horizontal_scroll'] = ! empty( $raw['wpt_enable_horizontal_scroll'] );

        $columns = [];
        if ( isset( $raw['wpt_columns'] ) && is_array( $raw['wpt_columns'] ) ) {
            $valid_types = [ 'meta', 'taxonomy', 'acf' ];
            foreach ( $raw['wpt_columns'] as $type => $cols ) {
                $type = sanitize_key( $type );
                if ( ! is_array( $cols ) ) {
                    continue;
                }
                $columns[ $type ] = [];
                foreach ( $cols as $col ) {
                    if ( empty( $col['key'] ) ) {
                        continue;
                    }
                    $columns[ $type ][] = [
                        'key'     => sanitize_key( $col['key'] ),
                        'label'   => sanitize_text_field( $col['label'] ?? '' ),
                        'type'    => in_array( $col['type'] ?? 'meta', $valid_types, true ) ? $col['type'] : 'meta',
                        'enabled' => ! empty( $col['enabled'] ),
                    ];
                }
            }
        }
        $sanitized['columns'] = $columns;

        return $sanitized;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No additional assets needed — inline CSS handles scroll.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'settings' => true,
        ];
    }
}
