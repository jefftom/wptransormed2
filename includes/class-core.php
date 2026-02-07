<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core Loader — How modules actually load.
 *
 * @package WPTransformed
 */
class Core {

    private static ?Core $instance = null;

    /** @var array<string, \WPTransformed\Modules\Module_Base> All instantiated modules */
    private array $modules = [];

    /** @var array<string> IDs of currently active modules */
    private array $active_ids = [];

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot sequence. Called from main plugin file.
     */
    public function boot(): void {
        // 1. Load active module IDs from database (single query)
        $this->active_ids = Settings::get_active_modules();

        // 2. Register all modules from the registry
        $registry = Module_Registry::get_all();
        $registry = apply_filters( 'wpt_registered_modules', $registry );

        foreach ( $registry as $id => $file ) {
            $this->register_module( $id, $file );
        }

        // 3. Initialize active modules
        foreach ( $this->active_ids as $id ) {
            $this->init_module( $id );
        }

        // 4. Hook asset loading
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
    }

    /**
     * Register a module: include the file, instantiate the class.
     * Does NOT call init() — that's separate.
     */
    private function register_module( string $id, string $file ): void {
        $path = WPT_PATH . '/' . $file;

        if ( ! file_exists( $path ) ) {
            // Log it, don't crash. Other modules should still load.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "WPTransformed: Module file not found: {$file}" );
            }
            return;
        }

        try {
            require_once $path;

            // Convention: class name derived from file name
            // class-content-duplication.php → Content_Duplication
            // The class must be in the right namespace based on category
            $class = $this->resolve_class_name( $id, $file );

            if ( ! class_exists( $class ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "WPTransformed: Class not found: {$class}" );
                }
                return;
            }

            $module = new $class();

            // Sanity check: does the module's get_id() match the registry key?
            if ( $module->get_id() !== $id ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "WPTransformed: Module ID mismatch. Registry: {$id}, Class: {$module->get_id()}" );
                }
                return;
            }

            $this->modules[ $id ] = $module;

        } catch ( \Throwable $e ) {
            // A single broken module must not take down the whole plugin.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "WPTransformed: Failed to register module '{$id}': " . $e->getMessage() );
            }
        }
    }

    /**
     * Initialize a single module (call its init() method).
     * Only called for active modules that passed registration.
     */
    private function init_module( string $id ): void {
        if ( ! isset( $this->modules[ $id ] ) ) {
            return;
        }

        $module = $this->modules[ $id ];

        // Check dependencies
        $deps = $module->get_dependencies();
        foreach ( $deps as $dep_id ) {
            if ( ! in_array( $dep_id, $this->active_ids, true ) ) {
                // Dependency not active. Skip this module.
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "WPTransformed: Module '{$id}' requires '{$dep_id}' which is not active." );
                }
                return;
            }
        }

        // License check (v1: all free, always passes)
        if ( $module->get_tier() === 'pro' && ! self::is_pro_licensed() ) {
            return;
        }

        try {
            $module->init();
        } catch ( \Throwable $e ) {
            // Module init failed. Log it, don't crash.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "WPTransformed: Module '{$id}' init() failed: " . $e->getMessage() );
            }
        }
    }

    /**
     * Enqueue admin assets for active modules.
     */
    public function enqueue_admin_assets( string $hook ): void {
        foreach ( $this->active_ids as $id ) {
            if ( isset( $this->modules[ $id ] ) ) {
                try {
                    $this->modules[ $id ]->enqueue_admin_assets( $hook );
                } catch ( \Throwable $e ) {
                    // Swallow asset errors — don't break the admin
                }
            }
        }
    }

    /**
     * Enqueue frontend assets for active modules.
     */
    public function enqueue_frontend_assets(): void {
        foreach ( $this->active_ids as $id ) {
            if ( isset( $this->modules[ $id ] ) ) {
                try {
                    $this->modules[ $id ]->enqueue_frontend_assets();
                } catch ( \Throwable $e ) {
                    // Swallow
                }
            }
        }
    }

    // -- Public API --

    /** Get a module instance by ID. */
    public function get_module( string $id ): ?\WPTransformed\Modules\Module_Base {
        return $this->modules[ $id ] ?? null;
    }

    /** Get all registered modules (active and inactive). */
    public function get_all_modules(): array {
        return $this->modules;
    }

    /** Check if a module is currently active. */
    public function is_active( string $id ): bool {
        return in_array( $id, $this->active_ids, true );
    }

    /** Placeholder for Pro license check. Always false in v1. */
    public static function is_pro_licensed(): bool {
        // TODO: Freemius integration in v2
        return false;
    }

    /** Resolve class name from module ID and file path. */
    private function resolve_class_name( string $id, string $file ): string {
        // Extract category from file path: modules/content-management/class-foo.php → ContentManagement
        $parts = explode( '/', $file );
        $category_slug = $parts[1] ?? '';
        $category_ns = str_replace( ' ', '', ucwords( str_replace( '-', ' ', $category_slug ) ) );

        // Extract class from filename: class-content-duplication.php → Content_Duplication
        $filename = basename( $file, '.php' );
        $filename = preg_replace( '/^class-/', '', $filename );
        $class_name = str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $filename ) ) );

        return "WPTransformed\\Modules\\{$category_ns}\\{$class_name}";
    }
}
