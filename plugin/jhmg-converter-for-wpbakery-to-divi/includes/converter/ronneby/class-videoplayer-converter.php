<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `videoplayer` → `divi/video`, with a `divi/heading` and a `divi/text` for the
 * caption the element prints beside its play button.
 *
 * `modules/dfd-video-module.php` draws a poster (`video_thumb`) with a play
 * button over it that opens `video_link` in a lightbox, and a `title` and
 * `subtitle` beside it. Divi's video module is the player and its poster —
 * `video.innerContent.desktop.value.src` and
 * `thumbnail.innerContent.desktop.value.src` (`VideoModule.php:129-190, 395`) —
 * so the two lines of copy become their own blocks rather than being dropped.
 *
 * Every `video_link` in the corpus is a `youtu.be` URL, which Divi's own module
 * resolves through `et_pb_check_oembed_provider()` exactly as WordPress's
 * `[embed]` does for WPBakery (`VideoConverter`).
 *
 * **No default bottom margin**: `.dfd-videoplayer` carries none.
 */
class VideoplayerConverter extends RonnebyConverter {

    /** `button_align` — the module's own text classes. */
    const ALIGNMENTS = [
        'text-left'   => 'left',
        'text-center' => 'center',
        'text-right'  => 'right',
    ];

    /** The play button and its ring, drawn by the theme's own stylesheet. */
    const REPORTED_LOOK = [
        'main_style'                => 'which of the two player frames is drawn',
        'main_layout'               => 'which of the four layouts places the parts',
        'videoanimation'            => 'the animation the poster plays',
        'round_button'              => 'a round play button',
        'bg_size'                   => 'the play button\'s size',
        'bg_radius'                 => 'its corner radius',
        'icon_color'                => 'the play glyph\'s colour',
        'icon_bg_color'             => 'the disc behind it',
        'fill_color_start'          => 'the first stop of that disc\'s gradient',
        'fill_color_end'            => 'its second stop',
        'button_b_color'            => 'the play button\'s border colour',
        'button_b_width'            => 'its border width',
        'shadow'                    => 'a drop shadow on the button',
        'thumb_radius'              => 'the poster\'s corner radius',
        'line_style'                => 'the rule under the caption',
        'line_color'                => 'its colour',
        'line_hide'                 => 'whether it is drawn at all',
        'lightbox_fill_color_start' => 'the first stop of the lightbox backdrop',
        'lightbox_fill_color_end'   => 'its second stop',
        'etc_h'                     => 'an editor section label',
        'bg_t_h'                    => 'an editor section label',
        'del_t_heading'             => 'an editor section label',
        'title_t_heading'           => 'an editor section label',
        'subtitle_t_heading'        => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_videoplayer_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // `el_class_name` is this element's own spelling of `el_class`, which
        // the StyleMapper reads; the alias is all it needs.
        if ( trim( $this->att( $atts, 'el_class' ) ) === '' && trim( $this->att( $atts, 'el_class_name' ) ) !== '' ) {
            $node['atts']['el_class'] = $this->att( $atts, 'el_class_name' );
        }

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'video_link', 'video_thumb', 'el_class_name', 'button_align', 'tutorials' ] );

        $link = trim( $this->att( $atts, 'video_link' ) );
        StyleMapper::write( $attrs, 'video.innerContent.desktop.value.src', $link );

        if ( $link === '' ) {
            $this->engine->logWarning( "videoplayer {$id} has no video URL; the module was written with an empty source." );
        }

        $attachment = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'video_thumb' ) );
        if ( $attachment > 0 ) {
            $poster = $this->attachmentUrl( $attachment, 'full', $id );
            if ( $poster !== null ) {
                StyleMapper::write( $attrs, 'thumbnail.innerContent.desktop.value.src', $poster );
            }
        }

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'button_align' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'videoplayer draws its play button and lightbox with the theme\'s own classes', $consumed );

        $blocks = [ $this->block( $id, 'divi/video', $attrs ) ];
        $this->engine->logConverted( 'video' );

        foreach ( $this->caption( $atts, $id, $align, $consumed ) as $block ) {
            $blocks[] = $block;
        }

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * The `title` and `subtitle` the module prints beside the play button, each
     * in the tag and typography its own `*_font_options` names.
     *
     * @param string[] $consumed
     * @return array<int, array<string,mixed>>
     */
    private function caption( array $atts, string $id, ?string $align, array &$consumed ): array {
        $consumed = array_merge( $consumed, [ 'title', 'title_font_options', 'subtitle', 'subtitle_font_options' ] );

        $blocks  = [];
        $ignored = [];

        $title = trim( $this->att( $atts, 'title' ) );
        if ( $title !== '' ) {
            $attrs = [];
            $this->applyFontSet( $atts, [ 'options' => 'title_font_options' ], 'title.decoration.font.font', $id, $attrs, $ignored );

            if ( $align !== null ) {
                StyleMapper::write( $attrs, 'title.decoration.font.font.desktop.value.textAlign', $align );
            }

            StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $title );

            $this->engine->logConverted( 'heading' );
            $blocks[] = $this->block( $id . '-title', 'divi/heading', $attrs );
        }

        $subtitle = trim( $this->att( $atts, 'subtitle' ) );
        if ( $subtitle !== '' ) {
            $attrs = [];
            $this->applyFontSet( $atts, [ 'options' => 'subtitle_font_options' ], 'content.decoration.bodyFont.body.font', $id, $attrs, $ignored );

            if ( $align !== null ) {
                StyleMapper::write( $attrs, 'content.decoration.bodyFont.body.font.desktop.value.textAlign', $align );
            }

            StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $subtitle );

            $this->engine->logConverted( 'text' );
            $blocks[] = $this->block( $id . '-subtitle', 'divi/text', $attrs );
        }

        return $blocks;
    }
}
