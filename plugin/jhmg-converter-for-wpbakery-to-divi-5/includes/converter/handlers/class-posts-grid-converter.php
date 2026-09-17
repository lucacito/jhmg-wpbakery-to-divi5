<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_basic_grid` and `vc_masonry_grid` → `divi/blog`.
 *
 * WPBakery's post grid is a query plus a grid-item template
 * (`config/grids/class-vc-grids-common.php::getBasicAtts()`): what to fetch,
 * how many per page and per row, and which `vc_gitem` layout to draw each
 * result with. Divi's blog module is the same query with its own fixed item
 * layout — `post.advanced.{type,number,categories,offset}`,
 * `blogGrid.decoration.layout` for the columns and `pagination.advanced.enable`
 * for the paging (`blog/module.json`, `BlogModule.php`) — so the query
 * converts and the item template is reported.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class PostsGridConverter extends BaseWPBakeryConverter {

    /**
     * Every attribute the two grids carry between them, claimed up front so an
     * element the converter writes nothing for still accounts for them
     * (`getBasicAtts()` and `getMasonryCommonAtts()`).
     */
    const CONSUMED = [
        'post_type', 'items_per_page', 'max_items', 'items_per_row', 'gap', 'offset',
        'orderby', 'order', 'meta_key', 'taxonomies', 'exclude', 'custom_query', 'grid_id',
        'item', 'style', 'initial_loading_animation',
        'show_filter', 'filter_source', 'exclude_filter', 'filter_style', 'filter_default_title',
        'filter_align', 'filter_size', 'filter_color',
        'button_style', 'button_color', 'button_size',
        'arrows_design', 'arrows_position', 'arrows_color',
        'paging_design', 'paging_color', 'paging_animation_in', 'paging_animation_out',
        'loop', 'autoplay',
    ];

    /** `getBasicAtts()`: `'value' => '10'` for `items_per_page`. */
    const DEFAULT_PER_PAGE = '10';

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_grid_' ) );
        $tag  = (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // divi/blog never keeps a bottom margin: Divi's flex reset zeroes it,
        // so the default trailing space goes to padding.
        $style    = $this->mapStyle( 'generic', $node, 'content', 'divi/blog' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], self::CONSUMED, [ 'title' ] );

        $this->query( $atts, $id, $attrs );
        $this->grid( $atts, $attrs );
        $this->paging( $atts, $attrs );
        $this->report( $atts, $id, $tag );

        $this->engine->logConverted( 'blog' );
        $this->logUnmappedSettings( $id, $atts, $consumed, $tag );

        $blocks = [ $this->block( $id, 'divi/blog', $attrs ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /** What to fetch: `post.advanced.{type,number,offset,categories}`. */
    private function query( array $atts, string $id, array &$attrs ): void {
        $type = trim( $this->att( $atts, 'post_type', 'post' ) );
        if ( $type !== '' ) {
            StyleMapper::write( $attrs, 'post.advanced.type.desktop.value', $type );
        }

        // `max_items` is the masonry grid's name for the same number.
        $number = trim( $this->att( $atts, 'items_per_page', $this->att( $atts, 'max_items', self::DEFAULT_PER_PAGE ) ) );
        if ( ctype_digit( $number ) && (int) $number > 0 ) {
            StyleMapper::write( $attrs, 'post.advanced.number.desktop.value', $number );
        }

        $offset = trim( $this->att( $atts, 'offset' ) );
        if ( ctype_digit( $offset ) && (int) $offset > 0 ) {
            StyleMapper::write( $attrs, 'post.advanced.offset.desktop.value', $offset );
        }

        $this->categories( $atts, $id, $attrs );
    }

    /**
     * `taxonomies` is the grid's "narrow data source" autocomplete: term ids
     * from *any* taxonomy the post type has (`class-vc-grids-common.php:428`).
     * Divi's blog filters on categories, so only the ids that are category
     * terms are written and everything else is reported (`categoryIds()`).
     */
    private function categories( array $atts, string $id, array &$attrs ): void {
        $terms = $this->categoryIds( $this->att( $atts, 'taxonomies' ), $id, 'taxonomies' );

        if ( $terms !== [] ) {
            StyleMapper::write( $attrs, 'post.advanced.categories.desktop.value', $terms );
        }
    }

    /**
     * `items_per_row` and `gap` → the layout group on the grid wrapper
     * (`blog/module.json` `blogGrid`, read by `BlogModule` as
     * `blogGrid.decoration.layout.desktop.value`).
     */
    private function grid( array $atts, array &$attrs ): void {
        $columns = (int) $this->att( $atts, 'items_per_row', '3' );
        $layout  = [ 'display' => 'grid', 'gridColumnWidths' => 'equal', 'gridColumnCount' => (string) max( 1, $columns ) ];

        $gap = PackedParams::sizeWithUnit( $this->att( $atts, 'gap', '5px' ) );
        if ( $gap !== '' ) {
            $layout['columnGap'] = $gap;
            $layout['rowGap']    = $gap;
        }

        StyleMapper::write( $attrs, 'blogGrid.decoration.layout.desktop.value', $layout );
    }

    /** `style` is WPBakery's paging mode; anything but `all` pages the grid. */
    private function paging( array $atts, array &$attrs ): void {
        $style = strtolower( $this->att( $atts, 'style', 'all' ) );

        StyleMapper::write( $attrs, 'pagination.advanced.enable.desktop.value', $style === 'all' ? 'off' : 'on' );
    }

    /** The grid machinery Divi's blog module has nothing for. */
    private function report( array $atts, string $id, string $tag ): void {
        $item = $this->att( $atts, 'item' );
        if ( $item !== '' ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                sprintf( '%s item="%s" is a WPBakery grid-item template (its own title, excerpt, overlay and hover animation); Divi\'s blog module draws its own', $tag, $item )
            );
        }

        if ( $this->on( $atts, 'show_filter' ) ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                'show_filter draws WPBakery\'s taxonomy filter above the grid; Divi\'s blog module has no filter (its filterable portfolio module does)'
            );
        }

        $order = trim( $this->att( $atts, 'orderby' ) );
        if ( $order !== '' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'orderby="%s" order="%s" sorts the query; Divi\'s blog module always lists the newest first', $order, $this->att( $atts, 'order', 'DESC' ) )
            );
        }

        if ( trim( $this->att( $atts, 'custom_query' ) ) !== '' ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                'custom_query holds a hand-written WP_Query argument string; Divi\'s blog module has no equivalent field'
            );
        }

        if ( $tag === 'vc_masonry_grid' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'vc_masonry_grid packs the results at their own heights; Divi\'s blog grid uses one row height'
            );
        }
    }
}
