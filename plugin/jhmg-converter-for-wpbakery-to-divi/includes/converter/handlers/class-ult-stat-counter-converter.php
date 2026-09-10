<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\UltimateFields;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `stat_counter` (Ultimate Addons) → `divi/number-counter`, registered
 * approximate.
 *
 * `modules/ultimate_stats_counter.php` counts up to `counter_value` and prints
 * `counter_prefix`, the number and `counter_suffix` with `counter_title` beside
 * it — which is Divi's number counter: `number.innerContent.desktop.value` and
 * `title.innerContent.desktop.value` (`number-counter/module.json`,
 * `NumberCounterModule::render_callback()`).
 *
 * Divi's number counter takes the prefix and suffix inside the number string
 * itself (its `number` is printed verbatim, with an optional percent sign from
 * `number.advanced.enablePercentSign`), so `counter_prefix`/`counter_suffix`
 * are concatenated the way the add-on prints them. What has no Divi field is
 * the icon beside the number, the thousands separator and the count `speed`,
 * and those are reported.
 */
class UltStatCounterConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_counter_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // `assets/css/stats-counter.css`: `.stats-block,
        // .wpb_row .wpb_column .wpb_wrapper .stats-block{display:block;margin-bottom:35px}`
        // — the add-on's own wrapper (`ultimate_stats_counter.php:737`).
        $style    = $this->mapStyle( 'counter', $this->withCss( $node, 'css_stat_counter' ), 'content' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'css_stat_counter', 'counter_title', 'counter_value', 'counter_prefix', 'counter_suffix',
            'counter_sep', 'counter_decimal', 'speed', 'counter_color_txt',
            'icon_type', 'icon', 'icon_img', 'img_width', 'icon_size', 'icon_color', 'icon_style',
            'icon_color_bg', 'icon_color_border', 'icon_border_style', 'icon_border_size',
            'icon_border_radius', 'icon_border_spacing', 'icon_animation', 'icon_position',
        ] );

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'counter_title' ) );
        StyleMapper::write( $attrs, 'number.innerContent.desktop.value', $this->number( $atts ) );

        // The add-on prints its suffix itself, so Divi's own percent sign stays off.
        StyleMapper::write( $attrs, 'number.advanced.enablePercentSign.desktop.value', 'off' );

        $this->fonts( $atts, $id, $attrs, $consumed );
        $this->report( $atts, $id );

        $this->engine->logConverted( 'number-counter' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/number-counter', $attrs );
    }

    /** `counter_prefix . counter_value . counter_suffix`, as the module prints it. */
    private function number( array $atts ): string {
        return trim( $this->att( $atts, 'counter_prefix' ) )
            . trim( $this->att( $atts, 'counter_value', '0' ) )
            . trim( $this->att( $atts, 'counter_suffix' ) );
    }

    /**
     * The add-on's typography fields: `title_font_*` for the caption,
     * `desc_font_*` for the number itself (its own naming), and
     * `counter_color_txt` for the number's colour.
     *
     * @param string[] $consumed
     */
    private function fonts( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $map = [
            'title'    => 'title.decoration.font.font.desktop.value',
            'desc'     => 'number.decoration.font.font.desktop.value',
            'suf_pref' => 'number.decoration.font.font.desktop.value',
        ];

        foreach ( $map as $prefix => $path ) {
            foreach ( array_keys( $atts ) as $key ) {
                if ( is_string( $key ) && ( str_starts_with( $key, $prefix . '_font' ) || str_starts_with( $key, $prefix . '_line' ) || str_starts_with( $key, $prefix . '_typography' ) ) ) {
                    $consumed[] = $key;
                }
            }
            $consumed[] = $prefix . '_text_typography';

            // `suf_pref` shares Divi's one number font, so only the caption and
            // the number carry their own size and line height.
            if ( $prefix === 'suf_pref' ) {
                $this->reportFontStyle( $atts, $id, $prefix );

                continue;
            }

            $size = UltimateFields::responsive( $this->att( $atts, $prefix . '_font_size' ) );
            if ( $size !== '' ) {
                StyleMapper::write( $attrs, $path . '.size', $size );
            }

            $line = UltimateFields::responsive( $this->att( $atts, $prefix . '_font_line_height' ) );
            if ( $line !== '' ) {
                StyleMapper::write( $attrs, $path . '.lineHeight', $line );
            }

            $color = $this->color( $atts, $prefix . '_font_color', $id );
            if ( $color !== null ) {
                StyleMapper::write( $attrs, $path . '.color', $color );
            }

            // `<prefix>_font` is the add-on's font-family field.
            $family = trim( $this->att( $atts, $prefix . '_font' ) );
            if ( $family !== '' ) {
                StyleMapper::write( $attrs, $path . '.family', $family );
            }

            if ( str_contains( strtolower( $this->att( $atts, $prefix . '_font_style' ) ), 'bold' ) ) {
                StyleMapper::write( $attrs, $path . '.weight', '700' );
            }

            $this->reportFontStyle( $atts, $id, $prefix );
        }

        // `counter_color_txt` is the caption colour in the add-on's own form.
        $caption = $this->color( $atts, 'counter_color_txt', $id );
        if ( $caption !== null ) {
            StyleMapper::write( $attrs, 'title.decoration.font.font.desktop.value.color', $caption );
        }
    }

    /**
     * The add-on stores its typography as a CSS fragment
     * (`font-weight:bold;font-style:italic;`). Only the weight has a single
     * Divi field, so anything else in it is reported rather than half-applied.
     */
    private function reportFontStyle( array $atts, string $id, string $prefix ): void {
        $font_style = trim( $this->att( $atts, $prefix . '_font_style' ) );

        if ( $font_style === '' || preg_match( '/^font-weight:\s*(bold|normal);?$/i', $font_style ) === 1 ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'addon',
            $id,
            sprintf( 'stat_counter %s_font_style="%s" is a CSS fragment; only its font weight has a Divi field', $prefix, $font_style )
        );
    }

    private function report( array $atts, string $id ): void {
        if ( $this->att( $atts, 'icon', 'none' ) !== 'none' || $this->att( $atts, 'icon_img' ) !== '' ) {
            $this->engine->logNotCarriedOver(
                'addon',
                $id,
                'stat_counter draws an icon beside the number; Divi\'s number counter is the number and its caption only — add an icon module next to it'
            );
        }

        $described = [];
        foreach ( [ 'speed' => 'how long the count-up takes', 'counter_sep' => 'the thousands separator', 'counter_decimal' => 'the decimal separator' ] as $key => $what ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                $described[] = $key . '="' . $value . '" (' . $what . ')';
            }
        }

        if ( $described !== [] ) {
            $this->engine->logNotCarriedOver(
                'addon',
                $id,
                'stat_counter ' . implode( ', ', $described ) . '; Divi\'s number counter animates at its own pace and prints the number as it is stored'
            );
        }
    }
}
