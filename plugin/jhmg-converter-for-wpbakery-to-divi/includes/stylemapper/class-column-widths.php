<?php

namespace WPBakeryDivi5Converter\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WPBakery column widths and responsive `offset` classes → Divi 5 column
 * sizing.
 *
 * WPBakery widths are twelfths plus fifths — the list is
 * `Vc_Column_Offset::$column_width_list` (js_composer 9.0.1,
 * include/params/column_offset/column_offset.php) and
 * `wpb_translateColumnWidthToSpan()` (include/helpers/helpers.php), which
 * renders `width` as `vc_col-sm-N` (or `vc_col-sm-N/5`).
 *
 * Divi 5.12 sizes a flex column from
 * `module.decoration.sizing.{bp}.value.flexType` on a 24-column grid (the
 * shipped grid CSS defines `et_flex_column_N_24` for N = 1…24 plus `n_5`),
 * with the legacy fraction at `module.advanced.type`; `module.advanced.type`
 * alone renders `24_24`. Divi's own migration table
 * (`MigrationUtils::map_flex_type_to_column_type`) names `5_12` and `7_12`,
 * so none of the legacy fractions here are invented.
 *
 * Breakpoints. WPBakery's bands come straight from `js_composer.min.css`
 * (9.0.1): xs < 768, sm 768–991, md 992–1199, lg 1200–1399, xl ≥ 1400.
 * Divi's are phone < 768, tablet 768–980, desktop ≥ 981. So:
 * xs → phone, sm → tablet **and** desktop (Bootstrap's `sm` applies upwards),
 * md / lg / xl → desktop, the larger band winning and the difference reported.
 */
final class ColumnWidths {

    /** WPBakery width ⇒ Divi `module.decoration.sizing.{bp}.value.flexType`. */
    const FLEX_TYPE = [
        '1/12'  => '2_24',
        '1/6'   => '4_24',
        '1/4'   => '6_24',
        '1/3'   => '8_24',
        '5/12'  => '10_24',
        '1/2'   => '12_24',
        '7/12'  => '14_24',
        '2/3'   => '16_24',
        '3/4'   => '18_24',
        '5/6'   => '20_24',
        '11/12' => '22_24',
        '1/1'   => '24_24',
        '1/5'   => '1_5',
        '2/5'   => '2_5',
        '3/5'   => '3_5',
        '4/5'   => '4_5',
    ];

    /** WPBakery width ⇒ Divi `module.advanced.type` (the legacy fraction). */
    const TYPE = [
        '1/12'  => '1_12',
        '1/6'   => '1_6',
        '1/4'   => '1_4',
        '1/3'   => '1_3',
        '5/12'  => '5_12',
        '1/2'   => '1_2',
        '7/12'  => '7_12',
        '2/3'   => '2_3',
        '3/4'   => '3_4',
        '5/6'   => '5_6',
        '11/12' => '11_12',
        '1/1'   => '4_4',
        '1/5'   => '1_5',
        '2/5'   => '2_5',
        '3/5'   => '3_5',
        '4/5'   => '4_5',
    ];

    /**
     * The offset-class span index (`vc_col-sm-6`, `vc_col-md-2/5`) ⇒ the
     * WPBakery width it stands for. `Vc_Column_Offset::getColumnIndexFromWidth()`
     * inverted.
     */
    const SPAN_TO_WIDTH = [
        '1'   => '1/12',
        '2'   => '1/6',
        '3'   => '1/4',
        '4'   => '1/3',
        '5'   => '5/12',
        '6'   => '1/2',
        '7'   => '7/12',
        '8'   => '2/3',
        '9'   => '3/4',
        '10'  => '5/6',
        '11'  => '11/12',
        '12'  => '1/1',
        '1/5' => '1/5',
        '2/5' => '2/5',
        '3/5' => '3/5',
        '4/5' => '4/5',
    ];

    /** WPBakery size token ⇒ the Divi breakpoints it drives. */
    const SIZE_BREAKPOINTS = [
        'xs' => [ 'phone' ],
        'sm' => [ 'tablet', 'desktop' ],
        'md' => [ 'desktop' ],
        'lg' => [ 'desktop' ],
        'xl' => [ 'desktop' ],
    ];

    /** Largest wins when two of these set the same Divi breakpoint. */
    const SIZE_RANK = [ 'xs' => 0, 'sm' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4 ];

    /** The WPBakery bands that all land on Divi's single desktop breakpoint. */
    const DESKTOP_SIZES = [ 'md', 'lg', 'xl' ];

    /** `1/3` → `8_24`; `''` (no width set) → a full-width column; unknown → null. */
    public static function flexType( string $width ): ?string {
        $width = trim( $width );

        return self::FLEX_TYPE[ $width === '' ? '1/1' : $width ] ?? null;
    }

    /** `1/3` → `1_3`, `1/1` → `4_4`; `''` → a full-width column; unknown → null. */
    public static function type( string $width ): ?string {
        $width = trim( $width );

        return self::TYPE[ $width === '' ? '1/1' : $width ] ?? null;
    }

    /** `1/3` → 33.3333; unknown → null. Rounded the way WPBakery labels its own list. */
    public static function percent( string $width ): ?float {
        $width = trim( $width );
        $width = $width === '' ? '1/1' : $width;

        if ( ! isset( self::FLEX_TYPE[ $width ] ) || ! preg_match( '#^(\d+)/(\d+)$#', $width, $m ) ) {
            return null;
        }

        return round( (int) $m[1] / (int) $m[2] * 100, 4 );
    }

    /**
     * The `offset` attribute's responsive classes.
     *
     * `vc_col-{size}-N` sizes a breakpoint, `vc_col-{size}-offset-N` insets it
     * with a left margin (Bootstrap's `margin-left: N/12`), `vc_hidden-{size}`
     * hides it. Anything the mapping can only approximate — an md/lg pair Divi
     * has no separate band for, a desktop band hidden on its own — is named in
     * `notes` rather than silently flattened.
     *
     * @return array{
     *     flex: array<string,string>,
     *     hidden: array<string,bool>,
     *     margin_left: array<string,string>,
     *     notes: string[]
     * }
     */
    public static function offsets( string $offset ): array {
        $result = [ 'flex' => [], 'hidden' => [], 'margin_left' => [], 'notes' => [] ];

        $offset = trim( $offset );
        if ( $offset === '' ) {
            return $result;
        }

        $tokens = preg_split( '/\s+/', self::normaliseLegacyHidden( $offset ) ) ?: [];

        $sizes   = [];  // size ⇒ WPBakery width
        $insets  = [];  // size ⇒ offset span
        $hidden  = [];  // size ⇒ true

        foreach ( $tokens as $token ) {
            if ( preg_match( '/^vc_hidden-(xs|sm|md|lg|xl)$/', $token, $m ) ) {
                $hidden[ $m[1] ] = true;
                continue;
            }
            if ( preg_match( '#^vc_col-(xs|sm|md|lg|xl)-offset-(\d+(?:/5)?)$#', $token, $m ) ) {
                $insets[ $m[1] ] = $m[2];
                continue;
            }
            if ( preg_match( '#^vc_col-(xs|sm|md|lg|xl)-(\d+(?:/5)?)$#', $token, $m ) ) {
                $width = self::SPAN_TO_WIDTH[ $m[2] ] ?? null;
                if ( $width !== null ) {
                    $sizes[ $m[1] ] = $width;
                }
                continue;
            }
            // `vc_col-{size}-inherit` is the editor's "no value here" marker
            // (`vc_column_offset_class_merge()` strips it before rendering).
        }

        self::applySizes( $sizes, $result );
        self::applyInsets( $insets, $result );
        self::applyHidden( $hidden, $result );

        return $result;
    }

    /**
     * `wpb_normalize_column_offset_hidden()` (js_composer 9.0.1): before 9.0
     * there was no xl band, so `vc_hidden-lg` meant "hidden on every desktop
     * screen" and gets `vc_hidden-xl` added.
     */
    private static function normaliseLegacyHidden( string $offset ): string {
        $is_legacy = str_contains( $offset, 'vc_hidden-lg' )
            && ! str_contains( $offset, 'vc_col-xl-' )
            && ! str_contains( $offset, 'vc_hidden-xl' );

        return $is_legacy ? $offset . ' vc_hidden-xl' : $offset;
    }

    /**
     * @param array<string,string> $sizes size ⇒ WPBakery width
     * @param array{flex: array<string,string>, hidden: array<string,bool>, margin_left: array<string,string>, notes: string[]} $result
     */
    private static function applySizes( array $sizes, array &$result ): void {
        $winner = [];  // breakpoint ⇒ size that set it

        foreach ( $sizes as $size => $width ) {
            $flex_type = self::flexType( $width );
            if ( $flex_type === null ) {
                continue;
            }
            foreach ( self::SIZE_BREAKPOINTS[ $size ] as $breakpoint ) {
                $current = $winner[ $breakpoint ] ?? null;
                if ( $current === null || self::SIZE_RANK[ $size ] > self::SIZE_RANK[ $current ] ) {
                    $winner[ $breakpoint ]         = $size;
                    $result['flex'][ $breakpoint ] = $flex_type;
                }
            }
        }

        // Divi has no 992–1199 or 1200–1399 band of its own: when two desktop
        // sizes ask for different widths only the largest survives.
        $desktop_widths = [];
        foreach ( self::DESKTOP_SIZES as $size ) {
            if ( isset( $sizes[ $size ] ) ) {
                $desktop_widths[ $size ] = $sizes[ $size ];
            }
        }
        if ( count( array_unique( $desktop_widths ) ) > 1 ) {
            $kept = $winner['desktop'] ?? '';
            foreach ( $desktop_widths as $size => $width ) {
                if ( $size !== $kept ) {
                    $result['notes'][] = sprintf(
                        'column width %s (%s) dropped: Divi has one desktop breakpoint, which keeps the %s width',
                        $width,
                        $size,
                        $kept
                    );
                }
            }
        }
    }

    /**
     * @param array<string,string> $insets size ⇒ offset span
     * @param array{flex: array<string,string>, hidden: array<string,bool>, margin_left: array<string,string>, notes: string[]} $result
     */
    private static function applyInsets( array $insets, array &$result ): void {
        $winner = [];

        foreach ( $insets as $size => $span ) {
            $width = self::SPAN_TO_WIDTH[ $span ] ?? null;
            if ( $width === null ) {
                // `-offset-0` is the form's "no offset" entry.
                continue;
            }
            $percent = self::percent( $width );
            if ( $percent === null ) {
                continue;
            }
            foreach ( self::SIZE_BREAKPOINTS[ $size ] as $breakpoint ) {
                $current = $winner[ $breakpoint ] ?? null;
                if ( $current === null || self::SIZE_RANK[ $size ] > self::SIZE_RANK[ $current ] ) {
                    $winner[ $breakpoint ]                = $size;
                    $result['margin_left'][ $breakpoint ] = self::trimNumber( $percent ) . '%';
                }
            }
        }
    }

    /**
     * @param array<string,bool> $hidden
     * @param array{flex: array<string,string>, hidden: array<string,bool>, margin_left: array<string,string>, notes: string[]} $result
     */
    private static function applyHidden( array $hidden, array &$result ): void {
        if ( ! empty( $hidden['xs'] ) ) {
            $result['hidden']['phone'] = true;
        }
        if ( ! empty( $hidden['sm'] ) ) {
            $result['hidden']['tablet'] = true;
        }

        $desktop = array_values( array_filter( self::DESKTOP_SIZES, static fn( string $s ): bool => ! empty( $hidden[ $s ] ) ) );
        if ( $desktop === [] ) {
            return;
        }

        $result['hidden']['desktop'] = true;

        if ( count( $desktop ) < count( self::DESKTOP_SIZES ) ) {
            $result['notes'][] = sprintf(
                'hidden on %s only; Divi has one desktop breakpoint, so the column is hidden on every desktop width',
                implode( ' and ', $desktop )
            );
        }
    }

    /** 25.0 → "25", 8.3333 → "8.3333". */
    private static function trimNumber( float $value ): string {
        return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
    }
}
