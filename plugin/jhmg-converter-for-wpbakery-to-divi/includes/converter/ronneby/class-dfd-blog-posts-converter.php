<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_blog_posts` → `divi/blog`.
 *
 * `modules/dfd_new_blog.php` is a post query plus the theme's own loop
 * template: how many to fetch (`posts_to_show`), where to start
 * (`items_offset`), which categories (`post_categories`, by slug), how many
 * across (`columns`), and a switch for every part of a card — the category
 * label, the title, the meta, the excerpt, the read-more, the share icons, the
 * comment and like counts.
 *
 * Divi's blog module is the same query with its own card:
 * `post.advanced.{type,number,offset,categories,showExcerpt}`,
 * `meta.advanced.{showAuthor,showCategories,showComments,showDate}`,
 * `readMore.advanced.enable`, `image.advanced.enable`,
 * `blogGrid.decoration.layout` and `pagination.advanced.enable`
 * (`blog/module.json`, `BlogModule.php`) — so the query and the switches
 * convert and the theme's card is reported, exactly as `PostsGridConverter`
 * handles `vc_basic_grid`.
 *
 * `post_categories` holds slugs, not term ids (`business,design,life`), which
 * `categoryIds()` reports rather than guesses at: Divi filters on ids.
 *
 * **No default bottom margin**: the theme gives `.dfd-blog-shortcode` none.
 */
class DfdBlogPostsConverter extends RonnebyConverter {

    /**
     * Ronneby's per-part switches and the Divi attributes each one is. A switch
     * the element does not carry is left alone: Divi's own default stands.
     *
     * `enabled_title`, `enabled_link_thumb` and `enabled_category` are
     * deliberately absent — Divi has no toggle that means the same thing, and
     * `switches()` reports all three.
     *
     * Each entry is `[ the token the switch is on at, the Divi paths ]`.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    const SWITCHES = [
        'enabled_excerpt'   => [ 'excerpt', [ 'post.advanced.showExcerpt.desktop.value' ] ],
        // `$enable_meta` gates the whole `.dfd-meta-wrap`
        // (`templates/blog_posts/template_parts/heading.php:27-33`), and what
        // that block prints is the date, the author and the category
        // (`dfd-ronneby/templates/entry-meta-post-bottom.php:3-9`) — three
        // toggles on Divi's blog, one switch on Ronneby's.
        'enabled_meta'      => [ 'meta', [
            'meta.advanced.showDate.desktop.value',
            'meta.advanced.showAuthor.desktop.value',
            'meta.advanced.showCategories.desktop.value',
        ] ],
        // The comment count: a badge on the thumbnail in Ronneby
        // (`template_parts/comments_likes.php:3-7`), an item in the meta line
        // in Divi — the same number in a different place.
        'enabled_comments'  => [ 'comments', [ 'meta.advanced.showComments.desktop.value' ] ],
        'enabled_read_more' => [ 'read_more', [ 'readMore.advanced.enable.desktop.value' ] ],
    ];

    /**
     * The `dfd_single_checkbox` switches with no Divi toggle that means the
     * same thing, and the token each one is on at.
     */
    const REPORTED_SWITCHES = [
        'enabled_title'      => [ 'title', 'enabled_title is off, so Ronneby draws no card title; Divi\'s blog module always draws one' ],
        // "Link on thumb" (`dfd_new_blog.php:500-513`) only decides whether the
        // thumbnail is wrapped in an `<a>` to the post
        // (`templates/blog_posts/standard.php:87-91`). Divi's
        // `image.advanced.enable` shows or hides the thumbnail, which is a
        // different thing entirely.
        'enabled_link_thumb' => [ 'link_thumb', 'enabled_link_thumb is off, so Ronneby prints the thumbnail without a link to the post; Divi\'s blog always links it' ],
    ];

    /** The theme's own card and carousel, which Divi's blog module draws its own way. */
    const REPORTED_LOOK = [
        'style'                     => 'which of the theme\'s card templates draws each post',
        'post_style'                => 'the card template of an older Ronneby build',
        'layout_type_grid_carousel' => 'whether the posts are a grid or a carousel',
        'enabled_autoslideshow'     => 'auto-advancing that carousel',
        'carousel_slideshow_speed'  => 'how fast it advances',
        'content_alignment'         => 'the alignment inside a card',
        'content_full_width'        => 'a full-width card body on hover',
        'content_effect'            => 'the card hover effect',
        'heading_position'          => 'where the card title sits',
        'items'                     => 'whether one hand-picked post is shown',
        'single_custom_post_item'   => 'which post that is',
        'exclude_from_loop'         => 'excluding these posts from the main loop',
        'add_exclude_from_loop'     => 'the same switch of an older Ronneby build',
        'post_tiled'                => 'a tiled layout',
        'post_content_style'        => 'how much of the post body a card shows',
        'enabled_share'             => 'the share icons on a card',
        'enabled_likes'             => 'the like count',
        'enabled_anim_com_like'     => 'the animated comment and like counters',
        'enabled_numeric_title'     => 'the numbered card titles',
        'masonry_sort_panel'        => 'the taxonomy filter above a masonry grid',
        'grid_sort_panel'           => 'the taxonomy filter above a grid',
        'show_sort_panel'           => 'the same filter of an older Ronneby build',
        'sort_alignment'            => 'that filter\'s alignment',
        'sort_panel_alignment'      => 'the same, of an older Ronneby build',
        'share_style'               => 'which share control is drawn',
        'read_more_style'           => 'which read-more control is drawn',
        'read_more_word'            => 'what the read-more says',
        'image_width'               => 'the card thumbnail width',
        'image_height'              => 'its height',
        'thumb_rounded'             => 'its corner radius',
        'image_mask_background'     => 'the wash over the thumbnail',
        'image_mask_color'          => 'its colour',
        'image_mask_gradient'       => 'its gradient',
        'image_mask_opacity'        => 'its opacity',
        'image_mask_opacity_hover'  => 'its opacity under the cursor',
        'post_show_author_box'      => 'the author box',
        'post_show_meta_category'   => 'the category in the meta line',
        'post_show_meta_comments'   => 'the comment count in the meta line',
        'post_show_top_cat'         => 'the category label above the card',
        'dropcap_excerpt'           => 'a drop cap on the excerpt',
        'category_background_color' => 'the category label background',
        'category_background_hover_color' => 'that background under the cursor',
        'category_color'            => 'the category label colour',
        'category_hover_color'      => 'that colour under the cursor',
        'category_hor_padding'      => 'the label\'s horizontal padding',
        'category_ver_padding'      => 'its vertical padding',
        'readmore_color'            => 'the read-more colour',
        'readmore_hover_color'      => 'that colour under the cursor',
        'readmore_share_before_offset' => 'the space above the read-more row',
        'readmore_share_border_style' => 'the rule above it',
        'readmore_share_border_width' => 'its width',
        'readmore_share_border_color' => 'its colour',
        'share_background_color'    => 'the share control background',
        'share_block_color'         => 'the share control colour',
        'share_border_width'        => 'its border width',
        'share_border_color'        => 'its border colour',
        'add_title_font_options'    => 'the typography of a second title',
        'add_title_google_fonts'    => 'its Google font toggle',
        'add_title_custom_fonts'    => 'its Google font',
        'number_title_font_options' => 'the typography of a numbered title',
        'number_title_google_fonts' => 'its Google font toggle',
        'number_title_custom_fonts' => 'its Google font',
        'loop_elements_heading'     => 'an editor section label',
        'layout_settings_heading'   => 'an editor section label',
        'enabled_elements_heading'  => 'an editor section label',
        'title_t_heading'           => 'an editor section label',
        'add_title_t_heading'       => 'an editor section label',
        'number_title_t_heading'    => 'an editor section label',
        'readmore_share_heading'    => 'an editor section label',
        'category_label_heading'    => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_blog_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'tutorials' ] );

        $this->query( $atts, $id, $attrs, $consumed );
        $this->grid( $atts, $attrs, $consumed );
        $this->switches( $atts, $id, $attrs, $consumed );

        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'title_google_fonts', 'family' => 'title_custom_fonts' ],
            'title.decoration.font.font',
            $id,
            $attrs,
            $consumed,
            true
        );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'dfd_blog_posts draws each post with the theme\'s own card', $consumed );

        $this->engine->logConverted( 'blog' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/blog', $attrs );
    }

    /**
     * How many posts and from where.
     *
     * @param string[] $consumed
     */
    private function query( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'posts_to_show', 'add_posts_to_show', 'items_offset', 'post_categories', 'add_post_categories' ] );

        StyleMapper::write( $attrs, 'post.advanced.type.desktop.value', 'post' );

        $number = trim( $this->att( $atts, 'posts_to_show', $this->att( $atts, 'add_posts_to_show' ) ) );
        if ( ctype_digit( $number ) && (int) $number > 0 ) {
            StyleMapper::write( $attrs, 'post.advanced.number.desktop.value', $number );
        }

        $offset = trim( $this->att( $atts, 'items_offset' ) );
        if ( ctype_digit( $offset ) && (int) $offset > 0 ) {
            StyleMapper::write( $attrs, 'post.advanced.offset.desktop.value', $offset );
        }

        // `post_categories` is a checkbox list of category *slugs*; Divi filters
        // on term ids, so `categoryIds()` reports them and writes nothing
        // rather than filtering the module down to nothing.
        $terms = $this->categoryIds(
            $this->att( $atts, 'post_categories', $this->att( $atts, 'add_post_categories' ) ),
            $id,
            'post_categories'
        );
        if ( $terms !== [] ) {
            StyleMapper::write( $attrs, 'post.advanced.categories.desktop.value', $terms );
        }
    }

    /**
     * `columns` — how many cards across (`blogGrid.decoration.layout`, the path
     * `PostsGridConverter::grid()` settled).
     *
     * @param string[] $consumed
     */
    private function grid( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'columns';

        $columns = trim( $this->att( $atts, 'columns' ) );
        if ( ! ctype_digit( $columns ) || (int) $columns < 1 ) {
            return;
        }

        StyleMapper::write( $attrs, 'blogGrid.decoration.layout.desktop.value', [
            'display'          => 'grid',
            'gridColumnWidths' => 'equal',
            'gridColumnCount'  => $columns,
        ] );
    }

    /**
     * The card's per-part switches. Only a switch the element actually carries
     * is written: an absent one means the theme's own default, which Divi's own
     * default stands in for rather than being guessed at.
     *
     * @param string[] $consumed
     */
    private function switches( array $atts, string $id, array &$attrs, array &$consumed ): void {
        foreach ( self::SWITCHES as $key => [ $token, $paths ] ) {
            $consumed[] = $key;

            if ( ! array_key_exists( $key, $atts ) ) {
                continue;
            }

            $value = $this->switchedOn( $atts, $key, $token ) ? 'on' : 'off';
            foreach ( $paths as $path ) {
                StyleMapper::write( $attrs, $path, $value );
            }
        }

        if ( array_key_exists( 'enabled_meta', $atts ) ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'enabled_meta="%s" is one switch over the whole meta line — the date, the author and the category; Divi has a toggle for each, so all three were set together',
                    $this->att( $atts, 'enabled_meta' )
                )
            );
        }

        foreach ( self::REPORTED_SWITCHES as $key => [ $token, $detail ] ) {
            $consumed[] = $key;

            if ( array_key_exists( $key, $atts ) && ! $this->switchedOn( $atts, $key, $token ) ) {
                $this->engine->logNotCarriedOver( 'layout', $id, $detail );
            }
        }

        // The highlighted category label above the title
        // (`template_parts/heading.php:12-16`) is not Divi's category meta item;
        // it is a badge the theme's stylesheet draws.
        $consumed[] = 'enabled_category';
        if ( $this->switchedOn( $atts, 'enabled_category', 'category' ) ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'enabled_category draws a highlighted category label above the card title; Divi lists the category in the meta line instead'
            );
        }
    }

    /**
     * One of `dfd_new_blog`'s checkboxes.
     *
     * They are not WPBakery's `yes`/`true`: each stores a token of its own and
     * the module compares against it — `$enable_meta = ($enabled_meta == 'meta')`
     * (`dfd_new_blog.php:1047-1063`). An **absent** attribute is on, not off,
     * because `vc_map_get_attributes()` fills the param's `'value'`, which for
     * every one of them but `enabled_link_thumb` is the token itself.
     */
    private function switchedOn( array $atts, string $key, string $token ): bool {
        if ( ! array_key_exists( $key, $atts ) ) {
            return $token !== '' && $key !== 'enabled_link_thumb';
        }

        return trim( (string) $atts[ $key ] ) === $token;
    }
}
