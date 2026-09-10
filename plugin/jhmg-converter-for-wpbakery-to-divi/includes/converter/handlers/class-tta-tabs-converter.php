<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_tta_tabs`, `vc_tta_tour` and `vc_tta_pageable` › `vc_tta_section` →
 * `divi/tabs` › `divi/tab` (the legacy `vc_tabs`/`vc_tour` › `vc_tab` arrive
 * here as the tta forms, `AttributeNormaliser::DEPRECATED_TAGS`).
 *
 * All three are the same element in WPBakery — one `vc_tta_global.php`
 * template, one section child — differing only in where the strip of controls
 * sits: on top for tabs, down the side for a tour, and replaced by pagination
 * dots for a pageable container. Divi has one horizontal tab strip, so `tour`
 * and `pageable` are registered approximate and their layout is reported
 * (spec §6).
 *
 * The colour attributes land on the tab strip rather than on a panel:
 * `tabs/module.json` exposes `tab.decoration.background` / `tab.decoration.font`
 * for the inactive controls and `activeTab.…` for the selected one.
 */
class TtaTabsConverter extends TtaAccordionConverter {

    protected const COLOUR_PATHS = [
        'color'                => 'tab.decoration.background.desktop.value.color',
        'active_color'         => 'activeTab.decoration.background.desktop.value.color',
        'inactive_title_color' => 'tab.decoration.font.font.desktop.value.color',
        'active_title_color'   => 'activeTab.decoration.font.font.desktop.value.color',
    ];

    public function convert( array $node ): array {
        $tag = (string) ( $node['tag'] ?? '' );

        if ( $tag === 'vc_tta_tour' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                (string) ( $node['id'] ?? '' ),
                'vc_tta_tour stacks its controls down one side of the panels; Divi\'s tabs module draws a single horizontal strip'
            );
        }

        if ( $tag === 'vc_tta_pageable' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                (string) ( $node['id'] ?? '' ),
                'vc_tta_pageable replaces the control strip with pagination dots and arrows; Divi\'s tabs module draws a labelled tab strip instead'
            );
        }

        return parent::convert( $node );
    }

    protected function parentName(): string {
        return 'divi/tabs';
    }

    protected function itemName(): string {
        return 'divi/tab';
    }

    protected function countedAs(): string {
        return 'tabs';
    }

    protected function itemCountedAs(): string {
        return 'tab';
    }

    /** `tab/module.json`'s title is an `<a class="et_pb_tab_nav_item_link">`, not a heading. */
    protected function itemHeadingPath(): ?string {
        return null;
    }
}
