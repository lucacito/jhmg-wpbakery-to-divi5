<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Helpers\UltimateFields;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `ult_content_box` (Ultimate Addons) → `divi/group`, registered approximate.
 *
 * `modules/ultimate_content_box.php` is a box drawn around whatever the editor
 * dropped into it: a background (`bg_type`, `bg_color`, `bg_image`), a border,
 * a box shadow, padding, margin, a minimum height and an optional link, plus a
 * hover state for the first three. Divi's group module is a container with the
 * same decoration groups (`group/module.json`: `module.decoration.{background,
 * border,boxShadow,spacing,sizing,layout}`), so the box converts and its
 * children are converted in place.
 *
 * The hover state (`hover_bg_color`, `hover_border_color`, `hover_box_shadow`
 * and the `content_transition` group) is reported: Divi keeps a hover state per
 * attribute rather than as a second set of fields, and guessing which of the
 * three the author cared about would be inventing.
 */
class UltContentBoxConverter extends BaseWPBakeryConverter {

    /** The CSS-fragment and packed fields the template appends to the box's inline style. */
    const PACKED = [ 'border', 'box_shadow', 'hover_box_shadow', 'padding', 'margin', 'responsive_margin', 'content_transition' ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_content_box_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // A container is structure: no default module margin of its own.
        $style    = $this->mapStyle( 'group', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], self::PACKED, [
            'bg_type', 'bg_color', 'bg_image', 'bg_repeat', 'bg_size', 'bg_position',
            'min_height', 'link', 'content_spacing',
            'hover_bg_color', 'hover_border_color', 'trans_property', 'trans_duration', 'trans_function',
        ] );

        $this->background( $atts, $id, $attrs );
        $this->decorate( $atts, $attrs );
        $this->boxLink( $atts, $attrs );
        $this->hover( $atts, $id );

        $this->engine->logConverted( 'group' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/group', $attrs, $this->convertChildren( $node ) );
    }

    private function background( array $atts, string $id, array &$attrs ): void {
        $color = $this->color( $atts, 'bg_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $color );
        }

        $image = UltimateFields::media( $this->att( $atts, 'bg_image' ) );
        $url   = $image['url'] ?? '';

        if ( $url === '' ) {
            if ( isset( $image['id'] ) ) {
                $this->engine->logUnresolvedMedia( $id, (int) $image['id'] );
            }

            return;
        }

        StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.image.url', $url );

        $repeat = strtolower( trim( $this->att( $atts, 'bg_repeat' ) ) );
        if ( in_array( $repeat, [ 'repeat', 'no-repeat', 'repeat-x', 'repeat-y' ], true ) ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.image.repeat', $repeat );
        }

        $size = strtolower( trim( $this->att( $atts, 'bg_size' ) ) );
        if ( in_array( $size, [ 'cover', 'contain' ], true ) ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.image.size', $size );
        }
    }

    /**
     * `border`, `padding` and `margin` are CSS declaration strings the template
     * appends to the box's inline `style` verbatim — `border-style:solid;|border-width:1px;|border-color:#ccc;`
     * and `padding-top:20px;padding-bottom:20px;` (`ultimate_content_box.php`
     * lines 128-156: `str_replace( '|', '', … )` and `$style .= …`). So they are
     * read as the CSS they are.
     *
     * `min_height` is a bare number the template writes as `min-height:<n>px`.
     */
    private function decorate( array $atts, array &$attrs ): void {
        $border = $this->declarations( $this->att( $atts, 'border' ) );

        $style = strtolower( trim( $border['border-style'] ?? '' ) );
        $color = Color::normalize( $border['border-color'] ?? '' );

        if ( $style !== '' && $style !== 'none' && $color !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.styles.all', [
                'width' => PackedParams::sizeWithUnit( $border['border-width'] ?? '1px' ),
                'style' => $style,
                'color' => $color,
            ] );
        }

        $radius = PackedParams::sizeWithUnit( $border['border-radius'] ?? '' );
        if ( $radius !== '' ) {
            StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.radius', self::radius( $radius ) );
        }

        $this->sides( $this->declarations( $this->att( $atts, 'padding' ) ), 'padding', $attrs );
        $this->sides( $this->declarations( $this->att( $atts, 'margin' ) ), 'margin', $attrs );
        $this->shadow( $atts, $attrs );

        $height = trim( $this->att( $atts, 'min_height' ) );
        if ( $height !== '' && is_numeric( $height ) ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.minHeight', $height . 'px' );
        }
    }

    /**
     * `box_shadow="horizontal:5px|vertical:5px|blur:10px|spread:0px|color:#000|style:outset|"`
     * — the add-on's packed shadow, `style:none` when it is off.
     */
    private function shadow( array $atts, array &$attrs ): void {
        $shadow = $this->packed( $this->att( $atts, 'box_shadow' ) );
        $style  = strtolower( trim( $shadow['style'] ?? '' ) );

        if ( $style === '' || $style === 'none' ) {
            return;
        }

        $color = Color::normalize( $shadow['color'] ?? '' );

        StyleMapper::write( $attrs, 'module.decoration.boxShadow.desktop.value', [
            'style'      => 'preset1',
            'horizontal' => PackedParams::sizeWithUnit( $shadow['horizontal'] ?? '0px' ),
            'vertical'   => PackedParams::sizeWithUnit( $shadow['vertical'] ?? '0px' ),
            'blur'       => PackedParams::sizeWithUnit( $shadow['blur'] ?? '0px' ),
            'spread'     => PackedParams::sizeWithUnit( $shadow['spread'] ?? '0px' ),
            'position'   => $style === 'inset' ? 'inner' : 'outer',
            'color'      => $color ?? 'rgba(0,0,0,0.3)',
        ] );
    }

    /**
     * A CSS declaration string, with the add-on's `|` separators removed, as
     * `property => value`.
     *
     * @return array<string,string>
     */
    private function declarations( string $raw ): array {
        $parts = [];

        foreach ( explode( ';', str_replace( '|', '', trim( $raw ) ) ) as $declaration ) {
            if ( ! str_contains( $declaration, ':' ) ) {
                continue;
            }
            [ $property, $value ] = explode( ':', $declaration, 2 );
            $property             = strtolower( trim( $property ) );
            $value                = trim( $value );

            if ( $property !== '' && $value !== '' ) {
                $parts[ $property ] = $value;
            }
        }

        return $parts;
    }

    /**
     * `box_shadow`-style packed values: `key:value` pairs joined with `|`.
     *
     * @return array<string,string>
     */
    private function packed( string $raw ): array {
        $parts = [];

        foreach ( explode( '|', trim( $raw ) ) as $pair ) {
            if ( ! str_contains( $pair, ':' ) ) {
                continue;
            }
            [ $key, $value ] = explode( ':', $pair, 2 );
            $key             = trim( $key );
            $value           = trim( $value );
            if ( $key !== '' && $value !== '' ) {
                $parts[ $key ] = $value;
            }
        }

        return $parts;
    }

    /**
     * The four sides of a CSS declaration set, written as Divi's box value.
     *
     * @param array<string,string> $declarations
     */
    private function sides( array $declarations, string $property, array &$attrs ): void {
        $box   = [];
        $found = false;

        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
            $value = trim( $declarations[ $property . '-' . $side ] ?? '' );
            if ( $value === '' ) {
                $box[ $side ] = '';
                continue;
            }
            $found        = true;
            $box[ $side ] = PackedParams::sizeWithUnit( $value );
        }

        if ( ! $found ) {
            return;
        }

        StyleMapper::write(
            $attrs,
            'module.decoration.spacing.desktop.value.' . $property,
            $box + [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ]
        );
    }

    /** `link` is a packed WPBakery link the template puts on the whole box. */
    private function boxLink( array $atts, array &$attrs ): void {
        $link = $this->link( $atts, 'link' );

        if ( $link === [] ) {
            return;
        }

        StyleMapper::write( $attrs, 'module.advanced.link.desktop.value.url', $link['url'] );
        StyleMapper::write( $attrs, 'module.advanced.link.desktop.value.target', ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off' );
    }

    private function hover( array $atts, string $id ): void {
        $described = [];

        foreach ( [
            'hover_bg_color'     => 'a hover background colour',
            'hover_border_color' => 'a hover border colour',
            'hover_box_shadow'   => 'a hover box shadow',
            'trans_property'     => 'the CSS property it transitions',
            'trans_duration'     => 'the transition duration',
            'trans_function'     => 'the easing function',
        ] as $key => $what ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' && ! str_contains( $value, 'style:none' ) ) {
                $described[] = $key . '="' . $value . '" (' . $what . ')';
            }
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'hover',
            $id,
            'ult_content_box ' . implode( ', ', $described ) . '; Divi keeps a hover state per attribute, so set the group\'s own hover background and border'
        );
    }
}
