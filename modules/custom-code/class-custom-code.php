<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Code — Consolidated module.
 *
 * Combines:
 *  - Custom Admin CSS
 *  - Custom Frontend CSS
 *  - Custom Frontend Code
 *  - Custom Body Class
 *  - Ads.txt Manager
 *  - Robots.txt Manager
 *
 * @package WPTransformed
 */
class Custom_Code extends Module_Base {

	public function get_id(): string {
		return 'custom-code';
	}

	public function get_title(): string {
		return __( 'Custom Code', 'wptransformed' );
	}

	public function get_category(): string {
		return 'custom-code';
	}

	public function get_description(): string {
		return __( 'Add custom CSS, JavaScript, and HTML to admin or frontend. Manage ads.txt and robots.txt files.', 'wptransformed' );
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
