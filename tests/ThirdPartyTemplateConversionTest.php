<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;
use WPBakeryDivi5Converter\Parsers\WPBakeryImportParser;

/**
 * Real theme exports: whole sites, as their authors shipped them.
 *
 * `wpbakery templates/**\/*.xml` holds WordPress exports (WXR) of complete
 * WPBakery sites — 96 DFD Ronneby demos, three of them committed as reviewed
 * samples and the rest re-extractable with
 * `scripts/extract-ronneby-corpus.sh`. They are the hardest input this
 * converter has: pages of forty rows with a theme's own elements, a theme's
 * own fields bolted onto WPBakery's rows and columns, and attribute names from
 * several builds of the same theme on the same tag.
 *
 * Every WPBakery item in every export has to convert clean — the same gate the
 * two fixture corpora pass (`CorpusAssertions`) — plus two things that are
 * only about a theme (task-10-amendments §5):
 *
 * - a Ronneby element **with** a handler converts, so it must not turn up as a
 *   theme-family placeholder under `theme_elements`;
 * - a Ronneby element **without** one is a theme element, not a WPBakery
 *   element this converter forgot, so it must never turn up under
 *   `unsupported`.
 *
 * ### Why this can be incomplete
 *
 * One way, and it is honest rather than a way round a failure: 93 of the 96
 * exports are gitignored (43 MB of commercial demo content), so a clean
 * checkout runs this over the three committed samples; with the theme archive
 * in `references/` it runs over all 96.
 */
final class ThirdPartyTemplateConversionTest extends TestCase {

    use CorpusAssertions;

    /**
     * The one case an empty corpus provides.
     *
     * A data provider that returns nothing is a hard error in PHPUnit 13
     * ("Empty data set provided by data provider"), raised before a line of
     * the test body runs — so a guard inside the body cannot save a checkout
     * with no exports. The empty case therefore has to *be* a case: one whose
     * file is the empty string, which `requireCorpus()` recognises and marks
     * incomplete on.
     */
    private const NO_CORPUS = '(no exports)';

    /** Overrides the corpus directory, so the empty case can be exercised. */
    private const CORPUS_ENV = 'WBDC_EXPORT_CORPUS';

    private static function corpusRoot(): string {
        $override = getenv( self::CORPUS_ENV );

        return is_string( $override ) && $override !== '' ? $override : dirname( __DIR__ ) . '/wpbakery templates';
    }

    /** @return array<string, array{0: string}> */
    public static function exportProvider(): array {
        $root  = self::corpusRoot();
        $cases = [];

        if ( is_dir( $root ) ) {
            $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

            foreach ( $files as $file ) {
                if ( $file->isFile() && strtolower( $file->getExtension() ) === 'xml' ) {
                    $cases[ self::label( $root, $file->getPathname() ) ] = [ $file->getPathname() ];
                }
            }

            ksort( $cases );
        }

        return $cases === [] ? [ self::NO_CORPUS => [ '' ] ] : $cases;
    }

    public function test_there_is_a_corpus_of_real_exports(): void {
        $cases = self::exportProvider();

        $this->requireCorpus( (string) ( reset( $cases )[0] ?? '' ) );

        foreach ( $cases as $label => [ $file ] ) {
            $this->assertFileIsReadable( $file, $label );
        }
    }

    #[DataProvider( 'exportProvider' )]
    public function test_a_real_theme_export_converts_to_valid_divi_5( string $file ): void {
        $this->requireCorpus( $file );

        // The parser's contract is that every item it returns holds a WPBakery
        // layout, so there is nothing to filter here — and an export that
        // yields none is a failure of this reader, not a property of the file:
        // all 96 of these are WPBakery sites.
        $items = ( new WPBakeryImportParser() )->parse( $file );

        // `summary()` walks every item of a 40 MB export, so it is built only
        // when it is about to be printed — never as an eagerly evaluated
        // assertion message, once per assertion, once per item.
        $this->assertTrue(
            $items !== [],
            $items !== [] ? '' : basename( $file ) . ': no item in this export holds a WPBakery layout'
        );

        foreach ( $items as $item ) {
            $this->assertTrue(
                WPBakeryDocumentParser::isWPBakeryContent( (string) ( $item['content'] ?? '' ) ),
                sprintf( '%s: the parser returned "%s", which is not a WPBakery layout', basename( $file ), (string) ( $item['title'] ?? '' ) )
            );

            $where = sprintf( '%s / %s', basename( $file ), (string) ( $item['title'] ?? 'untitled' ) );

            $result = $this->assertCorpusDocumentConverts(
                (string) $item['content'],
                $where,
                [
                    'mode'        => (string) ( $item['mode'] ?? 'import' ),
                    'attachments' => is_array( $item['attachments'] ?? null ) ? $item['attachments'] : [],
                ],
                is_array( $item['meta'] ?? null ) ? $item['meta'] : []
            );

            $this->assertThemeElementsAreOnlyTheUnhandledOnes(
                (string) $item['content'],
                $result['report'],
                $where,
                fn (): string => self::summary( $file, $items )
            );
        }
    }

    /**
     * A theme's element is either converted or kept as a family placeholder,
     * and which one it is says whether a handler ran.
     *
     * `theme_elements` counts by family, so the check is arithmetic: the
     * "Ronneby" count cannot exceed the number of Ronneby shortcodes on the
     * page that have no handler. If a handled tag fell through — the handler
     * unregistered, a tag renamed, a class not loading — the count goes above
     * that number and this fails, naming the tags.
     *
     * The other half, that an unhandled Ronneby tag is never `unsupported`, is
     * already covered: `assertCorpusDocumentConverts()` asserts `unsupported`
     * is empty for the whole page.
     *
     * @param array<string,mixed> $report
     * @param callable(): string $summary Built only if the assertion fails.
     */
    private function assertThemeElementsAreOnlyTheUnhandledOnes(
        string $content,
        array $report,
        string $where,
        callable $summary
    ): void {
        require_once dirname( __DIR__ ) . '/scripts/lib/corpus-shortcodes.php';

        $handled   = ( new ConverterEngine() )->registry()->knownTags();
        $unhandled = 0;
        $named     = [];

        foreach ( self::tagsIn( $content ) as $tag ) {
            if ( ThemeShortcodes::family( $tag )['key'] !== 'ronneby' || in_array( $tag, $handled, true ) ) {
                continue;
            }

            $unhandled += count( wbdc_find_shortcodes( $content, $tag ) );
            $named[]    = $tag;
        }

        $counted = $report['theme_elements']['Ronneby'] ?? 0;

        $this->assertTrue(
            $counted <= $unhandled,
            $counted <= $unhandled ? '' : sprintf(
                "%s: %d elements were kept as Ronneby placeholders but only %d Ronneby tags on the page (%s) have no handler — a handled element fell through.\n%s",
                $where,
                $counted,
                $unhandled,
                $named === [] ? 'none' : implode( ', ', $named ),
                $summary()
            )
        );
    }

    /** @return string[] Every distinct shortcode tag in a document. */
    private static function tagsIn( string $content ): array {
        if ( preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_-]*)/', $content, $matches ) === 0 ) {
            return [];
        }

        return array_values( array_unique( $matches[1] ) );
    }

    /**
     * What the export held, for a failure message only: the reader needs to
     * know whether one page broke or the whole file was read wrong.
     *
     * @param array<int,array<string,mixed>> $items The WPBakery items the parser returned.
     */
    private static function summary( string $file, array $items ): string {
        $elements = 0;
        $families = [];

        foreach ( $items as $item ) {
            $content   = (string) ( $item['content'] ?? '' );
            $elements += preg_match_all( '/\[[a-zA-Z][a-zA-Z0-9_-]*/', $content );

            foreach ( self::tagsIn( $content ) as $tag ) {
                $label              = ThemeShortcodes::family( $tag )['label'];
                $families[ $label ] = ( $families[ $label ] ?? 0 ) + 1;
            }
        }

        ksort( $families );

        return sprintf(
            '%s: %d WPBakery items, %d elements, families %s',
            basename( $file ),
            count( $items ),
            $elements,
            wp_json_encode( $families )
        );
    }

    /** @param string $file The provided file, or `''` — the empty-corpus sentinel. */
    private function requireCorpus( string $file ): void {
        if ( $file === '' ) {
            $this->markTestIncomplete(
                'No exports in "wpbakery templates/". Three are committed; run scripts/extract-ronneby-corpus.sh for the other 93.'
            );
        }
    }

    /** A stable, readable case name: the path under the corpus root. */
    private static function label( string $root, string $path ): string {
        return ltrim( str_replace( $root, '', $path ), '/' );
    }
}
