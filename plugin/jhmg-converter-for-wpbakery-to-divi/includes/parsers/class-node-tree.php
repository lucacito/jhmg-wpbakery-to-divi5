<?php

namespace WPBakeryDivi5Converter\Parsers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a parsed WPBakery document into the tree every converter walks:
 * section → row → column → element, with an id and a kind on every node.
 *
 * WPBakery does not enforce that shape. Its editor produces it, but the
 * content is a shortcode string that anything can edit: a theme's exported
 * demo, a search-and-replace, a hand-written page. What arrives is routinely
 * a row with no section around it (WPBakery only gained `vc_section` in 4.9,
 * and the editor still writes rows at the top level), an element sitting
 * outside any row, an inner row promoted to the top by a copy/paste, or a
 * paragraph of stray text between two shortcodes.
 *
 * Divi 5 has no equivalent slack: a module has to live in a column, a column
 * in a row, a row in a section. So every departure is repaired here, once,
 * rather than in each of the thirty-odd handlers — and every repair is
 * recorded in `warnings`, which the conversion report shows the user, so a
 * page that came out restructured says why.
 *
 * A repaired-in node carries `'implicit' => true`; nothing else does.
 */
final class NodeTree {

    /** Tag → kind. Everything the map does not name is an element. */
    private const KINDS = [
        '#text'           => 'text',
        'vc_section'      => 'section',
        'vc_row'          => 'row',
        'vc_row_inner'    => 'row_inner',
        'vc_column'       => 'column',
        'vc_column_inner' => 'column_inner',
    ];

    /** The two column kinds, either of which may sit in either kind of row. */
    private const COLUMN_KINDS = [ 'column', 'column_inner' ];

    /** How much of a stray text run the warning quotes. */
    private const TEXT_WARNING_LENGTH = 40;

    /**
     * Builds the tree.
     *
     * @param array<int, array<string, mixed>> $nodes `ShortcodeParser` nodes, normalised or not.
     * @return array{roots: array<int, array<string, mixed>>, warnings: string[]}
     */
    public static function build( array $nodes ): array {
        $warnings = [];

        $prepared = self::prepare( $nodes, $warnings );
        $roots    = self::sections( $prepared, $warnings );
        $used     = [];
        $counters = [];
        $roots    = self::assignIds( $roots, self::uniqueElementIds( $roots ), $used, $counters );

        return [
            'roots'    => $roots,
            'warnings' => $warnings,
        ];
    }

    /**
     * Counts every element tag in a tree, in tag order. Only nodes of kind
     * `element` are counted: the containers are structure, not content, and
     * the coverage panel reports on what a page is made of.
     *
     * @param array<int, array<string, mixed>> $roots
     * @return array<string, int>
     */
    public static function census( array $roots ): array {
        $census = [];

        self::walk( $roots, static function ( array $node ) use ( &$census ): void {
            if ( $node['kind'] !== 'element' ) {
                return;
            }

            $census[ $node['tag'] ] = ( $census[ $node['tag'] ] ?? 0 ) + 1;
        } );

        ksort( $census );

        return $census;
    }

    /**
     * Normalises every node to the tree's shape, depth first: adjacent text
     * runs merged, `content` cleared on everything but a raw-content tag.
     *
     * A container's `content` is the raw substring of its whole subtree, which
     * is already in `children`; carrying both means every later stage holds
     * the document twice and has two versions of the truth to choose from. A
     * raw-content tag (`ShortcodeParser::RAW_CONTENT_TAGS`) is the opposite
     * case: its content is an editor field, has no children, and is the only
     * thing it holds.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param string[]                         $warnings
     * @return array<int, array<string, mixed>>
     */
    private static function prepare( array $nodes, array &$warnings ): array {
        $prepared = [];

        foreach ( self::mergeText( $nodes ) as $node ) {
            if ( $node['tag'] === '#text' ) {
                $warnings[] = 'text kept: "' . self::excerpt( $node['text'] ) . '"';
                $prepared[] = self::textNode( $node );

                continue;
            }

            $raw = in_array( $node['tag'], ShortcodeParser::RAW_CONTENT_TAGS, true );

            $prepared[] = self::node(
                $node['tag'],
                $node['atts'] ?? [],
                $raw ? (string) ( $node['content'] ?? '' ) : '',
                self::prepare( $node['children'] ?? [], $warnings ),
                $node['notes'] ?? []
            );
        }

        return $prepared;
    }

    /**
     * Adjacent `#text` nodes concatenated into one, keeping the first
     * offset. `ShortcodeParser` emits a separate run either side of an
     * escaped shortcode (`[[vc_row]]`), and a stray-text warning per run
     * would report one interruption three times.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private static function mergeText( array $nodes ): array {
        $merged = [];

        foreach ( $nodes as $node ) {
            $previous = $merged === [] ? null : array_key_last( $merged );

            if ( $node['tag'] === '#text' && $previous !== null && $merged[ $previous ]['tag'] === '#text' ) {
                $merged[ $previous ]['text'] .= $node['text'];

                continue;
            }

            $merged[] = $node;
        }

        return $merged;
    }

    /**
     * The top level: every root is a section. A run of anything else — rows
     * included, which is how WPBakery itself writes a page that uses no
     * sections — is gathered into one implicit section rather than one each,
     * so a page keeps the number of bands it looked like it had.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param string[]                         $warnings
     * @return array<int, array<string, mixed>>
     */
    private static function sections( array $nodes, array &$warnings ): array {
        $roots   = [];
        $pending = [];

        foreach ( $nodes as $node ) {
            if ( $node['kind'] === 'section' ) {
                $roots   = self::flushSection( $roots, $pending, $warnings );
                $pending = [];
                $node['children'] = self::rows( $node['children'], $warnings );
                $roots[]          = $node;

                continue;
            }

            $pending[] = $node;
        }

        return self::flushSection( $roots, $pending, $warnings );
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @param array<int, array<string, mixed>> $pending
     * @param string[]                         $warnings
     * @return array<int, array<string, mixed>>
     */
    private static function flushSection( array $roots, array $pending, array &$warnings ): array {
        if ( $pending === [] ) {
            return $roots;
        }

        $roots[] = self::implicit( 'vc_section', [], self::rows( $pending, $warnings ) );

        return $roots;
    }

    /**
     * A section's children: rows. An inner row that has ended up here is a
     * row (a copy/paste out of its column); anything else is content outside
     * a row and gets one, with a column inside it.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param string[]                         $warnings
     * @return array<int, array<string, mixed>>
     */
    private static function rows( array $nodes, array &$warnings ): array {
        $rows    = [];
        $pending = [];

        foreach ( $nodes as $node ) {
            if ( $node['kind'] === 'row_inner' ) {
                $warnings[]    = 'vc_row_inner at the top level treated as a row';
                $node['tag']   = 'vc_row';
                $node['kind']  = 'row';
            }

            if ( $node['kind'] === 'row' ) {
                $rows             = self::flushRow( $rows, $pending );
                $pending          = [];
                $node['children'] = self::columns( $node['children'], $warnings, 'row child is not a column' );
                $rows[]           = $node;

                continue;
            }

            $warnings[] = 'content outside a row: ' . $node['tag'];
            $pending[]  = $node;
        }

        return self::flushRow( $rows, $pending );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $pending
     * @return array<int, array<string, mixed>>
     */
    private static function flushRow( array $rows, array $pending ): array {
        if ( $pending === [] ) {
            return $rows;
        }

        $ignored = [];
        $rows[]  = self::implicit( 'vc_row', [], self::columns( $pending, $ignored, '' ) );

        return $rows;
    }

    /**
     * A row's children: columns. A run of anything else is gathered into one
     * implicit full-width column — one per run, so two elements that sat side
     * by side in the source stay side by side.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param string[]                         $warnings
     * @param string                           $warning Prefix for the warning, '' to stay quiet
     *                                                  (the caller has already reported the run).
     * @return array<int, array<string, mixed>>
     */
    private static function columns( array $nodes, array &$warnings, string $warning ): array {
        $columns = [];
        $pending = [];

        foreach ( $nodes as $node ) {
            if ( in_array( $node['kind'], self::COLUMN_KINDS, true ) ) {
                $columns = self::flushColumn( $columns, $pending );
                $pending = [];
                $columns[] = $node;

                continue;
            }

            if ( $warning !== '' ) {
                $warnings[] = $warning . ': ' . $node['tag'];
            }
            $pending[] = $node;
        }

        return self::flushColumn( $columns, $pending );
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<string, mixed>> $pending
     * @return array<int, array<string, mixed>>
     */
    private static function flushColumn( array $columns, array $pending ): array {
        if ( $pending === [] ) {
            return $columns;
        }

        $columns[] = self::implicit( 'vc_column', [ 'width' => '1/1' ], $pending );

        return $columns;
    }

    /**
     * Ids, in document order: an element's own `el_id` when it has a unique
     * one — that is what the page's anchors and custom CSS already point at —
     * and `<tag>-<n>` otherwise.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, bool>              $unique_el_ids el_id → whether it appears once.
     * @param array<string, bool>              $used
     * @param array<string, int>               $counters
     * @return array<int, array<string, mixed>>
     */
    private static function assignIds( array $nodes, array $unique_el_ids, array &$used, array &$counters ): array {
        foreach ( $nodes as $index => $node ) {
            $el_id = (string) ( $node['atts']['el_id'] ?? '' );

            $counters[ $node['tag'] ] = ( $counters[ $node['tag'] ] ?? 0 ) + 1;

            if ( $el_id !== '' && ! empty( $unique_el_ids[ $el_id ] ) && empty( $used[ $el_id ] ) ) {
                $id = $el_id;
            } else {
                do {
                    $id = $node['tag'] . '-' . $counters[ $node['tag'] ];
                    if ( isset( $used[ $id ] ) ) {
                        $counters[ $node['tag'] ]++;
                    }
                } while ( isset( $used[ $id ] ) );
            }

            $used[ $id ]        = true;
            $node['id']         = $id;
            $node['children']   = self::assignIds( $node['children'], $unique_el_ids, $used, $counters );
            $nodes[ $index ]    = $node;
        }

        return $nodes;
    }

    /**
     * Which `el_id` values appear exactly once in the tree. A duplicate — the
     * usual result of cloning a row — identifies nothing, so both nodes fall
     * back to their positional id.
     *
     * @param array<int, array<string, mixed>> $roots
     * @return array<string, bool>
     */
    private static function uniqueElementIds( array $roots ): array {
        $counts = [];

        self::walk( $roots, static function ( array $node ) use ( &$counts ): void {
            $el_id = (string) ( $node['atts']['el_id'] ?? '' );

            if ( $el_id !== '' ) {
                $counts[ $el_id ] = ( $counts[ $el_id ] ?? 0 ) + 1;
            }
        } );

        return array_map( static fn( int $count ): bool => $count === 1, $counts );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private static function walk( array $nodes, callable $visit ): void {
        foreach ( $nodes as $node ) {
            $visit( $node );
            self::walk( $node['children'] ?? [], $visit );
        }
    }

    /**
     * @param array<string, string>            $atts
     * @param array<int, array<string, mixed>> $children
     * @param string[]                         $notes
     * @return array<string, mixed>
     */
    private static function node( string $tag, array $atts, string $content, array $children, array $notes ): array {
        return [
            'id'       => '',
            'tag'      => $tag,
            'kind'     => self::KINDS[ $tag ] ?? 'element',
            'atts'     => $atts,
            'content'  => $content,
            'children' => $children,
            'notes'    => $notes,
        ];
    }

    /**
     * @param array<string, string>            $atts
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private static function implicit( string $tag, array $atts, array $children ): array {
        return self::node( $tag, $atts, '', $children, [] ) + [ 'implicit' => true ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function textNode( array $node ): array {
        return self::node( '#text', [], '', [], [] ) + [
            'text'   => (string) $node['text'],
            'offset' => (int) ( $node['offset'] ?? 0 ),
        ];
    }

    /** The first characters of a stray text run, whitespace collapsed. */
    private static function excerpt( string $text ): string {
        $collapsed = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

        return mb_substr( $collapsed, 0, self::TEXT_WARNING_LENGTH );
    }
}
