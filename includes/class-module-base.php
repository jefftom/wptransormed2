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

    /**
     * Declare which settings keys hold secrets (slice 10b contract —
     * checkpoint §16.3). The REST controller, not modules, applies the
     * redaction: declared keys never leave over REST (GET masks them
     * with '' or the literal sentinel __WPT_SECRET__), and POSTs carrying
     * the sentinel (or omitting the key) splice the stored value back in
     * unchanged. A literal secret VALUE of __WPT_SECRET__ is unsupported
     * by design — the token is reserved to mean keep-existing.
     *
     * @return string[] Storage-shape keys holding secret values.
     */
    public function get_secret_settings_keys(): array {
        return [];
    }

    // -- Admin UI --
    public function render_settings(): void {}

    public function sanitize_settings( array $raw ): array {
        return [];
    }

    /**
     * Validate STORAGE-SHAPE settings (slice 10a contract — checkpoint
     * §16.2). Input and output are both storage shape.
     *
     * Distinct from sanitize_settings(), which maps RAW FORM input
     * (wpt_* field names) to storage shape and keeps serving the
     * existing form save paths. Feeding storage-shape data to
     * sanitize_settings() silently returns defaults — REST and import
     * surfaces must use THIS method instead.
     *
     * Base implementation is a whitelist + type floor:
     * - input keys are whitelisted to get_default_settings() keys;
     *   unknown keys are dropped
     * - scalar defaults coerce the input to the default's PHP type
     *   (bool/int/float/string casts)
     * - array/non-array mismatches (either direction) fall back to the
     *   default value for that key
     * - missing keys fall back to the default value — the output always
     *   contains exactly the default-settings keys
     *
     * SECURITY: this floor is NOT a ceiling. Modules whose settings can
     * enable dangerous behavior (anything that turns on PHP snippet
     * execution, code output, auth/login changes, …) MUST override with
     * real validation — import will eventually feed this method
     * attacker-influencable export files, so a type-correct boolean
     * that flips on snippet execution is still a hostile payload.
     *
     * @param array $settings Storage-shape settings (untrusted).
     * @return array Validated storage-shape settings.
     */
    public function validate_settings( array $settings ): array {
        $out = [];

        foreach ( $this->get_default_settings() as $key => $default ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                $out[ $key ] = $default;
                continue;
            }

            $value = $settings[ $key ];

            if ( is_array( $default ) || is_array( $value ) ) {
                // Array/non-array mismatches fall back to the default.
                $out[ $key ] = ( is_array( $default ) && is_array( $value ) ) ? $value : $default;
            } elseif ( is_bool( $default ) ) {
                $out[ $key ] = (bool) $value;
            } elseif ( is_int( $default ) ) {
                $out[ $key ] = (int) $value;
            } elseif ( is_float( $default ) ) {
                $out[ $key ] = (float) $value;
            } elseif ( is_string( $default ) ) {
                $out[ $key ] = (string) $value;
            } else {
                // Unsupported default types (null, objects) intentionally
                // fall back to the default — no safe coercion exists.
                $out[ $key ] = $default;
            }
        }

        return $out;
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
