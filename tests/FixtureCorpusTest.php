<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Parsers\NodeTree;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

/**
 * Every fixture in both corpora, through the whole parsing layer.
 *
 * `fixtures/wpbakery-templates/` is WPBakery's own default template list
 * (config/templates.php, written by scripts/wpb-templates-to-fixtures.php) and
 * `fixtures/wpbakery-layouts/` is the Layouts for WPBakery corpus
 * (scripts/fetch-layouts-corpus.php). Between them they are every element
 * WPBakery ships, in documents nobody here wrote, so they are the check that
 * the parser copes with real content rather than with the examples its unit
 * tests were built around.
 *
 * What is asserted is structural, not per-element: a page has to come out as
 * sections, and WPBakery's own templates — which its editor wrote — have to
 * come out with no stray text at all. Warnings are counted, not asserted:
 * a layout that needs repairing is not a failure, it is what the conversion
 * report is for; the counts only surface when something else fails.
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
