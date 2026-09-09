<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\CssRuleParser;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_row` → `divi/row`.
 *
 * A WPBakery row is two boxes in one. Its design options — background,
 * padding, border — paint the full-bleed band, which is what a Divi *section*
 * is; its gap, column alignment and stretch behaviour describe how the columns
 * inside it sit, which is what a Divi *row* is. So a top-level row becomes a
 * section holding one row (spec §5), and the section converter asks this class
 * for the half that belongs to it (`sectionAttrs()`) before converting the
 * row itself with `lifted` set. A row inside an explicit `vc_section` keeps
 * both halves, because its section already exists and carries its own design.
 *
 * The geometry is the measured box model, `docs/box-model.md` candidate C:
 * a 15 px bleed on every container-width row, the `gap` on the row's own
 * `columnGap`/`rowGap` plus `gap/2` of vertical padding, and never `gap/2` as
 * column padding.
 */
class RowConverter extends BaseWPBakeryConverter {

    /** `columns_placement` / `equal_height` ⇒ Divi row `alignItems`. */
    private const COLUMNS_PLACEMENT = [
        'top'     => 'flex-start',
        'middle'  => 'center',
        'bottom'  => 'flex-end',
        'stretch' => 'stretch',
    ];

    /** `content_placement` ⇒ the row `alignItems` it sets on a non-equal-height row. */
    private const CONTENT_PLACEMENT = [
        'top'    => 'flex-start',
        'middle' => 'center',
        'bottom' => 'flex-end',
    ];

    /** WPBakery's `parallax` values ⇒ Divi's `image.parallax.method`. */
    private const PARALLAX_METHOD = [
        'content-moving'      => 'on',
        'content-moving-fade' => 'on',
        'fixed'               => 'off',
        'fixed-fade'          => 'off',
    ];

    /** The attributes `sectionAttrs()` consumes on behalf of the section. */
    private const SECTION_KEYS = [
        'full_height', 'min_height', 'parallax', 'parallax_image', 'parallax_speed_bg',
        'parallax_speed_video', 'video_bg', 'video_bg_url', 'video_bg_parallax',
    ];

    public function convert( array $node ): array {
        $id     = (string) ( $node['id'] ?? uniqid( 'wbdc_row_' ) );
        $atts   = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $lifted = ! empty( $node['lifted'] );

        $consumed = [ 'gap', 'equal_height', 'columns_placement', 'content_placement', 'rtl_reverse', 'full_width' ];
        $settings = $this->geometry( $node, $atts );

        // A row whose design options went to the section keeps only its
        // layout; one that stands on its own paints them here.
        if ( ! $lifted ) {
            $style    = $this->mapStyle( 'row', $node );
            $settings = $this->deepMergeSettings( $settings, $style['divi_attrs'] );
            $settings = $this->deepMergeSettings( $settings, $this->heightAttrs( $atts ) );
            $settings = $this->parallaxBackground( $id, $atts, $settings );
            $this->reportBackgrounds( $id, $atts );
            $consumed = array_merge( $consumed, $style['handled_keys'], self::SECTION_KEYS );
        } else {
            $consumed = array_merge( $consumed, StyleMapper::COMMON_KEYS, self::SECTION_KEYS );
        }

        $settings = $this->deepMergeSettings( $settings, $this->layoutAttrs( $id, $atts ) );

        $columns = $this->convertColumns( $id, $node, $atts );
        $settings = $this->deepMergeSettings( $settings, $this->rowSettingsFromColumns( $columns ) );

        $this->engine->logConverted( $this->countedAs() );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/row', $settings, $columns );
    }

    /**
     * The half of a top-level row that belongs to the section standing in for
     * it: its design options, its id and classes, its visibility and its
     * height. Called by `SectionConverter` before `convert()`.
     *
     * @return array{divi_attrs: array, handled_keys: string[]}
     */
    public function sectionAttrs( array $node ): array {
        $id   = (string) ( $node['id'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style = $this->mapStyle( 'section', $node );
        $attrs = $this->deepMergeSettings( $style['divi_attrs'], $this->heightAttrs( $atts ) );
        $attrs = $this->parallaxBackground( $id, $atts, $attrs );
        $attrs = SectionConverter::fillSectionPadding( $attrs );

        $this->reportBackgrounds( $id, $atts );

        return [
            'divi_attrs'   => $attrs,
            'handled_keys' => array_merge( $style['handled_keys'], self::SECTION_KEYS ),
        ];
    }

    /**
     * Whether a row paints something — WPBakery's `vc_row-has-fill`, which
     * gives its columns 35 px of top padding and gives the *next* row's
     * columns the same (`js_composer.min.css`; measured in docs/box-model.md).
     *
     * The test is WPBakery's own: any `border` or `background` property in the
     * design-options rule, or a video/parallax background
     * (`vc_shortcode_custom_css_has_property()`, a substring match, not a
     * property-name match).
     */
    public static function isFilled( array $node ): bool {
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        if ( PackedParams::isOn( $atts['video_bg'] ?? '' ) || trim( (string) ( $atts['parallax'] ?? '' ) ) !== '' ) {
            return true;
        }

        $css = (string) ( $atts['css'] ?? '' );
        if ( trim( $css ) === '' ) {
            return false;
        }

        foreach ( array_keys( CssRuleParser::parse( $css )['declarations'] ) as $property ) {
            if ( str_contains( $property, 'border' ) || str_contains( $property, 'background' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks a run of sibling rows with the two flags their columns need:
     * `filled` for a row that paints, `after_filled` for the row right after
     * one. Used by whoever owns the run — the section for top-level rows, the
     * column for nested ones.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function markFilled( array $rows ): array {
        $previous_filled = false;

        foreach ( $rows as $index => $row ) {
            $filled = self::isFilled( $row );

            if ( $filled ) {
                $rows[ $index ]['filled'] = true;
            }
            if ( $previous_filled ) {
                $rows[ $index ]['after_filled'] = true;
            }

            $previous_filled = $filled;
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Geometry and layout
    // -------------------------------------------------------------------------

    /**
     * Width, bleed, padding and gaps.
     *
     * `full_width`: `stretch_row` needs nothing — a Divi section is already
     * full-bleed and WPBakery keeps the row at content width inside it;
     * `stretch_row_content` and `…_no_spaces` widen the row itself to 100 %
     * with no bleed (the no-spaces variant also drops the column side padding,
     * `.vc_row-no-padding .vc_column-inner`, which the columns are told about).
     *
     * A `vc_section` stretches the rows inside it the same way — its own
     * `full_width` is what carries `data-vc-stretch-content` — and passes its
     * choice down on the node as `section_stretch`.
     */
    protected function geometry( array $node, array $atts ): array {
        $settings = $this->stretched( $node, $atts )
            ? self::stretchedRowSettings()
            : $this->baseGeometry();

        $gap = $this->gap( $atts );
        if ( $gap > 0 ) {
            $half = self::px( $gap / 2 );
            StyleMapper::write( $settings, 'module.decoration.layout.desktop.value.columnGap', self::px( $gap ) );
            StyleMapper::write( $settings, 'module.decoration.layout.desktop.value.rowGap', self::px( $gap ) );
            StyleMapper::write( $settings, 'module.decoration.spacing.desktop.value.padding', self::box( $half, '0px', $half, '0px' ) );
        }

        return $settings;
    }

    /** A top-level row carries WPBakery's 15 px bleed; an inner one bleeds inside its column. */
    protected function baseGeometry(): array {
        return self::containerRowSettings();
    }

    /** Whether this row runs the full width of its section — its own doing, or its section's. */
    private function stretched( array $node, array $atts ): bool {
        return in_array( $this->att( $atts, 'full_width' ), [ 'stretch_row_content', 'stretch_row_content_no_spaces' ], true )
            || in_array( (string) ( $node['section_stretch'] ?? '' ), [ 'content', 'no_spaces' ], true );
    }

    /** Whether the columns lose their side padding — `_no_spaces`, from either level. */
    private function noSpaces( array $node, array $atts ): bool {
        return $this->att( $atts, 'full_width' ) === 'stretch_row_content_no_spaces'
            || (string) ( $node['section_stretch'] ?? '' ) === 'no_spaces';
    }

    /**
     * `content_placement`, `equal_height`, `columns_placement` and
     * `rtl_reverse` — how the columns sit, in the order WPBakery's own
     * stylesheet resolves them.
     *
     * `content_placement` has two halves. It sets `justify-content` on
     * `.vc_column-inner`, which is where the column's modules sit — the Divi
     * column's own `justifyContent`, written by `ColumnConverter` — and, only
     * when the row is not equal-height, `align-items` on `.vc_column_container`
     * (`.vc_row-o-content-*:not(.vc_row-o-equal-height) > .vc_column_container`),
     * which is where the column sits in the row: the Divi row's `alignItems`.
     * `.vc_row-o-equal-height > .vc_column_container{align-items:stretch}`
     * takes over when it is on, and `columns_placement` — which WPBakery only
     * emits on a full-height row — wins over both.
     */
    private function layoutAttrs( string $node_id, array $atts ): array {
        $layout    = [];
        $placement = $this->att( $atts, 'content_placement' );

        if ( isset( self::CONTENT_PLACEMENT[ $placement ] ) ) {
            $layout['alignItems'] = self::CONTENT_PLACEMENT[ $placement ];
        }

        if ( $this->on( $atts, 'equal_height' ) ) {
            $layout['alignItems'] = 'stretch';
        }

        $columns = $this->att( $atts, 'columns_placement' );
        if ( $this->on( $atts, 'full_height' ) && isset( self::COLUMNS_PLACEMENT[ $columns ] ) ) {
            $layout['alignItems'] = self::COLUMNS_PLACEMENT[ $columns ];
        }

        $layout = $this->rtlReverse( $node_id, $atts, $layout );

        return $layout === [] ? [] : [ 'module' => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => $layout ] ] ] ] ];
    }

    /**
     * `rtl_reverse` reverses the column order — but every rule WPBakery writes
     * for it is scoped to `[dir=rtl]` (`.vc_rtl-columns-reverse`), so on a
     * left-to-right site it changes nothing at all. Divi's `flexDirection` is
     * not direction-scoped, so writing it unconditionally would reverse the
     * columns of every LTR page that happens to carry the attribute. It is
     * written only where WPBakery would have applied it, and reported
     * everywhere else.
     */
    private function rtlReverse( string $node_id, array $atts, array $layout ): array {
        if ( ! $this->on( $atts, 'rtl_reverse' ) ) {
            return $layout;
        }

        if ( function_exists( 'is_rtl' ) && is_rtl() ) {
            $layout['flexDirection'] = 'row-reverse';

            return $layout;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $node_id,
            'rtl_reverse applies only on RTL sites; this site reads left to right, so the column order is unchanged'
        );

        return $layout;
    }

    /** `full_height` → 100vh, `min_height` → its own value; the explicit one wins, as in WPBakery. */
    private function heightAttrs( array $atts ): array {
        $min_height = PackedParams::sizeWithUnit( $this->att( $atts, 'min_height' ) );

        if ( $min_height === '' && $this->on( $atts, 'full_height' ) ) {
            $min_height = '100vh';
        }

        return $min_height === ''
            ? []
            : [ 'module' => [ 'decoration' => [ 'sizing' => [ 'desktop' => [ 'value' => [ 'minHeight' => $min_height ] ] ] ] ] ];
    }

    // -------------------------------------------------------------------------
    // Backgrounds WPBakery expresses and Divi does not
    // -------------------------------------------------------------------------

    /**
     * A parallax image becomes a Divi parallax background; a YouTube video
     * background cannot, because Divi's takes mp4/webm files, so it is
     * reported and its fallback image kept.
     */
    public function reportBackgrounds( string $node_id, array $atts ): void {
        $video_url = $this->att( $atts, 'video_bg_url' );
        if ( $this->on( $atts, 'video_bg' ) && $video_url !== '' ) {
            $this->engine->logNotCarriedOver(
                'background',
                $node_id,
                'YouTube video background (' . $video_url . '): Divi background video takes an mp4 or webm file, so the video is not carried over'
            );
        }

        $parallax = $this->att( $atts, 'parallax' );
        if ( $parallax === '' ) {
            return;
        }

        if ( str_contains( $parallax, 'fade' ) ) {
            $this->engine->logNotCarriedOver( 'background', $node_id, 'parallax fade (' . $parallax . '): Divi has no fade-on-scroll background' );
        }

        $speed = $this->att( $atts, 'parallax_speed_bg' );
        if ( $speed !== '' && $speed !== '1.5' ) {
            $this->engine->logNotCarriedOver( 'background', $node_id, 'parallax speed ' . $speed . ': Divi\'s parallax has no speed setting' );
        }
    }

    /**
     * The parallax image, once the section knows its own attrs. Written here
     * rather than in `reportBackgrounds()` because it needs the media lookup.
     */
    public function parallaxBackground( string $node_id, array $atts, array $attrs ): array {
        $parallax = $this->att( $atts, 'parallax' );
        $image_id = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'parallax_image' ) );

        if ( $parallax === '' || $image_id <= 0 ) {
            return $attrs;
        }

        $url = $this->attachmentUrl( $image_id, 'full', $node_id );
        if ( $url === null ) {
            return $attrs;
        }

        StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.image.url', $url );
        StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.image.parallax.enabled', 'on' );
        StyleMapper::write(
            $attrs,
            'module.decoration.background.desktop.value.image.parallax.method',
            self::PARALLAX_METHOD[ $parallax ] ?? 'on'
        );

        return $attrs;
    }

    // -------------------------------------------------------------------------
    // Columns
    // -------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function convertColumns( string $id, array $node, array $atts ): array {
        $filled       = ! empty( $node['filled'] ) || ! empty( $node['after_filled'] );
        $no_spaces    = $this->noSpaces( $node, $atts );
        $placement    = $this->att( $atts, 'content_placement' );
        $column_nodes = [];

        foreach ( $node['children'] ?? [] as $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            if ( $filled ) {
                $child['filled'] = true;
            }
            if ( $no_spaces ) {
                $child['no_spaces'] = true;
            }
            if ( $placement !== '' ) {
                $child['content_placement'] = $placement;
            }
            $column_nodes[] = $child;
        }

        $columns = $this->ensureColumnChildren( $id, $this->engine->convertChildren( $column_nodes ) );

        if ( $columns === [] ) {
            $this->engine->logWarning( "Empty row kept: {$id} had no columns; a full-width empty column stands in for it." );
            $columns = [ $this->block( $id . '-col', 'divi/column', self::fullWidthColumnSettings() ) ];
        }

        return $columns;
    }

    // -------------------------------------------------------------------------

    /** What the report counts this row as. */
    protected function countedAs(): string {
        return 'row';
    }

    /** `gap` is a plain number of pixels (`vc_row`'s `number` param, min 0). */
    private function gap( array $atts ): float {
        $gap = $this->att( $atts, 'gap' );

        return is_numeric( $gap ) ? max( 0.0, (float) $gap ) : 0.0;
    }

    /** `7.5` → `"7.5px"`, `30` → `"30px"`; a zero is `"0px"`, never `"0"`. */
    private static function px( float $value ): string {
        return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' ) . 'px';
    }
}
