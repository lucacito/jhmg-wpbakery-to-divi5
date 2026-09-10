<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Autop;
use WPBakeryDivi5Converter\Helpers\UltimateFields;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `ultimate_pricing` (Ultimate Addons) → `divi/pricing-tables` › one
 * `divi/pricing-table`, registered approximate.
 *
 * `modules/ultimate_pricing_tables.php` is a heading, a sub-heading, a price
 * with its unit, a features list (the element's content, one feature per line)
 * and a button — the same five things Divi's pricing table holds
 * (`pricing-table/module.json`: `title`, `subtitle`, `price`,
 * `currencyFrequency.innerContent…per`, `content` and `button.innerContent`).
 *
 * `design_style` and `color_scheme` choose one of the add-on's own ten designs;
 * Divi's pricing table draws its own, so they are reported.
 */
class UltPricingConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_ult_pricing_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // No margin of the add-on's own: `.ult_pricing_table` sits in the
        // column with the theme's spacing, so the `content` default applies.
        $style    = $this->mapStyle( 'generic', $this->withCss( $node, 'css_price_box' ) );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'css_price_box', 'package_heading', 'heading_tag', 'package_sub_heading', 'sub_heading_tag',
            'package_price', 'package_unit', 'package_btn_text', 'package_link', 'package_featured',
            'design_style', 'color_scheme', 'color_bg_main', 'color_txt_main',
            'color_bg_highlight', 'color_txt_highlight', 'min_ht',
        ] );

        $table = [];

        StyleMapper::write( $table, 'title.innerContent.desktop.value', $this->att( $atts, 'package_heading' ) );
        StyleMapper::write( $table, 'price.innerContent.desktop.value', $this->att( $atts, 'package_price' ) );

        // A field the author left blank is left out rather than written empty.
        foreach ( [
            'package_sub_heading' => 'subtitle.innerContent.desktop.value',
            'package_unit'        => 'currencyFrequency.innerContent.desktop.value.per',
        ] as $key => $path ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                StyleMapper::write( $table, $path, $value );
            }
        }
        StyleMapper::write( $table, 'content.innerContent.desktop.value', $this->features( $node, $id ) );

        $this->featured( $atts, $table );
        $this->button( $atts, $table );
        $this->fonts( $atts, $id, $table, $consumed );
        $this->design( $atts, $id );

        $this->engine->logConverted( 'pricing-tables' );
        $this->engine->logConverted( 'pricing-table' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/pricing-tables', $attrs, [
            $this->block( $id . '-table', 'divi/pricing-table', $table ),
        ] );
    }

    /**
     * The add-on's features list is the element's content, one feature per
     * line; Divi's pricing table takes a `<ul>` of them.
     */
    private function features( array $node, string $id ): string {
        $content = trim( $this->rawContent( $node ) );

        if ( $content === '' ) {
            return '';
        }

        if ( str_contains( $content, '<li' ) || str_contains( $content, '<ul' ) ) {
            return trim( $this->nestedShortcodes( Autop::apply( $content ), $id ) );
        }

        $items = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $content ) ?: [] ) ) );

        if ( $items === [] ) {
            return '';
        }

        return '<ul>' . implode( '', array_map( static fn( string $item ): string => '<li>' . $item . '</li>', $items ) ) . '</ul>';
    }

    /** `package_featured` marks the highlighted column, which Divi has too. */
    private function featured( array $atts, array &$table ): void {
        if ( $this->on( $atts, 'package_featured' ) ) {
            StyleMapper::write( $table, 'module.advanced.featured.desktop.value', 'on' );
        }
    }

    private function button( array $atts, array &$table ): void {
        $text = trim( $this->att( $atts, 'package_btn_text' ) );
        $link = $this->link( $atts, 'package_link' );

        if ( $text === '' && $link === [] ) {
            return;
        }

        StyleMapper::write( $table, 'button.innerContent.desktop.value.text', $text );

        if ( $link !== [] ) {
            StyleMapper::write( $table, 'button.innerContent.desktop.value.linkUrl', $link['url'] );
            StyleMapper::write( $table, 'button.innerContent.desktop.value.linkTarget', ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off' );
        }
    }

    /**
     * The add-on's five typography groups, one per line of the table.
     *
     * @param string[] $consumed
     */
    private function fonts( array $atts, string $id, array &$table, array &$consumed ): void {
        $map = [
            'package_name' => 'title.decoration.font.font.desktop.value',
            'subheading'   => 'subtitle.decoration.font.font.desktop.value',
            'price'        => 'price.decoration.font.font.desktop.value',
            'price_unit'   => 'currencyFrequency.decoration.font.font.desktop.value',
            'features'     => 'content.decoration.bodyFont.body.font.desktop.value',
            'button'       => 'button.decoration.font.font.desktop.value',
        ];

        foreach ( $map as $prefix => $path ) {
            foreach ( array_keys( $atts ) as $key ) {
                if ( is_string( $key ) && str_starts_with( $key, $prefix . '_' ) ) {
                    $consumed[] = $key;
                }
            }
            $consumed[] = $prefix . '_typograpy';

            $size = UltimateFields::responsive( $this->att( $atts, $prefix . '_font_size' ) );
            if ( $size !== '' ) {
                StyleMapper::write( $table, $path . '.size', $size );
            }

            $line = UltimateFields::responsive( $this->att( $atts, $prefix . '_line_height' ) );
            if ( $line !== '' ) {
                StyleMapper::write( $table, $path . '.lineHeight', $line );
            }

            $color = $this->color( $atts, $prefix . '_font_color', $id );
            if ( $color !== null ) {
                StyleMapper::write( $table, $path . '.color', $color );
            }
        }
    }

    private function design( array $atts, string $id ): void {
        $described = [];

        foreach ( [
            'design_style' => 'one of the add-on\'s ten table designs',
            'color_scheme' => 'its colour scheme',
            'min_ht'       => 'a minimum table height',
        ] as $key => $what ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                $described[] = $key . '="' . $value . '" (' . $what . ')';
            }
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'addon',
            $id,
            'ultimate_pricing ' . implode( ', ', $described ) . '; Divi\'s pricing table draws its own, so set its colours and spacing there'
        );
    }
}
