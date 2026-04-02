<?php
declare(strict_types=1);

namespace WPTransformed\Modules\ContentManagement;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Custom Content Types -- Register custom post types and taxonomies via UI.
 *
 * Features:
 *  - Register CPTs and taxonomies from saved settings (init priority 5)
 *  - Auto-generate all labels from singular/plural names
 *  - Flush rewrite rules on save via transient flag
 *  - AJAX CRUD for post types and taxonomies
 *  - Slug conflict validation against WP reserved types
 *  - Settings stored in module settings JSON (no separate DB tables)
 *
 * @package WPTransformed
 */
class Custom_Content_Types extends Module_Base {

    /**
     * Reserved slugs that cannot be used for custom post types or taxonomies.
     */
    private const RESERVED_SLUGS = [
        'post',
        'page',
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'action',
        'author',
        'order',
        'theme',
        'type',
        'category',
        'post_tag',
        'nav_menu',
        'link_category',
        'post_format',
        'wp_theme',
        'wp_pattern_category',
    ];

    /**
     * Transient key for rewrite flush flag.
     */
    private const FLUSH_TRANSIENT = 'wpt_cct_flush_rewrite';

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'custom-content-types';
    }

    public function get_title(): string {
        return __( 'Custom Content Types', 'wptransformed' );
    }

    public function get_category(): string {
        return 'content-management';
    }

    public function get_description(): string {
        return __( 'Register custom post types and taxonomies with a visual interface.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'post_types' => [],
            'taxonomies' => [],
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        // Register CPTs and taxonomies early so other plugins can reference them.
        add_action( 'init', [ $this, 'register_post_types' ], 5 );
        add_action( 'init', [ $this, 'register_taxonomies' ], 5 );

        // Flush rewrite rules once after save.
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 99 );

        // AJAX handlers.
        add_action( 'wp_ajax_wpt_cct_save_post_type',   [ $this, 'ajax_save_post_type' ] );
        add_action( 'wp_ajax_wpt_cct_delete_post_type',  [ $this, 'ajax_delete_post_type' ] );
        add_action( 'wp_ajax_wpt_cct_save_taxonomy',     [ $this, 'ajax_save_taxonomy' ] );
        add_action( 'wp_ajax_wpt_cct_delete_taxonomy',   [ $this, 'ajax_delete_taxonomy' ] );
    }

    // ── Register Post Types ──────────────────────────────────

    /**
     * Register all saved custom post types.
     */
    public function register_post_types(): void {
        $settings   = $this->get_settings();
        $post_types = $settings['post_types'] ?? [];

        if ( ! is_array( $post_types ) || empty( $post_types ) ) {
            return;
        }

        foreach ( $post_types as $pt ) {
            if ( ! is_array( $pt ) || empty( $pt['slug'] ) ) {
                continue;
            }

            $slug = sanitize_key( $pt['slug'] );

            // Skip if already registered (e.g., by another plugin).
            if ( post_type_exists( $slug ) ) {
                continue;
            }

            $singular = sanitize_text_field( $pt['singular'] ?? $slug );
            $plural   = sanitize_text_field( $pt['plural'] ?? $singular . 's' );

            $labels = $this->generate_post_type_labels( $singular, $plural );

            $supports = [];
            if ( ! empty( $pt['supports'] ) && is_array( $pt['supports'] ) ) {
                $allowed_supports = [ 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'revisions', 'author', 'page-attributes', 'custom-fields' ];
                $supports = array_intersect( $pt['supports'], $allowed_supports );
            }
            if ( empty( $supports ) ) {
                $supports = [ 'title', 'editor', 'thumbnail' ];
            }

            $args = [
                'labels'       => $labels,
                'public'       => ! empty( $pt['public'] ),
                'has_archive'  => ! empty( $pt['has_archive'] ),
                'show_in_rest' => ! empty( $pt['show_in_rest'] ),
                'menu_icon'    => $this->sanitize_menu_icon( $pt['menu_icon'] ?? 'dashicons-admin-post' ),
                'supports'     => $supports,
                'rewrite'      => ! empty( $pt['rewrite'] ) ? [ 'slug' => sanitize_key( $pt['rewrite'] ) ] : [ 'slug' => $slug ],
                'show_ui'      => true,
                'show_in_menu' => true,
            ];

            register_post_type( $slug, $args );
        }
    }

    // ── Register Taxonomies ──────────────────────────────────

    /**
     * Register all saved custom taxonomies.
     */
    public function register_taxonomies(): void {
        $settings   = $this->get_settings();
        $taxonomies = $settings['taxonomies'] ?? [];

        if ( ! is_array( $taxonomies ) || empty( $taxonomies ) ) {
            return;
        }

        foreach ( $taxonomies as $tax ) {
            if ( ! is_array( $tax ) || empty( $tax['slug'] ) ) {
                continue;
            }

            $slug = sanitize_key( $tax['slug'] );

            // Skip if already registered.
            if ( taxonomy_exists( $slug ) ) {
                continue;
            }

            $singular = sanitize_text_field( $tax['singular'] ?? $slug );
            $plural   = sanitize_text_field( $tax['plural'] ?? $singular . 's' );

            $labels = $this->generate_taxonomy_labels( $singular, $plural );

            // Filter post_types to only those that actually exist.
            $associated_types = [];
            if ( ! empty( $tax['post_types'] ) && is_array( $tax['post_types'] ) ) {
                foreach ( $tax['post_types'] as $pt_slug ) {
                    $pt_slug = sanitize_key( $pt_slug );
                    if ( post_type_exists( $pt_slug ) ) {
                        $associated_types[] = $pt_slug;
                    }
                }
            }

            $args = [
                'labels'       => $labels,
                'hierarchical' => ! empty( $tax['hierarchical'] ),
                'show_in_rest' => ! empty( $tax['show_in_rest'] ),
                'show_ui'      => true,
                'show_admin_column' => true,
                'rewrite'      => ! empty( $tax['rewrite'] ) ? [ 'slug' => sanitize_key( $tax['rewrite'] ) ] : [ 'slug' => $slug ],
            ];

            register_taxonomy( $slug, $associated_types, $args );
        }
    }

    // ── Rewrite Rules Flush ──────────────────────────────────

    /**
     * Check flag and flush rewrite rules once after save.
     * Uses option (not transient) for reliability on WP Engine with persistent object cache.
     */
    public function maybe_flush_rewrite_rules(): void {
        if ( get_option( self::FLUSH_TRANSIENT ) ) {
            delete_option( self::FLUSH_TRANSIENT );
            flush_rewrite_rules( false );
        }
    }

    /**
     * Set the flag to flush rewrite rules on next init.
     */
    private function set_flush_flag(): void {
        update_option( self::FLUSH_TRANSIENT, '1', false );
    }

    // ── Label Generators ─────────────────────────────────────

    /**
     * Generate all CPT labels from singular/plural names.
     *
     * @param string $singular Singular name.
     * @param string $plural   Plural name.
     * @return array WordPress labels array.
     */
    public function generate_post_type_labels( string $singular, string $plural ): array {
        return [
            'name'                  => $plural,
            'singular_name'         => $singular,
            /* translators: %s: plural post type name */
            'all_items'             => sprintf( __( 'All %s', 'wptransformed' ), $plural ),
            /* translators: %s: singular post type name */
            'add_new_item'          => sprintf( __( 'Add New %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular post type name */
            'edit_item'             => sprintf( __( 'Edit %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular post type name */
            'new_item'              => sprintf( __( 'New %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular post type name */
            'view_item'             => sprintf( __( 'View %s', 'wptransformed' ), $singular ),
            /* translators: %s: plural post type name */
            'view_items'            => sprintf( __( 'View %s', 'wptransformed' ), $plural ),
            /* translators: %s: plural post type name */
            'search_items'          => sprintf( __( 'Search %s', 'wptransformed' ), $plural ),
            /* translators: %s: plural post type name */
            'not_found'             => sprintf( __( 'No %s found.', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: plural post type name */
            'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: singular post type name */
            'parent_item_colon'     => sprintf( __( 'Parent %s:', 'wptransformed' ), $singular ),
            'add_new'               => __( 'Add New', 'wptransformed' ),
            /* translators: %s: singular post type name */
            'archives'              => sprintf( __( '%s Archives', 'wptransformed' ), $singular ),
            /* translators: %s: singular post type name */
            'insert_into_item'      => sprintf( __( 'Insert into %s', 'wptransformed' ), strtolower( $singular ) ),
            /* translators: %s: singular post type name */
            'uploaded_to_this_item' => sprintf( __( 'Uploaded to this %s', 'wptransformed' ), strtolower( $singular ) ),
            /* translators: %s: plural post type name */
            'filter_items_list'     => sprintf( __( 'Filter %s list', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: plural post type name */
            'items_list_navigation' => sprintf( __( '%s list navigation', 'wptransformed' ), $plural ),
            /* translators: %s: plural post type name */
            'items_list'            => sprintf( __( '%s list', 'wptransformed' ), $plural ),
            'menu_name'             => $plural,
            'name_admin_bar'        => $singular,
        ];
    }

    /**
     * Generate all taxonomy labels from singular/plural names.
     *
     * @param string $singular Singular name.
     * @param string $plural   Plural name.
     * @return array WordPress labels array.
     */
    public function generate_taxonomy_labels( string $singular, string $plural ): array {
        return [
            'name'                       => $plural,
            'singular_name'              => $singular,
            /* translators: %s: plural taxonomy name */
            'all_items'                  => sprintf( __( 'All %s', 'wptransformed' ), $plural ),
            /* translators: %s: singular taxonomy name */
            'edit_item'                  => sprintf( __( 'Edit %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular taxonomy name */
            'view_item'                  => sprintf( __( 'View %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular taxonomy name */
            'update_item'                => sprintf( __( 'Update %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular taxonomy name */
            'add_new_item'               => sprintf( __( 'Add New %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular taxonomy name */
            'new_item_name'              => sprintf( __( 'New %s Name', 'wptransformed' ), $singular ),
            /* translators: %s: singular taxonomy name */
            'parent_item'                => sprintf( __( 'Parent %s', 'wptransformed' ), $singular ),
            /* translators: %s: singular taxonomy name */
            'parent_item_colon'          => sprintf( __( 'Parent %s:', 'wptransformed' ), $singular ),
            /* translators: %s: plural taxonomy name */
            'search_items'               => sprintf( __( 'Search %s', 'wptransformed' ), $plural ),
            /* translators: %s: plural taxonomy name */
            'popular_items'              => sprintf( __( 'Popular %s', 'wptransformed' ), $plural ),
            /* translators: %s: plural taxonomy name */
            'separate_items_with_commas' => sprintf( __( 'Separate %s with commas', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: plural taxonomy name */
            'add_or_remove_items'        => sprintf( __( 'Add or remove %s', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: plural taxonomy name */
            'choose_from_most_used'      => sprintf( __( 'Choose from the most used %s', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: plural taxonomy name */
            'not_found'                  => sprintf( __( 'No %s found.', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: plural taxonomy name */
            'no_terms'                   => sprintf( __( 'No %s', 'wptransformed' ), strtolower( $plural ) ),
            /* translators: %s: plural taxonomy name */
            'items_list_navigation'      => sprintf( __( '%s list navigation', 'wptransformed' ), $plural ),
            /* translators: %s: plural taxonomy name */
            'items_list'                 => sprintf( __( '%s list', 'wptransformed' ), $plural ),
            'menu_name'                  => $plural,
            'back_to_items'              => sprintf( __( '&larr; Back to %s', 'wptransformed' ), $plural ),
        ];
    }

    // ── Slug Validation ──────────────────────────────────────

    /**
     * Check if a slug conflicts with reserved WP types.
     *
     * @param string $slug The slug to check.
     * @return bool True if the slug is reserved/conflicting.
     */
    public function is_reserved_slug( string $slug ): bool {
        $slug = sanitize_key( $slug );

        if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Validate a slug for use as a CPT or taxonomy.
     *
     * @param string $slug    The slug to validate.
     * @param string $context 'post_type' or 'taxonomy'.
     * @param string $editing_slug Slug being edited (for rename check). Empty for new items.
     * @return string Error message if invalid, empty string if valid.
     */
    public function validate_slug( string $slug, string $context = 'post_type', string $editing_slug = '' ): string {
        $slug = sanitize_key( $slug );

        if ( $slug === '' ) {
            return __( 'Slug cannot be empty.', 'wptransformed' );
        }

        if ( strlen( $slug ) > 20 ) {
            return __( 'Slug must be 20 characters or fewer.', 'wptransformed' );
        }

        if ( ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
            return __( 'Slug may only contain lowercase letters, numbers, hyphens, and underscores.', 'wptransformed' );
        }

        if ( $this->is_reserved_slug( $slug ) ) {
            return __( 'This slug is reserved by WordPress and cannot be used.', 'wptransformed' );
        }

        // Check for duplicate in our saved settings.
        $settings = $this->get_settings();
        $key      = $context === 'taxonomy' ? 'taxonomies' : 'post_types';
        $items    = $settings[ $key ] ?? [];

        foreach ( $items as $item ) {
            if ( isset( $item['slug'] ) && $item['slug'] === $slug && $slug !== $editing_slug ) {
                return sprintf(
                    /* translators: %s: slug */
                    __( 'The slug "%s" is already in use.', 'wptransformed' ),
                    $slug
                );
            }
        }

        return '';
    }

    // ── Menu Icon Sanitization ───────────────────────────────

    /**
     * Sanitize a dashicons class name.
     *
     * @param string $icon The icon class.
     * @return string Sanitized icon class or default.
     */
    private function sanitize_menu_icon( string $icon ): string {
        $icon = sanitize_text_field( $icon );

        if ( $icon === '' || ( strpos( $icon, 'dashicons-' ) !== 0 ) ) {
            return 'dashicons-admin-post';
        }

        if ( ! preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
            return 'dashicons-admin-post';
        }

        return $icon;
    }

    // ── AJAX: Save Post Type ─────────────────────────────────

    /**
     * Add or edit a custom post type via AJAX.
     */
    public function ajax_save_post_type(): void {
        check_ajax_referer( 'wpt_cct_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $slug         = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
        $editing_slug = isset( $_POST['editing_slug'] ) ? sanitize_key( wp_unslash( $_POST['editing_slug'] ) ) : '';
        $singular     = isset( $_POST['singular'] ) ? sanitize_text_field( wp_unslash( $_POST['singular'] ) ) : '';
        $plural       = isset( $_POST['plural'] ) ? sanitize_text_field( wp_unslash( $_POST['plural'] ) ) : '';
        $public       = ! empty( $_POST['public'] );
        $has_archive  = ! empty( $_POST['has_archive'] );
        $show_in_rest = ! empty( $_POST['show_in_rest'] );
        $menu_icon    = isset( $_POST['menu_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_icon'] ) ) : 'dashicons-admin-post';
        $rewrite      = isset( $_POST['rewrite'] ) ? sanitize_key( wp_unslash( $_POST['rewrite'] ) ) : '';

        $supports_raw = isset( $_POST['supports'] ) ? (array) $_POST['supports'] : [ 'title', 'editor', 'thumbnail' ];
        $allowed_supports = [ 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'revisions', 'author', 'page-attributes', 'custom-fields' ];
        $supports = array_values( array_intersect( array_map( 'sanitize_key', $supports_raw ), $allowed_supports ) );

        // Validate required fields.
        if ( $singular === '' || $plural === '' ) {
            wp_send_json_error( [ 'message' => __( 'Singular and plural names are required.', 'wptransformed' ) ] );
        }

        // Validate slug.
        $slug_error = $this->validate_slug( $slug, 'post_type', $editing_slug );
        if ( $slug_error !== '' ) {
            wp_send_json_error( [ 'message' => $slug_error ] );
        }

        $new_pt = [
            'slug'         => $slug,
            'singular'     => $singular,
            'plural'       => $plural,
            'public'       => $public,
            'has_archive'  => $has_archive,
            'show_in_rest' => $show_in_rest,
            'menu_icon'    => $this->sanitize_menu_icon( $menu_icon ),
            'supports'     => $supports,
            'rewrite'      => $rewrite !== '' ? $rewrite : $slug,
        ];

        $settings   = $this->get_settings();
        $post_types = $settings['post_types'] ?? [];

        if ( $editing_slug !== '' ) {
            // Update existing.
            $found = false;
            foreach ( $post_types as $i => $pt ) {
                if ( isset( $pt['slug'] ) && $pt['slug'] === $editing_slug ) {
                    $post_types[ $i ] = $new_pt;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                wp_send_json_error( [ 'message' => __( 'Post type not found.', 'wptransformed' ) ] );
            }
        } else {
            // Add new.
            $post_types[] = $new_pt;
        }

        $settings['post_types'] = array_values( $post_types );

        \WPTransformed\Core\Settings::save( $this->get_id(), $settings );
        $this->set_flush_flag();

        wp_send_json_success( [
            'message'    => $editing_slug !== ''
                ? __( 'Post type updated successfully.', 'wptransformed' )
                : __( 'Post type created successfully.', 'wptransformed' ),
            'post_type'  => $new_pt,
            'post_types' => $settings['post_types'],
        ] );
    }

    // ── AJAX: Delete Post Type ───────────────────────────────

    /**
     * Delete a custom post type via AJAX.
     */
    public function ajax_delete_post_type(): void {
        check_ajax_referer( 'wpt_cct_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

        if ( $slug === '' ) {
            wp_send_json_error( [ 'message' => __( 'Invalid slug.', 'wptransformed' ) ] );
        }

        $settings   = $this->get_settings();
        $post_types = $settings['post_types'] ?? [];
        $found      = false;

        foreach ( $post_types as $i => $pt ) {
            if ( isset( $pt['slug'] ) && $pt['slug'] === $slug ) {
                unset( $post_types[ $i ] );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => __( 'Post type not found.', 'wptransformed' ) ] );
        }

        // Also remove this CPT from any taxonomy associations.
        $taxonomies = $settings['taxonomies'] ?? [];
        foreach ( $taxonomies as $i => $tax ) {
            if ( ! empty( $tax['post_types'] ) && is_array( $tax['post_types'] ) ) {
                $taxonomies[ $i ]['post_types'] = array_values(
                    array_filter( $tax['post_types'], function( $pt_slug ) use ( $slug ) {
                        return $pt_slug !== $slug;
                    } )
                );
            }
        }

        $settings['post_types'] = array_values( $post_types );
        $settings['taxonomies'] = array_values( $taxonomies );

        \WPTransformed\Core\Settings::save( $this->get_id(), $settings );
        $this->set_flush_flag();

        wp_send_json_success( [
            'message'    => __( 'Post type deleted successfully.', 'wptransformed' ),
            'post_types' => $settings['post_types'],
            'taxonomies' => $settings['taxonomies'],
        ] );
    }

    // ── AJAX: Save Taxonomy ──────────────────────────────────

    /**
     * Add or edit a custom taxonomy via AJAX.
     */
    public function ajax_save_taxonomy(): void {
        check_ajax_referer( 'wpt_cct_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $slug         = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
        $editing_slug = isset( $_POST['editing_slug'] ) ? sanitize_key( wp_unslash( $_POST['editing_slug'] ) ) : '';
        $singular     = isset( $_POST['singular'] ) ? sanitize_text_field( wp_unslash( $_POST['singular'] ) ) : '';
        $plural       = isset( $_POST['plural'] ) ? sanitize_text_field( wp_unslash( $_POST['plural'] ) ) : '';
        $hierarchical = ! empty( $_POST['hierarchical'] );
        $show_in_rest = ! empty( $_POST['show_in_rest'] );
        $rewrite      = isset( $_POST['rewrite'] ) ? sanitize_key( wp_unslash( $_POST['rewrite'] ) ) : '';

        $post_types_raw = isset( $_POST['post_types'] ) ? (array) $_POST['post_types'] : [];
        $post_types     = array_values( array_map( 'sanitize_key', $post_types_raw ) );

        // Validate required fields.
        if ( $singular === '' || $plural === '' ) {
            wp_send_json_error( [ 'message' => __( 'Singular and plural names are required.', 'wptransformed' ) ] );
        }

        // Validate slug.
        $slug_error = $this->validate_slug( $slug, 'taxonomy', $editing_slug );
        if ( $slug_error !== '' ) {
            wp_send_json_error( [ 'message' => $slug_error ] );
        }

        $new_tax = [
            'slug'         => $slug,
            'singular'     => $singular,
            'plural'       => $plural,
            'post_types'   => $post_types,
            'hierarchical' => $hierarchical,
            'show_in_rest' => $show_in_rest,
            'rewrite'      => $rewrite !== '' ? $rewrite : $slug,
        ];

        $settings   = $this->get_settings();
        $taxonomies = $settings['taxonomies'] ?? [];

        if ( $editing_slug !== '' ) {
            // Update existing.
            $found = false;
            foreach ( $taxonomies as $i => $tax ) {
                if ( isset( $tax['slug'] ) && $tax['slug'] === $editing_slug ) {
                    $taxonomies[ $i ] = $new_tax;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                wp_send_json_error( [ 'message' => __( 'Taxonomy not found.', 'wptransformed' ) ] );
            }
        } else {
            // Add new.
            $taxonomies[] = $new_tax;
        }

        $settings['taxonomies'] = array_values( $taxonomies );

        \WPTransformed\Core\Settings::save( $this->get_id(), $settings );
        $this->set_flush_flag();

        wp_send_json_success( [
            'message'    => $editing_slug !== ''
                ? __( 'Taxonomy updated successfully.', 'wptransformed' )
                : __( 'Taxonomy created successfully.', 'wptransformed' ),
            'taxonomy'   => $new_tax,
            'taxonomies' => $settings['taxonomies'],
        ] );
    }

    // ── AJAX: Delete Taxonomy ────────────────────────────────

    /**
     * Delete a custom taxonomy via AJAX.
     */
    public function ajax_delete_taxonomy(): void {
        check_ajax_referer( 'wpt_cct_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wptransformed' ) ] );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

        if ( $slug === '' ) {
            wp_send_json_error( [ 'message' => __( 'Invalid slug.', 'wptransformed' ) ] );
        }

        $settings   = $this->get_settings();
        $taxonomies = $settings['taxonomies'] ?? [];
        $found      = false;

        foreach ( $taxonomies as $i => $tax ) {
            if ( isset( $tax['slug'] ) && $tax['slug'] === $slug ) {
                unset( $taxonomies[ $i ] );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => __( 'Taxonomy not found.', 'wptransformed' ) ] );
        }

        $settings['taxonomies'] = array_values( $taxonomies );

        \WPTransformed\Core\Settings::save( $this->get_id(), $settings );
        $this->set_flush_flag();

        wp_send_json_success( [
            'message'    => __( 'Taxonomy deleted successfully.', 'wptransformed' ),
            'taxonomies' => $settings['taxonomies'],
        ] );
    }

    // ── Assets ────────────────────────────────────────────────

    /**
     * Enqueue admin assets only on the WPTransformed settings page.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_wptransformed' ) {
            return;
        }

        wp_enqueue_style(
            'wpt-custom-content-types',
            WPT_URL . 'modules/content-management/css/custom-content-types.css',
            [],
            WPT_VERSION
        );

        wp_enqueue_script(
            'wpt-custom-content-types',
            WPT_URL . 'modules/content-management/js/custom-content-types.js',
            [],
            WPT_VERSION,
            true
        );

        wp_localize_script( 'wpt-custom-content-types', 'wptCCT', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpt_cct_nonce' ),
            'i18n'    => [
                'confirmDeletePT'  => __( 'Are you sure you want to delete this post type? This will not delete any existing content.', 'wptransformed' ),
                'confirmDeleteTax' => __( 'Are you sure you want to delete this taxonomy? This will not delete any existing terms.', 'wptransformed' ),
                'saving'           => __( 'Saving...', 'wptransformed' ),
                'deleting'         => __( 'Deleting...', 'wptransformed' ),
                'error'            => __( 'An error occurred. Please try again.', 'wptransformed' ),
            ],
        ] );
    }

    // ── Settings UI ───────────────────────────────────────────

    /**
     * Render the settings page UI with tabs for Post Types and Taxonomies.
     */
    public function render_settings(): void {
        $settings   = $this->get_settings();
        $post_types = $settings['post_types'] ?? [];
        $taxonomies = $settings['taxonomies'] ?? [];

        // Build list of all available post types for taxonomy association.
        $all_post_types = [];
        // Include our custom CPTs.
        foreach ( $post_types as $pt ) {
            if ( ! empty( $pt['slug'] ) ) {
                $all_post_types[ $pt['slug'] ] = $pt['plural'] ?? $pt['slug'];
            }
        }
        // Include built-in public post types.
        $builtin = get_post_types( [ 'public' => true ], 'objects' );
        foreach ( $builtin as $bpt ) {
            if ( ! isset( $all_post_types[ $bpt->name ] ) ) {
                $all_post_types[ $bpt->name ] = $bpt->labels->name;
            }
        }
        ?>

        <div class="wpt-cct-wrap">
            <!-- Tabs -->
            <div class="wpt-cct-tabs">
                <button type="button" class="wpt-cct-tab active" data-tab="post-types">
                    <?php esc_html_e( 'Post Types', 'wptransformed' ); ?>
                    <span class="wpt-cct-count"><?php echo count( $post_types ); ?></span>
                </button>
                <button type="button" class="wpt-cct-tab" data-tab="taxonomies">
                    <?php esc_html_e( 'Taxonomies', 'wptransformed' ); ?>
                    <span class="wpt-cct-count"><?php echo count( $taxonomies ); ?></span>
                </button>
            </div>

            <!-- Post Types Tab -->
            <div class="wpt-cct-panel active" data-panel="post-types">
                <div class="wpt-cct-header">
                    <h3><?php esc_html_e( 'Custom Post Types', 'wptransformed' ); ?></h3>
                    <button type="button" class="button button-primary" id="wpt-cct-add-pt">
                        <?php esc_html_e( 'Add Post Type', 'wptransformed' ); ?>
                    </button>
                </div>

                <div id="wpt-cct-pt-list" class="wpt-cct-list">
                    <?php if ( empty( $post_types ) ) : ?>
                        <p class="wpt-cct-empty"><?php esc_html_e( 'No custom post types registered yet.', 'wptransformed' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $post_types as $pt ) : ?>
                            <div class="wpt-cct-item" data-slug="<?php echo esc_attr( $pt['slug'] ); ?>">
                                <div class="wpt-cct-item-info">
                                    <span class="dashicons <?php echo esc_attr( $pt['menu_icon'] ?? 'dashicons-admin-post' ); ?>"></span>
                                    <strong><?php echo esc_html( $pt['plural'] ?? $pt['slug'] ); ?></strong>
                                    <code><?php echo esc_html( $pt['slug'] ); ?></code>
                                    <?php if ( ! empty( $pt['public'] ) ) : ?>
                                        <span class="wpt-cct-badge"><?php esc_html_e( 'Public', 'wptransformed' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $pt['show_in_rest'] ) ) : ?>
                                        <span class="wpt-cct-badge"><?php esc_html_e( 'REST', 'wptransformed' ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="wpt-cct-item-actions">
                                    <button type="button" class="button wpt-cct-edit-pt" data-slug="<?php echo esc_attr( $pt['slug'] ); ?>"><?php esc_html_e( 'Edit', 'wptransformed' ); ?></button>
                                    <button type="button" class="button wpt-cct-delete-pt" data-slug="<?php echo esc_attr( $pt['slug'] ); ?>"><?php esc_html_e( 'Delete', 'wptransformed' ); ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Taxonomies Tab -->
            <div class="wpt-cct-panel" data-panel="taxonomies">
                <div class="wpt-cct-header">
                    <h3><?php esc_html_e( 'Custom Taxonomies', 'wptransformed' ); ?></h3>
                    <button type="button" class="button button-primary" id="wpt-cct-add-tax">
                        <?php esc_html_e( 'Add Taxonomy', 'wptransformed' ); ?>
                    </button>
                </div>

                <div id="wpt-cct-tax-list" class="wpt-cct-list">
                    <?php if ( empty( $taxonomies ) ) : ?>
                        <p class="wpt-cct-empty"><?php esc_html_e( 'No custom taxonomies registered yet.', 'wptransformed' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $taxonomies as $tax ) : ?>
                            <div class="wpt-cct-item" data-slug="<?php echo esc_attr( $tax['slug'] ); ?>">
                                <div class="wpt-cct-item-info">
                                    <span class="dashicons dashicons-tag"></span>
                                    <strong><?php echo esc_html( $tax['plural'] ?? $tax['slug'] ); ?></strong>
                                    <code><?php echo esc_html( $tax['slug'] ); ?></code>
                                    <?php if ( ! empty( $tax['hierarchical'] ) ) : ?>
                                        <span class="wpt-cct-badge"><?php esc_html_e( 'Hierarchical', 'wptransformed' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $tax['show_in_rest'] ) ) : ?>
                                        <span class="wpt-cct-badge"><?php esc_html_e( 'REST', 'wptransformed' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $tax['post_types'] ) ) : ?>
                                        <span class="wpt-cct-meta"><?php echo esc_html( implode( ', ', $tax['post_types'] ) ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="wpt-cct-item-actions">
                                    <button type="button" class="button wpt-cct-edit-tax" data-slug="<?php echo esc_attr( $tax['slug'] ); ?>"><?php esc_html_e( 'Edit', 'wptransformed' ); ?></button>
                                    <button type="button" class="button wpt-cct-delete-tax" data-slug="<?php echo esc_attr( $tax['slug'] ); ?>"><?php esc_html_e( 'Delete', 'wptransformed' ); ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Post Type Form Modal -->
            <div id="wpt-cct-pt-modal" class="wpt-cct-modal" style="display:none;">
                <div class="wpt-cct-modal-content">
                    <div class="wpt-cct-modal-header">
                        <h3 id="wpt-cct-pt-modal-title"><?php esc_html_e( 'Add Post Type', 'wptransformed' ); ?></h3>
                        <button type="button" class="wpt-cct-modal-close">&times;</button>
                    </div>
                    <div class="wpt-cct-modal-body">
                        <input type="hidden" id="wpt-cct-pt-editing-slug" value="">

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="wpt-cct-pt-slug"><?php esc_html_e( 'Slug', 'wptransformed' ); ?></label></th>
                                <td>
                                    <input type="text" id="wpt-cct-pt-slug" class="regular-text" maxlength="20" pattern="[a-z0-9_-]+">
                                    <p class="description"><?php esc_html_e( 'Lowercase letters, numbers, hyphens, and underscores only. Max 20 characters.', 'wptransformed' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wpt-cct-pt-singular"><?php esc_html_e( 'Singular Name', 'wptransformed' ); ?></label></th>
                                <td><input type="text" id="wpt-cct-pt-singular" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wpt-cct-pt-plural"><?php esc_html_e( 'Plural Name', 'wptransformed' ); ?></label></th>
                                <td><input type="text" id="wpt-cct-pt-plural" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Options', 'wptransformed' ); ?></th>
                                <td>
                                    <fieldset>
                                        <label><input type="checkbox" id="wpt-cct-pt-public" checked> <?php esc_html_e( 'Public', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" id="wpt-cct-pt-has-archive" checked> <?php esc_html_e( 'Has Archive', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" id="wpt-cct-pt-show-in-rest" checked> <?php esc_html_e( 'Show in REST API (required for Gutenberg)', 'wptransformed' ); ?></label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wpt-cct-pt-icon"><?php esc_html_e( 'Menu Icon', 'wptransformed' ); ?></label></th>
                                <td>
                                    <input type="text" id="wpt-cct-pt-icon" class="regular-text" value="dashicons-admin-post" placeholder="dashicons-admin-post">
                                    <p class="description">
                                        <?php
                                        printf(
                                            /* translators: %s: URL to dashicons reference */
                                            esc_html__( 'Enter a dashicons class name. See %s for available icons.', 'wptransformed' ),
                                            '<a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">Dashicons Reference</a>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Supports', 'wptransformed' ); ?></th>
                                <td>
                                    <fieldset>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="title" checked> <?php esc_html_e( 'Title', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="editor" checked> <?php esc_html_e( 'Editor', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="thumbnail" checked> <?php esc_html_e( 'Featured Image', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="excerpt"> <?php esc_html_e( 'Excerpt', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="comments"> <?php esc_html_e( 'Comments', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="revisions"> <?php esc_html_e( 'Revisions', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="author"> <?php esc_html_e( 'Author', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="page-attributes"> <?php esc_html_e( 'Page Attributes', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" class="wpt-cct-pt-support" value="custom-fields"> <?php esc_html_e( 'Custom Fields', 'wptransformed' ); ?></label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wpt-cct-pt-rewrite"><?php esc_html_e( 'Rewrite Slug', 'wptransformed' ); ?></label></th>
                                <td>
                                    <input type="text" id="wpt-cct-pt-rewrite" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to use main slug', 'wptransformed' ); ?>">
                                    <p class="description"><?php esc_html_e( 'Custom URL slug. Leave blank to use the post type slug.', 'wptransformed' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="wpt-cct-modal-footer">
                        <button type="button" class="button" id="wpt-cct-pt-cancel"><?php esc_html_e( 'Cancel', 'wptransformed' ); ?></button>
                        <button type="button" class="button button-primary" id="wpt-cct-pt-save"><?php esc_html_e( 'Save Post Type', 'wptransformed' ); ?></button>
                    </div>
                </div>
            </div>

            <!-- Taxonomy Form Modal -->
            <div id="wpt-cct-tax-modal" class="wpt-cct-modal" style="display:none;">
                <div class="wpt-cct-modal-content">
                    <div class="wpt-cct-modal-header">
                        <h3 id="wpt-cct-tax-modal-title"><?php esc_html_e( 'Add Taxonomy', 'wptransformed' ); ?></h3>
                        <button type="button" class="wpt-cct-modal-close">&times;</button>
                    </div>
                    <div class="wpt-cct-modal-body">
                        <input type="hidden" id="wpt-cct-tax-editing-slug" value="">

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="wpt-cct-tax-slug"><?php esc_html_e( 'Slug', 'wptransformed' ); ?></label></th>
                                <td>
                                    <input type="text" id="wpt-cct-tax-slug" class="regular-text" maxlength="20" pattern="[a-z0-9_-]+">
                                    <p class="description"><?php esc_html_e( 'Lowercase letters, numbers, hyphens, and underscores only. Max 20 characters.', 'wptransformed' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wpt-cct-tax-singular"><?php esc_html_e( 'Singular Name', 'wptransformed' ); ?></label></th>
                                <td><input type="text" id="wpt-cct-tax-singular" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wpt-cct-tax-plural"><?php esc_html_e( 'Plural Name', 'wptransformed' ); ?></label></th>
                                <td><input type="text" id="wpt-cct-tax-plural" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Post Types', 'wptransformed' ); ?></th>
                                <td>
                                    <fieldset id="wpt-cct-tax-post-types">
                                        <?php foreach ( $all_post_types as $pt_slug => $pt_label ) : ?>
                                            <label>
                                                <input type="checkbox" class="wpt-cct-tax-pt" value="<?php echo esc_attr( $pt_slug ); ?>">
                                                <?php echo esc_html( $pt_label ); ?> <code><?php echo esc_html( $pt_slug ); ?></code>
                                            </label><br>
                                        <?php endforeach; ?>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Options', 'wptransformed' ); ?></th>
                                <td>
                                    <fieldset>
                                        <label><input type="checkbox" id="wpt-cct-tax-hierarchical"> <?php esc_html_e( 'Hierarchical (like categories)', 'wptransformed' ); ?></label><br>
                                        <label><input type="checkbox" id="wpt-cct-tax-show-in-rest" checked> <?php esc_html_e( 'Show in REST API (required for Gutenberg)', 'wptransformed' ); ?></label>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wpt-cct-tax-rewrite"><?php esc_html_e( 'Rewrite Slug', 'wptransformed' ); ?></label></th>
                                <td>
                                    <input type="text" id="wpt-cct-tax-rewrite" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to use main slug', 'wptransformed' ); ?>">
                                    <p class="description"><?php esc_html_e( 'Custom URL slug. Leave blank to use the taxonomy slug.', 'wptransformed' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="wpt-cct-modal-footer">
                        <button type="button" class="button" id="wpt-cct-tax-cancel"><?php esc_html_e( 'Cancel', 'wptransformed' ); ?></button>
                        <button type="button" class="button button-primary" id="wpt-cct-tax-save"><?php esc_html_e( 'Save Taxonomy', 'wptransformed' ); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Pass data to JS for edit operations.
        $init_data = [
            'postTypes'    => $post_types,
            'taxonomies'   => $taxonomies,
            'allPostTypes' => $all_post_types,
        ];
        $json = wp_json_encode( $init_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
        if ( $json === false ) {
            $json = '{"postTypes":[],"taxonomies":[],"allPostTypes":{}}';
        }
        ?>
        <script type="application/json" id="wpt-cct-init-data"><?php echo $json; ?></script>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    /**
     * Sanitize settings from the framework save handler.
     *
     * Note: The main CRUD operations are handled via AJAX, so the framework save
     * is used mainly for re-saving the full settings array. We sanitize defensively.
     *
     * @param array $raw Raw form data.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( array $raw ): array {
        // Since AJAX handles CRUD, on a normal form save we preserve the current settings.
        // The framework calls this on form submit, but we don't have form fields for the
        // complex data structures. Return current settings to avoid data loss.
        return $this->get_settings();
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'option', 'key' => self::FLUSH_TRANSIENT ],
        ];
    }
}
