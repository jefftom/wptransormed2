<?php
declare(strict_types=1);

namespace WPTransformed\Core;

use WPTransformed\Modules\LoginLogout\Login_Customizer;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Login Designer — dedicated APP page.
 *
 * Session 5 Part 1. Matches the reference mockup at
 * assets/admin/reference/app-pages/login-customizer-v3.html.
 *
 * Linked from the Session 3 Module Grid's Login Designer parent card
 * (wpt-login-designer slug). Wraps the existing Login_Customizer
 * module (module ID `login-branding`) with a split-pane UI:
 *
 * - Left pane (360px fixed): settings panel with 4 tabs
 *   (Design / Logo / Form / Advanced), save + reset footer buttons
 * - Right pane (flex 1): gradient background + device toolbar +
 *   live preview of the login form as a server-side HTML mockup that
 *   responds to input changes via JS (no iframe, no CORS)
 *
 * Data strategy:
 * - Settings are stored in the existing `login-branding` module row
 *   in wp_wpt_settings. The module's 14-field schema is unchanged;
 *   this app page just renders a nicer UI on top of it.
 * - Save goes through a dedicated admin-post action (wpt_save_login_designer)
 *   that calls Login_Customizer::sanitize_settings() and writes via
 *   Settings::save(), then redirects back to this page with ?wpt_saved=1.
 *   Avoids the shared Admin::handle_save() hardcoded redirect to
 *   the modules page.
 * - The live preview is pure client-side: every input has a data-preview
 *   attribute naming the CSS selector + property it controls, and
 *   login-designer.js wires input/change listeners to update the
 *   mockup DOM directly.
 *
 * Access control: manage_options.
 *
 * @package WPTransformed
 */
class Login_Designer_App {

    /** Module ID the app page wraps. */
    private const MODULE_ID = 'login-branding';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ], 20 );
        add_action( 'admin_post_wpt_save_login_designer', [ $this, 'handle_save' ] );
    }

    public function register_page(): void {
        $hook = add_submenu_page(
            'wpt-dashboard',
            __( 'Login Designer', 'wptransformed' ),
            __( 'Login Designer', 'wptransformed' ),
            'manage_options',
            'wpt-login-designer',
            [ $this, 'render' ]
        );

        add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        add_action( 'admin_enqueue_scripts', function () {
            wp_enqueue_style(
                'wpt-admin',
                WPT_URL . 'assets/admin/css/admin.css',
                [ 'wpt-admin-global' ],
                WPT_VERSION
            );
            wp_enqueue_script(
                'wpt-admin',
                WPT_URL . 'assets/admin/js/admin.js',
                [ 'wpt-admin-global' ],
                WPT_VERSION,
                true
            );
        } );
    }

    /**
     * Handle the form POST from the Login Designer page.
     *
     * Validates nonce + capability, instantiates Login_Customizer to reuse
     * its sanitize_settings() logic, writes to wp_wpt_settings via the
     * Settings class, then redirects back to this app page with a saved flag.
     */
    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptransformed' ) );
        }
        check_admin_referer( 'wpt_save_login_designer', 'wpt_login_designer_nonce' );

        $module = $this->get_module_instance();
        if ( $module ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
            $clean = $module->sanitize_settings( $_POST );
            Settings::save( self::MODULE_ID, $clean );
        }

        wp_safe_redirect( add_query_arg( [
            'page'      => 'wpt-login-designer',
            'wpt_saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptransformed' ) );
        }

        $module        = $this->get_module_instance();
        $module_active = Core::instance()->is_active( self::MODULE_ID );
        $settings      = $module ? $module->get_settings() : $this->get_default_settings();
        $just_saved    = isset( $_GET['wpt_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        ?>
        <div class="wpt-dashboard wpt-app-page wpt-login-designer" id="wptLoginDesigner">

            <?php if ( $just_saved ) : ?>
                <div class="wpt-app-notice wpt-app-notice-success" role="status">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong><?php esc_html_e( 'Login page updated', 'wptransformed' ); ?></strong>
                        <p><?php esc_html_e( 'Your new login design is live at wp-login.php.', 'wptransformed' ); ?></p>
                    </div>
                    <a class="btn btn-secondary" href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e( 'View', 'wptransformed' ); ?>
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ( ! $module_active ) : ?>
                <div class="wpt-app-notice wpt-app-notice-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong><?php esc_html_e( 'Login Branding module is inactive', 'wptransformed' ); ?></strong>
                        <p><?php esc_html_e( 'You can edit settings here, but they won\'t apply to wp-login.php until the module is activated.', 'wptransformed' ); ?></p>
                    </div>
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>">
                        <?php esc_html_e( 'Activate', 'wptransformed' ); ?>
                    </a>
                </div>
            <?php endif; ?>

            <form method="post"
                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  class="wpt-login-designer-layout"
                  id="wptLoginDesignerForm">

                <input type="hidden" name="action" value="wpt_save_login_designer">
                <?php wp_nonce_field( 'wpt_save_login_designer', 'wpt_login_designer_nonce' ); ?>

                <!-- ════════════════════════════════════════
                     LEFT PANE: settings panel
                ════════════════════════════════════════ -->
                <aside class="wpt-ld-panel">
                    <div class="wpt-ld-panel-header">
                        <h2>
                            <span class="wpt-page-header-icon blue"><i class="fas fa-fingerprint"></i></span>
                            <?php esc_html_e( 'Login Designer', 'wptransformed' ); ?>
                        </h2>
                        <a class="wpt-ld-close" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>" aria-label="<?php esc_attr_e( 'Back to Modules', 'wptransformed' ); ?>">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>

                    <div class="wpt-ld-tabs" role="tablist">
                        <button type="button" class="wpt-ld-tab active" role="tab" aria-selected="true" data-tab="design">
                            <?php esc_html_e( 'Design', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="wpt-ld-tab" role="tab" aria-selected="false" data-tab="logo">
                            <?php esc_html_e( 'Logo', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="wpt-ld-tab" role="tab" aria-selected="false" data-tab="form">
                            <?php esc_html_e( 'Form', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="wpt-ld-tab" role="tab" aria-selected="false" data-tab="advanced">
                            <?php esc_html_e( 'Advanced', 'wptransformed' ); ?>
                        </button>
                    </div>

                    <div class="wpt-ld-panel-content">

                        <!-- ──────────── DESIGN TAB ──────────── -->
                        <section class="wpt-ld-tab-panel active" data-tab-panel="design">

                            <div class="wpt-ld-section">
                                <div class="wpt-ld-section-header"><?php esc_html_e( 'Templates', 'wptransformed' ); ?></div>
                                <div class="wpt-ld-presets-grid">
                                    <?php foreach ( $this->get_templates() as $template_slug => $template ) : ?>
                                        <button type="button"
                                                class="wpt-ld-preset"
                                                data-template="<?php echo esc_attr( $template_slug ); ?>"
                                                data-template-json="<?php echo esc_attr( wp_json_encode( $template['settings'] ) ); ?>">
                                            <span class="wpt-ld-preset-preview" style="background: <?php echo esc_attr( $template['preview_bg'] ); ?>;">
                                                <span class="wpt-ld-mini-form">
                                                    <span class="wpt-ld-mini-logo"></span>
                                                    <span class="wpt-ld-mini-input"></span>
                                                    <span class="wpt-ld-mini-input"></span>
                                                    <span class="wpt-ld-mini-btn" style="background: <?php echo esc_attr( $template['settings']['button_bg_color'] ); ?>;"></span>
                                                </span>
                                            </span>
                                            <span class="wpt-ld-preset-name"><?php echo esc_html( $template['label'] ); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="wpt-ld-section">
                                <div class="wpt-ld-section-header"><?php esc_html_e( 'Background', 'wptransformed' ); ?></div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-bg-color"><?php esc_html_e( 'Background Color', 'wptransformed' ); ?></label>
                                    <?php $this->color_picker( 'bg_color', 'wpt-ld-bg-color', (string) $settings['bg_color'] ); ?>
                                </div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-bg-image"><?php esc_html_e( 'Background Image URL', 'wptransformed' ); ?></label>
                                    <input type="url"
                                           id="wpt-ld-bg-image"
                                           name="wpt_bg_image"
                                           class="wpt-ld-input"
                                           value="<?php echo esc_attr( (string) $settings['bg_image'] ); ?>"
                                           placeholder="https://example.com/background.jpg"
                                           data-preview-target=".wpt-ld-preview-area"
                                           data-preview-action="bg-image">
                                    <small class="wpt-ld-hint"><?php esc_html_e( 'Leave empty for solid color. Paste any image URL.', 'wptransformed' ); ?></small>
                                </div>
                            </div>
                        </section>

                        <!-- ──────────── LOGO TAB ──────────── -->
                        <section class="wpt-ld-tab-panel" data-tab-panel="logo">

                            <div class="wpt-ld-section">
                                <div class="wpt-ld-section-header"><?php esc_html_e( 'Logo Image', 'wptransformed' ); ?></div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-logo-url"><?php esc_html_e( 'Logo URL', 'wptransformed' ); ?></label>
                                    <input type="url"
                                           id="wpt-ld-logo-url"
                                           name="wpt_logo_url"
                                           class="wpt-ld-input"
                                           value="<?php echo esc_attr( (string) $settings['logo_url'] ); ?>"
                                           placeholder="https://example.com/logo.svg"
                                           data-preview-target=".wpt-ld-login-logo-img"
                                           data-preview-action="logo-url">
                                    <small class="wpt-ld-hint"><?php esc_html_e( 'PNG, SVG, or JPG. Leave empty to use the default WPTransformed bolt icon.', 'wptransformed' ); ?></small>
                                </div>

                                <div class="wpt-ld-form-grid-2">
                                    <div class="wpt-ld-form-group">
                                        <label class="wpt-ld-label" for="wpt-ld-logo-width"><?php esc_html_e( 'Width (px)', 'wptransformed' ); ?></label>
                                        <input type="number"
                                               id="wpt-ld-logo-width"
                                               name="wpt_logo_width"
                                               class="wpt-ld-input"
                                               value="<?php echo esc_attr( (string) $settings['logo_width'] ); ?>"
                                               min="1"
                                               max="1000">
                                    </div>
                                    <div class="wpt-ld-form-group">
                                        <label class="wpt-ld-label" for="wpt-ld-logo-height"><?php esc_html_e( 'Height (px)', 'wptransformed' ); ?></label>
                                        <input type="number"
                                               id="wpt-ld-logo-height"
                                               name="wpt_logo_height"
                                               class="wpt-ld-input"
                                               value="<?php echo esc_attr( (string) $settings['logo_height'] ); ?>"
                                               min="1"
                                               max="1000">
                                    </div>
                                </div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-logo-link"><?php esc_html_e( 'Logo Link URL', 'wptransformed' ); ?></label>
                                    <input type="url"
                                           id="wpt-ld-logo-link"
                                           name="wpt_logo_link"
                                           class="wpt-ld-input"
                                           value="<?php echo esc_attr( (string) $settings['logo_link'] ); ?>"
                                           placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
                                    <small class="wpt-ld-hint"><?php esc_html_e( 'Where the logo links to. Defaults to your site home.', 'wptransformed' ); ?></small>
                                </div>
                            </div>
                        </section>

                        <!-- ──────────── FORM TAB ──────────── -->
                        <section class="wpt-ld-tab-panel" data-tab-panel="form">

                            <div class="wpt-ld-section">
                                <div class="wpt-ld-section-header"><?php esc_html_e( 'Form Styling', 'wptransformed' ); ?></div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-form-bg"><?php esc_html_e( 'Form Background', 'wptransformed' ); ?></label>
                                    <?php $this->color_picker( 'form_bg_color', 'wpt-ld-form-bg', (string) $settings['form_bg_color'] ); ?>
                                </div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-form-radius">
                                        <?php esc_html_e( 'Border Radius', 'wptransformed' ); ?>
                                        <span class="wpt-ld-value-display" id="wpt-ld-radius-display"><?php echo esc_html( (string) $settings['form_border_radius'] ); ?>px</span>
                                    </label>
                                    <input type="range"
                                           id="wpt-ld-form-radius"
                                           name="wpt_form_border_radius"
                                           class="wpt-ld-range"
                                           value="<?php echo esc_attr( (string) $settings['form_border_radius'] ); ?>"
                                           min="0"
                                           max="50"
                                           step="1"
                                           data-preview-target=".wpt-ld-login-form"
                                           data-preview-action="border-radius"
                                           data-display-target="#wpt-ld-radius-display">
                                </div>
                            </div>

                            <div class="wpt-ld-section">
                                <div class="wpt-ld-section-header"><?php esc_html_e( 'Colors', 'wptransformed' ); ?></div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-button-bg"><?php esc_html_e( 'Button Color', 'wptransformed' ); ?></label>
                                    <?php $this->color_picker( 'button_bg_color', 'wpt-ld-button-bg', (string) $settings['button_bg_color'] ); ?>
                                </div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-button-text"><?php esc_html_e( 'Button Text', 'wptransformed' ); ?></label>
                                    <?php $this->color_picker( 'button_text_color', 'wpt-ld-button-text', (string) $settings['button_text_color'] ); ?>
                                </div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-text-color"><?php esc_html_e( 'Text Color', 'wptransformed' ); ?></label>
                                    <?php $this->color_picker( 'text_color', 'wpt-ld-text-color', (string) $settings['text_color'] ); ?>
                                </div>

                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-link-color"><?php esc_html_e( 'Link Color', 'wptransformed' ); ?></label>
                                    <?php $this->color_picker( 'link_color', 'wpt-ld-link-color', (string) $settings['link_color'] ); ?>
                                </div>
                            </div>

                            <div class="wpt-ld-section">
                                <div class="wpt-ld-section-header"><?php esc_html_e( 'Options', 'wptransformed' ); ?></div>

                                <div class="wpt-ld-toggle-row">
                                    <span><?php esc_html_e( 'Hide "Back to Site" link', 'wptransformed' ); ?></span>
                                    <label class="toggle">
                                        <input type="checkbox"
                                               name="wpt_hide_back_to_blog"
                                               value="1"
                                               data-preview-target=".wpt-ld-login-footer"
                                               data-preview-action="hide-back"
                                               <?php checked( ! empty( $settings['hide_back_to_blog'] ) ); ?>>
                                        <span class="toggle-track"></span>
                                    </label>
                                </div>

                                <div class="wpt-ld-toggle-row">
                                    <span><?php esc_html_e( 'Hide privacy policy link', 'wptransformed' ); ?></span>
                                    <label class="toggle">
                                        <input type="checkbox"
                                               name="wpt_hide_privacy_policy"
                                               value="1"
                                               <?php checked( ! empty( $settings['hide_privacy_policy'] ) ); ?>>
                                        <span class="toggle-track"></span>
                                    </label>
                                </div>
                            </div>
                        </section>

                        <!-- ──────────── ADVANCED TAB ──────────── -->
                        <section class="wpt-ld-tab-panel" data-tab-panel="advanced">
                            <div class="wpt-ld-section">
                                <div class="wpt-ld-section-header"><?php esc_html_e( 'Custom CSS', 'wptransformed' ); ?></div>
                                <div class="wpt-ld-form-group">
                                    <label class="wpt-ld-label" for="wpt-ld-custom-css">
                                        <?php esc_html_e( 'Extra CSS injected on wp-login.php', 'wptransformed' ); ?>
                                    </label>
                                    <textarea id="wpt-ld-custom-css"
                                              name="wpt_custom_css"
                                              class="wpt-ld-textarea"
                                              rows="8"
                                              placeholder="/* Your custom CSS */"><?php echo esc_textarea( (string) $settings['custom_css'] ); ?></textarea>
                                    <small class="wpt-ld-hint"><?php esc_html_e( 'Tags are stripped for safety. CSS is output inside a style block.', 'wptransformed' ); ?></small>
                                </div>
                            </div>
                        </section>

                    </div>

                    <footer class="wpt-ld-panel-footer">
                        <button type="button" class="btn btn-secondary" id="wptLoginDesignerReset">
                            <i class="fas fa-undo"></i> <?php esc_html_e( 'Reset', 'wptransformed' ); ?>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php esc_html_e( 'Save Changes', 'wptransformed' ); ?>
                        </button>
                    </footer>
                </aside>

                <!-- ════════════════════════════════════════
                     RIGHT PANE: live preview
                ════════════════════════════════════════ -->
                <div class="wpt-ld-preview-area"
                     style="
                         background-color: <?php echo esc_attr( (string) $settings['bg_color'] ); ?>;
                         <?php if ( ! empty( $settings['bg_image'] ) ) : ?>
                         background-image: url('<?php echo esc_url( (string) $settings['bg_image'] ); ?>');
                         background-size: cover;
                         background-position: center;
                         <?php endif; ?>
                     ">

                    <div class="wpt-ld-preview-toolbar">
                        <button type="button" class="wpt-ld-device-btn active" data-device="desktop" aria-label="<?php esc_attr_e( 'Desktop preview', 'wptransformed' ); ?>">
                            <i class="fas fa-desktop"></i>
                        </button>
                        <button type="button" class="wpt-ld-device-btn" data-device="tablet" aria-label="<?php esc_attr_e( 'Tablet preview', 'wptransformed' ); ?>">
                            <i class="fas fa-tablet-alt"></i>
                        </button>
                        <button type="button" class="wpt-ld-device-btn" data-device="mobile" aria-label="<?php esc_attr_e( 'Mobile preview', 'wptransformed' ); ?>">
                            <i class="fas fa-mobile-alt"></i>
                        </button>
                        <div class="wpt-ld-preview-url">
                            <i class="fas fa-lock"></i>
                            <span><?php echo esc_html( wp_parse_url( wp_login_url(), PHP_URL_HOST ) . wp_parse_url( wp_login_url(), PHP_URL_PATH ) ); ?></span>
                        </div>
                    </div>

                    <div class="wpt-ld-preview-wrap" data-device="desktop">
                        <div class="wpt-ld-login-preview">
                            <div class="wpt-ld-login-logo">
                                <?php if ( ! empty( $settings['logo_url'] ) ) : ?>
                                    <img class="wpt-ld-login-logo-img"
                                         src="<?php echo esc_url( (string) $settings['logo_url'] ); ?>"
                                         alt=""
                                         style="
                                             width: <?php echo esc_attr( (string) (int) $settings['logo_width'] ); ?>px;
                                             height: <?php echo esc_attr( (string) (int) $settings['logo_height'] ); ?>px;
                                         ">
                                <?php else : ?>
                                    <div class="wpt-ld-login-logo-placeholder"><i class="fas fa-bolt"></i></div>
                                    <img class="wpt-ld-login-logo-img" src="" alt="" hidden>
                                <?php endif; ?>
                            </div>

                            <div class="wpt-ld-login-form"
                                 style="
                                     background-color: <?php echo esc_attr( (string) $settings['form_bg_color'] ); ?>;
                                     color: <?php echo esc_attr( (string) $settings['text_color'] ); ?>;
                                     border-radius: <?php echo esc_attr( (string) (int) $settings['form_border_radius'] ); ?>px;
                                 ">
                                <h3 class="wpt-ld-login-title" style="color: <?php echo esc_attr( (string) $settings['text_color'] ); ?>;">
                                    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
                                </h3>
                                <div class="wpt-ld-login-field">
                                    <label style="color: <?php echo esc_attr( (string) $settings['text_color'] ); ?>;"><?php esc_html_e( 'Username or Email', 'wptransformed' ); ?></label>
                                    <input type="text" placeholder="<?php esc_attr_e( 'admin', 'wptransformed' ); ?>" readonly>
                                </div>
                                <div class="wpt-ld-login-field">
                                    <label style="color: <?php echo esc_attr( (string) $settings['text_color'] ); ?>;"><?php esc_html_e( 'Password', 'wptransformed' ); ?></label>
                                    <input type="password" placeholder="••••••••" readonly>
                                </div>
                                <div class="wpt-ld-login-remember">
                                    <label style="color: <?php echo esc_attr( (string) $settings['text_color'] ); ?>;"><input type="checkbox" disabled> <?php esc_html_e( 'Remember me', 'wptransformed' ); ?></label>
                                    <a href="#" style="color: <?php echo esc_attr( (string) $settings['link_color'] ); ?>;" onclick="event.preventDefault()"><?php esc_html_e( 'Lost password?', 'wptransformed' ); ?></a>
                                </div>
                                <button type="button"
                                        class="wpt-ld-login-btn"
                                        style="
                                            background-color: <?php echo esc_attr( (string) $settings['button_bg_color'] ); ?>;
                                            color: <?php echo esc_attr( (string) $settings['button_text_color'] ); ?>;
                                        ">
                                    <?php esc_html_e( 'Log In', 'wptransformed' ); ?>
                                </button>
                            </div>

                            <div class="wpt-ld-login-footer" <?php echo ! empty( $settings['hide_back_to_blog'] ) ? 'hidden' : ''; ?>>
                                <a href="#" style="color: <?php echo esc_attr( (string) $settings['link_color'] ); ?>;" onclick="event.preventDefault()">
                                    &larr; <?php printf( esc_html__( 'Back to %s', 'wptransformed' ), esc_html( get_bloginfo( 'name' ) ) ); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════
       Helpers
    ══════════════════════════════════════════ */

    /**
     * Render a color picker row: swatch + hex text input.
     *
     * Field names use the `wpt_` prefix because Login_Customizer::sanitize_settings()
     * reads `$raw['wpt_bg_color']` etc. — the existing convention from the
     * module's own settings page. The app page posts to the same handler
     * so it must match. See class docblock for the Session 5 Part 1
     * field-name mismatch bug fix (2026-04-11).
     */
    private function color_picker( string $field, string $id, string $value ): void {
        $target_map = [
            'bg_color'          => [ 'target' => '.wpt-ld-preview-area',    'action' => 'bg-color' ],
            'form_bg_color'     => [ 'target' => '.wpt-ld-login-form',      'action' => 'bg-color' ],
            'button_bg_color'   => [ 'target' => '.wpt-ld-login-btn',       'action' => 'bg-color' ],
            'button_text_color' => [ 'target' => '.wpt-ld-login-btn',       'action' => 'color'    ],
            'text_color'        => [ 'target' => '.wpt-ld-login-form',      'action' => 'text-color' ],
            'link_color'        => [ 'target' => '.wpt-ld-login-form a',    'action' => 'color'    ],
        ];
        $preview    = $target_map[ $field ] ?? [ 'target' => '', 'action' => '' ];
        $field_name = 'wpt_' . $field;
        ?>
        <div class="wpt-ld-color-picker">
            <label class="wpt-ld-color-swatch" style="background-color: <?php echo esc_attr( $value ); ?>;">
                <input type="color"
                       value="<?php echo esc_attr( $value ); ?>"
                       data-color-target="#<?php echo esc_attr( $id ); ?>"
                       aria-label="<?php esc_attr_e( 'Pick color', 'wptransformed' ); ?>">
            </label>
            <input type="text"
                   id="<?php echo esc_attr( $id ); ?>"
                   name="<?php echo esc_attr( $field_name ); ?>"
                   class="wpt-ld-input wpt-ld-color-input"
                   value="<?php echo esc_attr( $value ); ?>"
                   pattern="^#[0-9a-fA-F]{6}$"
                   data-preview-target="<?php echo esc_attr( $preview['target'] ); ?>"
                   data-preview-action="<?php echo esc_attr( $preview['action'] ); ?>">
        </div>
        <?php
    }

    /**
     * Built-in templates. Each one is a set of overrides that get
     * applied to the form when the user clicks a preset card. The
     * actual save still goes through the regular form submit flow.
     *
     * @return array<string,array{label:string, preview_bg:string, settings:array<string,mixed>}>
     */
    private function get_templates(): array {
        return [
            'dark-gradient' => [
                'label'      => __( 'Dark Gradient', 'wptransformed' ),
                'preview_bg' => 'linear-gradient(135deg, #0f2847, #2563eb)',
                'settings'   => [
                    'bg_color'          => '#0f2847',
                    'form_bg_color'     => '#ffffff',
                    'form_border_radius'=> 16,
                    'button_bg_color'   => '#2563eb',
                    'button_text_color' => '#ffffff',
                    'text_color'        => '#0f172a',
                    'link_color'        => '#2563eb',
                ],
            ],
            'clean-light' => [
                'label'      => __( 'Clean Light', 'wptransformed' ),
                'preview_bg' => '#f8fafc',
                'settings'   => [
                    'bg_color'          => '#f8fafc',
                    'form_bg_color'     => '#ffffff',
                    'form_border_radius'=> 12,
                    'button_bg_color'   => '#0f172a',
                    'button_text_color' => '#ffffff',
                    'text_color'        => '#1e293b',
                    'link_color'        => '#2563eb',
                ],
            ],
            'slate' => [
                'label'      => __( 'Slate', 'wptransformed' ),
                'preview_bg' => 'linear-gradient(135deg, #1e293b, #334155)',
                'settings'   => [
                    'bg_color'          => '#1e293b',
                    'form_bg_color'     => '#0f172a',
                    'form_border_radius'=> 8,
                    'button_bg_color'   => '#06d6a0',
                    'button_text_color' => '#0f172a',
                    'text_color'        => '#f1f5f9',
                    'link_color'        => '#06d6a0',
                ],
            ],
        ];
    }

    /**
     * Instantiate the Login_Customizer module directly so we can call
     * get_settings() and sanitize_settings() regardless of whether the
     * module is currently toggled on in the active-modules set.
     *
     * @return Login_Customizer|null
     */
    private function get_module_instance(): ?Login_Customizer {
        $class = '\WPTransformed\Modules\LoginLogout\Login_Customizer';
        if ( ! class_exists( $class ) ) {
            $file = WPT_PATH . 'modules/login-logout/class-login-customizer.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( $class ) ) {
            return null;
        }
        return new $class();
    }

    /**
     * Fallback defaults when we can't instantiate the module for some reason.
     * Mirrors Login_Customizer::get_default_settings() exactly.
     *
     * @return array<string,mixed>
     */
    private function get_default_settings(): array {
        return [
            'logo_url'            => '',
            'logo_link'           => '',
            'logo_width'          => 320,
            'logo_height'         => 84,
            'bg_color'            => '#f1f1f1',
            'bg_image'            => '',
            'form_bg_color'       => '#ffffff',
            'form_border_radius'  => 4,
            'button_bg_color'     => '#2271b1',
            'button_text_color'   => '#ffffff',
            'text_color'          => '#3c434a',
            'link_color'          => '#2271b1',
            'hide_back_to_blog'   => false,
            'hide_privacy_policy' => false,
            'custom_css'          => '',
        ];
    }
}
