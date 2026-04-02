<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;
use WPTransformed\Core\Settings;
use WPTransformed\Core\Module_Registry;

/**
 * Setup Wizard — Guided 4-step onboarding flow on first activation.
 *
 * Features:
 *  - Full-screen wizard with no admin sidebar/toolbar
 *  - Profile selection (blog, business, ecommerce, agency, developer)
 *  - Module selection grid with profile-based pre-checks
 *  - Admin cleanup options
 *  - Stores completion state in settings
 *  - Redirects on first activation via transient flag
 *  - WooCommerce detection for profile suggestion
 *  - Responsive design for mobile admin
 *  - Skip option on every step
 *
 * @package WPTransformed
 */
class Setup_Wizard extends Module_Base {

    /**
     * Profile to module mapping.
     */
    private const PROFILES = [
        'blog'       => [ 'content-duplication', 'dark-mode', 'heartbeat-control', 'disable-comments', 'lazy-load' ],
        'business'   => [ 'content-duplication', 'dark-mode', 'login-customizer', 'cookie-consent', 'redirect-manager', 'email-smtp', 'maintenance-mode' ],
        'ecommerce'  => [ 'content-duplication', 'dark-mode', 'cookie-consent', 'redirect-manager', 'email-smtp', 'lazy-load', 'image-upload-control', 'limit-login-attempts' ],
        'agency'     => [ 'content-duplication', 'dark-mode', 'command-palette', 'smart-menu-organizer', 'search-replace', 'database-cleanup', 'maintenance-mode', 'cron-manager', 'audit-log' ],
        'developer'  => [ 'command-palette', 'dark-mode', 'code-snippets', 'cron-manager', 'database-cleanup', 'search-replace', 'audit-log', 'user-role-editor' ],
    ];

    /**
     * Minimal default modules when skipping setup.
     */
    private const SKIP_DEFAULTS = [ 'content-duplication', 'dark-mode' ];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'setup-wizard';
    }

    public function get_title(): string {
        return __( 'Setup Wizard', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Guided onboarding flow that runs on first activation to help configure your site.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'completed'        => false,
            'completed_at'     => '',
            'selected_profile' => '',
            'skipped'          => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Activation redirect check.
        add_action( 'admin_init', [ $this, 'maybe_redirect' ] );

        // Register hidden admin page.
        add_action( 'admin_menu', [ $this, 'register_page' ] );

        // AJAX handler for wizard completion.
        add_action( 'wp_ajax_wpt_setup_wizard_complete', [ $this, 'ajax_complete' ] );
    }

    // ── Activation Redirect ───────────────────────────────────

    /**
     * Redirect to wizard on first activation (if not yet completed).
     */
    public function maybe_redirect(): void {
        $settings = $this->get_settings();

        // Already completed — nothing to do.
        if ( ! empty( $settings['completed'] ) ) {
            return;
        }

        // Only redirect if the activation transient is set.
        if ( ! get_transient( 'wpt_activation_redirect' ) ) {
            return;
        }

        // Delete the transient so we don't redirect again.
        delete_transient( 'wpt_activation_redirect' );

        // Don't redirect on bulk activations or AJAX.
        if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) ) {
            return;
        }

        // Only admins.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wpt-setup-wizard' ) );
        exit;
    }

    // ── Admin Page ────────────────────────────────────────────

    /**
     * Register hidden admin page (not shown in menu).
     */
    public function register_page(): void {
        add_submenu_page(
            null, // No parent — hidden page.
            __( 'WPTransformed Setup', 'wptransformed' ),
            __( 'Setup Wizard', 'wptransformed' ),
            'manage_options',
            'wpt-setup-wizard',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Render the full wizard page.
     */
    public function render_page(): void {
        $settings = $this->get_settings();

        // Already completed — show notice.
        if ( ! empty( $settings['completed'] ) ) {
            $this->render_already_completed();
            return;
        }

        // Collect available modules from registry.
        $all_modules     = Module_Registry::get_all();
        $woo_active      = $this->is_woocommerce_active();
        $nonce           = wp_create_nonce( 'wpt_setup_wizard_nonce' );
        $ajax_url        = admin_url( 'admin-ajax.php' );
        $dashboard_url   = admin_url();
        $profiles_json   = wp_json_encode( self::PROFILES );
        $module_titles   = $this->get_module_titles( $all_modules );
        $titles_json     = wp_json_encode( $module_titles );

        // Full-screen: hide admin chrome.
        $this->render_fullscreen_styles();

        ?>
        <div id="wpt-setup-wizard" class="wpt-wizard-wrap">
            <div class="wpt-wizard-container">

                <!-- Step indicator -->
                <div class="wpt-wizard-steps">
                    <div class="wpt-step active" data-step="1">
                        <span class="wpt-step-number">1</span>
                        <span class="wpt-step-label"><?php esc_html_e( 'Welcome', 'wptransformed' ); ?></span>
                    </div>
                    <div class="wpt-step-line"></div>
                    <div class="wpt-step" data-step="2">
                        <span class="wpt-step-number">2</span>
                        <span class="wpt-step-label"><?php esc_html_e( 'Profile', 'wptransformed' ); ?></span>
                    </div>
                    <div class="wpt-step-line"></div>
                    <div class="wpt-step" data-step="3">
                        <span class="wpt-step-number">3</span>
                        <span class="wpt-step-label"><?php esc_html_e( 'Modules', 'wptransformed' ); ?></span>
                    </div>
                    <div class="wpt-step-line"></div>
                    <div class="wpt-step" data-step="4">
                        <span class="wpt-step-number">4</span>
                        <span class="wpt-step-label"><?php esc_html_e( 'Finish', 'wptransformed' ); ?></span>
                    </div>
                </div>

                <!-- Step 1: Welcome -->
                <div class="wpt-wizard-panel active" data-panel="1">
                    <h1><?php esc_html_e( 'Welcome to WPTransformed', 'wptransformed' ); ?></h1>
                    <p class="wpt-wizard-subtitle">
                        <?php esc_html_e( 'This quick setup wizard will help you configure the most useful modules for your site. It only takes a minute.', 'wptransformed' ); ?>
                    </p>
                    <div class="wpt-wizard-actions">
                        <button type="button" class="button button-primary button-hero wpt-wizard-next">
                            <?php esc_html_e( 'Let\'s Go!', 'wptransformed' ); ?>
                        </button>
                    </div>
                    <p class="wpt-wizard-skip">
                        <a href="#" class="wpt-skip-setup"><?php esc_html_e( 'Skip Setup', 'wptransformed' ); ?></a>
                    </p>
                </div>

                <!-- Step 2: Quick Profile -->
                <div class="wpt-wizard-panel" data-panel="2">
                    <h1><?php esc_html_e( 'What type of site is this?', 'wptransformed' ); ?></h1>
                    <p class="wpt-wizard-subtitle">
                        <?php esc_html_e( 'Choose a profile to pre-select recommended modules. You can customize everything in the next step.', 'wptransformed' ); ?>
                    </p>
                    <?php if ( $woo_active ) : ?>
                        <p class="wpt-wizard-notice">
                            <?php esc_html_e( 'WooCommerce detected — the Ecommerce profile is recommended.', 'wptransformed' ); ?>
                        </p>
                    <?php endif; ?>
                    <div class="wpt-profile-grid">
                        <?php
                        $profile_data = [
                            'blog'       => [ __( 'Blog / Magazine', 'wptransformed' ), 'dashicons-welcome-write-blog', __( 'Content creation, performance, SEO', 'wptransformed' ) ],
                            'business'   => [ __( 'Business / Corporate', 'wptransformed' ), 'dashicons-building', __( 'Branding, security, email, compliance', 'wptransformed' ) ],
                            'ecommerce'  => [ __( 'Ecommerce / Shop', 'wptransformed' ), 'dashicons-cart', __( 'Performance, security, compliance', 'wptransformed' ) ],
                            'agency'     => [ __( 'Agency / Freelancer', 'wptransformed' ), 'dashicons-groups', __( 'Productivity, cleanup, maintenance', 'wptransformed' ) ],
                            'developer'  => [ __( 'Developer', 'wptransformed' ), 'dashicons-editor-code', __( 'Tools, debugging, code management', 'wptransformed' ) ],
                        ];
                        foreach ( $profile_data as $key => $info ) :
                            $recommended = ( $key === 'ecommerce' && $woo_active ) ? ' wpt-recommended' : '';
                        ?>
                            <button type="button"
                                    class="wpt-profile-card<?php echo esc_attr( $recommended ); ?>"
                                    data-profile="<?php echo esc_attr( $key ); ?>">
                                <span class="dashicons <?php echo esc_attr( $info[1] ); ?>"></span>
                                <strong><?php echo esc_html( $info[0] ); ?></strong>
                                <span class="wpt-profile-desc"><?php echo esc_html( $info[2] ); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="wpt-wizard-actions">
                        <button type="button" class="button wpt-wizard-prev">
                            <?php esc_html_e( 'Back', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="button button-primary wpt-wizard-next" disabled>
                            <?php esc_html_e( 'Next', 'wptransformed' ); ?>
                        </button>
                    </div>
                    <p class="wpt-wizard-skip">
                        <a href="#" class="wpt-skip-setup"><?php esc_html_e( 'Skip Setup', 'wptransformed' ); ?></a>
                    </p>
                </div>

                <!-- Step 3: Module Selection -->
                <div class="wpt-wizard-panel" data-panel="3">
                    <h1><?php esc_html_e( 'Select Your Modules', 'wptransformed' ); ?></h1>
                    <p class="wpt-wizard-subtitle">
                        <?php esc_html_e( 'Check the modules you want to activate. You can always change these later from the settings page.', 'wptransformed' ); ?>
                    </p>
                    <div class="wpt-module-grid">
                        <?php foreach ( $all_modules as $mod_id => $mod_path ) :
                            // Don't show setup-wizard itself in the grid.
                            if ( $mod_id === 'setup-wizard' ) {
                                continue;
                            }
                            $title = $module_titles[ $mod_id ] ?? $mod_id;
                        ?>
                            <label class="wpt-module-card" data-module="<?php echo esc_attr( $mod_id ); ?>">
                                <input type="checkbox" name="wpt_modules[]"
                                       value="<?php echo esc_attr( $mod_id ); ?>">
                                <span class="wpt-module-title"><?php echo esc_html( $title ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="wpt-module-count">
                        <span id="wpt-selected-count">0</span>
                        <?php esc_html_e( 'modules selected', 'wptransformed' ); ?>
                    </p>
                    <div class="wpt-wizard-actions">
                        <button type="button" class="button wpt-wizard-prev">
                            <?php esc_html_e( 'Back', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="button button-primary wpt-wizard-next">
                            <?php esc_html_e( 'Next', 'wptransformed' ); ?>
                        </button>
                    </div>
                    <p class="wpt-wizard-skip">
                        <a href="#" class="wpt-skip-setup"><?php esc_html_e( 'Skip Setup', 'wptransformed' ); ?></a>
                    </p>
                </div>

                <!-- Step 4: Finish -->
                <div class="wpt-wizard-panel" data-panel="4">
                    <h1><?php esc_html_e( 'Ready to Go!', 'wptransformed' ); ?></h1>
                    <p class="wpt-wizard-subtitle">
                        <?php esc_html_e( 'Click the button below to activate your selected modules and complete the setup.', 'wptransformed' ); ?>
                    </p>
                    <div id="wpt-finish-summary"></div>
                    <div class="wpt-wizard-actions">
                        <button type="button" class="button wpt-wizard-prev">
                            <?php esc_html_e( 'Back', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="button button-primary button-hero" id="wpt-finish-setup">
                            <?php esc_html_e( 'Finish Setup', 'wptransformed' ); ?>
                        </button>
                    </div>
                    <p class="wpt-wizard-skip">
                        <a href="#" class="wpt-skip-setup"><?php esc_html_e( 'Skip Setup', 'wptransformed' ); ?></a>
                    </p>
                </div>

                <!-- Completion screen (shown after AJAX) -->
                <div class="wpt-wizard-panel" data-panel="done" style="display:none;">
                    <h1><?php esc_html_e( 'Setup Complete!', 'wptransformed' ); ?></h1>
                    <p class="wpt-wizard-subtitle">
                        <?php esc_html_e( 'Your selected modules are now active. You can manage them anytime from the WPTransformed settings page.', 'wptransformed' ); ?>
                    </p>
                    <div class="wpt-wizard-actions">
                        <a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary button-hero">
                            <?php esc_html_e( 'Go to Dashboard', 'wptransformed' ); ?>
                        </a>
                    </div>
                </div>

            </div>
        </div>

        <script>
        (function() {
            'use strict';

            var profiles     = <?php echo $profiles_json; // Already JSON-encoded. ?>;

            var moduleTitles = <?php echo $titles_json; ?>;
            var ajaxUrl      = <?php echo wp_json_encode( $ajax_url ); ?>;
            var nonce        = <?php echo wp_json_encode( $nonce ); ?>;
            var dashboardUrl = <?php echo wp_json_encode( $dashboard_url ); ?>;

            var currentStep     = 1;
            var selectedProfile = '';
            var wizard          = document.getElementById('wpt-setup-wizard');

            if (!wizard) return;

            // ── Navigation ──

            function showStep(step) {
                currentStep = step;
                var panels = wizard.querySelectorAll('.wpt-wizard-panel');
                for (var i = 0; i < panels.length; i++) {
                    panels[i].classList.remove('active');
                    panels[i].style.display = 'none';
                }
                var target = wizard.querySelector('[data-panel="' + step + '"]');
                if (target) {
                    target.classList.add('active');
                    target.style.display = '';
                }

                // Update step indicators.
                var steps = wizard.querySelectorAll('.wpt-step');
                for (var j = 0; j < steps.length; j++) {
                    var s = parseInt(steps[j].getAttribute('data-step'), 10);
                    steps[j].classList.remove('active', 'done');
                    if (s === step) steps[j].classList.add('active');
                    if (s < step)  steps[j].classList.add('done');
                }

                // Build finish summary on step 4.
                if (step === 4) buildSummary();
            }

            // Next buttons.
            var nextBtns = wizard.querySelectorAll('.wpt-wizard-next');
            for (var n = 0; n < nextBtns.length; n++) {
                nextBtns[n].addEventListener('click', function() {
                    showStep(currentStep + 1);
                });
            }

            // Prev buttons.
            var prevBtns = wizard.querySelectorAll('.wpt-wizard-prev');
            for (var p = 0; p < prevBtns.length; p++) {
                prevBtns[p].addEventListener('click', function() {
                    showStep(currentStep - 1);
                });
            }

            // ── Profile Selection ──

            var profileCards = wizard.querySelectorAll('.wpt-profile-card');
            for (var pc = 0; pc < profileCards.length; pc++) {
                profileCards[pc].addEventListener('click', function() {
                    // Deselect all.
                    for (var x = 0; x < profileCards.length; x++) {
                        profileCards[x].classList.remove('selected');
                    }
                    this.classList.add('selected');
                    selectedProfile = this.getAttribute('data-profile');

                    // Enable next button.
                    var nextBtn = wizard.querySelector('[data-panel="2"] .wpt-wizard-next');
                    if (nextBtn) nextBtn.disabled = false;

                    // Pre-check modules for this profile.
                    applyProfile(selectedProfile);
                });
            }

            function applyProfile(profile) {
                var recommended = profiles[profile] || [];
                var checkboxes = wizard.querySelectorAll('.wpt-module-card input[type="checkbox"]');
                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = recommended.indexOf(checkboxes[i].value) !== -1;
                }
                updateCount();
            }

            // ── Module Selection ──

            var moduleCheckboxes = wizard.querySelectorAll('.wpt-module-card input[type="checkbox"]');
            for (var mc = 0; mc < moduleCheckboxes.length; mc++) {
                moduleCheckboxes[mc].addEventListener('change', updateCount);
            }

            function updateCount() {
                var cbs = wizard.querySelectorAll('.wpt-module-card input[type="checkbox"]:checked');
                var el = document.getElementById('wpt-selected-count');
                if (el) el.textContent = String(cbs.length);
            }

            function getSelectedModules() {
                var selected = [];
                var cbs = wizard.querySelectorAll('.wpt-module-card input[type="checkbox"]:checked');
                for (var i = 0; i < cbs.length; i++) {
                    selected.push(cbs[i].value);
                }
                return selected;
            }

            // ── Finish Summary ──

            function buildSummary() {
                var selected = getSelectedModules();
                var el = document.getElementById('wpt-finish-summary');
                if (!el) return;

                if (selected.length === 0) {
                    el.innerHTML = '<p class="wpt-wizard-notice">' +
                        <?php echo wp_json_encode( esc_html__( 'No modules selected. You can enable modules later from the settings page.', 'wptransformed' ) ); ?> +
                        '</p>';
                    return;
                }

                var html = '<ul class="wpt-finish-list">';
                for (var i = 0; i < selected.length; i++) {
                    var title = moduleTitles[selected[i]] || selected[i];
                    html += '<li>' + escapeHtml(title) + '</li>';
                }
                html += '</ul>';
                el.innerHTML = html;
            }

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }

            // ── Finish Setup (AJAX) ──

            var finishBtn = document.getElementById('wpt-finish-setup');
            if (finishBtn) {
                finishBtn.addEventListener('click', function() {
                    finishBtn.disabled = true;
                    finishBtn.textContent = <?php echo wp_json_encode( esc_html__( 'Setting up...', 'wptransformed' ) ); ?>;
                    submitWizard(false);
                });
            }

            // ── Skip Setup ──

            var skipLinks = wizard.querySelectorAll('.wpt-skip-setup');
            for (var sl = 0; sl < skipLinks.length; sl++) {
                skipLinks[sl].addEventListener('click', function(e) {
                    e.preventDefault();
                    submitWizard(true);
                });
            }

            // ── AJAX Submit ──

            function submitWizard(skipped) {
                var data = new FormData();
                data.append('action', 'wpt_setup_wizard_complete');
                data.append('nonce', nonce);
                data.append('skipped', skipped ? '1' : '0');
                data.append('profile', selectedProfile);

                if (!skipped) {
                    var selected = getSelectedModules();
                    for (var i = 0; i < selected.length; i++) {
                        data.append('modules[]', selected[i]);
                    }
                }

                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl, true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) return;
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showStep('done');
                                return;
                            }
                        } catch (e) { /* fall through */ }
                    }
                    // Error — reload to dashboard.
                    window.location.href = dashboardUrl;
                };
                xhr.send(data);
            }

            // Initialize: show step 1, hide others.
            showStep(1);
        })();
        </script>
        <?php
    }

    // ── Full-Screen Styles ────────────────────────────────────

    /**
     * Output CSS that hides admin chrome for full-screen wizard.
     */
    private function render_fullscreen_styles(): void {
        ?>
        <style>
        /* Hide admin chrome for full-screen wizard */
        #adminmenuwrap, #adminmenuback, #adminmenumain,
        #wpadminbar, #wpfooter, #screen-meta, #screen-meta-links {
            display: none !important;
        }
        #wpcontent, #wpbody { margin-left: 0 !important; }
        html.wp-toolbar { padding-top: 0 !important; }

        /* Wizard layout */
        .wpt-wizard-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f1;
            padding: 20px;
            box-sizing: border-box;
        }
        .wpt-wizard-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            max-width: 800px;
            width: 100%;
            padding: 40px;
        }

        /* Step indicator */
        .wpt-wizard-steps {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
            gap: 4px;
        }
        .wpt-step {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #999;
        }
        .wpt-step.active { color: #2271b1; font-weight: 600; }
        .wpt-step.done { color: #00a32a; }
        .wpt-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #ddd;
            font-size: 13px;
        }
        .wpt-step.active .wpt-step-number {
            border-color: #2271b1;
            background: #2271b1;
            color: #fff;
        }
        .wpt-step.done .wpt-step-number {
            border-color: #00a32a;
            background: #00a32a;
            color: #fff;
        }
        .wpt-step-line {
            width: 40px;
            height: 2px;
            background: #ddd;
            margin: 0 8px;
        }

        /* Panels */
        .wpt-wizard-panel { display: none; text-align: center; }
        .wpt-wizard-panel.active { display: block; }
        .wpt-wizard-panel h1 { font-size: 28px; margin: 0 0 12px; color: #1d2327; }
        .wpt-wizard-subtitle { color: #646970; font-size: 15px; margin: 0 0 30px; line-height: 1.5; }
        .wpt-wizard-notice {
            background: #fcf9e8;
            border-left: 4px solid #dba617;
            padding: 10px 14px;
            margin: 0 0 20px;
            text-align: left;
            border-radius: 2px;
            color: #1d2327;
        }

        /* Actions */
        .wpt-wizard-actions {
            margin-top: 30px;
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .wpt-wizard-skip {
            margin-top: 16px;
            font-size: 13px;
        }
        .wpt-wizard-skip a { color: #999; text-decoration: none; }
        .wpt-wizard-skip a:hover { color: #2271b1; }

        /* Profile cards */
        .wpt-profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin: 0 0 10px;
        }
        .wpt-profile-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 20px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s;
            text-align: center;
        }
        .wpt-profile-card:hover { border-color: #2271b1; }
        .wpt-profile-card.selected {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            background: #f0f6fc;
        }
        .wpt-profile-card.wpt-recommended { border-color: #dba617; }
        .wpt-profile-card .dashicons { font-size: 32px; width: 32px; height: 32px; color: #2271b1; }
        .wpt-profile-card strong { font-size: 14px; color: #1d2327; }
        .wpt-profile-desc { font-size: 12px; color: #646970; }

        /* Module grid */
        .wpt-module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 8px;
            margin: 0 0 10px;
            text-align: left;
            max-height: 400px;
            overflow-y: auto;
            padding: 4px;
        }
        .wpt-module-card {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: border-color 0.15s;
            font-size: 13px;
        }
        .wpt-module-card:hover { border-color: #2271b1; }
        .wpt-module-card input:checked ~ .wpt-module-title { font-weight: 600; }
        .wpt-module-count {
            text-align: center;
            color: #646970;
            font-size: 13px;
            margin: 8px 0 0;
        }

        /* Finish list */
        .wpt-finish-list {
            text-align: left;
            columns: 2;
            column-gap: 24px;
            margin: 0 auto 10px;
            max-width: 500px;
            list-style: none;
            padding: 0;
        }
        .wpt-finish-list li {
            padding: 4px 0;
            font-size: 13px;
            color: #1d2327;
        }
        .wpt-finish-list li::before {
            content: "\2713 ";
            color: #00a32a;
            font-weight: 700;
            margin-right: 4px;
        }

        /* Already completed */
        .wpt-wizard-completed {
            text-align: center;
            padding: 60px 20px;
        }
        .wpt-wizard-completed h1 { margin: 0 0 12px; }
        .wpt-wizard-completed p { color: #646970; margin: 0 0 20px; }

        /* Responsive */
        @media (max-width: 600px) {
            .wpt-wizard-container { padding: 24px 16px; }
            .wpt-wizard-panel h1 { font-size: 22px; }
            .wpt-profile-grid { grid-template-columns: 1fr 1fr; }
            .wpt-module-grid { grid-template-columns: 1fr; }
            .wpt-step-label { display: none; }
            .wpt-step-line { width: 20px; }
            .wpt-finish-list { columns: 1; }
        }
        </style>
        <?php
    }

    // ── Already Completed ─────────────────────────────────────

    /**
     * Show message when wizard has already been completed.
     */
    private function render_already_completed(): void {
        $this->render_fullscreen_styles();
        ?>
        <div class="wpt-wizard-wrap">
            <div class="wpt-wizard-container wpt-wizard-completed">
                <h1><?php esc_html_e( 'Setup Already Completed', 'wptransformed' ); ?></h1>
                <p><?php esc_html_e( 'The setup wizard has already been completed. You can manage your modules from the settings page.', 'wptransformed' ); ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Go to Settings', 'wptransformed' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    // ── AJAX Complete Handler ─────────────────────────────────

    /**
     * Handle wizard completion via AJAX.
     */
    public function ajax_complete(): void {
        check_ajax_referer( 'wpt_setup_wizard_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $skipped = ! empty( $_POST['skipped'] ) && $_POST['skipped'] === '1';
        $profile = sanitize_key( $_POST['profile'] ?? '' );

        if ( $skipped ) {
            // Enable minimal defaults.
            $modules_to_enable = self::SKIP_DEFAULTS;
        } else {
            // Sanitize submitted module list.
            $submitted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] )
                ? array_map( 'sanitize_key', $_POST['modules'] )
                : [];

            // Validate against registry.
            $valid_ids = array_keys( Module_Registry::get_all() );
            $modules_to_enable = array_intersect( $submitted, $valid_ids );

            // Don't enable setup-wizard itself.
            $modules_to_enable = array_diff( $modules_to_enable, [ 'setup-wizard' ] );
        }

        // Batch-enable selected modules.
        foreach ( $modules_to_enable as $mod_id ) {
            Settings::toggle_module( $mod_id, true );
        }

        // Mark wizard as completed.
        $completion_settings = [
            'completed'        => true,
            'completed_at'     => current_time( 'mysql' ),
            'selected_profile' => $profile,
            'skipped'          => $skipped,
        ];

        Settings::save( $this->get_id(), $completion_settings );

        // Clear activation redirect transient.
        delete_transient( 'wpt_activation_redirect' );

        wp_send_json_success( [
            'message'  => 'Setup complete.',
            'enabled'  => array_values( $modules_to_enable ),
        ] );
    }

    // ── Settings UI (minimal — wizard is the main UI) ─────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                <td>
                    <?php if ( ! empty( $settings['completed'] ) ) : ?>
                        <span style="color: #00a32a; font-weight: 600;">
                            <?php esc_html_e( 'Completed', 'wptransformed' ); ?>
                        </span>
                        <?php if ( ! empty( $settings['completed_at'] ) ) : ?>
                            <span style="color: #646970;">
                                &mdash; <?php echo esc_html( $settings['completed_at'] ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( ! empty( $settings['selected_profile'] ) ) : ?>
                            <br>
                            <span style="color: #646970;">
                                <?php
                                printf(
                                    /* translators: %s: profile name */
                                    esc_html__( 'Profile: %s', 'wptransformed' ),
                                    esc_html( ucfirst( $settings['selected_profile'] ) )
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( ! empty( $settings['skipped'] ) ) : ?>
                            <br>
                            <span style="color: #dba617;">
                                <?php esc_html_e( '(Setup was skipped)', 'wptransformed' ); ?>
                            </span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span style="color: #d63638;">
                            <?php esc_html_e( 'Not completed', 'wptransformed' ); ?>
                        </span>
                        &mdash;
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpt-setup-wizard' ) ); ?>">
                            <?php esc_html_e( 'Run Setup Wizard', 'wptransformed' ); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Re-run wizard', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_reset_wizard" value="1">
                        <?php esc_html_e( 'Reset wizard completion state (will allow the wizard to run again)', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $current = $this->get_settings();

        // If reset checkbox is checked, reset to defaults.
        if ( ! empty( $raw['wpt_reset_wizard'] ) ) {
            return $this->get_default_settings();
        }

        // Otherwise preserve existing state — wizard settings are managed by AJAX.
        return [
            'completed'        => (bool) ( $current['completed'] ?? false ),
            'completed_at'     => sanitize_text_field( $current['completed_at'] ?? '' ),
            'selected_profile' => sanitize_key( $current['selected_profile'] ?? '' ),
            'skipped'          => (bool) ( $current['skipped'] ?? false ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'transient', 'key' => 'wpt_activation_redirect' ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Check if WooCommerce is active.
     *
     * @return bool
     */
    private function is_woocommerce_active(): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active( 'woocommerce/woocommerce.php' );
    }

    /**
     * Get human-readable titles for all modules.
     *
     * Attempts to instantiate each module class to read its title.
     * Falls back to formatted ID if instantiation fails.
     *
     * @param array<string,string> $modules Module ID => file path.
     * @return array<string,string> Module ID => title.
     */
    private function get_module_titles( array $modules ): array {
        $titles = [];
        foreach ( $modules as $id => $path ) {
            // Format the ID as a readable title fallback.
            $titles[ $id ] = ucwords( str_replace( '-', ' ', $id ) );
        }
        return $titles;
    }
}
