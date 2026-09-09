<?php
/**
 * Converts a WPBakery page in place (the page itself becomes the Divi page).
 * Usage: PAGE_ID=<id> wp eval-file /tmp/convert-run.php --allow-root
 */
$page_id = (int) getenv( 'PAGE_ID' );
if ( ! $page_id ) {
    fwrite( STDERR, "PAGE_ID must be set\n" );
    exit( 1 );
}

foreach ( [
    '\WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser',
    '\WPBakeryDivi5Converter\Converter\ConverterEngine',
    '\WPBakeryDivi5Converter\Exporters\DiviExporter',
] as $class ) {
    if ( ! class_exists( $class ) ) {
        fwrite( STDERR, "{$class} does not exist yet — the converter is not built.\n" );
        exit( 1 );
    }
}

$post = get_post( $page_id );
if ( ! $post ) {
    fwrite( STDERR, "No post {$page_id}\n" );
    exit( 1 );
}

$meta     = array_map( static fn( $values ) => $values[0] ?? '', get_post_meta( $page_id ) );
$document = ( new \WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser() )->parse( $post->post_content, $meta );
if ( empty( $document['nodes'] ) ) {
    fwrite( STDERR, "No WPBakery content on post {$page_id}\n" );
    exit( 1 );
}

$converted = ( new \WPBakeryDivi5Converter\Converter\ConverterEngine() )->convert( $document );
( new \WPBakeryDivi5Converter\Exporters\DiviExporter() )->save( $page_id, $converted );
delete_post_meta( $page_id, '_wpb_vc_js_status' );

echo "Converted post {$page_id} in place\n";
