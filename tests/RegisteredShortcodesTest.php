<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Converter\Handlers\ThemeShortcodeConverter;
use WPBakeryDivi5Converter\Converter\Handlers\WoocommerceConverter;
use WPBakeryDivi5Converter\Converter\Registry\ConverterRegistry;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;
use WPBakeryDivi5Converter\Parsers\AttributeNormaliser;
use WPBakeryDivi5Converter\Parsers\ShortcodeParser;

/**
 * Coverage of WPBakery's own tag list, pinned literally.
 *
 * The registry is a table nobody reads end to end, and the one question a
 * buyer actually asks — "does it convert everything WPBakery ships?" — cannot
 * be answered by a table that grows a row at a time. So the list lives here,
 * copied from the source files named against each constant, and the test is
 * that every tag on it reaches a handler written for it rather than the
 * catch-all `ThemeShortcodeConverter` every unknown shortcode falls through
 * to.
 *
 * Falling through is not a failure in general — spec §7 makes it the whole of
 * the theme/add-on story — but for a tag WPBakery itself registers it means
 * the element was never implemented, and the report would say `unsupported`.
 *
 * The list is deliberately not derived from the registry: a test that reads
 * the thing it checks proves only that the thing is self-consistent.
 */
final class RegisteredShortcodesTest extends TestCase {

    /**
     * js_composer 9.0.1, `config/lean-map.php` — every `vc_lean_map()` call it
     * makes, in file order, 69 of them.
     */
    const LEAN_MAP = [
        'vc_accordion', 'vc_accordion_tab', 'vc_basic_grid', 'vc_btn', 'vc_button',
        'vc_column', 'vc_column_inner', 'vc_column_text', 'vc_copyright', 'vc_cta',
        'vc_cta_button', 'vc_custom_heading', 'vc_empty_space', 'vc_facebook', 'vc_flickr',
        'vc_gallery', 'vc_gmaps', 'vc_goo_maps', 'vc_googleplus', 'vc_hoverbox',
        'vc_icon', 'vc_images_carousel', 'vc_line_chart', 'vc_masonry_grid',
        'vc_masonry_media_grid', 'vc_media_grid', 'vc_message', 'vc_pie', 'vc_pinterest',
        'vc_posts_slider', 'vc_pricing_table', 'vc_progress_bar', 'vc_raw_html', 'vc_raw_js',
        'vc_round_chart', 'vc_row', 'vc_row_inner', 'vc_section', 'vc_separator',
        'vc_single_image', 'vc_tab', 'vc_tabs', 'vc_text_separator', 'vc_toggle', 'vc_tour',
        'vc_tta_accordion', 'vc_tta_pageable', 'vc_tta_section', 'vc_tta_tabs', 'vc_tta_toggle',
        'vc_tta_toggle_section', 'vc_tta_tour', 'vc_tweetmeme', 'vc_video', 'vc_widget_sidebar',
        'vc_wp_archives', 'vc_wp_calendar', 'vc_wp_categories', 'vc_wp_custommenu', 'vc_wp_links',
        'vc_wp_meta', 'vc_wp_pages', 'vc_wp_posts', 'vc_wp_recentcomments', 'vc_wp_rss',
        'vc_wp_search', 'vc_wp_tagcloud', 'vc_wp_text', 'vc_zigzag',
    ];

    /**
     * The two button forms 9.0.1 dropped from its map and 7.8 still registers:
     * js_composer 7.8, `config/lean-map.php:88,90` →
     * `config/deprecated/shortcode-vc-button2.php` and
     * `config/deprecated/shortcode-vc-cta-button2.php`.
     *
     * A page built before 9.0 still holds them, so they are part of what this
     * converter has to read (`AttributeNormaliser::DEPRECATED_TAGS`).
     */
    const DEPRECATED_BUTTONS = [ 'vc_button2', 'vc_cta_button2' ];

    /**
     * js_composer 9.0.1, `config/containers/` — the four container configs
     * whose tag `config/lean-map.php` does not carry (the other five files
     * there are `vc_row`, `vc_row_inner`, `vc_column`, `vc_column_inner` and
     * `vc_section`, which it does).
     */
    const CONTAINERS = [
        'vc_flexbox_container', 'vc_flexbox_container_item',
        'vc_grid_container', 'vc_grid_container_item',
    ];

    /**
     * Tags 9.0.1 ships a render template for
     * (`include/templates/shortcodes/`) or a vendor bridge registers, which
     * are therefore not in the 75 but still land in real `post_content`.
     *
     * `vc_woocommerce.php` is deliberately absent: it is the wrapper template
     * WPBakery renders a WooCommerce element *with*, not a tag anybody writes
     * — the tags a page holds are `self::WOOCOMMERCE` (task-10-amendments §5).
     */
    const TEMPLATE_ONLY = [
        // include/templates/shortcodes/vc_gutenberg.php
        'vc_gutenberg',
        // include/templates/shortcodes/vc_custom_field.php
        'vc_custom_field',
        // include/templates/shortcodes/rev_slider_vc.php
        'rev_slider_vc',
        // include/templates/shortcodes/layerslider_vc.php
        'layerslider_vc',
        // include/classes/vendors/plugins/class-vc-vendor-contact-form7.php:31
        'contact-form-7',
    ];

    /**
     * js_composer 9.0.1,
     * `include/classes/vendors/plugins/class-vc-vendor-woocommerce.php:27`
     * (`Vc_Vendor_Woocommerce::WC_SHORTCODES`), each fed to `vc_lean_map()` at
     * line 949. These are WooCommerce's own tags, mapped into WPBakery's
     * editor — the eighteen a converted shop page actually holds.
     */
    const WOOCOMMERCE = [
        'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_order_tracking',
        'woocommerce_my_account', 'recent_products', 'featured_products', 'product',
        'products', 'add_to_cart', 'add_to_cart_url', 'product_page', 'product_category',
        'product_categories', 'sale_products', 'best_selling_products', 'top_rated_products',
        'product_attribute', 'related_products',
    ];

    /**
     * The four tags that are a panel of their parent and nothing on their own,
     * with the element that reads them.
     *
     * A `vc_tta_section` is one tab; Divi's tabs module holds its panels as
     * HTML inside the parent's own attributes, so `TtaTabsConverter` reads the
     * children itself and there is no block a lone section could become. Same
     * for a flex or grid container item, which `FlexboxContainerConverter` and
     * `GridContainerConverter` turn into one `divi/group` each.
     *
     * Registering a handler for them would not help: it would have to invent a
     * container for a panel that has none. What this test asserts instead is
     * that the parent named here is registered, so the panel is read by
     * something.
     */
    const READ_BY_THEIR_PARENT = [
        'vc_tta_section'            => 'vc_tta_tabs',
        'vc_tta_toggle_section'     => 'vc_tta_toggle',
        'vc_flexbox_container_item' => 'vc_flexbox_container',
        'vc_grid_container_item'    => 'vc_grid_container',
    ];

    /** The node kind each container tag parses to (`NodeTree::KINDS`). */
    const STRUCTURE_KINDS = [
        'vc_section'      => 'section',
        'vc_row'          => 'row',
        'vc_row_inner'    => 'row_inner',
        'vc_column'       => 'column',
        'vc_column_inner' => 'column_inner',
    ];

    /** @return string[] The 75 tags WPBakery registers, across both eras. */
    public static function registeredTags(): array {
        return array_merge( self::LEAN_MAP, self::DEPRECATED_BUTTONS, self::CONTAINERS );
    }

    public function test_the_registered_list_is_the_seventy_five_tags_the_spec_names(): void {
        $tags = self::registeredTags();

        $this->assertCount( 69, self::LEAN_MAP, 'js_composer 9.0.1 config/lean-map.php makes 69 vc_lean_map() calls' );
        $this->assertCount( 75, $tags, '69 lean-map + 2 deprecated buttons + 4 container tags' );
        $this->assertSame( $tags, array_unique( $tags ), 'a tag is listed twice' );
    }

    /**
     * Every registered tag reaches an implementation.
     *
     * The tag is put through `AttributeNormaliser` first, because that is what
     * the engine does: a deprecated tag never reaches the registry under its
     * own name — `vc_button` is a `vc_btn` by the time a handler sees it.
     */
    #[DataProvider( 'registeredTagProvider' )]
    public function test_a_registered_tag_resolves_to_a_handler_of_its_own( string $tag ): void {
        $effective = self::normalise( $tag );

        if ( isset( self::READ_BY_THEIR_PARENT[ $effective ] ) ) {
            $parent = self::READ_BY_THEIR_PARENT[ $effective ];

            $this->assertNotNull(
                self::converterFor( $parent ),
                "[{$tag}] is read by [{$parent}], which has no handler."
            );

            return;
        }

        $converter = self::converterFor( $effective );

        $this->assertNotNull(
            $converter,
            "[{$tag}] (normalised to [{$effective}]) has no handler: it would be reported as an unsupported WPBakery element."
        );
        $this->assertNotInstanceOf(
            ThemeShortcodeConverter::class,
            $converter,
            "[{$tag}] falls through to the theme/add-on placeholder instead of converting."
        );
    }

    /** @return array<string, array{0: string}> */
    public static function registeredTagProvider(): array {
        $cases = [];
        foreach ( self::registeredTags() as $tag ) {
            $cases[ $tag ] = [ $tag ];
        }

        return $cases;
    }

    #[DataProvider( 'templateOnlyTagProvider' )]
    public function test_a_template_only_tag_resolves_to_a_handler_of_its_own( string $tag ): void {
        $converter = self::converterFor( $tag );

        $this->assertNotNull( $converter, "[{$tag}] has no handler." );
        $this->assertNotInstanceOf(
            ThemeShortcodeConverter::class,
            $converter,
            "[{$tag}] falls through to the theme/add-on placeholder instead of converting."
        );
    }

    /** @return array<string, array{0: string}> */
    public static function templateOnlyTagProvider(): array {
        $cases = [];
        foreach ( self::TEMPLATE_ONLY as $tag ) {
            $cases[ $tag ] = [ $tag ];
        }

        return $cases;
    }

    public function test_every_woocommerce_tag_wpbakery_maps_is_handled(): void {
        $this->assertCount( 18, self::WOOCOMMERCE, 'Vc_Vendor_Woocommerce::WC_SHORTCODES holds 18 tags' );
        $this->assertSame(
            self::WOOCOMMERCE,
            WoocommerceConverter::SHORTCODES,
            'the handler\'s copy of WC_SHORTCODES has drifted from the source'
        );

        foreach ( self::WOOCOMMERCE as $tag ) {
            $this->assertInstanceOf(
                WoocommerceConverter::class,
                self::converterFor( $tag ),
                "[{$tag}] is not handled by WoocommerceConverter."
            );
        }
    }

    /**
     * A tag whose body the parser must not read as shortcodes has to be one
     * this converter knows, or the raw content is kept for nothing.
     */
    public function test_every_raw_content_tag_is_registered_or_deliberately_template_only(): void {
        $this->assertCount( 11, ShortcodeParser::RAW_CONTENT_TAGS );

        $registered    = self::registeredTags();
        $template_only = [ 'vc_gutenberg', 'vc_custom_field' ];

        foreach ( ShortcodeParser::RAW_CONTENT_TAGS as $tag ) {
            $this->assertTrue(
                in_array( $tag, $registered, true ) || in_array( $tag, $template_only, true ),
                "[{$tag}] holds raw content but is neither a registered WPBakery tag nor one of the two template-only tags."
            );
        }
    }

    /** Every tag in both lists is one the registry calls a WPBakery tag. */
    public function test_the_core_tag_table_covers_every_registered_tag(): void {
        foreach ( self::registeredTags() as $tag ) {
            $this->assertTrue(
                ConverterRegistry::isCoreTag( $tag ),
                "[{$tag}] is registered by WPBakery but ConverterRegistry::CORE_TAGS does not list it."
            );
        }
    }

    /**
     * A family with neither tags nor prefixes claims nothing, so every element
     * it was written for would be filed under "Other shortcodes" instead.
     */
    public function test_every_theme_family_claims_something(): void {
        $families = ThemeShortcodes::FAMILIES;

        $this->assertNotEmpty( $families );

        foreach ( $families as $key => $family ) {
            $this->assertNotEmpty( $family['label'] ?? '', "family '{$key}' has no label" );
            $this->assertNotEmpty(
                array_merge( (array) ( $family['tags'] ?? [] ), (array) ( $family['prefixes'] ?? [] ) ),
                "family '{$key}' claims neither a tag nor a prefix"
            );
        }

        // The two families that name their tags one by one rather than by
        // prefix (amendment §2): an empty table there is a silent regression,
        // because a prefix rule would not catch `products_slider` or
        // `piecharts`.
        $this->assertNotEmpty( $families['the-retailer']['tags'] ?? [] );
        $this->assertNotEmpty( $families['ronneby']['tags'] ?? [] );
    }

    /** The tag a node carries once `AttributeNormaliser` has been over it. */
    private static function normalise( string $tag ): string {
        return (string) AttributeNormaliser::normalise( $tag, [] )['tag'];
    }

    private static function converterFor( string $tag ): ?object {
        $kind = self::STRUCTURE_KINDS[ $tag ] ?? 'element';

        return ( new ConverterEngine() )->registry()->getConverter( [ 'tag' => $tag, 'kind' => $kind ] );
    }
}
