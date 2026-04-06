<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Backend Features — Consolidated module.
 *
 * Combines:
 *  - Disable XML-RPC
 *  - Disable REST API
 *  - Disable REST Fields
 *  - Disable Updates
 *
 * @package WPTransformed
 */
class Disable_Backend extends Module_Base {

	public function get_id(): string {
		return 'disable-backend';
	}

	public function get_title(): string {
		return __( 'Disable Backend Features', 'wptransformed' );
	}

	public function get_category(): string {
		return 'disable-components';
	}

	public function get_description(): string {
		return __( 'Disable XML-RPC, REST API access, REST API fields, and update notifications.', 'wptransformed' );
	}

	public function init(): void {
		// TODO: Implement consolidated features.
	}

	public function get_default_settings(): array {
		return [];
	}

	public function render_settings(): void {
		// TODO: Implement tabbed settings UI for all consolidated features.
	}

	public function sanitize_settings( array $raw ): array {
		return [];
	}
}
