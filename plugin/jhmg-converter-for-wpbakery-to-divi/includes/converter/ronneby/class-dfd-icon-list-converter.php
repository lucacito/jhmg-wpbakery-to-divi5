<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_icon_list` › `dfd_icon_list_item` → `divi/icon-list` › `divi/icon-list-item`.
 *
 * `modules/dfd-icon-list-module.php:386-453` prints a `<ul>` and each child
 * prints one `<li>` holding an icon block and a body of copy (lines 455-645).
 * Divi's icon list is the same pair: `icon.innerContent` and
 * `content.innerContent` on the item, with `icon.advanced.color` /
 * `icon.advanced.size` and `listItem.decoration.font.font` on the list
 * (`icon-list/module.json`, `icon-list-item/module.json`,
 * `IconListModule.php:246-272`, `IconListItemModule.php:78-345`).
 *
 * An older build of the theme stored the whole list in one `list_fields`
 * param group instead of in children (39 lists in the corpus do), with
 * `block_title` and `block_content` per row; those are read too.
 *
 * Three things Divi's icon list has no part for: the disc drawn behind each
 * icon (`icon_dec_size`, `icon_background`, `icon_border_*`), the rule under
 * each row (`del_*`), and an item whose icon is an uploaded picture — Divi's
 * list item holds a glyph.
 *
 * **No default bottom margin**: `.dfd-icon-list-wrap` carries none
 * (`visual-composer.css` sets `.dfd-icon-list-wrap .dfd-icon-list{margin-bottom:0}`).
 */
class DfdIconListConverter extends RonnebyConverter {

    /** The disc behind the icon and the rule under each row, both the theme's own CSS. */
    const REPORTED_LOOK = [
        'main_style'        => 'which of the four list layouts is drawn',
        'icon_margin'       => 'the gap between the icon and the copy',
        'icon_bottom_spase' => 'the space between rows',
        'icon_dec_size'     => 'the size of the disc behind each icon',
        'icon_border_radius' => 'the corner radius of that disc',
        'icon_background'   => 'its fill',
        'icon_border_style' => 'its border style',
        'icon_border_width' => 'its border width',
        'icon_border_color' => 'its border colour',
        'del_height'        => 'the thickness of the rule under each row',
        'del_style'         => 'the style of that rule',
        'del_color'         => 'its colour',
        'color_link'        => 'the colour a linked row turns under the cursor',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_icon_list_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'tutorials', 'list_fields' ] );

        $this->listStyle( $atts, $id, $attrs, $consumed );

        $items = $this->items( $node, $atts, $id );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'dfd_icon_list draws its rows with the theme\'s own classes', $consumed );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        if ( $items === [] ) {
            $this->engine->logWarning( "dfd_icon_list {$id} holds no rows; nothing was written for it." );

            return [];
        }

        $this->engine->logConverted( 'icon-list' );

        return $this->block( $id, 'divi/icon-list', $attrs, $items );
    }

    /**
     * The list's own icon colour and size, and the typography every row
     * inherits (`dfd-icon-list-module.php:396-431`).
     *
     * @param string[] $consumed
     */
    private function listStyle( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'icon_size';
        $consumed[] = 'icon_color';

        $size = $this->pixels( $atts, 'icon_size' );
        if ( $size !== '' ) {
            StyleMapper::write( $attrs, 'icon.advanced.size.desktop.value', $size );
        }

        $color = $this->color( $atts, 'icon_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'icon.advanced.color.desktop.value', $color );
        }

        $this->applyFontSet(
            $atts,
            [ 'options' => 'font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'listItem.decoration.font.font',
            $id,
            $attrs,
            $consumed
        );
    }

    /**
     * The rows: the `dfd_icon_list_item` children, or the `list_fields` param
     * group an older build wrote them into.
     *
     * @return array<int, array<string,mixed>>
     */
    private function items( array $node, array $atts, string $id ): array {
        $items = [];
        $index = 0;

        foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
            if ( ! is_array( $child ) || ( $child['tag'] ?? '' ) !== 'dfd_icon_list_item' ) {
                continue;
            }

            $index++;
            $items[] = $this->item(
                is_array( $child['atts'] ?? null ) ? $child['atts'] : [],
                trim( $this->rawContent( $child ) ),
                (string) ( $child['id'] ?? $id . '-item-' . $index ),
                $child
            );
        }

        if ( $items !== [] ) {
            return $items;
        }

        foreach ( RonnebyParams::items( $this->att( $atts, 'list_fields' ) ) as $row ) {
            $index++;
            $title   = trim( (string) ( $row['block_title'] ?? '' ) );
            $content = trim( (string) ( $row['block_content'] ?? '' ) );
            $body    = $title !== '' && $content !== '' ? '<strong>' . $title . '</strong> ' . $content : $title . $content;

            $items[] = $this->item(
                array_map( static fn( $value ): string => is_scalar( $value ) ? (string) $value : '', $row ),
                $body,
                $id . '-item-' . $index,
                null
            );
        }

        return $items;
    }

    /**
     * One row. `icon_type` is `selector` (a glyph), `custom` (an uploaded
     * picture) or `none`; `link_box` makes the whole row a link
     * (`dfd-icon-list-module.php:466-481`).
     *
     * `icon.innerContent` is a required object on `divi/icon-list-item`, so a
     * row with no glyph is written with an empty one — which renders nothing,
     * the same as Ronneby's own `<i class="none">` — rather than with a star
     * the source never had.
     *
     * @param array<string,string> $atts
     * @param array<string,mixed>|null $child The node, when the row is a real child.
     */
    protected function item( array $atts, string $body, string $item_id, ?array $child ): array {
        $attrs    = [];
        $consumed = [ 'icon_type', 'icon', 'icon_img', 'link_box', 'link', 'select_icon', 'block_title', 'block_content' ];

        $type  = strtolower( trim( $this->att( $atts, 'icon_type' ) ) );
        $class = trim( $this->att( $atts, 'icon' ) );

        if ( $type === 'custom' || trim( $this->att( $atts, 'icon_img' ) ) !== '' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $item_id,
                sprintf(
                    'this row\'s icon is the uploaded picture %s; Divi\'s icon list draws a glyph, so the row was written without one',
                    $this->att( $atts, 'icon_img' )
                )
            );
            StyleMapper::write( $attrs, 'icon.innerContent.desktop.value', self::EMPTY_ICON );
        } elseif ( $type !== 'none' && $class !== '' ) {
            StyleMapper::write( $attrs, 'icon.innerContent.desktop.value', $this->iconValue( $class, $item_id, 'the list row icon' ) );
        } else {
            StyleMapper::write( $attrs, 'icon.innerContent.desktop.value', self::EMPTY_ICON );
        }

        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            trim( $this->nestedShortcodes( $this->editorHtml( $body ), $item_id ) )
        );

        // `if(isset($link_box) && $link_box == 'link_b')` — the row becomes one
        // anchor over the whole `<li>`.
        if ( trim( $this->att( $atts, 'link_box' ) ) === 'link_b' ) {
            $link = $this->link( $atts, 'link' );
            if ( $link !== [] ) {
                $this->reportLinkExtras( $atts, 'link', $item_id, true );
                StyleMapper::write( $attrs, 'module.advanced.link.desktop.value', [
                    'url'    => $link['url'],
                    'target' => ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off',
                ] );
            }
        }

        $this->engine->logConverted( 'icon-list-item' );

        if ( $child !== null ) {
            $this->logUnmappedSettings( $item_id, $atts, $consumed, 'dfd_icon_list_item' );
        }

        return $this->block( $item_id, 'divi/icon-list-item', $attrs );
    }

    /**
     * The icon value for a row that has none. Divi's list item requires the
     * key and renders nothing for an empty glyph; the `type` still says `fa` so
     * the value keeps the shape Divi's own asset detector expects.
     */
    const EMPTY_ICON = [ 'unicode' => '', 'type' => 'fa', 'weight' => '400' ];
}
