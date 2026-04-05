<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Editor Dashboard — Content-focused landing page.
 *
 * Replaces the default WP dashboard for WPTransformed users with
 * a content workspace showing real post data, quick actions, and
 * scheduled content.
 *
 * Reference: assets/admin/reference/dashboard/wp-transformation-editor.html
 *
 * @package WPTransformed
 */
class Editor_Dashboard {

    /** Writing tips — rotated based on day of year. */
    private const TIPS = [
        [
            'title' => 'Use Numbers in Headlines',
            'body'  => 'Headlines with numbers get 36% more clicks! Try using lists and specific data in your next post.',
            'link'  => 'https://wptransformed.com/docs/writing-tips#headlines',
        ],
        [
            'title' => 'Write Shorter Paragraphs',
            'body'  => 'Online readers scan content. Keep paragraphs to 2-3 sentences max for better readability and engagement.',
            'link'  => 'https://wptransformed.com/docs/writing-tips#paragraphs',
        ],
        [
            'title' => 'Front-Load Your Key Message',
            'body'  => 'Put the most important information in the first sentence. Readers decide in seconds whether to keep reading.',
            'link'  => 'https://wptransformed.com/docs/writing-tips#structure',
        ],
        [
            'title' => 'Add Internal Links',
            'body'  => 'Link to 2-3 related posts in every article. It boosts SEO, reduces bounce rate, and keeps readers on your site.',
            'link'  => 'https://wptransformed.com/docs/writing-tips#internal-links',
        ],
        [
            'title' => 'Optimize Your Featured Image',
            'body'  => 'Posts with images get 94% more views. Use compressed, relevant images with descriptive alt text for accessibility and SEO.',
            'link'  => 'https://wptransformed.com/docs/writing-tips#images',
        ],
        [
            'title' => 'Use Active Voice',
            'body'  => 'Active voice makes writing clearer and more engaging. "The team launched the product" beats "The product was launched by the team."',
            'link'  => 'https://wptransformed.com/docs/writing-tips#voice',
        ],
        [
            'title' => 'Schedule Posts Consistently',
            'body'  => 'Publishing on a regular schedule trains your audience to expect new content. Consistency beats frequency every time.',
            'link'  => 'https://wptransformed.com/docs/writing-tips#scheduling',
        ],
        [
            'title' => 'Write Meta Descriptions',
            'body'  => 'A compelling meta description (under 160 characters) can improve click-through rates from search results by up to 30%.',
            'link'  => 'https://wptransformed.com/docs/writing-tips#meta',
        ],
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ] );
    }

    /**
     * Register the Editor Dashboard as a submenu page under WPTransformed.
     * The top-level menu (wpt-dashboard) is registered by Admin class.
     * This adds the actual callback and renames the auto-generated first submenu.
     */
    public function register_page(): void {
        $hook = add_submenu_page(
            'wpt-dashboard',                                // parent slug
            __( 'Dashboard', 'wptransformed' ),             // page title
            __( 'Dashboard', 'wptransformed' ),             // menu title
            'edit_posts',                                   // capability — any editor+
            'wpt-dashboard',                                // menu slug — same as parent to replace default
            [ $this, 'render_page' ]                        // callback
        );

        add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        add_action( 'admin_enqueue_scripts', function() {
            wp_enqueue_style(
                'wpt-editor-dashboard',
                WPT_URL . 'assets/admin/css/editor-dashboard.css',
                [ 'wpt-admin-global' ],
                WPT_VERSION
            );

            wp_enqueue_script(
                'wpt-editor-dashboard',
                WPT_URL . 'assets/admin/js/editor-dashboard.js',
                [ 'wpt-admin-global' ],
                WPT_VERSION,
                true
            );
        } );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptransformed' ) );
        }

        $user = wp_get_current_user();
        $first_name = $user->first_name ?: $user->display_name;

        // Content counts
        $post_counts = wp_count_posts( 'post' );
        $page_counts = wp_count_posts( 'page' );
        $published   = (int) $post_counts->publish + (int) $page_counts->publish;
        $drafts      = (int) $post_counts->draft + (int) $page_counts->draft;
        $scheduled   = (int) $post_counts->future + (int) $page_counts->future;
        $pending     = (int) $post_counts->pending + (int) $page_counts->pending;

        // Dynamic greeting
        $hour = (int) current_time( 'G' );
        if ( $hour < 12 ) {
            $greeting = __( 'Good morning', 'wptransformed' );
        } elseif ( $hour < 17 ) {
            $greeting = __( 'Good afternoon', 'wptransformed' );
        } else {
            $greeting = __( 'Good evening', 'wptransformed' );
        }

        // Subtitle
        $parts = [];
        if ( $drafts > 0 ) {
            $parts[] = sprintf( _n( '%d draft waiting', '%d drafts waiting', $drafts, 'wptransformed' ), $drafts );
        }
        if ( $scheduled > 0 ) {
            $parts[] = sprintf( _n( '%d post scheduled this week', '%d posts scheduled this week', $scheduled, 'wptransformed' ), $scheduled );
        }
        $subtitle = ! empty( $parts )
            ? sprintf( __( 'You have %s. Keep up the great work!', 'wptransformed' ), implode( ' and ', $parts ) )
            : __( 'Your content workspace is ready. Let\'s create something great!', 'wptransformed' );

        // Recent posts
        $recent_posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => [ 'publish', 'draft', 'pending', 'future' ],
            'posts_per_page' => 5,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'perm'           => 'editable',
        ] );

        // Upcoming scheduled posts
        $upcoming = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'future',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ] );

        // Writing tip — rotate based on day of year
        $tip_index = (int) current_time( 'z' ) % count( self::TIPS );
        $tip       = self::TIPS[ $tip_index ];

        ?>
        <div class="wpt-editor-dashboard">

            <!-- Welcome Card -->
            <div class="wpt-ed-welcome">
                <h2><?php echo esc_html( $greeting . ', ' . $first_name . '!' ); ?></h2>
                <p><?php echo esc_html( $subtitle ); ?></p>
            </div>

            <!-- Stats Row -->
            <div class="wpt-ed-stats">
                <div class="wpt-ed-stat">
                    <div class="wpt-ed-stat-icon blue"><i class="fas fa-file-alt"></i></div>
                    <div class="wpt-ed-stat-value" data-count="<?php echo esc_attr( (string) $published ); ?>">0</div>
                    <div class="wpt-ed-stat-label"><?php esc_html_e( 'Published', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-ed-stat">
                    <div class="wpt-ed-stat-icon amber"><i class="fas fa-pencil-alt"></i></div>
                    <div class="wpt-ed-stat-value" data-count="<?php echo esc_attr( (string) $drafts ); ?>">0</div>
                    <div class="wpt-ed-stat-label"><?php esc_html_e( 'Drafts', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-ed-stat">
                    <div class="wpt-ed-stat-icon green"><i class="fas fa-clock"></i></div>
                    <div class="wpt-ed-stat-value" data-count="<?php echo esc_attr( (string) $scheduled ); ?>">0</div>
                    <div class="wpt-ed-stat-label"><?php esc_html_e( 'Scheduled', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-ed-stat">
                    <div class="wpt-ed-stat-icon violet"><i class="fas fa-eye"></i></div>
                    <div class="wpt-ed-stat-value" data-count="<?php echo esc_attr( (string) $pending ); ?>">0</div>
                    <div class="wpt-ed-stat-label"><?php esc_html_e( 'In Review', 'wptransformed' ); ?></div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="wpt-ed-section-title"><i class="fas fa-bolt"></i> <?php esc_html_e( 'Quick Actions', 'wptransformed' ); ?></div>
            <div class="wpt-ed-quick-actions">
                <a class="wpt-ed-qa" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>">
                    <div class="wpt-ed-qa-icon blue"><i class="fas fa-plus"></i></div>
                    <h4><?php esc_html_e( 'New Post', 'wptransformed' ); ?></h4>
                    <p><?php esc_html_e( 'Create a new article', 'wptransformed' ); ?></p>
                </a>
                <a class="wpt-ed-qa" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>">
                    <div class="wpt-ed-qa-icon teal"><i class="fas fa-copy"></i></div>
                    <h4><?php esc_html_e( 'New Page', 'wptransformed' ); ?></h4>
                    <p><?php esc_html_e( 'Create a new page', 'wptransformed' ); ?></p>
                </a>
                <a class="wpt-ed-qa" href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>">
                    <div class="wpt-ed-qa-icon violet"><i class="fas fa-upload"></i></div>
                    <h4><?php esc_html_e( 'Upload Media', 'wptransformed' ); ?></h4>
                    <p><?php esc_html_e( 'Add images or files', 'wptransformed' ); ?></p>
                </a>
                <a class="wpt-ed-qa" href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>">
                    <div class="wpt-ed-qa-icon amber"><i class="fas fa-th-large"></i></div>
                    <h4><?php esc_html_e( 'Block Patterns', 'wptransformed' ); ?></h4>
                    <p><?php esc_html_e( 'Pre-built templates', 'wptransformed' ); ?></p>
                </a>
            </div>

            <!-- Content Grid -->
            <div class="wpt-ed-content-grid">

                <!-- Recent Posts Panel -->
                <div class="wpt-ed-panel">
                    <div class="wpt-ed-panel-header">
                        <h3><i class="fas fa-file-alt"></i> <?php esc_html_e( 'Recent Posts', 'wptransformed' ); ?></h3>
                        <a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php esc_html_e( 'View All', 'wptransformed' ); ?> &rarr;</a>
                    </div>
                    <?php if ( ! empty( $recent_posts ) ) : ?>
                        <?php foreach ( $recent_posts as $post ) : ?>
                            <?php
                            $thumb_id = get_post_thumbnail_id( $post->ID );
                            $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
                            $edit_url  = get_edit_post_link( $post->ID, 'raw' );
                            $cats      = get_the_category( $post->ID );
                            $cat_name  = ! empty( $cats ) ? $cats[0]->name : ( $post->post_type === 'page' ? __( 'Page', 'wptransformed' ) : '' );
                            $status    = $post->post_status;
                            $status_map = [
                                'publish' => [ 'published', __( 'Published', 'wptransformed' ) ],
                                'draft'   => [ 'draft', __( 'Draft', 'wptransformed' ) ],
                                'pending' => [ 'review', __( 'Review', 'wptransformed' ) ],
                                'future'  => [ 'scheduled', __( 'Scheduled', 'wptransformed' ) ],
                            ];
                            $status_class = $status_map[ $status ][0] ?? 'draft';
                            $status_label = $status_map[ $status ][1] ?? ucfirst( $status );
                            ?>
                            <a class="wpt-ed-post-item" href="<?php echo esc_url( $edit_url ?: '#' ); ?>">
                                <div class="wpt-ed-post-thumb">
                                    <?php if ( $thumb_url ) : ?>
                                        <img src="<?php echo esc_url( $thumb_url ); ?>" alt="">
                                    <?php else : ?>
                                        <i class="fas fa-image"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="wpt-ed-post-info">
                                    <h4><?php echo esc_html( $post->post_title ?: __( '(no title)', 'wptransformed' ) ); ?></h4>
                                    <div class="wpt-ed-post-meta">
                                        <span><i class="fas fa-calendar"></i> <?php echo esc_html( get_the_date( 'M j, Y', $post ) ); ?></span>
                                        <?php if ( $cat_name ) : ?>
                                            <span><i class="fas fa-folder"></i> <?php echo esc_html( $cat_name ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="wpt-ed-post-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="wpt-ed-empty">
                            <i class="fas fa-file-alt"></i>
                            <p><?php esc_html_e( 'No posts yet. Create your first post!', 'wptransformed' ); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column -->
                <div class="wpt-ed-right-col">

                    <!-- Upcoming Scheduled -->
                    <div class="wpt-ed-panel">
                        <div class="wpt-ed-panel-header">
                            <h3><i class="fas fa-calendar-alt"></i> <?php esc_html_e( 'Upcoming', 'wptransformed' ); ?></h3>
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_status=future' ) ); ?>"><?php esc_html_e( 'View Calendar', 'wptransformed' ); ?> &rarr;</a>
                        </div>
                        <?php if ( ! empty( $upcoming ) ) : ?>
                            <?php foreach ( $upcoming as $post ) : ?>
                                <?php $edit_url = get_edit_post_link( $post->ID, 'raw' ); ?>
                                <a class="wpt-ed-schedule-item" href="<?php echo esc_url( $edit_url ?: '#' ); ?>">
                                    <div class="wpt-ed-schedule-date">
                                        <span class="wpt-ed-sched-day"><?php echo esc_html( get_the_date( 'd', $post ) ); ?></span>
                                        <span class="wpt-ed-sched-month"><?php echo esc_html( get_the_date( 'M', $post ) ); ?></span>
                                    </div>
                                    <div class="wpt-ed-schedule-info">
                                        <h4><?php echo esc_html( $post->post_title ?: __( '(no title)', 'wptransformed' ) ); ?></h4>
                                        <p><?php
                                            printf(
                                                esc_html__( 'Scheduled for %s', 'wptransformed' ),
                                                esc_html( get_the_date( 'g:i A', $post ) )
                                            );
                                        ?></p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="wpt-ed-empty">
                                <i class="fas fa-calendar-alt"></i>
                                <p><?php esc_html_e( 'No scheduled posts. Plan ahead!', 'wptransformed' ); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Writing Tip -->
                    <div class="wpt-ed-tips-card">
                        <h4><i class="fas fa-lightbulb"></i> <?php esc_html_e( 'Writing Tip', 'wptransformed' ); ?></h4>
                        <p>
                            <?php echo esc_html( $tip['body'] ); ?>
                            <a href="<?php echo esc_url( $tip['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'wptransformed' ); ?> &rarr;</a>
                        </p>
                    </div>

                </div>
            </div>

        </div>
        <?php
    }
}
