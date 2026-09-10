<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Converter\Handlers\TtaAccordionConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_accordion` › `vc_tta_section` → `divi/accordion` › `divi/accordion-item`.
 *
 * The element *is* WPBakery's accordion with a different form:
 * `modules/tta_accordion.php` is four lines —
 * `class WPBakeryShortCode_dfd_accordion extends WPBakeryShortCode_VC_Tta_Accordion`
 * with its own template name — and `modules/dfd_accordion.php` only re-maps the
 * params (`'as_parent' => array('only' => 'vc_tta_section')`). So the whole of
 * `TtaAccordionConverter` applies unchanged: the walk over the sections, the
 * flattening of their contents through `ContentFlattener`, and `active_section`
 * being reported because Divi opens the first panel by position.
 *
 * Only three things differ, and they are all in this class: the panel colours
 * are named after the tab strip rather than the toggle, the panel title has its
 * own typography, and the wrapper carries no `.vc_tta-container`, so there is no
 * default bottom margin (amendment §6).
 */
class DfdAccordionConverter extends TtaAccordionConverter {

    /**
     * Ronneby's own colour params (`modules/dfd_accordion.php`, "Tabs Style"
     * and "Text Style" groups) on the accordion's four Divi paths.
     */
    protected const COLOUR_PATHS = [
        'tab_background'        => 'closedToggle.decoration.background.desktop.value.color',
        'active_tab_background' => 'openToggle.decoration.background.desktop.value.color',
        'tab_text_color'        => 'closedToggle.decoration.font.font.desktop.value.color',
        'tab_active_color_text' => 'openToggle.decoration.font.font.desktop.value.color',
    ];

    /** What the theme's own stylesheet draws around the panels. */
    protected const REPORTED_LOOK = [
        'style'                => 'which of the panel frames is drawn',
        'border_radius'        => 'the corner radius of a panel',
        'border_color_radius'  => 'the panel border colour',
        'border_color_active'  => 'the border colour of the open panel',
        'active_two_px_border' => 'a two-pixel border on the open panel',
        'c_align'              => 'the alignment of the panel titles',
        'c_position'           => 'which side the open/close control sits on',
        'c_icon_position'      => 'where that control sits inside the title',
        'c_icon'               => 'which control icon is drawn',
        'icon_size'            => 'the size of that control',
        'icon_color'           => 'its colour',
        'underline'            => 'an underline on the panel title',
        'disable_plus_minus'   => 'hiding the plus/minus control',
        'color_content_area'   => 'the panel body background',
        'font_size'            => 'the panel title size',
        'tab_hover_background' => 'the panel background under the cursor',
        'tab_hover_text_color' => 'the panel title colour under the cursor',
    ];

    /** `.dfd-accordion` carries no `.vc_tta-container`, and the theme gives it no margin. */
    protected function marginKind(): string {
        return 'none';
    }

    /**
     * `tab_title_font_options` — the panel title's typography, which Divi holds
     * per state (`accordion/module.json` `closedToggle` / `openToggle`), so it
     * is written to both.
     *
     * `font_size` is Ronneby's own separate size field and stays reported: it
     * and `tab_title_font_options`'s `font_size` would fight over one Divi
     * value, and the packed one is the newer of the two.
     *
     * @param string[] $consumed
     */
    protected function extras( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'tab_title_font_options', 'tab_title_google_fonts', 'tab_title_custom_fonts', 'module_animation' ] );

        $decoded = RonnebyParams::fontOptions( (string) ( $atts['tab_title_font_options'] ?? '' ) );
        $mapper  = new StyleMapper();

        foreach ( [ 'closedToggle.decoration.font.font', 'openToggle.decoration.font.font' ] as $path ) {
            $mapper->applyDfdFontOptions( $decoded, $path, $attrs );

            if ( in_array( strtolower( trim( (string) ( $atts['tab_title_google_fonts'] ?? '' ) ) ), [ 'yes', 'show' ], true ) ) {
                $mapper->applyGoogleFonts(
                    PackedParams::googleFonts( (string) ( $atts['tab_title_custom_fonts'] ?? '' ) ),
                    $path,
                    $attrs
                );
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
