# WPTransformed v1 — Foundation Architecture

> What must be right NOW vs. what can wait.
> Every decision in this document is judged by one question:
> "If we get this wrong, do we have to rewrite at module 50?"

---

## Part 1: Must Be Right Now (Painful to Change Later)

These are the load-bearing walls. Get them wrong and you're rebuilding the house.

---

### 1.1 Module Registry — Explicit, Not Auto-Discovery

**The original spec:** Core class scans `modules/` directories at runtime, finds classes extending `Module_Base`, and auto-registers them.

**The problem:** One malformed PHP file in any module directory and the scan fails — silently or with a fatal error that kills the whole plugin. At 120 modules, you're scanning 120+ files on every admin page load. A developer adds a helper class in a module folder and the scanner tries to register it as a module.

**The v1 approach: Explicit registry array.**

```php
// includes/class-module-registry.php
// This is the single source of truth for what modules exist.
// Adding a module = adding one line here. Nothing else.

class Module_Registry {

    /**
     * Returns the master list of all modules.
     * Key = module ID (string), Value = class file path relative to plugin root.
     *
     * To add a new module:
     * 1. Create the module class file in modules/{category}/
     * 2. Add one line to this array
     * 3. That's it. The loader handles the rest.
     */
    public static function get_all(): array {
        return [
            // Content Management
            'content-duplication'  => 'modules/content-management/class-content-duplication.php',

            // Admin Interface
            'admin-menu-editor'    => 'modules/admin-interface/class-admin-menu-editor.php',
            'hide-admin-notices'   => 'modules/admin-interface/class-hide-admin-notices.php',
            'clean-admin-bar'      => 'modules/admin-interface/class-clean-admin-bar.php',
            'dark-mode'            => 'modules/admin-interface/class-dark-mode.php',

            // Performance
            'database-cleanup'     => 'modules/performance/class-database-cleanup.php',
            'heartbeat-control'    => 'modules/performance/class-heartbeat-control.php',

            // Utilities
            'disable-comments'     => 'modules/utilities/class-disable-comments.php',
            'email-smtp'           => 'modules/utilities/class-email-smtp.php',
            'svg-upload'           => 'modules/utilities/class-svg-upload.php',
        ];
    }
}
```

**Why this scales to 120+:**
- Adding a module is one line in one file. No filesystem scanning.
- If a module file is missing or broken, only THAT module fails — the rest load fine (the loader wraps each include in error handling).
- The array can be filtered: `apply_filters('wpt_registered_modules', $modules)` — lets third-party devs register their own modules.
- Registry is loaded once, cached in a static property. Zero overhead on subsequent calls.
- At 120 modules, this array is still just 120 lines. Trivial.

**Why NOT auto-discovery:**
- Filesystem scanning is slow (120 directories × `glob()` or `RecursiveDirectoryIterator`)
- One bad file kills everything
- Can't control load order
- Can't filter what's registered before files are included
- Harder to debug ("why isn't my module showing up?" vs. "is it in the registry?")

---

### 1.2 Module Base Class — The Contract

This class is the interface every module implements. Once 30+ modules extend it, changing a method signature means updating 30+ files. **Get it right now.**

```php
<?php
declare(strict_types=1);

namespace WPTransformed\Modules;

if ( ! defined( 'ABSPATH' ) ) exit;

abstract class Module_Base {

    // ── Identity (every module MUST implement these) ──

    /** Unique slug: 'content-duplication', 'dark-mode', etc. */
    abstract public function get_id(): string;

    /** Human-readable title: 'Content Duplication' */
    abstract public function get_title(): string;

    /** Category slug: 'content-management', 'admin-interface', etc. */
    abstract public function get_category(): string;

    /** One-sentence description for the settings page. */
    abstract public function get_description(): string;

    /** 'free' or 'pro'. All v1 modules return 'free'. */
    public function get_tier(): string {
        return 'free';
    }

    // ── Lifecycle (called by the core loader) ──

    /**
     * Called ONLY when the module is active (user toggled it on)
     * AND the license check passes (free modules always pass).
     *
     * This is where you add_action(), add_filter(), etc.
     * Do NOT add hooks in __construct(). Only in init().
     */
    abstract public function init(): void;

    /**
     * Called when the module is deactivated by the user.
     * Clean up any persistent changes (rewrite rules, cron jobs, etc.)
     * Do NOT delete user data here — that's for plugin uninstall only.
     */
    public function deactivate(): void {}

    // ── Settings ──

    /**
     * Return the default settings for this module.
     * These are used when the module is first activated,
     * and as fallbacks for any missing keys.
     *
     * Return an empty array if the module has no settings.
     */
    public function get_default_settings(): array {
        return [];
    }

    /**
     * Get this module's current settings (merged with defaults).
     * Modules call this in their init() to read their config.
     *
     * DO NOT override this method. It's provided by the base class
     * and handles the database read + default merging.
     */
    final public function get_settings(): array {
        $saved = \WPTransformed\Core\Settings::get( $this->get_id() );
        return wp_parse_args( $saved, $this->get_default_settings() );
    }

    // ── Admin UI ──

    /**
     * Render the settings form HTML for this module.
     * Called on the WPTransformed settings page when the module section is expanded.
     *
     * Use standard WordPress form elements (text inputs, checkboxes, dropdowns).
     * The settings page handles the form wrapper, nonce, and save button.
     * You just output the fields.
     *
     * Return empty/don't override if the module has no configurable settings
     * (e.g., Disable XML-RPC is just an on/off toggle with no options).
     */
    public function render_settings(): void {}

    /**
     * Sanitize and validate settings before saving.
     * Receives the raw $_POST data for this module's settings.
     * Returns the cleaned array to save.
     *
     * MUST sanitize every value. The core settings handler calls this
     * before writing to the database.
     */
    public function sanitize_settings( array $raw ): array {
        return [];
    }

    // ── Assets ──

    /**
     * Enqueue admin CSS/JS for this module.
     * Called inside admin_enqueue_scripts ONLY on pages where this module needs assets.
     *
     * $hook is the current admin page hook (e.g., 'toplevel_page_wptransformed').
     * Use it to conditionally load assets.
     *
     * RULES:
     * - Module-specific settings JS: only load on the WPT settings page.
     * - Module functionality (e.g., Dark Mode CSS): load where needed.
     * - Always use wp_enqueue_style() / wp_enqueue_script(). Never raw tags.
     * - Always use WPT_VERSION for cache busting.
     */
    public function enqueue_admin_assets( string $hook ): void {}

    /**
     * Enqueue frontend CSS/JS for this module.
     * Called inside wp_enqueue_scripts ONLY when this module is active.
     *
     * Most modules don't need frontend assets. Only override if you do.
     * RULE: Zero frontend impact from modules that don't override this.
     */
    public function enqueue_frontend_assets(): void {}

    // ── Dependencies (for future use) ──

    /**
     * Module IDs that must be active for this module to work.
     * The loader checks these before calling init().
     * Return empty array if no dependencies.
     *
     * Example: Builder Compatibility Layer depends on Page Builder Auto-Detect.
     */
    public function get_dependencies(): array {
        return [];
    }
}
```

**Why this specific contract:**

| Method | Why it exists | Why it's shaped this way |
|--------|--------------|--------------------------|
| `get_id()` | Primary key in settings table, used everywhere | String slug, not int. Human-readable. Won't change. |
| `get_title()` / `get_description()` | Settings page display | Separate from ID so they can be translated |
| `get_category()` | Settings page grouping | String slug, not enum. New categories can be added without changing the base class. |
| `get_tier()` | License gating | Defaults to 'free' so v1 modules don't even think about it. Pro check added later. |
| `init()` | The only lifecycle hook | NOT `__construct()`. Module objects are created during registration. `init()` is called only when active. This means creating a module object has zero cost. |
| `get_default_settings()` | Per-module defaults | Returns an array. Merged with saved settings via `wp_parse_args`. |
| `get_settings()` | Read current settings | FINAL method — modules can't override the read logic. Guarantees consistent behavior. |
| `render_settings()` | PHP-rendered form | Not React. Not JSON schema. Just echo HTML. Simple, works, no build step. |
| `sanitize_settings()` | Input sanitization | Called by core before save. Module is responsible for its own sanitization. |
| `enqueue_admin_assets()` | Per-module admin assets | Receives `$hook` so modules can be selective. |
| `enqueue_frontend_assets()` | Per-module frontend assets | Separate method. Most modules never override this = zero frontend impact. |
| `get_dependencies()` | Module interdependencies | Returns empty by default. Used by loader to check before init. |

**What's NOT in the base class (intentionally):**

| Omitted | Why |
|---------|-----|
| `get_tables()` / database migration | Only 3-4 modules out of 120 need custom tables. Handle it per-module in `init()` or a separate migration class. Don't bloat the contract. |
| `register_routes()` / REST API | REST endpoints are added later. Modules that need them add them in `init()` via standard `register_rest_route()`. No abstraction needed yet. |
| `get_settings_schema()` / JSON schema | Over-engineering. PHP form rendering is enough. If we add React settings later, we add a schema method then. |
| `get_icon()` / `get_order()` | UI concerns that don't affect architecture. Add them when the settings page design is finalized. Easy addition — doesn't break anything. |

---

### 1.3 Core Loader — How Modules Actually Load

This is the orchestrator. It reads the registry, checks what's active, and calls `init()` on active modules.

```php
<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

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

    // ── Public API ──

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
```

**Why this design scales:**

| Concern | How it's handled |
|---------|-----------------|
| Broken module kills everything | Every `require_once`, `new`, and `init()` is wrapped in try/catch. One broken module logs an error; the other 119 keep running. |
| Performance at 120 modules | Only active modules get `init()` called. Inactive modules: file is included (for settings page display) but `init()` is never called = zero hooks, zero overhead. |
| Load order | Registry array defines order. If it matters for a future module, move it up in the array. |
| Third-party modules | `apply_filters('wpt_registered_modules', $registry)` — anyone can add modules. |
| Module ID consistency | Loader verifies `$module->get_id() === $registry_key`. Catches bugs immediately. |

---

### 1.4 Settings Storage — Custom Table, Single Query

**Why not wp_options:** At 120 modules, each with a settings array, you'd have 120 autoloaded option rows. WordPress loads ALL autoloaded options on every page load. That's 120 JSON blobs loaded on the frontend, in REST requests, in cron — everywhere. Custom table with selective loading is dramatically better.

**The schema:**

```sql
CREATE TABLE {$wpdb->prefix}wpt_settings (
    module_id VARCHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    settings JSON NOT NULL DEFAULT '{}',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (module_id)
) {$charset_collate};
```

**Key design decisions:**

- `is_active` lives in the same table as settings. One query gets both: "which modules are on?" and "what are their settings?" No separate options, no separate active-modules list.
- `settings` is JSON. WordPress 5.x+ requires MySQL 5.7+ which supports JSON columns. This gives us structured data without serialization headaches.
- `module_id` is the primary key (VARCHAR(64)). No auto-increment ID needed. Lookups are always by module ID.
- Single row per module. 120 modules = 120 rows. Tiny table.

**The Settings class:**

```php
<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Settings {

    /** @var array|null Cached settings — loaded once per request */
    private static ?array $cache = null;

    /**
     * Load ALL module settings in a single query.
     * Called once during boot. Everything after reads from cache.
     */
    private static function load(): void {
        if ( self::$cache !== null ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        // Check if table exists (handles fresh install before activation hook runs)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results(
            "SELECT module_id, is_active, settings FROM {$table}",
            ARRAY_A
        );

        self::$cache = [];
        if ( $results ) {
            foreach ( $results as $row ) {
                self::$cache[ $row['module_id'] ] = [
                    'is_active' => (bool) $row['is_active'],
                    'settings'  => json_decode( $row['settings'], true ) ?: [],
                ];
            }
        }
    }

    /**
     * Get the list of active module IDs.
     * @return string[]
     */
    public static function get_active_modules(): array {
        self::load();
        $active = [];
        foreach ( self::$cache as $id => $data ) {
            if ( $data['is_active'] ) {
                $active[] = $id;
            }
        }
        return $active;
    }

    /**
     * Get settings for a specific module.
     * Returns empty array if module has no saved settings.
     */
    public static function get( string $module_id ): array {
        self::load();
        return self::$cache[ $module_id ]['settings'] ?? [];
    }

    /**
     * Save settings for a module. Uses REPLACE INTO (upsert).
     */
    public static function save( string $module_id, array $settings ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        $is_active = self::$cache[ $module_id ]['is_active'] ?? false;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->replace(
            $table,
            [
                'module_id' => $module_id,
                'is_active' => (int) $is_active,
                'settings'  => wp_json_encode( $settings ),
            ],
            [ '%s', '%d', '%s' ]
        );

        if ( $result !== false ) {
            // Update cache
            self::$cache[ $module_id ] = [
                'is_active' => $is_active,
                'settings'  => $settings,
            ];
            return true;
        }
        return false;
    }

    /**
     * Toggle a module active/inactive.
     */
    public static function toggle_module( string $module_id, bool $active ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';

        $settings = self::$cache[ $module_id ]['settings'] ?? [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->replace(
            $table,
            [
                'module_id' => $module_id,
                'is_active' => (int) $active,
                'settings'  => wp_json_encode( $settings ),
            ],
            [ '%s', '%d', '%s' ]
        );

        if ( $result !== false ) {
            self::$cache[ $module_id ] = [
                'is_active' => $active,
                'settings'  => $settings,
            ];
            return true;
        }
        return false;
    }

    /**
     * Create the settings table. Called on plugin activation.
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpt_settings';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            module_id VARCHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            settings JSON NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (module_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'wpt_db_version', '1.0.0' );
    }
}
```

**Performance characteristics:**
- **1 query per page load** to get all settings (the `load()` call). Not 1 per module. Not 120 queries.
- After that, everything reads from the static `$cache`. Zero additional DB hits.
- Module toggle is a single `REPLACE INTO`. Module settings save is a single `REPLACE INTO`.
- At 120 modules with ~500 bytes of JSON each: ~60KB total table size. Fits in a single InnoDB page.

**Why this design scales and can't easily be changed later:**
- If we started with `wp_options`, migrating 120 module settings to a custom table at module 50 would require a migration script, backwards compatibility, and re-testing everything. Starting with the custom table means we never have to migrate.
- The JSON column means module settings are flexible — any module can store any structure without altering the table schema.
- The `is_active` column being in the same table means we never have a sync issue between "what's active" and "what's configured."

---

### 1.5 Security Patterns — Baked Into The Code

Not a 300-line reference document. These are the patterns that every module follows.
The enforcement is structural — the settings page handler does the nonce/capability check once, not per-module. The base class contract forces sanitization.

**Rule 1: Every PHP file starts with the ABSPATH check.**
```php
<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) exit;
```
Non-negotiable. This is in every module template. Claude Code copies this pattern.

**Rule 2: The settings page handles auth once. Modules don't repeat it.**
The settings page controller (in `includes/class-admin.php`) does:
```php
// Before rendering ANY settings form:
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized' );
}

// Before processing ANY settings save:
check_admin_referer( 'wpt_save_settings', 'wpt_nonce' );
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized' );
}
```
Individual modules never need to check nonces or capabilities for their settings forms. The framework does it.

**Rule 3: Module `sanitize_settings()` is mandatory for any module with settings.**
The core settings handler calls `$module->sanitize_settings( $raw_post_data )` before saving. If a module returns unsanitized data, that's a bug in the module, not the framework. But the framework guarantees the method is called.

**Rule 4: Modules that register their own admin actions (like Content Duplication's duplicate link) handle their own nonce + capability checks.**
Because these are custom actions outside the settings page, the module must:
```php
// In the action handler:
if ( ! isset( $_GET['wpt_nonce'] ) || ! wp_verify_nonce( $_GET['wpt_nonce'], 'wpt_duplicate_' . $post_id ) ) {
    wp_die( 'Security check failed.' );
}
if ( ! current_user_can( 'edit_post', $post_id ) ) {
    wp_die( 'Unauthorized.' );
}
```
This is in each module's spec. Claude Code follows the spec.

**Rule 5: Output escaping at the echo.**
Every `render_settings()` method and every admin page output uses `esc_html()`, `esc_attr()`, `esc_url()`. This is enforced by code review (and later, by PHPCS in CI). It's part of the coding standard, not a separate document.

**Rule 6: Database queries use `$wpdb->prepare()`.**
The Settings class handles all settings reads/writes with proper escaping. Modules that do their own queries (Database Cleanup, Content Duplication) use `$wpdb->prepare()` in their own code, as shown in their individual specs.

**What this means for Claude Code:**
Instead of reading 300 lines of security rules, Claude Code sees:
1. ABSPATH check at the top of every file (it's in the template)
2. Nonce + capability pattern in any action handler (it's in the module spec)
3. `sanitize_settings()` method (it's in the base class contract)
4. `esc_html()` on every echo (it's in the coding standard)
5. `$wpdb->prepare()` on every query (it's in the module spec)

Five rules. Not fifteen. The rules are embedded in the code they write, not in a separate document they have to remember.

---

### 1.6 Asset Loading Architecture

**The problem at scale:** If 20 active modules each enqueue their own CSS file on every admin page, that's 20 HTTP requests. If they all load on every page, you're loading Dark Mode CSS on the media library page, Database Cleanup JS on the post editor, etc.

**The v1 approach: Two-tier asset loading.**

**Tier 1: Module-specific assets loaded only where needed.**
Each module's `enqueue_admin_assets( $hook )` method receives the current page hook. The module decides:
```php
// Dark Mode: loads on ALL admin pages (it changes the whole admin)
public function enqueue_admin_assets( string $hook ): void {
    wp_enqueue_style( 'wpt-dark-mode', WPT_URL . 'modules/admin-interface/css/dark-mode.css', [], WPT_VERSION );
}

// Database Cleanup: loads ONLY on the WPT settings page
public function enqueue_admin_assets( string $hook ): void {
    if ( $hook !== 'settings_page_wptransformed' ) return;
    wp_enqueue_script( 'wpt-db-cleanup', WPT_URL . 'modules/performance/js/database-cleanup.js', [], WPT_VERSION, true );
}

// Content Duplication: no admin assets needed at all (it's just a link in row actions)
// Don't override enqueue_admin_assets() — base class default does nothing.
```

**Tier 2: Shared settings page assets loaded once.**
The settings page controller enqueues one shared CSS file and one shared JS file for the WPT settings page UI (tabs, toggles, section expand/collapse). These are NOT per-module — they're the settings page chrome.

```php
// In class-admin.php, only on the WPT settings page:
wp_enqueue_style( 'wpt-admin', WPT_URL . 'assets/admin/css/admin.css', [], WPT_VERSION );
wp_enqueue_script( 'wpt-admin', WPT_URL . 'assets/admin/js/admin.js', [], WPT_VERSION, true );
```

**Tier 3: Frontend assets — zero by default.**
The `enqueue_frontend_assets()` method is only called for active modules. Most modules never override it. Only modules that affect the frontend (e.g., a future Lazy Load module) would add frontend assets.

**Why this scales:**
- At 120 modules with 20 active, only those 20 get `enqueue_admin_assets()` called.
- Of those 20, most only load assets on the WPT settings page (via the `$hook` check). On other admin pages, maybe 2-3 modules load assets (Dark Mode, Command Palette in the future).
- Frontend: most modules have zero frontend impact. Only the 2-3 that need frontend assets (Lazy Load, External Links) ever run `enqueue_frontend_assets()`.

**No build step in v1:**
Module CSS and JS files live alongside their PHP files:
```
modules/
├── admin-interface/
│   ├── class-dark-mode.php
│   ├── css/
│   │   └── dark-mode.css
│   └── js/
│       └── dark-mode-toggle.js
├── performance/
│   ├── class-database-cleanup.php
│   └── js/
│       └── database-cleanup.js
```

When we add React components later (Command Palette, Dashboard Redesign), those specific features get a Vite build. The rest stays as plain CSS/JS.

---

### 1.7 Main Plugin File — The Bootstrap

```php
<?php
/**
 * Plugin Name:       WPTransformed
 * Plugin URI:        https://wptransformed.com
 * Description:       Replace 15+ plugins with one. Modular admin enhancements for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WPTransformed
 * Author URI:        https://wptransformed.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wptransformed
 * Domain Path:       /languages
 *
 * @package WPTransformed
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin constants
define( 'WPT_VERSION', '1.0.0' );
define( 'WPT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPT_URL', plugin_dir_url( __FILE__ ) );
define( 'WPT_FILE', __FILE__ );

// Autoloader (simple, no Composer needed for v1)
spl_autoload_register( function( $class ) {
    $prefix = 'WPTransformed\\';
    if ( strpos( $class, $prefix ) !== 0 ) return;

    $relative = substr( $class, strlen( $prefix ) );
    // WPTransformed\Core\Settings → includes/class-settings.php
    // WPTransformed\Modules\ContentManagement\Content_Duplication → (loaded via registry)
    $parts = explode( '\\', $relative );

    if ( $parts[0] === 'Core' ) {
        $filename = 'class-' . strtolower( str_replace( '_', '-', $parts[1] ) ) . '.php';
        $path = WPT_PATH . 'includes/' . $filename;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
    // Module classes are loaded by the registry/loader, not the autoloader.
});

// Activation
register_activation_hook( __FILE__, function() {
    \WPTransformed\Core\Settings::create_table();
    \WPTransformed\Core\Safe_Mode::generate_token();
    flush_rewrite_rules();
} );

// Deactivation
register_deactivation_hook( __FILE__, function() {
    // Don't delete data. Just clean up cron jobs if any.
    flush_rewrite_rules();
} );

// Boot
add_action( 'plugins_loaded', function() {
    // Load text domain
    load_plugin_textdomain( 'wptransformed', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Check safe mode BEFORE loading any modules
    $safe_mode = \WPTransformed\Core\Safe_Mode::is_active();

    if ( $safe_mode ) {
        // Show warning banner — no modules loaded
        \WPTransformed\Core\Safe_Mode::render_banner();
    } else {
        // Normal boot — load and init modules
        \WPTransformed\Core\Core::instance()->boot();
    }

    // Admin settings page loads ALWAYS (even in safe mode)
    if ( is_admin() ) {
        require_once WPT_PATH . 'includes/class-admin.php';
        new \WPTransformed\Core\Admin();
    }
} );
```

**Why this specific bootstrap:**
- Constants defined at the top — every module uses `WPT_PATH`, `WPT_URL`, `WPT_VERSION`.
- Simple PSR-4-ish autoloader for core classes. No Composer dependency for v1 (Composer comes when we add Freemius SDK).
- Module classes are NOT autoloaded — they're loaded explicitly by the registry. This is intentional: we want to control when and if module files are included.
- `plugins_loaded` hook for boot — standard WP pattern, ensures all other plugins are loaded first.
- Admin class only loaded in admin context — zero overhead on frontend when no frontend modules are active.

---

### 1.8 Directory Structure (v1, Clean)

```
wptransformed/
├── wptransformed.php                    # Bootstrap (see above)
├── uninstall.php                        # Cleanup on uninstall (respects user preference)
├── readme.txt                           # WordPress.org readme
├── index.php                            # Silence is golden
│
├── includes/
│   ├── index.php                        # Silence
│   ├── class-core.php                   # Singleton loader (see 1.3)
│   ├── class-module-registry.php        # Module list (see 1.1)
│   ├── class-module-base.php            # Abstract base (see 1.2 + 1.12)
│   ├── class-settings.php               # Settings API (see 1.4)
│   ├── class-admin.php                  # Settings page controller (see 1.9)
│   └── class-safe-mode.php              # Emergency recovery (see 1.10)
│
├── modules/
│   ├── index.php
│   ├── content-management/
│   │   ├── index.php
│   │   └── class-content-duplication.php
│   ├── admin-interface/
│   │   ├── index.php
│   │   ├── class-admin-menu-editor.php
│   │   ├── class-hide-admin-notices.php
│   │   ├── class-clean-admin-bar.php
│   │   ├── class-dark-mode.php
│   │   ├── css/
│   │   │   └── dark-mode.css
│   │   └── js/
│   │       ├── admin-menu-editor.js
│   │       └── dark-mode-toggle.js
│   ├── performance/
│   │   ├── index.php
│   │   ├── class-database-cleanup.php
│   │   ├── class-heartbeat-control.php
│   │   └── js/
│   │       └── database-cleanup.js
│   └── utilities/
│       ├── index.php
│       ├── class-disable-comments.php
│       ├── class-email-smtp.php
│       └── class-svg-upload.php
│
├── assets/
│   └── admin/
│       ├── css/
│       │   └── admin.css                # Shared settings page styles
│       └── js/
│           └── admin.js                 # Shared settings page JS (tabs, toggles)
│
└── languages/
    └── wptransformed.pot                # Translation template
```

**What's NOT here (and that's intentional):**
- No `vendor/` — No Composer deps in v1. Freemius SDK comes in v2.
- No `node_modules/` or `package.json` — No build step in v1.
- No `vite.config.js` or `tsconfig.json` — No React/TypeScript in v1.
- No `tests/` — Tests come after the foundation works. (Controversial? See Part 3.)
- No `api/endpoints/` — REST API comes in v2 when we add the React dashboard.
- No `templates/` — Login templates, maintenance mode, etc. come in later phases.
- No `.github/workflows/` — CI/CD comes after we have something to test.

Every directory has an `index.php` with `<?php // Silence is golden.` — prevents directory listing.

---

### 1.9 Admin Settings Page — The Glue (class-admin.php)

This is the most referenced and least spec'd piece in the architecture. Every module's settings flow through it. The loader calls it. The modules render into it. If Claude Code has to invent this, it will get it wrong.

**What it does:**
1. Registers the settings page under Settings → WPTransformed
2. Renders the module list (grouped by category) with toggle switches
3. Handles module toggle (on/off) via AJAX
4. Handles settings form submission — routes to the correct module's `sanitize_settings()`
5. Enqueues the shared settings page CSS/JS

```php
<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'wp_ajax_wpt_toggle_module', [ $this, 'ajax_toggle_module' ] );
    }

    /**
     * Register the settings page under Settings menu.
     */
    public function register_page(): void {
        add_options_page(
            __( 'WPTransformed', 'wptransformed' ),    // Page title
            __( 'WPTransformed', 'wptransformed' ),    // Menu title
            'manage_options',                           // Capability
            'wptransformed',                            // Slug
            [ $this, 'render_page' ]                    // Callback
        );
    }

    /**
     * Render the main settings page.
     *
     * Layout:
     * ┌──────────────────────────────────────────────────┐
     * │  WPTransformed Settings                          │
     * ├──────────────────────────────────────────────────┤
     * │  [Tab: Content Management] [Admin Interface] ... │
     * ├──────────────────────────────────────────────────┤
     * │                                                  │
     * │  ┌─ Content Duplication ──────────── [ON/OFF] ─┐ │
     * │  │  One-click clone for posts and pages.       │ │
     * │  │                                             │ │
     * │  │  ▼ Settings (collapsed when OFF)            │ │
     * │  │    Post types: ☑ Posts  ☑ Pages             │ │
     * │  │    Copy taxonomies: ☑                       │ │
     * │  │    Title suffix: [(Copy)]                   │ │
     * │  │    ...                                      │ │
     * │  │                        [Save Settings]      │ │
     * │  └─────────────────────────────────────────────┘ │
     * │                                                  │
     * │  ┌─ SVG Upload ───────────────────── [ON/OFF] ─┐ │
     * │  │  Allow SVG uploads with sanitization.       │ │
     * │  │  (no settings — toggle only)                │ │
     * │  └─────────────────────────────────────────────┘ │
     * │                                                  │
     * └──────────────────────────────────────────────────┘
     */
    public function render_page(): void {
        // Capability check (defense in depth — WP checks this for the menu too)
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'wptransformed' ) );
        }

        $core = Core::instance();
        $all_modules = $core->get_all_modules();

        // Group modules by category
        $categories = [];
        foreach ( $all_modules as $id => $module ) {
            $cat = $module->get_category();
            if ( ! isset( $categories[ $cat ] ) ) {
                $categories[ $cat ] = [];
            }
            $categories[ $cat ][ $id ] = $module;
        }

        // Category display names
        $category_labels = [
            'content-management' => __( 'Content Management', 'wptransformed' ),
            'admin-interface'    => __( 'Admin Interface', 'wptransformed' ),
            'performance'        => __( 'Performance', 'wptransformed' ),
            'utilities'          => __( 'Utilities', 'wptransformed' ),
            'security'           => __( 'Security', 'wptransformed' ),
            'media'              => __( 'Media', 'wptransformed' ),
        ];

        // Determine active tab
        $category_slugs = array_keys( $categories );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ( $category_slugs[0] ?? '' );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WPTransformed', 'wptransformed' ); ?></h1>

            <?php // Show any save notices ?>
            <?php if ( isset( $_GET['wpt_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'wptransformed' ); ?></p>
                </div>
            <?php endif; ?>

            <?php // Category tabs ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $categories as $cat_slug => $cat_modules ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', $cat_slug ) ); ?>"
                       class="nav-tab <?php echo $active_tab === $cat_slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $category_labels[ $cat_slug ] ?? ucwords( str_replace( '-', ' ', $cat_slug ) ) ); ?>
                        <span class="wpt-module-count"><?php echo count( $cat_modules ); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php // Module cards for active tab ?>
            <div class="wpt-modules-list">
                <?php
                $tab_modules = $categories[ $active_tab ] ?? [];
                foreach ( $tab_modules as $id => $module ) :
                    $is_active = $core->is_active( $id );
                    $has_settings = ! empty( $module->get_default_settings() );
                ?>
                    <div class="wpt-module-card <?php echo $is_active ? 'wpt-module-active' : ''; ?>"
                         data-module-id="<?php echo esc_attr( $id ); ?>">

                        <?php // Module header: title + toggle ?>
                        <div class="wpt-module-header">
                            <div class="wpt-module-info">
                                <h3><?php echo esc_html( $module->get_title() ); ?></h3>
                                <p><?php echo esc_html( $module->get_description() ); ?></p>
                            </div>
                            <label class="wpt-toggle">
                                <input type="checkbox"
                                       class="wpt-module-toggle"
                                       data-module-id="<?php echo esc_attr( $id ); ?>"
                                       <?php checked( $is_active ); ?>>
                                <span class="wpt-toggle-slider"></span>
                            </label>
                        </div>

                        <?php // Module settings (only shown when active AND has settings) ?>
                        <?php if ( $has_settings ) : ?>
                            <div class="wpt-module-settings" style="<?php echo $is_active ? '' : 'display:none;'; ?>">
                                <form method="post" action="">
                                    <?php wp_nonce_field( 'wpt_save_' . $id, 'wpt_nonce' ); ?>
                                    <input type="hidden" name="wpt_module_id" value="<?php echo esc_attr( $id ); ?>">
                                    <input type="hidden" name="wpt_action" value="save_settings">

                                    <div class="wpt-settings-fields">
                                        <?php $module->render_settings(); ?>
                                    </div>

                                    <?php submit_button( __( 'Save Settings', 'wptransformed' ), 'primary', 'wpt_submit', true ); ?>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle settings form submission.
     * This runs on admin_init — before any output.
     */
    public function handle_save(): void {
        // Only process our form submissions
        if ( ! isset( $_POST['wpt_action'] ) || $_POST['wpt_action'] !== 'save_settings' ) {
            return;
        }

        $module_id = isset( $_POST['wpt_module_id'] ) ? sanitize_key( $_POST['wpt_module_id'] ) : '';
        if ( empty( $module_id ) ) return;

        // Security: nonce + capability
        check_admin_referer( 'wpt_save_' . $module_id, 'wpt_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'wptransformed' ) );
        }

        // Get the module
        $module = Core::instance()->get_module( $module_id );
        if ( ! $module ) return;

        // Let the module sanitize its own settings
        $raw = $_POST;
        $clean = $module->sanitize_settings( $raw );

        // Save
        Settings::save( $module_id, $clean );

        // Redirect back with success notice (PRG pattern — prevents double-submit)
        wp_safe_redirect( add_query_arg( [
            'page'      => 'wptransformed',
            'tab'       => $module->get_category(),
            'wpt_saved' => '1',
        ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    /**
     * AJAX handler for toggling a module on/off.
     * Called when the user clicks the toggle switch.
     */
    public function ajax_toggle_module(): void {
        // Security
        check_ajax_referer( 'wpt_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $module_id = isset( $_POST['module_id'] ) ? sanitize_key( $_POST['module_id'] ) : '';
        $active    = isset( $_POST['active'] ) && $_POST['active'] === '1';

        if ( empty( $module_id ) ) {
            wp_send_json_error( 'Missing module ID' );
        }

        // Verify the module exists in the registry
        $module = Core::instance()->get_module( $module_id );
        if ( ! $module ) {
            wp_send_json_error( 'Unknown module' );
        }

        // License check for pro modules (v1: always passes)
        if ( $module->get_tier() === 'pro' && ! Core::is_pro_licensed() ) {
            wp_send_json_error( 'Pro license required' );
        }

        // Toggle
        $result = Settings::toggle_module( $module_id, $active );

        if ( $result ) {
            // If deactivating, call the module's deactivate() method
            if ( ! $active ) {
                try {
                    $module->deactivate();
                } catch ( \Throwable $e ) {
                    // Log but don't fail the toggle
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( "WPTransformed: Module '{$module_id}' deactivate() failed: " . $e->getMessage() );
                    }
                }
            }
            wp_send_json_success( [ 'active' => $active ] );
        } else {
            wp_send_json_error( 'Failed to update' );
        }
    }
}
```

**The shared admin.js file (toggle + section expand):**

```js
/**
 * WPTransformed Admin Settings Page
 * assets/admin/js/admin.js
 *
 * Handles:
 * 1. Module toggle (on/off) via AJAX
 * 2. Settings section show/hide when toggling
 * 3. No jQuery dependency — vanilla JS
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ── Module Toggle (AJAX) ──
        document.querySelectorAll('.wpt-module-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var moduleId = this.dataset.moduleId;
                var active = this.checked ? '1' : '0';
                var card = this.closest('.wpt-module-card');
                var settingsPanel = card ? card.querySelector('.wpt-module-settings') : null;

                // Optimistic UI: toggle the card active class immediately
                if (card) {
                    card.classList.toggle('wpt-module-active', this.checked);
                }

                // Show/hide settings panel
                if (settingsPanel) {
                    settingsPanel.style.display = this.checked ? '' : 'none';
                }

                // AJAX request to save toggle state
                var formData = new FormData();
                formData.append('action', 'wpt_toggle_module');
                formData.append('module_id', moduleId);
                formData.append('active', active);
                formData.append('nonce', wptAdmin.nonce);

                fetch(wptAdmin.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data.success) {
                        // Revert on failure
                        toggle.checked = !toggle.checked;
                        if (card) {
                            card.classList.toggle('wpt-module-active', toggle.checked);
                        }
                        if (settingsPanel) {
                            settingsPanel.style.display = toggle.checked ? '' : 'none';
                        }
                        // Show error
                        alert('Failed to toggle module: ' + (data.data || 'Unknown error'));
                    }
                })
                .catch(function() {
                    // Revert on network error
                    toggle.checked = !toggle.checked;
                    if (card) {
                        card.classList.toggle('wpt-module-active', toggle.checked);
                    }
                    if (settingsPanel) {
                        settingsPanel.style.display = toggle.checked ? '' : 'none';
                    }
                });
            });
        });
    });
})();
```

**Localized data passed to admin.js:**

```php
// In Admin::register_page() or via admin_enqueue_scripts:
wp_localize_script( 'wpt-admin', 'wptAdmin', [
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    'nonce'   => wp_create_nonce( 'wpt_admin_nonce' ),
] );
```

**Why this must be spec'd now:**

| Concern | How the Admin class handles it |
|---------|-------------------------------|
| Where do nonces live? | One per-module nonce for settings forms (`wpt_save_{id}`), one global nonce for AJAX toggle (`wpt_admin_nonce`). Defined here, not invented per-module. |
| How does form submission route? | `$_POST['wpt_module_id']` identifies the module. The Admin class gets the module instance, calls its `sanitize_settings()`, saves via `Settings::save()`. Modules never handle form submission. |
| How does toggle work? | AJAX POST to `wpt_toggle_module`. Optimistic UI on the frontend, revert on failure. `deactivate()` called when toggling off. |
| What about modules with no settings? | They render as a card with just a title, description, and toggle. No settings panel, no form, no save button. The `$has_settings` check handles this. |
| PRG pattern? | Settings save redirects back to the page with `?wpt_saved=1`. No double-submit on refresh. |
| Tab persistence? | Active tab is a URL parameter (`?tab=admin-interface`). Survives save redirects. |

**What the Admin class does NOT do (module responsibility):**
- It doesn't know what fields a module has — `render_settings()` handles that.
- It doesn't know how to validate module data — `sanitize_settings()` handles that.
- It doesn't register any module hooks — `init()` handles that.
- It doesn't load module assets — `enqueue_admin_assets()` handles that.

---

### 1.10 Safe Mode — Error Recovery

**The problem:** PHP parse errors and fatal errors in an included file kill the process before any try/catch can run. If a bad module file has a syntax error, `require_once` fatals, and the entire admin is dead. The user can't get to the settings page to disable the module. They're locked out.

At 10 modules this is unlikely. At 120 modules, especially with future third-party modules via the filter hook, it's inevitable.

**The solution: Safe mode via URL parameter + stored token.**

```php
<?php
declare(strict_types=1);

namespace WPTransformed\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class Safe_Mode {

    /** Option key for the safe mode secret token. */
    const TOKEN_OPTION = 'wpt_safe_mode_token';

    /**
     * Check if safe mode is requested and valid.
     * Called BEFORE the module loader runs.
     *
     * Safe mode URL: /wp-admin/?wpt_safe_mode={token}
     *
     * In safe mode:
     * - No modules are loaded at all
     * - Settings page still renders (so you can toggle modules off)
     * - A prominent banner warns that safe mode is active
     */
    public static function is_active(): bool {
        if ( ! is_admin() ) return false;
        if ( ! isset( $_GET['wpt_safe_mode'] ) ) return false;

        $provided = sanitize_text_field( $_GET['wpt_safe_mode'] );
        $stored   = get_option( self::TOKEN_OPTION, '' );

        // Token must exist and match
        if ( empty( $stored ) || ! hash_equals( $stored, $provided ) ) {
            return false;
        }

        return true;
    }

    /**
     * Generate and store a safe mode token.
     * Called on plugin activation and can be regenerated from WP-CLI.
     */
    public static function generate_token(): string {
        $token = wp_generate_password( 32, false, false );
        update_option( self::TOKEN_OPTION, $token, false ); // no autoload
        return $token;
    }

    /**
     * Get the current safe mode URL.
     * Displayed on the settings page so the admin can bookmark it.
     */
    public static function get_safe_mode_url(): string {
        $token = get_option( self::TOKEN_OPTION, '' );
        if ( empty( $token ) ) {
            $token = self::generate_token();
        }
        return add_query_arg( 'wpt_safe_mode', $token, admin_url() );
    }

    /**
     * Show the safe mode admin banner.
     */
    public static function render_banner(): void {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-warning" style="border-left-color: #d63638; background: #fef1f1;">
                <p>
                    <strong><?php esc_html_e( '⚠️ WPTransformed Safe Mode Active', 'wptransformed' ); ?></strong><br>
                    <?php esc_html_e( 'All modules are disabled. Go to Settings → WPTransformed to disable any problematic modules, then remove ?wpt_safe_mode from the URL to exit safe mode.', 'wptransformed' ); ?>
                </p>
            </div>
            <?php
        } );
    }
}
```

**Integration with the boot sequence (updated main plugin file):**

```php
// In wptransformed.php, inside the plugins_loaded callback:
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'wptransformed', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Check safe mode BEFORE loading any modules
    $safe_mode = \WPTransformed\Core\Safe_Mode::is_active();

    if ( $safe_mode ) {
        // Show warning banner
        \WPTransformed\Core\Safe_Mode::render_banner();
    } else {
        // Normal boot — load and init modules
        \WPTransformed\Core\Core::instance()->boot();
    }

    // Admin settings page loads ALWAYS (even in safe mode — that's how you fix things)
    if ( is_admin() ) {
        require_once WPT_PATH . 'includes/class-admin.php';
        new \WPTransformed\Core\Admin();
    }
} );
```

**Integration with plugin activation:**

```php
register_activation_hook( __FILE__, function() {
    \WPTransformed\Core\Settings::create_table();
    \WPTransformed\Core\Safe_Mode::generate_token();
    flush_rewrite_rules();
} );
```

**How the user discovers their safe mode URL:**
On the WPTransformed settings page, below the module list:

```php
// In Admin::render_page(), at the bottom:
<div class="wpt-safe-mode-info">
    <h4><?php esc_html_e( 'Emergency Safe Mode', 'wptransformed' ); ?></h4>
    <p><?php esc_html_e( 'If a module causes issues and you can\'t access the admin, use this URL to load WordPress with all WPTransformed modules disabled:', 'wptransformed' ); ?></p>
    <code><?php echo esc_url( \WPTransformed\Core\Safe_Mode::get_safe_mode_url() ); ?></code>
    <p class="description"><?php esc_html_e( 'Bookmark this URL. Keep it secret — anyone with this link can access safe mode.', 'wptransformed' ); ?></p>
</div>
```

**WP-CLI support (future, but the foundation is here):**

```bash
# Regenerate safe mode token (if compromised or lost)
wp wpt safe-mode --regenerate

# Get the current safe mode URL
wp wpt safe-mode --url

# Disable a specific module without the admin UI
wp wpt module disable dark-mode
```

These commands aren't built in v1, but the `Safe_Mode` class and `Settings::toggle_module()` already support them. Adding WP-CLI commands later is just registering commands that call existing methods.

**Additional crash protection — the mu-plugin approach:**

For the most catastrophic case where even the main plugin file can't load (e.g., the autoloader has a bug), we offer an optional must-use plugin:

```php
<?php
/**
 * WPTransformed Emergency Recovery
 * Place in wp-content/mu-plugins/wpt-recovery.php
 *
 * This runs BEFORE any regular plugin loads.
 * If ?wpt_disable=1 is in the URL with the correct token,
 * it deactivates WPTransformed entirely via WordPress options.
 */
if ( isset( $_GET['wpt_disable'] ) && $_GET['wpt_disable'] === '1' ) {
    // Only work if the safe mode token matches
    $token = get_option( 'wpt_safe_mode_token', '' );
    if ( ! empty( $token ) && isset( $_GET['wpt_token'] ) && hash_equals( $token, $_GET['wpt_token'] ) ) {
        $active = get_option( 'active_plugins', [] );
        $active = array_diff( $active, [ 'wptransformed/wptransformed.php' ] );
        update_option( 'active_plugins', $active );
        wp_safe_redirect( admin_url() );
        exit;
    }
}
```

This is documented but NOT auto-installed. It's a safety net the user can place manually, or that we mention in support docs. It solves the "plugin is so broken it can't even boot" scenario.

**Why safe mode must be in the foundation:**
- At 120 modules, fatal errors are statistically inevitable
- Third-party modules (via the `wpt_registered_modules` filter) are out of our control
- Without safe mode, "broken module" means "deactivate entire plugin via FTP" — unacceptable for non-technical users
- The token system prevents random visitors from accessing safe mode
- The settings page works in safe mode because it doesn't depend on any module loading

---

### 1.11 Uninstall Cleanup — Module Data Contract

**The problem:** When the user deletes the plugin, `uninstall.php` runs. At 10 modules it's easy to hardcode what to clean up. At 120 modules, some of which create user meta, cron jobs, custom tables, or wp_options entries, you need a contract that tells the uninstaller what each module created.

**The solution: Add `get_cleanup_tasks()` to Module_Base.**

```php
// Added to the Module_Base abstract class:

/**
 * Return a list of data this module creates that should be
 * removed when the plugin is UNINSTALLED (deleted).
 *
 * This is NOT called on deactivation — only on full uninstall.
 * Users expect their settings to survive deactivation/reactivation.
 *
 * Return an array of cleanup task descriptors:
 *
 * [
 *     [ 'type' => 'option',    'key' => 'my_option_name' ],
 *     [ 'type' => 'user_meta', 'key' => 'wpt_dark_mode' ],
 *     [ 'type' => 'transient', 'key' => 'wpt_some_cache' ],
 *     [ 'type' => 'cron',      'hook' => 'wpt_scheduled_cleanup' ],
 *     [ 'type' => 'table',     'name' => 'wpt_audit_log' ],
 *     [ 'type' => 'post_meta', 'key' => '_wpt_duplicated_from' ],
 * ]
 *
 * The uninstaller knows how to handle each type.
 * If your module creates no external data, return empty array (default).
 */
public function get_cleanup_tasks(): array {
    return [];
}
```

**Example implementations per module:**

```php
// Content Duplication
public function get_cleanup_tasks(): array {
    return [
        [ 'type' => 'post_meta', 'key' => '_wpt_duplicated_from' ],
    ];
}

// Dark Mode
public function get_cleanup_tasks(): array {
    return [
        [ 'type' => 'user_meta', 'key' => 'wpt_dark_mode' ],
    ];
}

// Admin Menu Editor
public function get_cleanup_tasks(): array {
    return [
        // Menu order is stored per-user
        [ 'type' => 'user_meta', 'key' => 'wpt_menu_order' ],
        [ 'type' => 'user_meta', 'key' => 'wpt_hidden_menus' ],
    ];
}

// Database Cleanup (no external data — it only deletes, never creates)
public function get_cleanup_tasks(): array {
    return [];
}

// Email SMTP
public function get_cleanup_tasks(): array {
    return [
        [ 'type' => 'option', 'key' => 'wpt_smtp_test_log' ],
    ];
}

// Heartbeat Control, SVG Upload, Hide Admin Notices, Clean Admin Bar, Disable Comments
// These modify behavior via hooks but create no persistent data.
public function get_cleanup_tasks(): array {
    return [];
}
```

**The uninstall.php file:**

```php
<?php
/**
 * WPTransformed Uninstall
 *
 * Fired when the plugin is deleted (NOT deactivated).
 * Removes all plugin data from the database.
 *
 * @package WPTransformed
 */

// Verify this is a legitimate uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Step 1: Load just enough to read the registry ──

require_once plugin_dir_path( __FILE__ ) . 'includes/class-module-registry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-module-base.php';

// ── Step 2: Collect cleanup tasks from ALL modules ──

$registry = \WPTransformed\Core\Module_Registry::get_all();
$tasks = [];

foreach ( $registry as $id => $file ) {
    $path = plugin_dir_path( __FILE__ ) . $file;
    if ( ! file_exists( $path ) ) continue;

    try {
        require_once $path;

        // Resolve class name (same logic as Core::resolve_class_name)
        $parts = explode( '/', $file );
        $category_slug = $parts[1] ?? '';
        $category_ns = str_replace( ' ', '', ucwords( str_replace( '-', ' ', $category_slug ) ) );
        $filename = basename( $file, '.php' );
        $filename = preg_replace( '/^class-/', '', $filename );
        $class_name = str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $filename ) ) );
        $class = "WPTransformed\\Modules\\{$category_ns}\\{$class_name}";

        if ( class_exists( $class ) ) {
            $module = new $class();
            $module_tasks = $module->get_cleanup_tasks();
            if ( ! empty( $module_tasks ) ) {
                $tasks = array_merge( $tasks, $module_tasks );
            }
        }
    } catch ( \Throwable $e ) {
        // If we can't load a module to get its tasks, skip it.
        // Better to leave some orphaned data than crash the uninstaller.
        continue;
    }
}

// ── Step 3: Execute cleanup tasks ──

global $wpdb;

foreach ( $tasks as $task ) {
    try {
        switch ( $task['type'] ) {

            case 'option':
                delete_option( $task['key'] );
                break;

            case 'user_meta':
                // Delete from ALL users
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete(
                    $wpdb->usermeta,
                    [ 'meta_key' => $task['key'] ],
                    [ '%s' ]
                );
                break;

            case 'post_meta':
                // Delete from ALL posts
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete(
                    $wpdb->postmeta,
                    [ 'meta_key' => $task['key'] ],
                    [ '%s' ]
                );
                break;

            case 'transient':
                delete_transient( $task['key'] );
                break;

            case 'cron':
                $timestamp = wp_next_scheduled( $task['hook'] );
                if ( $timestamp ) {
                    wp_unschedule_event( $timestamp, $task['hook'] );
                }
                wp_unschedule_hook( $task['hook'] ); // Remove all instances
                break;

            case 'table':
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$task['name']}" );
                break;
        }
    } catch ( \Throwable $e ) {
        // Don't let one failed cleanup stop the rest
        continue;
    }
}

// ── Step 4: Remove core plugin data ──

// Drop the settings table
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpt_settings" );

// Remove plugin options
delete_option( 'wpt_db_version' );
delete_option( 'wpt_safe_mode_token' );

// Clear any remaining transients with our prefix
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wpt_%'
     OR option_name LIKE '_transient_timeout_wpt_%'"
);
```

**Why `get_cleanup_tasks()` and not just a `uninstall()` method per module:**

| Approach | Problem |
|----------|---------|
| Let each module run custom uninstall code | At 120 modules, that's 120 arbitrary code paths running during uninstall. One bug and the uninstaller crashes, leaving partial data. |
| Declarative task list | The uninstaller is a simple, predictable loop. Each task type has exactly one handler. If one task fails, the loop continues. Easy to test, easy to audit. |
| Hardcode everything in uninstall.php | Works at 10 modules. At 120, it's unmaintainable. You'd forget to add cleanup for module 87's user meta. |

**The contract guarantees:**
1. Every module declares its external data footprint.
2. The uninstaller handles execution — modules don't run arbitrary code.
3. New task types can be added to the switch statement without changing any module.
4. A failed cleanup for one module doesn't block cleanup for others.
5. Core data (settings table, options) is always cleaned up regardless of module tasks.

---

### 1.12 Updated Module Base Class (Complete)

With the addition of `get_cleanup_tasks()` from 1.11, here is the complete final `Module_Base`:

```php
<?php
declare(strict_types=1);

namespace WPTransformed\Modules;

if ( ! defined( 'ABSPATH' ) ) exit;

abstract class Module_Base {

    // ── Identity ──
    abstract public function get_id(): string;
    abstract public function get_title(): string;
    abstract public function get_category(): string;
    abstract public function get_description(): string;

    public function get_tier(): string {
        return 'free';
    }

    // ── Lifecycle ──
    abstract public function init(): void;

    public function deactivate(): void {}

    // ── Settings ──
    public function get_default_settings(): array {
        return [];
    }

    final public function get_settings(): array {
        $saved = \WPTransformed\Core\Settings::get( $this->get_id() );
        return wp_parse_args( $saved, $this->get_default_settings() );
    }

    // ── Admin UI ──
    public function render_settings(): void {}

    public function sanitize_settings( array $raw ): array {
        return [];
    }

    // ── Assets ──
    public function enqueue_admin_assets( string $hook ): void {}

    public function enqueue_frontend_assets(): void {}

    // ── Dependencies ──
    public function get_dependencies(): array {
        return [];
    }

    // ── Uninstall Cleanup ──
    public function get_cleanup_tasks(): array {
        return [];
    }
}
```

---

These are things the original spec included in Phase 1 that don't need to be right in the foundation. Adding them later requires zero rewrites.

| Item | Why it can wait | When to add it |
|------|----------------|----------------|
| **React/TypeScript settings panel** | PHP admin pages work fine. React can replace them later without changing any module code (modules just implement `render_settings()` either way). | v2.0 when we build Command Palette and Dashboard Redesign |
| **Vite build pipeline** | No JS bundling needed when there's no React. Individual module JS files load directly. | v2.0, only for the React components |
| **Composer autoloading (PSR-4)** | The simple autoloader in the main file handles core classes. Module files load via registry. Composer comes when we add Freemius SDK as a dependency. | v1.5 when adding Freemius |
| **REST API endpoints** | All v1 operations happen via standard WordPress admin forms and AJAX. REST API is needed when we add a React frontend or external integrations. | v2.0 |
| **Freemius SDK + licensing** | All v1 modules are free. No license checks needed. The `get_tier()` method is in the base class, ready for when Pro modules arrive. | v1.5 or v2.0 |
| **CI/CD pipeline (GitHub Actions)** | Ship working code first. Add automated testing + linting after v1 is proven on a real site. | After v1 is stable |
| **PHPUnit tests** | Controversial, but hear me out (see Part 3 below). | After v1 is manually verified on a real WordPress install |
| **Cypress E2E tests** | Same reasoning as PHPUnit. | After v1 is stable |
| **PHPCS / PHPStan** | Code quality tools. Valuable, but not needed to get 10 modules working. | After v1 is stable |
| **Database migration system** | v1 has one table. The `create_table()` method handles it. A versioned migration system is needed when we start adding more tables (audit log, analytics, etc.). | v1.2+ when adding modules that need custom tables |
| **Performance benchmarking harness** | Measure performance after there's something to measure. Premature optimization data is noise. | After v1 is stable, before v2 launch |
| **Custom admin color schemes** | Dark mode is enough for v1. Multiple schemes add complexity. | v2+ |
| **Settings import/export** | Useful for agencies deploying to multiple sites. Not needed for initial launch. | v2+ |
| **Notification Center** | Requires its own database table, Heartbeat API integration, and React panel. It's a feature, not foundation. | v3+ |
| **Page Builder Auto-Detect** | Nice-to-have for modules like Content Duplication, but not blocking for v1. Content Duplication works fine without it. | v2+ |
| **Internationalization (.pot file)** | All strings should be wrapped in `__()` from day 1 (that's the coding standard). But generating the actual .pot file and setting up translations can wait. | Before WordPress.org submission |
| **readme.txt (full version)** | Needed for WordPress.org. Not needed for development. Write it when we're ready to submit. | Before WordPress.org submission |

---

## Part 3: The Testing Question

**The original approach:** Build tests alongside code. PHPUnit + Cypress from day 1. Tests passed. Plugin didn't work.

**Why tests failed last time:** The tests tested the scaffold, not the functionality. `assertInstanceOf(Module_Base::class, $module)` passes for an empty class. It tells you nothing about whether the module actually works in WordPress.

**The v1 approach: Manual verification first, automated tests second.**

1. Build the module.
2. Activate WPTransformed on a real WordPress install (local dev environment like LocalWP).
3. Run through the verification steps in the module spec. These are manual QA steps, not automated tests.
4. If everything works → commit.
5. After all 10 modules are verified working → THEN write PHPUnit tests that test actual functionality, not scaffolding.

**This is not "skip testing."** It's "test the right things at the right time." The verification steps in the module specs ARE the test plan. They're just executed by a human (or by Claude Code checking the site) rather than by PHPUnit.

**When automated tests get added:**
- **PHPUnit:** After v1 is working. Tests will cover: module activation/deactivation, settings save/load, Content Duplication actually creating a post, SVG sanitization stripping dangerous elements, SMTP configuration setting PHPMailer properties. These test real behavior, not class instantiation.
- **Cypress:** After v1 is working. Tests will cover: clicking the Duplicate link, toggling modules on/off, the settings page rendering correctly, dark mode toggling.
- **PHPCS:** After v1 is working. Enforces coding standards retroactively. Fix any violations in a cleanup pass.

---

## Part 4: What Future Modules Plug Into

When module 11 or module 50 or module 120 gets built, here's what the developer (or Claude Code) does:

### Adding a New Module: 6-Step Checklist

**Step 1:** Create the module class file:
```
modules/{category}/class-{module-slug}.php
```

**Step 2:** Extend `Module_Base` and implement the abstract methods:
```php
<?php
declare(strict_types=1);

namespace WPTransformed\Modules\{CategoryNamespace};

use WPTransformed\Modules\Module_Base;

if ( ! defined( 'ABSPATH' ) ) exit;

class My_New_Module extends Module_Base {
    public function get_id(): string { return 'my-new-module'; }
    public function get_title(): string { return __( 'My New Module', 'wptransformed' ); }
    public function get_category(): string { return 'category-slug'; }
    public function get_description(): string { return __( 'What it does.', 'wptransformed' ); }

    public function get_default_settings(): array {
        return [ 'option_a' => true, 'option_b' => 'default' ];
    }

    public function init(): void {
        $settings = $this->get_settings();
        // Add hooks, filters, etc.
    }

    public function render_settings(): void {
        $settings = $this->get_settings();
        // Echo form HTML
    }

    public function sanitize_settings( array $raw ): array {
        return [
            'option_a' => ! empty( $raw['option_a'] ),
            'option_b' => sanitize_text_field( $raw['option_b'] ?? '' ),
        ];
    }

    // Only override if this module creates persistent data outside wpt_settings
    public function get_cleanup_tasks(): array {
        return [
            [ 'type' => 'user_meta', 'key' => 'wpt_my_module_pref' ],
        ];
    }
}
```

**Step 3:** Add one line to the registry:
```php
// In includes/class-module-registry.php
'my-new-module' => 'modules/{category}/class-my-new-module.php',
```

**Step 4:** Add an `index.php` to any new directories created.

**Step 5:** If the module creates persistent data (user meta, post meta, options, cron jobs, custom tables), implement `get_cleanup_tasks()`. If it only uses hooks/filters and wpt_settings, skip this — the default empty array is correct.

**Step 6:** Test it. Toggle it on. Verify it works.

That's it. No build step. No migration. No config file changes. No React component. One class, one registry line, done.

### What The Foundation Provides To Every Module For Free

| Capability | How the module gets it |
|------------|----------------------|
| Toggle on/off | Built into settings table + settings page. Module does nothing special. |
| Settings storage + retrieval | `$this->get_settings()` — reads from DB, merged with defaults. |
| Settings save with sanitization | `sanitize_settings()` method. Core handles the form submission. |
| Settings page section | `render_settings()` method. Core handles the page chrome. |
| Admin asset loading | Override `enqueue_admin_assets( $hook )`. Core calls it at the right time. |
| Frontend asset loading | Override `enqueue_frontend_assets()`. Core calls it only for active modules. |
| Error isolation | If `init()` throws, module is skipped. Other modules keep running. |
| Dependency checking | `get_dependencies()` method. Core verifies deps are active before `init()`. |
| License gating (future) | `get_tier()` returns 'free' or 'pro'. Core checks license before `init()`. |
| Category grouping | `get_category()` method. Settings page groups by category automatically. |
| Safe mode recovery | Handled by core. If admin is broken, safe mode URL bypasses all modules. |
| Uninstall cleanup | `get_cleanup_tasks()` method. Uninstaller handles execution — module just declares its data footprint. |

### What A Module Must Handle Itself

| Responsibility | Why it's per-module |
|----------------|-------------------|
| Its own hooks and filters | Only the module knows what WordPress hooks it needs. |
| Nonce verification on custom actions | Actions outside the settings page (like Duplicate link) need their own nonce. |
| Capability checks on custom actions | Same reason — context-specific. |
| Input sanitization in forms | `sanitize_settings()` — only the module knows its data types. |
| Output escaping in templates | `esc_html()` etc. at every echo. Standard coding practice. |
| Database queries (if any) | Most modules don't touch the DB directly. Those that do (Database Cleanup) use `$wpdb->prepare()`. |
| Custom database tables (if any) | Only 3-4 modules will ever need custom tables (Audit Log, Analytics, 404 Monitor). They handle their own table creation in `init()` or a separate migration. |
| REST API endpoints (if any) | Modules that need REST routes register them in `init()` via `register_rest_route()`. |

---

## Summary: The Foundation Contract

| Decision | Choice | Scales because | Painful to change because |
|----------|--------|----------------|--------------------------|
| Module discovery | Explicit registry array | One line per module, no filesystem scanning | Every module is in the registry; changing the mechanism means updating the loader and all docs |
| Settings storage | Custom `wpt_settings` table with JSON | Single query, no autoload bloat, flexible schema | Migrating 120 modules from wp_options would be a nightmare |
| Module interface | `Module_Base` abstract class | Clear contract, every module follows same pattern | 120+ classes extend it; method signature changes cascade |
| Asset loading | Per-module methods with `$hook` parameter | Modules control their own loading, most load nothing | Changing the enqueue approach means updating every module |
| Security | Patterns embedded in code, not in separate doc | Developers follow the pattern they see, not a doc they might not read | Bolting on nonce checks to 120 modules after the fact is painful |
| Admin UI | Native WordPress PHP | Works immediately, no build step, no failure modes | Not painful to change — React can replace `render_settings()` later without touching module logic |
| Settings page (Admin class) | Centralized form handling, AJAX toggle, per-module routing | Modules only implement render + sanitize; all submission/nonce/routing logic is in one place | Every module's settings flow through it; changing the routing means updating the save handler and every module's nonce expectations |
| Safe mode | Token-based URL parameter, disables all modules | Settings page works independently of modules; user can always recover | Without it, a fatal in any module = locked out of admin. Can't retrofit if the plugin is already broken |
| Uninstall cleanup | Declarative `get_cleanup_tasks()` on Module_Base | Predictable loop handles all types; adding a new type = one switch case | At 120 modules, hunting through each file to find what data it created is unmaintainable |
| Testing | Manual verification → automated tests later | Proves real functionality, not scaffolding | Not painful to change — tests are additive |
| Build pipeline | None in v1 | Nothing to break | Not painful to change — Vite can be added for specific features later |
