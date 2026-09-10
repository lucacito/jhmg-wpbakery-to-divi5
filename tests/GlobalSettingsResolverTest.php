<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\StyleMapper\GlobalSettingsResolver;

/**
 * The layout defaults encode the box model measured on the Docker site
 * (docs/box-model.md, candidate C) plus WPBakery's per-element bottom margins
 * read from js_composer.min.css (9.0.1). Everything sits behind the
 * `wbdc_layout_defaults` filter.
 */
final class GlobalSettingsResolverTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    protected function tearDown(): void {
        wbdc_test_reset_hooks();
    }

    public function test_layout_defaults_are_the_measured_box_model(): void {
        $this->assertSame(
            [
                'section_padding'           => '0px',
                'row_width'                 => 'calc(var(--content-width, 80%) + 30px)',
                'row_max_width'             => 'calc(var(--content-max-width, 1080px) + 30px)',
                'row_margin_x'              => '-15px',
                'nested_row_width'          => 'calc(100% + 30px)',
                'nested_row_max_width'      => 'none',
                'column_padding_x'          => '15px',
                'filled_column_padding_top' => '35px',
                'module_margins'            => [
                    'content'   => '35px',
                    'button'    => '22px',
                    'message'   => '21.74px',
                    'toggle'    => '21.74px',
                    'tta'       => '21.74px',
                    'social'    => '21.74px',
                    'ult_video' => '20px',
                    'inner_row' => '0px',
                ],
            ],
            GlobalSettingsResolver::layoutDefaults()
        );
    }

    public function test_accessors_read_the_defaults(): void {
        $this->assertSame( '0px', GlobalSettingsResolver::sectionPadding() );
        $this->assertSame( 'calc(var(--content-width, 80%) + 30px)', GlobalSettingsResolver::rowWidth() );
        $this->assertSame( 'calc(var(--content-max-width, 1080px) + 30px)', GlobalSettingsResolver::rowMaxWidth() );
        $this->assertSame( '-15px', GlobalSettingsResolver::rowMarginX() );
        $this->assertSame( 'calc(100% + 30px)', GlobalSettingsResolver::nestedRowWidth() );
        $this->assertSame( 'none', GlobalSettingsResolver::nestedRowMaxWidth() );
        $this->assertSame( '15px', GlobalSettingsResolver::columnPaddingX() );
        $this->assertSame( '35px', GlobalSettingsResolver::filledColumnPaddingTop() );
    }

    public function test_module_margin_bottom_is_per_element(): void {
        $this->assertSame( '35px', GlobalSettingsResolver::moduleMarginBottom() );
        $this->assertSame( '35px', GlobalSettingsResolver::moduleMarginBottom( 'content' ) );
        $this->assertSame( '22px', GlobalSettingsResolver::moduleMarginBottom( 'button' ) );
        $this->assertSame( '21.74px', GlobalSettingsResolver::moduleMarginBottom( 'message' ) );
        $this->assertSame( '21.74px', GlobalSettingsResolver::moduleMarginBottom( 'toggle' ) );
        // js_composer_tta.min.css: .vc_tta-container{margin-bottom:21.73913043px}
        $this->assertSame( '21.74px', GlobalSettingsResolver::moduleMarginBottom( 'tta' ) );
        // js_composer.min.css byte 103390: .fb_like,.wpb_googleplus,.wpb_pinterest,…
        $this->assertSame( '21.74px', GlobalSettingsResolver::moduleMarginBottom( 'social' ) );
        // Ultimate Addons assets/css/video_module.css: .ult-video{margin:20px}
        $this->assertSame( '20px', GlobalSettingsResolver::moduleMarginBottom( 'ult_video' ) );
        $this->assertSame( '0px', GlobalSettingsResolver::moduleMarginBottom( 'inner_row' ) );
    }

    public function test_an_unknown_element_kind_falls_back_to_content(): void {
        $this->assertSame( '35px', GlobalSettingsResolver::moduleMarginBottom( 'nothing-like-this' ) );
    }

    public function test_the_filter_overrides_one_key_at_a_time(): void {
        add_filter( 'wbdc_layout_defaults', static function ( array $defaults ): array {
            return array_replace_recursive( $defaults, [
                'section_padding' => '54px',
                'module_margins'  => [ 'button' => '10px' ],
            ] );
        } );

        $this->assertSame( '54px', GlobalSettingsResolver::sectionPadding() );
        $this->assertSame( '10px', GlobalSettingsResolver::moduleMarginBottom( 'button' ) );
        // Untouched siblings survive the recursive replace.
        $this->assertSame( '35px', GlobalSettingsResolver::moduleMarginBottom( 'content' ) );
        $this->assertSame( '15px', GlobalSettingsResolver::columnPaddingX() );
    }

    public function test_a_filter_returning_a_partial_array_keeps_the_rest(): void {
        add_filter( 'wbdc_layout_defaults', static fn(): array => [ 'column_padding_x' => '0px' ] );

        $this->assertSame( '0px', GlobalSettingsResolver::columnPaddingX() );
        $this->assertSame( '35px', GlobalSettingsResolver::filledColumnPaddingTop() );
    }

    /** Divi tests layout gap values for truthiness, so a zero is never "0". */
    public function test_every_value_is_a_css_string_and_zero_carries_a_unit(): void {
        $flat = GlobalSettingsResolver::layoutDefaults();
        $margins = $flat['module_margins'];
        unset( $flat['module_margins'] );

        foreach ( array_merge( $flat, $margins ) as $key => $value ) {
            $this->assertIsString( $value, $key );
            $this->assertNotSame( '0', $value, $key );
        }
    }
}
