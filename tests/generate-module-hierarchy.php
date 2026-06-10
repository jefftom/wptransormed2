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
$md[] = '- Implemented split: **' . ( count( $implemented ) - count( $pro ) ) . ' Core implemented**, **' . count( $pro ) . ' Pro implemented**, ' . count( $stubs ) . ' Core stubs';
$md[] = '- Pro modules: ' . implode( ', ', array_map( fn( $id ) => "`{$id}`", array_keys( $pro ) ) );
$md[] = '- Stub modules (spec exists, implementation pending): ' . implode( ', ', array_map( fn( $id ) => "`{$id}`", array_keys( $stubs ) ) );
$md[] = '- Mixed-tier parent cards (Core + Pro sub-modules; per-sub Pro gating applies): **' . count( array_filter( $visible_parents, function ( $p ) use ( $defs ) {
    $tiers = [];
    foreach ( Module_Hierarchy::filter_existing_sub_modules( $p['sub_modules'] ?? [] ) as $id ) {
        $tiers[ $defs[ $id ]['tier'] ] = true;
    }
    return isset( $tiers['core'], $tiers['pro'] );
} ) ) . '**';
$md[] = '- Companion integrations: none defined yet (no companion tier/status in definitions; companions register via the `wpt_registered_modules` filter when built)';
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

        // Parent ids are grouping keys, not module ids — annotate any that
        // coincide with a legacy module slug (documented exception).
        $key_note = isset( Module_Registry::get_legacy_map()[ $parent['id'] ] )
            ? ' _(grouping key only — not a module id)_'
            : '';

        $md[] = '';
        $md[] = '#### ' . $parent['label'] . ' — `' . $parent['id'] . '`' . $key_note . ( $flags ? ' _[' . implode( '; ', $flags ) . ']_' : '' );
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
$md[] = '## Canonical Slug Registry (' . count( $defs ) . ')';
$md[] = '';
$md[] = '### Core launch modules — implemented (' . ( count( $implemented ) - count( $pro ) ) . ')';
$md[] = '';
$core_impl = array_keys( array_filter( $defs, fn( $d ) => 'core' === $d['tier'] && 'implemented' === $d['status'] ) );
sort( $core_impl );
$md[] = implode( ', ', array_map( fn( $id ) => "`{$id}`", $core_impl ) );
$md[] = '';
$md[] = '### Pro launch modules (' . count( $pro ) . ')';
$md[] = '';
$pro_ids = array_keys( $pro );
sort( $pro_ids );
$md[] = implode( ', ', array_map( fn( $id ) => "`{$id}`", $pro_ids ) );
$md[] = '';
$md[] = '### Core stubs — spec exists, implementation pending (' . count( $stubs ) . ')';
$md[] = '';
$stub_ids = array_keys( $stubs );
sort( $stub_ids );
$md[] = implode( ', ', array_map( fn( $id ) => "`{$id}`", $stub_ids ) );

$aspirational = [];
foreach ( $parents as $parent ) {
    foreach ( array_diff( $parent['sub_modules'] ?? [], Module_Hierarchy::filter_existing_sub_modules( $parent['sub_modules'] ?? [] ) ) as $id ) {
        $aspirational[ $id ] = true;
    }
}
$aspirational = array_keys( $aspirational );
sort( $aspirational );
$md[] = '';
$md[] = '## Deferred / Future — NOT implemented (' . count( $aspirational ) . ')';
$md[] = '';
$md[] = 'Aspirational hierarchy ids with NO definition. Filtered from the UI at render time;';
$md[] = 'archived-roadmap material only — never treat these as live modules.';
$md[] = '';
$md[] = implode( ', ', array_map( fn( $id ) => "`{$id}`", $aspirational ) );

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
$md[] = '## Migration, Loading & Gating Notes';
$md[] = '';
$md[] = '- **Legacy slug migration:** 10 module ids were renamed to canonical slugs (see the';
$md[] = '  Legacy alias columns above). The one-time, idempotent migration is gated by the';
$md[] = '  `wpt_slug_version` option and audited in `wpt_slug_migration_v1_backup`. Legacy ids';
$md[] = '  remain resolvable aliases for old exports/bookmarks but are never written back.';
$md[] = '- **Zero-load:** module implementation files are included only when a module is active,';
$md[] = '  status `implemented`, tier-allowed, and not quarantined. Inactive modules contribute';
$md[] = '  zero file includes, instances, hooks, or assets; all cards/search render from definitions.';
$md[] = '- **Pro gating:** locks derive from definition tiers. Unlicensed Pro implementation files';
$md[] = '  never load; locked cards render from definitions. A parent card is fully locked only';
$md[] = '  when every built sub-module is Pro; mixed parents gate per sub-module.';
$md[] = '- **History:** the archived 125/141-module docs under `docs/archive/` are roadmap material';
$md[] = '  only and must not be used as implementation authority.';
$md[] = '';

$out = implode( "\n", $md );
file_put_contents( WPT_PATH . 'docs/module-hierarchy.md', $out );

// Self-check 1: every canonical id appears in the doc.
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

// Self-check 2: legacy slugs appear ONLY in alias cells / migration notes.
$legacy_map = Module_Registry::get_legacy_map();
$violations = [];
foreach ( explode( "\n", $out ) as $n => $line ) {
    foreach ( $legacy_map as $legacy => $canonical ) {
        if ( false === strpos( $line, '`' . $legacy . '`' ) ) {
            continue;
        }
        $is_alias_cell     = false !== strpos( $line, '`' . $canonical . '`' ); // table row pairing alias with canonical
        $is_migration_note = (bool) preg_match( '/legacy|migration|alias|grouping key/i', $line );
        if ( ! $is_alias_cell && ! $is_migration_note ) {
            $violations[] = 'line ' . ( $n + 1 ) . ": {$legacy}";
        }
    }
}
if ( $violations ) {
    fwrite( STDERR, 'Legacy slugs outside alias/migration context: ' . implode( '; ', $violations ) . "\n" );
    exit( 1 );
}

// Self-check 3: every aspirational id is listed in the Deferred section and none has a definition.
$deferred_section = substr( $out, strpos( $out, '## Deferred / Future' ) );
$deferred_section = substr( $deferred_section, 0, strpos( $deferred_section, "\n## " ) ?: strlen( $deferred_section ) );
$asp_missing      = [];
foreach ( $aspirational as $id ) {
    if ( isset( $defs[ $id ] ) || false === strpos( $deferred_section, '`' . $id . '`' ) ) {
        $asp_missing[] = $id;
    }
}
if ( $asp_missing ) {
    fwrite( STDERR, 'Aspirational ids missing from Deferred section (or wrongly defined): ' . implode( ', ', $asp_missing ) . "\n" );
    exit( 1 );
}

echo 'Generated docs/module-hierarchy.md — ' . count( $defs ) . ' modules present, '
    . count( $aspirational ) . " aspirational ids consolidated as Deferred, legacy slugs confined to alias/migration context.\n";
