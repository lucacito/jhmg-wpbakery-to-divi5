<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\IconMap;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What every DFD Ronneby element shares.
 *
 * Ronneby Core registers its elements with `add_shortcode()` and renders them
 * from its own PHP, so three things are true of all of them and are handled
 * here rather than twenty-one times over:
 *
 * 1. **No default bottom margin.** WPBakery's `.wpb_content_element` class is
 *    never printed — it belongs to WPBakery's own element template, and
 *    Ronneby's modules print their own `<div class="dfd-…">` — and the theme's
 *    stylesheets give none of those wrappers a bottom margin either
 *    (`dfd-ronneby/assets/css/visual-composer.css`, `app.css`: the only
 *    `margin-bottom` rules on a Ronneby module are on parts *inside* it, such
 *    as `.dfd-info-box .icon-wrapper{margin-bottom:15px}`). The demo pages space
 *    their elements with `dfd_spacer` instead — 13,689 of them across the 96
 *    exports. So every handler here passes `mapStyle( …, 'none' )`, per
 *    task-9b-amendments.md §6: a default margin needs a stylesheet line.
 * 2. **Typography is a packed `*_font_options` value** plus a Google-font
 *    toggle and a family, all applied to the same rendered element
 *    (`_crum_parse_text_shortcode_params()`, `inc/vc_custom/dfd_vc_addons.php:155`).
 *    `applyFontSet()` puts them on a Divi font path.
 * 3. **A `module_animation`** naming a velocity.js transition, and a block of
 *    look attributes the theme's own CSS draws. Both are reported.
 *
 * Every handler is registered `approximate: true` (amendment §4): the source
 * read for them is Ronneby Core 1.5.74 while a live site may run another build,
 * and the corpus shows attribute names from more than one era on the same tag.
 */
abstract class RonnebyConverter extends BaseWPBakeryConverter {

    /**
     * One Ronneby typography field set on a Divi font path.
     *
     * `$fields` names the three attributes the element uses, because they are
     * spelled differently on every module: `['options' => 'title_font_options',
     * 'toggle' => 'title_google_fonts', 'family' => 'title_custom_fonts']` on
     * `dfd_heading`, `['options' => 'font_options', 'toggle' => 'use_google_fonts',
     * 'family' => 'custom_fonts']` on `announcement`, and so on. A missing key
     * simply is not read.
     *
     * What Divi's font settings have no field for — a `tag` that is not a
     * heading level on a module whose text is not a heading — is reported by
     * the caller through the returned list, not swallowed.
     *
     * @param array<string,string> $fields  options|toggle|family ⇒ attribute name
     * @param string[]             $consumed
     * @return string[] The `font_options` keys this could not express.
     */
    protected function applyFontSet( array $atts, array $fields, string $font_path, string $node_id, array &$attrs, array &$consumed, bool $renders_heading_level = false ): array {
        foreach ( $fields as $attribute ) {
            $consumed[] = $attribute;
        }

        $decoded  = RonnebyParams::fontOptions( $this->att( $atts, $fields['options'] ?? '' ) );
        $unmapped = $this->applyFontOptions( $decoded, $font_path, $attrs, $renders_heading_level );

        $this->reportFontOptions( $unmapped, (string) ( $fields['options'] ?? '' ), $node_id );

        // `_crum_parse_text_shortcode_params()` reads the family only when the
        // toggle says `yes` or `show` (dfd_vc_addons.php:178).
        $toggle = strtolower( trim( $this->att( $atts, $fields['toggle'] ?? '' ) ) );
        if ( in_array( $toggle, [ 'yes', 'show' ], true ) && isset( $fields['family'] ) ) {
            ( new StyleMapper() )->applyGoogleFonts(
                PackedParams::googleFonts( $this->att( $atts, $fields['family'] ) ),
                $font_path,
                $attrs
            );
        }

        return $unmapped;
    }

    /**
     * A decoded `*_font_options` value written to a Divi font path.
     *
     * The mapping itself lives in `StyleMapper::applyDfdFontOptions()`, beside
     * `applyFontContainer()`, because the Ronneby accordion and tabs need it
     * too and they extend Task 9's tta converters rather than this class.
     *
     * @param array<string,string> $decoded
     * @return string[] keys not written
     */
    protected function applyFontOptions( array $decoded, string $font_path, array &$attrs, bool $renders_heading_level = false ): array {
        return ( new StyleMapper() )->applyDfdFontOptions( $decoded, $font_path, $attrs, $renders_heading_level );
    }

    /**
     * What a `*_font_options` value carried that its target has no field for.
     *
     * `tag` is the common one and is a page-level warning rather than an entry
     * per element: Ronneby's own default is `div`, every element on the page
     * carries one, and a line each would say the same thing dozens of times —
     * the rule `CustomHeadingConverter` follows for `vc_custom_heading`'s
     * missing margin. Anything else is a real loss and is reported.
     *
     * @param string[] $unmapped
     */
    protected function reportFontOptions( array $unmapped, string $key, string $node_id ): void {
        if ( in_array( 'tag', $unmapped, true ) ) {
            $this->engine->logWarning( sprintf(
                '%s names the tag Ronneby prints the text in; the Divi module it maps to renders its own element, so the tag was not carried over.',
                $key
            ) );

            $unmapped = array_values( array_diff( $unmapped, [ 'tag' ] ) );
        }

        if ( $unmapped === [] || $key === '' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $node_id,
            sprintf( '%s carries %s, which Divi\'s font settings have no field for', $key, implode( ', ', $unmapped ) )
        );
    }

    /**
     * `responsive_css()`'s per-band font sizes on the Divi breakpoints that
     * cover them.
     *
     * Ronneby's `desktop` band is 1024-1279px, which sits inside Divi's one
     * desktop breakpoint (≥981px) — writing it there would replace the size the
     * element uses above 1280px too — so it is reported instead.
     *
     * @param string[] $consumed
     */
    protected function applyResponsiveText( array $atts, string $key, string $font_path, string $node_id, array &$attrs, array &$consumed ): void {
        $consumed[] = $key;

        $raw = $this->att( $atts, $key );
        if ( trim( $raw ) === '' ) {
            return;
        }

        foreach ( RonnebyParams::responsiveText( $raw ) as $breakpoint => $fields ) {
            if ( $breakpoint === 'medium_desktop' ) {
                $this->engine->logNotCarriedOver(
                    'layout',
                    $node_id,
                    sprintf(
                        '%s sets a separate size for 1024-1279px screens (%s); Divi has one desktop breakpoint from 981px up, so the element keeps its wide-screen size there',
                        $key,
                        implode( ', ', array_map( static fn( string $k, string $v ): string => $k . ' ' . $v, array_keys( $fields ), $fields ) )
                    )
                );

                continue;
            }

            foreach ( $fields as $sub_name => $value ) {
                if ( $value !== '' ) {
                    StyleMapper::write( $attrs, "{$font_path}.{$breakpoint}.value.{$sub_name}", $value );
                }
            }
        }
    }

    /**
     * A group of attributes the theme's own CSS draws and Divi has no field
     * for, claimed and reported in one entry.
     *
     * One entry rather than one per key, the way `TtaAccordionConverter::look()`
     * does it: a real page carries dozens of these and a line each would bury
     * the report. Every key is listed with its value, so nothing is claimed
     * without being said (amendment §6).
     *
     * @param array<string,string> $describe attribute ⇒ what it changes
     * @param string[]             $consumed
     */
    protected function reportGroup( array $atts, array $describe, string $kind, string $node_id, string $intro, array &$consumed ): void {
        $consumed = array_merge( $consumed, array_keys( $describe ) );

        $described = [];
        foreach ( $describe as $key => $what ) {
            $value = trim( $this->att( $atts, $key ) );
            if ( $value === '' || in_array( strtolower( $value ), [ 'no', 'none', 'off', 'false' ], true ) ) {
                continue;
            }
            $described[] = $key . '="' . $value . '" (' . $what . ')';
        }

        if ( $described === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver( $kind, $node_id, $intro . ': ' . implode( ', ', $described ) );
    }

    /**
     * `module_animation`, the velocity.js transition Ronneby plays when the
     * element scrolls into view (`data-animate-type`, e.g. every module's
     * `if ( ! ( $module_animation == '' ) ) { $el_class .= ' cr-animate-gen'; }`).
     *
     * @param string[] $consumed
     */
    protected function reportAnimation( array $atts, string $node_id, array &$consumed ): void {
        $consumed[] = 'module_animation';

        $animation = trim( $this->att( $atts, 'module_animation' ) );
        if ( $animation === '' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'animation',
            $node_id,
            sprintf( 'module_animation="%s" plays a velocity.js transition when the element scrolls into view; set one of Divi\'s own animations on the module', $animation )
        );
    }

    /**
     * A Ronneby icon class as Divi's icon value.
     *
     * The theme's picker stores a CSS class from whichever library the element
     * offers: its own `dfd-icon-*` / `dfd-socicon-*` / `soc_icon-*` fonts, or
     * one of WPBakery's five (`icon_fontawesome`, `icon_openiconic`, …). Only
     * Font Awesome has a Divi equivalent, so anything else is a star and is
     * reported (`IconMap`, and the same rule `BtnConverter::icon()` follows).
     *
     * @return array{unicode: string, type: string, weight: string}
     */
    protected function iconValue( string $class, string $node_id, string $what ): array {
        $mapped = IconMap::fromClass( $class );

        if ( ! $mapped['exact'] ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $node_id,
                sprintf( '%s "%s" is from a theme icon font Divi does not ship; a star stands in for it', $what, trim( $class ) )
            );
        }

        return $mapped['icon'];
    }

    /**
     * The icon class an element actually renders, out of the six fields
     * WPBakery's picker fills.
     *
     * `dfd_button` and `dfd_delimiter` integrate the picker under `icon_font` +
     * `icon_<library>` (`modules/dfd_button.php:742-750`), where `dfd_icons` is
     * the theme's own set; the rest use a single `icon` field written by
     * Ronneby's own icon manager (`modules/dfd_icon_manager.php`).
     *
     * @param string[] $consumed
     * @return array{class: string, library: string}
     */
    protected function pickedIcon( array $atts, array &$consumed ): array {
        $consumed[] = 'icon_font';

        $library = trim( $this->att( $atts, 'icon_font' ) );

        foreach ( [ 'dfd_icons', 'fontawesome', 'openiconic', 'typicons', 'entypo', 'linecons' ] as $known ) {
            $consumed[] = 'icon_' . $known;
        }

        if ( $library === '' ) {
            return [ 'class' => '', 'library' => '' ];
        }

        return [ 'class' => trim( $this->att( $atts, 'icon_' . $library ) ), 'library' => $library ];
    }

    /**
     * A Ronneby number attribute (`icon_size`, `border_radius`, `height`) as a
     * pixel string, or '' — the theme prints all of them with `px` appended.
     */
    protected function pixels( array $atts, string $key, string $default = '' ): string {
        $raw = trim( $this->att( $atts, $key, $default ) );

        if ( $raw === '' || ! is_numeric( $raw ) ) {
            return '';
        }

        return PackedParams::sizeWithUnit( $raw );
    }
}
