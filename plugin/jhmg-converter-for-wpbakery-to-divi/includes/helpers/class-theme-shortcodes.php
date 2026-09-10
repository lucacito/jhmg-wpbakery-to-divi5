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
     * Source: Ronneby Core 1.5.74,
     * `inc/vc_custom/dfd_vc_addons.php:900-1400` — every `vc_add_param()` call
     * it makes, by the tag it makes it on.
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
            'vc_single_image' => [ 'image_opacity', 'item_animation', 'link_one_page_value' ],
            'vc_video'        => [
                'description', 'icon_color', 'label_background', 'module_alignment', 'video_id',
                'video_module_mode', 'video_source', 'video_thumb_image', 'video_title',
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
