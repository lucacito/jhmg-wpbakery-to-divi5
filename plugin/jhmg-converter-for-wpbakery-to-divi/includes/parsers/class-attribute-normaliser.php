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
 * - A colour value is only ever resolved through `Color` (whose tables are
 *   read from `vc_convert_vc_color()` and `VcSharedLibrary`). A slug none of
 *   those tables knows is left exactly as it was and recorded in `notes`;
 *   colours are never invented. That also means the handful of hex values
 *   WPBakery's migration hard-codes and `Color` does not carry — the `#666`
 *   label colour of a grey or white solid button, the `darken(…, 11%)` box
 *   shadow of a 3d button, the per-bar text shadow of a progress bar — are
 *   not written here; the note says the colour was not carried.
 */
final class AttributeNormaliser {

    /**
     * The deprecated elements and what they become. Every one of them still
     * ships a config in js_composer 9.0.1's `config/deprecated/` (or, for the
     * two button-2 forms, in 7.8's) and still renders; none of them can be
     * created in the editor any more, and Divi has no counterpart for the old
     * shape, so each is folded onto its modern equivalent.
     *
     * `vc_gmaps` is deliberately absent: it is deprecated in 9.0.1 but has no
     * replacement tag, so it keeps its own.
     */
    private const DEPRECATED_TAGS = [
        'vc_button'        => 'vc_btn',
        'vc_button2'       => 'vc_btn',
        'vc_cta_button'    => 'vc_btn',
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

    /** The four grid elements the 9.0 migration hooks the grid rules onto. */
    private const GRID_TAGS = [ 'vc_basic_grid', 'vc_masonry_grid', 'vc_media_grid', 'vc_masonry_media_grid' ];

    /** The tta containers that carried the `no_fill` / `no_fill_content_area` checkbox. */
    private const TTA_TAGS = [ 'vc_tta_tabs', 'vc_tta_tour', 'vc_tta_accordion', 'vc_tta_pageable' ];

    /** The palette slugs whose solid button used WPBakery's dark label colour. */
    private const DARK_LABEL_PALETTE = [ 'grey', 'white' ];

    /**
     * Normalises one element's tag and attributes.
     *
     * @param string               $tag  Shortcode tag as it appears in the content.
     * @param array<string,string> $atts Attributes as `ShortcodeParser` read them.
     * @return array{tag: string, atts: array<string,string>, notes: string[]}
     */
    public static function normalise( string $tag, array $atts ): array {
        $notes = [];

        if ( isset( self::DEPRECATED_TAGS[ $tag ] ) ) {
            $new_tag = self::DEPRECATED_TAGS[ $tag ];
            $notes[] = $tag . ' → ' . $new_tag;

            if ( $new_tag === 'vc_btn' ) {
                $atts = self::convertAttributesToButton3( $atts );
            } elseif ( $tag === 'vc_cta_button2' ) {
                $atts = self::convertCtaButton2ToCta( $atts );
            } elseif ( isset( $atts['interval'] ) ) {
                $notes[] = $new_tag . ': interval has no 9.0.1 equivalent';
            }

            $tag = $new_tag;
        }

        $atts = self::migrate( $tag, $atts, $notes );

        return [
            'tag'   => $tag,
            'atts'  => $atts,
            'notes' => $notes,
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
            $atts = self::convert_tta_no_fill_to_fill_content_area( $tag, $atts, $notes );

            if ( $tag === 'vc_tta_pageable' ) {
                $atts = self::update_tta_pageable_autoplay_default( $tag, $atts, $notes );
            }

            return $atts;
        }

        switch ( $tag ) {
            case 'vc_btn':
                $atts = self::convert_btn_dropdown_color_to_custom( $tag, $atts, '', $notes );
                $atts = self::convert_btn_outline_dropdown_color_to_custom( $tag, $atts, '', $notes );
                $atts = self::convert_btn_3d_dropdown_color_to_custom( $tag, $atts, '', $notes );

                return self::convert_btn_gradient_style_to_gradient_custom( $tag, $atts, '', $notes );

            case 'vc_cta':
                $atts = self::convert_cta_classic_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_flat_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_3d_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_outline_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_cta_el_width_dropdown_to_range( $tag, $atts, $notes );
                $atts = self::convert_cta_add_button_dropdown_to_toggle( $tag, $atts, $notes );
                $atts = self::convert_cta_add_icon_dropdown_to_toggle( $tag, $atts, $notes );
                $atts = self::convert_icon_dropdown_color_to_custom( $tag, $atts, $notes );
                $atts = self::convert_icon_background_dropdown_color_to_custom( $tag, $atts, $notes );

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
        if ( isset( $atts['size'], self::BUTTON_1_SIZES[ $atts['size'] ] ) ) {
            $atts['size'] = self::BUTTON_1_SIZES[ $atts['size'] ];
        }

        if ( ! isset( $atts['link'] ) && isset( $atts['href'] ) && $atts['href'] !== '' ) {
            $link           = $atts['href'];
            $target         = $atts['target'] ?? '';
            $title          = $atts['title'] ?? $link;
            $atts['link']   = 'url:' . rawurlencode( $link ) . '|title:' . $title
                . ( $target !== '' ? '|target:' . rawurlencode( $target ) : '' );
        }
        unset( $atts['href'], $atts['target'] );

        if ( ( ! isset( $atts['add_icon'] ) || $atts['add_icon'] !== 'true' )
            && isset( $atts['icon'] ) && $atts['icon'] !== '' && $atts['icon'] !== 'none' ) {
            $atts['add_icon']          = 'true';
            $atts['i_type']            = 'pixelicons';
            $atts['i_align']           = 'right';
            $atts['i_icon_pixelicons'] = 'vc_pixel_icon vc_pixel_icon-' . str_replace( 'wpb_', '', $atts['icon'] );
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
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private static function convertCtaButton2ToCta( array $atts ): array {
        if ( isset( $atts['size'], self::BUTTON_1_SIZES[ $atts['size'] ] ) ) {
            $atts['size'] = self::BUTTON_1_SIZES[ $atts['size'] ];
        }

        foreach ( [ 'title' => 'btn_title', 'link' => 'btn_link', 'size' => 'btn_size', 'color' => 'btn_color', 'position' => 'btn_position' ] as $old => $new ) {
            if ( isset( $atts[ $old ] ) && ! isset( $atts[ $new ] ) ) {
                $atts[ $new ] = $atts[ $old ];
            }
            unset( $atts[ $old ] );
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

    // --- 9.0: buttons -----------------------------------------------------------

    /**
     * `convert_btn_dropdown_color_to_custom()` + `apply_btn_solid_color()`.
     *
     * WPBakery writes the four (six for `modern`) hover and border values of
     * `VcSharedLibrary::$btn_solid_colors` and leaves `style` alone, because
     * its own CSS still reads the style class. Divi has no such class, so the
     * button becomes the `custom` style — the one 9.0.1 offers for a button
     * whose colours are picked rather than named
     * (config/content/vc-btn-element.php) — with the background and label it
     * had. The hover pair is a `darken()` of the background that no table in
     * `Color` carries; it is not written and the note says so.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_btn_dropdown_color_to_custom( string $tag, array $atts, string $prefix, array &$notes ): array {
        if ( ! in_array( $atts[ $prefix . 'style' ] ?? '', [ 'modern', 'classic', 'flat' ], true ) ) {
            return $atts;
        }

        $colour = self::buttonColour( $tag, $atts, $prefix, $notes );
        if ( $colour === null ) {
            return $atts;
        }

        [ $background, $label ] = $colour;

        $atts = self::put( $atts, $prefix . 'custom_background', $background );
        $written = [ $prefix . 'custom_background' ];

        if ( $label !== null ) {
            $atts      = self::put( $atts, $prefix . 'custom_text', $label );
            $written[] = $prefix . 'custom_text';
        }

        $atts[ $prefix . 'style' ] = 'custom';
        unset( $atts[ $prefix . 'color' ] );

        $notes[] = $tag . ': ' . $prefix . 'color → ' . implode( ', ', $written );

        return $atts;
    }

    /**
     * `convert_btn_outline_dropdown_color_to_custom()` + `apply_btn_outline_color()`.
     *
     * `VcSharedLibrary::$btn_outline_colors` paints text, border and hover
     * background with the same value, which is exactly what 9.0.1's
     * `outline-custom` style and its single `outline_custom_color` picker
     * express (config/content/vc-btn-element.php).
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

        $atts                      = self::put( $atts, $prefix . 'outline_custom_color', $colour[0] );
        $atts[ $prefix . 'style' ] = 'outline-custom';
        unset( $atts[ $prefix . 'color' ] );

        $notes[] = $tag . ': ' . $prefix . 'color → ' . $prefix . 'outline_custom_color';

        return $atts;
    }

    /**
     * `convert_btn_3d_dropdown_color_to_custom()` + `apply_btn_3d_color()`.
     *
     * The 3d style keeps its name — 9.0.1's `custom_background` and
     * `custom_text` are declared for it as well — and loses only the shadow,
     * which `$btn_3d_colors` stores as a `darken(@background, 11%)` value no
     * `Color` table carries.
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

        [ $background, $label ] = $colour;

        $atts    = self::put( $atts, $prefix . 'custom_background', $background );
        $written = [ $prefix . 'custom_background' ];

        if ( $label !== null ) {
            $atts      = self::put( $atts, $prefix . 'custom_text', $label );
            $written[] = $prefix . 'custom_text';
        }

        unset( $atts[ $prefix . 'color' ] );

        $notes[] = $tag . ': ' . $prefix . 'color → ' . implode( ', ', $written );
        $notes[] = $tag . ': the 3d button shadow colour was not carried';

        return $atts;
    }

    /**
     * `convert_btn_gradient_style_to_gradient_custom()`.
     *
     * Same defaults as the original — turquoise and blue when the button
     * carried no gradient colours — and the same white label, taken from the
     * palette rather than written as a literal.
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

            $atts = self::put( $atts, $prefix . 'gradient_custom_color_' . $index, $value );
            unset( $atts[ $prefix . 'gradient_color_' . $index ] );
        }

        $atts    = self::put( $atts, $prefix . 'gradient_text_color', (string) Color::fromPalette( 'white' ) );
        $notes[] = $tag . ': ' . $prefix . 'style="gradient" → gradient-custom';

        return $atts;
    }

    /**
     * `convert_integrated_btn()`: the same four button rules against the
     * prefix an element that embeds a button uses — `btn_` for `vc_cta`, the
     * grids and `vc_pricing_table`, `hover_btn_` for `vc_hoverbox`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_integrated_btn( string $tag, array $atts, string $prefix, array &$notes ): array {
        $atts = self::convert_btn_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );
        $atts = self::convert_btn_outline_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );
        $atts = self::convert_btn_3d_dropdown_color_to_custom( $tag, $atts, $prefix, $notes );

        return self::convert_btn_gradient_style_to_gradient_custom( $tag, $atts, $prefix, $notes );
    }

    /**
     * The background/label pair for a button `color` slug, or null when the
     * button has no colour to migrate or the slug is not one WPBakery ships.
     *
     * `Color::BUTTON_LEGACY` is `VcSharedLibrary::$btn_solid_colors`' seven
     * classic slugs, which carry their own label colour. The 17 palette names
     * take a white label, except grey and white: WPBakery labels those `#666`,
     * a value no `Color` table carries, so no label is written at all and the
     * module's own default applies — a dark label on a light button, which is
     * what the original looked like.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array{0: string, 1: ?string}|null
     */
    private static function buttonColour( string $tag, array $atts, string $prefix, array &$notes ): ?array {
        $slug = $atts[ $prefix . 'color' ] ?? '';

        if ( $slug === '' || $slug === 'custom' ) {
            return null;
        }

        $key = str_replace( '-', '_', strtolower( trim( $slug ) ) );

        if ( isset( Color::BUTTON_LEGACY[ $key ] ) ) {
            return [ Color::BUTTON_LEGACY[ $key ]['bg'], Color::BUTTON_LEGACY[ $key ]['text'] ];
        }

        $background = self::colour( $slug );

        if ( $background === null ) {
            $notes[] = self::unresolved( $tag, $prefix . 'color', $slug );

            return null;
        }

        return [
            $background,
            in_array( $key, self::DARK_LABEL_PALETTE, true ) ? null : Color::fromPalette( 'white' ),
        ];
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

        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'color', 'custom_text', $notes );
    }

    /**
     * `convert_cta_flat_color_to_custom()` + `apply_cta_flat_color()`.
     * `VcSharedLibrary::$cta_colors` gives background and heading colour; the
     * heading is white for every palette slug but grey and white.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_flat_color_to_custom( string $tag, array $atts, array &$notes ): array {
        if ( ( $atts['style'] ?? '' ) !== 'flat' ) {
            return $atts;
        }

        return self::applyCtaColour( $tag, $atts, 'custom_background', $notes );
    }

    /**
     * `convert_cta_3d_color_to_custom()` + `apply_cta_3d_color()`. The box
     * shadow `$cta_colors` carries as a fourth value is a `darken()` result
     * no `Color` table has, so it is not written.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_cta_3d_color_to_custom( string $tag, array $atts, array &$notes ): array {
        if ( ( $atts['style'] ?? '' ) !== '3d' ) {
            return $atts;
        }

        $atts = self::applyCtaColour( $tag, $atts, 'custom_background', $notes );

        if ( isset( $atts['custom_background'] ) ) {
            $notes[] = $tag . ': the 3d box shadow colour was not carried';
        }

        return $atts;
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
        if ( ( $atts['style'] ?? '' ) !== 'outline' ) {
            return $atts;
        }

        $slug  = $atts['color'] ?? '';
        $value = $slug === '' ? null : self::colour( $slug );

        if ( $slug === '' ) {
            return $atts;
        }

        if ( $value === null ) {
            $notes[] = self::unresolved( $tag, 'color', $slug );

            return $atts;
        }

        $atts = self::put( $atts, 'custom_text', $value );
        $atts = self::put( $atts, 'custom_border', $value );
        unset( $atts['color'] );

        $notes[] = $tag . ': color → custom_text, custom_border';

        return $atts;
    }

    /**
     * The background/heading half of `apply_cta_flat_color()` and
     * `apply_cta_3d_color()`, which differ only in the border they add.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function applyCtaColour( string $tag, array $atts, string $background_key, array &$notes ): array {
        $slug = $atts['color'] ?? '';

        if ( $slug === '' ) {
            return $atts;
        }

        $background = self::colour( $slug );

        if ( $background === null ) {
            $notes[] = self::unresolved( $tag, 'color', $slug );

            return $atts;
        }

        $atts    = self::put( $atts, $background_key, $background );
        $written = [ $background_key ];

        if ( ! in_array( str_replace( '-', '_', strtolower( $slug ) ), self::DARK_LABEL_PALETTE, true ) ) {
            $atts      = self::put( $atts, 'custom_text', (string) Color::fromPalette( 'white' ) );
            $written[] = 'custom_text';
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
        if ( ! isset( $atts[ $toggle ] ) || ! in_array( $atts[ $toggle ], [ 'top', 'bottom', 'left', 'right' ], true ) ) {
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
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'color', 'custom_color', $notes );
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
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'stroke_color', 'custom_stroke_color', $notes );
    }

    /**
     * `convert_round_chart_legend_color_to_custom()`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_round_chart_legend_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'legend_color', 'custom_legend_color', $notes );
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
     * `convert_progress_bar_bgcolor_to_custom()` + `apply_progress_bar_color()`.
     *
     * The classic `bar_*` slugs are Bootstrap-2 values WPBakery keeps in the
     * migration itself rather than in a shared table, so they resolve to
     * nothing here and are reported; the palette slugs resolve normally. The
     * label colour and text shadow the original also writes are literals of
     * the same kind and are left out.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_progress_bar_bgcolor_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrate_dropdown_color_to_custom( $tag, $atts, 'bgcolor', 'custombgcolor', $notes );
    }

    /**
     * `migrate_progress_bar_values_color()`: the per-bar `color` dropdown
     * inside the `values` param group.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_progress_bar_values_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrateParamGroupColour( $tag, $atts, 'customcolor', $notes );
    }

    /**
     * `migrate_chart_values_color()` / `migrate_chart_item_color()`: the same
     * rule for `vc_round_chart` and `vc_line_chart`, whose per-item picker is
     * called `custom_color`.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function convert_chart_values_color_to_custom( string $tag, array $atts, array &$notes ): array {
        return self::migrateParamGroupColour( $tag, $atts, 'custom_color', $notes );
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
        if ( ! isset( $atts['count'] ) || is_numeric( $atts['count'] ) ) {
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
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrate_dropdown_color_to_custom( string $tag, array $atts, string $source_key, string $target_key, array &$notes ): array {
        $slug = $atts[ $source_key ] ?? '';

        if ( $slug === '' || $slug === 'custom' || ! empty( $atts[ $target_key ] ) ) {
            return $atts;
        }

        $value = self::colour( $slug );

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
     * The per-item colour of a `values` param group, for the progress bar and
     * both charts. WPBakery re-encodes the group with
     * `rawurlencode( wp_json_encode( … ) )`; so does this.
     *
     * @param array<string,string> $atts
     * @param string[]             $notes
     * @return array<string,string>
     */
    private static function migrateParamGroupColour( string $tag, array $atts, string $target_key, array &$notes ): array {
        if ( empty( $atts['values'] ) ) {
            return $atts;
        }

        $items = json_decode( urldecode( $atts['values'] ), true );

        if ( ! is_array( $items ) ) {
            return $atts;
        }

        $changed = false;

        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) || ! isset( $item['color'] ) ) {
                continue;
            }

            $slug  = (string) $item['color'];
            $value = $slug === '' || $slug === 'custom' ? null : self::colour( $slug );

            if ( $value === null ) {
                if ( $slug !== '' && $slug !== 'custom' ) {
                    $notes[] = self::unresolved( $tag, 'values color', $slug );
                }
                continue;
            }

            if ( empty( $item[ $target_key ] ) ) {
                $item[ $target_key ] = $value;
            }

            unset( $item['color'] );
            $items[ $index ] = $item;
            $changed         = true;
        }

        if ( ! $changed ) {
            return $atts;
        }

        $atts['values'] = rawurlencode( (string) json_encode( $items ) );
        $notes[]        = $tag . ': values color → ' . $target_key;

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
     * `if ( ! isset( $atts[…] ) )` guard every `apply_*_color()` uses, so a
     * colour the author picked by hand is never overwritten by a migrated one.
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
