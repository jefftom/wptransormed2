<?php
declare(strict_types=1);

namespace WPTransformed\Modules\Compliance;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Cookie Consent — GDPR/CCPA-compliant cookie consent banner.
 *
 * Features:
 *  - Configurable cookie categories (necessary, analytics, marketing, preferences)
 *  - Accept All / Reject All / Customize buttons
 *  - Script blocking via script_loader_tag filter (type="text/plain")
 *  - Client-side consent state in cookie (cache-safe, no PHP rendering decisions)
 *  - Dispatches wpt_consent_given CustomEvent for integrations
 *  - Banner positions: bottom, top, bottom-left, bottom-right
 *  - Inline HTML/CSS/JS in wp_footer (no external deps)
 *
 * @package WPTransformed
 */
class Cookie_Consent extends Module_Base {

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'cookie-consent';
    }

    public function get_title(): string {
        return __( 'Cookie Consent', 'wptransformed' );
    }

    public function get_category(): string {
        return 'compliance';
    }

    public function get_description(): string {
        return __( 'Display a customizable cookie consent banner with category-based script blocking.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'banner_position'    => 'bottom',
            'banner_style'       => 'bar',
            'message'            => 'We use cookies to improve your experience.',
            'accept_text'        => 'Accept All',
            'reject_text'        => 'Reject All',
            'customize_text'     => 'Customize',
            'privacy_url'        => '/privacy-policy',
            'categories'         => [
                'necessary'   => [
                    'label'       => 'Necessary',
                    'description' => 'Required for the site to function.',
                    'required'    => true,
                ],
                'analytics'   => [
                    'label'       => 'Analytics',
                    'description' => 'Help us understand how you use the site.',
                    'required'    => false,
                ],
                'marketing'   => [
                    'label'       => 'Marketing',
                    'description' => 'Used for targeted advertising.',
                    'required'    => false,
                ],
                'preferences' => [
                    'label'       => 'Preferences',
                    'description' => 'Remember your settings.',
                    'required'    => false,
                ],
            ],
            'auto_block_scripts' => true,
            'consent_duration'   => 365,
            'script_categories'  => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Frontend: output banner in footer.
        add_action( 'wp_footer', [ $this, 'render_banner' ], 99 );

        // Script blocking: modify script tags for tagged handles.
        $settings = $this->get_settings();
        if ( ! empty( $settings['auto_block_scripts'] ) && ! empty( $settings['script_categories'] ) ) {
            add_filter( 'script_loader_tag', [ $this, 'filter_script_tag' ], 10, 3 );
        }
    }

    // ── Hook Callbacks ────────────────────────────────────────

    /**
     * Filter script tags to add consent-based blocking.
     *
     * Scripts whose handle is mapped to a non-necessary category get
     * type="text/plain" and data-consent="category" so they are inert
     * until consent is given. The frontend JS swaps them back.
     *
     * @param string $tag    The full script tag HTML.
     * @param string $handle The script handle.
     * @param string $src    The script source URL.
     * @return string Modified tag.
     */
    public function filter_script_tag( string $tag, string $handle, string $src ): string {
        $settings   = $this->get_settings();
        $categories = $settings['script_categories'];

        if ( ! isset( $categories[ $handle ] ) ) {
            return $tag;
        }

        $category = $categories[ $handle ];

        // Never block necessary scripts.
        if ( $category === 'necessary' ) {
            return $tag;
        }

        // Replace type so the browser won't execute the script.
        $tag = str_replace( " type='text/javascript'", " type='text/plain'", $tag );
        $tag = str_replace( ' type="text/javascript"', ' type="text/plain"', $tag );

        // If no type attribute was present, add one before src.
        if ( strpos( $tag, "type='text/plain'" ) === false && strpos( $tag, 'type="text/plain"' ) === false ) {
            $tag = str_replace( ' src=', ' type="text/plain" src=', $tag );
        }

        // Add data-consent attribute.
        $tag = str_replace( '<script ', '<script data-consent="' . esc_attr( $category ) . '" ', $tag );

        return $tag;
    }

    /**
     * Render the cookie consent banner inline in wp_footer.
     *
     * All HTML, CSS, and JS are self-contained. The JS reads the consent
     * cookie client-side so the output is cache-safe.
     */
    public function render_banner(): void {
        // Don't show in admin or to logged-in admins in customizer previews.
        if ( is_admin() ) {
            return;
        }

        $settings       = $this->get_settings();
        $position       = esc_attr( $settings['banner_position'] );
        $style          = esc_attr( $settings['banner_style'] );
        $message        = esc_html( $settings['message'] );
        $accept_text    = esc_html( $settings['accept_text'] );
        $reject_text    = esc_html( $settings['reject_text'] );
        $customize_text = esc_html( $settings['customize_text'] );
        $privacy_url    = esc_url( $settings['privacy_url'] );
        $categories     = $settings['categories'];
        $duration       = absint( $settings['consent_duration'] );

        // Build categories JSON for the JS (escaped for embedding in a script tag).
        $categories_json = wp_json_encode( $categories );
        ?>
<!-- WPTransformed Cookie Consent -->
<style>
.wpt-cookie-banner{display:none;position:fixed;z-index:999999;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:14px;line-height:1.5;color:#333;background:#fff;box-shadow:0 -2px 16px rgba(0,0,0,.12)}
.wpt-cookie-banner.wpt-pos-bottom{bottom:0;left:0;right:0}
.wpt-cookie-banner.wpt-pos-top{top:0;left:0;right:0}
.wpt-cookie-banner.wpt-pos-bottom-left{bottom:20px;left:20px;max-width:420px;border-radius:12px}
.wpt-cookie-banner.wpt-pos-bottom-right{bottom:20px;right:20px;max-width:420px;border-radius:12px}
.wpt-cookie-banner .wpt-cb-inner{padding:20px 24px}
.wpt-cookie-banner .wpt-cb-message{margin:0 0 16px}
.wpt-cookie-banner .wpt-cb-message a{color:#0073aa;text-decoration:underline}
.wpt-cookie-banner .wpt-cb-buttons{display:flex;gap:10px;flex-wrap:wrap}
.wpt-cookie-banner .wpt-cb-btn{padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:opacity .2s}
.wpt-cookie-banner .wpt-cb-btn:hover{opacity:.85}
.wpt-cookie-banner .wpt-cb-btn-accept{background:#0073aa;color:#fff}
.wpt-cookie-banner .wpt-cb-btn-reject{background:#e2e4e7;color:#333}
.wpt-cookie-banner .wpt-cb-btn-customize{background:transparent;color:#0073aa;border:1px solid #0073aa}
.wpt-cookie-banner .wpt-cb-categories{display:none;margin-top:16px;padding-top:16px;border-top:1px solid #e2e4e7}
.wpt-cookie-banner .wpt-cb-category{margin-bottom:12px}
.wpt-cookie-banner .wpt-cb-category label{display:flex;align-items:flex-start;gap:8px;cursor:pointer}
.wpt-cookie-banner .wpt-cb-category input[type="checkbox"]{margin-top:3px;width:16px;height:16px}
.wpt-cookie-banner .wpt-cb-cat-info strong{display:block}
.wpt-cookie-banner .wpt-cb-cat-info span{font-size:12px;color:#666}
.wpt-cookie-banner .wpt-cb-save{margin-top:12px}
</style>
<div id="wpt-cookie-banner" class="wpt-cookie-banner wpt-pos-<?php echo $position; ?>" role="dialog" aria-label="<?php esc_attr_e( 'Cookie Consent', 'wptransformed' ); ?>">
    <div class="wpt-cb-inner">
        <p class="wpt-cb-message">
            <?php echo $message; ?>
            <?php if ( $privacy_url ) : ?>
                <a href="<?php echo $privacy_url; ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'wptransformed' ); ?></a>
            <?php endif; ?>
        </p>
        <div class="wpt-cb-buttons">
            <button type="button" class="wpt-cb-btn wpt-cb-btn-accept" id="wpt-cb-accept"><?php echo $accept_text; ?></button>
            <button type="button" class="wpt-cb-btn wpt-cb-btn-reject" id="wpt-cb-reject"><?php echo $reject_text; ?></button>
            <button type="button" class="wpt-cb-btn wpt-cb-btn-customize" id="wpt-cb-customize"><?php echo $customize_text; ?></button>
        </div>
        <div class="wpt-cb-categories" id="wpt-cb-categories">
            <?php foreach ( $categories as $cat_key => $cat_data ) :
                $is_required = ! empty( $cat_data['required'] );
            ?>
            <div class="wpt-cb-category">
                <label>
                    <input type="checkbox"
                           name="wpt_consent_<?php echo esc_attr( $cat_key ); ?>"
                           value="<?php echo esc_attr( $cat_key ); ?>"
                           <?php if ( $is_required ) : ?>checked disabled<?php endif; ?>
                           data-category="<?php echo esc_attr( $cat_key ); ?>">
                    <span class="wpt-cb-cat-info">
                        <strong><?php echo esc_html( $cat_data['label'] ); ?></strong>
                        <span><?php echo esc_html( $cat_data['description'] ); ?></span>
                    </span>
                </label>
            </div>
            <?php endforeach; ?>
            <button type="button" class="wpt-cb-btn wpt-cb-btn-accept wpt-cb-save" id="wpt-cb-save"><?php esc_html_e( 'Save Preferences', 'wptransformed' ); ?></button>
        </div>
    </div>
</div>
<script>
(function(){
    var COOKIE_NAME='wpt_consent';
    var DURATION=<?php echo $duration; ?>;
    var CATEGORIES=<?php echo $categories_json; ?>;
    var banner=document.getElementById('wpt-cookie-banner');
    if(!banner)return;

    function getCookie(name){
        var m=document.cookie.match('(?:^|; )'+name.replace(/([.$?*|{}()[\]\\/+^])/g,'\\$1')+'=([^;]*)');
        return m?decodeURIComponent(m[1]):null;
    }
    function setCookie(name,val,days){
        var d=new Date();
        d.setTime(d.getTime()+days*86400000);
        document.cookie=name+'='+encodeURIComponent(val)+';expires='+d.toUTCString()+';path=/;SameSite=Lax';
    }
    function getConsent(){
        var raw=getCookie(COOKIE_NAME);
        if(!raw)return null;
        try{return JSON.parse(raw);}catch(e){return null;}
    }
    function saveConsent(consent){
        // Ensure necessary is always true.
        consent.necessary=true;
        setCookie(COOKIE_NAME,JSON.stringify(consent),DURATION);
        banner.style.display='none';
        activateScripts(consent);
        document.dispatchEvent(new CustomEvent('wpt_consent_given',{detail:consent}));
    }
    function activateScripts(consent){
        var blocked=document.querySelectorAll('script[data-consent]');
        for(var i=0;i<blocked.length;i++){
            var s=blocked[i];
            var cat=s.getAttribute('data-consent');
            if(consent[cat]){
                var ns=document.createElement('script');
                if(s.src){ns.src=s.src;}else{ns.textContent=s.textContent;}
                ns.type='text/javascript';
                // Copy other attributes.
                for(var j=0;j<s.attributes.length;j++){
                    var a=s.attributes[j];
                    if(a.name!=='type'&&a.name!=='data-consent'){
                        ns.setAttribute(a.name,a.value);
                    }
                }
                s.parentNode.replaceChild(ns,s);
            }
        }
    }
    function buildConsent(all){
        var consent={};
        for(var k in CATEGORIES){
            if(CATEGORIES.hasOwnProperty(k)){
                consent[k]=!!all||!!CATEGORIES[k].required;
            }
        }
        return consent;
    }

    // Check existing consent.
    var existing=getConsent();
    if(existing){
        activateScripts(existing);
        return;
    }

    // Show banner.
    banner.style.display='block';

    // Accept All.
    document.getElementById('wpt-cb-accept').addEventListener('click',function(){
        saveConsent(buildConsent(true));
    });

    // Reject All.
    document.getElementById('wpt-cb-reject').addEventListener('click',function(){
        saveConsent(buildConsent(false));
    });

    // Customize toggle.
    document.getElementById('wpt-cb-customize').addEventListener('click',function(){
        var cats=document.getElementById('wpt-cb-categories');
        cats.style.display=cats.style.display==='block'?'none':'block';
    });

    // Save Preferences.
    document.getElementById('wpt-cb-save').addEventListener('click',function(){
        var consent={};
        var checkboxes=document.querySelectorAll('#wpt-cb-categories input[type="checkbox"]');
        for(var i=0;i<checkboxes.length;i++){
            var cb=checkboxes[i];
            var cat=cb.getAttribute('data-category');
            if(cat){
                consent[cat]=cb.checked||cb.disabled;
            }
        }
        saveConsent(consent);
    });
})();
</script>
<!-- /WPTransformed Cookie Consent -->
        <?php
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings   = $this->get_settings();
        $categories = $settings['categories'];
        $positions  = [
            'bottom'       => __( 'Bottom (full-width bar)', 'wptransformed' ),
            'top'          => __( 'Top (full-width bar)', 'wptransformed' ),
            'bottom-left'  => __( 'Bottom Left (floating)', 'wptransformed' ),
            'bottom-right' => __( 'Bottom Right (floating)', 'wptransformed' ),
        ];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpt-banner-position"><?php esc_html_e( 'Banner Position', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <select id="wpt-banner-position" name="wpt_banner_position">
                        <?php foreach ( $positions as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['banner_position'], $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-message"><?php esc_html_e( 'Banner Message', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-message" name="wpt_message" rows="3"
                              class="large-text"><?php echo esc_textarea( $settings['message'] ); ?></textarea>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-accept-text"><?php esc_html_e( 'Accept Button Text', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-accept-text" name="wpt_accept_text"
                           value="<?php echo esc_attr( $settings['accept_text'] ); ?>" class="regular-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-reject-text"><?php esc_html_e( 'Reject Button Text', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-reject-text" name="wpt_reject_text"
                           value="<?php echo esc_attr( $settings['reject_text'] ); ?>" class="regular-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-customize-text"><?php esc_html_e( 'Customize Button Text', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-customize-text" name="wpt_customize_text"
                           value="<?php echo esc_attr( $settings['customize_text'] ); ?>" class="regular-text">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-privacy-url"><?php esc_html_e( 'Privacy Policy URL', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="text" id="wpt-privacy-url" name="wpt_privacy_url"
                           value="<?php echo esc_attr( $settings['privacy_url'] ); ?>" class="regular-text"
                           placeholder="/privacy-policy">
                    <p class="description">
                        <?php esc_html_e( 'Link to your privacy policy page. Can be a relative or absolute URL.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-consent-duration"><?php esc_html_e( 'Consent Duration (days)', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <input type="number" id="wpt-consent-duration" name="wpt_consent_duration"
                           value="<?php echo esc_attr( (string) $settings['consent_duration'] ); ?>"
                           min="1" max="730" step="1" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'How many days before the consent cookie expires and the banner reappears.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( 'Auto-Block Scripts', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wpt_auto_block_scripts" value="1"
                               <?php checked( ! empty( $settings['auto_block_scripts'] ) ); ?>>
                        <?php esc_html_e( 'Automatically block tagged scripts until consent is given', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpt-script-categories"><?php esc_html_e( 'Script Category Mappings', 'wptransformed' ); ?></label>
                </th>
                <td>
                    <textarea id="wpt-script-categories" name="wpt_script_categories" rows="6"
                              class="large-text code"
                              placeholder="google-analytics:analytics&#10;facebook-pixel:marketing&#10;hotjar:analytics"><?php
                        $mappings = $settings['script_categories'];
                        if ( is_array( $mappings ) ) {
                            $lines = [];
                            foreach ( $mappings as $handle => $cat ) {
                                $lines[] = esc_textarea( $handle . ':' . $cat );
                            }
                            echo implode( "\n", $lines );
                        }
                    ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One mapping per line in the format: script-handle:category (e.g., google-analytics:analytics). Valid categories: necessary, analytics, marketing, preferences.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $defaults = $this->get_default_settings();
        $clean    = [];

        // Banner position — whitelist.
        $valid_positions = [ 'bottom', 'top', 'bottom-left', 'bottom-right' ];
        $pos             = $raw['wpt_banner_position'] ?? 'bottom';
        $clean['banner_position'] = in_array( $pos, $valid_positions, true ) ? $pos : 'bottom';

        // Banner style.
        $clean['banner_style'] = sanitize_text_field( $raw['wpt_banner_style'] ?? 'bar' );

        // Text fields.
        $clean['message']        = sanitize_textarea_field( $raw['wpt_message'] ?? $defaults['message'] );
        $clean['accept_text']    = sanitize_text_field( $raw['wpt_accept_text'] ?? $defaults['accept_text'] );
        $clean['reject_text']    = sanitize_text_field( $raw['wpt_reject_text'] ?? $defaults['reject_text'] );
        $clean['customize_text'] = sanitize_text_field( $raw['wpt_customize_text'] ?? $defaults['customize_text'] );

        // Privacy URL — allow relative and absolute URLs.
        $privacy_url = $raw['wpt_privacy_url'] ?? $defaults['privacy_url'];
        if ( strpos( $privacy_url, '/' ) === 0 ) {
            // Relative URL: sanitize as text.
            $clean['privacy_url'] = sanitize_text_field( $privacy_url );
        } else {
            $clean['privacy_url'] = esc_url_raw( $privacy_url );
        }

        // Categories — keep defaults, don't allow editing category structure from settings form.
        $clean['categories'] = $defaults['categories'];

        // Auto-block scripts.
        $clean['auto_block_scripts'] = ! empty( $raw['wpt_auto_block_scripts'] );

        // Consent duration — clamp.
        $duration = (int) ( $raw['wpt_consent_duration'] ?? 365 );
        $clean['consent_duration'] = max( 1, min( 730, $duration ) );

        // Script category mappings — parse handle:category lines.
        $valid_cats  = [ 'necessary', 'analytics', 'marketing', 'preferences' ];
        $script_text = $raw['wpt_script_categories'] ?? '';
        $lines       = array_filter( array_map( 'trim', explode( "\n", $script_text ) ) );
        $mappings    = [];

        foreach ( $lines as $line ) {
            $parts = explode( ':', $line, 2 );
            if ( count( $parts ) === 2 ) {
                $handle = sanitize_key( trim( $parts[0] ) );
                $cat    = sanitize_key( trim( $parts[1] ) );
                if ( $handle && in_array( $cat, $valid_cats, true ) ) {
                    $mappings[ $handle ] = $cat;
                }
            }
        }

        $clean['script_categories'] = $mappings;

        return $clean;
    }

    // ── Assets ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // No separate admin assets — banner is inline in wp_footer.
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            'cookies' => [
                'description' => __( 'Cookie consent preferences cookie (wpt_consent) in visitor browsers — cannot be removed server-side.', 'wptransformed' ),
                'type'        => 'info',
            ],
        ];
    }
}
