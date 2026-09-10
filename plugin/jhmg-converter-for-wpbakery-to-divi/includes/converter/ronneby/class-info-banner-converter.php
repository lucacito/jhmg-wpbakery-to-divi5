<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `info_banner` → `divi/cta` over the banner picture.
 *
 * `modules/dfd-info-banner.php` is a picture with a title, a subtitle, a body
 * and a read-more link written over it, tinted by a two-stop gradient
 * (`gradient_color1`/`gradient_color2`). Divi's call-to-action module is the
 * same three pieces plus a button — `title.innerContent`,
 * `content.innerContent` and `button.*` (`cta/module.json`) — and the picture
 * becomes the module's own background image, with the gradient over it, which
 * is exactly how Divi layers the two (`module.decoration.background`).
 *
 * Divi's CTA is one title, so the subtitle goes at the top of the body in the
 * tag `subtitle_font_options` names, as `CtaConverter` places `vc_cta`'s `h4`.
 *
 * **No default bottom margin**: `.dfd-info-banner` carries none.
 */
class InfoBannerConverter extends RonnebyConverter {

    /** `content_alignment` ⇒ the module's text orientation. */
    const ALIGNMENTS = [
        'text-left'   => 'left',
        'text-center' => 'center',
        'text-right'  => 'right',
    ];

    /** The banner frame and its hover choreography, drawn by the theme. */
    const REPORTED_LOOK = [
        'style'                      => 'which of the banner frames is drawn',
        'delimiter_style'            => 'the rule under the title',
        'line_hide'                  => 'whether that rule is drawn',
        'image_effect'               => 'the picture\'s hover animation',
        'image_effect_heading'       => 'an editor section label',
        'shadow'                     => 'a drop shadow on the banner',
        'shadow_style'               => 'whether that shadow is permanent or on hover',
        'thumb_radius'               => 'the picture\'s corner radius',
        'img_width'                  => 'the crop width of the picture',
        'img_height'                 => 'its crop height',
        'vertical_content_alignment' => 'where the copy sits vertically',
        'readmore_style'             => 'which of the read-more controls is drawn',
        'more_show'                  => 'whether the read-more only appears on hover',
        'subtitle_h_heading'         => 'an editor section label',
        'title_d_heading'            => 'an editor section label',
        'title_t_heading'            => 'an editor section label',
        'subtitle_t_heading'         => 'an editor section label',
        'content_t_heading'          => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_info_banner_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'cta', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'title', 'content_alignment', 'tutorials' ] );

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'title' ) );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'title.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );

        $this->body( $node, $atts, $id, $attrs, $consumed );
        $this->background( $atts, $id, $attrs, $consumed );
        $this->button( $atts, $id, $attrs, $consumed );

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'content_alignment' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'info_banner draws its frame with the theme\'s own classes', $consumed );

        $this->engine->logConverted( 'cta' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/cta', $attrs );
    }

    /**
     * The body, with the subtitle above it.
     *
     * @param string[] $consumed
     */
    private function body( array $node, array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'subtitle', 'content' ] );

        // `content` is a `textarea` param, not the element's inner content.
        $html = trim( $this->nestedShortcodes( $this->editorHtml( $this->att( $atts, 'content', $this->rawContent( $node ) ) ), $id ) );

        $subtitle = trim( $this->att( $atts, 'subtitle' ) );
        if ( $subtitle !== '' ) {
            $tag  = strtolower( trim( RonnebyParams::fontOptions( $this->att( $atts, 'subtitle_font_options' ) )['tag'] ?? '' ) );
            $tag  = preg_match( '/^h[1-6]$/', $tag ) === 1 ? $tag : 'h4';
            $html = '<' . $tag . '>' . $subtitle . '</' . $tag . '>' . $html;

            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'the banner subtitle has its own typography (subtitle_font_options / subtitle_use_google_fonts / subtitle_custom_fonts); Divi\'s call to action has one title and one body font, so the subtitle was put at the top of the body as a heading'
            );
        }

        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $html );

        $consumed = array_merge( $consumed, [ 'subtitle_font_options', 'subtitle_use_google_fonts', 'subtitle_custom_fonts' ] );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'content.decoration.bodyFont.body.font',
            $id,
            $attrs,
            $consumed
        );
    }

    /**
     * The picture and the tint over it: `image` is the banner and
     * `gradient_color1`/`gradient_color2` a top-to-bottom wash the module puts
     * over it, which Divi layers the same way (a gradient above the image on
     * one background value).
     *
     * @param string[] $consumed
     */
    private function background( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'image', 'gradient_color1', 'gradient_color2' ] );

        $background = [];

        $attachment = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'image' ) );
        if ( $attachment > 0 ) {
            $url = $this->attachmentUrl( $attachment, 'full', $id );
            if ( $url !== null ) {
                $background['image'] = [ 'url' => $url ];
            }
        }

        $from = $this->color( $atts, 'gradient_color1', $id );
        $to   = $this->color( $atts, 'gradient_color2', $id );

        if ( $from !== null || $to !== null ) {
            // Divi appends the unit to a stop position itself; a `%` here drops
            // the whole background declaration, image included (CLAUDE.md).
            $background['gradient'] = [
                'enabled'   => 'on',
                'type'      => 'linear',
                'direction' => '180deg',
                'stops'     => [
                    [ 'color' => $from ?? $to, 'position' => '0' ],
                    [ 'color' => $to ?? $from, 'position' => '100' ],
                ],
            ];
        }

        if ( $background !== [] ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value', $background );
        }
    }

    /**
     * `read_more` says where the link goes — `box`, `title` or `more` — and
     * `readmore_text` labels it. Divi's CTA requires its `button` key
     * (`cta/module.json`, and the vendored validator's render-critical rule),
     * so the object is always written and its text is empty when the banner has
     * no read-more control.
     *
     * @param string[] $consumed
     */
    private function button( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'read_more', 'link', 'readmore_show', 'readmore_text' ] );

        $mode = strtolower( trim( $this->att( $atts, 'read_more', 'none' ) ) );
        $link = $this->link( $atts, 'link' );

        $button = [ 'text' => '' ];

        if ( $link !== [] && $mode !== 'none' ) {
            $this->reportLinkExtras( $atts, 'link', $id, true );

            $target = ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off';

            if ( $mode === 'box' || $mode === 'title' ) {
                StyleMapper::write( $attrs, 'module.advanced.link.desktop.value', [ 'url' => $link['url'], 'target' => $target ] );

                if ( $mode === 'title' ) {
                    $this->engine->logNotCarriedOver(
                        'layout',
                        $id,
                        'read_more="title" makes the banner title the link; Divi\'s call to action has no title link, so the whole module was linked instead'
                    );
                }
            } else {
                $button = [
                    'text'       => $this->att( $atts, 'readmore_text', 'Read More' ),
                    'linkUrl'    => $link['url'],
                    'linkTarget' => $target,
                ];
            }
        }

        StyleMapper::write( $attrs, 'button.innerContent.desktop.value', $button );
    }
}
