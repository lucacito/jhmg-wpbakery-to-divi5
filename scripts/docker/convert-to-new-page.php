<?php
/**
 * Creates a new Divi 5 page from a WPBakery page, through the same pipeline the
 * admin screen uses. Prints the new page id.
 * Usage: SOURCE_PAGE_ID=<id> wp eval-file /tmp/convert-to-new-page.php --allow-root
 */
$source_id = (int) getenv( 'SOURCE_PAGE_ID' );
if ( ! $source_id ) {
    fwrite( STDERR, "SOURCE_PAGE_ID must be set\n" );
    exit( 1 );
}

foreach ( [
    '\WPBakeryDivi5Converter\Conversion\ConversionPreflight',
    '\WPBakeryDivi5Converter\Conversion\InstalledPostSource',
    '\WPBakeryDivi5Converter\Conversion\ConversionCommitter',
] as $class ) {
    if ( ! class_exists( $class ) ) {
        fwrite( STDERR, "{$class} does not exist yet — the converter is not built.\n" );
        exit( 1 );
    }
}

$plan    = ( new \WPBakeryDivi5Converter\Conversion\ConversionPreflight() )->run( new \WPBakeryDivi5Converter\Conversion\InstalledPostSource( [ $source_id ] ) );
// create_new: conversions are in place by default now, and this helper's whole
// job is to hand back a second post to look at.
$results = ( new \WPBakeryDivi5Converter\Conversion\ConversionCommitter() )->commit( $plan, [ 'post_status' => 'publish', 'create_new' => true ] );

if ( empty( $results[0]['success'] ) ) {
    fwrite( STDERR, 'Conversion failed: ' . ( $results[0]['error'] ?? 'unknown' ) . "\n" );
    exit( 1 );
}

echo $results[0]['post_id'];
