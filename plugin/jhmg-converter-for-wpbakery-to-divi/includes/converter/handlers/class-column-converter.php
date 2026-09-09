<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\ColumnWidths;
use WPBakeryDivi5Converter\StyleMapper\GlobalSettingsResolver;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_column` and `vc_column_inner` → `divi/column`.
 *
 * Three things decide what a column looks like:
 *
 * - **`width`.** WPBakery's twelfths and fifths all land exactly on Divi
 *   5.12's 24-column flex grid (`ColumnWidths`), so every width converts:
 *   `module.advanced.type` carries the legacy fraction, `sizing.flexType` the
 *   real width. `width` is `vc_col-sm-N`, a Bootstrap 3 class, so it applies
 *   from 768 px up — desktop and tablet in Divi's bands; below that WPBakery
 *   stacks, which is why phone is written full width unless an `xs` class
 *   says otherwise.
 * - **`offset`.** The per-breakpoint classes: widths, left insets and
 *   visibility. When the value carries an `inherit` marker WPBakery drops the
 *   `width` class altogether, so the offsets are the only source of width
 *   there (amendment §8).
 * - **The row it sits in.** WPBakery paints the padding on `.vc_column-inner`,
 *   which is what a Divi column is: `0 15px`, plus 35 px on top when the row
 *   paints a background *or follows a row that does*, and none at the sides in
 *   a `stretch_row_content_no_spaces` row. The row passes those down as flags
 *   on the node; the gap never becomes column padding (amendment §1-2).
 */
class ColumnConverter extends BaseWPBakeryConverter {

    /** `content_placement` ⇒ the column's own `justifyContent`. */
    private const CONTENT_PLACEMENT = [
        'top'    => 'flex-start',
        'middle' => 'center',
        'bottom' => 'flex-end',
    ];

    /** Divi's three breakpoints, largest first. */
    private const BREAKPOINTS = [ 'desktop', 'tablet', 'phone' ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_column_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style   = $this->mapStyle( 'column', $node );
        $attrs   = $style['divi_attrs'];
        $offsets = ColumnWidths::offsets( $this->att( $atts, 'offset' ) );

        $attrs = $this->applyWidth( $id, $atts, $offsets, $attrs );
        $attrs = $this->applyPadding( $node, $attrs );
        $attrs = $this->applyOffsets( $id, $offsets, $attrs );

        // WPBakery stacks the modules in a column with their own margins only;
        // Divi's 30 px column gap would add to them.
        StyleMapper::write( $attrs, 'module.decoration.layout.desktop.value.rowGap', '0px' );

        $placement = (string) ( $node['content_placement'] ?? '' );
        if ( isset( self::CONTENT_PLACEMENT[ $placement ] ) ) {
            StyleMapper::write( $attrs, 'module.decoration.layout.desktop.value.justifyContent', self::CONTENT_PLACEMENT[ $placement ] );
        }

        $children = $this->groupInline( $id, $this->convertColumnChildren( $node ) );

        if ( $children === [] ) {
            $this->engine->logWarning( "Empty column kept: {$id} had nothing in it." );
        }

        $this->engine->logConverted( 'column' );
        $this->logUnmappedSettings(
            $id,
            $atts,
            array_merge( $style['handled_keys'], [ 'width', 'offset' ] ),
            (string) ( $node['tag'] ?? '' )
        );

        return $this->block( $id, 'divi/column', $attrs, $children );
    }

    // -------------------------------------------------------------------------
    // Width
    // -------------------------------------------------------------------------

    /**
     * @param array{flex: array<string,string>, hidden: array<string,bool>, margin_left: array<string,string>, inherit: bool, notes: string[]} $offsets
     */
    private function applyWidth( string $id, array $atts, array $offsets, array $attrs ): array {
        $width = $this->att( $atts, 'width', '1/1' );

        if ( ColumnWidths::flexType( $width ) === null ) {
            $this->engine->logWarning( "Column {$id}: width \"{$width}\" is not one WPBakery renders; the column was made full width." );
            $width = '1/1';
        }

        // The `inherit` marker stops WPBakery emitting the `vc_col-sm-N` class
        // at all (`vc_column_offset_class_merge()`), so `width` contributes
        // nothing and the offsets are the only source.
        $base = $offsets['inherit'] ? null : ColumnWidths::flexType( $width );

        if ( $offsets['inherit'] && $offsets['flex'] === [] ) {
            $this->engine->logWarning( "Column {$id}: its offset leaves every breakpoint inherited and sets no width, so the column is full width." );
        }

        $flex = [
            'desktop' => $offsets['flex']['desktop'] ?? $base ?? '24_24',
            'tablet'  => $offsets['flex']['tablet'] ?? $base,
            // Below 768 px WPBakery's `vc_col-sm-*` grid stops applying and
            // columns are full width; only a `vc_col-xs-*` class changes that.
            'phone'   => $offsets['flex']['phone'] ?? '24_24',
        ];

        foreach ( self::BREAKPOINTS as $breakpoint ) {
            if ( $flex[ $breakpoint ] === null ) {
                continue;
            }
            // Divi inherits a breakpoint from the one above it, so a value
            // equal to its parent's is noise — except desktop, which is the base.
            if ( $breakpoint !== 'desktop' && $flex[ $breakpoint ] === $this->inheritedFlex( $flex, $breakpoint ) ) {
                continue;
            }
            StyleMapper::write( $attrs, "module.decoration.sizing.{$breakpoint}.value.flexType", $flex[ $breakpoint ] );
        }

        // The legacy fraction Divi's own CSS and the row's columnStructure
        // read; it describes the desktop width, which an offset may have moved.
        $type = ColumnWidths::type( self::widthFor( $flex['desktop'] ) ?? $width );
        StyleMapper::write( $attrs, 'module.advanced.type.desktop.value', $type ?? '4_4' );

        return $attrs;
    }

    /** The value a breakpoint would take from the one above it. */
    private function inheritedFlex( array $flex, string $breakpoint ): ?string {
        $index = array_search( $breakpoint, self::BREAKPOINTS, true );

        for ( $i = (int) $index - 1; $i >= 0; $i-- ) {
            $value = $flex[ self::BREAKPOINTS[ $i ] ] ?? null;
            if ( $value !== null ) {
                return $value;
            }
        }

        return null;
    }

    /** `10_24` → `5/12`; the `flexType` table read backwards. */
    private static function widthFor( string $flex_type ): ?string {
        static $reverse = null;
        $reverse ??= array_flip( ColumnWidths::FLEX_TYPE );

        return $reverse[ $flex_type ] ?? null;
    }

    // -------------------------------------------------------------------------
    // Padding
    // -------------------------------------------------------------------------

    /**
     * `.vc_column-inner{padding:0 15px}`, plus the 35 px of top padding
     * WPBakery gives a painted box, minus the sides in a no-spaces row.
     *
     * Three things trigger the top padding, all in one CSS rule: the row
     * paints a background or border (`.vc_row-has-fill`), the row *before* it
     * did (`.vc_row-has-fill + .vc_row`), or this column paints one itself
     * (`.vc_col-has-fill`). The row passes the first two down as flags; the
     * third is the column's own design options, tested exactly as WPBakery's
     * `vc_column.php` template tests them.
     *
     * A side the column's own design options set keeps its value: WPBakery
     * writes those `!important` and this stylesheet rule is not, so the
     * author's value wins there too (verified in `js_composer.min.css`).
     */
    private function applyPadding( array $node, array $attrs ): array {
        $padding = $this->read( $attrs, 'module.decoration.spacing.desktop.value.padding' );
        $padding = is_array( $padding ) ? $padding : [];

        $side_padding = empty( $node['no_spaces'] ) ? GlobalSettingsResolver::columnPaddingX() : '0px';
        $has_fill     = ! empty( $node['filled'] ) || ! empty( $node['after_filled'] ) || RowConverter::isFilled( $node );

        $defaults = [
            'top'    => $has_fill ? GlobalSettingsResolver::filledColumnPaddingTop() : '0px',
            'right'  => $side_padding,
            'bottom' => '0px',
            'left'   => $side_padding,
        ];

        foreach ( $defaults as $side => $value ) {
            if ( ! isset( $padding[ $side ] ) || $padding[ $side ] === '' ) {
                $padding[ $side ] = $value;
            }
        }

        StyleMapper::write(
            $attrs,
            'module.decoration.spacing.desktop.value.padding',
            $padding + [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ]
        );

        return $attrs;
    }

    // -------------------------------------------------------------------------
    // Offsets
    // -------------------------------------------------------------------------

    /**
     * The left insets and the visibility classes.
     *
     * `disabledOn` is written on all three breakpoints whenever any of them
     * hides the column: Divi inherits a breakpoint from the one above it, so
     * `vc_hidden-sm` alone would hide the phone too without an explicit `off`.
     *
     * @param array{flex: array<string,string>, hidden: array<string,bool>, margin_left: array<string,string>, inherit: bool, notes: string[]} $offsets
     */
    private function applyOffsets( string $id, array $offsets, array $attrs ): array {
        $insets = $offsets['margin_left'];
        foreach ( self::BREAKPOINTS as $index => $breakpoint ) {
            $existing = $this->read( $attrs, "module.decoration.spacing.{$breakpoint}.value.margin" );
            $existing = is_array( $existing ) ? $existing : [];
            $value    = $insets[ $breakpoint ] ?? null;

            // A Bootstrap offset class stops applying below its own band, but
            // Divi inherits a breakpoint from the one above it — so a smaller
            // breakpoint the offset does not reach is reset to zero, and only
            // then (a margin the design options set is not breakpoint-scoped
            // in WPBakery either, so it is left to inherit).
            if ( $value === null ) {
                if ( ! self::insetAbove( $insets, $index ) || ( $existing['left'] ?? '' ) !== '' ) {
                    continue;
                }
                $value = '0px';
            }

            StyleMapper::write( $attrs, "module.decoration.spacing.{$breakpoint}.value.margin", array_merge(
                [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ],
                $existing,
                [ 'left' => $value, 'syncVertical' => 'off', 'syncHorizontal' => 'off' ]
            ) );
        }

        if ( $offsets['hidden'] !== [] ) {
            foreach ( self::BREAKPOINTS as $breakpoint ) {
                StyleMapper::write(
                    $attrs,
                    "module.decoration.disabledOn.{$breakpoint}.value",
                    empty( $offsets['hidden'][ $breakpoint ] ) ? 'off' : 'on'
                );
            }
            $this->engine->logNotCarriedOver(
                'visibility',
                $id,
                'hidden on ' . implode( ', ', array_keys( array_filter( $offsets['hidden'] ) ) ) . ' by its offset classes'
            );
        }

        foreach ( $offsets['notes'] as $note ) {
            $this->engine->logNotCarriedOver( 'layout', $id, $note );
        }

        return $attrs;
    }

    /** Whether a larger breakpoint than $index carries an offset inset. */
    private static function insetAbove( array $insets, int $index ): bool {
        for ( $i = $index - 1; $i >= 0; $i-- ) {
            if ( isset( $insets[ self::BREAKPOINTS[ $i ] ] ) ) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Children
    // -------------------------------------------------------------------------

    /**
     * A column's children, with the nested rows among them marked so their own
     * columns get the filled-row top padding — the `.vc_row-has-fill + .vc_row`
     * rule applies inside a column as well, because `vc_row_inner` renders with
     * the `vc_row` class too.
     *
     * @return array<int,array<string,mixed>>
     */
    private function convertColumnChildren( array $node ): array {
        $children = [];
        $rows     = [];

        foreach ( $node['children'] ?? [] as $index => $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            $children[ $index ] = $child;
            if ( in_array( $child['kind'] ?? '', [ 'row', 'row_inner' ], true ) ) {
                $rows[ $index ] = $child;
            }
        }

        foreach ( RowConverter::markFilled( array_values( $rows ) ) as $position => $row ) {
            $children[ array_keys( $rows )[ $position ] ] = $row;
        }

        // `.vc_toggle:last-of-type{margin-bottom:35px}` is a rule about a
        // toggle's neighbours, so it is resolved here, where they are visible.
        return $this->engine->convertChildren( ToggleConverter::markLastOfRun( array_values( $children ) ) );
    }

    /**
     * Blocks a handler asked to sit side by side — a run of buttons, a row of
     * icons — go in a nested flexed row, because Divi stacks a column's
     * modules and has no inline mode for them.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @return array<int,array<string,mixed>>
     */
    private function groupInline( string $id, array $blocks ): array {
        $grouped = [];
        $run     = [];
        $count   = 0;

        $flush = function () use ( &$grouped, &$run, &$count, $id ): void {
            if ( $run === [] ) {
                return;
            }
            $count++;
            $grouped[] = $this->inlineRow( $id . '-inline-' . $count, $run );
            $run       = [];
        };

        foreach ( $blocks as $block ) {
            if ( ! empty( $block['inline'] ) ) {
                unset( $block['inline'] );
                $run[] = $block;

                continue;
            }
            $flush();
            $grouped[] = $block;
        }
        $flush();

        return $grouped;
    }

    /** @param array<int,array<string,mixed>> $blocks */
    private function inlineRow( string $id, array $blocks ): array {
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
            $this->deepMergeSettings( self::nestedRowSettings(), [ 'module' => [ 'advanced' => [ 'columnStructure' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ] ] ),
            [ $column ]
        );
    }
}
