<?php
declare(strict_types=1);

namespace WPTransformed\Modules\CustomCode;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Ads.txt Manager -- Manage ads.txt and app-ads.txt content from the admin.
 *
 * @package WPTransformed
 */
class Ads_Txt_Manager extends Module_Base {

    // -- Identity --

    public function get_id(): string {
        return 'ads-txt-manager';
    }

    public function get_title(): string {
        return __( 'Ads.txt Manager', 'wptransformed' );
    }

    public function get_category(): string {
        return 'custom-code';
    }

    public function get_description(): string {
        return __( 'Manage ads.txt and app-ads.txt content directly from the WordPress admin.', 'wptransformed' );
    }

    // -- Settings --

    public function get_default_settings(): array {
        return [
            'ads_txt'     => '',
            'app_ads_txt' => '',
        ];
    }

    // -- Lifecycle --

    public function init(): void {
        add_action( 'init', [ $this, 'maybe_serve_ads_txt' ], 0 );
    }

    // -- Serve Ads.txt --

    /**
     * Intercept requests for /ads.txt and /app-ads.txt.
     */
    public function maybe_serve_ads_txt(): void {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }

        $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

        // Strip query string
        $path = strtok( $request_uri, '?' );
        if ( $path === false ) {
            return;
        }

        // Normalize trailing slashes
        $path = rtrim( $path, '/' );

        if ( $path !== '/ads.txt' && $path !== '/app-ads.txt' ) {
            return;
        }

        $settings = $this->get_settings();

        if ( $path === '/ads.txt' && ! empty( $settings['ads_txt'] ) ) {
            $this->serve_text_content( $settings['ads_txt'] );
        } elseif ( $path === '/app-ads.txt' && ! empty( $settings['app_ads_txt'] ) ) {
            $this->serve_text_content( $settings['app_ads_txt'] );
        }
    }

    /**
     * Output plain text content and exit.
     *
     * @param string $content The text content to serve.
     */
    private function serve_text_content( string $content ): void {
        // Ensure no other headers are sent
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'X-Robots-Tag: noindex' );
            nocache_headers();
        }

        echo $content; // Already sanitized on save; plain text output
        exit;
    }

    // -- Settings UI --

    public function render_settings(): void {
        $settings = $this->get_settings();

        // Check for physical files
        $has_physical_ads_txt     = file_exists( ABSPATH . 'ads.txt' );
        $has_physical_app_ads_txt = file_exists( ABSPATH . 'app-ads.txt' );

        ?>
        <table class="form-table" role="presentation">

            <?php if ( $has_physical_ads_txt ) : ?>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <div class="notice notice-warning inline" style="margin: 0;">
                            <p>
                                <?php esc_html_e( 'A physical ads.txt file exists in your WordPress root directory. It will take priority over the content managed here. Delete or rename it to use this module.', 'wptransformed' ); ?>
                            </p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>

            <tr>
                <th scope="row">
                    <label for="wpt_ads_txt"><?php esc_html_e( 'ads.txt Content', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt_ads_txt"
                              name="wpt_ads_txt"
                              rows="10"
                              class="large-text code"
                              placeholder="<?php esc_attr_e( 'google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0', 'wptransformed' ); ?>"><?php echo esc_textarea( $settings['ads_txt'] ); ?></textarea>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: URL to ads.txt */
                            esc_html__( 'This content will be served at %s.', 'wptransformed' ),
                            '<code>' . esc_html( home_url( '/ads.txt' ) ) . '</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <?php if ( $has_physical_app_ads_txt ) : ?>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <div class="notice notice-warning inline" style="margin: 0;">
                            <p>
                                <?php esc_html_e( 'A physical app-ads.txt file exists in your WordPress root directory. It will take priority over the content managed here. Delete or rename it to use this module.', 'wptransformed' ); ?>
                            </p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>

            <tr>
                <th scope="row">
                    <label for="wpt_app_ads_txt"><?php esc_html_e( 'app-ads.txt Content', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt_app_ads_txt"
                              name="wpt_app_ads_txt"
                              rows="10"
                              class="large-text code"
                              placeholder="<?php esc_attr_e( 'google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0', 'wptransformed' ); ?>"><?php echo esc_textarea( $settings['app_ads_txt'] ); ?></textarea>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: URL to app-ads.txt */
                            esc_html__( 'This content will be served at %s.', 'wptransformed' ),
                            '<code>' . esc_html( home_url( '/app-ads.txt' ) ) . '</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>

        </table>
        <?php
    }

    // -- Sanitize --

    public function sanitize_settings( array $raw ): array {
        return [
            'ads_txt'     => $this->sanitize_ads_txt( $raw['wpt_ads_txt'] ?? '' ),
            'app_ads_txt' => $this->sanitize_ads_txt( $raw['wpt_app_ads_txt'] ?? '' ),
        ];
    }

    /**
     * Sanitize ads.txt content.
     * Allow only valid lines: comments (#), variables, records, and blank lines.
     *
     * @param string $content Raw content.
     * @return string
     */
    private function sanitize_ads_txt( string $content ): string {
        $content = wp_unslash( $content );
        $lines   = explode( "\n", $content );
        $clean   = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );

            // Allow blank lines
            if ( $line === '' ) {
                $clean[] = '';
                continue;
            }

            // Allow comments
            if ( str_starts_with( $line, '#' ) ) {
                $clean[] = sanitize_text_field( $line );
                continue;
            }

            // Allow variable declarations (e.g., contact=email@example.com)
            if ( preg_match( '/^[a-zA-Z]+\s*=\s*.+$/', $line ) ) {
                $clean[] = sanitize_text_field( $line );
                continue;
            }

            // Allow standard ads.txt records (domain, account-id, type[, cert-authority])
            if ( preg_match( '/^[a-zA-Z0-9\.\-]+\s*,\s*[^\s,]+\s*,\s*(DIRECT|RESELLER)/i', $line ) ) {
                $clean[] = sanitize_text_field( $line );
                continue;
            }

            // Skip invalid lines silently
        }

        return implode( "\n", $clean );
    }

    // -- Cleanup --

    public function get_cleanup_tasks(): array {
        return [];
    }
}
