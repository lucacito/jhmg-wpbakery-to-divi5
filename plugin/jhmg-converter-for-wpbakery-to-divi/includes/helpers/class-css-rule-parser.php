<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses the one CSS rule WPBakery's "design options" panel writes into the
 * `css` shortcode attribute: `.vc_custom_<timestamp>{prop: value !important;...}`.
 * `vc_shortcode_custom_css_class()` (include/helpers/helpers.php, js_composer
 * 9.0.1) pulls the class name back out of that same string with a
 * `.name{...}` regex — `parse()` does the equivalent, plus reading the
 * declaration list the class stays paired with.
 *
 * `imageRef()` reads the `?id=<attachment id>` suffix WPBakery's own WXR
 * importer appends to a background-image URL when it remaps attachment URLs
 * on import (include/classes/core/shared-templates/importer/class-vc-wxr-parser-plugin.php,
 * `remapAttachmentUrls()`) — the same convention a design-options
 * `background-image: url(...)` value carries after a page has been through
 * an export/import round trip.
 */
final class CssRuleParser {

    /**
     * @return array{class: string, declarations: array<string,string>}
     */
    public static function parse( string $css ): array {
        $result = [ 'class' => '', 'declarations' => [] ];

        // `([^}]*)` stops at the FIRST closing brace, exactly as
        // `vc_shortcode_custom_css_class()` does; a greedy `(.*)` would run to
        // the last one and swallow a second rule's selector as a declaration.
        if ( ! preg_match( '/^\s*\.([^{]+?)\s*\{([^}]*)\}/s', $css, $m ) ) {
            return $result;
        }

        $result['class'] = trim( $m[1] );

        foreach ( explode( ';', $m[2] ) as $chunk ) {
            $chunk = trim( $chunk );
            if ( $chunk === '' ) {
                continue;
            }

            $colon = strpos( $chunk, ':' );
            if ( $colon === false ) {
                continue;
            }

            $prop = strtolower( trim( substr( $chunk, 0, $colon ) ) );
            if ( $prop === '' || $prop[0] === '*' || $prop[0] === '_' ) {
                // `*background-color`, `_zoom` and other browser-hack prefixes.
                continue;
            }

            $value = trim( substr( $chunk, $colon + 1 ) );
            $value = trim( preg_replace( '/\s*!\s*important\s*$/i', '', $value ) );

            if ( $prop === 'background' ) {
                foreach ( self::splitBackgroundShorthand( $value ) as $bg_prop => $bg_value ) {
                    $result['declarations'][ $bg_prop ] = $bg_value;
                }
                continue;
            }

            $result['declarations'][ $prop ] = $value;
        }

        return $result;
    }

    /**
     * `url(https://x/y.jpg?id=106)` → the URL and the attachment id WPBakery's
     * WXR importer appended; `url(123)` (an id with no URL at all) →
     * `['url' => '', 'id' => 123]`.
     *
     * @return array{url: string, id: ?int}
     */
    public static function imageRef( string $url_value ): array {
        if ( ! preg_match( '/^url\(\s*[\'"]?(.*?)[\'"]?\s*\)$/is', trim( $url_value ), $m ) ) {
            return [ 'url' => '', 'id' => null ];
        }

        $inner = $m[1];

        if ( preg_match( '/^\d+$/', $inner ) ) {
            return [ 'url' => '', 'id' => (int) $inner ];
        }

        $id       = null;
        $question = strpos( $inner, '?' );

        if ( $question !== false ) {
            $query = [];
            parse_str( substr( $inner, $question + 1 ), $query );

            if ( isset( $query['id'] ) && ctype_digit( (string) $query['id'] ) ) {
                $id    = (int) $query['id'];
                $inner = substr( $inner, 0, $question );
            }
        }

        return [ 'url' => $inner, 'id' => $id ];
    }

    /**
     * The `background` shorthand split into `background-color` (a colour
     * token) and `background-image` (a `url(...)` token), whichever are
     * present. Position/repeat/attachment keywords are not carried — nothing
     * downstream of this parser reads them.
     *
     * @return array<string,string>
     */
    private static function splitBackgroundShorthand( string $value ): array {
        $remaining = $value;
        $image     = null;
        $color     = null;

        if ( preg_match( '/url\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', $remaining, $m ) ) {
            $image     = 'url(' . $m[1] . ')';
            $remaining = str_replace( $m[0], ' ', $remaining );
        }

        if ( preg_match( '/(rgba?|hsla?)\([^)]*\)/i', $remaining, $m ) ) {
            $color = $m[0];
        } elseif ( preg_match( '/#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i', $remaining, $m ) ) {
            $color = $m[0];
        }

        // background-color before background-image, whichever are present.
        $out = [];
        if ( $color !== null ) {
            $out['background-color'] = $color;
        }
        if ( $image !== null ) {
            $out['background-image'] = $image;
        }

        return $out;
    }
}
