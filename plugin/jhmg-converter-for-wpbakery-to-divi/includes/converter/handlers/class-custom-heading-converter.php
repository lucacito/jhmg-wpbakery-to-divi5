<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\DynamicContent;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_custom_heading` → `divi/heading`.
 *
 * `include/templates/shortcodes/vc_custom_heading.php` prints one `<hN>` whose
 * tag, size, alignment, line height and colour come from `font_container`, whose
 * family comes from `google_fonts` unless `use_theme_fonts` is on, and whose
 * text is either the `text` attribute or — when `source` is `post_title` — the
 * post's own title. Divi has all five as font settings and a dynamic-content
 * token for the title, so nothing here is an approximation.
 *
 * **No default bottom margin** (amendment §2, which asks for the finding to be
 * recorded). 9.0.1's `element_default_class` for this element is
 * `vc_do_custom_heading`, not `wpb_content_element`
 * (config/content/vc-custom-heading-element.php), and `js_composer.min.css`
 * carries no `.vc_do_custom_heading` rule at all — so the space below a heading
 * is the theme's own `h1…h6` margin, which Divi's heading module inherits the
 * same way. (9.0.1's editor seeds the design options with
 * `margin-bottom: 0.625rem`; when a page carries that it arrives in `css` and
 * the StyleMapper writes it, like any other design option.) The page-level note
 * is a warning rather than one `not_carried_over` entry per heading: a real
 * page has dozens of them and they would say the same thing every time.
 */
class CustomHeadingConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_heading_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // A heading carries the theme's own margins; `mapStyle()` would add
        // WPBakery's 35 px element margin, which this element never has.
        $style    = $this->mapStyle( 'heading', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = $style['handled_keys'];

        $font_path = (string) StyleMapper::fontPathFor( 'heading' );
        $mapper    = new StyleMapper();

        $font_container = PackedParams::fontContainer( $this->att( $atts, 'font_container' ) );
        if ( $font_container !== [] ) {
            $mapper->applyFontContainer( $font_container, $font_path, $attrs );
            $consumed[] = 'font_container';
        }

        // `Vc_Custom_Heading::getStyles()` reads `google_fonts` only when
        // `use_theme_fonts` is off.
        if ( $this->on( $atts, 'use_theme_fonts' ) ) {
            $consumed[] = 'use_theme_fonts';
            $consumed[] = 'google_fonts';
        } elseif ( $this->att( $atts, 'google_fonts' ) !== '' ) {
            $mapper->applyGoogleFonts( PackedParams::googleFonts( $this->att( $atts, 'google_fonts' ) ), $font_path, $attrs );
            $consumed[] = 'google_fonts';
        }

        $link = $this->link( $atts, 'link' );
        if ( $link !== [] ) {
            $this->reportLinkExtras( $atts, 'link', $id );
            StyleMapper::write( $attrs, 'module.advanced.link.desktop.value', [
                'url'    => $link['url'],
                'target' => ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off',
            ] );
            $consumed[] = 'link';
        }

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->text( $atts, $consumed ) );

        $this->engine->logWarning( 'vc_custom_heading carries no WPBakery margin of its own; heading margin follows the theme.' );
        $this->engine->logConverted( 'heading' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/heading', $attrs );
    }

    /**
     * The heading's text: the `text` attribute, or the post title as a Divi
     * dynamic-content token when `source` says so — the template's
     * `if ( 'post_title' === $source ) { $text = get_the_title( get_the_ID() ); }`,
     * kept dynamic instead of frozen into the page.
     *
     * @param string[] $consumed
     */
    private function text( array $atts, array &$consumed ): string {
        $consumed[] = 'text';

        if ( $this->att( $atts, 'source' ) === 'post_title' ) {
            $consumed[] = 'source';

            return DynamicContent::token( 'post_title' );
        }

        return $this->att( $atts, 'text' );
    }
}
