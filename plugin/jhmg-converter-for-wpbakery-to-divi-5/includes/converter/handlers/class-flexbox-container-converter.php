<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_flexbox_container` › `vc_flexbox_container_item` (WPBakery 9.0) →
 * `divi/group` › one `divi/group` per item.
 *
 * `js_composer.min.css`:
 *
 *     .vc_flexbox_container{--gap:0px;display:flex;flex-wrap:wrap;gap:var(--gap);
 *                           margin-left:-15px;margin-right:-15px}
 *     .vc_flexbox_container_item{flex:1 0 auto;max-width:100%}
 *
 * and `WPBakeryShortCode_Vc_Flexbox_Container::output_wrapper_attributes()`
 * writes `--gap: <gap>` inline. Divi's group module is a flex (or grid)
 * container of its own — `module.decoration.layout.desktop.value` carries
 * `display`, `flexWrap`, `columnGap` and `rowGap`
 * (`StyleLibrary/Declarations/Layout/Layout.php`, and `GroupModule::module_classnames()`
 * reads the same `display` to add `et_flex_group`) — so the container and its
 * items convert as they stand, bleed included (amendment §3).
 *
 * An item sets no width at all, which is Divi's own `flex: 1 0 auto` default
 * for a group child: WPBakery's rule is the same, so nothing is written for it.
 *
 * The element has no `element_default_class`, so it takes no default bottom
 * margin of its own; its children keep theirs.
 */
class FlexboxContainerConverter extends BaseWPBakeryConverter {

    /** `config/containers/shortcode-vc-flexbox-container.php`: `'value' => '0px'`. */
    const DEFAULT_GAP = '0px';

    /** `.vc_flexbox_container{margin-left:-15px;margin-right:-15px}`. */
    const BLEED = '-15px';

    /** The tag each item of this container is written with. */
    const ITEM_TAG = 'vc_flexbox_container_item';

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_flexbox_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // A container is structure, so no default module margin (the
        // `group` kind the StyleMapper knows skips it too).
        $style    = $this->mapStyle( 'group', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], $this->consumed() );

        StyleMapper::write( $attrs, 'module.decoration.layout.desktop.value', $this->layout( $atts, $id ) );
        $this->bleed( $attrs );

        $items = $this->items( $node, $id );

        $this->engine->logConverted( 'group' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/group', $attrs, $items );
    }

    /** @return string[] */
    protected function consumed(): array {
        return [ 'gap' ];
    }

    /**
     * `display:flex;flex-wrap:wrap;gap:var(--gap)`, with the gap written as a
     * unit string — Divi tests a layout gap for truthiness, so a zero is
     * `"0px"` and never `"0"`.
     *
     * @return array<string,string>
     */
    protected function layout( array $atts, string $id ): array {
        $gap = PackedParams::sizeWithUnit( $this->att( $atts, 'gap', self::DEFAULT_GAP ) );
        $gap = $gap === '' ? self::DEFAULT_GAP : $gap;

        return [
            'display'   => 'flex',
            'flexWrap'  => 'wrap',
            'columnGap' => $gap,
            'rowGap'    => $gap,
        ];
    }

    /** The container's own `-15px` bleed, unless its design options set a margin. */
    private function bleed( array &$attrs ): void {
        $margin = $this->read( $attrs, 'module.decoration.spacing.desktop.value.margin' );
        $margin = is_array( $margin ) ? $margin : [];

        foreach ( [ 'right' => self::BLEED, 'left' => self::BLEED ] as $side => $value ) {
            if ( ! isset( $margin[ $side ] ) || $margin[ $side ] === '' ) {
                $margin[ $side ] = $value;
            }
        }

        StyleMapper::write(
            $attrs,
            'module.decoration.spacing.desktop.value.margin',
            array_merge( [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ], $margin, [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ] )
        );
    }

    /**
     * One `divi/group` per item, holding that item's converted children.
     *
     * @return array<int, array<string,mixed>>
     */
    private function items( array $node, string $id ): array {
        $items = [];

        foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }

            if ( (string) ( $child['tag'] ?? '' ) !== static::ITEM_TAG ) {
                // Anything the editor put straight into the container rather
                // than into an item still belongs on the page.
                foreach ( $this->engine->convertChildren( [ $child ] ) as $block ) {
                    $items[] = $block;
                }

                continue;
            }

            $items[] = $this->item( $child, $id );
        }

        return $items;
    }

    private function item( array $child, string $parent_id ): array {
        $item_id = (string) ( $child['id'] ?? uniqid( $parent_id . '-item-' ) );
        $atts    = is_array( $child['atts'] ?? null ) ? $child['atts'] : [];

        $style = $this->mapStyle( 'group', $child );
        $attrs = $this->itemSettings( $style['divi_attrs'] );

        $this->engine->logConverted( 'group' );
        $this->logUnmappedSettings( $item_id, $atts, $style['handled_keys'], (string) ( $child['tag'] ?? '' ) );

        return $this->block( $item_id, 'divi/group', $attrs, $this->engine->convertChildren( $child['children'] ?? [] ) );
    }

    /**
     * An item's own box. The flex item has none of its own
     * (`.vc_flexbox_container_item{flex:1 0 auto;max-width:100%}`), so only the
     * max width is written; the grid item overrides this with its padding.
     */
    protected function itemSettings( array $attrs ): array {
        StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.maxWidth', '100%' );

        return $attrs;
    }
}
