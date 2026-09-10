<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The packed parameter formats DFD Ronneby's own elements use, read from
 * Ronneby Core 1.5.74 (`references/ronneby-core.zip`).
 *
 * They are the theme's shapes, not WPBakery's, which is why they live here
 * beside `UltimateFields` rather than in `PackedParams` — though three of the
 * four are `vc_parse_multi_attribute()` underneath, because Ronneby builds its
 * params on WPBakery's helper:
 *
 * - **Font options** (`title_font_options`, `subtitle_font_options`,
 *   `font_options`, `number_font_options`, `tab_title_font_options`, …) is
 *   `tag:h3|font_size:45|line_height:40|color:%23333333|letter_spacing:2`.
 *   The param declares its fields in
 *   `inc/vc_custom/dfd_vc_addons/params/param_font_container.php:321-339`
 *   (`Dfd_Font_Container::_dfd_font_container_parse_attributes()`, a
 *   `vc_parse_multi_attribute()` call), and the render side is
 *   `_crum_parse_text_shortcode_params()`
 *   (`inc/vc_custom/dfd_vc_addons.php:155-243`) — which is where the semantics
 *   come from, and they are **not** `font_container`'s:
 *   `_crum_parse_text_shortcode_params()` splits on `|` and then on `:`, and
 *   decodes nothing except `%23` → `#` and `%2C` → `,` on `color` (line 217).
 *   Every numeric field is emitted as `<key with _ → ->: <value>px` (line 225),
 *   so `font_size`, `line_height` and `letter_spacing` are all pixels; the
 *   three `font_style_*` flags are `font-style:italic`, `font-weight:bold` and
 *   `text-decoration:underline` (lines 209-214).
 * - **Responsive text** (`title_responsive`, `content_responsive`,
 *   `delimiter_text_responsive`) is
 *   `font_size_desktop:40|font_size_tablet:30|line_height_mobile:26`, read by
 *   `Dfd_Resposive_Text_Param::responsive_css()`
 *   (`params/param_responsive_text.php:71-101`). Its three bands are media
 *   queries, not Divi breakpoints: `desktop` is 1024-1279px, `tablet` 800-1023px and `mobile` ≤799px (lines 80-84).
 * - **A param group** (`dfd_social_networks`, `list_fields`, `info_fields`,
 *   `location_list`) is WPBakery's own `param_group`, i.e.
 *   `json_decode( urldecode( … ) )` — `PackedParams::paramGroup()`.
 * - **A spacer's sizes** are four plain numbers with four resolutions
 *   (`modules/dfd_spacer.php:44-126`), applied by the theme's own script
 *   (`assets/js_pub/uncompresed.js:16321-16353`).
 *
 * Two more CSS-fragment params are decoded here because several elements carry
 * them: `Dfd_Margin`'s `margin-top:20px;margin-bottom:30px;` (a plain
 * declaration list, `params/Dfd_Margin.php`) and `Dfd_Border`'s
 * `border-style:solid;|border-width:1px;border-radius:0px;|border-color:#fff;`
 * (the same list with `|` separators the module strips,
 * `modules/dfd_button.php:787`).
 */
final class RonnebyParams {

    /**
     * The three responsive bands `Dfd_Resposive_Text_Param::responsive_css()`
     * writes, and the Divi breakpoint each one covers.
     *
     * Ronneby's `desktop` band is a *medium* desktop (1024-1279px) that Divi
     * has no breakpoint for — Divi's desktop is everything from 981px up — so
     * it is reported by the caller rather than written over the base size.
     */
    const RESPONSIVE_BANDS = [
        'tablet' => 'tablet',
        'mobile' => 'phone',
    ];

    /** `responsive_css()`'s three declarations, and the Divi font sub-name each becomes. */
    const RESPONSIVE_FIELDS = [
        'font_size'      => 'size',
        'line_height'    => 'lineHeight',
        'letter_spacing' => 'letterSpacing',
    ];

    /**
     * One `dfd_font_container_param` value, decoded exactly as
     * `_crum_parse_text_shortcode_params()` reads it.
     *
     * Keys are returned as they were written; `color` has WPBakery's `%23`/`%2C`
     * escapes undone the way line 217 does, and nothing else is decoded —
     * a family name is stored plainly (`font_family:TeXGyreAdventorRegular`).
     *
     * **One deliberate divergence.** The theme splits each pair with
     * `explode( ':', $single_param )` and keeps part 1
     * (`dfd_vc_addons.php:167-168`), so a value that contains a colon is
     * truncated at the second one — `color:rgba(0,0,0,.5)` survives, but a
     * hypothetical `font_family:Font: Bold` would become `Font`. Everything
     * after the first colon is kept here instead: the theme's truncation is a
     * bug that loses data, and no corpus value depends on it.
     *
     * @return array<string,string>
     */
    public static function fontOptions( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return [];
        }

        $values = [];

        foreach ( explode( '|', $raw ) as $pair ) {
            $colon = strpos( $pair, ':' );
            if ( $colon === false ) {
                continue;
            }

            $key = trim( substr( $pair, 0, $colon ) );
            if ( $key === '' ) {
                continue;
            }

            $value = trim( substr( $pair, $colon + 1 ) );
            if ( $key === 'color' ) {
                $value = str_replace( [ '%23', '%2C' ], [ '#', ',' ], $value );
            }

            $values[ $key ] = $value;
        }

        return $values;
    }

    /**
     * One `dfd_param_responsive_text` value as
     * `[ divi breakpoint => [ divi font sub-name => css value ] ]`, plus the
     * `desktop` band under the key `medium_desktop` for the caller to report.
     *
     * `responsive_css()` writes every one of the three as pixels
     * (`params/param_responsive_text.php:89-96`).
     *
     * @return array<string, array<string,string>>
     */
    public static function responsiveText( string $raw ): array {
        $values = [];

        foreach ( explode( '|', trim( $raw ) ) as $pair ) {
            $colon = strpos( $pair, ':' );
            if ( $colon === false ) {
                continue;
            }
            $values[ trim( substr( $pair, 0, $colon ) ) ] = trim( substr( $pair, $colon + 1 ) );
        }

        $bands = self::RESPONSIVE_BANDS + [ 'desktop' => 'medium_desktop' ];
        $out   = [];

        foreach ( $bands as $band => $breakpoint ) {
            foreach ( self::RESPONSIVE_FIELDS as $field => $sub_name ) {
                $value = $values[ $field . '_' . $band ] ?? '';
                if ( trim( (string) $value ) === '' ) {
                    continue;
                }
                $out[ $breakpoint ][ $sub_name ] = PackedParams::sizeWithUnit( (string) $value );
            }
        }

        return $out;
    }

    /**
     * A spacer's height per Divi breakpoint.
     *
     * `dfd_spacer` carries four sizes and four resolutions
     * (`modules/dfd_spacer.php:44-126`) and the theme's script picks one by
     * window width (`assets/js_pub/uncompresed.js:16337-16353`):
     * ≥`screen_wide_resolution` (1280) the wide size, down to
     * `screen_normal_resolution` (1024) the normal one, down to
     * `screen_tablet_resolution` (800) the tablet one, and below that the
     * mobile one.
     *
     * Divi's desktop is everything from 981px up, which is Ronneby's *normal*
     * band and above — so the normal size is the desktop height and the wide
     * size only stands in when normal is blank. Ronneby's tablet band
     * (800-1023) is Divi's tablet (768-980) and its mobile band is Divi's phone.
     *
     * `units="%"` makes the other three sizes percentages of the wide one
     * (`uncompresed.js:16331-16335`), so they are resolved to pixels here the
     * same way the script does — the rendered element is always `px`, because
     * jQuery's `.css('height', <number>)` writes pixels.
     *
     * @param array<string,mixed> $atts
     * @return array{desktop: string, tablet: string, phone: string}
     */
    public static function responsiveSizes( array $atts, string $base = 'screen' ): array {
        $percent = strtolower( trim( (string) ( $atts['units'] ?? '' ) ) ) === '%';

        $raw = [];
        foreach ( [ 'wide', 'normal', 'tablet', 'mobile' ] as $band ) {
            $value        = trim( (string) ( $atts[ $base . '_' . $band . '_spacer_size' ] ?? '' ) );
            $raw[ $band ] = is_numeric( $value ) ? (float) $value : null;
        }

        if ( $percent && $raw['wide'] !== null ) {
            foreach ( [ 'normal', 'tablet', 'mobile' ] as $band ) {
                if ( $raw[ $band ] !== null ) {
                    $raw[ $band ] = $raw['wide'] * $raw[ $band ] / 100;
                }
            }
        }

        $desktop = $raw['normal'] ?? $raw['wide'];

        return [
            'desktop' => self::px( $desktop ),
            'tablet'  => self::px( $raw['tablet'] ),
            'phone'   => self::px( $raw['mobile'] ),
        ];
    }

    /**
     * A `param_group` value (`dfd_social_networks`, `list_fields`,
     * `info_fields`), i.e. WPBakery's own `json_decode( urldecode( … ) )`.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function items( string $raw ): array {
        $decoded = PackedParams::paramGroup( $raw );

        return array_values( array_filter( $decoded, 'is_array' ) );
    }

    /**
     * A CSS declaration list (`Dfd_Margin`, `Dfd_Border`, `Dfd_Delimiter`) as
     * `property => value`.
     *
     * The modules print these verbatim after stripping the `|` the editor puts
     * between groups (`modules/dfd_button.php:787`,
     * `modules/dfd_heading_module.php:585`), so the decode is that strip plus a
     * split on `;`.
     *
     * @return array<string,string>
     */
    public static function declarations( string $raw ): array {
        $out = [];

        foreach ( explode( ';', str_replace( '|', '', trim( $raw ) ) ) as $declaration ) {
            $colon = strpos( $declaration, ':' );
            if ( $colon === false ) {
                continue;
            }

            $property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
            $value    = trim( substr( $declaration, $colon + 1 ) );

            if ( $property !== '' && $value !== '' ) {
                $out[ $property ] = $value;
            }
        }

        return $out;
    }

    /**
     * A `dfd_box_shadow_param` value as Divi's box-shadow value, or null when
     * the element switched its shadow off.
     *
     * `Dfd_Box_Shadow_Param::box_shadow_css()`
     * (`params/param_box_shadow.php:66-82`) reads
     * `box_shadow_enable:enable|shadow_horizontal:0|shadow_vertical:15|shadow_blur:70|shadow_spread:0|box_shadow_color:rgba(0%2C0%2C0%2C0.3)`
     * through `vc_parse_multi_attribute()` and writes
     * `<h>px <v>px <blur>px <spread>px <color>`; `disable` writes
     * `box-shadow: none`.
     *
     * @return array{style:string,horizontal:string,vertical:string,blur:string,spread:string,position:string,color:string}|null
     */
    public static function boxShadow( string $raw ): ?array {
        $values = self::fontOptions( $raw );

        if ( $values === [] || ( $values['box_shadow_enable'] ?? '' ) !== 'enable' ) {
            return null;
        }

        $color = Color::normalize( rawurldecode( $values['box_shadow_color'] ?? '' ) );
        if ( $color === null ) {
            return null;
        }

        return [
            'style'      => 'preset1',
            'horizontal' => self::px( self::number( $values['shadow_horizontal'] ?? '' ) ) ?: '0px',
            'vertical'   => self::px( self::number( $values['shadow_vertical'] ?? '' ) ) ?: '0px',
            'blur'       => self::px( self::number( $values['shadow_blur'] ?? '' ) ) ?: '0px',
            'spread'     => self::px( self::number( $values['shadow_spread'] ?? '' ) ) ?: '0px',
            'position'   => 'outer',
            'color'      => $color,
        ];
    }

    private static function number( string $raw ): ?float {
        $raw = trim( $raw );

        return is_numeric( $raw ) ? (float) $raw : null;
    }

    /** A number as a pixel string, with a whole number kept whole. */
    private static function px( ?float $value ): string {
        if ( $value === null ) {
            return '';
        }

        $rounded = round( $value, 2 );

        return ( $rounded === floor( $rounded ) ? (string) (int) $rounded : rtrim( rtrim( number_format( $rounded, 2, '.', '' ), '0' ), '.' ) ) . 'px';
    }
}
