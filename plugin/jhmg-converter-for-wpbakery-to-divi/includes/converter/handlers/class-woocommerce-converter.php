<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce's own shortcodes, the ones WPBakery maps into its editor →
 * `divi/code` holding the shortcode itself, registered approximate under the
 * "WooCommerce" family (amendment §6).
 *
 * There is no `vc_woocommerce` shortcode: `vc_woocommerce.php` is a *wrapper
 * template* WPBakery wraps around WooCommerce's own output when the element
 * carries an `el_class` or `el_id`
 * (`Vc_Vendor_Woocommerce::add_wrapper_to_shortcodes_output()`), and
 * `Vc_Vendor_Woocommerce::WC_SHORTCODES` is the list of tags it maps. So what
 * a page actually holds is `[products …]`, `[product_category …]` and their
 * siblings.
 *
 * WordPress expands a shortcode inside a Divi code module at render time, so
 * keeping the shortcode text is not a placeholder: with WooCommerce active the
 * shop grid still renders. It is approximate because Divi draws it with
 * WooCommerce's own markup rather than a Divi module — 5.12.1 does ship real
 * WooCommerce modules, and a later release of this converter can use them.
 */
class WoocommerceConverter extends BaseWPBakeryConverter {

    /**
     * `Vc_Vendor_Woocommerce::WC_SHORTCODES`
     * (`include/classes/vendors/plugins/class-vc-vendor-woocommerce.php:27`).
     */
    const SHORTCODES = [
        'woocommerce_cart',
        'woocommerce_checkout',
        'woocommerce_order_tracking',
        'woocommerce_my_account',
        'recent_products',
        'featured_products',
        'product',
        'products',
        'add_to_cart',
        'add_to_cart_url',
        'product_page',
        'product_category',
        'product_categories',
        'sale_products',
        'best_selling_products',
        'top_rated_products',
        'product_attribute',
        'related_products',
    ];

    private string $tag;

    public function __construct( ConverterEngine $engine, string $tag = '' ) {
        parent::__construct( $engine );
        $this->tag = $tag;
    }

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_woo_' ) );
        $tag  = $this->tag !== '' ? $this->tag : (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // The design options apply to the block; the rendered shop brings its
        // own spacing from WooCommerce's stylesheet.
        $style = $this->mapStyle( 'generic', array_merge( $node, [ 'delegated' => true ] ) );

        // `el_class` and `el_id` are WPBakery's wrapper, not WooCommerce's, so
        // the shortcode is rebuilt without them: the StyleMapper has already
        // put them on the block.
        $shortcode = $this->originalShortcode( $this->withoutWrapperAttributes( $node ) );

        $this->engine->logThemeElement( ThemeShortcodes::family( $tag )['label'], $id, $tag );
        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            sprintf(
                '%s is a WooCommerce shortcode; it is kept as the shortcode itself inside a code module, so WordPress still expands it while WooCommerce is active — Divi 5.12 also ships its own WooCommerce modules if you would rather rebuild it',
                $tag
            )
        );

        $this->engine->logConverted( 'code' );
        $this->logUnmappedSettings( $id, $atts, array_keys( $atts ), $tag );

        return $this->codeBlock( $id, $shortcode, $style['divi_attrs'] );
    }

    /** @param array<string,mixed> $node */
    private function withoutWrapperAttributes( array $node ): array {
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        foreach ( [ 'el_class', 'el_id', 'css', 'css_animation' ] as $key ) {
            unset( $atts[ $key ] );
        }

        $node['atts'] = $atts;

        return $node;
    }
}
