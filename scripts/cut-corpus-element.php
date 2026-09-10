#!/usr/bin/env php
<?php
/**
 * Cuts one element out of a real WPBakery export and writes it as a fixture.
 *
 * The Ronneby corpus (`wpbakery templates/ronneby/*.xml`) is the project's
 * real-world source: 96 WordPress exports of the theme's demo pages. A handler
 * written against the theme's PHP still has to be shown converting the
 * shortcode a real page carries, and hand-typing one is how a fixture stops
 * being evidence. So the element is taken byte for byte out of the export.
 *
 * Usage:
 *   php scripts/cut-corpus-element.php <export.xml> <tag> [occurrence] [--name=<fixture>] [--list]
 *
 *   <export.xml>   a file in `wpbakery templates/ronneby/`, or a path
 *   <tag>          the shortcode tag to cut (`dfd_heading`, `new_team_member`, …)
 *   [occurrence]   1-based; the n-th `[tag …]` in document order (default 1)
 *   --name=…       fixture basename (default `ronneby-<tag with dfd_ stripped>`)
 *   --list         print every occurrence with its offset and first line instead
 *                  of writing anything, so a reviewer can pick one
 *
 * It writes:
 *   fixtures/wpbakery/<name>.txt    `[vc_row][vc_column]…[/vc_column][/vc_row]`
 *   fixtures/wpbakery/<name>.json   { mode: "import", source: "<file>#<n>", attachments: {…} }
 *
 * **The attachment map.** A fixture cut from an export has no media library to
 * ask, so the map the conversion needs is built here, out of the export's own
 * `attachment` items — `WxrReader::attachments()`, the same map the import
 * parser hands a real conversion. Which attributes hold an id is not guessed:
 * `ATTACHMENT_ATTRIBUTES` is every `attach_image` / `attach_images` param name
 * in Ronneby Core 1.5.74 (`inc/vc_custom/dfd_vc_addons/modules/*.php`), because
 * an attribute like `image_width` is also numeric and is not an id. An id the
 * export does not carry is left out of the map and the handler reports it as
 * unresolved media, which is the true answer. The sidecar keeps `id => { url }`:
 * a fixture cut from one element needs the URL, and a size map it never asks
 * for would only be noise in the file a reviewer reads.
 *
 * The export is read with `WxrReader` — the plugin's own, and the only WXR
 * parser in the repository (task-11-amendments §6), so a fixture is cut out of
 * exactly the document a conversion would read.
 */

require __DIR__ . '/../tests/bootstrap.php';
require __DIR__ . '/lib/corpus-shortcodes.php';

use WPBakeryDivi5Converter\Parsers\WxrReader;

$root = dirname( __DIR__ );

/**
 * Every `attach_image` / `attach_images` param name Ronneby Core 1.5.74
 * declares (`inc/vc_custom/dfd_vc_addons/modules/*.php`). Anything else that
 * looks numeric — `image_width`, `img_height`, `image_mask_opacity` — is not an
 * attachment id and is deliberately absent.
 */
const ATTACHMENT_ATTRIBUTES = [
    'address_img',        // dfd-contact-block-horizontal-module.php
    'background_image',   // dfd-contact-block-module.php
    'delimiter_image',    // dfd_heading_module.php
    'email_img',          // dfd-contact-block-horizontal-module.php
    'icon_bg_img',        // dfd-info-box.php
    'icon_image_id',      // dfd-info-box.php, dfd-milestone.php, dfd-pricing-block.php, dfd_rotate_box.php
    'icon_img',           // dfd-icon-list-module.php, dfd_heading_module.php
    'image',              // dfd-delimiter.php, dfd-info-banner.php, dfd-testimonials.php, dfd_image_module.php, …
    'image_background',   // dfd_modal_box.php
    'image_first',        // dfd_slide_parallax.php
    'image_id',           // dfd_button_gradient.php, dfd_image_layers.php, dfd_price_list.php
    'image_id_reverse',   // dfd_rotate_box.php
    'image_second',       // dfd_slide_parallax.php
    'left_arrow',         // dfd_carousel.php
    'marker',             // dfd_slide_parallax.php
    'marker_image',       // dfd_google_map.php, dfd_hotspot.php
    'module_image',       // dfd_scrolling_effect.php
    'phones_img',         // dfd-contact-block-horizontal-module.php
    'retina_image_x2',    // dfd_image_module.php
    'retina_image_x4',    // dfd_image_module.php
    'right_arrow',        // dfd_carousel.php
    'team_member_photo',  // dfd-team-member.php
    'video_thumb',        // dfd-video-module.php
    // WPBakery's own, for an element cut out of a `vc_*` tag in the same export.
    'images',
    'source',
    'bg_image',
];

$argv_rest = [];
$options   = [ 'name' => '', 'list' => false ];

foreach ( array_slice( $argv, 1 ) as $argument ) {
    if ( str_starts_with( $argument, '--name=' ) ) {
        $options['name'] = substr( $argument, 7 );
    } elseif ( $argument === '--list' ) {
        $options['list'] = true;
    } else {
        $argv_rest[] = $argument;
    }
}

$file       = $argv_rest[0] ?? '';
$tag        = $argv_rest[1] ?? '';
$occurrence = (int) ( $argv_rest[2] ?? 1 );

if ( $file === '' || $tag === '' ) {
    fwrite( STDERR, "Usage: php scripts/cut-corpus-element.php <export.xml> <tag> [occurrence] [--name=<fixture>] [--list]\n" );
    exit( 2 );
}

$path = is_file( $file ) ? $file : $root . '/wpbakery templates/ronneby/' . $file;
if ( ! is_file( $path ) ) {
    fwrite( STDERR, "no such export: {$file}\n" );
    exit( 2 );
}

$items = ( new WxrReader() )->read( (string) file_get_contents( $path ) );

// Every occurrence in every item's content, in document order — a shortcode
// in a postmeta or an excerpt is not a page element and is not cut.
$matches = [];
foreach ( $items as $item ) {
    foreach ( wbdc_find_shortcodes( (string) $item['content'], $tag ) as $match ) {
        $matches[] = $match + [ 'item' => (string) $item['title'] ];
    }
}

if ( $matches === [] ) {
    fwrite( STDERR, sprintf( "[%s] does not appear in %s\n", $tag, basename( $path ) ) );
    exit( 1 );
}

if ( $options['list'] ) {
    foreach ( $matches as $index => $match ) {
        printf(
            "%3d  %-28s offset %-8d %s\n",
            $index + 1,
            substr( $match['item'], 0, 28 ),
            $match['offset'],
            substr( preg_replace( '/\s+/', ' ', $match['text'] ), 0, 120 )
        );
    }
    exit( 0 );
}

if ( $occurrence < 1 || $occurrence > count( $matches ) ) {
    fwrite( STDERR, sprintf( "occurrence %d is out of range: %s holds %d of [%s]\n", $occurrence, basename( $path ), count( $matches ), $tag ) );
    exit( 1 );
}

$element = $matches[ $occurrence - 1 ]['text'];
$name    = $options['name'] !== '' ? $options['name'] : 'ronneby-' . str_replace( '_', '-', preg_replace( '/^dfd_/', '', $tag ) );

$fixture = '[vc_row][vc_column]' . $element . '[/vc_column][/vc_row]';

$sidecar = [
    'meta'        => new stdClass(),
    'mode'        => 'import',
    'attachments' => attachments_for( $element, WxrReader::attachments( $items ) ),
    'source'      => basename( $path ) . '#' . $occurrence,
];

file_put_contents( $root . "/fixtures/wpbakery/{$name}.txt", $fixture . "\n" );
file_put_contents(
    $root . "/fixtures/wpbakery/{$name}.json",
    json_encode( $sidecar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
);

printf(
    "wrote fixtures/wpbakery/%s.txt (%d bytes from %s#%d, %d attachment%s resolved)\n",
    $name,
    strlen( $fixture ),
    basename( $path ),
    $occurrence,
    count( $sidecar['attachments'] ),
    count( $sidecar['attachments'] ) === 1 ? '' : 's'
);

/**
 * The `id => ['url' => …]` map for every attachment the cut element names.
 *
 * @param array<int, array{url: string, sizes: array<string,string>}> $library The export's whole media library.
 * @return array<string, array{url: string}>
 */
function attachments_for( string $element, array $library ): array {
    $ids = [];

    foreach ( ATTACHMENT_ATTRIBUTES as $attribute ) {
        if ( preg_match_all( '/(?:^|\s)' . preg_quote( $attribute, '/' ) . '="([^"]*)"/', $element, $m ) === 0 ) {
            continue;
        }

        foreach ( $m[1] as $value ) {
            foreach ( preg_split( '/[\s,]+/', trim( $value ) ) ?: [] as $piece ) {
                if ( ctype_digit( $piece ) && (int) $piece > 0 ) {
                    $ids[ $piece ] = true;
                }
            }
        }
    }

    if ( $ids === [] ) {
        return [];
    }

    $map = [];

    foreach ( array_keys( $ids ) as $id ) {
        if ( isset( $library[ (int) $id ]['url'] ) ) {
            $map[ $id ] = [ 'url' => $library[ (int) $id ]['url'] ];
        } else {
            fwrite( STDERR, "  attachment {$id} is not in this export; the handler will report it as unresolved media\n" );
        }
    }

    ksort( $map, SORT_NUMERIC );

    return $map;
}
