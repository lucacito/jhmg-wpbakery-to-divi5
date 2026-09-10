<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Converter\ContentFlattener;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_tta_accordion` › `vc_tta_section` → `divi/accordion` › `divi/accordion-item`,
 * and the shared machinery of the whole TTA family.
 *
 * `TtaTabsConverter` and `TtaToggleConverter` extend this class the way
 * `RowInnerConverter` extends `RowConverter`: the walk over the sections, the
 * flattening of their contents and the reporting of WPBakery's colour scheme
 * are identical, and only the two block names and the four colour paths
 * differ.
 *
 * **Content.** `include/templates/shortcodes/vc_tta_section.php` puts whatever
 * the editor dropped into a panel body — rows, columns, any element. Divi
 * 5.12.1's accordion item and tab both hold a single `content.innerContent`
 * string, so the children are converted by their own handlers first and the
 * blocks are then rendered to HTML by `ContentFlattener` (spec §6). Nothing is
 * dropped: what has no HTML form is a comment and a report entry.
 *
 * The element and each of its panels are counted as converted — both are real
 * blocks in the finished page — but the children are not: `ContentFlattener::flatten()`
 * converts them inside `ConverterEngine::withConvertedCountSuppressed()`,
 * because the blocks they produce are read for their HTML and thrown away.
 *
 * **Which panel is open.** `active_section` is a number, and WPBakery opens
 * that panel. Divi 5.12.1 has no equivalent attribute: `AccordionItemModuleUtils::get_toggle_class_name()`
 * gives `et_pb_toggle_open` to the *first* item and `TabModule::_should_be_active_tab()`
 * does the same for tabs, both by position alone. So an `active_section` other
 * than the first is reported rather than invented.
 *
 * **Spacing.** `js_composer_tta.min.css` `.vc_tta-container{margin-bottom:21.73913043px}`
 * — the tta family carries its own bottom margin, not `.wpb_content_element`'s
 * 35 px (amendment §1), which is the `tta` kind in `GlobalSettingsResolver`.
 */
class TtaAccordionConverter extends BaseWPBakeryConverter {

    /** The colour attributes both eras write, and where each lands on this module. */
    protected const COLOUR_PATHS = [
        'color'                => 'closedToggle.decoration.background.desktop.value.color',
        'active_color'         => 'openToggle.decoration.background.desktop.value.color',
        'inactive_title_color' => 'closedToggle.decoration.font.font.desktop.value.color',
        'active_title_color'   => 'openToggle.decoration.font.font.desktop.value.color',
    ];

    /**
     * The look attributes WPBakery paints with CSS classes on `.vc_tta`
     * (`getTtaGeneralClasses()`), with what each one changes. Divi's accordion
     * and tabs draw their own frame, so every one of them is reported.
     */
    protected const REPORTED_LOOK = [
        'style'           => 'the panel frame (classic, modern, flat, outline, …)',
        'shape'           => 'the panel corners (rounded, square, round)',
        'outline_color'   => 'the outline colour of the outline styles',
        'c_align'         => 'the alignment of the controls',
        'c_icon'          => 'the open/close control icon',
        'c_position'      => 'which side the control icon sits on',
        'spacing'         => 'the space between panels',
        'gap'             => 'the gap between panels',
        'alignment'       => 'the alignment of the tab strip',
        'tab_position'    => 'which side the tab strip sits on',
        'controls_size'   => 'the size of the tour controls',
        'pagination_style' => 'the pagination dots',
        'pagination_color' => 'the pagination colour',
        'title_tag'       => 'the tag of the element heading',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_tta_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'tta' );
        $attrs    = $style['divi_attrs'];
        $consumed = $style['handled_keys'];

        $this->colours( $atts, $id, $attrs, $consumed );
        $this->behaviour( $atts, $id, $consumed );
        $this->look( $atts, $id, $consumed );

        $items = $this->items( $node );

        $this->engine->logConverted( $this->countedAs() );
        $this->logUnmappedSettings( $id, $atts, array_merge( $consumed, [ 'title' ] ), (string) ( $node['tag'] ?? '' ) );

        if ( $items === [] ) {
            $this->engine->logWarning( sprintf( '%s %s holds no sections; nothing was written for it.', (string) ( $node['tag'] ?? '' ), $id ) );

            return [];
        }

        $blocks = [ $this->block( $id, $this->parentName(), $attrs, $items ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    // -------------------------------------------------------------------------
    // What this handler builds
    // -------------------------------------------------------------------------

    protected function parentName(): string {
        return 'divi/accordion';
    }

    protected function itemName(): string {
        return 'divi/accordion-item';
    }

    protected function countedAs(): string {
        return 'accordion';
    }

    protected function itemCountedAs(): string {
        return 'accordion-item';
    }

    /** The tags a panel of this element is written with, after normalisation. */
    protected function sectionTags(): array {
        return [ 'vc_tta_section' ];
    }

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    /** @return array<int, array<string,mixed>> */
    private function items( array $node ): array {
        $flattener = new ContentFlattener( $this->engine );
        $items     = [];
        $tag       = (string) ( $node['tag'] ?? '' );
        $heading   = strtolower( trim( $this->att( is_array( $node['atts'] ?? null ) ? $node['atts'] : [], 'section_title_tag' ) ) );

        foreach ( is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
            if ( ! is_array( $child ) || ! in_array( (string) ( $child['tag'] ?? '' ), $this->sectionTags(), true ) ) {
                continue;
            }

            $items[] = $this->item( $child, $flattener, $heading, $tag );
        }

        return $items;
    }

    private function item( array $section, ContentFlattener $flattener, string $heading_level, string $parent_tag ): array {
        $id    = (string) ( $section['id'] ?? uniqid( 'wbdc_tta_section_' ) );
        $atts  = is_array( $section['atts'] ?? null ) ? $section['atts'] : [];
        $attrs = [];

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'title' ) );

        if ( $heading_level !== '' && $this->itemHeadingPath() !== null ) {
            if ( preg_match( '/^h[1-6]$/', $heading_level ) === 1 ) {
                StyleMapper::write( $attrs, $this->itemHeadingPath(), $heading_level );
            }
        }

        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            $flattener->flatten( is_array( $section['children'] ?? null ) ? $section['children'] : [], $id )
        );

        $consumed = $this->sectionIcon( $atts, $id, $parent_tag );

        $this->engine->logConverted( $this->itemCountedAs() );
        $this->logUnmappedSettings( $id, $atts, array_merge( $consumed, [ 'title' ] ), (string) ( $section['tag'] ?? '' ) );

        return $this->block( $id, $this->itemName(), $attrs );
    }

    /**
     * Where the item's own title tag goes, or null when the module has none.
     * `accordion-item/module.json` renders `title.decoration.font`'s
     * `headingLevel` field; `tab/module.json`'s title is an `<a>` and does not.
     */
    protected function itemHeadingPath(): ?string {
        return 'title.decoration.font.font.desktop.value.headingLevel';
    }

    /**
     * `vc_tta_section`'s `add_icon` puts an icon beside the panel title
     * (`config/tta/shortcode-vc-tta-section.php` integrates `vc_icon`'s picker
     * under an `i_` prefix). Divi's accordion has one icon per panel and it is
     * the open/close control, not a decoration next to the title, so the
     * section icon is reported.
     *
     * @return string[] The section attributes this claimed.
     */
    private function sectionIcon( array $atts, string $id, string $parent_tag ): array {
        $library  = $this->att( $atts, 'i_type', 'fontawesome' );
        $consumed = [ 'add_icon', 'i_position', 'i_type', 'i_icon_' . $library ];

        if ( ! $this->on( $atts, 'add_icon' ) ) {
            return $consumed;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                '%s section icon (%s "%s"); Divi\'s panel icon is the open/close control, not a decoration beside the title',
                $parent_tag,
                $library,
                $this->att( $atts, 'i_icon_' . $library )
            )
        );

        return $consumed;
    }

    // -------------------------------------------------------------------------
    // Colours, behaviour and the rest of the look
    // -------------------------------------------------------------------------

    /** @param string[] $consumed */
    protected function colours( array $atts, string $id, array &$attrs, array &$consumed ): void {
        foreach ( static::COLOUR_PATHS as $key => $path ) {
            $consumed[] = $key;

            $color = $this->color( $atts, $key, $id );
            if ( $color !== null ) {
                StyleMapper::write( $attrs, $path, $color );
            }
        }
    }

    /**
     * `AttributeNormaliser` rewrites the deprecated tags but not their
     * attributes — WPBakery 9.0.1 keeps `config/deprecated/shortcode-vc-accordion.php`
     * and renders it with its own `active_tab`, `collapsible` and
     * `disable_keyboard` names — so both spellings are read here and a legacy
     * `vc_accordion` converts to exactly what its `vc_tta_accordion`
     * equivalent does.
     *
     * @param string[] $consumed
     */
    protected function behaviour( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, [
            'active_section',
            'collapsible_all',
            'autoplay',
            'fill_content_area',
            'section_title_tag',
            'active_tab',
            'collapsible',
            'disable_keyboard',
            'interval',
        ] );

        $atts['active_section']  = $this->att( $atts, 'active_section', $this->att( $atts, 'active_tab' ) );
        $atts['collapsible_all'] = $this->att( $atts, 'collapsible_all', $this->att( $atts, 'collapsible' ) );

        if ( $this->on( $atts, 'disable_keyboard' ) ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                'disable_keyboard turns off the arrow-key navigation of the deprecated accordion; Divi always keeps its own keyboard handling'
            );
        }

        $interval = trim( $this->att( $atts, 'interval' ) );
        if ( $interval !== '' && $interval !== '0' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                sprintf( 'interval="%s" auto-rotated the deprecated tabs element; 9.0.1 dropped the option and Divi has none', $interval )
            );
        }

        $active = trim( $this->att( $atts, 'active_section' ) );
        if ( $active !== '' && $active !== '1' && $active !== '0' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'active_section="%s" opens that panel in WPBakery; Divi 5.12 always opens the first one (AccordionItemModuleUtils::get_toggle_class_name)',
                    $active
                )
            );
        }

        if ( $this->on( $atts, 'collapsible_all' ) ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                'collapsible_all lets every panel close at once; Divi\'s accordion always keeps one open'
            );
        }

        $autoplay = trim( $this->att( $atts, 'autoplay' ) );
        if ( $autoplay !== '' && $autoplay !== '0' ) {
            $this->engine->logNotCarriedOver(
                'interaction',
                $id,
                sprintf( 'autoplay="%s" rotates the panels every %s seconds; Divi has no auto-rotate for this module', $autoplay, $autoplay )
            );
        }

        $heading = trim( $this->att( $atts, 'section_title_tag' ) );
        if ( $heading !== '' && $this->itemHeadingPath() === null ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'section_title_tag="%s" sets the tag of each panel title; Divi renders a tab label as a link, not a heading', $heading )
            );
        }

        if ( array_key_exists( 'fill_content_area', $atts ) && ! $this->on( $atts, 'fill_content_area' ) ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'fill_content_area is off, so WPBakery drops the panel background and border; set the module\'s own background in Divi'
            );
        }
    }

    /** @param string[] $consumed */
    protected function look( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, array_keys( self::REPORTED_LOOK ) );

        $described = [];
        foreach ( self::REPORTED_LOOK as $key => $what ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value !== '' ) {
                $described[] = $key . '="' . $value . '" (' . $what . ')';
            }
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'WPBakery paints this element with its own classes: ' . implode( ', ', $described ) . '; Divi draws its own frame'
        );
    }
}
