<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Autop;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A run of text between shortcodes → `divi/text`.
 *
 * WPBakery's editor never writes one, but real pages are full of them: a
 * paragraph left behind when an element was deleted, a theme demo pasted in,
 * a stray line between two rows. WordPress renders them through `wpautop()`,
 * so the converter does the same and keeps them exactly where they were,
 * rather than losing a page's worth of copy to a structure rule.
 *
 * No default bottom margin: a stray run carries none of WPBakery's element
 * classes, so its spacing is its paragraph's, which Divi's text module
 * reproduces on its own.
 */
class TextNodeConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $text = (string) ( $node['text'] ?? '' );

        if ( trim( $text ) === '' ) {
            return [];
        }

        $this->engine->logTextNode();
        $this->engine->logConverted( 'text' );

        return $this->block( (string) ( $node['id'] ?? uniqid( 'wbdc_text_' ) ), 'divi/text', [
            'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => trim( Autop::apply( trim( $text ) ) ) ] ] ],
        ] );
    }
}
