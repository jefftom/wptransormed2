<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable Attachment Pages — Redirect attachment pages instead of showing them.
 *
 * When a visitor hits an attachment page, redirect them based on the
 * configured setting: parent post, file URL, home page, or 404.
 *
 * @package WPTransformed
 */
class Disable_Attachment_Pages extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-attachment-pages';
    }

    public function get_title(): string {
        return __( 'Disable Attachment Pages', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Redirect attachment pages to the parent post, file URL, home page, or show a 404.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'redirect_to' => 'parent',
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        add_action( 'template_redirect', [ $this, 'handle_attachment_redirect' ] );
    }

    /**
     * Redirect attachment pages based on the configured setting.
     */
    public function handle_attachment_redirect(): void {
        if ( ! is_attachment() ) {
            return;
        }

        $settings    = $this->get_settings();
        $redirect_to = $settings['redirect_to'];

        // Handle 404.
        if ( '404' === $redirect_to ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }

        $post = get_queried_object();
        $url  = '';

        switch ( $redirect_to ) {
            case 'parent':
                if ( $post instanceof \WP_Post && $post->post_parent > 0 ) {
                    $url = get_permalink( $post->post_parent );
                }
                break;

            case 'file':
                if ( $post instanceof \WP_Post ) {
                    $url = wp_get_attachment_url( $post->ID );
                }
                break;

            case 'home':
            default:
                $url = home_url( '/' );
                break;
        }

        // Fallback: if parent/file unavailable, go home.
        if ( empty( $url ) && '404' !== $redirect_to ) {
            $url = home_url( '/' );
        }

        if ( ! empty( $url ) ) {
            wp_safe_redirect( esc_url_raw( $url ), 301 );
            exit;
        }
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();

        $options = [
            'parent' => __( 'Parent post (falls back to home)', 'wptransformed' ),
            'file'   => __( 'Attachment file URL (falls back to home)', 'wptransformed' ),
            'home'   => __( 'Home page', 'wptransformed' ),
            '404'    => __( '404 Not Found', 'wptransformed' ),
        ];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Redirect To', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $options as $value => $label ) : ?>
                            <label>
                                <input type="radio"
                                       name="wpt_redirect_to"
                                       value="<?php echo esc_attr( $value ); ?>"
                                       <?php checked( $settings['redirect_to'], $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'When a visitor navigates to an attachment page, redirect them to the selected destination.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_targets = [ 'parent', 'file', 'home', '404' ];

        return [
            'redirect_to' => in_array( $raw['wpt_redirect_to'] ?? '', $valid_targets, true )
                              ? $raw['wpt_redirect_to'] : 'parent',
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
