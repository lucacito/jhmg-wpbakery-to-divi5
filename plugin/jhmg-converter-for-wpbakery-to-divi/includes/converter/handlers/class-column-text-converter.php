<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_column_text` → `divi/text`.
 *
 * The whole element is its content: `include/templates/shortcodes/vc_column_text.php`
 * prints `wpb_js_remove_wpautop( $content, true )` inside two divs that carry
 * nothing but classes, so the conversion is that one call (`editorHtml()`) plus
 * the design options every element has.
 *
 * A shortcode a theme or add-on registered can sit inside that HTML; spec §7
 * says what happens to it, and `nestedShortcodes()` does it.
 */
class ColumnTextConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_text_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        $html = $this->nestedShortcodes( $this->editorHtml( $content ), $id );

        $style = $this->mapStyle( 'text', $node );

        $this->engine->logConverted( 'text' );
        $this->logUnmappedSettings( $id, $atts, $style['handled_keys'], (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/text', $this->deepMergeSettings(
            $style['divi_attrs'],
            [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => trim( $html ) ] ] ] ]
        ) );
    }
}
