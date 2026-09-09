<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\Color;

/**
 * Every hex value here was read, not guessed: the 17-name palette from
 * `vc_convert_vc_color()` and `VcSharedLibrary::$colors_hash` (both in
 * js_composer 9.0.1's `include/helpers/helpers.php` and
 * `include/classes/core/class-vc-shared-library.php`), the legacy button
 * background/text pair from `VcSharedLibrary::$btn_solid_colors` (same file),
 * and the message-box text/border/background triples from the compiled
 * `.vc_color-<name>.vc_message_box{...}` rules in
 * `assets/css/js_composer.min.css`.
 *
 * That stylesheet defines 25 distinct `.vc_color-<name>.vc_message_box`
 * triples (the 17 palette names, kept for pre-9.0 `message_box_color` values,
 * plus the 8 dropdown presets `info`/`success`/`warning`/`danger` and their
 * `alert-*` classic variants) — Color::MESSAGE_BOX carries all 25.
 */
final class ColorTest extends TestCase {

    public function test_palette_has_seventeen_names(): void {
        $this->assertCount( 17, Color::PALETTE );
    }

    public function test_every_palette_name_normalizes_via_both_spellings(): void {
        foreach ( Color::PALETTE as $underscored => $hex ) {
            $hyphenated = str_replace( '_', '-', $underscored );

            $this->assertSame( $hex, Color::normalize( $underscored ), $underscored );
            $this->assertSame( $hex, Color::normalize( $hyphenated ), $hyphenated );
            $this->assertTrue( Color::isPaletteName( $underscored ) );
            $this->assertTrue( Color::isPaletteName( $hyphenated ) );
            $this->assertSame( $hex, Color::fromPalette( $underscored ) );
        }
    }

    public function test_mulled_wine_underscore_and_hyphen_agree(): void {
        $this->assertSame( Color::normalize( 'mulled_wine' ), Color::normalize( 'mulled-wine' ) );
        $this->assertSame( '#50485b', Color::normalize( 'mulled_wine' ) );
    }

    public function test_button_legacy_has_seven_names(): void {
        $this->assertCount( 7, Color::BUTTON_LEGACY );
    }

    public function test_button_legacy_background_and_text(): void {
        $this->assertSame( [ 'bg' => '#f7f7f7', 'text' => '#333' ], Color::BUTTON_LEGACY['default'] );
        $this->assertSame( [ 'bg' => '#0088cc', 'text' => '#fff' ], Color::BUTTON_LEGACY['primary'] );
        $this->assertSame( [ 'bg' => '#ff675b', 'text' => '#fff' ], Color::BUTTON_LEGACY['danger'] );
        $this->assertSame( [ 'bg' => '#555555', 'text' => '#fff' ], Color::BUTTON_LEGACY['inverse'] );
    }

    public function test_message_box_has_twenty_five_triples(): void {
        $this->assertCount( 25, Color::MESSAGE_BOX );
    }

    public function test_message_box_triple_for_blue(): void {
        $this->assertSame(
            [ 'text' => '#364a8a', 'border' => '#c5cff0', 'bg' => '#edf1fa' ],
            Color::MESSAGE_BOX['blue']
        );
    }

    public function test_message_box_triple_for_alert_info(): void {
        $this->assertSame(
            [ 'text' => '#31708f', 'border' => '#bce8f1', 'bg' => '#d9edf7' ],
            Color::MESSAGE_BOX['alert-info']
        );
    }

    public function test_normalize_lower_cases_hex(): void {
        $this->assertSame( '#fff', Color::normalize( '#FFF' ) );
        $this->assertSame( '#aabbcc', Color::normalize( '#AABBCC' ) );
        $this->assertSame( '#aabbccdd', Color::normalize( '#AABBCCDD' ) );
    }

    public function test_normalize_passes_rgba_through(): void {
        $this->assertSame( 'rgba(0,0,0,.5)', Color::normalize( 'rgba(0,0,0,.5)' ) );
    }

    public function test_normalize_of_unknown_name_is_null(): void {
        $this->assertNull( Color::normalize( 'not-a-color' ) );
        $this->assertNull( Color::normalize( '' ) );
        $this->assertNull( Color::normalize( 123 ) );
    }

    public function test_with_opacity_converts_hex_to_rgba(): void {
        $this->assertSame( 'rgba(84,114,210,0.5)', Color::withOpacity( '#5472d2', 0.5 ) );
    }

    public function test_with_opacity_passes_non_hex_through(): void {
        $this->assertSame( 'red', Color::withOpacity( 'red', 0.5 ) );
    }
}
