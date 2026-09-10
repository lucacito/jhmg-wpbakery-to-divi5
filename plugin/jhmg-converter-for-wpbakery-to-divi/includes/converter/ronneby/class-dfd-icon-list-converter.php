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
 * param group instead of in children — 39 lists, 203 rows, in the corpus — with
 * `{icon_type, select_icon, ic_<library>, text_content, link_box, link}` per
 * row. Those are read the same way, and a key such a row carries that the
 * element's form no longer maps is reported: `logUnmappedSettings()` never sees
 * it, because it lives inside the one attribute the parent claims.
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
            $row = array_map( static fn( $value ): string => is_scalar( $value ) ? (string) $value : '', $row );

            $items[] = $this->item( $row, $this->legacyBody( $row ), $id . '-item-' . $index, null );
        }

        return $items;
    }

    /**
     * A legacy row's body.
     *
     * The 39 `list_fields` lists in the corpus all store it as `text_content`
     * (203 rows, e.g. `63_fifty_third.xml`:
     * `{"icon_type":"selector","select_icon":"dfd_icons","ic_dfd_icons":"dfd-socicon-arrow-right","text_content":"Leverage agile frameworks…"}`).
     * `content` is the name the same element's Elementor port gives the field
     * (`ronneby-core/elementor/widgets/el-icon-list.php:120-127, 492`), which is
     * what a build between the two writes, so both are read.
     *
     * @param array<string,string> $row
     */
    private function legacyBody( array $row ): string {
        foreach ( [ 'text_content', 'content' ] as $key ) {
            $value = trim( $row[ $key ] ?? '' );
            if ( $value !== '' ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * One row, from a `dfd_icon_list_item` child or from a legacy
     * `list_fields` entry.
     *
     * `icon_type` is `selector` (a glyph), `custom` (an uploaded picture) or
     * `none`; `link_box` makes the whole row a link
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
        $consumed = [ 'icon_type', 'icon', 'icon_img', 'link_box', 'link', 'text_content', 'content' ];

        $type    = strtolower( trim( $this->att( $atts, 'icon_type' ) ) );
        $picture = trim( $this->att( $atts, 'icon_img' ) );
        $class   = $this->rowIcon( $atts, $consumed );

        if ( $type === 'custom' || $picture !== '' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $item_id,
                sprintf(
                    'this row\'s icon is the uploaded picture %s; Divi\'s icon list draws a glyph, so the row was written without one',
                    $picture !== '' ? $picture : '(none named)'
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

        // A child row is a shortcode and reports through the usual path; a
        // legacy row is a `param_group` entry, which `logUnmappedSettings()`
        // never sees — its keys are inside one attribute the parent already
        // claimed — so anything left over is reported here instead of being
        // dropped where no gate can see it.
        if ( $child !== null ) {
            $this->logUnmappedSettings( $item_id, $atts, $consumed, 'dfd_icon_list_item' );

            return $this->block( $item_id, 'divi/icon-list-item', $attrs );
        }

        $left_over = [];
        foreach ( $atts as $key => $value ) {
            if ( in_array( $key, $consumed, true ) || trim( (string) $value ) === '' ) {
                continue;
            }
            $left_over[] = $key . '="' . $value . '"';
        }

        if ( $left_over !== [] ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $item_id,
                'this list row carries ' . implode( ', ', $left_over ) . ', which the element\'s own form no longer maps'
            );
        }

        return $this->block( $item_id, 'divi/icon-list-item', $attrs );
    }

    /**
     * The glyph class a row names.
     *
     * A `dfd_icon_list_item` child stores it in one `icon` field written by the
     * theme's icon manager (`dfd-icon-list-module.php:326-332`). A legacy
     * `list_fields` row stores WPBakery's picker instead: `select_icon` is the
     * library and `ic_<library>` the class — the shape
     * `dfd_button_gradient.php:564` also uses, and the one all 203 legacy rows
     * in the corpus carry (`select_icon: "dfd_icons"`,
     * `ic_dfd_icons: "dfd-socicon-arrow-right"`).
     *
     * @param string[] $consumed
     */
    private function rowIcon( array $atts, array &$consumed ): string {
        $consumed[] = 'select_icon';

        $library = trim( $this->att( $atts, 'select_icon' ) );

        foreach ( [ 'dfd_icons', 'fontawesome', 'openiconic', 'typicons', 'entypo', 'linecons' ] as $known ) {
            $consumed[] = 'ic_' . $known;
        }

        $class = trim( $this->att( $atts, 'icon' ) );
        if ( $class !== '' ) {
            return $class;
        }

        return $library === '' ? '' : trim( $this->att( $atts, 'ic_' . $library ) );
    }

    /**
     * The icon value for a row that has none. Divi's list item requires the
     * key and renders nothing for an empty glyph; the `type` still says `fa` so
     * the value keeps the shape Divi's own asset detector expects.
     */
    const EMPTY_ICON = [ 'unicode' => '', 'type' => 'fa', 'weight' => '400' ];
}
