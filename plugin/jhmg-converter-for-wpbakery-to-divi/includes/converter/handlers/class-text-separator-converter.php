<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_text_separator` → `divi/heading` (h4) above `divi/divider`. Approximate,
 * and registered as such.
 *
 * WPBakery draws one flex row: a line, the title as an `<h4>`, another line
 * (`include/templates/shortcodes/vc_text_separator.php`), so the rule sits
 * *through* the text. Divi has no module that does that, and drawing it with
 * custom CSS would be a second box model to maintain — so the title becomes a
 * heading and the rule a divider under it, and the difference is reported.
 *
 * `title_align` decides which of the two lines is drawn
 * (`.vc_separator_align_left` hides the left one), which is why a left- or
 * right-aligned title also aligns the heading.
 */
class TextSeparatorConverter extends BaseWPBakeryConverter {

    /** `.vc_<title_align>` → the heading's text alignment. */
    const TITLE_ALIGNMENTS = [
        'separator_align_left'   => 'left',
        'separator_align_center' => 'center',
        'separator_align_right'  => 'right',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_text_separator_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = $style['handled_keys'];

        $separator = new SeparatorConverter( $this->engine );
        $separator->line( $atts, $id, $attrs, $consumed );
        $separator->geometry( $atts, $attrs, $consumed );

        $consumed[] = 'title';
        $consumed[] = 'title_align';
        $consumed[] = 'layout';

        $this->icon( $atts, $id, $consumed );

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'vc_text_separator draws its rule through the title; Divi puts the title above a full-width divider'
        );

        $blocks = [];
        $title  = trim( $this->att( $atts, 'title' ) );

        if ( $title !== '' && $this->att( $atts, 'layout', 'separator_with_text' ) !== 'separator_no_text' ) {
            $heading = $this->headingBlock( $id . '-title', $title, 'h4' );

            $align = strtolower( $this->att( $atts, 'title_align', 'separator_align_center' ) );
            if ( isset( self::TITLE_ALIGNMENTS[ $align ] ) ) {
                StyleMapper::write(
                    $heading['settings'],
                    'title.decoration.font.font.desktop.value.textAlign',
                    self::TITLE_ALIGNMENTS[ $align ]
                );
            }

            $this->engine->logConverted( 'heading' );
            $blocks[] = $heading;
        }

        $this->engine->logConverted( 'divider' );
        $blocks[] = $this->block( $id, 'divi/divider', $attrs );

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * `add_icon` puts a `vc_icon` in the middle of the rule. There is nowhere in
     * a heading-plus-divider pair to put it, so it is reported rather than
     * dropped in silence.
     *
     * @param string[] $consumed
     */
    private function icon( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, [
            'add_icon',
            'i_type',
            'i_color',
            'i_custom_color',
            'i_background_style',
            'i_background_color',
            'i_custom_background_color',
            'i_size',
            'i_css_animation',
        ] );

        $library    = $this->att( $atts, 'i_type', 'fontawesome' );
        // Filled by the picker whether or not `add_icon` is on.
        $consumed[] = 'i_icon_' . $library;

        if ( ! $this->on( $atts, 'add_icon' ) ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'vc_text_separator add_icon draws %s in the middle of the rule; the heading and divider have nowhere to put it',
                $this->att( $atts, 'i_icon_' . $library, $library )
            )
        );
    }
}
