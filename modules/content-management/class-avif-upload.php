<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * AVIF Upload — Allow AVIF image uploads to the media library.
 *
 * Adds AVIF MIME type support and warns when the server lacks
 * GD AVIF support (imageavif function).
 *
 * @package WPTransformed
 */
class Avif_Upload extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'avif-upload';
    }

    public function get_title(): string {
        return __( 'AVIF Upload', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Allow AVIF image uploads to the media library.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        add_filter( 'upload_mimes', [ $this, 'add_avif_mime_type' ] );
        add_filter( 'wp_check_filetype_and_ext', [ $this, 'fix_avif_filetype' ], 10, 4 );
    }

    // ── Upload Filters ────────────────────────────────────────

    /**
     * Add AVIF MIME type to allowed uploads.
     *
     * @param array<string,string> $mimes Allowed MIME types.
     * @return array<string,string>
     */
    public function add_avif_mime_type( array $mimes ): array {
        $mimes['avif'] = 'image/avif';
        return $mimes;
    }

    /**
     * Fix WordPress file type detection for AVIF files.
     *
     * finfo may not reliably detect AVIF MIME types on all servers.
     *
     * @param array{ext:string|false,type:string|false,proper_filename:string|false} $data
     * @param string      $file     Full path to the file.
     * @param string      $filename Name of the file.
     * @param string[]|null $mimes  Allowed MIME types.
     * @return array{ext:string|false,type:string|false,proper_filename:string|false}
     */
    public function fix_avif_filetype( array $data, string $file, string $filename, $mimes ): array {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( $ext === 'avif' ) {
            $data['ext']  = 'avif';
            $data['type'] = 'image/avif';
        }

        return $data;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings       = $this->get_settings();
        $has_gd_support = function_exists( 'imageavif' );
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Enable AVIF Uploads', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_enabled" value="1"
                               <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        <?php esc_html_e( 'Allow AVIF image files to be uploaded to the media library.', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Server Support', 'wptransformed' ); ?></th>
                <td>
                    <?php if ( $has_gd_support ) : ?>
                        <span style="color: #00a32a;">&#10003; <?php esc_html_e( 'Your server supports AVIF image processing (GD imageavif).', 'wptransformed' ); ?></span>
                    <?php else : ?>
                        <span style="color: #d63638;">&#10007; <?php esc_html_e( 'Your server does not have GD AVIF support (imageavif function not found). AVIF uploads will work but WordPress cannot generate thumbnails or edit AVIF images.', 'wptransformed' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => ! empty( $raw['wpt_enabled'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
