<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WPBakery colour values, normalised for Divi. Colours are never invented: a
 * name none of the tables below know resolves to null so the caller can
 * report it, not guess at it.
 *
 * PALETTE is the 17-name colour picker every WPBakery version ships, read
 * from `vc_convert_vc_color()` (include/helpers/helpers.php, js_composer
 * 9.0.1) and cross-checked against `VcSharedLibrary::$colors_hash`
 * (include/classes/core/class-vc-shared-library.php) — both name the same 17
 * hex values, the second under underscored keys. A name may be written
 * either way (`mulled_wine` / `mulled-wine`); the canonical key here is
 * underscored, matching the CSS class names below.
 *
 * BUTTON_LEGACY is `VcSharedLibrary::$btn_solid_colors`' background/text
 * pair (same file) for the 7 slugs `vc_btn`'s classic style — as opposed to
 * the 17-name palette — uses: default, primary, info, success, warning,
 * danger, inverse.
 *
 * MESSAGE_BOX is the text/border/background triple of every
 * `.vc_color-<name>.vc_message_box{...}` rule in `assets/css/js_composer.min.css`
 * (same in 7.8 and 9.0.1): the 17 palette names — `vc_message`'s
 * `message_box_color` field could be set to one directly before 9.0 — plus
 * the 8 `color` dropdown presets `info`/`success`/`warning`/`danger` and
 * their `alert-*` classic counterparts
 * (config/content/shortcode-vc-message.php). That is 25 distinct triples,
 * not 21; every one of them is a real, addressable `color`/`message_box_color`
 * value, so all 25 are kept rather than an arbitrary subset.
 */
final class Color {

    /** The 17-name colour picker, underscored keys, from vc_convert_vc_color(). */
    public const PALETTE = [
        'blue'        => '#5472d2',
        'turquoise'   => '#00c1cf',
        'pink'        => '#fe6c61',
        'violet'      => '#8d6dc4',
        'peacoc'      => '#4cadc9',
        'chino'       => '#cec2ab',
        'mulled_wine' => '#50485b',
        'vista_blue'  => '#75d69c',
        'orange'      => '#f7be68',
        'sky'         => '#5aa1e3',
        'green'       => '#6dab3c',
        'juicy_pink'  => '#f4524d',
        'sandy_brown' => '#f79468',
        'purple'      => '#b97ebb',
        'black'       => '#2a2a2a',
        'grey'        => '#ebebeb',
        'white'       => '#ffffff',
    ];

    /** `vc_btn` classic-style background/text pairs, from $btn_solid_colors. */
    public const BUTTON_LEGACY = [
        'default' => [ 'bg' => '#f7f7f7', 'text' => '#333' ],
        'primary' => [ 'bg' => '#0088cc', 'text' => '#fff' ],
        'info'    => [ 'bg' => '#58b9da', 'text' => '#fff' ],
        'success' => [ 'bg' => '#6ab165', 'text' => '#fff' ],
        'warning' => [ 'bg' => '#ff9900', 'text' => '#fff' ],
        'danger'  => [ 'bg' => '#ff675b', 'text' => '#fff' ],
        'inverse' => [ 'bg' => '#555555', 'text' => '#fff' ],
    ];

    /** `.vc_color-<name>.vc_message_box{color;border-color;background-color}` triples. */
    public const MESSAGE_BOX = [
        'blue'          => [ 'text' => '#364a8a', 'border' => '#c5cff0', 'bg' => '#edf1fa' ],
        'turquoise'     => [ 'text' => '#085b61', 'border' => '#c6ecee', 'bg' => '#ebfcfd' ],
        'pink'          => [ 'text' => '#d82e21', 'border' => '#ffd8d6', 'bg' => '#fff0ef' ],
        'violet'        => [ 'text' => '#5e4a81', 'border' => '#d4c8e9', 'bg' => '#f0ecf7' ],
        'peacoc'        => [ 'text' => '#366a79', 'border' => '#c2e3ec', 'bg' => '#e9f5f8' ],
        'chino'         => [ 'text' => '#978258', 'border' => '#e5ded2', 'bg' => '#f7f5f2' ],
        'mulled_wine'   => [ 'text' => '#1e1b22', 'border' => '#d0ccd6', 'bg' => '#eae8ed' ],
        'vista_blue'    => [ 'text' => '#3e8e5e', 'border' => '#bcebcf', 'bg' => '#e3f7eb' ],
        'orange'        => [ 'text' => '#c3811c', 'border' => '#fbe1ba', 'bg' => '#fef6eb' ],
        'sky'           => [ 'text' => '#2a6194', 'border' => '#bedaf4', 'bg' => '#eaf3fb' ],
        'green'         => [ 'text' => '#3e562b', 'border' => '#c2e1a9', 'bg' => '#eaf5e2' ],
        'juicy_pink'    => [ 'text' => '#a3231f', 'border' => '#fbc7c5', 'bg' => '#fef5f5' ],
        'sandy_brown'   => [ 'text' => '#c3501c', 'border' => '#fbceba', 'bg' => '#fef1eb' ],
        'purple'        => [ 'text' => '#886389', 'border' => '#e3cbe3', 'bg' => '#f5ecf5' ],
        'black'         => [ 'text' => '#fff', 'border' => '#2a2a2a', 'bg' => '#3c3c3c' ],
        'grey'          => [ 'text' => '#858585', 'border' => '#d2d2d2', 'bg' => '#ebebeb' ],
        'white'         => [ 'text' => '#b3b3b3', 'border' => '#e6e6e6', 'bg' => '#fff' ],
        'info'          => [ 'text' => '#5e7f96', 'border' => '#cfebfe', 'bg' => '#dff2fe' ],
        'success'       => [ 'text' => '#5e7f96', 'border' => '#cfebfe', 'bg' => '#e6fdf8' ],
        'warning'       => [ 'text' => '#9d8967', 'border' => '#ffeccc', 'bg' => '#fff4e2' ],
        'danger'        => [ 'text' => '#a85959', 'border' => '#fedede', 'bg' => '#fdeaea' ],
        'alert-info'    => [ 'text' => '#31708f', 'border' => '#bce8f1', 'bg' => '#d9edf7' ],
        'alert-success' => [ 'text' => '#3c763d', 'border' => '#d6e9c6', 'bg' => '#dff0d8' ],
        'alert-warning' => [ 'text' => '#8a6d3b', 'border' => '#faebcc', 'bg' => '#fcf8e3' ],
        'alert-danger'  => [ 'text' => '#a94442', 'border' => '#ebccd1', 'bg' => '#f2dede' ],
    ];

    /**
     * A CSS colour Divi can use: lower-cased hex pass-through, `rgb()`/`rgba()`/
     * `hsl()`/`hsla()` pass-through, or the palette hex for a palette name
     * (either spelling). Anything else — including a non-string or an empty
     * value — is null, never a guess.
     */
    public static function normalize( mixed $raw ): ?string {
        if ( ! is_string( $raw ) ) {
            return null;
        }

        $value = trim( $raw );
        if ( $value === '' ) {
            return null;
        }

        if ( preg_match( '/^#?([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value, $m ) ) {
            return '#' . strtolower( $m[1] );
        }

        if ( preg_match( '/^(rgba?|hsla?)\s*\(/i', $value ) ) {
            return $value;
        }

        return self::fromPalette( $value );
    }

    /** True when $name (underscore or hyphen spelling) is one of the 17 palette names. */
    public static function isPaletteName( string $name ): bool {
        return self::fromPalette( $name ) !== null;
    }

    /** The palette hex for $name (underscore or hyphen spelling), or null when unknown. */
    public static function fromPalette( string $name ): ?string {
        $key = str_replace( '-', '_', strtolower( trim( $name ) ) );

        return self::PALETTE[ $key ] ?? null;
    }

    /** `#rrggbb` + opacity (0-1) → `rgba()`; non-hex colours are returned unchanged. */
    public static function withOpacity( string $color, float $opacity ): string {
        if ( ! preg_match( '/^#([0-9a-f]{6})$/i', $color, $m ) ) {
            return $color;
        }
        [ $r, $g, $b ] = sscanf( $m[1], '%02x%02x%02x' );

        return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim( rtrim( number_format( max( 0, min( 1, $opacity ) ), 2, '.', '' ), '0' ), '.' ) );
    }
}
