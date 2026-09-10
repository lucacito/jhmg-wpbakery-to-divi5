<?php

namespace WPBakeryDivi5Converter\Converter\Registry;

use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Converter\ConverterInterface;
use WPBakeryDivi5Converter\Converter\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode tag ⇒ converter.
 *
 * Two key spaces, because a node's kind decides which: WPBakery's five
 * containers register under `structure:<tag>`, every other tag under
 * `element:<tag>`. A handler entry is a class name or a factory closure that
 * receives the engine.
 *
 * `CORE_TAGS` is a separate thing from the handler table: it is every tag
 * WPBakery 9.0.1 itself registers (`config/lean-map.php` plus the `'base' =>`
 * entries under `config/**` and `include/**`, and the container configs). It
 * is what tells a core element this converter has not implemented yet — which
 * belongs in `unsupported` — from a theme's shortcode, which is handled by
 * `ThemeShortcodeConverter` and never unsupported (spec §7).
 */
class ConverterRegistry {

    /**
     * Every tag js_composer 9.0.1 registers. Sources, in order:
     * `config/lean-map.php` (`vc_lean_map()` calls), the `'base' =>` entries
     * across `config/**` and `include/classes/**`, `config/containers/*`, and
     * the deprecated tags `AttributeNormaliser` rewrites.
     */
    const CORE_TAGS = [
        'vc_accordion', 'vc_accordion_tab', 'vc_acf', 'vc_basic_grid', 'vc_btn', 'vc_button',
        'vc_button2', 'vc_column', 'vc_column_inner', 'vc_column_text', 'vc_container_anchor',
        'vc_copyright', 'vc_cta', 'vc_cta_button', 'vc_cta_button2', 'vc_custom_field',
        'vc_custom_heading', 'vc_empty_space', 'vc_facebook', 'vc_flexbox_container',
        'vc_flexbox_container_item', 'vc_flickr', 'vc_gallery', 'vc_gitem', 'vc_gitem_acf',
        'vc_gitem_animated_block', 'vc_gitem_col', 'vc_gitem_image', 'vc_gitem_post_author',
        'vc_gitem_post_categories', 'vc_gitem_post_date', 'vc_gitem_post_excerpt',
        'vc_gitem_post_meta', 'vc_gitem_post_title', 'vc_gitem_row', 'vc_gitem_wocommerce',
        'vc_gitem_zone', 'vc_gitem_zone_a', 'vc_gitem_zone_b', 'vc_gitem_zone_c', 'vc_gmaps',
        'vc_goo_maps', 'vc_googleplus', 'vc_grid', 'vc_grid_container', 'vc_grid_container_item',
        'vc_gutenberg', 'vc_hoverbox', 'vc_icon', 'vc_images_carousel', 'vc_line_chart',
        'vc_masonry_grid', 'vc_masonry_media_grid', 'vc_media_grid', 'vc_message', 'vc_pie',
        'vc_pinterest', 'vc_posts_slider', 'vc_pricing_table', 'vc_progress_bar', 'vc_raw_html',
        'vc_raw_js', 'vc_round_chart', 'vc_row', 'vc_row_inner', 'vc_section', 'vc_separator',
        'vc_single_image', 'vc_tab', 'vc_tabs', 'vc_text_separator', 'vc_toggle', 'vc_tour',
        'vc_tta_accordion', 'vc_tta_pageable', 'vc_tta_section', 'vc_tta_tabs', 'vc_tta_toggle',
        'vc_tta_toggle_section', 'vc_tta_tour', 'vc_tweetmeme', 'vc_video', 'vc_widget_sidebar',
        'vc_wp_archives', 'vc_wp_calendar', 'vc_wp_categories', 'vc_wp_custommenu', 'vc_wp_links',
        'vc_wp_meta', 'vc_wp_pages', 'vc_wp_posts', 'vc_wp_recentcomments', 'vc_wp_rss',
        'vc_wp_search', 'vc_wp_tagcloud', 'vc_wp_text', 'vc_zigzag',
    ];

    /** The node kinds that register under `structure:`. */
    private const STRUCTURE_KINDS = [ 'section', 'row', 'row_inner', 'column', 'column_inner' ];

    private ConverterEngine $engine;

    /** @var array<string,mixed> */
    private array $registry = [];

    /** @var array<string,bool> Tags whose handler is an approximation by design. */
    private array $approximate = [];

    public function __construct( ConverterEngine $engine ) {
        $this->engine = $engine;
        $this->registerDefaults();
    }

    /** @param class-string|callable $entry */
    public function register( string $key, $entry ): void {
        $this->registry[ $key ] = $entry;
    }

    /**
     * @param class-string|callable $entry
     * @param bool $approximate True when the handler's behaviour is an approximation
     *   by design (a chart, a slider, an add-on element read from an older release).
     *   The engine reports such conversions separately from clean ones.
     */
    public function registerElement( string $tag, $entry, bool $approximate = false ): void {
        $this->registry[ 'element:' . $tag ] = $entry;

        if ( $approximate ) {
            $this->approximate[ $tag ] = true;
        } else {
            unset( $this->approximate[ $tag ] );
        }
    }

    public function getConverter( array $node ): ?ConverterInterface {
        $key = self::keyFor( $node );

        if ( $key === null || ! isset( $this->registry[ $key ] ) ) {
            return null;
        }

        return $this->instantiate( $this->registry[ $key ] );
    }

    public function isApproximate( array $node ): bool {
        return ! empty( $this->approximate[ (string) ( $node['tag'] ?? '' ) ] );
    }

    /** Short class name of the converter a node resolves to, for the report. */
    public function converterName( array $node ): string {
        $key   = self::keyFor( $node );
        $entry = $key !== null ? ( $this->registry[ $key ] ?? null ) : null;

        if ( is_string( $entry ) ) {
            return basename( str_replace( '\\', '/', $entry ) );
        }

        return is_object( $entry ) ? 'closure' : 'none';
    }

    /**
     * What converts a tag nothing else claimed. It is not a rescue: spec §7
     * makes it the whole of the theme/add-on story, and it is why nothing is
     * ever dropped.
     */
    public function defaultConverter( array $node ): ConverterInterface {
        return new Handlers\ThemeShortcodeConverter( $this->engine, (string) ( $node['tag'] ?? '' ) );
    }

    /** @return string[] Every tag with a registered handler. */
    public function knownTags(): array {
        $tags = [];

        foreach ( array_keys( $this->registry ) as $key ) {
            $tags[] = substr( $key, (int) strpos( $key, ':' ) + 1 );
        }

        return $tags;
    }

    /** @return string[] Tags whose handler is registered as approximate. */
    public function approximateTags(): array {
        return array_keys( $this->approximate );
    }

    /** Whether WPBakery itself ships this tag. */
    public static function isCoreTag( string $tag ): bool {
        return in_array( $tag, self::CORE_TAGS, true );
    }

    private static function keyFor( array $node ): ?string {
        $tag = $node['tag'] ?? '';
        if ( ! is_string( $tag ) || $tag === '' ) {
            return null;
        }

        $kind = $node['kind'] ?? '';

        return in_array( $kind, self::STRUCTURE_KINDS, true ) ? 'structure:' . $tag : 'element:' . $tag;
    }

    /** @param class-string|callable $entry */
    private function instantiate( $entry ): ConverterInterface {
        if ( is_callable( $entry ) ) {
            return $entry( $this->engine );
        }

        return new $entry( $this->engine );
    }

    private function registerDefaults(): void {
        $structure = [
            'vc_section'      => Handlers\SectionConverter::class,
            'vc_row'          => Handlers\RowConverter::class,
            'vc_row_inner'    => Handlers\RowInnerConverter::class,
            'vc_column'       => Handlers\ColumnConverter::class,
            'vc_column_inner' => Handlers\ColumnConverter::class,
        ];
        foreach ( $structure as $tag => $class ) {
            if ( class_exists( $class ) ) {
                $this->register( 'structure:' . $tag, $class );
            }
        }

        // A run of text between shortcodes is content like any other.
        if ( class_exists( Handlers\TextNodeConverter::class ) ) {
            $this->registerElement( '#text', Handlers\TextNodeConverter::class );
        }

        // Elements. `$approximate` is true where the handler's output is a
        // deliberate stand-in rather than the same thing in Divi's vocabulary.
        $elements = [
            'vc_column_text'    => [ Handlers\ColumnTextConverter::class, false ],
            'vc_custom_heading' => [ Handlers\CustomHeadingConverter::class, false ],
            'vc_single_image'   => [ Handlers\SingleImageConverter::class, false ],
            'vc_btn'            => [ Handlers\BtnConverter::class, false ],
            'vc_icon'           => [ Handlers\IconConverter::class, false ],
            'vc_separator'      => [ Handlers\SeparatorConverter::class, false ],
            // No zigzag divider in Divi; a straight line stands in for it.
            'vc_zigzag'         => [ Handlers\ZigzagConverter::class, true ],
            // The rule runs through the title in WPBakery, under it in Divi.
            'vc_text_separator' => [ Handlers\TextSeparatorConverter::class, true ],
            'vc_empty_space'    => [ Handlers\EmptySpaceConverter::class, false ],
            'vc_message'        => [ Handlers\MessageConverter::class, false ],
            'vc_toggle'         => [ Handlers\ToggleConverter::class, false ],
            'vc_copyright'      => [ Handlers\CopyrightConverter::class, false ],
            'vc_raw_html'       => [ Handlers\RawHtmlConverter::class, false ],
            'vc_raw_js'         => [ Handlers\RawHtmlConverter::class, false ],
            // Both map elements keep an embed or need an API key; neither is
            // the same object Divi's map module is.
            'vc_gmaps'          => [ Handlers\GmapsConverter::class, true ],
            'vc_goo_maps'       => [ Handlers\GmapsConverter::class, true ],
            'vc_video'          => [ Handlers\VideoConverter::class, false ],
            // A code stand-in, like vc_gmaps: block-editor markup rendered (or
            // kept verbatim) in a divi/code module, not a native Divi block.
            'vc_gutenberg'      => [ Handlers\GutenbergConverter::class, true ],
            // WPBakery resolves its meta token only inside a grid item.
            'vc_custom_field'   => [ Handlers\CustomFieldConverter::class, true ],

            // Composites. Their panels hold blocks in WPBakery and HTML in
            // Divi, so `ContentFlattener` renders the converted children back
            // to HTML (spec §6).
            'vc_tta_accordion'  => [ Handlers\TtaAccordionConverter::class, false ],
            'vc_tta_tabs'       => [ Handlers\TtaTabsConverter::class, false ],
            // A tour stacks its controls down one side and a pageable
            // container replaces them with dots; Divi draws one horizontal
            // tab strip either way.
            'vc_tta_tour'       => [ Handlers\TtaTabsConverter::class, true ],
            'vc_tta_pageable'   => [ Handlers\TtaTabsConverter::class, true ],
            // A two-state switch has no Divi module at all.
            'vc_tta_toggle'     => [ Handlers\TtaToggleConverter::class, true ],

            // Galleries and carousels.
            'vc_gallery'        => [ Handlers\GalleryConverter::class, false ],
            // A media grid carries a grid-item template Divi's gallery cannot
            // reproduce, and the masonry one packs the pictures at their own
            // heights.
            'vc_media_grid'     => [ Handlers\GalleryConverter::class, true ],
            'vc_masonry_media_grid' => [ Handlers\GalleryConverter::class, true ],
            'vc_images_carousel' => [ Handlers\ImagesCarouselConverter::class, false ],

            // Counters and charts.
            'vc_progress_bar'   => [ Handlers\ProgressBarConverter::class, false ],
            'vc_pie'            => [ Handlers\PieConverter::class, false ],
            // Chart.js on both sides, but the stroke, legend and animation
            // styling is WPBakery's own (task-9-amendments.md §7).
            'vc_round_chart'    => [ Handlers\ChartConverter::class, true ],
            'vc_line_chart'     => [ Handlers\ChartConverter::class, true ],
        ];

        foreach ( $elements as $tag => [ $class, $approximate ] ) {
            if ( class_exists( $class ) ) {
                $this->registerElement( $tag, $class, $approximate );
            }
        }
    }
}
