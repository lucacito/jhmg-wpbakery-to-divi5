<?php

namespace WPBakeryDivi5Converter\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Exporters\DiviBlockSerializer;
use WPBakeryDivi5Converter\Parsers\NodeTree;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

/**
 * Every fixture in both corpora, through the parser and then the converter.
 *
 * `fixtures/wpbakery-templates/` is WPBakery's own default template list
 * (config/templates.php, written by scripts/wpb-templates-to-fixtures.php) and
 * `fixtures/wpbakery-layouts/` is the Layouts for WPBakery corpus
 * (scripts/fetch-layouts-corpus.php). Between them they are every element
 * WPBakery ships, in documents nobody here wrote, so they are the check that
 * the parser copes with real content rather than with the examples its unit
 * tests were built around.
 *
 * Two things are asserted. **Parsing** is structural, not per-element: a page
 * has to come out as sections, and WPBakery's own templates — which its editor
 * wrote — have to come out with no stray text at all. **Conversion** has to
 * produce a valid Divi 5 document with no skipped settings, which is the
 * integration gate every handler batch keeps green.
 *
 * Warnings are counted, not asserted: a layout that needs repairing is not a
 * failure, it is what the conversion report is for; the counts only surface
 * when something else fails.
 */
final class FixtureCorpusTest extends TestCase {

    /** @return array<string, array{0: string}> */
    public static function templateProvider(): array {
        return self::fixtures( 'wpbakery-templates' );
    }

    /** @return array<string, array{0: string}> */
    public static function layoutProvider(): array {
        return self::fixtures( 'wpbakery-layouts' );
    }

    /** @return array<string, array{0: string}> */
    private static function fixtures( string $folder ): array {
        $directory = dirname( __DIR__ ) . '/fixtures/' . $folder;
        $cases     = [];

        foreach ( glob( $directory . '/*.txt' ) ?: [] as $file ) {
            $cases[ $folder . '/' . basename( $file ) ] = [ $file ];
        }

        return $cases;
    }

    public function test_both_corpora_are_present(): void {
        // Both counts are deliberately exact: a fixture that appears without
        // anyone noticing is a fixture nobody has read.
        $this->assertCount( 75, self::templateProvider(), 'js_composer 9.0.1 config/templates.php holds 75 templates' );
        $this->assertCount( 35, self::layoutProvider(), 'the Layouts for WPBakery API published 35 layouts when this corpus was fetched' );
    }

    #[DataProvider( 'templateProvider' )]
    public function test_a_default_template_parses_to_sections_of_rows( string $file ): void {
        $tree = $this->tree( $file );

        $this->assertNotEmpty( $tree['roots'], 'no roots' );

        foreach ( $tree['roots'] as $root ) {
            $this->assertSame( 'section', $root['kind'], 'a root that is not a section' );
        }

        $this->assertSame( [], $this->textNodes( $tree['roots'] ), 'WPBakery\'s own templates hold no stray text' );
    }

    #[DataProvider( 'layoutProvider' )]
    public function test_a_layout_parses_to_sections_of_rows( string $file ): void {
        $tree = $this->tree( $file );

        $this->assertNotEmpty( $tree['roots'], 'no roots' );

        foreach ( $tree['roots'] as $root ) {
            $this->assertSame(
                'section',
                $root['kind'],
                'a root that is not a section; warnings: ' . count( $tree['warnings'] )
            );
        }
    }

    /**
     * The whole corpus through the converter, not just the parser: both
     * corpora are documents nobody here wrote, so they are the integration
     * gate every handler batch has to keep green — a valid Divi 5 document out
     * the other end, and no setting reported as skipped.
     *
     * A skipped setting is not a crash, it is an attribute no handler claimed;
     * on 110 real documents that is exactly the signal that a handler has a
     * gap, so it is asserted at zero rather than counted.
     */
    #[DataProvider( 'templateProvider' )]
    public function test_a_default_template_converts_clean( string $file ): void {
        $this->assertConvertsClean( $file );
    }

    #[DataProvider( 'layoutProvider' )]
    public function test_a_layout_converts_clean( string $file ): void {
        $this->assertConvertsClean( $file );
    }

    private function assertConvertsClean( string $file ): void {
        wbdc_test_reset_hooks();

        $result = ( new ConverterEngine() )->convert(
            [ 'content' => (string) file_get_contents( $file ) ],
            [ 'mode' => 'import' ]
        );

        $this->assertSame(
            [],
            $result['report']['skipped_settings'],
            basename( $file ) . ': an attribute no handler claimed'
        );

        $content = ( new DiviBlockSerializer() )->serialize( [ 'divi' => $result['divi'] ] );

        // Headings keep the author's level (task-8-fix-round-1.md, R1), so a
        // converted page may legitimately carry more than one <h1>.
        $validation = ( new Validator() )->validateContent( $content, [ Validator::E_MULTIPLE_H1 ] );

        $this->assertTrue(
            $validation->isValid(),
            basename( $file ) . " is not a valid Divi 5 document:\n" . json_encode( $validation->toArray(), JSON_PRETTY_PRINT )
        );
    }

    /** @return array{roots: array, warnings: string[]} */
    private function tree( string $file ): array {
        $content = (string) file_get_contents( $file );

        $this->assertTrue( WPBakeryDocumentParser::isWPBakeryContent( $content ), 'not recognised as WPBakery content' );

        return NodeTree::build( WPBakeryDocumentParser::parse( $content )['nodes'] );
    }

    /**
     * Every `#text` node in a tree, quoted, so a failure names the text rather
     * than only counting it.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return string[]
     */
    private function textNodes( array $nodes ): array {
        $found = [];

        foreach ( $nodes as $node ) {
            if ( $node['kind'] === 'text' ) {
                $found[] = $node['text'];
            }

            $found = array_merge( $found, $this->textNodes( $node['children'] ) );
        }

        return $found;
    }
}
