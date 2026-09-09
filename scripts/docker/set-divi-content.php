<?php
/**
 * Writes a hand-written Divi 5 block document onto a page, with the same meta
 * and cache clearing DiviExporter::save() performs, so the page renders as a
 * native Divi 5 page.
 *
 * Usage: HTML_PATH=/tmp/divi-candidate-a.html PAGE_ID=<id> wp eval-file /tmp/set-divi-content.php --allow-root
 *   HTML_PATH holds `<!-- wp:divi/placeholder -->…<!-- /wp:divi/placeholder -->` markup.
 */
$page_id   = (int) getenv( 'PAGE_ID' );
$html_path = getenv( 'HTML_PATH' );

if ( ! $page_id || ! $html_path ) {
    fwrite( STDERR, "PAGE_ID and HTML_PATH must be set\n" );
    exit( 1 );
}

if ( ! file_exists( $html_path ) ) {
    fwrite( STDERR, "Document not found: {$html_path}\n" );
    exit( 1 );
}

$content = trim( (string) file_get_contents( $html_path ) );
if ( '' === $content ) {
    fwrite( STDERR, "Document is empty: {$html_path}\n" );
    exit( 1 );
}

$post = get_post( $page_id );
if ( ! $post ) {
    fwrite( STDERR, "No post {$page_id}\n" );
    exit( 1 );
}

$version = defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : '';
if ( '' === $version ) {
    fwrite( STDERR, "ET_BUILDER_VERSION is not defined — is Divi active?\n" );
    exit( 1 );
}

// wp_update_post() unslashes its input; without wp_slash() the JSON escapes
// inside block attributes lose their backslashes and the page renders
// "u003Cp" where a paragraph should be.
$updated = wp_update_post( [
    'ID'           => $page_id,
    'post_content' => wp_slash( $content ),
], true );
if ( is_wp_error( $updated ) ) {
    fwrite( STDERR, "wp_update_post failed for page {$page_id}: " . $updated->get_error_message() . "\n" );
    exit( 1 );
}
if ( ! $updated ) {
    fwrite( STDERR, "wp_update_post failed for page {$page_id}\n" );
    exit( 1 );
}

update_post_meta( $page_id, '_et_pb_use_builder', 'on' );
// Must be the string 'on' — Divi checks === 'on' to recognise a Divi 5 post.
update_post_meta( $page_id, '_et_pb_use_divi_5', 'on' );
update_post_meta( $page_id, '_et_builder_version', 'VB|Divi|' . $version );

// Divi caches static CSS and the dynamic-assets module list per post; without
// this the previous document's stylesheet is served.
if ( class_exists( 'ET_Core_PageResource' ) ) {
    \ET_Core_PageResource::remove_static_resources( $page_id, 'all' );
}
delete_post_meta( $page_id, '_divi_dynamic_assets_cached_modules' );
delete_post_meta( $page_id, '_divi_dynamic_assets_cached_feature_used' );

echo "Set Divi 5 content on page {$page_id} (" . strlen( $content ) . " bytes, builder {$version})\n";
