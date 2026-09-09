<?php
/**
 * Attaches a WPBakery Page Builder fixture to a page, exactly as WPBakery
 * itself stores it: the shortcode string in post_content plus the builder
 * status flag, and any sidecar meta (design-options CSS, custom CSS).
 *
 * Usage: FIXTURE=wpbakery/box-model PAGE_ID=<id> wp eval-file /tmp/set-wpbakery-content.php --allow-root
 *   FIXTURE is relative to /var/www/html/fixtures/, without .txt. An optional
 *   sidecar <name>.json next to it may carry {"meta": {key: value, …}}.
 *   TEXT_PATH=/tmp/file.txt can be used instead of FIXTURE.
 */
$page_id   = (int) getenv( 'PAGE_ID' );
$fixture   = getenv( 'FIXTURE' );
$text_path = getenv( 'TEXT_PATH' );

if ( ! $page_id || ( ! $fixture && ! $text_path ) ) {
    fwrite( STDERR, "PAGE_ID and FIXTURE (or TEXT_PATH) must be set\n" );
    exit( 1 );
}

$file = $text_path ?: ABSPATH . 'fixtures/' . $fixture . '.txt';
if ( ! file_exists( $file ) ) {
    fwrite( STDERR, "Fixture not found: {$file}\n" );
    exit( 1 );
}

$content = (string) file_get_contents( $file );

// wp_update_post() unslashes its input; without wp_slash() any backslash in
// the shortcode string (and in Divi block JSON later) is eaten.
wp_update_post( [
    'ID'           => $page_id,
    'post_content' => wp_slash( $content ),
] );

// 'true' (the string) is what WPBakery writes when the builder owns the post.
update_post_meta( $page_id, '_wpb_vc_js_status', 'true' );

$meta_count = 0;
$sidecar    = substr( $file, -4 ) === '.txt' ? substr( $file, 0, -4 ) . '.json' : '';
if ( $sidecar && file_exists( $sidecar ) ) {
    $decoded = json_decode( (string) file_get_contents( $sidecar ), true );
    if ( ! is_array( $decoded ) ) {
        fwrite( STDERR, "Sidecar is not valid JSON: {$sidecar}\n" );
        exit( 1 );
    }
    foreach ( ( $decoded['meta'] ?? [] ) as $key => $value ) {
        update_post_meta( $page_id, (string) $key, wp_slash( $value ) );
        ++$meta_count;
    }
}

echo "Set WPBakery content on page {$page_id} (" . strlen( $content ) . " bytes, {$meta_count} sidecar meta)\n";
