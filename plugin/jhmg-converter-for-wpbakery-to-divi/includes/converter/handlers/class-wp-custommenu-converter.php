<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_wp_custommenu` → `divi/menu`.
 *
 * `include/templates/shortcodes/vc_wp_custommenu.php` renders WordPress's own
 * `WP_Nav_Menu_Widget` with `nav_menu` as its menu id; `menu/module.json` keeps
 * the same id at `menu.advanced.menuId` (`MenuModule::render_callback()` reads
 * it), so the menu itself carries over untouched.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class WpCustommenuConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_menu_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'nav_menu', 'title' ] );

        $menu = trim( $this->att( $atts, 'nav_menu' ) );
        if ( $menu !== '' ) {
            StyleMapper::write( $attrs, 'menu.advanced.menuId.desktop.value', $menu );
        } else {
            $this->engine->logWarning( "vc_wp_custommenu {$id} names no menu; the module will show the first one Divi finds." );
        }

        $this->engine->logConverted( 'menu' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        $blocks = [ $this->block( $id, 'divi/menu', $attrs ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }
}
