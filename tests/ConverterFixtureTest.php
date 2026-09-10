<?php

namespace WPBakeryDivi5Converter\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Exporters\DiviBlockSerializer;

/**
 * Every fixtures/wpbakery/<name>.txt must convert to exactly
 * fixtures/divi/<name>.json, and that expected output must serialize to a
 * valid Divi 5 document.
 *
 * The expected files are a reviewed specification, not a snapshot: read
 * `php scripts/render-fixture.php fixtures/wpbakery/<name>.txt --report`
 * against the spec first, then regenerate with `scripts/update-expected.php`.
 *
 * A sidecar `fixtures/wpbakery/<name>.json` may carry:
 *   meta        post meta the document parser reads (_wpb_shortcodes_custom_css…)
 *   mode        'direct' | 'import'  (the scripts and this test default to direct)
 *   attachments id ⇒ URL, both a conversion option and the site's media library
 *   rendered    tag ⇒ html, what do_shortcode() answers for a theme shortcode
 *   rendered_widgets  widget class ⇒ html, what the_widget() echoes for a vc_wp_* element
 *   source      the corpus file a real fixture was cut from
 */
final class ConverterFixtureTest extends TestCase {

    /** @return array<string, array{0: string}> */
    public static function fixtureProvider(): array {
        $cases = [];
        foreach ( glob( __DIR__ . '/../fixtures/wpbakery/*.txt' ) ?: [] as $file ) {
            $name           = basename( $file, '.txt' );
            $cases[ $name ] = [ $name ];
        }
        return $cases;
    }

    #[DataProvider( 'fixtureProvider' )]
    public function test_converter_matches_expected_fixture( string $name ): void {
        wbdc_test_reset_hooks();

        $expected_file = __DIR__ . "/../fixtures/divi/{$name}.json";
        $this->assertFileExists(
            $expected_file,
            "No expected output for fixture '{$name}'. Review scripts/render-fixture.php output, then run scripts/update-expected.php {$name}."
        );

        $content = (string) file_get_contents( __DIR__ . "/../fixtures/wpbakery/{$name}.txt" );
        $sidecar = self::sidecar( $name );

        $attachments = $sidecar['attachments'] ?? [];
        if ( ! empty( $attachments ) ) {
            $GLOBALS['__test_attachments'] = array_map(
                static fn( $url ): array => is_array( $url ) ? $url : [ 'url' => $url ],
                $attachments
            );
        }
        if ( isset( $sidecar['rendered'] ) && is_array( $sidecar['rendered'] ) ) {
            $GLOBALS['__test_rendered_shortcodes'] = $sidecar['rendered'];
        }
        if ( isset( $sidecar['rendered_widgets'] ) && is_array( $sidecar['rendered_widgets'] ) ) {
            $GLOBALS['__test_rendered_widgets'] = $sidecar['rendered_widgets'];
        }

        $result = ( new ConverterEngine() )->convert(
            [ 'content' => $content, 'meta' => $sidecar['meta'] ?? [] ],
            [ 'mode' => $sidecar['mode'] ?? 'direct', 'attachments' => $attachments ]
        );

        $expected = json_decode( (string) file_get_contents( $expected_file ), true );

        $this->assertEquals(
            $expected,
            [ 'divi' => $result['divi'], 'unsupported' => $result['unsupported'] ],
            "Converter output did not match fixtures/divi/{$name}.json"
        );
    }

    #[DataProvider( 'fixtureProvider' )]
    public function test_expected_output_is_a_valid_divi_5_document( string $name ): void {
        wbdc_test_reset_hooks();

        $expected_file = __DIR__ . "/../fixtures/divi/{$name}.json";
        $this->assertFileExists( $expected_file );

        $expected = json_decode( (string) file_get_contents( $expected_file ), true );
        $content  = ( new DiviBlockSerializer() )->serialize( $expected );

        // Headings keep the author's level (task-8-fix-round-1.md, R1): a page can
        // legitimately carry more than one <h1> after conversion, so that one check
        // is ignored here rather than the converter rewriting what the author wrote.
        $result = ( new Validator() )->validateContent( $content, [ Validator::E_MULTIPLE_H1 ] );

        $this->assertTrue(
            $result->isValid(),
            "fixtures/divi/{$name}.json is not a valid Divi 5 document:\n" . json_encode( $result->toArray(), JSON_PRETTY_PRINT )
        );
    }

    /** @return array<string, mixed> */
    private static function sidecar( string $name ): array {
        $file = __DIR__ . "/../fixtures/wpbakery/{$name}.json";
        if ( ! is_file( $file ) ) {
            return [];
        }
        return json_decode( (string) file_get_contents( $file ), true ) ?: [];
    }
}
