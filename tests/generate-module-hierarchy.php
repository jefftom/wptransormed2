<?php
declare(strict_types=1);

/**
 * docs/module-hierarchy.md generator — plain PHP CLI dev tool.
 *
 * Regenerates the module hierarchy document from definition truth:
 *   1. Module_Registry definitions (canonical slugs per build authority
 *      v5.3.6 §12 / canonical scope v5.2.3; tiers/risk/status per the
 *      definition arrays).
 *   2. Module_Hierarchy grouping (presentation layer: parents, order,
 *      categories).
 *   3. Phase 1 reframe v4.1 statuses (implemented/stub via definitions).
 *
 * Usage: php tests/generate-module-hierarchy.php
 * Output: docs/module-hierarchy.md (overwritten)
 *
 * @package WPTransformed
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPT_PATH', dirname( __DIR__ ) . '/' );

function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function apply_filters( $t, $v, ...$a ) { return $v; }
function get_option( $k, $d = false ) { return $d; }
function update_option( ...$a ) { return true; }
function delete_option( $k ) { return true; }
function user_can( $u, $c ) { return false; }
function current_user_can( $c ) { return false; }
function get_current_user_id() { return 0; }

require WPT_PATH . 'includes/class-module-base.php';
require WPT_PATH . 'includes/class-permission-manager.php';
require WPT_PATH . 'includes/class-module-registry.php';
require WPT_PATH . 'includes/class-module-hierarchy.php';

use WPTransformed\Core\Module_Hierarchy;
use WPTransformed\Core\Module_Registry;
use WPTransformed\Core\Permission_Manager;

$defs = Module_Registry::get_definitions();
if ( 83 !== count( $defs ) ) {
    fwrite( STDERR, 'Expected 83 definitions, got ' . count( $defs ) . "\n" );
    exit( 1 );
}
$invalid = Module_Registry::validate_all();
if ( [] !== $invalid ) {
    fwrite( STDERR, 'Invalid definitions: ' . implode( ', ', array_keys( $invalid ) ) . "\n" );
    exit( 1 );
}

$implemented = array_filter( $defs, fn( $d ) => 'implemented' === $d['status'] );
$stubs       = array_filter( $defs, fn( $d ) => 'stub' === $d['status'] );
$pro         = array_filter( $defs, fn( $d ) => 'pro' === $d['tier'] );

$assigned = [];
foreach ( Module_Hierarchy::get_parents() as $p ) {
    foreach ( Module_Hierarchy::filter_existing_sub_modules( $p['sub_modules'] ?? [] ) as $id ) {
        $assigned[ $id ] = true;
    }
}
$unassigned = array_diff( array_keys( $defs ), array_keys( $assigned ) );

$categories      = Module_Hierarchy::get_categories();
$parents         = Module_Hierarchy::get_parents();
$visible_parents = Module_Hierarchy::get_visible_parents();
$visible_ids     = array_column( $visible_parents, 'id' );
$hidden_parents  = array_filter( $parents, fn( $p ) => ! in_array( $p['id'], $visible_ids, true ) );

$md   = [];
$md[] = '# WPTransformed Module Hierarchy';
$md[] = '';
$md[] = '> GENERATED FILE — do not hand-edit. Regenerate with: `php tests/generate-module-hierarchy.php`';
$md[] = '> Generated: ' . gmdate( 'Y-m-d' ) . ' from Module_Registry definitions (canonical slugs per build';
$md[] = '> authority v5.3.6 §12 / canonical scope v5.2.3) + Module_Hierarchy grouping + Phase 1 reframe';
$md[] = '> v4.1 statuses. Supersedes the archived 141-module hierarchy (`docs/archive/module-hierarchy.md`),';
$md[] = '> which is NOT implementation authority.';
$md[] = '';
$md[] = '## Summary';
$md[] = '';
$md[] = '- Registry modules: **' . count( $defs ) . '** (' . count( $implemented ) . ' implemented, ' . count( $stubs ) . ' stubs)';
$md[] = '- Tiers: **' . ( count( $defs ) - count( $pro ) ) . ' Core**, **' . count( $pro ) . ' Pro**';
$md[] = '- Display categories: ' . count( $categories ) . '; parent cards: ' . count( $parents ) . ' (' . count( $visible_parents ) . ' visible, ' . count( $hidden_parents ) . ' hidden — zero built sub-modules)';
$md[] = '- Pro modules: ' . implode( ', ', array_map( fn( $id ) => "`{$id}`", array_keys( $pro ) ) );
$md[] = '- Stub modules (spec exists, implementation pending): ' . implode( ', ', array_map( fn( $id ) => "`{$id}`", array_keys( $stubs ) ) );
$md[] = '- Not assigned to any parent card: ' . ( $unassigned ? implode( ', ', array_map( fn( $id ) => "`{$id}`", $unassigned ) ) : 'none' );
$md[] = '';
$md[] = '## Display Hierarchy';

foreach ( $categories as $cat_slug => $cat ) {
    $cat_parents = array_values( array_filter( $visible_parents, fn( $p ) => $p['category'] === $cat_slug ) );
    if ( ! $cat_parents ) {
        continue;
    }
    $md[] = '';
    $md[] = '### ' . $cat['label'] . ' (' . count( $cat_parents ) . ' parent' . ( 1 === count( $cat_parents ) ? '' : 's' ) . ')';

    foreach ( $cat_parents as $parent ) {
        $built    = Module_Hierarchy::filter_existing_sub_modules( $parent['sub_modules'] ?? [] );
        $deferred = array_values( array_diff( $parent['sub_modules'] ?? [], $built ) );

        $all_pro = count( $built ) > 0;
        foreach ( $built as $id ) {
            if ( 'pro' !== $defs[ $id ]['tier'] ) {
                $all_pro = false;
            }
        }

        $flags = [];
        if ( ! empty( $parent['app_page'] ) ) {
            $flags[] = 'APP → `' . $parent['app_page'] . '`';
        }
        if ( $all_pro ) {
            $flags[] = 'LOCKED (all sub-modules Pro)';
        }
        if ( $parent['badges'] ?? [] ) {
            $flags[] = 'badges: ' . implode( '/', $parent['badges'] );
        }

        $md[] = '';
        $md[] = '#### ' . $parent['label'] . ' — `' . $parent['id'] . '`' . ( $flags ? ' _[' . implode( '; ', $flags ) . ']_' : '' );
        $md[] = '';
        $md[] = $parent['description'];
        $md[] = '';
        $md[] = '| Module | Title | Tier | Risk | Status | Legacy alias |';
        $md[] = '|---|---|---|---|---|---|';
        foreach ( $built as $id ) {
            $d    = $defs[ $id ];
            $md[] = '| `' . $id . '` | ' . $d['title'] . ' | ' . $d['tier'] . ' | ' . $d['risk'] . ' | ' . $d['status'] . ' | ' . ( $d['legacy_ids'] ? '`' . implode( '`, `', $d['legacy_ids'] ) . '`' : '—' ) . ' |';
        }
        if ( $deferred ) {
            $md[] = '';
            $md[] = 'Deferred (no definition yet — filtered from the UI): ' . implode( ', ', array_map( fn( $id ) => "`{$id}`", $deferred ) );
        }
    }
}

$md[] = '';
$md[] = '## Hidden Parent Cards (zero built sub-modules)';
$md[] = '';
foreach ( $hidden_parents as $parent ) {
    $md[] = '- **' . $parent['label'] . '** — `' . $parent['id'] . '` — deferred: ' . implode( ', ', array_map( fn( $id ) => "`{$id}`", $parent['sub_modules'] ?? [] ) );
}

$md[] = '';
$md[] = '## App Pages';
$md[] = '';
$md[] = '| Page slug | Boundary capability | Backing module |';
$md[] = '|---|---|---|';
$page_modules = [];
foreach ( $defs as $id => $d ) {
    if ( ! empty( $d['app_page'] ) ) {
        $page_modules[ $d['app_page'] ] = $id;
    }
}
foreach ( Permission_Manager::PAGE_CAPS as $slug => $cap ) {
    $md[] = '| `' . $slug . '` | `' . $cap . '` | ' . ( isset( $page_modules[ $slug ] ) ? '`' . $page_modules[ $slug ] . '`' : '— (core surface)' ) . ' |';
}

$md[] = '';
$md[] = '## System Foundation Services (Phase 1 — not registry modules)';
$md[] = '';
$md[] = 'Specified in `docs/modules/system/`: module-registry, module-loader, settings-storage,';
$md[] = 'permission-model, recovery-center (Safe Mode), conflict-detector (not yet built),';
$md[] = 'module-library, dashboard-shell, import-export, admin-chrome-foundation,';
$md[] = 'editor-dashboard-shell. These are always-available services, not toggleable modules.';
$md[] = '';

$out = implode( "\n", $md );
file_put_contents( WPT_PATH . 'docs/module-hierarchy.md', $out );

// Self-check: every canonical id appears in the doc exactly where expected.
$missing = [];
foreach ( array_keys( $defs ) as $id ) {
    if ( false === strpos( $out, '`' . $id . '`' ) ) {
        $missing[] = $id;
    }
}
if ( $missing ) {
    fwrite( STDERR, 'Doc missing module ids: ' . implode( ', ', $missing ) . "\n" );
    exit( 1 );
}

echo 'Generated docs/module-hierarchy.md — ' . count( $defs ) . " modules, all present in doc.\n";
