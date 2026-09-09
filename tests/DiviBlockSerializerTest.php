<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Exporters\DiviBlockSerializer;

/**
 * The block document Divi 5 stores in `post_content`.
 */
final class DiviBlockSerializerTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    public function test_it_wraps_everything_in_a_placeholder_and_stamps_builder_version(): void {
        $out = ( new DiviBlockSerializer() )->serialize( [ 'divi' => [ 'elements' => [
            [ 'name' => 'divi/section', 'settings' => [], 'elements' => [
                [ 'name' => 'divi/row', 'settings' => [], 'elements' => [
                    [ 'name' => 'divi/column', 'settings' => [], 'elements' => [
                        [ 'name' => 'divi/heading', 'settings' => [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Hi <b>there</b>' ] ] ] ], 'elements' => [] ],
                    ] ],
                ] ],
            ] ],
        ] ] ] );

        $this->assertStringStartsWith( '<!-- wp:divi/placeholder -->', $out );
        $this->assertStringEndsWith( '<!-- /wp:divi/placeholder -->', $out );
        $this->assertStringContainsString( '<!-- wp:divi/section {"builderVersion":"5.0.0"} -->', $out );
        // HTML inside attributes is stored as JSON unicode escapes, exactly as Divi 5 saves it.
        $this->assertStringContainsString( '<!-- wp:divi/heading {"builderVersion":"5.0.0","title":{"innerContent":{"desktop":{"value":"Hi \\u003Cb\\u003Ethere\\u003C/b\\u003E"}}}} /-->', $out );
        $this->assertStringContainsString( '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->', $out );
    }

    public function test_a_loose_module_under_a_section_is_wrapped_in_row_and_column(): void {
        $out = ( new DiviBlockSerializer() )->serialize( [ [ 'name' => 'divi/section', 'settings' => [], 'elements' => [
            [ 'name' => 'divi/text', 'settings' => [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'x' ] ] ] ], 'elements' => [] ],
        ] ] ] );

        $this->assertMatchesRegularExpression( '#wp:divi/section .*?wp:divi/row .*?wp:divi/column .*?wp:divi/text#', $out );
    }

    public function test_a_loose_module_under_a_row_is_wrapped_in_a_column(): void {
        $out = ( new DiviBlockSerializer() )->serialize( [ [ 'name' => 'divi/row', 'settings' => [], 'elements' => [
            [ 'name' => 'divi/code', 'settings' => [], 'elements' => [] ],
        ] ] ] );

        $this->assertMatchesRegularExpression( '#wp:divi/row .*?wp:divi/column .*?wp:divi/code#', $out );
    }

    public function test_a_nested_row_inside_a_column_survives(): void {
        $out = ( new DiviBlockSerializer() )->serialize( [ [ 'name' => 'divi/column', 'settings' => [], 'elements' => [
            [ 'name' => 'divi/row', 'settings' => [], 'elements' => [
                [ 'name' => 'divi/column', 'settings' => [], 'elements' => [] ],
            ] ],
        ] ] ] );

        $this->assertStringContainsString( '<!-- wp:divi/column {"builderVersion":"5.0.0"} --><!-- wp:divi/row', $out );
        $this->assertStringContainsString( '<!-- /wp:divi/row --><!-- /wp:divi/column -->', $out );
    }

    public function test_the_running_divi_version_is_used_when_defined(): void {
        $out = ( new DiviBlockSerializer() )->serialize( [ [ 'name' => 'divi/code', 'settings' => [], 'elements' => [] ] ] );

        // ET_BUILDER_VERSION is not defined in the test bootstrap, so the
        // minimum supported version stands in.
        $this->assertStringContainsString( '"builderVersion":"5.0.0"', $out );
    }
}
