<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

/**
 * The document parser is the only place the shortcode string and the three
 * WPBakery post metas are read together, so the meta shapes `get_post_meta()`
 * can return are pinned here.
 */
final class WPBakeryDocumentParserTest extends TestCase {

    /**
     * WPBakery 9.0.1's own "Call to Action Page" default template, copied
     * verbatim from config/templates.php of references/js_composer.9.0.1.zip.
     */
    private const CALL_TO_ACTION_PAGE = '[vc_row full_width=""][vc_column width="1/1"][vc_column_text css_animation=""]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][vc_separator color="grey" align="align_center" style="" border_width="" el_width=""][/vc_column][/vc_row][vc_row full_width=""][vc_column width="1/2"][vc_single_image image="" alignment="" style="" border_color="grey" img_link_large="" img_link_target="_self" css_animation=""][/vc_column][vc_column width="1/2"][vc_column_text css_animation=""]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][vc_btn title="Read more" style="flat" shape="rounded" color="blue" size="md" align="inline" i_align="left" i_type="fontawesome" i_icon_fontawesome="fas fa-adjust" i_icon_openiconic="vc-oi vc-oi-dial" i_icon_typicons="typcn typcn-adjust-brightness" i_icon_entypo="entypo-icon entypo-icon-note" i_icon_linecons="vc_li vc_li-heart" button_block="" add_icon="" i_icon_pixelicons="vc_pixel_icon vc_pixel_icon-alert"][/vc_column][/vc_row][vc_row full_width=""][vc_column width="1/2"][vc_single_image image="" alignment="" style="" border_color="grey" img_link_large="" img_link_target="_self" css_animation=""][/vc_column][vc_column width="1/2"][vc_column_text css_animation=""]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][vc_btn title="Read more" style="flat" shape="rounded" color="blue" size="md" align="inline" i_align="left" i_type="fontawesome" i_icon_fontawesome="fas fa-adjust" i_icon_openiconic="vc-oi vc-oi-dial" i_icon_typicons="typcn typcn-adjust-brightness" i_icon_entypo="entypo-icon entypo-icon-note" i_icon_linecons="vc_li vc_li-heart" css_animation="" button_block="" add_icon="" i_icon_pixelicons="vc_pixel_icon vc_pixel_icon-alert"][/vc_column][/vc_row]';

    public function test_the_result_always_carries_the_full_shape(): void {
        $this->assertSame(
            [ 'nodes', 'custom_css', 'page_css', 'builder_flag' ],
            array_keys( WPBakeryDocumentParser::parse( '' ) )
        );
    }

    public function test_the_call_to_action_template_parses_to_three_rows(): void {
        $document = WPBakeryDocumentParser::parse( self::CALL_TO_ACTION_PAGE );

        $this->assertCount( 3, $document['nodes'] );
        $this->assertSame( [ 'vc_row', 'vc_row', 'vc_row' ], array_column( $document['nodes'], 'tag' ) );

        $first_column = $document['nodes'][0]['children'][0];
        $this->assertSame( 'vc_column', $first_column['tag'] );
        $this->assertSame( '1/1', $first_column['atts']['width'] );
        $this->assertSame( [ 'vc_column_text', 'vc_separator' ], array_column( $first_column['children'], 'tag' ) );
    }

    public function test_the_call_to_action_templates_separator_colour_is_normalised(): void {
        $document  = WPBakeryDocumentParser::parse( self::CALL_TO_ACTION_PAGE );
        $separator = $document['nodes'][0]['children'][0]['children'][1];

        $this->assertSame( 'vc_separator', $separator['tag'] );
        $this->assertSame( '#ebebeb', $separator['atts']['accent_color'] );
        $this->assertArrayNotHasKey( 'color', $separator['atts'] );
        $this->assertSame( [ 'vc_separator: color → accent_color' ], $separator['notes'] );
    }

    public function test_every_node_carries_notes_even_when_nothing_was_migrated(): void {
        $document = WPBakeryDocumentParser::parse( '[vc_row][vc_column][vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row]' );

        $this->assertSame( [], $document['nodes'][0]['notes'] );
        $this->assertSame(
            [ 'tag', 'atts', 'content', 'children', 'self_closing', 'offset', 'notes' ],
            array_keys( $document['nodes'][0] )
        );
    }

    public function test_a_deprecated_element_is_normalised_wherever_it_is_nested(): void {
        $document = WPBakeryDocumentParser::parse(
            '[vc_row][vc_column][vc_button href="https://x" title="Go" size="btn-mini"][/vc_column][/vc_row]'
        );

        $button = $document['nodes'][0]['children'][0]['children'][0];
        $this->assertSame( 'vc_btn', $button['tag'] );
        $this->assertSame( 'xs', $button['atts']['size'] );
        $this->assertSame( 'url:https%3A%2F%2Fx|title:Go', $button['atts']['link'] );
        $this->assertContains( 'vc_button → vc_btn', $button['notes'] );
    }

    public function test_text_between_elements_survives_as_a_text_node(): void {
        $document = WPBakeryDocumentParser::parse( 'Loose words[vc_row][/vc_row]' );

        $this->assertSame( '#text', $document['nodes'][0]['tag'] );
        $this->assertSame( 'Loose words', $document['nodes'][0]['text'] );
    }

    // --- metas ---------------------------------------------------------------

    public function test_the_metas_are_read_in_the_array_form_get_post_meta_returns(): void {
        $document = WPBakeryDocumentParser::parse( '[vc_row][/vc_row]', [
            '_wpb_shortcodes_custom_css' => [ '.vc_custom_1{color: red;}' ],
            '_wpb_post_custom_css'       => [ '.page{margin: 0;}' ],
            '_wpb_vc_js_status'          => [ 'true' ],
        ] );

        $this->assertSame( '.vc_custom_1{color: red;}', $document['custom_css'] );
        $this->assertSame( '.page{margin: 0;}', $document['page_css'] );
        $this->assertTrue( $document['builder_flag'] );
    }

    public function test_the_metas_are_read_in_the_plain_string_form_too(): void {
        $document = WPBakeryDocumentParser::parse( '[vc_row][/vc_row]', [
            '_wpb_shortcodes_custom_css' => '.vc_custom_1{color: red;}',
            '_wpb_post_custom_css'       => '.page{margin: 0;}',
            '_wpb_vc_js_status'          => 'true',
        ] );

        $this->assertSame( '.vc_custom_1{color: red;}', $document['custom_css'] );
        $this->assertSame( '.page{margin: 0;}', $document['page_css'] );
        $this->assertTrue( $document['builder_flag'] );
    }

    public function test_missing_metas_are_empty_strings_and_a_false_flag(): void {
        $document = WPBakeryDocumentParser::parse( '[vc_row][/vc_row]' );

        $this->assertSame( '', $document['custom_css'] );
        $this->assertSame( '', $document['page_css'] );
        $this->assertFalse( $document['builder_flag'] );
    }

    public function test_the_builder_flag_is_true_only_for_the_string_true(): void {
        foreach ( [ 'true' => true, 'false' => false, '1' => false, '' => false, 'TRUE' => false ] as $status => $expected ) {
            $document = WPBakeryDocumentParser::parse( '[vc_row][/vc_row]', [ '_wpb_vc_js_status' => [ $status ] ] );
            $this->assertSame( $expected, $document['builder_flag'], $status );
        }
    }

    // --- the caller's gate ----------------------------------------------------

    public function test_wpbakery_content_is_recognised_by_its_row_or_section(): void {
        $this->assertTrue( WPBakeryDocumentParser::isWPBakeryContent( '<p>Intro</p>[vc_row][/vc_row]' ) );
        $this->assertTrue( WPBakeryDocumentParser::isWPBakeryContent( '[vc_section full_width="stretch_row"][/vc_section]' ) );
        $this->assertTrue( WPBakeryDocumentParser::isWPBakeryContent( '[vc_row]' ) );
    }

    public function test_other_content_is_not_wpbakery_content(): void {
        $this->assertFalse( WPBakeryDocumentParser::isWPBakeryContent( '<!-- wp:paragraph --><p>Hi</p>' ) );
        $this->assertFalse( WPBakeryDocumentParser::isWPBakeryContent( '[vc_row_inner][/vc_row_inner]' ) );
        $this->assertFalse( WPBakeryDocumentParser::isWPBakeryContent( '[vc_column_text]Hi[/vc_column_text]' ) );
        $this->assertFalse( WPBakeryDocumentParser::isWPBakeryContent( '' ) );
    }

    public function test_a_non_wpbakery_document_parses_without_throwing(): void {
        $document = WPBakeryDocumentParser::parse( '<p>Just HTML</p>[gallery ids="1,2"]' );

        $this->assertFalse( $document['builder_flag'] );
        $this->assertSame( [ '#text', 'gallery' ], array_column( $document['nodes'], 'tag' ) );
    }
}
