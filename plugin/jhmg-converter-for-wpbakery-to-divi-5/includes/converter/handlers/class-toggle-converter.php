<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_toggle` → `divi/toggle`.
 *
 * `include/templates/shortcodes/vc_toggle.php` is a title, an icon and a
 * content div that `vc_toggle_active` reveals — which is Divi's toggle module
 * exactly: `title.innerContent`, `content.innerContent` and
 * `module.advanced.open` (`toggle/module.json`).
 *
 * Spacing follows amendment §2 and `js_composer.min.css`:
 * `.vc_toggle_content{margin-bottom:21.73913043px}` sits between two toggles,
 * and `.vc_toggle:last-of-type{margin-bottom:35px}` closes the run — so every
 * toggle takes the `toggle` margin except the last of a consecutive run, which
 * takes the `content` one. Which toggle that is can only be seen from the
 * outside, so `ColumnConverter` marks it with `markLastOfRun()` before the
 * children are converted.
 */
class ToggleConverter extends BaseWPBakeryConverter {

    /** The node key `markLastOfRun()` sets and `convert()` reads. */
    const LAST_OF_RUN = 'wbdc_toggle_last';

    /**
     * Marks the last `vc_toggle` of every consecutive run of them, the way
     * `.vc_toggle:last-of-type` matches. Mirrors
     * `RowConverter::markFilled()`: a rule about a node's neighbours, applied
     * where the neighbours are visible.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,array<string,mixed>>
     */
    public static function markLastOfRun( array $nodes ): array {
        $last = null;

        foreach ( $nodes as $index => $node ) {
            if ( ( $node['tag'] ?? '' ) === 'vc_toggle' ) {
                $last = $index;
                continue;
            }
            if ( $last !== null ) {
                $nodes[ $last ][ self::LAST_OF_RUN ] = true;
                $last                                = null;
            }
        }

        if ( $last !== null ) {
            $nodes[ $last ][ self::LAST_OF_RUN ] = true;
        }

        return $nodes;
    }

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_toggle_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        $margin_kind = empty( $node[ self::LAST_OF_RUN ] ) ? 'toggle' : 'content';

        $style    = $this->mapStyle( 'text', $node, $margin_kind );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'title', 'open' ] );

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'title' ) );
        StyleMapper::write( $attrs, 'module.advanced.open.desktop.value', $this->on( $atts, 'open' ) ? 'on' : 'off' );

        $html = $this->nestedShortcodes( $this->editorHtml( $content ), $id );
        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', trim( $html ) );

        $this->look( $atts, $id, $consumed );

        $this->engine->logConverted( 'toggle' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/toggle', $attrs );
    }

    /**
     * `style`, `size` and `color` are all about the little open/close icon and
     * the title colour (`.vc_toggle_<style>`, `.vc_toggle_size_<size>`,
     * `.vc_color-<name>`); Divi draws its own toggle icon and has no matching
     * shape vocabulary, so what they change is reported.
     *
     * `use_custom_heading` hands the title to `vc_custom_heading` with a
     * `custom_` prefix; Divi's toggle title is a single font setting, so the
     * prefixed attributes are reported as well.
     *
     * @param string[] $consumed
     */
    private function look( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'style', 'size', 'color', 'use_custom_heading' ] );

        $described = [];
        foreach ( [ 'style', 'size', 'color' ] as $key ) {
            $value = $this->att( $atts, $key );
            if ( $value !== '' ) {
                $described[] = $key . '="' . $value . '"';
            }
        }

        if ( $described !== [] ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'vc_toggle ' . implode( ' ', $described ) . ' style the open/close icon and the title colour; Divi draws its own toggle icon'
            );
        }

        if ( $this->on( $atts, 'use_custom_heading' ) ) {
            foreach ( array_keys( $atts ) as $key ) {
                if ( is_string( $key ) && str_starts_with( $key, 'custom_' ) ) {
                    $consumed[] = $key;
                }
            }

            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'vc_toggle use_custom_heading renders the title through vc_custom_heading; Divi\'s toggle title takes its own font settings instead'
            );
        }
    }
}
