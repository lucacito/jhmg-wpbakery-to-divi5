<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `countdown` → `divi/countdown-timer`.
 *
 * `modules/dfd-countdown.php:321-450` counts down to `datetime` and draws the
 * units `countdown_opts` lists (`syear,smonth,sweek,sday,shr,smin,ssec`). Divi's
 * countdown timer takes the same instant — `content.advanced.dateTime`, read
 * through `strtotime()` (`CountdownTimerModule.php:362`) — and always draws
 * days, hours, minutes and seconds, so which units the source picked is
 * reported.
 *
 * The corpus writes `datetime` as `2024/05/19 10:36:19`, which `strtotime()`
 * reads as a date, so the value passes through as it stands.
 *
 * **No default bottom margin**: `.dfd-countdown` carries none.
 */
class CountdownConverter extends RonnebyConverter {

    /** The boxes around the digits, all drawn by the theme's own stylesheet. */
    const REPORTED_LOOK = [
        'main_layout'      => 'which of the four countdown layouts is drawn',
        'radius'           => 'the corner radius of a digit box',
        'line_color'       => 'its border colour',
        'shadow'           => 'a drop shadow on the boxes',
        'delim_color'      => 'the border colour of the separator between them',
        'disable_ividers'  => 'hiding those separators',
        'b_s_h'            => 'an editor section label',
        'nfh'              => 'an editor section label',
        'tut'              => 'an editor section label',
        'content_t_heading' => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_countdown_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'datetime', 'countdown_opts', 'bg_color', 'tutorials' ] );

        $datetime = trim( $this->att( $atts, 'datetime' ) );
        StyleMapper::write( $attrs, 'content.advanced.dateTime.desktop.value', $datetime );

        if ( $datetime === '' ) {
            $this->engine->logWarning( "countdown {$id} has no date to count to; the module was written with an empty one." );
        }

        $background = $this->color( $atts, 'bg_color', $id );
        if ( $background !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background );
        }

        // Divi's countdown timer styles its three parts separately, which is
        // exactly how Ronneby stores them: `number_font_options` is the digits
        // (`dfd-countdown.php:342`), `font_options` the `:` between them
        // (line 335) and `units_font_options` the unit labels (line 337).
        $this->applyFontSet(
            $atts,
            [ 'options' => 'number_font_options', 'toggle' => 'use_number_google_fonts', 'family' => 'custom_number_fonts' ],
            'number.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'use_dividers_google_fonts', 'family' => 'custom_dividers_fonts' ],
            'separator.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyFontSet(
            $atts,
            [ 'options' => 'units_font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'label.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );

        // The label's size and colour are two fields of their own beside the
        // packed value (lines 366-374).
        $consumed[] = 'time_units_font_size';
        $consumed[] = 'time_units_font_color';

        $label_size = $this->pixels( $atts, 'time_units_font_size' );
        if ( $label_size !== '' ) {
            StyleMapper::write( $attrs, 'label.decoration.font.font.desktop.value.size', $label_size );
        }

        $label_color = $this->color( $atts, 'time_units_font_color', $id );
        if ( $label_color !== null ) {
            StyleMapper::write( $attrs, 'label.decoration.font.font.desktop.value.color', $label_color );
        }

        $this->reportUnits( $atts, $id );
        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'countdown draws its digit boxes with the theme\'s own classes', $consumed );

        $this->engine->logConverted( 'countdown-timer' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/countdown-timer', $attrs );
    }

    /**
     * `countdown_opts` is a comma-separated list of the units to draw
     * (`dfd-countdown.php:377-400`). Divi's timer always draws days, hours,
     * minutes and seconds.
     */
    private function reportUnits( array $atts, string $id ): void {
        $units = trim( $this->att( $atts, 'countdown_opts' ) );
        if ( $units === '' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf( 'countdown_opts="%s" chooses which units are drawn; Divi\'s countdown timer always draws days, hours, minutes and seconds', $units )
        );
    }
}
