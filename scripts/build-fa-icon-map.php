#!/usr/bin/env php
<?php
/**
 * Builds plugin/jhmg-converter-for-wpbakery-to-divi-5/data/fa-icons.json from
 * Divi 5's own icon list, so WPBakery's Font Awesome classes (`fas fa-check`,
 * `fa-solid fa-check`) can become the Divi icon with the same glyph instead
 * of a generic placeholder.
 *
 * Ported from the Beaver converter's scripts/build-fa-icon-map.php
 * (jhmg-beaver-to-divi5/scripts/build-fa-icon-map.php), which takes an
 * already-extracted Divi directory. This version defaults to reading
 * references/Divi.zip directly (the form every other task in this project
 * reads Divi source from) via ZipArchive, and only falls back to a plain
 * directory when one is given explicitly.
 *
 * Usage:
 *   php scripts/build-fa-icon-map.php                  # references/Divi.zip
 *   php scripts/build-fa-icon-map.php /path/to/Divi.zip
 *   php scripts/build-fa-icon-map.php /path/to/extracted/Divi-or-its-parent
 *
 * Output shape: { "<fa name>": { "u": "&#xf00c;", "w": [900, 400] } }
 *   u — the unicode entity Divi stores in an icon attribute
 *   w — font weights Divi lists for that glyph (900 = solid, 400 = regular/brands),
 *       sorted ascending so the file stays deterministic on regeneration.
 */

const ICON_LIST_PATH = 'includes/builder-5/visual-builder/packages/icon-library/src/components/icon-font/iconList.json';

/** @return string|null Raw JSON, or null when it could not be found. */
function read_icon_list( string $source ): ?string {
    if ( is_dir( $source ) ) {
        foreach ( [ rtrim( $source, '/' ) . '/Divi/' . ICON_LIST_PATH, rtrim( $source, '/' ) . '/' . ICON_LIST_PATH ] as $candidate ) {
            if ( is_file( $candidate ) ) {
                return (string) file_get_contents( $candidate );
            }
        }
        return null;
    }

    if ( is_file( $source ) && preg_match( '/\.zip$/i', $source ) ) {
        $zip = new ZipArchive();
        if ( $zip->open( $source ) !== true ) {
            return null;
        }
        foreach ( [ 'Divi/' . ICON_LIST_PATH, ICON_LIST_PATH ] as $entry ) {
            $contents = $zip->getFromName( $entry );
            if ( $contents !== false ) {
                $zip->close();
                return $contents;
            }
        }
        $zip->close();
    }

    return null;
}

$source = $argv[1] ?? dirname( __DIR__ ) . '/references/Divi.zip';
$json   = read_icon_list( $source );

if ( $json === null ) {
    fwrite( STDERR, "Usage: php scripts/build-fa-icon-map.php [/path/to/Divi.zip or an extracted Divi directory] (iconList.json not found under $source)\n" );
    exit( 2 );
}

$icons = json_decode( $json, true );
if ( ! is_array( $icons ) ) {
    fwrite( STDERR, "iconList.json at $source did not decode to a JSON array\n" );
    exit( 2 );
}

$map = [];

foreach ( $icons as $icon ) {
    if ( ! in_array( 'fa', $icon['styles'] ?? [], true ) ) {
        continue;
    }
    $name                = strtolower( (string) $icon['name'] );
    $map[ $name ]['u']   = (string) $icon['unicode'];
    $map[ $name ]['w'][] = (int) $icon['fontWeight'];
}

ksort( $map );
foreach ( $map as &$entry ) {
    $entry['w'] = array_values( array_unique( $entry['w'] ) );
    sort( $entry['w'] );
}
unset( $entry );

$out = dirname( __DIR__ ) . '/plugin/jhmg-converter-for-wpbakery-to-divi-5/data/fa-icons.json';
if ( ! is_dir( dirname( $out ) ) ) {
    mkdir( dirname( $out ), 0755, true );
}
file_put_contents( $out, json_encode( $map, JSON_UNESCAPED_SLASHES ) . "\n" );
echo 'wrote ' . $out . ' (' . count( $map ) . " icons)\n";
