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
        $settings = $this->geometry( $atts );

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

        $settings = $this->deepMergeSettings( $settings, $this->layoutAttrs( $atts ) );

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
     */
    protected function geometry( array $atts ): array {
        $settings = in_array( $this->att( $atts, 'full_width' ), [ 'stretch_row_content', 'stretch_row_content_no_spaces' ], true )
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

    /** `equal_height`, `columns_placement` and `rtl_reverse` — how the columns sit. */
    private function layoutAttrs( array $atts ): array {
        $layout = [];

        if ( $this->on( $atts, 'equal_height' ) ) {
            $layout['alignItems'] = 'stretch';
        }

        // WPBakery only emits the column-position class on a full-height row.
        $placement = $this->att( $atts, 'columns_placement' );
        if ( $this->on( $atts, 'full_height' ) && isset( self::COLUMNS_PLACEMENT[ $placement ] ) ) {
            $layout['alignItems'] = self::COLUMNS_PLACEMENT[ $placement ];
        }

        if ( $this->on( $atts, 'rtl_reverse' ) ) {
            $layout['flexDirection'] = 'row-reverse';
        }

        return $layout === [] ? [] : [ 'module' => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => $layout ] ] ] ] ];
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
        $no_spaces    = $this->att( $atts, 'full_width' ) === 'stretch_row_content_no_spaces';
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
