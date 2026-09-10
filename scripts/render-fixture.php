#!/usr/bin/env php
<?php
/**
 * Runs the converter over a WPBakery fixture and prints the structural
 * output (divi tree + unsupported) as JSON — the shape fixtures/divi/*.json holds.
 *
 * Usage: php scripts/render-fixture.php fixtures/wpbakery/heading.txt [--report]
 *
 * The fixture is a raw shortcode string. An optional sidecar next to it,
 * fixtures/wpbakery/heading.json, may carry:
 *   { "meta": {...}, "mode": "direct|import", "attachments": {"123": "https://..."},
 *     "rendered": {"tag": "<html>"}, "rendered_widgets": {"WP_Widget_Archives": "<html>"} }
 * "meta" becomes the post meta the parser reads (e.g. _wpb_shortcodes_custom_css);
 * "mode" and "attachments" are passed through as conversion options; "rendered"
 * seeds $GLOBALS['__test_rendered_shortcodes'] so do_shortcode() in the test
 * bootstrap can answer for third-party shortcodes embedded in the fixture.
 */
require __DIR__ . '/../tests/bootstrap.php';

$file = $argv[1] ?? '';
if ( ! is_file( $file ) ) {
    fwrite( STDERR, "Usage: php scripts/render-fixture.php <fixture.txt> [--report]\n" );
    exit( 2 );
}

$engine_class = '\\WPBakeryDivi5Converter\\Converter\\ConverterEngine';
if ( ! class_exists( $engine_class ) ) {
    fwrite( STDERR, "ConverterEngine is not implemented yet (see Task 7): {$engine_class} not found.\n" );
    exit( 2 );
}

$content = (string) file_get_contents( $file );
$sidecar = [];
$sidecar_file = preg_replace( '/\.txt$/', '.json', $file );
if ( is_file( $sidecar_file ) ) {
    $sidecar = json_decode( (string) file_get_contents( $sidecar_file ), true ) ?: [];
}

$meta        = $sidecar['meta'] ?? [];
$mode        = $sidecar['mode'] ?? 'direct';
$attachments = $sidecar['attachments'] ?? [];

if ( isset( $sidecar['rendered'] ) && is_array( $sidecar['rendered'] ) ) {
    $GLOBALS['__test_rendered_shortcodes'] = $sidecar['rendered'];
}
if ( isset( $sidecar['rendered_widgets'] ) && is_array( $sidecar['rendered_widgets'] ) ) {
    $GLOBALS['__test_rendered_widgets'] = $sidecar['rendered_widgets'];
}

$result = ( new $engine_class() )->convert(
    [ 'content' => $content, 'meta' => $meta ],
    [ 'mode' => $mode, 'attachments' => $attachments ]
);

$out = [ 'divi' => $result['divi'], 'unsupported' => $result['unsupported'] ];
if ( in_array( '--report', $argv, true ) ) {
    $out['report'] = $result['report'];
}
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
