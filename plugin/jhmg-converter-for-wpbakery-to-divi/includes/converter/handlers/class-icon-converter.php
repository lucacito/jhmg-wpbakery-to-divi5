<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\IconMap;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_icon` → `divi/icon`.
 *
 * `include/templates/shortcodes/vc_icon.php` renders one `<span>` carrying the
 * icon font's class inside a fixed square, with an optional link laid over it.
 * Divi's icon module is the same thing: `icon.innerContent` holds both the glyph
 * (`unicode, type, weight`) and the link (`url`, `target`), and
 * `icon.advanced.{color,size,align}` hold the rest (`icon/module.json`).
 */
class IconConverter extends BaseWPBakeryConverter {

    /**
     * `.vc_icon_element-size-<size> .vc_icon_element-icon{font-size:…}` from
     * `assets/css/js_composer.min.css`, in the `em` the stylesheet writes.
     */
    const SIZES = [
        'xs' => '1.2em',
        'sm' => '1.6em',
        'md' => '2.15em',
        'lg' => '2.85em',
        'xl' => '5em',
    ];

    /**
     * `.vc_icon_element{font-size:14px}` — the base those `em` resolve against.
     * WPBakery pins it in its own stylesheet, so the rendered size is the same
     * on every theme; Divi's icon has no such base, so the size is written in
     * pixels rather than handing the `em` to whatever the body font is.
     */
    const BASE_FONT_SIZE = 14;

    /** `.vc_icon_element-inner.vc_icon_element-style-<style>{border-radius:…}`. */
    const BACKGROUND_RADIUS = [
        'rounded'              => '50%',
        'rounded-outline'      => '50%',
        'boxed'                => '0px',
        'boxed-outline'        => '0px',
        'rounded-less'         => '5px',
        'rounded-less-outline' => '5px',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_icon_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'icon', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = $style['handled_keys'];

        $this->glyph( $atts, $id, $attrs, $consumed );
        $this->size( $atts, $attrs, $consumed );
        $this->colour( $atts, $id, $attrs, $consumed );
        $this->alignment( $atts, $attrs, $consumed );
        $this->background( $atts, $id, $attrs, $consumed );
        $this->linkTo( $atts, $id, $attrs, $consumed );

        $this->engine->logConverted( 'icon' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/icon', $attrs );
    }

    /**
     * `$icon_class = ${'icon_' . $type}` — the library named by `type`, with the
     * template's own `fa fa-adjust` fallback when that field is empty.
     *
     * @param string[] $consumed
     */
    private function glyph( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'type';

        $library    = $this->att( $atts, 'type', 'fontawesome' );
        $class      = $this->att( $atts, 'icon_' . $library, $library === 'fontawesome' ? 'fa fa-adjust' : '' );
        $consumed[] = 'icon_' . $library;

        if ( $library !== 'fontawesome' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'icon from the %s library (%s); Divi ships Font Awesome, so a star stands in for it', $library, $class )
            );
        }

        $mapped = IconMap::fromClass( $class );

        if ( ! $mapped['exact'] && $library === 'fontawesome' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'icon %s is not in Divi\'s Font Awesome set; a star stands in for it', $class )
            );
        }

        // unicode, type, weight in that order: Divi's asset detector only
        // enqueues Font Awesome when it reads them that way round.
        StyleMapper::write( $attrs, 'icon.innerContent.desktop.value', $mapped['icon'] );
    }

    /** @param string[] $consumed */
    private function size( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'size';

        $size = strtolower( $this->att( $atts, 'size', 'md' ) );
        $em   = self::SIZES[ $size ] ?? self::SIZES['md'];

        $pixels = (float) rtrim( $em, 'em' ) * self::BASE_FONT_SIZE;

        StyleMapper::write(
            $attrs,
            'icon.advanced.size.desktop.value',
            rtrim( rtrim( number_format( $pixels, 2, '.', '' ), '0' ), '.' ) . 'px'
        );
    }

    /** @param string[] $consumed */
    private function colour( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'color';
        $consumed[] = 'custom_color';

        $color = $this->color( $atts, 'custom_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'icon.advanced.color.desktop.value', $color );
        }
    }

    /** @param string[] $consumed */
    private function alignment( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'align';

        $align = strtolower( $this->att( $atts, 'align', 'left' ) );
        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'icon.advanced.align.desktop.value', $align );
        }
    }

    /**
     * `background_style` draws a fixed square (or circle) behind the glyph, in
     * `custom_background_color` — as a background for the solid shapes and as a
     * border for the `*-outline` ones. Divi's icon module has no such box: the
     * colour and the radius go on the module, which is as close as the module
     * gets, and the difference is reported.
     *
     * @param string[] $consumed
     */
    private function background( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'background_style';
        $consumed[] = 'background_color';
        $consumed[] = 'custom_background_color';

        $shape = strtolower( $this->att( $atts, 'background_style' ) );
        if ( $shape === '' ) {
            return;
        }

        $radius = self::BACKGROUND_RADIUS[ $shape ] ?? null;
        if ( $radius !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.radius', [
                'topLeft'     => $radius,
                'topRight'    => $radius,
                'bottomRight' => $radius,
                'bottomLeft'  => $radius,
            ] );
        }

        $color = $this->color( $atts, 'custom_background_color', $id );
        if ( $color !== null ) {
            if ( str_contains( $shape, 'outline' ) ) {
                StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.styles.all', [
                    'width' => '2px',
                    'style' => 'solid',
                    'color' => $color,
                ] );
            } else {
                StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $color );
            }
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'vc_icon background_style="%s" draws a fixed square behind the glyph (%s of the icon size); Divi paints the module instead',
                $shape,
                '4em'
            )
        );
    }

    /** @param string[] $consumed */
    private function linkTo( array $atts, string $node_id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'link';

        $link = $this->link( $atts, 'link' );
        if ( $link === [] ) {
            return;
        }

        $this->reportLinkExtras( $atts, 'link', $node_id );

        StyleMapper::write( $attrs, 'icon.innerContent.desktop.value.url', $link['url'] );
        StyleMapper::write( $attrs, 'icon.innerContent.desktop.value.target', ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off' );
    }
}
