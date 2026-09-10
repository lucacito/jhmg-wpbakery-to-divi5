<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Converter\Handlers\TtaTabsConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_tta_tabs` and `dfd_tta_tour` › `vc_tta_section` → `divi/tabs` › `divi/tab`.
 *
 * Both are WPBakery's own tta elements with a re-mapped form:
 * `modules/tta_tabs.php` and `modules/tta_tour.php` are
 * `extends WPBakeryShortCode_VC_Tta_Tabs` / `…_Tour` with one overridden
 * template name, so `TtaTabsConverter` — the walk over the sections, the
 * flattening of their contents, the tour's controls being reported because Divi
 * draws one horizontal strip — applies unchanged.
 *
 * What this class adds is Ronneby's own vocabulary: four colour params named
 * after the tab strip, a `*_title_font_options` for the tab label (spelled
 * `tab_title_*` on the tabs and `tour_title_*` on the tour), and a long list of
 * frame options the theme's stylesheet draws.
 *
 * **No default bottom margin**: the element carries no `.vc_tta-container`
 * class and the theme gives `.dfd-tabs` none (amendment §6).
 */
class DfdTabsConverter extends TtaTabsConverter {

    /** Ronneby's colour params on `tabs/module.json`'s strip paths. */
    protected const COLOUR_PATHS = [
        'tab_background'        => 'tab.decoration.background.desktop.value.color',
        'active_tab_background' => 'activeTab.decoration.background.desktop.value.color',
        'tab_text_color'        => 'tab.decoration.font.font.desktop.value.color',
        'tab_active_color_text' => 'activeTab.decoration.font.font.desktop.value.color',
    ];

    /** The frame and the controls, all drawn by the theme's own stylesheet. */
    protected const REPORTED_LOOK = [
        'style'                       => 'which of the tab frames is drawn',
        'border_radius'               => 'the corner radius of a tab',
        'border_color_radius'         => 'the tab border colour',
        'border_color_active'         => 'the border colour of the open tab',
        'border_hover_color'          => 'the border colour under the cursor',
        'border_width'                => 'the border width',
        'tab_position'                => 'which side the tab strip sits on',
        'alignment'                   => 'the alignment of the strip',
        'tab_hover_background'        => 'the tab background under the cursor',
        'tab_hover_text_color'        => 'the tab label under the cursor',
        'tab_active_hover_color_text' => 'the open tab\'s label under the cursor',
        'tab_title_uppercase'         => 'upper-casing the tab labels',
        'tour_title_uppercase'        => 'upper-casing the tour labels',
        'tab_text_transform'          => 'the case of the tab labels',
        'underline'                   => 'an underline on the tab label',
        'font_size'                   => 'the tab label size',
        'show_separator_line'         => 'the rule between the strip and the panel',
        'color_content_area'          => 'the panel background',
        'gap'                         => 'the space between the strip and the panel',
        'icon_color'                  => 'the colour of a tab icon',
        'icon_size'                   => 'its size',
        'controls_size'               => 'the size of the tour controls',
        'spacing'                     => 'the space between the tour controls',
        'pagination_style'            => 'the pagination dots',
        'title_font_options'          => 'the typography of the element heading',
    ];

    /**
     * `dfd_tta_tour` stacks its controls down one side, exactly as
     * `vc_tta_tour` does — and `TtaTabsConverter` reports that by tag, so the
     * theme's own tag is reported here.
     */
    public function convert( array $node ): array {
        if ( (string) ( $node['tag'] ?? '' ) === 'dfd_tta_tour' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                (string) ( $node['id'] ?? '' ),
                'dfd_tta_tour stacks its controls down one side of the panels; Divi\'s tabs module draws a single horizontal strip'
            );
        }

        return parent::convert( $node );
    }

    /** The theme renders these from its own template, with no `.vc_tta-container`. */
    protected function marginKind(): string {
        return 'none';
    }

    /**
     * `tab_title_font_options` (tabs) / `tour_title_font_options` (tour) — the
     * label's typography, written to both strip states
     * (`tabs/module.json` `tab` / `activeTab`).
     *
     * @param string[] $consumed
     */
    protected function extras( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $mapper = new StyleMapper();

        foreach ( [ 'tab_title', 'tour_title' ] as $prefix ) {
            $consumed = array_merge( $consumed, [ $prefix . '_font_options', $prefix . '_google_fonts', $prefix . '_custom_fonts' ] );

            $decoded = RonnebyParams::fontOptions( (string) ( $atts[ $prefix . '_font_options' ] ?? '' ) );
            $uses    = in_array( strtolower( trim( (string) ( $atts[ $prefix . '_google_fonts' ] ?? '' ) ) ), [ 'yes', 'show' ], true );

            if ( $decoded === [] && ! $uses ) {
                continue;
            }

            foreach ( [ 'tab.decoration.font.font', 'activeTab.decoration.font.font' ] as $path ) {
                $mapper->applyDfdFontOptions( $decoded, $path, $attrs );

                if ( $uses ) {
                    $mapper->applyGoogleFonts(
                        PackedParams::googleFonts( (string) ( $atts[ $prefix . '_custom_fonts' ] ?? '' ) ),
                        $path,
                        $attrs
                    );
                }
            }
        }

        // The element's own "custom font" pair, which the tour form spells
        // without a prefix.
        $consumed = array_merge( $consumed, [ 'use_google_fonts', 'custom_fonts', 'module_animation' ] );
        if ( in_array( strtolower( trim( (string) ( $atts['use_google_fonts'] ?? '' ) ) ), [ 'yes', 'show' ], true ) ) {
            foreach ( [ 'tab.decoration.font.font', 'activeTab.decoration.font.font' ] as $path ) {
                $mapper->applyGoogleFonts( PackedParams::googleFonts( (string) ( $atts['custom_fonts'] ?? '' ) ), $path, $attrs );
            }
        }

        $animation = trim( (string) ( $atts['module_animation'] ?? '' ) );
        if ( $animation !== '' ) {
            $this->engine->logNotCarriedOver(
                'animation',
                $id,
                sprintf( 'module_animation="%s" plays a velocity.js transition when the element scrolls into view; set one of Divi\'s own animations on the module', $animation )
            );
        }
    }
}
