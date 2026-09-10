<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_pie` → `divi/circle-counter`.
 *
 * The same object on both sides: a ring drawn to a percentage with the number
 * in the middle and a caption under it.
 * `include/templates/shortcodes/vc_pie.php` writes `data-pie-value`,
 * `data-pie-label-value`, `data-pie-units` and `data-pie-color`, and Divi's
 * `CircleCounterModule` reads `number.innerContent`,
 * `number.advanced.percentSign`, `title.innerContent` and
 * `circle.advanced.color` (`circle-counter/module.json`).
 *
 * One difference worth noting: WPBakery prints `title` *below* the ring as an
 * `h4.wpb_heading`, where every other element prints it above — and Divi's
 * circle counter also prints its title below the ring, so the title becomes the
 * module's own title rather than a separate heading block.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class PieConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_pie_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'counter', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'value', 'label_value', 'units', 'custom_color', 'title' ] );

        // `data-pie-label-value` replaces the number when it is set; WPBakery's
        // script falls back to `data-pie-value`.
        $label = trim( $this->att( $atts, 'label_value' ) );
        $value = trim( $this->att( $atts, 'value', '50' ) );

        StyleMapper::write( $attrs, 'number.innerContent.desktop.value', $label !== '' ? $label : $value );
        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'title' ) );

        $this->units( $atts, $id, $attrs );

        // `if ( $custom_color ) { $color = $custom_color; } else { $color = '#ebebeb'; }`
        $color = $this->color( array_merge( [ 'custom_color' => '#ebebeb' ], $atts ), 'custom_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'circle.advanced.color.desktop.value', $color );
        }

        $this->engine->logConverted( 'circle-counter' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/circle-counter', $attrs );
    }

    /**
     * `units` is appended to the number WPBakery prints. Divi's circle counter
     * prints a percent sign or nothing (`number.advanced.percentSign`), so `%`
     * maps and anything else is reported.
     */
    private function units( array $atts, string $id, array &$attrs ): void {
        $units = trim( $this->att( $atts, 'units' ) );

        StyleMapper::write( $attrs, 'number.advanced.percentSign.desktop.value', $units === '%' ? 'on' : 'off' );

        if ( $units !== '' && $units !== '%' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'units="%s" is printed after the number; Divi\'s circle counter prints a percent sign or nothing', $units )
            );
        }
    }
}
