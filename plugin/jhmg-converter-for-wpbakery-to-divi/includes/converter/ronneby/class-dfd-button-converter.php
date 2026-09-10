<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_button` → `divi/button`.
 *
 * `modules/dfd_button.php:671-992` builds an `<a class="dfd-button-link">` and
 * then writes the whole of its appearance as a stylesheet the module appends at
 * run time: the label's typography, the padding, the text and background
 * colours, the border and the box shadow, each with a `:hover` twin. Divi's
 * button module holds the resting half of all of it —
 * `button.innerContent`, `button.decoration.{font,spacing,background,border,boxShadow,sizing,button}`
 * (`button/module.json`) — and paints its hover state from its own settings, so
 * every `hover_*` value is reported rather than invented, which is the rule
 * `BtnConverter::interaction()` follows for `vc_btn`.
 *
 * The six `style_N` presets are the module's real subject: each is a different
 * hover choreography drawn with `:before`/`:after` layers
 * (`visual-composer.css`), and none of them has a Divi equivalent.
 *
 * **No default bottom margin**: `.dfd-button-module-wrap` carries none — the
 * only `margin-bottom` under that selector in `visual-composer.css` is on the
 * tooltip (`.dfd-button-tooltip.dfd-button-tooltip-top{margin-bottom:20px}`).
 * This is where the brief's `button` kind is corrected: WPBakery's 22 px comes
 * from `.vc_do_btn`, a class this element never carries.
 */
class DfdButtonConverter extends RonnebyConverter {

    /** `alignment` (`modules/dfd_button.php` `'value' => 'text-center'`). */
    const ALIGNMENTS = [
        'text-left'   => 'left',
        'text-center' => 'center',
        'text-right'  => 'right',
    ];

    /** The look attributes the module draws with its own classes and stylesheet. */
    const REPORTED_LOOK = [
        'style'                              => 'which of the six hover choreographies the button plays',
        'main_style'                         => 'the style preset of an older Ronneby build',
        'direction'                          => 'the direction the hover layer travels',
        'direction_slide'                    => 'the direction the hover layer slides from',
        'direction_tilt'                     => 'the corner the hover layer tilts from',
        'direction_width'                    => 'how far across the hover layer travels',
        'direction_effect'                   => 'the easing of the hover layer',
        'click_animation'                    => 'the animation played on click',
        'mobile_center'                      => 'centring the button on small screens',
        'alignment_resolution'               => 'the width that centring applies below',
        'subborder_decoration'                => 'a second offset border drawn behind the button',
        'subborder_decoration_width'          => 'the width of that border',
        'subborder_decoration_color'          => 'its colour',
        'subborder_decoration_hover_color'    => 'its hover colour',
        'subborder_decoration_x_offset'       => 'how far right it sits',
        'subborder_decoration_y_offset'       => 'how far down it sits',
        'subborder_decoration_hover_x_offset' => 'where it moves to on hover',
        'subborder_decoration_hover_y_offset' => 'where it moves to on hover',
        'tooltip_text'                        => 'a tooltip beside the button',
        'tooltip_alignment'                   => 'which side that tooltip sits on',
        'tooltip_color'                       => 'the tooltip text colour',
        'tooltip_background'                  => 'the tooltip background',
        'icon_size'                           => 'the size of the icon beside the label',
        'icon_hover_action'                   => 'how the icon behaves on hover',
        'hover_style_heading'                 => 'an editor section label',
    ];

    /** Every colour and shadow the module paints only while the cursor is over the button. */
    const REPORTED_HOVER = [
        'hover_text_color'  => 'the label colour',
        'hover_background'  => 'the background',
        'hover_border'      => 'the border',
        'hover_box_shadow'  => 'the shadow',
        'icon_hover'        => 'the icon colour',
        'hover_ic_color'    => 'the icon colour of an older Ronneby build',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_button_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'button', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'tutorials' ] );

        $this->text( $atts, $id, $attrs, $consumed );
        $this->typography( $atts, $id, $attrs, $consumed );
        $this->colours( $atts, $id, $attrs, $consumed );
        $this->frame( $atts, $attrs, $consumed );
        $this->padding( $atts, $attrs, $consumed );
        $this->icon( $atts, $id, $attrs, $consumed );
        $this->alignment( $atts, $attrs, $consumed );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'dfd_button draws itself with the theme\'s own classes', $consumed );
        $this->reportGroup( $atts, self::REPORTED_HOVER, 'hover', $id, 'dfd_button repaints itself under the cursor; set these on the Divi button\'s hover state', $consumed );

        $this->engine->logConverted( 'button' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/button', $attrs );
    }

    /**
     * `button_text` and `buttom_link_src` — the module's own spelling of the
     * link field (`dfd_button.php:915-933`, a `vc_link`).
     *
     * @param string[] $consumed
     */
    private function text( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'button_text';
        $consumed[] = 'buttom_link_src';

        StyleMapper::write( $attrs, 'button.innerContent.desktop.value.text', $this->att( $atts, 'button_text' ) );

        $link = $this->link( $atts, 'buttom_link_src' );
        if ( $link === [] ) {
            return;
        }

        $this->reportLinkExtras( $atts, 'buttom_link_src', $id, true );

        StyleMapper::write( $attrs, 'button.innerContent.desktop.value.linkUrl', $link['url'] );
        StyleMapper::write( $attrs, 'button.innerContent.desktop.value.linkTarget', ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off' );

        if ( ! empty( $link['rel'] ) ) {
            StyleMapper::write( $attrs, 'button.innerContent.desktop.value.rel', $link['rel'] );
        }
    }

    /** @param string[] $consumed */
    private function typography( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'title_google_fonts', 'family' => 'title_custom_fonts' ],
            'button.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
    }

    /**
     * `text_color` and `background`, both written into the module's own
     * stylesheet (lines 777-784).
     *
     * @param string[] $consumed
     */
    private function colours( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'text_color';
        $consumed[] = 'background';

        $text = $this->color( $atts, 'text_color', $id );
        if ( $text !== null ) {
            StyleMapper::write( $attrs, 'button.decoration.font.font.desktop.value.color', $text );
        }

        $background = $this->color( $atts, 'background', $id );
        if ( $background !== null ) {
            StyleMapper::write( $attrs, 'button.decoration.background.desktop.value.color', $background );
        }
    }

    /**
     * `border` (a `Dfd_Border` declaration list, line 787) and `box_shadow`
     * (a `Dfd_Box_Shadow_Param` value, line 841).
     *
     * @param string[] $consumed
     */
    private function frame( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'border';
        $consumed[] = 'box_shadow';

        $border = RonnebyParams::declarations( $this->att( $atts, 'border' ) );

        $width = trim( $border['border-width'] ?? '' );
        $style = strtolower( trim( $border['border-style'] ?? '' ) );
        $color = Color::normalize( $border['border-color'] ?? '' );

        if ( $width !== '' || $style !== '' || $color !== null ) {
            StyleMapper::write( $attrs, 'button.decoration.border.desktop.value.styles.all', array_filter( [
                'width' => $width,
                'style' => $style,
                'color' => $color ?? '',
            ], static fn( string $value ): bool => $value !== '' ) );
        }

        $radius = trim( $border['border-radius'] ?? '' );
        if ( $radius !== '' ) {
            StyleMapper::write( $attrs, 'button.decoration.border.desktop.value.radius', self::radius( $radius ) );
        }

        $shadow = RonnebyParams::boxShadow( $this->att( $atts, 'box_shadow' ) );
        if ( $shadow !== null ) {
            StyleMapper::write( $attrs, 'button.decoration.boxShadow.desktop.value', $shadow );
        }
    }

    /**
     * `padding_left` / `padding_right`, which the module puts on the label
     * rather than the anchor (lines 756-768) — the same box either way, because
     * the anchor has none of its own.
     *
     * @param string[] $consumed
     */
    private function padding( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'padding_left';
        $consumed[] = 'padding_right';
        $consumed[] = 'button_size';

        $left  = $this->pixels( $atts, 'padding_left' );
        $right = $this->pixels( $atts, 'padding_right' );

        if ( $left !== '' || $right !== '' ) {
            StyleMapper::write( $attrs, 'button.decoration.spacing.desktop.value.padding', self::box( '', $right, '', $left ) );
        }

        // `.dfd-button-module.dfd-button-full-width .dfd-button-link{display:block}`.
        if ( trim( $this->att( $atts, 'button_size' ) ) === 'dfd-button-full-width' ) {
            StyleMapper::write( $attrs, 'button.decoration.sizing.desktop.value.width', '100%' );
        }
    }

    /**
     * `icon_font` + `icon_<library>` (lines 742-750). `select_icon`,
     * `ic_dfd_icons` and `ic_color` are the same three fields under an older
     * build's names, and the corpus carries them.
     *
     * @param string[] $consumed
     */
    private function icon( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $picked     = $this->pickedIcon( $atts, $consumed );
        $consumed   = array_merge( $consumed, [ 'icon_align', 'icon_color', 'select_icon', 'ic_dfd_icons', 'ic_color' ] );
        $class      = $picked['class'];

        if ( $class === '' && trim( $this->att( $atts, 'select_icon' ) ) !== '' ) {
            // The older build stored the library in `select_icon` and the class
            // in `ic_<library>`.
            $class = trim( $this->att( $atts, 'ic_' . $this->att( $atts, 'select_icon' ) ) );
        }

        if ( $class === '' ) {
            return;
        }

        StyleMapper::write( $attrs, 'button.decoration.button.desktop.value.icon', [
            'enable'   => 'on',
            'settings' => $this->iconValue( $class, $id, 'the button icon' ),
        ] );

        // `if($icon_align == 'dfd-button-icon-right' || …-bottom) { $button_text = $button_text . $icon_html; }`
        $align = trim( $this->att( $atts, 'icon_align' ) );
        StyleMapper::write(
            $attrs,
            'button.decoration.button.desktop.value.icon.placement',
            in_array( $align, [ 'dfd-button-icon-right', 'dfd-button-icon-bottom' ], true ) ? 'right' : 'left'
        );

        // Divi's button icon takes the button's own font colour.
        $icon_color = $this->color( $atts, 'icon_color', $id ) ?? $this->color( $atts, 'ic_color', $id );
        if ( $icon_color !== null && $icon_color !== $this->read( $attrs, 'button.decoration.font.font.desktop.value.color' ) ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'icon_color="%s" paints the icon separately from the label; Divi\'s button icon takes the button\'s own text colour', $icon_color )
            );
        }
    }

    /** @param string[] $consumed */
    private function alignment( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'alignment';

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'alignment', 'text-center' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.alignment.desktop.value', $align );
        }
    }
}
