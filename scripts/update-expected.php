#!/usr/bin/env php
<?php
/**
 * Regenerates fixtures/divi/<name>.json from fixtures/wpbakery/<name>.txt
 * (+ optional <name>.json sidecar).
 *
 * Only run this after reviewing the converter's output by hand
 * (scripts/render-fixture.php): the expected files are the specification the
 * fixture test enforces, not a snapshot of whatever the code happens to do.
 *
 * Usage: php scripts/update-expected.php heading button …   (or --all)
 */
require __DIR__ . '/../tests/bootstrap.php';

$root  = dirname( __DIR__ );
$names = array_slice( $argv, 1 );

if ( in_array( '--all', $names, true ) ) {
    $names = array_map( static fn( string $f ) => basename( $f, '.txt' ), glob( $root . '/fixtures/wpbakery/*.txt' ) ?: [] );
}
if ( empty( $names ) ) {
    fwrite( STDERR, "Usage: php scripts/update-expected.php <fixture name>… | --all\n" );
    exit( 2 );
}

$engine_class = '\\WPBakeryDivi5Converter\\Converter\\ConverterEngine';
if ( ! class_exists( $engine_class ) ) {
    fwrite( STDERR, "ConverterEngine is not implemented yet (see Task 7): {$engine_class} not found.\n" );
    exit( 2 );
}

foreach ( $names as $name ) {
    $in = $root . "/fixtures/wpbakery/{$name}.txt";
    if ( ! is_file( $in ) ) {
        fwrite( STDERR, "missing: {$in}\n" );
        continue;
    }

    $content      = (string) file_get_contents( $in );
    $sidecar      = [];
    $sidecar_file = $root . "/fixtures/wpbakery/{$name}.json";
    if ( is_file( $sidecar_file ) ) {
        $sidecar = json_decode( (string) file_get_contents( $sidecar_file ), true ) ?: [];
    }

    $meta        = $sidecar['meta'] ?? [];
    $mode        = $sidecar['mode'] ?? 'direct';
    $attachments = $sidecar['attachments'] ?? [];

    if ( isset( $sidecar['rendered'] ) && is_array( $sidecar['rendered'] ) ) {
        $GLOBALS['__test_rendered_shortcodes'] = $sidecar['rendered'];
    }

    $result = $engine_class::convert(
        [ 'content' => $content, 'meta' => $meta ],
        [ 'mode' => $mode, 'attachments' => $attachments ]
    );
    $out = [ 'divi' => $result['divi'], 'unsupported' => $result['unsupported'] ];
    file_put_contents( $root . "/fixtures/divi/{$name}.json", json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
    echo "wrote fixtures/divi/{$name}.json\n";
}
