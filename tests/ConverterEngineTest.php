<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Parsers\NodeTree;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

/**
 * The engine's own contract: what it accepts, what it always returns, and the
 * two things nothing else can do — report every key the admin panel reads, and
 * make sure no node ever leaves without a block.
 */
final class ConverterEngineTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    private function convert( string $content, array $meta = [], array $options = [] ): array {
        return ( new ConverterEngine() )->convert( [ 'content' => $content, 'meta' => $meta ], $options );
    }

    public function test_a_content_string_converts_to_sections(): void {
        $result = $this->convert( '[vc_row][vc_column][vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row]' );

        $this->assertCount( 1, $result['divi']['elements'] );
        $this->assertSame( 'divi/section', $result['divi']['elements'][0]['name'] );
        $this->assertSame( 'divi/row', $result['divi']['elements'][0]['elements'][0]['name'] );
        $this->assertSame( 'divi/column', $result['divi']['elements'][0]['elements'][0]['elements'][0]['name'] );
    }

    public function test_a_prebuilt_tree_converts_the_same_way(): void {
        $document = WPBakeryDocumentParser::parse( '[vc_row][vc_column][/vc_column][/vc_row]' );
        $tree     = NodeTree::build( $document['nodes'] );

        $result = ( new ConverterEngine() )->convert( [ 'roots' => $tree['roots'] ] );

        $this->assertSame( 'divi/section', $result['divi']['elements'][0]['name'] );
    }

    public function test_parser_output_converts_directly(): void {
        // scripts/docker/convert-run.php hands the engine the parser's own
        // return value; that has to keep working.
        $document = WPBakeryDocumentParser::parse( '[vc_row][vc_column][/vc_column][/vc_row]' );

        $result = ( new ConverterEngine() )->convert( $document );

        $this->assertSame( 'divi/section', $result['divi']['elements'][0]['name'] );
    }

    public function test_the_report_carries_every_key(): void {
        $result = $this->convert( '[vc_row][vc_column][/vc_column][/vc_row]' );

        foreach ( [
            'converted', 'approximate', 'approximate_matches', 'warnings', 'skipped_settings',
            'unresolved_globals', 'not_carried_over', 'theme_elements', 'static_copies',
            'custom_css_carried', 'unresolved_media', 'text_nodes', 'bracketed_text', 'quality',
        ] as $key ) {
            $this->assertArrayHasKey( $key, $result['report'], "report is missing '{$key}'" );
        }

        $this->assertArrayHasKey( 'module_coverage', $result['report']['quality'] );
        $this->assertArrayHasKey( 'settings_issues', $result['report']['quality'] );
    }

    public function test_page_custom_css_is_reported_not_emitted(): void {
        $result = $this->convert(
            '[vc_row][vc_column][/vc_column][/vc_row]',
            [ WPBakeryDocumentParser::PAGE_CSS_META => '.page { color: red; }' ]
        );

        $entries = array_values( array_filter(
            $result['report']['not_carried_over'],
            static fn( array $e ): bool => $e['node_id'] === 'page'
        ) );

        $this->assertCount( 1, $entries );
        $this->assertSame( 'custom_code', $entries[0]['kind'] );
        $this->assertStringNotContainsString( 'color: red', json_encode( $result['divi'] ) );
    }

    public function test_a_core_tag_with_no_handler_is_unsupported_and_gets_a_placeholder(): void {
        // `vc_gitem_post_date` is one of the grid-item elements: WPBakery ships
        // it, and this converter has no handler for it yet.
        $result = $this->convert( '[vc_row][vc_column][vc_gitem_post_date][/vc_column][/vc_row]' );

        $this->assertSame( [ [ 'id' => 'vc_gitem_post_date-1', 'tag' => 'vc_gitem_post_date' ] ], $result['unsupported'] );

        $column = $result['divi']['elements'][0]['elements'][0]['elements'][0];
        $this->assertSame( 'divi/code', $column['elements'][0]['name'] );
        $this->assertStringContainsString( 'vc_gitem_post_date', $column['elements'][0]['settings']['content']['innerContent']['desktop']['value'] );
    }

    public function test_a_theme_tag_is_never_unsupported(): void {
        $result = $this->convert( '[vc_row][vc_column][nectar_btn text="Go"][/vc_column][/vc_row]' );

        $this->assertSame( [], $result['unsupported'] );
        $this->assertSame( [ 'Salient' => 1 ], $result['report']['theme_elements'] );
    }

    public function test_an_unregistered_theme_tag_in_direct_mode_is_a_placeholder_not_a_static_copy(): void {
        // do_shortcode() on a tag nothing registered returns its input
        // untouched — non-empty, so BaseWPBakeryConverter::staticCopy() must
        // gate the call on shortcode_exists() rather than read that as a
        // render (task-8-fix-round-1.md, #7). Nothing seeds
        // __test_rendered_shortcodes, so nectar_btn is not "registered" here.
        $result = $this->convert(
            '[vc_row][vc_column][nectar_btn text="Go"][/vc_column][/vc_row]',
            [],
            [ 'mode' => 'direct', 'render_shortcodes' => true ]
        );

        $this->assertSame( [], $result['report']['static_copies'] );

        $column = $result['divi']['elements'][0]['elements'][0]['elements'][0];
        $html   = (string) $column['elements'][0]['settings']['content']['innerContent']['desktop']['value'];
        $this->assertStringContainsString( 'wbdc-unconverted-element', $html, 'an unregistered tag falls back to the labelled placeholder' );
        $this->assertStringContainsString( 'nectar_btn', $html );
    }

    /**
     * A placeholder is built with `esc_html()`, and core's `esc_html()` does
     * not double-encode: an entity the source already carried survives as
     * itself. Escaping it again would put the literal text `D&amp;G` on the
     * page where the WPBakery original read `D&G`.
     */
    public function test_a_placeholder_does_not_escape_an_entity_the_source_already_carried(): void {
        $result = $this->convert(
            '[vc_row][vc_column][nectar_btn]It was a pleasure to work for D&amp;G. We&#039;re done.[/nectar_btn][/vc_column][/vc_row]'
        );

        $column = $result['divi']['elements'][0]['elements'][0]['elements'][0];
        $html   = (string) $column['elements'][0]['settings']['content']['innerContent']['desktop']['value'];

        $this->assertStringContainsString( 'D&amp;G', $html );
        $this->assertStringNotContainsString( '&amp;amp;', $html );
        $this->assertStringContainsString( 'We&#039;re', $html );
    }

    public function test_mode_defaults_to_import(): void {
        $engine = new ConverterEngine();
        $engine->convert( [ 'content' => '[vc_row][vc_column][/vc_column][/vc_row]' ] );

        $this->assertSame( 'import', $engine->options()['mode'] );
        $this->assertFalse( $engine->options()['render_shortcodes'] );
    }

    public function test_direct_mode_renders_shortcodes_by_default(): void {
        $engine = new ConverterEngine();
        $engine->convert( [ 'content' => '[vc_row][vc_column][/vc_column][/vc_row]' ], [ 'mode' => 'direct' ] );

        $this->assertTrue( $engine->options()['render_shortcodes'] );
    }

    public function test_stray_text_becomes_a_text_module_and_is_counted(): void {
        $result = $this->convert( '[vc_row][vc_column]Loose words[/vc_column][/vc_row]' );

        $column = $result['divi']['elements'][0]['elements'][0]['elements'][0];
        $this->assertSame( 'divi/text', $column['elements'][0]['name'] );
        $this->assertSame( '<p>Loose words</p>', $column['elements'][0]['settings']['content']['innerContent']['desktop']['value'] );
        $this->assertSame( 1, $result['report']['text_nodes'] );
    }

    public function test_a_bare_bracket_token_is_kept_as_text(): void {
        $result = $this->convert( '[vc_row][vc_column][1][/vc_column][/vc_row]' );

        $column = $result['divi']['elements'][0]['elements'][0]['elements'][0];
        $this->assertSame( 'divi/text', $column['elements'][0]['name'] );
        $this->assertStringContainsString( '[1]', $column['elements'][0]['settings']['content']['innerContent']['desktop']['value'] );
        $this->assertSame( [], $result['report']['theme_elements'] );
        $this->assertContains( 'bracketed text kept: [1]', $result['report']['warnings'] );
    }

    public function test_an_empty_row_is_reported_not_dropped(): void {
        $result = $this->convert( '[vc_row][/vc_row]' );

        $row = $result['divi']['elements'][0]['elements'][0];
        $this->assertSame( 'divi/row', $row['name'] );
        $this->assertSame( 'divi/column', $row['elements'][0]['name'] );
        $this->assertNotEmpty( array_filter(
            $result['report']['warnings'],
            static fn( string $w ): bool => str_contains( $w, 'Empty row' )
        ) );
    }

    public function test_converted_counts_the_structure(): void {
        $result = $this->convert( '[vc_row][vc_column width="1/2"][/vc_column][vc_column width="1/2"][/vc_column][/vc_row]' );

        $this->assertSame( 1, $result['report']['converted']['section'] ?? 0 );
        $this->assertSame( 1, $result['report']['converted']['row'] ?? 0 );
        $this->assertSame( 2, $result['report']['converted']['column'] ?? 0 );
    }

    // -------------------------------------------------------------------------
    // Sections: stretch, fill, and a handler that throws
    // -------------------------------------------------------------------------

    public function test_a_stretched_section_stretches_the_rows_inside_it(): void {
        $result = $this->convert(
            '[vc_section full_width="stretch_row_content"][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
        );

        $sizing = $result['divi']['elements'][0]['elements'][0]['settings']['module']['decoration']['sizing']['desktop']['value'];
        $margin = $result['divi']['elements'][0]['elements'][0]['settings']['module']['decoration']['spacing']['desktop']['value']['margin'];

        $this->assertSame( '100%', $sizing['width'] );
        $this->assertSame( 'none', $sizing['maxWidth'] );
        $this->assertSame( '0px', $margin['left'], 'a stretched section leaves its rows no bleed' );
    }

    public function test_a_stretched_section_is_not_reported_as_a_skipped_setting(): void {
        $result = $this->convert(
            '[vc_section full_width="stretch_row_content"][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
        );

        $this->assertSame( [], $result['report']['skipped_settings'] );
    }

    public function test_a_plain_stretch_row_section_leaves_its_rows_alone(): void {
        $result = $this->convert(
            '[vc_section full_width="stretch_row"][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
        );

        $sizing = $result['divi']['elements'][0]['elements'][0]['settings']['module']['decoration']['sizing']['desktop']['value'];

        $this->assertSame( 'calc(var(--content-width, 80%) + 30px)', $sizing['width'] );
    }

    public function test_a_no_spaces_section_zeroes_its_column_side_padding(): void {
        $result = $this->convert(
            '[vc_section full_width="stretch_row_content_no_spaces"][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
        );

        $padding = $result['divi']['elements'][0]['elements'][0]['elements'][0]['settings']['module']['decoration']['spacing']['desktop']['value']['padding'];

        $this->assertSame( '0px', $padding['left'] );
        $this->assertSame( '0px', $padding['right'] );
    }

    public function test_a_filled_section_and_the_one_after_it_take_35px_of_top_padding(): void {
        $result = $this->convert(
            '[vc_section css=".vc_custom_1{background-color: #eee !important;}"][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
            . '[vc_section][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
            . '[vc_section][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
        );

        $top = static fn( array $section ): string => $section['settings']['module']['decoration']['spacing']['desktop']['value']['padding']['top'];

        $this->assertSame( '35px', $top( $result['divi']['elements'][0] ), 'the section that paints' );
        $this->assertSame( '35px', $top( $result['divi']['elements'][1] ), 'the section right after it' );
        $this->assertSame( '0px', $top( $result['divi']['elements'][2] ), 'and no further' );
    }

    public function test_a_section_that_sets_its_own_padding_top_keeps_it(): void {
        $result = $this->convert(
            '[vc_section css=".vc_custom_1{padding-top: 8px !important;background-color: #eee !important;}"][vc_row][vc_column][/vc_column][/vc_row][/vc_section]'
        );

        $padding = $result['divi']['elements'][0]['settings']['module']['decoration']['spacing']['desktop']['value']['padding'];

        $this->assertSame( '8px', $padding['top'] );
    }

    public function test_a_handler_that_throws_costs_one_node_not_the_page(): void {
        $engine = new ConverterEngine();
        $engine->registry()->registerElement( 'vc_flickr', ThrowingProbeConverter::class );

        $result = $engine->convert( [
            // The gallery names an image: an empty one converts to nothing at
            // all (Task 9), and this test needs a second block to look at.
            'content' => '[vc_row][vc_column][vc_flickr][vc_gallery images="7"][/vc_column][/vc_row]',
        ] );

        // The page still converts, and the rest of it is untouched.
        $blocks = $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'];
        $this->assertCount( 2, $blocks );
        $this->assertSame( 'divi/code', $blocks[0]['name'] );
        $this->assertStringContainsString( 'vc_flickr', $blocks[0]['settings']['content']['innerContent']['desktop']['value'] );

        $this->assertNotEmpty( array_filter(
            $result['report']['warnings'],
            static fn( string $w ): bool => str_contains( $w, 'handler ThrowingProbeConverter failed on vc_flickr-1 (vc_flickr)' )
                && str_contains( $w, 'RuntimeException: the plugin exploded' )
        ) );

        $errors = array_values( array_filter(
            $result['report']['not_carried_over'],
            static fn( array $e ): bool => $e['kind'] === 'error'
        ) );
        $this->assertCount( 1, $errors );
        $this->assertSame( 'vc_flickr-1', $errors[0]['node_id'] );
    }

    // -------------------------------------------------------------------------
    // Row layout
    // -------------------------------------------------------------------------

    public function test_content_placement_aligns_the_columns_when_the_row_is_not_equal_height(): void {
        $result = $this->convert( '[vc_row content_placement="middle"][vc_column][/vc_column][/vc_row]' );

        $row    = $result['divi']['elements'][0]['elements'][0];
        $column = $row['elements'][0];

        $this->assertSame( 'center', $row['settings']['module']['decoration']['layout']['desktop']['value']['alignItems'] );
        $this->assertSame( 'center', $column['settings']['module']['decoration']['layout']['desktop']['value']['justifyContent'] );
    }

    public function test_equal_height_wins_over_content_placement_on_the_row(): void {
        $result = $this->convert( '[vc_row content_placement="middle" equal_height="yes"][vc_column][/vc_column][/vc_row]' );

        $row = $result['divi']['elements'][0]['elements'][0];

        $this->assertSame( 'stretch', $row['settings']['module']['decoration']['layout']['desktop']['value']['alignItems'] );
        // The column half of `content_placement` still applies.
        $this->assertSame( 'center', $row['elements'][0]['settings']['module']['decoration']['layout']['desktop']['value']['justifyContent'] );
    }

    public function test_rtl_reverse_is_written_only_on_an_rtl_site(): void {
        $GLOBALS['__test_is_rtl'] = true;
        $result = $this->convert( '[vc_row rtl_reverse="yes"][vc_column][/vc_column][/vc_row]' );

        $this->assertSame(
            'row-reverse',
            $result['divi']['elements'][0]['elements'][0]['settings']['module']['decoration']['layout']['desktop']['value']['flexDirection']
        );
        $this->assertSame( [], array_filter(
            $result['report']['not_carried_over'],
            static fn( array $e ): bool => str_contains( $e['detail'], 'rtl_reverse' )
        ) );
    }

    public function test_rtl_reverse_is_reported_on_a_left_to_right_site(): void {
        $GLOBALS['__test_is_rtl'] = false;
        $result = $this->convert( '[vc_row rtl_reverse="yes"][vc_column][/vc_column][/vc_row]' );

        $layout = $result['divi']['elements'][0]['elements'][0]['settings']['module']['decoration']['layout']['desktop']['value'] ?? [];
        $this->assertArrayNotHasKey( 'flexDirection', $layout );

        $entries = array_values( array_filter(
            $result['report']['not_carried_over'],
            static fn( array $e ): bool => str_contains( $e['detail'], 'rtl_reverse' )
        ) );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'layout', $entries[0]['kind'] );
        $this->assertSame( 'vc_row-1', $entries[0]['node_id'] );
    }

    // -------------------------------------------------------------------------
    // The registry, and the base-converter helpers Tasks 8 and 9 build on
    // -------------------------------------------------------------------------

    public function test_a_registered_element_handler_takes_over(): void {
        $engine = new ConverterEngine();
        $engine->registry()->registerElement( 'vc_flickr', ProbeConverter::class );

        $result = $engine->convert( [ 'content' => '[vc_row][vc_column][vc_flickr title="Gallery"][/vc_column][/vc_row]' ] );

        $this->assertSame( [], $result['unsupported'] );
        $this->assertSame( 'divi/code', $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'][0]['name'] );
        $this->assertSame( 1, $result['report']['converted']['probe'] );
    }

    public function test_an_approximate_handler_is_reported_separately(): void {
        $engine = new ConverterEngine();
        $engine->registry()->registerElement( 'vc_flickr', ProbeConverter::class, true );

        $result = $engine->convert( [ 'content' => '[vc_row][vc_column][vc_flickr][/vc_column][/vc_row]' ] );

        $this->assertSame( [ [ 'node_id' => 'vc_flickr-1', 'tag' => 'vc_flickr', 'matched_to' => 'ProbeConverter' ] ], $result['report']['approximate_matches'] );
        $this->assertSame( 1, $result['report']['approximate']['probe'] );
        $this->assertArrayNotHasKey( 'probe', $result['report']['converted'] );
        // The registry ships approximate handlers of its own (Task 8), so this
        // asserts the tag joined them rather than that it is the only one.
        $this->assertContains( 'vc_flickr', $engine->registry()->approximateTags() );
    }

    /**
     * "Approximate" belongs to the node the registry flagged, not to
     * everything underneath it. `ult_content_box` is registered approximate
     * and converts its children in place: the button inside it is an exact
     * `divi/button` and is counted as one, or the summary tells the reader
     * "2 of them approximate" about a page with one approximate box on it.
     */
    public function test_an_approximate_container_does_not_make_its_children_approximate(): void {
        $result = $this->convert( '[vc_row][vc_column][ult_content_box][vc_btn title="Press me"][/ult_content_box][/vc_column][/vc_row]' );

        $this->assertSame( [ 'group' => 1 ], $result['report']['approximate'] );
        $this->assertSame( 1, $result['report']['converted']['button'] );
    }

    /**
     * And the other half: a child that is not approximate must not leave the
     * flag switched off behind it, so what the approximate parent converts
     * *after* that child is still its own.
     */
    public function test_an_exact_child_does_not_unflag_the_approximate_parent_around_it(): void {
        $result = $this->convert(
            '[vc_row][vc_column][ult_content_box][ult_content_box][/ult_content_box][vc_btn title="After"][/ult_content_box][/vc_column][/vc_row]'
        );

        $this->assertSame( [ 'group' => 2 ], $result['report']['approximate'] );
        $this->assertSame( 1, $result['report']['converted']['button'] );
    }

    public function test_the_registry_knows_its_structural_tags(): void {
        $tags = ( new ConverterEngine() )->registry()->knownTags();

        foreach ( [ 'vc_section', 'vc_row', 'vc_row_inner', 'vc_column', 'vc_column_inner', '#text' ] as $tag ) {
            $this->assertContains( $tag, $tags );
        }
    }

    public function test_base_helpers_read_wpbakery_attributes(): void {
        $engine = new ConverterEngine();
        $engine->registry()->registerElement( 'vc_flickr', ProbeConverter::class );
        $GLOBALS['__test_attachments'] = [ 12 => [ 'url' => 'https://site.test/full.jpg', 'sizes' => [ 'medium' => 'https://site.test/medium.jpg' ] ] ];

        $engine->convert( [ 'content' => '[vc_row][vc_column][vc_flickr link="url:https%3A%2F%2Fex.test%2Fa|target:_blank|rel:nofollow" colour="vista_blue" bogus="chartreuse" toggle="no"][/vc_column][/vc_row]' ], [ 'mode' => 'direct' ] );

        $probe = ProbeConverter::$last;
        $this->assertSame( [ 'url' => 'https://ex.test/a', 'target' => '_blank', 'rel' => [ 'nofollow' ] ], $probe['link'] );
        $this->assertSame( '#75d69c', $probe['colour'] );
        $this->assertNull( $probe['unknown_colour'] );
        $this->assertSame( 'https://site.test/medium.jpg', $probe['attachment'] );
        $this->assertNull( $probe['missing_attachment'] );
        $this->assertTrue( $probe['on_default'] );

        $report = $engine->getReport();
        $this->assertSame( [ [ 'node_id' => 'vc_flickr-1', 'setting_key' => 'bogus', 'ref' => 'chartreuse' ] ], $report['unresolved_globals'] );
        $this->assertSame( [ [ 'node_id' => 'vc_flickr-1', 'attachment_id' => 999 ] ], $report['unresolved_media'] );
        // `toggle="no"` is a switch that is off: it describes nothing the page loses.
        $this->assertSame( [ 'vc_flickr-1: colour', 'vc_flickr-1: bogus' ], $report['skipped_settings'] );
    }

    public function test_inline_blocks_are_grouped_into_one_flexed_nested_row(): void {
        $engine = new ConverterEngine();
        $engine->registry()->registerElement( 'vc_flickr', InlineProbeConverter::class );

        $result = $engine->convert( [ 'content' => '[vc_row][vc_column][vc_flickr][vc_flickr][/vc_column][/vc_row]' ] );

        $column = $result['divi']['elements'][0]['elements'][0]['elements'][0];
        $this->assertCount( 1, $column['elements'] );

        $nested = $column['elements'][0];
        $this->assertSame( 'divi/row', $nested['name'] );
        $this->assertSame( '0px', $nested['settings']['module']['decoration']['spacing']['desktop']['value']['padding']['top'] );
        $this->assertSame( 'calc(100% + 30px)', $nested['settings']['module']['decoration']['sizing']['desktop']['value']['width'] );

        $flexed = $nested['elements'][0];
        $this->assertSame( 'flex', $flexed['settings']['module']['decoration']['layout']['desktop']['value']['display'] );
        $this->assertSame( 'wrap', $flexed['settings']['module']['decoration']['layout']['desktop']['value']['flexWrap'] );
        $this->assertSame( '0px', $flexed['settings']['module']['decoration']['layout']['desktop']['value']['columnGap'] );
        $this->assertCount( 2, $flexed['elements'] );
        $this->assertArrayNotHasKey( 'inline', $flexed['elements'][0] );
    }

    public function test_a_title_attribute_becomes_a_heading_before_the_element(): void {
        $engine = new ConverterEngine();
        $engine->registry()->registerElement( 'vc_flickr', TitledProbeConverter::class );

        $result = $engine->convert( [ 'content' => '[vc_row][vc_column][vc_flickr title="Our gallery"][/vc_column][/vc_row]' ] );

        $blocks = $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'];
        $this->assertSame( 'divi/heading', $blocks[0]['name'] );
        $this->assertSame( 'Our gallery', $blocks[0]['settings']['title']['innerContent']['desktop']['value'] );
        $this->assertSame( 'h2', $blocks[0]['settings']['title']['decoration']['font']['font']['desktop']['value']['headingLevel'] );
        $this->assertSame( 'divi/code', $blocks[1]['name'] );
    }

    public function test_a_delegated_block_gets_no_default_module_margin(): void {
        $engine = new ConverterEngine();
        $engine->registry()->registerElement( 'vc_flickr', DelegatingProbeConverter::class );

        $result = $engine->convert( [ 'content' => '[vc_row][vc_column][vc_flickr][/vc_column][/vc_row]' ] );

        $blocks = $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'];
        $this->assertSame( '35px', $blocks[0]['settings']['module']['decoration']['spacing']['desktop']['value']['margin']['bottom'], 'an element of its own takes WPBakery default' );
        $this->assertArrayNotHasKey( 'spacing', $blocks[1]['settings']['module']['decoration'] ?? [], 'a delegated piece takes none' );
    }
}

/** A stand-in element handler: records what the base helpers return. */
final class ProbeConverter extends \WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter {
    public static array $last = [];

    public function convert( array $node ): array {
        $id   = (string) $node['id'];
        $atts = $node['atts'];

        self::$last = [
            'link'               => $this->link( $atts, 'link' ),
            'colour'             => $this->color( $atts, 'colour', $id ),
            'unknown_colour'     => $this->color( $atts, 'bogus', $id ),
            'attachment'         => $this->attachmentUrl( 12, 'medium', $id ),
            'missing_attachment' => $this->attachmentUrl( 999, 'full', $id ),
            'on_default'         => ! $this->on( $atts, 'toggle' ),
        ];

        $style = $this->mapStyle( 'text', $node );
        $this->engine->logConverted( 'probe' );
        $this->logUnmappedSettings( $id, $atts, array_merge( $style['handled_keys'], [ 'link', 'toggle', 'title' ] ) );

        return $this->codeBlock( $id, 'probe', $style['divi_attrs'] );
    }
}

/** Emits two blocks that must sit side by side. */
final class InlineProbeConverter extends \WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter {
    public function convert( array $node ): array {
        $this->engine->logConverted( 'probe' );

        return $this->codeBlock( (string) $node['id'], 'inline' ) + [ 'inline' => true ];
    }
}

/** Emits its `title` as a heading before itself. */
final class TitledProbeConverter extends \WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter {
    public function convert( array $node ): array {
        $this->engine->logConverted( 'probe' );

        $blocks = [];
        $title  = $this->titleBlock( $node );
        if ( $title !== null ) {
            $blocks[] = $title;
        }
        $blocks[] = $this->codeBlock( (string) $node['id'], 'titled' );

        return $blocks;
    }
}

/** One element of its own plus one piece built through delegate(). */
final class DelegatingProbeConverter extends \WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter {
    public function convert( array $node ): array {
        $this->engine->logConverted( 'probe' );

        $style = $this->mapStyle( 'text', $node );

        return [
            $this->codeBlock( (string) $node['id'], 'own', $style['divi_attrs'] ),
            $this->delegate( PieceConverter::class, (string) $node['id'] . '-piece', [] ),
        ];
    }
}

/** A handler that fails the way a real one would: mid-conversion, on one node. */
final class ThrowingProbeConverter extends \WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter {
    public function convert( array $node ): array {
        throw new \RuntimeException( 'the plugin exploded' );
    }
}

/** A piece of a composite element: it must carry no default margin of its own. */
final class PieceConverter extends \WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter {
    public function convert( array $node ): array {
        $style = $this->mapStyle( 'text', $node );

        return $this->codeBlock( (string) $node['id'], 'piece', $style['divi_attrs'] );
    }
}
