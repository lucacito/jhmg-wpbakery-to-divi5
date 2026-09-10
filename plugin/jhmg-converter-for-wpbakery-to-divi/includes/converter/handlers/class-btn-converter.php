<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\IconMap;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_btn` → `divi/button`. Every pre-9.0 button form (`vc_button`,
 * `vc_button2`) arrives here as a `vc_btn` with colorpicker attributes, because
 * `AttributeNormaliser` runs WPBakery's own migration first.
 *
 * The button's look is four independent things in
 * `include/templates/shortcodes/vc_btn.php` and `js_composer.min.css`: a
 * **size** (font size + padding), a **shape** (border radius), a **style**
 * (which of the custom colours are painted where), and an optional **icon**.
 * Divi's button module has all four — `button.decoration.font.font`,
 * `button.decoration.border`, `button.decoration.background` /
 * `.border.styles.all.color`, and `button.decoration.button.…icon`.
 *
 * `align="inline"` (a 7.8 dropdown value the CSS still honours as
 * `.vc_btn3-container.vc_btn3-inline{display:inline-block}`) only flags the
 * block; the `ColumnConverter` gathers a run of flagged blocks into one nested
 * flexed row (amendment §3).
 */
class BtnConverter extends BaseWPBakeryConverter {

    /**
     * `.vc_btn3-size-<size>` — font size and padding — from
     * `assets/css/js_composer.min.css`. `outline` adds a 2px border and takes
     * 1px off each padding side to keep the same box; that adjustment is in
     * `OUTLINE_PADDING`.
     */
    const SIZES = [
        'xs' => [ 'font' => '11px', 'padding' => '8px 12px' ],
        'sm' => [ 'font' => '12px', 'padding' => '11px 16px' ],
        'md' => [ 'font' => '14px', 'padding' => '14px 20px' ],
        'lg' => [ 'font' => '16px', 'padding' => '18px 25px' ],
    ];

    /** `.vc_btn3-size-<size>.vc_btn3-style-outline{padding:…}` — one pixel less each way. */
    const OUTLINE_PADDING = [
        'xs' => '7px 11px',
        'sm' => '10px 15px',
        'md' => '13px 19px',
        'lg' => '17px 24px',
    ];

    /** `.vc_btn3-shape-<shape>{border-radius:…}`. */
    const SHAPES = [
        'rounded' => '5px',
        'square'  => '0px',
        'round'   => '2em',
    ];

    /** The styles that paint no background of their own (`$style` branches in the template). */
    const OUTLINE_STYLES = [ 'outline', 'outline-custom' ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_button_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'button', $node, 'button' );
        $attrs    = $style['divi_attrs'];
        $consumed = $style['handled_keys'];

        $this->text( $atts, $id, $attrs, $consumed );
        $this->size( $atts, $attrs, $consumed );
        $this->shape( $atts, $attrs, $consumed );
        $this->colours( $atts, $id, $attrs, $consumed );
        $this->icon( $atts, $id, $attrs, $consumed );
        $inline = $this->alignment( $atts, $attrs, $consumed );
        $this->width( $atts, $attrs, $consumed );
        $this->interaction( $atts, $id, $consumed );

        $this->engine->logConverted( 'button' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        $block = $this->block( $id, 'divi/button', $attrs );

        if ( $inline ) {
            // Read and cleared by `ColumnConverter::groupInline()`.
            $block['inline'] = true;
        }

        return $block;
    }

    /** @param string[] $consumed */
    private function text( array $atts, string $node_id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'title';
        $consumed[] = 'link';

        StyleMapper::write( $attrs, 'button.innerContent.desktop.value.text', $this->att( $atts, 'title' ) );

        $link = $this->link( $atts, 'link' );
        if ( $link === [] ) {
            return;
        }

        $this->reportLinkExtras( $atts, 'link', $node_id, true );

        StyleMapper::write( $attrs, 'button.innerContent.desktop.value.linkUrl', $link['url'] );
        StyleMapper::write( $attrs, 'button.innerContent.desktop.value.linkTarget', ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off' );

        if ( ! empty( $link['rel'] ) ) {
            StyleMapper::write( $attrs, 'button.innerContent.desktop.value.rel', $link['rel'] );
        }
    }

    /** @param string[] $consumed */
    private function size( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'size';

        $size = strtolower( $this->att( $atts, 'size', 'md' ) );
        $spec = self::SIZES[ $size ] ?? self::SIZES['md'];

        $padding = in_array( strtolower( $this->att( $atts, 'style' ) ), self::OUTLINE_STYLES, true )
            ? ( self::OUTLINE_PADDING[ $size ] ?? self::OUTLINE_PADDING['md'] )
            : $spec['padding'];

        [ $vertical, $horizontal ] = explode( ' ', $padding );

        StyleMapper::write( $attrs, 'button.decoration.font.font.desktop.value.size', $spec['font'] );
        StyleMapper::write( $attrs, 'button.decoration.spacing.desktop.value.padding', self::box( $vertical, $horizontal, $vertical, $horizontal ) );
    }

    /** @param string[] $consumed */
    private function shape( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'shape';

        $radius = self::SHAPES[ strtolower( $this->att( $atts, 'shape', 'rounded' ) ) ] ?? self::SHAPES['rounded'];

        StyleMapper::write( $attrs, 'button.decoration.border.desktop.value.radius', self::radius( $radius ) );
    }

    /**
     * The `style` branches of the template, with the defaults
     * `vc_map_get_attributes()` fills in: `custom_background` `#ebebeb`,
     * `custom_text` `#666` and `outline_custom_color` `#666`
     * (config/content/vc-btn-element.php `std` values), which is why a
     * `[vc_btn]` with no colours at all renders grey rather than in the theme's.
     *
     * After the 9.0 normaliser a palette-coloured button already carries
     * `custom_*` values. A pre-9.0 button that lost its `style` on the way —
     * `vc_button` never had one, so `convert_btn_dropdown_color_to_custom()`
     * skips it — still carries the `color` slug, and `legacyColour()` resolves
     * that from WPBakery's own table rather than letting the button go grey.
     *
     * @param string[] $consumed
     */
    private function colours( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [
            'style',
            'color',
            'custom_background',
            'custom_text',
            'custom_border',
            'outline_custom_color',
            'gradient_custom_color_1',
            'gradient_custom_color_2',
            'gradient_text_color',
        ] );

        $style = strtolower( $this->att( $atts, 'style', 'modern' ) );
        $atts  = $this->legacyColour( $atts, $style, $id );

        if ( $style === 'gradient-custom' ) {
            $this->gradient( $atts, $id, $attrs );

            return;
        }

        if ( $style === 'outline-custom' ) {
            // `border-color` and `color` are the one `outline_custom_color`.
            $color = $this->color( array_merge( [ 'outline_custom_color' => '#666' ], $atts ), 'outline_custom_color', $id );
            if ( $color !== null ) {
                StyleMapper::write( $attrs, 'button.decoration.font.font.desktop.value.color', $color );
                $this->borderColour( $color, $attrs );
            }
            StyleMapper::write( $attrs, 'button.decoration.background.desktop.value.color', 'rgba(0,0,0,0)' );

            return;
        }

        if ( $style === 'outline' ) {
            // `if ( $custom_background && 'outline' !== $style )` — an outline
            // button paints no background at all.
            StyleMapper::write( $attrs, 'button.decoration.background.desktop.value.color', 'rgba(0,0,0,0)' );
        } else {
            $background = $this->color( array_merge( [ 'custom_background' => '#ebebeb' ], $atts ), 'custom_background', $id );
            if ( $background !== null ) {
                StyleMapper::write( $attrs, 'button.decoration.background.desktop.value.color', $background );
            }
        }

        $text = $this->color( array_merge( [ 'custom_text' => '#666' ], $atts ), 'custom_text', $id );
        if ( $text !== null ) {
            StyleMapper::write( $attrs, 'button.decoration.font.font.desktop.value.color', $text );
        }

        $border = $this->color( $atts, 'custom_border', $id );
        if ( $border !== null ) {
            if ( $style === '3d' ) {
                // `box-shadow: 0 <shadow width>px 0 <custom_border>`, not a
                // border: the 3d style's `custom_border` is the block under the
                // button (template lines 152-160).
                $this->dropShadow( $border, $this->att( $atts, 'size', 'md' ), $attrs );
                $this->engine->logNotCarriedOver(
                    'interaction',
                    $id,
                    'vc_btn style="3d" lifts the button onto its shadow on hover; Divi paints the shadow but does not animate it'
                );
            } else {
                $this->borderColour( $border, $attrs, $style );
            }
        }

        if ( $style === 'modern' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'vc_btn style="modern" adds a white 20% → 1% overlay gradient over the background colour; Divi keeps the flat colour'
            );
        }
    }

    /**
     * A `color` slug that survived the normaliser, resolved through
     * `Color::BUTTON_MIGRATION` — WPBakery's own `$btn_solid_colors` /
     * `$btn_3d_colors` / `$btn_outline_colors` tables — into the same
     * `custom_*` values `Wpb_Attributes_Migration` writes for that style.
     *
     * WPBakery's migration only fires for a button that already has one of the
     * five styles it knows; a `vc_button` has no `style` attribute at all, so
     * 9.0.1 renders it grey and loses the colour the page was built with. The
     * converter keeps it.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    private function legacyColour( array $atts, string $style, string $id ): array {
        $slug = strtolower( trim( $this->att( $atts, 'color' ) ) );

        if ( $slug === '' || $slug === 'custom' ) {
            return $atts;
        }
        if ( $this->att( $atts, 'custom_background' ) !== '' || $this->att( $atts, 'custom_text' ) !== '' || $this->att( $atts, 'outline_custom_color' ) !== '' ) {
            return $atts;
        }

        $row = Color::buttonMigration( $slug );
        if ( $row === null ) {
            $this->engine->logUnresolvedGlobal( $id, 'color', $slug );

            return $atts;
        }

        switch ( $style ) {
            case 'outline':
                $atts['custom_text']   = $row['bg'];
                $atts['custom_border'] = $row['bg'];
                break;

            case 'outline-custom':
                $atts['outline_custom_color'] = $row['bg'];
                break;

            case '3d':
                $atts['custom_background'] = $row['bg'];
                $atts['custom_text']       = $row['text'];
                $atts['custom_border']     = $row['shadow'];
                break;

            case 'modern':
                $atts['custom_background'] = $row['bg'];
                $atts['custom_text']       = $row['text'];
                $atts['custom_border']     = $row['bg'];
                break;

            default:
                $atts['custom_background'] = $row['bg'];
                $atts['custom_text']       = $row['text'];
        }

        return $atts;
    }

    /**
     * `.vc_general.vc_btn3{border:1px solid transparent}` is the base, and
     * `.vc_btn3-style-outline`/`-outline-custom{border-width:2px}` thickens it —
     * so a border colour is 1px on every other style.
     */
    private function borderColour( string $color, array &$attrs, string $style = 'outline' ): void {
        StyleMapper::write( $attrs, 'button.decoration.border.desktop.value.styles.all', [
            'width' => in_array( $style, self::OUTLINE_STYLES, true ) ? '2px' : '1px',
            'style' => 'solid',
            'color' => $color,
        ] );
    }

    /**
     * The 3d style's block of colour under the button:
     * `box-shadow: 0 <width>px 0 <custom_border>`, with the widths the template
     * keeps per size (`$shadow_widths`).
     */
    private function dropShadow( string $color, string $size, array &$attrs ): void {
        $widths = [ 'xs' => '3px', 'sm' => '4px', 'md' => '5px', 'lg' => '5px' ];

        StyleMapper::write( $attrs, 'button.decoration.boxShadow.desktop.value', [
            'style'      => 'preset1',
            'horizontal' => '0px',
            'vertical'   => $widths[ strtolower( $size ) ] ?? '5px',
            'blur'       => '0px',
            'spread'     => '0px',
            'position'   => 'outer',
            'color'      => $color,
        ] );
    }

    /**
     * `gradient-custom`: the template writes
     * `linear-gradient(to right, c1 0%, c2 50%, c1 100%)` with a 200% background
     * size, and slides it on hover. Divi's gradient takes the same three stops;
     * the slide is an animation and is reported.
     */
    private function gradient( array $atts, string $id, array &$attrs ): void {
        $from = $this->color( $atts, 'gradient_custom_color_1', $id );
        $to   = $this->color( $atts, 'gradient_custom_color_2', $id );
        $text = $this->color( $atts, 'gradient_text_color', $id );

        if ( $text !== null ) {
            StyleMapper::write( $attrs, 'button.decoration.font.font.desktop.value.color', $text );
        }

        if ( $from === null || $to === null ) {
            return;
        }

        // Stop positions are bare numbers: Divi appends the unit itself
        // (`GradientUtils::_sanitize_gradient_stop_position()`), and `to right`
        // is one of the eight direction keywords it accepts.
        StyleMapper::write( $attrs, 'button.decoration.background.desktop.value', [
            'color'    => $from,
            'gradient' => [
                'enabled'   => 'on',
                'type'      => 'linear',
                'direction' => 'to right',
                'stops'     => [
                    [ 'color' => $from, 'position' => '0' ],
                    [ 'color' => $to, 'position' => '50' ],
                    [ 'color' => $from, 'position' => '100' ],
                ],
            ],
        ] );

        $this->engine->logNotCarriedOver(
            'interaction',
            $id,
            'vc_btn style="gradient-custom" slides its gradient across on hover; Divi paints the gradient but does not animate it'
        );
    }

    /**
     * `add_icon` + `i_type` + `i_icon_<library>`, as
     * `button.decoration.button.…icon` (`button/module.json`,
     * `ButtonModule::render_callback()` reads `icon.enable` and `icon.settings`).
     * The settings are written `unicode, type, weight` in that order, which is
     * what Divi's asset detector matches on.
     *
     * @param string[] $consumed
     */
    private function icon( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $library = $this->att( $atts, 'i_type', 'fontawesome' );
        $class   = $this->att( $atts, 'i_icon_' . $library );

        // The icon picker fills its field whether or not `add_icon` is on, so
        // the field is consumed either way: an icon that is switched off
        // describes nothing the page loses.
        $consumed = array_merge( $consumed, [ 'add_icon', 'i_type', 'i_align', 'i_icon_' . $library ] );

        if ( ! $this->on( $atts, 'add_icon' ) ) {
            return;
        }

        if ( $library !== 'fontawesome' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'button icon from the %s library (%s); Divi ships Font Awesome, so a star stands in for it', $library, $class )
            );
        }

        $mapped = IconMap::fromClass( $class );
        if ( ! $mapped['exact'] && $library === 'fontawesome' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'button icon %s is not in Divi\'s Font Awesome set; a star stands in for it', $class )
            );
        }

        StyleMapper::write( $attrs, 'button.decoration.button.desktop.value.icon', [
            'enable'   => 'on',
            'settings' => $mapped['icon'],
        ] );

        // `.vc_btn3-icon-left` puts the icon before the label; Divi's button
        // places it after the text unless `placement` says otherwise.
        $align = strtolower( $this->att( $atts, 'i_align', 'left' ) );
        StyleMapper::write( $attrs, 'button.decoration.button.desktop.value.icon.placement', $align === 'left' ? 'left' : 'right' );
    }

    /**
     * `align` → the module's own alignment field, except `inline`, which is not
     * an alignment at all: it makes the button's container `inline-block` so the
     * next one sits beside it.
     *
     * @param string[] $consumed
     * @return bool Whether this button belongs in an inline group.
     */
    private function alignment( array $atts, array &$attrs, array &$consumed ): bool {
        $consumed[] = 'align';

        $align = strtolower( $this->att( $atts, 'align', 'left' ) );

        if ( $align === 'inline' ) {
            return true;
        }

        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'module.advanced.alignment.desktop.value', $align );
        }

        return false;
    }

    /** `.vc_btn3-block{width:100%}` — `button_block`, ignored when the button is inline. */
    private function width( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'button_block';

        if ( $this->on( $atts, 'button_block' ) && strtolower( $this->att( $atts, 'align' ) ) !== 'inline' ) {
            StyleMapper::write( $attrs, 'button.decoration.sizing.desktop.value.width', '100%' );
        }
    }

    /**
     * The hover colours and the `onclick` handler: WPBakery writes both as
     * inline JavaScript on the element, which no Divi attribute can hold.
     *
     * @param string[] $consumed
     */
    private function interaction( array $atts, string $id, array &$consumed ): void {
        $hover_keys = [
            'custom_hover_background',
            'custom_hover_text',
            'custom_hover_border',
            'outline_custom_hover_background',
            'outline_custom_hover_text',
        ];
        $consumed   = array_merge( $consumed, $hover_keys, [ 'custom_onclick', 'custom_onclick_code' ] );

        $hover = [];
        foreach ( $hover_keys as $key ) {
            $value = $this->att( $atts, $key );
            if ( $value !== '' ) {
                $hover[] = $key . ' ' . $value;
            }
        }

        if ( $hover !== [] ) {
            $this->engine->logNotCarriedOver(
                'hover',
                $id,
                'button hover colours (' . implode( ', ', $hover ) . '); set them on the Divi button\'s hover state'
            );
        }

        if ( $this->att( $atts, 'custom_onclick_code' ) !== '' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                'button custom onclick JavaScript (' . $this->att( $atts, 'custom_onclick_code' ) . '); Divi buttons carry no inline script'
            );
        }
    }
}
