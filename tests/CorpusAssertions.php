<?php

namespace WPBakeryDivi5Converter\Tests;

use Divi5Validator\Validator;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Exporters\DiviBlockSerializer;
use WPBakeryDivi5Converter\Parsers\NodeTree;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

/**
 * What "this page converted" means, checked the same way on every corpus.
 *
 * `BundledTemplateConversionTest`, `LayoutsCorpusConversionTest` and
 * `ThirdPartyTemplateConversionTest` are the same gate over three different
 * bodies of real content — WPBakery's own templates, a third-party layout
 * pack, and theme demo exports — so the assertions live here once and each
 * test says only which documents it feeds them.
 *
 * Five things are asserted, and none of them is a snapshot:
 *
 * 1. **Valid Divi 5.** The vendored deterministic validator over the
 *    serialized document, ignoring `E_MULTIPLE_H1` (headings keep the author's
 *    level, task-8-fix-round-1.md R1).
 * 2. **Nothing unclaimed.** `skipped_settings` empty: an attribute no handler
 *    named is a gap in a handler, not a property of the page.
 * 3. **Nothing unimplemented.** `unsupported` empty. A theme's element is
 *    never in there (spec §7), so anything that is, is a WPBakery core element
 *    with no handler.
 * 4. **The page still has its bands.** One Divi section per top-level
 *    `vc_row`/`vc_section`, because that is the band WPBakery painted
 *    (`SectionConverter`): a page that converts to valid markup with half its
 *    sections merged is not a converted page.
 * 5. **The words survived.** Every heading, button label, toggle title and
 *    plain text block that the *parser* found is somewhere in the blocks.
 *
 * Point 5 reads the source through `WPBakeryDocumentParser`, not with a regex
 * over the raw text, and that is deliberate. A shortcode whose attribute holds
 * an unescaped `"` — `text="A FEW WORDS <span style="…">ABOUT</span>…"`, which
 * one layout in the corpus really does hold — is one WordPress itself cannot
 * read: `shortcode_parse_atts()` breaks it into positional junk and the live
 * WPBakery page renders the heading empty too. Asserting against the raw text
 * would demand this converter recover something WPBakery never showed. What it
 * must not do is lose it *quietly*, and that is a separate check
 * (`logUnmappedSettings()` reports a positional attribute as a warning).
 */
trait CorpusAssertions {

    /**
     * The `not_carried_over` kinds a corpus page may legitimately carry.
     *
     * `error` is deliberately absent: it means a handler threw and a
     * placeholder stands in for a real element, which is a bug, not a
     * reportable property of the page.
     */
    private const ALLOWED_KINDS = [
        'addon', 'animation', 'visibility', 'background', 'interaction',
        'integration', 'layout', 'hover', 'custom_code',
    ];

    /**
     * One document through the whole pipeline, with every corpus assertion
     * made against it.
     *
     * @param string $content The WPBakery `post_content`.
     * @param string $where   What to name in a failure (a file, or an export and item).
     * @param array<string,mixed> $options Conversion options; defaults to import mode.
     * @param array<string,mixed> $meta    The post meta the document parser reads
     *   (`_wpb_shortcodes_custom_css`, `_wpb_post_custom_css`): a page's design
     *   options live there, not in the shortcode, so a corpus that has them and
     *   does not pass them is converting a different page.
     * @return array{divi: array, unsupported: array, report: array}
     */
    private function assertCorpusDocumentConverts( string $content, string $where, array $options = [], array $meta = [] ): array {
        wbdc_test_reset_hooks();

        $result = ( new ConverterEngine() )->convert(
            [ 'content' => $content, 'meta' => $meta ],
            $options + [ 'mode' => 'import' ]
        );

        $this->assertSame(
            [],
            $result['report']['skipped_settings'],
            $where . ': an attribute no handler claimed'
        );

        $this->assertSame(
            [],
            $result['unsupported'],
            $where . ': a WPBakery element with no handler — ' . wp_json_encode( $result['unsupported'] )
        );

        $this->assertValidDivi5( $result['divi'], $where );
        $this->assertSectionPerTopLevelRow( $content, $result['divi'], $where );
        $this->assertSourceStringsSurvive( $content, $result['divi'], $where );
        $this->assertOnlyDocumentedKinds( $result['report'], $where );

        return $result;
    }

    /** @param array{elements: array} $divi */
    private function assertValidDivi5( array $divi, string $where ): void {
        $content = ( new DiviBlockSerializer() )->serialize( [ 'divi' => $divi ] );

        // Headings keep the author's level, so a converted page may
        // legitimately carry more than one <h1>.
        $validation = ( new Validator() )->validateContent( $content, [ Validator::E_MULTIPLE_H1 ] );

        $this->assertTrue(
            $validation->isValid(),
            $where . " is not a valid Divi 5 document:\n" . wp_json_encode( $validation->toArray(), JSON_PRETTY_PRINT )
        );
    }

    /**
     * One `divi/section` per band WPBakery painted.
     *
     * A `vc_section` the author drew is one section. A run of bare top-level
     * rows — which is most WPBakery pages — is wrapped by `NodeTree` into one
     * implicit section, and `SectionConverter` gives each of its rows a section
     * of its own, because each row paints its own full-bleed band.
     *
     * @param array{elements: array} $divi
     */
    private function assertSectionPerTopLevelRow( string $content, array $divi, string $where ): void {
        $roots    = NodeTree::build( WPBakeryDocumentParser::parse( $content )['nodes'] )['roots'];
        $expected = 0;

        foreach ( $roots as $root ) {
            // An implicit section that ended up with no rows still becomes one
            // section holding an empty row, rather than disappearing.
            $expected += empty( $root['implicit'] ) ? 1 : max( 1, count( $root['children'] ?? [] ) );
        }

        $names = array_column( $divi['elements'], 'name' );

        $this->assertSame(
            array_fill( 0, count( $names ), 'divi/section' ),
            $names,
            $where . ': a root block that is not a section'
        );
        $this->assertCount(
            $expected,
            $divi['elements'],
            $where . ': one section per top-level vc_row/vc_section'
        );
    }

    /**
     * Every string the source carries in a place a reader would notice is
     * still somewhere in the blocks.
     *
     * @param array{elements: array} $divi
     */
    private function assertSourceStringsSurvive( string $content, array $divi, string $where ): void {
        $haystack = self::flatten( implode( ' ', self::stringLeaves( $divi ) ) );

        foreach ( self::sourceStrings( WPBakeryDocumentParser::parse( $content )['nodes'] ) as [ $what, $string ] ) {
            $wanted = self::flatten( $string );

            if ( $wanted === '' ) {
                continue;
            }

            $this->assertStringContainsString(
                $wanted,
                $haystack,
                sprintf( '%s: the %s "%s" is in no block', $where, $what, self::excerpt( $wanted ) )
            );
        }
    }

    /** @param array<string,mixed> $report */
    private function assertOnlyDocumentedKinds( array $report, string $where ): void {
        foreach ( $report['not_carried_over'] as $entry ) {
            $this->assertContains(
                $entry['kind'],
                self::ALLOWED_KINDS,
                sprintf( '%s: not_carried_over kind "%s" — %s', $where, $entry['kind'], $entry['detail'] )
            );
        }
    }

    /**
     * The strings a reader would notice, read out of the parsed tree.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array{0: string, 1: string}> [what it is, the string]
     */
    private static function sourceStrings( array $nodes ): array {
        /** tag ⇒ the attribute that holds its visible text. */
        static $attribute_text = [
            'vc_custom_heading' => 'text',
            'vc_btn'            => 'title',
            'vc_toggle'         => 'title',
        ];

        $found = [];

        foreach ( $nodes as $node ) {
            $tag  = (string) ( $node['tag'] ?? '' );
            $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

            if ( isset( $attribute_text[ $tag ] ) ) {
                $key = $attribute_text[ $tag ];
                if ( isset( $atts[ $key ] ) && is_string( $atts[ $key ] ) ) {
                    $found[] = [ $tag . ' ' . $key, $atts[ $key ] ];
                }
            }

            // A text block whose body holds another shortcode is split across
            // several blocks by the flattener, so only the plain ones are a
            // single string anybody can look for.
            if ( $tag === 'vc_column_text' ) {
                $body = (string) ( $node['content'] ?? '' );
                if ( ! str_contains( $body, '[' ) ) {
                    $found[] = [ 'vc_column_text', $body ];
                }
            }

            $found = array_merge( $found, self::sourceStrings( is_array( $node['children'] ?? null ) ? $node['children'] : [] ) );
        }

        return $found;
    }

    /**
     * Every string in a block tree, so a value can be looked for wherever a
     * handler decided to put it.
     *
     * @param array<mixed> $node
     * @return string[]
     */
    private static function stringLeaves( array $node ): array {
        $found = [];

        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                $found = array_merge( $found, self::stringLeaves( $value ) );
            } elseif ( is_string( $value ) ) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * Text as a reader sees it: no markup, no entities, one space between
     * words.
     *
     * A heading written over three lines in the source becomes `<br>`-joined
     * or wrapped markup in Divi, and a `<strong>Read More</strong>` button
     * label becomes a plain label, so the comparison has to be about the words
     * rather than about the characters.
     */
    private static function flatten( string $text ): string {
        $text = preg_replace( '/<br\s*\/?>/i', ' ', $text );
        $text = preg_replace( '#</(p|div|h[1-6]|li|td|th)>#i', ' ', (string) $text );
        $text = wp_strip_all_tags( (string) $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 );
        $text = str_replace( "\xc2\xa0", ' ', $text );

        return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function excerpt( string $text ): string {
        return strlen( $text ) > 80 ? substr( $text, 0, 80 ) . '…' : $text;
    }
}
