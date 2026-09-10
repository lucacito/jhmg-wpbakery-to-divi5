<?php

namespace WPBakeryDivi5Converter\Helpers;

use WPBakeryDivi5Converter\Converter\Registry\ConverterRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which theme or add-on a non-core shortcode belongs to.
 *
 * Almost every WPBakery site runs a ThemeForest theme and two or three
 * add-ons, and their shortcodes outnumber WPBakery's own on a typical page.
 * The converter never drops one (`ThemeShortcodeConverter`, spec §7), but the
 * report has to say *what* it kept, and "47 unknown shortcodes" says nothing
 * useful. This table turns a tag into a family so the report can say
 * "Salient: 12, Ultimate Addons: 6" instead.
 *
 * Two kinds of rule, evaluated in this order:
 *
 * 1. **Exact tags.** A family that registers a literal tag wins outright. Both
 *    corpora that arrived with the project need this: The Retailer's Extender
 *    registers `products_slider`, which the WooCommerce `products` prefix
 *    would otherwise swallow, and Ronneby Core registers two dozen unprefixed
 *    tags (`piecharts`, `rotate_box`, `tooltip`) that match no prefix at all.
 * 2. **Prefixes.** The usual add-on convention (`ultimate_`, `mpc_`, `dfd_`).
 *
 * Anything neither rule claims is its own family, "Other shortcodes" — a real
 * answer, not a failure.
 *
 * `kind` is the `not_carried_over` kind the engine files the element under
 * (spec §10): a plugin that renders a form or a shop is an `integration`, a
 * theme's own element is an `addon`.
 *
 * The whole table is one array so `wbdc_theme_families` can add a family with
 * either `tags` or `prefixes` (or replace one wholesale).
 */
final class ThemeShortcodes {

    const FILTER = 'wbdc_theme_families';

    /** The filter for `THEME_PARAMS`. */
    const PARAMS_FILTER = 'wbdc_theme_params';

    /**
     * family key ⇒ { label, kind, tags[], prefixes[] }.
     *
     * Sources: spec §7 for the prefix table; `references/README.md` (The
     * Retailer Extender 10.0.6) and `references/ronneby-core.zip`
     * → `inc/vc_custom/**` `'base' =>` entries (Ronneby Core 1.5.74) for the
     * two explicit-tag families.
     */
    const FAMILIES = [
        'the-retailer'    => [
            'label' => 'The Retailer',
            'tags'  => [
                'banner_simple_height', 'best_selling_products_mixed', 'custom_best_sellers',
                'custom_featured_products', 'custom_latest_products', 'custom_on_sale_products',
                'custom_product_attribute', 'custom_products', 'custom_top_rated_products',
                'featured_products_mixed', 'from_the_blog', 'icon_box', 'image_slide',
                'products_by_attribute_mixed', 'products_by_category', 'products_by_category_mixed',
                'products_mixed', 'products_slider', 'recent_products_mixed', 'sale_products_mixed',
                'slider', 'team_member', 'title_subtitle', 'top_rated_products_mixed',
            ],
        ],
        'ronneby'         => [
            'label'    => 'Ronneby',
            'prefixes' => [ 'dfd_' ],
            'tags'     => [
                'announcement', 'button_gradient', 'countdown', 'facts', 'image_layers',
                'info_banner', 'new_team_member', 'new_testimonials', 'piecharts', 'price_list',
                'pricing_Label', 'pricing_block', 'progressbar', 'rotate_box', 'single_icon',
                'testimonials_slider', 'videoplayer', 'woocomposer_grid', 'woocomposer_product',
                'woocomposer_single_cat', 'tooltip', 'popover',
            ],
        ],
        'ultimate-addons' => [
            'label'    => 'Ultimate Addons',
            'prefixes' => [ 'ultimate_', 'ult_', 'bsf-', 'bsf_', 'info_list', 'icon_counter', 'just_icon', 'stat_counter' ],
        ],
        'massive-addons'  => [
            'label'    => 'Massive Addons',
            'prefixes' => [ 'mpc_' ],
        ],
        'salient'         => [
            'label'    => 'Salient',
            'prefixes' => [
                'nectar_', 'nectar', 'fancy_box', 'image_with_animation', 'divider_line',
                'milestone', 'testimonial_slider', 'recent_posts', 'split_line_heading',
            ],
        ],
        'bridge'          => [
            'label'    => 'Bridge',
            'prefixes' => [ 'qode_', 'no_' ],
        ],
        'the7'            => [
            'label'    => 'The7',
            'prefixes' => [ 'dt_' ],
        ],
        'jupiter'         => [
            'label'    => 'Jupiter',
            'prefixes' => [ 'mk_' ],
        ],
        'templatera'      => [
            'label'    => 'Templatera',
            'prefixes' => [ 'templatera' ],
        ],
        'woocommerce'     => [
            'label'    => 'WooCommerce',
            'kind'     => 'integration',
            'prefixes' => [ 'products', 'product_category', 'product_page', 'add_to_cart', 'woocommerce_' ],
        ],
        'contact-form-7'  => [
            'label'    => 'Contact Form 7',
            'kind'     => 'integration',
            'prefixes' => [ 'contact-form-7', 'contact-form' ],
        ],
        'gravity-forms'   => [
            'label'    => 'Gravity Forms',
            'kind'     => 'integration',
            'prefixes' => [ 'gravityforms', 'gravityform' ],
        ],
        'sliders'         => [
            'label'    => 'Sliders',
            'kind'     => 'integration',
            'prefixes' => [ 'rev_slider', 'layerslider', 'smartslider3', 'metaslider' ],
        ],
    ];

    /**
     * Params a theme adds to a WPBakery **core** element with `vc_add_param()`.
     *
     * A theme does not only register elements of its own (`FAMILIES` above); it
     * also bolts fields onto WPBakery's rows, columns and text blocks, and those
     * fields end up in the shortcode of every page the theme built. They are
     * not WPBakery's, so no core handler knows them, and they are not "skipped
     * settings" in the sense that word has here — nobody wrote a handler that
     * ignored them, they belong to a plugin that is not being converted. So
     * they are reported under `addon`, with the family named, instead
     * (`BaseWPBakeryConverter::logUnmappedSettings()`).
     *
     * Sources, each named again against the block of names it produced:
     * Ronneby Core 1.5.74 — `inc/vc_custom/dfd_vc_addons.php:901-1729`,
     * `inc/vc_custom/dfd_vc_background/**` and
     * `inc/vc_custom/dfd_vc_addons/old_modules/Dfd_Override_Parallax.php`;
     * Ultimate VC Addons 3.19.3 — `modules/ultimate_parallax.php`. Every live
     * `vc_add_param()` / `vc_add_params()` call they make, by the tag they make
     * it on. A registration that is commented out is deliberately absent: the
     * four Ronneby makes on `vc_single_image` (dfd_vc_addons.php:1730-1776,
     * `image_opacity`, `onclick`, `link_one_page_value`, `item_animation`) and
     * `dfd_fadeout_row` / `dfd_fadeout_start_effect` on `vc_row`, and
     * `dfd_oembed_start_time` / `dfd_oembed_stop_time` / `dfd_in_viewport` in
     * `dfd_vc_background/admin_templates/video.php:86,97,108`. A demo export
     * written by an older build still carries some of those, and they are
     * reported as skipped settings — which is the truth about them: 1.5.74 does
     * not offer the field.
     *
     * The table is keyed by param name, not by what the page proves is
     * installed: an export carries no plugin list, and gating it on a Ronneby
     * element being present on the same page would put every core-only Ronneby
     * page back into `skipped_settings`. A name that another theme also uses is
     * therefore attributed by name, which is why the report says the field
     * *matches* this theme's table rather than asserting the theme is the one
     * that added it.
     *
     * The add-on families are here for the same reason the theme ones are: a
     * page that used Ultimate Addons' row backgrounds carries those fields on
     * every `vc_row` long after the element that came with the plugin is gone.
     *
     * `wbdc_theme_params` lets a site add another theme's table.
     *
     * @var array<string, array<int, string>>
     */
    const THEME_PARAMS = [
        'ronneby' => [
            'vc_row'          => [
                'align_content_vertically', 'anchor', 'delimiter_bg_color_value', 'delimiter_height',
                'dfd_row_config', 'dfd_row_parallax', 'dfd_row_responsive_enable', 'effects',
                'extra_css_styles', 'extra_features', 'force_equal_height_columns', 'heading',
                'mobile_destroy_equal_heights', 'mobile_destroy_equal_heights_resolution',
                'one_page_title', 'responsive_styles', 'row_delimiter', 'row_effect',
                'row_parallax_limit', 'row_parallax_sense', 'row_prebuilt_classes',
                'row_responsive_mobile_classes', 'row_responsive_mobile_resolutions', 'sizing',
                // The row background panel: `inc/vc_custom/dfd_vc_background/dfd_vc_bg.php`
                // (`vc_add_params( 'vc_row', $row_params )`, line 188) and the five
                // per-style field sets it folds in from `admin_templates/` —
                // animated.php, canvas.php, gradient.php, image.php, video.php.
                'bg_main', 'bg_check', 'dfd_bg_style', 'bg_overlay', 'dfd_enable_overlay',
                'dfd_overlay_color', 'dfd_overlay_pattern', 'dfd_overlay_pattern_opacity',
                'dfd_overlay_pattern_size',
                'dfd_bg_colors', 'dfd_bg_single_color', 'dfd_anim_bg_duration',
                'dfd_canvas_style', 'dfd_canvas_size', 'dfd_bg_color_value', 'dfd_canvas_color',
                'dfd_bg_image_canvas', 'dfd_bg_image_repeat_canvas', 'dfd_bg_image_size_canvas',
                'dfd_bg_grad', 'dfd_bg_grad_animate', 'dfd_bg_grad_anim_duration',
                'dfd_parallax_style', 'dfd_bg_image_new', 'dfd_layer_image',
                'dfd_multi_parallax_direction', 'dfd_bg_image_repeat', 'dfd_bg_image_size',
                'dfd_bg_image_manual_size', 'dfd_image_bg_color', 'dfd_bg_img_attach',
                'dfd_parallax_sense', 'dfd_parallax_offset', 'dfd_bg_image_position',
                'dfd_animation_direction', 'bg_mobile_main', 'dfd_mobile_enable',
                'dfd_bg_image_new_responsive', 'dfd_bg_resolution', 'dfd_bg_image_position_mobile',
                'dfd_bg_image_repeat_mobile', 'dfd_bg_image_size_mobile',
                'dfd_bg_image_manual_size_mobile',
                'dfd_video_variant', 'dfd_video_url_mp4', 'dfd_video_url_webm',
                'dfd_youtube_video_id', 'dfd_vimeo_video_id', 'dfd_video_opts',
                'dfd_video_poster', 'dfd_enable_controls', 'dfd_controls_color',
                // The five fields `inc/vc_custom/dfd_vc_addons/old_modules/Dfd_Override_Parallax.php`
                // adds that Ultimate Addons' own row panel does not (its canvas
                // background, and a parallax offset); the sixty-five it shares with
                // that panel are under 'ultimate-addons' below, whose names they are.
                'canvas_style', 'canvas_size', 'canvas_color', 'bg_image_canvas', 'parallax_offset',
            ],
            'vc_row_inner'    => [
                'dfd_row_responsive_enable', 'extra_features', 'inner_row_bg_position',
                'inner_row_bg_repeat', 'inner_row_custom_img_size', 'responsive_styles',
            ],
            'vc_column'       => [
                'col_hover_bg', 'col_hover_settings', 'col_shadow', 'col_shadow_hover',
                'column_bg_check', 'column_bg_position', 'column_bg_repeat', 'column_parallax',
                'column_parallax_destroy', 'column_parallax_limit', 'column_parallax_sense',
                'column_prebuilt_classes', 'column_responsive_mobile_classes',
                'column_responsive_mobile_resolutions', 'dfd_column_responsive_enable', 'extra_features',
                'main', 'responsive_styles',
            ],
            'vc_column_inner' => [
                'col_inner_hover_bg', 'col_inner_hover_settings', 'col_inner_shadow',
                'col_inner_shadow_hover', 'dfd_column_responsive_enable', 'responsive_styles',
            ],
            'vc_column_text'  => [ 'item_animation' ],
            'vc_accordion'    => [ 'item_animation', 'titles_alignment' ],
            'vc_tour'         => [ 'tabs_alignment' ],
            'vc_video'        => [
                'description', 'icon_color', 'label_background', 'module_alignment', 'video_id',
                'video_module_mode', 'video_source', 'video_thumb_image', 'video_title',
            ],
        ],
        'ultimate-addons' => [
            // "Ultimate Row Backgrounds": Ultimate VC Addons 3.19.3,
            // `modules/ultimate_parallax.php`, every live `vc_add_param( 'vc_row', … )`
            // it makes (68). It is the most widely installed WPBakery add-on there
            // is, so these names are on the rows of a great many pages that have
            // nothing else of the plugin left on them; Ronneby re-registers all but
            // three of them from `Dfd_Override_Parallax.php` when the add-on is
            // active, which is why the demo exports carry them too.
            'vc_row' => [
                'bg_type', 'bg_grad', 'bg_color_value', 'parallax_style', 'bg_image_new',
                'layer_image', 'bg_image_repeat', 'bg_image_size', 'bg_cstm_size', 'bg_img_attach',
                'parallax_sense', 'bg_image_posiiton', 'animation_direction', 'animation_repeat',
                'video_url', 'video_url_2', 'u_video_url', 'video_opts', 'video_poster',
                'u_start_time', 'u_stop_time', 'viewport_vdo', 'enable_controls', 'controls_color',
                'bg_override', 'disable_on_mobile_img_parallax', 'parallax_content',
                'parallax_content_sense', 'fadeout_row', 'fadeout_start_effect', 'enable_overlay',
                'overlay_color', 'overlay_pattern', 'overlay_pattern_opacity',
                'overlay_pattern_size', 'overlay_pattern_attachment', 'multi_color_overlay',
                'multi_color_overlay_opacity', 'seperator_enable', 'seperator_type',
                'seperator_position', 'seperator_shape_size', 'seperator_svg_height',
                'seperator_shape_background', 'seperator_shape_border',
                'seperator_shape_border_color', 'seperator_shape_border_width', 'icon_type',
                'icon', 'icon_size', 'icon_color', 'icon_style', 'icon_color_bg',
                'icon_border_style', 'icon_color_border', 'icon_border_size', 'icon_border_radius',
                'icon_border_spacing', 'icon_img', 'img_width', 'ult_hide_row',
                'ult_hide_row_large_screen', 'ult_hide_row_desktop', 'ult_hide_row_tablet',
                'ult_hide_row_tablet_small', 'ult_hide_row_mobile', 'ult_hide_row_mobile_large',
                'notification',
            ],
        ],
    ];

    /**
     * The theme family a param on a core element belongs to, or null.
     *
     * @return array{key: string, label: string, kind: string}|null
     */
    public static function themeParam( string $tag, string $param ): ?array {
        foreach ( self::themeParams() as $family => $tags ) {
            if ( in_array( $param, (array) ( $tags[ $tag ] ?? [] ), true ) ) {
                return self::entry( $family, self::families()[ $family ] ?? [] );
            }
        }

        return null;
    }

    /** @return array<string, array<string, array<int, string>>> The table, overlaid with the filter. */
    public static function themeParams(): array {
        if ( ! function_exists( 'apply_filters' ) ) {
            return self::THEME_PARAMS;
        }

        // Literal so Plugin Check can read the hook name.
        $filtered = apply_filters( 'wbdc_theme_params', self::THEME_PARAMS );

        return is_array( $filtered ) ? $filtered : self::THEME_PARAMS;
    }

    /** The family a tag nobody claims belongs to. */
    const OTHER = [ 'key' => 'other', 'label' => 'Other shortcodes', 'kind' => 'addon' ];

    /**
     * The family for one shortcode tag.
     *
     * @return array{key: string, label: string, kind: string}
     */
    public static function family( string $tag ): array {
        $tag      = trim( $tag );
        $families = self::families();

        foreach ( $families as $key => $family ) {
            if ( in_array( $tag, (array) ( $family['tags'] ?? [] ), true ) ) {
                return self::entry( $key, $family );
            }
        }

        foreach ( $families as $key => $family ) {
            foreach ( (array) ( $family['prefixes'] ?? [] ) as $prefix ) {
                if ( $prefix !== '' && str_starts_with( $tag, $prefix ) ) {
                    return self::entry( $key, $family );
                }
            }
        }

        return self::OTHER;
    }

    /**
     * Whether a tag is one WPBakery itself ships — the difference between "a
     * core element this converter has no handler for yet", which is reported
     * as unsupported, and "a theme's element", which never is.
     */
    public static function isCore( string $tag ): bool {
        return str_starts_with( $tag, 'vc_' ) && ConverterRegistry::isCoreTag( $tag );
    }

    /** @return array<string, array<string, mixed>> The table, overlaid with the filter. */
    public static function families(): array {
        if ( ! function_exists( 'apply_filters' ) ) {
            return self::FAMILIES;
        }

        // Literal so Plugin Check can read the hook name; keep in step with self::FILTER.
        $filtered = apply_filters( 'wbdc_theme_families', self::FAMILIES );

        return is_array( $filtered ) ? $filtered : self::FAMILIES;
    }

    /**
     * @param array<string, mixed> $family
     * @return array{key: string, label: string, kind: string}
     */
    private static function entry( string $key, array $family ): array {
        return [
            'key'   => $key,
            'label' => is_string( $family['label'] ?? null ) ? $family['label'] : $key,
            'kind'  => is_string( $family['kind'] ?? null ) ? $family['kind'] : 'addon',
        ];
    }
}
