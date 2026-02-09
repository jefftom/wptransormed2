<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Dark Mode — Admin dashboard dark mode with FOUC prevention.
 *
 * Features:
 *  - Zero-FOUC inline script at admin_head priority 1
 *  - 4 color schemes: Midnight Blue, True Dark, Charcoal, Nord
 *  - Admin bar toggle (moon/sun)
 *  - Keyboard shortcut Ctrl+Alt+D
 *  - OS auto-detect (prefers-color-scheme)
 *  - Per-user preference via user meta
 *  - Role-based defaults
 *  - Optional sidebar darkening
 *  - Optional login page dark mode
 *  - Frontend admin bar dark mode
 *  - CSS custom properties — no !important (except FOUC inline)
 *  - Print reset
 *  - Smooth 0.2s transitions on toggle
 *
 * @package WPTransformed
 */
class Dark_Mode extends Module_Base {

    /**
     * Available color schemes.
     */
    private const SCHEMES = [
        'midnight-blue' => 'Midnight Blue',
        'true-dark'     => 'True Dark',
        'charcoal'      => 'Charcoal',
        'nord'          => 'Nord',
    ];

    /**
     * Valid user meta values.
     */
    private const VALID_PREFS = [ 'dark', 'light', '' ];

    /**
     * Valid role default values.
     */
    private const VALID_ROLE_DEFAULTS = [ '', 'light', 'dark', 'auto' ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'dark-mode';
    }

    public function get_title(): string {
        return __( 'Dark Mode', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Add a dark color scheme to the WordPress admin dashboard with per-user preferences.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'default_mode'     => 'light',
            'color_scheme'     => 'midnight-blue',
            'show_toggle'      => true,
            'enable_shortcut'  => true,
            'auto_detect_os'   => true,
            'include_sidebar'  => false,
            'login_dark_mode'  => false,
            'role_defaults'    => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        // FOUC prevention — must be FIRST thing in admin_head.
        add_action( 'admin_head', [ $this, 'inline_fouc_prevention' ], 1 );

        // Enqueue main dark mode CSS and toggle JS in admin.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Admin bar toggle.
        if ( ! empty( $settings['show_toggle'] ) ) {
            add_action( 'admin_bar_menu', [ $this, 'add_toggle_node' ], 999 );
        }

        // AJAX handler.
        add_action( 'wp_ajax_wpt_toggle_dark_mode', [ $this, 'ajax_toggle' ] );

        // Frontend admin bar dark mode for logged-in users.
        add_action( 'wp_head', [ $this, 'frontend_admin_bar_styles' ], 1 );

        // Frontend toggle JS + admin bar toggle.
        if ( ! empty( $settings['show_toggle'] ) ) {
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_toggle' ] );
        }

        // Login page dark mode.
        if ( ! empty( $settings['login_dark_mode'] ) ) {
            add_action( 'login_head', [ $this, 'login_dark_styles' ] );
        }
    }

    // ── FOUC Prevention (Critical) ────────────────────────────

    /**
     * Inline script + minimal CSS at admin_head priority 1.
     * Runs BEFORE any rendering to prevent flash of wrong theme.
     */
    public function inline_fouc_prevention(): void {
        $user_pref    = get_user_meta( get_current_user_id(), 'wpt_dark_mode', true );
        $settings     = $this->get_settings();
        $auto_detect  = ! empty( $settings['auto_detect_os'] );
        $default_mode = $settings['default_mode'] ?? 'light';
        $scheme       = $settings['color_scheme'] ?? 'midnight-blue';
        $inc_sidebar  = ! empty( $settings['include_sidebar'] );

        // Check for role-based default.
        $role_default = $this->get_role_default_for_user();

        ?>
        <script>
        (function(){
            var pref = <?php echo wp_json_encode( (string) $user_pref ); ?>;
            var autoDetect = <?php echo wp_json_encode( $auto_detect ); ?>;
            var defaultMode = <?php echo wp_json_encode( $default_mode ); ?>;
            var roleDefault = <?php echo wp_json_encode( $role_default ); ?>;
            var scheme = <?php echo wp_json_encode( $scheme ); ?>;
            var sidebar = <?php echo wp_json_encode( $inc_sidebar ); ?>;
            var dark = false;

            if (pref === 'dark') {
                dark = true;
            } else if (pref === 'light') {
                dark = false;
            } else {
                // No explicit pref — check role default, then auto-detect, then global default.
                if (roleDefault === 'dark') {
                    dark = true;
                } else if (roleDefault === 'light') {
                    dark = false;
                } else if (roleDefault === 'auto' || (roleDefault === '' && autoDetect)) {
                    dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                } else {
                    dark = (defaultMode === 'dark');
                }
            }

            if (dark) {
                document.documentElement.classList.add('wpt-dark');
                if (sidebar) document.documentElement.classList.add('wpt-dark-sidebar');
            }
            if (scheme && scheme !== 'midnight-blue') {
                document.documentElement.setAttribute('data-wpt-scheme', scheme);
            }
        })();
        </script>
        <style>
        /* Minimal FOUC prevention — dark bg before full CSS loads */
        html.wpt-dark { background-color: #1a1a2e !important; color: #e4e6eb !important; }
        html.wpt-dark #wpcontent, html.wpt-dark #wpbody-content { background-color: #1a1a2e; }
        html.wpt-dark[data-wpt-scheme="true-dark"] { background-color: #121212 !important; }
        html.wpt-dark[data-wpt-scheme="true-dark"] #wpcontent,
        html.wpt-dark[data-wpt-scheme="true-dark"] #wpbody-content { background-color: #121212; }
        html.wpt-dark[data-wpt-scheme="charcoal"] { background-color: #2d2d2d !important; }
        html.wpt-dark[data-wpt-scheme="charcoal"] #wpcontent,
        html.wpt-dark[data-wpt-scheme="charcoal"] #wpbody-content { background-color: #2d2d2d; }
        html.wpt-dark[data-wpt-scheme="nord"] { background-color: #2e3440 !important; }
        html.wpt-dark[data-wpt-scheme="nord"] #wpcontent,
        html.wpt-dark[data-wpt-scheme="nord"] #wpbody-content { background-color: #2e3440; }
        </style>
        <?php
    }

    // ── Admin Bar Toggle ──────────────────────────────────────

    /**
     * Add moon/sun toggle to admin bar.
     *
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
     */
    public function add_toggle_node( \WP_Admin_Bar $wp_admin_bar ): void {
        $is_dark = $this->is_dark_for_current_user();

        $wp_admin_bar->add_node( [
            'id'    => 'wpt-dark-mode-toggle',
            'title' => $is_dark ? "\u{2600}\u{FE0F}" : "\u{1F319}",
            'href'  => '#',
            'meta'  => [
                'class' => 'wpt-dark-toggle',
                'title' => $is_dark
                    ? __( 'Switch to light mode', 'wptransformed' )
                    : __( 'Switch to dark mode', 'wptransformed' ),
            ],
        ] );
    }

    // ── AJAX Handler ──────────────────────────────────────────

    /**
     * Handle AJAX toggle — save user preference.
     */
    public function ajax_toggle(): void {
        check_ajax_referer( 'wpt_dark_mode_nonce', 'nonce' );

        $mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? '' ) );

        if ( ! in_array( $mode, [ 'dark', 'light' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid mode.' ] );
        }

        update_user_meta( get_current_user_id(), 'wpt_dark_mode', $mode );
        wp_send_json_success();
    }

    // ── Frontend Admin Bar ────────────────────────────────────

    /**
     * Dark admin bar styles on frontend for logged-in users.
     * Only styles #wpadminbar — NOT the rest of the page.
     */
    public function frontend_admin_bar_styles(): void {
        if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
            return;
        }

        if ( ! $this->is_dark_for_current_user() ) {
            return;
        }

        $settings = $this->get_settings();
        $scheme   = $settings['color_scheme'] ?? 'midnight-blue';
        $bg       = $this->get_scheme_adminbar_bg( $scheme );

        ?>
        <style id="wpt-dark-frontend-adminbar">
        #wpadminbar { background: <?php echo esc_attr( $bg ); ?>; }
        #wpadminbar .ab-item, #wpadminbar a.ab-item,
        #wpadminbar .ab-empty-item,
        #wpadminbar > #wp-toolbar span.ab-label { color: #b0b3b8; }
        #wpadminbar .menupop .ab-sub-wrapper { background: <?php echo esc_attr( $bg ); ?>; }
        #wpadminbar .menupop .ab-sub-wrapper .ab-item { color: #b0b3b8; }
        </style>
        <?php
    }

    /**
     * Enqueue toggle JS on frontend for admin bar toggle.
     */
    public function enqueue_frontend_toggle(): void {
        if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
            return;
        }

        $settings = $this->get_settings();

        wp_enqueue_script(
            'wpt-dark-mode-toggle',
            WPT_URL . 'modules/admin-interface/js/dark-mode-toggle.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-dark-mode-toggle', 'wptDarkMode', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wpt_dark_mode_nonce' ),
            'enableShortcut' => ! empty( $settings['enable_shortcut'] ),
            'includeSidebar' => ! empty( $settings['include_sidebar'] ),
        ] );
    }

    // ── Login Page Dark Mode ──────────────────────────────────

    /**
     * Inject dark mode CSS on wp-login.php using OS auto-detect.
     * No JS needed — uses @media (prefers-color-scheme: dark).
     */
    public function login_dark_styles(): void {
        $settings = $this->get_settings();
        $scheme   = $settings['color_scheme'] ?? 'midnight-blue';
        $colors   = $this->get_scheme_login_colors( $scheme );
        ?>
        <style id="wpt-dark-login">
        @media (prefers-color-scheme: dark) {
            body.login {
                background-color: <?php echo esc_attr( $colors['bg'] ); ?>;
                color: <?php echo esc_attr( $colors['text'] ); ?>;
            }
            .login #loginform,
            .login #registerform,
            .login #lostpasswordform {
                background-color: <?php echo esc_attr( $colors['surface'] ); ?>;
                border-color: <?php echo esc_attr( $colors['border'] ); ?>;
                color: <?php echo esc_attr( $colors['text'] ); ?>;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            }
            .login #loginform input[type="text"],
            .login #loginform input[type="password"],
            .login #registerform input[type="text"],
            .login #registerform input[type="email"],
            .login #lostpasswordform input[type="text"] {
                background-color: <?php echo esc_attr( $colors['input'] ); ?>;
                border-color: <?php echo esc_attr( $colors['input_border'] ); ?>;
                color: <?php echo esc_attr( $colors['text'] ); ?>;
            }
            .login #loginform label,
            .login #registerform label,
            .login #lostpasswordform label {
                color: <?php echo esc_attr( $colors['text_secondary'] ); ?>;
            }
            .login #nav a, .login #backtoblog a {
                color: <?php echo esc_attr( $colors['text_secondary'] ); ?>;
            }
            .login #nav a:hover, .login #backtoblog a:hover {
                color: <?php echo esc_attr( $colors['text'] ); ?>;
            }
            .login .message, .login .success {
                background-color: <?php echo esc_attr( $colors['surface'] ); ?>;
                border-left-color: #72aee6;
                color: <?php echo esc_attr( $colors['text'] ); ?>;
            }
            .login h1 a {
                color: <?php echo esc_attr( $colors['text'] ); ?>;
            }
            .login .privacy-policy-page-link a {
                color: <?php echo esc_attr( $colors['text_secondary'] ); ?>;
            }
        }
        </style>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        $settings = $this->get_settings();
        $scheme   = $settings['color_scheme'] ?? 'midnight-blue';

        // Main dark mode CSS — loaded on ALL admin pages.
        wp_enqueue_style(
            'wpt-dark-mode',
            WPT_URL . 'modules/admin-interface/css/dark-mode.css',
            [],
            WPT_VERSION
        );

        // Toggle JS.
        wp_enqueue_script(
            'wpt-dark-mode-toggle',
            WPT_URL . 'modules/admin-interface/js/dark-mode-toggle.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-dark-mode-toggle', 'wptDarkMode', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wpt_dark_mode_nonce' ),
            'enableShortcut' => ! empty( $settings['enable_shortcut'] ),
            'includeSidebar' => ! empty( $settings['include_sidebar'] ),
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        // Count users with dark mode active.
        $dark_users  = $this->count_users_with_dark_mode();
        $total_users = (int) count_users()['total_users'];

        $this->render_status_summary( $settings, $dark_users, $total_users );

        ?>
        <table class="form-table" role="presentation">
            <!-- Default Mode -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Default mode for new users', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="margin-right: 16px;">
                            <input type="radio" name="wpt_default_mode" value="light"
                                   <?php checked( $settings['default_mode'], 'light' ); ?>>
                            <?php esc_html_e( 'Light', 'wptransformed' ); ?>
                        </label>
                        <label style="margin-right: 16px;">
                            <input type="radio" name="wpt_default_mode" value="dark"
                                   <?php checked( $settings['default_mode'], 'dark' ); ?>>
                            <?php esc_html_e( 'Dark', 'wptransformed' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="wpt_default_mode" value="auto"
                                   <?php checked( $settings['default_mode'], 'auto' ); ?>>
                            <?php esc_html_e( 'Auto-detect (follow OS)', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <!-- Color Scheme -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Color scheme', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php
                        $swatches = [
                            'midnight-blue' => [ '#1a1a2e', '#16213e' ],
                            'true-dark'     => [ '#121212', '#1e1e1e' ],
                            'charcoal'      => [ '#2d2d2d', '#383838' ],
                            'nord'          => [ '#2e3440', '#3b4252' ],
                        ];
                        foreach ( self::SCHEMES as $value => $label ) :
                            $colors = $swatches[ $value ];
                        ?>
                            <label style="display: inline-flex; align-items: center; margin-right: 20px; margin-bottom: 8px;">
                                <input type="radio" name="wpt_color_scheme" value="<?php echo esc_attr( $value ); ?>"
                                       <?php checked( $settings['color_scheme'], $value ); ?>
                                       style="margin-right: 6px;">
                                <span style="display: inline-block; width: 14px; height: 14px; background: <?php echo esc_attr( $colors[0] ); ?>; border-radius: 2px; margin-right: 2px; border: 1px solid #ccc;"></span>
                                <span style="display: inline-block; width: 14px; height: 14px; background: <?php echo esc_attr( $colors[1] ); ?>; border-radius: 2px; margin-right: 6px; border: 1px solid #ccc;"></span>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>

            <!-- Toggle Options -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Toggle options', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_show_toggle" value="1"
                                   <?php checked( ! empty( $settings['show_toggle'] ) ); ?>>
                            <?php esc_html_e( 'Show toggle in admin bar', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_enable_shortcut" value="1"
                                   <?php checked( ! empty( $settings['enable_shortcut'] ) ); ?>>
                            <?php
                            /* translators: keyboard shortcut */
                            esc_html_e( 'Enable keyboard shortcut Ctrl+Alt+D', 'wptransformed' );
                            ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_auto_detect_os" value="1"
                                   <?php checked( ! empty( $settings['auto_detect_os'] ) ); ?>>
                            <?php esc_html_e( 'Auto-detect OS preference', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Automatically use dark mode when the user\'s operating system is set to dark mode.', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_include_sidebar" value="1"
                                   <?php checked( ! empty( $settings['include_sidebar'] ) ); ?>>
                            <?php esc_html_e( 'Include admin sidebar in dark mode', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'By default, the sidebar respects the WordPress Admin Color Scheme. Enable this to darken it too.', 'wptransformed' ); ?>
                            </p>
                        </label>
                        <label style="display: block; margin-bottom: 4px;">
                            <input type="checkbox" name="wpt_login_dark_mode" value="1"
                                   <?php checked( ! empty( $settings['login_dark_mode'] ) ); ?>>
                            <?php esc_html_e( 'Dark mode on login page', 'wptransformed' ); ?>
                            <p class="description" style="margin: 2px 0 0 24px;">
                                <?php esc_html_e( 'Uses OS auto-detect via media query. No user login required.', 'wptransformed' ); ?>
                            </p>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <!-- Role Defaults -->
            <tr>
                <th scope="row"><?php esc_html_e( 'Role defaults', 'wptransformed' ); ?></th>
                <td>
                    <table class="widefat fixed" style="max-width: 500px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Role', 'wptransformed' ); ?></th>
                                <th><?php esc_html_e( 'Default Mode', 'wptransformed' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $roles = wp_roles()->get_names();
                            $role_defaults = $settings['role_defaults'] ?? [];
                            $options = [
                                ''     => __( 'Follow global default', 'wptransformed' ),
                                'light' => __( 'Light', 'wptransformed' ),
                                'dark'  => __( 'Dark', 'wptransformed' ),
                                'auto'  => __( 'Auto-detect', 'wptransformed' ),
                            ];
                            foreach ( $roles as $slug => $name ) :
                                $current = $role_defaults[ $slug ] ?? '';
                            ?>
                                <tr>
                                    <td><?php echo esc_html( translate_user_role( $name ) ); ?></td>
                                    <td>
                                        <select name="wpt_role_defaults[<?php echo esc_attr( $slug ); ?>]">
                                            <?php foreach ( $options as $val => $lbl ) : ?>
                                                <option value="<?php echo esc_attr( $val ); ?>"
                                                        <?php selected( $current, $val ); ?>>
                                                    <?php echo esc_html( $lbl ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e( 'Set the default dark mode for each role. Users can still override individually via the toggle.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render status summary.
     *
     * @param array<string,mixed> $settings    Module settings.
     * @param int                 $dark_users  Count of users with dark mode on.
     * @param int                 $total_users Total user count.
     */
    private function render_status_summary( array $settings, int $dark_users, int $total_users ): void {
        $scheme_label = self::SCHEMES[ $settings['color_scheme'] ] ?? 'Midnight Blue';
        $auto_detect  = ! empty( $settings['auto_detect_os'] );
        $show_toggle  = ! empty( $settings['show_toggle'] );
        ?>
        <div style="background: #fff; border: 1px solid #ddd; border-left: 4px solid #6366f1; border-radius: 4px; padding: 12px 16px; margin-bottom: 16px;">
            <p style="margin: 0 0 4px 0;">
                <strong><?php echo esc_html( $scheme_label ); ?></strong>
                &middot;
                <?php
                    echo esc_html(
                        $auto_detect
                            ? __( 'Auto-detect ON', 'wptransformed' )
                            : __( 'Auto-detect OFF', 'wptransformed' )
                    );
                ?>
                &middot;
                <?php
                    echo esc_html(
                        $show_toggle
                            ? __( 'Admin bar toggle ON', 'wptransformed' )
                            : __( 'Admin bar toggle OFF', 'wptransformed' )
                    );
                ?>
            </p>
            <p style="margin: 4px 0 0 0; color: #666;">
                <?php
                printf(
                    /* translators: 1: dark mode user count, 2: total user count */
                    esc_html__( 'Users with dark mode: %1$d of %2$d', 'wptransformed' ),
                    $dark_users,
                    $total_users
                );
                ?>
            </p>
        </div>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        // Default mode.
        $default_mode = sanitize_text_field( $raw['wpt_default_mode'] ?? 'light' );
        if ( ! in_array( $default_mode, [ 'light', 'dark', 'auto' ], true ) ) {
            $default_mode = 'light';
        }

        // Color scheme.
        $scheme = sanitize_key( $raw['wpt_color_scheme'] ?? 'midnight-blue' );
        if ( ! array_key_exists( $scheme, self::SCHEMES ) ) {
            $scheme = 'midnight-blue';
        }

        // Boolean options.
        $show_toggle    = ! empty( $raw['wpt_show_toggle'] );
        $enable_shortcut = ! empty( $raw['wpt_enable_shortcut'] );
        $auto_detect_os = ! empty( $raw['wpt_auto_detect_os'] );
        $include_sidebar = ! empty( $raw['wpt_include_sidebar'] );
        $login_dark_mode = ! empty( $raw['wpt_login_dark_mode'] );

        // Role defaults.
        $submitted_roles = (array) ( $raw['wpt_role_defaults'] ?? [] );
        $valid_roles     = array_keys( wp_roles()->get_names() );
        $role_defaults   = [];

        foreach ( $submitted_roles as $role => $value ) {
            $role  = sanitize_key( $role );
            $value = sanitize_text_field( $value );

            if ( in_array( $role, $valid_roles, true ) && in_array( $value, self::VALID_ROLE_DEFAULTS, true ) ) {
                if ( $value !== '' ) {
                    $role_defaults[ $role ] = $value;
                }
            }
        }

        return [
            'default_mode'    => $default_mode,
            'color_scheme'    => $scheme,
            'show_toggle'     => $show_toggle,
            'enable_shortcut' => $enable_shortcut,
            'auto_detect_os'  => $auto_detect_os,
            'include_sidebar'  => $include_sidebar,
            'login_dark_mode' => $login_dark_mode,
            'role_defaults'   => $role_defaults,
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => 'wpt_dark_mode' ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if dark mode is active for the current user.
     *
     * @return bool
     */
    private function is_dark_for_current_user(): bool {
        $user_pref = (string) get_user_meta( get_current_user_id(), 'wpt_dark_mode', true );
        $settings  = $this->get_settings();

        if ( $user_pref === 'dark' ) {
            return true;
        }

        if ( $user_pref === 'light' ) {
            return false;
        }

        // No explicit preference — check role default, then global default.
        $role_default = $this->get_role_default_for_user();

        if ( $role_default === 'dark' ) {
            return true;
        }

        if ( $role_default === 'light' ) {
            return false;
        }

        // Auto-detect happens client-side. Server assumes default.
        return ( $settings['default_mode'] ?? 'light' ) === 'dark';
    }

    /**
     * Get the role-based default for the current user.
     *
     * @return string '' | 'light' | 'dark' | 'auto'
     */
    private function get_role_default_for_user(): string {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return '';
        }

        $settings      = $this->get_settings();
        $role_defaults = $settings['role_defaults'] ?? [];

        // Use the first matching role.
        foreach ( $user->roles as $role ) {
            if ( isset( $role_defaults[ $role ] ) && $role_defaults[ $role ] !== '' ) {
                return $role_defaults[ $role ];
            }
        }

        return '';
    }

    /**
     * Count users who have dark mode set to 'dark'.
     *
     * @return int
     */
    private function count_users_with_dark_mode(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = 'wpt_dark_mode' AND meta_value = 'dark'"
        );
    }

    /**
     * Get admin bar background color for a scheme.
     *
     * @param string $scheme Scheme ID.
     * @return string CSS color value.
     */
    private function get_scheme_adminbar_bg( string $scheme ): string {
        $map = [
            'midnight-blue' => '#0f0f1a',
            'true-dark'     => '#0a0a0a',
            'charcoal'      => '#222222',
            'nord'          => '#242933',
        ];

        return $map[ $scheme ] ?? '#0f0f1a';
    }

    /**
     * Get login page colors for a scheme.
     *
     * @param string $scheme Scheme ID.
     * @return array<string,string> Color map.
     */
    private function get_scheme_login_colors( string $scheme ): array {
        $schemes = [
            'midnight-blue' => [
                'bg'             => '#1a1a2e',
                'surface'        => '#16213e',
                'text'           => '#e4e6eb',
                'text_secondary' => '#b0b3b8',
                'border'         => '#3a3f47',
                'input'          => '#1e2d44',
                'input_border'   => '#4a5568',
            ],
            'true-dark' => [
                'bg'             => '#121212',
                'surface'        => '#1e1e1e',
                'text'           => '#e0e0e0',
                'text_secondary' => '#aaaaaa',
                'border'         => '#333333',
                'input'          => '#2c2c2c',
                'input_border'   => '#444444',
            ],
            'charcoal' => [
                'bg'             => '#2d2d2d',
                'surface'        => '#383838',
                'text'           => '#e8e8e8',
                'text_secondary' => '#b8b8b8',
                'border'         => '#4a4a4a',
                'input'          => '#444444',
                'input_border'   => '#5a5a5a',
            ],
            'nord' => [
                'bg'             => '#2e3440',
                'surface'        => '#3b4252',
                'text'           => '#eceff4',
                'text_secondary' => '#d8dee9',
                'border'         => '#4c566a',
                'input'          => '#434c5e',
                'input_border'   => '#4c566a',
            ],
        ];

        return $schemes[ $scheme ] ?? $schemes['midnight-blue'];
    }
}
