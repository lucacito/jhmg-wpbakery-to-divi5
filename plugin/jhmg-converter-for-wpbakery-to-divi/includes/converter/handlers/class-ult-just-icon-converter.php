<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\IconMap;
use WPBakeryDivi5Converter\Helpers\UltimateFields;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `just_icon` (Ultimate Addons) → `divi/icon`, or `divi/image` when the icon is
 * an uploaded picture. Registered approximate.
 *
 * `modules/ultimate_just_icon.php` is one glyph from the add-on's Font Icon
 * Manager (`icon`), or a custom image (`icon_type="custom"`, `icon_img`), with
 * a size, a colour, an optional ring (`icon_style`, `icon_color_bg`,
 * `icon_border_*`) and an optional link. Divi's icon module has the glyph, its
 * size, its colour and its alignment (`icon/module.json`: `icon.innerContent`,
 * `icon.advanced.{size,color,align}`); the ring is the add-on's own stylesheet
 * and is reported, exactly as `vc_icon`'s `background_style` is.
 */
class UltJustIconConverter extends BaseWPBakeryConverter {

    /** The ring and its trimmings, all drawn by the add-on's stylesheet. */
    const REPORTED_LOOK = [
        'icon_style'          => 'the ring or square drawn behind the icon',
        'icon_color_bg'       => 'the colour of that ring',
        'icon_color_border'   => 'its border colour',
        'icon_border_style'   => 'its border style',
        'icon_border_size'    => 'its border width',
        'icon_border_radius'  => 'its corner radius',
        'icon_border_spacing' => 'the space it leaves around the glyph',
        'icon_animation'      => 'the icon entrance animation',
        'tooltip_disp'        => 'a tooltip on hover',
        'tooltip_text'        => 'the tooltip text',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_just_icon_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $custom = $this->att( $atts, 'icon_type', 'selector' ) === 'custom';

        $style    = $this->mapStyle( $custom ? 'image' : 'icon', $this->withCss( $node, 'css_just_icon' ) );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'css_just_icon', 'icon_type', 'icon', 'icon_img', 'img_width', 'icon_size', 'icon_color',
            'icon_link', 'icon_align',
        ], array_keys( self::REPORTED_LOOK ) );

        $this->look( $atts, $id );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        if ( $custom ) {
            return $this->image( $atts, $id, $attrs );
        }

        return $this->glyph( $atts, $id, $attrs );
    }

    private function glyph( array $atts, string $id, array $attrs ): array {
        $class  = UltimateFields::iconClass( $this->att( $atts, 'icon' ) );
        $mapped = IconMap::fromClass( $class === '' ? 'fa fa-star' : $class );

        if ( $class !== '' && ! $mapped['exact'] ) {
            $this->engine->logNotCarriedOver(
                'addon',
                $id,
                sprintf( 'icon "%s" comes from the add-on\'s own icon manager and is not in Divi\'s Font Awesome set; a star stands in for it', $this->att( $atts, 'icon' ) )
            );
        }

        StyleMapper::write( $attrs, 'icon.innerContent.desktop.value', $mapped['icon'] );

        $color = $this->color( $atts, 'icon_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'icon.advanced.color.desktop.value', $color );
        }

        $size = UltimateFields::responsive( $this->att( $atts, 'icon_size' ) );
        if ( $size !== '' ) {
            StyleMapper::write( $attrs, 'icon.advanced.size.desktop.value', rtrim( $size, 'px' ) . 'px' );
        }

        $align = strtolower( trim( $this->att( $atts, 'icon_align' ) ) );
        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'icon.advanced.align.desktop.value', $align );
        }

        $link = $this->link( $atts, 'icon_link' );
        if ( $link !== [] ) {
            StyleMapper::write( $attrs, 'icon.innerContent.desktop.value.url', $link['url'] );
            StyleMapper::write( $attrs, 'icon.innerContent.desktop.value.target', ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off' );
        }

        $this->engine->logConverted( 'icon' );

        return $this->block( $id, 'divi/icon', $attrs );
    }

    /** `icon_type="custom"`: an uploaded picture, which is an image module. */
    private function image( array $atts, string $id, array $attrs ): array {
        $image = UltimateFields::media( $this->att( $atts, 'icon_img' ) );
        $url   = $image['url'] ?? '';

        if ( $url === '' && isset( $image['id'] ) ) {
            $this->engine->logUnresolvedMedia( $id, (int) $image['id'] );
        }

        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.src', $url );

        if ( isset( $image['alt'] ) ) {
            StyleMapper::write( $attrs, 'image.innerContent.desktop.value.alt', $image['alt'] );
        }

        $width = UltimateFields::responsive( $this->att( $atts, 'img_width' ) );
        if ( $width !== '' ) {
            StyleMapper::write( $attrs, 'module.advanced.sizing.desktop.value.width', rtrim( $width, 'px' ) . 'px' );
            StyleMapper::write( $attrs, 'module.advanced.sizing.desktop.value.forceFullwidth', 'on' );
        }

        $align = strtolower( trim( $this->att( $atts, 'icon_align' ) ) );
        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'module.advanced.align.desktop.value', $align );
        }

        $this->engine->logConverted( 'image' );

        return $this->block( $id, 'divi/image', $attrs );
    }

    /** @param array<string,mixed> $atts */
    private function look( array $atts, string $id ): void {
        $described = [];

        foreach ( self::REPORTED_LOOK as $key => $what ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' && $value !== 'none' && $value !== '0' ) {
                $described[] = $key . '="' . $value . '" (' . $what . ')';
            }
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'addon',
            $id,
            'just_icon is drawn by Ultimate Addons\' own stylesheet: ' . implode( ', ', $described ) . '; Divi\'s icon module draws the glyph alone'
        );
    }
}
