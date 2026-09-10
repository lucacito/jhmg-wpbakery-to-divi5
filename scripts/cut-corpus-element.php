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
 * **The attachment map.** Task 11's WXR reader does not exist yet, and a
 * fixture cut from an export has no media library to ask, so the map the
 * conversion needs is built here: every attachment id the cut element
 * references is looked up in the export's own `<item>` list
 * (`wp:post_type=attachment`) and written as `id => { url }`. Which attributes
 * hold an id is not guessed — `ATTACHMENT_ATTRIBUTES` is every `attach_image` /
 * `attach_images` param name in Ronneby Core 1.5.74
 * (`inc/vc_custom/dfd_vc_addons/modules/*.php`), because an attribute like
 * `image_width` is also numeric and is not an id. An id the export does not
 * carry is left out of the map and the handler reports it as unresolved media,
 * which is the true answer.
 *
 * Exports are 10-40 MB, so the content is read with a streaming regex rather
 * than a DOM parse; `<content:encoded>` is CDATA, so the shortcode inside it is
 * already literal text.
 */

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

$xml = (string) file_get_contents( $path );

$matches = find_shortcodes( $xml, $tag );

if ( $matches === [] ) {
    fwrite( STDERR, sprintf( "[%s] does not appear in %s\n", $tag, basename( $path ) ) );
    exit( 1 );
}

if ( $options['list'] ) {
    foreach ( $matches as $index => $match ) {
        printf( "%3d  offset %-10d %s\n", $index + 1, $match['offset'], substr( preg_replace( '/\s+/', ' ', $match['text'] ), 0, 150 ) );
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
    'attachments' => attachments_for( $element, $xml ),
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
 * Every `[tag …]…[/tag]` (or self-closing `[tag …]`) in the document, with the
 * offset it starts at.
 *
 * The closing tag is matched by counting opens and closes rather than with a
 * lazy `.*?`, so a `dfd_accordion` holding another `dfd_accordion` — or, more
 * usually, a `vc_tta_section` holding a second element of the same kind — is cut
 * whole instead of at the first `[/tag]`.
 *
 * @return array<int, array{offset: int, text: string}>
 */
function find_shortcodes( string $document, string $tag ): array {
    $pattern = '/\[' . preg_quote( $tag, '/' ) . '(?![\w-])([^\]]*)\]/';

    if ( preg_match_all( $pattern, $document, $opens, PREG_OFFSET_CAPTURE ) === 0 ) {
        return [];
    }

    $close     = '[/' . $tag . ']';
    $found     = [];
    $skip_past = -1;

    foreach ( $opens[0] as $index => $open ) {
        [ $open_text, $offset ] = $open;

        // A nested occurrence is part of the element already cut; listing it
        // again would give the reviewer the same span twice.
        if ( $offset < $skip_past ) {
            continue;
        }

        // A self-closing element (`[dfd_spacer …]`) has no closing tag at all.
        $next_close = strpos( $document, $close, $offset );
        if ( $next_close === false ) {
            $found[] = [ 'offset' => $offset, 'text' => $open_text ];

            continue;
        }

        // Is there another open of the same tag before that close? Then the
        // close belongs to it, and this element's own close is further on.
        $depth  = 1;
        $cursor = $offset + strlen( $open_text );
        $end    = null;

        while ( $depth > 0 ) {
            $next_open  = preg_match( $pattern, $document, $m, PREG_OFFSET_CAPTURE, $cursor ) === 1 ? $m[0][1] : false;
            $next_close = strpos( $document, $close, $cursor );

            if ( $next_close === false ) {
                break;
            }

            if ( $next_open !== false && $next_open < $next_close ) {
                $depth++;
                $cursor = $next_open + strlen( $m[0][0] );

                continue;
            }

            $depth--;
            $cursor = $next_close + strlen( $close );
            if ( $depth === 0 ) {
                $end = $cursor;
            }
        }

        if ( $end === null ) {
            // Unbalanced: keep the opening tag alone rather than the rest of
            // the document.
            $found[] = [ 'offset' => $offset, 'text' => $open_text ];

            continue;
        }

        $found[]   = [ 'offset' => $offset, 'text' => substr( $document, $offset, $end - $offset ) ];
        $skip_past = $end;
    }

    return $found;
}

/**
 * The `id => ['url' => …]` map for every attachment the cut element names.
 *
 * @return array<string, array{url: string}>
 */
function attachments_for( string $element, string $document ): array {
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

    $urls = attachment_urls( $document, array_keys( $ids ) );
    $map  = [];

    foreach ( array_keys( $ids ) as $id ) {
        if ( isset( $urls[ $id ] ) ) {
            $map[ $id ] = [ 'url' => $urls[ $id ] ];
        } else {
            fwrite( STDERR, "  attachment {$id} is not in this export; the handler will report it as unresolved media\n" );
        }
    }

    ksort( $map, SORT_NUMERIC );

    return $map;
}

/**
 * `<wp:post_id>` ⇒ `<wp:attachment_url>` for the attachment items of a WXR
 * document, restricted to the ids asked for.
 *
 * @param string[] $ids
 * @return array<string,string>
 */
function attachment_urls( string $document, array $ids ): array {
    $wanted = array_flip( $ids );
    $found  = [];
    $offset = 0;

    while ( ( $start = strpos( $document, '<item>', $offset ) ) !== false ) {
        $end = strpos( $document, '</item>', $start );
        if ( $end === false ) {
            break;
        }

        $item   = substr( $document, $start, $end - $start );
        $offset = $end + 7;

        if ( ! str_contains( $item, '<wp:post_type><![CDATA[attachment]]></wp:post_type>' ) ) {
            continue;
        }
        if ( preg_match( '#<wp:post_id>(\d+)</wp:post_id>#', $item, $m ) !== 1 ) {
            continue;
        }
        if ( ! isset( $wanted[ $m[1] ] ) ) {
            continue;
        }
        if ( preg_match( '#<wp:attachment_url>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</wp:attachment_url>#s', $item, $u ) !== 1 ) {
            continue;
        }

        $found[ $m[1] ] = trim( $u[1] );
    }

    return $found;
}
