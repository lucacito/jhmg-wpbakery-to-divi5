<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Decoders for WPBakery's packed parameter formats.
 *
 * WPBakery packs several unrelated widget shapes into one attribute string.
 * Every decoder here is a straight port of the js_composer 9.0.1 function
 * that reads it at render time (both eras use the same packing — 7.8's
 * `vc_parse_multi_attribute()` is byte-identical to 9.0.1's):
 *
 * - `link()` is `vc_build_link()` (include/params/vc_link/vc_link.php), i.e.
 *   `vc_parse_multi_attribute()` (include/helpers/helpers.php), plus the
 *   bare-URL wrapping `include/params/href/href.php`'s `vc_href_form_field()`
 *   does before a plain `href` value ever reaches it: a value with no `url:`
 *   key is the whole URL, not junk.
 * - `fontContainer()` is `Vc_Font_Container::_vc_font_container_parse_attributes()`
 *   (include/params/font_container/font_container.php), also
 *   `vc_parse_multi_attribute()` under the hood, narrowed to the six fields
 *   `vc_custom_heading`'s template actually reads.
 * - `googleFonts()` is `Vc_Google_Fonts::_vc_google_fonts_parse_attributes()`
 *   (include/params/google_fonts/google_fonts.php) followed by the
 *   `font_family`/`font_style` splitting `WPBakeryShortCode_Vc_Custom_Heading::getStyles()`
 *   does (include/classes/shortcodes/vc-custom-heading.php): family is the
 *   text before the first `:`, weight and italic-ness are the second and
 *   third `:`-separated parts of `font_style` ("400 regular:400:normal").
 * - `paramGroup()`, `safeValue()` (`vc_value_from_safe()`) and `rawHtml()`
 *   (`include/templates/shortcodes/vc_raw_html.php`) are also from
 *   include/helpers/helpers.php.
 * - `sizeWithUnit()` is `wpb_format_with_css_unit()`'s regex
 *   (include/helpers/helpers.php), with the unit list from
 *   `VcSharedLibrary::get_css_units()`
 *   (include/classes/core/class-vc-shared-library.php) — unlike the WPBakery
 *   original, unparseable input returns '' rather than a fabricated "0px",
 *   so a caller can tell "no size" from "zero".
 */
final class PackedParams {

    /** `wpb_format_with_css_unit()`'s unit list, from get_css_units(). */
    private const UNITS = 'px|%|in|cm|mm|em|rem|ex|pt|pc|vw|vh|vmin|vmax';

    /**
     * `vc_build_link()` / `vc_href_form_field()` semantics.
     *
     * @return array{url: string, title: string, target: string, rel: string}
     */
    public static function link( string $raw ): array {
        // A bare URL (href.php's domain) never contains '|' or 'url:' itself;
        // a string that does is a packed-value attempt (however malformed —
        // '||' and '|' decode to all-empty, not to a literal URL of "||").
        if ( $raw !== '' && ! str_contains( $raw, '|' ) && ! str_contains( $raw, 'url:' ) ) {
            $raw = 'url:' . rawurlencode( $raw );
        }

        return self::parseMulti( $raw, [ 'url' => '', 'title' => '', 'target' => '', 'rel' => '' ] );
    }

    /**
     * `Vc_Font_Container::_vc_font_container_parse_attributes()`, narrowed to
     * the six fields the custom-heading template reads. `'' → []` rather than
     * the field defaults `_vc_font_container_parse_attributes()` would apply,
     * since PackedParams has no `settings.fields` context to draw them from.
     *
     * @return array<string,string>
     */
    public static function fontContainer( string $raw ): array {
        if ( $raw === '' ) {
            return [];
        }

        $defaults = [
            'tag'         => '',
            'font_size'   => '',
            'text_align'  => '',
            'line_height' => '',
            'color'       => '',
            'font_family' => '',
        ];

        return self::parseMulti( $raw, $defaults );
    }

    /**
     * `Vc_Google_Fonts::_vc_google_fonts_parse_attributes()` plus the
     * family/weight/style split `getStyles()` performs on its result.
     *
     * @return array{family: string, weight: string, italic: bool}
     */
    public static function googleFonts( string $raw ): array {
        $decoded = self::parseMulti( $raw, [ 'font_family' => '', 'font_style' => '' ] );

        $family_parts = explode( ':', $decoded['font_family'] );
        $style_parts  = explode( ':', $decoded['font_style'] );

        return [
            'family' => $family_parts[0] ?? '',
            'weight' => $style_parts[1] ?? '',
            'italic' => ( $style_parts[2] ?? '' ) === 'italic',
        ];
    }

    /** `json_decode( urldecode( $raw ), true )`, or `[]` when that is not an array. */
    public static function paramGroup( string $raw ): array {
        $decoded = json_decode( urldecode( $raw ), true );

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * `vc_value_from_safe()` (include/helpers/helpers.php), without its
     * `$encode` parameter — nothing here calls it with `$encode = true`.
     */
    public static function safeValue( string $raw ): string {
        $value = preg_match( '/^#E\-8_/', $raw )
            ? rawurldecode( (string) base64_decode( preg_replace( '/^#E\-8_/', '', $raw ) ) )
            : $raw;

        return str_replace( [ '`{`', '`}`', '``' ], [ '[', ']', '"' ], $value );
    }

    /** `include/templates/shortcodes/vc_raw_html.php`'s `$content` decode. */
    public static function rawHtml( string $raw ): string {
        return rawurldecode( (string) base64_decode( wp_strip_all_tags( $raw ) ) );
    }

    /**
     * `wpb_format_with_css_unit()`'s pattern
     * `^(\d*(?:\.\d+)?)\s*(px|%|in|cm|mm|em|rem|ex|pt|pc|vw|vh|vmin|vmax)?$`.
     * Unlike the WPBakery original, a value the pattern cannot read at all —
     * or one with no numeric part — returns '' instead of a fabricated
     * "0" . $default_unit.
     */
    public static function sizeWithUnit( string $raw, string $default_unit = 'px' ): string {
        $value = preg_replace( '/\s+/', '', $raw );

        if ( ! preg_match( '/^(\d*(?:\.\d+)?)(' . self::UNITS . ')?$/', (string) $value, $m ) ) {
            return '';
        }

        if ( $m[1] === '' ) {
            return '';
        }

        $unit = $m[2] ?? '';

        return $m[1] . ( $unit !== '' ? $unit : $default_unit );
    }

    /** True for the boolean-ish strings WPBakery attributes use: true, yes, 1, on. */
    public static function isOn( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_int( $value ) ) {
            return $value === 1;
        }
        if ( ! is_string( $value ) ) {
            return false;
        }

        return in_array( strtolower( trim( $value ) ), [ 'true', 'yes', '1', 'on' ], true );
    }

    /**
     * `vc_parse_multi_attribute()`: split on `|`, each pair split on the
     * *first* `:` only (WPBakery uses `preg_split('/\:/', $pair)` and keeps
     * only parts 0 and 1 — safe because every packed value is itself
     * rawurlencoded, so a real `:` never appears before decoding).
     *
     * @param array<string,string> $defaults
     * @return array<string,string>
     */
    private static function parseMulti( string $raw, array $defaults ): array {
        $result = $defaults;

        foreach ( explode( '|', $raw ) as $pair ) {
            $colon = strpos( $pair, ':' );
            if ( $colon === false ) {
                continue;
            }
            $key = substr( $pair, 0, $colon );
            if ( $key === '' ) {
                continue;
            }
            $result[ $key ] = trim( rawurldecode( substr( $pair, $colon + 1 ) ) );
        }

        return $result;
    }
}
