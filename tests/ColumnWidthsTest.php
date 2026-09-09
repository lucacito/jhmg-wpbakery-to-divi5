<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\StyleMapper\ColumnWidths;

/**
 * WPBakery column widths are twelfths plus fifths
 * (`wpb_translateColumnWidthToSpan()`, js_composer 9.0.1
 * include/helpers/helpers.php, and the `column_width_list` in
 * include/params/column_offset/column_offset.php). Divi 5.12 sizes flex
 * columns from `module.decoration.sizing.{bp}.value.flexType` on a 24-grid
 * plus the legacy `module.advanced.type` fraction, so every WPBakery width
 * maps exactly (design spec §5).
 *
 * The `offset` attribute carries Bootstrap-3 responsive classes
 * (`vc_col-{xs|sm|md|lg|xl}-N`, `vc_col-{size}-offset-N`, `vc_hidden-{size}`);
 * the breakpoint bands are read from js_composer.min.css:
 * xs < 768, sm 768-991, md 992-1199, lg 1200-1399, xl >= 1400.
 */
final class ColumnWidthsTest extends TestCase {

    /** @return array<string,array{0:string,1:?string,2:?string}> width => [flexType, type] */
    public static function widthProvider(): array {
        return [
            '1/12'  => [ '1/12', '2_24', '1_12' ],
            '1/6'   => [ '1/6', '4_24', '1_6' ],
            '1/4'   => [ '1/4', '6_24', '1_4' ],
            '1/3'   => [ '1/3', '8_24', '1_3' ],
            '5/12'  => [ '5/12', '10_24', '5_12' ],
            '1/2'   => [ '1/2', '12_24', '1_2' ],
            '7/12'  => [ '7/12', '14_24', '7_12' ],
            '2/3'   => [ '2/3', '16_24', '2_3' ],
            '3/4'   => [ '3/4', '18_24', '3_4' ],
            '5/6'   => [ '5/6', '20_24', '5_6' ],
            '11/12' => [ '11/12', '22_24', '11_12' ],
            '1/1'   => [ '1/1', '24_24', '4_4' ],
            '1/5'   => [ '1/5', '1_5', '1_5' ],
            '2/5'   => [ '2/5', '2_5', '2_5' ],
            '3/5'   => [ '3/5', '3_5', '3_5' ],
            '4/5'   => [ '4/5', '4_5', '4_5' ],
        ];
    }

    #[DataProvider( 'widthProvider' )]
    public function test_every_width_in_the_table_maps( string $width, string $flex_type, string $type ): void {
        $this->assertSame( $flex_type, ColumnWidths::flexType( $width ), $width . ' flexType' );
        $this->assertSame( $type, ColumnWidths::type( $width ), $width . ' type' );
    }

    public function test_an_empty_width_is_a_full_width_column(): void {
        $this->assertSame( '24_24', ColumnWidths::flexType( '' ) );
        $this->assertSame( '4_4', ColumnWidths::type( '' ) );
    }

    public function test_an_unknown_width_is_null(): void {
        $this->assertNull( ColumnWidths::flexType( 'garbage' ) );
        $this->assertNull( ColumnWidths::type( 'garbage' ) );
        $this->assertNull( ColumnWidths::flexType( '1/7' ) );
        $this->assertNull( ColumnWidths::percent( 'garbage' ) );
    }

    public function test_percent(): void {
        $this->assertSame( 100.0, ColumnWidths::percent( '1/1' ) );
        $this->assertSame( 50.0, ColumnWidths::percent( '1/2' ) );
        $this->assertSame( 33.3333, ColumnWidths::percent( '1/3' ) );
        $this->assertSame( 20.0, ColumnWidths::percent( '1/5' ) );
        $this->assertSame( 8.3333, ColumnWidths::percent( '1/12' ) );
    }

    public function test_lg_wins_over_md_and_the_difference_is_noted(): void {
        $result = ColumnWidths::offsets( 'vc_col-lg-4 vc_col-md-6 vc_hidden-xs' );

        $this->assertSame( '8_24', $result['flex']['desktop'] );
        $this->assertTrue( $result['hidden']['phone'] );
        $this->assertNotEmpty( $result['notes'] );
        $this->assertStringContainsString( 'md', implode( ' ', $result['notes'] ) );
    }

    public function test_sm_sizes_both_desktop_and_tablet(): void {
        $result = ColumnWidths::offsets( 'vc_col-sm-4' );

        $this->assertSame( '8_24', $result['flex']['desktop'] );
        $this->assertSame( '8_24', $result['flex']['tablet'] );
        $this->assertArrayNotHasKey( 'phone', $result['flex'] );
    }

    public function test_xs_sizes_the_phone_breakpoint(): void {
        $result = ColumnWidths::offsets( 'vc_col-xs-6' );

        $this->assertSame( '12_24', $result['flex']['phone'] );
    }

    public function test_fifths_survive_an_offset_class(): void {
        $result = ColumnWidths::offsets( 'vc_col-md-2/5' );

        $this->assertSame( '2_5', $result['flex']['desktop'] );
    }

    public function test_offset_becomes_a_left_margin_percentage(): void {
        $result = ColumnWidths::offsets( 'vc_col-sm-offset-3' );

        $this->assertSame( '25%', $result['margin_left']['desktop'] );
    }

    public function test_offset_zero_is_no_offset(): void {
        $result = ColumnWidths::offsets( 'vc_col-md-offset-0' );

        $this->assertSame( [], $result['margin_left'] );
    }

    public function test_hiding_every_desktop_band_needs_no_note(): void {
        $result = ColumnWidths::offsets( 'vc_hidden-md vc_hidden-lg vc_hidden-xl' );

        $this->assertTrue( $result['hidden']['desktop'] );
        $this->assertSame( [], $result['notes'] );
    }

    /**
     * Pre-9.0 values have no xl class at all: `vc_hidden-lg` meant "hidden on
     * every desktop screen", and `wpb_normalize_column_offset_hidden()`
     * (js_composer 9.0.1) adds `vc_hidden-xl` to exactly those values.
     */
    public function test_a_legacy_hidden_lg_covers_xl_too(): void {
        $result = ColumnWidths::offsets( 'vc_hidden-md vc_hidden-lg' );

        $this->assertTrue( $result['hidden']['desktop'] );
        $this->assertSame( [], $result['notes'] );
    }

    public function test_hiding_one_desktop_band_is_approximate(): void {
        $result = ColumnWidths::offsets( 'vc_hidden-md' );

        $this->assertTrue( $result['hidden']['desktop'] );
        $this->assertNotEmpty( $result['notes'] );
    }

    public function test_hidden_sm_hides_the_tablet(): void {
        $result = ColumnWidths::offsets( 'vc_hidden-sm' );

        $this->assertTrue( $result['hidden']['tablet'] );
        $this->assertArrayNotHasKey( 'desktop', $result['hidden'] );
    }

    public function test_an_empty_offset_yields_nothing(): void {
        $this->assertSame(
            [ 'flex' => [], 'hidden' => [], 'margin_left' => [], 'inherit' => false, 'notes' => [] ],
            ColumnWidths::offsets( '' )
        );
    }

    public function test_inherit_markers_are_not_sizes(): void {
        $result = ColumnWidths::offsets( 'vc_col-md-inherit vc_col-lg-6' );

        $this->assertSame( '12_24', $result['flex']['desktop'] );
        $this->assertSame( [], $result['notes'] );
    }

    /**
     * The marker is not a size, but its presence is load-bearing:
     * `vc_column_offset_class_merge()` (column_offset.php:304-311) strips the
     * markers and returns the offset classes without prepending the `width`
     * attribute's own `vc_col-sm-N`, so the column takes no default width.
     */
    public function test_an_inherit_marker_is_reported(): void {
        $this->assertTrue( ColumnWidths::offsets( 'vc_col-md-inherit vc_col-lg-6' )['inherit'] );
        $this->assertFalse( ColumnWidths::offsets( 'vc_col-lg-6' )['inherit'] );
    }
}
