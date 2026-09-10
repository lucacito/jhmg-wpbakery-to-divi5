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
 *   php scripts/build-wpbakery-params.php references/js_composer.7.8.zip --out=/tmp/one-era.json
 *
 * Output shape: { "<tag>": [ "<param_name>", … ] }, tags and names sorted, so
 * a regeneration from the same archives is byte-identical.
 *
 * **What is read.** `config/**` — the element configs `config/lean-map.php`
 * lazily maps a tag to, containers and deprecated elements included — plus the
 * grid-item element configs, which declare their own `'base'`
 * (`include/params/vc_grid_item/shortcodes/` in 9.0.1, the single
 * `shortcodes.php` of the same directory in 7.8), plus the nineteen deprecated
 * fields the 9.0 attribute migration re-registers with `vc_add_param()`
 * (`LEGACY_PARAMS`). A config the lean map does not name and
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
 *  3. a variable a `'params' =>` names, and the variables that one holds in
 *     turn, assigned anywhere in the same file.
 *
 * A file that maps several elements in a row — 7.8's grid-item `shortcodes.php`
 * is the only one either era has — is cut at each `'base' =>` so one element's
 * fields do not become all sixteen elements'.
 *
 * A tag whose config **was** read and declares nothing is written as an
 * explicit `[]` — 9.0.1's `vc_gitem_zone` is one — and every name on such a
 * tag is therefore undeclared. A tag with **no entry** is one this file could
 * not read, which the converter treats as unknown rather than as declaring
 * nothing (`WPBakeryParams::declares()` returns null). Keeping the two apart
 * is the whole point of emitting the empty entry.
 */

/**
 * Where a source's element configs live, relative to the js_composer root.
 *
 * A trailing slash is a directory prefix; anything else is one file. 9.0.1
 * gives the grid-item elements a file each under
 * `include/params/vc_grid_item/shortcodes/`; 7.8 declares all sixteen of them
 * in one `shortcodes.php`, which is the only multi-`'base'` file either era
 * has and the only place two names are declared at all
 * (`vc_gitem_image.border_color`, `vc_gitem_post_categories.category_color`).
 */
const CONFIG_PATHS = [
    'config/',
    'include/params/vc_grid_item/shortcodes/',
    'include/params/vc_grid_item/shortcodes.php',
];

/** The 9.0.1 helper class whose methods configs call for the shared param groups. */
const CONFIG_LIB = 'include/classes/editors/class-vc-config-lib.php';

/**
 * 9.0.1 keeps the fields it deprecated in 9.0 registered — as hidden params, so
 * a page saved before the upgrade still round-trips — by looping
 * `vc_add_param()` over a table of nineteen `element` + `param_name` pairs.
 * They are WPBakery's own fields and no config file declares them any more.
 * `CLAUDE.md` names this file as a source of field names.
 */
const LEGACY_PARAMS = 'include/classes/migrations/class-wpb-template-attributes-migration.php';

$out     = dirname( __DIR__ ) . '/plugin/jhmg-converter-for-wpbakery-to-divi/data/wpbakery-params.json';
$sources = [];

foreach ( array_slice( $argv, 1 ) as $argument ) {
    if ( str_starts_with( $argument, '--out=' ) ) {
        $out = substr( $argument, 6 );

        continue;
    }

    $sources[] = $argument;
}

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
        // A tag with an empty list is a tag whose config was read and declared
        // nothing; it must survive the merge as an empty entry.
        $table[ $tag ] = $table[ $tag ] ?? [];

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

    $segments = [];
    foreach ( $tags_for as $path => $tags ) {
        $segments[ $path ] = segments_for( $files[ $path ], $tags );
    }

    // Pass one: each tag's own names, before the integrations that need them.
    // A tag whose config was read is a key here even when the config declares
    // nothing, so "declares no parameters" stays distinguishable from "this
    // generator could not read it" — the converter treats the two differently.
    $direct = [];
    foreach ( $segments as $path => $file_segments ) {
        foreach ( $file_segments as [ $tag, $segment ] ) {
            $names = names_in( $segment, $files[ $path ], $functions );

            $direct[ $tag ] = array_merge( $direct[ $tag ] ?? [], $names );
        }
    }

    // Pass two: `vc_map_integrate_shortcode()` — another element's params under
    // a prefix, which is where `vc_cta`'s `btn_*` and `h2_*` fields come from.
    // Per segment, not per file: 7.8 maps sixteen elements in one file and
    // integrates `vc_icon` into exactly one of them.
    $table = $direct;
    foreach ( $segments as $path => $file_segments ) {
        foreach ( $file_segments as [ $tag, $segment ] ) {
            foreach ( integrations( $segment ) as [ $reference, $prefix, $target ] ) {
                $names = resolve_reference( $reference, $files[ $path ], $functions, $direct );
                $into  = $target !== '' ? $target : $tag;

                foreach ( $names as $name ) {
                    $table[ $into ][] = $prefix . $name;
                }
            }
        }
    }

    // The fields 9.0.1 keeps registered only as deprecated hidden params.
    foreach ( legacy_params( $files ) as [ $tag, $name ] ) {
        $table[ $tag ][] = $name;
    }

    foreach ( $table as $tag => $names ) {
        $table[ $tag ] = array_values( array_unique( $names ) );
    }

    return $table;
}

/**
 * The stretch of a config file each of its tags owns.
 *
 * One tag owns the whole file — which is every config `config/lean-map.php`
 * names, and every one attributed by file name. A file that maps several
 * elements in a row (7.8's `include/params/vc_grid_item/shortcodes.php`, the
 * only one either era has) is cut at each `'base' =>`, so `vc_gitem_image`'s
 * fields do not become `vc_gitem_row`'s as well. Anything declared above the
 * first base is shared scaffolding held in a variable, and reaches the tags
 * that name that variable through `names_in()`.
 *
 * @param string[] $tags
 * @return array<int, array{0: string, 1: string}> [tag, the source it owns]
 */
function segments_for( string $source, array $tags ): array {
    if ( count( $tags ) < 2 ) {
        return [ [ (string) reset( $tags ), $source ] ];
    }

    if ( preg_match_all( '/[\'"]base[\'"]\s*=>\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $matches, PREG_OFFSET_CAPTURE ) === 0 ) {
        return [];
    }

    $segments = [];
    foreach ( $matches[0] as $index => $match ) {
        $start  = (int) $match[1];
        $end    = isset( $matches[0][ $index + 1 ] ) ? (int) $matches[0][ $index + 1 ][1] : strlen( $source );
        $segments[] = [ (string) $matches[1][ $index ][0], substr( $source, $start, $end - $start ) ];
    }

    return $segments;
}

/**
 * Every parameter name a stretch of config declares: its own, the shared groups
 * it calls a helper for, and the ones held in a variable it names
 * (`'params' => $zone_params`, `array_merge( $post_data_params, … )`), which is
 * how 7.8 writes the grid-item elements.
 *
 * @param array<string, string[]> $functions
 * @return string[]
 */
function names_in( string $segment, string $file, array $functions ): array {
    $names = param_names( $segment );

    foreach ( helper_calls( $segment, $functions ) as $helper ) {
        $names = array_merge( $names, $functions[ $helper ] );
    }

    foreach ( params_values( $segment ) as $value ) {
        $names = array_merge( $names, variable_params( $value, $file ) );
    }

    return array_values( array_unique( $names ) );
}

/**
 * The parameter names held in the variables a value names, and in the
 * variables those hold in turn — 7.8's `$zone_params` is a list of arrays that
 * includes `$vc_gitem_add_link_param`, so one level would lose `link`.
 *
 * @return string[]
 */
function variable_params( string $value, string $file, int $depth = 0 ): array {
    if ( $depth > 2 || preg_match_all( '/\$([a-z_][a-z0-9_]*)/i', $value, $matches ) === 0 ) {
        return [];
    }

    $names = [];
    foreach ( array_unique( $matches[1] ) as $variable ) {
        $assigned = assignment_value( $file, $variable );

        if ( $assigned === '' ) {
            continue;
        }

        $names = array_merge( $names, param_names( $assigned ), variable_params( $assigned, $file, $depth + 1 ) );
    }

    return $names;
}

/**
 * The value of every `'params' =>` in a stretch of config.
 *
 * Variables are read out of these and nowhere else: a `$variable` mentioned
 * somewhere else in a map is a template path or a `js_view`, and pulling its
 * parameters in would put fields on an element that does not have them —
 * which is the one error this table must not make, because a name it wrongly
 * calls WPBakery's is a theme's field reported as a converter gap.
 *
 * @return string[]
 */
function params_values( string $segment ): array {
    if ( preg_match_all( '/[\'"]params[\'"]\s*=>\s*/', $segment, $matches, PREG_OFFSET_CAPTURE ) === 0 ) {
        return [];
    }

    $values = [];
    foreach ( $matches[0] as $match ) {
        $from = (int) $match[1] + strlen( $match[0] );

        // `'params' => $zone_params,` — the value is the variable itself.
        if ( preg_match( '/^\$[a-z_][a-z0-9_]*/i', substr( $segment, $from, 64 ), $variable ) === 1 ) {
            $values[] = $variable[0];

            continue;
        }

        // `'params' => array( … )`, `'params' => array_merge( $a, $b, array( … ) )`.
        $open = strcspn( $segment, '([', $from ) + $from;
        if ( $open < strlen( $segment ) ) {
            $values[] = balanced( $segment, $open );
        }
    }

    return $values;
}

/**
 * The `array( … )` / `[ … ]` a variable is assigned in this file, matched by
 * counting brackets so a nested array does not end it early. '' when the file
 * assigns it nothing.
 */
function assignment_value( string $source, string $variable ): string {
    if ( preg_match( '/\$' . preg_quote( $variable, '/' ) . '\s*=\s*/', $source, $match, PREG_OFFSET_CAPTURE ) !== 1 ) {
        return '';
    }

    $from = (int) $match[0][1] + strlen( $match[0][0] );
    $open = strcspn( $source, '([', $from ) + $from;

    return $open >= strlen( $source ) ? '' : balanced( $source, $open );
}

/** The bracketed run starting at `$open`, brackets counted rather than lazily matched. */
function balanced( string $source, int $open ): string {
    $pairs  = [ '(' => ')', '[' => ']' ];
    $close  = $pairs[ $source[ $open ] ] ?? ')';
    $depth  = 0;
    $length = strlen( $source );

    for ( $i = $open; $i < $length; $i++ ) {
        if ( $source[ $i ] === $source[ $open ] ) {
            $depth++;
        } elseif ( $source[ $i ] === $close ) {
            $depth--;
            if ( $depth === 0 ) {
                return substr( $source, $open, $i - $open + 1 );
            }
        }
    }

    return substr( $source, $open );
}

/**
 * The `element` + `param_name` pairs the 9.0 attribute migration re-registers
 * with `vc_add_param()`.
 *
 * They are fields WPBakery declared, deprecated, and still accepts — a page
 * saved before 9.0 carries them — so a converter that meets one has met a
 * WPBakery field, not a theme's.
 *
 * @param array<string,string> $files
 * @return array<int, array{0: string, 1: string}>
 */
function legacy_params( array $files ): array {
    $source = $files[ LEGACY_PARAMS ] ?? '';

    if ( $source === '' ) {
        return [];
    }

    if ( preg_match_all(
        '/[\'"]element[\'"]\s*=>\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*[\'"]param_name[\'"]\s*=>\s*[\'"]([a-z0-9_]+)[\'"]/s',
        $source,
        $matches,
        PREG_SET_ORDER
    ) === 0 ) {
        return [];
    }

    $pairs = [];
    foreach ( $matches as $match ) {
        $pairs[] = [ $match[1], $match[2] ];
    }

    return $pairs;
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
    foreach ( CONFIG_PATHS as $candidate ) {
        $matched = str_ends_with( $candidate, '/' ) ? str_starts_with( $path, $candidate ) : $path === $candidate;

        if ( $matched ) {
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
 * A call written as `$list['vc_icon']['params'] = vc_map_integrate_shortcode( … )`
 * says which element it is for, and that element is not always the one the
 * surrounding map declares — 7.8 sets `vc_icon`'s params in the middle of the
 * grid-item file. When it says so, it is believed; otherwise the params belong
 * to the element whose map the call sits in.
 *
 * @return array<int, array{0: string, 1: string, 2: string}> [the first argument as written, the prefix, the named element or '']
 */
function integrations( string $source ): array {
    if ( preg_match_all(
        '/(?:\$list\[\s*[\'"]([a-z0-9_]+)[\'"]\s*\]\s*\[\s*[\'"]params[\'"]\s*\]\s*=\s*)?vc_map_integrate_shortcode\(\s*(?:\(array\)\s*)?([^,]+?)\s*,\s*[\'"]([^\'"]*)[\'"]/s',
        $source,
        $matches,
        PREG_SET_ORDER
    ) === 0 ) {
        return [];
    }

    $found = [];
    foreach ( $matches as $match ) {
        $found[] = [ trim( $match[2] ), $match[3], $match[1] ];
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

    // A plain call or a static one: `vc_btn_element_params()`,
    // `VcGridsCommon::getBasicAtts()`.
    if ( preg_match( '/^(?:[a-z_][a-z0-9_]*::)?([a-z_][a-z0-9_]*)\s*\(/i', $reference, $match ) === 1 ) {
        return $functions[ $match[1] ] ?? [];
    }

    if ( preg_match( '/^\$([a-z0-9_]+)$/i', $reference, $match ) === 1 ) {
        if ( preg_match( '/\$' . preg_quote( $match[1], '/' ) . '\s*=\s*([^;]+);/s', $source, $assignment ) === 1 ) {
            return resolve_reference( trim( $assignment[1] ), $source, $functions, $direct, $depth + 1 );
        }
    }

    return [];
}
