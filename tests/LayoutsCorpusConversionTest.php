<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

/**
 * The Layouts for WPBakery corpus: 35 finished pages built by somebody else.
 *
 * `fixtures/wpbakery-layouts/` was fetched by `scripts/fetch-layouts-corpus.php`
 * from the layout pack's own API. Where the bundled templates are WPBakery's
 * demonstrations of each element, these are commercial pages — a restaurant, a
 * photographer, a SaaS landing page — with the attribute soup that comes of
 * being designed rather than demonstrated: sixty-odd design-option rules per
 * page, animations, hover states, media ids that belong to a site this test
 * does not have.
 *
 * They convert in **import** mode, which is what they are: an export, with no
 * media library behind it. So `unresolved_media` is expected to be non-empty
 * here and is asserted for shape rather than for emptiness — the point of the
 * key is that the report names each id so a reader can go and find it, and a
 * missing picture on a page imported from another site is not a conversion
 * failure.
 *
 * Everything else is `CorpusAssertions`, the same gate the bundled templates
 * pass.
 */
final class LayoutsCorpusConversionTest extends TestCase {

    use CorpusAssertions;

    /** @return array<string, array{0: string}> */
    public static function layoutProvider(): array {
        $cases = [];

        foreach ( glob( dirname( __DIR__ ) . '/fixtures/wpbakery-layouts/*.txt' ) ?: [] as $file ) {
            $cases[ basename( $file ) ] = [ $file ];
        }

        return $cases;
    }

    public function test_the_corpus_holds_every_layout_that_was_fetched(): void {
        $this->assertCount(
            35,
            self::layoutProvider(),
            'the Layouts for WPBakery API published 35 layouts when this corpus was fetched'
        );
    }

    #[DataProvider( 'layoutProvider' )]
    public function test_a_layout_converts_to_valid_divi_5( string $file ): void {
        $result = $this->assertCorpusDocumentConverts( (string) file_get_contents( $file ), basename( $file ) );

        foreach ( $result['report']['unresolved_media'] as $entry ) {
            $this->assertSame( [ 'node_id', 'attachment_id' ], array_keys( $entry ) );
            $this->assertGreaterThan(
                0,
                $entry['attachment_id'],
                basename( $file ) . ': an unresolved attachment with no id to look up'
            );
        }
    }

    /**
     * The corpus as a whole, not one page: `unresolved_media` has to actually
     * fire somewhere, or the assertion above is checking an empty list on
     * every one of the 35 and proving nothing.
     */
    public function test_the_corpus_reports_the_media_it_could_not_resolve(): void {
        $seen = 0;

        foreach ( self::layoutProvider() as [ $file ] ) {
            $result = ( new ConverterEngine() )->convert(
                [ 'content' => (string) file_get_contents( $file ) ],
                [ 'mode' => 'import' ]
            );
            $seen  += count( $result['report']['unresolved_media'] );
        }

        $this->assertGreaterThan( 0, $seen, 'no layout referenced an attachment id this site does not have' );
    }
}
