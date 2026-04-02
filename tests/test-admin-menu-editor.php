<?php
declare(strict_types=1);

/**
 * Tests for Admin Menu Editor module.
 *
 * @package WPTransformed
 */

use WPTransformed\Modules\AdminInterface\Admin_Menu_Editor;

class Test_Admin_Menu_Editor extends WP_UnitTestCase {

    /** @var Admin_Menu_Editor */
    private Admin_Menu_Editor $module;

    public function setUp(): void {
        parent::setUp();
        $this->module = new Admin_Menu_Editor();
    }

    // ── Identity ──────────────────────────────────────────────

    public function test_get_id(): void {
        $this->assertSame( 'admin-menu-editor', $this->module->get_id() );
    }

    public function test_get_title(): void {
        $this->assertIsString( $this->module->get_title() );
        $this->assertNotEmpty( $this->module->get_title() );
    }

    public function test_get_category(): void {
        $this->assertSame( 'admin-interface', $this->module->get_category() );
    }

    public function test_get_description(): void {
        $this->assertIsString( $this->module->get_description() );
        $this->assertNotEmpty( $this->module->get_description() );
    }

    // ── Default Settings ──────────────────────────────────────

    public function test_get_default_settings_returns_expected_structure(): void {
        $defaults = $this->module->get_default_settings();

        $this->assertIsArray( $defaults );
        $this->assertArrayHasKey( 'menu_order', $defaults );
        $this->assertArrayHasKey( 'hidden_items', $defaults );
        $this->assertArrayHasKey( 'renamed_items', $defaults );
        $this->assertArrayHasKey( 'custom_icons', $defaults );
        $this->assertArrayHasKey( 'separators', $defaults );

        $this->assertSame( [], $defaults['menu_order'] );
        $this->assertSame( [], $defaults['hidden_items'] );
        $this->assertSame( [], $defaults['renamed_items'] );
        $this->assertSame( [], $defaults['custom_icons'] );
        $this->assertSame( [], $defaults['separators'] );
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function test_sanitize_settings_valid_data(): void {
        $raw = [
            'wpt_menu_order'   => 'index.php,edit.php,upload.php',
            'wpt_hidden_items' => 'tools.php,options-general.php',
            'wpt_renamed_json' => wp_json_encode( [ 'index.php' => 'Home', 'edit.php' => 'Blog' ] ),
            'wpt_icons_json'   => wp_json_encode( [ 'index.php' => 'dashicons-admin-home' ] ),
            'wpt_separators'   => 'index.php,edit.php',
        ];

        $result = $this->module->sanitize_settings( $raw );

        $this->assertSame( [ 'index.php', 'edit.php', 'upload.php' ], $result['menu_order'] );
        $this->assertSame( [ 'tools.php', 'options-general.php' ], $result['hidden_items'] );
        $this->assertSame( [ 'index.php' => 'Home', 'edit.php' => 'Blog' ], $result['renamed_items'] );
        $this->assertSame( [ 'index.php' => 'dashicons-admin-home' ], $result['custom_icons'] );
        $this->assertSame( [ 'index.php', 'edit.php' ], $result['separators'] );
    }

    public function test_sanitize_settings_strips_empty_slugs(): void {
        $raw = [
            'wpt_menu_order'   => 'index.php,,edit.php,',
            'wpt_hidden_items' => ',tools.php,,',
            'wpt_renamed_json' => wp_json_encode( [ '' => 'Empty', 'edit.php' => 'Blog' ] ),
            'wpt_icons_json'   => '{}',
            'wpt_separators'   => '',
        ];

        $result = $this->module->sanitize_settings( $raw );

        $this->assertSame( [ 'index.php', 'edit.php' ], $result['menu_order'] );
        $this->assertSame( [ 'tools.php' ], $result['hidden_items'] );
        $this->assertSame( [ 'edit.php' => 'Blog' ], $result['renamed_items'] );
        $this->assertSame( [], $result['custom_icons'] );
        $this->assertSame( [], $result['separators'] );
    }

    public function test_sanitize_settings_strips_html_from_labels(): void {
        $raw = [
            'wpt_menu_order'   => '',
            'wpt_hidden_items' => '',
            'wpt_renamed_json' => wp_json_encode( [
                'index.php' => '<script>alert("xss")</script>Dashboard',
                'edit.php'  => '<b>Posts</b>',
            ] ),
            'wpt_icons_json'   => '{}',
            'wpt_separators'   => '',
        ];

        $result = $this->module->sanitize_settings( $raw );

        $this->assertSame( 'Dashboard', $result['renamed_items']['index.php'] );
        $this->assertSame( 'Posts', $result['renamed_items']['edit.php'] );
    }

    public function test_sanitize_settings_invalid_json_returns_empty(): void {
        $raw = [
            'wpt_menu_order'   => '',
            'wpt_hidden_items' => '',
            'wpt_renamed_json' => 'not-valid-json{{{',
            'wpt_icons_json'   => 'also-broken',
            'wpt_separators'   => '',
        ];

        $result = $this->module->sanitize_settings( $raw );

        $this->assertSame( [], $result['renamed_items'] );
        $this->assertSame( [], $result['custom_icons'] );
    }

    public function test_sanitize_settings_empty_input(): void {
        $result = $this->module->sanitize_settings( [] );

        $this->assertSame( [], $result['menu_order'] );
        $this->assertSame( [], $result['hidden_items'] );
        $this->assertSame( [], $result['renamed_items'] );
        $this->assertSame( [], $result['custom_icons'] );
        $this->assertSame( [], $result['separators'] );
    }

    public function test_sanitize_settings_strips_invalid_icon_classes(): void {
        $raw = [
            'wpt_menu_order'   => '',
            'wpt_hidden_items' => '',
            'wpt_renamed_json' => '{}',
            'wpt_icons_json'   => wp_json_encode( [
                'index.php' => 'dashicons-admin-home',
                'edit.php'  => '<script>bad</script>',
                'tools.php' => 'dashicons-admin-tools',
            ] ),
            'wpt_separators'   => '',
        ];

        $result = $this->module->sanitize_settings( $raw );

        $this->assertSame( 'dashicons-admin-home', $result['custom_icons']['index.php'] );
        $this->assertSame( 'dashicons-admin-tools', $result['custom_icons']['tools.php'] );
        // The invalid one should be stripped (sanitize_html_class removes non-class chars)
        $this->assertArrayNotHasKey( 'edit.php', $result['custom_icons'] );
    }

    // ── Menu Order Filter ─────────────────────────────────────

    public function test_filter_menu_order_appends_unknown_items(): void {
        // Simulate saved order with some items.
        $saved_order = [ 'index.php', 'edit.php', 'upload.php' ];

        // Current WP menu order includes items not in saved order.
        $current_order = [ 'index.php', 'edit.php', 'upload.php', 'themes.php', 'plugins.php' ];

        // The module should return saved order + unknown items appended.
        $result = $this->module->apply_menu_order( $saved_order, $current_order );

        $this->assertSame( [ 'index.php', 'edit.php', 'upload.php', 'themes.php', 'plugins.php' ], $result );
    }

    public function test_filter_menu_order_skips_removed_plugins(): void {
        // Saved order has a slug that no longer exists in current menu.
        $saved_order = [ 'index.php', 'nonexistent-plugin.php', 'edit.php' ];
        $current_order = [ 'index.php', 'edit.php', 'upload.php' ];

        $result = $this->module->apply_menu_order( $saved_order, $current_order );

        // nonexistent-plugin.php should be silently skipped, upload.php appended.
        $this->assertSame( [ 'index.php', 'edit.php', 'upload.php' ], $result );
    }

    public function test_filter_menu_order_empty_saved_returns_current(): void {
        $saved_order = [];
        $current_order = [ 'index.php', 'edit.php' ];

        $result = $this->module->apply_menu_order( $saved_order, $current_order );

        $this->assertSame( [ 'index.php', 'edit.php' ], $result );
    }

    // ── Hidden Items ──────────────────────────────────────────

    public function test_apply_hidden_items_removes_from_menu(): void {
        global $menu;
        $menu = [
            2  => [ 'Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard' ],
            5  => [ 'Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts', 'dashicons-admin-post' ],
            10 => [ 'Media', 'upload_files', 'upload.php', '', 'menu-top', 'menu-media', 'dashicons-admin-media' ],
        ];

        $hidden = [ 'edit.php' ];

        $this->module->apply_hidden_items( $hidden );

        // edit.php should be removed.
        $slugs = array_column( $menu, 2 );
        $this->assertNotContains( 'edit.php', $slugs );
        $this->assertContains( 'index.php', $slugs );
        $this->assertContains( 'upload.php', $slugs );
    }

    // ── Renamed Items ─────────────────────────────────────────

    public function test_apply_renamed_items_updates_labels(): void {
        global $menu;
        $menu = [
            2  => [ 'Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard' ],
            5  => [ 'Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts', 'dashicons-admin-post' ],
        ];

        $renamed = [ 'index.php' => 'Home', 'edit.php' => 'Blog' ];

        $this->module->apply_renamed_items( $renamed );

        $labels = [];
        foreach ( $menu as $item ) {
            $labels[ $item[2] ] = $item[0];
        }

        $this->assertSame( 'Home', $labels['index.php'] );
        $this->assertSame( 'Blog', $labels['edit.php'] );
    }

    // ── Custom Icons ──────────────────────────────────────────

    public function test_apply_custom_icons_updates_icons(): void {
        global $menu;
        $menu = [
            2  => [ 'Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard' ],
            5  => [ 'Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts', 'dashicons-admin-post' ],
        ];

        $icons = [ 'index.php' => 'dashicons-heart' ];

        $this->module->apply_custom_icons( $icons );

        $icon_map = [];
        foreach ( $menu as $item ) {
            $icon_map[ $item[2] ] = $item[6];
        }

        $this->assertSame( 'dashicons-heart', $icon_map['index.php'] );
        $this->assertSame( 'dashicons-admin-post', $icon_map['edit.php'] ); // unchanged
    }

    // ── Cleanup Tasks ─────────────────────────────────────────

    public function test_get_cleanup_tasks_returns_array(): void {
        $tasks = $this->module->get_cleanup_tasks();
        $this->assertIsArray( $tasks );
    }
}
