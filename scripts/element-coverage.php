#!/usr/bin/env php
<?php
/**
 * What this converter covers, counted over every corpus in the repository.
 *
 * The readme and the product page quote coverage numbers, and a number quoted
 * from memory is a number nobody can check. So this script counts, from the
 * source of truth in each case:
 *
 *   - **What WPBakery registers** — the 75 tags of
 *     `tests/RegisteredShortcodesTest::registeredTags()` (9.0.1's
 *     `config/lean-map.php`, the two 7.8 button forms, the four 9.0 container
 *     tags), plus the tags only a render template or a vendor bridge
 *     registers, plus WooCommerce's eighteen. That list is pinned literally in
 *     that test against the plugin source, so this script never has to guess.
 *   - **What is registered here** — `ConverterRegistry`, including which
 *     handlers are marked approximate.
 *   - **What real pages actually hold** — the three corpora:
 *     `fixtures/wpbakery-templates/` (WPBakery's own 75 templates),
 *     `fixtures/wpbakery-layouts/` (35 pages from a commercial layout pack)
 *     and `wpbakery templates/ ** / *.xml` (WordPress exports of complete theme
 *     sites; three committed, 96 with the theme archive in `references/`).
 *
 * Usage:
 *   php scripts/element-coverage.php            # the summary and the tables
 *   php scripts/element-coverage.php --json     # the same numbers as JSON
 *   php scripts/element-coverage.php --unseen   # also list registered tags no corpus holds
 *
 * A tag is counted once per occurrence, per document. The exports are scanned
 * with a plain regex rather than parsed: what is wanted is how often a tag
 * appears, and a 40 MB WXR does not need a DOM for that.
 */

require __DIR__ . '/../tests/bootstrap.php';
// The classification (exact / approximate / read by its parent, and which
// family a theme tag belongs to) is shared with `tests/ReleaseMetadataTest.php`,
// which checks the readme's numbers against it. Two copies would drift.
require __DIR__ . '/lib/element-coverage.php';

use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;
use WPBakeryDivi5Converter\Tests\RegisteredShortcodesTest;

$root      = dirname( __DIR__ );
$as_json   = in_array( '--json', $argv, true );
$show_all  = in_array( '--unseen', $argv, true );

// -----------------------------------------------------------------------------
// What WPBakery registers, and what is registered here
// -----------------------------------------------------------------------------

$registered = WBDC_Element_Coverage::registered_tags();
$extra      = WBDC_Element_Coverage::template_only_tags();

$engine   = new ConverterEngine();
$registry = $engine->registry();
$handled  = $registry->knownTags();

/** The handler a tag reaches, after the normaliser has had its go at the tag. */
$handler_for = static fn( string $tag ): array => WBDC_Element_Coverage::handler_for( $tag );

// -----------------------------------------------------------------------------
// What the corpora hold
// -----------------------------------------------------------------------------

/** @return array<string,int> tag ⇒ occurrences */
$count_tags = static function ( string $content ): array {
    $counts = [];

    if ( preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_-]*)(?![\w-])/', $content, $matches ) === 0 ) {
        return $counts;
    }

    foreach ( $matches[1] as $tag ) {
        $counts[ $tag ] = ( $counts[ $tag ] ?? 0 ) + 1;
    }

    return $counts;
};

$corpora = [
    'templates' => [ 'label' => 'WPBakery bundled templates', 'files' => glob( $root . '/fixtures/wpbakery-templates/*.txt' ) ?: [] ],
    'layouts'   => [ 'label' => 'Layouts for WPBakery',       'files' => glob( $root . '/fixtures/wpbakery-layouts/*.txt' ) ?: [] ],
    'exports'   => [ 'label' => 'Real theme exports (WXR)',   'files' => [] ],
];

if ( is_dir( $root . '/wpbakery templates' ) ) {
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root . '/wpbakery templates', FilesystemIterator::SKIP_DOTS )
    );
    foreach ( $walk as $file ) {
        if ( $file->isFile() && strtolower( $file->getExtension() ) === 'xml' ) {
            $corpora['exports']['files'][] = $file->getPathname();
        }
    }
    sort( $corpora['exports']['files'] );
}

/**
 * The WPBakery documents in one corpus file.
 *
 * A `.txt` fixture is one document. A WXR export is a whole site: its items'
 * `content:encoded` blocks are the pages, and most of them were not built with
 * WPBakery at all — a blog post, a product description, a widget's serialized
 * options. Counting tags over the raw XML would count `[caption]`, `[embed]`
 * and every bracket in a stylesheet as elements, so the export is read with
 * `WxrReader` (the repository's one WXR parser) and only the items
 * `WPBakeryDocumentParser::isWPBakeryContent()` recognises are counted.
 *
 * @return string[]
 */
$documents_in = static function ( string $file ): array {
    $raw = (string) file_get_contents( $file );

    if ( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) !== 'xml' ) {
        return [ $raw ];
    }

    $items = ( new \WPBakeryDivi5Converter\Parsers\WxrReader() )->read( $raw );

    return array_values( array_filter(
        array_column( $items, 'content' ),
        static fn( string $content ): bool => \WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser::isWPBakeryContent( $content )
    ) );
};

$seen = [];
foreach ( $corpora as $key => $corpus ) {
    $corpora[ $key ]['tags']      = [];
    $corpora[ $key ]['documents'] = 0;

    foreach ( $corpus['files'] as $file ) {
        foreach ( $documents_in( $file ) as $content ) {
            $corpora[ $key ]['documents']++;

            foreach ( $count_tags( $content ) as $tag => $n ) {
                $corpora[ $key ]['tags'][ $tag ] = ( $corpora[ $key ]['tags'][ $tag ] ?? 0 ) + $n;
                $seen[ $tag ]                    = ( $seen[ $tag ] ?? 0 ) + $n;
            }
        }
    }
}

arsort( $seen );

// -----------------------------------------------------------------------------
// The numbers
// -----------------------------------------------------------------------------

// The classification comes from the shared helper; this script's own job is to
// hang an occurrence count off each row.
$with_occurrences = static function ( array $rows ) use ( $seen ): array {
    foreach ( $rows as $index => $row ) {
        $rows[ $index ] = [
            'tag'         => $row['tag'],
            'occurrences' => $seen[ $row['tag'] ] ?? 0,
            'handler'     => $row['handler'],
            'note'        => $row['note'],
        ];
    }

    return $rows;
};

$rows       = $with_occurrences( WBDC_Element_Coverage::rows_for( $registered ) );
$extra_rows = $with_occurrences( WBDC_Element_Coverage::rows_for( $extra ) );

// Theme- and add-on-family tags: everything the corpora hold that WPBakery
// does not ship. Split by whether a handler of this project's own converts it
// (Ultimate Addons, Ronneby) or the family placeholder keeps it.
$family_rows = [];
foreach ( $seen as $tag => $occurrences ) {
    if ( in_array( $tag, $registered, true ) || in_array( $tag, $extra, true ) ) {
        continue;
    }

    $family        = ThemeShortcodes::family( $tag );
    $has_handler   = in_array( $tag, $handled, true );
    $family_rows[] = [
        'tag'         => $tag,
        'occurrences' => $occurrences,
        'family'      => $family['label'],
        'handler'     => $has_handler ? $handler_for( $tag )['handler'] : '',
    ];
}

$family_handled = count( array_filter( $family_rows, static fn( array $r ): bool => $r['handler'] !== '' ) );
$families_seen  = array_values( array_unique( array_column( $family_rows, 'family' ) ) );
sort( $families_seen );

// The corpus-independent half is the shared helper's; the corpus-dependent half
// is this script's, and stays here — no test reads it, and it never reaches the
// readme, because it describes whichever corpus files this checkout happens to
// have rather than what the converter does.
$summary = WBDC_Element_Coverage::summary() + [
    'theme_family_tags_seen'    => count( $family_rows ),
    'theme_family_tags_handled' => $family_handled,
    'theme_families_seen'       => count( $families_seen ),
    'corpus_files'              => array_map( static fn( array $c ): int => count( $c['files'] ), $corpora ),
    'corpus_documents'          => array_map( static fn( array $c ): int => $c['documents'], $corpora ),
    'corpus_elements'           => array_map( static fn( array $c ): int => array_sum( $c['tags'] ), $corpora ),
];

if ( $as_json ) {
    echo json_encode(
        [
            'summary'      => $summary,
            'registered'   => $rows,
            'template_only' => $extra_rows,
            'theme_family' => $family_rows,
            'families'     => $families_seen,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ), "\n";
    exit( 0 );
}

// -----------------------------------------------------------------------------
// The report
// -----------------------------------------------------------------------------

$line = static function ( string $tag, int $occurrences, string $right, string $note ): void {
    printf(
        "  %-32s %7s  %s%s\n",
        $tag,
        number_format( $occurrences ),
        $right === '' ? 'NO HANDLER' : $right,
        $note === '' ? '' : '  [' . $note . ']'
    );
};

echo "WPBakery → Divi 5 element coverage\n";
echo str_repeat( '=', 72 ), "\n\n";

echo "Corpora scanned\n";
foreach ( $corpora as $corpus ) {
    printf(
        "  %-28s %4d files, %4d WPBakery documents, %8s elements\n",
        $corpus['label'],
        count( $corpus['files'] ),
        $corpus['documents'],
        number_format( array_sum( $corpus['tags'] ) )
    );
}
if ( $corpora['exports']['files'] === [] ) {
    echo "  (no exports found; run scripts/extract-ronneby-corpus.sh for the other 93)\n";
}
echo "\n";

echo "Coverage\n";
printf(
    "  Registered WPBakery tags mapped   %d / %d   (%d exact, %d approximate, %d read by their parent element)\n",
    $summary['registered_mapped'],
    $summary['registered_tags'],
    $summary['registered_exact'],
    $summary['registered_approximate'],
    $summary['registered_read_by_parent']
);
printf(
    "  Template-only and vendor tags     %d / %d   (%s)\n",
    $summary['template_only_mapped'],
    $summary['template_only_tags'],
    'the WPBakery render templates and WooCommerce\'s 18'
);
printf(
    "  Theme / add-on tags seen          %d in %d families, %d of them converted by a handler of ours\n",
    $summary['theme_family_tags_seen'],
    $summary['theme_families_seen'],
    $summary['theme_family_tags_handled']
);

// What the readme is allowed to quote: handlers this plugin ships, which does
// not depend on which corpus files this checkout has. The line above does.
$family_parts = [];
foreach ( $summary['family_handlers'] as $label => $count ) {
    $family_parts[] = sprintf( '%s × %d', $label, $count );
}
printf(
    "  Theme / add-on handlers shipped   %d          (%s)  ← the corpus-independent figure the readme quotes\n",
    $summary['family_handler_tags'],
    implode( ', ', $family_parts )
);
echo "\n";

echo "Registered WPBakery tags (", count( $rows ), ")\n";
foreach ( $rows as $row ) {
    if ( ! $show_all && $row['occurrences'] === 0 && $row['handler'] !== '' ) {
        continue;
    }
    $line( $row['tag'], $row['occurrences'], $row['handler'], $row['note'] );
}
if ( ! $show_all ) {
    $hidden = count( array_filter( $rows, static fn( array $r ): bool => $r['occurrences'] === 0 && $r['handler'] !== '' ) );
    echo "  … and {$hidden} more that no corpus document holds (--unseen to list them)\n";
}
echo "\n";

echo "Template-only and vendor tags (", count( $extra_rows ), ")\n";
foreach ( $extra_rows as $row ) {
    $line( $row['tag'], $row['occurrences'], $row['handler'], $row['note'] );
}
echo "\n";

echo "Theme and add-on tags in the corpora (", count( $family_rows ), ")\n";
foreach ( $family_rows as $row ) {
    $line( $row['tag'], $row['occurrences'], $row['handler'] !== '' ? $row['handler'] : $row['family'], $row['handler'] !== '' ? '' : 'placeholder' );
}
echo "\n";

echo "Families seen: ", implode( ', ', $families_seen ), "\n";
