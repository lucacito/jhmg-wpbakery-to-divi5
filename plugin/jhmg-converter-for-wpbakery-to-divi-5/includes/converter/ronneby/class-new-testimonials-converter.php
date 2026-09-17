<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `new_testimonials` → `divi/testimonial`.
 *
 * **One testimonial, not a list.** The brief's table reads the element as a
 * `param_group` of entries; `modules/dfd-testimonials.php` maps no group at
 * all — `author`, `subtitle`, `description` and `image` are four flat params,
 * and a page shows several by placing several shortcodes (232 of them across
 * the corpus, each with one quote). So the conversion is one Divi testimonial
 * per element: `author.innerContent`, `jobTitle.innerContent`,
 * `content.innerContent` and `portrait.innerContent.desktop.value.src`
 * (`testimonial/module.json`, `TestimonialModule.php:140-190`).
 *
 * Divi's testimonial draws its own quote mark (`quoteIcon.decoration.icon`),
 * which is why `quote_color`, `quote_size` and `quote_hide` are reported rather
 * than mapped: the module has one icon setting and Ronneby styles a glyph the
 * theme's stylesheet draws.
 *
 * **No default bottom margin**: `.dfd-testimonial-item` carries none.
 */
class NewTestimonialsConverter extends RonnebyConverter {

    /** `align` — the module's own `align-left|align-center|align-right`. */
    const ALIGNMENTS = [
        'align-left'   => 'left',
        'align-center' => 'center',
        'align-right'  => 'right',
    ];

    /** The card's frame, its quote mark and the disc behind the portrait. */
    const REPORTED_LOOK = [
        'main_style'           => 'which of the three testimonial frames is drawn',
        'main_layout'          => 'which of the twelve layouts places the parts',
        'thumb_radius'         => 'the portrait\'s corner radius',
        'thumb_border_width'   => 'the ring around the portrait',
        'thumb_color'          => 'its colour',
        'image_offset'         => 'how far the portrait overlaps the card',
        'line_width'           => 'the width of the rule under the quote',
        'line_border'          => 'its thickness',
        'line_color'           => 'its colour',
        'line_hide'            => 'whether it is drawn at all',
        'bg_radius'            => 'the card\'s corner radius',
        'quote_color'          => 'the colour of the quote mark',
        'quote_size'           => 'its size',
        'quote_margin'         => 'the space under it',
        'quote_hide'           => 'whether it is drawn at all',
        'enabled_bg_decor'     => 'a decorative disc behind the portrait',
        'bg_size'              => 'that disc\'s size',
        'bg_border_radius'     => 'its corner radius',
        'bg_background'        => 'its colour',
        'icon_offset_bottom'   => 'how far it sits from the bottom',
        'title_uppercase'      => 'upper-casing the author\'s name',
        'offset_after_thumb'   => 'the space under the portrait',
        'offset_before_content' => 'the space above the quote',
        'title_t_heading'      => 'an editor section label',
        'subtitle_t_heading'   => 'an editor section label',
        'content_t_heading'    => 'an editor section label',
        'thumb_t_heading'      => 'an editor section label',
        'del_t_heading'        => 'an editor section label',
        'bg_t_heading'         => 'an editor section label',
        'quote_t_heading'      => 'an editor section label',
        'extra_features_elements_heading' => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_testimonial_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'author', 'subtitle', 'description', 'align', 'tutorials' ] );

        StyleMapper::write( $attrs, 'author.innerContent.desktop.value', $this->att( $atts, 'author' ) );
        StyleMapper::write( $attrs, 'jobTitle.innerContent.desktop.value', $this->att( $atts, 'subtitle' ) );
        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            trim( $this->nestedShortcodes( $this->editorHtml( $this->att( $atts, 'description' ) ), $id ) )
        );

        $this->portrait( $atts, $id, $attrs, $consumed );
        $this->fonts( $atts, $id, $attrs, $consumed );

        $consumed[] = 'bg_color';
        $background = $this->color( $atts, 'bg_color', $id );
        if ( $background !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background );
        }

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'align' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'new_testimonials draws its card with the theme\'s own classes', $consumed );

        $this->engine->logConverted( 'testimonial' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/testimonial', $attrs );
    }

    /** @param string[] $consumed */
    private function portrait( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'image', 'thumb_size' ] );

        $attachment = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'image' ) );
        if ( $attachment > 0 ) {
            $url = $this->attachmentUrl( $attachment, 'full', $id );
            if ( $url !== null ) {
                StyleMapper::write( $attrs, 'portrait.innerContent.desktop.value.src', $url );
            }
        }

        // `portrait.decoration.sizing` is the one decoration group Divi's
        // testimonial portrait has (`TestimonialModule.php:760`); it carries no
        // border, so `thumb_radius` is reported with the rest of the frame.
        $size = $this->pixels( $atts, 'thumb_size' );
        if ( $size !== '' ) {
            StyleMapper::write( $attrs, 'portrait.decoration.sizing.desktop.value.width', $size );
        }
    }

    /** @param string[] $consumed */
    private function fonts( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'author.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyFontSet(
            $atts,
            [ 'options' => 'subtitle_font_options', 'toggle' => 'subtitle_use_google_fonts', 'family' => 'subtitle_custom_fonts' ],
            'jobTitle.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyFontSet(
            $atts,
            [ 'options' => 'content_font_options' ],
            'content.decoration.bodyFont.body.font',
            $id,
            $attrs,
            $consumed
        );
    }
}
