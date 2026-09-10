<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_raw_html` and `vc_raw_js` → `divi/code`.
 *
 * Both share one template: the content is
 * `rawurldecode( base64_decode( wp_strip_all_tags( $content ) ) )`
 * (`include/templates/shortcodes/vc_raw_html.php`, which the "Raw JS" element
 * reuses), printed verbatim. `divi/code` is the module that prints raw markup
 * verbatim too (`content.innerContent`, `code/module.json`), so the conversion
 * is the decode and nothing else — script tags included, which is the whole
 * point of the element.
 *
 * `vc_raw_js` has no Design Options in either release (the template says so),
 * so its `css` is simply absent; the StyleMapper handles that on its own.
 *
 * The two tags also differ on the default bottom margin: `shortcode-vc-raw-html.php`
 * sets `element_default_class` to `wpb_content_element` and seeds Design Options
 * with `margin-bottom: 35px`, so `vc_raw_html` keeps the usual 35 px content
 * default. `shortcode-vc-raw-js.php` has neither — no `element_default_class`,
 * no Design Options tab at all — so `vc_raw_js` carries no margin.
 */
class RawHtmlConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_code_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        $margin_kind = ( (string) ( $node['tag'] ?? '' ) === 'vc_raw_js' ) ? 'none' : 'content';
        $style       = $this->mapStyle( 'generic', $node, $margin_kind );

        $this->engine->logConverted( 'code' );
        $this->logUnmappedSettings( $id, $atts, $style['handled_keys'], (string) ( $node['tag'] ?? '' ) );

        return $this->codeBlock( $id, PackedParams::rawHtml( $content ), $style['divi_attrs'] );
    }
}
