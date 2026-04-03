<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Form Builder (Basic) -- Simple contact form builder with shortcode.
 *
 * Features:
 *  - Custom DB tables for forms and entries (wpt_forms, wpt_form_entries)
 *  - Admin CRUD for forms
 *  - Shortcode [wpt_form id="X"] for frontend rendering
 *  - 10 field types: text, email, textarea, select, checkbox, radio, phone, number, date, file
 *  - Email notifications on submission
 *  - Honeypot spam protection
 *  - DB versioning via get_option()
 *
 * @package WPTransformed
 */
class Form_Builder extends Module_Base {

    private const DB_VERSION_KEY    = 'wpt_form_builder_db_version';
    private const DB_VERSION        = '1.0';
    private const TABLE_FORMS       = 'wpt_forms';
    private const TABLE_ENTRIES     = 'wpt_form_entries';
    private const TABLE_CHECK_TRANS = 'wpt_form_builder_table_exists';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'form-builder';
    }

    public function get_title(): string {
        return __( 'Form Builder', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Simple drag-free form builder with shortcode output, email notifications, and honeypot spam protection.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $this->maybe_create_tables();

        // Shortcode.
        add_shortcode( 'wpt_form', [ $this, 'render_shortcode' ] );

        // Frontend form submission.
        add_action( 'wp_ajax_wpt_form_submit', [ $this, 'ajax_submit_form' ] );
        add_action( 'wp_ajax_nopriv_wpt_form_submit', [ $this, 'ajax_submit_form' ] );

        // Admin pages.
        add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );

        // Admin AJAX.
        add_action( 'wp_ajax_wpt_save_form', [ $this, 'ajax_save_form' ] );
        add_action( 'wp_ajax_wpt_delete_form', [ $this, 'ajax_delete_form' ] );
        add_action( 'wp_ajax_wpt_delete_entry', [ $this, 'ajax_delete_entry' ] );
    }

    // ── Table Creation ────────────────────────────────────────

    private function maybe_create_tables(): void {
        if ( get_transient( self::TABLE_CHECK_TRANS ) ) {
            return;
        }

        $installed_ver = get_option( self::DB_VERSION_KEY, '0' );
        if ( version_compare( $installed_ver, self::DB_VERSION, '>=' ) ) {
            set_transient( self::TABLE_CHECK_TRANS, '1', DAY_IN_SECONDS );
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $forms_table   = $wpdb->prefix . self::TABLE_FORMS;
        $entries_table  = $wpdb->prefix . self::TABLE_ENTRIES;

        $sql_forms = "CREATE TABLE {$forms_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL DEFAULT '',
            fields longtext NOT NULL,
            settings longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};";

        $sql_entries = "CREATE TABLE {$entries_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            data longtext NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_forms );
        dbDelta( $sql_entries );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
        set_transient( self::TABLE_CHECK_TRANS, '1', DAY_IN_SECONDS );
    }

    // ── Admin Pages ───────────────────────────────────────────

    public function register_admin_pages(): void {
        add_submenu_page(
            'wptransformed',
            __( 'Form Builder', 'wptransformed' ),
            __( 'Form Builder', 'wptransformed' ),
            'manage_options',
            'wpt-form-builder',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Render the admin page (list, edit, or entries view).
     */
    public function render_admin_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only, nonce checked on actions.
        $action  = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

        echo '<div class="wrap">';

        switch ( $action ) {
            case 'edit':
                $this->render_form_editor( $form_id );
                break;
            case 'entries':
                $this->render_entries_page( $form_id );
                break;
            default:
                $this->render_forms_list();
                break;
        }

        echo '</div>';
    }

    /**
     * Render the forms list view.
     */
    private function render_forms_list(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_FORMS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $forms = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );

        $new_url = admin_url( 'admin.php?page=wpt-form-builder&action=edit&form_id=0' );
        ?>
        <h1>
            <?php esc_html_e( 'Form Builder', 'wptransformed' ); ?>
            <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New Form', 'wptransformed' ); ?>
            </a>
        </h1>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Shortcode', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $forms ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No forms yet.', 'wptransformed' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $forms as $form ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $form->id ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpt-form-builder&action=edit&form_id=' . $form->id ) ); ?>">
                                    <?php echo esc_html( $form->title ); ?>
                                </a>
                            </td>
                            <td><code>[wpt_form id="<?php echo esc_html( (string) $form->id ); ?>"]</code></td>
                            <td><?php echo esc_html( $form->created_at ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpt-form-builder&action=entries&form_id=' . $form->id ) ); ?>">
                                    <?php esc_html_e( 'Entries', 'wptransformed' ); ?>
                                </a> |
                                <a href="#" class="wpt-delete-form" data-id="<?php echo esc_attr( (string) $form->id ); ?>"
                                   data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpt_delete_form_' . $form->id ) ); ?>">
                                    <?php esc_html_e( 'Delete', 'wptransformed' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        (function(){
            document.querySelectorAll('.wpt-delete-form').forEach(function(el){
                el.addEventListener('click', function(e){
                    e.preventDefault();
                    if(!confirm('Delete this form and all its entries?')) return;
                    var data = new FormData();
                    data.append('action','wpt_delete_form');
                    data.append('nonce', this.dataset.nonce);
                    data.append('form_id', this.dataset.id);
                    fetch(ajaxurl,{method:'POST',body:data})
                        .then(function(r){return r.json();})
                        .then(function(r){ if(r.success) location.reload(); else alert(r.data.message||'Failed.'); });
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the form editor.
     *
     * @param int $form_id Form ID (0 for new).
     */
    private function render_form_editor( int $form_id ): void {
        $form   = null;
        $fields = [];
        $form_settings = [ 'notify_email' => get_option( 'admin_email' ), 'success_message' => __( 'Thank you! Your submission has been received.', 'wptransformed' ) ];

        if ( $form_id > 0 ) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_FORMS;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ) );
            if ( $form ) {
                $fields        = json_decode( $form->fields, true ) ?: [];
                $form_settings = json_decode( $form->settings, true ) ?: $form_settings;
            }
        }

        $nonce = wp_create_nonce( 'wpt_save_form_' . $form_id );
        $field_types = [ 'text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'phone', 'number', 'date', 'file' ];
        ?>
        <h1><?php echo $form_id > 0 ? esc_html__( 'Edit Form', 'wptransformed' ) : esc_html__( 'New Form', 'wptransformed' ); ?></h1>

        <form id="wpt-form-editor" data-form-id="<?php echo esc_attr( (string) $form_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="wpt-form-title"><?php esc_html_e( 'Form Title', 'wptransformed' ); ?></label></th>
                    <td><input type="text" id="wpt-form-title" value="<?php echo esc_attr( $form ? $form->title : '' ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpt-notify-email"><?php esc_html_e( 'Notification Email', 'wptransformed' ); ?></label></th>
                    <td><input type="email" id="wpt-notify-email" value="<?php echo esc_attr( $form_settings['notify_email'] ?? '' ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpt-success-msg"><?php esc_html_e( 'Success Message', 'wptransformed' ); ?></label></th>
                    <td><input type="text" id="wpt-success-msg" value="<?php echo esc_attr( $form_settings['success_message'] ?? '' ); ?>" class="large-text"></td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Fields', 'wptransformed' ); ?></h3>
            <div id="wpt-form-fields">
                <?php if ( ! empty( $fields ) ) : ?>
                    <?php foreach ( $fields as $i => $field ) : ?>
                        <div class="wpt-field-row" style="border:1px solid #ccd0d4;padding:10px;margin-bottom:10px;background:#f9f9f9">
                            <p>
                                <label><?php esc_html_e( 'Label', 'wptransformed' ); ?></label>
                                <input type="text" class="wpt-field-label regular-text" value="<?php echo esc_attr( $field['label'] ?? '' ); ?>">
                            </p>
                            <p>
                                <label><?php esc_html_e( 'Type', 'wptransformed' ); ?></label>
                                <select class="wpt-field-type">
                                    <?php foreach ( $field_types as $ft ) : ?>
                                        <option value="<?php echo esc_attr( $ft ); ?>" <?php selected( ( $field['type'] ?? 'text' ), $ft ); ?>>
                                            <?php echo esc_html( ucfirst( $ft ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" class="wpt-field-required" <?php checked( ! empty( $field['required'] ) ); ?>>
                                    <?php esc_html_e( 'Required', 'wptransformed' ); ?>
                                </label>
                            </p>
                            <p>
                                <label><?php esc_html_e( 'Options (comma-separated, for select/radio)', 'wptransformed' ); ?></label>
                                <input type="text" class="wpt-field-options regular-text" value="<?php echo esc_attr( $field['options'] ?? '' ); ?>">
                            </p>
                            <button type="button" class="button wpt-remove-field"><?php esc_html_e( 'Remove', 'wptransformed' ); ?></button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p>
                <button type="button" class="button" id="wpt-add-field"><?php esc_html_e( 'Add Field', 'wptransformed' ); ?></button>
                <button type="button" class="button button-primary" id="wpt-save-form"><?php esc_html_e( 'Save Form', 'wptransformed' ); ?></button>
            </p>
        </form>

        <script>
        (function(){
            var fieldTypes = <?php echo wp_json_encode( $field_types ); ?>;

            document.getElementById('wpt-add-field').addEventListener('click', function(){
                var container = document.getElementById('wpt-form-fields');
                var row = document.createElement('div');
                row.className = 'wpt-field-row';
                row.style.cssText = 'border:1px solid #ccd0d4;padding:10px;margin-bottom:10px;background:#f9f9f9';
                var opts = fieldTypes.map(function(t){ return '<option value="'+t+'">'+t.charAt(0).toUpperCase()+t.slice(1)+'</option>'; }).join('');
                row.innerHTML = '<p><label>Label</label> <input type="text" class="wpt-field-label regular-text"></p>'
                    + '<p><label>Type</label> <select class="wpt-field-type">'+opts+'</select></p>'
                    + '<p><label><input type="checkbox" class="wpt-field-required"> Required</label></p>'
                    + '<p><label>Options (comma-separated)</label> <input type="text" class="wpt-field-options regular-text"></p>'
                    + '<button type="button" class="button wpt-remove-field">Remove</button>';
                container.appendChild(row);
            });

            document.getElementById('wpt-form-fields').addEventListener('click', function(e){
                if(e.target.classList.contains('wpt-remove-field')){
                    e.target.closest('.wpt-field-row').remove();
                }
            });

            document.getElementById('wpt-save-form').addEventListener('click', function(){
                var editor = document.getElementById('wpt-form-editor');
                var fields = [];
                editor.querySelectorAll('.wpt-field-row').forEach(function(row){
                    fields.push({
                        label: row.querySelector('.wpt-field-label').value,
                        type: row.querySelector('.wpt-field-type').value,
                        required: row.querySelector('.wpt-field-required').checked,
                        options: row.querySelector('.wpt-field-options').value
                    });
                });

                var data = new FormData();
                data.append('action', 'wpt_save_form');
                data.append('nonce', editor.dataset.nonce);
                data.append('form_id', editor.dataset.formId);
                data.append('title', document.getElementById('wpt-form-title').value);
                data.append('fields', JSON.stringify(fields));
                data.append('settings', JSON.stringify({
                    notify_email: document.getElementById('wpt-notify-email').value,
                    success_message: document.getElementById('wpt-success-msg').value
                }));

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if(r.success){
                            window.location.href = r.data.redirect;
                        } else {
                            alert(r.data.message || 'Save failed.');
                        }
                    });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render entries for a form.
     *
     * @param int $form_id Form ID.
     */
    private function render_entries_page( int $form_id ): void {
        global $wpdb;
        $forms_table   = $wpdb->prefix . self::TABLE_FORMS;
        $entries_table  = $wpdb->prefix . self::TABLE_ENTRIES;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$forms_table} WHERE id = %d", $form_id ) );
        if ( ! $form ) {
            echo '<p>' . esc_html__( 'Form not found.', 'wptransformed' ) . '</p>';
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $entries = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$entries_table} WHERE form_id = %d ORDER BY created_at DESC LIMIT 200", $form_id )
        );

        $fields = json_decode( $form->fields, true ) ?: [];
        ?>
        <h1>
            <?php
            printf(
                /* translators: %s: form title */
                esc_html__( 'Entries: %s', 'wptransformed' ),
                esc_html( $form->title )
            );
            ?>
        </h1>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'wptransformed' ); ?></th>
                    <?php foreach ( $fields as $f ) : ?>
                        <th><?php echo esc_html( $f['label'] ?? '' ); ?></th>
                    <?php endforeach; ?>
                    <th><?php esc_html_e( 'IP', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $entries ) ) : ?>
                    <tr><td colspan="<?php echo esc_attr( (string) ( count( $fields ) + 4 ) ); ?>"><?php esc_html_e( 'No entries.', 'wptransformed' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $entries as $entry ) :
                        $data = json_decode( $entry->data, true ) ?: [];
                    ?>
                        <tr>
                            <td><?php echo esc_html( (string) $entry->id ); ?></td>
                            <?php foreach ( $fields as $fi => $f ) : ?>
                                <td><?php echo esc_html( $data[ $fi ] ?? '' ); ?></td>
                            <?php endforeach; ?>
                            <td><?php echo esc_html( $entry->ip_address ); ?></td>
                            <td><?php echo esc_html( $entry->created_at ); ?></td>
                            <td>
                                <a href="#" class="wpt-delete-entry" data-id="<?php echo esc_attr( (string) $entry->id ); ?>"
                                   data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpt_delete_entry_' . $entry->id ) ); ?>">
                                    <?php esc_html_e( 'Delete', 'wptransformed' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        (function(){
            document.querySelectorAll('.wpt-delete-entry').forEach(function(el){
                el.addEventListener('click', function(e){
                    e.preventDefault();
                    if(!confirm('Delete this entry?')) return;
                    var data = new FormData();
                    data.append('action','wpt_delete_entry');
                    data.append('nonce', this.dataset.nonce);
                    data.append('entry_id', this.dataset.id);
                    fetch(ajaxurl,{method:'POST',body:data})
                        .then(function(r){return r.json();})
                        .then(function(r){ if(r.success) location.reload(); else alert(r.data.message||'Failed.'); });
                });
            });
        })();
        </script>
        <?php
    }

    // ── AJAX: Save Form ───────────────────────────────────────

    public function ajax_save_form(): void {
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        check_ajax_referer( 'wpt_save_form_' . $form_id, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        // Don't sanitize_text_field on JSON — it strips tags and corrupts JSON values.
        // Individual field values are sanitized after json_decode below.
        $fields   = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '[]';
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '{}';

        // Validate JSON.
        $fields_arr   = json_decode( $fields, true );
        $settings_arr = json_decode( $settings, true );

        if ( ! is_array( $fields_arr ) ) {
            $fields_arr = [];
        }
        if ( ! is_array( $settings_arr ) ) {
            $settings_arr = [];
        }

        // Sanitize fields.
        $valid_types = [ 'text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'phone', 'number', 'date', 'file' ];
        $clean_fields = [];
        foreach ( $fields_arr as $f ) {
            $type = isset( $f['type'] ) && in_array( $f['type'], $valid_types, true ) ? $f['type'] : 'text';
            $clean_fields[] = [
                'label'    => sanitize_text_field( $f['label'] ?? '' ),
                'type'     => $type,
                'required' => ! empty( $f['required'] ),
                'options'  => sanitize_text_field( $f['options'] ?? '' ),
            ];
        }

        // Sanitize settings.
        $clean_settings = [
            'notify_email'    => sanitize_email( $settings_arr['notify_email'] ?? '' ),
            'success_message' => sanitize_text_field( $settings_arr['success_message'] ?? '' ),
        ];

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_FORMS;

        $data = [
            'title'    => $title,
            'fields'   => wp_json_encode( $clean_fields ),
            'settings' => wp_json_encode( $clean_settings ),
        ];

        if ( $form_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update( $table, $data, [ 'id' => $form_id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert( $table, $data, [ '%s', '%s', '%s' ] );
            $form_id = (int) $wpdb->insert_id;
        }

        wp_send_json_success( [
            'form_id'  => $form_id,
            'redirect' => admin_url( 'admin.php?page=wpt-form-builder&action=edit&form_id=' . $form_id ),
        ] );
    }

    // ── AJAX: Delete Form ─────────────────────────────────────

    public function ajax_delete_form(): void {
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        check_ajax_referer( 'wpt_delete_form_' . $form_id, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;

        // Delete entries first.
        $entries_table = $wpdb->prefix . self::TABLE_ENTRIES;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $entries_table, [ 'form_id' => $form_id ], [ '%d' ] );

        // Delete form.
        $forms_table = $wpdb->prefix . self::TABLE_FORMS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $forms_table, [ 'id' => $form_id ], [ '%d' ] );

        wp_send_json_success();
    }

    // ── AJAX: Delete Entry ────────────────────────────────────

    public function ajax_delete_entry(): void {
        $entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
        check_ajax_referer( 'wpt_delete_entry_' . $entry_id, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $entries_table = $wpdb->prefix . self::TABLE_ENTRIES;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $entries_table, [ 'id' => $entry_id ], [ '%d' ] );

        wp_send_json_success();
    }

    // ── Shortcode Rendering ───────────────────────────────────

    /**
     * Render the [wpt_form] shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_shortcode( $atts ): string {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'wpt_form' );
        $form_id = absint( $atts['id'] );

        if ( $form_id < 1 ) {
            return '';
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_FORMS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ) );

        if ( ! $form ) {
            return '';
        }

        $fields   = json_decode( $form->fields, true ) ?: [];
        $settings = json_decode( $form->settings, true ) ?: [];
        $nonce    = wp_create_nonce( 'wpt_form_submit_' . $form_id );

        ob_start();
        ?>
        <div class="wpt-form-wrapper" id="wpt-form-<?php echo esc_attr( (string) $form_id ); ?>">
            <form class="wpt-form" data-form-id="<?php echo esc_attr( (string) $form_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                <?php foreach ( $fields as $i => $field ) :
                    $field_id  = 'wpt-field-' . $form_id . '-' . $i;
                    $required  = ! empty( $field['required'] ) ? 'required' : '';
                    $req_mark  = ! empty( $field['required'] ) ? ' <span class="required">*</span>' : '';
                ?>
                    <div class="wpt-form-field" style="margin-bottom:15px">
                        <?php if ( $field['type'] !== 'checkbox' ) : ?>
                            <label for="<?php echo esc_attr( $field_id ); ?>">
                                <?php echo esc_html( $field['label'] ?? '' ); echo wp_kses( $req_mark, [ 'span' => [ 'class' => [] ] ] ); ?>
                            </label><br>
                        <?php endif; ?>

                        <?php
                        switch ( $field['type'] ) {
                            case 'textarea':
                                echo '<textarea id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" rows="5" style="width:100%" ' . esc_attr( $required ) . '></textarea>';
                                break;

                            case 'select':
                                $options = array_map( 'trim', explode( ',', $field['options'] ?? '' ) );
                                echo '<select id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" ' . esc_attr( $required ) . '>';
                                echo '<option value="">' . esc_html__( '-- Select --', 'wptransformed' ) . '</option>';
                                foreach ( $options as $opt ) {
                                    echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
                                }
                                echo '</select>';
                                break;

                            case 'radio':
                                $options = array_map( 'trim', explode( ',', $field['options'] ?? '' ) );
                                foreach ( $options as $oi => $opt ) {
                                    $rid = $field_id . '-' . $oi;
                                    echo '<label><input type="radio" id="' . esc_attr( $rid ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" value="' . esc_attr( $opt ) . '" ' . esc_attr( $required ) . '> ' . esc_html( $opt ) . '</label><br>';
                                }
                                break;

                            case 'checkbox':
                                echo '<label><input type="checkbox" id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" value="1" ' . esc_attr( $required ) . '> ' . esc_html( $field['label'] ?? '' ) . '</label>';
                                break;

                            case 'file':
                                echo '<input type="file" id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" ' . esc_attr( $required ) . '>';
                                break;

                            case 'email':
                                echo '<input type="email" id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" style="width:100%" ' . esc_attr( $required ) . '>';
                                break;

                            case 'phone':
                                echo '<input type="tel" id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" style="width:100%" ' . esc_attr( $required ) . '>';
                                break;

                            case 'number':
                                echo '<input type="number" id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" style="width:100%" ' . esc_attr( $required ) . '>';
                                break;

                            case 'date':
                                echo '<input type="date" id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" style="width:100%" ' . esc_attr( $required ) . '>';
                                break;

                            case 'text':
                            default:
                                echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="wpt_field_' . esc_attr( (string) $i ) . '" style="width:100%" ' . esc_attr( $required ) . '>';
                                break;
                        }
                        ?>
                    </div>
                <?php endforeach; ?>

                <!-- Honeypot -->
                <div style="position:absolute;left:-9999px" aria-hidden="true">
                    <input type="text" name="wpt_hp_<?php echo esc_attr( (string) $form_id ); ?>" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="wpt-form-message" style="display:none;margin:10px 0;padding:10px;border-radius:4px"></div>

                <button type="submit" class="button"><?php esc_html_e( 'Submit', 'wptransformed' ); ?></button>
            </form>
        </div>

        <script>
        (function(){
            var wrapper = document.getElementById('wpt-form-<?php echo esc_js( (string) $form_id ); ?>');
            if(!wrapper) return;
            var form = wrapper.querySelector('.wpt-form');
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var msg = wrapper.querySelector('.wpt-form-message');
                var data = new FormData(form);
                data.append('action', 'wpt_form_submit');
                data.append('nonce', form.dataset.nonce);
                data.append('form_id', form.dataset.formId);

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST', body:data})
                    .then(function(r){return r.json();})
                    .then(function(r){
                        msg.style.display = 'block';
                        if(r.success){
                            msg.style.background = '#d4edda'; msg.style.color = '#155724';
                            msg.textContent = r.data.message;
                            form.reset();
                        } else {
                            msg.style.background = '#f8d7da'; msg.style.color = '#721c24';
                            msg.textContent = r.data.message || 'Submission failed.';
                        }
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── AJAX: Submit Form (Frontend) ──────────────────────────

    public function ajax_submit_form(): void {
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        check_ajax_referer( 'wpt_form_submit_' . $form_id, 'nonce' );

        // Honeypot check.
        $hp_key = 'wpt_hp_' . $form_id;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Already verified above.
        $hp_val = isset( $_POST[ $hp_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $hp_key ] ) ) : '';
        if ( ! empty( $hp_val ) ) {
            // Silently succeed for bots.
            wp_send_json_success( [ 'message' => __( 'Thank you!', 'wptransformed' ) ] );
        }

        global $wpdb;
        $forms_table = $wpdb->prefix . self::TABLE_FORMS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$forms_table} WHERE id = %d", $form_id ) );

        if ( ! $form ) {
            wp_send_json_error( [ 'message' => __( 'Form not found.', 'wptransformed' ) ] );
        }

        $fields   = json_decode( $form->fields, true ) ?: [];
        $settings = json_decode( $form->settings, true ) ?: [];

        // Collect and validate field values.
        $entry_data = [];
        foreach ( $fields as $i => $field ) {
            $key   = 'wpt_field_' . $i;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

            if ( ! empty( $field['required'] ) && empty( $value ) && $field['type'] !== 'checkbox' ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: %s: field label */
                        __( '%s is required.', 'wptransformed' ),
                        $field['label'] ?? __( 'Field', 'wptransformed' )
                    ),
                ] );
            }

            if ( $field['type'] === 'email' && ! empty( $value ) && ! is_email( $value ) ) {
                wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'wptransformed' ) ] );
            }

            $entry_data[ $i ] = $value;
        }

        // Store entry.
        $entries_table = $wpdb->prefix . self::TABLE_ENTRIES;
        $ip = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $entries_table,
            [
                'form_id'    => $form_id,
                'data'       => wp_json_encode( $entry_data ),
                'ip_address' => $ip,
            ],
            [ '%d', '%s', '%s' ]
        );

        // Send notification email.
        $notify_email = $settings['notify_email'] ?? '';
        if ( ! empty( $notify_email ) && is_email( $notify_email ) ) {
            $subject = sprintf(
                /* translators: %s: form title */
                __( 'New form submission: %s', 'wptransformed' ),
                $form->title
            );

            $body = __( 'New form submission received:', 'wptransformed' ) . "\n\n";
            foreach ( $fields as $i => $field ) {
                $body .= ( $field['label'] ?? 'Field ' . ( $i + 1 ) ) . ': ' . ( $entry_data[ $i ] ?? '' ) . "\n";
            }

            wp_mail( $notify_email, $subject, $body );
        }

        $success_msg = $settings['success_message'] ?? __( 'Thank you! Your submission has been received.', 'wptransformed' );
        wp_send_json_success( [ 'message' => $success_msg ] );
    }

    // ── Settings UI (module settings page) ────────────────────

    public function render_settings(): void {
        $admin_url = admin_url( 'admin.php?page=wpt-form-builder' );
        ?>
        <p>
            <?php esc_html_e( 'Form Builder uses its own admin page for creating and managing forms.', 'wptransformed' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( $admin_url ); ?>" class="button button-primary">
                <?php esc_html_e( 'Go to Form Builder', 'wptransformed' ); ?>
            </a>
        </p>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [];
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Inline JS is used in admin pages.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'settings' => true,
            'options'  => [ self::DB_VERSION_KEY ],
            'tables'   => [ self::TABLE_FORMS, self::TABLE_ENTRIES ],
        ];
    }
}
