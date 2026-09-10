<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_posts_slider` → `divi/post-slider`.
 *
 * The same object: a query whose results are shown one at a time.
 * `post-slider/module.json` keeps the query at `post.advanced.{number,categories,orderby,contentSource,offset}`
 * and the playback at `module.advanced.{auto,autoSpeed}`.
 *
 * `orderby`/`order` are two fields in WPBakery and one in Divi
 * (`date_desc | date_asc | title_asc | title_desc | rand`), so the pairs Divi
 * offers convert and the rest are reported.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class PostsSliderConverter extends BaseWPBakeryConverter {

    /** WPBakery `orderby` + `order` ⇒ Divi's single `post.advanced.orderby` value. */
    const ORDER = [
        'date:DESC'  => 'date_desc',
        'date:ASC'   => 'date_asc',
        'title:ASC'  => 'title_asc',
        'title:DESC' => 'title_desc',
        'rand:DESC'  => 'rand',
        'rand:ASC'   => 'rand',
    ];

    /** Every attribute the element carries (`config/content/shortcode-vc-posts-slider.php`). */
    const CONSUMED = [
        'type', 'count', 'interval', 'thumb_size', 'posttypes', 'slides_content', 'slides_title',
        'link', 'custom_links', 'posts_in', 'categories', 'orderby', 'order',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_post_slider_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], self::CONSUMED, [ 'title' ] );

        $count = trim( $this->att( $atts, 'count', '10' ) );
        if ( ctype_digit( $count ) && (int) $count > 0 ) {
            StyleMapper::write( $attrs, 'post.advanced.number.desktop.value', $count );
        }

        $this->categories( $atts, $id, $attrs );
        $this->order( $atts, $id, $attrs );
        $this->content( $atts, $id, $attrs );
        $this->rotation( $atts, $attrs );
        $this->report( $atts, $id );

        $this->engine->logConverted( 'post-slider' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        $blocks = [ $this->block( $id, 'divi/post-slider', $attrs ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * `categories` is an `exploded_textarea_safe` of category *names* — "Enter
     * categories by names to narrow output"
     * (`config/content/shortcode-vc-posts-slider.php:134`) — though a page
     * written by hand may hold ids. Divi's post slider filters on category
     * ids, so `categoryIds()` keeps the ids that resolve to a category and
     * reports every name and every other term rather than dropping them.
     */
    private function categories( array $atts, string $id, array &$attrs ): void {
        $terms = $this->categoryIds(
            PackedParams::safeValue( $this->att( $atts, 'categories' ) ),
            $id,
            'categories'
        );

        if ( $terms !== [] ) {
            StyleMapper::write( $attrs, 'post.advanced.categories.desktop.value', $terms );
        }
    }

    private function order( array $atts, string $id, array &$attrs ): void {
        $orderby = strtolower( trim( $this->att( $atts, 'orderby' ) ) );
        $order   = strtoupper( trim( $this->att( $atts, 'order', 'DESC' ) ) );

        if ( $orderby === '' ) {
            return;
        }

        $key = $orderby . ':' . ( $order === 'ASC' ? 'ASC' : 'DESC' );

        if ( isset( self::ORDER[ $key ] ) ) {
            StyleMapper::write( $attrs, 'post.advanced.orderby.desktop.value', self::ORDER[ $key ] );

            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'orderby="%s" order="%s"; Divi\'s post slider sorts by date, title or at random only, so the order was left at its default',
                $orderby,
                $order
            )
        );
    }

    /**
     * `slides_content` is empty (no description at all) or `teaser` (the
     * excerpt). Divi's `post.advanced.contentSource` is "Show Excerpt" /
     * "Show Content", with no third value for "show nothing" — and
     * `content.advanced.showOnMobile` is a mobile-visibility toggle, not the
     * content source, so hiding the excerpt there would leave it on the desktop
     * and lose it on the phone. The excerpt is kept and the difference reported.
     */
    private function content( array $atts, string $id, array &$attrs ): void {
        StyleMapper::write( $attrs, 'post.advanced.contentSource.desktop.value', 'off' );

        if ( trim( $this->att( $atts, 'slides_content' ) ) !== '' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'slides_content is empty, so WPBakery shows the title alone; Divi\'s post slider always prints an excerpt under it'
        );
    }

    /** `interval` in seconds → `module.advanced.auto` / `autoSpeed` in milliseconds. */
    private function rotation( array $atts, array &$attrs ): void {
        $interval = (int) $this->att( $atts, 'interval', '0' );

        if ( $interval <= 0 ) {
            return;
        }

        StyleMapper::write( $attrs, 'module.advanced.auto.desktop.value', 'on' );
        StyleMapper::write( $attrs, 'module.advanced.autoSpeed.desktop.value', (string) ( $interval * 1000 ) );
    }

    private function report( array $atts, string $id ): void {
        $types = trim( $this->att( $atts, 'posttypes' ) );
        if ( $types !== '' && $types !== 'post' ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                sprintf( 'posttypes="%s"; Divi\'s post slider queries posts only', $types )
            );
        }

        $link = trim( $this->att( $atts, 'link' ) );
        if ( $link !== '' && $link !== 'link_post' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                sprintf( 'link="%s" changes where a slide goes when clicked; Divi\'s post slider always links its button to the post', $link )
            );
        }

        $posts_in = trim( $this->att( $atts, 'posts_in' ) );
        if ( $posts_in !== '' ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                sprintf( 'posts_in="%s" lists the exact posts to show; Divi\'s post slider queries by category and count', $posts_in )
            );
        }

        $thumb = trim( $this->att( $atts, 'thumb_size' ) );
        if ( $thumb !== '' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'thumb_size="%s" chooses the crop of each slide picture; Divi\'s post slider uses its own', $thumb )
            );
        }
    }
}
