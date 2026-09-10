<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_gallery`, `vc_media_grid` and `vc_masonry_media_grid` → `divi/gallery`.
 *
 * All three are the same thing in Divi's vocabulary: a set of media-library
 * attachments laid out on a grid, or run as a slider. `gallery/module.json`
 * keeps the ids at `image.advanced.galleryIds` and `GalleryModule::get_gallery_items()`
 * passes them straight to `get_posts( ['include' => …] )`, so the ids are the
 * only thing that identifies a picture — an external URL cannot go in one.
 *
 * - `vc_gallery` with `type=image_grid` and both media grids are grids
 *   (`module.advanced.fullwidth` `off`); `flexslider_fade`, `flexslider_slide`
 *   and `nivo` are sliders (`fullwidth` `on`, which is what
 *   `GalleryModule::module_classnames()` tests for `et_pb_slider`).
 * - `vc_gallery source="external_link"` has no attachment ids at all
 *   (`custom_srcs` is a list of URLs), so those become one `divi/image` each in
 *   a nested flexed row and the element is reported: it is a row of pictures,
 *   not a gallery module (spec §6).
 *
 * `.wpb_content_element` (config `element_default_class`) — the 35 px `content`
 * margin.
 */
class GalleryConverter extends BaseWPBakeryConverter {

    /** `config/content/shortcode-vc-gallery.php` `type` values that run as a slider. */
    const SLIDER_TYPES = [ 'flexslider_fade', 'flexslider_slide', 'nivo' ];

    /** The media grids' own attribute names, and the `vc_gallery` ones they match. */
    const GRID_TAGS = [ 'vc_media_grid', 'vc_masonry_media_grid' ];

    /**
     * Every attribute the three elements carry between them
     * (`config/content/shortcode-vc-gallery.php` and
     * `config/grids/class-vc-grids-common.php::getMediaCommonAtts()`), claimed
     * up front so an element with no pictures at all still accounts for them.
     */
    const CONSUMED = [
        'source', 'type', 'images', 'include', 'img_size', 'external_img_size', 'custom_srcs',
        'items_per_page', 'items_per_row', 'gap', 'interval',
        'onclick', 'custom_links', 'custom_links_target',
        'item', 'style', 'button_style', 'button_color', 'button_size', 'grid_id', 'initial_loading_animation',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_gallery_' ) );
        $tag  = (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $blocks = $this->att( $atts, 'source' ) === 'external_link'
            ? $this->externalImages( $node, $id, $atts )
            : $this->gallery( $node, $id, $tag, $atts );

        $title = $this->titleBlock( $node );
        if ( $title !== null && $blocks !== [] ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    // -------------------------------------------------------------------------
    // The media-library gallery
    // -------------------------------------------------------------------------

    /** @return array<int, array<string,mixed>> */
    private function gallery( array $node, string $id, string $tag, array $atts ): array {
        $is_grid = in_array( $tag, self::GRID_TAGS, true );

        $style = $this->mapStyle( 'generic', $node );
        $attrs = $style['divi_attrs'];

        // Every key this branch claims, listed before the early return below:
        // a gallery with no pictures still has to account for its attributes.
        $consumed = array_merge( $style['handled_keys'], self::CONSUMED, [ 'title' ] );

        $ids = $this->ids( $atts, $is_grid ? 'include' : 'images' );

        if ( $ids === [] ) {
            $this->engine->logWarning( "{$tag} {$id} names no images; nothing was written for it." );
            $this->logUnmappedSettings( $id, $atts, $consumed, $tag );

            return [];
        }

        StyleMapper::write( $attrs, 'image.advanced.galleryIds.desktop.value', $ids );

        $slider = ! $is_grid && in_array( strtolower( $this->att( $atts, 'type', 'flexslider_fade' ) ), self::SLIDER_TYPES, true );
        StyleMapper::write( $attrs, 'module.advanced.fullwidth.desktop.value', $slider ? 'on' : 'off' );

        $this->perPage( $atts, $ids, $is_grid, $attrs );
        $this->grid( $atts, $is_grid, $attrs );
        $this->rotation( $atts, $slider, $attrs );
        $this->clicks( $atts, $id );
        $this->gridExtras( $atts, $id, $tag, $is_grid );
        $this->size( $atts, $id, $is_grid );

        $this->engine->logConverted( 'gallery' );
        $this->logUnmappedSettings( $id, $atts, $consumed, $tag );

        return [ $this->block( $id, 'divi/gallery', $attrs ) ];
    }

    /** @return string[] The attachment ids, in the order the element lists them. */
    private function ids( array $atts, string $key ): array {
        $ids = [];

        foreach ( explode( ',', $this->att( $atts, $key ) ) as $value ) {
            $value = trim( $value );
            if ( $value !== '' && ctype_digit( $value ) ) {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    /**
     * `module.advanced.postsNumber` is how many pictures Divi shows per page.
     * A `vc_gallery` shows all of them; a media grid pages at `items_per_page`.
     *
     * @param string[] $ids
     */
    private function perPage( array $atts, array $ids, bool $is_grid, array &$attrs ): void {
        $per_page = $is_grid ? (int) $this->att( $atts, 'items_per_page', '0' ) : 0;
        $per_page = $per_page > 0 ? $per_page : count( $ids );

        StyleMapper::write( $attrs, 'module.advanced.postsNumber.desktop.value', (string) $per_page );
        StyleMapper::write( $attrs, 'pagination.advanced.showPagination.desktop.value', $per_page < count( $ids ) ? 'on' : 'off' );
    }

    /**
     * The grid itself: `galleryGrid.decoration.layout` is Divi's layout group
     * on the items wrapper (`gallery/module.json`, default
     * `{display: grid, gridColumnCount: 4}`), and the layout declaration reads
     * `gridColumnCount`, `columnGap` and `rowGap`
     * (`StyleLibrary/Declarations/Layout/Layout.php`).
     */
    private function grid( array $atts, bool $is_grid, array &$attrs ): void {
        if ( ! $is_grid ) {
            return;
        }

        $columns = (int) $this->att( $atts, 'items_per_row', '6' );
        $layout  = [ 'display' => 'grid', 'gridColumnWidths' => 'equal', 'gridColumnCount' => (string) max( 1, $columns ) ];

        $gap = PackedParams::sizeWithUnit( $this->att( $atts, 'gap', '5px' ) );
        if ( $gap !== '' ) {
            $layout['columnGap'] = $gap;
            $layout['rowGap']    = $gap;
        }

        StyleMapper::write( $attrs, 'galleryGrid.decoration.layout.desktop.value', $layout );
    }

    /**
     * `interval` auto-rotates a `vc_gallery` slider every N seconds;
     * `module.advanced.auto` / `autoSpeed` are Divi's, in milliseconds
     * (`GalleryModule::module_script_data()`).
     */
    private function rotation( array $atts, bool $slider, array &$attrs ): void {
        $interval = (int) $this->att( $atts, 'interval', '0' );

        if ( ! $slider || $interval <= 0 ) {
            return;
        }

        StyleMapper::write( $attrs, 'module.advanced.auto.desktop.value', 'on' );
        StyleMapper::write( $attrs, 'module.advanced.autoSpeed.desktop.value', (string) ( $interval * 1000 ) );
    }

    /**
     * WPBakery's four click actions. Divi's gallery has one: every picture
     * opens in its own lightbox, and there is no per-image link.
     */
    private function clicks( array $atts, string $id ): void {
        $onclick = $this->att( $atts, 'onclick', 'link_image' );

        if ( $onclick === '' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                'onclick is off, so WPBakery makes the pictures unclickable; Divi\'s gallery always opens each one in its lightbox'
            );
        }

        if ( $onclick === 'custom_link' && trim( $this->att( $atts, 'custom_links' ) ) !== '' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                'onclick="custom_link" gives each picture its own link (' . PackedParams::safeValue( $this->att( $atts, 'custom_links' ) ) . '); Divi\'s gallery opens the lightbox instead'
            );
        }
    }

    /**
     * The grid-only options: the grid item template, the paging button and the
     * loading animation. All three are WPBakery's own grid machinery.
     */
    private function gridExtras( array $atts, string $id, string $tag, bool $is_grid ): void {
        if ( ! $is_grid ) {
            return;
        }

        $item = $this->att( $atts, 'item' );
        if ( $item !== '' ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                sprintf( '%s item="%s" is a WPBakery grid-item template (the caption, the overlay and the hover animation); Divi\'s gallery draws its own', $tag, $item )
            );
        }

        $style = $this->att( $atts, 'style' );
        if ( $style !== '' && $style !== 'all' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                sprintf( '%s style="%s" pages the grid with a load-more button or lazy loading; Divi\'s gallery uses numbered pagination', $tag, $style )
            );
        }

        if ( $tag === 'vc_masonry_media_grid' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'vc_masonry_media_grid packs the pictures at their own heights; Divi\'s gallery grid uses one row height'
            );
        }
    }

    /**
     * `img_size` chooses the registered size WPBakery renders. Divi's gallery
     * picks its own (`GalleryModule::get_gallery_items()` asks for
     * `et-pb-gallery-module-image` or the full size for a slider), so a named
     * size is reported rather than forced.
     */
    private function size( array $atts, string $id, bool $is_grid ): void {
        if ( $is_grid ) {
            return;
        }

        $size = trim( $this->att( $atts, 'img_size' ) );
        if ( $size === '' || $size === 'thumbnail' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf( 'img_size="%s" chooses the crop WPBakery renders; Divi\'s gallery uses its own gallery size', $size )
        );
    }

    // -------------------------------------------------------------------------
    // The external-link gallery
    // -------------------------------------------------------------------------

    /**
     * `source="external_link"` stores a list of URLs in `custom_srcs`, and
     * `divi/gallery` can only name attachment ids, so each URL becomes a
     * `divi/image` in one nested flexed row — the same shape a run of inline
     * buttons takes (spec §6, CLAUDE.md).
     *
     * @return array<int, array<string,mixed>>
     */
    private function externalImages( array $node, string $id, array $atts ): array {
        $urls = array_values( array_filter( array_map(
            'trim',
            preg_split( '/[\r\n]+/', PackedParams::safeValue( $this->att( $atts, 'custom_srcs' ) ) ) ?: []
        ) ) );

        $style    = $this->mapStyle( 'generic', $node );
        $consumed = array_merge( $style['handled_keys'], self::CONSUMED, [ 'title' ] );

        if ( $urls === [] ) {
            $this->engine->logWarning( "vc_gallery {$id} names no external images; nothing was written for it." );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return [];
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'vc_gallery source="external_link" holds URLs rather than media-library ids, and Divi\'s gallery module names ids; the pictures became one image module each in a flexed row'
        );

        $links  = array_values( array_filter( array_map(
            'trim',
            preg_split( '/[\r\n]+/', PackedParams::safeValue( $this->att( $atts, 'custom_links' ) ) ) ?: []
        ) ) );
        $target = $this->att( $atts, 'custom_links_target' );

        $images = [];
        foreach ( $urls as $index => $url ) {
            $image_atts = [
                'source'     => 'external_link',
                'custom_src' => $url,
            ];
            if ( $this->att( $atts, 'external_img_size' ) !== '' ) {
                $image_atts['external_img_size'] = $this->att( $atts, 'external_img_size' );
            }
            if ( isset( $links[ $index ] ) && $links[ $index ] !== '' ) {
                $image_atts['onclick']         = 'custom_link';
                $image_atts['link']            = 'url:' . rawurlencode( $links[ $index ] );
                $image_atts['img_link_target'] = $target;
            }

            $images[] = $this->delegate( SingleImageConverter::class, $id . '-image-' . ( $index + 1 ), $image_atts );
        }

        // The blocks' own names, not the WPBakery kinds they stand in for.
        $this->engine->logConverted( 'row' );
        $this->engine->logConverted( 'column' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return [ $this->nestedFlexRow( $id, $images, $style['divi_attrs'] ) ];
    }
}
