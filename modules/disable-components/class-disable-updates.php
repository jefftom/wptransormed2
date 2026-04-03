<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Updates — Control automatic and manual WordPress updates.
 *
 * Allows granular control over core, plugin, theme, and translation
 * auto-updates as well as blocking update checks entirely. Shows a
 * security warning when core updates are fully disabled.
 *
 * @package WPTransformed
 */
class Disable_Updates extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-updates';
    }

    public function get_title(): string {
        return __( 'Disable Updates', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Control automatic and manual update checks for core, plugins, themes, and translations.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'disable_core'               => false,
            'disable_plugins'            => false,
            'disable_themes'             => false,
            'disable_auto_core'          => true,
            'disable_auto_plugins'       => true,
            'disable_auto_themes'        => true,
            'disable_translation_updates' => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        // ── Auto-update filters ──────────────────────────────
        if ( ! empty( $settings['disable_auto_core'] ) ) {
            add_filter( 'auto_update_core', '__return_false' );
            add_filter( 'allow_major_auto_core_updates', '__return_false' );
            add_filter( 'allow_minor_auto_core_updates', '__return_false' );
        }

        if ( ! empty( $settings['disable_auto_plugins'] ) ) {
            add_filter( 'auto_update_plugin', '__return_false' );
        }

        if ( ! empty( $settings['disable_auto_themes'] ) ) {
            add_filter( 'auto_update_theme', '__return_false' );
        }

        if ( ! empty( $settings['disable_translation_updates'] ) ) {
            add_filter( 'auto_update_translation', '__return_false' );
        }

        // ── Block update checks entirely ─────────────────────
        if ( ! empty( $settings['disable_core'] ) ) {
            add_filter( 'pre_site_transient_update_core', [ $this, 'block_update_check' ] );
            remove_action( 'admin_notices', 'update_nag', 3 );
            remove_action( 'network_admin_notices', 'update_nag', 3 );
        }

        if ( ! empty( $settings['disable_plugins'] ) ) {
            add_filter( 'pre_site_transient_update_plugins', [ $this, 'block_update_check' ] );
        }

        if ( ! empty( $settings['disable_themes'] ) ) {
            add_filter( 'pre_site_transient_update_themes', [ $this, 'block_update_check' ] );
        }

        if ( ! empty( $settings['disable_core'] ) && is_admin() ) {
            add_action( 'admin_notices', [ $this, 'show_security_warning' ] );
        }
    }

    // ── Callbacks ─────────────────────────────────────────────

    /**
     * Block update check by returning an empty object.
     *
     * @return object
     */
    public function block_update_check(): object {
        return (object) [
            'last_checked'    => time(),
            'version_checked' => get_bloginfo( 'version' ),
            'updates'         => [],
            'translations'    => [],
            'response'        => [],
            'no_update'       => [],
        ];
    }

    /**
     * Display a security warning when core updates are disabled.
     */
    public function show_security_warning(): void {
        // Only show to users who can manage options.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'WPTransformed:', 'wptransformed' ); ?></strong>
                <?php esc_html_e( 'WordPress core update checks are disabled. Your site will not receive security patches automatically. Enable core updates when possible.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        ?>

        <?php if ( ! empty( $settings['disable_core'] ) ) : ?>
        <div style="background: #fff; border: 1px solid #ddd; border-left: 4px solid #dba617; border-radius: 4px; padding: 12px 16px; margin-bottom: 16px;">
            <p style="margin: 0;">
                <strong><?php esc_html_e( 'Security Warning:', 'wptransformed' ); ?></strong>
                <?php esc_html_e( 'Core update checks are disabled. Your site will not be notified of security patches.', 'wptransformed' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Update Checks', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_core" value="1"
                                   <?php checked( ! empty( $settings['disable_core'] ) ); ?>>
                            <?php esc_html_e( 'Core updates', 'wptransformed' ); ?>
                            <span style="color: #d63638; margin-left: 4px;"><?php esc_html_e( '(not recommended)', 'wptransformed' ); ?></span>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_plugins" value="1"
                                   <?php checked( ! empty( $settings['disable_plugins'] ) ); ?>>
                            <?php esc_html_e( 'Plugin updates', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_themes" value="1"
                                   <?php checked( ! empty( $settings['disable_themes'] ) ); ?>>
                            <?php esc_html_e( 'Theme updates', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Completely block update checks. The update badge will disappear from the admin.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Auto-Updates', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_auto_core" value="1"
                                   <?php checked( ! empty( $settings['disable_auto_core'] ) ); ?>>
                            <?php esc_html_e( 'Core auto-updates', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_auto_plugins" value="1"
                                   <?php checked( ! empty( $settings['disable_auto_plugins'] ) ); ?>>
                            <?php esc_html_e( 'Plugin auto-updates', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_auto_themes" value="1"
                                   <?php checked( ! empty( $settings['disable_auto_themes'] ) ); ?>>
                            <?php esc_html_e( 'Theme auto-updates', 'wptransformed' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wpt_disable_translation_updates" value="1"
                                   <?php checked( ! empty( $settings['disable_translation_updates'] ) ); ?>>
                            <?php esc_html_e( 'Translation auto-updates', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Prevent WordPress from automatically installing updates. You can still update manually.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'disable_core'               => ! empty( $raw['wpt_disable_core'] ),
            'disable_plugins'            => ! empty( $raw['wpt_disable_plugins'] ),
            'disable_themes'             => ! empty( $raw['wpt_disable_themes'] ),
            'disable_auto_core'          => ! empty( $raw['wpt_disable_auto_core'] ),
            'disable_auto_plugins'       => ! empty( $raw['wpt_disable_auto_plugins'] ),
            'disable_auto_themes'        => ! empty( $raw['wpt_disable_auto_themes'] ),
            'disable_translation_updates' => ! empty( $raw['wpt_disable_translation_updates'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
