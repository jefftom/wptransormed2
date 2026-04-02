<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Email Obfuscator — Protect email addresses from spam harvesters.
 *
 * Features:
 *  - Regex-based email detection in content, widgets, and comments
 *  - ROT13-encoded data attributes decoded by lightweight JS
 *  - Automatic mailto link creation on decode
 *  - RSS fallback using [at]/[dot] replacement
 *  - Skips admin pages and input fields
 *  - Filters: the_content, widget_text, comment_text
 *
 * @package WPTransformed
 */
class Email_Obfuscator extends Module_Base {

    /**
     * Whether the decoder JS needs to be output in the footer.
     */
    private bool $needs_decoder = false;

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'email-obfuscator';
    }

    public function get_title(): string {
        return __( 'Email Obfuscator', 'wptransformed' );
    }

    public function get_category(): string {
        return 'security';
    }

    public function get_description(): string {
        return __( 'Protect email addresses from spam harvesters by encoding them in page content.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'method'           => 'js_decode',
            'protect_mailto'   => true,
            'protect_plaintext' => true,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Never run on admin pages.
        if ( is_admin() ) {
            return;
        }

        // Filter content at low priority so other filters run first.
        add_filter( 'the_content', [ $this, 'obfuscate_emails' ], 999 );
        add_filter( 'widget_text', [ $this, 'obfuscate_emails' ], 999 );
        add_filter( 'comment_text', [ $this, 'obfuscate_emails' ], 999 );

        // Output decoder JS in footer when needed.
        add_action( 'wp_footer', [ $this, 'output_decoder_js' ], 999 );
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Find and obfuscate email addresses in content.
     *
     * @param string $content The content to filter.
     * @return string Filtered content.
     */
    public function obfuscate_emails( $content ): string {
        if ( ! is_string( $content ) || $content === '' ) {
            return is_string( $content ) ? $content : '';
        }

        // RSS feeds get simple text replacement.
        if ( is_feed() ) {
            return $this->obfuscate_for_rss( $content );
        }

        $settings = $this->get_settings();

        // Replace mailto: links first (before plaintext detection).
        if ( ! empty( $settings['protect_mailto'] ) ) {
            $content = $this->replace_mailto_links( $content );
        }

        // Replace plaintext emails (not inside HTML tags/attributes).
        if ( ! empty( $settings['protect_plaintext'] ) ) {
            $content = $this->replace_plaintext_emails( $content );
        }

        return $content;
    }

    /**
     * Output the decoder JavaScript in the footer if needed.
     */
    public function output_decoder_js(): void {
        if ( ! $this->needs_decoder ) {
            return;
        }

        ?>
<script>
(function(){
    function rot13(s){
        return s.replace(/[a-zA-Z]/g,function(c){
            return String.fromCharCode((c<='Z'?90:122)>=(c=c.charCodeAt(0)+13)?c:c-26);
        });
    }
    var els=document.querySelectorAll('[data-wpt-email]');
    for(var i=0;i<els.length;i++){
        var el=els[i];
        var email=rot13(el.getAttribute('data-wpt-email'));
        var a=document.createElement('a');
        a.href='mailto:'+email;
        a.textContent=email;
        if(el.className){a.className=el.className;}
        el.parentNode.replaceChild(a,el);
    }
})();
</script>
        <?php
    }

    // ── Email Replacement ─────────────────────────────────────

    /**
     * Replace mailto: anchor tags with obfuscated spans.
     *
     * @param string $content HTML content.
     * @return string Content with mailto links replaced.
     */
    private function replace_mailto_links( string $content ): string {
        // Match <a ... href="mailto:email@example.com" ...>text</a>
        $pattern = '/<a\b([^>]*?)href\s*=\s*["\']mailto:([^"\'?]+)[^"\']*["\']([^>]*)>(.*?)<\/a>/is';

        return (string) preg_replace_callback( $pattern, function ( array $matches ): string {
            $encoded = str_rot13( $matches[2] );

            $this->needs_decoder = true;

            return '<span data-wpt-email="' . esc_attr( $encoded ) . '">' . esc_html( '[email protected]' ) . '</span>';
        }, $content );
    }

    /**
     * Replace plaintext email addresses with obfuscated spans.
     * Skips emails inside HTML tag attributes (href, value, src, etc.).
     *
     * @param string $content HTML content.
     * @return string Content with plaintext emails replaced.
     */
    private function replace_plaintext_emails( string $content ): string {
        // Split content into HTML tags and text nodes.
        $parts = preg_split( '/(<[^>]*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        if ( $parts === false ) {
            return $content;
        }

        $result    = '';
        $in_script = false;
        $in_style  = false;

        $email_pattern = '/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/';

        foreach ( $parts as $part ) {
            // Track opening/closing of script, style, and input tags.
            if ( preg_match( '/<\s*script\b/i', $part ) ) {
                $in_script = true;
            }
            if ( preg_match( '/<\s*\/\s*script\b/i', $part ) ) {
                $in_script = false;
            }
            if ( preg_match( '/<\s*style\b/i', $part ) ) {
                $in_style = true;
            }
            if ( preg_match( '/<\s*\/\s*style\b/i', $part ) ) {
                $in_style = false;
            }

            // If this is an HTML tag, skip it (don't replace emails in attributes).
            if ( isset( $part[0] ) && $part[0] === '<' ) {
                $result .= $part;
                continue;
            }

            // Don't replace inside script or style blocks.
            if ( $in_script || $in_style ) {
                $result .= $part;
                continue;
            }

            // Replace plaintext emails in text nodes.
            $replaced = preg_replace_callback( $email_pattern, function ( array $matches ): string {
                $this->needs_decoder = true;
                $encoded = str_rot13( $matches[1] );

                return '<span data-wpt-email="' . esc_attr( $encoded ) . '">' . esc_html( '[email protected]' ) . '</span>';
            }, $part );

            $result .= ( $replaced !== null ) ? $replaced : $part;
        }

        return $result;
    }

    /**
     * Simple [at]/[dot] replacement for RSS feeds.
     *
     * @param string $content Feed content.
     * @return string Content with emails replaced.
     */
    private function obfuscate_for_rss( string $content ): string {
        $email_pattern = '/\b([a-zA-Z0-9._%+\-]+)@([a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/';

        return (string) preg_replace_callback( $email_pattern, function ( array $matches ): string {
            $local  = $matches[1];
            $domain = str_replace( '.', ' [dot] ', $matches[2] );

            return $local . ' [at] ' . $domain;
        }, $content );
    }

    // ── Admin UI ──────────────────────────────────────────────

    public function render_settings(): void {
        $settings = $this->get_settings();
        $methods  = [
            'js_decode' => __( 'JavaScript decode (recommended)', 'wptransformed' ),
        ];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt_method"><?php echo esc_html__( 'Obfuscation Method', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt_method" name="wpt_method">
                        <?php foreach ( $methods as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['method'], $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php echo esc_html__( 'How email addresses are encoded. JavaScript decode is the most compatible method.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__( 'Protection Scope', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="wpt_protect_mailto" value="1" <?php checked( $settings['protect_mailto'] ); ?> />
                            <?php echo esc_html__( 'Protect mailto: links', 'wptransformed' ); ?>
                        </label>
                        <br />
                        <label>
                            <input type="checkbox" name="wpt_protect_plaintext" value="1" <?php checked( $settings['protect_plaintext'] ); ?> />
                            <?php echo esc_html__( 'Protect plaintext email addresses', 'wptransformed' ); ?>
                        </label>
                    </fieldset>
                    <p class="description">
                        <?php echo esc_html__( 'Choose which types of email addresses to obfuscate.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        $valid_methods = [ 'js_decode' ];

        return [
            'method'            => in_array( $raw['wpt_method'] ?? '', $valid_methods, true )
                                   ? $raw['wpt_method'] : 'js_decode',
            'protect_mailto'    => ! empty( $raw['wpt_protect_mailto'] ),
            'protect_plaintext' => ! empty( $raw['wpt_protect_plaintext'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        // No persistent data stored by this module.
        return [];
    }
}
