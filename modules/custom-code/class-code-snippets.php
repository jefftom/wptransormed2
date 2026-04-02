<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Code Snippets -- Add custom PHP, CSS, JS, and HTML snippets to your site.
 *
 * Features:
 *  - Execute PHP snippets with error recovery (auto-deactivates on fatal)
 *  - Inject CSS snippets into wp_head / admin_head
 *  - Inject JS snippets into wp_footer / admin_footer
 *  - Inject HTML snippets into wp_head or wp_footer
 *  - CodeMirror-powered editor (bundled with WordPress)
 *  - Scope control: everywhere, admin-only, or frontend-only
 *  - Priority ordering for execution
 *  - AJAX-powered CRUD operations
 *  - PHP snippets restricted to manage_options capability
 *
 * @package WPTransformed
 */
class Code_Snippets extends Module_Base {

    /**
     * DB version transient key.
     */
    private const DB_VERSION_KEY = 'wpt_code_snippets_db_version';

    /**
     * Current DB schema version.
     */
    private const DB_VERSION = '1.0';

    /**
     * Allowed snippet types.
     */
    private const ALLOWED_TYPES = [ 'php', 'css', 'js', 'html' ];

    /**
     * Allowed snippet scopes.
     */
    private const ALLOWED_SCOPES = [ 'everywhere', 'admin', 'frontend' ];

    /**
     * Transient key prefix for deactivated snippets (error recovery).
     */
    private const DEACTIVATED_TRANSIENT = 'wpt_snippet_deactivated';

    // -- Identity --

    public function get_id(): string {
        return 'code-snippets';
    }

    public function get_title(): string {
        return __( 'Code Snippets', 'wptransformed' );
    }

    public function get_category(): string {
        return 'custom-code';
    }

    public function get_description(): string {
        return __( 'Add custom PHP, CSS, JS, and HTML code snippets with error recovery and scoped execution.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'enable_php'            => true,
            'enable_error_recovery' => true,
            'codemirror_theme'      => 'default',
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        $this->maybe_create_table();

        // Show admin notice if a snippet was auto-deactivated.
        add_action( 'admin_notices', [ $this, 'show_deactivation_notice' ] );

        // Execute PHP snippets on init (priority 99 to run after most plugins).
        add_action( 'init', [ $this, 'execute_php_snippets' ], 99 );

        // CSS snippets.
        add_action( 'wp_head',    [ $this, 'output_frontend_css' ] );
        add_action( 'admin_head', [ $this, 'output_admin_css' ] );

        // JS snippets.
        add_action( 'wp_footer',    [ $this, 'output_frontend_js' ] );
        add_action( 'admin_footer', [ $this, 'output_admin_js' ] );

        // HTML snippets.
        add_action( 'wp_head',      [ $this, 'output_frontend_html_head' ] );
        add_action( 'wp_footer',    [ $this, 'output_frontend_html_footer' ] );
        add_action( 'admin_head',   [ $this, 'output_admin_html_head' ] );
        add_action( 'admin_footer', [ $this, 'output_admin_html_footer' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_add_snippet',    [ $this, 'ajax_add_snippet' ] );
        add_action( 'wp_ajax_wpt_edit_snippet',   [ $this, 'ajax_edit_snippet' ] );
        add_action( 'wp_ajax_wpt_delete_snippet', [ $this, 'ajax_delete_snippet' ] );
        add_action( 'wp_ajax_wpt_toggle_snippet', [ $this, 'ajax_toggle_snippet' ] );
    }

    public function deactivate(): void {
        delete_transient( self::DEACTIVATED_TRANSIENT );
    }

    // -- Table Creation --

    /**
     * Create the snippets table if it doesn't exist.
     */
    private function maybe_create_table(): void {
        $installed_version = get_transient( self::DB_VERSION_KEY );

        if ( $installed_version === self::DB_VERSION ) {
            return;
        }

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $wpdb->prefix . 'wpt_snippets';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            code LONGTEXT NOT NULL,
            type VARCHAR(10) DEFAULT 'php',
            scope VARCHAR(20) DEFAULT 'everywhere',
            priority INT DEFAULT 10,
            is_active TINYINT(1) DEFAULT 0,
            description TEXT,
            conditional LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_type_active (type, is_active),
            INDEX idx_scope (scope)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql );

        set_transient( self::DB_VERSION_KEY, self::DB_VERSION, YEAR_IN_SECONDS );
    }

    // -- PHP Snippet Execution --

    /**
     * Execute active PHP snippets with error recovery.
     */
    public function execute_php_snippets(): void {
        $settings = $this->get_settings();

        if ( empty( $settings['enable_php'] ) ) {
            return;
        }

        // PHP snippets require manage_options.
        if ( ! current_user_can( 'manage_options' ) && is_admin() ) {
            return;
        }

        $snippets = $this->get_active_snippets( 'php' );

        if ( empty( $snippets ) ) {
            return;
        }

        foreach ( $snippets as $snippet ) {
            if ( ! $this->should_execute_in_scope( $snippet['scope'] ) ) {
                continue;
            }

            $this->execute_single_php_snippet( $snippet );
        }
    }

    /**
     * Execute a single PHP snippet with output buffering and error handling.
     *
     * @param array $snippet Snippet data from DB.
     */
    private function execute_single_php_snippet( array $snippet ): void {
        $settings   = $this->get_settings();
        $snippet_id = (int) $snippet['id'];

        if ( ! empty( $settings['enable_error_recovery'] ) ) {
            // Store the snippet ID being executed so the shutdown handler can find it.
            $GLOBALS['wpt_executing_snippet_id'] = $snippet_id;

            register_shutdown_function( [ $this, 'shutdown_error_handler' ] );
        }

        ob_start();

        try {
            // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Intentional: user-managed code snippets.
            eval( $snippet['code'] );
        } catch ( \Throwable $e ) {
            $this->deactivate_snippet_on_error( $snippet_id, $e->getMessage() );
        }

        ob_end_clean();

        // Clear the executing snippet marker.
        unset( $GLOBALS['wpt_executing_snippet_id'] );
    }

    /**
     * Shutdown function to catch fatal errors during PHP snippet execution.
     */
    public function shutdown_error_handler(): void {
        $error = error_get_last();

        if ( $error === null ) {
            return;
        }

        // Only handle fatal errors.
        $fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ];

        if ( ! in_array( $error['type'], $fatal_types, true ) ) {
            return;
        }

        $snippet_id = $GLOBALS['wpt_executing_snippet_id'] ?? 0;

        if ( $snippet_id < 1 ) {
            return;
        }

        $this->deactivate_snippet_on_error( (int) $snippet_id, $error['message'] );
    }

    /**
     * Deactivate a snippet and set a transient for the admin notice.
     *
     * @param int    $snippet_id Snippet ID.
     * @param string $error_msg  Error message.
     */
    private function deactivate_snippet_on_error( int $snippet_id, string $error_msg ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'wpt_snippets';

        $wpdb->update(
            $table,
            [ 'is_active' => 0 ],
            [ 'id' => $snippet_id ],
            [ '%d' ],
            [ '%d' ]
        );

        // Get snippet title for the notice.
        $title = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT title FROM {$table} WHERE id = %d",
                $snippet_id
            )
        );

        set_transient( self::DEACTIVATED_TRANSIENT, [
            'snippet_id'    => $snippet_id,
            'snippet_title' => $title ?: "Snippet #{$snippet_id}",
            'error'         => $error_msg,
        ], HOUR_IN_SECONDS );
    }

    /**
     * Show admin notice when a snippet was auto-deactivated.
     */
    public function show_deactivation_notice(): void {
        $data = get_transient( self::DEACTIVATED_TRANSIENT );

        if ( empty( $data ) ) {
            return;
        }

        delete_transient( self::DEACTIVATED_TRANSIENT );

        printf(
            '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
            esc_html__( 'WPTransformed Code Snippets:', 'wptransformed' ),
            sprintf(
                /* translators: %s: snippet title */
                esc_html__( 'Snippet "%s" caused an error and was automatically deactivated.', 'wptransformed' ),
                esc_html( $data['snippet_title'] )
            ),
            esc_html( $data['error'] )
        );
    }

    // -- CSS Output --

    /**
     * Output active CSS snippets in the frontend head.
     */
    public function output_frontend_css(): void {
        $this->output_snippets( 'css', 'frontend' );
    }

    /**
     * Output active CSS snippets in the admin head.
     */
    public function output_admin_css(): void {
        $this->output_snippets( 'css', 'admin' );
    }

    // -- JS Output --

    /**
     * Output active JS snippets in the frontend footer.
     */
    public function output_frontend_js(): void {
        $this->output_snippets( 'js', 'frontend' );
    }

    /**
     * Output active JS snippets in the admin footer.
     */
    public function output_admin_js(): void {
        $this->output_snippets( 'js', 'admin' );
    }

    // -- HTML Output --

    /**
     * Output active HTML snippets in the frontend head.
     */
    public function output_frontend_html_head(): void {
        $this->output_html_snippets( 'frontend', 'head' );
    }

    /**
     * Output active HTML snippets in the frontend footer.
     */
    public function output_frontend_html_footer(): void {
        $this->output_html_snippets( 'frontend', 'footer' );
    }

    /**
     * Output active HTML snippets in the admin head.
     */
    public function output_admin_html_head(): void {
        $this->output_html_snippets( 'admin', 'head' );
    }

    /**
     * Output active HTML snippets in the admin footer.
     */
    public function output_admin_html_footer(): void {
        $this->output_html_snippets( 'admin', 'footer' );
    }

    // -- Snippet Output Helpers --

    /**
     * Output CSS or JS snippets for a given context.
     *
     * @param string $type    Snippet type: 'css' or 'js'.
     * @param string $context Current context: 'frontend' or 'admin'.
     */
    private function output_snippets( string $type, string $context ): void {
        $snippets = $this->get_active_snippets( $type );

        if ( empty( $snippets ) ) {
            return;
        }

        foreach ( $snippets as $snippet ) {
            if ( ! $this->should_output_in_context( $snippet['scope'], $context ) ) {
                continue;
            }

            if ( $type === 'css' ) {
                echo "\n<style id=\"wpt-snippet-" . esc_attr( (string) $snippet['id'] ) . "\">\n";
                // CSS is output as-is; it's sandboxed in a style tag.
                echo wp_strip_all_tags( $snippet['code'] );
                echo "\n</style>\n";
            } elseif ( $type === 'js' ) {
                echo "\n<script id=\"wpt-snippet-" . esc_attr( (string) $snippet['id'] ) . "\">\n";
                // JS is output as-is inside a script tag.
                echo $snippet['code']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: user-managed JS.
                echo "\n</script>\n";
            }
        }
    }

    /**
     * Output HTML snippets for a given context and location.
     *
     * HTML snippets use a conditional JSON field to determine head vs footer placement.
     * Default placement is 'head'.
     *
     * @param string $context  Current context: 'frontend' or 'admin'.
     * @param string $location Target location: 'head' or 'footer'.
     */
    private function output_html_snippets( string $context, string $location ): void {
        $snippets = $this->get_active_snippets( 'html' );

        if ( empty( $snippets ) ) {
            return;
        }

        foreach ( $snippets as $snippet ) {
            if ( ! $this->should_output_in_context( $snippet['scope'], $context ) ) {
                continue;
            }

            // Determine placement from conditional field.
            $conditional = ! empty( $snippet['conditional'] ) ? json_decode( $snippet['conditional'], true ) : [];
            $placement   = ( is_array( $conditional ) && isset( $conditional['placement'] ) ) ? $conditional['placement'] : 'head';

            if ( $placement !== $location ) {
                continue;
            }

            echo "\n<!-- WPT Snippet: " . esc_html( $snippet['title'] ) . " -->\n";
            echo $snippet['code']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: user-managed HTML.
            echo "\n";
        }
    }

    /**
     * Check if a snippet should execute in the current scope.
     *
     * @param string $scope Snippet scope.
     * @return bool
     */
    private function should_execute_in_scope( string $scope ): bool {
        if ( $scope === 'everywhere' ) {
            return true;
        }

        if ( $scope === 'admin' && is_admin() ) {
            return true;
        }

        if ( $scope === 'frontend' && ! is_admin() ) {
            return true;
        }

        return false;
    }

    /**
     * Check if a snippet should output in the given context.
     *
     * @param string $scope   Snippet scope (everywhere, admin, frontend).
     * @param string $context Current context (admin, frontend).
     * @return bool
     */
    private function should_output_in_context( string $scope, string $context ): bool {
        if ( $scope === 'everywhere' ) {
            return true;
        }

        return $scope === $context;
    }

    /**
     * Get all active snippets of a given type, ordered by priority.
     *
     * @param string $type Snippet type.
     * @return array
     */
    private function get_active_snippets( string $type ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'wpt_snippets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, code, type, scope, priority, conditional FROM {$table} WHERE type = %s AND is_active = 1 ORDER BY priority ASC, id ASC",
                $type
            ),
            ARRAY_A
        );

        return is_array( $results ) ? $results : [];
    }

    // -- AJAX: Add Snippet --

    /**
     * Add a new snippet via AJAX.
     */
    public function ajax_add_snippet(): void {
        check_ajax_referer( 'wpt_code_snippets_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $code        = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Code is stored as-is.
        $type        = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'php';
        $scope       = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'everywhere';
        $priority    = isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10;
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $conditional = isset( $_POST['conditional'] ) ? sanitize_text_field( wp_unslash( $_POST['conditional'] ) ) : '';

        // Validate.
        $error = $this->validate_snippet( $title, $code, $type, $scope, $priority );
        if ( $error ) {
            wp_send_json_error( [ 'message' => $error ] );
        }

        // Check PHP is enabled.
        if ( $type === 'php' ) {
            $settings = $this->get_settings();
            if ( empty( $settings['enable_php'] ) ) {
                wp_send_json_error( [ 'message' => __( 'PHP snippets are disabled in settings.', 'wptransformed' ) ] );
            }
        }

        // Validate conditional JSON if provided.
        $conditional_value = '';
        if ( ! empty( $conditional ) ) {
            $decoded = json_decode( $conditional, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                wp_send_json_error( [ 'message' => __( 'Invalid JSON in conditional field.', 'wptransformed' ) ] );
            }
            $conditional_value = wp_json_encode( $decoded );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_snippets';

        $inserted = $wpdb->insert(
            $table,
            [
                'title'       => $title,
                'code'        => $code,
                'type'        => $type,
                'scope'       => $scope,
                'priority'    => $priority,
                'is_active'   => 0,
                'description' => $description,
                'conditional' => $conditional_value,
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        if ( $inserted === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save snippet.', 'wptransformed' ) ] );
        }

        $new_id = (int) $wpdb->insert_id;

        wp_send_json_success( [
            'message' => __( 'Snippet added successfully.', 'wptransformed' ),
            'snippet' => [
                'id'          => $new_id,
                'title'       => $title,
                'type'        => $type,
                'scope'       => $scope,
                'priority'    => $priority,
                'is_active'   => 0,
                'description' => $description,
                'created_at'  => current_time( 'mysql' ),
            ],
        ] );
    }

    // -- AJAX: Edit Snippet --

    /**
     * Edit an existing snippet via AJAX.
     */
    public function ajax_edit_snippet(): void {
        check_ajax_referer( 'wpt_code_snippets_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id          = isset( $_POST['snippet_id'] ) ? (int) $_POST['snippet_id'] : 0;
        $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $code        = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $type        = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'php';
        $scope       = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'everywhere';
        $priority    = isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10;
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
        $conditional = isset( $_POST['conditional'] ) ? sanitize_text_field( wp_unslash( $_POST['conditional'] ) ) : '';

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid snippet ID.', 'wptransformed' ) ] );
        }

        // Validate.
        $error = $this->validate_snippet( $title, $code, $type, $scope, $priority );
        if ( $error ) {
            wp_send_json_error( [ 'message' => $error ] );
        }

        // Check PHP is enabled.
        if ( $type === 'php' ) {
            $settings = $this->get_settings();
            if ( empty( $settings['enable_php'] ) ) {
                wp_send_json_error( [ 'message' => __( 'PHP snippets are disabled in settings.', 'wptransformed' ) ] );
            }
        }

        // Validate conditional JSON if provided.
        $conditional_value = '';
        if ( ! empty( $conditional ) ) {
            $decoded = json_decode( $conditional, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                wp_send_json_error( [ 'message' => __( 'Invalid JSON in conditional field.', 'wptransformed' ) ] );
            }
            $conditional_value = wp_json_encode( $decoded );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_snippets';

        // Verify snippet exists.
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id )
        );

        if ( ! $exists ) {
            wp_send_json_error( [ 'message' => __( 'Snippet not found.', 'wptransformed' ) ] );
        }

        $updated = $wpdb->update(
            $table,
            [
                'title'       => $title,
                'code'        => $code,
                'type'        => $type,
                'scope'       => $scope,
                'priority'    => $priority,
                'description' => $description,
                'conditional' => $conditional_value,
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to update snippet.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => __( 'Snippet updated successfully.', 'wptransformed' ),
        ] );
    }

    // -- AJAX: Delete Snippet --

    /**
     * Delete a snippet via AJAX.
     */
    public function ajax_delete_snippet(): void {
        check_ajax_referer( 'wpt_code_snippets_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id = isset( $_POST['snippet_id'] ) ? (int) $_POST['snippet_id'] : 0;

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid snippet ID.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_snippets';

        $deleted = $wpdb->delete(
            $table,
            [ 'id' => $id ],
            [ '%d' ]
        );

        if ( $deleted === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete snippet.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => __( 'Snippet deleted successfully.', 'wptransformed' ),
        ] );
    }

    // -- AJAX: Toggle Snippet --

    /**
     * Toggle a snippet active/inactive via AJAX.
     */
    public function ajax_toggle_snippet(): void {
        check_ajax_referer( 'wpt_code_snippets_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $id     = isset( $_POST['snippet_id'] ) ? (int) $_POST['snippet_id'] : 0;
        $active = isset( $_POST['active'] ) && $_POST['active'] === '1' ? 1 : 0;

        if ( $id < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid snippet ID.', 'wptransformed' ) ] );
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_snippets';

        // If activating a PHP snippet, check that PHP is enabled.
        if ( $active === 1 ) {
            $snippet_type = $wpdb->get_var(
                $wpdb->prepare( "SELECT type FROM {$table} WHERE id = %d", $id )
            );

            if ( $snippet_type === 'php' ) {
                $settings = $this->get_settings();
                if ( empty( $settings['enable_php'] ) ) {
                    wp_send_json_error( [ 'message' => __( 'PHP snippets are disabled in settings.', 'wptransformed' ) ] );
                }
            }
        }

        $updated = $wpdb->update(
            $table,
            [ 'is_active' => $active ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to update snippet.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => $active
                ? __( 'Snippet activated.', 'wptransformed' )
                : __( 'Snippet deactivated.', 'wptransformed' ),
            'active'  => $active,
        ] );
    }

    // -- Validation --

    /**
     * Validate snippet data.
     *
     * @param string $title    Snippet title.
     * @param string $code     Snippet code.
     * @param string $type     Snippet type.
     * @param string $scope    Snippet scope.
     * @param int    $priority Snippet priority.
     * @return string Empty string on success, error message on failure.
     */
    private function validate_snippet( string $title, string $code, string $type, string $scope, int $priority ): string {
        if ( empty( $title ) ) {
            return __( 'Title is required.', 'wptransformed' );
        }

        if ( strlen( $title ) > 255 ) {
            return __( 'Title must be 255 characters or fewer.', 'wptransformed' );
        }

        if ( empty( $code ) ) {
            return __( 'Code is required.', 'wptransformed' );
        }

        if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
            return __( 'Invalid snippet type.', 'wptransformed' );
        }

        if ( ! in_array( $scope, self::ALLOWED_SCOPES, true ) ) {
            return __( 'Invalid snippet scope.', 'wptransformed' );
        }

        if ( $priority < -100 || $priority > 1000 ) {
            return __( 'Priority must be between -100 and 1000.', 'wptransformed' );
        }

        return '';
    }

    // -- Admin UI --

    /**
     * Render the settings panel including snippet management.
     */
    public function render_settings(): void {
        $settings = $this->get_settings();

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_snippets';

        // Fetch all snippets.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $snippets = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY priority ASC, created_at DESC",
            ARRAY_A
        );

        if ( ! is_array( $snippets ) ) {
            $snippets = [];
        }
        ?>

        <!-- Module Settings -->
        <h3><?php esc_html_e( 'Settings', 'wptransformed' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable PHP Snippets', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enable_php" value="1"
                               <?php checked( ! empty( $settings['enable_php'] ) ); ?>>
                        <?php esc_html_e( 'Allow PHP code execution (requires manage_options capability)', 'wptransformed' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Warning: PHP snippets execute server-side code. Only enable if you trust all administrators.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Error Recovery', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enable_error_recovery" value="1"
                               <?php checked( ! empty( $settings['enable_error_recovery'] ) ); ?>>
                        <?php esc_html_e( 'Automatically deactivate snippets that cause fatal errors', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wpt-codemirror-theme"><?php esc_html_e( 'Editor Theme', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-codemirror-theme" name="wpt_codemirror_theme">
                        <option value="default" <?php selected( $settings['codemirror_theme'], 'default' ); ?>>
                            <?php esc_html_e( 'Default', 'wptransformed' ); ?>
                        </option>
                        <option value="monokai" <?php selected( $settings['codemirror_theme'], 'monokai' ); ?>>
                            <?php esc_html_e( 'Monokai', 'wptransformed' ); ?>
                        </option>
                        <option value="material" <?php selected( $settings['codemirror_theme'], 'material' ); ?>>
                            <?php esc_html_e( 'Material', 'wptransformed' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
        </table>

        <!-- Snippet Manager -->
        <div id="wpt-snippets-manager" data-php-enabled="<?php echo esc_attr( ! empty( $settings['enable_php'] ) ? '1' : '0' ); ?>">

            <h3><?php esc_html_e( 'Snippets', 'wptransformed' ); ?></h3>

            <!-- Add Snippet Form -->
            <div class="wpt-snippet-add-form">
                <h4><?php esc_html_e( 'Add New Snippet', 'wptransformed' ); ?></h4>

                <div class="wpt-snippet-form-row">
                    <div class="wpt-snippet-field wpt-snippet-field-title">
                        <label for="wpt-snippet-title"><?php esc_html_e( 'Title', 'wptransformed' ); ?></label>
                        <input type="text" id="wpt-snippet-title" placeholder="<?php esc_attr_e( 'My Snippet', 'wptransformed' ); ?>" class="regular-text">
                    </div>
                    <div class="wpt-snippet-field">
                        <label for="wpt-snippet-type"><?php esc_html_e( 'Type', 'wptransformed' ); ?></label>
                        <select id="wpt-snippet-type">
                            <option value="php">PHP</option>
                            <option value="css">CSS</option>
                            <option value="js">JavaScript</option>
                            <option value="html">HTML</option>
                        </select>
                    </div>
                    <div class="wpt-snippet-field">
                        <label for="wpt-snippet-scope"><?php esc_html_e( 'Scope', 'wptransformed' ); ?></label>
                        <select id="wpt-snippet-scope">
                            <option value="everywhere"><?php esc_html_e( 'Everywhere', 'wptransformed' ); ?></option>
                            <option value="frontend"><?php esc_html_e( 'Frontend Only', 'wptransformed' ); ?></option>
                            <option value="admin"><?php esc_html_e( 'Admin Only', 'wptransformed' ); ?></option>
                        </select>
                    </div>
                    <div class="wpt-snippet-field">
                        <label for="wpt-snippet-priority"><?php esc_html_e( 'Priority', 'wptransformed' ); ?></label>
                        <input type="number" id="wpt-snippet-priority" value="10" min="-100" max="1000" class="small-text">
                    </div>
                </div>

                <div class="wpt-snippet-form-row">
                    <div class="wpt-snippet-field wpt-snippet-field-full">
                        <label for="wpt-snippet-description"><?php esc_html_e( 'Description (optional)', 'wptransformed' ); ?></label>
                        <input type="text" id="wpt-snippet-description" placeholder="<?php esc_attr_e( 'What does this snippet do?', 'wptransformed' ); ?>" class="large-text">
                    </div>
                </div>

                <div class="wpt-snippet-form-row">
                    <div class="wpt-snippet-field wpt-snippet-field-full">
                        <label for="wpt-snippet-code"><?php esc_html_e( 'Code', 'wptransformed' ); ?></label>
                        <textarea id="wpt-snippet-code" rows="12" class="large-text code"></textarea>
                        <p class="description" id="wpt-snippet-php-note">
                            <?php esc_html_e( 'PHP snippets: Do not include opening <?php tag. Code executes on the init hook.', 'wptransformed' ); ?>
                        </p>
                    </div>
                </div>

                <div class="wpt-snippet-form-row" id="wpt-snippet-html-placement-row" style="display:none;">
                    <div class="wpt-snippet-field">
                        <label for="wpt-snippet-placement"><?php esc_html_e( 'Placement', 'wptransformed' ); ?></label>
                        <select id="wpt-snippet-placement">
                            <option value="head"><?php esc_html_e( 'Head', 'wptransformed' ); ?></option>
                            <option value="footer"><?php esc_html_e( 'Footer', 'wptransformed' ); ?></option>
                        </select>
                    </div>
                </div>

                <button type="button" id="wpt-add-snippet-btn" class="button button-primary">
                    <?php esc_html_e( 'Add Snippet', 'wptransformed' ); ?>
                </button>
            </div>

            <!-- Snippets Table -->
            <div class="wpt-snippets-table-wrap">
                <table class="wp-list-table widefat fixed striped" id="wpt-snippets-table">
                    <thead>
                        <tr>
                            <th class="wpt-col-status"><?php esc_html_e( 'Active', 'wptransformed' ); ?></th>
                            <th class="wpt-col-title"><?php esc_html_e( 'Title', 'wptransformed' ); ?></th>
                            <th class="wpt-col-type"><?php esc_html_e( 'Type', 'wptransformed' ); ?></th>
                            <th class="wpt-col-scope"><?php esc_html_e( 'Scope', 'wptransformed' ); ?></th>
                            <th class="wpt-col-priority"><?php esc_html_e( 'Priority', 'wptransformed' ); ?></th>
                            <th class="wpt-col-updated"><?php esc_html_e( 'Updated', 'wptransformed' ); ?></th>
                            <th class="wpt-col-actions"><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wpt-snippets-tbody">
                        <?php if ( empty( $snippets ) ) : ?>
                            <tr class="wpt-no-snippets">
                                <td colspan="7"><?php esc_html_e( 'No snippets yet. Add your first snippet above.', 'wptransformed' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $snippets as $snippet ) : ?>
                                <tr data-snippet-id="<?php echo esc_attr( (string) $snippet['id'] ); ?>">
                                    <td class="wpt-col-status">
                                        <label class="wpt-toggle wpt-toggle-small">
                                            <input type="checkbox"
                                                   class="wpt-snippet-toggle"
                                                   data-snippet-id="<?php echo esc_attr( (string) $snippet['id'] ); ?>"
                                                   <?php checked( (int) $snippet['is_active'], 1 ); ?>>
                                            <span class="wpt-toggle-slider"></span>
                                        </label>
                                    </td>
                                    <td class="wpt-col-title">
                                        <strong><?php echo esc_html( $snippet['title'] ); ?></strong>
                                        <?php if ( ! empty( $snippet['description'] ) ) : ?>
                                            <br><span class="description"><?php echo esc_html( $snippet['description'] ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="wpt-col-type">
                                        <span class="wpt-type-badge wpt-type-<?php echo esc_attr( $snippet['type'] ); ?>">
                                            <?php echo esc_html( strtoupper( $snippet['type'] ) ); ?>
                                        </span>
                                    </td>
                                    <td class="wpt-col-scope"><?php echo esc_html( ucfirst( $snippet['scope'] ) ); ?></td>
                                    <td class="wpt-col-priority"><?php echo esc_html( (string) $snippet['priority'] ); ?></td>
                                    <td class="wpt-col-updated"><?php echo esc_html( $snippet['updated_at'] ); ?></td>
                                    <td class="wpt-col-actions">
                                        <button type="button" class="button button-small wpt-edit-snippet"
                                                data-snippet-id="<?php echo esc_attr( (string) $snippet['id'] ); ?>"
                                                data-title="<?php echo esc_attr( $snippet['title'] ); ?>"
                                                data-code="<?php echo esc_attr( $snippet['code'] ); ?>"
                                                data-type="<?php echo esc_attr( $snippet['type'] ); ?>"
                                                data-scope="<?php echo esc_attr( $snippet['scope'] ); ?>"
                                                data-priority="<?php echo esc_attr( (string) $snippet['priority'] ); ?>"
                                                data-description="<?php echo esc_attr( $snippet['description'] ?? '' ); ?>"
                                                data-conditional="<?php echo esc_attr( $snippet['conditional'] ?? '' ); ?>">
                                            <?php esc_html_e( 'Edit', 'wptransformed' ); ?>
                                        </button>
                                        <button type="button" class="button button-small button-link-delete wpt-delete-snippet"
                                                data-snippet-id="<?php echo esc_attr( (string) $snippet['id'] ); ?>">
                                            <?php esc_html_e( 'Delete', 'wptransformed' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="wpt-snippet-edit-modal" class="wpt-modal" style="display:none;">
            <div class="wpt-modal-overlay"></div>
            <div class="wpt-modal-content">
                <div class="wpt-modal-header">
                    <h3><?php esc_html_e( 'Edit Snippet', 'wptransformed' ); ?></h3>
                    <button type="button" class="wpt-modal-close">&times;</button>
                </div>
                <div class="wpt-modal-body">
                    <input type="hidden" id="wpt-edit-snippet-id">
                    <div class="wpt-snippet-form-row">
                        <div class="wpt-snippet-field wpt-snippet-field-title">
                            <label for="wpt-edit-title"><?php esc_html_e( 'Title', 'wptransformed' ); ?></label>
                            <input type="text" id="wpt-edit-title" class="regular-text">
                        </div>
                        <div class="wpt-snippet-field">
                            <label for="wpt-edit-type"><?php esc_html_e( 'Type', 'wptransformed' ); ?></label>
                            <select id="wpt-edit-type">
                                <option value="php">PHP</option>
                                <option value="css">CSS</option>
                                <option value="js">JavaScript</option>
                                <option value="html">HTML</option>
                            </select>
                        </div>
                        <div class="wpt-snippet-field">
                            <label for="wpt-edit-scope"><?php esc_html_e( 'Scope', 'wptransformed' ); ?></label>
                            <select id="wpt-edit-scope">
                                <option value="everywhere"><?php esc_html_e( 'Everywhere', 'wptransformed' ); ?></option>
                                <option value="frontend"><?php esc_html_e( 'Frontend Only', 'wptransformed' ); ?></option>
                                <option value="admin"><?php esc_html_e( 'Admin Only', 'wptransformed' ); ?></option>
                            </select>
                        </div>
                        <div class="wpt-snippet-field">
                            <label for="wpt-edit-priority"><?php esc_html_e( 'Priority', 'wptransformed' ); ?></label>
                            <input type="number" id="wpt-edit-priority" value="10" min="-100" max="1000" class="small-text">
                        </div>
                    </div>
                    <div class="wpt-snippet-form-row">
                        <div class="wpt-snippet-field wpt-snippet-field-full">
                            <label for="wpt-edit-description"><?php esc_html_e( 'Description', 'wptransformed' ); ?></label>
                            <input type="text" id="wpt-edit-description" class="large-text">
                        </div>
                    </div>
                    <div class="wpt-snippet-form-row" id="wpt-edit-html-placement-row" style="display:none;">
                        <div class="wpt-snippet-field">
                            <label for="wpt-edit-placement"><?php esc_html_e( 'Placement', 'wptransformed' ); ?></label>
                            <select id="wpt-edit-placement">
                                <option value="head"><?php esc_html_e( 'Head', 'wptransformed' ); ?></option>
                                <option value="footer"><?php esc_html_e( 'Footer', 'wptransformed' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="wpt-snippet-form-row">
                        <div class="wpt-snippet-field wpt-snippet-field-full">
                            <label for="wpt-edit-code"><?php esc_html_e( 'Code', 'wptransformed' ); ?></label>
                            <textarea id="wpt-edit-code" rows="15" class="large-text code"></textarea>
                        </div>
                    </div>
                </div>
                <div class="wpt-modal-footer">
                    <button type="button" class="button" id="wpt-edit-cancel"><?php esc_html_e( 'Cancel', 'wptransformed' ); ?></button>
                    <button type="button" class="button button-primary" id="wpt-edit-save"><?php esc_html_e( 'Save Changes', 'wptransformed' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Sanitize module settings.
     *
     * @param array $raw Raw form data.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( array $raw ): array {
        $themes = [ 'default', 'monokai', 'material' ];

        return [
            'enable_php'            => ! empty( $raw['wpt_enable_php'] ),
            'enable_error_recovery' => ! empty( $raw['wpt_enable_error_recovery'] ),
            'codemirror_theme'      => isset( $raw['wpt_codemirror_theme'] ) && in_array( $raw['wpt_codemirror_theme'], $themes, true )
                ? $raw['wpt_codemirror_theme']
                : 'default',
        ];
    }

    // -- Assets --

    /**
     * Enqueue admin assets on the WPTransformed settings page.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        // WordPress bundled CodeMirror.
        $cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/x-php' ] );

        wp_enqueue_style(
            'wpt-code-snippets',
            WPT_URL . 'modules/custom-code/css/code-snippets.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-code-snippets',
            WPT_URL . 'modules/custom-code/js/code-snippets.js',
            [ 'wp-codemirror' ],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-code-snippets', 'wptCodeSnippets', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'wpt_code_snippets_nonce' ),
            'cmSettings' => $cm_settings !== false ? $cm_settings : [],
            'i18n'       => [
                'confirmDelete'  => __( 'Are you sure you want to delete this snippet? This cannot be undone.', 'wptransformed' ),
                'networkError'   => __( 'Network error. Please try again.', 'wptransformed' ),
                'titleRequired'  => __( 'Title is required.', 'wptransformed' ),
                'codeRequired'   => __( 'Code is required.', 'wptransformed' ),
                'phpDisabled'    => __( 'PHP snippets are disabled in settings.', 'wptransformed' ),
                'saving'         => __( 'Saving...', 'wptransformed' ),
                'addSnippet'     => __( 'Add Snippet', 'wptransformed' ),
                'saveChanges'    => __( 'Save Changes', 'wptransformed' ),
                'active'         => __( 'Active', 'wptransformed' ),
                'inactive'       => __( 'Inactive', 'wptransformed' ),
            ],
        ] );
    }

    // -- Cleanup --

    /**
     * Declare persistent data for uninstall.
     *
     * @return array
     */
    public function get_cleanup_tasks(): array {
        global $wpdb;

        return [
            'drop_table:' . $wpdb->prefix . 'wpt_snippets',
            'delete_transient:' . self::DB_VERSION_KEY,
            'delete_transient:' . self::DEACTIVATED_TRANSIENT,
        ];
    }
}
