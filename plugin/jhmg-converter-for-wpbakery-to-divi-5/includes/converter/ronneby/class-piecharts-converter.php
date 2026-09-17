<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `piecharts` → `divi/circle-counter`, with a `divi/text` after it for the
 * subtitle.
 *
 * `modules/dfd-piecharts.php` draws a ring to `percent` with `number` and
 * `unit` in the middle and `title`/`subtitle` under it. Divi's circle counter is
 * the ring and one caption: `number.innerContent`,
 * `number.advanced.percentSign`, `title.innerContent` and
 * `circle.advanced.color` for the ring, `circle.advanced.background` for the
 * track (`circle-counter/module.json`, `CircleCounterModule.php:521-731` — the
 * paths `PieConverter` settled in Task 9).
 *
 * `number` and `percent` are two different fields in Ronneby: the ring is drawn
 * to `percent` and the figure in the middle is `number`. Divi draws both from
 * one value, so the ring keeps `percent` — the shape the reader sees — and a
 * `number` that says something else is reported.
 *
 * **No default bottom margin**: `.dfd-piecharts` carries none.
 */
class PiechartsConverter extends RonnebyConverter {

    /** The ring's own drawing, which Divi's circle counter does its own way. */
    const REPORTED_LOOK = [
        'main_layout'   => 'which of the four layouts places the parts',
        'size'          => 'the diameter of the ring',
        'fill_width'    => 'the thickness of the ring',
        'animation_off' => 'switching the draw-on animation off',
        'clock_wise'    => 'drawing the ring anticlockwise',
        'icon'          => 'an icon in the middle of the ring',
        'line_width'    => 'the width of the rule under the caption',
        'line_border'   => 'its thickness',
        'line_color'    => 'its colour',
        'line_hide'     => 'whether it is drawn at all',
        'f_s_h'         => 'an editor section label',
        'b_s_h'         => 'an editor section label',
        'del_t_heading' => 'an editor section label',
        'content_t_heading' => 'an editor section label',
        'title_t_heading'   => 'an editor section label',
        'subtitle_t_heading' => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_piechart_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'counter', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'percent', 'number', 'unit', 'title', 'fill_color_start', 'fill_color_end', 'bg_color', 'tutorials' ] );

        $percent = trim( $this->att( $atts, 'percent', '0' ) );
        $number  = trim( $this->att( $atts, 'number' ) );

        StyleMapper::write( $attrs, 'number.innerContent.desktop.value', $percent );
        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'title' ) );

        if ( $number !== '' && $number !== $percent ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'number="%s" is printed in the middle of a ring drawn to percent="%s"; Divi\'s circle counter draws both from one value, and the ring\'s own is what was kept', $number, $percent )
            );
        }

        $this->units( $atts, $id, $attrs, $consumed );
        $this->ring( $atts, $id, $attrs );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'content_google_fonts', 'family' => 'content_custom_fonts' ],
            'number.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'title_google_fonts', 'family' => 'title_custom_fonts' ],
            'title.decoration.font.font',
            $id,
            $attrs,
            $consumed,
            true
        );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'piecharts draws its ring with the theme\'s own script', $consumed );

        $blocks = [ $this->block( $id, 'divi/circle-counter', $attrs ) ];
        $this->engine->logConverted( 'circle-counter' );

        $subtitle = $this->subtitle( $atts, $id, $consumed );
        if ( $subtitle !== null ) {
            $blocks[] = $subtitle;
        }

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * `unit` is printed after the figure. Divi's circle counter prints a
     * percent sign or nothing (`number.advanced.percentSign`), so `%` maps and
     * `€` or `°` — both of which the corpus carries — are reported.
     *
     * @param string[] $consumed
     */
    private function units( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $unit = trim( $this->att( $atts, 'unit' ) );

        StyleMapper::write( $attrs, 'number.advanced.percentSign.desktop.value', $unit === '%' ? 'on' : 'off' );

        if ( $unit !== '' && $unit !== '%' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'unit="%s" is printed after the figure; Divi\'s circle counter prints a percent sign or nothing', $unit )
            );
        }
    }

    /** `fill_color_start` is the ring and `bg_color` the track behind it. */
    private function ring( array $atts, string $id, array &$attrs ): void {
        $fill = $this->color( $atts, 'fill_color_start', $id ) ?? $this->color( $atts, 'fill_color_end', $id );
        if ( $fill !== null ) {
            StyleMapper::write( $attrs, 'circle.advanced.color.desktop.value', $fill );
        }

        $track = $this->color( $atts, 'bg_color', $id );
        if ( $track !== null ) {
            StyleMapper::write( $attrs, 'circle.advanced.background.desktop.value', $track );
        }

        $start = $this->color( $atts, 'fill_color_start', $id );
        $end   = $this->color( $atts, 'fill_color_end', $id );
        if ( $start !== null && $end !== null && $start !== $end ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'the ring is a gradient from %s to %s; Divi\'s circle counter draws it in one colour, and the first stop was kept', $start, $end )
            );
        }
    }

    /**
     * The second caption, which Divi's circle counter has no field for.
     *
     * @param string[] $consumed
     */
    private function subtitle( array $atts, string $id, array &$consumed ): ?array {
        $consumed = array_merge( $consumed, [ 'subtitle', 'subtitle_font_options' ] );

        $text = trim( $this->att( $atts, 'subtitle' ) );
        if ( $text === '' ) {
            return null;
        }

        $attrs   = [];
        $ignored = [];

        $this->applyFontSet( $atts, [ 'options' => 'subtitle_font_options' ], 'content.decoration.bodyFont.body.font', $id, $attrs, $ignored );

        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $text );

        $this->engine->logConverted( 'text' );

        return $this->block( $id . '-subtitle', 'divi/text', $attrs );
    }
}
