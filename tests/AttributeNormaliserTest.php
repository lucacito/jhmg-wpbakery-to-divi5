<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Parsers\AttributeNormaliser;

/**
 * Both WPBakery attribute eras folded into 9.0.1's shapes.
 *
 * The expected hex values are quoted as literals here on purpose: they are the
 * documentation of WPBakery's own tables — the 17-name palette
 * (`vc_convert_vc_color()`), `VcSharedLibrary::$btn_solid_colors`,
 * `$btn_3d_colors` and `$cta_colors`, and the migration's classic `bar_*`
 * colours — and a test that computed them the way the implementation does
 * would assert nothing. The normaliser itself holds no hex at all: it reads
 * `Color::fromPalette()`, `Color::BUTTON_MIGRATION`, `Color::CTA_MIGRATION`
 * and `Color::PROGRESS_BAR_LEGACY`.
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
        $result = AttributeNormaliser::normalise( 'vc_row', [] );

        $this->assertSame( [ 'tag', 'atts', 'content', 'notes' ], array_keys( $result ) );
        $this->assertNull( $result['content'], 'only a rule that moves text into the element rewrites content' );
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
        // The outline styles pick up the grey the 9.0 migration back-fills for
        // a colourless outline button, so only the two keys are compared.
        $expected = [
            'rounded'         => [ 'style' => 'flat', 'shape' => 'rounded' ],
            'square'          => [ 'style' => 'flat', 'shape' => 'square' ],
            'round'           => [ 'style' => 'flat', 'shape' => 'round' ],
            'outlined'        => [ 'style' => 'outline-custom', 'shape' => null ],
            'square_outlined' => [ 'style' => 'outline-custom', 'shape' => 'square' ],
        ];

        foreach ( $expected as $old => $new ) {
            $atts = $this->atts( 'vc_button2', [ 'style' => $old ] );

            $this->assertSame( $new['style'], $atts['style'], $old );
            $this->assertSame( $new['shape'], $atts['shape'] ?? null, $old );
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

    /**
     * Every attribute `vc_cta_button` registers
     * (config/deprecated/shortcode-vc-cta-button.php), at once.
     */
    public function test_vc_cta_button_becomes_a_vc_cta_whose_text_is_the_content(): void {
        $result = AttributeNormaliser::normalise( 'vc_cta_button', [
            'call_text' => 'Ready to start?',
            'title'     => 'Sign up',
            'href'      => 'https://example.com/go',
            'target'    => '_blank',
            'color'     => 'btn-primary',
            'icon'      => 'wpb_book',
            'size'      => 'btn-large',
            'position'  => 'cta_align_bottom',
            'el_class'  => 'promo',
        ] );

        $this->assertSame( 'vc_cta', $result['tag'] );
        $this->assertSame( 'Ready to start?', $result['content'] );
        $this->assertEquals(
            [
                'el_class'              => 'promo',
                'btn_title'             => 'Sign up',
                'btn_link'              => 'url:https%3A%2F%2Fexample.com%2Fgo|title:Sign up|target:_blank',
                'btn_color'             => 'primary',
                'btn_size'              => 'lg',
                'btn_add_icon'          => 'true',
                'btn_i_type'            => 'pixelicons',
                'btn_i_align'           => 'right',
                'btn_i_icon_pixelicons' => 'vc_pixel_icon vc_pixel_icon-book',
                'add_button'            => 'true',
                'btn_position'          => 'bottom',
            ],
            $result['atts']
        );
        $this->assertContains( 'vc_cta_button → vc_cta', $result['notes'] );
    }

    public function test_a_cta_button_without_a_position_puts_the_button_on_the_right(): void {
        $atts = $this->atts( 'vc_cta_button', [ 'call_text' => 'Hi', 'title' => 'Go' ] );

        $this->assertSame( 'true', $atts['add_button'] );
        $this->assertSame( 'right', $atts['btn_position'] );
    }

    public function test_a_cta_buttons_text_is_taken_out_of_the_attributes(): void {
        $result = AttributeNormaliser::normalise( 'vc_cta_button', [ 'call_text' => 'Ready?' ] );

        $this->assertArrayNotHasKey( 'call_text', $result['atts'] );
        $this->assertSame( 'Ready?', $result['content'] );
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

    /**
     * `btn_style` holds one of `VcSharedLibrary::$button_styles` — the
     * button-1 styles — so it has to be split before the button colour rules
     * can read it.
     */
    public function test_a_cta_button2_button_style_and_colour_become_the_integrated_buttons(): void {
        $result = AttributeNormaliser::normalise( 'vc_cta_button2', [
            'h2'        => 'Grow',
            'title'     => 'Go',
            'btn_style' => 'rounded',
            'color'     => 'blue',
        ] );

        $this->assertEquals(
            [
                'h2'                          => 'Grow',
                'add_button'                  => 'true',
                'btn_title'                   => 'Go',
                'btn_style'                   => 'custom',
                'btn_shape'                   => 'rounded',
                'btn_custom_background'       => '#5472d2',
                'btn_custom_text'             => '#fff',
                'btn_custom_hover_background' => '#3c5ecc',
                'btn_custom_hover_text'       => '#f7f7f7',
            ],
            $result['atts']
        );
        $this->assertArrayNotHasKey( 'color', $result['atts'] );
    }

    public function test_a_cta_button2_outlined_button_becomes_an_outline_custom_one(): void {
        $atts = $this->atts( 'vc_cta_button2', [ 'title' => 'Go', 'btn_style' => 'square_outlined', 'color' => 'green' ] );

        $this->assertSame( 'outline-custom', $atts['btn_style'] );
        $this->assertSame( 'square', $atts['btn_shape'] );
        $this->assertSame( '#6dab3c', $atts['btn_outline_custom_color'] );
    }

    public function test_a_cta_button2_3d_button_keeps_the_style_button_3_shares(): void {
        $atts = $this->atts( 'vc_cta_button2', [ 'title' => 'Go', 'btn_style' => '3d', 'color' => 'black' ] );

        $this->assertSame( '3d', $atts['btn_style'] );
        $this->assertSame( '#2a2a2a', $atts['btn_custom_background'] );
        $this->assertSame( '#0e0e0e', $atts['btn_custom_border'] );
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
            [
                'style'                   => 'custom',
                'custom_background'       => '#5472d2',
                'custom_text'             => '#fff',
                'custom_hover_background' => '#3c5ecc',
                'custom_hover_text'       => '#f7f7f7',
            ],
            $result['atts']
        );
        $this->assertContains(
            'vc_btn: color → style="custom", custom_background, custom_text, custom_hover_background, custom_hover_text',
            $result['notes']
        );
    }

    public function test_a_modern_button_also_takes_its_borders(): void {
        $this->assertEquals(
            [
                'style'                   => 'custom',
                'custom_background'       => '#6dab3c',
                'custom_text'             => '#fff',
                'custom_hover_background' => '#5f9434',
                'custom_hover_text'       => '#f7f7f7',
                'custom_border'           => '#6dab3c',
                'custom_hover_border'     => '#5f9434',
            ],
            $this->atts( 'vc_btn', [ 'style' => 'modern', 'color' => 'green' ] )
        );
    }

    public function test_a_classic_button_takes_its_legacy_background_and_text(): void {
        $atts = $this->atts( 'vc_btn', [ 'style' => 'classic', 'color' => 'primary' ] );

        $this->assertSame( '#0088cc', $atts['custom_background'] );
        $this->assertSame( '#fff', $atts['custom_text'] );
        $this->assertSame( '#0074ad', $atts['custom_hover_background'] );
        $this->assertArrayNotHasKey( 'custom_border', $atts, 'only the modern style has borders' );
    }

    public function test_a_light_palette_button_takes_wpbakerys_dark_label(): void {
        $atts = $this->atts( 'vc_btn', [ 'style' => 'flat', 'color' => 'grey' ] );

        $this->assertSame( '#ebebeb', $atts['custom_background'] );
        $this->assertSame( '#666', $atts['custom_text'] );
        $this->assertSame( '#5e5e5e', $atts['custom_hover_text'] );
    }

    /**
     * `apply_btn_solid_color()` assigns rather than back-fills, so a slug
     * beside a picked colour resolves in favour of the slug — this follows it.
     */
    public function test_a_colour_slug_beside_a_picked_colour_wins_as_it_does_in_wpbakery(): void {
        $atts = $this->atts( 'vc_btn', [ 'style' => 'flat', 'color' => 'blue', 'custom_text' => '#101010' ] );

        $this->assertSame( '#fff', $atts['custom_text'] );
        $this->assertSame( '#5472d2', $atts['custom_background'] );
    }

    public function test_an_outline_palette_button_becomes_an_outline_custom_button(): void {
        $result = AttributeNormaliser::normalise( 'vc_btn', [ 'style' => 'outline', 'color' => 'green' ] );

        $this->assertEquals(
            [
                'style'                           => 'outline-custom',
                'outline_custom_color'            => '#6dab3c',
                'outline_custom_hover_background' => '#6dab3c',
                'outline_custom_hover_text'       => '#fff',
            ],
            $result['atts']
        );
        $this->assertContains(
            'vc_btn: color → style="outline-custom", outline_custom_color, outline_custom_hover_background, outline_custom_hover_text',
            $result['notes']
        );
    }

    public function test_an_outline_button_with_no_colour_defaults_to_grey(): void {
        $result = AttributeNormaliser::normalise( 'vc_btn', [ 'style' => 'outline', 'title' => 'Go' ] );

        $this->assertEquals(
            [
                'title'                           => 'Go',
                'style'                           => 'outline-custom',
                'outline_custom_color'            => '#ebebeb',
                'outline_custom_hover_background' => '#ebebeb',
                'outline_custom_hover_text'       => '#666',
            ],
            $result['atts']
        );
        $this->assertContains( 'vc_btn: style="outline" with no colour defaults to grey', $result['notes'] );
    }

    public function test_a_3d_button_with_no_colour_defaults_to_grey(): void {
        $this->assertEquals(
            [
                'title'             => 'Go',
                'style'             => '3d',
                'custom_background' => '#ebebeb',
                'custom_text'       => '#666',
                'custom_border'     => '#cfcfcf',
            ],
            $this->atts( 'vc_btn', [ 'style' => '3d', 'title' => 'Go' ] )
        );
    }

    public function test_a_button_that_already_carries_picked_colours_is_not_given_grey(): void {
        $result = AttributeNormaliser::normalise( 'vc_btn', [ 'style' => 'outline', 'custom_text' => '#101010' ] );

        $this->assertSame( [ 'style' => 'outline', 'custom_text' => '#101010' ], $result['atts'] );
        $this->assertSame( [], $result['notes'] );
    }

    public function test_a_3d_palette_button_keeps_its_style_and_gains_custom_colours(): void {
        $this->assertEquals(
            [ 'style' => '3d', 'custom_background' => '#2a2a2a', 'custom_text' => '#fff', 'custom_border' => '#0e0e0e' ],
            $this->atts( 'vc_btn', [ 'style' => '3d', 'color' => 'black' ] )
        );
    }

    public function test_a_button_1_colour_class_becomes_the_slug_the_tables_use(): void {
        $expected = [
            'wpb_button'  => '#f7f7f7',
            'btn-primary' => '#0088cc',
            'btn-info'    => '#58b9da',
            'btn-success' => '#6ab165',
            'btn-warning' => '#ff9900',
            'btn-danger'  => '#ff675b',
            'btn-inverse' => '#555555',
        ];

        foreach ( $expected as $class => $background ) {
            $atts = $this->atts( 'vc_button', [ 'color' => $class, 'style' => 'rounded' ] );

            $this->assertSame( 'custom', $atts['style'], $class );
            $this->assertSame( $background, $atts['custom_background'], $class );
        }
    }

    public function test_a_gradient_button_becomes_a_custom_gradient(): void {
        $this->assertEquals(
            [
                'style'                   => 'gradient-custom',
                'gradient_custom_color_1' => '#f4524d',
                'gradient_custom_color_2' => '#5aa1e3',
                'gradient_text_color'     => '#fff',
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
            [ 'style' => 'flat', 'custom_background' => '#6dab3c', 'custom_text' => '#fff', 'text_color' => '#e5f2da' ],
            $this->atts( 'vc_cta', [ 'style' => 'flat', 'color' => 'green' ] )
        );
    }

    public function test_a_3d_cta_colour_also_carries_the_box_shadow(): void {
        $this->assertEquals(
            [
                'style'             => '3d',
                'custom_background' => '#5472d2',
                'custom_text'       => '#fff',
                'text_color'        => '#c9d2f0',
                'custom_border'     => '#3253bc',
            ],
            $this->atts( 'vc_cta', [ 'style' => '3d', 'color' => 'blue' ] )
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
            [
                'custombgcolor'     => '#5aa1e3',
                'customtxtcolor'    => '#ffffff',
                'add_text_shadow'   => 'true',
                'text_shadow_color' => '#00000040',
            ],
            $this->atts( 'vc_progress_bar', [ 'bgcolor' => 'sky' ] )
        );
    }

    public function test_a_light_progress_bar_takes_the_dark_label(): void {
        $this->assertSame( '#666666', $this->atts( 'vc_progress_bar', [ 'bgcolor' => 'white' ] )['customtxtcolor'] );
    }

    public function test_a_classic_progress_bar_colour_becomes_a_custom_colour(): void {
        $result = AttributeNormaliser::normalise( 'vc_progress_bar', [ 'bgcolor' => 'bar_blue' ] );

        $this->assertSame(
            [
                'custombgcolor'     => '#0074cc',
                'customtxtcolor'    => '#ffffff',
                'add_text_shadow'   => 'true',
                'text_shadow_color' => '#00000040',
            ],
            $result['atts']
        );
        $this->assertContains(
            'vc_progress_bar: bgcolor → custombgcolor, customtxtcolor, add_text_shadow, text_shadow_color',
            $result['notes']
        );
    }

    public function test_the_default_grey_bar_is_consumed_without_a_colour(): void {
        $result = AttributeNormaliser::normalise( 'vc_progress_bar', [ 'bgcolor' => 'bar_grey' ] );

        $this->assertSame( [], $result['atts'] );
        $this->assertContains( 'vc_progress_bar: bgcolor="bar_grey" is the default bar colour', $result['notes'] );
    }

    public function test_an_unknown_progress_bar_colour_is_left_alone_and_reported(): void {
        $result = AttributeNormaliser::normalise( 'vc_progress_bar', [ 'bgcolor' => 'theme-bar' ] );

        $this->assertSame( [ 'bgcolor' => 'theme-bar' ], $result['atts'] );
        $this->assertContains( 'vc_progress_bar: bgcolor="theme-bar" is not a WPBakery palette name', $result['notes'] );
    }

    public function test_per_bar_colours_become_custom_colours(): void {
        $values = rawurlencode( (string) json_encode( [
            [ 'label' => 'PHP', 'value' => '90', 'color' => 'green' ],
            [ 'label' => 'CSS', 'value' => '80', 'customcolor' => '#123456' ],
            [ 'label' => 'JS', 'value' => '70', 'color' => 'bar_red' ],
        ] ) );

        $atts = $this->atts( 'vc_progress_bar', [ 'values' => $values ] );
        $bars = PackedParams::paramGroup( $atts['values'] );

        $this->assertSame( '#6dab3c', $bars[0]['customcolor'] );
        $this->assertSame( '#ffffff', $bars[0]['customtxtcolor'] );
        $this->assertSame( 'true', $bars[0]['add_text_shadow'] );
        $this->assertSame( '#00000040', $bars[0]['text_shadow_color'] );
        $this->assertArrayNotHasKey( 'color', $bars[0] );

        $this->assertSame( '#123456', $bars[1]['customcolor'] );
        $this->assertArrayNotHasKey( 'customtxtcolor', $bars[1], 'a bar that had its own colour is left alone' );

        $this->assertSame( '#da4f49', $bars[2]['customcolor'] );
    }

    public function test_a_bar_that_already_has_a_custom_colour_keeps_it(): void {
        $values = rawurlencode( (string) json_encode( [
            [ 'label' => 'PHP', 'value' => '90', 'color' => 'green', 'customcolor' => '#123456' ],
        ] ) );

        $bars = PackedParams::paramGroup( $this->atts( 'vc_progress_bar', [ 'values' => $values ] )['values'] );

        $this->assertSame( '#123456', $bars[0]['customcolor'] );
        $this->assertArrayNotHasKey( 'color', $bars[0] );
    }

    public function test_chart_item_colours_become_custom_colours(): void {
        $values = rawurlencode( (string) json_encode( [
            [ 'title' => 'A', 'value' => '10', 'color' => 'pink' ],
            [ 'title' => 'B', 'value' => '20', 'color' => 'primary' ],
        ] ) );

        foreach ( [ 'vc_round_chart', 'vc_line_chart' ] as $tag ) {
            $items = PackedParams::paramGroup( $this->atts( $tag, [ 'values' => $values ] )['values'] );

            $this->assertSame( '#fe6c61', $items[0]['custom_color'], $tag );
            $this->assertArrayNotHasKey( 'color', $items[0], $tag );
            $this->assertSame( '#0088cc', $items[1]['custom_color'], $tag . ': the wider dashed hash' );
        }
    }

    public function test_a_custom_styled_chart_backfills_grey_and_drops_the_slug(): void {
        $values = rawurlencode( (string) json_encode( [
            [ 'title' => 'A', 'value' => '10', 'color' => 'pink' ],
            [ 'title' => 'B', 'value' => '20', 'color' => 'pink', 'custom_color' => '#123456' ],
        ] ) );

        $items = PackedParams::paramGroup( $this->atts( 'vc_round_chart', [ 'style' => 'custom', 'values' => $values ] )['values'] );

        $this->assertSame( '#ebebeb', $items[0]['custom_color'] );
        $this->assertSame( '#123456', $items[1]['custom_color'] );
        $this->assertArrayNotHasKey( 'color', $items[0] );
    }

    public function test_a_chart_item_that_says_custom_only_loses_the_key(): void {
        $values = rawurlencode( (string) json_encode( [ [ 'title' => 'A', 'value' => '10', 'color' => 'custom', 'custom_color' => '#123456' ] ] ) );

        $items = PackedParams::paramGroup( $this->atts( 'vc_line_chart', [ 'values' => $values ] )['values'] );

        $this->assertSame( [ 'title' => 'A', 'value' => '10', 'custom_color' => '#123456' ], $items[0] );
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
        $this->assertContains(
            'vc_tta_tabs: color="juicy_pink" kept as a slug (9.0.1 resolves it at render)',
            $result['notes'],
            'the one rule this class defers to the handler says so'
        );
    }

    public function test_an_accordion_no_fill_checkbox_becomes_an_inverted_fill_toggle(): void {
        $this->assertEquals(
            [ 'fill_content_area' => 'true' ],
            $this->atts( 'vc_tta_accordion', [ 'no_fill' => '' ] )
        );
    }

    public function test_a_tta_pagination_colour_becomes_a_custom_colour(): void {
        $result = AttributeNormaliser::normalise( 'vc_tta_tabs', [ 'pagination_color' => 'juicy_pink' ] );

        $this->assertSame( [ 'pagination_color' => '#f4524d' ], $result['atts'] );
        $this->assertContains( 'vc_tta_tabs: pagination_color → colour', $result['notes'] );
    }

    public function test_a_tta_pagination_colour_that_is_already_a_colour_is_left_alone(): void {
        $atts = $this->atts( 'vc_tta_pageable', [ 'pagination_color' => '#123456' ] );

        $this->assertSame( '#123456', $atts['pagination_color'] );
        $this->assertSame( '0', $atts['autoplay'], 'the pageable autoplay default still applies' );
    }

    public function test_a_posts_slider_count_becomes_a_number_and_a_toggle(): void {
        $this->assertEquals( [ 'count' => '5', 'display_all' => '' ], $this->atts( 'vc_posts_slider', [ 'count' => '5' ] ) );
        $this->assertEquals( [ 'display_all' => 'yes' ], $this->atts( 'vc_posts_slider', [ 'count' => 'all' ] ) );
    }

    public function test_a_tta_active_colour_passes_through(): void {
        $atts = [ 'color' => 'grey', 'active_color' => '#f8f8f8' ];

        $this->assertSame( $atts, $this->atts( 'vc_tta_tour', $atts ) );
    }
}
