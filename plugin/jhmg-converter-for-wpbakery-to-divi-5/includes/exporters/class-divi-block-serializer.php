<?php

namespace WPBakeryDivi5Converter\Exporters;

use WPBakeryDivi5Converter\Helpers\DiviRequirement;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Serializes the converted block tree into Divi 5's storage format: WordPress
 * block comments wrapped in `divi/placeholder`, with `builderVersion` on every
 * block so Divi's own migrations leave the attributes alone.
 *
 * The nesting rules are Divi's: a section holds rows, a row holds columns, and
 * a column may hold another row (which is how `vc_row_inner` survives). Any
 * block that turns up at the wrong depth is wrapped on the way out rather than
 * written somewhere Divi will not render it.
 */
class DiviBlockSerializer {

    public function serialize( array $divi_data ): string {
        $inner = $this->serializeElements( $this->resolveElements( $divi_data ) );

        return "<!-- wp:divi/placeholder -->{$inner}<!-- /wp:divi/placeholder -->";
    }

    private function resolveElements( array $divi_data ): array {
        if ( isset( $divi_data['divi']['elements'] ) && is_array( $divi_data['divi']['elements'] ) ) {
            return $divi_data['divi']['elements'];
        }
        if ( isset( $divi_data['elements'] ) && is_array( $divi_data['elements'] ) ) {
            return $divi_data['elements'];
        }

        return $divi_data;
    }

    private function serializeElements( array $elements ): string {
        $output = '';

        foreach ( $elements as $element ) {
            if ( is_array( $element ) ) {
                $output .= $this->serializeElement( $element );
            }
        }

        return $output;
    }

    private function serializeElement( array $element ): string {
        $name     = (string) ( $element['name'] ?? '' );
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
        $children = is_array( $element['elements'] ?? null ) ? $element['elements'] : [];

        switch ( $name ) {
            case 'divi/section':
                return $this->wrapBlock( $name, $settings, $this->serializeSectionChildren( $children ) );

            case 'divi/row':
            case 'divi/row-inner':
                return $this->wrapBlock( $name, $settings, $this->serializeRowChildren( $children ) );

            case 'divi/column':
            case 'divi/column-inner':
            case 'divi/group':
                return $this->wrapBlock( $name, $settings, $this->serializeElements( $children ) );

            default:
                if ( ! empty( $children ) ) {
                    return $this->wrapBlock( $name, $settings, $this->serializeElements( $children ) );
                }

                return $this->selfClosingBlock( $name, $settings );
        }
    }

    /** A section holds rows; anything else is wrapped in a row (and a column) on the way out. */
    private function serializeSectionChildren( array $children ): string {
        $output = '';

        foreach ( $children as $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            $name = $child['name'] ?? '';

            if ( $name === 'divi/row' ) {
                $output .= $this->serializeElement( $child );
            } elseif ( $name === 'divi/column' ) {
                $output .= $this->serializeElement( [ 'name' => 'divi/row', 'settings' => [], 'elements' => [ $child ] ] );
            } else {
                $output .= $this->serializeElement( [
                    'name'     => 'divi/row',
                    'settings' => [],
                    'elements' => [ [ 'name' => 'divi/column', 'settings' => [], 'elements' => [ $child ] ] ],
                ] );
            }
        }

        return $output;
    }

    /** A row holds columns; a loose module is wrapped in one. */
    private function serializeRowChildren( array $children ): string {
        $output = '';

        foreach ( $children as $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            if ( in_array( $child['name'] ?? '', [ 'divi/column', 'divi/column-inner' ], true ) ) {
                $output .= $this->serializeElement( $child );
            } else {
                $output .= $this->serializeElement( [ 'name' => 'divi/column', 'settings' => [], 'elements' => [ $child ] ] );
            }
        }

        return $output;
    }

    private function wrapBlock( string $block_name, array $attrs, string $inner ): string {
        $json = $this->encodeAttrs( $this->withBuilderVersion( $attrs ) );

        return "<!-- wp:{$block_name} {$json} -->{$inner}<!-- /wp:{$block_name} -->";
    }

    private function selfClosingBlock( string $block_name, array $attrs ): string {
        $json = $this->encodeAttrs( $this->withBuilderVersion( $attrs ) );

        return "<!-- wp:{$block_name} {$json} /-->";
    }

    private function encodeAttrs( array $attrs ): string {
        // A block comment delimiter cannot contain "--", so JSON-escaping "<"
        // and ">" — which wp_json_encode does not do by default — keeps HTML
        // content safe inside the comment, exactly as Divi itself stores it.
        $json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );

        return $json === false ? '{}' : $json;
    }

    /**
     * Divi's FlexboxMigration (hooked on `the_content`) rewrites blocks that
     * carry no `builderVersion`; stamping the running version keeps it off
     * the converter's output.
     */
    private function withBuilderVersion( array $attrs ): array {
        $version = defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : DiviRequirement::MINIMUM_DIVI_VERSION;

        return array_merge( [ 'builderVersion' => $version ], $attrs );
    }
}
