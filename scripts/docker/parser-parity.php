<?php
/**
 * Does `ShortcodeParser::parse()` see the same shortcodes WordPress does?
 *
 * The converter cannot call `do_shortcode()` to read a page: it has to parse
 * content WordPress is not rendering — an uploaded export, a page whose plugin
 * is long gone — so `ShortcodeParser` ports WordPress's grammar rather than
 * calling it (`get_shortcode_regex()`, `shortcode_parse_atts()`, the escaping
 * and self-closing rules). A port is only worth having if it agrees with the
 * original, and the only way to know is to run both over real content.
 *
 * This script does that inside the container, where WordPress itself is
 * loaded: it walks every bundled template, every layout fixture and the three
 * committed Ronneby exports with WordPress's own regex, and compares the
 * sequence of `[tag, atts]` pairs with the parser's.
 *
 * Two things are levelled first, because they are facts about the corpus
 * rather than about either parser:
 *
 *  - **Registration.** `get_shortcode_regex()` only matches tags that are
 *    registered, and a theme's are not installed here. Every tag that appears
 *    anywhere in the corpus is registered with a no-op callback before the
 *    walk, so WordPress is asked the same question the parser answers.
 *  - **Raw-content tags.** Neither walk descends into the editor fields
 *    (`vc_column_text` and the rest of `ShortcodeParser::RAW_CONTENT_TAGS`),
 *    because their content is HTML a reader typed, not child elements.
 *
 * A bare bracket token — `[1]`, a footnote marker, a stray bracket in body
 * copy — is an expected difference and is listed rather than failed on: the
 * parser generalises the tag name to `[a-zA-Z0-9_-]+` so it can convert
 * content whose plugins are missing, and turns such a token back into the text
 * WordPress would have printed (`ThemeShortcodeConverter`).
 *
 * One other difference is deliberate and is **not** exempted, because it has
 * never appeared in the corpus and ought to be looked at if it does: an
 * attribute delimited by curly-quote entities (`title=&#8220;Hi&#8221;`, what
 * an export/import round trip through `wptexturize()` leaves behind) is folded
 * back to straight quotes by the parser and read literally by WordPress.
 *
 * Usage: wp eval-file /tmp/parser-parity.php --allow-root
 * Exit 0 and `parity ok: N files`, or exit 1 and the first divergence.
 */

use WPBakeryDivi5Converter\Parsers\ShortcodeParser;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;
use WPBakeryDivi5Converter\Parsers\WxrReader;

if ( ! class_exists( ShortcodeParser::class ) ) {
    fwrite( STDERR, "ShortcodeParser is not loaded — activate the converter plugin first.\n" );
    exit( 1 );
}

/** The exports that are in the repository; the other 93 are gitignored. */
const WBDC_PARITY_EXPORTS = [ '04_pages_demo.xml', '15_tenth.xml', '23_eighteenth.xml' ];

/**
 * Every document to compare, as label ⇒ content, and how many came from where.
 *
 * A corpus that is not mounted is **said out loud**, not passed over: a shorter
 * run that still printed `parity ok` would be the one way this script could
 * lie about its own coverage.
 *
 * @param array<string,int> $counts Filled with corpus ⇒ number of documents.
 * @return array<string,string>
 */
function wbdc_parity_documents( array &$counts ): array {
    $documents = [];

    foreach ( [ 'wpbakery-templates', 'wpbakery-layouts' ] as $dir ) {
        $root  = ABSPATH . 'fixtures/' . $dir;
        $files = glob( $root . '/*.txt' ) ?: [];

        if ( [] === $files ) {
            echo "corpus not mounted: {$root}/*.txt\n";
        }

        foreach ( $files as $file ) {
            $documents[ $dir . '/' . basename( $file ) ] = (string) file_get_contents( $file );
        }

        $counts[ $dir ] = count( $files );
    }

    $counts['ronneby exports']      = 0;
    $counts['ronneby export pages'] = 0;

    foreach ( WBDC_PARITY_EXPORTS as $name ) {
        $file = ABSPATH . 'wpbakery-templates/ronneby/' . $name;
        if ( ! is_readable( $file ) ) {
            echo "export not mounted: {$file}\n";
            continue;
        }

        ++$counts['ronneby exports'];

        // One export holds many posts; each one is a document of its own, so a
        // divergence names the page it is in rather than the whole file.
        $index = 0;
        foreach ( ( new WxrReader() )->read( (string) file_get_contents( $file ) ) as $post ) {
            $content = (string) ( $post['content'] ?? '' );
            if ( WPBakeryDocumentParser::isWPBakeryContent( $content ) ) {
                $documents[ 'ronneby/' . $name . '#' . $index++ ] = $content;
                ++$counts['ronneby export pages'];
            }
        }
    }

    return $documents;
}

/**
 * Every tag that looks like a shortcode name anywhere in the corpus.
 *
 * Deliberately its own scan rather than the parser's regex: asking the parser
 * which tags exist and then asking WordPress about exactly those would be
 * asking the same question twice.
 *
 * @param array<string,string> $documents
 * @return string[]
 */
function wbdc_parity_tags( array $documents ): array {
    $tags = [];

    foreach ( $documents as $content ) {
        if ( preg_match_all( '/\[\/?([a-zA-Z][a-zA-Z0-9_\-]*)/', $content, $m ) ) {
            foreach ( $m[1] as $tag ) {
                $tags[ $tag ] = true;
            }
        }
    }

    return array_keys( $tags );
}

/**
 * WordPress's own walk: `get_shortcode_regex()` plus `shortcode_parse_atts()`,
 * recursing into inner content, flattened in document order.
 *
 * @param array<int, array{tag: string, atts: array, offset: int, depth: int}> $out
 */
function wbdc_parity_wordpress_walk( string $content, int $base, int $depth, array &$out ): void {
    $pattern = '/' . get_shortcode_regex() . '/';
    $matches = [];

    if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) === false ) {
        fwrite( STDERR, 'WordPress regex failed: ' . preg_last_error_msg() . "\n" );
        exit( 1 );
    }

    foreach ( $matches as $m ) {
        // `[[tag]]` is an escape: WordPress prints `[tag]` as text and never
        // treats it as an element, and neither does the parser.
        if ( '[' === $m[1][0] && ']' === $m[6][0] ) {
            continue;
        }

        $tag  = (string) $m[2][0];
        $atts = shortcode_parse_atts( (string) $m[3][0] );

        $out[] = [
            'tag'    => $tag,
            // shortcode_parse_atts() answers with the leftover string, not an
            // array, when an attribute string holds nothing at all.
            'atts'   => is_array( $atts ) ? $atts : [],
            'offset' => $base + (int) $m[0][1],
            'depth'  => $depth,
        ];

        // Group 5 sits out entirely when there is no closing tag; PCRE marks
        // that with offset -1, which is the only reliable "self-closing" test.
        $self_closing = '/' === $m[4][0] || -1 === (int) $m[5][1];
        if ( $self_closing || in_array( $tag, ShortcodeParser::RAW_CONTENT_TAGS, true ) ) {
            continue;
        }

        wbdc_parity_wordpress_walk( (string) $m[5][0], $base + (int) $m[5][1], $depth + 1, $out );
    }
}

/**
 * The converter's walk, flattened the same way.
 *
 * `ShortcodeParser` records an offset into the string handed to that call, so
 * a child's absolute position is its parent's content start plus that offset.
 * The content ends where the parent's closing tag begins, and the parser takes
 * the content up to the **first** `[/tag]`, so the closing tag can be located
 * exactly — looking the content string up directly would find an attribute
 * value that happens to repeat it (`[vc_btn title="x"]x[/vc_btn]`).
 *
 * @param array<int, array{tag: string, atts: array, offset: int, depth: int}> $out
 */
function wbdc_parity_parser_walk( string $document, array $nodes, int $base, int $depth, array &$out ): void {
    foreach ( $nodes as $node ) {
        if ( '#text' === ( $node['tag'] ?? '' ) ) {
            continue;
        }

        $absolute = $base + (int) ( $node['offset'] ?? 0 );

        $out[] = [
            'tag'    => (string) $node['tag'],
            'atts'   => is_array( $node['atts'] ?? null ) ? $node['atts'] : [],
            'offset' => $absolute,
            'depth'  => $depth,
        ];

        $children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
        if ( [] === $children ) {
            continue;
        }

        $content  = (string) ( $node['content'] ?? '' );
        $close_at = strpos( $document, '[/' . $node['tag'] . ']', $absolute );

        wbdc_parity_parser_walk(
            $document,
            $children,
            false === $close_at ? $absolute : $close_at - strlen( $content ),
            $depth + 1,
            $out
        );
    }
}

/** A short, single-line window on the document around an offset. */
function wbdc_parity_excerpt( string $document, int $offset ): string {
    $start   = max( 0, $offset - 40 );
    $excerpt = substr( $document, $start, 120 );

    return str_replace( [ "\n", "\r" ], ' ', $excerpt );
}

// ---------------------------------------------------------------------------

$counts    = [];
$documents = wbdc_parity_documents( $counts );
if ( [] === $documents ) {
    fwrite( STDERR, "No corpus found: fixtures/ is not mounted.\n" );
    exit( 1 );
}

foreach ( wbdc_parity_tags( $documents ) as $tag ) {
    add_shortcode( $tag, '__return_empty_string' );
}
$registered = $GLOBALS['shortcode_tags'];

$bracket_tokens = [];
$files          = 0;
$pairs          = 0;

foreach ( $documents as $label => $document ) {
    $wordpress = [];
    wbdc_parity_wordpress_walk( $document, 0, 0, $wordpress );

    $parsed = [];
    wbdc_parity_parser_walk( $document, ShortcodeParser::parse( $document ), 0, 0, $parsed );

    $i = 0;
    foreach ( $parsed as $mine ) {
        // A token no plugin could have registered: WordPress prints it as
        // text, so it is listed here and never compared.
        if ( ! isset( $registered[ $mine['tag'] ] ) ) {
            $bracket_tokens[] = sprintf( '%s @%d: [%s]', $label, $mine['offset'], $mine['tag'] );
            continue;
        }

        $theirs = $wordpress[ $i ] ?? null;
        ++$i;

        if ( null === $theirs ) {
            fwrite( STDERR, sprintf(
                "parity FAILED in %s\n  the parser found [%s] at offset %d, WordPress found nothing there\n  %s\n",
                $label,
                $mine['tag'],
                $mine['offset'],
                wbdc_parity_excerpt( $document, $mine['offset'] )
            ) );
            exit( 1 );
        }

        if ( $theirs['tag'] !== $mine['tag'] || $theirs['atts'] !== $mine['atts'] ) {
            fwrite( STDERR, sprintf(
                "parity FAILED in %s\n  WordPress: [%s] at offset %d %s\n  parser:    [%s] at offset %d %s\n  %s\n",
                $label,
                $theirs['tag'],
                $theirs['offset'],
                wp_json_encode( $theirs['atts'] ),
                $mine['tag'],
                $mine['offset'],
                wp_json_encode( $mine['atts'] ),
                wbdc_parity_excerpt( $document, $theirs['offset'] )
            ) );
            exit( 1 );
        }

        ++$pairs;
    }

    if ( $i < count( $wordpress ) ) {
        $missed = $wordpress[ $i ];
        fwrite( STDERR, sprintf(
            "parity FAILED in %s\n  WordPress found [%s] at offset %d and the parser found nothing there\n  %s\n",
            $label,
            $missed['tag'],
            $missed['offset'],
            wbdc_parity_excerpt( $document, $missed['offset'] )
        ) );
        exit( 1 );
    }

    ++$files;
}

if ( [] !== $bracket_tokens ) {
    echo 'bracket tokens (expected, kept as text): ', count( $bracket_tokens ), "\n";
    foreach ( array_slice( $bracket_tokens, 0, 20 ) as $token ) {
        echo '  ', $token, "\n";
    }
    if ( count( $bracket_tokens ) > 20 ) {
        echo '  … and ', count( $bracket_tokens ) - 20, " more\n";
    }
}

$breakdown = [];
foreach ( $counts as $corpus => $count ) {
    $breakdown[] = sprintf( '%d %s', $count, $corpus );
}

echo sprintf(
    "parity ok: %d documents (%s), %d shortcodes\n",
    $files,
    implode( ', ', $breakdown ),
    $pairs
);
