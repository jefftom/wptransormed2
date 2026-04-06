<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Frontend Features — Consolidated module.
 *
 * Combines:
 *  - Disable Feeds
 *  - Disable Embeds
 *  - Disable Emojis
 *  - Disable Self Pingbacks
 *  - Disable Attachment Pages
 *  - Disable Author Archives
 *
 * @package WPTransformed
 */
class Disable_Frontend extends Module_Base {

	public function get_id(): string {
		return 'disable-frontend';
	}

	public function get_title(): string {
		return __( 'Disable Frontend Features', 'wptransformed' );
	}

	public function get_category(): string {
		return 'disable-components';
	}

	public function get_description(): string {
		return __( 'Disable RSS feeds, oEmbed, emojis, self-pingbacks, attachment pages, and author archives.', 'wptransformed' );
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
