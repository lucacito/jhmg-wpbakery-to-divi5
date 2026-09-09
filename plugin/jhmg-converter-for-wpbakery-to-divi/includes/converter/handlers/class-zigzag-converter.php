<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_zigzag` → `divi/divider`. Approximate, and registered as such.
 *
 * `include/templates/shortcodes/vc_zigzag.php` builds an inline SVG of one
 * chevron and repeats it along the element as a background image, sized
 * `el_border_width` tall and `el_width` percent wide, in `custom_color`. Divi
 * has no zigzag divider, and painting one with custom CSS would put a data URI
 * into every page the converter touches — so the colour, the height and the
 * width become a straight divider and the pattern is reported.
 */
class ZigzagConverter extends BaseWPBakeryConverter {

    /** `.vc-zigzag-wrapper{text-align:center}` and its two modifiers. */
    const ALIGNMENTS = [ 'left' => 'left', 'center' => 'center', 'right' => 'right' ];

    /** `$border_width = '10'` when `el_border_width` is empty (template line 33). */
    const DEFAULT_BORDER_WIDTH = '10';

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_zigzag_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'color', 'custom_color', 'el_border_width', 'el_width', 'align' ] );

        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.show', 'on' );

        $color = $this->color( $atts, 'custom_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.color', $color );
        }

        $weight = trim( $this->att( $atts, 'el_border_width', self::DEFAULT_BORDER_WIDTH ) );
        if ( preg_match( '/^\d+$/', str_replace( 'px', '', $weight ) ) === 1 ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.weight', str_replace( 'px', '', $weight ) . 'px' );
        }

        $width = trim( $this->att( $atts, 'el_width' ) );
        if ( preg_match( '/^\d+$/', $width ) === 1 && (int) $width > 0 && (int) $width < 100 ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.width', $width . '%' );
        }

        $align = strtolower( $this->att( $atts, 'align', 'center' ) );
        if ( isset( self::ALIGNMENTS[ $align ] ) ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.alignment', self::ALIGNMENTS[ $align ] );
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'vc_zigzag repeats an SVG chevron along the element; Divi has no zigzag divider, so a straight line of the same colour and height stands in for it'
        );

        $this->engine->logConverted( 'divider' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/divider', $attrs );
    }
}
