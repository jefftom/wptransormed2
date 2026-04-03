<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Admin Quick Notes -- Dashboard widget for shared sticky notes.
 *
 * Adds a dashboard widget with color-coded notes (yellow/blue/green/red).
 * Notes are stored in wp_options as wpt_quick_notes JSON. AJAX handlers
 * support add, delete, and reorder. Visible to all manage_options users.
 *
 * @package WPTransformed
 */
class Admin_Quick_Notes extends Module_Base {

    /** Option key for notes storage. */
    private const OPTION_KEY = 'wpt_quick_notes';

    /** Valid note colors. */
    private const VALID_COLORS = [ 'yellow', 'blue', 'green', 'red' ];

    /** Color-to-hex background map. */
    private const COLOR_MAP = [
        'yellow' => '#fff9c4',
        'blue'   => '#bbdefb',
        'green'  => '#c8e6c9',
        'red'    => '#ffcdd2',
    ];

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'admin-quick-notes';
    }

    public function get_title(): string {
        return __( 'Admin Quick Notes', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add a shared sticky-notes widget to the WordPress dashboard for quick team communication.', 'wptransformed' );
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
        add_action( 'wp_ajax_wpt_quick_notes_add',     [ $this, 'ajax_add_note' ] );
        add_action( 'wp_ajax_wpt_quick_notes_delete',  [ $this, 'ajax_delete_note' ] );
        add_action( 'wp_ajax_wpt_quick_notes_reorder', [ $this, 'ajax_reorder_notes' ] );
    }

    // -- Dashboard Widget -------------------------------------------------

    /**
     * Register the Quick Notes dashboard widget.
     */
    public function register_dashboard_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'wpt_quick_notes_widget',
            __( 'Quick Notes', 'wptransformed' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }

    /**
     * Render the dashboard widget content.
     */
    public function render_dashboard_widget(): void {
        $notes  = $this->get_notes();
        $nonce  = wp_create_nonce( 'wpt_quick_notes' );
        ?>
        <div id="wpt-quick-notes" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <div id="wpt-qn-list">
                <?php if ( empty( $notes ) ) : ?>
                    <p class="wpt-qn-empty"><?php esc_html_e( 'No notes yet. Add one below.', 'wptransformed' ); ?></p>
                <?php else : ?>
                    <?php foreach ( $notes as $index => $note ) : ?>
                        <?php echo $this->render_note_html( $note, $index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_note_html ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div style="margin-top:10px; border-top:1px solid #ddd; padding-top:10px;">
                <textarea id="wpt-qn-text" rows="2" style="width:100%;"
                          placeholder="<?php esc_attr_e( 'Type a note...', 'wptransformed' ); ?>"></textarea>
                <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                    <label for="wpt-qn-color"><?php esc_html_e( 'Color:', 'wptransformed' ); ?></label>
                    <select id="wpt-qn-color">
                        <?php foreach ( self::VALID_COLORS as $color ) : ?>
                            <option value="<?php echo esc_attr( $color ); ?>"><?php echo esc_html( ucfirst( $color ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="wpt-qn-add" class="button button-primary">
                        <?php esc_html_e( 'Add Note', 'wptransformed' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var wrap  = document.getElementById('wpt-quick-notes');
            if (!wrap) return;
            var nonce = wrap.getAttribute('data-nonce');

            function ajaxPost(action, data, cb) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('_wpnonce', nonce);
                for (var k in data) {
                    if (data.hasOwnProperty(k)) fd.append(k, data[k]);
                }
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(j) { if (cb) cb(j); });
            }

            // Add note.
            var addBtn = document.getElementById('wpt-qn-add');
            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    var text  = document.getElementById('wpt-qn-text').value.trim();
                    var color = document.getElementById('wpt-qn-color').value;
                    if (!text) return;
                    ajaxPost('wpt_quick_notes_add', { text: text, color: color }, function(res) {
                        if (res.success && res.data && res.data.html) {
                            var list = document.getElementById('wpt-qn-list');
                            var empty = list.querySelector('.wpt-qn-empty');
                            if (empty) empty.remove();
                            list.insertAdjacentHTML('beforeend', res.data.html);
                            document.getElementById('wpt-qn-text').value = '';
                        }
                    });
                });
            }

            // Delete note (delegated).
            var list = document.getElementById('wpt-qn-list');
            if (list) {
                list.addEventListener('click', function(e) {
                    if (!e.target.classList.contains('wpt-qn-delete')) return;
                    var idx = e.target.getAttribute('data-index');
                    ajaxPost('wpt_quick_notes_delete', { index: idx }, function(res) {
                        if (res.success) {
                            var note = e.target.closest('.wpt-qn-note');
                            if (note) note.remove();
                            if (!list.querySelector('.wpt-qn-note')) {
                                list.innerHTML = '<p class="wpt-qn-empty">' +
                                    '<?php echo esc_js( __( 'No notes yet. Add one below.', 'wptransformed' ) ); ?>' +
                                    '</p>';
                            }
                        }
                    });
                });
            }
        })();
        </script>
        <?php
    }

    // -- AJAX Handlers ----------------------------------------------------

    /**
     * Handle AJAX request to add a note.
     */
    public function ajax_add_note(): void {
        check_ajax_referer( 'wpt_quick_notes' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $text  = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
        $color = isset( $_POST['color'] ) ? sanitize_key( $_POST['color'] ) : 'yellow';

        if ( '' === $text ) {
            wp_send_json_error( [ 'message' => 'Note text is required.' ] );
        }

        if ( ! in_array( $color, self::VALID_COLORS, true ) ) {
            $color = 'yellow';
        }

        $user = wp_get_current_user();
        $note = [
            'text'       => $text,
            'color'      => $color,
            'created_by' => $user->user_login,
            'created_at' => time(),
        ];

        $notes   = $this->get_notes();
        $notes[] = $note;
        $this->save_notes( $notes );

        $index = count( $notes ) - 1;

        wp_send_json_success( [ 'html' => $this->render_note_html( $note, $index ) ] );
    }

    /**
     * Handle AJAX request to delete a note.
     */
    public function ajax_delete_note(): void {
        check_ajax_referer( 'wpt_quick_notes' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
        $notes = $this->get_notes();

        if ( $index < 0 || $index >= count( $notes ) ) {
            wp_send_json_error( [ 'message' => 'Invalid note index.' ] );
        }

        array_splice( $notes, $index, 1 );
        $this->save_notes( $notes );

        wp_send_json_success();
    }

    /**
     * Handle AJAX request to reorder notes.
     */
    public function ajax_reorder_notes(): void {
        check_ajax_referer( 'wpt_quick_notes' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $order = isset( $_POST['order'] ) && is_array( $_POST['order'] )
            ? array_map( 'intval', $_POST['order'] )
            : [];

        $notes     = $this->get_notes();
        $reordered = [];

        foreach ( $order as $old_index ) {
            if ( isset( $notes[ $old_index ] ) ) {
                $reordered[] = $notes[ $old_index ];
            }
        }

        // Safety: append any notes not in the order array.
        if ( count( $reordered ) !== count( $notes ) ) {
            foreach ( $notes as $i => $note ) {
                if ( ! in_array( $i, $order, true ) ) {
                    $reordered[] = $note;
                }
            }
        }

        $this->save_notes( $reordered );

        wp_send_json_success();
    }

    // -- Rendering Helper -------------------------------------------------

    /**
     * Render a single note as HTML.
     *
     * @param array $note  Note data array.
     * @param int   $index Note index for data attribute.
     * @return string HTML string.
     */
    private function render_note_html( array $note, int $index ): string {
        $bg = self::COLOR_MAP[ $note['color'] ] ?? self::COLOR_MAP['yellow'];

        return '<div class="wpt-qn-note" data-index="' . esc_attr( (string) $index ) . '"'
             . ' style="background:' . esc_attr( $bg ) . ';'
             . ' padding:8px 12px; margin-bottom:6px; border-radius:4px; position:relative;">'
             . '<span class="wpt-qn-text">' . esc_html( $note['text'] ) . '</span>'
             . '<small style="display:block; color:#666; margin-top:4px;">'
             . esc_html( sprintf(
                   __( 'by %1$s on %2$s', 'wptransformed' ),
                   $note['created_by'],
                   wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $note['created_at'] )
               ) )
             . '</small>'
             . '<button type="button" class="wpt-qn-delete" data-index="' . esc_attr( (string) $index ) . '"'
             . ' style="position:absolute; top:4px; right:6px; background:none; border:none; cursor:pointer; color:#a00; font-size:16px;"'
             . ' title="' . esc_attr__( 'Delete note', 'wptransformed' ) . '">&times;</button>'
             . '</div>';
    }

    // -- Notes Storage ----------------------------------------------------

    /**
     * Retrieve all notes from wp_options.
     *
     * @return array<int, array{text: string, color: string, created_by: string, created_at: int}>
     */
    private function get_notes(): array {
        $notes = get_option( self::OPTION_KEY, [] );

        if ( ! is_array( $notes ) ) {
            return [];
        }

        return $notes;
    }

    /**
     * Save notes to wp_options.
     *
     * @param array $notes Notes array.
     */
    private function save_notes( array $notes ): void {
        update_option( self::OPTION_KEY, $notes, false );
    }

    // -- Admin UI (Module Settings) ---------------------------------------

    public function render_settings(): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Quick Notes', 'wptransformed' ); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e( 'This module has no settings. When enabled, a Quick Notes widget appears on the WordPress dashboard for all administrators.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            [
                'type' => 'option',
                'key'  => self::OPTION_KEY,
            ],
        ];
    }
}
