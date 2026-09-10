<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `rev_slider_vc`, `rev_slider`, `layerslider_vc` and `layerslider` — the two
 * slider plugins WPBakery ships a bridge for
 * (`include/classes/shortcodes/rev-slider-vc.php`,
 * `layerslider-vc.php`, and their templates) — registered approximate under
 * the "Sliders" family.
 *
 * A Slider Revolution or LayerSlider slide deck lives in that plugin's own
 * tables, not in the page: the shortcode is a reference to it by alias or id.
 * Nothing can move that deck into Divi, so amendment §6's two behaviours apply:
 *
 * - **on the site the page lives on** the shortcode renders and the markup is
 *   kept as a static copy — markup only: the deck still needs its plugin's
 *   scripts and styles to animate, so the plugin has to stay active;
 * - **from an export** a labelled placeholder names the alias so the deck can
 *   be rebuilt or the plugin re-pointed.
 */
class SliderBridgeConverter extends BaseWPBakeryConverter {

    /** The attribute each bridge names its deck with. */
    const ALIAS_KEYS = [ 'alias', 'id', 'slidertitle' ];

    private string $tag;

    public function __construct( ConverterEngine $engine, string $tag = '' ) {
        parent::__construct( $engine );
        $this->tag = $tag;
    }

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_slider_' ) );
        $tag  = $this->tag !== '' ? $this->tag : (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $alias = '';
        foreach ( self::ALIAS_KEYS as $key ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                $alias = $value;
                break;
            }
        }

        $style = $this->mapStyle( 'generic', array_merge( $node, [ 'delegated' => true ] ) );
        $copy  = $this->staticCopy( $node, '' );

        $this->engine->logThemeElement( ThemeShortcodes::family( $tag )['label'], $id, $tag );
        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            $copy['static']
                ? sprintf(
                    '%s shows the "%s" slider, which lives in its plugin\'s own tables; the markup was rendered here and kept as a static copy, and it still needs that plugin active for its scripts and styles',
                    $tag,
                    $alias
                )
                : sprintf(
                    '%s shows the "%s" slider, which lives in its plugin\'s own tables and cannot be moved into Divi; a labelled placeholder keeps its place — rebuild it with Divi\'s slider module or keep the plugin',
                    $tag,
                    $alias
                )
        );

        $this->engine->logConverted( 'code' );
        $this->logUnmappedSettings( $id, $atts, array_keys( $atts ), $tag );

        return $this->codeBlock( $id, $copy['html'], $style['divi_attrs'] );
    }
}
