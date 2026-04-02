<?php
declare(strict_types=1);

/**
 * PHPUnit tests for Database_Cleanup module.
 *
 * Tests behavioral correctness: sanitization, defaults, identity,
 * size estimates, and batch size configuration.
 *
 * @package WPTransformed
 */

use WPTransformed\Modules\Performance\Database_Cleanup;

class Test_Database_Cleanup extends WP_UnitTestCase {

    /**
     * Module instance.
     *
     * @var Database_Cleanup
     */
    private Database_Cleanup $module;

    /**
     * Set up each test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->module = new Database_Cleanup();
    }

    // ── Identity ──────────────────────────────────────────────

    public function test_get_id(): void {
        $this->assertSame( 'database-cleanup', $this->module->get_id() );
    }

    public function test_get_title(): void {
        $this->assertSame( 'Database Cleanup', $this->module->get_title() );
    }

    public function test_get_category(): void {
        $this->assertSame( 'performance', $this->module->get_category() );
    }

    public function test_get_description_is_non_empty(): void {
        $desc = $this->module->get_description();
        $this->assertIsString( $desc );
        $this->assertNotEmpty( $desc );
    }

    public function test_get_tier_is_free(): void {
        $this->assertSame( 'free', $this->module->get_tier() );
    }

    // ── Default Settings ──────────────────────────────────────

    public function test_get_default_settings_structure(): void {
        $defaults = $this->module->get_default_settings();

        $this->assertArrayHasKey( 'items_to_clean', $defaults );
        $this->assertArrayHasKey( 'keep_recent_revisions', $defaults );
        $this->assertArrayHasKey( 'optimize_tables', $defaults );

        $this->assertIsArray( $defaults['items_to_clean'] );
        $this->assertIsInt( $defaults['keep_recent_revisions'] );
        $this->assertIsBool( $defaults['optimize_tables'] );
    }

    public function test_default_items_to_clean_has_all_categories(): void {
        $defaults = $this->module->get_default_settings();
        $items    = $defaults['items_to_clean'];

        $expected_keys = [
            'revisions',
            'auto_drafts',
            'trashed_posts',
            'spam_comments',
            'trashed_comments',
            'expired_transients',
            'orphaned_postmeta',
            'orphaned_commentmeta',
            'orphaned_relationships',
        ];

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $items, "Missing category: $key" );
            $this->assertTrue( $items[ $key ], "Category $key should default to true" );
        }
    }

    public function test_default_keep_recent_revisions_is_zero(): void {
        $defaults = $this->module->get_default_settings();
        $this->assertSame( 0, $defaults['keep_recent_revisions'] );
    }

    public function test_default_optimize_tables_is_false(): void {
        $defaults = $this->module->get_default_settings();
        $this->assertFalse( $defaults['optimize_tables'] );
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function test_sanitize_settings_with_valid_data(): void {
        $raw = [
            'wpt_items_to_clean' => [
                'revisions'    => '1',
                'auto_drafts'  => '1',
                'spam_comments' => '1',
            ],
            'wpt_keep_recent_revisions' => '5',
            'wpt_optimize_tables'       => '1',
        ];

        $clean = $this->module->sanitize_settings( $raw );

        $this->assertTrue( $clean['items_to_clean']['revisions'] );
        $this->assertTrue( $clean['items_to_clean']['auto_drafts'] );
        $this->assertFalse( $clean['items_to_clean']['trashed_posts'] );
        $this->assertTrue( $clean['items_to_clean']['spam_comments'] );
        $this->assertFalse( $clean['items_to_clean']['trashed_comments'] );
        $this->assertSame( 5, $clean['keep_recent_revisions'] );
        $this->assertTrue( $clean['optimize_tables'] );
    }

    public function test_sanitize_settings_with_empty_data(): void {
        $clean = $this->module->sanitize_settings( [] );

        // All items should be false when nothing submitted.
        foreach ( $clean['items_to_clean'] as $key => $value ) {
            $this->assertFalse( $value, "Item $key should be false when not submitted" );
        }

        $this->assertSame( 0, $clean['keep_recent_revisions'] );
        $this->assertFalse( $clean['optimize_tables'] );
    }

    public function test_sanitize_settings_clamps_keep_revisions(): void {
        $raw = [
            'wpt_keep_recent_revisions' => '999',
        ];

        $clean = $this->module->sanitize_settings( $raw );
        $this->assertSame( 100, $clean['keep_recent_revisions'] );
    }

    public function test_sanitize_settings_handles_negative_revisions(): void {
        $raw = [
            'wpt_keep_recent_revisions' => '-5',
        ];

        $clean = $this->module->sanitize_settings( $raw );
        // absint converts negative to 0 (unsigned).
        $this->assertSame( 0, $clean['keep_recent_revisions'] );
    }

    public function test_sanitize_settings_ignores_unknown_categories(): void {
        $raw = [
            'wpt_items_to_clean' => [
                'revisions'       => '1',
                'malicious_field' => '1',
            ],
        ];

        $clean = $this->module->sanitize_settings( $raw );

        $this->assertTrue( $clean['items_to_clean']['revisions'] );
        $this->assertArrayNotHasKey( 'malicious_field', $clean['items_to_clean'] );
    }

    public function test_sanitize_settings_returns_correct_structure(): void {
        $clean = $this->module->sanitize_settings( [] );

        $this->assertArrayHasKey( 'items_to_clean', $clean );
        $this->assertArrayHasKey( 'keep_recent_revisions', $clean );
        $this->assertArrayHasKey( 'optimize_tables', $clean );

        $this->assertCount( 9, $clean['items_to_clean'] );
    }

    // ── Size Estimates ────────────────────────────────────────

    public function test_size_estimates_cover_all_categories(): void {
        $estimates = $this->module->get_size_estimates();
        $defaults  = $this->module->get_default_settings();
        $categories = array_keys( $defaults['items_to_clean'] );

        foreach ( $categories as $cat ) {
            $this->assertArrayHasKey( $cat, $estimates, "Missing size estimate for: $cat" );
            $this->assertIsInt( $estimates[ $cat ] );
            $this->assertGreaterThan( 0, $estimates[ $cat ] );
        }
    }

    public function test_revision_size_estimate_is_approximately_3kb(): void {
        $estimates = $this->module->get_size_estimates();
        $this->assertSame( 3072, $estimates['revisions'] );
    }

    public function test_comment_size_estimate(): void {
        $estimates = $this->module->get_size_estimates();
        $this->assertSame( 512, $estimates['spam_comments'] );
        $this->assertSame( 512, $estimates['trashed_comments'] );
    }

    public function test_meta_size_estimate(): void {
        $estimates = $this->module->get_size_estimates();
        $this->assertSame( 200, $estimates['orphaned_postmeta'] );
        $this->assertSame( 200, $estimates['orphaned_commentmeta'] );
    }

    // ── Batch Size ────────────────────────────────────────────

    public function test_batch_size_is_1000(): void {
        $this->assertSame( 1000, $this->module->get_batch_size() );
    }

    // ── Scan Counts (requires WP database) ────────────────────

    public function test_scan_count_for_revisions_starts_at_zero(): void {
        $settings = $this->module->get_default_settings();
        $count    = $this->module->get_count( 'revisions', $settings );
        $this->assertIsInt( $count );
        $this->assertSame( 0, $count );
    }

    public function test_scan_count_for_auto_drafts_starts_at_zero(): void {
        $settings = $this->module->get_default_settings();
        $count    = $this->module->get_count( 'auto_drafts', $settings );
        $this->assertIsInt( $count );
        $this->assertSame( 0, $count );
    }

    public function test_scan_count_for_spam_comments_starts_at_zero(): void {
        $settings = $this->module->get_default_settings();
        $count    = $this->module->get_count( 'spam_comments', $settings );
        $this->assertIsInt( $count );
        $this->assertSame( 0, $count );
    }

    public function test_scan_count_for_invalid_category_returns_zero(): void {
        $settings = $this->module->get_default_settings();
        $count    = $this->module->get_count( 'nonexistent_category', $settings );
        $this->assertSame( 0, $count );
    }

    public function test_scan_count_for_orphaned_postmeta(): void {
        $settings = $this->module->get_default_settings();
        $count    = $this->module->get_count( 'orphaned_postmeta', $settings );
        $this->assertIsInt( $count );
        $this->assertGreaterThanOrEqual( 0, $count );
    }

    // ── Cleanup Returns Integer ───────────────────────────────

    public function test_cleanup_returns_integer_for_valid_category(): void {
        $settings = $this->module->get_default_settings();
        $deleted  = $this->module->run_cleanup( 'revisions', $settings );
        $this->assertIsInt( $deleted );
        $this->assertGreaterThanOrEqual( 0, $deleted );
    }

    public function test_cleanup_returns_zero_for_invalid_category(): void {
        $settings = $this->module->get_default_settings();
        $deleted  = $this->module->run_cleanup( 'nonexistent', $settings );
        $this->assertSame( 0, $deleted );
    }

    // ── Cleanup Integration: Create and Delete ────────────────

    public function test_cleanup_deletes_revisions(): void {
        // Create a post with revisions.
        $post_id = self::factory()->post->create();

        // Create revisions by updating the post.
        wp_update_post( [
            'ID'           => $post_id,
            'post_content' => 'Revision 1',
        ] );
        wp_update_post( [
            'ID'           => $post_id,
            'post_content' => 'Revision 2',
        ] );

        $settings = $this->module->get_default_settings();
        $before   = $this->module->get_count( 'revisions', $settings );
        $this->assertGreaterThan( 0, $before );

        $deleted = $this->module->run_cleanup( 'revisions', $settings );
        $this->assertGreaterThan( 0, $deleted );

        $after = $this->module->get_count( 'revisions', $settings );
        $this->assertSame( 0, $after );
    }

    public function test_cleanup_respects_keep_recent_revisions(): void {
        $post_id = self::factory()->post->create();

        // Create 5 revisions.
        for ( $i = 1; $i <= 5; $i++ ) {
            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => "Revision $i",
            ] );
        }

        $settings = $this->module->get_default_settings();
        $settings['keep_recent_revisions'] = 2;

        $before = $this->module->get_count( 'revisions', $settings );
        // Should have more than 2 revisions.
        $this->assertGreaterThan( 0, $before );

        $this->module->run_cleanup( 'revisions', $settings );

        // After cleanup with keep=2, check with keep=0 to see remaining.
        $settings_all = $this->module->get_default_settings();
        $remaining    = $this->module->get_count( 'revisions', $settings_all );
        $this->assertLessThanOrEqual( 2, $remaining );
    }

    public function test_cleanup_deletes_spam_comments(): void {
        // Create a spam comment.
        self::factory()->comment->create( [
            'comment_approved' => 'spam',
        ] );

        $settings = $this->module->get_default_settings();
        $before   = $this->module->get_count( 'spam_comments', $settings );
        $this->assertGreaterThan( 0, $before );

        $this->module->run_cleanup( 'spam_comments', $settings );

        $after = $this->module->get_count( 'spam_comments', $settings );
        $this->assertSame( 0, $after );
    }

    public function test_cleanup_deletes_trashed_posts(): void {
        self::factory()->post->create( [
            'post_status' => 'trash',
        ] );

        $settings = $this->module->get_default_settings();
        $before   = $this->module->get_count( 'trashed_posts', $settings );
        $this->assertGreaterThan( 0, $before );

        $this->module->run_cleanup( 'trashed_posts', $settings );

        $after = $this->module->get_count( 'trashed_posts', $settings );
        $this->assertSame( 0, $after );
    }

    public function test_cleanup_deletes_auto_drafts(): void {
        self::factory()->post->create( [
            'post_status' => 'auto-draft',
        ] );

        $settings = $this->module->get_default_settings();
        $before   = $this->module->get_count( 'auto_drafts', $settings );
        $this->assertGreaterThan( 0, $before );

        $this->module->run_cleanup( 'auto_drafts', $settings );

        $after = $this->module->get_count( 'auto_drafts', $settings );
        $this->assertSame( 0, $after );
    }

    // ── Cleanup Tasks ─────────────────────────────────────────

    public function test_get_cleanup_tasks_returns_empty_array(): void {
        $tasks = $this->module->get_cleanup_tasks();
        $this->assertIsArray( $tasks );
        $this->assertEmpty( $tasks );
    }
}
