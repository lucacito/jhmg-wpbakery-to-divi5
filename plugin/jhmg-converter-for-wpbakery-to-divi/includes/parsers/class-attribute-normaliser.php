<?php

namespace WPBakeryDivi5Converter\Parsers;

use WPBakeryDivi5Converter\Helpers\Color;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Folds both WPBakery attribute eras into the shapes js_composer 9.0.1 uses.
 *
 * Every converter handler in this plugin is written to the 9.0.1 attribute
 * names. Content in the wild is not: a page saved in 2016 carries deprecated
 * tags (`vc_button`, `vc_tabs`) and pre-9.0 attribute forms (a `color`
 * dropdown holding a palette slug, an `options` checkbox holding a
 * comma-separated list, a `no_fill_content_area` checkbox with inverted
 * meaning). WPBakery itself converts those at render time, in two places:
 *
 * - `WPBakeryShortCode_Vc_Btn::convertAttributesToButton3()`
 *   (include/classes/shortcodes/vc-btn.php) for the button-1/button-2 atts;
 * - `Wpb_Template_Attributes_Migration` and its parent
 *   `Wpb_Attributes_Migration_Abstract`
 *   (include/classes/migrations/{class-wpb-template-attributes-migration.php,
 *   abstract-class-wpb-attributes-migration.php}) for the 9.0 renames, hooked
 *   onto `shortcode_atts_<tag>`.
 *
 * Both are ported here method for method — each private method keeps the name
 * of the WPBakery callback it ports and cites its file — so that a handler
 * only ever sees one shape. Everything this class has no rule for passes
 * through untouched.
 *
 * Two deliberate departures from the WPBakery originals:
 *
 * - A migrated source attribute is unset rather than left beside its
 *   replacement. WPBakery keeps both because its filters run on the merged
 *   `shortcode_atts()` output, where the old key still has a registered
 *   default; here the result is the input to a converter, and a stale key
 *   would be reported as an unmapped setting.
 * - No rule renames a `style`. Every colour goes into the picker the
 *   migration puts it in, and a button keeps the style it was saved with —
 *   `flat`, `classic`, `modern`, `outline` and `3d` all declare those pickers
 *   themselves. The one style that does change is `gradient`, which the
 *   migration itself rewrites to `gradient-custom`.
 * - A colour value is only ever resolved through `Color`, whose tables are
 *   read from `vc_convert_vc_color()`, `VcSharedLibrary` and the migration
 *   itself: `Color::BUTTON_MIGRATION` and `Color::CTA_MIGRATION` are the rows
 *   `apply_btn_*_color()` and `apply_cta_*_color()` write, and
 *   `Color::PROGRESS_BAR_LEGACY` is `resolve_progress_bar_color()`'s classic
 *   `bar_*` map. There is not one hex literal in this file. A slug none of
 *   those tables knows is left exactly as it was and recorded in `notes`;
 *   colours are never invented.
 */
final class AttributeNormaliser {

    /**
     * The deprecated elements and what they become. Every one of them still
     * ships a config in js_composer 9.0.1's `config/deprecated/` (or, for the
     * two button-2 forms, in 7.8's) and still renders; none of them can be
     * created in the editor any more, and Divi has no counterpart for the old
     * shape, so each is folded onto its modern equivalent.
     *
     * `vc_cta_button` goes to `vc_cta` rather than to `vc_btn`: it is a box of
     * text with a button in it (`call_text` is the box), which is exactly what
     * `vc_cta` is, and the button half maps onto that element's integrated
     * `btn_` params.
     *
     * `vc_gmaps` is deliberately absent: it is deprecated in 9.0.1 but has no
     * replacement tag, so it keeps its own.
     */
    private const DEPRECATED_TAGS = [
        'vc_button'        => 'vc_btn',
        'vc_button2'       => 'vc_btn',
        'vc_cta_button'    => 'vc_cta',
        'vc_cta_button2'   => 'vc_cta',
        'vc_tabs'          => 'vc_tta_tabs',
        'vc_tour'          => 'vc_tta_tour',
        'vc_tab'           => 'vc_tta_section',
        'vc_accordion'     => 'vc_tta_accordion',
        'vc_accordion_tab' => 'vc_tta_section',
    ];

    /** `$btn1_sizes` and their button-3 replacements, from convertAttributesToButton3(). */
    private const BUTTON_1_SIZES = [
        'wpb_regularsize' => 'md',
        'btn-large'       => 'lg',
        'btn-small'       => 'sm',
        'btn-mini'        => 'xs',
    ];

    /**
     * `$haystack` and the style/shape pair each old style becomes, from
     * convertAttributesToButton3(). An empty shape means the style carries none.
     * The same five slugs are `VcSharedLibrary::$cta_styles`, which is what
     * `vc_cta_button2`'s `style` holds.
     */
    private const BUTTON_1_STYLES = [
        'rounded'         => [ 'flat', 'rounded' ],
        'square'          => [ 'flat', 'square' ],
        'round'           => [ 'flat', 'round' ],
        'outlined'        => [ 'outline', '' ],
        'square_outlined' => [ 'outline', 'square' ],
    ];

    /**
     * `VcSharedLibrary::get_color_arr()`
     * (include/classes/core/class-vc-shared-library.php:828, js_composer
     * 9.0.1; `vc_colors_arr()` before 8.6) — the seven Bootstrap-2 button
     * classes the button-1 era stored in `color` — and the slug each one is
     * in every colour table since. `$btn_solid_colors`' classic rows are keyed
     * by the same Bootstrap names, and `wpb_button` is the plain grey button,
     * which that table calls `default`.
     */
    private const BUTTON_1_CLASSES = [
        'wpb_button'   => 'default',
        'btn-primary'  => 'primary',
        'btn-info'     => 'info',
        'btn-success'  => 'success',
        'btn-warning'  => 'warning',
        'btn-danger'   => 'danger',
        'btn-inverse'  => 'inverse',
    ];

    /** The four grid elements the 9.0 migration hooks the grid rules onto. */
    private const GRID_TAGS = [ 'vc_basic_grid', 'vc_masonry_grid', 'vc_media_grid', 'vc_masonry_media_grid' ];

    /** `is_cta_legacy_position()`: the four values the old `add_button` dropdown held. */
    private const CTA_POSITIONS = [ 'top', 'bottom', 'left', 'right' ];

    /** The tta containers that carried the `no_fill` / `no_fill_content_area` checkbox. */
    private const TTA_TAGS = [ 'vc_tta_tabs', 'vc_tta_tour', 'vc_tta_accordion', 'vc_tta_pageable' ];

    /**
     * Normalises one element's tag and attributes.
     *
     * `content` is null for every rule but one: `vc_cta_button` keeps its text
     * in an attribute (`call_text`) where `vc_cta` keeps it in the element's
     * content, so that rule hands the caller a content string to write. A
     * caller that only wants attributes can ignore it.
     *
     * @param string               $tag  Shortcode tag as it appears in the content.
     * @param array<string,string> $atts Attributes as `ShortcodeParser` read them.
     * @return array{tag: string, atts: array<string,string>, content: ?string, notes: string[]}
     */
    public static function normalise( string $tag, array $atts ): array {
        $notes   = [];
        $content = null;

        if ( isset( self::DEPRECATED_TAGS[ $tag ] ) ) {
            $new_tag = self::DEPRECATED_TAGS[ $tag ];
            $notes[] = $tag . ' → ' . $new_tag;

            if ( $new_tag === 'vc_btn' ) {
                $atts = self::convertAttributesToButton3( $atts );
            } elseif ( $tag === 'vc_cta_button' ) {
                [ $atts, $content ] = self::convertCtaButtonToCta( $atts );
            } elseif ( $tag === 'vc_cta_button2' ) {
                $atts = self::convertCtaButton2ToCta( $atts );
            } elseif ( isset( $atts['interval'] ) ) {
                $notes[] = $new_tag . ': interval has no 9.0.1 equivalent';
            }

            $tag = $new_tag;
        }

        $atts = self::migrate( $tag, $atts, $notes );

        return [
            'tag'     => $tag,
            'atts'    => $atts,
            'content' => $content,
            'notes'   => $notes,
        ];
    }

    /**
     * The 9.0 `shortcode_atts_<tag>` filters, dispatched by tag.
     *
     * The order inside each branch is `Wpb_Template_Attributes_Migration::init()`'s
     * filter registration order, so a rule that reads a value another rule
     * rewrites sees the same value WPBakery's would.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrate( string $tag, array $atts, array &$notes ): array {
        if ( in_array( $tag, self::GRID_TAGS, true ) ) {
            $atts = self::convert_grid_element_width_to_items_per_row( $tag, $atts, $notes );
            $atts = self::normalize_grid_gap_to_px( $tag, $atts, $notes );
            $atts = self::migrate_inline_dropdown_color_to_custom( $tag, $atts, 'filter_color', $notes );
            $atts = self::migrate_inline_dropdown_color_to_custom( $tag, $atts, 'arrows_color', $notes );
            $atts = self::migrate_inline_dropdown_color_to_custom( $tag, $atts, 'paging_color', $notes );

            return self::convert_integrated_btn( $tag, $atts, 'btn_', $notes );
        }

        if ( in_array( $tag, self::TTA_TAGS, true ) ) {
            if ( $tag !== 'vc_tta_accordion' ) {
                $atts = self::convert_tta_tabs_pagination_color_to_custom( $tag, $atts, $notes );
            }

            $atts = self::keep_tta_color_slug( $tag, $atts, $notes );

            $atts = self::convert_tta_no_fill_to_fill_content_area( $tag, $atts, $notes );

            if ( $tag === 'vc_tta_pageable' ) {
                $atts = self::update_tta_pageable_autoplay_default( $tag, $atts, $notes );
            }

            return $atts;
        }

        switch ( $tag ) {
            case 'vc_btn':
                $atts = self::convert_btn_default_outline_dropdown_color_to_custom( $tag, $atts, '', $notes );
                $atts = self::convert_btn_default_3d_dropdown_color_to_custom( $tag, $atts, '', $notes );
                $atts = self::convert_btn_dropdown_color_to_custom( $tag, $atts, '', $notes );
                $atts = self::convert_btn_outline_dropdown_color_to_custom( $tag, $atts, '', $notes );
                $atts = self::convert_btn_3d_dropdown_color_to_custom( $tag, $atts, '', $notes );

                return self::convert_btn_gradient_style_to_gradient_custom( $tag, $atts, '', $notes );

            case 'vc_cta':
                // The icon colour filters register at init():35-36, before the
                // call-to-action ones at :108, so they run first here too.
                $atts = self::convert_icon_dropdown_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_icon_background_dropdown_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_classic_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_flat_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_3d_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_outline_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_el_width_dropdown_to_range( $tag, $atts, $notes );
                $atts = self::convert_cta_add_button_dropdown_to_toggle( $tag, $atts, $notes );
                $atts = self::convert_cta_add_icon_dropdown_to_toggle( $tag, $atts, $notes );

                return self::convert_integrated_btn( $tag, $atts, 'btn_', $notes );

            case 'vc_icon':
                $atts = self::convert_dropdown_color_to_custom( $tag, $atts, $notes );

                return self::convert_background_dropdown_color_to_custom( $tag, $atts, $notes );

            case 'vc_zigzag':
                return self::convert_dropdown_color_to_custom( $tag, $atts, $notes );

            case 'vc_separator':
                return self::convert_separator_dropdown_color_to_custom( $tag, $atts, $notes );

            case 'vc_text_separator':
                $atts = self::convert_separator_dropdown_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_icon_dropdown_color_to_custom( $tag, $atts, $notes );

                return self::convert_icon_background_dropdown_color_to_custom( $tag, $atts, $notes );

            case 'vc_pie':
                return self::convert_pie_chart_color_to_custom( $tag, $atts, $notes );

            case 'vc_progress_bar':
                $atts = self::convert_progress_bar_bgcolor_to_custom( $tag, $atts, $notes );
                $atts = self::convert_progress_bar_values_color_to_custom( $tag, $atts, $notes );

                return self::convert_progress_bar_options_checkbox_to_toggles( $tag, $atts, $notes );

            case 'vc_round_chart':
                $atts = self::convert_round_chart_stroke_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_round_chart_legend_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_chart_values_color_to_custom( $tag, $atts, $notes );

                return self::migrate_chart_style_custom_to_flat( $tag, $atts, $notes );

            case 'vc_line_chart':
                $atts = self::convert_chart_values_color_to_custom( $tag, $atts, $notes );

                return self::migrate_chart_style_custom_to_flat( $tag, $atts, $notes );

            case 'vc_hoverbox':
                $atts = self::convert_hoverbox_background_color_to_custom( $tag, $atts, $notes );

                return self::convert_integrated_btn( $tag, $atts, 'hover_btn_', $notes );

            case 'vc_pricing_table':
                return self::convert_integrated_btn( $tag, $atts, 'btn_', $notes );

            case 'vc_section':
                return self::convert_section_content_placement_to_vertical_content_position( $tag, $atts, $notes );

            case 'vc_wp_archives':
                return self::migrate_wp_archives_options_to_type_and_count( $tag, $atts, $notes );

            case 'vc_wp_rss':
                return self::migrate_wp_rss_options_to_toggles( $tag, $atts, $notes );

            case 'vc_gitem_image':
                return self::convert_border_dropdown_color_to_custom( $tag, $atts, $notes );

            case 'vc_gitem_post_categories':
                return self::convert_category_dropdown_color_to_custom( $tag, $atts, $notes );

            case 'vc_posts_slider':
                return self::convert_posts_slider_count_textfield_to_number( $tag, $atts, $notes );

            case 'vc_images_carousel':
                return self::convert_images_carousel_speed_to_numeric( $tag, $atts, $notes );
        }

        return $atts;
    }

    // --- deprecated elements --------------------------------------------------

    /**
     * `WPBakeryShortCode_Vc_Btn::convertAttributesToButton3()`
     * (include/classes/shortcodes/vc-btn.php), unchanged but for the icon
     * attribute names: the original writes button-2's `icon_type`,
     * `icon_align` and `icon_pixelicons`, which 9.0.1's `vc_btn` no longer
     * registers — it integrates `vc_icon`'s params under an `i_` prefix
     * (config/content/vc-btn-element.php), so the icon lands on `i_type`,
     * `i_align` and `i_icon_pixelicons` instead.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private static function convertAttributesToButton3( array $atts ): array {
        $atts = self::foldButtonClass( $atts, 'color' );

        if ( isset( $atts['size'], self::BUTTON_1_SIZES[ $atts['size'] ] ) ) {
            $atts['size'] = self::BUTTON_1_SIZES[ $atts['size'] ];
        }

        if ( ! isset( $atts['link'] ) && isset( $atts['href'] ) && $atts['href'] !== '' ) {
            $atts['link'] = self::packLink( $atts['href'], $atts['title'] ?? $atts['href'], $atts['target'] ?? '' );
        }
        unset( $atts['href'], $atts['target'] );

        if ( ( ! isset( $atts['add_icon'] ) || $atts['add_icon'] !== 'true' ) && isset( $atts['icon'] ) ) {
            $atts = self::pixelIcon( $atts, $atts['icon'], '' );
        }
        unset( $atts['icon'] );

        if ( isset( $atts['style'], self::BUTTON_1_STYLES[ $atts['style'] ] ) ) {
            [ $style, $shape ] = self::BUTTON_1_STYLES[ $atts['style'] ];
            $atts['style']     = $style;
            if ( $shape !== '' ) {
                $atts['shape'] = $shape;
            }
        }

        return $atts;
    }

    /**
     * `vc_cta_button` onto `vc_cta`.
     *
     * The 4.5-deprecated element (config/deprecated/shortcode-vc-cta-button.php)
     * is a block of text with a button beside it: `call_text` is the text —
     * which `vc_cta` keeps as the element's content, so it is handed back to
     * the caller rather than left in an attribute — and `title`, `href`,
     * `target`, `color`, `size`, `icon` and `position` are the button.
     *
     * 9.0.1's `vc_cta` carries a whole `vc_btn` under a `btn_` prefix
     * (`vc_map_integrate_shortcode( 'vc_btn', 'btn_', … )`,
     * config/buttons/shortcode-vc-cta.php — there is no
     * config/content/shortcode-vc-cta.php in 9.0.1), so every button
     * attribute moves under that prefix: `btn_title`, `btn_link`, `btn_color`,
     * `btn_size`, `btn_style`, `btn_add_icon`, `btn_i_type`,
     * `btn_i_icon_pixelicons`, `btn_i_align`.
     *
     * `position` holds `cta_align_right|cta_align_left|cta_align_bottom`; the
     * bare direction goes into `add_button`, the legacy dropdown that
     * `convert_cta_add_button_dropdown_to_toggle()` then splits into the
     * toggle and `btn_position`. An element saved without one still had a
     * button, on the right (the dropdown's first value), so that is the
     * default.
     *
     * `color` holds one of `VcSharedLibrary::get_color_arr()`'s Bootstrap-2
     * button classes rather than a palette slug, so it goes through
     * `BUTTON_1_CLASSES` first and reaches `btn_color` as the slug every
     * colour table is keyed by.
     *
     * @param array<string,string> $atts
     * @return array{0: array<string,string>, 1: ?string} Attributes, and the content to write.
     */
    private static function convertCtaButtonToCta( array $atts ): array {
        $content = null;
        $atts    = self::foldButtonClass( $atts, 'color' );

        if ( isset( $atts['call_text'] ) ) {
            $content = $atts['call_text'];
            unset( $atts['call_text'] );
        }

        if ( isset( $atts['size'], self::BUTTON_1_SIZES[ $atts['size'] ] ) ) {
            $atts['size'] = self::BUTTON_1_SIZES[ $atts['size'] ];
        }

        if ( isset( $atts['href'] ) && $atts['href'] !== '' ) {
            $atts['link'] = self::packLink( $atts['href'], $atts['title'] ?? $atts['href'], $atts['target'] ?? '' );
        }
        unset( $atts['href'], $atts['target'] );

        if ( isset( $atts['icon'] ) ) {
            $atts = self::pixelIcon( $atts, $atts['icon'], 'btn_' );
        }
        unset( $atts['icon'] );

        if ( isset( $atts['style'], self::BUTTON_1_STYLES[ $atts['style'] ] ) ) {
            [ $style, $shape ] = self::BUTTON_1_STYLES[ $atts['style'] ];
            $atts['btn_style'] = $style;
            if ( $shape !== '' ) {
                $atts['btn_shape'] = $shape;
            }
        }
        unset( $atts['style'] );

        $position = str_replace( 'cta_align_', '', $atts['position'] ?? '' );
        unset( $atts['position'] );

        $atts['add_button'] = in_array( $position, self::CTA_POSITIONS, true ) ? $position : 'right';

        return [ self::prefixButtonAtts( $atts ), $content ];
    }

    /**
     * `vc_cta_button2` onto `vc_cta`.
     *
     * The old element was a call-to-action box with a button welded into it:
     * `h2`, `h4` and `content` are the box (all three survive verbatim in
     * 9.0.1's `vc_cta`, config/buttons/shortcode-vc-cta.php), while `title`,
     * `link`, `size`, `color` and `position` describe the button. 9.0.1 keeps
     * the button as `vc_btn`'s params integrated under the `btn_` prefix
     * (`vc_map_integrate_shortcode( 'vc_btn', 'btn_', … )` in that config), so
     * every button attribute is moved under that prefix and `add_button` — a
     * toggle since 9.0 — is switched on. `style` holds one of
     * `VcSharedLibrary::$cta_styles`, the same five slugs button 1 used, so it
     * goes through the same style/shape split.
     *
     * `btn_style` holds one of `VcSharedLibrary::$button_styles`
     * (`rounded|square|round|outlined|3d|square_outlined`,
     * include/classes/core/class-vc-shared-library.php:54 of js_composer 7.8)
     * — the button-1 styles, not button 3's — so it takes the same split, into
     * `btn_style` + `btn_shape`, before the button colour rules read it.
     * `3d` is the one value the split table has no row for, and needs none:
     * button 3 has a `3d` style of its own.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private static function convertCtaButton2ToCta( array $atts ): array {
        $atts = self::foldButtonClass( $atts, 'color' );

        if ( isset( $atts['size'], self::BUTTON_1_SIZES[ $atts['size'] ] ) ) {
            $atts['size'] = self::BUTTON_1_SIZES[ $atts['size'] ];
        }

        if ( isset( $atts['btn_style'], self::BUTTON_1_STYLES[ $atts['btn_style'] ] ) ) {
            [ $btn_style, $btn_shape ] = self::BUTTON_1_STYLES[ $atts['btn_style'] ];
            $atts['btn_style']         = $btn_style;
            if ( $btn_shape !== '' ) {
                $atts['btn_shape'] = $btn_shape;
            }
        }

        $atts = self::prefixButtonAtts( $atts );

        if ( isset( $atts['position'] ) ) {
            $atts['btn_position'] = $atts['position'];
            unset( $atts['position'] );
        }

        if ( isset( $atts['btn_title'] ) || isset( $atts['btn_link'] ) ) {
            $atts['add_button'] = 'true';
        }

        if ( isset( $atts['style'], self::BUTTON_1_STYLES[ $atts['style'] ] ) ) {
            [ $style, $shape ] = self::BUTTON_1_STYLES[ $atts['style'] ];
            $atts['style']     = $style;
            if ( $shape !== '' ) {
                $atts['shape'] = $shape;
            }
        }

        return $atts;
    }

    /**
     * A button-1 `color` — one of the seven Bootstrap-2 classes
     * `VcSharedLibrary::get_color_arr()` offered — rewritten as the slug the
     * colour tables use. A value that is not one of those classes (the palette
     * slugs `vc_button2` and `vc_cta_button2` store, or a theme's own) is left
     * exactly as it is.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private static function foldButtonClass( array $atts, string $key ): array {
        if ( isset( $atts[ $key ], self::BUTTON_1_CLASSES[ $atts[ $key ] ] ) ) {
            $atts[ $key ] = self::BUTTON_1_CLASSES[ $atts[ $key ] ];
        }

        return $atts;
    }

    /**
     * `convertAttributesToButton3()`'s `link` string:
     * `url:<rawurlencoded>|title:<title>|target:<rawurlencoded>`, the title
     * left unencoded exactly as the original writes it, and the target only
     * there when the element had one.
     */
    private static function packLink( string $href, string $title, string $target ): string {
        return 'url:' . rawurlencode( $href ) . '|title:' . $title
            . ( $target !== '' ? '|target:' . rawurlencode( $target ) : '' );
    }

    /**
     * `convertAttributesToButton3()`'s icon branch: a button-1 `icon`
     * (`vc_icons_arr()`'s `wpb_*` slugs, config/lean-map.php) is a pixel icon,
     * which 9.0.1 addresses through `vc_icon`'s params integrated under an
     * `i_` prefix — `i_type`, `i_icon_pixelicons`, `i_align` — themselves
     * prefixed again when the button is integrated into another element.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private static function pixelIcon( array $atts, string $icon, string $prefix ): array {
        if ( $icon === '' || $icon === 'none' ) {
            return $atts;
        }

        $atts[ $prefix . 'add_icon' ]          = 'true';
        $atts[ $prefix . 'i_type' ]            = 'pixelicons';
        $atts[ $prefix . 'i_align' ]           = 'right';
        $atts[ $prefix . 'i_icon_pixelicons' ] = 'vc_pixel_icon vc_pixel_icon-' . str_replace( 'wpb_', '', $icon );

        return $atts;
    }

    /**
     * Moves the button half of a deprecated call-to-action element under the
     * `btn_` prefix `vc_cta` integrates `vc_btn` with, leaving everything else
     * (the box: `h2`, `h4`, `el_width`, `accent_color`, `el_class`, …) alone.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private static function prefixButtonAtts( array $atts ): array {
        foreach ( [ 'title', 'link', 'size', 'color', 'align' ] as $key ) {
            if ( ! isset( $atts[ $key ] ) ) {
                continue;
            }
            if ( ! isset( $atts[ 'btn_' . $key ] ) ) {
                $atts[ 'btn_' . $key ] = $atts[ $key ];
            }
            unset( $atts[ $key ] );
        }

        return $atts;
    }

    // --- 9.0: buttons -----------------------------------------------------------

    /**
     * `convert_btn_dropdown_color_to_custom()` + `apply_btn_solid_color()`.
     *
     * The source writes the whole row — background, label and the hover pair,
     * plus a border and hover border when the style is `modern` — into the
     * pickers each of those three styles declares for itself
     * (config/content/vc-btn-element.php:165, :180, :191, :206, :217, :228),
     * and leaves `style` alone. So does this: `custom` is a fourth state of
     * the same dropdown, and renaming into it would swap one look for another.
     *
     * Like the source, this overwrites: a `color` slug beside a hand-picked
     * `custom_*` value is a pre-9.0 button that has been edited since, and
     * WPBakery resolves that in favour of the slug.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_btn_dropdown_color_to_custom( string $tag, array $atts, string $prefix, array &$notes ): array {
        $style = $atts[ $prefix . 'style' ] ?? '';

        if ( ! in_array( $style, [ 'modern', 'classic', 'flat' ], true ) ) {
            return $atts;
        }

        $colour = self::buttonColour( $tag, $atts, $prefix, $notes );
        if ( $colour === null ) {
            return $atts;
        }

        $written = [ 'custom_background' => $colour['bg'], 'custom_text' => $colour['text'],
            'custom_hover_background' => $colour['hover_bg'], 'custom_hover_text' => $colour['hover_text'] ];

        if ( $style === 'modern' ) {
            $written['custom_border']       = $colour['bg'];
            $written['custom_hover_border'] = $colour['hover_bg'];
        }

        foreach ( $written as $key => $value ) {
            $atts[ $prefix . $key ] = $value;
        }

        unset( $atts[ $prefix . 'color' ] );

        $notes[] = $tag . ': ' . $prefix . 'color → ' . self::keyList( $prefix, array_keys( $written ) );

        return $atts;
    }

    /**
     * `convert_btn_outline_dropdown_color_to_custom()` + `apply_btn_outline_color()`
     * (abstract-class-wpb-attributes-migration.php:192), key for key.
     *
     * `VcSharedLibrary::$btn_outline_colors` gives text, border and hover
     * text; the source writes those into the five generic pickers the
     * `outline` style exposes — `custom_text`, `custom_border`,
     * `custom_hover_background` and `custom_hover_border` all taking the
     * accent colour, `custom_hover_text` the row's label
     * (config/content/vc-btn-element.php:191, :217, :180, :228, :206) — and
     * leaves `style` alone.
     *
     * `outline-custom` is not that: it is a separate state of the same
     * dropdown with three pickers of its own (:240, :255, :270) and its own
     * defaults, which the migration never writes. Nothing is renamed here
     * either.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_btn_outline_dropdown_color_to_custom( string $tag, array $atts, string $prefix, array &$notes ): array {
        if ( ( $atts[ $prefix . 'style' ] ?? '' ) !== 'outline' ) {
            return $atts;
        }

        $colour = self::buttonColour( $tag, $atts, $prefix, $notes );
        if ( $colour === null ) {
            return $atts;
        }

        $written = [
            'custom_text'             => $colour['bg'],
            'custom_border'           => $colour['bg'],
            'custom_hover_background' => $colour['bg'],
            'custom_hover_text'       => $colour['text'],
            'custom_hover_border'     => $colour['bg'],
        ];

        foreach ( $written as $key => $value ) {
            $atts[ $prefix . $key ] = $value;
        }

        unset( $atts[ $prefix . 'color' ] );

        $notes[] = $tag . ': ' . $prefix . 'color → ' . self::keyList( $prefix, array_keys( $written ) );

        return $atts;
    }

    /**
     * `convert_btn_3d_dropdown_color_to_custom()` + `apply_btn_3d_color()`,
     * which writes background, label and the `darken(@background, 11%)` box
     * shadow `$btn_3d_colors` carries. The 3d style keeps its name: 9.0.1
     * declares `custom_background`, `custom_text` and `custom_border` for it.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_btn_3d_dropdown_color_to_custom( string $tag, array $atts, string $prefix, array &$notes ): array {
        if ( ( $atts[ $prefix . 'style' ] ?? '' ) !== '3d' ) {
            return $atts;
        }

        $colour = self::buttonColour( $tag, $atts, $prefix, $notes );
        if ( $colour === null ) {
            return $atts;
        }

        $written = [
            'custom_background' => $colour['bg'],
            'custom_text'       => $colour['text'],
            'custom_border'     => $colour['shadow'],
        ];

        foreach ( $written as $key => $value ) {
            $atts[ $prefix . $key ] = $value;
        }

        unset( $atts[ $prefix . 'color' ] );

        $notes[] = $tag . ': ' . $prefix . 'color → ' . self::keyList( $prefix, array_keys( $written ) );

        return $atts;
    }

    /**
     * `convert_btn_default_outline_dropdown_color_to_custom()`: an outline
     * button saved with no colour at all rendered grey, and 9.0 back-fills
     * that before the colour rules run — unless the button already carries a
     * hand-picked `custom_*` value, which `is_custom_button_atts()` checks.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_btn_default_outline_dropdown_color_to_custom( string $tag, array $atts, string $prefix, array &$notes ): array {
        return self::defaultButtonColour( $tag, $atts, $prefix, 'outline', $notes );
    }

    /**
     * `convert_btn_default_3d_dropdown_color_to_custom()`, the same back-fill
     * for the 3d style.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_btn_default_3d_dropdown_color_to_custom( string $tag, array $atts, string $prefix, array &$notes ): array {
        return self::defaultButtonColour( $tag, $atts, $prefix, '3d', $notes );
    }

    /**
     * The grey back-fill both default rules share, and
     * `is_custom_button_atts()`: the six pickers whose presence means the
     * author has chosen the colours themselves.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function defaultButtonColour( string $tag, array $atts, string $prefix, string $style, array &$notes ): array {
        if ( ( $atts[ $prefix . 'style' ] ?? '' ) !== $style || ! empty( $atts[ $prefix . 'color' ] ) ) {
            return $atts;
        }

        foreach ( [ 'custom_background', 'custom_text', 'custom_border', 'custom_hover_background', 'custom_hover_text', 'custom_hover_border' ] as $picked ) {
            if ( array_key_exists( $prefix . $picked, $atts ) ) {
                return $atts;
            }
        }

        $atts[ $prefix . 'color' ] = 'grey';
        $notes[]                   = $tag . ': ' . $prefix . 'style="' . $style . '" with no colour defaults to grey';

        return $atts;
    }

    /**
     * `convert_btn_gradient_style_to_gradient_custom()`.
     *
     * Same defaults as the original: turquoise and blue when the button
     * carried no gradient colours, and `Color::BUTTON_GRADIENT_TEXT` for the
     * label the original writes as `#fff`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_btn_gradient_style_to_gradient_custom( string $tag, array $atts, string $prefix, array &$notes ): array {
        if ( ( $atts[ $prefix . 'style' ] ?? '' ) !== 'gradient' ) {
            return $atts;
        }

        $atts[ $prefix . 'style' ] = 'gradient-custom';

        foreach ( [ 1 => 'turquoise', 2 => 'blue' ] as $index => $default ) {
            $slug  = $atts[ $prefix . 'gradient_color_' . $index ] ?? $default;
            $value = self::colour( $slug );

            if ( $value === null ) {
                $notes[] = self::unresolved( $tag, $prefix . 'gradient_color_' . $index, $slug );
                continue;
            }

            $atts[ $prefix . 'gradient_custom_color_' . $index ] = $value;
            unset( $atts[ $prefix . 'gradient_color_' . $index ] );
        }

        $atts[ $prefix . 'gradient_text_color' ] = Color::BUTTON_GRADIENT_TEXT;
        $notes[]                                 = $tag . ': ' . $prefix . 'style="gradient" → gradient-custom';

        return $atts;
    }

    /**
     * `convert_integrated_btn()`: the same button rules against the prefix an
     * element that embeds a button uses — `btn_` for `vc_cta`, the grids and
     * `vc_pricing_table`, `hover_btn_` for `vc_hoverbox`.
     *
     * The two default back-fills run first here, as they do for a plain
     * `vc_btn` (`init():43-44`, before the colour filters at `:46-48`), rather
     * than last as `convert_integrated_btn()` itself calls them. The result is
     * the same: the source's default methods apply the grey row directly,
     * where this one writes `color = 'grey'` and lets the colour rule that
     * follows resolve it.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_integrated_btn( string $tag, array $atts, string $prefix, array &$notes ): array {
        $atts = self::convert_btn_default_outline_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );
        $atts = self::convert_btn_default_3d_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );
        $atts = self::convert_btn_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );
        $atts = self::convert_btn_outline_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );
        $atts = self::convert_btn_3d_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );

        return self::convert_btn_gradient_style_to_gradient_custom( $tag, $atts, $prefix, $notes );
    }

    /**
     * The `Color::BUTTON_MIGRATION` row for a button `color` slug — the 17
     * palette names and the 7 classic Bootstrap-2 ones, in either spelling —
     * or null when the button has no colour to migrate or the slug is not one
     * of them.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array{bg: string, text: string, hover_bg: string, hover_text: string, shadow: string}|null
     */
    private static function buttonColour( string $tag, array $atts, string $prefix, array &$notes ): ?array {
        $slug = $atts[ $prefix . 'color' ] ?? '';

        if ( $slug === '' || $slug === 'custom' ) {
            return null;
        }

        $row = Color::buttonMigration( $slug );

        if ( $row === null ) {
            $notes[] = self::unresolved( $tag, $prefix . 'color', $slug );
        }

        return $row;
    }

    /**
     * The attribute names a rule wrote, prefixed, for its note.
     *
     * @param string[] $keys
     */
    private static function keyList( string $prefix, array $keys ): string {
        return implode( ', ', array_map( static fn( string $key ): string => $prefix . $key, $keys ) );
    }

    // --- 9.0: call to action -------------------------------------------------------

    /**
     * `convert_cta_classic_color_to_custom()`. The classic box paints only its
     * text; `color="classic"` is the style's own default and migrates nothing.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_classic_color_to_custom( string $tag, array $atts, array &$notes ): array {
        if ( ( $atts['style'] ?? 'classic' ) !== 'classic' || ( $atts['color'] ?? '' ) === 'classic' ) {
            return $atts;
        }

        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'color', 'custom_text', $notes, true );
    }

    /**
     * `convert_cta_flat_color_to_custom()` + `apply_cta_flat_color()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_flat_color_to_custom( string $tag, array $atts, array &$notes ): array {
        if ( ( $atts['style'] ?? '' ) !== 'flat' ) {
            return $atts;
        }

        return self::applyCtaColour( $tag, $atts, false, $notes );
    }

    /**
     * `convert_cta_3d_color_to_custom()` + `apply_cta_3d_color()`, which is
     * the flat rule plus the box shadow `$cta_colors` carries as its fourth
     * value, written to `custom_border` as the original writes it.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_3d_color_to_custom( string $tag, array $atts, array &$notes ): array {
        if ( ( $atts['style'] ?? '' ) !== '3d' ) {
            return $atts;
        }

        return self::applyCtaColour( $tag, $atts, true, $notes );
    }

    /**
     * `convert_cta_outline_color_to_custom()` + `apply_cta_outline_color()`:
     * the outline box paints text and border with the same value.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_outline_color_to_custom( string $tag, array $atts, array &$notes ): array {
        if ( ( $atts['style'] ?? '' ) !== 'outline' || ( $atts['color'] ?? '' ) === '' ) {
            return $atts;
        }

        $row = Color::ctaMigration( $atts['color'] );

        if ( $row === null ) {
            $notes[] = self::unresolved( $tag, 'color', $atts['color'] );

            return $atts;
        }

        $atts = self::put( $atts, 'custom_text', $row['bg'] );
        $atts = self::put( $atts, 'custom_border', $row['bg'] );
        unset( $atts['color'] );

        $notes[] = $tag . ': color → custom_text, custom_border';

        return $atts;
    }

    /**
     * `apply_cta_flat_color()` and `apply_cta_3d_color()`, which differ only in
     * the box shadow the second one adds. Both run in the template context
     * (`$is_template = true`), where the row's body-text colour is written to
     * `text_color` as well — an attribute `WPBakeryShortCode_Vc_Cta` still
     * reads (include/classes/shortcodes/vc-cta.php) though 9.0.1 no longer
     * maps it — and both leave a hand-picked `custom_text` alone.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function applyCtaColour( string $tag, array $atts, bool $with_shadow, array &$notes ): array {
        $slug = $atts['color'] ?? '';

        if ( $slug === '' ) {
            return $atts;
        }

        $row = Color::ctaMigration( $slug );

        if ( $row === null ) {
            $notes[] = self::unresolved( $tag, 'color', $slug );

            return $atts;
        }

        $written = [ 'custom_background' ];
        $atts    = self::put( $atts, 'custom_background', $row['bg'] );

        if ( empty( $atts['custom_text'] ) ) {
            $atts['custom_text'] = $row['heading'];
            $atts['text_color']  = $row['text'];
            $written[]           = 'custom_text';
            $written[]           = 'text_color';
        }

        if ( $with_shadow ) {
            $atts      = self::put( $atts, 'custom_border', $row['shadow'] );
            $written[] = 'custom_border';
        }

        unset( $atts['color'] );

        $notes[] = $tag . ': color → ' . implode( ', ', $written );

        return $atts;
    }

    /**
     * `convert_cta_el_width_dropdown_to_range()`, slug for slug.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_el_width_dropdown_to_range( string $tag, array $atts, array &$notes ): array {
        $slug_to_percent = [
            ''   => '100',
            'xl' => '90',
            'lg' => '80',
            'md' => '70',
            'sm' => '60',
            'xs' => '50',
        ];

        if ( ! isset( $atts['el_width'] ) || ! array_key_exists( $atts['el_width'], $slug_to_percent ) ) {
            return $atts;
        }

        $atts['el_width'] = $slug_to_percent[ $atts['el_width'] ];
        $notes[]          = $tag . ': el_width → range';

        return $atts;
    }

    /**
     * `convert_cta_add_button_dropdown_to_toggle()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_add_button_dropdown_to_toggle( string $tag, array $atts, array &$notes ): array {
        return self::ctaPositionToggle( $tag, $atts, 'add_button', 'btn_position', $notes );
    }

    /**
     * `convert_cta_add_icon_dropdown_to_toggle()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_add_icon_dropdown_to_toggle( string $tag, array $atts, array &$notes ): array {
        return self::ctaPositionToggle( $tag, $atts, 'add_icon', 'i_position', $notes );
    }

    /**
     * `is_cta_legacy_position()` and the toggle split both dropdowns share.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function ctaPositionToggle( string $tag, array $atts, string $toggle, string $position, array &$notes ): array {
        if ( ! isset( $atts[ $toggle ] ) || ! in_array( $atts[ $toggle ], self::CTA_POSITIONS, true ) ) {
            return $atts;
        }

        $atts[ $position ] = $atts[ $toggle ];
        $atts[ $toggle ]   = 'true';
        $notes[]           = $tag . ': ' . $toggle . ' → ' . $toggle . ', ' . $position;

        return $atts;
    }

    // --- 9.0: single-colour elements -------------------------------------------------

    /**
     * `convert_dropdown_color_to_custom()` (vc_icon, vc_zigzag).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_dropdown_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'color', 'custom_color', $notes );
    }

    /**
     * `convert_background_dropdown_color_to_custom()` (vc_icon).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_background_dropdown_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'background_color', 'custom_background_color', $notes );
    }

    /**
     * `convert_separator_dropdown_color_to_custom()` (vc_separator, vc_text_separator).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_separator_dropdown_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'color', 'accent_color', $notes );
    }

    /**
     * `convert_icon_dropdown_color_to_custom()` (vc_text_separator, vc_cta).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_icon_dropdown_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'i_color', 'i_custom_color', $notes );
    }

    /**
     * `convert_icon_background_dropdown_color_to_custom()` (vc_text_separator, vc_cta).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_icon_background_dropdown_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'i_background_color', 'i_custom_background_color', $notes );
    }

    /**
     * `convert_pie_chart_color_to_custom()` (vc_pie).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_pie_chart_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'color', 'custom_color', $notes, true );
    }

    /**
     * `convert_hoverbox_background_color_to_custom()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_hoverbox_background_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'hover_background_color', 'hover_custom_background', $notes );
    }

    /**
     * `convert_border_dropdown_color_to_custom()` (vc_gitem_image).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_border_dropdown_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'border_color', 'custom_border_color', $notes );
    }

    /**
     * `convert_category_dropdown_color_to_custom()` (vc_gitem_post_categories).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_category_dropdown_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'category_color', 'custom_category_color', $notes );
    }

    /**
     * `convert_round_chart_stroke_color_to_custom()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_round_chart_stroke_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'stroke_color', 'custom_stroke_color', $notes, true );
    }

    /**
     * `convert_round_chart_legend_color_to_custom()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_round_chart_legend_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'legend_color', 'custom_legend_color', $notes, true );
    }

    // --- 9.0: checkboxes, dropdowns and renames ---------------------------------------

    /**
     * `migrate_progress_bar_options_to_toggles()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_progress_bar_options_checkbox_to_toggles( string $tag, array $atts, array &$notes ): array {
        if ( ! isset( $atts['options'] ) ) {
            return $atts;
        }

        $options = self::options( $atts['options'] );
        $written = [];

        foreach ( [ 'striped', 'animated' ] as $toggle ) {
            if ( empty( $atts[ $toggle ] ) && in_array( $toggle, $options, true ) ) {
                $atts[ $toggle ] = 'true';
                $written[]       = $toggle;
            }
        }

        unset( $atts['options'] );

        if ( $written !== [] ) {
            $notes[] = $tag . ': options → ' . implode( ', ', $written );
        }

        return $atts;
    }

    /**
     * `convert_progress_bar_bgcolor_to_custom()` + `apply_progress_bar_color()`
     * + `resolve_progress_bar_color()`.
     *
     * The dropdown offered the palette and seven classic `bar_*` colours; the
     * latter live in the migration itself, and `Color::PROGRESS_BAR_LEGACY`
     * carries them. A resolved bar takes the whole treatment the source
     * writes: the colour, the label colour `resolve_progress_bar_txt_color()`
     * picks for it, and the text shadow.
     *
     * Two departures, both noted: `bar_grey` and an unknown slug resolve to no
     * colour, and where the source writes an empty `custombgcolor` this drops
     * the attribute instead — an empty colour is not a colour, and a handler
     * would have to special-case it.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_progress_bar_bgcolor_to_custom( string $tag, array $atts, array &$notes ): array {
        $slug = $atts['bgcolor'] ?? '';

        if ( $slug === '' || $slug === 'custom' || ! empty( $atts['custombgcolor'] ) ) {
            return $atts;
        }

        $value = self::barColour( $slug );

        if ( $value === null ) {
            $notes[] = self::unresolved( $tag, 'bgcolor', $slug );

            return $atts;
        }

        unset( $atts['bgcolor'] );

        if ( $value === '' ) {
            $notes[] = $tag . ': bgcolor="' . $slug . '" is the default bar colour';

            return $atts;
        }

        $atts    = self::applyProgressBarColour( $atts, $slug, $value, 'custombgcolor', 'customtxtcolor' );
        $notes[] = $tag . ': bgcolor → custombgcolor, customtxtcolor, add_text_shadow, text_shadow_color';

        return $atts;
    }

    /**
     * `migrate_progress_bar_values_color()`: the per-bar `color` dropdown
     * inside the `values` param group, through the same
     * `apply_progress_bar_color()` — so a migrated bar carries its label
     * colour and text shadow inside the group, exactly as the source writes
     * them — and then unset, as the source unsets it, resolved or not.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_progress_bar_values_color_to_custom( string $tag, array $atts, array &$notes ): array {
        $items = self::paramGroupItems( $atts );

        if ( $items === null ) {
            return $atts;
        }

        $changed = false;

        foreach ( $items as $index => $bar ) {
            if ( ! is_array( $bar ) || ! isset( $bar['color'] ) ) {
                continue;
            }

            $slug = (string) $bar['color'];
            unset( $bar['color'] );
            $changed = true;

            if ( $slug !== '' && $slug !== 'custom' && empty( $bar['customcolor'] ) ) {
                $value = self::barColour( $slug );

                if ( $value === null ) {
                    $notes[] = self::unresolved( $tag, 'values color', $slug );
                } elseif ( $value === '' ) {
                    $notes[] = $tag . ': values color="' . $slug . '" is the default bar colour';
                } else {
                    $bar = self::applyProgressBarColour( $bar, $slug, $value, 'customcolor', 'customtxtcolor' );
                }
            }

            $items[ $index ] = $bar;
        }

        if ( ! $changed ) {
            return $atts;
        }

        $notes[] = $tag . ': values color → customcolor, customtxtcolor, add_text_shadow, text_shadow_color';

        return self::packParamGroup( $atts, $items );
    }

    /**
     * `apply_progress_bar_color()`'s write half, and
     * `resolve_progress_bar_txt_color()`: a coloured bar gets a light label,
     * the two light palette bars a dark one, and both get the text shadow.
     *
     * @param array<string,string> $data
     * @return array<string,string>
     */
    private static function applyProgressBarColour( array $data, string $slug, string $value, string $bg_key, string $txt_key ): array {
        $data[ $bg_key ]            = $value;
        $data[ $txt_key ]           = in_array( str_replace( '_', '-', $slug ), [ 'grey', 'white' ], true )
            ? Color::PROGRESS_BAR_TEXT_DARK
            : Color::PROGRESS_BAR_TEXT;
        $data['add_text_shadow']   = 'true';
        $data['text_shadow_color'] = Color::PROGRESS_BAR_TEXT_SHADOW;

        return $data;
    }

    /**
     * `migrate_chart_values_color()` / `migrate_chart_item_color()`, for
     * `vc_round_chart` and `vc_line_chart`.
     *
     * The rule reads the element's own `style` first: a chart already on the
     * `custom` style keeps whatever `custom_color` each item carries and
     * back-fills grey where there is none, discarding the item's dropdown
     * slug; every other chart resolves that slug through the
     * `dashed-colors-hash`. Either way the slug itself is unset, and an item
     * that says `custom` only loses the key.
     *
     * One departure: where `resolve_dropdown_color()` copies a slug the hash
     * does not know into `custom_color` verbatim, this reports it instead —
     * `custom_color` is a colorpicker, and a slug in it is not a colour.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_chart_values_color_to_custom( string $tag, array $atts, array &$notes ): array {
        $items = self::paramGroupItems( $atts );

        if ( $items === null ) {
            return $atts;
        }

        $style_is_custom = ( $atts['style'] ?? '' ) === 'custom';
        $changed         = false;

        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) || ! isset( $item['color'] ) ) {
                continue;
            }

            $slug = (string) $item['color'];

            if ( $style_is_custom ) {
                if ( empty( $item['custom_color'] ) ) {
                    $item['custom_color'] = (string) Color::fromPalette( 'grey' );
                }
            } elseif ( $slug !== 'custom' ) {
                $value = self::dashedColour( $slug );

                if ( $value === null ) {
                    $notes[] = self::unresolved( $tag, 'values color', $slug );
                } else {
                    $item['custom_color'] = $value;
                }
            }

            unset( $item['color'] );
            $items[ $index ] = $item;
            $changed         = true;
        }

        if ( ! $changed ) {
            return $atts;
        }

        $notes[] = $tag . ': values color → custom_color';

        return self::packParamGroup( $atts, $items );
    }

    /**
     * `migrate_line_chart_style_custom_to_flat()` /
     * `migrate_round_chart_style_custom_to_flat()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrate_chart_style_custom_to_flat( string $tag, array $atts, array &$notes ): array {
        if ( ( $atts['style'] ?? '' ) !== 'custom' ) {
            return $atts;
        }

        $atts['style'] = 'flat';
        $notes[]       = $tag . ': style="custom" → flat';

        return $atts;
    }

    /**
     * `migrate_wp_archives_options_to_type_and_count()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrate_wp_archives_options_to_type_and_count( string $tag, array $atts, array &$notes ): array {
        if ( ! isset( $atts['options'] ) ) {
            return $atts;
        }

        $options      = self::options( $atts['options'] );
        $atts['type'] = in_array( 'dropdown', $options, true ) ? 'dropdown' : 'list';
        $written      = [ 'type' ];

        if ( empty( $atts['count'] ) && in_array( 'count', $options, true ) ) {
            $atts['count'] = 'true';
            $written[]     = 'count';
        }

        unset( $atts['options'] );
        $notes[] = $tag . ': options → ' . implode( ', ', $written );

        return $atts;
    }

    /**
     * `migrate_wp_rss_options_to_toggles()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrate_wp_rss_options_to_toggles( string $tag, array $atts, array &$notes ): array {
        if ( ! isset( $atts['options'] ) ) {
            return $atts;
        }

        $options = self::options( $atts['options'] );
        $written = [];

        foreach ( [ 'show_summary' => 'item_content', 'show_author' => 'item_author', 'show_date' => 'item_date' ] as $option => $toggle ) {
            if ( empty( $atts[ $toggle ] ) && in_array( $option, $options, true ) ) {
                $atts[ $toggle ] = 'true';
                $written[]       = $toggle;
            }
        }

        unset( $atts['options'] );

        if ( $written !== [] ) {
            $notes[] = $tag . ': options → ' . implode( ', ', $written );
        }

        return $atts;
    }

    /**
     * The one tta rule this class does not port:
     * `convert_tta_tabs_color_to_custom()` resolves the `color` slug in place,
     * and here it is left as a slug, because 9.0.1 still accepts one there and
     * resolves it at render time — so the slug is the shape a handler is
     * written against, and the handler is where the tta palette becomes
     * Divi's. An element that carries one says so, since every other
     * departure in this class is in `notes` too.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function keep_tta_color_slug( string $tag, array $atts, array &$notes ): array {
        $slug = $atts['color'] ?? '';

        if ( $slug !== '' && Color::fromPalette( $slug ) !== null ) {
            $notes[] = $tag . ': color="' . $slug . '" kept as a slug (9.0.1 resolves it at render)';
        }

        return $atts;
    }

    /**
     * `convert_tta_tabs_pagination_color_to_custom()`
     * (`class-wpb-template-attributes-migration.php:78, :84, :97`): the
     * `pagination_color` dropdown became a colorpicker in 9.0, so a stored
     * slug is resolved in place. Registered for `vc_tta_tabs`, `vc_tta_tour`
     * and `vc_tta_pageable` only — `vc_tta_accordion` has no pagination.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_tta_tabs_pagination_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_inline_dropdown_color_to_custom( $tag, $atts, 'pagination_color', $notes );
    }

    /**
     * `convert_tta_no_fill_to_fill_content_area()`, without its third branch:
     * WPBakery backfills `fill_content_area = 'true'` for an element that
     * carried neither checkbox, because its filter runs after
     * `shortcode_atts()` has already applied a default. Nothing has applied a
     * default here, and 9.0.1 registers `'std' => 'true'` for the toggle
     * (config/tta/shortcode-vc-tta-tabs.php), so an absent attribute already
     * means "fill" and is left absent.
     *
     * The `color` attribute is deliberately not touched: 9.0.1 still accepts
     * the palette slug there and resolves it at render time through
     * `migrate_inline_dropdown_color_to_custom()`, so the slug is the shape a
     * handler is written against — as is `active_color`, which is a hex value
     * in both eras.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_tta_no_fill_to_fill_content_area( string $tag, array $atts, array &$notes ): array {
        if ( array_key_exists( 'fill_content_area', $atts ) ) {
            return $atts;
        }

        foreach ( [ 'no_fill_content_area', 'no_fill' ] as $legacy ) {
            if ( ! array_key_exists( $legacy, $atts ) ) {
                continue;
            }

            $atts['fill_content_area'] = $atts[ $legacy ] === 'true' ? '' : 'true';
            unset( $atts[ $legacy ] );
            $notes[] = $tag . ': ' . $legacy . ' → fill_content_area';

            return $atts;
        }

        return $atts;
    }

    /**
     * `update_tta_pageable_autoplay_default()`: 9.0 changed the `autoplay`
     * default from 0 to 7, so an element saved without one meant "no
     * autoplay" and has to say so.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function update_tta_pageable_autoplay_default( string $tag, array $atts, array &$notes ): array {
        if ( isset( $atts['autoplay'] ) ) {
            return $atts;
        }

        $atts['autoplay'] = '0';
        $notes[]          = $tag . ': autoplay defaulted to 0 (the pre-9.0 default)';

        return $atts;
    }

    /**
     * `migrate_grid_element_width_to_items_per_row()`.
     *
     * The original divides 12 by the stored Bootstrap column span. Content
     * from the era before the grids were rewritten stores that same width as
     * the fraction the editor showed (`1/4`), which reads as its own
     * denominator; both forms are accepted.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_grid_element_width_to_items_per_row( string $tag, array $atts, array &$notes ): array {
        $width = $atts['element_width'] ?? '';

        if ( $width === '' || isset( $atts['items_per_row'] ) ) {
            return $atts;
        }

        if ( preg_match( '#^1/([1-9][0-9]*)$#', $width, $m ) ) {
            $per_row = (int) $m[1];
        } elseif ( is_numeric( $width ) && (int) $width > 0 ) {
            $per_row = intdiv( 12, (int) $width );
        } else {
            $notes[] = $tag . ': element_width="' . $width . '" is not a column width';

            return $atts;
        }

        $atts['items_per_row'] = (string) $per_row;
        unset( $atts['element_width'] );
        $notes[] = $tag . ': element_width → items_per_row';

        return $atts;
    }

    /**
     * `normalize_grid_gap_to_px()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function normalize_grid_gap_to_px( string $tag, array $atts, array &$notes ): array {
        if ( ! isset( $atts['gap'] ) || $atts['gap'] === '' || ! is_numeric( $atts['gap'] ) ) {
            return $atts;
        }

        $atts['gap'] = $atts['gap'] . 'px';
        $notes[]     = $tag . ': gap → px';

        return $atts;
    }

    /**
     * `convert_section_content_placement_to_vertical_content_position()`
     * (`migrate_renamed_attribute()`).
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_section_content_placement_to_vertical_content_position( string $tag, array $atts, array &$notes ): array {
        if ( empty( $atts['content_placement'] ) ) {
            return $atts;
        }

        if ( empty( $atts['vertical_content_position'] ) ) {
            $atts['vertical_content_position'] = $atts['content_placement'];
            $notes[]                           = $tag . ': content_placement → vertical_content_position';
        }

        unset( $atts['content_placement'] );

        return $atts;
    }

    /**
     * `convert_posts_slider_count_textfield_to_number()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_posts_slider_count_textfield_to_number( string $tag, array $atts, array &$notes ): array {
        if ( ! isset( $atts['count'] ) ) {
            return $atts;
        }

        if ( is_numeric( $atts['count'] ) ) {
            // The source writes the empty toggle too, so a slider that counts
            // its posts says out loud that it does not show them all.
            $atts['display_all'] = '';

            return $atts;
        }

        $atts['display_all'] = 'yes';
        unset( $atts['count'] );
        $notes[] = $tag . ': count → display_all';

        return $atts;
    }

    /**
     * `convert_images_carousel_speed_to_numeric()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_images_carousel_speed_to_numeric( string $tag, array $atts, array &$notes ): array {
        if ( ! isset( $atts['speed'] ) || $atts['speed'] === '' || is_numeric( $atts['speed'] ) ) {
            return $atts;
        }

        $atts['speed'] = (string) (int) preg_replace( '/[^0-9]/', '', $atts['speed'] );
        $notes[]       = $tag . ': speed → number';

        return $atts;
    }

    // --- shared -----------------------------------------------------------------------

    /**
     * `migrate_dropdown_color_to_custom()`: resolve the slug in $source_key
     * and write it to $target_key, leaving an already-picked colour alone.
     * `$dashed` picks the wider `dashed-colors-hash` the source passes as
     * `$hash_lib` for a few of these rules, instead of the default
     * `colors-hash`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrate_dropdown_color_to_custom( string $tag, array $atts, string $source_key, string $target_key, array &$notes, bool $dashed = false ): array {
        $slug = $atts[ $source_key ] ?? '';

        if ( $slug === '' || $slug === 'custom' || ! empty( $atts[ $target_key ] ) ) {
            return $atts;
        }

        $value = $dashed ? self::dashedColour( $slug ) : self::colour( $slug );

        if ( $value === null ) {
            $notes[] = self::unresolved( $tag, $source_key, $slug );

            return $atts;
        }

        $atts[ $target_key ] = $value;
        unset( $atts[ $source_key ] );
        $notes[] = $tag . ': ' . $source_key . ' → ' . $target_key;

        return $atts;
    }

    /**
     * `migrate_inline_dropdown_color_to_custom()`: the same resolution in
     * place, for the pickers 9.0 kept under their old name.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrate_inline_dropdown_color_to_custom( string $tag, array $atts, string $key, array &$notes ): array {
        $slug = $atts[ $key ] ?? '';

        if ( $slug === '' || preg_match( '/^(#|rgb|hsl)/i', $slug ) ) {
            return $atts;
        }

        $value = self::colour( $slug );

        if ( $value === null ) {
            $notes[] = self::unresolved( $tag, $key, $slug );

            return $atts;
        }

        $atts[ $key ] = $value;
        $notes[]      = $tag . ': ' . $key . ' → colour';

        return $atts;
    }

    /**
     * The items of a `values` param group, or null when there is nothing to
     * read. `vc_param_group_parse_atts()` is `json_decode( urldecode( … ) )`.
     *
     * @param array<string,string> $atts
     * @return array<int, mixed>|null
     */
    private static function paramGroupItems( array $atts ): ?array {
        if ( empty( $atts['values'] ) ) {
            return null;
        }

        $items = json_decode( urldecode( $atts['values'] ), true );

        return is_array( $items ) ? $items : null;
    }

    /**
     * The group re-encoded the way the source does it,
     * `rawurlencode( wp_json_encode( … ) )`.
     *
     * @param array<string,string> $atts
     * @param array<int, mixed>    $items
     * @return array<string,string>
     */
    private static function packParamGroup( array $atts, array $items ): array {
        $atts['values'] = rawurlencode( (string) json_encode( $items ) );

        return $atts;
    }

    /**
     * A palette slug resolved to its hex, or a value already written as a CSS
     * colour passed through. Null means "not a colour this plugin knows",
     * which is always reported rather than guessed at.
     */
    private static function colour( string $value ): ?string {
        return Color::fromPalette( $value ) ?? Color::normalize( $value );
    }

    /**
     * The same against `vc_get_shared( 'dashed-colors-hash' )`, which a few of
     * the rules pass as their `$hash_lib`: the 17 palette hexes plus the 7
     * classic button colours. That is value for value the `bg` column of
     * `Color::BUTTON_MIGRATION` — the same 24 rows, read from the same file —
     * so no fourth table is kept for it.
     */
    private static function dashedColour( string $value ): ?string {
        $row = Color::buttonMigration( $value );

        return $row === null ? self::colour( $value ) : $row['bg'];
    }

    /**
     * The same, for a progress bar, whose dropdown also held the seven classic
     * `bar_*` slugs. `''` is a real answer there — `bar_grey` is the
     * uncoloured default — and is not the same as null, which still means
     * "not a colour this plugin knows".
     */
    private static function barColour( string $slug ): ?string {
        return Color::progressBarLegacy( $slug ) ?? self::colour( $slug );
    }

    /**
     * A checkbox param: the comma-separated list of ticked values WPBakery
     * stores, as `explode( ',', … )` + `array_filter( array_map( 'trim', … ) )`
     * in every `migrate_*_options_to_*()`.
     *
     * @return string[]
     */
    private static function options( string $raw ): array {
        return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    }

    /**
     * Writes $key only when it is not already carrying a value — the
     * `if ( ! isset( $atts[…] ) )` guard `apply_cta_*_color()`,
     * `apply_progress_bar_color()` and `should_migrate_dropdown_color()` use,
     * so a colour the author picked by hand is not overwritten by a migrated
     * one. The button rules deliberately do not use it: `apply_btn_*_color()`
     * assigns unconditionally, and this follows them.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private static function put( array $atts, string $key, string $value ): array {
        if ( empty( $atts[ $key ] ) ) {
            $atts[ $key ] = $value;
        }

        return $atts;
    }

    /** The note an unknown colour slug leaves behind. */
    private static function unresolved( string $tag, string $key, string $value ): string {
        return $tag . ': ' . $key . '="' . $value . '" is not a WPBakery palette name';
    }
}
