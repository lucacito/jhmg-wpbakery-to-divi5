<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

/**
 * The composite, container, widget and add-on handlers (Task 9), for the
 * behaviour fixture equality cannot pin down: what lands in the report, which
 * Divi module a WPBakery element resolves to, the two conversions that have to
 * agree with each other (a legacy `vc_accordion` and its `vc_tta_accordion`
 * equivalent), and the promise that nothing this batch touches is a skipped
 * setting.
 *
 * The *shape* of the output is pinned by the goldens in fixtures/divi/.
 */
final class ContentHandlersBTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    /** @param array{mode?: string, attachments?: array, render_shortcodes?: bool} $options */
    private function convert( string $content, array $options = [] ): array {
        return ( new ConverterEngine() )->convert(
            [ 'content' => $content ],
            array_merge( [ 'mode' => 'import' ], $options )
        );
    }

    private function row( string $inner ): string {
        return '[vc_row][vc_column]' . $inner . '[/vc_column][/vc_row]';
    }

    /** The first block of the first column of the first row. */
    private function firstModule( array $result ): array {
        return $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'][0];
    }

    /** Every block of the first column. */
    private function modules( array $result ): array {
        return $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'];
    }

    private function read( array $settings, string $path ): mixed {
        $current = $settings;
        foreach ( explode( '.', $path ) as $key ) {
            if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
                return null;
            }
            $current = $current[ $key ];
        }

        return $current;
    }

    private function details( array $result ): string {
        return implode( "\n", array_column( $result['report']['not_carried_over'], 'detail' ) );
    }

    // -------------------------------------------------------------------------
    // Every tag in this batch has a handler
    // -------------------------------------------------------------------------

    /** @return array<string, string> tag ⇒ one instance of it */
    private static function batch(): array {
        return [
            'vc_tta_accordion'  => '[vc_tta_accordion][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]',
            'vc_tta_tabs'       => '[vc_tta_tabs][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_tabs]',
            'vc_tta_tour'       => '[vc_tta_tour][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_tour]',
            'vc_tta_pageable'   => '[vc_tta_pageable][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_pageable]',
            'vc_tta_toggle'     => '[vc_tta_toggle][vc_tta_toggle_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_toggle_section][/vc_tta_toggle]',
            'vc_accordion'      => '[vc_accordion][vc_accordion_tab title="A"][vc_column_text]A[/vc_column_text][/vc_accordion_tab][/vc_accordion]',
            'vc_tabs'           => '[vc_tabs][vc_tab title="A"][vc_column_text]A[/vc_column_text][/vc_tab][/vc_tabs]',
            'vc_gallery'        => '[vc_gallery images="7,8" img_size="medium" type="image_grid"]',
            'vc_media_grid'     => '[vc_media_grid include="7,8" items_per_row="3" gap="10px" item="mediaGrid_Default" grid_id="vc_gid:1"]',
            'vc_masonry_media_grid' => '[vc_masonry_media_grid include="7,8" items_per_row="3"]',
            'vc_images_carousel' => '[vc_images_carousel images="7,8" img_size="full" autoplay="yes" speed="4000" slides_per_view="2" mode="horizontal"]',
            'vc_progress_bar'   => '[vc_progress_bar values="%5B%7B%22label%22%3A%22One%22%2C%22value%22%3A%2260%22%7D%5D" units="%" striped="true"]',
            'vc_pie'            => '[vc_pie value="70" title="Done" units="%" custom_color="#5472d2"]',
            'vc_round_chart'    => '[vc_round_chart type="pie" style="flat" animation="easeOutBounce" stroke_width="2" custom_stroke_color="#ffffff" legend="yes" tooltips="yes" legend_position="left" custom_legend_color="#2a2a2a" values="%5B%7B%22title%22%3A%22One%22%2C%22value%22%3A%2260%22%2C%22custom_color%22%3A%22%235472d2%22%7D%5D"]',
            'vc_line_chart'     => '[vc_line_chart type="bar" x_values="Jan;Feb" legend="yes" values="%5B%7B%22title%22%3A%22One%22%2C%22y_values%22%3A%221%3B2%22%2C%22custom_color%22%3A%22%235472d2%22%7D%5D"]',
        ];
    }

    public function test_no_element_in_this_batch_is_unsupported_any_more(): void {
        $tags = self::batch();

        $result = $this->convert( $this->row( implode( '', $tags ) ) );

        $this->assertSame( [], $result['unsupported'], 'every tag in batch B has a handler' );
    }

    public function test_no_element_in_this_batch_reports_a_skipped_setting(): void {
        foreach ( self::batch() as $tag => $shortcode ) {
            wbdc_test_reset_hooks();
            $result = $this->convert( $this->row( $shortcode ) );

            $this->assertSame(
                [],
                $result['report']['skipped_settings'],
                $tag . ' left a skipped setting behind'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Accordions, tabs, tours
    // -------------------------------------------------------------------------

    public function test_an_accordion_becomes_an_accordion_with_one_item_per_section(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion][vc_tta_section title="One"][vc_column_text]First[/vc_column_text][/vc_tta_section]'
            . '[vc_tta_section title="Two"][vc_column_text]Second[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $accordion = $this->firstModule( $result );

        $this->assertSame( 'divi/accordion', $accordion['name'] );
        $this->assertCount( 2, $accordion['elements'] );
        $this->assertSame( 'divi/accordion-item', $accordion['elements'][0]['name'] );
        $this->assertSame( 'One', $this->read( $accordion['elements'][0]['settings'], 'title.innerContent.desktop.value' ) );
        $this->assertStringContainsString( 'Second', (string) $this->read( $accordion['elements'][1]['settings'], 'content.innerContent.desktop.value' ) );
    }

    public function test_active_section_two_is_reported_because_divi_always_opens_the_first_panel(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion active_section="2"][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section]'
            . '[vc_tta_section title="Two"][vc_column_text]B[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $this->assertStringContainsString( 'active_section="2"', $this->details( $result ) );
        $this->assertStringContainsString( 'Divi 5.12 always opens the first one', $this->details( $result ) );
    }

    public function test_a_legacy_accordion_converts_to_the_same_blocks_as_its_tta_equivalent(): void {
        $legacy = $this->convert( $this->row(
            '[vc_accordion collapsible="true" active_tab="2"][vc_accordion_tab title="One"][vc_column_text]A[/vc_column_text][/vc_accordion_tab][/vc_accordion]'
        ) );

        wbdc_test_reset_hooks();

        $modern = $this->convert( $this->row(
            '[vc_tta_accordion collapsible_all="true" active_section="2"][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $this->assertSame(
            self::withoutIds( $this->firstModule( $legacy ) ),
            self::withoutIds( $this->firstModule( $modern ) )
        );
        $this->assertSame( [], $legacy['report']['skipped_settings'] );
    }

    public function test_a_tour_is_a_tabs_module_and_says_so(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_tour][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_tour]'
        ) );

        $this->assertSame( 'divi/tabs', $this->firstModule( $result )['name'] );
        $this->assertSame( 'divi/tab', $this->firstModule( $result )['elements'][0]['name'] );
        $this->assertStringContainsString( 'stacks its controls down one side', $this->details( $result ) );
    }

    public function test_the_tta_colour_scheme_lands_on_the_open_and_closed_toggles(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion color="grey" active_color="#5472d2" active_title_color="#ffffff" inactive_title_color="#2a2a2a"]'
            . '[vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( '#ebebeb', $this->read( $settings, 'closedToggle.decoration.background.desktop.value.color' ) );
        $this->assertSame( '#5472d2', $this->read( $settings, 'openToggle.decoration.background.desktop.value.color' ) );
        $this->assertSame( '#ffffff', $this->read( $settings, 'openToggle.decoration.font.font.desktop.value.color' ) );
        $this->assertSame( '#2a2a2a', $this->read( $settings, 'closedToggle.decoration.font.font.desktop.value.color' ) );
    }

    public function test_a_tta_element_takes_its_own_bottom_margin_not_the_content_one(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        // js_composer_tta.min.css: .vc_tta-container{margin-bottom:21.73913043px}
        $this->assertSame(
            '21.74px',
            $this->read( $this->firstModule( $result )['settings'], 'module.decoration.spacing.desktop.value.margin.bottom' )
        );
    }

    // -------------------------------------------------------------------------
    // Galleries, carousels, counters and charts
    // -------------------------------------------------------------------------

    public function test_a_gallery_keeps_its_attachment_ids_in_order(): void {
        $result = $this->convert( $this->row( '[vc_gallery images="12,7,9" type="image_grid"]' ) );

        $gallery = $this->firstModule( $result );

        $this->assertSame( 'divi/gallery', $gallery['name'] );
        $this->assertSame( [ '12', '7', '9' ], $this->read( $gallery['settings'], 'image.advanced.galleryIds.desktop.value' ) );
        $this->assertSame( 'off', $this->read( $gallery['settings'], 'module.advanced.fullwidth.desktop.value' ) );
    }

    public function test_a_flexslider_gallery_is_a_fullwidth_gallery(): void {
        $result = $this->convert( $this->row( '[vc_gallery images="12,7" type="flexslider_slide" interval="5"]' ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'on', $this->read( $settings, 'module.advanced.fullwidth.desktop.value' ) );
        $this->assertSame( 'on', $this->read( $settings, 'module.advanced.auto.desktop.value' ) );
        $this->assertSame( '5000', $this->read( $settings, 'module.advanced.autoSpeed.desktop.value' ) );
    }

    public function test_a_media_grid_sets_its_column_count_and_gap(): void {
        $result = $this->convert( $this->row( '[vc_media_grid include="1,2,3" items_per_row="3" gap="10"]' ) );

        $layout = $this->read( $this->firstModule( $result )['settings'], 'galleryGrid.decoration.layout.desktop.value' );

        $this->assertSame( 'grid', $layout['display'] );
        $this->assertSame( '3', $layout['gridColumnCount'] );
        $this->assertSame( '10px', $layout['columnGap'] );
        $this->assertSame( '10px', $layout['rowGap'] );
    }

    public function test_an_external_gallery_becomes_a_flexed_row_of_images(): void {
        $result = $this->convert( $this->row(
            '[vc_gallery source="external_link" custom_srcs="https://example.com/a.jpg' . "\n" . 'https://example.com/b.jpg"]'
        ) );

        $row = $this->firstModule( $result );

        $this->assertSame( 'divi/row', $row['name'] );
        $this->assertSame( 'flex', $this->read( $row['elements'][0]['settings'], 'module.decoration.layout.desktop.value.display' ) );
        $this->assertCount( 2, $row['elements'][0]['elements'] );
        $this->assertSame( 'divi/image', $row['elements'][0]['elements'][0]['name'] );
        $this->assertStringContainsString( 'holds URLs rather than media-library ids', $this->details( $result ) );
    }

    public function test_a_carousel_is_one_slide_per_image_with_the_picture_behind_it(): void {
        $result = $this->convert(
            $this->row( '[vc_images_carousel images="7,8" img_size="full"]' ),
            [ 'attachments' => [ 7 => [ 'url' => 'https://example.com/a.jpg' ], 8 => [ 'url' => 'https://example.com/b.jpg' ] ] ]
        );

        $slider = $this->firstModule( $result );

        $this->assertSame( 'divi/slider', $slider['name'] );
        $this->assertCount( 2, $slider['elements'] );
        $this->assertSame( 'divi/slide', $slider['elements'][0]['name'] );
        $this->assertSame(
            'https://example.com/a.jpg',
            $this->read( $slider['elements'][0]['settings'], 'module.decoration.background.desktop.value.image.url' )
        );
    }

    public function test_a_carousel_reports_the_swiper_options_divi_has_none_of(): void {
        $result = $this->convert( $this->row( '[vc_images_carousel images="7" slides_per_view="3" partial_view="yes" mode="vertical"]' ) );

        $details = $this->details( $result );

        $this->assertStringContainsString( 'slides_per_view="3"', $details );
        $this->assertStringContainsString( 'partial_view', $details );
        $this->assertStringContainsString( 'mode="vertical"', $details );
    }

    public function test_a_progress_bar_becomes_one_counter_per_values_entry(): void {
        // values = [{label:"Design",value:"90",customcolor:"#5472d2"},{label:"Code",value:"70"}]
        $values = rawurlencode( (string) json_encode( [
            [ 'label' => 'Design', 'value' => '90', 'customcolor' => '#5472d2' ],
            [ 'label' => 'Code', 'value' => '70' ],
        ] ) );

        $result   = $this->convert( $this->row( '[vc_progress_bar values="' . $values . '"]' ) );
        $counters = $this->firstModule( $result );

        $this->assertSame( 'divi/counters', $counters['name'] );
        $this->assertCount( 2, $counters['elements'] );
        $this->assertSame( 'divi/counter', $counters['elements'][0]['name'] );
        $this->assertSame( 'Design', $this->read( $counters['elements'][0]['settings'], 'title.innerContent.desktop.value' ) );
        $this->assertSame( '90', $this->read( $counters['elements'][0]['settings'], 'barProgress.innerContent.desktop.value' ) );
        $this->assertSame( '#5472d2', $this->read( $counters['elements'][0]['settings'], 'barProgress.decoration.background.desktop.value.color' ) );
        $this->assertSame( 'Code', $this->read( $counters['elements'][1]['settings'], 'title.innerContent.desktop.value' ) );
    }

    public function test_a_pie_is_a_circle_counter_with_its_own_title(): void {
        $result   = $this->convert( $this->row( '[vc_pie value="70" label_value="70" units="%" custom_color="#5472d2" title="Complete"]' ) );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'divi/circle-counter', $this->firstModule( $result )['name'] );
        $this->assertSame( '70', $this->read( $settings, 'number.innerContent.desktop.value' ) );
        $this->assertSame( 'on', $this->read( $settings, 'number.advanced.percentSign.desktop.value' ) );
        $this->assertSame( 'Complete', $this->read( $settings, 'title.innerContent.desktop.value' ) );
        $this->assertSame( '#5472d2', $this->read( $settings, 'circle.advanced.color.desktop.value' ) );
        // The pie prints its title under the ring, exactly as Divi's does, so
        // there is no separate heading block.
        $this->assertCount( 1, $this->modules( $result ) );
    }

    public function test_a_round_chart_is_the_category_value_family(): void {
        $values = rawurlencode( (string) json_encode( [
            [ 'title' => 'One', 'value' => '60', 'custom_color' => '#5472d2' ],
            [ 'title' => 'Two', 'value' => '40', 'custom_color' => '#fe6c61' ],
        ] ) );

        $result   = $this->convert( $this->row( '[vc_round_chart type="doughnut" legend="yes" tooltips="yes" values="' . $values . '"]' ) );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'divi/charts', $this->firstModule( $result )['name'] );
        $this->assertSame( 'doughnut', $this->read( $settings, 'chart.advanced.config.desktop.value.type' ) );
        $this->assertSame( 'on', $this->read( $settings, 'chart.advanced.config.desktop.value.showLegend' ) );

        $data = $this->read( $settings, 'chart.innerContent.desktop.value.data' );

        $this->assertSame( 'category', $data['columns'][0]['role'] );
        $this->assertSame( 'value', $data['columns'][1]['role'] );
        $this->assertSame( [ 'One', '60' ], $data['rows'][0]['cells'] );
        $this->assertSame( '#5472d2', $data['rows'][0]['color'] );
        $this->assertSame( '#fe6c61', $data['rows'][1]['color'] );
    }

    public function test_a_line_chart_is_one_series_column_per_values_entry(): void {
        $values = rawurlencode( (string) json_encode( [
            [ 'title' => 'Sales', 'y_values' => '10;20;30', 'custom_color' => '#5472d2' ],
            [ 'title' => 'Costs', 'y_values' => '5;6;7' ],
        ] ) );

        $result = $this->convert( $this->row( '[vc_line_chart type="line" x_values="Jan;Feb;Mar" values="' . $values . '"]' ) );
        $data   = $this->read( $this->firstModule( $result )['settings'], 'chart.innerContent.desktop.value.data' );

        $this->assertSame( 'category', $data['columns'][0]['role'] );
        $this->assertSame( 'series', $data['columns'][1]['role'] );
        $this->assertSame( 'Sales', $data['columns'][1]['label'] );
        $this->assertSame( '#5472d2', $data['columns'][1]['color'] );
        // A series with no colour of its own gets none: Divi's DefaultPalette fills it.
        $this->assertArrayNotHasKey( 'color', $data['columns'][2] );
        $this->assertSame( [ 'Jan', '10', '5' ], $data['rows'][0]['cells'] );
        $this->assertSame( [ 'Mar', '30', '7' ], $data['rows'][2]['cells'] );
    }

    public function test_a_chart_reports_the_styling_divi_has_no_key_for(): void {
        $values  = rawurlencode( (string) json_encode( [ [ 'title' => 'One', 'value' => '60' ] ] ) );
        $result  = $this->convert( $this->row( '[vc_round_chart style="modern" animation="easeOutBounce" stroke_width="2" custom_stroke_color="#ffffff" values="' . $values . '"]' ) );
        $details = $this->details( $result );

        $this->assertStringContainsString( 'style="modern"', $details );
        $this->assertStringContainsString( 'stroke_width="2"', $details );
    }

        /** Ids are generated from the source position, so two equivalent pages differ only there. */
    private static function withoutIds( array $block ): array {
        unset( $block['id'] );

        foreach ( $block['elements'] ?? [] as $index => $child ) {
            $block['elements'][ $index ] = self::withoutIds( $child );
        }

        return $block;
    }
}
