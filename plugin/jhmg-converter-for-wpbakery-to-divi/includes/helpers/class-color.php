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
     * The colour values WPBakery's own 9.0 migration writes when it turns a
     * legacy button `color` dropdown into colorpicker attributes, one row per
     * slug.
     *
     * Read from `VcSharedLibrary::$btn_solid_colors` (background, text, hover
     * background, hover text) and `VcSharedLibrary::$btn_3d_colors` (whose
     * third value is the 3d style's box shadow, a `darken(@bg, 11%)` of the
     * same background) — include/classes/core/class-vc-shared-library.php,
     * js_composer 9.0.1. Both tables carry the same background and text per
     * slug, so one row covers both and `$btn_3d_colors` adds only the shadow.
     *
     * `$btn_outline_colors` needs no table of its own: every one of its rows
     * is `[ text = bg, border = bg, hover text = text ]` of the row here,
     * checked against all 24 slugs.
     *
     * The keys are the 17 palette names plus the 7 classic Bootstrap-2 slugs
     * (`default`, `primary`, `info`, `success`, `warning`, `danger`,
     * `inverse`), underscored as everywhere else in this class — the source
     * writes the multi-word ones hyphenated — and the hex values are
     * lower-cased. `Color::BUTTON_LEGACY` is the `bg`/`text` pair of the seven
     * classic rows, kept for callers that need only that pair.
     */
    public const BUTTON_MIGRATION = [
        'blue'        => [ 'bg' => '#5472d2', 'text' => '#fff', 'hover_bg' => '#3c5ecc', 'hover_text' => '#f7f7f7', 'shadow' => '#3253bc' ],
        'turquoise'   => [ 'bg' => '#00c1cf', 'text' => '#fff', 'hover_bg' => '#00a4b0', 'hover_text' => '#f7f7f7', 'shadow' => '#008d97' ],
        'pink'        => [ 'bg' => '#fe6c61', 'text' => '#fff', 'hover_bg' => '#fe5043', 'hover_text' => '#f7f7f7', 'shadow' => '#fe3829' ],
        'violet'      => [ 'bg' => '#8d6dc4', 'text' => '#fff', 'hover_bg' => '#7c57bb', 'hover_text' => '#f7f7f7', 'shadow' => '#6e48b1' ],
        'peacoc'      => [ 'bg' => '#4cadc9', 'text' => '#fff', 'hover_bg' => '#39a0bd', 'hover_text' => '#f7f7f7', 'shadow' => '#338faa' ],
        'chino'       => [ 'bg' => '#cec2ab', 'text' => '#fff', 'hover_bg' => '#c3b498', 'hover_text' => '#f7f7f7', 'shadow' => '#b9a888' ],
        'mulled_wine' => [ 'bg' => '#50485b', 'text' => '#fff', 'hover_bg' => '#413a4a', 'hover_text' => '#f7f7f7', 'shadow' => '#342f3c' ],
        'vista_blue'  => [ 'bg' => '#75d69c', 'text' => '#fff', 'hover_bg' => '#5dcf8b', 'hover_text' => '#f7f7f7', 'shadow' => '#4ac97d' ],
        'black'       => [ 'bg' => '#2a2a2a', 'text' => '#fff', 'hover_bg' => '#1b1b1b', 'hover_text' => '#f7f7f7', 'shadow' => '#0e0e0e' ],
        'grey'        => [ 'bg' => '#ebebeb', 'text' => '#666', 'hover_bg' => '#dcdcdc', 'hover_text' => '#5e5e5e', 'shadow' => '#cfcfcf' ],
        'orange'      => [ 'bg' => '#f7be68', 'text' => '#fff', 'hover_bg' => '#f5b14b', 'hover_text' => '#f7f7f7', 'shadow' => '#f4a733' ],
        'sky'         => [ 'bg' => '#5aa1e3', 'text' => '#fff', 'hover_bg' => '#4092df', 'hover_text' => '#f7f7f7', 'shadow' => '#2a86db' ],
        'green'       => [ 'bg' => '#6dab3c', 'text' => '#fff', 'hover_bg' => '#5f9434', 'hover_text' => '#f7f7f7', 'shadow' => '#53812d' ],
        'juicy_pink'  => [ 'bg' => '#f4524d', 'text' => '#fff', 'hover_bg' => '#f23630', 'hover_text' => '#f7f7f7', 'shadow' => '#f11f18' ],
        'sandy_brown' => [ 'bg' => '#f79468', 'text' => '#fff', 'hover_bg' => '#f57f4b', 'hover_text' => '#f7f7f7', 'shadow' => '#f46e33' ],
        'purple'      => [ 'bg' => '#b97ebb', 'text' => '#fff', 'hover_bg' => '#ae6ab0', 'hover_text' => '#f7f7f7', 'shadow' => '#a559a8' ],
        'white'       => [ 'bg' => '#ffffff', 'text' => '#666', 'hover_bg' => '#f0f0f0', 'hover_text' => '#5e5e5e', 'shadow' => '#e3e3e3' ],
        'default'     => [ 'bg' => '#f7f7f7', 'text' => '#333', 'hover_bg' => '#e8e8e8', 'hover_text' => '#2b2b2b', 'shadow' => '#dbdbdb' ],
        'primary'     => [ 'bg' => '#0088cc', 'text' => '#fff', 'hover_bg' => '#0074ad', 'hover_text' => '#f7f7f7', 'shadow' => '#006394' ],
        'info'        => [ 'bg' => '#58b9da', 'text' => '#fff', 'hover_bg' => '#3fafd4', 'hover_text' => '#f7f7f7', 'shadow' => '#2da4cd' ],
        'success'     => [ 'bg' => '#6ab165', 'text' => '#fff', 'hover_bg' => '#59a453', 'hover_text' => '#f7f7f7', 'shadow' => '#4f934b' ],
        'warning'     => [ 'bg' => '#ff9900', 'text' => '#fff', 'hover_bg' => '#e08700', 'hover_text' => '#f7f7f7', 'shadow' => '#c77700' ],
        'danger'      => [ 'bg' => '#ff675b', 'text' => '#fff', 'hover_bg' => '#ff4b3c', 'hover_text' => '#f7f7f7', 'shadow' => '#ff3323' ],
        'inverse'     => [ 'bg' => '#555555', 'text' => '#fff', 'hover_bg' => '#464646', 'hover_text' => '#f7f7f7', 'shadow' => '#393939' ],
    ];

    /**
     * `VcSharedLibrary::$cta_colors` (same file): the four values
     * `Wpb_Attributes_Migration_Abstract::apply_cta_flat_color()`,
     * `apply_cta_3d_color()` and `apply_cta_outline_color()` read when a
     * `vc_cta`'s `color` dropdown still holds a slug. `text` is the box's body
     * text — the `text_color` attribute `WPBakeryShortCode_Vc_Cta` still
     * honours (include/classes/shortcodes/vc-cta.php) — `bg` its background,
     * `heading` its `custom_text`, and `shadow` the 3d style's box shadow.
     *
     * 18 rows: the 17 palette names plus `classic`, the style's own default,
     * which is not a palette colour. Keys underscored, hex lower-cased.
     */
    public const CTA_MIGRATION = [
        'classic'     => [ 'text' => '#9d9d9e', 'bg' => '#f0f0f0', 'heading' => '#666', 'shadow' => '#d4d4d4' ],
        'blue'        => [ 'text' => '#c9d2f0', 'bg' => '#5472d2', 'heading' => '#fff', 'shadow' => '#3253bc' ],
        'turquoise'   => [ 'text' => '#d3f5f1', 'bg' => '#00c1cf', 'heading' => '#fff', 'shadow' => '#008d97' ],
        'pink'        => [ 'text' => '#fcdbd7', 'bg' => '#fe6c61', 'heading' => '#fff', 'shadow' => '#fe3829' ],
        'violet'      => [ 'text' => '#e1d5f5', 'bg' => '#8d6dc4', 'heading' => '#fff', 'shadow' => '#6e48b1' ],
        'peacoc'      => [ 'text' => '#d0edf5', 'bg' => '#4cadc9', 'heading' => '#fff', 'shadow' => '#338faa' ],
        'chino'       => [ 'text' => '#f7f3eb', 'bg' => '#cec2ab', 'heading' => '#fff', 'shadow' => '#b9a888' ],
        'mulled_wine' => [ 'text' => '#e2ddeb', 'bg' => '#50485b', 'heading' => '#fff', 'shadow' => '#342f3c' ],
        'vista_blue'  => [ 'text' => '#e1f5e9', 'bg' => '#75d69c', 'heading' => '#fff', 'shadow' => '#4ac97d' ],
        'black'       => [ 'text' => '#d9d9d9', 'bg' => '#2a2a2a', 'heading' => '#fff', 'shadow' => '#0e0e0e' ],
        'grey'        => [ 'text' => '#9d9d9e', 'bg' => '#ebebeb', 'heading' => '#666', 'shadow' => '#cfcfcf' ],
        'orange'      => [ 'text' => '#faf0e1', 'bg' => '#f7be68', 'heading' => '#fff', 'shadow' => '#f4a733' ],
        'sky'         => [ 'text' => '#dce9f5', 'bg' => '#5aa1e3', 'heading' => '#fff', 'shadow' => '#2a86db' ],
        'green'       => [ 'text' => '#e5f2da', 'bg' => '#6dab3c', 'heading' => '#fff', 'shadow' => '#53812d' ],
        'juicy_pink'  => [ 'text' => '#fce2e1', 'bg' => '#f4524d', 'heading' => '#fff', 'shadow' => '#f11f18' ],
        'sandy_brown' => [ 'text' => '#f7e1d7', 'bg' => '#f79468', 'heading' => '#fff', 'shadow' => '#f46e33' ],
        'purple'      => [ 'text' => '#f4dff5', 'bg' => '#b97ebb', 'heading' => '#fff', 'shadow' => '#a559a8' ],
        'white'       => [ 'text' => '#9d9d9e', 'bg' => '#ffffff', 'heading' => '#666', 'shadow' => '#e3e3e3' ],
    ];

    /**
     * `Wpb_Attributes_Migration_Abstract::resolve_progress_bar_color()`'s
     * `$bar_colors`
     * (include/classes/migrations/abstract-class-wpb-attributes-migration.php,
     * js_composer 9.0.1): the seven Bootstrap-2 bar colours `vc_progress_bar`
     * offered beside the palette, which live in the migration itself rather
     * than in any shared table. `bar_grey` is the uncoloured default and is
     * empty in the source too.
     */
    public const PROGRESS_BAR_LEGACY = [
        'bar_grey'      => '',
        'bar_blue'      => '#0074cc',
        'bar_turquoise' => '#49afcd',
        'bar_green'     => '#5bb75b',
        'bar_orange'    => '#faa732',
        'bar_red'       => '#da4f49',
        'bar_black'     => '#414141',
    ];

    /**
     * The label colour
     * `Wpb_Template_Attributes_Migration::convert_btn_gradient_style_to_gradient_custom()`
     * writes when a gradient button becomes a custom gradient:
     * `$out[ … 'gradient_text_color' ] = '#fff'`.
     */
    public const BUTTON_GRADIENT_TEXT = '#fff';

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
        return self::PALETTE[ self::slug( $name ) ] ?? null;
    }

    /**
     * One `BUTTON_MIGRATION` row (`bg`, `text`, `hover_bg`, `hover_text`,
     * `shadow`) for a legacy button `color` slug in either spelling, or null
     * when the slug is not one WPBakery ships.
     *
     * @return array{bg: string, text: string, hover_bg: string, hover_text: string, shadow: string}|null
     */
    public static function buttonMigration( string $slug ): ?array {
        return self::BUTTON_MIGRATION[ self::slug( $slug ) ] ?? null;
    }

    /**
     * One `CTA_MIGRATION` row (`text`, `bg`, `heading`, `shadow`) for a legacy
     * `vc_cta` `color` slug in either spelling, or null when unknown.
     *
     * @return array{text: string, bg: string, heading: string, shadow: string}|null
     */
    public static function ctaMigration( string $slug ): ?array {
        return self::CTA_MIGRATION[ self::slug( $slug ) ] ?? null;
    }

    /**
     * The hex for a classic `bar_*` progress-bar slug — '' for `bar_grey`,
     * which is the uncoloured default — or null when the slug is not one.
     */
    public static function progressBarLegacy( string $slug ): ?string {
        return self::PROGRESS_BAR_LEGACY[ self::slug( $slug ) ] ?? null;
    }

    /** A colour slug in this class's canonical spelling: lower case, underscored. */
    private static function slug( string $name ): string {
        return str_replace( '-', '_', strtolower( trim( $name ) ) );
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
