<?php
/**
 * Puts real WPBakery content on the Docker site, two ways.
 *
 * 1. `TEMPLATE=about-section` — creates a **WPBakery source page** from one of
 *    WPBakery's own bundled templates, captured under
 *    fixtures/wpbakery-templates/ by scripts/wpb-templates-to-fixtures.php.
 *    Nothing is converted; the page is what the Tools screen then converts.
 *
 * 2. `WXR=/var/www/html/wpbakery-templates/ronneby/15_tenth.xml` — reads a real
 *    theme export through the plugin's own import path
 *    (`WPBakeryImportParser` → `ConversionPreflight` → `ConversionCommitter`)
 *    and creates the **converted Divi page**. There is no WPBakery source page
 *    in this mode: an export is a file, not a post, which is exactly the
 *    difference `mode: import` describes.
 *
 * Both print the new post id and nothing else, so a shell can capture it.
 * `POST_NAME=` overrides the slug in either mode, which is what makes the seed
 * idempotent (the caller looks the slug up first).
 *
 * Usage:
 *   TEMPLATE=about-section wp eval-file /tmp/import-wpb-template.php --allow-root
 *   WXR=/var/www/html/wpbakery-templates/ronneby/15_tenth.xml wp eval-file /tmp/import-wpb-template.php --allow-root
 */

$wxr       = (string) getenv( 'WXR' );
$post_name = (string) getenv( 'POST_NAME' );

if ( '' !== $wxr ) {
    echo wbdc_docker_import_wxr( $wxr, $post_name );
    exit( 0 );
}

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

$title    = ucwords( str_replace( '-', ' ', $template ) ) . ' (WPBakery)';
$postarr  = [
    'post_type'    => 'page',
    'post_status'  => 'publish',
    'post_title'   => $title,
    'post_content' => wp_slash( $content ),
];
if ( '' !== $post_name ) {
    $postarr['post_name'] = $post_name;
}

$page_id = wp_insert_post( $postarr );

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

/**
 * Converts the first WPBakery page in a WordPress export and returns the new
 * post id. Dies with a message on stderr if anything goes wrong — a seed that
 * quietly produced no page would be worse than a failure.
 */
function wbdc_docker_import_wxr( string $file, string $post_name ): int {
    foreach ( [
        '\WPBakeryDivi5Converter\Parsers\WPBakeryImportParser',
        '\WPBakeryDivi5Converter\Conversion\ConversionPreflight',
        '\WPBakeryDivi5Converter\Conversion\ConversionCommitter',
    ] as $class ) {
        if ( ! class_exists( $class ) ) {
            fwrite( STDERR, "{$class} does not exist — the converter plugin is not active.\n" );
            exit( 1 );
        }
    }

    if ( ! is_readable( $file ) ) {
        fwrite( STDERR, "Export not found: {$file}\n" );
        exit( 1 );
    }

    try {
        $items = ( new \WPBakeryDivi5Converter\Parsers\WPBakeryImportParser() )->parse( $file );
    } catch ( \Throwable $e ) {
        fwrite( STDERR, 'Could not read the export: ' . $e->getMessage() . "\n" );
        exit( 1 );
    }

    if ( empty( $items ) ) {
        fwrite( STDERR, "No WPBakery pages in {$file}\n" );
        exit( 1 );
    }

    if ( '' !== $post_name ) {
        $items[0]['post_name'] = $post_name;
    }

    // The same anonymous source the upload screen builds, so the seed goes
    // through the shipped pipeline rather than round it.
    $source = new class( $items ) implements \WPBakeryDivi5Converter\Conversion\ConversionSource {
        /** @param array[] $items */
        public function __construct( private array $items ) {}

        public function items(): array {
            return $this->items;
        }
    };

    $plan    = ( new \WPBakeryDivi5Converter\Conversion\ConversionPreflight() )->run( $source );
    $results = ( new \WPBakeryDivi5Converter\Conversion\ConversionCommitter() )->commit( $plan, [ 'post_status' => 'publish' ] );

    if ( empty( $results[0]['success'] ) ) {
        fwrite( STDERR, 'Import failed: ' . ( $results[0]['error'] ?? 'unknown' ) . "\n" );
        exit( 1 );
    }

    return (int) $results[0]['post_id'];
}
