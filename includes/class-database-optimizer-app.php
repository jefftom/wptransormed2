<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Performance\Database_Cleanup;

/**
 * Database Optimizer — dedicated APP page.
 *
 * Session 4 Stage 2 of the UI restructure. Matches the reference mockup at
 * assets/admin/reference/app-pages/database-optimizer-v3.html.
 *
 * This is the "app page" view linked from the Session 3 Module Grid's
 * Database Optimizer parent card (wpt-database slug). It wraps the
 * existing Database_Cleanup module's data layer with a bento-style UI:
 *
 * - Header with title + action buttons (History, Settings, Clean All)
 * - 4 bento stat cards (DB size, total tables, cleanable data, potential savings)
 * - Main cleanup task list (left column, 2/3 width)
 * - Auto-cleanup scheduler sidebar (right column, 1/3 width)
 * - Largest tables grid + progress bar + last cleanup badge
 *
 * Data strategy:
 * - Cleanup counts come from Database_Cleanup::get_count() — directly
 *   instantiated, NOT from Core::get_module() (which would require the
 *   module to be in the active-modules set). This lets the app page
 *   work whether or not the backend module is toggled on in Modules.
 * - Table sizes come from `SHOW TABLE STATUS` via $wpdb.
 * - The "Clean" buttons AJAX-call wpt_db_cleanup_run, which is only
 *   registered when the backend module is active (its init() adds the
 *   hook). If the module is inactive, the buttons are present but
 *   visibly disabled with a tooltip explaining why.
 *
 * Access control: manage_options.
 *
 * @package WPTransformed
 */
class Database_Optimizer_App {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ], 20 );
    }

    /**
     * Register the app page as a submenu under the WPTransformed top-level.
     * Uses slug `wpt-database` to match the APP link emitted by the
     * Session 3 parent card (see Module_Hierarchy::get_parents() entry
     * for database-optimizer).
     */
    public function register_page(): void {
        $hook = add_submenu_page(
            'wpt-dashboard',
            __( 'Database Optimizer', 'wptransformed' ),
            __( 'Database', 'wptransformed' ),
            'manage_options',
            'wpt-database',
            [ $this, 'render' ]
        );

        add_action( 'load-' . $hook, [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        add_action( 'admin_enqueue_scripts', function () {
            // Reuse the existing dashboard CSS (which now also contains
            // the Session 4 app-page styles added in Stage 4).
            wp_enqueue_style(
                'wpt-admin',
                WPT_URL . 'assets/admin/css/admin.css',
                [ 'wpt-admin-global' ],
                WPT_VERSION
            );
            wp_enqueue_script(
                'wpt-admin',
                WPT_URL . 'assets/admin/js/admin.js',
                [ 'wpt-admin-global' ],
                WPT_VERSION,
                true
            );
            wp_localize_script( 'wpt-admin', 'wptDbOptimizer', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wpt_db_cleanup_nonce' ),
            ] );
        } );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptransformed' ) );
        }

        $core              = Core::instance();
        $module_active     = $core->is_active( 'database-cleanup' );
        $cleanup           = $this->get_cleanup_instance();
        $cleanup_settings  = $cleanup ? $cleanup->get_settings() : [];
        $cleanup_categories = $this->get_cleanup_data( $cleanup, $cleanup_settings );
        $db_stats          = $this->get_db_stats();
        $largest_tables    = $this->get_largest_tables();

        $total_cleanable_bytes = array_sum( array_column( $cleanup_categories, 'size' ) );
        $total_cleanable_count = array_sum( array_column( $cleanup_categories, 'count' ) );
        $savings_pct           = $db_stats['total_bytes'] > 0
            ? round( ( $total_cleanable_bytes / $db_stats['total_bytes'] ) * 100 )
            : 0;

        ?>
        <div class="wpt-dashboard wpt-app-page" id="wptDbOptimizer">

            <?php if ( ! $module_active ) : ?>
                <div class="wpt-app-gate">
                    <i class="fas fa-database wpt-app-gate-icon"></i>
                    <h2><?php esc_html_e( 'Database Cleanup module is inactive', 'wptransformed' ); ?></h2>
                    <p><?php esc_html_e( 'This app page needs the Database Cleanup module to run cleanup tasks. You can still view database stats below, but the Clean buttons will be disabled until the module is activated.', 'wptransformed' ); ?></p>
                    <a class="btn btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>">
                        <?php esc_html_e( 'Go to Modules', 'wptransformed' ); ?>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            <?php endif; ?>

            <header class="wpt-page-header">
                <h1>
                    <span class="wpt-page-header-icon green"><i class="fas fa-database"></i></span>
                    <?php esc_html_e( 'Database Optimizer', 'wptransformed' ); ?>
                </h1>
                <div class="wpt-page-header-actions">
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed' ) ); ?>">
                        <i class="fas fa-arrow-left"></i> <?php esc_html_e( 'Modules', 'wptransformed' ); ?>
                    </a>
                    <a class="btn btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wptransformed&module=database-cleanup' ) ); ?>">
                        <i class="fas fa-cog"></i> <?php esc_html_e( 'Settings', 'wptransformed' ); ?>
                    </a>
                    <button type="button" class="btn btn-primary" id="wptDbCleanAll" <?php echo $module_active ? '' : 'disabled'; ?>>
                        <i class="fas fa-broom"></i> <?php esc_html_e( 'Clean All', 'wptransformed' ); ?>
                    </button>
                </div>
            </header>

            <!-- Bento Stats -->
            <div class="wpt-overview-grid">
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon green"><i class="fas fa-database"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( size_format( $db_stats['total_bytes'], 1 ) ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Database Size', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon amber"><i class="fas fa-table"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( (string) $db_stats['table_count'] ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Total Tables', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon rose"><i class="fas fa-broom"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( size_format( $total_cleanable_bytes, 1 ) ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Cleanable Data', 'wptransformed' ); ?></div>
                </div>
                <div class="wpt-overview-card">
                    <div class="wpt-overview-icon blue"><i class="fas fa-percentage"></i></div>
                    <div class="wpt-overview-value"><?php echo esc_html( $savings_pct . '%' ); ?></div>
                    <div class="wpt-overview-label"><?php esc_html_e( 'Potential Savings', 'wptransformed' ); ?></div>
                </div>
            </div>

            <div class="wpt-app-main">

                <!-- Cleanup Task List -->
                <div class="wpt-panel wpt-cleanup-panel">
                    <div class="wpt-panel-head">
                        <h3><i class="fas fa-broom"></i> <?php esc_html_e( 'Cleanup Tasks', 'wptransformed' ); ?></h3>
                        <span class="wpt-panel-meta">
                            <?php
                            printf(
                                /* translators: %d: total cleanable item count */
                                esc_html( _n( '%d item to clean', '%d items to clean', (int) $total_cleanable_count, 'wptransformed' ) ),
                                (int) $total_cleanable_count
                            );
                            ?>
                        </span>
                    </div>

                    <?php if ( empty( $cleanup_categories ) ) : ?>
                        <div class="wpt-panel-empty">
                            <i class="fas fa-check-circle"></i>
                            <p><?php esc_html_e( 'Database is clean. Nothing to do here.', 'wptransformed' ); ?></p>
                        </div>
                    <?php else : ?>
                        <?php foreach ( $cleanup_categories as $slug => $cat ) : ?>
                            <div class="wpt-cleanup-item <?php echo $cat['count'] === 0 ? 'is-clean' : ''; ?>"
                                 data-category="<?php echo esc_attr( $slug ); ?>">
                                <div class="wpt-cleanup-icon <?php echo esc_attr( $cat['color'] ); ?>">
                                    <i class="fas <?php echo esc_attr( $cat['icon'] ); ?>"></i>
                                </div>
                                <div class="wpt-cleanup-info">
                                    <h4><?php echo esc_html( $cat['label'] ); ?></h4>
                                    <p><?php echo esc_html( $cat['description'] ); ?></p>
                                </div>
                                <div class="wpt-cleanup-stats">
                                    <div class="wpt-cleanup-count" data-count-for="<?php echo esc_attr( $slug ); ?>">
                                        <?php echo esc_html( number_format_i18n( $cat['count'] ) ); ?>
                                    </div>
                                    <div class="wpt-cleanup-size" data-size-for="<?php echo esc_attr( $slug ); ?>">
                                        <?php echo esc_html( size_format( $cat['size'], 1 ) ); ?>
                                    </div>
                                </div>
                                <button type="button"
                                        class="wpt-cleanup-action"
                                        data-category="<?php echo esc_attr( $slug ); ?>"
                                        <?php disabled( ! $module_active || $cat['count'] === 0 ); ?>>
                                    <?php echo $cat['count'] === 0 ? esc_html__( 'Clean', 'wptransformed' ) : esc_html__( 'Clean', 'wptransformed' ); ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Right column sidebar: scheduler + tables + progress -->
                <aside class="wpt-app-sidebar">

                    <div class="wpt-panel wpt-schedule-panel">
                        <div class="wpt-panel-head">
                            <h3><i class="fas fa-clock"></i> <?php esc_html_e( 'Auto-Cleanup', 'wptransformed' ); ?></h3>
                        </div>
                        <?php
                        $schedule_options = [
                            [
                                'label'   => __( 'Daily Cleanup', 'wptransformed' ),
                                'icon'    => 'fa-calendar-day',
                                'key'     => 'daily_cleanup',
                                'checked' => ! empty( $cleanup_settings['daily_cleanup'] ),
                            ],
                            [
                                'label'   => __( 'Keep 5 Revisions', 'wptransformed' ),
                                'icon'    => 'fa-history',
                                'key'     => 'keep_recent_revisions',
                                'checked' => (int) ( $cleanup_settings['keep_recent_revisions'] ?? 0 ) > 0,
                            ],
                            [
                                'label'   => __( 'Auto-Empty Trash', 'wptransformed' ),
                                'icon'    => 'fa-trash',
                                'key'     => 'auto_empty_trash',
                                'checked' => ! empty( $cleanup_settings['auto_empty_trash'] ),
                            ],
                            [
                                'label'   => __( 'Email Reports', 'wptransformed' ),
                                'icon'    => 'fa-envelope',
                                'key'     => 'email_reports',
                                'checked' => ! empty( $cleanup_settings['email_reports'] ),
                            ],
                        ];
                        foreach ( $schedule_options as $opt ) :
                        ?>
                            <div class="wpt-schedule-option">
                                <div class="wpt-schedule-label">
                                    <i class="fas <?php echo esc_attr( $opt['icon'] ); ?>"></i>
                                    <span><?php echo esc_html( $opt['label'] ); ?></span>
                                </div>
                                <label class="toggle">
                                    <input type="checkbox"
                                           class="wpt-schedule-toggle"
                                           data-key="<?php echo esc_attr( $opt['key'] ); ?>"
                                           <?php checked( $opt['checked'] ); ?>
                                           disabled>
                                    <span class="toggle-track"></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <p class="wpt-schedule-note">
                            <?php esc_html_e( 'Schedule editing is coming in v2. For now these reflect the Database Cleanup module settings.', 'wptransformed' ); ?>
                        </p>
                    </div>

                    <div class="wpt-panel wpt-tables-panel">
                        <div class="wpt-panel-head">
                            <h3><i class="fas fa-table"></i> <?php esc_html_e( 'Largest Tables', 'wptransformed' ); ?></h3>
                        </div>
                        <div class="wpt-tables-grid">
                            <?php foreach ( $largest_tables as $table ) : ?>
                                <div class="wpt-table-item">
                                    <h5><?php echo esc_html( $table['name'] ); ?></h5>
                                    <p class="wpt-table-size"><?php echo esc_html( size_format( $table['size'], 1 ) ); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php
                        $db_used_bytes = array_sum( array_column( $largest_tables, 'size' ) );
                        $db_pct        = $db_stats['total_bytes'] > 0
                            ? min( 100, round( ( $db_used_bytes / $db_stats['total_bytes'] ) * 100 ) )
                            : 0;
                        ?>
                        <div class="wpt-progress-bar">
                            <div class="wpt-progress-fill" style="width: <?php echo esc_attr( (string) $db_pct ); ?>%;"></div>
                        </div>
                        <div class="wpt-progress-label">
                            <span><?php esc_html_e( 'Top tables share', 'wptransformed' ); ?></span>
                            <span>
                                <?php echo esc_html( size_format( $db_used_bytes, 1 ) ); ?>
                                /
                                <?php echo esc_html( size_format( $db_stats['total_bytes'], 1 ) ); ?>
                            </span>
                        </div>
                    </div>

                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * Instantiate the Database_Cleanup module class directly, bypassing Core.
     *
     * We don't go through Core::instance()->get_module() because that only
     * returns modules that have been boot-loaded, and we want the data layer
     * to work whether or not the module is in the active set. The class
     * file is loaded lazily.
     *
     * @return Database_Cleanup|null
     */
    private function get_cleanup_instance(): ?Database_Cleanup {
        $class = '\WPTransformed\Modules\Performance\Database_Cleanup';
        if ( ! class_exists( $class ) ) {
            $file = WPT_PATH . 'modules/performance/class-database-cleanup.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( $class ) ) {
            return null;
        }
        return new $class();
    }

    /**
     * Build the cleanup categories payload for rendering.
     *
     * Each entry has: label, description, color, icon, count, size.
     * Sorted by size descending so the biggest savings show first.
     *
     * @param Database_Cleanup|null $cleanup
     * @param array                 $settings
     * @return array<string,array<string,mixed>>
     */
    private function get_cleanup_data( ?Database_Cleanup $cleanup, array $settings ): array {
        $meta = [
            'revisions'              => [ 'label' => __( 'Post Revisions', 'wptransformed' ),         'description' => __( 'Old versions of posts and pages', 'wptransformed' ),       'icon' => 'fa-history',       'color' => 'blue'   ],
            'auto_drafts'            => [ 'label' => __( 'Auto Drafts', 'wptransformed' ),            'description' => __( 'Abandoned draft posts', 'wptransformed' ),                'icon' => 'fa-file',          'color' => 'blue'   ],
            'trashed_posts'          => [ 'label' => __( 'Trashed Posts', 'wptransformed' ),          'description' => __( 'Posts and pages in the trash', 'wptransformed' ),         'icon' => 'fa-trash',         'color' => 'rose'   ],
            'spam_comments'          => [ 'label' => __( 'Spam Comments', 'wptransformed' ),          'description' => __( 'Comments flagged as spam', 'wptransformed' ),             'icon' => 'fa-shield-alt',    'color' => 'rose'   ],
            'trashed_comments'       => [ 'label' => __( 'Trashed Comments', 'wptransformed' ),       'description' => __( 'Comments in the trash', 'wptransformed' ),                'icon' => 'fa-comment-slash', 'color' => 'rose'   ],
            'expired_transients'     => [ 'label' => __( 'Expired Transients', 'wptransformed' ),     'description' => __( 'Cache entries past their expiry time', 'wptransformed' ), 'icon' => 'fa-clock',         'color' => 'amber'  ],
            'orphaned_postmeta'      => [ 'label' => __( 'Orphaned Post Meta', 'wptransformed' ),     'description' => __( 'Meta rows for deleted posts', 'wptransformed' ),          'icon' => 'fa-unlink',        'color' => 'violet' ],
            'orphaned_commentmeta'   => [ 'label' => __( 'Orphaned Comment Meta', 'wptransformed' ),  'description' => __( 'Meta rows for deleted comments', 'wptransformed' ),       'icon' => 'fa-unlink',        'color' => 'violet' ],
            'orphaned_relationships' => [ 'label' => __( 'Orphaned Term Relationships', 'wptransformed' ), 'description' => __( 'Taxonomy links to deleted posts', 'wptransformed' ),  'icon' => 'fa-unlink',        'color' => 'violet' ],
        ];

        if ( ! $cleanup ) {
            // Can't query counts without the instance — show zeros.
            foreach ( $meta as $slug => &$entry ) {
                $entry['count'] = 0;
                $entry['size']  = 0;
            }
            return $meta;
        }

        $size_estimates = [
            'revisions'              => 3072,
            'auto_drafts'            => 3072,
            'trashed_posts'          => 3072,
            'spam_comments'          => 512,
            'trashed_comments'       => 512,
            'expired_transients'     => 512,
            'orphaned_postmeta'      => 200,
            'orphaned_commentmeta'   => 200,
            'orphaned_relationships' => 200,
        ];

        foreach ( $meta as $slug => &$entry ) {
            try {
                $count = $cleanup->get_count( $slug, $settings );
            } catch ( \Throwable $e ) {
                $count = 0;
            }
            $entry['count'] = $count;
            $entry['size']  = $count * ( $size_estimates[ $slug ] ?? 200 );
        }
        unset( $entry );

        // Sort by size desc so the biggest savings are at the top.
        uasort( $meta, static function ( $a, $b ) {
            return $b['size'] <=> $a['size'];
        } );

        return $meta;
    }

    /**
     * Return total database size + table count via SHOW TABLE STATUS.
     *
     * @return array{total_bytes:int, table_count:int}
     */
    private function get_db_stats(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( 'SHOW TABLE STATUS' );
        if ( ! is_array( $rows ) ) {
            return [ 'total_bytes' => 0, 'table_count' => 0 ];
        }
        $total = 0;
        foreach ( $rows as $row ) {
            $data  = isset( $row->Data_length ) ? (int) $row->Data_length : 0;
            $index = isset( $row->Index_length ) ? (int) $row->Index_length : 0;
            $total += $data + $index;
        }
        return [
            'total_bytes' => $total,
            'table_count' => count( $rows ),
        ];
    }

    /**
     * Return the 6 largest tables by data+index size, including only
     * tables that match the WP prefix (so other databases on the same
     * server don't leak in if any).
     *
     * @return array<int,array{name:string, size:int, rows:int}>
     */
    private function get_largest_tables(): array {
        global $wpdb;
        $prefix = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $prefix . '%' ) );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $tables = [];
        foreach ( $rows as $row ) {
            $size = (int) ( $row->Data_length ?? 0 ) + (int) ( $row->Index_length ?? 0 );
            $tables[] = [
                'name' => (string) ( $row->Name ?? '' ),
                'size' => $size,
                'rows' => (int) ( $row->Rows ?? 0 ),
            ];
        }
        usort( $tables, static function ( $a, $b ) {
            return $b['size'] <=> $a['size'];
        } );
        return array_slice( $tables, 0, 6 );
    }
}
