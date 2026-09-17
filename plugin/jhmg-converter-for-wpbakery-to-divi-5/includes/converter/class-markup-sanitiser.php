<?php

namespace WPBakeryDivi5Converter\Converter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Converted markup is held to what the person converting may publish.
 *
 * A conversion moves HTML that already sits in a page into Divi block
 * attributes. WordPress filters post content through kses for a user without
 * `unfiltered_html`, but it cannot see markup once it is JSON-escaped inside a
 * block attribute — so the converter does that filtering itself, over every
 * string in the converted tree, before anything is written. A user who holds
 * `unfiltered_html` (an administrator on a single site) could already type the
 * same markup into any post, and gets it as written.
 *
 * The allowed set is WordPress's own `post` list plus `iframe`, because video
 * and map embeds are ordinary page content. What kses removed is compared tag
 * by tag and attribute by attribute, so the report names what went (`script`,
 * `onclick`) rather than flagging every quote kses normalised.
 */
final class MarkupSanitiser {

    public static function mayPostUnfiltered(): bool {
        return function_exists( 'current_user_can' ) && current_user_can( 'unfiltered_html' );
    }

    /** @return array<string,array<string,bool>> */
    public static function allowedHtml(): array {
        $allowed = wp_kses_allowed_html( 'post' );

        $allowed['iframe'] = [
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'title'           => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'frameborder'     => true,
            'loading'         => true,
            'referrerpolicy'  => true,
            'style'           => true,
            'class'           => true,
            'id'              => true,
        ];

        return $allowed;
    }

    /**
     * Filter every string in a block tree's settings.
     *
     * @param array<int,array<string,mixed>>        $blocks
     * @param callable(string, string[]): void      $report Called once per block that lost something, with what it lost.
     * @return array<int,array<string,mixed>>
     */
    public static function tree( array $blocks, callable $report ): array {
        foreach ( $blocks as $i => $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $removed = [];
            if ( isset( $block['settings'] ) && is_array( $block['settings'] ) ) {
                $block['settings'] = self::settings( $block['settings'], $removed );
            }
            if ( $removed !== [] ) {
                $report( (string) ( $block['id'] ?? '' ), array_values( array_unique( $removed ) ) );
            }

            if ( isset( $block['elements'] ) && is_array( $block['elements'] ) ) {
                $block['elements'] = self::tree( $block['elements'], $report );
            }

            $blocks[ $i ] = $block;
        }

        return $blocks;
    }

    /**
     * @param array<string|int,mixed> $settings
     * @param string[]                $removed
     * @return array<string|int,mixed>
     */
    private static function settings( array $settings, array &$removed ): array {
        foreach ( $settings as $key => $value ) {
            if ( is_array( $value ) ) {
                $settings[ $key ] = self::settings( $value, $removed );
            } elseif ( is_string( $value ) && str_contains( $value, '<' ) ) {
                $settings[ $key ] = self::markup( $value, $removed );
            }
        }

        return $settings;
    }

    /**
     * @param string[] $removed Appended with each tag (`<script>`) and attribute (`onclick`) kses took out.
     */
    public static function markup( string $html, array &$removed = [] ): string {
        // kses removes a `<script>` tag but keeps what was inside it, which
        // would then print as text on the page. The contents go with the tag.
        $clean  = wp_kses( (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html ), self::allowedHtml() );
        $before = self::inventory( $html );
        $after  = self::inventory( $clean );

        foreach ( $before as $name => $count ) {
            if ( $count > ( $after[ $name ] ?? 0 ) ) {
                $removed[] = $name;
            }
        }

        return $clean;
    }

    /** @return array<string,int> `<tag>` and attribute names, counted. */
    private static function inventory( string $html ): array {
        $names = [];

        if ( preg_match_all( '/<([a-zA-Z][\w:-]*)([^>]*)>/', $html, $tags, PREG_SET_ORDER ) ) {
            foreach ( $tags as $tag ) {
                $names[] = '<' . strtolower( $tag[1] ) . '>';

                if ( preg_match_all( '/([a-zA-Z_:][\w:.-]*)\s*=/', $tag[2], $attributes ) ) {
                    foreach ( $attributes[1] as $attribute ) {
                        $names[] = strtolower( $attribute );
                    }
                }
            }
        }

        return array_count_values( $names );
    }
}
