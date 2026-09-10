<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\GlobalSettingsResolver;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_grid_container` › `vc_grid_container_item` (WPBakery 9.0) →
 * `divi/group` on Divi's own grid layout.
 *
 * `js_composer.min.css`:
 *
 *     .vc_grid_container{--col-width:1fr;column-gap:var(--col-gap);display:grid;
 *                        grid-template-columns:repeat(var(--grid-cols),minmax(0,var(--col-width)));
 *                        grid-template-rows:auto;margin-left:-15px;margin-right:-15px;
 *                        row-gap:var(--row-gap)}
 *     .vc_grid_container_item>.vc_grid_container_item-inner{padding:0 15px}
 *
 * and `WPBakeryShortCode_Vc_Grid_Container::output_wrapper_attributes()` writes
 * `--grid-cols`, `--col-gap` and `--row-gap` inline.
 *
 * Divi 5.12.1 has real grid keys, so this is not the flex fallback amendment §3
 * allows for: `module.decoration.layout.desktop.value` takes
 * `display: grid`, `gridColumnWidths: equal` and `gridColumnCount`, which
 * `StyleLibrary/Declarations/Layout/Layout.php` turns into
 * `grid-template-columns: repeat(var(--column-count), minmax(0, 1fr))` — the
 * same rule WPBakery writes — plus `columnGap` and `rowGap`
 * (`GroupModule::module_classnames()` reads the same `display` to add
 * `et_grid_group`).
 *
 * `rows` is the one thing that does not carry: WPBakery writes `--grid-rows`
 * inline but its own stylesheet never reads it (`grid-template-rows` is
 * `auto`), so it changes nothing on either side and is reported.
 */
class GridContainerConverter extends FlexboxContainerConverter {

    const ITEM_TAG = 'vc_grid_container_item';

    /** `config/containers/shortcode-vc-grid-container.php`: `'value' => '2'`. */
    const DEFAULT_COLUMNS = '2';

    /** @return string[] */
    protected function consumed(): array {
        return [ 'columns', 'rows', 'row_gap', 'col_gap' ];
    }

    /** @return array<string,string> */
    protected function layout( array $atts, string $id ): array {
        $columns = (int) $this->att( $atts, 'columns', self::DEFAULT_COLUMNS );

        $layout = [
            'display'          => 'grid',
            'gridColumnWidths' => 'equal',
            'gridColumnCount'  => (string) max( 1, $columns ),
        ];

        // Divi tests a layout gap for truthiness, so an unset gap is left out
        // rather than written as "0".
        $column_gap = PackedParams::sizeWithUnit( $this->att( $atts, 'col_gap' ) );
        if ( $column_gap !== '' ) {
            $layout['columnGap'] = $column_gap;
        }

        $row_gap = PackedParams::sizeWithUnit( $this->att( $atts, 'row_gap' ) );
        if ( $row_gap !== '' ) {
            $layout['rowGap'] = $row_gap;
        }

        $rows = trim( $this->att( $atts, 'rows' ) );
        if ( $rows !== '' && $rows !== '1' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'vc_grid_container rows="%s" writes --grid-rows on the wrapper, but WPBakery\'s own stylesheet never reads it (grid-template-rows is auto), so it changes nothing', $rows )
            );
        }

        return $layout;
    }

    /** `.vc_grid_container_item>.vc_grid_container_item-inner{padding:0 15px}`. */
    protected function itemSettings( array $attrs ): array {
        $padding_x = GlobalSettingsResolver::columnPaddingX();
        $padding   = $this->read( $attrs, 'module.decoration.spacing.desktop.value.padding' );
        $padding   = is_array( $padding ) ? $padding : [];

        foreach ( [ 'top' => '0px', 'right' => $padding_x, 'bottom' => '0px', 'left' => $padding_x ] as $side => $value ) {
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
}
