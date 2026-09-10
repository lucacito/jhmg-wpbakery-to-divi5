<?php

namespace WPBakeryDivi5Converter\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The layout defaults every converter uses to reproduce WPBakery's box model
 * instead of Divi's.
 *
 * WPBakery has no global layout settings option the way Beaver Builder does:
 * its box model lives entirely in `assets/css/js_composer.min.css`. The values
 * below are that stylesheet's, verified by measuring both builders side by
 * side on the Docker site at a 1280 px viewport — `docs/box-model.md`
 * (candidate C, `tests/e2e/box-model.spec.ts`):
 *
 * - Section padding `0px`. WPBakery contributes none; Divi's own default is 56 px.
 * - Every top-level row carries WPBakery's 15 px bleed (`.vc_row{margin:0 -15px}`),
 *   written with Divi's own `:root` layout custom properties and their plain
 *   fallbacks so an unknown property degrades to Divi's shipped width rather
 *   than to `auto`: width `calc(var(--content-width, 80%) + 30px)`, max-width
 *   `calc(var(--content-max-width, 1080px) + 30px)`, margin-x `-15px`. The
 *   bleed stays 15 px whatever the row's `gap`.
 * - A nested row (`vc_row_inner`) uses `calc(100% + 30px)` with no max-width.
 * - Columns keep `.vc_column-inner{padding:0 15px}`, plus 35 px on top when the
 *   row is filled (`.vc_row-has-fill>.vc_column_container>.vc_column-inner`)
 *   **and** on the columns of the row that follows a filled row.
 * - The default module bottom margin is per element, not global.
 *
 * Two contracts the handler tasks depend on:
 *
 * 1. **The StyleMapper never applies `moduleMarginBottom()` itself.** A handler
 *    calls it only when the element's own `css` design options set no
 *    `margin-bottom`; an explicit `margin-bottom` in `css` always wins, because
 *    WPBakery emits it `!important`. Blocks a handler builds through
 *    `delegate()` are pieces of one element and get no default margin at all.
 * 2. **`row_max_width` / `row_width` describe the default, container-width row.**
 *    A `full_width="stretch_row_content"` / `stretch_row_content_no_spaces` row
 *    is width `100%`, max-width `none`, margin `0px` — the row converter applies
 *    that itself; the resolver exposes nothing extra for it.
 *
 * Everything is filterable through `wbdc_layout_defaults`
 * (`array_replace_recursive`, so a site can override a single key). Every value
 * is a CSS string and a zero is written `"0px"`, never `"0"` — Divi tests
 * layout gap values for truthiness (`Module/Options/Layout/LayoutStyle.php`).
 */
final class GlobalSettingsResolver {

    const FILTER = 'wbdc_layout_defaults';

    /** The measured box model, before the filter. */
    const DEFAULTS = [
        'section_padding'           => '0px',
        'row_width'                 => 'calc(var(--content-width, 80%) + 30px)',
        'row_max_width'             => 'calc(var(--content-max-width, 1080px) + 30px)',
        'row_margin_x'              => '-15px',
        'nested_row_width'          => 'calc(100% + 30px)',
        'nested_row_max_width'      => 'none',
        'column_padding_x'          => '15px',
        'filled_column_padding_top' => '35px',
        'module_margins'            => [
            // `.wpb_content_element`, `.vc_icon_element`, `.vc_toggle:last-of-type`.
            'content'   => '35px',
            // `.vc_do_btn`, WPBakery's per-page default sheet, which overrides
            // the stylesheet's 21.73913043px `.vc_btn3-container`.
            'button'    => '22px',
            // `.vc_message_box`.
            'message'   => '21.74px',
            // `.vc_toggle_content`; the last toggle of a run takes 'content'.
            'toggle'    => '21.74px',
            // `.vc_tta-container{margin-bottom:21.73913043px}` — the tta family
            // (accordion, tabs, tour, pageable, toggle) is styled by its own
            // stylesheet, `assets/css/js_composer_tta.min.css`, and carries no
            // `.wpb_content_element` class at all.
            'tta'       => '21.74px',
            // `.entry-content .twitter-share-button,.fb_like,.twitter-share-button,
            // .wpb_accordion .wpb_content_element,.wpb_googleplus,.wpb_pinterest,
            // .wpb_tab .wpb_content_element{margin-bottom:21.73913043px}` — byte
            // 103390, after the 35px rule at byte 103311, so at equal
            // specificity this one wins. It reaches the wrapper of
            // `vc_facebook` (`.fb_like`), `vc_pinterest` and `vc_googleplus`
            // only; `vc_tweetmeme` puts `twitter-share-button` on the anchor
            // inside, not on its `vc_tweetmeme-element` wrapper, and
            // `vc_flickr`'s wrapper is `wpb_flickr_widget`.
            'social'    => '21.74px',
            // Ultimate Addons' `assets/css/video_module.css` `.ult-video{margin:20px}`
            // — all four sides; `UltVideoConverter` writes the other three.
            'ult_video' => '20px',
            // `vc_row_inner` carries no default margin (measured 0px).
            'inner_row' => '0px',
        ],
    ];

    /** @return array<string,mixed> The defaults, overlaid with the filter. */
    public static function layoutDefaults(): array {
        if ( ! function_exists( 'apply_filters' ) ) {
            return self::DEFAULTS;
        }

        // Literal so Plugin Check can read the hook name; keep in step with self::FILTER.
        $filtered = apply_filters( 'wbdc_layout_defaults', self::DEFAULTS );

        return is_array( $filtered ) ? array_replace_recursive( self::DEFAULTS, $filtered ) : self::DEFAULTS;
    }

    /** Section top/bottom padding: WPBakery contributes none. */
    public static function sectionPadding(): string {
        return self::value( 'section_padding' );
    }

    /** `module.decoration.sizing.desktop.value.width` of a container-width row. */
    public static function rowWidth(): string {
        return self::value( 'row_width' );
    }

    /** `module.decoration.sizing.desktop.value.maxWidth` of a container-width row. */
    public static function rowMaxWidth(): string {
        return self::value( 'row_max_width' );
    }

    /** The left and right row margin that produces WPBakery's 15 px bleed. */
    public static function rowMarginX(): string {
        return self::value( 'row_margin_x' );
    }

    /** Width of a `vc_row_inner` (it bleeds inside its column, not the container). */
    public static function nestedRowWidth(): string {
        return self::value( 'nested_row_width' );
    }

    /** A nested row is not clamped to the container width. */
    public static function nestedRowMaxWidth(): string {
        return self::value( 'nested_row_max_width' );
    }

    /** `.vc_column-inner{padding-left:15px;padding-right:15px}`. */
    public static function columnPaddingX(): string {
        return self::value( 'column_padding_x' );
    }

    /** `.vc_row-has-fill>.vc_column_container>.vc_column-inner{padding-top:35px}`. */
    public static function filledColumnPaddingTop(): string {
        return self::value( 'filled_column_padding_top' );
    }

    /**
     * WPBakery's default bottom margin for one kind of element, as read from
     * `js_composer.min.css`. An unknown kind falls back to `content` (35 px).
     *
     * @param string $element_kind content | button | message | toggle | tta | social | ult_video | inner_row
     */
    public static function moduleMarginBottom( string $element_kind = 'content' ): string {
        $margins = self::layoutDefaults()['module_margins'] ?? [];
        $margin  = $margins[ $element_kind ] ?? ( $margins['content'] ?? self::DEFAULTS['module_margins']['content'] );

        return is_string( $margin ) ? $margin : self::DEFAULTS['module_margins']['content'];
    }

    private static function value( string $key ): string {
        $value = self::layoutDefaults()[ $key ] ?? self::DEFAULTS[ $key ];

        return is_string( $value ) ? $value : (string) self::DEFAULTS[ $key ];
    }
}
