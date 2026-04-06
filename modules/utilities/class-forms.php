<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Utilities;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Forms
 *
 * Features:
 *  - Visual form builder
 *  - Shortcode and block output
 *  - Email notifications
 *  - Honeypot anti-spam
 *  - Webhook on submission
 *  - Upgrade prompt for WPBuiltRight Forms Pro (multi-step, conditional logic, payments)
 *
 * @package WPTransformed
 */
class Forms extends Module_Base {

	public function get_id(): string {
		return 'forms';
	}

	public function get_title(): string {
		return __( 'Forms', 'wptransformed' );
	}

	public function get_category(): string {
		return 'utilities';
	}

	public function get_description(): string {
		return __( 'Drag-and-drop form builder with email notifications, honeypot spam protection, and webhook support.', 'wptransformed' );
	}

	public function init(): void {
		// TODO: Implement.
	}

	public function get_default_settings(): array {
		return [];
	}

	public function render_settings(): void {
		// TODO: Implement settings UI.
	}

	public function sanitize_settings( array $raw ): array {
		return [];
	}
}
