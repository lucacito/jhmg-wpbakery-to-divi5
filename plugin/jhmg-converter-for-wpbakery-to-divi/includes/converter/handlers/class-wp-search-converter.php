<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_wp_search` → `divi/search`.
 *
 * WordPress's `WP_Widget_Search` is a form with one field and a button, and so
 * is Divi's search module; the element carries nothing but its optional
 * heading (`config/wp/shortcode-vc-wp-search.php` maps `title` alone).
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class WpSearchConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_search_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style = $this->mapStyle( 'generic', $node );

        $this->engine->logConverted( 'search' );
        $this->logUnmappedSettings( $id, $atts, array_merge( $style['handled_keys'], [ 'title' ] ), (string) ( $node['tag'] ?? '' ) );

        $blocks = [ $this->block( $id, 'divi/search', $style['divi_attrs'] ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }
}
