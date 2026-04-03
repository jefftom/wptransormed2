<?php
declare(strict_types=1);

namespace WPTransformed\Modules\AdminInterface;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Media Infinite Scroll -- Re-enable infinite scroll in the Media Library.
 *
 * WordPress removed infinite scroll in the media grid. This module re-enables
 * it by enqueuing inline JS that overrides the scroll handler on
 * `wp.media.view.AttachmentsBrowser` to call `this.collection.more()`.
 *
 * @package WPTransformed
 */
class Media_Infinite_Scroll extends Module_Base {

    // -- Identity ---------------------------------------------------------

    public function get_id(): string {
        return 'media-infinite-scroll';
    }

    public function get_title(): string {
        return __( 'Media Infinite Scroll', 'wptransformed' );
    }

    public function get_category(): string {
        return 'admin-interface';
    }

    public function get_description(): string {
        return __( 'Re-enable infinite scroll in the Media Library grid view.', 'wptransformed' );
    }

    // -- Settings ---------------------------------------------------------

    public function get_default_settings(): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Lifecycle --------------------------------------------------------

    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_infinite_scroll_script' ] );
    }

    // -- Hook Callbacks ---------------------------------------------------

    /**
     * Enqueue inline JS on upload.php to re-enable infinite scroll.
     */
    public function enqueue_infinite_scroll_script( string $hook ): void {
        if ( 'upload.php' !== $hook ) {
            return;
        }

        $js = <<<'JS'
(function() {
    if (typeof wp === 'undefined' || !wp.media || !wp.media.view) return;

    var originalBrowser = wp.media.view.AttachmentsBrowser;
    if (!originalBrowser) return;

    wp.media.view.AttachmentsBrowser = originalBrowser.extend({
        initialize: function() {
            originalBrowser.prototype.initialize.apply(this, arguments);
            this.on('ready', this.bindInfiniteScroll, this);
        },
        bindInfiniteScroll: function() {
            var self = this;
            var scroller = this.$('.attachments');
            if (!scroller.length) return;

            scroller.on('scroll.wpt-infinite', function() {
                var el = this;
                if (el.scrollTop + el.clientHeight >= el.scrollHeight - 200) {
                    if (self.collection && typeof self.collection.more === 'function') {
                        if (!self.collection._moreRequested) {
                            self.collection._moreRequested = true;
                            self.collection.more().always(function() {
                                self.collection._moreRequested = false;
                            });
                        }
                    }
                }
            });
        }
    });
})();
JS;

        wp_add_inline_script( 'media-grid', $js, 'after' );
    }

    // -- Admin UI ---------------------------------------------------------

    public function render_settings(): void {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Info', 'wptransformed' ); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e( 'Automatically loads more media items as you scroll down in the Media Library grid view.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'enabled' => true,
        ];
    }

    // -- Cleanup ----------------------------------------------------------

    public function get_cleanup_tasks(): array {
        return [];
    }
}
