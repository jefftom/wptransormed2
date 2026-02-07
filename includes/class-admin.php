<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Settings Page — The Glue.
 *
 * @package WPTransformed
 */
class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'wp_ajax_wpt_toggle_module', [ $this, 'ajax_toggle_module' ] );
    }

    /**
     * Register the settings page under Settings menu.
     */
    public function register_page(): void {
        $hook = add_options_page(
            __( 'WPTransformed', 'wptransformed' ),    // Page title
            __( 'WPTransformed', 'wptransformed' ),    // Menu title
            'manage_options',                           // Capability
            'wptransformed',                            // Slug
            [ $this, 'render_page' ]                    // Callback
        );

        // Enqueue assets only on our settings page
        add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
    }

    /**
     * Enqueue shared settings page CSS/JS.
     */
    public function enqueue_assets(): void {
        add_action( 'admin_enqueue_scripts', function() {
            wp_enqueue_style(
                'wpt-admin',
                WPT_URL . 'assets/admin/css/admin.css',
                [],
                WPT_VERSION
            );
            wp_enqueue_script(
                'wpt-admin',
                WPT_URL . 'assets/admin/js/admin.js',
                [],
                WPT_VERSION,
                true
            );
            wp_localize_script( 'wpt-admin', 'wptAdmin', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wpt_admin_nonce' ),
            ] );
        } );
    }

    /**
     * Render the main settings page.
     */
    public function render_page(): void {
        // Capability check (defense in depth — WP checks this for the menu too)
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'wptransformed' ) );
        }

        $core = Core::instance();
        $all_modules = $core->get_all_modules();

        // Group modules by category
        $categories = [];
        foreach ( $all_modules as $id => $module ) {
            $cat = $module->get_category();
            if ( ! isset( $categories[ $cat ] ) ) {
                $categories[ $cat ] = [];
            }
            $categories[ $cat ][ $id ] = $module;
        }

        // Category display names
        $category_labels = [
            'content-management' => __( 'Content Management', 'wptransformed' ),
            'admin-interface'    => __( 'Admin Interface', 'wptransformed' ),
            'performance'        => __( 'Performance', 'wptransformed' ),
            'utilities'          => __( 'Utilities', 'wptransformed' ),
            'security'           => __( 'Security', 'wptransformed' ),
            'media'              => __( 'Media', 'wptransformed' ),
        ];

        // Determine active tab
        $category_slugs = array_keys( $categories );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ( $category_slugs[0] ?? '' );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WPTransformed', 'wptransformed' ); ?></h1>

            <?php // Show any save notices ?>
            <?php if ( isset( $_GET['wpt_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'wptransformed' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $categories ) ) : ?>
                <?php // Category tabs ?>
                <nav class="nav-tab-wrapper">
                    <?php foreach ( $categories as $cat_slug => $cat_modules ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'tab', $cat_slug, admin_url( 'options-general.php?page=wptransformed' ) ) ); ?>"
                           class="nav-tab <?php echo $active_tab === $cat_slug ? 'nav-tab-active' : ''; ?>">
                            <?php echo esc_html( $category_labels[ $cat_slug ] ?? ucwords( str_replace( '-', ' ', $cat_slug ) ) ); ?>
                            <span class="wpt-module-count"><?php echo count( $cat_modules ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php // Module cards for active tab ?>
                <div class="wpt-modules-list">
                    <?php
                    $tab_modules = $categories[ $active_tab ] ?? [];
                    foreach ( $tab_modules as $id => $module ) :
                        $is_active = $core->is_active( $id );
                        $has_settings = ! empty( $module->get_default_settings() );
                    ?>
                        <div class="wpt-module-card <?php echo $is_active ? 'wpt-module-active' : ''; ?>"
                             data-module-id="<?php echo esc_attr( $id ); ?>">

                            <?php // Module header: title + toggle ?>
                            <div class="wpt-module-header">
                                <div class="wpt-module-info">
                                    <h3><?php echo esc_html( $module->get_title() ); ?></h3>
                                    <p><?php echo esc_html( $module->get_description() ); ?></p>
                                </div>
                                <label class="wpt-toggle">
                                    <input type="checkbox"
                                           class="wpt-module-toggle"
                                           data-module-id="<?php echo esc_attr( $id ); ?>"
                                           <?php checked( $is_active ); ?>>
                                    <span class="wpt-toggle-slider"></span>
                                </label>
                            </div>

                            <?php // Module settings (only shown when active AND has settings) ?>
                            <?php if ( $has_settings ) : ?>
                                <div class="wpt-module-settings" style="<?php echo $is_active ? '' : 'display:none;'; ?>">
                                    <form method="post" action="">
                                        <?php wp_nonce_field( 'wpt_save_' . $id, 'wpt_nonce' ); ?>
                                        <input type="hidden" name="wpt_module_id" value="<?php echo esc_attr( $id ); ?>">
                                        <input type="hidden" name="wpt_action" value="save_settings">

                                        <div class="wpt-settings-fields">
                                            <?php $module->render_settings(); ?>
                                        </div>

                                        <?php submit_button( __( 'Save Settings', 'wptransformed' ), 'primary', 'wpt_submit', true ); ?>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="wpt-modules-list">
                    <p><?php esc_html_e( 'No modules are currently available. Module files will be loaded as they are created.', 'wptransformed' ); ?></p>
                </div>
            <?php endif; ?>

            <?php // Safe mode info ?>
            <div class="wpt-safe-mode-info">
                <h4><?php esc_html_e( 'Emergency Safe Mode', 'wptransformed' ); ?></h4>
                <p><?php esc_html_e( 'If a module causes issues and you can\'t access the admin, use this URL to load WordPress with all WPTransformed modules disabled:', 'wptransformed' ); ?></p>
                <code><?php echo esc_url( Safe_Mode::get_safe_mode_url() ); ?></code>
                <p class="description"><?php esc_html_e( 'Bookmark this URL. Keep it secret — anyone with this link can access safe mode.', 'wptransformed' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Handle settings form submission.
     * This runs on admin_init — before any output.
     */
    public function handle_save(): void {
        // Only process our form submissions
        if ( ! isset( $_POST['wpt_action'] ) || $_POST['wpt_action'] !== 'save_settings' ) {
            return;
        }

        $module_id = isset( $_POST['wpt_module_id'] ) ? sanitize_key( $_POST['wpt_module_id'] ) : '';
        if ( empty( $module_id ) ) return;

        // Security: nonce + capability
        check_admin_referer( 'wpt_save_' . $module_id, 'wpt_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'wptransformed' ) );
        }

        // Get the module
        $module = Core::instance()->get_module( $module_id );
        if ( ! $module ) return;

        // Let the module sanitize its own settings
        $raw = $_POST;
        $clean = $module->sanitize_settings( $raw );

        // Save
        Settings::save( $module_id, $clean );

        // Redirect back with success notice (PRG pattern — prevents double-submit)
        wp_safe_redirect( add_query_arg( [
            'page'      => 'wptransformed',
            'tab'       => $module->get_category(),
            'wpt_saved' => '1',
        ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * AJAX handler for toggling a module on/off.
     * Called when the user clicks the toggle switch.
     */
    public function ajax_toggle_module(): void {
        // Security
        check_ajax_referer( 'wpt_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $module_id = isset( $_POST['module_id'] ) ? sanitize_key( $_POST['module_id'] ) : '';
        $active    = isset( $_POST['active'] ) && $_POST['active'] === '1';

        if ( empty( $module_id ) ) {
            wp_send_json_error( 'Missing module ID' );
        }

        // Verify the module exists in the registry
        $module = Core::instance()->get_module( $module_id );
        if ( ! $module ) {
            wp_send_json_error( 'Unknown module' );
        }

        // License check for pro modules (v1: always passes)
        if ( $module->get_tier() === 'pro' && ! Core::is_pro_licensed() ) {
            wp_send_json_error( 'Pro license required' );
        }

        // Toggle
        $result = Settings::toggle_module( $module_id, $active );

        if ( $result ) {
            // If deactivating, call the module's deactivate() method
            if ( ! $active ) {
                try {
                    $module->deactivate();
                } catch ( \Throwable $e ) {
                    // Log but don't fail the toggle
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
