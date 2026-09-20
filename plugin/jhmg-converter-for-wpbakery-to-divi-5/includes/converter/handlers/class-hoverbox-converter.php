<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_hoverbox` → `divi/blurb` (+ a `divi/button` when `hover_add_button`),
 * registered approximate.
 *
 * `include/templates/shortcodes/vc_hoverbox.php` builds two faces of one card:
 * the front is the picture with `primary_title` over it, and the back — shown
 * on hover — is `hover_custom_background` with `hover_title`, the content and
 * an optional button. Divi has no flip card, so both faces are flattened into
 * one blurb: the picture and the primary title are the blurb's image and
 * heading, and the hover face becomes its body. The flip itself is reported
 * (spec §6).
 *
 * `blurb/module.json`: the picture is `imageIcon.innerContent…src` with
 * `useIcon` `off`, the heading `title.innerContent…text` and the body
 * `content.innerContent`.
 *
 * `config/content/shortcode-vc-hoverbox.php` calls `vc_config()->merge_default_params( $params )`
 * with no design value, so the element gets no default class CSS of its own,
 * and its wrapper (`.vc-hoverbox-wrapper`) is not `.wpb_content_element` and
 * has no margin rule in `js_composer.min.css`: it carries no bottom margin at
 * all, so none is written.
 */
class HoverboxConverter extends BaseWPBakeryConverter {

    /** `.vc-hoverbox-shape--<shape>` — the card's corners. */
    const SHAPES = [
        'square'  => '0px',
        'rounded' => '5px',
        'round'   => '50%',
    ];

    /** `$hover_background_color = $atts['hover_custom_background'] ?: '#EBEBEB';` */
    const DEFAULT_HOVER_BACKGROUND = '#ebebeb';

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_hoverbox_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        // `include/templates/shortcodes/vc_hoverbox.php:58` prints
        // `.vc-hoverbox-wrapper`; the element's config sets no
        // `element_default_class`, and `js_composer.min.css` gives that class
        // no margin, so the element carries none of its own.
        $style    = $this->mapStyle( 'blurb', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'image', 'primary_title', 'hover_title', 'align', 'reverse', 'shape', 'el_width',
            'primary_align', 'hover_align', 'hover_custom_background',
        ] );

        $this->picture( $atts, $id, $attrs );

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value.text', $this->att( $atts, 'primary_title' ) );
        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $this->body( $atts, $content, $id ) );

        $background = $this->color( $atts, 'hover_custom_background', $id ) ?? self::DEFAULT_HOVER_BACKGROUND;
        StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background );

        $radius = self::SHAPES[ strtolower( $this->att( $atts, 'shape', 'square' ) ) ] ?? self::SHAPES['square'];
        StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.radius', self::radius( $radius ) );

        $this->width( $atts, $attrs );
        $this->fonts( $atts, $id, $attrs, $consumed );

        $this->engine->logNotCarriedOver(
            'interaction',
            $id,
            'vc_hoverbox flips between a picture and a coloured panel on hover; Divi has no flip card, so both faces became one blurb'
        );

        $this->engine->logConverted( 'blurb' );

        $blocks = [ $this->block( $id, 'divi/blurb', $attrs ) ];

        $button = $this->button( $atts, $id, $consumed );
        if ( $button !== null ) {
            $blocks[] = $button;
        }

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * `wp_get_attachment_image_src( $image, 'large' )`, with WPBakery's own
     * `no_image.png` fallback left out: a placeholder picture is not content.
     */
    private function picture( array $atts, string $id, array &$attrs ): void {
        $attachment = (int) $this->att( $atts, 'image', '0' );
        $url        = $attachment > 0 ? $this->attachmentUrl( $attachment, 'large', $id ) : null;

        StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.useIcon', 'off' );

        if ( $url !== null ) {
            StyleMapper::write( $attrs, 'imageIcon.innerContent.desktop.value.src', $url );
        }
    }

    /** `hover_title` above the hover face's copy, as WPBakery prints it. */
    private function body( array $atts, string $content, string $id ): string {
        $html = trim( $this->nestedShortcodes( $this->editorHtml( $content ), $id ) );

        $title = trim( $this->att( $atts, 'hover_title' ) );
        if ( $title !== '' ) {
            $html = '<h4>' . $title . '</h4>' . $html;
        }

        return $html;
    }

    /** `$width = ctype_digit( $atts['el_width'] ) ? $atts['el_width'] . '%' : $atts['el_width'];` */
    private function width( array $atts, array &$attrs ): void {
        $width = trim( $this->att( $atts, 'el_width' ) );

        if ( preg_match( '/^\d+$/', $width ) === 1 && (int) $width > 0 && (int) $width < 100 ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.width', $width . '%' );
        }
    }

    /**
     * The two titles are `vc_custom_heading` field sets under `primary_title_`
     * and `hover_title_` prefixes (`config/content/shortcode-vc-hoverbox.php:28, :62`,
     * rendered by `WPBakeryShortCode_Vc_Hoverbox::getHeading()`).
     *
     * Divi's blurb has one title font (`blurb/module.json`
     * `title.decoration.font`), and the primary title *is* that title, so its
     * packed `font_container` and `google_fonts` are applied through the
     * StyleMapper the same way `CustomHeadingConverter` and `CtaConverter` do.
     * The hover title lives inside the body, where there is no field for it,
     * so its set is reported.
     *
     * @param string[] $consumed
     */
    private function fonts( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $mapper = new StyleMapper();

        foreach ( [ 'primary_title', 'hover_title' ] as $prefix ) {
            $consumed[] = 'use_custom_fonts_' . $prefix;

            foreach ( array_keys( $atts ) as $key ) {
                if ( is_string( $key ) && str_starts_with( $key, $prefix . '_' ) ) {
                    $consumed[] = $key;
                }
            }

            $container = trim( $this->att( $atts, $prefix . '_font_container' ) );
            $google    = trim( $this->att( $atts, $prefix . '_google_fonts' ) );

            if ( $container === '' && $google === '' ) {
                continue;
            }

            if ( $prefix === 'hover_title' ) {
                $this->engine->logNotCarriedOver(
                    'layout',
                    $id,
                    'the hover title\'s custom font (hover_title_font_container / hover_title_google_fonts); Divi\'s blurb has one title field, so the hover title is a plain <h4> inside the body'
                );

                continue;
            }

            $mapper->applyFontContainer( PackedParams::fontContainer( $container ), 'title.decoration.font.font', $attrs );
            $mapper->applyGoogleFonts( PackedParams::googleFonts( $google ), 'title.decoration.font.font', $attrs );
        }
    }

    /**
     * `renderButton()` renders a `vc_btn` from the `hover_btn_` field set, so
     * the button is a real `divi/button` placed after the blurb — Divi's blurb
     * has no button of its own.
     *
     * @param string[] $consumed
     */
    private function button( array $atts, string $id, array &$consumed ): ?array {
        $consumed[] = 'hover_add_button';

        $button   = BtnConverter::embeddedButton( $this->engine, $atts, 'hover_btn_', $id );
        $consumed = array_merge( $consumed, $button['consumed'] );

        if ( ! $this->on( $atts, 'hover_add_button' ) || $button['settings'] === [] ) {
            return null;
        }

        $this->engine->logConverted( 'button' );

        return $this->block( $id . '-button', 'divi/button', [ 'button' => $button['settings'] ] );
    }
}
