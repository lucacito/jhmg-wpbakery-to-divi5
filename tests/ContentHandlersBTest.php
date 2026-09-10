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
            'vc_cta'            => '[vc_cta h2="Heading" h4="Sub" style="flat" shape="round" custom_background="#5472d2" custom_text="#ffffff" txt_align="center" el_width="80" add_button="bottom" btn_title="Go" btn_link="url:https%3A%2F%2Fexample.com" add_icon="left" i_type="fontawesome" i_icon_fontawesome="fas fa-star"]Body[/vc_cta]',
            'vc_hoverbox'       => '[vc_hoverbox image="7" primary_title="Front" hover_title="Back" shape="rounded" align="left" el_width="80" hover_custom_background="#5472d2" hover_add_button="true" hover_btn_title="Go"]Body[/vc_hoverbox]',
            'vc_pricing_table'  => '[vc_pricing_table heading="Basic" subheading="Per seat" currency="$" price="9" period="month" markers_color="#5472d2" add_button="true" btn_title="Buy"]<ul><li>One</li></ul>[/vc_pricing_table]',
            'vc_basic_grid'     => '[vc_basic_grid post_type="post" items_per_page="6" items_per_row="3" gap="10" item="basicGrid_NoAnimation" style="load-more" grid_id="vc_gid:1" orderby="title" order="ASC" taxonomies="4,9"]',
            'vc_masonry_grid'   => '[vc_masonry_grid post_type="post" max_items="6" items_per_row="3"]',
            'vc_posts_slider'   => '[vc_posts_slider count="3" posttypes="post" interval="5" type="flexslider_fade" slides_content="teaser" link="link_post" categories="4" orderby="title" order="ASC" thumb_size="large"]',
            'vc_widget_sidebar' => '[vc_widget_sidebar sidebar_id="sidebar-1" title="More"]',
            'vc_wp_custommenu'  => '[vc_wp_custommenu nav_menu="4" title="Menu"]',
            'vc_wp_search'      => '[vc_wp_search title="Search"]',
            'vc_wp_text'        => '[vc_wp_text title="Note"]Body[/vc_wp_text]',
            'vc_wp_archives'    => '[vc_wp_archives title="Archives" type="dropdown" count="true"]',
            'vc_wp_calendar'    => '[vc_wp_calendar title="Calendar"]',
            'vc_wp_categories'  => '[vc_wp_categories title="Categories" count="true" hierarchical="true"]',
            'vc_wp_links'       => '[vc_wp_links category="3" orderby="name"]',
            'vc_wp_meta'        => '[vc_wp_meta title="Meta"]',
            'vc_wp_pages'       => '[vc_wp_pages title="Pages" sortby="menu_order"]',
            'vc_wp_posts'       => '[vc_wp_posts title="Posts" number="5" show_date="true"]',
            'vc_wp_recentcomments' => '[vc_wp_recentcomments title="Comments" number="5"]',
            'vc_wp_rss'         => '[vc_wp_rss url="https://example.com/feed" items="5"]',
            'vc_wp_tagcloud'    => '[vc_wp_tagcloud title="Tags" taxonomy="post_tag"]',
            'vc_facebook'       => '[vc_facebook type="standard"]',
            'vc_tweetmeme'      => '[vc_tweetmeme type="share"]',
            'vc_pinterest'      => '[vc_pinterest type="horizontal"]',
            'vc_googleplus'     => '[vc_googleplus type="standard" annotation="bubble"]',
            'vc_flickr'         => '[vc_flickr flickr_id="12345@N00" count="6" type="user" display="latest"]',
            'rev_slider_vc'     => '[rev_slider_vc alias="home-slider"]',
            'rev_slider'        => '[rev_slider alias="home-slider"]',
            'layerslider_vc'    => '[layerslider_vc id="3"]',
            'products'          => '[products limit="4" columns="4" category="hoodies"]',
            'contact-form-7'    => '[contact-form-7 id="1234" title="Contact form 1"]',
            'vc_flexbox_container' => '[vc_flexbox_container gap="20px"][vc_flexbox_container_item][vc_column_text]A[/vc_column_text][/vc_flexbox_container_item][/vc_flexbox_container]',
            'vc_grid_container' => '[vc_grid_container columns="3" rows="2" col_gap="10px" row_gap="5px"][vc_grid_container_item][vc_column_text]A[/vc_column_text][/vc_grid_container_item][/vc_grid_container]',
            'bsf-info-box'      => '[bsf-info-box icon_type="selector" icon="Defaults-database" icon_size="32" icon_color="#333333" icon_style="circle" icon_color_bg="#ffffff" title="Hosting" heading_tag="h4" pos="top" read_more="more" read_text="Read More" link="url:https%3A%2F%2Fexample.com" title_font_size="desktop:20px;" title_font_color="#111111" desc_font_size="desktop:14px;"]Fast and reliable.[/bsf-info-box]',
            'just_icon'         => '[just_icon icon="Defaults-database" icon_size="45" icon_color="#ffffff" icon_style="circle" icon_color_bg="#ffb400" icon_align="center" icon_link="url:https%3A%2F%2Fexample.com"]',
            'stat_counter'      => '[stat_counter icon_size="32" icon_color="#ffffff" counter_title="PROJECTS" counter_value="82" counter_prefix="+" counter_suffix="%" counter_sep="," speed="3" counter_color_txt="#ffffff" title_font_size="desktop:20px;" desc_font_size="desktop:80px;"]',
            'ultimate_pricing'  => '[ultimate_pricing design_style="design03" package_heading="Health" package_price="10.25" package_unit="Month" package_btn_text="Choose Plan" package_link="url:https%3A%2F%2Fexample.com"]One' . "\n" . 'Two[/ultimate_pricing]',
            'ultimate_video'    => '[ultimate_video u_video_url="https://www.youtube.com/watch?v=abc" yt_autoplay="" default_thumb="hqdefault" play_source="icon" play_icon="Defaults-play-circle-o" play_size="90" icon_color="#ffffff" icon_hover_color="#ef1a5a"]',
            'ult_content_box'   => '[ult_content_box bg_color="#f7f7f7" padding="padding-top:20px;padding-bottom:20px;" border="border-style:solid;|border-width:1px;|border-color:#cccccc;" box_shadow="horizontal:px|vertical:px|blur:px|spread:px|style:none|" min_height="200"][vc_column_text]A[/vc_column_text][/ult_content_box]',
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

        // -------------------------------------------------------------------------
    // CTAs, hover boxes, pricing tables, post queries
    // -------------------------------------------------------------------------

    public function test_a_cta_carries_its_heading_subheading_body_and_button(): void {
        $result = $this->convert( $this->row(
            '[vc_cta h2="Ready?" h4="It only takes a minute" style="flat" custom_background="#5472d2" custom_text="#ffffff" shape="round" txt_align="center" add_button="bottom" btn_title="Start" btn_link="url:https%3A%2F%2Fexample.com%2Fstart"]Sign up today.[/vc_cta]'
        ) );

        $cta = $this->firstModule( $result );

        $this->assertSame( 'divi/cta', $cta['name'] );
        $this->assertSame( 'Ready?', $this->read( $cta['settings'], 'title.innerContent.desktop.value' ) );
        $this->assertStringContainsString( '<h4>It only takes a minute</h4>', (string) $this->read( $cta['settings'], 'content.innerContent.desktop.value' ) );
        $this->assertStringContainsString( '<p>Sign up today.</p>', (string) $this->read( $cta['settings'], 'content.innerContent.desktop.value' ) );
        $this->assertSame( '#5472d2', $this->read( $cta['settings'], 'module.decoration.background.desktop.value.color' ) );
        $this->assertSame( '#ffffff', $this->read( $cta['settings'], 'title.decoration.font.font.desktop.value.color' ) );
        $this->assertSame( '4em', $this->read( $cta['settings'], 'module.decoration.border.desktop.value.radius.topLeft' ) );
        $this->assertSame( 'center', $this->read( $cta['settings'], 'module.advanced.text.text.desktop.value.orientation' ) );
        // The button is BtnConverter's own mapping, lifted onto the CTA.
        $this->assertSame( 'Start', $this->read( $cta['settings'], 'button.innerContent.desktop.value.text' ) );
        $this->assertSame( 'https://example.com/start', $this->read( $cta['settings'], 'button.innerContent.desktop.value.linkUrl' ) );
    }

    public function test_a_cta_takes_wpbakerys_own_default_padding_and_classic_colours(): void {
        $result   = $this->convert( $this->row( '[vc_cta h2="Plain"]Body[/vc_cta]' ) );
        $settings = $this->firstModule( $result )['settings'];

        // `.vc_do_cta3{padding:28px;…}` and the classic style's own colours.
        $this->assertSame( '28px', $this->read( $settings, 'module.decoration.spacing.desktop.value.padding.top' ) );
        $this->assertSame( '#f7f7f7', $this->read( $settings, 'module.decoration.background.desktop.value.color' ) );
        $this->assertSame( '#f0f0f0', $this->read( $settings, 'module.decoration.border.desktop.value.styles.all.color' ) );
        $this->assertSame( '35px', $this->read( $settings, 'module.decoration.spacing.desktop.value.margin.bottom' ) );
    }

    public function test_a_legacy_cta_button2_converts_to_the_same_cta(): void {
        // AttributeNormaliser rewrites vc_cta_button2 → vc_cta.
        $result = $this->convert( $this->row(
            '[vc_cta_button2 h2="Ready?" txt_align="center" title="Start" link="url:https%3A%2F%2Fexample.com%2Fstart"]Sign up today.[/vc_cta_button2]'
        ) );

        $this->assertSame( 'divi/cta', $this->firstModule( $result )['name'] );
        $this->assertSame( [], $result['report']['skipped_settings'] );
    }

    public function test_a_hoverbox_is_a_blurb_and_its_flip_is_reported(): void {
        $result = $this->convert(
            $this->row( '[vc_hoverbox image="7" primary_title="Front" hover_title="Back" hover_custom_background="#5472d2" hover_add_button="true" hover_btn_title="Go" hover_btn_link="url:https%3A%2F%2Fexample.com"]Body copy.[/vc_hoverbox]' ),
            [ 'attachments' => [ 7 => [ 'url' => 'https://example.com/a.jpg' ] ] ]
        );

        $blocks = $this->modules( $result );

        $this->assertSame( 'divi/blurb', $blocks[0]['name'] );
        $this->assertSame( 'Front', $this->read( $blocks[0]['settings'], 'title.innerContent.desktop.value.text' ) );
        $this->assertStringContainsString( '<h4>Back</h4>', (string) $this->read( $blocks[0]['settings'], 'content.innerContent.desktop.value' ) );
        $this->assertSame( 'https://example.com/a.jpg', $this->read( $blocks[0]['settings'], 'imageIcon.innerContent.desktop.value.src' ) );
        $this->assertSame( '#5472d2', $this->read( $blocks[0]['settings'], 'module.decoration.background.desktop.value.color' ) );
        // The hover button has no home on a blurb, so it follows it.
        $this->assertSame( 'divi/button', $blocks[1]['name'] );
        $this->assertSame( 'Go', $this->read( $blocks[1]['settings'], 'button.innerContent.desktop.value.text' ) );

        $this->assertStringContainsString( 'flips between a picture and a coloured panel', $this->details( $result ) );
    }

    public function test_a_pricing_table_is_a_one_table_set(): void {
        $result = $this->convert( $this->row(
            '[vc_pricing_table heading="Basic" subheading="Per seat" currency="$" price="9" period="month" markers_color="#5472d2" add_button="true" btn_title="Buy"]<ul><li>One</li><li>Two</li></ul>[/vc_pricing_table]'
        ) );

        $tables = $this->firstModule( $result );

        $this->assertSame( 'divi/pricing-tables', $tables['name'] );
        $this->assertCount( 1, $tables['elements'] );

        $table = $tables['elements'][0]['settings'];

        $this->assertSame( 'divi/pricing-table', $tables['elements'][0]['name'] );
        $this->assertSame( 'Basic', $this->read( $table, 'title.innerContent.desktop.value' ) );
        $this->assertSame( 'Per seat', $this->read( $table, 'subtitle.innerContent.desktop.value' ) );
        $this->assertSame( '9', $this->read( $table, 'price.innerContent.desktop.value' ) );
        $this->assertSame( '$', $this->read( $table, 'currencyFrequency.innerContent.desktop.value.currency' ) );
        $this->assertSame( 'month', $this->read( $table, 'currencyFrequency.innerContent.desktop.value.per' ) );
        $this->assertStringContainsString( '<li>One</li>', (string) $this->read( $table, 'content.innerContent.desktop.value' ) );
        $this->assertSame( '#5472d2', $this->read( $table, 'content.advanced.bulletColor.desktop.value' ) );
        $this->assertSame( 'Buy', $this->read( $table, 'button.innerContent.desktop.value.text' ) );
    }

    public function test_a_pricing_table_has_no_default_bottom_margin(): void {
        // `.vc_do_pricing_table` sets padding, radius, border and background —
        // and no margin-bottom at all.
        $result   = $this->convert( $this->row( '[vc_pricing_table heading="Basic" price="9"]<ul><li>One</li></ul>[/vc_pricing_table]' ) );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertNull( $this->read( $settings, 'module.decoration.spacing.desktop.value.margin' ) );
        $this->assertSame( '30px', $this->read( $settings, 'module.decoration.spacing.desktop.value.padding.top' ) );
        $this->assertSame( '20px', $this->read( $settings, 'module.decoration.spacing.desktop.value.padding.right' ) );
        $this->assertSame( '#ececec', $this->read( $settings, 'module.decoration.background.desktop.value.color' ) );
    }

    public function test_a_basic_grid_is_a_blog_module_with_the_query_carried_over(): void {
        $GLOBALS['__test_terms'] = [
            4 => [ 'taxonomy' => 'category', 'name' => 'News' ],
            9 => [ 'taxonomy' => 'category', 'name' => 'Guides' ],
        ];

        $result   = $this->convert(
            $this->row( '[vc_basic_grid post_type="page" items_per_page="6" items_per_row="3" gap="10" taxonomies="4,9" style="load-more"]' ),
            [ 'mode' => 'direct' ]
        );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'divi/blog', $this->firstModule( $result )['name'] );
        $this->assertSame( 'page', $this->read( $settings, 'post.advanced.type.desktop.value' ) );
        $this->assertSame( '6', $this->read( $settings, 'post.advanced.number.desktop.value' ) );
        $this->assertSame( [ '4', '9' ], $this->read( $settings, 'post.advanced.categories.desktop.value' ) );
        $this->assertSame( '3', $this->read( $settings, 'blogGrid.decoration.layout.desktop.value.gridColumnCount' ) );
        $this->assertSame( 'on', $this->read( $settings, 'pagination.advanced.enable.desktop.value' ) );
    }

    public function test_only_category_terms_reach_divis_category_filter(): void {
        // `taxonomies` searches every taxonomy the post type has, and a term
        // from another one would empty the module rather than narrow it.
        $GLOBALS['__test_terms'] = [
            4  => [ 'taxonomy' => 'category', 'name' => 'News' ],
            9  => [ 'taxonomy' => 'portfolio_category', 'name' => 'Print' ],
            31 => [ 'taxonomy' => 'post_tag', 'name' => 'featured' ],
        ];

        $result = $this->convert(
            $this->row( '[vc_basic_grid post_type="post" taxonomies="4,9,31,404"]' ),
            [ 'mode' => 'direct' ]
        );

        $this->assertSame( [ '4' ], $this->read( $this->firstModule( $result )['settings'], 'post.advanced.categories.desktop.value' ) );

        $details = $this->details( $result );
        $this->assertStringContainsString( '9 is a portfolio_category term', $details );
        $this->assertStringContainsString( '31 is a post_tag term', $details );
        $this->assertStringContainsString( '404 is not a term on this site', $details );
        $this->assertStringContainsString( 'only the category terms were kept', $details );
    }

    public function test_a_grid_converted_from_an_export_is_left_unfiltered_and_says_so(): void {
        // No database to ask, so no filter at all: an unfiltered module can be
        // narrowed, an empty one has to be debugged.
        $result = $this->convert( $this->row( '[vc_basic_grid post_type="post" taxonomies="4,9"]' ) );

        $this->assertNull( $this->read( $this->firstModule( $result )['settings'], 'post.advanced.categories.desktop.value' ) );
        $this->assertStringContainsString( 'there is no database to check them against', $this->details( $result ) );
        $this->assertStringContainsString( 'taxonomies="4,9"', $this->details( $result ) );
    }

    public function test_a_posts_slider_category_name_is_reported_rather_than_dropped(): void {
        // `vc_posts_slider`'s `categories` holds category *names*
        // (config/content/shortcode-vc-posts-slider.php:134), which Divi's
        // post slider cannot filter on.
        $GLOBALS['__test_terms'] = [ 4 => [ 'taxonomy' => 'category', 'name' => 'News' ] ];

        $result = $this->convert(
            $this->row( '[vc_posts_slider count="3" categories="News' . "\n" . '4"]' ),
            [ 'mode' => 'direct' ]
        );

        $this->assertSame( [ '4' ], $this->read( $this->firstModule( $result )['settings'], 'post.advanced.categories.desktop.value' ) );
        $this->assertStringContainsString( '"News" is a name, not a term id', $this->details( $result ) );
    }

    public function test_a_posts_slider_maps_the_order_pair_divi_understands(): void {
        $result   = $this->convert( $this->row( '[vc_posts_slider count="3" orderby="title" order="ASC" interval="5"]' ) );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'divi/post-slider', $this->firstModule( $result )['name'] );
        $this->assertSame( 'title_asc', $this->read( $settings, 'post.advanced.orderby.desktop.value' ) );
        $this->assertSame( '5000', $this->read( $settings, 'module.advanced.autoSpeed.desktop.value' ) );
    }

    public function test_an_order_divi_cannot_express_is_reported(): void {
        $result = $this->convert( $this->row( '[vc_posts_slider count="3" orderby="comment_count" order="DESC"]' ) );

        $this->assertStringContainsString( 'orderby="comment_count"', $this->details( $result ) );
        $this->assertNull( $this->read( $this->firstModule( $result )['settings'], 'post.advanced.orderby.desktop.value' ) );
    }

    // -------------------------------------------------------------------------
    // Widgets, social buttons, sliders, WooCommerce, Contact Form 7
    // -------------------------------------------------------------------------

    public function test_a_widget_sidebar_keeps_its_area(): void {
        $result = $this->convert( $this->row( '[vc_widget_sidebar sidebar_id="sidebar-footer-col2" title="More"]' ) );
        $blocks = $this->modules( $result );

        $this->assertSame( 'divi/heading', $blocks[0]['name'] );
        $this->assertSame( 'divi/sidebar', $blocks[1]['name'] );
        $this->assertSame( 'sidebar-footer-col2', $this->read( $blocks[1]['settings'], 'sidebar.innerContent.desktop.value.area' ) );
    }

    public function test_a_custom_menu_keeps_its_menu_id_and_search_and_text_become_their_modules(): void {
        $result = $this->convert( $this->row( '[vc_wp_custommenu nav_menu="7"][vc_wp_search][vc_wp_text]Hi[/vc_wp_text]' ) );
        $blocks = $this->modules( $result );

        $this->assertSame( 'divi/menu', $blocks[0]['name'] );
        $this->assertSame( '7', $this->read( $blocks[0]['settings'], 'menu.advanced.menuId.desktop.value' ) );
        $this->assertSame( 'divi/search', $blocks[1]['name'] );
        $this->assertSame( 'divi/text', $blocks[2]['name'] );
        $this->assertSame( '<p>Hi</p>', $this->read( $blocks[2]['settings'], 'content.innerContent.desktop.value' ) );
    }

    public function test_a_wp_archives_widget_is_rendered_on_this_site_and_a_placeholder_from_an_export(): void {
        $GLOBALS['__test_rendered_widgets'] = [ 'WP_Widget_Archives' => '<ul><li>June 2026</li></ul>' ];

        $direct = $this->convert( $this->row( '[vc_wp_archives title="Archives"]' ), [ 'mode' => 'direct', 'render_shortcodes' => true ] );
        $blocks = $this->modules( $direct );

        $this->assertSame( 'divi/code', $blocks[1]['name'] );
        $this->assertStringContainsString( '<li>June 2026</li>', (string) $this->read( $blocks[1]['settings'], 'content.innerContent.desktop.value' ) );
        $this->assertContains( 'vc_wp_archives-1', $direct['report']['static_copies'] );

        wbdc_test_reset_hooks();

        $import = $this->convert( $this->row( '[vc_wp_archives title="Archives" type="dropdown"]' ) );
        $html   = (string) $this->read( $this->modules( $import )[1]['settings'], 'content.innerContent.desktop.value' );

        $this->assertStringContainsString( 'wbdc-unconverted-element', $html );
        $this->assertStringContainsString( 'WP_Widget_Archives', $html );
        $this->assertStringContainsString( 'type: dropdown', $html );
        $this->assertSame( [], $import['report']['static_copies'] );
    }

    public function test_a_share_button_is_a_placeholder_naming_its_network(): void {
        $result = $this->convert( $this->row( '[vc_tweetmeme type="share"]' ) );

        $this->assertSame( 'divi/code', $this->firstModule( $result )['name'] );
        $this->assertStringContainsString( 'X (Twitter) widget', $this->details( $result ) );
    }

    public function test_a_slider_bridge_names_its_deck_and_is_filed_under_sliders(): void {
        $result = $this->convert( $this->row( '[rev_slider_vc alias="home-slider"]' ) );

        $this->assertSame( 'divi/code', $this->firstModule( $result )['name'] );
        $this->assertStringContainsString( 'shows the "home-slider" slider', $this->details( $result ) );
        $this->assertArrayHasKey( 'Sliders', $result['report']['theme_elements'] );
        $this->assertSame( [], $result['unsupported'] );
    }

    public function test_a_woocommerce_shortcode_is_kept_as_the_shortcode(): void {
        $result = $this->convert( $this->row( '[products limit="4" columns="4" category="hoodies" el_class="shop"]' ) );

        $html = (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' );

        $this->assertSame( 'divi/code', $this->firstModule( $result )['name'] );
        $this->assertSame( '[products limit="4" columns="4" category="hoodies"]', $html );
        // el_class went onto the block, not back into the shortcode.
        $this->assertSame( 'shop', $this->read( $this->firstModule( $result )['settings'], 'module.advanced.htmlAttributes.desktop.value.class' ) );
        $this->assertArrayHasKey( 'WooCommerce', $result['report']['theme_elements'] );
    }

    public function test_contact_form_7_becomes_divis_own_module(): void {
        $result = $this->convert( $this->row( '[contact-form-7 id="1234" title="Contact form 1"]' ) );

        $this->assertSame( 'divi/contact-form-7', $this->firstModule( $result )['name'] );
        $this->assertSame( '1234', $this->read( $this->firstModule( $result )['settings'], 'form.advanced.formId.desktop.value' ) );
        $this->assertCount( 1, $this->modules( $result ) );
    }

    // -------------------------------------------------------------------------
    // The 9.0 containers
    // -------------------------------------------------------------------------

    public function test_a_flexbox_container_is_a_flexed_group_of_groups(): void {
        $result = $this->convert( $this->row(
            '[vc_flexbox_container gap="20px"][vc_flexbox_container_item][vc_column_text]A[/vc_column_text][/vc_flexbox_container_item]'
            . '[vc_flexbox_container_item][vc_column_text]B[/vc_column_text][/vc_flexbox_container_item][/vc_flexbox_container]'
        ) );

        $group = $this->firstModule( $result );

        $this->assertSame( 'divi/group', $group['name'] );
        $this->assertSame(
            [ 'display' => 'flex', 'flexWrap' => 'wrap', 'columnGap' => '20px', 'rowGap' => '20px' ],
            $this->read( $group['settings'], 'module.decoration.layout.desktop.value' )
        );
        // `.vc_flexbox_container{margin-left:-15px;margin-right:-15px}`
        $this->assertSame( '-15px', $this->read( $group['settings'], 'module.decoration.spacing.desktop.value.margin.left' ) );
        $this->assertCount( 2, $group['elements'] );
        $this->assertSame( 'divi/group', $group['elements'][0]['name'] );
        $this->assertSame( 'divi/text', $group['elements'][0]['elements'][0]['name'] );
    }

    public function test_a_container_with_no_gap_writes_a_zero_with_its_unit(): void {
        $result = $this->convert( $this->row( '[vc_flexbox_container][vc_flexbox_container_item][vc_column_text]A[/vc_column_text][/vc_flexbox_container_item][/vc_flexbox_container]' ) );

        // Divi tests a gap for truthiness, so "0px" and never "0".
        $this->assertSame( '0px', $this->read( $this->firstModule( $result )['settings'], 'module.decoration.layout.desktop.value.columnGap' ) );
    }

    public function test_a_grid_container_uses_divis_own_grid_keys(): void {
        $result = $this->convert( $this->row(
            '[vc_grid_container columns="3" rows="2" col_gap="10px" row_gap="5px"][vc_grid_container_item][vc_column_text]A[/vc_column_text][/vc_grid_container_item][/vc_grid_container]'
        ) );

        $group  = $this->firstModule( $result );
        $layout = $this->read( $group['settings'], 'module.decoration.layout.desktop.value' );

        $this->assertSame( 'grid', $layout['display'] );
        $this->assertSame( 'equal', $layout['gridColumnWidths'] );
        $this->assertSame( '3', $layout['gridColumnCount'] );
        $this->assertSame( '10px', $layout['columnGap'] );
        $this->assertSame( '5px', $layout['rowGap'] );
        // `.vc_grid_container_item>.vc_grid_container_item-inner{padding:0 15px}`
        $this->assertSame( '15px', $this->read( $group['elements'][0]['settings'], 'module.decoration.spacing.desktop.value.padding.left' ) );
        // WPBakery writes --grid-rows but never reads it.
        $this->assertStringContainsString( 'rows="2"', $this->details( $result ) );
    }

    // -------------------------------------------------------------------------
    // Ultimate Addons
    // -------------------------------------------------------------------------

    public function test_an_info_box_is_a_blurb_with_its_icon_title_and_copy(): void {
        $result = $this->convert( $this->row(
            '[bsf-info-box icon_type="selector" icon="Defaults-database" icon_color="#333333" icon_size="32" title="Hosting" heading_tag="h4" pos="left" title_font_size="desktop:20px;" title_font_color="#111111"]Fast and reliable.[/bsf-info-box]'
        ) );

        $blurb = $this->firstModule( $result );

        $this->assertSame( 'divi/blurb', $blurb['name'] );
        $this->assertSame( 'on', $this->read( $blurb['settings'], 'imageIcon.innerContent.desktop.value.useIcon' ) );
        // unicode, type, weight in that order.
        $this->assertSame(
            [ 'unicode', 'type', 'weight' ],
            array_keys( (array) $this->read( $blurb['settings'], 'imageIcon.innerContent.desktop.value.icon' ) )
        );
        $this->assertSame( '#333333', $this->read( $blurb['settings'], 'imageIcon.advanced.color.desktop.value' ) );
        $this->assertSame( 'left', $this->read( $blurb['settings'], 'imageIcon.advanced.placement.desktop.value' ) );
        $this->assertSame( 'Hosting', $this->read( $blurb['settings'], 'title.innerContent.desktop.value.text' ) );
        $this->assertSame( 'h4', $this->read( $blurb['settings'], 'title.decoration.font.font.desktop.value.headingLevel' ) );
        $this->assertSame( '20px', $this->read( $blurb['settings'], 'title.decoration.font.font.desktop.value.size' ) );
        $this->assertSame( '#111111', $this->read( $blurb['settings'], 'title.decoration.font.font.desktop.value.color' ) );
        $this->assertStringContainsString( 'Fast and reliable.', (string) $this->read( $blurb['settings'], 'content.innerContent.desktop.value' ) );
    }

    public function test_an_info_box_with_a_custom_image_icon_uses_the_picture(): void {
        $result = $this->convert( $this->row(
            '[bsf-info-box icon_type="custom" icon_img="id^4282|url^https://example.com/icon.png|caption^null|alt^null|title^icon|description^null" img_width="80" title="Join"]Copy.[/bsf-info-box]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'off', $this->read( $settings, 'imageIcon.innerContent.desktop.value.useIcon' ) );
        $this->assertSame( 'https://example.com/icon.png', $this->read( $settings, 'imageIcon.innerContent.desktop.value.src' ) );
        $this->assertSame( '80px', $this->read( $settings, 'imageIcon.decoration.sizing.desktop.value.width' ) );
    }

    public function test_an_info_box_read_more_link_becomes_a_button_after_it(): void {
        $result = $this->convert( $this->row(
            '[bsf-info-box title="Hosting" read_more="more" read_text="Learn more" link="url:https%3A%2F%2Fexample.com%2Fhosting"]Copy.[/bsf-info-box]'
        ) );

        $blocks = $this->modules( $result );

        $this->assertCount( 2, $blocks );
        $this->assertSame( 'divi/button', $blocks[1]['name'] );
        $this->assertSame( 'Learn more', $this->read( $blocks[1]['settings'], 'button.innerContent.desktop.value.text' ) );
    }

    public function test_a_just_icon_is_an_icon_module_and_its_ring_is_reported(): void {
        $result = $this->convert( $this->row(
            '[just_icon icon="Defaults-database" icon_size="45" icon_color="#ffffff" icon_style="circle" icon_color_bg="#ffb400" icon_align="center"]'
        ) );

        $icon = $this->firstModule( $result );

        $this->assertSame( 'divi/icon', $icon['name'] );
        $this->assertSame( '45px', $this->read( $icon['settings'], 'icon.advanced.size.desktop.value' ) );
        $this->assertSame( '#ffffff', $this->read( $icon['settings'], 'icon.advanced.color.desktop.value' ) );
        $this->assertSame( 'center', $this->read( $icon['settings'], 'icon.advanced.align.desktop.value' ) );
        $this->assertStringContainsString( 'icon_style="circle"', $this->details( $result ) );
    }

    public function test_a_stat_counter_is_a_number_counter(): void {
        $result   = $this->convert( $this->row( '[stat_counter counter_title="PROJECTS" counter_value="82" counter_prefix="+" counter_suffix="%" speed="3"]' ) );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'divi/number-counter', $this->firstModule( $result )['name'] );
        $this->assertSame( 'PROJECTS', $this->read( $settings, 'title.innerContent.desktop.value' ) );
        $this->assertSame( '+82%', $this->read( $settings, 'number.innerContent.desktop.value' ) );
        // The add-on prints its own suffix, so Divi's percent sign stays off.
        $this->assertSame( 'off', $this->read( $settings, 'number.advanced.enablePercentSign.desktop.value' ) );
        $this->assertStringContainsString( 'speed="3"', $this->details( $result ) );
    }

    public function test_an_ultimate_pricing_table_turns_its_lines_into_a_list(): void {
        $result = $this->convert( $this->row(
            '[ultimate_pricing design_style="design03" package_heading="Health" package_price="10.25" package_unit="Month" package_btn_text="Choose Plan" package_link="url:https%3A%2F%2Fexample.com"]One' . "\n" . 'Two[/ultimate_pricing]'
        ) );

        $tables = $this->firstModule( $result );
        $table  = $tables['elements'][0]['settings'];

        $this->assertSame( 'divi/pricing-tables', $tables['name'] );
        $this->assertSame( 'Health', $this->read( $table, 'title.innerContent.desktop.value' ) );
        $this->assertSame( '10.25', $this->read( $table, 'price.innerContent.desktop.value' ) );
        $this->assertSame( 'Month', $this->read( $table, 'currencyFrequency.innerContent.desktop.value.per' ) );
        $this->assertSame( '<ul><li>One</li><li>Two</li></ul>', $this->read( $table, 'content.innerContent.desktop.value' ) );
        $this->assertSame( 'Choose Plan', $this->read( $table, 'button.innerContent.desktop.value.text' ) );
        $this->assertStringContainsString( 'design_style="design03"', $this->details( $result ) );
    }

    public function test_an_ultimate_video_is_a_video_module_with_its_custom_thumbnail(): void {
        $result = $this->convert( $this->row(
            '[ultimate_video u_video_url="https://www.youtube.com/watch?v=abc" thumbnail="custom" custom_thumb="id^90|url^https://example.com/poster.jpg|alt^null" play_size="90" icon_color="#ffffff"]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'divi/video', $this->firstModule( $result )['name'] );
        $this->assertSame( 'https://www.youtube.com/watch?v=abc', $this->read( $settings, 'video.innerContent.desktop.value.src' ) );
        $this->assertSame( 'https://example.com/poster.jpg', $this->read( $settings, 'video.innerContent.desktop.value.thumbnailSrc' ) );
        $this->assertStringContainsString( 'play button', $this->details( $result ) );
    }

    public function test_a_content_box_is_a_group_with_its_box_and_its_children(): void {
        $result = $this->convert( $this->row(
            '[ult_content_box bg_color="#f7f7f7" padding="padding-top:20px;padding-bottom:30px;" border="border-style:solid;|border-width:2px;|border-color:#cccccc;" min_height="200" hover_bg_color="#eeeeee"][vc_column_text]Inside.[/vc_column_text][/ult_content_box]'
        ) );

        $group = $this->firstModule( $result );

        $this->assertSame( 'divi/group', $group['name'] );
        $this->assertSame( '#f7f7f7', $this->read( $group['settings'], 'module.decoration.background.desktop.value.color' ) );
        $this->assertSame( '20px', $this->read( $group['settings'], 'module.decoration.spacing.desktop.value.padding.top' ) );
        $this->assertSame( '30px', $this->read( $group['settings'], 'module.decoration.spacing.desktop.value.padding.bottom' ) );
        $this->assertSame(
            [ 'width' => '2px', 'style' => 'solid', 'color' => '#cccccc' ],
            $this->read( $group['settings'], 'module.decoration.border.desktop.value.styles.all' )
        );
        $this->assertSame( '200px', $this->read( $group['settings'], 'module.decoration.sizing.desktop.value.minHeight' ) );
        $this->assertSame( 'divi/text', $group['elements'][0]['name'] );
        $this->assertStringContainsString( 'hover_bg_color', $this->details( $result ) );
    }

    // -------------------------------------------------------------------------
    // Fix round 1
    // -------------------------------------------------------------------------

    public function test_an_info_box_read_more_box_links_the_whole_module(): void {
        $result = $this->convert( $this->row(
            '[bsf-info-box title="Hosting" read_more="box" link="url:https%3A%2F%2Fexample.com%2Fhosting|target:_blank"]Copy.[/bsf-info-box]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'https://example.com/hosting', $this->read( $settings, 'module.advanced.link.desktop.value.url' ) );
        $this->assertSame( 'on', $this->read( $settings, 'module.advanced.link.desktop.value.target' ) );
        $this->assertCount( 1, $this->modules( $result ) );
    }

    public function test_an_info_box_read_more_title_links_the_title(): void {
        $result = $this->convert( $this->row(
            '[bsf-info-box title="Hosting" read_more="title" link="url:https%3A%2F%2Fexample.com%2Fhosting"]Copy.[/bsf-info-box]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'https://example.com/hosting', $this->read( $settings, 'title.innerContent.desktop.value.url' ) );
        $this->assertSame( 'off', $this->read( $settings, 'title.innerContent.desktop.value.target' ) );
        $this->assertNull( $this->read( $settings, 'module.advanced.link.desktop.value.url' ) );
    }

    public function test_a_pricing_tables_heading_font_lands_on_the_title(): void {
        $result = $this->convert( $this->row(
            '[vc_pricing_table heading="Basic" subheading="Per seat" price="9" use_custom_fonts_heading="true"'
            . ' heading_font_container="tag:h3|font_size:28px|color:%23111111|text_align:center"'
            . ' use_custom_fonts_subheading="true" subheading_font_container="font_size:14px|color:%23666666"]<ul><li>One</li></ul>[/vc_pricing_table]'
        ) );

        $table = $this->firstModule( $result )['elements'][0]['settings'];

        $this->assertSame( '28px', $this->read( $table, 'title.decoration.font.font.desktop.value.size' ) );
        $this->assertSame( '#111111', $this->read( $table, 'title.decoration.font.font.desktop.value.color' ) );
        $this->assertSame( '14px', $this->read( $table, 'subtitle.decoration.font.font.desktop.value.size' ) );
        $this->assertSame( '#666666', $this->read( $table, 'subtitle.decoration.font.font.desktop.value.color' ) );
    }

    public function test_a_hoverbox_primary_title_font_lands_on_the_blurb_title_and_the_hover_one_is_reported(): void {
        $result = $this->convert( $this->row(
            '[vc_hoverbox primary_title="Design" hover_title="What we do"'
            . ' use_custom_fonts_primary_title="true" primary_title_font_container="font_size:30px|color:%23000000|line_height:40px"'
            . ' use_custom_fonts_hover_title="true" hover_title_font_container="font_size:24px|color:%23575c71"]Copy.[/vc_hoverbox]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( '30px', $this->read( $settings, 'title.decoration.font.font.desktop.value.size' ) );
        $this->assertSame( '#000000', $this->read( $settings, 'title.decoration.font.font.desktop.value.color' ) );
        $this->assertStringContainsString( 'the hover title\'s custom font', $this->details( $result ) );
    }

    public function test_an_ultimate_font_family_is_applied_and_its_style_fragment_reported(): void {
        $result = $this->convert( $this->row(
            '[bsf-info-box title="Hosting" title_font="Montserrat" title_font_style="font-weight:bold;font-style:italic;"]Copy.[/bsf-info-box]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'Montserrat', $this->read( $settings, 'title.decoration.font.font.desktop.value.family' ) );
        $this->assertSame( '700', $this->read( $settings, 'title.decoration.font.font.desktop.value.weight' ) );
        $this->assertStringContainsString( 'title_font_style="font-weight:bold;font-style:italic;"', $this->details( $result ) );
    }

    public function test_an_ultimate_pricing_font_family_is_applied(): void {
        $result = $this->convert( $this->row(
            '[ultimate_pricing package_heading="Health" package_price="10" package_name_font_family="Montserrat" package_name_font_size="desktop:24px;"]One[/ultimate_pricing]'
        ) );

        $table = $this->firstModule( $result )['elements'][0]['settings'];

        $this->assertSame( 'Montserrat', $this->read( $table, 'title.decoration.font.font.desktop.value.family' ) );
        $this->assertSame( '24px', $this->read( $table, 'title.decoration.font.font.desktop.value.size' ) );
    }

    public function test_a_btn_field_the_button_handler_does_not_map_is_reported_inside_a_cta(): void {
        // `embeddedButton()` returns the fields it actually read, re-prefixed,
        // so anything else under `btn_` still reaches logUnmappedSettings().
        // `btn_css` is a field WPBakery declares for `vc_cta` (the embedded
        // button's design-options box), so it is a gap here rather than
        // somebody else's parameter.
        $result = $this->convert( $this->row( '[vc_cta h2="Hi" add_button="bottom" btn_title="Go" btn_css=".x{color:red}"]Body[/vc_cta]' ) );

        $this->assertContains( 'vc_cta-1: btn_css', $result['report']['skipped_settings'] );
    }

    public function test_a_posts_slider_with_no_description_keeps_divis_excerpt_and_says_so(): void {
        $result   = $this->convert( $this->row( '[vc_posts_slider count="3" slides_content=""]' ) );
        $settings = $this->firstModule( $result )['settings'];

        // `content.advanced.showOnMobile` is a mobile-visibility toggle, not
        // the content source: writing "off" there would lose the copy on phones
        // and keep it on the desktop.
        $this->assertNull( $this->read( $settings, 'content.advanced.showOnMobile.desktop.value' ) );
        $this->assertStringContainsString( 'always prints an excerpt', $this->details( $result ) );
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function marginProvider(): array {
        return [
            // element, expected bottom margin (null = none written at all)
            'hoverbox has no wrapper margin'      => [ '[vc_hoverbox primary_title="A"]Copy.[/vc_hoverbox]', null ],
            'contact-form-7 is not a wpb element' => [ '[contact-form-7 id="12"]', null ],
            'just_icon has no wrapper margin'     => [ '[just_icon icon="Defaults-database"]', null ],
            'ult_content_box is a container'      => [ '[ult_content_box][vc_column_text]A[/vc_column_text][/ult_content_box]', null ],
            // .aio-icon-component / .stats-block / .ult_pricing_table_wrap
            'bsf-info-box: info-box.css 35px'     => [ '[bsf-info-box title="A"]Copy.[/bsf-info-box]', '35px' ],
            'stat_counter: stats-counter.css'     => [ '[stat_counter counter_title="A" counter_value="1"]', '35px' ],
            'ultimate_pricing: pricing.css'       => [ '[ultimate_pricing package_heading="A" package_price="1"]One[/ultimate_pricing]', '35px' ],
            // .ult-video{margin:20px}
            'ultimate_video: video_module.css'    => [ '[ultimate_video u_video_url="https://youtu.be/abc"]', '20px' ],
            // .fb_like / .wpb_pinterest / .wpb_googleplus beat .wpb_content_element
            'vc_facebook: the later 21.74px rule' => [ '[vc_facebook type="standard"]', '21.74px' ],
            'vc_pinterest: the later rule'        => [ '[vc_pinterest type="horizontal"]', '21.74px' ],
            'vc_googleplus: the later rule'       => [ '[vc_googleplus type="standard"]', '21.74px' ],
            // twitter-share-button is on the anchor, not the wrapper
            'vc_tweetmeme keeps wpb_content_element' => [ '[vc_tweetmeme type="share"]', '35px' ],
            'vc_flickr keeps wpb_content_element'    => [ '[vc_flickr flickr_id="1@N00"]', '35px' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'marginProvider' )]
    public function test_each_element_takes_the_bottom_margin_its_own_stylesheet_gives_it( string $shortcode, ?string $expected ): void {
        $result = $this->convert( $this->row( $shortcode ) );
        $module = $this->firstModule( $result );

        $path = $module['name'] === 'divi/image'
            ? 'module.advanced.spacing.desktop.value.margin.bottom'
            : 'module.decoration.spacing.desktop.value.margin.bottom';

        $this->assertSame( $expected, $this->read( $module['settings'], $path ) );
    }

    public function test_an_ultimate_video_takes_its_margin_on_all_four_sides(): void {
        // `assets/css/video_module.css`: `.ult-video{margin:20px}`.
        $margin = $this->read(
            $this->firstModule( $this->convert( $this->row( '[ultimate_video u_video_url="https://youtu.be/abc"]' ) ) )['settings'],
            'module.decoration.spacing.desktop.value.margin'
        );

        $this->assertSame( '20px', $margin['top'] );
        $this->assertSame( '20px', $margin['right'] );
        $this->assertSame( '20px', $margin['bottom'] );
        $this->assertSame( '20px', $margin['left'] );
    }

    public function test_an_element_that_writes_nothing_is_not_counted_as_converted(): void {
        $result = $this->convert( $this->row(
            '[vc_images_carousel][vc_progress_bar][vc_tta_accordion][/vc_tta_accordion]'
        ) );

        $converted = $result['report']['converted'];

        $this->assertArrayNotHasKey( 'slider', $converted );
        $this->assertArrayNotHasKey( 'counters', $converted );
        $this->assertArrayNotHasKey( 'accordion', $converted );
    }

    public function test_a_toggle_containers_own_fill_switch_is_read_and_reported(): void {
        // `vc_tta_toggle` is not in the normaliser's TTA_TAGS, so it keeps
        // `no_fill_content_area` rather than the 9.0 spelling.
        $result = $this->convert( $this->row(
            '[vc_tta_toggle no_fill_content_area="true"][vc_tta_toggle_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_toggle_section][/vc_tta_toggle]'
        ) );

        $this->assertSame( [], $result['report']['skipped_settings'] );
        $this->assertStringContainsString( 'fill_content_area is off', $this->details( $result ) );
    }

    public function test_a_pie_with_an_empty_colour_still_paints_wpbakerys_default(): void {
        // `$custom_color ?: '#ebebeb'` — a blank value takes the default too.
        $result = $this->convert( $this->row( '[vc_pie value="70" custom_color=""]' ) );

        $this->assertSame(
            '#ebebeb',
            $this->read( $this->firstModule( $result )['settings'], 'circle.advanced.color.desktop.value' )
        );
    }

    public function test_an_external_gallery_row_is_counted_by_the_blocks_it_writes(): void {
        $result = $this->convert( $this->row(
            '[vc_gallery source="external_link" custom_srcs="https://example.com/a.jpg"]'
        ) );

        $converted = $result['report']['converted'];

        // The page's own `vc_row` plus the one the gallery built.
        $this->assertSame( 2, $converted['row'] ?? 0 );
        $this->assertArrayNotHasKey( 'row_inner', $converted );
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
