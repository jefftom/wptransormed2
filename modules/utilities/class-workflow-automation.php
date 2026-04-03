<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Workflow Automation -- Rule-based automation: "When [trigger] and [conditions] then [actions]".
 *
 * Features:
 *  - Custom DB table for rules (wpt_automation_rules)
 *  - Visual rule builder (form-based, not drag-drop)
 *  - Triggers: post_published, user_registered, comment_posted, wc_order_completed, plugin_activated
 *  - Conditions: field checks as JSON array of {field, op, value}
 *  - Actions: send_email, send_webhook, set_meta, clear_caches (max 3 per rule)
 *  - Execution log stored in option wpt_automation_log (last 50 entries)
 *  - AJAX for CRUD, toggle active, view log
 *
 * @package WPTransformed
 */
class Workflow_Automation extends Module_Base {

    /**
     * Current DB schema version.
     */
    private const DB_VERSION = '1.0';

    /**
     * DB version option key.
     */
    private const DB_VERSION_KEY = 'wpt_workflow_automation_db_version';

    /**
     * Log option key.
     */
    private const LOG_KEY = 'wpt_automation_log';

    /**
     * Maximum log entries to keep.
     */
    private const MAX_LOG_ENTRIES = 50;

    /**
     * Maximum actions per rule.
     */
    private const MAX_ACTIONS = 3;

    /**
     * Supported triggers with human-readable labels.
     *
     * @var array<string, string>
     */
    private const TRIGGERS = [
        'post_published'      => 'Post Published',
        'user_registered'     => 'User Registered',
        'comment_posted'      => 'Comment Posted',
        'wc_order_completed'  => 'WooCommerce Order Completed',
        'plugin_activated'    => 'Plugin Activated',
    ];

    /**
     * Supported condition operators.
     *
     * @var array<string, string>
     */
    private const OPERATORS = [
        '=='         => 'Equals',
        '!='         => 'Not Equals',
        'contains'   => 'Contains',
        '>'          => 'Greater Than',
        '<'          => 'Less Than',
    ];

    /**
     * Supported action types.
     *
     * @var array<string, string>
     */
    private const ACTION_TYPES = [
        'send_email'    => 'Send Email',
        'send_webhook'  => 'Send Webhook',
        'set_meta'      => 'Set Meta',
        'clear_caches'  => 'Clear Caches',
    ];

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'workflow-automation';
    }

    public function get_title(): string {
        return __( 'Workflow Automation', 'wptransformed' );
    }

    public function get_category(): string {
        return 'utilities';
    }

    public function get_description(): string {
        return __( 'Automate WordPress workflows with trigger-condition-action rules.', 'wptransformed' );
    }

    public function get_tier(): string {
        return 'pro';
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        $this->maybe_create_table();

        // Hook into triggers.
        $this->register_trigger_hooks();

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_automation_save_rule',   [ $this, 'ajax_save_rule' ] );
        add_action( 'wp_ajax_wpt_automation_delete_rule', [ $this, 'ajax_delete_rule' ] );
        add_action( 'wp_ajax_wpt_automation_toggle_rule', [ $this, 'ajax_toggle_rule' ] );
        add_action( 'wp_ajax_wpt_automation_get_rule',    [ $this, 'ajax_get_rule' ] );
        add_action( 'wp_ajax_wpt_automation_get_log',     [ $this, 'ajax_get_log' ] );
    }

    // =====================================================================
    //  TABLE CREATION
    // =====================================================================

    /**
     * Create the automation rules table if needed.
     */
    private function maybe_create_table(): void {
        $installed = get_option( self::DB_VERSION_KEY );
        if ( $installed === self::DB_VERSION ) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wpt_automation_rules';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            trigger_hook VARCHAR(100) NOT NULL,
            conditions JSON,
            actions JSON,
            is_active TINYINT(1) DEFAULT 1,
            last_run DATETIME NULL,
            run_count INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_trigger (trigger_hook),
            INDEX idx_active (is_active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    // =====================================================================
    //  TRIGGER HOOKS
    // =====================================================================

    /**
     * Register WordPress hooks for all supported triggers.
     */
    private function register_trigger_hooks(): void {
        // Post Published.
        add_action( 'transition_post_status', [ $this, 'on_post_published' ], 10, 3 );

        // User Registered.
        add_action( 'user_register', [ $this, 'on_user_registered' ], 10, 1 );

        // Comment Posted.
        add_action( 'wp_insert_comment', [ $this, 'on_comment_posted' ], 10, 2 );

        // WooCommerce Order Completed (only if WC is active).
        add_action( 'woocommerce_order_status_completed', [ $this, 'on_wc_order_completed' ], 10, 1 );

        // Plugin Activated.
        add_action( 'activated_plugin', [ $this, 'on_plugin_activated' ], 10, 2 );
    }

    /**
     * Handle post published trigger.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post       Post object.
     */
    public function on_post_published( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) {
            return;
        }

        $context = [
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'post_id'    => $post->ID,
            'author_id'  => $post->post_author,
            'object_id'  => $post->ID,
            'object_type' => 'post',
        ];

        $this->execute_rules( 'post_published', $context );
    }

    /**
     * Handle user registered trigger.
     *
     * @param int $user_id New user ID.
     */
    public function on_user_registered( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $context = [
            'user_id'    => $user_id,
            'user_email' => $user->user_email,
            'user_login' => $user->user_login,
            'role'       => implode( ', ', $user->roles ),
            'object_id'  => $user_id,
            'object_type' => 'user',
        ];

        $this->execute_rules( 'user_registered', $context );
    }

    /**
     * Handle comment posted trigger.
     *
     * @param int         $comment_id Comment ID.
     * @param \WP_Comment $comment    Comment object.
     */
    public function on_comment_posted( int $comment_id, \WP_Comment $comment ): void {
        $context = [
            'comment_id'      => $comment_id,
            'comment_author'  => $comment->comment_author,
            'comment_email'   => $comment->comment_author_email,
            'post_id'         => (int) $comment->comment_post_ID,
            'comment_type'    => $comment->comment_type ?: 'comment',
            'object_id'       => $comment_id,
            'object_type'     => 'comment',
        ];

        $this->execute_rules( 'comment_posted', $context );
    }

    /**
     * Handle WooCommerce order completed trigger.
     *
     * @param int $order_id Order ID.
     */
    public function on_wc_order_completed( int $order_id ): void {
        $context = [
            'order_id'   => $order_id,
            'object_id'  => $order_id,
            'object_type' => 'order',
        ];

        // If WooCommerce is available, add more context.
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $context['order_total']  = $order->get_total();
                $context['customer_email'] = $order->get_billing_email();
                $context['order_status'] = $order->get_status();
            }
        }

        $this->execute_rules( 'wc_order_completed', $context );
    }

    /**
     * Handle plugin activated trigger.
     *
     * @param string $plugin       Plugin path.
     * @param bool   $network_wide Whether network-wide activation.
     */
    public function on_plugin_activated( string $plugin, bool $network_wide ): void {
        $context = [
            'plugin'       => $plugin,
            'network_wide' => $network_wide ? 'yes' : 'no',
            'object_id'    => 0,
            'object_type'  => 'plugin',
        ];

        $this->execute_rules( 'plugin_activated', $context );
    }

    // =====================================================================
    //  RULE EXECUTION ENGINE
    // =====================================================================

    /**
     * Execute all active rules matching a trigger.
     *
     * @param string $trigger_hook The trigger that fired.
     * @param array  $context      Contextual data from the trigger.
     */
    private function execute_rules( string $trigger_hook, array $context ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'wpt_automation_rules';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rules = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE trigger_hook = %s AND is_active = 1",
                $trigger_hook
            ),
            ARRAY_A
        );

        if ( empty( $rules ) ) {
            return;
        }

        foreach ( $rules as $rule ) {
            $conditions = json_decode( $rule['conditions'] ?? '[]', true );
            if ( ! is_array( $conditions ) ) {
                $conditions = [];
            }

            // Evaluate conditions.
            if ( ! $this->evaluate_conditions( $conditions, $context ) ) {
                continue;
            }

            $actions = json_decode( $rule['actions'] ?? '[]', true );
            if ( ! is_array( $actions ) ) {
                continue;
            }

            // Execute actions sequentially.
            $results = [];
            foreach ( array_slice( $actions, 0, self::MAX_ACTIONS ) as $action ) {
                $results[] = $this->execute_action( $action, $context );
            }

            // Update rule stats.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                [
                    'last_run'  => current_time( 'mysql' ),
                    'run_count' => (int) $rule['run_count'] + 1,
                ],
                [ 'id' => (int) $rule['id'] ],
                [ '%s', '%d' ],
                [ '%d' ]
            );

            // Log execution.
            $this->log_execution( (int) $rule['id'], $rule['name'], $trigger_hook, $results );
        }
    }

    /**
     * Evaluate an array of conditions against context.
     *
     * @param array $conditions Array of {field, op, value} arrays.
     * @param array $context    Context data.
     * @return bool True if all conditions pass (AND logic).
     */
    private function evaluate_conditions( array $conditions, array $context ): bool {
        if ( empty( $conditions ) ) {
            return true; // No conditions = always run.
        }

        foreach ( $conditions as $cond ) {
            if ( ! is_array( $cond ) ) {
                continue;
            }

            $field = $cond['field'] ?? '';
            $op    = $cond['op'] ?? '==';
            $value = $cond['value'] ?? '';

            $actual = $context[ $field ] ?? '';

            switch ( $op ) {
                case '==':
                    if ( (string) $actual !== (string) $value ) {
                        return false;
                    }
                    break;
                case '!=':
                    if ( (string) $actual === (string) $value ) {
                        return false;
                    }
                    break;
                case 'contains':
                    if ( strpos( (string) $actual, (string) $value ) === false ) {
                        return false;
                    }
                    break;
                case '>':
                    if ( (float) $actual <= (float) $value ) {
                        return false;
                    }
                    break;
                case '<':
                    if ( (float) $actual >= (float) $value ) {
                        return false;
                    }
                    break;
                default:
                    return false;
            }
        }

        return true;
    }

    /**
     * Execute a single action.
     *
     * @param array $action  Action definition {type, ...params}.
     * @param array $context Trigger context.
     * @return array Result with 'type', 'success', and 'message'.
     */
    private function execute_action( array $action, array $context ): array {
        $type = $action['type'] ?? '';

        switch ( $type ) {
            case 'send_email':
                return $this->action_send_email( $action, $context );
            case 'send_webhook':
                return $this->action_send_webhook( $action, $context );
            case 'set_meta':
                return $this->action_set_meta( $action, $context );
            case 'clear_caches':
                return $this->action_clear_caches();
            default:
                return [ 'type' => $type, 'success' => false, 'message' => 'Unknown action type.' ];
        }
    }

    /**
     * Send email action.
     */
    private function action_send_email( array $action, array $context ): array {
        $to      = sanitize_email( $this->replace_merge_tags( $action['to'] ?? '', $context ) );
        $subject = $this->replace_merge_tags( $action['subject'] ?? '', $context );
        $body    = $this->replace_merge_tags( $action['body'] ?? '', $context );

        if ( empty( $to ) || ! is_email( $to ) ) {
            return [ 'type' => 'send_email', 'success' => false, 'message' => 'Invalid email address.' ];
        }

        $sent = wp_mail( $to, $subject, $body );

        return [
            'type'    => 'send_email',
            'success' => $sent,
            'message' => $sent ? 'Email sent to ' . $to : 'Email failed.',
        ];
    }

    /**
     * Send webhook action.
     */
    private function action_send_webhook( array $action, array $context ): array {
        $url = $action['url'] ?? '';

        // Restrict to HTTP(S) schemes only — prevents SSRF via file://, gopher://, etc.
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( empty( $url ) || ! in_array( $scheme, [ 'http', 'https' ], true ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return [ 'type' => 'send_webhook', 'success' => false, 'message' => 'Invalid webhook URL.' ];
        }

        $payload = $action['payload'] ?? '';
        $body = ! empty( $payload )
            ? $this->replace_merge_tags( $payload, $context )
            : wp_json_encode( $context );

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'type' => 'send_webhook', 'success' => false, 'message' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $success = $code >= 200 && $code < 300;

        return [
            'type'    => 'send_webhook',
            'success' => $success,
            'message' => 'Webhook response: HTTP ' . $code,
        ];
    }

    /**
     * Set meta action on the triggering object.
     */
    private function action_set_meta( array $action, array $context ): array {
        $key   = sanitize_key( $action['meta_key'] ?? '' );
        $value = sanitize_text_field( $this->replace_merge_tags( $action['meta_value'] ?? '', $context ) );

        if ( empty( $key ) ) {
            return [ 'type' => 'set_meta', 'success' => false, 'message' => 'No meta key specified.' ];
        }

        $object_id   = (int) ( $context['object_id'] ?? 0 );
        $object_type = $context['object_type'] ?? '';

        if ( $object_id <= 0 ) {
            return [ 'type' => 'set_meta', 'success' => false, 'message' => 'No object ID in context.' ];
        }

        switch ( $object_type ) {
            case 'post':
                update_post_meta( $object_id, $key, $value );
                break;
            case 'user':
                update_user_meta( $object_id, $key, $value );
                break;
            case 'comment':
                update_comment_meta( $object_id, $key, $value );
                break;
            default:
                return [ 'type' => 'set_meta', 'success' => false, 'message' => 'Unsupported object type: ' . $object_type ];
        }

        return [ 'type' => 'set_meta', 'success' => true, 'message' => 'Meta set: ' . $key . ' = ' . $value ];
    }

    /**
     * Clear caches action.
     */
    private function action_clear_caches(): array {
        wp_cache_flush();

        // Clear known caching plugin caches.
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }

        return [ 'type' => 'clear_caches', 'success' => true, 'message' => 'Caches cleared.' ];
    }

    /**
     * Replace merge tags in a string with context values.
     *
     * Merge tags use the format {{field_name}}.
     *
     * @param string $text    Text with merge tags.
     * @param array  $context Context data.
     * @return string Text with tags replaced.
     */
    private function replace_merge_tags( string $text, array $context ): string {
        return (string) preg_replace_callback( '/\{\{(\w+)\}\}/', static function ( array $matches ) use ( $context ): string {
            $key = $matches[1];
            return isset( $context[ $key ] ) ? (string) $context[ $key ] : $matches[0];
        }, $text );
    }

    // =====================================================================
    //  EXECUTION LOG
    // =====================================================================

    /**
     * Log a rule execution.
     *
     * @param int    $rule_id   Rule ID.
     * @param string $rule_name Rule name.
     * @param string $trigger   Trigger hook.
     * @param array  $results   Action results.
     */
    private function log_execution( int $rule_id, string $rule_name, string $trigger, array $results ): void {
        $log = get_option( self::LOG_KEY, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        array_unshift( $log, [
            'rule_id'   => $rule_id,
            'rule_name' => $rule_name,
            'trigger'   => $trigger,
            'results'   => $results,
            'timestamp' => current_time( 'mysql' ),
        ] );

        // Keep only the last N entries.
        $log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );

        update_option( self::LOG_KEY, $log, false );
    }

    // =====================================================================
    //  AJAX HANDLERS
    // =====================================================================

    /**
     * Save (create or update) an automation rule.
     */
    public function ajax_save_rule(): void {
        check_ajax_referer( 'wpt_automation_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_automation_rules';

        $rule_id      = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
        $name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $trigger_hook = isset( $_POST['trigger_hook'] ) ? sanitize_key( wp_unslash( $_POST['trigger_hook'] ) ) : '';
        $conditions_raw = isset( $_POST['conditions'] ) ? wp_unslash( $_POST['conditions'] ) : '[]';
        $actions_raw    = isset( $_POST['actions'] ) ? wp_unslash( $_POST['actions'] ) : '[]';

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Rule name is required.', 'wptransformed' ) ] );
        }

        if ( ! isset( self::TRIGGERS[ $trigger_hook ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid trigger.', 'wptransformed' ) ] );
        }

        // Sanitize conditions.
        $conditions = json_decode( (string) $conditions_raw, true );
        if ( ! is_array( $conditions ) ) {
            $conditions = [];
        }
        $conditions = $this->sanitize_conditions( $conditions );

        // Sanitize actions.
        $actions = json_decode( (string) $actions_raw, true );
        if ( ! is_array( $actions ) ) {
            $actions = [];
        }
        $actions = $this->sanitize_actions( $actions );

        if ( empty( $actions ) ) {
            wp_send_json_error( [ 'message' => __( 'At least one action is required.', 'wptransformed' ) ] );
        }

        $data = [
            'name'         => $name,
            'trigger_hook' => $trigger_hook,
            'conditions'   => wp_json_encode( $conditions ),
            'actions'      => wp_json_encode( $actions ),
        ];
        $formats = [ '%s', '%s', '%s', '%s' ];

        if ( $rule_id > 0 ) {
            // Update existing rule.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update( $table, $data, [ 'id' => $rule_id ], $formats, [ '%d' ] );
        } else {
            // Insert new rule.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->insert( $table, $data, $formats );
            $rule_id = (int) $wpdb->insert_id;
        }

        if ( $result === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save rule.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [
            'message' => __( 'Rule saved.', 'wptransformed' ),
            'rule_id' => $rule_id,
        ] );
    }

    /**
     * Delete an automation rule.
     */
    public function ajax_delete_rule(): void {
        check_ajax_referer( 'wpt_automation_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_automation_rules';

        $rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
        if ( $rule_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid rule ID.', 'wptransformed' ) ] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete( $table, [ 'id' => $rule_id ], [ '%d' ] );

        if ( $result === false ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete rule.', 'wptransformed' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Rule deleted.', 'wptransformed' ) ] );
    }

    /**
     * Toggle a rule's active state.
     */
    public function ajax_toggle_rule(): void {
        check_ajax_referer( 'wpt_automation_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_automation_rules';

        $rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
        if ( $rule_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid rule ID.', 'wptransformed' ) ] );
        }

        // Get current state.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT is_active FROM {$table} WHERE id = %d", $rule_id )
        );

        if ( $current === null ) {
            wp_send_json_error( [ 'message' => __( 'Rule not found.', 'wptransformed' ) ] );
        }

        $new_state = (int) $current === 1 ? 0 : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, [ 'is_active' => $new_state ], [ 'id' => $rule_id ], [ '%d' ], [ '%d' ] );

        wp_send_json_success( [
            'message'   => $new_state ? __( 'Rule activated.', 'wptransformed' ) : __( 'Rule deactivated.', 'wptransformed' ),
            'is_active' => $new_state,
        ] );
    }

    /**
     * Get a single rule for editing.
     */
    public function ajax_get_rule(): void {
        check_ajax_referer( 'wpt_automation_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_automation_rules';

        $rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rule = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rule_id ),
            ARRAY_A
        );

        if ( ! $rule ) {
            wp_send_json_error( [ 'message' => __( 'Rule not found.', 'wptransformed' ) ] );
        }

        $rule['conditions'] = json_decode( $rule['conditions'] ?? '[]', true ) ?: [];
        $rule['actions']    = json_decode( $rule['actions'] ?? '[]', true ) ?: [];

        wp_send_json_success( $rule );
    }

    /**
     * Get the execution log.
     */
    public function ajax_get_log(): void {
        check_ajax_referer( 'wpt_automation_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $log = get_option( self::LOG_KEY, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        wp_send_json_success( $log );
    }

    // =====================================================================
    //  SANITIZATION HELPERS
    // =====================================================================

    /**
     * Sanitize conditions array.
     *
     * @param array $conditions Raw conditions.
     * @return array Sanitized conditions.
     */
    private function sanitize_conditions( array $conditions ): array {
        $clean = [];
        foreach ( $conditions as $cond ) {
            if ( ! is_array( $cond ) ) {
                continue;
            }
            $field = sanitize_key( $cond['field'] ?? '' );
            $op    = $cond['op'] ?? '==';
            $value = sanitize_text_field( $cond['value'] ?? '' );

            if ( empty( $field ) || ! isset( self::OPERATORS[ $op ] ) ) {
                continue;
            }

            $clean[] = [
                'field' => $field,
                'op'    => $op,
                'value' => $value,
            ];
        }
        return $clean;
    }

    /**
     * Sanitize actions array (max 3).
     *
     * @param array $actions Raw actions.
     * @return array Sanitized actions.
     */
    private function sanitize_actions( array $actions ): array {
        $clean = [];

        foreach ( array_slice( $actions, 0, self::MAX_ACTIONS ) as $action ) {
            if ( ! is_array( $action ) ) {
                continue;
            }

            $type = $action['type'] ?? '';
            if ( ! isset( self::ACTION_TYPES[ $type ] ) ) {
                continue;
            }

            $sanitized = [ 'type' => $type ];

            switch ( $type ) {
                case 'send_email':
                    $sanitized['to']      = sanitize_email( $action['to'] ?? '' );
                    $sanitized['subject'] = sanitize_text_field( $action['subject'] ?? '' );
                    $sanitized['body']    = sanitize_textarea_field( $action['body'] ?? '' );
                    break;

                case 'send_webhook':
                    $sanitized['url']     = esc_url_raw( $action['url'] ?? '' );
                    $sanitized['payload'] = sanitize_textarea_field( $action['payload'] ?? '' );
                    break;

                case 'set_meta':
                    $sanitized['meta_key']   = sanitize_key( $action['meta_key'] ?? '' );
                    $sanitized['meta_value'] = sanitize_text_field( $action['meta_value'] ?? '' );
                    break;

                case 'clear_caches':
                    // No additional params.
                    break;
            }

            $clean[] = $sanitized;
        }

        return $clean;
    }

    // =====================================================================
    //  RENDER SETTINGS
    // =====================================================================

    public function render_settings(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_automation_rules';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rules = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
        if ( ! is_array( $rules ) ) {
            $rules = [];
        }

        ?>
        <!-- Existing Rules -->
        <h3><?php esc_html_e( 'Automation Rules', 'wptransformed' ); ?>
            <button type="button" class="button button-primary" id="wpt-automation-add-rule" style="margin-left: 10px;">
                <?php esc_html_e( '+ Add Rule', 'wptransformed' ); ?>
            </button>
            <button type="button" class="button button-secondary" id="wpt-automation-view-log" style="margin-left: 5px;">
                <?php esc_html_e( 'View Log', 'wptransformed' ); ?>
            </button>
        </h3>

        <?php if ( empty( $rules ) ) : ?>
            <p class="description"><?php esc_html_e( 'No automation rules yet. Click "Add Rule" to create your first workflow.', 'wptransformed' ); ?></p>
        <?php else : ?>
        <table class="widefat striped" id="wpt-automation-rules-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Trigger', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Conditions', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Runs', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Last Run', 'wptransformed' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wptransformed' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rules as $rule ) :
                    $conditions = json_decode( $rule['conditions'] ?? '[]', true ) ?: [];
                    $actions    = json_decode( $rule['actions'] ?? '[]', true ) ?: [];
                    $trigger_label = self::TRIGGERS[ $rule['trigger_hook'] ] ?? $rule['trigger_hook'];
                ?>
                <tr data-rule-id="<?php echo esc_attr( (string) $rule['id'] ); ?>">
                    <td><strong><?php echo esc_html( $rule['name'] ); ?></strong></td>
                    <td><?php echo esc_html( $trigger_label ); ?></td>
                    <td><?php echo esc_html( (string) count( $conditions ) ); ?></td>
                    <td>
                        <?php
                        $action_labels = [];
                        foreach ( $actions as $a ) {
                            $action_labels[] = self::ACTION_TYPES[ $a['type'] ] ?? $a['type'];
                        }
                        echo esc_html( implode( ', ', $action_labels ) );
                        ?>
                    </td>
                    <td>
                        <button type="button" class="button button-small wpt-automation-toggle"
                                data-rule-id="<?php echo esc_attr( (string) $rule['id'] ); ?>">
                            <?php echo (int) $rule['is_active']
                                ? '<span style="color: green;">' . esc_html__( 'Active', 'wptransformed' ) . '</span>'
                                : '<span style="color: #999;">' . esc_html__( 'Inactive', 'wptransformed' ) . '</span>';
                            ?>
                        </button>
                    </td>
                    <td><?php echo esc_html( (string) $rule['run_count'] ); ?></td>
                    <td><?php echo esc_html( $rule['last_run'] ?? '—' ); ?></td>
                    <td>
                        <button type="button" class="button button-small wpt-automation-edit"
                                data-rule-id="<?php echo esc_attr( (string) $rule['id'] ); ?>">
                            <?php esc_html_e( 'Edit', 'wptransformed' ); ?>
                        </button>
                        <button type="button" class="button button-small button-link-delete wpt-automation-delete"
                                data-rule-id="<?php echo esc_attr( (string) $rule['id'] ); ?>">
                            <?php esc_html_e( 'Delete', 'wptransformed' ); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Rule Editor Modal (hidden by default) -->
        <div id="wpt-automation-editor" style="display: none; background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px; border-radius: 4px;">
            <h3 id="wpt-automation-editor-title"><?php esc_html_e( 'New Automation Rule', 'wptransformed' ); ?></h3>
            <input type="hidden" id="wpt-automation-rule-id" value="0">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="wpt-automation-name"><?php esc_html_e( 'Rule Name', 'wptransformed' ); ?></label></th>
                    <td><input type="text" id="wpt-automation-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Notify admin on new post', 'wptransformed' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpt-automation-trigger"><?php esc_html_e( 'When', 'wptransformed' ); ?></label></th>
                    <td>
                        <select id="wpt-automation-trigger">
                            <?php foreach ( self::TRIGGERS as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <!-- Conditions -->
            <h4><?php esc_html_e( 'And (Conditions)', 'wptransformed' ); ?></h4>
            <p class="description"><?php esc_html_e( 'All conditions must be true for the actions to execute.', 'wptransformed' ); ?></p>
            <div id="wpt-automation-conditions"></div>
            <button type="button" class="button button-secondary" id="wpt-automation-add-condition">
                <?php esc_html_e( '+ Add Condition', 'wptransformed' ); ?>
            </button>

            <!-- Actions -->
            <h4 style="margin-top: 15px;"><?php esc_html_e( 'Then (Actions)', 'wptransformed' ); ?></h4>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: max actions */
                    esc_html__( 'Maximum %d actions per rule. Executed sequentially.', 'wptransformed' ),
                    self::MAX_ACTIONS
                );
                ?>
            </p>
            <div id="wpt-automation-actions"></div>
            <button type="button" class="button button-secondary" id="wpt-automation-add-action">
                <?php esc_html_e( '+ Add Action', 'wptransformed' ); ?>
            </button>

            <!-- Save/Cancel -->
            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                <button type="button" class="button button-primary" id="wpt-automation-save-rule">
                    <?php esc_html_e( 'Save Rule', 'wptransformed' ); ?>
                </button>
                <button type="button" class="button button-secondary" id="wpt-automation-cancel">
                    <?php esc_html_e( 'Cancel', 'wptransformed' ); ?>
                </button>
                <span class="spinner" id="wpt-automation-spinner" style="float: none;"></span>
            </div>
        </div>

        <!-- Log Modal (hidden by default) -->
        <div id="wpt-automation-log-view" style="display: none; background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px; border-radius: 4px;">
            <h3><?php esc_html_e( 'Execution Log (Last 50)', 'wptransformed' ); ?>
                <button type="button" class="button button-secondary" id="wpt-automation-close-log" style="margin-left: 10px;">
                    <?php esc_html_e( 'Close', 'wptransformed' ); ?>
                </button>
            </h3>
            <div id="wpt-automation-log-entries"></div>
        </div>

        <!-- Templates for JS: condition/action row HTML -->
        <script type="text/template" id="wpt-tmpl-condition-row">
            <div class="wpt-automation-condition-row" style="display: flex; gap: 5px; margin-bottom: 5px; align-items: center;">
                <input type="text" class="wpt-cond-field" placeholder="<?php esc_attr_e( 'field (e.g., post_type)', 'wptransformed' ); ?>" style="width: 150px;">
                <select class="wpt-cond-op">
                    <?php foreach ( self::OPERATORS as $op => $label ) : ?>
                        <option value="<?php echo esc_attr( $op ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="wpt-cond-value" placeholder="<?php esc_attr_e( 'value', 'wptransformed' ); ?>" style="width: 150px;">
                <button type="button" class="button button-small button-link-delete wpt-remove-row">&times;</button>
            </div>
        </script>

        <script type="text/template" id="wpt-tmpl-action-row">
            <div class="wpt-automation-action-row" style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 3px; background: #f9f9f9;">
                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 8px;">
                    <select class="wpt-action-type" style="min-width: 150px;">
                        <?php foreach ( self::ACTION_TYPES as $type => $label ) : ?>
                            <option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button button-small button-link-delete wpt-remove-row">&times;</button>
                </div>
                <div class="wpt-action-fields"></div>
            </div>
        </script>
        <?php
    }

    // -- Sanitize Settings ------------------------------------------------

    public function sanitize_settings( array $raw ): array {
        // No module-level settings -- rules are in the DB table.
        return [];
    }

    // -- Assets -----------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'wptransformed' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'wpt-workflow-automation',
            WPT_URL . 'modules/utilities/js/workflow-automation.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-workflow-automation', 'wptWorkflowAutomation', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'wpt_automation_nonce' ),
            'maxActions'  => self::MAX_ACTIONS,
            'actionTypes' => self::ACTION_TYPES,
            'i18n'        => [
                'confirmDelete' => __( 'Are you sure you want to delete this rule?', 'wptransformed' ),
                'saving'        => __( 'Saving...', 'wptransformed' ),
                'saved'         => __( 'Rule saved. Reloading...', 'wptransformed' ),
                'networkError'  => __( 'Network error. Please try again.', 'wptransformed' ),
                'noActions'     => __( 'Please add at least one action.', 'wptransformed' ),
                'nameRequired'  => __( 'Rule name is required.', 'wptransformed' ),
                'noLogEntries'  => __( 'No log entries yet.', 'wptransformed' ),
                'editRule'      => __( 'Edit Automation Rule', 'wptransformed' ),
                'newRule'       => __( 'New Automation Rule', 'wptransformed' ),
                'emailTo'       => __( 'To Email', 'wptransformed' ),
                'emailSubject'  => __( 'Subject', 'wptransformed' ),
                'emailBody'     => __( 'Body (supports {{merge_tags}})', 'wptransformed' ),
                'webhookUrl'    => __( 'Webhook URL', 'wptransformed' ),
                'webhookPayload' => __( 'Payload JSON (optional, supports {{merge_tags}})', 'wptransformed' ),
                'metaKey'       => __( 'Meta Key', 'wptransformed' ),
                'metaValue'     => __( 'Meta Value (supports {{merge_tags}})', 'wptransformed' ),
            ],
        ] );
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [
            'options' => [ self::DB_VERSION_KEY, self::LOG_KEY ],
            'tables'  => [ 'wpt_automation_rules' ],
        ];
    }
}
