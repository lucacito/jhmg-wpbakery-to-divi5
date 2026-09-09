<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Size values, ported unchanged (bar the namespace) from the Beaver
 * converter's `BeaverDivi5Converter\Helpers\Size::withUnit()` /
 * `Size::number()`
 * (plugin/jhmg-converter-for-beaver-builder-to-divi/includes/helpers/class-size.php).
 * WPBakery has no per-field `_unit` sibling attribute or `{length, unit}`
 * typography value the way Beaver Builder does, so `fromSettings()` and
 * `fromLength()` are not ported — every WPBakery size is already a single
 * string such as "20" or "20px", which `PackedParams::sizeWithUnit()`
 * (its own decoder, ported from `wpb_format_with_css_unit()`) reads.
 */
final class Size {

    /**
     * "20" + "%" → "20%"; "1.5" + "em" → "1.5em"; "" → ""; "20px" → "20px"
     * (a value that already carries a unit is trusted).
     */
    public static function withUnit( mixed $value, ?string $unit, string $default_unit = 'px' ): string {
        if ( is_int( $value ) || is_float( $value ) ) {
            $value = (string) $value;
        }
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        if ( ! is_numeric( $value ) ) {
            // "auto", "calc(...)", "20px" — already a CSS length.
            return preg_match( '/^-?[0-9.]+[a-z%]+$/i', $value ) || in_array( $value, [ 'auto', 'inherit', 'initial' ], true ) || str_starts_with( $value, 'calc(' )
                ? $value
                : '';
        }

        // null means "no unit recorded" → the field's default; an explicit ''
        // means unitless.
        $unit = $unit === null ? $default_unit : trim( $unit );

        return $value . $unit;
    }

    /** "20px" → 20.0; "" → null. */
    public static function number( string $css ): ?float {
        if ( $css === '' || ! preg_match( '/^(-?[0-9.]+)/', $css, $m ) ) {
            return null;
        }
        return (float) $m[1];
    }
}
