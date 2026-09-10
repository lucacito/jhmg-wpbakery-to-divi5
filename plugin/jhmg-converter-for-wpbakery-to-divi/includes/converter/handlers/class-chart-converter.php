<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_round_chart` and `vc_line_chart` → `divi/charts` (amendment §7).
 *
 * Both builders draw with Chart.js — WPBakery registers
 * `lib/vendor/dist/chart.js/dist/chart.min.js` and hands its data through
 * `data-vc-values`; Divi 5.12.1's charts module builds a Chart.js config from
 * stored table data. So this is a real conversion of the series, not a
 * placeholder, and it is registered approximate only because the stroke,
 * legend and animation styling is not 1:1.
 *
 * **The stored shape**, read from `ChartsModule.php` before a key was written:
 *
 * - `_build_chart_config()` (282-317) reads `chart.innerContent` (mode-resolved
 *   by `_resolve_mode_value()`, 1364-1376) `['data']['columns']` and
 *   `['data']['rows']`, and `chart.advanced.config`'s `type`.
 * - `_normalize_chart_columns()` (561-588): a column is
 *   `{label: string, visible: bool, role: string}` and its index is its
 *   position; `_get_column_roles()` (628-641) keeps only visible columns whose
 *   `role` is one of `category|value|series|x|y|size`
 *   (`_is_chart_column_role()`, 616-618).
 * - `_get_chart_columns_by_role()` (684-745) with
 *   `_get_chart_column_role_family()` (762-786): `pie`/`doughnut`/`polarArea`
 *   need one `category` and one `value` column; `line`/`area`/`bar`/`radar`
 *   need one `category` and one or more `series` columns.
 * - `_get_row_cells()` (1387-1393): a row is `{cells: [...], color: string}`,
 *   the cells positional against the columns; `_get_row_color()` (1471-1479)
 *   and `_get_column_color()` (1426-1433) are where the colours live, resolved
 *   by `_resolve_persisted_data_color()` (1490-1497).
 * - `_build_standard_config()` (456-548) is what turns all of that into
 *   Chart.js datasets.
 *
 * So a round chart is the `categoryValue` family — one category column of
 * titles, one value column, and the colour on each *row*; a line chart is
 * `categorySeries` — one category column from `x_values` and one series column
 * per `values` entry, with the colour on each *column*.
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class ChartConverter extends BaseWPBakeryConverter {

    /** `config/content/shortcode-vc-round-chart.php`: pie | doughnut. */
    const ROUND_TYPES = [ 'pie', 'doughnut' ];

    /** `config/content/shortcode-vc-line-chart.php`: line | bar, `'std' => 'bar'`. */
    const LINE_TYPES = [ 'line', 'bar' ];

    /**
     * Every attribute the two elements carry between them, claimed before the
     * data is read: a chart with no series of its own still has to account for
     * them. `stroke_color` is the 7.8 dropdown 9.0 replaced with
     * `custom_stroke_color`, which `REPORTED_LOOK` already names.
     */
    const CONSUMED = [
        'values', 'x_values', 'type',
        'legend', 'tooltips', 'legend_position', 'custom_legend_color',
        'style', 'animation', 'stroke_width', 'custom_stroke_color', 'stroke_color',
    ];

    /**
     * The look attributes Chart.js draws from WPBakery's own script and Divi's
     * module has no persisted key for.
     */
    const REPORTED_LOOK = [
        'style'               => 'the flat/modern two-tone fill',
        'animation'           => 'the Chart.js easing function',
        'stroke_width'        => 'the gap drawn between the segments',
        'custom_stroke_color' => 'the colour of that gap',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_chart_' ) );
        $tag  = (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // divi/charts never keeps a bottom margin: Divi's flex reset zeroes
        // it, so the default trailing space goes to padding.
        $style    = $this->mapStyle( 'generic', $node, 'content', 'divi/charts' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], self::CONSUMED, [ 'title' ] );

        $data = $tag === 'vc_line_chart'
            ? $this->lineData( $atts, $id )
            : $this->roundData( $atts, $id );

        $this->legend( $atts, $id, $attrs );
        $this->look( $atts, $id );

        if ( $data['rows'] === [] ) {
            // `vc_map_get_attributes()` fills a missing `values` from the
            // element's own default, so a chart without one still draws two
            // slices on the live page — but the converter never fabricates
            // data, so the element is reported and left out.
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                sprintf( '%s carries no series of its own, so WPBakery draws its two-slice demo data; nothing was written for it', $tag )
            );
            $this->engine->logWarning( "{$tag} {$id} holds no values; nothing was written for it." );
            $this->logUnmappedSettings( $id, $atts, $consumed, $tag );

            return [];
        }

        StyleMapper::write( $attrs, 'chart.innerContent.desktop.value.data', $data );
        StyleMapper::write( $attrs, 'chart.advanced.config.desktop.value.type', $this->type( $tag, $atts ) );

        $this->engine->logConverted( 'charts' );
        $this->logUnmappedSettings( $id, $atts, $consumed, $tag );

        $blocks = [ $this->block( $id, 'divi/charts', $attrs ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /** `data-vc-type`, narrowed to the values each element's dropdown offers. */
    private function type( string $tag, array $atts ): string {
        $type = strtolower( trim( $this->att( $atts, 'type' ) ) );

        if ( $tag === 'vc_line_chart' ) {
            return in_array( $type, self::LINE_TYPES, true ) ? $type : 'bar';
        }

        return in_array( $type, self::ROUND_TYPES, true ) ? $type : 'pie';
    }

    // -------------------------------------------------------------------------
    // The data
    // -------------------------------------------------------------------------

    /**
     * A round chart is one category column and one value column, with each
     * slice's colour on its own row (`_get_row_color()`).
     *
     * @return array{columns: array<int,array<string,mixed>>, rows: array<int,array<string,mixed>>}
     */
    private function roundData( array $atts, string $id ): array {
        $rows = [];

        foreach ( PackedParams::paramGroup( $this->att( $atts, 'values' ) ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $row = [ 'cells' => [ (string) ( $entry['title'] ?? '' ), (string) ( $entry['value'] ?? '0' ) ] ];

            $color = Color::normalize( $entry['custom_color'] ?? '' );
            if ( $color !== null ) {
                $row['color'] = $color;
            } elseif ( trim( (string) ( $entry['custom_color'] ?? '' ) ) !== '' ) {
                $this->engine->logUnresolvedGlobal( $id, 'values custom_color', trim( (string) $entry['custom_color'] ) );
            }

            $rows[] = $row;
        }

        return [
            'columns' => [
                [ 'label' => 'Label', 'visible' => true, 'role' => 'category' ],
                [ 'label' => 'Value', 'visible' => true, 'role' => 'value' ],
            ],
            'rows'    => $rows,
        ];
    }

    /**
     * A line chart is one category column built from `x_values` and one series
     * column per `values` entry, with the series colour on the *column*
     * (`_get_column_color()`).
     *
     * `WPBakeryShortCode_Vc_Line_Chart`'s template splits both `x_values` and
     * each entry's `y_values` on `;` (`explode( ';', trim( $x_values, ';' ) )`).
     *
     * @return array{columns: array<int,array<string,mixed>>, rows: array<int,array<string,mixed>>}
     */
    private function lineData( array $atts, string $id ): array {
        $labels = $this->split( $this->att( $atts, 'x_values' ) );
        $series = [];

        foreach ( PackedParams::paramGroup( $this->att( $atts, 'values' ) ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $column = [ 'label' => (string) ( $entry['title'] ?? '' ), 'visible' => true, 'role' => 'series' ];

            $color = Color::normalize( $entry['custom_color'] ?? '' );
            if ( $color !== null ) {
                $column['color'] = $color;
            } elseif ( trim( (string) ( $entry['custom_color'] ?? '' ) ) !== '' ) {
                $this->engine->logUnresolvedGlobal( $id, 'values custom_color', trim( (string) $entry['custom_color'] ) );
            }

            $series[] = [ 'column' => $column, 'values' => $this->split( (string) ( $entry['y_values'] ?? '' ) ) ];
        }

        if ( $series === [] || $labels === [] ) {
            return [ 'columns' => [], 'rows' => [] ];
        }

        $rows = [];
        foreach ( $labels as $index => $label ) {
            $cells = [ $label ];
            foreach ( $series as $line ) {
                $cells[] = $line['values'][ $index ] ?? '';
            }
            $rows[] = [ 'cells' => $cells ];
        }

        $columns = [ [ 'label' => 'Label', 'visible' => true, 'role' => 'category' ] ];
        foreach ( $series as $line ) {
            $columns[] = $line['column'];
        }

        return [ 'columns' => $columns, 'rows' => $rows ];
    }

    /** @return string[] */
    private function split( string $raw ): array {
        return array_values( array_filter(
            array_map( 'trim', explode( ';', trim( $raw, '; ' ) ) ),
            static fn( string $value ): bool => $value !== ''
        ) );
    }

    // -------------------------------------------------------------------------
    // Legend, tooltips and the rest of the look
    // -------------------------------------------------------------------------

    /**
     * `legend`, `tooltips`, `legend_position` and `custom_legend_color`, at the
     * keys `_build_chart_options()` reads: `showLegend` / `showTooltip` (796-801,
     * both `'off' !== …`), the legend position at
     * `chart.advanced.legend.layout…position` (875) and the label colour at
     * `chart.advanced.legend.markers…color` (835 → 886).
     *
     */
    private function legend( array $atts, string $id, array &$attrs ): void {
        StyleMapper::write( $attrs, 'chart.advanced.config.desktop.value.showLegend', $this->on( $atts, 'legend' ) ? 'on' : 'off' );
        StyleMapper::write( $attrs, 'chart.advanced.config.desktop.value.showTooltip', $this->on( $atts, 'tooltips' ) ? 'on' : 'off' );

        $position = strtolower( trim( $this->att( $atts, 'legend_position' ) ) );
        if ( in_array( $position, [ 'top', 'left', 'bottom', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'chart.advanced.legend.layout.desktop.value.position', $position );
        }

        $color = $this->color( $atts, 'custom_legend_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'chart.advanced.legend.markers.desktop.value.color', $color );
        }
    }

    private function look( array $atts, string $id ): void {
        $described = [];
        foreach ( self::REPORTED_LOOK as $key => $what ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' && $value !== '0' ) {
                $described[] = $key . '="' . $value . '" (' . $what . ')';
            }
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'WPBakery draws this chart through its own Chart.js options: ' . implode( ', ', $described ) . '; Divi\'s charts module has its own styling instead'
        );
    }
}
