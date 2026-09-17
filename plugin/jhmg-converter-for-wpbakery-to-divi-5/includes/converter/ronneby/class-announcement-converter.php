<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `announcement` → `divi/blurb`.
 *
 * **Not the brief's `divi/cta`.** `modules/dfd-announcement.php` maps no title
 * and no button: the whole element is an icon from the theme's icon manager and
 * a body of copy in a bordered box (`icon`, `content`, plus `icon_*` and
 * `content_*` colours). Divi's call-to-action module is a title, a body and a
 * button — it would leave the icon nowhere and require a button the source has
 * none of — while the blurb is exactly an icon beside a body of copy:
 * `imageIcon.innerContent`, `content.innerContent` and the icon's own colour,
 * size, background and radius under `imageIcon.advanced` / `imageIcon.decoration`
 * (`blurb/module.json`). The brief's table is written against an element with a
 * title and a button; the source is what this follows (CLAUDE.md).
 *
 * **No default bottom margin**: `.dfd-announcement` carries none.
 */
class AnnouncementConverter extends RonnebyConverter {

    /** `align` — the module's own `align-left|align-center|align-right` classes. */
    const ALIGNMENTS = [
        'align-left'   => 'left',
        'align-center' => 'center',
        'align-right'  => 'right',
    ];

    /** What the theme's stylesheet draws around the box. */
    const REPORTED_LOOK = [
        'main_style'            => 'which of the three announcement frames is drawn',
        'main_layout'           => 'where the icon sits in the box',
        'dark_bg'               => 'the dark colour scheme',
        'full_width_background' => 'stretching the background across the row',
        'hide_icon'             => 'hiding the icon',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_announcement_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'blurb', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'icon', 'icon_size', 'icon_color', 'icon_bg', 'icon_radius', 'align' ] );

        $class = trim( $this->att( $atts, 'icon' ) );
        if ( $class !== '' && ! $this->on( $atts, 'hide_icon' ) ) {
            StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.useIcon', 'on' );
            StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.icon', $this->iconValue( $class, $id, 'the announcement icon' ) );

            $color = $this->color( $atts, 'icon_color', $id );
            if ( $color !== null ) {
                StyleMapper::write( $attrs, 'imageIcon.advanced.color.desktop.value', $color );
            }

            $size = $this->pixels( $atts, 'icon_size' );
            if ( $size !== '' ) {
                StyleMapper::write( $attrs, 'imageIcon.decoration.sizing.desktop.value.width', $size );
            }

            $icon_background = $this->color( $atts, 'icon_bg', $id );
            if ( $icon_background !== null ) {
                StyleMapper::write( $attrs, 'imageIcon.decoration.background.desktop.value.color', $icon_background );
            }

            $icon_radius = $this->pixels( $atts, 'icon_radius' );
            if ( $icon_radius !== '' ) {
                StyleMapper::write( $attrs, 'imageIcon.decoration.border.desktop.value.radius', self::radius( $icon_radius ) );
            }
        }

        // `imageIcon.advanced.placement` is `top` by default; the module puts
        // the icon beside the copy in every one of its four layouts
        // (`.dfd-announcement .icon-wrap{float:left}`).
        StyleMapper::write( $attrs, 'imageIcon.advanced.placement.desktop.value', 'left' );

        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            trim( $this->nestedShortcodes( $this->editorHtml( $this->rawContent( $node ) ), $id ) )
        );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'content.decoration.bodyFont.body.font',
            $id,
            $attrs,
            $consumed
        );

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'align' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }

        $this->boxLook( $atts, $id, $attrs, $consumed );
        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'announcement draws its box with the theme\'s own classes', $consumed );

        $this->engine->logConverted( 'blurb' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/blurb', $attrs );
    }

    /**
     * `content_bg`, `content_border`, `border_color` and `content_radius` — the
     * box the copy sits in, which is the Divi module's own background and border.
     *
     * @param string[] $consumed
     */
    private function boxLook( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'content_bg', 'content_border', 'border_color', 'content_radius' ] );

        $background = $this->color( $atts, 'content_bg', $id );
        if ( $background !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background );
        }

        $radius = $this->pixels( $atts, 'content_radius' );
        if ( $radius !== '' ) {
            StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.radius', self::radius( $radius ) );
        }

        $width = $this->pixels( $atts, 'content_border' );
        $color = $this->color( $atts, 'border_color', $id );
        if ( $width !== '' || $color !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.styles.all', array_filter( [
                'width' => $width,
                'style' => 'solid',
                'color' => $color ?? '',
            ], static fn( string $value ): bool => $value !== '' ) );
        }
    }
}
