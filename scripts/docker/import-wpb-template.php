<?php
/**
 * Creates a page from one of WPBakery's own bundled templates, captured under
 * fixtures/wpbakery-templates/ by scripts/wpb-templates-to-fixtures.php, so
 * the e2e suite can exercise real WPBakery content.
 *
 * Usage: TEMPLATE=about-section wp eval-file /tmp/import-wpb-template.php --allow-root
 * Prints the new page id.
 */
$template = getenv( 'TEMPLATE' ) ?: 'about-section';
$path     = ABSPATH . 'fixtures/wpbakery-templates/' . $template . '.txt';
if ( ! file_exists( $path ) ) {
    fwrite( STDERR, "Template not found: {$path}\n" );
    exit( 1 );
}

$content = (string) file_get_contents( $path );
if ( '' === trim( $content ) ) {
    fwrite( STDERR, "Template is empty: {$path}\n" );
    exit( 1 );
}

$title   = ucwords( str_replace( '-', ' ', $template ) ) . ' (WPBakery)';
$page_id = wp_insert_post( [
    'post_type'    => 'page',
    'post_status'  => 'publish',
    'post_title'   => $title,
    'post_content' => wp_slash( $content ),
] );

if ( is_wp_error( $page_id ) || ! $page_id ) {
    fwrite( STDERR, "Could not create the page\n" );
    exit( 1 );
}

update_post_meta( $page_id, '_wpb_vc_js_status', 'true' );

$sidecar = substr( $path, 0, -4 ) . '.json';
if ( $sidecar && file_exists( $sidecar ) ) {
    $decoded = json_decode( (string) file_get_contents( $sidecar ), true );
    foreach ( ( is_array( $decoded ) ? $decoded['meta'] ?? [] : [] ) as $key => $value ) {
        update_post_meta( $page_id, (string) $key, wp_slash( $value ) );
    }
}

echo $page_id;
