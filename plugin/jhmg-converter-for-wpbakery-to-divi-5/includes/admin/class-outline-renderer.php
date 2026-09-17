<?php
/**
 * Renders a conversion outline as nested lists. Returns a string so it can be
 * asserted in tests without output buffering.
 */

namespace WPBakeryDivi5Converter\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OutlineRenderer {

    /** @param array<int, mixed> $outline From `ConversionOutline::build()`. */
    public static function render( array $outline ): string {
        if ( empty( $outline ) ) {
            return '<p class="wbdc-outline-empty">'
                . esc_html__( 'Nothing to show — this page converted to no Divi modules.', 'jhmg-converter-for-wpbakery-to-divi-5' )
                . '</p>';
        }

        return self::render_list( $outline );
    }

    /** @param array<int, mixed> $nodes */
    private static function render_list( array $nodes ): string {
        $html = '<ul class="wbdc-outline">';

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $type    = (string) ( $node['type'] ?? 'module' );
            $classes = 'wbdc-outline-node wbdc-outline-node--' . $type;

            if ( ! empty( $node['placeholder'] ) ) {
                $classes .= ' wbdc-outline-node--placeholder';
            }

            $html .= '<li class="' . esc_attr( $classes ) . '"><span class="wbdc-outline-label">' . esc_html( (string) ( $node['label'] ?? '' ) );

            if ( ! empty( $node['placeholder'] ) ) {
                $html .= ' <em>' . esc_html__( '(placeholder — rebuild by hand)', 'jhmg-converter-for-wpbakery-to-divi-5' ) . '</em>';
            }

            $html .= '</span>';

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $html .= self::render_list( $node['children'] );
            }

            $html .= '</li>';
        }

        return $html . '</ul>';
    }
}
