<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_separator` → `divi/divider`.
 *
 * `include/templates/shortcodes/vc_separator.php` hands the whole element to
 * `vc_text_separator` with `layout="separator_no_text"`, so the line is that
 * template's `.vc_sep_line`: a `border-top` whose colour is `accent_color`
 * (default `#ebebeb`), whose style is the `style` dropdown, and whose width is
 * `.vc_sep_border_width_<n>`. `el_width` is a percentage on the wrapper and
 * `align` is `margin-left/right: auto` (`.vc_sep_pos_align_*`).
 *
 * Divi's divider is the same line: `divider.advanced.line.{color,style,weight}`
 * and `module.decoration.sizing.{width,alignment}` (`divider/module.json`,
 * `StyleLibrary/Declarations/Sizing`).
 */
class SeparatorConverter extends BaseWPBakeryConverter {

    /** `VcSharedLibrary::$sep_styles`, and what each draws in the stylesheet. */
    const STYLES = [
        ''       => 'solid',
        'dashed' => 'dashed',
        'dotted' => 'dotted',
        'double' => 'double',
    ];

    /** `.vc_separator .vc_sep_holder .vc_sep_line{border-top:1px solid #ebebeb}`. */
    const DEFAULT_COLOR = '#ebebeb';

    /** `.vc_sep_pos_align_<align>` → Divi's sizing alignment. */
    const ALIGNMENTS = [
        'align_left'   => 'left',
        'align_center' => 'center',
        'align_right'  => 'right',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_divider_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = $style['handled_keys'];

        $this->line( $atts, $id, $attrs, $consumed );
        $this->geometry( $atts, $attrs, $consumed );

        $this->engine->logConverted( 'divider' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/divider', $attrs );
    }

    /**
     * The line itself. Public so `TextSeparatorConverter` — which is the same
     * element with a heading in the middle — draws it the same way.
     *
     * @param string[] $consumed
     */
    public function line( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'color';
        $consumed[] = 'accent_color';
        $consumed[] = 'style';
        $consumed[] = 'border_width';

        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.show', 'on' );

        $color = $this->color( $atts, 'accent_color', $id ) ?? self::DEFAULT_COLOR;
        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.color', $color );

        $style = strtolower( $this->att( $atts, 'style' ) );
        if ( isset( self::STYLES[ $style ] ) ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.style', self::STYLES[ $style ] );
        } else {
            // `shadow` is a pair of blurred pseudo-elements, not a line.
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.style', 'solid' );
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'separator style="%s" is drawn with a blurred pseudo-element; Divi draws a solid line instead', $style )
            );
        }

        $width = trim( $this->att( $atts, 'border_width', '1' ) );
        if ( preg_match( '/^\d+$/', str_replace( 'px', '', $width ) ) === 1 ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.weight', str_replace( 'px', '', $width ) . 'px' );
        }
    }

    /**
     * `el_width` (a percentage) and `align`.
     *
     * @param string[] $consumed
     */
    public function geometry( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'el_width';
        $consumed[] = 'align';

        $align = strtolower( $this->att( $atts, 'align', 'align_center' ) );
        $this->elWidth( $attrs, $this->att( $atts, 'el_width' ), self::ALIGNMENTS[ $align ] ?? null );
    }
}
