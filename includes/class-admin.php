<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Settings Page — Dashboard + Module Settings.
 *
 * @package WPTransformed
 */
class Admin {

    /** Category → Font Awesome icon class */
    private const CATEGORY_ICONS = [
        'content-management' => 'fa-file-alt',
        'admin-interface'    => 'fa-sliders-h',
        'security'           => 'fa-shield-alt',
        'login-logout'       => 'fa-sign-in-alt',
        'performance'        => 'fa-rocket',
        'compliance'         => 'fa-balance-scale',
        'utilities'          => 'fa-wrench',
        'custom-code'        => 'fa-code',
        'disable-components' => 'fa-ban',
        'woocommerce'        => 'fa-shopping-cart',
    ];

    /** Category → color class (blue|green|rose|violet|amber) */
    private const CATEGORY_COLORS = [
        'content-management' => 'blue',
        'admin-interface'    => 'violet',
        'security'           => 'rose',
        'login-logout'       => 'violet',
        'performance'        => 'green',
        'compliance'         => 'blue',
        'utilities'          => 'amber',
        'custom-code'        => 'amber',
        'disable-components' => 'rose',
        'woocommerce'        => 'violet',
    ];

    /** Module ID → Font Awesome icon (overrides category default) */
    private const MODULE_ICONS = [
        'content-duplication'        => 'fa-copy',
        'admin-menu-editor'          => 'fa-bars',
        'hide-admin-notices'         => 'fa-bell-slash',
        'svg-upload'                 => 'fa-bezier-curve',
        'clean-admin-bar'            => 'fa-minus-circle',
        'dark-mode'                  => 'fa-moon',
        'database-cleanup'           => 'fa-database',
        'heartbeat-control'          => 'fa-heartbeat',
        'disable-comments'           => 'fa-comment-slash',
        'email-smtp'                 => 'fa-envelope',
        'audit-log'                  => 'fa-clipboard-list',
        'limit-login-attempts'       => 'fa-lock',
        'two-factor-auth'            => 'fa-key',
        'disable-xmlrpc'             => 'fa-plug',
        'revision-control'           => 'fa-history',
        'lazy-load'                  => 'fa-spinner',
        'minify-assets'              => 'fa-compress',
        'redirect-manager'           => 'fa-directions',
        'maintenance-mode'           => 'fa-hard-hat',
        'email-log'                  => 'fa-inbox',
        'cron-manager'               => 'fa-clock',
        'search-replace'             => 'fa-exchange-alt',
        'export-import-settings'     => 'fa-file-export',
        'broken-link-checker'        => 'fa-unlink',
        'media-replace'              => 'fa-sync-alt',
        'media-folders'              => 'fa-folder',
        'image-upload-control'       => 'fa-image',
        'code-snippets'              => 'fa-terminal',
        'custom-admin-css'           => 'fa-paint-brush',
        'cookie-consent'             => 'fa-cookie-bite',
        'login-customizer'           => 'fa-fingerprint',
        'change-login-url'           => 'fa-link',
        'white-label'                => 'fa-tag',
        'command-palette'            => 'fa-search',
        'keyboard-shortcuts'         => 'fa-keyboard',
        'environment-indicator'      => 'fa-flag',
        'content-calendar'           => 'fa-calendar-alt',
        'content-order'              => 'fa-sort',
        'disable-emojis'             => 'fa-smile',
        'disable-embeds'             => 'fa-film',
        'disable-feeds'              => 'fa-rss',
        'disable-rest-api'           => 'fa-globe',
        'disable-gutenberg'          => 'fa-edit',
        'user-role-editor'           => 'fa-users-cog',
        'password-protection'        => 'fa-user-lock',
        'session-manager'            => 'fa-user-clock',
        'file-manager'               => 'fa-folder-open',
        'redirect-404'               => 'fa-exclamation-triangle',
        '404-monitor'                => 'fa-bug',
        'system-summary'             => 'fa-server',
        'form-builder'               => 'fa-wpforms',
        'webhook-manager'            => 'fa-paper-plane',
        'workflow-automation'         => 'fa-project-diagram',
        'robots-txt-manager'         => 'fa-robot',
        'ads-txt-manager'            => 'fa-ad',
        'hide-admin-bar'             => 'fa-eye-slash',
        'hide-dashboard-widgets'     => 'fa-th-large',
        'custom-admin-footer'        => 'fa-shoe-prints',
        'wider-admin-menu'           => 'fa-arrows-alt-h',
        'activity-feed'              => 'fa-stream',
        'notification-center'        => 'fa-bell',
        'view-as-role'               => 'fa-user-secret',
        'admin-bookmarks'            => 'fa-bookmark',
        'client-dashboard'           => 'fa-tachometer-alt',
        'setup-wizard'               => 'fa-magic',
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'wp_ajax_wpt_toggle_module', [ $this, 'ajax_toggle_module' ] );

        // Global admin reskin hooks (all admin pages)
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_global_assets' ] );
        add_action( 'admin_menu', [ $this, 'inject_section_labels' ], 999 );
        add_filter( 'admin_body_class', [ $this, 'add_body_classes' ] );
        add_action( 'wp_ajax_wpt_save_dark_mode', [ $this, 'ajax_save_dark_mode' ] );
    }

    public function register_page(): void {
        $hook = add_menu_page(
            __( 'WPTransformed', 'wptransformed' ),
            __( 'WPTransformed', 'wptransformed' ),
            'manage_options',
            'wptransformed',
            [ $this, 'render_page' ],
            'dashicons-admin-generic'
        );

        add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        add_action( 'admin_enqueue_scripts', function() {
            // Dashboard CSS (depends on global CSS which handles fonts + FA)
            wp_enqueue_style(
                'wpt-admin',
                WPT_URL . 'assets/admin/css/admin.css',
                [ 'wpt-admin-global' ],
                WPT_VERSION
            );

            // Dashboard JS
            wp_enqueue_script(
                'wpt-admin',
                WPT_URL . 'assets/admin/js/admin.js',
                [ 'wpt-admin-global' ],
                WPT_VERSION,
                true
            );

            // Build module data for JS (command palette + filtering)
            $core        = Core::instance();
            $all_modules = $core->get_all_modules();
            $js_modules  = [];

            foreach ( $all_modules as $id => $module ) {
                $cat = $module->get_category();
                $js_modules[] = [
                    'id'          => $id,
                    'title'       => $module->get_title(),
                    'desc'        => $module->get_description(),
                    'category'    => $cat,
                    'active'      => $core->is_active( $id ),
                    'icon'        => self::get_module_icon( $id, $cat ),
                    'color'       => self::get_category_color( $cat ),
                    'settingsUrl' => admin_url( 'admin.php?page=wptransformed&module=' . $id ),
                ];
            }

            wp_localize_script( 'wpt-admin', 'wptAdmin', [
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'adminUrl' => admin_url(),
                'pageUrl'  => admin_url( 'admin.php?page=wptransformed' ),
                'nonce'    => wp_create_nonce( 'wpt_admin_nonce' ),
                'modules'  => $js_modules,
            ] );
        } );
    }

    /**
     * Render the main page — either dashboard or module settings.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'wptransformed' ) );
        }

        // Route: module settings vs dashboard
        $module_slug = isset( $_GET['module'] ) ? sanitize_key( $_GET['module'] ) : '';

        if ( $module_slug ) {
            $this->render_module_settings( $module_slug );
        } else {
            $this->render_dashboard();
        }
    }

    /* ══════════════════════════════════════════
       DASHBOARD VIEW
    ══════════════════════════════════════════ */
    private function render_dashboard(): void {
        $core         = Core::instance();
        $all_modules  = $core->get_all_modules();
        $total        = count( $all_modules );
        $active_count = 0;

        $categories = [
            'content-management'  => __( 'Content', 'wptransformed' ),
            'admin-interface'     => __( 'Admin UI', 'wptransformed' ),
            'security'            => __( 'Security', 'wptransformed' ),
            'login-logout'        => __( 'Login', 'wptransformed' ),
            'performance'         => __( 'Performance', 'wptransformed' ),
            'compliance'          => __( 'Compliance', 'wptransformed' ),
            'utilities'           => __( 'Utilities', 'wptransformed' ),
            'custom-code'         => __( 'Code', 'wptransformed' ),
            'disable-components'  => __( 'Disable', 'wptransformed' ),
            'woocommerce'         => __( 'WooCommerce', 'wptransformed' ),
        ];

        foreach ( $all_modules as $id => $module ) {
            if ( $core->is_active( $id ) ) {
                $active_count++;
            }
        }

        $active_pct  = $total > 0 ? round( ( $active_count / $total ) * 100 ) : 0;
        $ring_offset = $total > 0 ? 125.7 - ( 125.7 * $active_count / $total ) : 125.7;
        $user        = wp_get_current_user();
        $greeting    = $this->get_greeting();

        ?>
        <div class="wpt-dashboard" id="wptDashboard">

            <?php $this->render_topbar(); ?>

            <?php if ( isset( $_GET['wpt_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'wptransformed' ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Welcome Banner -->
            <div class="wpt-welcome">
                <h2><?php echo esc_html( $greeting . ', ' . $user->display_name ); ?></h2>
                <p><?php
                    printf(
                        esc_html__( '%1$d of %2$d modules active. Enable only what you need — one plugin to replace them all.', 'wptransformed' ),
                        $active_count,
                        $total
                    );
                ?></p>
                <div class="wpt-banner-stats">
                    <div class="wpt-banner-stat">
                        <div class="wpt-ring-wrap">
                            <svg viewBox="0 0 50 50">
                                <circle class="track" cx="25" cy="25" r="20" />
                                <circle class="fill" cx="25" cy="25" r="20" stroke="#fff" stroke-dasharray="125.7" stroke-dashoffset="<?php echo esc_attr( (string) $ring_offset ); ?>" />
                            </svg>
                            <span class="wpt-ring-value"><?php echo esc_html( $active_pct . '%' ); ?></span>
                        </div>
                        <div class="wpt-stat-text">
                            <span><?php esc_html_e( 'Modules', 'wptransformed' ); ?></span>
                            <small><?php echo esc_html( $active_count . ' of ' . $total . ' active' ); ?></small>
                        </div>
                    </div>
                    <div class="wpt-banner-stat">
                        <div class="wpt-ring-wrap">
                            <svg viewBox="0 0 50 50">
                                <circle class="track" cx="25" cy="25" r="20" />
                                <circle class="fill" cx="25" cy="25" r="20" stroke="#06d6a0" stroke-dasharray="125.7" stroke-dashoffset="0" />
                            </svg>
                            <span class="wpt-ring-value">A+</span>
                        </div>
                        <div class="wpt-stat-text">
                            <span><?php esc_html_e( 'Health', 'wptransformed' ); ?></span>
                            <small><?php esc_html_e( 'All systems go', 'wptransformed' ); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bento Stats -->
            <div class="wpt-bento-grid">
                <div class="wpt-bento-card">
                    <div class="bento-icon blue"><i class="fas fa-puzzle-piece"></i></div>
                    <div class="bento-value" id="wptActiveCount" data-count="<?php echo esc_attr( (string) $active_count ); ?>">0</div>
                    <div class="bento-label"><?php esc_html_e( 'Active Modules', 'wptransformed' ); ?></div>
                    <div class="bento-change neutral"><i class="fas fa-cubes"></i> <?php echo esc_html( $total . ' total' ); ?></div>
                </div>
                <div class="wpt-bento-card">
                    <div class="bento-icon green"><i class="fas fa-layer-group"></i></div>
                    <div class="bento-value" data-count="<?php echo esc_attr( (string) count( $categories ) ); ?>">0</div>
                    <div class="bento-label"><?php esc_html_e( 'Categories', 'wptransformed' ); ?></div>
                    <div class="bento-change up"><i class="fas fa-check"></i> <?php esc_html_e( 'Organized', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-bento-card">
                    <div class="bento-icon amber"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="bento-value" data-count="<?php echo esc_attr( (string) $active_pct ); ?>" data-suffix="%">0</div>
                    <div class="bento-label"><?php esc_html_e( 'Utilization', 'wptransformed' ); ?></div>
                    <div class="bento-change neutral"><i class="fas fa-chart-line"></i> <?php esc_html_e( 'Capacity', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-bento-card">
                    <div class="bento-icon rose"><i class="fas fa-shield-alt"></i></div>
                    <div class="bento-value" data-count="<?php echo esc_attr( (string) $this->count_active_in_category( 'security' ) ); ?>">0</div>
                    <div class="bento-label"><?php esc_html_e( 'Security Modules', 'wptransformed' ); ?></div>
                    <div class="bento-change up"><i class="fas fa-lock"></i> <?php esc_html_e( 'Protected', 'wptransformed' ); ?></div>
                </div>
            </div>

            <!-- Module Grid Section -->
            <div class="wpt-section-header">
                <h2><?php esc_html_e( 'Modules', 'wptransformed' ); ?></h2>
                <div class="wpt-header-controls">
                    <div class="wpt-pill-tabs">
                        <button class="wpt-pill-tab active" data-category="all"><?php esc_html_e( 'All', 'wptransformed' ); ?></button>
                        <?php foreach ( $categories as $slug => $label ) : ?>
                            <button class="wpt-pill-tab" data-category="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="wpt-module-grid" id="wptModGrid">
                <?php
                $i = 0;
                foreach ( $all_modules as $id => $module ) :
                    $is_active  = $core->is_active( $id );
                    $cat        = $module->get_category();
                    $color      = self::get_category_color( $cat );
                    $icon       = self::get_module_icon( $id, $cat );
                    $cat_label  = $categories[ $cat ] ?? ucfirst( $cat );
                    $settings_url = admin_url( 'admin.php?page=wptransformed&module=' . $id );
                ?>
                    <div class="wpt-mod-card <?php echo $is_active ? '' : 'disabled'; ?>"
                         data-module-id="<?php echo esc_attr( $id ); ?>"
                         data-category="<?php echo esc_attr( $cat ); ?>"
                         data-settings-url="<?php echo esc_url( $settings_url ); ?>"
                         style="animation-delay: <?php echo esc_attr( ( $i * 0.025 ) . 's' ); ?>">

                        <div class="wpt-tip"><?php echo esc_html( $module->get_description() ); ?></div>

                        <div class="wpt-mod-main">
                            <div class="wpt-mod-top">
                                <div class="mod-icon <?php echo esc_attr( $color ); ?>">
                                    <i class="fas <?php echo esc_attr( $icon ); ?>"></i>
                                </div>
                                <label class="wpt-toggle">
                                    <input type="checkbox"
                                           class="wpt-module-toggle"
                                           data-module-id="<?php echo esc_attr( $id ); ?>"
                                           <?php checked( $is_active ); ?>>
                                    <span class="wpt-toggle-track"></span>
                                </label>
                            </div>
                            <div class="wpt-mod-name"><?php echo esc_html( $module->get_title() ); ?></div>
                            <div class="wpt-mod-desc"><?php echo esc_html( $module->get_description() ); ?></div>
                            <div class="wpt-mod-footer">
                                <span class="wpt-mod-meta">
                                    <i class="fas <?php echo esc_attr( self::CATEGORY_ICONS[ $cat ] ?? 'fa-puzzle-piece' ); ?>"></i>
                                    <?php echo esc_html( $cat_label ); ?>
                                </span>
                                <div class="wpt-mod-badges">
                                    <?php if ( $is_active ) : ?>
                                        <span class="wpt-badge wpt-badge-active"><?php esc_html_e( 'Active', 'wptransformed' ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
                    $i++;
                endforeach;
                ?>
            </div>

            <!-- Bottom Panels -->
            <div class="wpt-bottom-grid">
                <div class="wpt-panel">
                    <div class="wpt-panel-head">
                        <div class="ph-icon"><i class="fas fa-server"></i></div>
                        <h3><?php esc_html_e( 'System Status', 'wptransformed' ); ?></h3>
                    </div>
                    <div class="wpt-sys-grid">
                        <div class="wpt-sys-item">
                            <div class="wpt-sys-label"><?php esc_html_e( 'WordPress', 'wptransformed' ); ?></div>
                            <div class="wpt-sys-val"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></div>
                        </div>
                        <div class="wpt-sys-item">
                            <div class="wpt-sys-label"><?php esc_html_e( 'PHP', 'wptransformed' ); ?></div>
                            <div class="wpt-sys-val"><?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION ); ?></div>
                        </div>
                        <div class="wpt-sys-item">
                            <div class="wpt-sys-label"><?php esc_html_e( 'Plugin', 'wptransformed' ); ?></div>
                            <div class="wpt-sys-val"><?php echo esc_html( 'v' . WPT_VERSION ); ?></div>
                        </div>
                        <div class="wpt-sys-item">
                            <div class="wpt-sys-label"><?php esc_html_e( 'Modules', 'wptransformed' ); ?></div>
                            <div class="wpt-sys-val"><?php echo esc_html( $active_count . '/' . $total ); ?></div>
                        </div>
                        <div class="wpt-sys-item">
                            <div class="wpt-sys-label"><?php esc_html_e( 'Memory Limit', 'wptransformed' ); ?></div>
                            <div class="wpt-sys-val"><?php echo esc_html( WP_MEMORY_LIMIT ); ?></div>
                        </div>
                        <div class="wpt-sys-item">
                            <div class="wpt-sys-label"><?php esc_html_e( 'SSL', 'wptransformed' ); ?></div>
                            <div class="wpt-sys-val" <?php echo is_ssl() ? 'style="color:var(--accent);"' : ''; ?>>
                                <?php echo is_ssl() ? esc_html__( 'Active', 'wptransformed' ) : esc_html__( 'Inactive', 'wptransformed' ); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="wpt-panel">
                    <div class="wpt-panel-head">
                        <div class="ph-icon"><i class="fas fa-bolt"></i></div>
                        <h3><?php esc_html_e( 'Quick Actions', 'wptransformed' ); ?></h3>
                    </div>
                    <a class="wpt-qa-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed&module=export-import-settings' ) ); ?>">
                        <div class="qa-icon"><i class="fas fa-download"></i></div>
                        <div class="qa-text">
                            <h4><?php esc_html_e( 'Export Settings', 'wptransformed' ); ?></h4>
                            <p><?php esc_html_e( 'Download full configuration', 'wptransformed' ); ?></p>
                        </div>
                        <span class="qa-arrow"><i class="fas fa-chevron-right"></i></span>
                    </a>
                    <a class="wpt-qa-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed&module=database-cleanup' ) ); ?>">
                        <div class="qa-icon"><i class="fas fa-database"></i></div>
                        <div class="qa-text">
                            <h4><?php esc_html_e( 'Database Cleanup', 'wptransformed' ); ?></h4>
                            <p><?php esc_html_e( 'Optimize tables and remove bloat', 'wptransformed' ); ?></p>
                        </div>
                        <span class="qa-arrow"><i class="fas fa-chevron-right"></i></span>
                    </a>
                    <a class="wpt-qa-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed&module=system-summary' ) ); ?>">
                        <div class="qa-icon"><i class="fas fa-server"></i></div>
                        <div class="qa-text">
                            <h4><?php esc_html_e( 'System Summary', 'wptransformed' ); ?></h4>
                            <p><?php esc_html_e( 'Full environment details', 'wptransformed' ); ?></p>
                        </div>
                        <span class="qa-arrow"><i class="fas fa-chevron-right"></i></span>
                    </a>
                    <a class="wpt-qa-item" href="https://wptransformed.com/docs/" target="_blank" rel="noopener">
                        <div class="qa-icon"><i class="fas fa-life-ring"></i></div>
                        <div class="qa-text">
                            <h4><?php esc_html_e( 'Support & Docs', 'wptransformed' ); ?></h4>
                            <p><?php esc_html_e( 'Knowledge base & guides', 'wptransformed' ); ?></p>
                        </div>
                        <span class="qa-arrow"><i class="fas fa-chevron-right"></i></span>
                    </a>
                </div>
            </div>

            <!-- Safe Mode -->
            <div class="wpt-safe-mode">
                <h4><?php esc_html_e( 'Emergency Safe Mode', 'wptransformed' ); ?></h4>
                <p><?php esc_html_e( 'If a module causes issues, load this URL to disable all modules:', 'wptransformed' ); ?></p>
                <code><?php echo esc_url( Safe_Mode::get_safe_mode_url() ); ?></code>
            </div>

            <?php $this->render_command_palette(); ?>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════
       MODULE SETTINGS VIEW
    ══════════════════════════════════════════ */
    private function render_module_settings( string $module_slug ): void {
        $core   = Core::instance();
        $module = $core->get_module( $module_slug );

        if ( ! $module ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wptransformed' ) );
            exit;
        }

        $is_active    = $core->is_active( $module_slug );
        $has_settings = ! empty( $module->get_default_settings() );
        $cat          = $module->get_category();
        $color        = self::get_category_color( $cat );
        $icon         = self::get_module_icon( $module_slug, $cat );

        ?>
        <div class="wpt-dashboard" id="wptDashboard">

            <?php $this->render_topbar( $module->get_title() ); ?>

            <?php if ( isset( $_GET['wpt_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'wptransformed' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="wpt-settings-page">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>" class="wpt-back-link">
                    <i class="fas fa-arrow-left"></i>
                    <?php esc_html_e( 'Back to Dashboard', 'wptransformed' ); ?>
                </a>

                <div class="wpt-settings-header">
                    <div class="mod-icon <?php echo esc_attr( $color ); ?>">
                        <i class="fas <?php echo esc_attr( $icon ); ?>"></i>
                    </div>
                    <div class="wpt-settings-header-text">
                        <h2><?php echo esc_html( $module->get_title() ); ?></h2>
                        <p><?php echo esc_html( $module->get_description() ); ?></p>
                    </div>
                    <label class="wpt-toggle" style="margin-left: auto;">
                        <input type="checkbox"
                               class="wpt-module-toggle"
                               data-module-id="<?php echo esc_attr( $module_slug ); ?>"
                               <?php checked( $is_active ); ?>>
                        <span class="wpt-toggle-track"></span>
                    </label>
                </div>

                <?php if ( $has_settings ) : ?>
                    <div class="wpt-settings-form">
                        <form method="post" action="">
                            <?php wp_nonce_field( 'wpt_save_' . $module_slug, 'wpt_nonce' ); ?>
                            <input type="hidden" name="wpt_module_id" value="<?php echo esc_attr( $module_slug ); ?>">
                            <input type="hidden" name="wpt_action" value="save_settings">
                            <?php $module->render_settings(); ?>
                            <?php submit_button( __( 'Save Settings', 'wptransformed' ), 'primary', 'wpt_submit', true ); ?>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="wpt-settings-form">
                        <p style="color: var(--text-muted); font-size: 15px;">
                            <?php esc_html_e( 'This module has no configurable settings. Just toggle it on or off.', 'wptransformed' ); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <?php $this->render_command_palette(); ?>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════
       SHARED COMPONENTS
    ══════════════════════════════════════════ */

    private function render_topbar( string $crumb = '' ): void {
        ?>
        <div class="wpt-topbar">
            <div class="wpt-topbar-left">
                <span class="wpt-topbar-title"><?php esc_html_e( 'WPTransformed', 'wptransformed' ); ?></span>
                <?php if ( $crumb ) : ?>
                    <span class="wpt-topbar-sep"></span>
                    <span class="wpt-topbar-crumb"><?php echo esc_html( $crumb ); ?></span>
                <?php endif; ?>
            </div>
            <div class="wpt-topbar-right">
                <button class="wpt-tb-btn wpt-tb-search" id="wptSearchTrigger">
                    <i class="fas fa-search"></i>
                    <span><?php esc_html_e( 'Search', 'wptransformed' ); ?></span>
                    <kbd>Ctrl+K</kbd>
                </button>
                <button class="wpt-tb-btn" id="wptThemeToggle" title="<?php esc_attr_e( 'Toggle dark mode', 'wptransformed' ); ?>">
                    <i class="fas fa-moon" id="wptThemeIcon"></i>
                </button>
            </div>
        </div>
        <?php
    }

    private function render_command_palette(): void {
        ?>
        <div class="wpt-cmd-overlay" id="wptCmdOverlay">
            <div class="wpt-cmd-box">
                <div class="wpt-cmd-input-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text"
                           class="wpt-cmd-input"
                           id="wptCmdInput"
                           placeholder="<?php esc_attr_e( 'Search modules, settings, actions...', 'wptransformed' ); ?>"
                           autocomplete="off">
                    <div class="wpt-cmd-shortcut"><kbd>esc</kbd></div>
                </div>
                <div class="wpt-cmd-results" id="wptCmdResults"></div>
                <div class="wpt-cmd-footer">
                    <div class="wpt-cmd-footer-left">
                        <div class="wpt-cmd-footer-item"><kbd>&uarr;</kbd><kbd>&darr;</kbd> <?php esc_html_e( 'Navigate', 'wptransformed' ); ?></div>
                        <div class="wpt-cmd-footer-item"><kbd>&crarr;</kbd> <?php esc_html_e( 'Select', 'wptransformed' ); ?></div>
                        <div class="wpt-cmd-footer-item"><kbd>esc</kbd> <?php esc_html_e( 'Close', 'wptransformed' ); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ══════════════════════════════════════════
       GLOBAL ADMIN RESKIN
    ══════════════════════════════════════════ */

    /**
     * Enqueue Google Fonts, Font Awesome, admin-global.css, and admin-global.js
     * on ALL admin pages (not just the WPT dashboard).
     */
    public function enqueue_global_assets(): void {
        // Google Fonts: Outfit + JetBrains Mono
        wp_enqueue_style(
            'wpt-google-fonts',
            'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap',
            [],
            null
        );

        // Font Awesome 6
        wp_enqueue_style(
            'wpt-font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            [],
            '6.4.0'
        );

        // Global admin reskin CSS
        wp_enqueue_style(
            'wpt-admin-global',
            WPT_URL . 'assets/admin/css/admin-global.css',
            [ 'wpt-google-fonts', 'wpt-font-awesome' ],
            WPT_VERSION
        );

        // Global admin reskin JS
        wp_enqueue_script(
            'wpt-admin-global',
            WPT_URL . 'assets/admin/js/admin-global.js',
            [],
            WPT_VERSION,
            true
        );

        // Localize data for the global JS
        $user     = wp_get_current_user();
        $initials = $this->get_user_initials( $user );
        $roles    = $user->roles;

        wp_localize_script( 'wpt-admin-global', 'wptGlobal', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'wpt_global_nonce' ),
            'version'      => sanitize_text_field( WPT_VERSION ),
            'userName'     => sanitize_text_field( $user->display_name ),
            'userInitials' => sanitize_text_field( $initials ),
            'userRole'     => sanitize_text_field( ! empty( $roles ) ? ucfirst( $roles[0] ) : __( 'User', 'wptransformed' ) ),
            'profileUrl'   => esc_url( admin_url( 'profile.php' ) ),
            'pageTitle'    => sanitize_text_field( $this->get_current_page_title() ),
            'pageCrumb'    => sanitize_text_field( $this->get_current_page_crumb() ),
            'darkMode'     => sanitize_text_field( get_user_meta( $user->ID, 'wpt_dark_mode', true ) ),
        ] );
    }

    /**
     * Inject section label separators into the admin menu.
     * Uses priority 999 so all menu items are already registered.
     */
    public function inject_section_labels(): void {
        global $menu;

        // Remove default WordPress separators (replaced by section labels)
        unset( $menu[4] );   // separator1 (between Dashboard and Posts)
        unset( $menu[99] );  // separator-last

        // Move Plugins (65) and Users (70) into the CONFIGURE range
        // so they appear after Settings (80) instead of between DESIGN and TOOLS.
        if ( isset( $menu[65] ) ) {
            $menu[81] = $menu[65];
            unset( $menu[65] );
        }
        if ( isset( $menu[70] ) ) {
            $menu[82] = $menu[70];
            unset( $menu[70] );
        }

        // Inject section label separators.
        // Positions chosen so core WP items fall into correct groups:
        //   CONTENT:   Dashboard(2), Posts(5), Media(10), Pages(20), Comments(25)
        //   SECURITY:  WPT security pages (26-58)
        //   DESIGN:    Appearance(60), detected page builders
        //   TOOLS:     Tools(75)
        //   CONFIGURE: Settings(80), Plugins(81), Users(82), WPTransformed
        $labels = [
            1  => 'content',
            41 => 'security',
            59 => 'design',
            74 => 'tools',
            79 => 'configure',
        ];

        foreach ( $labels as $position => $label ) {
            $menu[ $position ] = [
                '',                                          // [0] menu title
                'read',                                      // [1] capability
                'wpt-sep-' . $label,                         // [2] slug
                '',                                          // [3] page title
                'wp-menu-separator wpt-section-sep',         // [4] CSS classes
                'wpt-sep-' . $label,                         // [5] ID
                '',                                          // [6] icon
            ];
        }

        ksort( $menu );
    }

    /**
     * Add body classes for global styling scope + dark mode.
     */
    public function add_body_classes( string $classes ): string {
        $classes .= ' wpt-admin';

        $user_id = get_current_user_id();
        if ( get_user_meta( $user_id, 'wpt_dark_mode', true ) === '1' ) {
            $classes .= ' wpt-dark';
        }

        return $classes;
    }

    /**
     * AJAX: persist dark mode preference to user meta.
     */
    public function ajax_save_dark_mode(): void {
        check_ajax_referer( 'wpt_global_nonce', 'nonce' );

        $dark = isset( $_POST['dark_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['dark_mode'] ) ) : '0';
        update_user_meta( get_current_user_id(), 'wpt_dark_mode', $dark === '1' ? '1' : '0' );

        wp_send_json_success();
    }

    /**
     * Get user initials for avatar display.
     */
    private function get_user_initials( \WP_User $user ): string {
        $name = $user->display_name;
        if ( empty( $name ) ) {
            return 'U';
        }

        $parts = preg_split( '/\s+/', trim( $name ) );
        if ( count( $parts ) >= 2 ) {
            return strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( end( $parts ), 0, 1 ) );
        }

        return strtoupper( mb_substr( $name, 0, 1 ) );
    }

    /**
     * Derive page title for topbar from current admin screen.
     */
    private function get_current_page_title(): string {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return __( 'Dashboard', 'wptransformed' );
        }

        // Map common screen IDs to friendly titles
        $map = [
            'dashboard'             => __( 'Dashboard', 'wptransformed' ),
            'edit-post'             => __( 'Posts', 'wptransformed' ),
            'edit-page'             => __( 'Pages', 'wptransformed' ),
            'upload'                => __( 'Media', 'wptransformed' ),
            'edit-comments'         => __( 'Comments', 'wptransformed' ),
            'themes'                => __( 'Appearance', 'wptransformed' ),
            'plugins'               => __( 'Plugins', 'wptransformed' ),
            'users'                 => __( 'Users', 'wptransformed' ),
            'tools_page_wptransformed' => __( 'WPTransformed', 'wptransformed' ),
            'toplevel_page_wptransformed' => __( 'WPTransformed', 'wptransformed' ),
            'options-general'       => __( 'Settings', 'wptransformed' ),
            'profile'               => __( 'Profile', 'wptransformed' ),
        ];

        return $map[ $screen->id ] ?? ( $screen->parent_base ? ucfirst( str_replace( '-', ' ', $screen->parent_base ) ) : __( 'Dashboard', 'wptransformed' ) );
    }

    /**
     * Derive breadcrumb for topbar from current admin screen.
     */
    private function get_current_page_crumb(): string {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return __( 'Overview', 'wptransformed' );
        }

        if ( $screen->action === 'add' ) {
            return __( 'Add New', 'wptransformed' );
        }

        if ( $screen->base === 'post' && $screen->action !== 'add' ) {
            return __( 'Edit', 'wptransformed' );
        }

        return __( 'Overview', 'wptransformed' );
    }

    /* ══════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════ */

    private static function get_module_icon( string $id, string $category ): string {
        return self::MODULE_ICONS[ $id ] ?? self::CATEGORY_ICONS[ $category ] ?? 'fa-puzzle-piece';
    }

    private static function get_category_color( string $category ): string {
        return self::CATEGORY_COLORS[ $category ] ?? 'blue';
    }

    private function get_greeting(): string {
        $hour = (int) current_time( 'G' );
        if ( $hour < 12 ) return __( 'Good morning', 'wptransformed' );
        if ( $hour < 17 ) return __( 'Good afternoon', 'wptransformed' );
        return __( 'Good evening', 'wptransformed' );
    }

    private function count_active_in_category( string $category ): int {
        $core    = Core::instance();
        $modules = $core->get_all_modules();
        $count   = 0;

        foreach ( $modules as $id => $module ) {
            if ( $module->get_category() === $category && $core->is_active( $id ) ) {
                $count++;
            }
        }

        return $count;
    }

    /* ══════════════════════════════════════════
       FORM SAVE + AJAX (unchanged logic)
    ══════════════════════════════════════════ */

    public function handle_save(): void {
        if ( ! isset( $_POST['wpt_action'] ) || $_POST['wpt_action'] !== 'save_settings' ) {
            return;
        }

        $module_id = isset( $_POST['wpt_module_id'] ) ? sanitize_key( $_POST['wpt_module_id'] ) : '';
        if ( empty( $module_id ) ) return;

        check_admin_referer( 'wpt_save_' . $module_id, 'wpt_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'wptransformed' ) );
        }

        $module = Core::instance()->get_module( $module_id );
        if ( ! $module ) return;

        $raw   = $_POST;
        $clean = $module->sanitize_settings( $raw );
        Settings::save( $module_id, $clean );

        wp_safe_redirect( add_query_arg( [
            'page'      => 'wptransformed',
            'module'    => $module_id,
            'wpt_saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function ajax_toggle_module(): void {
        check_ajax_referer( 'wpt_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $module_id = isset( $_POST['module_id'] ) ? sanitize_key( $_POST['module_id'] ) : '';
        $active    = isset( $_POST['active'] ) && $_POST['active'] === '1';

        if ( empty( $module_id ) ) {
            wp_send_json_error( 'Missing module ID' );
        }

        $module = Core::instance()->get_module( $module_id );
        if ( ! $module ) {
            wp_send_json_error( 'Unknown module' );
        }

        if ( $module->get_tier() === 'pro' && ! Core::is_pro_licensed() ) {
            wp_send_json_error( 'Pro license required' );
        }

        $result = Settings::toggle_module( $module_id, $active );

        if ( $result ) {
            if ( ! $active ) {
                try {
                    $module->deactivate();
                } catch ( \Throwable $e ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( "WPTransformed: Module '{$module_id}' deactivate() failed: " . $e->getMessage() );
                    }
                }
            }
            wp_send_json_success( [ 'active' => $active ] );
        } else {
            wp_send_json_error( 'Failed to update' );
        }
    }
}
