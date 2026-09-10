<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_images_carousel` → `divi/slider` › one `divi/slide` per image.
 *
 * WPBakery runs a Swiper over a list of attachments
 * (`include/templates/shortcodes/vc_images_carousel.php`); Divi's slider is one
 * child module per slide, and a slide shows a picture either beside its text
 * (`image.innerContent.desktop.value.src`) or behind it
 * (`module.decoration.background…image.url`). A carousel has no text at all, so
 * the picture is the slide's background — full width, the way WPBakery draws it.
 *
 * `slides_per_view` above 1, `partial_view` and `mode="vertical"` are Swiper
 * features Divi's slider has no equivalent for, and are reported (spec §6).
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class ImagesCarouselConverter extends BaseWPBakeryConverter {

    /** `config/content/shortcode-vc-images-carousel.php`: `'value' => '5000'` (ms). */
    const DEFAULT_SPEED = '5000';

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_carousel_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'images', 'img_size', 'title' ] );

        $slides = $this->slides( $atts, $id, $consumed );

        $this->playback( $atts, $attrs, $consumed );
        $this->controls( $atts, $attrs, $consumed );
        $this->reportSwiperOnly( $atts, $id, $consumed );

        $this->engine->logConverted( 'slider' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        if ( $slides === [] ) {
            $this->engine->logWarning( "vc_images_carousel {$id} names no images; nothing was written for it." );

            return [];
        }

        $blocks = [ $this->block( $id, 'divi/slider', $attrs, $slides ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * One slide per attachment, its picture behind it and its custom link on
     * the slide as a whole (`module.advanced.link`, which is what
     * `SlideModule` reads for a slide-wide link).
     *
     * @param string[] $consumed
     * @return array<int, array<string,mixed>>
     */
    private function slides( array $atts, string $id, array &$consumed ): array {
        $consumed = array_merge( $consumed, [ 'onclick', 'custom_links', 'custom_links_target' ] );

        $size   = $this->att( $atts, 'img_size', 'thumbnail' );
        $links  = array_values( array_filter( array_map(
            'trim',
            preg_split( '/[\r\n]+/', PackedParams::safeValue( $this->att( $atts, 'custom_links' ) ) ) ?: []
        ) ) );
        $target = $this->att( $atts, 'custom_links_target' ) === '_blank' ? 'on' : 'off';
        $custom = $this->att( $atts, 'onclick' ) === 'custom_link';

        $slides = [];
        $index  = 0;

        foreach ( explode( ',', $this->att( $atts, 'images' ) ) as $attachment ) {
            $attachment = trim( $attachment );
            if ( $attachment === '' || ! ctype_digit( $attachment ) ) {
                continue;
            }

            $index++;
            $slide_id = $id . '-slide-' . $index;
            $url      = $this->attachmentUrl( (int) $attachment, $size, $slide_id );

            $settings = [];
            if ( $url !== null ) {
                StyleMapper::write( $settings, 'module.decoration.background.desktop.value.image.url', $url );
                StyleMapper::write( $settings, 'module.decoration.background.desktop.value.image.size', 'cover' );
            }

            if ( $custom && isset( $links[ $index - 1 ] ) && $links[ $index - 1 ] !== '' ) {
                StyleMapper::write( $settings, 'module.advanced.link.desktop.value.url', $links[ $index - 1 ] );
                StyleMapper::write( $settings, 'module.advanced.link.desktop.value.target', $target );
            }

            $this->engine->logConverted( 'slide' );
            $slides[] = $this->block( $slide_id, 'divi/slide', $settings );
        }

        if ( $slides !== [] && $this->att( $atts, 'onclick' ) === 'link_image' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                'onclick="link_image" opens each picture in WPBakery\'s lightbox; a Divi slide has no lightbox, so the slides are not clickable'
            );
        }

        return $slides;
    }

    /**
     * `autoplay` and `speed` → `module.advanced.auto` / `autoSpeed`
     * (`slider/module.json`; `SliderModule::module_script_data()` reads them,
     * both in milliseconds as WPBakery's `speed` already is).
     *
     * @param string[] $consumed
     */
    private function playback( array $atts, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'autoplay', 'speed', 'wrap' ] );

        if ( ! $this->on( $atts, 'autoplay' ) ) {
            return;
        }

        StyleMapper::write( $attrs, 'module.advanced.auto.desktop.value', 'on' );

        $speed = trim( $this->att( $atts, 'speed', self::DEFAULT_SPEED ) );
        if ( ctype_digit( $speed ) && (int) $speed > 0 ) {
            StyleMapper::write( $attrs, 'module.advanced.autoSpeed.desktop.value', $speed );
        }
    }

    /**
     * The two "hide" toggles → Divi's `arrows.advanced.show` and
     * `pagination.advanced.show` (`slider/module.json`).
     *
     * @param string[] $consumed
     */
    private function controls( array $atts, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'hide_prev_next_buttons', 'hide_pagination_control' ] );

        StyleMapper::write( $attrs, 'arrows.advanced.show.desktop.value', $this->on( $atts, 'hide_prev_next_buttons' ) ? 'off' : 'on' );
        StyleMapper::write( $attrs, 'pagination.advanced.show.desktop.value', $this->on( $atts, 'hide_pagination_control' ) ? 'off' : 'on' );
    }

    /**
     * The Swiper options Divi's slider has nothing for: more than one slide at
     * a time, a peek at the next one, and a vertical run.
     *
     * @param string[] $consumed
     */
    private function reportSwiperOnly( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'slides_per_view', 'partial_view', 'mode' ] );

        $per_view = (int) $this->att( $atts, 'slides_per_view', '1' );
        if ( $per_view > 1 ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'slides_per_view="%d" shows several pictures at once; Divi\'s slider shows one slide at a time', $per_view )
            );
        }

        if ( $this->on( $atts, 'partial_view' ) ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'partial_view lets the next picture peek in at the edge; Divi\'s slider fills the whole width with one slide'
            );
        }

        if ( strtolower( $this->att( $atts, 'mode' ) ) === 'vertical' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'mode="vertical" slides the carousel up and down; Divi\'s slider always slides sideways'
            );
        }
    }
}
