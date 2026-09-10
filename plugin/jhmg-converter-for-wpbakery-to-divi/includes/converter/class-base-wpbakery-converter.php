<?php

namespace WPBakeryDivi5Converter\Converter;

use WPBakeryDivi5Converter\Helpers\Autop;
use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;
use WPBakeryDivi5Converter\StyleMapper\GlobalSettingsResolver;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What every handler shares: reading WPBakery attributes, running the
 * StyleMapper, assembling Divi blocks, and reporting what it did not use.
 *
 * The geometry helpers here are the single place the measured box model
 * (`docs/box-model.md`, candidate C) turns into Divi attributes, so a site can
 * change it once through `wbdc_layout_defaults` and every row, column and
 * section follows.
 */
abstract class BaseWPBakeryConverter implements ConverterInterface {

    protected ConverterEngine $engine;

    public function __construct( ConverterEngine $engine ) {
        $this->engine = $engine;
    }

    /**
     * A Divi row that stands in for something WPBakery gives no spacing at
     * all: a nested row, or the row wrapped around an inline group of buttons.
     * Divi's own row default (27 px top and bottom, a 30 px gutter) would add
     * space the source never had, so it is reset to nothing.
     *
     * The half of the nested-row geometry that is not site-configurable; the
     * width, max-width and bleed come from `GlobalSettingsResolver` through
     * `nestedRowSettings()`, which is what handlers actually use.
     */
    const ROW_RESET = [
        'module' => [
            'decoration' => [
                'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [
                    'top'            => '0px',
                    'right'          => '0px',
                    'bottom'         => '0px',
                    'left'           => '0px',
                    'syncVertical'   => 'off',
                    'syncHorizontal' => 'off',
                ] ] ] ],
                // Divi tests layout gap values for truthiness, so a zero is
                // "0px" and never "0".
                'layout'  => [ 'desktop' => [ 'value' => [ 'columnGap' => '0px', 'rowGap' => '0px' ] ] ],
            ],
        ],
    ];

    // -------------------------------------------------------------------------
    // Geometry (docs/box-model.md, candidate C)
    // -------------------------------------------------------------------------

    /**
     * A top-level, container-width row: WPBakery's 15 px bleed written with
     * Divi's own layout custom properties and their plain fallbacks.
     */
    public static function containerRowSettings(): array {
        $margin_x = GlobalSettingsResolver::rowMarginX();

        return self::rowGeometry(
            GlobalSettingsResolver::rowWidth(),
            GlobalSettingsResolver::rowMaxWidth(),
            $margin_x
        );
    }

    /** A `vc_row_inner`, or any row a handler builds inside a column: `ROW_RESET` plus its bleed. */
    public static function nestedRowSettings(): array {
        return self::rowGeometry(
            GlobalSettingsResolver::nestedRowWidth(),
            GlobalSettingsResolver::nestedRowMaxWidth(),
            GlobalSettingsResolver::rowMarginX()
        );
    }

    /** A stretched row (`stretch_row_content`): the full width of its section, no bleed. */
    public static function stretchedRowSettings(): array {
        return self::rowGeometry( '100%', 'none', '0px' );
    }

    private static function rowGeometry( string $width, string $max_width, string $margin_x ): array {
        $settings = self::ROW_RESET;

        $settings['module']['decoration']['sizing']['desktop']['value'] = [
            'width'    => $width,
            'maxWidth' => $max_width,
        ];
        $settings['module']['decoration']['spacing']['desktop']['value']['margin'] = self::box( '0px', $margin_x, '0px', $margin_x );

        return $settings;
    }

    /**
     * A run of blocks that has to sit side by side, in the one shape Divi
     * allows for it: a nested row holding a single flexed, wrapping column.
     *
     * Divi stacks a column's modules and has no inline mode for them
     * (CLAUDE.md), so a row of inline buttons, a row of external gallery
     * pictures and anything else of that kind all take this. The row keeps
     * `ROW_RESET` and the nested bleed; the column loses its 15 px sides,
     * because the run's own members carry their spacing.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param array<string,mixed>            $row_attrs Design options for the row itself.
     */
    protected function nestedFlexRow( string $id, array $blocks, array $row_attrs = [] ): array {
        $column = $this->block( $id . '-col', 'divi/column', $this->deepMergeSettings(
            self::fullWidthColumnSettings(),
            [ 'module' => [ 'decoration' => [
                'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => self::box( '0px', '0px', '0px', '0px' ) ] ] ],
                'layout'  => [ 'desktop' => [ 'value' => [
                    'display'   => 'flex',
                    'flexWrap'  => 'wrap',
                    'columnGap' => '0px',
                    'rowGap'    => '0px',
                ] ] ],
            ] ] ]
        ), $blocks );

        return $this->block(
            $id,
            'divi/row',
            $this->deepMergeSettings(
                self::nestedRowSettings(),
                $this->deepMergeSettings(
                    $row_attrs,
                    [ 'module' => [ 'advanced' => [ 'columnStructure' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ] ]
                )
            ),
            [ $column ]
        );
    }

    /** WPBakery contributes no section padding of its own; Divi's 56 px default has to go. */
    public static function sectionResetSettings(): array {
        $padding = GlobalSettingsResolver::sectionPadding();

        return [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [
            'padding' => self::box( $padding, $padding, $padding, $padding ),
        ] ] ] ] ] ];
    }

    /** The settings of a full-width column the converter had to invent. */
    public static function fullWidthColumnSettings(): array {
        $padding_x = GlobalSettingsResolver::columnPaddingX();

        return [ 'module' => [
            'advanced'   => [ 'type' => [ 'desktop' => [ 'value' => '4_4' ] ] ],
            'decoration' => [
                'sizing'  => [ 'desktop' => [ 'value' => [ 'flexType' => '24_24' ] ] ],
                'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => self::box( '0px', $padding_x, '0px', $padding_x ) ] ] ],
                'layout'  => [ 'desktop' => [ 'value' => [ 'rowGap' => '0px' ] ] ],
            ],
        ] ];
    }

    /** Divi's four-sided box value; WPBakery's inputs are independent, so the sync flags are off. */
    public static function box( string $top, string $right, string $bottom, string $left ): array {
        return [
            'top'            => $top,
            'right'          => $right,
            'bottom'         => $bottom,
            'left'           => $left,
            'syncVertical'   => 'off',
            'syncHorizontal' => 'off',
        ];
    }

    /** Divi's four-corner border radius value, the same radius on every corner. */
    public static function radius( string $r ): array {
        return [
            'topLeft'     => $r,
            'topRight'    => $r,
            'bottomRight' => $r,
            'bottomLeft'  => $r,
        ];
    }

    /**
     * `el_width` (a WPBakery percentage, exclusive of 0 and 100) and an alignment
     * already resolved to Divi's `left|center|right`, written to the pair of
     * `module.decoration.sizing` keys `vc_separator`, `vc_zigzag` and `vc_video`
     * all carry. A caller reads its own `align` attribute and default (they
     * differ: `align_center` for the separator, `center` for the zigzag, `left`
     * for the video) and maps it to a Divi value or `null` before calling this.
     */
    protected function elWidth( array &$attrs, string $width, ?string $alignment ): void {
        $width = trim( $width );
        if ( preg_match( '/^\d+$/', $width ) === 1 && (int) $width > 0 && (int) $width < 100 ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.width', $width . '%' );
        }

        if ( $alignment !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.alignment', $alignment );
        }
    }

    // -------------------------------------------------------------------------
    // Children
    // -------------------------------------------------------------------------

    /** Every child of a node converted through the registry, as a flat list of blocks. */
    protected function convertChildren( array $node ): array {
        return $this->engine->convertChildren( $node['children'] ?? [] );
    }

    // -------------------------------------------------------------------------
    // Attribute access
    // -------------------------------------------------------------------------

    /** A string attribute, `$default` when absent, blank or not a scalar. */
    protected function att( array $atts, string $key, string $default = '' ): string {
        $value = $atts[ $key ] ?? null;

        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }
        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return $default;
        }

        return $value;
    }

    /** WPBakery's boolean-ish attributes: `yes`, `true`, `1`, `on`. */
    protected function on( array $atts, string $key ): bool {
        return PackedParams::isOn( $atts[ $key ] ?? '' );
    }

    /**
     * A packed link attribute (`url:…|title:…|target:…|rel:…`) as Divi's link
     * value `{url, target?, rel?}`.
     *
     * @return array{url?: string, target?: string, rel?: string[]}
     */
    protected function link( array $atts, string $key ): array {
        $packed = PackedParams::link( $this->att( $atts, $key ) );

        if ( $packed['url'] === '' ) {
            return [];
        }

        $value = [ 'url' => $packed['url'] ];

        if ( $packed['target'] !== '' ) {
            $value['target'] = str_starts_with( trim( $packed['target'] ), '_' ) ? trim( $packed['target'] ) : '_' . trim( $packed['target'] );
        }
        if ( $packed['rel'] !== '' ) {
            $value['rel'] = preg_split( '/\s+/', trim( $packed['rel'] ) ) ?: [];
        }

        return $value;
    }

    /**
     * The parts of a packed link WPBakery prints on the anchor that the Divi
     * module has no attribute for — its `title`, and its `rel` on the modules
     * whose link value is only `{url, target}`. Reported rather than dropped.
     */
    protected function reportLinkExtras( array $atts, string $key, string $node_id, bool $keeps_rel = false ): void {
        $packed = PackedParams::link( $this->att( $atts, $key ) );

        $lost = [];
        if ( trim( $packed['title'] ) !== '' ) {
            $lost[] = 'title "' . trim( $packed['title'] ) . '"';
        }
        if ( ! $keeps_rel && trim( $packed['rel'] ) !== '' ) {
            $lost[] = 'rel "' . trim( $packed['rel'] ) . '"';
        }

        if ( $lost === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $node_id,
            'link ' . implode( ' and ', $lost ) . ': the Divi module\'s link holds only a URL and a target'
        );
    }

    /** A normalised colour, or null. A palette name Divi cannot resolve is reported, never guessed at. */
    protected function color( array $atts, string $key, string $node_id ): ?string {
        $raw = $this->att( $atts, $key );
        if ( $raw === '' ) {
            return null;
        }

        $color = Color::normalize( $raw );
        if ( $color === null ) {
            $this->engine->logUnresolvedGlobal( $node_id, $key, trim( $raw ) );
        }

        return $color;
    }

    /**
     * The URL of a media-library attachment, at a named size when the import
     * map carries one.
     *
     * On this site the library answers; from an export only the id/URL map a
     * WXR carried does. Either way a miss is reported and null, never a
     * fabricated path.
     *
     * The map entry can be a plain URL string, or `['url' => …, 'sizes' => [name
     * => url]]` (Task 11's WXR shape). A size WPBakery asked for that is not in
     * the `sizes` map — because the map has none at all, or just not that size
     * — falls back to the attachment's own URL and is reported, the same way an
     * unresolved size elsewhere in this file is: the page shows the right
     * picture, at the wrong crop.
     */
    protected function attachmentUrl( int $id, string $size, string $node_id ): ?string {
        if ( $id <= 0 ) {
            return null;
        }

        $options = $this->engine->options();
        $mapped  = $options['attachments'][ $id ] ?? '';

        $sizes = [];
        if ( is_array( $mapped ) ) {
            $sizes  = is_array( $mapped['sizes'] ?? null ) ? $mapped['sizes'] : [];
            $mapped = $mapped['url'] ?? '';
        }

        if ( is_string( $mapped ) && $mapped !== '' ) {
            if ( $size !== '' && $size !== 'full' ) {
                if ( is_string( $sizes[ $size ] ?? null ) && $sizes[ $size ] !== '' ) {
                    return $sizes[ $size ];
                }
                $this->engine->logNotCarriedOver(
                    'layout',
                    $node_id,
                    "img_size=\"{$size}\" is not in the import's size map for attachment {$id}; the attachment's own URL was used instead"
                );
            }

            return $mapped;
        }

        if ( $options['mode'] !== 'import' ) {
            if ( $size !== '' && $size !== 'full' && function_exists( 'wp_get_attachment_image_src' ) ) {
                $src = wp_get_attachment_image_src( $id, $size );
                if ( is_array( $src ) && is_string( $src[0] ?? null ) && $src[0] !== '' ) {
                    return $src[0];
                }
            }
            if ( function_exists( 'wp_get_attachment_url' ) ) {
                $url = wp_get_attachment_url( $id );
                if ( is_string( $url ) && $url !== '' ) {
                    return $url;
                }
            }
        }

        $this->engine->logUnresolvedMedia( $node_id, $id );

        return null;
    }

    /**
     * The `category` term ids in a WPBakery term list, with everything that is
     * not one reported rather than passed on.
     *
     * Spec §6 asks for "categories when resolvable", and the difference is not
     * cosmetic: `divi/blog` and `divi/post-slider` filter on category ids
     * (`post.advanced.categories`), so a term id from another taxonomy — which
     * is exactly what `vc_basic_grid`'s `taxonomies` autocomplete stores, since
     * it searches every taxonomy the post type has — matches no post and empties
     * the module, where writing no filter at all shows every post.
     *
     * On the site the page lives on `get_term()` says which taxonomy an id
     * belongs to. From an export there is no database to ask, so nothing is
     * written and every id is reported: an unfiltered module the reader can
     * narrow is better than an empty one they have to debug.
     *
     * @param string $raw       A comma- or newline-separated list of term ids (or,
     *                          for `vc_posts_slider`, category names).
     * @param string $attribute The attribute's name, so the report says which field.
     * @return string[] The ids Divi can filter on, in the order they were listed.
     */
    protected function categoryIds( string $raw, string $node_id, string $attribute ): array {
        $terms = array_values( array_filter(
            array_map( 'trim', preg_split( '/[\s,]+/', trim( $raw ) ) ?: [] ),
            static fn( string $term ): bool => $term !== ''
        ) );

        if ( $terms === [] ) {
            return [];
        }

        $options  = $this->engine->options();
        $resolves = $options['mode'] === 'direct' && function_exists( 'get_term' );

        if ( ! $resolves ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $node_id,
                sprintf(
                    '%s="%s" narrows the query to those terms; %s, so the module was left unfiltered — set its categories in Divi',
                    $attribute,
                    implode( ',', $terms ),
                    $options['mode'] === 'direct'
                        ? 'WordPress\'s term functions are not loaded here, so which taxonomy each id belongs to could not be checked'
                        : 'there is no database to check them against when converting from an export'
                )
            );

            return [];
        }

        $ids      = [];
        $rejected = [];

        foreach ( $terms as $term ) {
            if ( ! ctype_digit( $term ) ) {
                $rejected[] = sprintf( '"%s" is a name, not a term id', $term );

                continue;
            }

            $resolved = get_term( (int) $term );
            $taxonomy = is_object( $resolved ) ? (string) ( $resolved->taxonomy ?? '' ) : '';

            if ( $taxonomy === 'category' ) {
                $ids[] = $term;

                continue;
            }

            $rejected[] = $taxonomy === ''
                ? sprintf( '%s is not a term on this site', $term )
                : sprintf( '%s is a %s term', $term, $taxonomy );
        }

        if ( $rejected !== [] ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $node_id,
                sprintf(
                    '%s: Divi filters this module on categories, and %s; %s',
                    $attribute,
                    implode( ', ', $rejected ),
                    $ids === []
                        ? 'the module was left unfiltered rather than filtered to nothing'
                        : 'only the category terms were kept'
                )
            );
        }

        return $ids;
    }

    // -------------------------------------------------------------------------
    // Style mapping
    // -------------------------------------------------------------------------

    /**
     * A node whose design-options field is named something other than `css`,
     * rewritten so the StyleMapper can read it.
     *
     * Every Ultimate Addons element names that field after itself
     * (`css_info_box`, `css_just_icon`, `css_stat_counter`, `css_price_box`,
     * `css_video_design`) but stores exactly what WPBakery's `css` holds — the
     * `.vc_custom_… { … }` rule the editor generated — so the alias is all the
     * StyleMapper needs.
     */
    protected function withCss( array $node, string $key ): array {
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        if ( ! empty( $atts[ $key ] ) && empty( $atts['css'] ) ) {
            $atts['css']  = $atts[ $key ];
            $node['atts'] = $atts;
        }

        return $node;
    }

    /**
     * Runs the StyleMapper over a node's design options and forwards its notes
     * to the report.
     *
     * A module side WPBakery leaves blank takes WPBakery's own default bottom
     * margin for that kind of element (`js_composer.min.css`, per element, not
     * global) — but only when the element's `css` set none, because WPBakery
     * writes design options `!important`. Blocks a composite handler builds
     * through `delegate()` are pieces of one element and get no default at all.
     *
     * @param string $kind        StyleMapper kind: section|row|column|group|heading|text|image|…
     * @param string $margin_kind GlobalSettingsResolver kind: content|button|message|toggle|inner_row|none
     *                            (`none` skips the default outright — the element carries no
     *                            WPBakery margin of its own, distinct from an unset `css` side,
     *                            which still takes the per-kind default)
     * @return array{divi_attrs: array, handled_keys: string[]}
     */
    protected function mapStyle( string $kind, array $node, string $margin_kind = 'content' ): array {
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $node_id = (string) ( $node['id'] ?? '' );

        $result = ( new StyleMapper() )->map( $kind, $atts, $this->engine->options() );

        foreach ( $result['notes'] as $note ) {
            $this->reportNote( $node_id, (string) $note['kind'], (string) $note['detail'] );
        }

        $attrs = $result['divi_attrs'];

        if ( $margin_kind !== 'none' && ! in_array( $kind, [ 'section', 'row', 'column', 'group' ], true ) && empty( $node['delegated'] ) ) {
            $attrs = $this->fillModuleMarginBottom( $kind, $attrs, $margin_kind );
        }

        return [ 'divi_attrs' => $attrs, 'handled_keys' => $result['handled_keys'] ];
    }

    private function reportNote( string $node_id, string $kind, string $detail ): void {
        if ( $kind === 'custom_css_carried' ) {
            $this->engine->logCustomCssCarried( $node_id );

            return;
        }

        if ( $kind === 'unresolved_media' ) {
            if ( preg_match( '/attachment (\d+)/', $detail, $m ) === 1 ) {
                $this->engine->logUnresolvedMedia( $node_id, (int) $m[1] );
            }
            if ( str_contains( $detail, 'no URL to fall back on' ) ) {
                $this->engine->logNotCarriedOver( 'background', $node_id, $detail );
            }

            return;
        }

        $this->engine->logNotCarriedOver( $kind, $node_id, $detail );
    }

    /** WPBakery's per-element bottom margin, at the path that element's module reads. */
    private function fillModuleMarginBottom( string $kind, array $attrs, string $margin_kind ): array {
        // `divi/image` reads its spacing from module.advanced, everything else
        // from module.decoration (docs/box-model.md, "Additional findings").
        $path   = $kind === 'image' ? 'module.advanced.spacing' : 'module.decoration.spacing';
        $margin = $this->read( $attrs, $path . '.desktop.value.margin' );
        $margin = is_array( $margin ) ? $margin : [];

        if ( isset( $margin['bottom'] ) && $margin['bottom'] !== '' ) {
            return $attrs;
        }

        $margin['bottom'] = GlobalSettingsResolver::moduleMarginBottom( $margin_kind );

        StyleMapper::write( $attrs, $path . '.desktop.value.margin', array_merge(
            [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ],
            $margin,
            [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ]
        ) );

        return $attrs;
    }

    /** Reads a dot path out of a settings array, or null. */
    protected function read( array $attrs, string $dot_path ): mixed {
        $current = $attrs;
        foreach ( explode( '.', $dot_path ) as $key ) {
            if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
                return null;
            }
            $current = $current[ $key ];
        }

        return $current;
    }

    // -------------------------------------------------------------------------
    // Block assembly
    // -------------------------------------------------------------------------

    protected function block( string $id, string $name, array $settings, array $children = [] ): array {
        return [ 'id' => $id, 'name' => $name, 'settings' => $settings, 'elements' => $children ];
    }

    /** A `divi/code` block carrying raw HTML or a shortcode. */
    protected function codeBlock( string $id, string $html, array $settings = [] ): array {
        return $this->block( $id, 'divi/code', $this->deepMergeSettings(
            $settings,
            [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $html ] ] ] ]
        ) );
    }

    /** A `divi/heading` at $level. */
    protected function headingBlock( string $id, string $text, string $level = 'h2' ): array {
        return $this->block( $id, 'divi/heading', [
            'title' => [
                'innerContent' => [ 'desktop' => [ 'value' => $text ] ],
                'decoration'   => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'headingLevel' => $level ] ] ] ] ],
            ],
        ] );
    }

    /**
     * The `title` many WPBakery elements carry above themselves
     * (`wpb_heading`, an h2 in every one of WPBakery's own templates), as a
     * heading block to place before the element, or null when there is none.
     */
    protected function titleBlock( array $node ): ?array {
        $atts  = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $title = trim( $this->att( $atts, 'title' ) );

        if ( $title === '' ) {
            return null;
        }

        return $this->headingBlock( (string) ( $node['id'] ?? '' ) . '-title', $title, 'h2' );
    }

    /** Recursively merges two settings arrays; nested arrays merge rather than replace. */
    protected function deepMergeSettings( array $a, array $b ): array {
        foreach ( $b as $k => $v ) {
            if ( is_array( $v ) && isset( $a[ $k ] ) && is_array( $a[ $k ] ) ) {
                $a[ $k ] = $this->deepMergeSettings( $a[ $k ], $v );
            } else {
                $a[ $k ] = $v;
            }
        }

        return $a;
    }

    /** Guarantees a list holds only `divi/column` blocks by wrapping anything else in one. */
    protected function ensureColumnChildren( string $id, array $children ): array {
        if ( empty( $children ) ) {
            return [];
        }

        foreach ( $children as $child ) {
            if ( ( $child['name'] ?? '' ) !== 'divi/column' ) {
                return [ $this->block( $id . '-col', 'divi/column', self::fullWidthColumnSettings(), $children ) ];
            }
        }

        return $children;
    }

    /** A row's `columnStructure`, the comma-joined legacy fraction of its columns. */
    protected function rowSettingsFromColumns( array $columns ): array {
        $fractions = [];

        foreach ( $columns as $column ) {
            if ( ( $column['name'] ?? '' ) !== 'divi/column' ) {
                continue;
            }
            $fraction = $column['settings']['module']['advanced']['type']['desktop']['value'] ?? null;
            if ( ! is_string( $fraction ) || $fraction === '' ) {
                return [];
            }
            $fractions[] = $fraction;
        }

        if ( $fractions === [] ) {
            return [];
        }

        return [ 'module' => [ 'advanced' => [ 'columnStructure' => [ 'desktop' => [ 'value' => implode( ',', $fractions ) ] ] ] ] ];
    }

    /**
     * Runs another handler over attributes shaped the way it expects.
     *
     * Composite elements are built from simpler ones this way, so every piece
     * is mapped by its own source-verified handler. A delegated block is a
     * piece of one element, not an element, so it carries no default margin.
     *
     * @param class-string<BaseWPBakeryConverter> $handler
     */
    protected function delegate( string $handler, string $id, array $atts, string $content = '' ): array {
        return ( new $handler( $this->engine ) )->convert( [
            'id'        => $id,
            'tag'       => '',
            'kind'      => 'element',
            'atts'      => $atts,
            'content'   => $content,
            'children'  => [],
            'notes'     => [],
            'delegated' => true,
        ] );
    }

    // -------------------------------------------------------------------------
    // Shortcodes inside an element's HTML content
    // -------------------------------------------------------------------------

    /**
     * A shortcode WPBakery's editor never wrote, sitting inside the HTML of a
     * `textarea_html` field (`vc_column_text`, `vc_message`, `vc_toggle`, …).
     *
     * Spec §7's two behaviours, applied in place rather than to the block:
     * on the site the page lives on the shortcode is rendered with
     * `do_shortcode()` — exactly what `wpb_js_remove_wpautop()` does at the end
     * of every one of those templates — and the HTML replaces it, a static
     * copy; from an export nothing can render it, so the text is left where it
     * was and the family is reported once per node.
     *
     * `do_shortcode()` matches only registered shortcodes internally
     * (`get_shortcode_regex()`); handed a tag nothing registered, it returns
     * the input untouched — non-empty, so a bare `trim( $html ) !== ''` check
     * would read that as "rendered" and file the tag as a static copy it never
     * was. `shortcode_exists( $tag )` is the actual signal; a tag it does not
     * know is a placeholder, not a render.
     *
     * A bracketed token that is not a shortcode at all — `[1]`, a footnote
     * marker — is prose: WordPress prints it verbatim because nothing
     * registers it (amendment §5), so it is left alone and never reported.
     *
     * @param string $html    Content already through `Autop`.
     * @param string $node_id The node the content belongs to.
     */
    protected function nestedShortcodes( string $html, string $node_id ): string {
        if ( ! str_contains( $html, '[' ) ) {
            return $html;
        }

        $options  = $this->engine->options();
        $render   = $options['mode'] === 'direct' && $options['render_shortcodes'] && function_exists( 'do_shortcode' ) && function_exists( 'shortcode_exists' );
        $reported = [];
        $rendered = false;

        $out = preg_replace_callback(
            // One shortcode: an opening tag, and its closing tag when it has
            // one. Same grammar as `ShortcodeParser`, narrowed to a scan — this
            // is looking for spans to replace, not building a tree.
            '/\[([a-zA-Z0-9_-]+)(?![\w-])([^\]]*)\](?:(.*?)\[\/\1\])?/s',
            function ( array $m ) use ( $render, $node_id, &$reported, &$rendered ): string {
                $tag = $m[1];

                // Not a shortcode: a bare number, or a name no plugin could
                // register. WordPress prints those as text.
                if ( preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $tag ) !== 1 ) {
                    return $m[0];
                }

                $family = ThemeShortcodes::family( $tag );

                if ( $render && shortcode_exists( $tag ) ) {
                    $html = (string) do_shortcode( $m[0] );
                    if ( trim( $html ) !== '' ) {
                        $rendered = true;
                        $this->engine->logThemeElement( $family['label'], $node_id, $tag );
                        if ( ! isset( $reported[ $tag ] ) ) {
                            $reported[ $tag ] = true;
                            $this->engine->logNotCarriedOver(
                                $family['kind'],
                                $node_id,
                                sprintf( '%s inside the text: rendered on this site and kept as a static copy; it will not update when its plugin does', $tag )
                            );
                        }

                        return $html;
                    }
                }

                $this->engine->logThemeElement( $family['label'], $node_id, $tag );
                if ( ! isset( $reported[ $tag ] ) ) {
                    $reported[ $tag ] = true;
                    $this->engine->logNotCarriedOver(
                        $family['kind'],
                        $node_id,
                        sprintf( '%s inside the text: kept as the shortcode text it was; rebuild it with a Divi module or the plugin\'s own shortcode', $tag )
                    );
                }

                return $m[0];
            },
            $html
        );

        if ( $rendered ) {
            $this->engine->logStaticCopy( $node_id );
        }

        return is_string( $out ) ? $out : $html;
    }

    /**
     * `wpb_js_remove_wpautop( $content, true )`
     * (include/helpers/helpers.php, js_composer 9.0.1): every `<p>`/`</p>`
     * becomes a newline, `wpautop()` runs over the result, and
     * `shortcode_unautop()` unwraps a paragraph that holds nothing but one
     * shortcode. Only the standalone case is reproduced, because that is the
     * only case core's `shortcode_unautop()` fires in ("Don't do anything if
     * it doesn't contain a single shortcode").
     */
    protected function editorHtml( string $content ): string {
        $stripped = preg_replace( '#</?p>#', "\n", $content );

        $html = Autop::apply( (string) $stripped . "\n" );

        // A tag has to start with a letter, the way a registered shortcode does:
        // core's `shortcode_unautop()` only unwraps shortcodes it knows, so a
        // paragraph holding nothing but `[1]` keeps its `<p>` (amendment §5).
        return (string) preg_replace(
            '#<p>\s*(\[[A-Za-z][A-Za-z0-9_-]*(?![\w-])[^\]]*\](?:(?!\[[A-Za-z][A-Za-z0-9_-]*[^\]]*\]).*?\[/[A-Za-z][A-Za-z0-9_-]*\])?)\s*</p>#s',
            '$1',
            $html
        );
    }

    // -------------------------------------------------------------------------
    // Keeping what cannot be converted
    // -------------------------------------------------------------------------

    /**
     * What happens to an element with no Divi equivalent, and the reason the
     * converter never loses one.
     *
     * On the site the page lives on, the theme and its add-ons are active, so
     * the shortcode renders exactly as the visitor sees it and the HTML is
     * kept in a `divi/code` block — a static copy, reported as such. From an
     * export nothing can render it, so a labelled placeholder keeps the text
     * and the position instead.
     *
     * `do_shortcode()` on a tag nothing registered returns its input
     * untouched — non-empty, so it must not be read as a render on its own;
     * `shortcode_exists( $tag )` gates the call, the same fix `nestedShortcodes()`
     * needed.
     *
     * @param string $reason The `not_carried_over` kind to file it under
     *   (`addon`, `integration`); `''` when the caller has already reported
     *   the element some other way and a second entry would only repeat it.
     * @return array{html: string, static: bool}
     */
    protected function staticCopy( array $node, string $reason ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_code_' ) );
        $tag     = (string) ( $node['tag'] ?? '' );
        $options = $this->engine->options();

        $can_render = $options['mode'] === 'direct' && $options['render_shortcodes']
            && function_exists( 'do_shortcode' ) && function_exists( 'shortcode_exists' ) && shortcode_exists( $tag );

        if ( $can_render ) {
            $html = (string) do_shortcode( $this->originalShortcode( $node ) );
            if ( trim( $html ) !== '' ) {
                $this->engine->logStaticCopy( $id );
                if ( $reason !== '' ) {
                    $this->engine->logNotCarriedOver(
                        $reason,
                        $id,
                        sprintf( '%s: rendered on this site and kept as a static copy; it will not update when its plugin does', $tag )
                    );
                }

                return [ 'html' => $html, 'static' => true ];
            }
        }

        if ( $reason !== '' ) {
            $this->engine->logNotCarriedOver(
                $reason,
                $id,
                sprintf( '%s: kept as a labelled placeholder; rebuild it with a Divi module or the plugin\'s own shortcode', $tag )
            );
        }

        $text = trim( wp_strip_all_tags( $this->innerText( $node ) ) );
        $html = '<!-- wpbakery element: ' . esc_html( $tag ) . ' -->'
            . '<div class="wbdc-unconverted-element" data-wpbakery-element="' . esc_attr( $tag ) . '">' . esc_html( $text ) . '</div>';

        return [ 'html' => $html, 'static' => false ];
    }

    /** A node rebuilt as the shortcode text it was parsed from, children included. */
    protected function originalShortcode( array $node ): string {
        $tag = (string) ( $node['tag'] ?? '' );

        if ( $tag === '#text' ) {
            return (string) ( $node['text'] ?? '' );
        }
        if ( $tag === '' ) {
            return '';
        }

        $atts = '';
        foreach ( is_array( $node['atts'] ?? null ) ? $node['atts'] : [] as $key => $value ) {
            if ( ! is_string( $key ) ) {
                continue;
            }
            $atts .= is_string( $value ) || is_numeric( $value )
                ? ' ' . $key . '="' . str_replace( '"', '&quot;', (string) $value ) . '"'
                : ' ' . $key;
        }

        $inner = $this->innerShortcode( $node );

        return $inner === ''
            ? '[' . $tag . $atts . ']'
            : '[' . $tag . $atts . ']' . $inner . '[/' . $tag . ']';
    }

    /**
     * What an element held, whether or not its tag is a raw-content one.
     *
     * `NodeTree` keeps `content` only for `ShortcodeParser::RAW_CONTENT_TAGS`
     * and parses everything else into children — so an add-on element whose
     * content is a body of copy (`bsf-info-box`, `ultimate_pricing`) has to be
     * rebuilt from those children to read it back.
     */
    protected function rawContent( array $node ): string {
        return $this->innerShortcode( $node );
    }

    /** Everything a node held: its raw content, or its children rebuilt. */
    private function innerShortcode( array $node ): string {
        $content = (string) ( $node['content'] ?? '' );
        if ( $content !== '' ) {
            return $content;
        }

        $inner = '';
        foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
            if ( is_array( $child ) ) {
                $inner .= $this->originalShortcode( $child );
            }
        }

        return $inner;
    }

    /** The visible text a node carried, for a placeholder that keeps something readable. */
    private function innerText( array $node ): string {
        $text = (string) ( $node['content'] ?? '' );

        foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            $text .= ( $child['tag'] ?? '' ) === '#text' ? (string) ( $child['text'] ?? '' ) : $this->innerText( $child );
        }

        if ( trim( $text ) !== '' ) {
            return $text;
        }

        // Nothing inside: the attributes usually hold the label.
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        foreach ( [ 'title', 'heading', 'text', 'content', 'caption', 'label', 'btn_text' ] as $key ) {
            $value = $this->att( $atts, $key );
            if ( trim( $value ) !== '' ) {
                return $value;
            }
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Reporting
    // -------------------------------------------------------------------------

    /**
     * Every attribute the handler did not consume, so nothing is dropped in
     * silence.
     *
     * Four things are not "skipped settings": a blank value, a toggle switched
     * off (it describes nothing the page loses), the keys the StyleMapper
     * already owns, and WPBakery's own form scaffolding — the six icon-library
     * fields every icon element carries whether or not that library is the one
     * selected, an editor-only row label, a tab's generated id.
     */
    protected function logUnmappedSettings( string $node_id, array $atts, array $consumed = [], string $tag = '' ): void {
        static $always_ignore = [
            // The StyleMapper owns these five and reports what it could not
            // express — `css_animation` under `animation`, `disable_element`
            // under `visibility`, leftover `css` under `custom_css_carried` —
            // so a handler naming them again would report them twice.
            'css_animation', 'el_class', 'el_id', 'css', 'disable_element',
            // Editor-only labels and generated ids: nothing renders from them.
            'row_title', 'flexbox_container_title', 'grid_container_title', 'section_index', 'tab_id',
        ];

        foreach ( $atts as $key => $value ) {
            if ( ! is_string( $key ) || in_array( $key, $consumed, true ) || in_array( $key, $always_ignore, true ) ) {
                continue;
            }
            if ( $value === '' || $value === null || $value === [] || $value === false ) {
                continue;
            }
            if ( is_string( $value ) && in_array( strtolower( trim( $value ) ), [ 'no', 'none', 'off', 'false' ], true ) ) {
                continue;
            }
            if ( $this->isInertIconLibrary( $key, $atts ) ) {
                continue;
            }
            // `vc_text_separator`'s `layout` names an editor preset, not a
            // rendered difference.
            if ( $key === 'layout' && $tag === 'vc_text_separator' ) {
                continue;
            }

            $this->engine->logSkippedSetting( "{$node_id}: {$key}" );
        }
    }

    /**
     * WPBakery's icon params ship one field per library
     * (`i_icon_openiconic`, `icon_typicons`, …) and the form fills every one
     * of them; only the library `i_type`/`icon_type` names is rendered.
     */
    private function isInertIconLibrary( string $key, array $atts ): bool {
        if ( preg_match( '/^(i_)?icon_(fontawesome|openiconic|typicons|entypo|linecons|pixelicons|monosocial|material)$/', $key, $m ) !== 1 ) {
            return false;
        }

        // Which field names the library differs per element: `vc_btn` and
        // `vc_text_separator` integrate the icon under an `i_` prefix,
        // `vc_message` calls it `icon_type`, and `vc_icon`'s own field is
        // simply `type` (config/content/vc-icon-element.php).
        $selected = $m[1] === 'i_'
            ? $this->att( $atts, 'i_type', 'fontawesome' )
            : $this->att( $atts, 'icon_type', $this->att( $atts, 'type', 'fontawesome' ) );

        return $selected !== $m[2];
    }
}
