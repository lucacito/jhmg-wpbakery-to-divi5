#!/usr/bin/env php
<?php
/**
 * Builds plugin/jhmg-converter-for-wpbakery-to-divi/data/wpbakery-params.json —
 * every parameter name WPBakery itself declares for each of its elements.
 *
 * Why the file exists (task-11-amendments §7). A field on a WPBakery core
 * element that no handler consumed is one of two very different things: a gap
 * in this converter (WPBakery declares the field and nothing here read it), or
 * a field a theme or plugin bolted onto the element with `vc_add_param()`,
 * which belongs to software that is not being converted. `ThemeShortcodes::THEME_PARAMS`
 * answers that for the themes whose source is in `references/`, but a name can
 * only enter that table from a shipped registration — never from a corpus — so
 * parameters written by builds of a theme nobody has the source for stayed
 * indistinguishable from converter gaps.
 *
 * This table turns the question round and grounds it in WPBakery's own source:
 * a name WPBakery does not declare for that tag was added by somebody else,
 * whoever that was. Nothing is dropped either way; the two are worded
 * differently in the report.
 *
 * Run like `scripts/build-fa-icon-map.php`, against the reference archives:
 *
 *   php scripts/build-wpbakery-params.php                      # both zips in references/
 *   php scripts/build-wpbakery-params.php /path/to/js_composer.9.0.1.zip …
 *   php scripts/build-wpbakery-params.php /path/to/extracted/js_composer …
 *
 * Output shape: { "<tag>": [ "<param_name>", … ] }, tags and names sorted, so
 * a regeneration from the same archives is byte-identical.
 *
 * **What is read.** `config/**` — the element configs `config/lean-map.php`
 * lazily maps a tag to, containers and deprecated elements included — plus the
 * grid-item element configs under `include/params/vc_grid_item/shortcodes/`,
 * which declare their own `'base'`. A config the lean map does not name and
 * that carries no `'base'` (the elements registered from
 * `include/classes/shortcodes/`, such as `vc_flexbox_container`) is attributed
 * by the file-name convention the lean map itself follows:
 * `shortcode-vc-flexbox-container.php` ⇒ `vc_flexbox_container`.
 *
 * Three things a config leaves to a helper are resolved rather than lost:
 *
 *  1. every function or method the config calls that itself declares params —
 *     `vc_config()->get_design_options_tab()` and its siblings (9.0.1, read out
 *     of `include/classes/editors/class-vc-config-lib.php`),
 *     `vc_btn_element_params()`, `VcGridsCommon::getBasicAtts()`;
 *  2. `vc_map_integrate_shortcode( <source>, '<prefix>', … )`, which is how
 *     `vc_cta` comes to carry `btn_title` and `h2_font_container` — the source's
 *     names are added under the prefix;
 *  3. a variable holding either of those, assigned in the same file.
 *
 * A tag the table has no entry for is not "a tag with no parameters": it is a
 * tag this file could not read, and the converter treats it as unknown rather
 * than as declaring nothing (`WPBakeryParams::declares()` returns null).
 */

/** Where a source's element configs live, relative to the js_composer root. */
const CONFIG_DIRS = [ 'config/', 'include/params/vc_grid_item/shortcodes/' ];

/** The 9.0.1 helper class whose methods configs call for the shared param groups. */
const CONFIG_LIB = 'include/classes/editors/class-vc-config-lib.php';

$sources = array_slice( $argv, 1 );
if ( $sources === [] ) {
    $sources = [
        dirname( __DIR__ ) . '/references/js_composer.9.0.1.zip',
        dirname( __DIR__ ) . '/references/js_composer.7.8.zip',
    ];
}

$table = [];
$read  = [];

foreach ( $sources as $source ) {
    $files = read_source( $source );

    if ( $files === null ) {
        fwrite( STDERR, "cannot read {$source} (expected a js_composer zip or an extracted js_composer directory)\n" );
        exit( 2 );
    }

    $found = params_from( $files );
    $read[] = sprintf( '%s: %d tags', basename( $source ), count( $found ) );

    foreach ( $found as $tag => $names ) {
        foreach ( $names as $name ) {
            $table[ $tag ][ $name ] = true;
        }
    }
}

ksort( $table );

$out_table = [];
$total     = 0;

foreach ( $table as $tag => $names ) {
    $list = array_keys( $names );
    sort( $list );
    $out_table[ $tag ] = $list;
    $total            += count( $list );
}

$out = dirname( __DIR__ ) . '/plugin/jhmg-converter-for-wpbakery-to-divi/data/wpbakery-params.json';
if ( ! is_dir( dirname( $out ) ) ) {
    mkdir( dirname( $out ), 0755, true );
}

file_put_contents( $out, json_encode( $out_table, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );

printf( "read %s\n", implode( '; ', $read ) );
printf( "wrote %s (%d tags, %d parameter names)\n", $out, count( $out_table ), $total );

// ---------------------------------------------------------------------------

/**
 * Every PHP file of one source, keyed by its path under the js_composer root.
 *
 * @return array<string,string>|null
 */
function read_source( string $source ): ?array {
    if ( is_dir( $source ) ) {
        $root  = rtrim( $source, '/' );
        $root  = is_dir( $root . '/js_composer' ) ? $root . '/js_composer' : $root;
        $files = [];

        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() && strtolower( $file->getExtension() ) === 'php' ) {
                $files[ ltrim( str_replace( $root, '', $file->getPathname() ), '/' ) ] = (string) file_get_contents( $file->getPathname() );
            }
        }

        return $files === [] ? null : $files;
    }

    if ( ! is_file( $source ) ) {
        return null;
    }

    $zip = new ZipArchive();
    if ( $zip->open( $source ) !== true ) {
        return null;
    }

    $files = [];
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $name = (string) $zip->getNameIndex( $i );
        if ( substr( $name, -4 ) !== '.php' ) {
            continue;
        }
        $relative = preg_replace( '#^js_composer/#', '', $name );
        $contents = $zip->getFromIndex( $i );
        if ( $contents !== false ) {
            $files[ $relative ] = $contents;
        }
    }
    $zip->close();

    return $files === [] ? null : $files;
}

/**
 * tag ⇒ param names, for one source.
 *
 * @param array<string,string> $files
 * @return array<string, string[]>
 */
function params_from( array $files ): array {
    $functions = function_params( $files );
    $lean      = lean_map( $files );

    // Which config file belongs to which tag: the lean map's, plus any file
    // that names its own `'base'` (the grid-item elements).
    $tags_for = [];

    foreach ( $lean as $tag => $path ) {
        if ( isset( $files[ $path ] ) ) {
            $tags_for[ $path ][] = $tag;
        }
    }

    foreach ( $files as $path => $source ) {
        if ( ! is_config_path( $path ) || isset( $tags_for[ $path ] ) ) {
            continue;
        }

        $bases = bases_in( $source );
        if ( $bases === [] ) {
            $bases = array_filter( [ tag_from_file_name( $path ) ] );
        }

        foreach ( $bases as $base ) {
            $tags_for[ $path ][] = $base;
        }
    }

    // Pass one: each tag's own names, before the integrations that need them.
    $direct = [];
    foreach ( $tags_for as $path => $tags ) {
        $names = param_names( $files[ $path ] );

        foreach ( helper_calls( $files[ $path ], $functions ) as $helper ) {
            $names = array_merge( $names, $functions[ $helper ] );
        }

        foreach ( $tags as $tag ) {
            $direct[ $tag ] = array_merge( $direct[ $tag ] ?? [], $names );
        }
    }

    // Pass two: `vc_map_integrate_shortcode()` — another element's params under
    // a prefix, which is where `vc_cta`'s `btn_*` and `h2_*` fields come from.
    $table = $direct;
    foreach ( $tags_for as $path => $tags ) {
        foreach ( integrations( $files[ $path ] ) as [ $reference, $prefix ] ) {
            $names = resolve_reference( $reference, $files[ $path ], $functions, $direct );

            foreach ( $tags as $tag ) {
                foreach ( $names as $name ) {
                    $table[ $tag ][] = $prefix . $name;
                }
            }
        }
    }

    foreach ( $table as $tag => $names ) {
        $table[ $tag ] = array_values( array_unique( $names ) );
    }

    return $table;
}

/**
 * `config/containers/shortcode-vc-flexbox-container.php` ⇒ `vc_flexbox_container`,
 * the convention `config/lean-map.php` follows for every element it does name.
 */
function tag_from_file_name( string $path ): string {
    if ( preg_match( '#/shortcode-(vc-[a-z0-9-]+)\.php$#', $path, $match ) !== 1 ) {
        return '';
    }

    return str_replace( '-', '_', $match[1] );
}

function is_config_path( string $path ): bool {
    foreach ( CONFIG_DIRS as $directory ) {
        if ( str_starts_with( $path, $directory ) ) {
            return true;
        }
    }

    return false;
}

/**
 * `vc_lean_map( 'vc_row', null, $vc_config_path . '/containers/shortcode-vc-row.php' )`
 * ⇒ `vc_row` ⇒ `config/containers/shortcode-vc-row.php`.
 *
 * @param array<string,string> $files
 * @return array<string,string>
 */
function lean_map( array $files ): array {
    $source = $files['config/lean-map.php'] ?? '';
    $map    = [];

    if ( preg_match_all( '/vc_lean_map\(\s*[\'"]([a-z0-9_]+)[\'"].*?[\'"](\/[^\'"]+\.php)[\'"]/is', $source, $matches, PREG_SET_ORDER ) === 0 ) {
        return $map;
    }

    foreach ( $matches as $match ) {
        $map[ $match[1] ] = 'config' . $match[2];
    }

    return $map;
}

/** @return string[] Every `'base' => 'tag'` in a file, in document order. */
function bases_in( string $source ): array {
    if ( preg_match_all( '/[\'"]base[\'"]\s*=>\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $matches ) === 0 ) {
        return [];
    }

    return array_values( array_unique( $matches[1] ) );
}

/** @return string[] Every `'param_name' => 'x'` in a file. */
function param_names( string $source ): array {
    if ( preg_match_all( '/[\'"]param_name[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $source, $matches ) === 0 ) {
        return [];
    }

    return array_values( array_unique( $matches[1] ) );
}

/**
 * Function name ⇒ the param names its body declares.
 *
 * Both the `Vc_Config` methods a 9.0.1 config calls for the shared groups and
 * the element param functions (`vc_btn_element_params()`) are found this way,
 * so neither needs a hard-coded list here.
 *
 * @param array<string,string> $files
 * @return array<string, string[]>
 */
function function_params( array $files ): array {
    $found = [];

    foreach ( $files as $path => $source ) {
        if ( ! is_config_path( $path ) && $path !== CONFIG_LIB ) {
            continue;
        }

        if ( preg_match_all( '/function\s+([a-z0-9_]+)\s*\(/i', $source, $matches, PREG_OFFSET_CAPTURE ) === 0 ) {
            continue;
        }

        foreach ( $matches[1] as $index => $match ) {
            $body = function_body( $source, (int) $matches[0][ $index ][1] );
            if ( $body === '' ) {
                continue;
            }

            $names = param_names( $body );
            if ( $names !== [] ) {
                $found[ $match[0] ] = array_values( array_unique( array_merge( $found[ $match[0] ] ?? [], $names ) ) );
            }
        }
    }

    return $found;
}

/** The `{ … }` after a function signature, matched by counting braces. */
function function_body( string $source, int $offset ): string {
    $open = strpos( $source, '{', $offset );
    if ( $open === false ) {
        return '';
    }

    $depth  = 0;
    $length = strlen( $source );

    for ( $i = $open; $i < $length; $i++ ) {
        if ( $source[ $i ] === '{' ) {
            $depth++;
        } elseif ( $source[ $i ] === '}' ) {
            $depth--;
            if ( $depth === 0 ) {
                return substr( $source, $open, $i - $open + 1 );
            }
        }
    }

    return '';
}

/**
 * The calls a config makes to something that itself declares parameters —
 * `vc_config()->get_design_options_tab()`, `vc_btn_element_params()`,
 * `VcGridsCommon::getBasicAtts()`.
 *
 * Every call in the file is looked up in the function table rather than
 * matched against a list of names, so a helper this project has not seen is
 * picked up as long as its body declares `param_name`s.
 *
 * @param array<string, string[]> $functions
 * @return string[]
 */
function helper_calls( string $source, array $functions ): array {
    if ( preg_match_all( '/([a-z0-9_]+)\s*\(/i', $source, $matches ) === 0 ) {
        return [];
    }

    return array_values( array_unique( array_filter(
        $matches[1],
        static fn( string $name ): bool => isset( $functions[ $name ] )
    ) ) );
}

/**
 * Every `vc_map_integrate_shortcode( <source>, '<prefix>', … )` call.
 *
 * @return array<int, array{0: string, 1: string}> [the first argument as written, the prefix]
 */
function integrations( string $source ): array {
    if ( preg_match_all(
        '/vc_map_integrate_shortcode\(\s*(\(array\)\s*)?([^,]+?)\s*,\s*[\'"]([^\'"]*)[\'"]/s',
        $source,
        $matches,
        PREG_SET_ORDER
    ) === 0 ) {
        return [];
    }

    $found = [];
    foreach ( $matches as $match ) {
        $found[] = [ trim( $match[2] ), $match[3] ];
    }

    return $found;
}

/**
 * The param names an integration's first argument stands for: a tag in quotes,
 * a call to a param function, or a variable assigned one of those in the same
 * file.
 *
 * @param array<string, string[]> $functions
 * @param array<string, string[]> $direct
 * @return string[]
 */
function resolve_reference( string $reference, string $source, array $functions, array $direct, int $depth = 0 ): array {
    if ( $depth > 3 ) {
        return [];
    }

    if ( preg_match( '/^[\'"]([a-z0-9_]+)[\'"]$/i', $reference, $match ) === 1 ) {
        return $direct[ $match[1] ] ?? [];
    }

    if ( preg_match( '/^([a-z0-9_]+)\s*\(/i', $reference, $match ) === 1 ) {
        return $functions[ $match[1] ] ?? [];
    }

    if ( preg_match( '/^\$([a-z0-9_]+)$/i', $reference, $match ) === 1 ) {
        if ( preg_match( '/\$' . preg_quote( $match[1], '/' ) . '\s*=\s*([^;]+);/s', $source, $assignment ) === 1 ) {
            return resolve_reference( trim( $assignment[1] ), $source, $functions, $direct, $depth + 1 );
        }
    }

    return [];
}
