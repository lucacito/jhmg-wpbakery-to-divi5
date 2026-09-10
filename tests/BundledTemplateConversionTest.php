<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WPBakery's own default templates, every one of them, through the converter.
 *
 * `fixtures/wpbakery-templates/` is `config/templates.php` from js_composer
 * 9.0.1 turned into fixtures by `scripts/wpb-templates-to-fixtures.php`: the
 * 75 layouts the plugin offers in its template library, written by WPBakery's
 * own editor. They are the closest thing to a specification of what the
 * builder emits, and nobody here wrote them, so they are the gate that says
 * the converter handles WPBakery rather than handling its own examples.
 *
 * The assertions are `CorpusAssertions`; this file only supplies the corpus.
 * Parsing the same fixtures — that a page comes out as sections of rows with
 * no stray text — is `FixtureCorpusTest`.
 */
final class BundledTemplateConversionTest extends TestCase {

    use CorpusAssertions;

    /** @return array<string, array{0: string}> */
    public static function templateProvider(): array {
        $cases = [];

        foreach ( glob( dirname( __DIR__ ) . '/fixtures/wpbakery-templates/*.txt' ) ?: [] as $file ) {
            $cases[ basename( $file ) ] = [ $file ];
        }

        return $cases;
    }

    /**
     * At least, not exactly: js_composer 7.8 and 9.0.1 both ship 75, and a
     * later release adding one must not silently shrink the gate — but a
     * regenerated corpus that *lost* templates has to fail.
     */
    public function test_the_corpus_holds_every_bundled_template(): void {
        $this->assertGreaterThanOrEqual(
            75,
            count( self::templateProvider() ),
            'js_composer 9.0.1 config/templates.php holds 75 templates; run scripts/wpb-templates-to-fixtures.php'
        );
    }

    #[DataProvider( 'templateProvider' )]
    public function test_a_bundled_template_converts_to_valid_divi_5( string $file ): void {
        $this->assertCorpusDocumentConverts( (string) file_get_contents( $file ), basename( $file ) );
    }
}
