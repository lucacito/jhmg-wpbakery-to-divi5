<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Parsers\AttributeNormaliser;

/**
 * Both WPBakery attribute eras folded into 9.0.1's shapes.
 *
 * The expected hex values are quoted as literals here on purpose: they are the
 * documentation of WPBakery's 17-name palette (`vc_convert_vc_color()`), and a
 * test that computed them the way the implementation does would assert nothing.
 * The normaliser itself always goes through `Color::fromPalette()`.
 */
final class AttributeNormaliserTest extends TestCase {

    /** @param array<string, mixed> $atts */
    private function atts( string $tag, array $atts ): array {
        return AttributeNormaliser::normalise( $tag, $atts )['atts'];
    }

    // --- pass-through -------------------------------------------------------

    public function test_an_unknown_tag_passes_through_untouched(): void {
        $atts   = [ 'css' => '.vc_custom_1{color: red;}', 'el_class' => 'promo' ];
        $result = AttributeNormaliser::normalise( 'rev_slider_vc', $atts );

        $this->assertSame( 'rev_slider_vc', $result['tag'] );
        $this->assertSame( $atts, $result['atts'] );
        $this->assertSame( [], $result['notes'] );
    }

    public function test_a_9_0_1_button_passes_through_untouched(): void {
        $atts   = [ 'style' => 'custom', 'custom_background' => '#5472d2', 'custom_text' => '#ffffff' ];
        $result = AttributeNormaliser::normalise( 'vc_btn', $atts );

        $this->assertSame( 'vc_btn', $result['tag'] );
        $this->assertSame( $atts, $result['atts'] );
        $this->assertSame( [], $result['notes'] );
    }

    public function test_the_result_always_carries_the_full_shape(): void {
        $this->assertSame(
            [ 'tag', 'atts', 'notes' ],
            array_keys( AttributeNormaliser::normalise( 'vc_row', [] ) )
        );
    }

    // --- deprecated elements: buttons ---------------------------------------

    public function test_vc_button_becomes_a_vc_btn_with_a_packed_link(): void {
        $result = AttributeNormaliser::normalise( 'vc_button', [
            'href'   => 'https://x',
            'target' => '_blank',
            'title'  => 'Go',
            'size'   => 'btn-large',
            'style'  => 'rounded',
        ] );

        $this->assertSame( 'vc_btn', $result['tag'] );
        $this->assertEquals(
            [
                'title' => 'Go',
                'size'  => 'lg',
                'style' => 'flat',
                'shape' => 'rounded',
                'link'  => 'url:https%3A%2F%2Fx|title:Go|target:_blank',
            ],
            $result['atts']
        );
        $this->assertContains( 'vc_button → vc_btn', $result['notes'] );
    }

    public function test_the_packed_link_reads_back_through_packed_params(): void {
        $atts = $this->atts( 'vc_button', [ 'href' => 'https://example.com/go', 'title' => 'Go', 'target' => '_blank' ] );

        $this->assertSame(
            [ 'url' => 'https://example.com/go', 'title' => 'Go', 'target' => '_blank', 'rel' => '' ],
            PackedParams::link( $atts['link'] )
        );
    }

    public function test_a_button_href_without_a_title_titles_the_link_with_the_url(): void {
        $atts = $this->atts( 'vc_button', [ 'href' => 'https://x' ] );

        $this->assertSame( 'url:https%3A%2F%2Fx|title:https://x', $atts['link'] );
    }

    public function test_every_button_1_size_maps_to_its_button_3_size(): void {
        foreach ( [ 'wpb_regularsize' => 'md', 'btn-large' => 'lg', 'btn-small' => 'sm', 'btn-mini' => 'xs' ] as $old => $new ) {
            $this->assertSame( $new, $this->atts( 'vc_button', [ 'size' => $old ] )['size'], $old );
        }
    }

    public function test_every_button_1_style_maps_to_a_button_3_style_and_shape(): void {
        $expected = [
            'rounded'         => [ 'style' => 'flat', 'shape' => 'rounded' ],
            'square'          => [ 'style' => 'flat', 'shape' => 'square' ],
            'round'           => [ 'style' => 'flat', 'shape' => 'round' ],
            'outlined'        => [ 'style' => 'outline' ],
            'square_outlined' => [ 'style' => 'outline', 'shape' => 'square' ],
        ];

        foreach ( $expected as $old => $new ) {
            $this->assertEquals( $new, $this->atts( 'vc_button2', [ 'style' => $old ] ), $old );
        }
    }

    public function test_a_button_1_icon_becomes_a_pixel_icon(): void {
        $atts = $this->atts( 'vc_button', [ 'title' => 'Read', 'icon' => 'wpb_book' ] );

        $this->assertSame( 'true', $atts['add_icon'] );
        $this->assertSame( 'pixelicons', $atts['i_type'] );
        $this->assertSame( 'right', $atts['i_align'] );
        $this->assertSame( 'vc_pixel_icon vc_pixel_icon-book', $atts['i_icon_pixelicons'] );
        $this->assertArrayNotHasKey( 'icon', $atts );
    }

    public function test_a_button_1_icon_of_none_is_dropped(): void {
        $atts = $this->atts( 'vc_button', [ 'icon' => 'none' ] );

        $this->assertArrayNotHasKey( 'add_icon', $atts );
    }

    public function test_vc_cta_button_becomes_a_vc_btn_and_keeps_its_call_text(): void {
        $result = AttributeNormaliser::normalise( 'vc_cta_button', [
            'call_text' => 'Ready to start?',
            'title'     => 'Go',
            'href'      => 'https://x',
        ] );

        $this->assertSame( 'vc_btn', $result['tag'] );
        $this->assertSame( 'Ready to start?', $result['atts']['call_text'] );
        $this->assertContains( 'vc_cta_button → vc_btn', $result['notes'] );
    }

    public function test_vc_cta_button2_becomes_a_vc_cta_with_an_integrated_button(): void {
        $result = AttributeNormaliser::normalise( 'vc_cta_button2', [
            'h2'       => 'Grow your business',
            'h4'       => 'Today',
            'title'    => 'Sign up',
            'link'     => 'url:https%3A%2F%2Fx',
            'position' => 'right',
            'size'     => 'btn-large',
            'style'    => 'square_outlined',
        ] );

        $this->assertSame( 'vc_cta', $result['tag'] );
        $this->assertEquals(
            [
                'h2'           => 'Grow your business',
                'h4'           => 'Today',
                'add_button'   => 'true',
                'btn_title'    => 'Sign up',
                'btn_link'     => 'url:https%3A%2F%2Fx',
                'btn_position' => 'right',
                'btn_size'     => 'lg',
                'style'        => 'outline',
                'shape'        => 'square',
            ],
            $result['atts']
        );
        $this->assertContains( 'vc_cta_button2 → vc_cta', $result['notes'] );
    }

    public function test_a_cta_button2_palette_colour_becomes_the_integrated_buttons_custom_colour(): void {
        $atts = $this->atts( 'vc_cta_button2', [ 'title' => 'Go', 'btn_style' => 'flat', 'color' => 'blue' ] );

        $this->assertSame( 'custom', $atts['btn_style'] );
        $this->assertSame( '#5472d2', $atts['btn_custom_background'] );
        $this->assertSame( '#ffffff', $atts['btn_custom_text'] );
        $this->assertArrayNotHasKey( 'color', $atts );
    }

    // --- deprecated elements: tabs, tours, accordions ------------------------

    public function test_vc_tabs_becomes_vc_tta_tabs_and_interval_is_noted(): void {
        $result = AttributeNormaliser::normalise( 'vc_tabs', [ 'interval' => '5', 'title' => 'Tabs' ] );

        $this->assertSame( 'vc_tta_tabs', $result['tag'] );
        $this->assertSame( [ 'interval' => '5', 'title' => 'Tabs' ], $result['atts'] );
        $this->assertContains( 'vc_tabs → vc_tta_tabs', $result['notes'] );
        $this->assertContains( 'vc_tta_tabs: interval has no 9.0.1 equivalent', $result['notes'] );
    }

    public function test_the_deprecated_container_family_maps_onto_tta(): void {
        $expected = [
            'vc_tabs'          => 'vc_tta_tabs',
            'vc_tour'          => 'vc_tta_tour',
            'vc_tab'           => 'vc_tta_section',
            'vc_accordion'     => 'vc_tta_accordion',
            'vc_accordion_tab' => 'vc_tta_section',
            'vc_gmaps'         => 'vc_gmaps',
        ];

        foreach ( $expected as $old => $new ) {
            $this->assertSame( $new, AttributeNormaliser::normalise( $old, [] )['tag'], $old );
        }
    }

    // --- 9.0 migrations: vc_btn colours --------------------------------------

    public function test_a_flat_palette_button_becomes_a_custom_button(): void {
        $result = AttributeNormaliser::normalise( 'vc_btn', [ 'style' => 'flat', 'color' => 'blue' ] );

        $this->assertEquals(
            [ 'style' => 'custom', 'custom_background' => '#5472d2', 'custom_text' => '#ffffff' ],
            $result['atts']
        );
        $this->assertContains( 'vc_btn: color → custom_background, custom_text', $result['notes'] );
    }

    public function test_a_classic_button_takes_its_legacy_background_and_text(): void {
        $this->assertEquals(
            [ 'style' => 'custom', 'custom_background' => '#0088cc', 'custom_text' => '#fff' ],
            $this->atts( 'vc_btn', [ 'style' => 'classic', 'color' => 'primary' ] )
        );
    }

    public function test_a_light_palette_button_keeps_wpbakerys_default_text_colour(): void {
        $atts = $this->atts( 'vc_btn', [ 'style' => 'flat', 'color' => 'grey' ] );

        $this->assertSame( '#ebebeb', $atts['custom_background'] );
        $this->assertArrayNotHasKey( 'custom_text', $atts );
    }

    public function test_an_outline_palette_button_becomes_an_outline_custom_button(): void {
        $result = AttributeNormaliser::normalise( 'vc_btn', [ 'style' => 'outline', 'color' => 'green' ] );

        $this->assertEquals(
            [ 'style' => 'outline-custom', 'outline_custom_color' => '#6dab3c' ],
            $result['atts']
        );
        $this->assertContains( 'vc_btn: color → outline_custom_color', $result['notes'] );
    }

    public function test_a_3d_palette_button_keeps_its_style_and_gains_custom_colours(): void {
        $this->assertEquals(
            [ 'style' => '3d', 'custom_background' => '#2a2a2a', 'custom_text' => '#ffffff' ],
            $this->atts( 'vc_btn', [ 'style' => '3d', 'color' => 'black' ] )
        );
    }

    public function test_a_gradient_button_becomes_a_custom_gradient(): void {
        $this->assertEquals(
            [
                'style'                   => 'gradient-custom',
                'gradient_custom_color_1' => '#f4524d',
                'gradient_custom_color_2' => '#5aa1e3',
                'gradient_text_color'     => '#ffffff',
            ],
            $this->atts( 'vc_btn', [ 'style' => 'gradient', 'gradient_color_1' => 'juicy_pink', 'gradient_color_2' => 'sky' ] )
        );
    }

    public function test_a_gradient_button_without_colours_takes_wpbakerys_defaults(): void {
        $atts = $this->atts( 'vc_btn', [ 'style' => 'gradient' ] );

        $this->assertSame( '#00c1cf', $atts['gradient_custom_color_1'] );
        $this->assertSame( '#5472d2', $atts['gradient_custom_color_2'] );
    }

    public function test_an_unknown_button_colour_is_left_alone_and_reported(): void {
        $result = AttributeNormaliser::normalise( 'vc_btn', [ 'style' => 'flat', 'color' => 'theme-accent' ] );

        $this->assertSame( [ 'style' => 'flat', 'color' => 'theme-accent' ], $result['atts'] );
        $this->assertContains( 'vc_btn: color="theme-accent" is not a WPBakery palette name', $result['notes'] );
    }

    // --- 9.0 migrations: vc_cta ----------------------------------------------

    public function test_a_flat_cta_colour_becomes_a_custom_background(): void {
        $this->assertEquals(
            [ 'style' => 'flat', 'custom_background' => '#6dab3c', 'custom_text' => '#ffffff' ],
            $this->atts( 'vc_cta', [ 'style' => 'flat', 'color' => 'green' ] )
        );
    }

    public function test_an_outline_cta_colour_paints_the_text_and_the_border(): void {
        $this->assertEquals(
            [ 'style' => 'outline', 'custom_text' => '#5472d2', 'custom_border' => '#5472d2' ],
            $this->atts( 'vc_cta', [ 'style' => 'outline', 'color' => 'blue' ] )
        );
    }

    public function test_a_classic_cta_colour_paints_the_text(): void {
        $this->assertEquals(
            [ 'style' => 'classic', 'custom_text' => '#f4524d' ],
            $this->atts( 'vc_cta', [ 'style' => 'classic', 'color' => 'juicy_pink' ] )
        );
    }

    public function test_a_cta_button_position_dropdown_becomes_a_toggle(): void {
        $result = AttributeNormaliser::normalise( 'vc_cta', [ 'add_button' => 'bottom', 'add_icon' => 'left' ] );

        $this->assertEquals(
            [ 'add_button' => 'true', 'btn_position' => 'bottom', 'add_icon' => 'true', 'i_position' => 'left' ],
            $result['atts']
        );
        $this->assertContains( 'vc_cta: add_button → add_button, btn_position', $result['notes'] );
    }

    public function test_a_cta_width_dropdown_becomes_a_range(): void {
        $this->assertSame( '80', $this->atts( 'vc_cta', [ 'el_width' => 'lg' ] )['el_width'] );
        $this->assertSame( '100', $this->atts( 'vc_cta', [ 'el_width' => '' ] )['el_width'] );
        $this->assertSame( '75', $this->atts( 'vc_cta', [ 'el_width' => '75' ] )['el_width'] );
    }

    public function test_a_cta_icon_colour_becomes_a_custom_icon_colour(): void {
        $atts = $this->atts( 'vc_cta', [ 'i_color' => 'orange', 'i_background_color' => 'sky' ] );

        $this->assertSame( '#f7be68', $atts['i_custom_color'] );
        $this->assertSame( '#5aa1e3', $atts['i_custom_background_color'] );
    }

    // --- 9.0 migrations: single-colour elements ------------------------------

    public function test_an_icon_colour_and_background_become_custom_colours(): void {
        $result = AttributeNormaliser::normalise( 'vc_icon', [ 'color' => 'violet', 'background_color' => 'chino' ] );

        $this->assertEquals(
            [ 'custom_color' => '#8d6dc4', 'custom_background_color' => '#cec2ab' ],
            $result['atts']
        );
        $this->assertContains( 'vc_icon: color → custom_color', $result['notes'] );
    }

    public function test_a_zigzag_colour_becomes_a_custom_colour(): void {
        $this->assertEquals( [ 'custom_color' => '#00c1cf' ], $this->atts( 'vc_zigzag', [ 'color' => 'turquoise' ] ) );
    }

    public function test_a_separator_colour_becomes_an_accent_colour(): void {
        $this->assertEquals( [ 'accent_color' => '#ebebeb' ], $this->atts( 'vc_separator', [ 'color' => 'grey' ] ) );
        $this->assertEquals( [ 'accent_color' => '#ebebeb' ], $this->atts( 'vc_text_separator', [ 'color' => 'grey' ] ) );
    }

    public function test_a_pie_chart_colour_becomes_a_custom_colour(): void {
        $this->assertEquals( [ 'custom_color' => '#75d69c' ], $this->atts( 'vc_pie', [ 'color' => 'vista_blue' ] ) );
    }

    public function test_a_hoverbox_hover_background_becomes_a_custom_background(): void {
        $this->assertEquals(
            [ 'hover_custom_background' => '#b97ebb' ],
            $this->atts( 'vc_hoverbox', [ 'hover_background_color' => 'purple' ] )
        );
    }

    // --- 9.0 migrations: progress bars and charts ----------------------------

    public function test_progress_bar_options_become_toggles(): void {
        $result = AttributeNormaliser::normalise( 'vc_progress_bar', [ 'options' => 'striped,animated' ] );

        $this->assertEquals( [ 'striped' => 'true', 'animated' => 'true' ], $result['atts'] );
        $this->assertContains( 'vc_progress_bar: options → striped, animated', $result['notes'] );
    }

    public function test_a_progress_bar_background_colour_becomes_a_custom_colour(): void {
        $this->assertEquals(
            [ 'custombgcolor' => '#5aa1e3' ],
            $this->atts( 'vc_progress_bar', [ 'bgcolor' => 'sky' ] )
        );
    }

    public function test_a_classic_progress_bar_colour_is_left_alone_and_reported(): void {
        $result = AttributeNormaliser::normalise( 'vc_progress_bar', [ 'bgcolor' => 'bar_blue' ] );

        $this->assertSame( [ 'bgcolor' => 'bar_blue' ], $result['atts'] );
        $this->assertContains( 'vc_progress_bar: bgcolor="bar_blue" is not a WPBakery palette name', $result['notes'] );
    }

    public function test_per_bar_colours_become_custom_colours(): void {
        $values = rawurlencode( (string) json_encode( [
            [ 'label' => 'PHP', 'value' => '90', 'color' => 'green' ],
            [ 'label' => 'CSS', 'value' => '80', 'customcolor' => '#123456' ],
        ] ) );

        $atts = $this->atts( 'vc_progress_bar', [ 'values' => $values ] );
        $bars = PackedParams::paramGroup( $atts['values'] );

        $this->assertSame( '#6dab3c', $bars[0]['customcolor'] );
        $this->assertArrayNotHasKey( 'color', $bars[0] );
        $this->assertSame( '#123456', $bars[1]['customcolor'] );
    }

    public function test_chart_item_colours_become_custom_colours(): void {
        $values = rawurlencode( (string) json_encode( [ [ 'title' => 'A', 'value' => '10', 'color' => 'pink' ] ] ) );

        foreach ( [ 'vc_round_chart', 'vc_line_chart' ] as $tag ) {
            $items = PackedParams::paramGroup( $this->atts( $tag, [ 'values' => $values ] )['values'] );
            $this->assertSame( '#fe6c61', $items[0]['custom_color'], $tag );
        }
    }

    public function test_round_chart_stroke_and_legend_colours_become_custom_colours(): void {
        $atts = $this->atts( 'vc_round_chart', [ 'stroke_color' => 'black', 'legend_color' => 'grey' ] );

        $this->assertSame( '#2a2a2a', $atts['custom_stroke_color'] );
        $this->assertSame( '#ebebeb', $atts['custom_legend_color'] );
    }

    // --- 9.0 migrations: grids, sections, widgets, tta -----------------------

    public function test_a_grid_element_width_becomes_items_per_row(): void {
        $result = AttributeNormaliser::normalise( 'vc_basic_grid', [ 'element_width' => '1/4' ] );

        $this->assertSame( '4', $result['atts']['items_per_row'] );
        $this->assertArrayNotHasKey( 'element_width', $result['atts'] );
        $this->assertContains( 'vc_basic_grid: element_width → items_per_row', $result['notes'] );
    }

    public function test_every_grid_fraction_maps_onto_a_row_count(): void {
        foreach ( [ '1/2' => '2', '1/3' => '3', '1/4' => '4', '1/6' => '6', '1/12' => '12' ] as $width => $per_row ) {
            $this->assertSame( $per_row, $this->atts( 'vc_media_grid', [ 'element_width' => $width ] )['items_per_row'], $width );
        }
    }

    public function test_a_bootstrap_column_span_maps_onto_a_row_count(): void {
        $this->assertSame( '4', $this->atts( 'vc_masonry_grid', [ 'element_width' => '3' ] )['items_per_row'] );
    }

    public function test_a_bare_grid_gap_gains_its_unit(): void {
        $this->assertSame( '30px', $this->atts( 'vc_basic_grid', [ 'gap' => '30' ] )['gap'] );
        $this->assertSame( '30px', $this->atts( 'vc_basic_grid', [ 'gap' => '30px' ] )['gap'] );
    }

    public function test_a_section_content_placement_is_renamed(): void {
        $result = AttributeNormaliser::normalise( 'vc_section', [ 'content_placement' => 'middle' ] );

        $this->assertSame( [ 'vertical_content_position' => 'middle' ], $result['atts'] );
        $this->assertContains( 'vc_section: content_placement → vertical_content_position', $result['notes'] );
    }

    public function test_wp_archives_options_become_a_type_and_a_count(): void {
        $this->assertEquals(
            [ 'type' => 'dropdown', 'count' => 'true' ],
            $this->atts( 'vc_wp_archives', [ 'options' => 'dropdown,count' ] )
        );
        $this->assertEquals( [ 'type' => 'list' ], $this->atts( 'vc_wp_archives', [ 'options' => '' ] ) );
    }

    public function test_wp_rss_options_become_toggles(): void {
        $this->assertEquals(
            [ 'item_content' => 'true', 'item_author' => 'true', 'item_date' => 'true' ],
            $this->atts( 'vc_wp_rss', [ 'options' => 'show_summary,show_author,show_date' ] )
        );
    }

    public function test_a_tta_no_fill_checkbox_becomes_an_inverted_fill_toggle(): void {
        $result = AttributeNormaliser::normalise( 'vc_tta_tabs', [ 'no_fill_content_area' => 'true', 'color' => 'juicy_pink' ] );

        $this->assertEquals( [ 'fill_content_area' => '', 'color' => 'juicy_pink' ], $result['atts'] );
        $this->assertContains( 'vc_tta_tabs: no_fill_content_area → fill_content_area', $result['notes'] );
    }

    public function test_an_accordion_no_fill_checkbox_becomes_an_inverted_fill_toggle(): void {
        $this->assertEquals(
            [ 'fill_content_area' => 'true' ],
            $this->atts( 'vc_tta_accordion', [ 'no_fill' => '' ] )
        );
    }

    public function test_a_tta_active_colour_passes_through(): void {
        $atts = [ 'color' => 'grey', 'active_color' => '#f8f8f8' ];

        $this->assertSame( $atts, $this->atts( 'vc_tta_tour', $atts ) );
    }
}
