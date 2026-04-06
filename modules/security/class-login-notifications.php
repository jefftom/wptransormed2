<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Login Notifications
 *
 * Features:
 *  - Email on new IP/device login
 *  - Configurable per-role
 *  - "Not you?" link with instant lockout
 *  - Device fingerprinting
 *  - Known device tracking
 *
 * @package WPTransformed
 */
class Login_Notifications extends Module_Base {

	public function get_id(): string {
		return 'login-notifications';
	}

	public function get_title(): string {
		return __( 'Login Notifications', 'wptransformed' );
	}

	public function get_category(): string {
		return 'security';
	}

	public function get_description(): string {
		return __( 'Email users when their account logs in from a new IP or device with instant lockout option.', 'wptransformed' );
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
