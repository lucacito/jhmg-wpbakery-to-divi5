<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `facts` → `divi/number-counter`, with a `divi/text` after it for the subtitle.
 *
 * `modules/dfd-new-facts.php:335-563` counts up to `number` (`data-max`, drawn
 * by odometer.js) and prints `title` and `subtitle` beside it. Divi's number
 * counter is the number and one caption — `number.innerContent`,
 * `title.innerContent` and `number.advanced.enablePercentSign`
 * (`number-counter/module.json`) — so the subtitle becomes its own text block
 * rather than being dropped.
 *
 * Three typography sets, each on the element it styles: `font_options` is the
 * number (line 444, applied to `.facts-number`), `title_font_options` the
 * caption and `subtitle_font_options` the second line.
 *
 * **No default bottom margin**: `.dfd-facts-counter` carries none.
 */
class FactsConverter extends RonnebyConverter {

    /** `content_alignment` ⇒ the module's text orientation. */
    const ALIGNMENTS = [
        'text-left'   => 'left',
        'text-center' => 'center',
        'text-right'  => 'right',
    ];

    /** The icon, the rule and the layout, all the theme's own. */
    const REPORTED_LOOK = [
        'main_layout'    => 'which of the nine layouts places the parts',
        'transition'     => 'how the number counts up',
        'number_margin'  => 'the space above the number',
        'title_margin'   => 'the space above the caption',
        'line_position'  => 'where the rule sits',
        'line_width'     => 'its width',
        'line_border'    => 'its thickness',
        'line_color'     => 'its colour',
        'line_hide'      => 'whether it is drawn at all',
        'main_t_heading' => 'an editor section label',
        'del_t_heading'  => 'an editor section label',
        'content_t_heading' => 'an editor section label',
        'title_t_heading'   => 'an editor section label',
        'subtitle_t_heading' => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_facts_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'counter', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'number', 'title', 'content_alignment', 'number_letter_spacing', 'tutorials' ] );

        StyleMapper::write( $attrs, 'number.innerContent.desktop.value', trim( $this->att( $atts, 'number', '0' ) ) );
        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'title' ) );

        // The module prints the number as it is stored; Divi's own percent sign
        // would add one the source never had.
        StyleMapper::write( $attrs, 'number.advanced.enablePercentSign.desktop.value', 'off' );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'use_content_google_fonts', 'family' => 'content_custom_fonts' ],
            'number.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'title.decoration.font.font',
            $id,
            $attrs,
            $consumed,
            true
        );

        // `margin: 0 <number_letter_spacing>/2 px` on each odometer digit
        // (line 445) is letter spacing by another name.
        $spacing = trim( $this->att( $atts, 'number_letter_spacing' ) );
        if ( $spacing !== '' && is_numeric( $spacing ) ) {
            StyleMapper::write( $attrs, 'number.decoration.font.font.desktop.value.letterSpacing', $spacing . 'px' );
        }

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'content_alignment' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }

        $this->icon( $atts, $id, $consumed );
        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'facts draws its card with the theme\'s own classes', $consumed );

        $blocks = [ $this->block( $id, 'divi/number-counter', $attrs ) ];
        $this->engine->logConverted( 'number-counter' );

        $subtitle = $this->subtitle( $atts, $id, $align, $consumed );
        if ( $subtitle !== null ) {
            $blocks[] = $subtitle;
        }

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * The second caption, which Divi's number counter has no field for.
     *
     * @param string[] $consumed
     */
    private function subtitle( array $atts, string $id, ?string $align, array &$consumed ): ?array {
        $consumed = array_merge( $consumed, [ 'subtitle', 'subtitle_font_options', 'use_subtitle_google_fonts', 'subtitle_custom_fonts' ] );

        $text = trim( $this->att( $atts, 'subtitle' ) );
        if ( $text === '' ) {
            return null;
        }

        $attrs   = [];
        $ignored = [];

        $this->applyFontSet(
            $atts,
            [ 'options' => 'subtitle_font_options', 'toggle' => 'use_subtitle_google_fonts', 'family' => 'subtitle_custom_fonts' ],
            'content.decoration.bodyFont.body.font',
            $id,
            $attrs,
            $ignored
        );

        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'content.decoration.bodyFont.body.font.desktop.value.textAlign', $align );
        }

        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $text );

        $this->engine->logConverted( 'text' );

        return $this->block( $id . '-subtitle', 'divi/text', $attrs );
    }

    /** The icon's own size, colour and opacity, named so the report can be acted on. */
    private function iconStyle( array $atts ): string {
        $described = [];
        foreach ( [ 'icon_size', 'icon_color', 'opacity' ] as $key ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                $described[] = $key . '="' . $value . '"';
            }
        }

        return $described === [] ? '' : ' (' . implode( ', ', $described ) . ')';
    }

    /**
     * `crumina_icon_render()` draws a glyph or a picture beside the number
     * (line 370). Divi's number counter is the number and its caption.
     *
     * @param string[] $consumed
     */
    private function icon( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'icon', 'icon_type', 'icon_image_id', 'icon_size', 'icon_color', 'opacity' ] );

        $icon = trim( $this->att( $atts, 'icon' ) );
        if ( ( $icon === '' || $icon === 'none' ) && trim( $this->att( $atts, 'icon_image_id' ) ) === '' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'facts draws %s beside the number%s; Divi\'s number counter is the number and its caption only — add an icon or image module next to it',
                $icon !== '' && $icon !== 'none' ? 'the icon "' . $icon . '"' : 'the picture ' . $this->att( $atts, 'icon_image_id' ),
                $this->iconStyle( $atts )
            )
        );
    }
}
