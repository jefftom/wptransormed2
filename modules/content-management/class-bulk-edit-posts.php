<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Bulk Edit Posts -- Extended bulk editing with custom field support.
 *
 * @package WPTransformed
 */
class Bulk_Edit_Posts extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'bulk-edit-posts';
    }

    public function get_title(): string {
        return __( 'Bulk Edit Posts', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Extended bulk editing with support for custom fields, author, date, and status changes.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enabled_for'   => [ 'post', 'page' ],
            'custom_fields' => [],
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        add_action( 'bulk_edit_custom_box', [ $this, 'render_bulk_edit_fields' ], 10, 2 );
        add_action( 'wp_ajax_wpt_bulk_edit_save', [ $this, 'ajax_save_bulk_edit' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // -- Bulk Edit Fields --

    /**
     * Render custom fields in the bulk edit box.
     *
     * @param string $column_name The column being rendered.
     * @param string $post_type   The current post type.
     */
    public function render_bulk_edit_fields( string $column_name, string $post_type ): void {
        // Only render once, on the first column
        static $rendered = false;
        if ( $rendered ) {
            return;
        }

        $settings   = $this->get_settings();
        $post_types = (array) $settings['enabled_for'];

        if ( ! in_array( $post_type, $post_types, true ) ) {
            return;
        }

        $rendered = true;
        $custom_fields = (array) $settings['custom_fields'];

        if ( empty( $custom_fields ) ) {
            return;
        }

        ?>
        <fieldset class="inline-edit-col-right wpt-bulk-edit-fields">
            <div class="inline-edit-col">
                <h4><?php esc_html_e( 'WPTransformed Custom Fields', 'wptransformed' ); ?></h4>
                <?php foreach ( $custom_fields as $field ) :
                    $field_key  = sanitize_key( $field['key'] ?? '' );
                    $field_name = sanitize_text_field( $field['label'] ?? $field_key );
                    if ( empty( $field_key ) ) {
                        continue;
                    }
                    ?>
                    <label class="inline-edit-group">
                        <span class="title"><?php echo esc_html( $field_name ); ?></span>
                        <span class="input-text-wrap">
                            <select name="wpt_bulk_action_<?php echo esc_attr( $field_key ); ?>" class="wpt-bulk-action-select">
                                <option value=""><?php esc_html_e( '-- No Change --', 'wptransformed' ); ?></option>
                                <option value="set"><?php esc_html_e( 'Set to', 'wptransformed' ); ?></option>
                                <option value="clear"><?php esc_html_e( 'Clear', 'wptransformed' ); ?></option>
                            </select>
                            <input type="text"
                                   name="wpt_bulk_field_<?php echo esc_attr( $field_key ); ?>"
                                   class="wpt-bulk-field-input"
                                   value=""
                                   placeholder="<?php echo esc_attr( $field_name ); ?>">
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    // -- AJAX Save --

    /**
     * Handle bulk edit save via AJAX.
     */
    public function ajax_save_bulk_edit(): void {
        check_ajax_referer( 'wpt_bulk_edit_save', 'wpt_nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : [];
        if ( empty( $post_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No posts selected.', 'wptransformed' ) ] );
        }

        $settings      = $this->get_settings();
        $custom_fields = (array) $settings['custom_fields'];
        $updated       = 0;

        foreach ( $post_ids as $post_id ) {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            // Process custom fields -- empty field does NOT clear
            foreach ( $custom_fields as $field ) {
                $field_key = sanitize_key( $field['key'] ?? '' );
                if ( empty( $field_key ) ) {
                    continue;
                }

                $action_key = 'wpt_bulk_action_' . $field_key;
                $value_key  = 'wpt_bulk_field_' . $field_key;
                $action     = isset( $_POST[ $action_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $action_key ] ) ) : '';

                if ( $action === 'set' ) {
                    $value = isset( $_POST[ $value_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $value_key ] ) ) : '';
                    update_post_meta( $post_id, $field_key, $value );
                } elseif ( $action === 'clear' ) {
                    delete_post_meta( $post_id, $field_key );
                }
                // Empty action = no change (intentional)
            }

            $updated++;
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: number of posts updated */
                __( '%d posts updated.', 'wptransformed' ),
                $updated
            ),
        ] );
    }

    // -- Assets --

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'edit.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $settings   = $this->get_settings();
        $post_types = (array) $settings['enabled_for'];

        if ( ! in_array( $screen->post_type, $post_types, true ) ) {
            return;
        }

        wp_register_script( 'wpt-bulk-edit-posts', '', [], WPT_VERSION, true );
        wp_enqueue_script( 'wpt-bulk-edit-posts' );

        wp_localize_script( 'wpt-bulk-edit-posts', 'wptBulkEdit', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_bulk_edit_save' ),
        ] );

        wp_add_inline_script( 'wpt-bulk-edit-posts', $this->get_inline_js() );
        wp_add_inline_style( 'wp-admin', $this->get_inline_css() );
    }

    /**
     * Inline JS for bulk edit integration.
     */
    private function get_inline_js(): string {
        return <<<'JS'
(function() {
    "use strict";

    // Hook into WordPress bulk edit save
    var wpInlineEdit = window.inlineEditPost;
    if (!wpInlineEdit) return;

    var origSave = wpInlineEdit.save;
    wpInlineEdit.save = function(id) {
        // Call original save first
        origSave.apply(this, arguments);

        // Get post IDs from bulk edit
        var bulkRow = document.getElementById('bulk-edit');
        if (!bulkRow) return;

        var checkboxes = document.querySelectorAll('tbody th.check-column input[type="checkbox"]:checked');
        var postIds = [];
        checkboxes.forEach(function(cb) {
            if (cb.value && cb.value !== 'on') {
                postIds.push(cb.value);
            }
        });

        if (postIds.length === 0) return;

        // Collect custom field values
        var data = new FormData();
        data.append('action', 'wpt_bulk_edit_save');
        data.append('wpt_nonce', wptBulkEdit.nonce);

        postIds.forEach(function(pid) {
            data.append('post_ids[]', pid);
        });

        // Collect action selects and field inputs
        var selects = bulkRow.querySelectorAll('.wpt-bulk-action-select');
        selects.forEach(function(sel) {
            data.append(sel.name, sel.value);
        });

        var inputs = bulkRow.querySelectorAll('.wpt-bulk-field-input');
        inputs.forEach(function(inp) {
            data.append(inp.name, inp.value);
        });

        fetch(wptBulkEdit.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        });
    };
})();
JS;
    }

    /**
     * Inline CSS for bulk edit fields.
     */
    private function get_inline_css(): string {
        return <<<'CSS'
.wpt-bulk-edit-fields .inline-edit-group {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}
.wpt-bulk-edit-fields .wpt-bulk-action-select {
    margin-right: 8px;
    min-width: 120px;
}
CSS;
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings      = $this->get_settings();
        $post_types    = get_post_types( [ 'public' => true ], 'objects' );
        $custom_fields = (array) $settings['custom_fields'];

        unset( $post_types['attachment'] );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enabled Post Types', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $post_types as $pt ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_enabled_for[]"
                                       value="<?php echo esc_attr( $pt->name ); ?>"
                                       <?php checked( in_array( $pt->name, (array) $settings['enabled_for'], true ) ); ?>>
                                <?php echo esc_html( $pt->labels->singular_name ); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Custom Fields', 'wptransformed' ); ?></th>
                <td>
                    <div id="wpt-custom-fields-list">
                        <?php foreach ( $custom_fields as $i => $field ) : ?>
                            <div class="wpt-custom-field-row" style="margin-bottom: 8px;">
                                <input type="text"
                                       name="wpt_custom_fields[<?php echo (int) $i; ?>][key]"
                                       value="<?php echo esc_attr( $field['key'] ?? '' ); ?>"
                                       placeholder="<?php esc_attr_e( 'meta_key', 'wptransformed' ); ?>"
                                       class="regular-text">
                                <input type="text"
                                       name="wpt_custom_fields[<?php echo (int) $i; ?>][label]"
                                       value="<?php echo esc_attr( $field['label'] ?? '' ); ?>"
                                       placeholder="<?php esc_attr_e( 'Label', 'wptransformed' ); ?>"
                                       class="regular-text">
                                <button type="button" class="button wpt-remove-field"><?php esc_html_e( 'Remove', 'wptransformed' ); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="wpt-add-custom-field"><?php esc_html_e( 'Add Field', 'wptransformed' ); ?></button>
                    <p class="description"><?php esc_html_e( 'Define custom meta fields available in bulk edit. Each field needs a meta key and display label.', 'wptransformed' ); ?></p>
                    <script>
                    (function() {
                        var idx = <?php echo count( $custom_fields ); ?>;
                        document.getElementById('wpt-add-custom-field').addEventListener('click', function() {
                            var list = document.getElementById('wpt-custom-fields-list');
                            var row = document.createElement('div');
                            row.className = 'wpt-custom-field-row';
                            row.style.marginBottom = '8px';
                            row.innerHTML = '<input type="text" name="wpt_custom_fields[' + idx + '][key]" value="" placeholder="meta_key" class="regular-text"> ' +
                                '<input type="text" name="wpt_custom_fields[' + idx + '][label]" value="" placeholder="Label" class="regular-text"> ' +
                                '<button type="button" class="button wpt-remove-field">Remove</button>';
                            list.appendChild(row);
                            idx++;
                        });
                        document.addEventListener('click', function(e) {
                            if (e.target.classList.contains('wpt-remove-field')) {
                                e.target.parentNode.remove();
                            }
                        });
                    })();
                    </script>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        $allowed_post_types = array_diff(
            array_keys( get_post_types( [ 'public' => true ] ) ),
            [ 'attachment' ]
        );

        $enabled_for = isset( $raw['wpt_enabled_for'] ) && is_array( $raw['wpt_enabled_for'] )
            ? array_values( array_intersect( array_map( 'sanitize_key', $raw['wpt_enabled_for'] ), $allowed_post_types ) )
            : [];

        $custom_fields = [];
        if ( isset( $raw['wpt_custom_fields'] ) && is_array( $raw['wpt_custom_fields'] ) ) {
            foreach ( $raw['wpt_custom_fields'] as $field ) {
                $key   = sanitize_key( $field['key'] ?? '' );
                $label = sanitize_text_field( $field['label'] ?? '' );
                if ( ! empty( $key ) ) {
                    $custom_fields[] = [
                        'key'   => $key,
                        'label' => $label ?: $key,
                    ];
                }
            }
        }

        return [
            'enabled_for'   => $enabled_for,
            'custom_fields' => $custom_fields,
        ];
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
