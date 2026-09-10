<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_pricing_table` → `divi/pricing-tables` › one `divi/pricing-table`.
 *
 * WPBakery's element is a single table (`include/templates/shortcodes/vc_pricing_table.php`:
 * a heading, a sub-heading, `<sup>currency</sup><strong>price</strong><sub>period</sub>`,
 * a button and the content list). Divi's is a *set* of tables with one child
 * per column, so each `vc_pricing_table` becomes a one-table set — the shape
 * that survives being dropped next to another one.
 *
 * `pricing-table/module.json`: `title`, `subtitle`, `price`,
 * `currencyFrequency.innerContent…{currency,per}` (`PricingTablesItemModule`
 * renders them as `.et_pb_dollar_sign` and `.et_pb_frequency`), `content` and
 * the button under `button.innerContent`.
 *
 * `.vc_do_pricing_table` carries the element's default design options —
 * `padding: 30px 20px`, a 5 px radius, a 1 px `rgba(0,0,0,0.01)` border and a
 * `#ECECEC` background (`config/buttons/shortcode-vc-pricing-table.php`
 * `$default_values`) — and, unlike `.vc_do_cta3`, **no** `margin-bottom`, so
 * the element takes no default bottom margin at all.
 */
class PricingTableConverter extends BaseWPBakeryConverter {

    /** `$default_values` in `config/buttons/shortcode-vc-pricing-table.php`. */
    const DEFAULT_PADDING = [ 'top' => '30px', 'right' => '20px', 'bottom' => '30px', 'left' => '20px' ];
    const DEFAULT_RADIUS  = '5px';
    const DEFAULT_BORDER  = [ 'width' => '1px', 'style' => 'solid', 'color' => 'rgba(0,0,0,0.01)' ];
    const DEFAULT_BACKGROUND = '#ececec';

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_pricing_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        // No `margin-bottom` in the element's default design options, so no
        // default bottom margin (amendment §1).
        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'heading', 'subheading', 'price', 'currency', 'period', 'markers_color' ] );

        $table = [];

        StyleMapper::write( $table, 'title.innerContent.desktop.value', $this->att( $atts, 'heading' ) );
        StyleMapper::write( $table, 'price.innerContent.desktop.value', $this->att( $atts, 'price' ) );

        // A field the author left blank is left out rather than written empty.
        foreach ( [
            'subheading' => 'subtitle.innerContent.desktop.value',
            'currency'   => 'currencyFrequency.innerContent.desktop.value.currency',
            'period'     => 'currencyFrequency.innerContent.desktop.value.per',
        ] as $key => $path ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                StyleMapper::write( $table, $path, $value );
            }
        }
        StyleMapper::write( $table, 'content.innerContent.desktop.value', trim( $this->nestedShortcodes( $this->editorHtml( $content ), $id ) ) );

        $markers = $this->color( $atts, 'markers_color', $id );
        if ( $markers !== null ) {
            StyleMapper::write( $table, 'content.advanced.bulletColor.desktop.value', $markers );
        }

        $this->defaultBox( $attrs );
        $this->fonts( $atts, $table, $consumed );
        $this->button( $atts, $id, $table, $consumed );

        $this->engine->logConverted( 'pricing-tables' );
        $this->engine->logConverted( 'pricing-table' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/pricing-tables', $attrs, [
            $this->block( $id . '-table', 'divi/pricing-table', $table ),
        ] );
    }

    /**
     * The default design options WPBakery writes to `.vc_do_pricing_table` on
     * every page that uses the element, unless the element's own `css` already
     * set that side.
     */
    private function defaultBox( array &$attrs ): void {
        $padding = $this->read( $attrs, 'module.decoration.spacing.desktop.value.padding' );
        $padding = is_array( $padding ) ? $padding : [];

        foreach ( self::DEFAULT_PADDING as $side => $value ) {
            if ( ! isset( $padding[ $side ] ) || $padding[ $side ] === '' ) {
                $padding[ $side ] = $value;
            }
        }

        StyleMapper::write(
            $attrs,
            'module.decoration.spacing.desktop.value.padding',
            $padding + [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ]
        );

        if ( $this->read( $attrs, 'module.decoration.border.desktop.value.radius' ) === null ) {
            StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.radius', self::radius( self::DEFAULT_RADIUS ) );
        }
        if ( $this->read( $attrs, 'module.decoration.border.desktop.value.styles.all' ) === null ) {
            StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.styles.all', self::DEFAULT_BORDER );
        }
        if ( $this->read( $attrs, 'module.decoration.background.desktop.value.color' ) === null ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', self::DEFAULT_BACKGROUND );
        }
    }

    /**
     * `heading` and `subheading` each carry a `vc_custom_heading` field set
     * under their own prefix (`config/buttons/shortcode-vc-pricing-table.php`
     * integrates one for each), and `pricing-table/module.json` has a font
     * group for both — `title.decoration.font` and `subtitle.decoration.font`
     * — so the packed `font_container` and `google_fonts` are applied through
     * the StyleMapper the same way `CustomHeadingConverter` does.
     *
     * @param string[] $consumed
     */
    private function fonts( array $atts, array &$table, array &$consumed ): void {
        $mapper = new StyleMapper();

        $paths = [
            'heading'    => 'title.decoration.font.font',
            'subheading' => 'subtitle.decoration.font.font',
        ];

        foreach ( $paths as $prefix => $path ) {
            $consumed[] = 'use_custom_fonts_' . $prefix;

            foreach ( array_keys( $atts ) as $key ) {
                if ( is_string( $key ) && str_starts_with( $key, $prefix . '_' ) ) {
                    $consumed[] = $key;
                }
            }

            $mapper->applyFontContainer(
                PackedParams::fontContainer( $this->att( $atts, $prefix . '_font_container' ) ),
                $path,
                $table
            );
            $mapper->applyGoogleFonts(
                PackedParams::googleFonts( $this->att( $atts, $prefix . '_google_fonts' ) ),
                $path,
                $table
            );
        }
    }

    /**
     * `add_button` + the `btn_*` field set (`vc_map_integrate_shortcode( 'vc_btn', 'btn_', … )`).
     * `pricing-table/module.json` keeps its button at the same `button`
     * attribute `button/module.json` uses, so `BtnConverter` maps it.
     *
     * @param string[] $consumed
     */
    private function button( array $atts, string $id, array &$table, array &$consumed ): void {
        $consumed[] = 'add_button';

        $button   = BtnConverter::embeddedButton( $this->engine, $atts, 'btn_', $id );
        $consumed = array_merge( $consumed, $button['consumed'] );

        if ( ! $this->on( $atts, 'add_button' ) || $button['settings'] === [] ) {
            return;
        }

        $table['button'] = $this->deepMergeSettings( is_array( $table['button'] ?? null ) ? $table['button'] : [], $button['settings'] );
    }
}
