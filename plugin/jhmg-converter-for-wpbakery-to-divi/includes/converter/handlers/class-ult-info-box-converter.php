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
 * `bsf-info-box` (Ultimate Addons) → `divi/blurb`, registered approximate.
 *
 * `modules/ultimate_info_box.php::icon_boxes()` draws an icon (or a custom
 * image), a heading in `heading_tag`, the description and an optional read-more
 * link — which is Divi's blurb: `imageIcon.innerContent` (`useIcon`, `icon` or
 * `src`), `title.innerContent…text` and `content.innerContent`
 * (`blurb/module.json`, `BlurbModule::render_callback()`).
 *
 * The whole element is the add-on's own `.aio-icon-box`, so what does not
 * convert is its box: `hover_effect`, the icon's border ring and the
 * `pos="square_box"` layout are its stylesheet's, and are reported.
 *
 * Approximate because the source read here is 3.19.3 while a live site may run
 * a newer build (spec §7).
 */
class UltInfoBoxConverter extends BaseWPBakeryConverter {

    /** `$ult_info_box_settings['pos']` ⇒ Divi's `imageIcon.advanced.placement`. */
    const PLACEMENT = [
        'default'    => 'top',
        'top'        => 'top',
        'left'       => 'left',
        'right'      => 'right',
        'square_box' => 'top',
    ];

    /** The look attributes the add-on's own stylesheet draws. */
    const REPORTED_LOOK = [
        'hover_effect'        => 'the box hover animation',
        'icon_style'          => 'the ring or square drawn behind the icon',
        'icon_color_bg'       => 'the colour of that ring',
        'icon_color_border'   => 'its border colour',
        'icon_border_style'   => 'its border style',
        'icon_border_size'    => 'its border width',
        'icon_border_radius'  => 'its corner radius',
        'icon_border_spacing' => 'the space it leaves around the glyph',
        'icon_animation'      => 'the icon entrance animation',
        'box_min_height'      => 'a minimum box height',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_info_box_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'blurb', $this->withCss( $node, 'css_info_box' ) );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'css_info_box', 'icon_type', 'icon', 'icon_img', 'img_width', 'icon_size', 'icon_color',
            'title', 'heading_tag', 'pos', 'read_more', 'read_text', 'link',
            'box_border_style', 'box_border_width', 'box_border_color', 'box_bg_color',
        ], array_keys( self::REPORTED_LOOK ) );

        $this->icon( $atts, $id, $attrs );
        $this->heading( $atts, $attrs );
        $this->body( $node, $id, $attrs );
        $this->decorate( $atts, $id, $attrs );
        $this->placement( $atts, $attrs );
        $this->fonts( $atts, $id, $attrs, $consumed );
        $this->look( $atts, $id );

        $blocks = [ $this->block( $id, 'divi/blurb', $attrs ) ];

        $button = $this->readMore( $atts, $id, $attrs );
        if ( $button !== null ) {
            $blocks[] = $button;
        }

        $this->engine->logConverted( 'blurb' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * `icon_type` is `selector` (the Font Icon Manager) or `custom` (an
     * uploaded image), and the template hands both to `[just_icon]`.
     */
    private function icon( array $atts, string $id, array &$attrs ): void {
        if ( $this->att( $atts, 'icon_type', 'selector' ) === 'custom' ) {
            $image = UltimateFields::media( $this->att( $atts, 'icon_img' ) );
            $url   = $image['url'] ?? '';

            StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.useIcon', 'off' );

            if ( $url !== '' ) {
                StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.src', $url );
            } elseif ( isset( $image['id'] ) ) {
                $this->engine->logUnresolvedMedia( $id, (int) $image['id'] );
            }

            $width = UltimateFields::responsive( $this->att( $atts, 'img_width' ) );
            if ( $width !== '' ) {
                StyleMapper::write( $attrs, 'imageIcon.decoration.sizing.desktop.value.width', rtrim( $width, 'px' ) . 'px' );
            }

            return;
        }

        $class = UltimateFields::iconClass( $this->att( $atts, 'icon' ) );
        if ( $class === '' ) {
            return;
        }

        $mapped = IconMap::fromClass( $class );
        if ( ! $mapped['exact'] ) {
            $this->engine->logNotCarriedOver(
                'addon',
                $id,
                sprintf( 'icon "%s" comes from the add-on\'s own icon manager and is not in Divi\'s Font Awesome set; a star stands in for it', $this->att( $atts, 'icon' ) )
            );
        }

        StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.useIcon', 'on' );
        StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.icon', $mapped['icon'] );

        $color = $this->color( $atts, 'icon_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'imageIcon.advanced.color.desktop.value', $color );
        }

        $size = UltimateFields::responsive( $this->att( $atts, 'icon_size' ) );
        if ( $size !== '' ) {
            StyleMapper::write( $attrs, 'imageIcon.decoration.sizing.desktop.value.width', rtrim( $size, 'px' ) . 'px' );
        }
    }

    private function heading( array $atts, array &$attrs ): void {
        StyleMapper::write( $attrs, 'title.innerContent.desktop.value.text', $this->att( $atts, 'title' ) );

        $tag = strtolower( trim( $this->att( $atts, 'heading_tag', 'h3' ) ) );
        if ( preg_match( '/^h[1-6]$/', $tag ) === 1 ) {
            StyleMapper::write( $attrs, 'title.decoration.font.font.desktop.value.headingLevel', $tag );
        }
    }

    /**
     * The description is the element's content. `bsf-info-box` is not a
     * raw-content tag, so `NodeTree` parsed it into children; the text is what
     * they were parsed from.
     */
    private function body( array $node, string $id, array &$attrs ): void {
        $content = $this->rawContent( $node );

        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            trim( $this->nestedShortcodes( $this->editorHtml( $content ), $id ) )
        );
    }

    private function decorate( array $atts, string $id, array &$attrs ): void {
        $background = $this->color( $atts, 'box_bg_color', $id );
        if ( $background !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background );
        }

        $border = $this->color( $atts, 'box_border_color', $id );
        $style  = strtolower( trim( $this->att( $atts, 'box_border_style' ) ) );

        if ( $border === null || $style === '' || $style === 'none' ) {
            return;
        }

        $width = trim( $this->att( $atts, 'box_border_width', '1' ) );

        StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.styles.all', [
            'width' => rtrim( $width, 'px' ) . 'px',
            'style' => $style,
            'color' => $border,
        ] );
    }

    private function placement( array $atts, array &$attrs ): void {
        $pos = strtolower( trim( $this->att( $atts, 'pos', 'default' ) ) );

        StyleMapper::write(
            $attrs,
            'imageIcon.advanced.placement.desktop.value',
            self::PLACEMENT[ $pos ] ?? 'top'
        );
    }

    /**
     * `title_font_*` and `desc_font_*` are the add-on's own typography fields:
     * a responsive size and line height, a colour and a weight/style string.
     *
     * @param string[] $consumed
     */
    private function fonts( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $map = [
            'title' => 'title.decoration.font.font.desktop.value',
            'desc'  => 'content.decoration.bodyFont.body.font.desktop.value',
        ];

        foreach ( $map as $prefix => $path ) {
            foreach ( array_keys( $atts ) as $key ) {
                if ( is_string( $key ) && str_starts_with( $key, $prefix . '_font' ) ) {
                    $consumed[] = $key;
                }
            }
            $consumed[] = $prefix . '_text_typography';

            $size = UltimateFields::responsive( $this->att( $atts, $prefix . '_font_size' ) );
            if ( $size !== '' ) {
                StyleMapper::write( $attrs, $path . '.size', $size );
            }

            $line = UltimateFields::responsive( $this->att( $atts, $prefix . '_font_line_height' ) );
            if ( $line !== '' ) {
                StyleMapper::write( $attrs, $path . '.lineHeight', $line );
            }

            $color = $this->color( $atts, $prefix . '_font_color', $id );
            if ( $color !== null ) {
                StyleMapper::write( $attrs, $path . '.color', $color );
            }

            // `font-weight:bold;` — the add-on stores a CSS fragment.
            if ( str_contains( strtolower( $this->att( $atts, $prefix . '_font_style' ) ), 'bold' ) ) {
                StyleMapper::write( $attrs, $path . '.weight', '700' );
            }
        }
    }

    /**
     * `read_more` is `none`, `title` (the heading becomes the link), `more` (a
     * link under the copy) or `box` (the whole box is a link). Divi's blurb has
     * a title link and a module link; a `more` link has no home, so it becomes
     * a button after the blurb.
     */
    private function readMore( array $atts, string $id, array &$attrs ): ?array {
        $mode = strtolower( trim( $this->att( $atts, 'read_more', 'none' ) ) );
        $link = $this->link( $atts, 'link' );

        if ( $mode === 'none' || $link === [] ) {
            return null;
        }

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

        $this->engine->logConverted( 'button' );

        return $this->block( $id . '-button', 'divi/button', [
            'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => [
                'text'       => $this->att( $atts, 'read_text', 'Read More' ),
                'linkUrl'    => $link['url'],
                'linkTarget' => $target,
            ] ] ] ],
        ] );
    }

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
            'bsf-info-box is drawn by Ultimate Addons\' own stylesheet: ' . implode( ', ', $described ) . '; Divi\'s blurb draws its own box'
        );
    }
}
