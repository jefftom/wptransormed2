<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Keyboard Shortcuts — Navigate the admin dashboard with keyboard combos.
 *
 * Core logic:
 * - admin_footer: outputs JS listener for keyboard combos
 * - Default shortcuts: Alt+N (posts), Alt+P (pages), Alt+M (media), etc.
 * - Custom user-defined shortcuts via settings
 * - Avoids conflicts with browser defaults (Ctrl+, Cmd+)
 *
 * @package WPTransformed
 */
class Keyboard_Shortcuts extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'keyboard-shortcuts';
    }

    public function get_title(): string {
        return __( 'Keyboard Shortcuts', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Navigate the WordPress admin with configurable keyboard shortcuts.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled'   => true,
            'shortcuts' => [
                'alt+n' => '/wp-admin/edit.php',
                'alt+p' => '/wp-admin/edit.php?post_type=page',
                'alt+m' => '/wp-admin/upload.php',
                'alt+d' => '/wp-admin/index.php',
                'alt+s' => '/wp-admin/options-general.php',
                'alt+u' => '/wp-admin/users.php',
            ],
            'custom_shortcuts' => [],
            'show_help_badge'  => true,
        ];
    }

    /**
     * All available built-in shortcuts with labels.
     *
     * @return array<string, array{url: string, label: string}>
     */
    private function get_builtin_shortcuts(): array {
        return [
            'alt+n' => [
                'url'   => '/wp-admin/edit.php',
                'label' => __( 'All Posts', 'wptransformed' ),
            ],
            'alt+p' => [
                'url'   => '/wp-admin/edit.php?post_type=page',
                'label' => __( 'All Pages', 'wptransformed' ),
            ],
            'alt+m' => [
                'url'   => '/wp-admin/upload.php',
                'label' => __( 'Media Library', 'wptransformed' ),
            ],
            'alt+d' => [
                'url'   => '/wp-admin/index.php',
                'label' => __( 'Dashboard', 'wptransformed' ),
            ],
            'alt+s' => [
                'url'   => '/wp-admin/options-general.php',
                'label' => __( 'Settings', 'wptransformed' ),
            ],
            'alt+u' => [
                'url'   => '/wp-admin/users.php',
                'label' => __( 'Users', 'wptransformed' ),
            ],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_action( 'admin_footer', [ $this, 'render_shortcut_listener' ] );
    }

    // ── Footer JS ─────────────────────────────────────────────

    /**
     * Output keyboard shortcut listener and help overlay in admin footer.
     */
    public function render_shortcut_listener(): void {
        if ( ! current_user_can( 'read' ) ) {
            return;
        }

        $settings   = $this->get_settings();
        $shortcuts  = $settings['shortcuts'] ?? [];
        $custom     = $settings['custom_shortcuts'] ?? [];
        $show_badge = ! empty( $settings['show_help_badge'] );

        // Merge built-in + custom for JS.
        $all_shortcuts = [];
        $builtin_defs  = $this->get_builtin_shortcuts();

        foreach ( $shortcuts as $combo => $url ) {
            if ( $url === '' ) {
                continue;
            }
            $label = isset( $builtin_defs[ $combo ] ) ? $builtin_defs[ $combo ]['label'] : $combo;
            $all_shortcuts[] = [
                'combo' => $combo,
                'url'   => $url,
                'label' => $label,
            ];
        }

        foreach ( $custom as $entry ) {
            $combo = $entry['combo'] ?? '';
            $url   = $entry['url'] ?? '';
            $label = $entry['label'] ?? $combo;
            if ( $combo === '' || $url === '' ) {
                continue;
            }
            $all_shortcuts[] = [
                'combo' => $combo,
                'url'   => $url,
                'label' => $label,
            ];
        }

        if ( empty( $all_shortcuts ) ) {
            return;
        }
        ?>
        <style>
            #wpt-shortcuts-help {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                z-index: 100000;
                background: rgba(0,0,0,0.6);
                align-items: center;
                justify-content: center;
            }
            #wpt-shortcuts-help.visible { display: flex; }
            #wpt-shortcuts-help-inner {
                background: #fff;
                border-radius: 8px;
                padding: 24px 32px;
                max-width: 480px;
                width: 90%;
                max-height: 70vh;
                overflow-y: auto;
                box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            }
            #wpt-shortcuts-help h3 {
                margin: 0 0 16px;
                font-size: 16px;
            }
            .wpt-shortcut-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 6px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            .wpt-shortcut-row:last-child { border-bottom: none; }
            .wpt-shortcut-key {
                display: inline-block;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 3px;
                padding: 2px 8px;
                font-family: monospace;
                font-size: 12px;
                white-space: nowrap;
            }
            .wpt-shortcut-label {
                color: #50575e;
                font-size: 13px;
            }
            #wpt-shortcuts-badge {
                position: fixed;
                bottom: 20px;
                left: 20px;
                z-index: 99998;
                background: #2271b1;
                color: #fff;
                border: none;
                border-radius: 4px;
                padding: 4px 10px;
                font-size: 11px;
                cursor: pointer;
                opacity: 0.7;
                transition: opacity 0.2s;
            }
            #wpt-shortcuts-badge:hover { opacity: 1; }
        </style>

        <div id="wpt-shortcuts-help">
            <div id="wpt-shortcuts-help-inner">
                <h3><?php esc_html_e( 'Keyboard Shortcuts', 'wptransformed' ); ?></h3>
                <?php foreach ( $all_shortcuts as $sc ) : ?>
                    <div class="wpt-shortcut-row">
                        <span class="wpt-shortcut-label"><?php echo esc_html( $sc['label'] ); ?></span>
                        <span class="wpt-shortcut-key"><?php echo esc_html( strtoupper( $sc['combo'] ) ); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="wpt-shortcut-row">
                    <span class="wpt-shortcut-label"><?php esc_html_e( 'Show/Hide This Help', 'wptransformed' ); ?></span>
                    <span class="wpt-shortcut-key">SHIFT+?</span>
                </div>
            </div>
        </div>

        <?php if ( $show_badge ) : ?>
            <button type="button" id="wpt-shortcuts-badge" title="<?php esc_attr_e( 'Press Shift+? for keyboard shortcuts', 'wptransformed' ); ?>">
                <?php esc_html_e( 'Shortcuts: Shift+?', 'wptransformed' ); ?>
            </button>
        <?php endif; ?>

        <script>
        (function() {
            var shortcuts = <?php echo wp_json_encode( $all_shortcuts ); ?>;
            var helpOverlay = document.getElementById('wpt-shortcuts-help');
            var badge = document.getElementById('wpt-shortcuts-badge');

            function parseCombo(combo) {
                var parts = combo.toLowerCase().split('+');
                return {
                    alt:   parts.indexOf('alt') !== -1,
                    shift: parts.indexOf('shift') !== -1,
                    key:   parts[parts.length - 1]
                };
            }

            function toggleHelp() {
                helpOverlay.classList.toggle('visible');
            }

            if (helpOverlay) {
                helpOverlay.addEventListener('click', function(e) {
                    if (e.target === helpOverlay) toggleHelp();
                });
            }

            if (badge) {
                badge.addEventListener('click', toggleHelp);
            }

            document.addEventListener('keydown', function(e) {
                // Ignore when typing in inputs.
                var tag = (e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) {
                    return;
                }

                // Shift+? = help toggle.
                if (e.shiftKey && (e.key === '?' || e.key === '/')) {
                    e.preventDefault();
                    toggleHelp();
                    return;
                }

                // Escape closes help.
                if (e.key === 'Escape' && helpOverlay && helpOverlay.classList.contains('visible')) {
                    toggleHelp();
                    return;
                }

                // Match shortcuts.
                for (var i = 0; i < shortcuts.length; i++) {
                    var sc = parseCombo(shortcuts[i].combo);
                    if (sc.alt === e.altKey && sc.shift === e.shiftKey && e.key.toLowerCase() === sc.key) {
                        e.preventDefault();
                        var url = shortcuts[i].url;
                        // If relative path, make absolute.
                        if (url.charAt(0) === '/') {
                            url = window.location.origin + url;
                        }
                        window.location.href = url;
                        return;
                    }
                }
            });
        })();
        </script>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings     = $this->get_settings();
        $shortcuts    = $settings['shortcuts'] ?? [];
        $custom       = $settings['custom_shortcuts'] ?? [];
        $builtin_defs = $this->get_builtin_shortcuts();
        ?>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e( 'Keyboard Shortcuts Settings', 'wptransformed' ); ?></legend>

            <p>
                <label>
                    <input type="checkbox" name="wpt_enabled" value="1"
                        <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                    <?php esc_html_e( 'Enable keyboard shortcuts', 'wptransformed' ); ?>
                </label>
            </p>

            <p>
                <label>
                    <input type="checkbox" name="wpt_show_help_badge" value="1"
                        <?php checked( ! empty( $settings['show_help_badge'] ) ); ?>>
                    <?php esc_html_e( 'Show help badge in bottom-left corner', 'wptransformed' ); ?>
                </label>
            </p>

            <h3><?php esc_html_e( 'Built-in Shortcuts', 'wptransformed' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Clear the URL to disable a shortcut. All shortcuts use Alt+Key to avoid browser conflicts.', 'wptransformed' ); ?></p>

            <table class="widefat striped" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Combo', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'Label', 'wptransformed' ); ?></th>
                        <th><?php esc_html_e( 'URL', 'wptransformed' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $builtin_defs as $combo => $def ) :
                        $current_url = $shortcuts[ $combo ] ?? $def['url'];
                    ?>
                        <tr>
                            <td><code><?php echo esc_html( strtoupper( $combo ) ); ?></code></td>
                            <td><?php echo esc_html( $def['label'] ); ?></td>
                            <td>
                                <input type="text" name="wpt_shortcuts[<?php echo esc_attr( $combo ); ?>]"
                                       value="<?php echo esc_attr( $current_url ); ?>"
                                       style="width: 100%;" class="regular-text">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 style="margin-top: 20px;"><?php esc_html_e( 'Custom Shortcuts', 'wptransformed' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Add your own shortcuts. Use Alt+Letter combos (e.g., alt+w). Avoid Ctrl/Cmd combos to prevent browser conflicts.', 'wptransformed' ); ?></p>

            <div id="wpt-custom-shortcuts-list">
                <?php if ( ! empty( $custom ) ) :
                    foreach ( $custom as $i => $entry ) : ?>
                        <div class="wpt-custom-shortcut-row" style="margin-bottom: 8px; display: flex; gap: 8px; align-items: center;">
                            <input type="text" name="wpt_custom_shortcuts[<?php echo (int) $i; ?>][combo]"
                                   value="<?php echo esc_attr( $entry['combo'] ?? '' ); ?>"
                                   placeholder="alt+w" style="width: 100px;">
                            <input type="text" name="wpt_custom_shortcuts[<?php echo (int) $i; ?>][label]"
                                   value="<?php echo esc_attr( $entry['label'] ?? '' ); ?>"
                                   placeholder="<?php esc_attr_e( 'Label', 'wptransformed' ); ?>" style="width: 150px;">
                            <input type="text" name="wpt_custom_shortcuts[<?php echo (int) $i; ?>][url]"
                                   value="<?php echo esc_attr( $entry['url'] ?? '' ); ?>"
                                   placeholder="/wp-admin/..." style="width: 250px;">
                            <button type="button" class="button wpt-remove-shortcut">&times;</button>
                        </div>
                    <?php endforeach;
                endif; ?>
            </div>
            <button type="button" id="wpt-add-custom-shortcut" class="button" style="margin-top: 8px;">
                + <?php esc_html_e( 'Add Custom Shortcut', 'wptransformed' ); ?>
            </button>
        </fieldset>

        <script>
        (function() {
            var list = document.getElementById('wpt-custom-shortcuts-list');
            var addBtn = document.getElementById('wpt-add-custom-shortcut');
            var idx = <?php echo (int) count( $custom ); ?>;

            function createRow(i) {
                var div = document.createElement('div');
                div.className = 'wpt-custom-shortcut-row';
                div.style.cssText = 'margin-bottom: 8px; display: flex; gap: 8px; align-items: center;';
                div.innerHTML =
                    '<input type="text" name="wpt_custom_shortcuts[' + i + '][combo]" placeholder="alt+w" style="width: 100px;">' +
                    '<input type="text" name="wpt_custom_shortcuts[' + i + '][label]" placeholder="<?php echo esc_js( __( 'Label', 'wptransformed' ) ); ?>" style="width: 150px;">' +
                    '<input type="text" name="wpt_custom_shortcuts[' + i + '][url]" placeholder="/wp-admin/..." style="width: 250px;">' +
                    '<button type="button" class="button wpt-remove-shortcut">&times;</button>';
                return div;
            }

            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    list.appendChild(createRow(idx++));
                });
            }

            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('wpt-remove-shortcut')) {
                    e.target.parentElement.remove();
                }
            });
        })();
        </script>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $sanitized = [
            'enabled'          => ! empty( $raw['wpt_enabled'] ),
            'show_help_badge'  => ! empty( $raw['wpt_show_help_badge'] ),
            'shortcuts'        => [],
            'custom_shortcuts' => [],
        ];

        // Built-in shortcuts.
        $builtin_keys = array_keys( $this->get_builtin_shortcuts() );
        $raw_shortcuts = $raw['wpt_shortcuts'] ?? [];
        if ( is_array( $raw_shortcuts ) ) {
            foreach ( $builtin_keys as $combo ) {
                $url = isset( $raw_shortcuts[ $combo ] ) ? sanitize_text_field( $raw_shortcuts[ $combo ] ) : '';
                $sanitized['shortcuts'][ $combo ] = $url;
            }
        }

        // Custom shortcuts.
        $raw_custom = $raw['wpt_custom_shortcuts'] ?? [];
        if ( is_array( $raw_custom ) ) {
            foreach ( $raw_custom as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                $combo = sanitize_text_field( $entry['combo'] ?? '' );
                $label = sanitize_text_field( $entry['label'] ?? '' );
                $url   = sanitize_text_field( $entry['url'] ?? '' );

                // Validate combo format: must be modifier+key.
                if ( $combo === '' || $url === '' ) {
                    continue;
                }

                // Only allow alt+key and shift+alt+key combos.
                if ( strpos( $combo, 'alt+' ) === false ) {
                    continue;
                }

                $sanitized['custom_shortcuts'][] = [
                    'combo' => strtolower( $combo ),
                    'label' => $label !== '' ? $label : $combo,
                    'url'   => $url,
                ];
            }
        }

        // Limit custom shortcuts to 20.
        $sanitized['custom_shortcuts'] = array_slice( $sanitized['custom_shortcuts'], 0, 20 );

        return $sanitized;
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
