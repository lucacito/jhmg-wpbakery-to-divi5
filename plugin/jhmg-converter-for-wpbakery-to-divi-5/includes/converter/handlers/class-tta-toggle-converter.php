<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_tta_toggle` › `vc_tta_toggle_section` → `divi/tabs` with one tab per
 * section, registered approximate.
 *
 * WPBakery's toggle container is a two-state switch — the pricing-table
 * "monthly / yearly" control — drawn as a pill with a sliding knob
 * (`include/templates/shortcodes/vc_tta_toggle.php`,
 * `getTtaContainerClasses()` adds `vc_tta-container`). Divi has no switch
 * module; a tab strip is the same behaviour (one panel visible, the others
 * hidden, click to change) drawn differently, which is exactly what
 * "approximate" means (spec §6).
 *
 * `vc_tta_toggle_section` carries `title`, `tab_id` and `section_index`; the
 * first is the switch label, the last two are editor bookkeeping.
 */
class TtaToggleConverter extends TtaTabsConverter {

    /**
     * `config/tta/shortcode-vc-tta-toggle.php` has only the two switch
     * colours — there is no active/inactive title pair here.
     */
    protected const COLOUR_PATHS = [
        'color'       => 'tab.decoration.background.desktop.value.color',
        'hover_color' => 'activeTab.decoration.background.desktop.value.color',
    ];

    public function convert( array $node ): array {
        $this->engine->logNotCarriedOver(
            'layout',
            (string) ( $node['id'] ?? '' ),
            'vc_tta_toggle draws a two-state switch; Divi has no switch module, so its sections became the tabs of a tabs module'
        );

        return parent::convert( $node );
    }

    protected function sectionTags(): array {
        return [ 'vc_tta_toggle_section' ];
    }

    protected function countedAs(): string {
        return 'tabs';
    }
}
