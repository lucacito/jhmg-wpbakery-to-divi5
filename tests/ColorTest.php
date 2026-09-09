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
 *
 * The migration tables are read the same way: BUTTON_MIGRATION from
 * `$btn_solid_colors` + `$btn_3d_colors`, CTA_MIGRATION from `$cta_colors`
 * (all three in class-vc-shared-library.php) and PROGRESS_BAR_LEGACY from
 * `Wpb_Attributes_Migration_Abstract::resolve_progress_bar_color()`. The
 * cross-checks below — every button and CTA background equal to the palette
 * hex of the same name, every classic pair equal to BUTTON_LEGACY's — are
 * what would catch a transcription slip.
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

    // --- the 9.0 migration tables ------------------------------------------

    public function test_button_migration_covers_the_palette_and_the_classic_slugs(): void {
        $this->assertCount( 24, Color::BUTTON_MIGRATION );

        foreach ( array_keys( Color::PALETTE ) as $palette_name ) {
            $this->assertArrayHasKey( $palette_name, Color::BUTTON_MIGRATION );
        }
        foreach ( array_keys( Color::BUTTON_LEGACY ) as $classic ) {
            $this->assertArrayHasKey( $classic, Color::BUTTON_MIGRATION );
        }
    }

    public function test_every_button_migration_row_has_the_five_values(): void {
        foreach ( Color::BUTTON_MIGRATION as $slug => $row ) {
            $this->assertSame( [ 'bg', 'text', 'hover_bg', 'hover_text', 'shadow' ], array_keys( $row ), $slug );

            foreach ( $row as $key => $hex ) {
                $this->assertMatchesRegularExpression( '/^#[0-9a-f]{3,6}$/', $hex, $slug . '.' . $key );
            }
        }
    }

    public function test_button_migration_backgrounds_agree_with_the_palette(): void {
        foreach ( Color::PALETTE as $name => $hex ) {
            $this->assertSame( $hex, Color::BUTTON_MIGRATION[ $name ]['bg'], $name );
        }
    }

    public function test_button_migration_agrees_with_the_classic_pair(): void {
        foreach ( Color::BUTTON_LEGACY as $slug => $pair ) {
            $this->assertSame( $pair['bg'], Color::BUTTON_MIGRATION[ $slug ]['bg'], $slug );
            $this->assertSame( $pair['text'], Color::BUTTON_MIGRATION[ $slug ]['text'], $slug );
        }
    }

    public function test_only_the_light_buttons_take_a_dark_label(): void {
        foreach ( Color::BUTTON_MIGRATION as $slug => $row ) {
            $expected = in_array( $slug, [ 'grey', 'white' ], true ) ? '#666'
                : ( $slug === 'default' ? '#333' : '#fff' );

            $this->assertSame( $expected, $row['text'], $slug );
        }
    }

    public function test_button_migration_row_for_blue(): void {
        $this->assertSame(
            [ 'bg' => '#5472d2', 'text' => '#fff', 'hover_bg' => '#3c5ecc', 'hover_text' => '#f7f7f7', 'shadow' => '#3253bc' ],
            Color::buttonMigration( 'blue' )
        );
    }

    public function test_button_migration_accepts_both_spellings(): void {
        $this->assertSame( Color::buttonMigration( 'mulled_wine' ), Color::buttonMigration( 'mulled-wine' ) );
        $this->assertSame( '#342f3c', Color::buttonMigration( 'MULLED-WINE' )['shadow'] );
        $this->assertNull( Color::buttonMigration( 'theme-accent' ) );
    }

    public function test_cta_migration_has_the_palette_plus_classic(): void {
        $this->assertCount( 18, Color::CTA_MIGRATION );
        $this->assertArrayHasKey( 'classic', Color::CTA_MIGRATION );

        foreach ( Color::PALETTE as $name => $hex ) {
            $this->assertSame( $hex, Color::CTA_MIGRATION[ $name ]['bg'], $name );
        }
    }

    public function test_cta_migration_row_for_blue(): void {
        $this->assertSame(
            [ 'text' => '#c9d2f0', 'bg' => '#5472d2', 'heading' => '#fff', 'shadow' => '#3253bc' ],
            Color::ctaMigration( 'blue' )
        );
    }

    public function test_the_light_cta_boxes_take_a_dark_heading(): void {
        foreach ( Color::CTA_MIGRATION as $slug => $row ) {
            $expected = in_array( $slug, [ 'classic', 'grey', 'white' ], true ) ? '#666' : '#fff';

            $this->assertSame( $expected, $row['heading'], $slug );
        }
        $this->assertNull( Color::ctaMigration( 'theme-accent' ) );
    }

    public function test_progress_bar_legacy_has_the_seven_classic_bars(): void {
        $this->assertCount( 7, Color::PROGRESS_BAR_LEGACY );
        $this->assertSame( '#0074cc', Color::progressBarLegacy( 'bar_blue' ) );
        $this->assertSame( '#414141', Color::progressBarLegacy( 'BAR_BLACK' ) );
    }

    public function test_the_default_bar_has_no_colour_and_an_unknown_bar_is_null(): void {
        $this->assertSame( '', Color::progressBarLegacy( 'bar_grey' ) );
        $this->assertNull( Color::progressBarLegacy( 'blue' ) );
    }

    public function test_the_progress_bar_label_colours_and_shadow(): void {
        // resolve_progress_bar_txt_color(): #ffffff for a coloured bar, #666666
        // for the grey and white palette bars; apply_progress_bar_color()'s
        // shadow is #00000040.
        $this->assertSame( '#ffffff', Color::PROGRESS_BAR_TEXT );
        $this->assertSame( '#666666', Color::PROGRESS_BAR_TEXT_DARK );
        $this->assertSame( '#00000040', Color::PROGRESS_BAR_TEXT_SHADOW );
    }

    public function test_the_text_shadow_is_a_colour_divi_can_read(): void {
        $this->assertSame( '#00000040', Color::normalize( Color::PROGRESS_BAR_TEXT_SHADOW ) );
    }

    public function test_the_gradient_label_is_wpbakerys_own_white(): void {
        $this->assertSame( '#fff', Color::BUTTON_GRADIENT_TEXT );
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
