<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_raw_html` → `divi/code`; `vc_raw_js` → nothing, reported.
 *
 * Both share one template: the content is
 * `rawurldecode( base64_decode( wp_strip_all_tags( $content ) ) )`
 * (`include/templates/shortcodes/vc_raw_html.php`, which the "Raw JS" element
 * reuses). `divi/code` prints markup (`content.innerContent`,
 * `code/module.json`), so `vc_raw_html` is the decode and nothing else; what
 * the person converting may publish of it is decided afterwards, over the
 * whole tree, by `MarkupSanitiser`.
 *
 * `vc_raw_js` is only ever a script. The converter never writes one: the
 * element is reported under `not_carried_over` kind `custom_code` with its
 * length, and no block is emitted.
 *
 * `shortcode-vc-raw-html.php` sets `element_default_class` to
 * `wpb_content_element` and seeds Design Options with `margin-bottom: 35px`,
 * so `vc_raw_html` keeps the usual 35 px content default.
 */
class RawHtmlConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_code_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        if ( (string) ( $node['tag'] ?? '' ) === 'vc_raw_js' ) {
            $this->engine->logNotCarriedOver(
                'custom_code',
                $id,
                'Raw JS element (' . strlen( PackedParams::rawHtml( $content ) ) . ' characters of script) not converted; if the page still needs it, add it through Divi > Theme Options > Integration'
            );

            return [];
        }

        $style = $this->mapStyle( 'generic', $node );

        $this->engine->logConverted( 'code' );
        $this->logUnmappedSettings( $id, $atts, $style['handled_keys'], 'vc_raw_html' );

        return $this->codeBlock( $id, PackedParams::rawHtml( $content ), $style['divi_attrs'] );
    }
}
