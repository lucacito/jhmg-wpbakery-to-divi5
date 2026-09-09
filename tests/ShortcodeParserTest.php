<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Parsers\ShortcodeParser;

/**
 * The parser layer every later stage consumes, so the shape of a node and the
 * grammar's edge cases are pinned here rather than in the conversion tests.
 */
final class ShortcodeParserTest extends TestCase {

    /**
     * WPBakery 9.0.1's own "About section" default template, copied verbatim
     * from config/templates.php of references/js_composer.9.0.1.zip.
     */
    private const ABOUT_SECTION = '[vc_row][vc_column width="2/3"][vc_custom_heading text="This is custom heading element with Google Fonts" font_container="tag:h2|text_align:left" google_fonts="font_family:Montserrat%3Aregular%2C700|font_style:400%20regular%3A400%3Anormal"][vc_column_text]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_column][vc_column width="1/3"][vc_row_inner][vc_column_inner width="1/1"][vc_single_image border_color="grey" img_link_target="_self" style="vc_box_rounded"][vc_column_text]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_column_inner][/vc_row_inner][vc_separator color="grey"][vc_row_inner][vc_column_inner width="1/1"][vc_single_image border_color="grey" img_link_target="_self" style="vc_box_rounded"][vc_column_text]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_column_inner][/vc_row_inner][/vc_column][/vc_row]';

    /** @param array<string, mixed>[] $nodes */
    private function tags( array $nodes ): array {
        return array_column( $nodes, 'tag' );
    }

    // --- nesting ------------------------------------------------------------

    public function test_a_row_column_text_document_nests_three_levels(): void {
        $nodes = ShortcodeParser::parse( '[vc_row][vc_column width="1/2"][vc_column_text]<p>Hello</p>[/vc_column_text][/vc_column][/vc_row]' );

        $this->assertCount( 1, $nodes );
        $row = $nodes[0];
        $this->assertSame( 'vc_row', $row['tag'] );
        $this->assertSame( [], $row['atts'] );
        $this->assertFalse( $row['self_closing'] );
        $this->assertSame( 0, $row['offset'] );

        $this->assertCount( 1, $row['children'] );
        $column = $row['children'][0];
        $this->assertSame( 'vc_column', $column['tag'] );
        $this->assertSame( [ 'width' => '1/2' ], $column['atts'] );
        $this->assertSame( 0, $column['offset'], 'offsets are relative to the string parse() was handed' );

        $this->assertCount( 1, $column['children'] );
        $text = $column['children'][0];
        $this->assertSame( 'vc_column_text', $text['tag'] );
        $this->assertSame( '<p>Hello</p>', $text['content'] );
        $this->assertSame( [], $text['children'] );
    }

    public function test_a_node_always_carries_the_full_shape(): void {
        $nodes = ShortcodeParser::parse( '[vc_row][/vc_row]' );

        $this->assertSame(
            [ 'tag', 'atts', 'content', 'children', 'self_closing', 'offset' ],
            array_keys( $nodes[0] )
        );
    }

    // --- raw content --------------------------------------------------------

    public function test_column_text_keeps_its_html_and_leaves_inner_shortcodes_unparsed(): void {
        $nodes = ShortcodeParser::parse( '[vc_column_text]<p>Hi <strong>there</strong></p>[contact-form-7 id="5"][/vc_column_text]' );

        $this->assertCount( 1, $nodes );
        $this->assertSame( 'vc_column_text', $nodes[0]['tag'] );
        $this->assertSame( '<p>Hi <strong>there</strong></p>[contact-form-7 id="5"]', $nodes[0]['content'] );
        $this->assertSame( [], $nodes[0]['children'] );
    }

    public function test_an_empty_raw_tag_list_parses_the_same_content_as_elements(): void {
        $nodes = ShortcodeParser::parse( '[vc_column_text]<p>Hi</p>[contact-form-7 id="5"][/vc_column_text]', [] );

        $this->assertSame( [ '#text', 'contact-form-7' ], $this->tags( $nodes[0]['children'] ) );
        $this->assertSame( '<p>Hi</p>', $nodes[0]['children'][0]['text'] );
        $this->assertSame( [ 'id' => '5' ], $nodes[0]['children'][1]['atts'] );
    }

    public function test_raw_content_tags_lists_only_tags_whose_content_is_an_editor_field(): void {
        $raw = ShortcodeParser::RAW_CONTENT_TAGS;

        $this->assertSame( array_values( $raw ), $raw, 'RAW_CONTENT_TAGS is a flat list of tag names' );

        foreach ( [ 'vc_column_text', 'vc_message', 'vc_cta', 'vc_toggle', 'vc_hoverbox', 'vc_raw_html', 'vc_raw_js', 'vc_wp_text', 'vc_gutenberg', 'vc_pricing_table', 'vc_cta_button2' ] as $tag ) {
            $this->assertContains( $tag, $raw );
        }

        foreach ( [ 'vc_tta_section', 'vc_tab', 'vc_accordion_tab', 'vc_tta_accordion', 'vc_tta_tabs', 'vc_tta_tour', 'vc_tta_pageable', 'vc_tour', 'vc_accordion', 'vc_tabs', 'vc_section', 'vc_row', 'vc_row_inner', 'vc_column', 'vc_column_inner' ] as $tag ) {
            $this->assertNotContains( $tag, $raw, $tag . ' holds child elements, not an editor field' );
        }
    }

    public function test_a_tta_section_holding_a_row_is_parsed_as_elements(): void {
        $nodes = ShortcodeParser::parse( '[vc_tta_accordion][vc_tta_section title="One"][vc_row_inner][vc_column_inner width="1/1"][vc_column_text]Inside[/vc_column_text][/vc_column_inner][/vc_row_inner][/vc_tta_section][/vc_tta_accordion]' );

        $section = $nodes[0]['children'][0];
        $this->assertSame( 'vc_tta_section', $section['tag'] );
        $this->assertSame( [ 'title' => 'One' ], $section['atts'] );

        $this->assertSame( [ 'vc_row_inner' ], $this->tags( $section['children'] ) );
        $inner_column = $section['children'][0]['children'][0];
        $this->assertSame( 'vc_column_inner', $inner_column['tag'] );
        $this->assertSame( 'Inside', $inner_column['children'][0]['content'] );
    }

    // --- self closing -------------------------------------------------------

    public function test_an_image_without_a_close_tag_is_self_closing_and_the_next_element_is_a_sibling(): void {
        $nodes = ShortcodeParser::parse( '[vc_column][vc_single_image image="12"][vc_column_text]After[/vc_column_text][/vc_column]' );

        $children = $nodes[0]['children'];
        $this->assertSame( [ 'vc_single_image', 'vc_column_text' ], $this->tags( $children ) );

        $this->assertTrue( $children[0]['self_closing'] );
        $this->assertSame( '', $children[0]['content'] );
        $this->assertSame( [], $children[0]['children'] );

        $this->assertFalse( $children[1]['self_closing'] );
        $this->assertSame( 'After', $children[1]['content'] );
    }

    public function test_an_explicit_slash_closes_the_tag(): void {
        $nodes = ShortcodeParser::parse( '[vc_btn title="A"/]' );

        $this->assertTrue( $nodes[0]['self_closing'] );
        $this->assertSame( [ 'title' => 'A' ], $nodes[0]['atts'] );
    }

    public function test_an_empty_content_pair_is_not_self_closing(): void {
        $nodes = ShortcodeParser::parse( '[vc_btn title="A"][/vc_btn]' );

        $this->assertCount( 1, $nodes );
        $this->assertFalse( $nodes[0]['self_closing'] );
        $this->assertSame( '', $nodes[0]['content'] );
        $this->assertSame( [], $nodes[0]['children'] );
    }

    public function test_content_runs_to_the_first_closing_tag(): void {
        $nodes = ShortcodeParser::parse( '[vc_toggle]one[/vc_toggle]two[/vc_toggle]' );

        $this->assertSame( [ 'vc_toggle', '#text' ], $this->tags( $nodes ) );
        $this->assertSame( 'one', $nodes[0]['content'] );
        $this->assertSame( 'two[/vc_toggle]', $nodes[1]['text'] );
    }

    // --- escaping and text --------------------------------------------------

    public function test_a_doubled_bracket_is_literal_text(): void {
        $nodes = ShortcodeParser::parse( '[[vc_row]]' );

        $this->assertCount( 1, $nodes );
        $this->assertSame( '#text', $nodes[0]['tag'] );
        $this->assertSame( '[vc_row]', $nodes[0]['text'] );
        $this->assertSame( 0, $nodes[0]['offset'] );
    }

    public function test_stray_text_between_rows_becomes_a_text_node(): void {
        $nodes = ShortcodeParser::parse( '[vc_row][/vc_row]Stray[vc_row][/vc_row]' );

        $this->assertSame( [ 'vc_row', '#text', 'vc_row' ], $this->tags( $nodes ) );
        $this->assertSame( 'Stray', $nodes[1]['text'] );
        $this->assertSame( [ 0, 17, 22 ], array_column( $nodes, 'offset' ) );
    }

    public function test_whitespace_between_elements_is_dropped(): void {
        $nodes = ShortcodeParser::parse( "\n[vc_row][/vc_row]\n\n  [vc_row][/vc_row]\n" );

        $this->assertSame( [ 'vc_row', 'vc_row' ], $this->tags( $nodes ) );
    }

    public function test_a_document_without_shortcodes_is_one_text_node(): void {
        $this->assertSame( [], ShortcodeParser::parse( "   \n\t " ) );
        $this->assertSame( [], ShortcodeParser::parse( '' ) );

        $nodes = ShortcodeParser::parse( 'Just words.' );
        $this->assertSame( [ '#text' ], $this->tags( $nodes ) );
        $this->assertSame( 'Just words.', $nodes[0]['text'] );
    }

    // --- attributes ---------------------------------------------------------

    public function test_entity_quotes_are_normalised_before_matching(): void {
        $this->assertSame( [ 'title' => 'Hi' ], ShortcodeParser::parseAtts( 'title=&#8220;Hi&#8221;' ) );
        $this->assertSame( [ 'title' => 'Hi' ], ShortcodeParser::parseAtts( 'title=&#8243;Hi&#8243;' ) );
        $this->assertSame( [ 'title' => 'Hi' ], ShortcodeParser::parseAtts( 'title=&#8216;Hi&#8217;' ) );

        $nodes = ShortcodeParser::parse( '[vc_custom_heading text=&#8220;Hi there&#8221; el_class=&#8220;lead&#8221;]' );
        $this->assertSame( [ 'text' => 'Hi there', 'el_class' => 'lead' ], $nodes[0]['atts'] );
    }

    public function test_single_quoted_and_unquoted_values(): void {
        $this->assertSame(
            [ 'title' => 'Hi there', 'color' => 'blue', 'size' => 'lg' ],
            ShortcodeParser::parseAtts( "title='Hi there' color=blue size=lg" )
        );
    }

    public function test_a_positional_attribute_lands_at_index_zero(): void {
        $this->assertSame( [ 0 => 'Click me', 'title' => 'A' ], ShortcodeParser::parseAtts( '"Click me" title="A"' ) );
        $this->assertSame( [ 0 => 'bare' ], ShortcodeParser::parseAtts( 'bare' ) );
    }

    public function test_keys_are_lowercased_and_values_are_not(): void {
        $this->assertSame(
            [ 'title' => 'Buy Now', 'el_class' => 'Lead' ],
            ShortcodeParser::parseAtts( 'TITLE="Buy Now" El_Class="Lead"' )
        );
    }

    public function test_non_breaking_and_zero_width_spaces_are_folded_to_a_space(): void {
        $this->assertSame(
            [ 'a' => '1', 'b' => '2' ],
            ShortcodeParser::parseAtts( "a=\"1\"\u{00a0}\u{200b}b=\"2\"" )
        );
    }

    public function test_backslash_escapes_are_stripped_like_wordpress_does(): void {
        $this->assertSame( [ 'css' => '.a{content:"x"}' ], ShortcodeParser::parseAtts( 'css=\'.a{content:\"x\"}\'' ) );
    }

    public function test_an_unclosed_html_element_in_a_value_is_rejected(): void {
        $this->assertSame( [ 'title' => '' ], ShortcodeParser::parseAtts( 'title="<p unclosed"' ) );
        $this->assertSame( [ 'title' => '<b>ok</b>' ], ShortcodeParser::parseAtts( 'title="<b>ok</b>"' ) );
    }

    public function test_an_unparseable_attribute_string_is_an_empty_array(): void {
        $this->assertSame( [], ShortcodeParser::parseAtts( '' ) );
        $this->assertSame( [], ShortcodeParser::parseAtts( '   ' ) );
    }

    // --- unicode ------------------------------------------------------------

    public function test_unicode_survives_in_content_and_attributes(): void {
        $nodes = ShortcodeParser::parse( '[vc_column_text el_class="café"]<p>Un café ☕ à Montréal 🎉</p>[/vc_column_text]' );

        $this->assertSame( [ 'el_class' => 'café' ], $nodes[0]['atts'] );
        $this->assertSame( '<p>Un café ☕ à Montréal 🎉</p>', $nodes[0]['content'] );
    }

    public function test_offsets_are_byte_offsets_into_the_string_that_was_parsed(): void {
        $content = '[vc_row]☕[vc_column][/vc_column][/vc_row]';
        $nodes   = ShortcodeParser::parse( $content );

        $this->assertSame( 0, $nodes[0]['offset'] );
        $children = $nodes[0]['children'];
        $this->assertSame( [ '#text', 'vc_column' ], $this->tags( $children ) );
        $this->assertSame( 0, $children[0]['offset'] );
        $this->assertSame( 3, $children[1]['offset'], '☕ is three bytes' );
    }

    // --- the real template --------------------------------------------------

    public function test_the_about_section_default_template(): void {
        $nodes = ShortcodeParser::parse( self::ABOUT_SECTION );

        $this->assertSame( [ 'vc_row' ], $this->tags( $nodes ) );

        $columns = $nodes[0]['children'];
        $this->assertSame( [ 'vc_column', 'vc_column' ], $this->tags( $columns ) );
        $this->assertSame( [ 'width' => '2/3' ], $columns[0]['atts'] );
        $this->assertSame( [ 'width' => '1/3' ], $columns[1]['atts'] );

        $this->assertSame( [ 'vc_custom_heading', 'vc_column_text' ], $this->tags( $columns[0]['children'] ) );
        $this->assertTrue( $columns[0]['children'][0]['self_closing'] );
        $this->assertSame(
            'font_family:Montserrat%3Aregular%2C700|font_style:400%20regular%3A400%3Anormal',
            $columns[0]['children'][0]['atts']['google_fonts']
        );
        $this->assertStringStartsWith( 'I am text block.', $columns[0]['children'][1]['content'] );

        $this->assertSame( [ 'vc_row_inner', 'vc_separator', 'vc_row_inner' ], $this->tags( $columns[1]['children'] ) );
        $this->assertTrue( $columns[1]['children'][1]['self_closing'] );
        $this->assertSame( [ 'color' => 'grey' ], $columns[1]['children'][1]['atts'] );

        foreach ( [ 0, 2 ] as $index ) {
            $inner_row = $columns[1]['children'][ $index ];
            $this->assertSame( [ 'vc_column_inner' ], $this->tags( $inner_row['children'] ) );

            $inner_column = $inner_row['children'][0];
            $this->assertSame( [ 'width' => '1/1' ], $inner_column['atts'] );
            $this->assertSame( [ 'vc_single_image', 'vc_column_text' ], $this->tags( $inner_column['children'] ) );
            $this->assertTrue( $inner_column['children'][0]['self_closing'] );
            $this->assertSame( 'vc_box_rounded', $inner_column['children'][0]['atts']['style'] );
            $this->assertSame( [], $inner_column['children'][1]['children'] );
        }
    }
}
