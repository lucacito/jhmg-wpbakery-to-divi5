<?php
/**
 * The structural outline the report screen draws: sections, rows, columns and
 * modules as a tree of labels. Structure, not pixels — Divi 5 keys its CSS to
 * a post id, so rendering detached blocks would only show an unstyled skeleton.
 */

namespace WPBakeryDivi5Converter\Conversion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ConversionOutline {

    private const STRUCTURAL = [
        'divi/section' => 'section',
        'divi/row'     => 'row',
        'divi/column'  => 'column',
        'divi/group'   => 'group',
    ];

    /**
     * The comment `BaseWPBakeryConverter::staticCopy()` writes when nothing
     * could render the element — the difference between a placeholder and a
     * static copy of the real thing, which looks like any other code block.
     */
    private const PLACEHOLDER_MARKER = 'wpbakery element:';

    /**
     * @param array<int, mixed> $blocks
     * @return array<int, array{type: string, name: string, label: string, placeholder: bool, children: array}>
     */
    public static function build( array $blocks ): array {
        $nodes = [];

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $name = (string) ( $block['name'] ?? '' );
            if ( $name === '' ) {
                continue;
            }

            $content = $block['settings']['content']['innerContent']['desktop']['value'] ?? '';

            $nodes[] = [
                'type'        => self::STRUCTURAL[ $name ] ?? 'module',
                'name'        => $name,
                'label'       => self::label( $name ),
                'placeholder' => $name === 'divi/code' && is_string( $content ) && str_contains( $content, self::PLACEHOLDER_MARKER ),
                'children'    => self::build( is_array( $block['elements'] ?? null ) ? $block['elements'] : [] ),
            ];
        }

        return $nodes;
    }

    /** 'divi/number-counter' → 'Number Counter'. */
    private static function label( string $name ): string {
        $bare = str_contains( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;

        return ucwords( str_replace( [ '-', '_' ], ' ', $bare ) );
    }
}
