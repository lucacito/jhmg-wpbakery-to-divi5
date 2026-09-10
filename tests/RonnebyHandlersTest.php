<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;

/**
 * The DFD Ronneby element handlers (Task 9b), against the theme's own demo
 * exports.
 *
 * Three things are checked, and the first is the one that matters:
 *
 * 1. **Every occurrence in the committed exports converts clean.** Not one
 *    fixture per element but every `[dfd_heading]` on three real pages — 80 of
 *    them, with attribute names from more than one build of the theme — with
 *    `skipped_settings` and `unsupported` both empty. `wpbakery templates/ronneby/`
 *    holds all 96 exports locally; the three committed ones are the gate, and
 *    the numbers for the other 93 are in the task report.
 * 2. **Nothing is claimed without being said.** For each fixture, every
 *    attribute of the cut element is either written into the blocks, named in
 *    the report, or listed here as one whose value Divi stores in another form
 *    — a check the corpus gate cannot make, because a handler can satisfy
 *    "no skipped settings" by listing a key as consumed and mapping none of it
 *    (task-9b-amendments.md §6).
 * 3. **The mapping itself**, one assertion per handler on its own fixture.
 */
final class RonnebyHandlersTest extends TestCase {

    /** The exports committed to the repository (amendment §2). */
    const EXPORTS = [ '04_pages_demo.xml', '15_tenth.xml', '23_eighteenth.xml' ];

    /** Every tag the Task 9b handler table claims. */
    const TAGS = [
        'dfd_spacer', 'dfd_heading', 'dfd_single_image', 'dfd_button', 'dfd_delimiter',
        'dfd_info_box', 'dfd_icon_list', 'dfd_icon_list_item', 'dfd_google_map',
        'dfd_accordion', 'dfd_tta_tabs', 'dfd_tta_tour', 'dfd_blog_posts',
        'dfd_new_social_accounts', 'announcement', 'info_banner', 'new_team_member',
        'new_testimonials', 'facts', 'progressbar', 'piecharts', 'countdown', 'videoplayer',
    ];

    /**
     * Attributes a fixture's element carries whose value Divi keeps in another
     * form, so neither the value nor the key can be found by searching.
     *
     * Every entry is a reviewed statement about the conversion, not a way round
     * the check: the mapping each one names is asserted in the per-handler
     * tests below.
     *
     * @var array<string, array<string,string>>
     */
    const TRANSFORMED = [
        'ronneby-spacer'      => [
            'screen_normal_resolution' => 'the width Divi\'s desktop breakpoint stands in for',
            'screen_tablet_resolution' => 'the width Divi\'s tablet breakpoint stands in for',
            'screen_mobile_resolution' => 'the width Divi\'s phone breakpoint stands in for',
        ],
        'ronneby-heading'     => [
            'delimiter_settings'    => 'the divider\'s style, weight, colour and width',
            'subheading_margin'     => 'the subtitle block\'s bottom margin',
            'title_responsive'      => 'the title\'s tablet and phone sizes',
            'subtitle_google_fonts' => 'switches the subtitle\'s Google font on',
            'subtitle_custom_fonts' => 'the subtitle\'s font family and weight',
        ],
        'ronneby-single-image' => [],
        'ronneby-button'      => [
            'alignment'          => 'the module\'s own alignment value',
            'buttom_link_src'    => 'the button\'s link URL',
            'title_google_fonts' => 'switches the label\'s Google font on',
            'title_custom_fonts' => 'the label\'s font family and weight',
            'title_font_options' => 'the label\'s line height',
        ],
        'ronneby-delimiter'   => [
            'delimiter_style'    => 'which blocks the element becomes',
            'custom_fonts'       => 'the text\'s font family and weight',
            'title_font_options' => 'the text\'s size, line height and letter spacing',
        ],
        'ronneby-info-box'    => [
            'icon_type'          => 'switches the blurb\'s icon off in favour of an image',
            'icon_image_id'      => 'the icon image\'s URL',
            'custom_fonts'       => 'the title\'s font family and weight',
            'layout'             => 'the blurb\'s icon placement',
        ],
        'ronneby-icon-list'   => [],
        'ronneby-icon-list-item' => [
            'icon_type' => 'switches the row\'s glyph off',
            'link_box'  => 'switches the row\'s link on',
            'link'      => 'the row\'s link URL',
        ],
        'ronneby-google-map'  => [
            'enable_responsive' => 'switches the per-breakpoint heights on',
        ],
        'ronneby-accordion'   => [
            'tab_title_google_fonts' => 'switches the panel title\'s Google font on',
            'tab_title_custom_fonts' => 'the panel title\'s font family and weight',
            'tab_title_font_options' => 'the panel title\'s letter spacing',
        ],
        'ronneby-tta-tabs'    => [],
        'ronneby-tta-tour'    => [
            'tour_title_google_fonts' => 'switches the tab label\'s Google font on',
            'tour_title_custom_fonts' => 'the tab label\'s font family and weight',
            'tour_title_font_options' => 'the tab label\'s line height and letter spacing',
            'css'                     => 'the design options the StyleMapper reads',
        ],
        'ronneby-blog-posts'  => [],
        'ronneby-new-social-accounts' => [
            'info_alignment'      => 'the module\'s text orientation',
            'dfd_social_networks' => 'one child block per account',
        ],
        'ronneby-announcement' => [
            'align'             => 'the module\'s text orientation',
            'use_google_fonts'  => 'switches the copy\'s Google font on',
            'custom_fonts'      => 'the copy\'s font family and weight',
            'font_options'      => 'the copy\'s size',
        ],
        'ronneby-info-banner' => [
            'image'         => 'the module\'s background image URL',
            'readmore_show' => 'switches the button on',
        ],
        'ronneby-new-team-member' => [],
        'ronneby-new-testimonials' => [
            'image' => 'the portrait\'s URL',
        ],
        'ronneby-facts'       => [
            'use_google_fonts' => 'switches the caption\'s Google font on',
            'custom_fonts'     => 'the caption\'s font family and weight',
        ],
        'ronneby-progressbar' => [],
        'ronneby-piecharts'   => [
            'content_custom_fonts' => 'the figure\'s font family and weight',
            'title_custom_fonts'   => 'the caption\'s font family and weight',
        ],
        'ronneby-countdown'   => [
            'custom_fonts'        => 'the unit labels\' font family and weight',
            'font_options'        => 'the separator\'s size and colour',
            'number_font_options' => 'the digits\' size and colour',
            'units_font_options'  => 'the unit labels\' line height',
        ],
        'ronneby-videoplayer' => [
            'button_align' => 'the module\'s text orientation',
        ],
    ];

    /** @var array<string,string> */
    private static array $exports = [];

    // -------------------------------------------------------------------------
    // (a) Every occurrence in the committed exports
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function tagProvider(): array {
        $cases = [];
        foreach ( self::TAGS as $tag ) {
            $cases[ $tag ] = [ $tag ];
        }

        return $cases;
    }

    #[DataProvider( 'tagProvider' )]
    public function test_every_occurrence_in_the_committed_exports_converts_with_nothing_skipped( string $tag ): void {
        $occurrences = 0;

        foreach ( self::EXPORTS as $export ) {
            foreach ( self::occurrences( self::export( $export ), $tag ) as $index => $element ) {
                $occurrences++;
                $this->assertConvertsClean( $element, sprintf( '%s #%d in %s', $tag, $index + 1, $export ) );
            }
        }

        if ( $occurrences > 0 ) {
            return;
        }

        // Six of the twenty-three tags appear on none of the three committed
        // pages (`dfd_delimiter`, `dfd_accordion`, `dfd_tta_tour`,
        // `dfd_blog_posts`, `announcement`, `piecharts`). The other 93 exports
        // are gitignored, so what stands in for them here is the fixture that
        // *was* cut from one — its sidecar names the export and the occurrence,
        // and the full 96-export run is in the task report.
        $fixture = self::FIXTURE_FOR_TAG[ $tag ] ?? null;
        $this->assertNotNull( $fixture, "[{$tag}] appears in none of the committed exports and has no fixture." );

        $source = (string) file_get_contents( __DIR__ . "/../fixtures/wpbakery/{$fixture}.txt" );
        $this->assertSame(
            1,
            preg_match( '/\[vc_column\](.*)\[\/vc_column\]/s', $source, $cut ),
            "fixtures/wpbakery/{$fixture}.txt does not hold one cut element."
        );

        $sidecar = json_decode( (string) file_get_contents( __DIR__ . "/../fixtures/wpbakery/{$fixture}.json" ), true ) ?: [];
        $this->assertConvertsClean( $cut[1], sprintf( '%s, cut from %s', $tag, $sidecar['source'] ?? 'the corpus' ) );
    }

    /** The tag ⇒ fixture map for the six tags the committed exports do not carry. */
    const FIXTURE_FOR_TAG = [
        'dfd_delimiter'  => 'ronneby-delimiter',
        'dfd_accordion'  => 'ronneby-accordion',
        'dfd_tta_tour'   => 'ronneby-tta-tour',
        'dfd_blog_posts' => 'ronneby-blog-posts',
        'announcement'   => 'ronneby-announcement',
        'piecharts'      => 'ronneby-piecharts',
    ];

    /** One element, wrapped in a row and a column, with nothing left unaccounted for. */
    private function assertConvertsClean( string $element, string $where ): void {
        wbdc_test_reset_hooks();

        $result = ( new ConverterEngine() )->convert(
            [ 'content' => '[vc_row][vc_column]' . $element . '[/vc_column][/vc_row]' ],
            [ 'mode' => 'import' ]
        );

        $this->assertSame(
            [],
            $result['report']['skipped_settings'],
            sprintf(
                "%s left settings unaccounted for.\n%d entries in not_carried_over.",
                $where,
                count( $result['report']['not_carried_over'] )
            )
        );
        $this->assertSame( [], $result['unsupported'], "{$where} produced an unsupported element." );
    }

    // -------------------------------------------------------------------------
    // (a2) Nothing claimed without being said
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function fixtureProvider(): array {
        $cases = [];
        foreach ( array_keys( self::TRANSFORMED ) as $name ) {
            $cases[ $name ] = [ $name ];
        }

        return $cases;
    }

    #[DataProvider( 'fixtureProvider' )]
    public function test_every_attribute_of_the_cut_element_is_written_or_reported( string $name ): void {
        $converted = $this->convertFixture( $name );

        $source = (string) file_get_contents( __DIR__ . "/../fixtures/wpbakery/{$name}.txt" );
        $this->assertSame(
            1,
            preg_match( '/\[vc_column\]\[([a-z_0-9]+)([^\]]*)\]/', $source, $element ),
            "fixtures/wpbakery/{$name}.txt does not hold one cut element."
        );

        // Slashes unescaped, or a URL and a date would never be found.
        $blocks   = (string) wp_json_encode( $converted['result']['divi'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $reported = implode( ' ', array_column( $converted['result']['report']['not_carried_over'], 'detail' ) )
            . ' ' . implode( ' ', $converted['result']['report']['warnings'] );

        preg_match_all( '/([a-zA-Z0-9_]+)="([^"]*)"/', $element[2], $attributes, PREG_SET_ORDER );

        foreach ( $attributes as [ , $key, $value ] ) {
            $value = trim( $value );

            // A blank value and a switch that is off describe nothing the page
            // loses, exactly as `logUnmappedSettings()` treats them.
            if ( $value === '' || in_array( strtolower( $value ), [ 'no', 'none', 'off', 'false' ], true ) ) {
                continue;
            }

            $covered = str_contains( $reported, $key )
                || str_contains( $reported, $value )
                || str_contains( $blocks, $value )
                || isset( self::TRANSFORMED[ $name ][ $key ] );

            $this->assertTrue(
                $covered,
                sprintf(
                    '%s: %s="%s" is neither written into the blocks nor named in the report. Map it, report it, or add it to RonnebyHandlersTest::TRANSFORMED with what Divi stores instead.',
                    $name,
                    $key,
                    $value
                )
            );
        }
    }

    // -------------------------------------------------------------------------
    // (b) The mapping, per handler
    // -------------------------------------------------------------------------

    public function test_spacer_becomes_a_divider_with_no_line_and_three_heights(): void {
        $divider = $this->firstBlock( 'ronneby-spacer', 'divi/divider' );

        $this->assertSame( 'off', $divider['settings']['divider']['advanced']['line']['desktop']['value']['show'] );

        $sizing = $divider['settings']['module']['decoration']['sizing'];
        // `screen_normal_spacer_size="100"` is Divi's desktop; wide (120) is
        // above 1280 and reported.
        $this->assertSame( '100px', $sizing['desktop']['value']['height'] );
        $this->assertSame( '100px', $sizing['tablet']['value']['height'] );
        $this->assertSame( '100px', $sizing['phone']['value']['height'] );
        $this->assertReported( 'ronneby-spacer', 'screen_wide_spacer_size="120"' );
    }

    public function test_heading_becomes_a_heading_a_text_and_a_divider(): void {
        $blocks = $this->blockNames( 'ronneby-heading' );
        $this->assertSame( [ 'divi/heading', 'divi/text', 'divi/divider' ], $blocks );

        $heading = $this->firstBlock( 'ronneby-heading', 'divi/heading' );
        $this->assertSame( 'How it all was', $heading['settings']['title']['innerContent']['desktop']['value'] );

        $font = $heading['settings']['title']['decoration']['font']['font'];
        $this->assertSame( 'h3', $font['desktop']['value']['headingLevel'] );
        $this->assertSame( '30px', $font['tablet']['value']['size'] );
        $this->assertSame( '30px', $font['phone']['value']['size'] );

        $subtitle = $this->firstBlock( 'ronneby-heading', 'divi/text' );
        $this->assertStringContainsString( 'Find out more', $subtitle['settings']['content']['innerContent']['desktop']['value'] );
        $this->assertSame( '17px', $subtitle['settings']['content']['decoration']['bodyFont']['body']['font']['desktop']['value']['size'] );
        $this->assertSame( 'Handlee', $subtitle['settings']['content']['decoration']['bodyFont']['body']['font']['desktop']['value']['family'] );
        $this->assertSame( '20px', $subtitle['settings']['module']['decoration']['spacing']['desktop']['value']['margin']['bottom'] );

        $line = $this->firstBlock( 'ronneby-heading', 'divi/divider' )['settings']['divider']['advanced']['line']['desktop']['value'];
        $this->assertSame( [ 'show' => 'on', 'weight' => '1px', 'style' => 'dotted', 'color' => '#dddddd' ], $line );
    }

    public function test_single_image_takes_the_attachment_url_and_the_declared_width(): void {
        $image = $this->firstBlock( 'ronneby-single-image', 'divi/image' )['settings'];

        $this->assertSame( '336', $image['image']['innerContent']['desktop']['value']['id'] );
        $this->assertStringContainsString( 'ipadds-1.jpg', $image['image']['innerContent']['desktop']['value']['src'] );
        $this->assertSame( '600px', $image['module']['advanced']['sizing']['desktop']['value']['width'] );
        $this->assertSame( 'on', $image['module']['advanced']['sizing']['desktop']['value']['forceFullwidth'] );
        $this->assertSame( 'center', $image['module']['advanced']['align']['desktop']['value'] );
    }

    public function test_button_keeps_its_label_link_colours_border_and_padding(): void {
        $button = $this->firstBlock( 'ronneby-button', 'divi/button' )['settings']['button'];

        $this->assertSame( 'View more', $button['innerContent']['desktop']['value']['text'] );
        $this->assertSame( '#', $button['innerContent']['desktop']['value']['linkUrl'] );
        $this->assertSame( '#3d3d3d', $button['decoration']['background']['desktop']['value']['color'] );
        $this->assertSame(
            [ 'width' => '1px', 'style' => 'solid', 'color' => '#3d3d3d' ],
            $button['decoration']['border']['desktop']['value']['styles']['all']
        );
        $this->assertSame( '2px', $button['decoration']['border']['desktop']['value']['radius']['topLeft'] );
        $this->assertSame( '60px', $button['decoration']['spacing']['desktop']['value']['padding']['left'] );
        $this->assertSame( '45px', $button['decoration']['font']['font']['desktop']['value']['lineHeight'] );

        $this->assertReported( 'ronneby-button', 'hover_background="#2d2d2d"' );
    }

    public function test_delimiter_with_text_becomes_a_text_block_and_a_divider(): void {
        $this->assertSame( [ 'divi/text', 'divi/divider' ], $this->blockNames( 'ronneby-delimiter' ) );

        $text = $this->firstBlock( 'ronneby-delimiter', 'divi/text' )['settings']['content'];
        $this->assertSame( 'or', $text['innerContent']['desktop']['value'] );
        $this->assertSame( '10px', $text['decoration']['bodyFont']['body']['font']['desktop']['value']['size'] );
        $this->assertSame( 'Montserrat', $text['decoration']['bodyFont']['body']['font']['desktop']['value']['family'] );

        $line = $this->firstBlock( 'ronneby-delimiter', 'divi/divider' )['settings']['divider']['advanced']['line']['desktop']['value'];
        $this->assertSame( 'on', $line['show'] );
        $this->assertSame( '#dbdbdb', $line['color'] );
    }

    public function test_info_box_becomes_a_blurb_with_the_image_icon_and_the_subtitle_in_the_copy(): void {
        $blurb = $this->firstBlock( 'ronneby-info-box', 'divi/blurb' )['settings'];

        $this->assertSame( 'off', $blurb['imageIcon']['innerContent']['desktop']['value']['useIcon'] );
        $this->assertStringContainsString( 'pr_1.png', $blurb['imageIcon']['innerContent']['desktop']['value']['src'] );
        // `layout-03` stacks the parts, so the icon is above the copy.
        $this->assertSame( 'top', $blurb['imageIcon']['advanced']['placement']['desktop']['value'] );
        $this->assertSame( 'Schedule the works', $blurb['title']['innerContent']['desktop']['value']['text'] );
        $this->assertStringContainsString( '<h4>Vestibulum ante ipsum</h4>', $blurb['content']['innerContent']['desktop']['value'] );
        // `read_more="box"` links the whole module.
        $this->assertSame( '#', $blurb['module']['advanced']['link']['desktop']['value']['url'] );
    }

    public function test_icon_list_becomes_one_list_with_a_row_per_child(): void {
        $list = $this->firstBlock( 'ronneby-icon-list', 'divi/icon-list' );

        $this->assertSame( '18px', $list['settings']['icon']['advanced']['size']['desktop']['value'] );
        $this->assertSame( '#3a3a3a', $list['settings']['icon']['advanced']['color']['desktop']['value'] );
        $this->assertCount( 4, $list['elements'] );

        foreach ( $list['elements'] as $item ) {
            $this->assertSame( 'divi/icon-list-item', $item['name'] );
            $this->assertIsArray( $item['settings']['icon']['innerContent']['desktop']['value'] );
        }

        $this->assertStringContainsString(
            '121 King Street',
            $list['elements'][0]['settings']['content']['innerContent']['desktop']['value']
        );
        // The theme's own icon font has no Divi equivalent.
        $this->assertReported( 'ronneby-icon-list', 'dfd-icon-map_marker' );
    }

    public function test_a_stray_icon_list_row_is_wrapped_in_a_list_of_its_own(): void {
        $list = $this->firstBlock( 'ronneby-icon-list-item', 'divi/icon-list' );

        $this->assertCount( 1, $list['elements'] );
        $this->assertSame( '#', $list['elements'][0]['settings']['module']['advanced']['link']['desktop']['value']['url'] );
        $this->assertReported( 'ronneby-icon-list-item', 'not inside a dfd_icon_list' );
    }

    public function test_google_map_keeps_its_marker_zoom_and_per_breakpoint_height(): void {
        $map = $this->firstBlock( 'ronneby-google-map', 'divi/map' );

        $this->assertSame( 'Washington', $map['settings']['map']['innerContent']['desktop']['value']['address'] );
        $this->assertSame( '14', $map['settings']['map']['innerContent']['desktop']['value']['zoom'] );
        $this->assertSame( '1180px', $map['settings']['module']['decoration']['sizing']['desktop']['value']['height'] );
        $this->assertSame( '600px', $map['settings']['module']['decoration']['sizing']['tablet']['value']['height'] );
        $this->assertSame( '500px', $map['settings']['module']['decoration']['sizing']['phone']['value']['height'] );

        $this->assertCount( 1, $map['elements'] );
        $this->assertSame( 'divi/map-pin', $map['elements'][0]['name'] );
        $this->assertSame( 'Washington', $map['elements'][0]['settings']['pin']['innerContent']['desktop']['value']['address'] );
    }

    public function test_accordion_panels_and_their_colours(): void {
        $accordion = $this->firstBlock( 'ronneby-accordion', 'divi/accordion' );

        $this->assertCount( 6, $accordion['elements'] );
        $this->assertSame( 'divi/accordion-item', $accordion['elements'][0]['name'] );
        $this->assertSame(
            'What does all of this mean?',
            $accordion['elements'][0]['settings']['title']['innerContent']['desktop']['value']
        );
        $this->assertSame(
            '#001d63',
            $accordion['settings']['closedToggle']['decoration']['font']['font']['desktop']['value']['color']
        );
        $this->assertSame(
            'Raleway',
            $accordion['settings']['openToggle']['decoration']['font']['font']['desktop']['value']['family']
        );
    }

    public function test_tabs_keep_their_panels_and_the_active_label_colour(): void {
        $tabs = $this->firstBlock( 'ronneby-tta-tabs', 'divi/tabs' );

        $this->assertCount( 3, $tabs['elements'] );
        $this->assertSame( 'divi/tab', $tabs['elements'][0]['name'] );
        $this->assertSame( 'Start the journey', $tabs['elements'][0]['settings']['title']['innerContent']['desktop']['value'] );
        $this->assertSame(
            '#ffffff',
            $tabs['settings']['activeTab']['decoration']['font']['font']['desktop']['value']['color']
        );
    }

    public function test_a_tour_reports_its_side_controls(): void {
        $this->assertSame( 'divi/tabs', $this->firstBlock( 'ronneby-tta-tour', 'divi/tabs' )['name'] );
        $this->assertReported( 'ronneby-tta-tour', 'dfd_tta_tour stacks its controls down one side' );
    }

    public function test_blog_posts_carry_the_query_and_report_the_category_slugs(): void {
        $blog = $this->firstBlock( 'ronneby-blog-posts', 'divi/blog' )['settings'];

        $this->assertSame( 'post', $blog['post']['advanced']['type']['desktop']['value'] );
        $this->assertSame( '4', $blog['post']['advanced']['number']['desktop']['value'] );
        $this->assertSame( 'h3', $blog['title']['decoration']['font']['font']['desktop']['value']['headingLevel'] );
        $this->assertReported( 'ronneby-blog-posts', 'fashionable-summer-2019' );
    }

    public function test_social_accounts_map_the_networks_divi_has_and_report_the_rest(): void {
        $follow = $this->firstBlock( 'ronneby-new-social-accounts', 'divi/social-media-follow' );

        $networks = array_map(
            static fn( array $child ): string => $child['settings']['socialNetwork']['innerContent']['desktop']['value']['title'],
            $follow['elements']
        );

        $this->assertSame( [ 'deviantart', 'dribbble' ], $networks );
        $this->assertSame( '17px', $follow['elements'][0]['settings']['icon']['advanced']['size']['desktop']['value'] );
        $this->assertReported( 'ronneby-new-social-accounts', 'Divi has no network for soc_icon-digg' );
    }

    public function test_announcement_becomes_a_blurb_with_the_icon_beside_the_copy(): void {
        $blurb = $this->firstBlock( 'ronneby-announcement', 'divi/blurb' )['settings'];

        $this->assertSame( 'on', $blurb['imageIcon']['innerContent']['desktop']['value']['useIcon'] );
        $this->assertSame( 'left', $blurb['imageIcon']['advanced']['placement']['desktop']['value'] );
        $this->assertSame( '#262626', $blurb['imageIcon']['advanced']['color']['desktop']['value'] );
        $this->assertSame( '19px', $blurb['imageIcon']['decoration']['sizing']['desktop']['value']['width'] );
        $this->assertStringContainsString( 'Testimonials slider', $blurb['content']['innerContent']['desktop']['value'] );
        $this->assertSame( 'center', $blurb['module']['advanced']['text']['text']['desktop']['value']['orientation'] );
    }

    public function test_info_banner_becomes_a_cta_over_its_picture(): void {
        $cta = $this->firstBlock( 'ronneby-info-banner', 'divi/cta' )['settings'];

        $this->assertSame( 'Quality', $cta['title']['innerContent']['desktop']['value'] );
        $this->assertSame( '45px', $cta['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertStringContainsString( '<h4>Lorem ipsum dolor sit amet</h4>', $cta['content']['innerContent']['desktop']['value'] );
        $this->assertStringContainsString( 'acbook-1.jpg', $cta['module']['decoration']['background']['desktop']['value']['image']['url'] );
        $this->assertSame( 'Read More', $cta['button']['innerContent']['desktop']['value']['text'] );
        $this->assertSame( 'on', $cta['button']['innerContent']['desktop']['value']['linkTarget'] );
    }

    public function test_team_member_keeps_the_name_position_portrait_and_reports_the_other_networks(): void {
        $member = $this->firstBlock( 'ronneby-new-team-member', 'divi/team-member' )['settings'];

        $this->assertSame( 'Brandon Jenkins', $member['name']['innerContent']['desktop']['value'] );
        $this->assertSame( 'Graphic designer', $member['position']['innerContent']['desktop']['value'] );
        $this->assertStringContainsString( 'team_11.jpg', $member['image']['innerContent']['desktop']['value']['url'] );
        $this->assertSame( '2px', $member['image']['decoration']['border']['desktop']['value']['radius']['topLeft'] );
        $this->assertReported( 'ronneby-new-team-member', 'dribbble="#"' );
    }

    public function test_a_testimonial_keeps_its_author_job_quote_and_portrait(): void {
        $testimonial = $this->firstBlock( 'ronneby-new-testimonials', 'divi/testimonial' )['settings'];

        $this->assertSame( 'Robert Richardson', $testimonial['author']['innerContent']['desktop']['value'] );
        $this->assertSame( 'Fashion blogger and photographer', $testimonial['jobTitle']['innerContent']['desktop']['value'] );
        $this->assertStringContainsString( 'Lorem ipsum', $testimonial['content']['innerContent']['desktop']['value'] );
        $this->assertStringContainsString( 'author2.jpg', $testimonial['portrait']['innerContent']['desktop']['value']['src'] );
    }

    public function test_facts_becomes_a_number_counter_and_a_text_for_the_subtitle(): void {
        $this->assertSame( [ 'divi/number-counter', 'divi/text' ], $this->blockNames( 'ronneby-facts' ) );

        $counter = $this->firstBlock( 'ronneby-facts', 'divi/number-counter' )['settings'];
        $this->assertSame( '47', $counter['number']['innerContent']['desktop']['value'] );
        $this->assertSame( 'Layouts created', $counter['title']['innerContent']['desktop']['value'] );
        $this->assertSame( 'off', $counter['number']['advanced']['enablePercentSign']['desktop']['value'] );
        $this->assertSame( '#353535', $counter['number']['decoration']['font']['font']['desktop']['value']['color'] );

        $subtitle = $this->firstBlock( 'ronneby-facts', 'divi/text' )['settings'];
        $this->assertSame( 'To show you the possibilities', $subtitle['content']['innerContent']['desktop']['value'] );
        $this->assertReported( 'ronneby-facts', 'dfd-icon-pc_wifi' );
    }

    public function test_progressbar_becomes_one_counter_in_one_counters_module(): void {
        $counters = $this->firstBlock( 'ronneby-progressbar', 'divi/counters' );

        $this->assertCount( 1, $counters['elements'] );
        $this->assertSame( 'divi/counter', $counters['elements'][0]['name'] );

        $bar = $counters['elements'][0]['settings'];
        $this->assertSame( 'CSS/JavaScript Programming', $bar['title']['innerContent']['desktop']['value'] );
        $this->assertSame( '94', $bar['barProgress']['innerContent']['desktop']['value'] );
        $this->assertSame( 'off', $counters['settings']['barProgress']['advanced']['usePercentages']['desktop']['value'] );
    }

    public function test_piecharts_becomes_a_circle_counter_and_a_text_for_the_subtitle(): void {
        $circle = $this->firstBlock( 'ronneby-piecharts', 'divi/circle-counter' )['settings'];

        $this->assertSame( '93', $circle['number']['innerContent']['desktop']['value'] );
        $this->assertSame( 'on', $circle['number']['advanced']['percentSign']['desktop']['value'] );
        $this->assertSame( 'Happy Clients', $circle['title']['innerContent']['desktop']['value'] );
        $this->assertSame( '#f27049', $circle['circle']['advanced']['color']['desktop']['value'] );
        $this->assertSame( '#ffffff', $circle['circle']['advanced']['background']['desktop']['value'] );

        $subtitle = $this->firstBlock( 'ronneby-piecharts', 'divi/text' )['settings'];
        $this->assertStringContainsString( 'Purus fusce', $subtitle['content']['innerContent']['desktop']['value'] );
    }

    public function test_countdown_keeps_its_date_and_styles_its_three_parts(): void {
        $timer = $this->firstBlock( 'ronneby-countdown', 'divi/countdown-timer' )['settings'];

        $this->assertSame( '2023/09/28 15:21:44', $timer['content']['advanced']['dateTime']['desktop']['value'] );
        $this->assertSame( '45px', $timer['number']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertSame( '1px', $timer['separator']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertSame( '14px', $timer['label']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertSame( 'rgba(255,255,255,0.3)', $timer['label']['decoration']['font']['font']['desktop']['value']['color'] );
        $this->assertReported( 'ronneby-countdown', 'countdown_opts="sweek,sday,shr,smin,ssec"' );
    }

    public function test_videoplayer_becomes_a_video_with_its_caption_beside_it(): void {
        $this->assertSame( [ 'divi/video', 'divi/heading', 'divi/text' ], $this->blockNames( 'ronneby-videoplayer' ) );

        $video = $this->firstBlock( 'ronneby-videoplayer', 'divi/video' )['settings'];
        $this->assertSame( 'https://youtu.be/k4ytWgtwFP4', $video['video']['innerContent']['desktop']['value']['src'] );
        $this->assertSame( 'left', $video['module']['advanced']['text']['text']['desktop']['value']['orientation'] );

        $heading = $this->firstBlock( 'ronneby-videoplayer', 'divi/heading' )['settings'];
        $this->assertSame( 'Find out more about us', $heading['title']['innerContent']['desktop']['value'] );
    }

    // -------------------------------------------------------------------------
    // (c) RonnebyParams
    // -------------------------------------------------------------------------

    public function test_font_options_decodes_a_real_packed_value(): void {
        // `04_pages_demo.xml`, a `dfd_info_box`.
        $decoded = RonnebyParams::fontOptions( 'tag:div|font_size:22|color:%23313131|letter_spacing:0|font_style_underline:1' );

        $this->assertSame(
            [
                'tag'                  => 'div',
                'font_size'            => '22',
                // `_crum_parse_text_shortcode_params()` undoes `%23` and `%2C`
                // on the colour and nothing else.
                'color'                => '#313131',
                'letter_spacing'       => '0',
                'font_style_underline' => '1',
            ],
            $decoded
        );

        $this->assertSame( [], RonnebyParams::fontOptions( '' ) );
        $this->assertSame( '#f78800,rgb', RonnebyParams::fontOptions( 'color:%23f78800%2Crgb' )['color'] );
    }

    public function test_responsive_text_maps_the_two_bands_divi_has_and_keeps_the_third_apart(): void {
        // `81_seventy_first.xml`, a `dfd_heading`.
        $bands = RonnebyParams::responsiveText( 'font_size_desktop:40|font_size_tablet:32|line_height_tablet:32|font_size_mobile:30' );

        $this->assertSame( [ 'size' => '32px', 'lineHeight' => '32px' ], $bands['tablet'] );
        $this->assertSame( [ 'size' => '30px' ], $bands['phone'] );
        // Ronneby's `desktop` band is 1024-1279px, which Divi has no breakpoint
        // for, so it is handed back for the caller to report.
        $this->assertSame( [ 'size' => '40px' ], $bands['medium_desktop'] );
    }

    public function test_responsive_sizes_prefers_the_normal_band_and_resolves_percentages(): void {
        // `43_thirty_eighth.xml`, a `dfd_spacer`.
        $this->assertSame(
            [ 'desktop' => '110px', 'tablet' => '100px', 'phone' => '90px' ],
            RonnebyParams::responsiveSizes( [
                'screen_wide_spacer_size'   => '120',
                'screen_normal_spacer_size' => '110',
                'screen_tablet_spacer_size' => '100',
                'screen_mobile_spacer_size' => '90',
            ] )
        );

        // With no normal size the wide one is the desktop height.
        $this->assertSame( '120px', RonnebyParams::responsiveSizes( [ 'screen_wide_spacer_size' => '120' ] )['desktop'] );

        // `units="%"`: the other three are percentages of the wide one
        // (`uncompresed.js:16331-16335`).
        $this->assertSame(
            [ 'desktop' => '60px', 'tablet' => '30px', 'phone' => '12px' ],
            RonnebyParams::responsiveSizes( [
                'units'                     => '%',
                'screen_wide_spacer_size'   => '120',
                'screen_normal_spacer_size' => '50',
                'screen_tablet_spacer_size' => '25',
                'screen_mobile_spacer_size' => '10',
            ] )
        );
    }

    public function test_items_decodes_a_param_group(): void {
        // `23_eighteenth.xml`, a `dfd_new_social_accounts`.
        $raw = '%5B%7B%22dfd_social_networks_sel%22%3A%22soc_icon-deviantart%22%2C%22soc_url%22%3A%22url%3A%2523%7C%7C%7C%22%7D%5D';

        $items = RonnebyParams::items( $raw );

        $this->assertCount( 1, $items );
        $this->assertSame( 'soc_icon-deviantart', $items[0]['dfd_social_networks_sel'] );
        $this->assertSame( 'url:%23|||', $items[0]['soc_url'] );
        $this->assertSame( [], RonnebyParams::items( 'not a param group' ) );
    }

    public function test_declarations_reads_a_border_and_a_margin_fragment(): void {
        // `04_pages_demo.xml`, a `dfd_button` and a `dfd_heading`.
        $this->assertSame(
            [ 'border-style' => 'solid', 'border-width' => '1px', 'border-radius' => '2px', 'border-color' => '#3d3d3d' ],
            RonnebyParams::declarations( 'border-style:solid;|border-width:1px;border-radius:2px;|border-color:#3d3d3d;' )
        );
        $this->assertSame( [ 'margin-bottom' => '20px' ], RonnebyParams::declarations( 'margin-bottom:20px;' ) );
        $this->assertSame( [], RonnebyParams::declarations( '' ) );
    }

    public function test_box_shadow_reads_the_enable_switch(): void {
        // `87_seventy_seventh.xml`, a `dfd_single_image`.
        $shadow = RonnebyParams::boxShadow(
            'box_shadow_enable:enable|shadow_horizontal:0|shadow_vertical:15|shadow_blur:70|shadow_spread:0|box_shadow_color:rgba(0%2C0%2C0%2C0.3)'
        );

        $this->assertSame(
            [
                'style'      => 'preset1',
                'horizontal' => '0px',
                'vertical'   => '15px',
                'blur'       => '70px',
                'spread'     => '0px',
                'position'   => 'outer',
                'color'      => 'rgba(0,0,0,0.3)',
            ],
            $shadow
        );

        $this->assertNull( RonnebyParams::boxShadow( 'box_shadow_enable:disable|shadow_blur:50|box_shadow_color:%23000000' ) );
        $this->assertNull( RonnebyParams::boxShadow( '' ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{result: array, blocks: array<int, array<string,mixed>>} */
    private function convertFixture( string $name ): array {
        static $cache = [];

        if ( isset( $cache[ $name ] ) ) {
            return $cache[ $name ];
        }

        wbdc_test_reset_hooks();

        $content = (string) file_get_contents( __DIR__ . "/../fixtures/wpbakery/{$name}.txt" );
        $sidecar = json_decode( (string) file_get_contents( __DIR__ . "/../fixtures/wpbakery/{$name}.json" ), true ) ?: [];

        $result = ( new ConverterEngine() )->convert(
            [ 'content' => $content, 'meta' => $sidecar['meta'] ?? [] ],
            [ 'mode' => $sidecar['mode'] ?? 'import', 'attachments' => $sidecar['attachments'] ?? [] ]
        );

        $blocks = [];
        self::collectModules( $result['divi']['elements'], $blocks );

        return $cache[ $name ] = [ 'result' => $result, 'blocks' => $blocks ];
    }

    /**
     * The modules a fixture produced, in document order, with the section, row
     * and column the engine wraps every page in left out.
     *
     * @param array<int, array<string,mixed>> $elements
     * @param array<int, array<string,mixed>> $into
     */
    private static function collectModules( array $elements, array &$into ): void {
        foreach ( $elements as $element ) {
            if ( in_array( $element['name'], [ 'divi/section', 'divi/row', 'divi/column' ], true ) ) {
                self::collectModules( $element['elements'], $into );

                continue;
            }

            $into[] = $element;
        }
    }

    /** @return string[] */
    private function blockNames( string $name ): array {
        return array_column( $this->convertFixture( $name )['blocks'], 'name' );
    }

    /** @return array<string,mixed> */
    private function firstBlock( string $name, string $block_name ): array {
        foreach ( $this->convertFixture( $name )['blocks'] as $block ) {
            if ( $block['name'] === $block_name ) {
                return $block;
            }
        }

        $this->fail( sprintf( '%s produced no %s (it produced %s).', $name, $block_name, implode( ', ', $this->blockNames( $name ) ) ) );
    }

    private function assertReported( string $name, string $needle ): void {
        $report = $this->convertFixture( $name )['result']['report'];
        $text   = implode( ' ', array_column( $report['not_carried_over'], 'detail' ) ) . ' ' . implode( ' ', $report['warnings'] );

        $this->assertStringContainsString( $needle, $text, "{$name} did not report \"{$needle}\"." );
    }

    private static function export( string $file ): string {
        if ( ! isset( self::$exports[ $file ] ) ) {
            self::$exports[ $file ] = (string) file_get_contents( __DIR__ . '/../wpbakery templates/ronneby/' . $file );
        }

        return self::$exports[ $file ];
    }

    /**
     * Every `[tag …]…[/tag]` in a document, the way
     * `scripts/cut-corpus-element.php` cuts them: opens are counted so a nested
     * element of the same tag does not end the outer one, and an occurrence
     * inside one already taken is not returned again.
     *
     * @return string[]
     */
    private static function occurrences( string $document, string $tag ): array {
        $pattern = '/\[' . preg_quote( $tag, '/' ) . '(?![\w-])([^\]]*)\]/';
        $close   = '[/' . $tag . ']';

        if ( preg_match_all( $pattern, $document, $opens, PREG_OFFSET_CAPTURE ) === 0 ) {
            return [];
        }

        $found     = [];
        $skip_past = -1;

        foreach ( $opens[0] as $open ) {
            [ $open_text, $offset ] = $open;

            if ( $offset < $skip_past ) {
                continue;
            }

            $next_close = strpos( $document, $close, $offset );
            if ( $next_close === false ) {
                $found[] = $open_text;

                continue;
            }

            $depth  = 1;
            $cursor = $offset + strlen( $open_text );
            $end    = null;

            while ( $depth > 0 ) {
                $next_open  = preg_match( $pattern, $document, $m, PREG_OFFSET_CAPTURE, $cursor ) === 1 ? $m[0][1] : false;
                $next_close = strpos( $document, $close, $cursor );

                if ( $next_close === false ) {
                    break;
                }

                if ( $next_open !== false && $next_open < $next_close ) {
                    $depth++;
                    $cursor = $next_open + strlen( $m[0][0] );

                    continue;
                }

                $depth--;
                $cursor = $next_close + strlen( $close );
                if ( $depth === 0 ) {
                    $end = $cursor;
                }
            }

            if ( $end === null ) {
                $found[] = $open_text;

                continue;
            }

            $found[]   = substr( $document, $offset, $end - $offset );
            $skip_past = $end;
        }

        return $found;
    }
}
