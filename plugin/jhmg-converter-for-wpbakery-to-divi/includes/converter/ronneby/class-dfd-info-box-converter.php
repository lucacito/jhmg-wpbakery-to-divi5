<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_info_box` → `divi/blurb`, with a `divi/button` after it for a read-more
 * link that is neither the title nor the whole box.
 *
 * `modules/dfd-info-box.php:662-960` draws an icon (a glyph, an uploaded image
 * or a piece of text), a title, a subtitle, a delimiter, the copy and a
 * read-more control, and `layout` decides which order and which side. Divi's
 * blurb is the icon, the title and the copy: `imageIcon.innerContent`,
 * `title.innerContent` and `content.innerContent`, with the icon's own colour,
 * size, background and border under `imageIcon.advanced` /
 * `imageIcon.decoration` (`blurb/module.json`, `BlurbModule.php:735-1067`).
 *
 * The subtitle has no field of its own, so it goes at the top of the copy in
 * the tag `subtitle_font_options` names — where the module prints it — the same
 * way `CtaConverter` places `vc_cta`'s `h4`.
 *
 * **No default bottom margin**: `.dfd-info-box` carries none; the only
 * `margin-bottom` under it in `visual-composer.css` is
 * `.dfd-info-box .icon-wrapper{margin-bottom:15px}`, which is inside the box.
 */
class DfdInfoBoxConverter extends RonnebyConverter {

    /**
     * `layout` (`modules/dfd-info-box.php:93-121`, tooltips included) ⇒ Divi's
     * `imageIcon.advanced.placement`. The four vertical layouts differ in where
     * the icon sits in the stack, which Divi's blurb draws as `top`;
     * `.dfd-info-box.layout-05 .icon-wrapper{float:right}` (`visual-composer.css`)
     * is what makes 05 and 07 the right-hand ones.
     */
    const PLACEMENT = [
        'layout-01' => 'top',
        'layout-02' => 'top',
        'layout-03' => 'top',
        'layout-04' => 'left',
        'layout-05' => 'right',
        'layout-06' => 'left',
        'layout-07' => 'right',
    ];

    /** `content_alignment` ⇒ the module's text orientation. */
    const ALIGNMENTS = [
        'text-left'   => 'left',
        'text-center' => 'center',
        'text-right'  => 'right',
    ];

    /** The box's own drawing, which belongs to the theme's stylesheet. */
    const REPORTED_LOOK = [
        'style'                => 'which of the six box frames is drawn',
        'main_style'           => 'the box frame of an older Ronneby build',
        'main_layout'          => 'the layout of an older Ronneby build',
        'hover_animation'      => 'the box or icon lift on hover',
        'icon_bg_size'         => 'the size of the shape behind the icon',
        'opacity'              => 'the opacity of an image icon',
        'content_under_offset' => 'extra space above the copy',
        'delimiter_style'      => 'the rule under the title',
        'line_width'           => 'the width of that rule',
        'line_border'          => 'its thickness',
        'line_color'           => 'its colour',
        'line_hide'            => 'whether it is drawn at all',
        'icon_number'          => 'a numbered badge on the icon',
        'icon_number_text'     => 'what that badge says',
        'number_bg_color'      => 'the badge colour',
        'readmore_style'       => 'which of the ten read-more controls is drawn',
        'more_show'            => 'whether the read-more control only appears on hover',
        'main_content'         => 'the copy of an older Ronneby build',
    ];

    /** Colours the module paints only under the cursor. */
    const REPORTED_HOVER = [
        'icon_hover'            => 'the icon colour',
        'hover_icon_bg'         => 'the fill behind the icon',
        'hover_icon_border'     => 'the icon\'s border',
        'hover_border_color'    => 'the icon\'s border, of an older Ronneby build',
        'read_more_hover_color' => 'the read-more colour',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_info_box_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'blurb', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'tutorials' ] );

        $this->icon( $atts, $id, $attrs, $consumed );
        $this->heading( $atts, $id, $attrs, $consumed );
        $this->body( $node, $atts, $id, $attrs, $consumed );
        $this->placement( $atts, $attrs, $consumed );

        $button = $this->readMore( $atts, $id, $attrs, $consumed );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'dfd_info_box draws its box with the theme\'s own classes', $consumed );
        $this->reportGroup( $atts, self::REPORTED_HOVER, 'hover', $id, 'dfd_info_box repaints itself under the cursor; set these on the Divi blurb\'s hover state', $consumed );

        $blocks = [ $this->block( $id, 'divi/blurb', $attrs ) ];
        if ( $button !== null ) {
            $blocks[] = $button;
        }

        $this->engine->logConverted( 'blurb' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * `crumina_icon_render()` (`inc/vc_custom/dfd_vc_addons.php:373-450`):
     * `icon_type` is `icon` (a glyph from Ronneby's icon manager), `custom` (an
     * uploaded image) or `text` (a word or a number in the icon's place).
     *
     * @param string[] $consumed
     */
    private function icon( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [
            'icon_type', 'icon', 'ic_dfd_icons', 'icon_image_id', 'icon_size', 'icon_color',
            'icon_text', 'text_icon_font_options', 'text_icon_use_google_fonts', 'text_icon_custom_fonts',
            'icon_bg_img', 'fill_color_start', 'fill_color_end', 'background_color',
            'border_radius', 'border_width', 'border_color',
        ] );

        $type = strtolower( trim( $this->att( $atts, 'icon_type', 'icon' ) ) );

        if ( $type === 'text' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'icon_type="text" prints "%s" where the icon goes, in its own typography; Divi\'s blurb holds an icon or an image, so the text was not carried over',
                    $this->att( $atts, 'icon_text' )
                )
            );

            return;
        }

        if ( $type === 'custom' ) {
            $attachment = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'icon_image_id' ) );
            $url        = $attachment > 0 ? $this->attachmentUrl( $attachment, 'full', $id ) : null;

            StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.useIcon', 'off' );
            if ( $url !== null ) {
                StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.src', $url );
            }
        } else {
            $class = trim( $this->att( $atts, 'icon', $this->att( $atts, 'ic_dfd_icons' ) ) );
            if ( $class === '' ) {
                $this->iconBackground( $atts, $id, $attrs );

                return;
            }

            StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.useIcon', 'on' );
            StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.icon', $this->iconValue( $class, $id, 'the info box icon' ) );

            $color = $this->color( $atts, 'icon_color', $id );
            if ( $color !== null ) {
                StyleMapper::write( $attrs, 'imageIcon.advanced.color.desktop.value', $color );
            }
        }

        // `crumina_icon_render()` writes `font-size` for a glyph and `width`
        // for an image; Divi's blurb takes both from `imageIcon.decoration.sizing`.
        $size = $this->pixels( $atts, 'icon_size' );
        if ( $size !== '' ) {
            StyleMapper::write( $attrs, 'imageIcon.decoration.sizing.desktop.value.width', $size );
        }

        $this->iconBackground( $atts, $id, $attrs );
    }

    /**
     * The shape behind the icon: `fill_color_start`/`fill_color_end` are a
     * left-to-right gradient (`dfd-info-box.php:762-768`) and `border_radius`,
     * `border_width` and `border_color` its frame (lines 740-752).
     */
    private function iconBackground( array $atts, string $id, array &$attrs ): void {
        $start = $this->color( $atts, 'fill_color_start', $id ) ?? $this->color( $atts, 'background_color', $id );
        $end   = $this->color( $atts, 'fill_color_end', $id );

        if ( $start !== null && $end !== null && $start !== $end ) {
            StyleMapper::write( $attrs, 'imageIcon.decoration.background.desktop.value', [
                'color'    => $start,
                // Divi appends the unit to a gradient stop itself (CLAUDE.md).
                'gradient' => [
                    'enabled'   => 'on',
                    'type'      => 'linear',
                    'direction' => 'to right',
                    'stops'     => [
                        [ 'color' => $start, 'position' => '0' ],
                        [ 'color' => $end, 'position' => '100' ],
                    ],
                ],
            ] );
        } elseif ( $start !== null || $end !== null ) {
            StyleMapper::write( $attrs, 'imageIcon.decoration.background.desktop.value.color', $start ?? $end );
        }

        $radius = $this->pixels( $atts, 'border_radius' );
        if ( $radius !== '' ) {
            StyleMapper::write( $attrs, 'imageIcon.decoration.border.desktop.value.radius', self::radius( $radius ) );
        }

        $border = $this->color( $atts, 'border_color', $id );
        $width  = $this->pixels( $atts, 'border_width' );
        if ( $border !== null || $width !== '' ) {
            StyleMapper::write( $attrs, 'imageIcon.decoration.border.desktop.value.styles.all', array_filter( [
                'width' => $width,
                'style' => 'solid',
                'color' => $border ?? '',
            ], static fn( string $value ): bool => $value !== '' ) );
        }

        if ( trim( $this->att( $atts, 'icon_bg_img' ) ) !== '' ) {
            $this->engine->logNotCarriedOver(
                'background',
                $id,
                'icon_bg_img puts a picture behind the icon (the style-05 frame); Divi\'s blurb icon takes a colour or a gradient'
            );
        }
    }

    /** @param string[] $consumed */
    private function heading( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'title';

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value.text', $this->att( $atts, 'title' ) );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'title.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );

        $this->applyResponsiveText( $atts, 'title_responsive', 'title.decoration.font.font', $id, $attrs, $consumed );
    }

    /**
     * The copy, with the subtitle above it in the tag the module prints it in
     * (`dfd-info-box.php:829-832`).
     *
     * @param string[] $consumed
     */
    private function body( array $node, array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'subtitle', 'subtitle_font_options', 'content_alignment' ] );

        $html = trim( $this->nestedShortcodes( $this->editorHtml( $this->rawContent( $node ) ), $id ) );

        $subtitle = trim( $this->att( $atts, 'subtitle' ) );
        if ( $subtitle !== '' ) {
            $tag = strtolower( trim( RonnebyParams::fontOptions( $this->att( $atts, 'subtitle_font_options' ) )['tag'] ?? '' ) );
            $tag = preg_match( '/^h[1-6]$/', $tag ) === 1 ? $tag : 'h4';

            $html = '<' . $tag . '>' . $subtitle . '</' . $tag . '>' . $html;

            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'the info box subtitle has its own typography (subtitle_font_options); Divi\'s blurb has one body font, so the subtitle was put at the top of the copy as a heading'
            );
        }

        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $html );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'use_content_google_fonts', 'family' => 'content_custom_fonts' ],
            'content.decoration.bodyFont.body.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyResponsiveText( $atts, 'content_responsive', 'content.decoration.bodyFont.body.font', $id, $attrs, $consumed );

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'content_alignment', 'text-center' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }
    }

    /** @param string[] $consumed */
    private function placement( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'layout';

        $layout = strtolower( trim( $this->att( $atts, 'layout', 'layout-01' ) ) );

        StyleMapper::write( $attrs, 'imageIcon.advanced.placement.desktop.value', self::PLACEMENT[ $layout ] ?? 'top' );
    }

    /**
     * `read_more` is `none`, `title`, `more` or `box`
     * (`dfd-info-box.php:806-818, 899-917`) — the same four `bsf-info-box` has,
     * and the same three homes in Divi: the module link, the title link, or a
     * button after the blurb.
     *
     * @param string[] $consumed
     */
    private function readMore( array $atts, string $id, array &$attrs, array &$consumed ): ?array {
        $consumed = array_merge( $consumed, [ 'read_more', 'link', 'readmore_show', 'readmore_text', 'read_more_color' ] );

        $mode = strtolower( trim( $this->att( $atts, 'read_more', 'none' ) ) );
        $link = $this->link( $atts, 'link' );

        if ( $mode === 'none' || $link === [] ) {
            return null;
        }

        $this->reportLinkExtras( $atts, 'link', $id, true );

        $target = ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off';

        if ( $mode === 'box' ) {
            StyleMapper::write( $attrs, 'module.advanced.link.desktop.value.url', $link['url'] );
            StyleMapper::write( $attrs, 'module.advanced.link.desktop.value.target', $target );

            return null;
        }

        if ( $mode === 'title' ) {
            StyleMapper::write( $attrs, 'title.innerContent.desktop.value.url', $link['url'] );
            StyleMapper::write( $attrs, 'title.innerContent.desktop.value.target', $target );

            return null;
        }

        // `'more'`: a control under the copy, drawn only when `readmore_show`
        // is on (line 899).
        if ( ! $this->on( $atts, 'readmore_show' ) ) {
            return null;
        }

        $settings = [];
        StyleMapper::write( $settings, 'button.innerContent.desktop.value', [
            'text'       => $this->att( $atts, 'readmore_text', 'Read More' ),
            'linkUrl'    => $link['url'],
            'linkTarget' => $target,
        ] );

        $color = $this->color( $atts, 'read_more_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $settings, 'button.decoration.font.font.desktop.value.color', $color );
        }

        $this->engine->logConverted( 'button' );

        return $this->block( $id . '-button', 'divi/button', $settings );
    }
}
