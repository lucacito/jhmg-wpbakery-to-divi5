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
