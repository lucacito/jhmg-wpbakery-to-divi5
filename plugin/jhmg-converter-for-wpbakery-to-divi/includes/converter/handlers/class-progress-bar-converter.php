<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_progress_bar` → `divi/counters` › one `divi/counter` per `values` entry.
 *
 * `include/templates/shortcodes/vc_progress_bar.php` walks the `values` param
 * group and prints a `.vc_single_bar` for each: a label, an optional value with
 * its units, and a bar whose width is the value. Divi's bar counter is the same
 * three things — `title.innerContent`, `barProgress.innerContent` (the percent)
 * and `barProgress.decoration.background` (the fill) on `counter/module.json`,
 * with the track on `barCounter.decoration.background`.
 *
 * Two per-element fallbacks apply to every bar that sets none of its own:
 * `custombgcolor` is the fill and `customtxtcolor` the label colour — after the
 * 9.0 normaliser both arrive as hex values, and the per-bar `customcolor` /
 * `customtxtcolor` inside the group override them.
 *
 * `divi/counters` has no `number` element (amendment §1), so this handler never
 * asks the StyleMapper for the `counter` font kind; the two fonts it writes are
 * `title.decoration.font.font` and `barProgress.decoration.font.font`, both
 * read from `counter/module.json`.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class ProgressBarConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_bars_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'values', 'units', 'title',
            'custombgcolor', 'customtxtcolor', 'add_text_shadow', 'text_shadow_color',
        ] );

        // Divi prints the percent sign itself; WPBakery only prints a value at
        // all when `units` is set, and then in the label.
        StyleMapper::write( $attrs, 'barProgress.advanced.usePercentages.desktop.value', 'off' );

        $label_color = $this->color( $atts, 'customtxtcolor', $id );
        if ( $label_color !== null ) {
            StyleMapper::write( $attrs, 'title.decoration.font.font.desktop.value.color', $label_color );
            StyleMapper::write( $attrs, 'barProgress.decoration.font.font.desktop.value.color', $label_color );
        }

        $bars = $this->bars( $atts, $id );

        $this->options( $atts, $id, $consumed );

        $this->engine->logConverted( 'counters' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        if ( $bars === [] ) {
            $this->engine->logWarning( "vc_progress_bar {$id} holds no bars; nothing was written for it." );

            return [];
        }

        $blocks = [ $this->block( $id, 'divi/counters', $attrs, $bars ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /** @return array<int, array<string,mixed>> */
    private function bars( array $atts, string $id ): array {
        $units    = trim( $this->att( $atts, 'units' ) );
        $fallback = $this->color( $atts, 'custombgcolor', $id );

        $bars  = [];
        $index = 0;

        foreach ( PackedParams::paramGroup( $this->att( $atts, 'values' ) ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $index++;
            $bar_id   = $id . '-bar-' . $index;
            $settings = [];

            // WPBakery prints "<label> <value><units>" in one `<small>`; Divi's
            // title is the label and its own percent is printed beside it, so
            // the units go on the label only when WPBakery printed them.
            $label = (string) ( $entry['label'] ?? '' );
            $value = trim( (string) ( $entry['value'] ?? '0' ) );
            if ( $units !== '' ) {
                $label = trim( $label . ' ' . $value . $units );
            }

            StyleMapper::write( $settings, 'title.innerContent.desktop.value', $label );
            StyleMapper::write( $settings, 'barProgress.innerContent.desktop.value', $this->percent( $value ) );

            $fill = Color::normalize( $entry['customcolor'] ?? '' ) ?? $fallback;
            if ( $fill !== null ) {
                StyleMapper::write( $settings, 'barProgress.decoration.background.desktop.value.color', $fill );
            }

            $text = Color::normalize( $entry['customtxtcolor'] ?? '' );
            if ( $text !== null ) {
                StyleMapper::write( $settings, 'title.decoration.font.font.desktop.value.color', $text );
                StyleMapper::write( $settings, 'barProgress.decoration.font.font.desktop.value.color', $text );
            }

            if ( ! empty( $entry['add_text_shadow'] ) && ! empty( $entry['text_shadow_color'] ) ) {
                $this->engine->logNotCarriedOver(
                    'layout',
                    $bar_id,
                    'the bar label carries WPBakery\'s 0 -1px 0 text shadow; set it on the Divi counter\'s title text shadow'
                );
            }

            $this->engine->logConverted( 'counter' );
            $bars[] = $this->block( $bar_id, 'divi/counter', $settings );
        }

        return $bars;
    }

    /**
     * `data-percentage-value` — the width of the bar. WPBakery treats the value
     * as a percentage until one of them passes 100, at which point every bar is
     * scaled against the largest; Divi only draws a percentage, so a value above
     * 100 is clamped and the element's own scale is reported by the caller.
     */
    private function percent( string $value ): string {
        if ( ! is_numeric( $value ) ) {
            return '0';
        }

        $number = max( 0.0, min( 100.0, (float) $value ) );

        return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
    }

    /**
     * `striped` and `animated` are the two bar decorations
     * (`.vc_bar.striped`, `.vc_bar.animated`); Divi's counter draws a flat bar
     * and animates its width once on scroll.
     *
     * @param string[] $consumed
     */
    private function options( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'striped', 'animated', 'options' ] );

        $described = [];
        foreach ( [ 'striped', 'animated' ] as $key ) {
            if ( $this->on( $atts, $key ) ) {
                $described[] = $key;
            }
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'vc_progress_bar ' . implode( ' and ', $described ) . ' paints the diagonal stripe overlay and keeps it moving; Divi\'s counter draws a flat bar'
        );
    }
}
