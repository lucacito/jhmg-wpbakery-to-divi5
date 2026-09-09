<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\PackedParams;

/**
 * WPBakery packs several parameter types into one string: `vc_link`
 * (`vc_build_link()`/`vc_parse_multi_attribute()`), `font_container` and
 * `google_fonts` (`_vc_font_container_parse_attributes()` /
 * `_vc_google_fonts_parse_attributes()`, both themselves
 * `vc_parse_multi_attribute()`), a JSON param group (progress bars),
 * `vc_value_from_safe()` and the raw-HTML base64 wrapper. Every case here was
 * traced to `include/helpers/helpers.php`, `include/params/vc_link/vc_link.php`,
 * `include/params/href/href.php`, `include/params/font_container/font_container.php`,
 * `include/params/google_fonts/google_fonts.php` and
 * `include/templates/shortcodes/vc_raw_html.php` of js_composer 9.0.1.
 */
final class PackedParamsTest extends TestCase {

    // --- link -----------------------------------------------------------

    public function test_link_decodes_a_full_packed_value(): void {
        $raw = 'url:http%3A%2F%2Fexample.com|title:Click%20here|target:_blank|rel:nofollow';

        $this->assertSame(
            [ 'url' => 'http://example.com', 'title' => 'Click here', 'target' => '_blank', 'rel' => 'nofollow' ],
            PackedParams::link( $raw )
        );
    }

    public function test_link_treats_double_and_single_pipes_as_empty(): void {
        $defaults = [ 'url' => '', 'title' => '', 'target' => '', 'rel' => '' ];

        $this->assertSame( $defaults, PackedParams::link( '||' ) );
        $this->assertSame( $defaults, PackedParams::link( '|' ) );
    }

    /**
     * `include/params/href/href.php` wraps a bare URL as `url:` . rawurlencode()
     * before it ever reaches vc_build_link() — a value with no `url:` key is
     * the whole href, not junk to discard.
     */
    public function test_link_treats_a_raw_url_without_url_key_as_the_url(): void {
        $this->assertSame(
            [ 'url' => 'https://example.com/page', 'title' => '', 'target' => '', 'rel' => '' ],
            PackedParams::link( 'https://example.com/page' )
        );
    }

    public function test_link_of_empty_string_is_all_defaults(): void {
        $this->assertSame(
            [ 'url' => '', 'title' => '', 'target' => '', 'rel' => '' ],
            PackedParams::link( '' )
        );
    }

    public function test_link_of_arbitrary_text_round_trips_as_the_url(): void {
        $this->assertSame(
            [ 'url' => 'not a url at all, just text', 'title' => '', 'target' => '', 'rel' => '' ],
            PackedParams::link( 'not a url at all, just text' )
        );
    }

    // --- fontContainer ----------------------------------------------------

    public function test_font_container_decodes_the_documented_fields(): void {
        $raw = 'tag:h2|text_align:left|font_size:32px|line_height:1.5|color:%23ffffff|font_family:Georgia';

        $this->assertSame(
            [
                'tag'         => 'h2',
                'font_size'   => '32px',
                'text_align'  => 'left',
                'line_height' => '1.5',
                'color'       => '#ffffff',
                'font_family' => 'Georgia',
            ],
            PackedParams::fontContainer( $raw )
        );
    }

    public function test_font_container_of_empty_string_is_empty_array(): void {
        $this->assertSame( [], PackedParams::fontContainer( '' ) );
    }

    public function test_font_container_of_unpacked_text_is_all_blank_fields(): void {
        $this->assertSame(
            [
                'tag'         => '',
                'font_size'   => '',
                'text_align'  => '',
                'line_height' => '',
                'color'       => '',
                'font_family' => '',
            ],
            PackedParams::fontContainer( 'not-packed-at-all' )
        );
    }

    // --- googleFonts --------------------------------------------------------

    public function test_google_fonts_decodes_family_weight_and_normal_style(): void {
        $raw = 'font_family:Montserrat%3Aregular%2C700|font_style:400%20regular%3A400%3Anormal';

        $this->assertSame(
            [ 'family' => 'Montserrat', 'weight' => '400', 'italic' => false ],
            PackedParams::googleFonts( $raw )
        );
    }

    public function test_google_fonts_decodes_bold_italic_style(): void {
        $raw = 'font_family:Exo|font_style:700%20bold%20italic%3A700%3Aitalic';

        $this->assertSame(
            [ 'family' => 'Exo', 'weight' => '700', 'italic' => true ],
            PackedParams::googleFonts( $raw )
        );
    }

    public function test_google_fonts_of_empty_string_is_blank(): void {
        $this->assertSame(
            [ 'family' => '', 'weight' => '', 'italic' => false ],
            PackedParams::googleFonts( '' )
        );
    }

    // --- paramGroup -----------------------------------------------------

    public function test_param_group_decodes_the_progress_bar_example(): void {
        $raw = '%5B%7B%22label%22%3A%22A%22%2C%22value%22%3A%2270%22%7D%5D';

        $this->assertSame(
            [ [ 'label' => 'A', 'value' => '70' ] ],
            PackedParams::paramGroup( $raw )
        );
    }

    public function test_param_group_of_empty_string_is_empty_array(): void {
        $this->assertSame( [], PackedParams::paramGroup( '' ) );
    }

    public function test_param_group_of_non_json_is_empty_array(): void {
        $this->assertSame( [], PackedParams::paramGroup( 'not json' ) );
    }

    // --- safeValue --------------------------------------------------------

    public function test_safe_value_decodes_the_e8_base64_wrapper(): void {
        $content = '<iframe src="x"></iframe>';
        $raw     = '#E-8_' . base64_encode( rawurlencode( $content ) );

        $this->assertSame( $content, PackedParams::safeValue( $raw ) );
    }

    public function test_safe_value_substitutes_backtick_forms(): void {
        $this->assertSame( '[text]', PackedParams::safeValue( '`{`text`}`' ) );
        $this->assertSame( 'a"b', PackedParams::safeValue( 'a``b' ) );
    }

    public function test_safe_value_without_the_prefix_passes_through(): void {
        $this->assertSame( 'plain', PackedParams::safeValue( 'plain' ) );
        $this->assertSame( '', PackedParams::safeValue( '' ) );
    }

    // --- rawHtml ----------------------------------------------------------

    public function test_raw_html_decodes_base64_of_rawurlencoded_html(): void {
        $raw = base64_encode( rawurlencode( '<p>Hi</p>' ) );

        $this->assertSame( '<p>Hi</p>', PackedParams::rawHtml( $raw ) );
    }

    public function test_raw_html_strips_tags_before_decoding(): void {
        $encoded = base64_encode( rawurlencode( '<p>Hi</p>' ) );

        $this->assertSame( '<p>Hi</p>', PackedParams::rawHtml( '<b>' . $encoded . '</b>' ) );
    }

    public function test_raw_html_of_empty_string_is_empty(): void {
        $this->assertSame( '', PackedParams::rawHtml( '' ) );
    }

    // --- sizeWithUnit -------------------------------------------------------

    public function test_size_with_unit_appends_the_default_unit(): void {
        $this->assertSame( '30px', PackedParams::sizeWithUnit( '30' ) );
    }

    public function test_size_with_unit_keeps_an_explicit_unit(): void {
        $this->assertSame( '2em', PackedParams::sizeWithUnit( '2em' ) );
        $this->assertSame( '50%', PackedParams::sizeWithUnit( '50%' ) );
        $this->assertSame( '1.5px', PackedParams::sizeWithUnit( '1.5' ) );
    }

    public function test_size_with_unit_honours_a_custom_default(): void {
        $this->assertSame( '10%', PackedParams::sizeWithUnit( '10', '%' ) );
    }

    public function test_size_with_unit_of_unparseable_text_is_empty(): void {
        $this->assertSame( '', PackedParams::sizeWithUnit( 'abc' ) );
        $this->assertSame( '', PackedParams::sizeWithUnit( '10xyz' ) );
        $this->assertSame( '', PackedParams::sizeWithUnit( '' ) );
    }

    // --- isOn ---------------------------------------------------------------

    public function test_is_on_recognises_the_documented_truthy_strings(): void {
        $this->assertTrue( PackedParams::isOn( 'true' ) );
        $this->assertTrue( PackedParams::isOn( 'yes' ) );
        $this->assertTrue( PackedParams::isOn( '1' ) );
        $this->assertTrue( PackedParams::isOn( 'on' ) );
        $this->assertTrue( PackedParams::isOn( 'YES' ) );
    }

    public function test_is_on_rejects_everything_else(): void {
        $this->assertFalse( PackedParams::isOn( 'no' ) );
        $this->assertFalse( PackedParams::isOn( 'off' ) );
        $this->assertFalse( PackedParams::isOn( 'false' ) );
        $this->assertFalse( PackedParams::isOn( '0' ) );
        $this->assertFalse( PackedParams::isOn( '' ) );
        $this->assertFalse( PackedParams::isOn( null ) );
        $this->assertFalse( PackedParams::isOn( [] ) );
    }

    public function test_is_on_accepts_native_bool_and_int(): void {
        $this->assertTrue( PackedParams::isOn( true ) );
        $this->assertTrue( PackedParams::isOn( 1 ) );
        $this->assertFalse( PackedParams::isOn( false ) );
        $this->assertFalse( PackedParams::isOn( 0 ) );
    }
}
