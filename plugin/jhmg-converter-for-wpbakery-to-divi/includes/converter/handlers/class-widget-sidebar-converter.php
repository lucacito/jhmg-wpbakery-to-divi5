<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_widget_sidebar` → `divi/sidebar`.
 *
 * `include/templates/shortcodes/vc_widget_sidebar.php` is `dynamic_sidebar( $sidebar_id )`
 * inside a `.wpb_widgetised_column.wpb_content_element` wrapper — which is
 * Divi's sidebar module exactly: `sidebar.innerContent.desktop.value.area`
 * (`sidebar/module.json`, read by `SidebarModule::render_callback()`), and the
 * same registered sidebar id on both sides, so the widgets carry over untouched.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class WidgetSidebarConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_sidebar_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'sidebar_id', 'title' ] );

        $area = trim( $this->att( $atts, 'sidebar_id' ) );

        if ( $area === '' ) {
            // `if ( '' === $sidebar_id ) { return null; }`
            $this->engine->logWarning( "vc_widget_sidebar {$id} names no sidebar; nothing was written for it." );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return [];
        }

        StyleMapper::write( $attrs, 'sidebar.innerContent.desktop.value.area', $area );

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            sprintf( 'the "%s" widget area is a theme registration; it has to exist on the Divi side too, or the module renders nothing', $area )
        );

        $this->engine->logConverted( 'sidebar' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        $blocks = [ $this->block( $id, 'divi/sidebar', $attrs ) ];

        // `wpb_widget_title()` prints it above the widgets, as every element's
        // `title` is printed.
        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }
}
