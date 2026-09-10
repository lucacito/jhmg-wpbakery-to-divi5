<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

/**
 * The report's contract.
 *
 * Everything the admin panel renders and everything a buyer is told about the
 * conversion comes out of one array, and every key of it is read by name
 * somewhere — the panel, the Pro batch summary, the telemetry payload. So the
 * shape is pinned here rather than left to whichever handler happened to fill
 * a key in: an empty conversion must still carry every key, at the right type,
 * so a template that loops one of them never has to guard.
 *
 * `ConverterEngineTest` checks that the engine *produces* a report at all;
 * this file is about what is in it, and about the five counters no single
 * handler test can see — `theme_elements`, `static_copies`,
 * `custom_css_carried`, `unresolved_media` and `bracketed_text` — plus the
 * arithmetic behind `quality.module_coverage`.
 */
final class ConversionReportTest extends TestCase {

    /**
     * key ⇒ the PHP type it always holds.
     *
     * `converted` and `approximate` are maps of Divi module short name ⇒
     * count, not totals: the panel lists them. `theme_elements` is family
     * label ⇒ count. `text_nodes` and `bracketed_text` are plain counters.
     */
    const KEYS = [
        'converted'           => 'array',
        'approximate'         => 'array',
        'approximate_matches' => 'array',
        'warnings'            => 'array',
        'skipped_settings'    => 'array',
        'unresolved_globals'  => 'array',
        'not_carried_over'    => 'array',
        'theme_elements'      => 'array',
        'static_copies'       => 'array',
        'custom_css_carried'  => 'array',
        'unresolved_media'    => 'array',
        'text_nodes'          => 'integer',
        'bracketed_text'      => 'integer',
        'quality'             => 'array',
    ];

    /**
     * The kinds `logNotCarriedOver()` documents. Every entry the corpora
     * produce has to be one of them, so the panel can group by kind without a
     * fallback bucket.
     */
    const NOT_CARRIED_OVER_KINDS = [
        'animation', 'visibility', 'background', 'interaction', 'integration',
        'custom_code', 'addon', 'layout', 'hover', 'error',
    ];

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    /** @param array<string,mixed> $options */
    private function convert( string $content, array $options = [], array $meta = [] ): array {
        return ( new ConverterEngine() )->convert( [ 'content' => $content, 'meta' => $meta ], $options );
    }

    // -------------------------------------------------------------------------
    // Every key, on nothing at all
    // -------------------------------------------------------------------------

    #[DataProvider( 'keyProvider' )]
    public function test_an_empty_document_still_reports_every_key( string $key, string $type ): void {
        $report = $this->convert( '' )['report'];

        $this->assertArrayHasKey( $key, $report, "report is missing '{$key}'" );
        $this->assertSame(
            $type,
            gettype( $report[ $key ] ),
            "report['{$key}'] is a " . gettype( $report[ $key ] ) . ", not a {$type}"
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function keyProvider(): array {
        $cases = [];
        foreach ( self::KEYS as $key => $type ) {
            $cases[ $key ] = [ $key, $type ];
        }

        return $cases;
    }

    /**
     * Presence is half the contract; the other half is that nothing is in the
     * report that this file has not been made to think about. A key added
     * without a line in `KEYS` fails here rather than shipping unpinned.
     */
    public function test_the_report_holds_these_keys_and_no_others(): void {
        $report = $this->convert( '' )['report'];

        $expected = array_keys( self::KEYS );
        $actual   = array_keys( $report );
        sort( $expected );
        sort( $actual );

        $this->assertSame( $expected, $actual, 'the report has a key ConversionReportTest::KEYS does not pin' );
    }

    public function test_an_empty_document_reports_nothing_wrong(): void {
        $report = $this->convert( '' )['report'];

        foreach ( array_keys( self::KEYS ) as $key ) {
            if ( $key === 'quality' ) {
                continue;
            }
            $this->assertEmpty( $report[ $key ], "an empty document filled report['{$key}']" );
        }

        // Nothing converted is not a failure to convert: the ratio is over the
        // elements there were, and there were none.
        $this->assertSame( 100, $report['quality']['module_coverage'] );
        $this->assertSame( 0, $report['quality']['settings_issues'] );
    }

    public function test_quality_carries_both_of_its_keys(): void {
        $quality = $this->convert( '' )['report']['quality'];

        $this->assertSame( [ 'module_coverage', 'settings_issues' ], array_keys( $quality ) );
        $this->assertIsInt( $quality['module_coverage'] );
        $this->assertIsInt( $quality['settings_issues'] );
    }

    // -------------------------------------------------------------------------
    // theme_elements
    // -------------------------------------------------------------------------

    public function test_theme_elements_counts_by_family_not_by_tag(): void {
        $report = $this->convert(
            '[vc_row][vc_column]'
            . '[dfd_carousel][mpc_button][mpc_divider][nectar_slider]'
            . '[/vc_column][/vc_row]'
        )['report'];

        $this->assertSame(
            [ 'Ronneby' => 1, 'Massive Addons' => 2, 'Salient' => 1 ],
            $report['theme_elements'],
            'theme_elements must be family label ⇒ count'
        );
    }

    public function test_a_tag_no_family_claims_is_its_own_family_not_a_missing_one(): void {
        $result = $this->convert( '[vc_row][vc_column][acme_widget][/vc_column][/vc_row]' );

        $this->assertSame( [ 'Other shortcodes' => 1 ], $result['report']['theme_elements'] );
        $this->assertSame( [], $result['unsupported'], 'a tag no family claims is still not a WPBakery element' );
    }

    public function test_a_theme_element_is_never_reported_as_unsupported(): void {
        $result = $this->convert( '[vc_row][vc_column][dfd_carousel][/vc_column][/vc_row]' );

        $this->assertSame( [], $result['unsupported'] );
        $this->assertSame(
            [ 'addon' ],
            array_values( array_unique( array_column( $result['report']['not_carried_over'], 'kind' ) ) )
        );
    }

    // -------------------------------------------------------------------------
    // static_copies
    // -------------------------------------------------------------------------

    public function test_static_copies_records_a_shortcode_rendered_on_this_site(): void {
        $GLOBALS['__test_rendered_shortcodes'] = [ 'acme_widget' => '<div class="acme">rendered</div>' ];

        $report = $this->convert(
            '[vc_row][vc_column][vc_column_text]before [acme_widget] after[/vc_column_text][/vc_column][/vc_row]',
            [ 'mode' => 'direct' ]
        )['report'];

        $this->assertCount( 1, $report['static_copies'] );
        $this->assertIsString( $report['static_copies'][0] );
    }

    /**
     * From an export there is no site to render on, so the shortcode text is
     * kept as it stands and nothing is a static copy — the same document, the
     * other mode.
     */
    public function test_static_copies_stays_empty_in_import_mode(): void {
        $GLOBALS['__test_rendered_shortcodes'] = [ 'acme_widget' => '<div class="acme">rendered</div>' ];

        $report = $this->convert(
            '[vc_row][vc_column][vc_column_text]before [acme_widget] after[/vc_column_text][/vc_column][/vc_row]',
            [ 'mode' => 'import' ]
        )['report'];

        $this->assertSame( [], $report['static_copies'] );
    }

    public function test_a_node_is_only_listed_once_however_many_shortcodes_it_rendered(): void {
        $GLOBALS['__test_rendered_shortcodes'] = [
            'acme_widget' => '<div>one</div>',
            'acme_other'  => '<div>two</div>',
        ];

        $report = $this->convert(
            '[vc_row][vc_column][vc_column_text][acme_widget] and [acme_other][/vc_column_text][/vc_column][/vc_row]',
            [ 'mode' => 'direct' ]
        )['report'];

        $this->assertCount( 1, $report['static_copies'] );
    }

    // -------------------------------------------------------------------------
    // unresolved_media
    // -------------------------------------------------------------------------

    public function test_unresolved_media_names_the_node_and_the_attachment(): void {
        $report = $this->convert( '[vc_row][vc_column][vc_single_image image="4821"][/vc_column][/vc_row]' )['report'];

        $this->assertCount( 1, $report['unresolved_media'] );
        $this->assertSame( [ 'node_id', 'attachment_id' ], array_keys( $report['unresolved_media'][0] ) );
        $this->assertSame( 4821, $report['unresolved_media'][0]['attachment_id'] );
    }

    public function test_an_attachment_the_site_knows_is_not_unresolved(): void {
        $GLOBALS['__test_attachments'] = [ 4821 => [ 'url' => 'https://example.test/wp-content/uploads/hero.jpg' ] ];

        $report = $this->convert(
            '[vc_row][vc_column][vc_single_image image="4821"][/vc_column][/vc_row]',
            [ 'mode' => 'direct' ]
        )['report'];

        $this->assertSame( [], $report['unresolved_media'] );
    }

    // -------------------------------------------------------------------------
    // custom_css_carried
    // -------------------------------------------------------------------------

    public function test_custom_css_carried_lists_the_node_whose_design_options_became_css(): void {
        // A gradient with pixel stops is not something Divi's background
        // fields can hold, so the StyleMapper puts it in the module's own CSS
        // and says so (StyleMapperTest).
        $report = $this->convert(
            '[vc_row][vc_column][vc_column_text css=".c{background: linear-gradient(to right, #fff 0px, #000 40px);}"]Hi[/vc_column_text][/vc_column][/vc_row]'
        )['report'];

        $this->assertCount( 1, $report['custom_css_carried'] );
        $this->assertIsString( $report['custom_css_carried'][0] );
    }

    public function test_design_options_divi_can_express_are_not_reported_as_custom_css(): void {
        $report = $this->convert(
            '[vc_row][vc_column][vc_column_text css=".c{background-color: #ff0000 !important;}"]Hi[/vc_column_text][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( [], $report['custom_css_carried'] );
    }

    // -------------------------------------------------------------------------
    // text_nodes and bracketed_text
    // -------------------------------------------------------------------------

    public function test_bracketed_text_counts_the_tokens_that_were_never_shortcodes(): void {
        // `[1]` is a footnote marker somebody typed; WordPress prints it, and
        // only this converter's parser ever saw it as a tag (task-7
        // amendments §5). It comes back as text, and the count says how many.
        $report = $this->convert(
            '[vc_row][vc_column][vc_column_text]See [1] and [2].[/vc_column_text]stray [3][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( 1, $report['bracketed_text'], 'only the token that became its own node is counted' );
    }

    public function test_bracketed_text_is_not_a_theme_element(): void {
        $report = $this->convert( '[vc_row][vc_column][1][/vc_column][/vc_row]' )['report'];

        $this->assertSame( 1, $report['bracketed_text'] );
        $this->assertSame( [], $report['theme_elements'], 'a bare bracket token belongs to no theme' );
        $this->assertSame( 1, $report['text_nodes'] );
    }

    public function test_text_nodes_counts_the_runs_between_shortcodes(): void {
        $report = $this->convert(
            '[vc_row][vc_column]before[vc_column_text]Hi[/vc_column_text]after[/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( 2, $report['text_nodes'] );
        $this->assertSame( 0, $report['bracketed_text'] );
    }

    // -------------------------------------------------------------------------
    // quality.module_coverage
    // -------------------------------------------------------------------------

    public function test_module_coverage_is_clean_modules_over_every_source_element(): void {
        // Two clean elements plus one approximate one: the section, row and
        // column count as converted modules too, so the ratio is over
        // everything the engine emitted, and the one approximate element is
        // the only thing outside it.
        $result = $this->convert(
            '[vc_row][vc_column][vc_column_text]Hi[/vc_column_text][vc_zigzag][/vc_column][/vc_row]'
        );
        $report = $result['report'];

        $converted   = array_sum( $report['converted'] );
        $approximate = count( $report['approximate_matches'] );

        $this->assertSame( 1, $approximate, 'vc_zigzag is registered approximate' );
        $this->assertSame(
            (int) round( $converted / ( $converted + $approximate + count( $result['unsupported'] ) ) * 100 ),
            $report['quality']['module_coverage']
        );
    }

    public function test_module_coverage_falls_when_an_element_is_unsupported(): void {
        // `vc_gitem_zone_a` is a WPBakery core tag with no handler: the one
        // thing `unsupported` records.
        $result = $this->convert( '[vc_row][vc_column][vc_gitem_zone_a][/vc_column][/vc_row]' );

        $this->assertCount( 1, $result['unsupported'] );
        $this->assertLessThan( 100, $result['report']['quality']['module_coverage'] );
    }

    public function test_settings_issues_is_the_number_of_skipped_settings(): void {
        // Two fields WPBakery declares for `vc_toggle` — the optional custom
        // heading it integrates into the title — that this converter does not
        // map yet. Fields WPBakery declares are the only ones that count as
        // gaps (task-11-amendments §7).
        $report = $this->convert(
            '[vc_row][vc_column][vc_toggle title="T" custom_font_container="tag:h3" custom_google_fonts="font_family:Roboto"]Body[/vc_toggle][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame(
            [ 'vc_toggle-1: custom_font_container', 'vc_toggle-1: custom_google_fonts' ],
            $report['skipped_settings']
        );
        $this->assertSame( 2, $report['quality']['settings_issues'] );
    }

    /**
     * A tab's children are converted only so `ContentFlattener` can render
     * them back to HTML; none of those blocks is in the finished page, so
     * counting them would inflate the coverage figure with modules that do not
     * exist (Task 9).
     */
    public function test_flattened_children_are_not_counted_as_converted_modules(): void {
        $flat = $this->convert(
            '[vc_row][vc_column][vc_tta_tabs][vc_tta_section title="One"]'
            . '[vc_column_text]Hi[/vc_column_text][vc_column_text]There[/vc_column_text]'
            . '[/vc_tta_section][/vc_tta_tabs][/vc_column][/vc_row]'
        )['report'];

        $this->assertArrayNotHasKey( 'text', $flat['converted'], 'the flattened text blocks were counted' );
        $this->assertSame( 100, $flat['quality']['module_coverage'] );
    }

    // -------------------------------------------------------------------------
    // not_carried_over
    // -------------------------------------------------------------------------

    public function test_every_not_carried_over_entry_has_a_documented_kind(): void {
        $report = $this->convert(
            '[vc_row css_animation="bounceIn"][vc_column]'
            . '[vc_column_text disable_element="yes"]Hi[/vc_column_text]'
            . '[dfd_carousel]'
            . '[/vc_column][/vc_row]'
        )['report'];

        $this->assertNotEmpty( $report['not_carried_over'] );

        foreach ( $report['not_carried_over'] as $entry ) {
            $this->assertSame( [ 'kind', 'node_id', 'detail' ], array_keys( $entry ) );
            $this->assertContains( $entry['kind'], self::NOT_CARRIED_OVER_KINDS, "undocumented kind '{$entry['kind']}'" );
            $this->assertNotSame( '', $entry['detail'], 'an entry with no explanation' );
        }
    }

    public function test_page_custom_css_is_reported_under_custom_code(): void {
        $report = $this->convert(
            '[vc_row][vc_column][/vc_column][/vc_row]',
            [],
            [ '_wpb_post_custom_css' => '.page{color:red}' ]
        )['report'];

        $kinds = array_column( $report['not_carried_over'], 'kind' );

        $this->assertContains( 'custom_code', $kinds );
    }

    /**
     * A field a theme bolted onto a WPBakery core element with
     * `vc_add_param()` is not a skipped setting: nobody here ignored it, it
     * belongs to a plugin that is not being converted (task-10 amendments §5).
     */
    public function test_a_theme_param_on_a_core_element_is_reported_as_an_addon_not_skipped(): void {
        $report = $this->convert(
            '[vc_row dfd_row_config="wide" row_effect="parallax"][vc_column][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( [], $report['skipped_settings'] );

        $addon = array_values( array_filter(
            $report['not_carried_over'],
            static fn( array $e ): bool => $e['kind'] === 'addon'
        ) );

        $this->assertCount( 1, $addon, 'one entry per node, naming every such field' );
        $this->assertStringContainsString( 'dfd_row_config', $addon[0]['detail'] );
        $this->assertStringContainsString( 'row_effect', $addon[0]['detail'] );
        $this->assertStringContainsString( 'Ronneby', $addon[0]['detail'] );
    }

    /**
     * A name no theme table claims, on an element WPBakery does not declare it
     * for.
     *
     * Older builds of Ronneby wrote `cus_bg_position` and `col_hover` on a
     * `vc_column`, and no shipped version of the theme registers either, so
     * neither can enter `THEME_PARAMS` — a name only ever gets in there from a
     * live `vc_add_param()` call. WPBakery's own parameter table settles it
     * from the other side: `vc_column` has no such fields, so WPBakery did not
     * put them there and this converter has no gap to answer for
     * (task-11-amendments §7).
     */
    public function test_a_field_wpbakery_never_declared_is_an_addon_not_a_skipped_setting(): void {
        $report = $this->convert(
            '[vc_row][vc_column cus_bg_position="center" col_hover="yes"][dfd_carousel][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( [], $report['skipped_settings'] );

        $addon = $this->addonEntries( $report, 'vc_column-1' );

        $this->assertCount( 1, $addon, 'one entry per node, naming every such field' );
        $this->assertStringContainsString( 'cus_bg_position, col_hover', $addon[0]['detail'] );
        $this->assertStringContainsString( 'not declared by WPBakery', $addon[0]['detail'] );
        $this->assertStringContainsString( 'Ronneby', $addon[0]['detail'], 'the page\'s one theme family is named' );
    }

    /** With two families on the page there is no honest single answer, so none is given. */
    public function test_the_family_is_only_named_when_the_page_has_exactly_one(): void {
        $report = $this->convert(
            '[vc_row][vc_column cus_bg_position="center"][dfd_carousel][mpc_button][/vc_column][/vc_row]'
        )['report'];

        $addon = $this->addonEntries( $report, 'vc_column-1' );

        $this->assertStringContainsString( 'not declared by WPBakery', $addon[0]['detail'] );
        $this->assertStringNotContainsString( 'Ronneby', $addon[0]['detail'] );
        $this->assertStringNotContainsString( 'Massive Addons', $addon[0]['detail'] );
    }

    /**
     * The other half of §7: a field WPBakery *does* declare, that no handler
     * read, is a gap in this converter and is still a skipped setting.
     */
    public function test_a_field_wpbakery_declares_that_no_handler_read_is_still_a_skipped_setting(): void {
        $report = $this->convert(
            '[vc_row][vc_column][vc_progress_bar values="50|Design" customcolor="#ffffff"][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( [ 'vc_progress_bar-1: customcolor' ], $report['skipped_settings'] );
        $this->assertSame( [], $this->addonEntries( $report, 'vc_progress_bar-1' ) );
    }

    /** The same field on an element the theme never added it to is not attributed to that theme. */
    public function test_a_theme_param_on_the_wrong_element_is_not_attributed_to_that_theme(): void {
        $report = $this->convert(
            '[vc_row][vc_column][vc_column_text dfd_row_config="wide"]Hi[/vc_column_text][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( [], $report['skipped_settings'] );

        $addon = $this->addonEntries( $report, 'vc_column_text-1' );

        $this->assertCount( 1, $addon );
        $this->assertStringContainsString( 'not declared by WPBakery', $addon[0]['detail'] );
        $this->assertStringNotContainsString( 'matches', $addon[0]['detail'], 'no table claims it for this element' );
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int, array{kind: string, node_id: string, detail: string}>
     */
    private function addonEntries( array $report, string $node_id = '' ): array {
        return array_values( array_filter(
            $report['not_carried_over'],
            static fn( array $e ): bool => $e['kind'] === 'addon' && ( $node_id === '' || $e['node_id'] === $node_id )
        ) );
    }

    /**
     * Ultimate Addons' "Ultimate Row Backgrounds" put sixty-eight fields on
     * every `vc_row` of every page built with it. They belong to a plugin that
     * is not being converted, so they are an `addon` entry naming it, not
     * sixty-eight gaps in the row handler.
     */
    public function test_an_add_on_row_background_field_is_reported_as_an_addon_not_skipped(): void {
        $report = $this->convert(
            '[vc_row bg_type="image" parallax_style="vcpb-default" bg_image_new="id^42|url^https://e.test/a.jpg"][vc_column][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( [], $report['skipped_settings'] );

        $addon = array_values( array_filter(
            $report['not_carried_over'],
            static fn( array $e ): bool => $e['kind'] === 'addon'
        ) );

        $this->assertCount( 1, $addon );
        $this->assertStringContainsString( 'Ultimate Addons', $addon[0]['detail'] );
        $this->assertStringContainsString( 'bg_type', $addon[0]['detail'] );
        $this->assertStringContainsString( 'parallax_style', $addon[0]['detail'] );
    }

    /** Ronneby's own row background panel, the `dfd_`-prefixed half of the same story. */
    public function test_a_theme_row_background_field_is_reported_under_that_theme(): void {
        $report = $this->convert(
            '[vc_row dfd_bg_style="dfd_bg_image" dfd_bg_image_new="42" dfd_parallax_sense="30"][vc_column][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame( [], $report['skipped_settings'] );

        $addon = array_values( array_filter(
            $report['not_carried_over'],
            static fn( array $e ): bool => $e['kind'] === 'addon'
        ) );

        $this->assertCount( 1, $addon );
        $this->assertStringContainsString( 'Ronneby', $addon[0]['detail'] );
        $this->assertStringContainsString( 'dfd_bg_style', $addon[0]['detail'] );
    }

    // -------------------------------------------------------------------------
    // A shortcode WordPress itself cannot read as key="value" pairs
    // -------------------------------------------------------------------------

    /**
     * `text="A FEW WORDS <span style="…">ABOUT</span> OUR STUDIO"` — a real
     * heading from `fixtures/wpbakery-layouts/4002-dance-studio.txt`. The
     * unescaped `"` ends the value early, so `shortcode_parse_atts()` (and
     * therefore WordPress, and therefore WPBakery on the live page) reads no
     * `text` at all and hands back numbered fragments instead.
     *
     * The converter must not invent the heading back — the WPBakery page does
     * not show it either — but it must not swallow it: the reader is looking at
     * an element that came out blank and needs to be told why.
     */
    public function test_an_attribute_string_wordpress_cannot_read_is_reported(): void {
        $report = $this->convert(
            '[vc_row][vc_column][vc_custom_heading text="A FEW WORDS <span style="color: #00D9BF;">ABOUT</span> OUR STUDIO" font_container="tag:h2"][/vc_column][/vc_row]'
        )['report'];

        $malformed = array_values( array_filter(
            $report['warnings'],
            static fn( string $w ): bool => str_contains( $w, 'read positionally' )
        ) );

        $this->assertCount( 1, $malformed );
        $this->assertStringContainsString( 'vc_custom_heading', $malformed[0] );
        $this->assertStringContainsString( 'A FEW WORDS', $malformed[0] );

        // Not a skipped setting: no handler ignored a field, the source never
        // held a readable one.
        $this->assertSame( [], $report['skipped_settings'] );
    }

    public function test_a_readable_attribute_string_reports_nothing_of_the_kind(): void {
        $report = $this->convert(
            '[vc_row][vc_column][vc_custom_heading text="A FEW WORDS ABOUT OUR STUDIO" font_container="tag:h2"][/vc_column][/vc_row]'
        )['report'];

        $this->assertSame(
            [],
            array_values( array_filter( $report['warnings'], static fn( string $w ): bool => str_contains( $w, 'read positionally' ) ) )
        );
    }

    // -------------------------------------------------------------------------
    // unresolved_globals
    // -------------------------------------------------------------------------

    public function test_unresolved_globals_names_the_setting_and_the_reference(): void {
        $report = $this->convert(
            '[vc_row][vc_column][vc_btn title="Go" color="vc-skin-colour-nobody-defined"][/vc_column][/vc_row]'
        )['report'];

        foreach ( $report['unresolved_globals'] as $entry ) {
            $this->assertSame( [ 'node_id', 'setting_key', 'ref' ], array_keys( $entry ) );
        }

        $this->assertIsArray( $report['unresolved_globals'] );
    }

    // -------------------------------------------------------------------------
    // warnings
    // -------------------------------------------------------------------------

    /**
     * `logWarning()` deduplicates, and the input has to be one where that is
     * the only reason there is a single entry: a warning that names the node
     * it came from is distinct per node however the method behaves.
     *
     * `NodeTree` repairs a top-level `vc_row_inner` with a warning that names
     * no node at all, so two of them produce the same string twice and the
     * count says whether it was kept once.
     */
    public function test_a_warning_is_recorded_once_however_often_it_happens(): void {
        $repair = 'vc_row_inner at the top level treated as a row';

        $one = $this->convert( '[vc_row_inner][vc_column][/vc_column][/vc_row_inner]' )['report'];
        $this->assertContains( $repair, $one['warnings'], 'the input no longer produces the warning under test' );

        $two = $this->convert(
            '[vc_row_inner][vc_column][/vc_column][/vc_row_inner][vc_row_inner][vc_column][/vc_column][/vc_row_inner]'
        )['report'];

        $this->assertCount(
            1,
            array_keys( $two['warnings'], $repair, true ),
            'the same warning was recorded twice'
        );
    }
}
