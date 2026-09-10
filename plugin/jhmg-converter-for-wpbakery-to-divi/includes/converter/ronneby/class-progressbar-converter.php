<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `progressbar` → `divi/counters` › one `divi/counter`.
 *
 * `modules/dfd-progress-bar.php` is one bar: a `title`, a `percent`, a fill
 * whose colour is `fill_color_start` (with `fill_color_end` making it a
 * gradient) and a track whose colour is `bg_color`. Divi's bar counter is the
 * same three things — `title.innerContent`, `barProgress.innerContent` and
 * `barProgress.decoration.background`, with the track on
 * `barCounter.decoration.background` (`counter/module.json`, the paths
 * `ProgressBarConverter` settled in Task 9) — inside a `divi/counters`, which
 * is the module the page holds.
 *
 * One bar per shortcode, so one `divi/counters` per shortcode: Ronneby's demo
 * pages stack several `[progressbar]` in a column rather than listing them in
 * one element (103 across the corpus, none with more than one bar).
 *
 * `divi/counters` has no `number` element, so this handler never asks the
 * StyleMapper for the `counter` font kind: the two font paths are
 * `title.decoration.font.font` and `barProgress.decoration.font.font`
 * (amendment §4).
 *
 * **No default bottom margin**: `.dfd-progressbar` carries none.
 */
class ProgressbarConverter extends RonnebyConverter {

    /** The bar's frame and animation, drawn by the theme's own stylesheet. */
    const REPORTED_LOOK = [
        'main_layout'      => 'which of the four bar layouts is drawn',
        'text_position'    => 'whether the label sits above or below the bar',
        'animate_progress' => 'the fill animating to its width',
        'animate_lines'    => 'the moving stripes over the fill',
        'height'           => 'the bar\'s height',
        'line_border'      => 'the track\'s border width',
        'line_color'       => 'its colour',
        'delim_color'      => 'the colour of the marks along the bar',
        'f_s_h'            => 'an editor section label',
        'b_s_h'            => 'an editor section label',
        'nfh'              => 'an editor section label',
        'content_t_heading' => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_progressbar_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'title', 'percent', 'bg_color', 'fill_color_start', 'fill_color_end', 'tutorials' ] );

        // Divi prints the percent sign itself; Ronneby's own label is `title`.
        StyleMapper::write( $attrs, 'barProgress.advanced.usePercentages.desktop.value', 'off' );

        $bar = [];
        StyleMapper::write( $bar, 'title.innerContent.desktop.value', $this->att( $atts, 'title' ) );
        StyleMapper::write( $bar, 'barProgress.innerContent.desktop.value', $this->percent( $atts ) );

        $this->fill( $atts, $id, $bar );

        $track = $this->color( $atts, 'bg_color', $id );
        if ( $track !== null ) {
            StyleMapper::write( $attrs, 'barCounter.decoration.background.desktop.value.color', $track );
        }

        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'title.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyFontSet(
            $atts,
            [ 'options' => 'number_font_options', 'toggle' => 'use_number_google_fonts', 'family' => 'number_custom_fonts' ],
            'barProgress.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'progressbar draws its bar with the theme\'s own classes', $consumed );

        $this->engine->logConverted( 'counters' );
        $this->engine->logConverted( 'counter' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/counters', $attrs, [ $this->block( $id . '-bar', 'divi/counter', $bar ) ] );
    }

    /** `percent` is the width of the fill; Divi draws a percentage. */
    private function percent( array $atts ): string {
        $value = trim( $this->att( $atts, 'percent', '0' ) );

        if ( ! is_numeric( $value ) ) {
            return '0';
        }

        $number = max( 0.0, min( 100.0, (float) $value ) );

        return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
    }

    /**
     * `fill_color_start` alone is a flat fill; with `fill_color_end` the module
     * paints `linear-gradient(to right, start, end)`.
     */
    private function fill( array $atts, string $id, array &$bar ): void {
        $start = $this->color( $atts, 'fill_color_start', $id );
        $end   = $this->color( $atts, 'fill_color_end', $id );

        if ( $start === null && $end === null ) {
            return;
        }

        if ( $start === null || $end === null || $start === $end ) {
            StyleMapper::write( $bar, 'barProgress.decoration.background.desktop.value.color', $start ?? $end );

            return;
        }

        // Divi appends the unit to a stop position itself (CLAUDE.md).
        StyleMapper::write( $bar, 'barProgress.decoration.background.desktop.value', [
            'color'    => $start,
            'gradient' => [
                'enabled'   => 'on',
                'type'      => 'linear',
                'direction' => 'to right',
                'stops'     => [
                    [ 'color' => $start, 'position' => '0' ],
                    [ 'color' => $end, 'position' => '100' ],
                ],
            ],
        ] );
    }
}
