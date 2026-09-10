<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A `dfd_icon_list_item` that reached the registry on its own.
 *
 * The item is `'as_child' => array('only' => 'dfd_icon_list')`
 * (`modules/dfd-icon-list-module.php:309`), so on a real page it is read by
 * `DfdIconListConverter` and never dispatched — but a page whose list wrapper
 * was lost in an old edit still carries the rows, and Divi's
 * `divi/icon-list-item` cannot sit in a column on its own
 * (`icon-list/module.json` `childrenName`). So the row is wrapped in a list of
 * its own rather than dropped.
 */
class DfdIconListItemConverter extends DfdIconListConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_icon_list_item_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $item = $this->item( $atts, trim( $this->rawContent( $node ) ), $id, $node );

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'this icon list row is not inside a dfd_icon_list; it was wrapped in a list of its own, because Divi\'s list item is a child module'
        );
        $this->engine->logConverted( 'icon-list' );

        return $this->block( $id . '-list', 'divi/icon-list', [], [ $item ] );
    }
}
