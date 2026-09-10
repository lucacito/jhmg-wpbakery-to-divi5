<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ContentFlattener;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

/**
 * `ContentFlattener` — the Divi blocks a `vc_tta_section` produced, rendered
 * back to the HTML an accordion item or a tab can hold (spec §6).
 *
 * The flattener is not exercised through a fixture because a golden pins the
 * whole page; these are the rules the HTML itself has to follow.
 */
final class ContentFlattenerTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    /** The HTML of one accordion item, converted from the section's shortcodes. */
    private function flatten( string $section_inner, array $options = [] ): array {
        $engine = new ConverterEngine();

        $result = $engine->convert(
            [ 'content' => '[vc_row][vc_column][vc_tta_accordion][vc_tta_section title="Item"]' . $section_inner . '[/vc_tta_section][/vc_tta_accordion][/vc_column][/vc_row]' ],
            array_merge( [ 'mode' => 'import' ], $options )
        );

        $accordion = $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'][0];

        return [
            'html'   => (string) ( $accordion['elements'][0]['settings']['content']['innerContent']['desktop']['value'] ?? '' ),
            'report' => $result['report'],
        ];
    }

    // -------------------------------------------------------------------------
    // The blocks that have an HTML form
    // -------------------------------------------------------------------------

    public function test_a_section_of_heading_text_image_and_a_nested_row_flattens_to_html(): void {
        $flat = $this->flatten(
            '[vc_custom_heading text="A title" font_container="tag:h3|text_align:left" use_theme_fonts="yes"]'
            . '[vc_column_text]Body copy.[/vc_column_text]'
            . '[vc_row_inner][vc_column_inner width="1/2"][vc_single_image image="7"][/vc_column_inner]'
            . '[vc_column_inner width="1/2"][vc_column_text]Right.[/vc_column_text][/vc_column_inner][/vc_row_inner]',
            [ 'attachments' => [ 7 => [ 'url' => 'https://example.com/photo.jpg' ] ] ]
        );

        $html = $flat['html'];

        $this->assertStringContainsString( '<h3>A title</h3>', $html );
        $this->assertStringContainsString( '<p>Body copy.</p>', $html );
        $this->assertStringContainsString( '<img src="https://example.com/photo.jpg"', $html );
        $this->assertStringContainsString( '<div class="wbdc-flat-row" style="display:flex;gap:30px">', $html );
        // 12_24 of the 24-column grid.
        $this->assertStringContainsString( '<div style="flex:0 0 50%">', $html );
        $this->assertStringContainsString( 'Right.', $html );
    }

    public function test_a_button_becomes_a_flat_anchor_and_a_separator_a_rule(): void {
        $flat = $this->flatten( '[vc_btn title="Start now" link="url:https%3A%2F%2Fexample.com%2Fgo"][vc_separator]' );

        $this->assertStringContainsString(
            '<a class="wbdc-flat-button" href="https://example.com/go">Start now</a>',
            $flat['html']
        );
        $this->assertStringContainsString( '<hr>', $flat['html'] );
    }

    public function test_a_linked_image_keeps_its_anchor(): void {
        $flat = $this->flatten(
            '[vc_single_image image="7" onclick="custom_link" link="url:https%3A%2F%2Fexample.com%2Fx|target:_blank"]',
            [ 'attachments' => [ 7 => [ 'url' => 'https://example.com/photo.jpg' ] ] ]
        );

        $this->assertStringContainsString( '<a href="https://example.com/x" target="_blank"><img', $flat['html'] );
    }

    public function test_a_theme_shortcode_placeholder_is_emitted_verbatim(): void {
        // A divi/code block from ThemeShortcodeConverter is already HTML
        // (amendment §2): it is representable and goes in as it stands.
        $flat = $this->flatten( '[nectar_highlighted_text]Hello[/nectar_highlighted_text]' );

        $this->assertStringContainsString( 'wbdc-unconverted-element', $flat['html'] );
        $this->assertStringNotContainsString( 'not representable inside this item', $flat['html'] );
    }

    // -------------------------------------------------------------------------
    // Everything else is named, not dropped
    // -------------------------------------------------------------------------

    public function test_a_module_with_no_html_form_becomes_a_comment_and_is_reported(): void {
        $flat = $this->flatten( '[vc_toggle title="Inner"]Body[/vc_toggle]' );

        $this->assertStringContainsString( '<!-- wbdc: divi/toggle not representable inside this item -->', $flat['html'] );

        $kinds = array_column( $flat['report']['not_carried_over'], 'kind' );
        $this->assertContains( 'layout', $kinds );

        $details = implode( "\n", array_column( $flat['report']['not_carried_over'], 'detail' ) );
        $this->assertStringContainsString( 'divi/toggle sits inside a tab or accordion item', $details );
    }

    public function test_an_unknown_block_name_is_named_in_the_comment(): void {
        $flattener = new ContentFlattener( new ConverterEngine() );

        $html = $flattener->toHtml( [ [ 'id' => 'x', 'name' => 'divi/gallery', 'settings' => [], 'elements' => [] ] ], 'node-1' );

        $this->assertSame( '<!-- wbdc: divi/gallery not representable inside this item -->', $html );
    }

    // -------------------------------------------------------------------------
    // What the flattener converts is not counted as converted
    // -------------------------------------------------------------------------

    public function test_flattened_children_are_not_counted_as_converted_modules(): void {
        $flat = $this->flatten(
            '[vc_custom_heading text="A title" font_container="tag:h3|text_align:left" use_theme_fonts="yes"]'
            . '[vc_column_text]Body copy.[/vc_column_text]'
            . '[vc_single_image image="7"]',
            [ 'attachments' => [ 7 => [ 'url' => 'https://example.com/photo.jpg' ] ] ]
        );

        $converted = $flat['report']['converted'];

        // The accordion and its one panel are real blocks in the page; the
        // heading, the text and the image were read for their HTML and thrown
        // away, so none of the three is counted.
        $this->assertSame( 1, $converted['accordion'] ?? 0 );
        $this->assertSame( 1, $converted['accordion-item'] ?? 0 );
        $this->assertArrayNotHasKey( 'heading', $converted );
        $this->assertArrayNotHasKey( 'text', $converted );
        $this->assertArrayNotHasKey( 'image', $converted );

        // …and the HTML still holds all three.
        $this->assertStringContainsString( '<h3>A title</h3>', $flat['html'] );
        $this->assertStringContainsString( '<img src="https://example.com/photo.jpg"', $flat['html'] );
    }

    public function test_a_suppressed_child_still_reports_what_it_could_not_carry(): void {
        // Only the count is suppressed: everything the source has to say is
        // still recorded while the scope is open.
        $flat = $this->flatten( '[vc_toggle title="Inner"]Body[/vc_toggle]' );

        $this->assertArrayNotHasKey( 'toggle', $flat['report']['converted'] );

        $layout = array_values( array_filter(
            $flat['report']['not_carried_over'],
            static fn( array $entry ): bool => $entry['kind'] === 'layout'
                && str_contains( $entry['detail'], 'divi/toggle sits inside a tab or accordion item' )
        ) );

        $this->assertCount( 1, $layout );
    }

    public function test_the_suppression_scope_is_a_depth_counter(): void {
        $engine = new ConverterEngine();

        $engine->withConvertedCountSuppressed( function () use ( $engine ): void {
            $engine->withConvertedCountSuppressed( static function (): void {} );
            // The inner scope closing must not un-suppress the outer one.
            $engine->logConverted( 'text' );
        } );

        $this->assertSame( [], $engine->getReport()['converted'] );

        $engine->logConverted( 'text' );
        $this->assertSame( [ 'text' => 1 ], $engine->getReport()['converted'] );
    }

    public function test_an_empty_block_list_is_an_empty_string(): void {
        $flattener = new ContentFlattener( new ConverterEngine() );

        $this->assertSame( '', $flattener->toHtml( [], 'node-1' ) );
    }
}
