<?php
declare(strict_types=1);

namespace WPTransformed\Modules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Module Base Class — The contract every module implements.
 *
 * @package WPTransformed
 */
abstract class Module_Base {

    // -- Identity --
    abstract public function get_id(): string;
    abstract public function get_title(): string;
    abstract public function get_category(): string;
    abstract public function get_description(): string;

    public function get_tier(): string {
        return 'free';
    }

    // -- Lifecycle --
    abstract public function init(): void;

    public function deactivate(): void {}

    // -- Settings --
    public function get_default_settings(): array {
        return [];
    }

    final public function get_settings(): array {
        $saved = \WPTransformed\Core\Settings::get( $this->get_id() );
        return wp_parse_args( $saved, $this->get_default_settings() );
    }

    // -- Admin UI --
    public function render_settings(): void {}

    public function sanitize_settings( array $raw ): array {
        return [];
    }

    // -- Assets --
    public function enqueue_admin_assets( string $hook ): void {}

    public function enqueue_frontend_assets(): void {}

    // -- Dependencies --
    public function get_dependencies(): array {
        return [];
    }

    // -- Uninstall Cleanup --
    public function get_cleanup_tasks(): array {
        return [];
    }
}
