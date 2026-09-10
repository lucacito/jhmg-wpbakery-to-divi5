<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_spacer` → `divi/divider` with the line switched off.
 *
 * The element is nothing but its height, per breakpoint:
 * `modules/dfd_spacer.php:157` prints
 * `<div class="dfd-spacer-module" data-…  style="height: <wide>px">` and the
 * theme's script resizes it on `load` and `resize`
 * (`assets/js_pub/uncompresed.js:16321-16353`). Divi's divider is the module
 * whose height can be set with no line drawn — the same conversion
 * `vc_empty_space` takes (`EmptySpaceConverter`), with three heights instead of
 * one.
 *
 * It is the single most common element in the corpus (13,689 of them across the
 * 96 exports), because Ronneby's demo pages space everything with spacers
 * rather than with element margins — which is also why no Ronneby element
 * carries a default bottom margin of its own.
 *
 * **No default bottom margin**: the element *is* the space (the rule
 * `EmptySpaceConverter` follows for the same reason).
 */
class DfdSpacerConverter extends RonnebyConverter {

    /**
     * The resolutions `modules/dfd_spacer.php:55,77,97,117` ship as defaults,
     * used when the element carries none.
     */
    const DEFAULT_RESOLUTIONS = [
        'screen_wide_resolution'   => '1280',
        'screen_normal_resolution' => '1024',
        'screen_tablet_resolution' => '800',
        'screen_mobile_resolution' => '480',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_spacer_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'units',
            'screen_wide_spacer_size', 'screen_normal_spacer_size',
            'screen_tablet_spacer_size', 'screen_mobile_spacer_size',
            // `dfd_param_heading` params: form section labels, never rendered
            // (`modules/dfd_spacer.php:26-32,44-50,66-72,87-92,107-112`).
            'sizing', 'sizing_wide', 'sizing_normal', 'sizing_tablet', 'sizing_mobile',
            'tutorials',
        ] );

        $heights = RonnebyParams::responsiveSizes( $atts );

        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.show', 'off' );

        foreach ( $heights as $breakpoint => $height ) {
            if ( $height !== '' ) {
                StyleMapper::write( $attrs, "module.decoration.sizing.{$breakpoint}.value.height", $height );
            }
        }

        $this->resolutions( $atts, $id, $heights, $consumed );
        $this->wideSize( $atts, $id );

        $this->engine->logConverted( 'divider' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/divider', $attrs );
    }

    /**
     * Ronneby's wide band (≥1280px) and its normal band (1024-1279px) both sit
     * inside Divi's one desktop breakpoint (≥981px), so only one of the two
     * heights can be kept — the normal one, which is the band Divi's desktop
     * starts in. A page that gave the two different heights loses the wider.
     */
    private function wideSize( array $atts, string $id ): void {
        $wide   = trim( $this->att( $atts, 'screen_wide_spacer_size' ) );
        $normal = trim( $this->att( $atts, 'screen_normal_spacer_size' ) );

        if ( $wide === '' || $normal === '' || $wide === $normal ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'screen_wide_spacer_size="%s" is the height above %spx and screen_normal_spacer_size="%s" the height below it; Divi has one desktop breakpoint, so the %s one was kept',
                $wide,
                $this->att( $atts, 'screen_wide_resolution', self::DEFAULT_RESOLUTIONS['screen_wide_resolution'] ),
                $normal,
                'normal'
            )
        );
    }

    /**
     * Where the spacer changes height. Divi's breakpoints are fixed — phone
     * below 768, tablet to 980, desktop above — and Ronneby's are not, so the
     * widths it switches at are reported rather than approximated.
     *
     * @param string[] $consumed
     */
    private function resolutions( array $atts, string $id, array $heights, array &$consumed ): void {
        $consumed = array_merge( $consumed, array_keys( self::DEFAULT_RESOLUTIONS ) );

        // A spacer whose three heights are the same is the same height at every
        // width, so where it switches is invisible and saying so would put a
        // line in the report for each of the 348 spacers on a page.
        if ( count( array_unique( array_values( $heights ) ) ) < 2 ) {
            return;
        }

        $described = [];
        foreach ( self::DEFAULT_RESOLUTIONS as $key => $default ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                $described[] = $key . '="' . $value . '"';
            }
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'dfd_spacer switches height at its own screen widths (%s); Divi\'s breakpoints are fixed at 768px and 981px, so the three heights were mapped onto those',
                implode( ', ', $described )
            )
        );
    }
}
