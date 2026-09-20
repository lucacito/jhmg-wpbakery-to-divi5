<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_wp_text` → `divi/text`.
 *
 * WordPress's text widget is a title and a body of HTML, and its content is a
 * raw editor field (`ShortcodeParser::RAW_CONTENT_TAGS`), so it converts the
 * same way `vc_column_text` does: `wpb_js_remove_wpautop()` over the content
 * and a heading block for the title.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class WpTextConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_wp_text_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        $style = $this->mapStyle( 'text', $node );
        $attrs = $style['divi_attrs'];

        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            trim( $this->nestedShortcodes( $this->editorHtml( $content ), $id ) )
        );

        $this->engine->logConverted( 'text' );
        $this->logUnmappedSettings( $id, $atts, array_merge( $style['handled_keys'], [ 'title', 'content' ] ), (string) ( $node['tag'] ?? '' ) );

        $blocks = [ $this->block( $id, 'divi/text', $attrs ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }
}
