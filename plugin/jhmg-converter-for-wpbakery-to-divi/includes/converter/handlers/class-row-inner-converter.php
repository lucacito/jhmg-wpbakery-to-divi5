<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_row_inner` → a `divi/row` inside a column.
 *
 * Divi 5 allows column → row → column, so a nested WPBakery row keeps its
 * shape. It differs from a top-level row in two ways only: it has no section
 * to hand its design options to, so it paints them itself; and its bleed is
 * measured against its column rather than the page container —
 * `calc(100% + 30px)` with no max width (`ROW_RESET` plus that bleed,
 * amendment §1, measured in `docs/box-model.md`).
 */
class RowInnerConverter extends RowConverter {

    protected function baseGeometry(): array {
        return self::nestedRowSettings();
    }

    protected function countedAs(): string {
        return 'row_inner';
    }
}
