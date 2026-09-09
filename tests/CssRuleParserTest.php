<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\CssRuleParser;

/**
 * WPBakery's "design options" panel stores a `css` shortcode attribute shaped
 * like `.vc_custom_<timestamp>{prop: value !important;...}`
 * (`vc_shortcode_custom_css_class()` in include/helpers/helpers.php pulls the
 * class name back out of that same string with a `.name{...}` regex).
 * A background image URL can carry a `?id=<attachment id>` suffix that
 * WPBakery's own WXR importer writes when it remaps attachment URLs on import
 * (`include/classes/core/shared-templates/importer/class-vc-wxr-parser-plugin.php`,
 * `remapAttachmentUrls()`); `imageRef()` reads that convention back.
 */
final class CssRuleParserTest extends TestCase {

    public function test_parse_extracts_class_and_strips_important(): void {
        $css = '.vc_custom_1600000000000{margin-top: 20px !important;background-image: url(https://x/y.jpg?id=106) !important;}';

        $this->assertSame(
            [
                'class'        => 'vc_custom_1600000000000',
                'declarations' => [
                    'margin-top'       => '20px',
                    'background-image' => 'url(https://x/y.jpg?id=106)',
                ],
            ],
            CssRuleParser::parse( $css )
        );
    }

    public function test_parse_strips_important_on_a_single_declaration(): void {
        $result = CssRuleParser::parse( '.c{color: red !important;}' );

        $this->assertSame( 'c', $result['class'] );
        $this->assertSame( [ 'color' => 'red' ], $result['declarations'] );
    }

    public function test_parse_splits_the_background_shorthand(): void {
        $result = CssRuleParser::parse( '.c{background: #fff url(x.png) no-repeat;}' );

        $this->assertSame(
            [ 'background-color' => '#fff', 'background-image' => 'url(x.png)' ],
            $result['declarations']
        );
    }

    public function test_parse_drops_star_and_underscore_ie_hacks(): void {
        $result = CssRuleParser::parse( '.c{*background-color: #fff;color: blue;}' );
        $this->assertSame( [ 'color' => 'blue' ], $result['declarations'] );

        $result = CssRuleParser::parse( '.c{_zoom: 1;width: 10px;}' );
        $this->assertSame( [ 'width' => '10px' ], $result['declarations'] );
    }

    public function test_parse_of_malformed_string_is_empty_declarations(): void {
        $this->assertSame(
            [ 'class' => '', 'declarations' => [] ],
            CssRuleParser::parse( 'not a css rule' )
        );
        $this->assertSame(
            [ 'class' => '', 'declarations' => [] ],
            CssRuleParser::parse( '' )
        );
    }

    /**
     * `vc_shortcode_custom_css_class()` matches `.name{...}` non-greedily and
     * stops at the first closing brace; a `css` value that carries two rules
     * (a hand-edited or theme-exported one) must not swallow the second.
     */
    public function test_parse_stops_at_the_first_closing_brace(): void {
        $result = CssRuleParser::parse( '.a{color:red !important;}.b{color:blue !important;}' );

        $this->assertSame( 'a', $result['class'] );
        $this->assertSame( [ 'color' => 'red' ], $result['declarations'] );
    }

    // --- imageRef -----------------------------------------------------------

    public function test_image_ref_splits_the_url_and_attachment_id(): void {
        $this->assertSame(
            [ 'url' => 'https://x/y.jpg', 'id' => 106 ],
            CssRuleParser::imageRef( 'url(https://x/y.jpg?id=106)' )
        );
    }

    public function test_image_ref_of_a_bare_id_has_no_url(): void {
        $this->assertSame(
            [ 'url' => '', 'id' => 123 ],
            CssRuleParser::imageRef( 'url(123)' )
        );
    }

    public function test_image_ref_without_an_id_query_leaves_id_null(): void {
        $this->assertSame(
            [ 'url' => 'https://x/y.jpg', 'id' => null ],
            CssRuleParser::imageRef( 'url(https://x/y.jpg)' )
        );
    }

    public function test_image_ref_of_malformed_value_is_empty(): void {
        $this->assertSame(
            [ 'url' => '', 'id' => null ],
            CssRuleParser::imageRef( 'not-a-url' )
        );
    }
}
