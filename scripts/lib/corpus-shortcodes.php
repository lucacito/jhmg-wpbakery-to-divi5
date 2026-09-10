<?php
/**
 * Finding one shortcode inside a WordPress export.
 *
 * `scripts/cut-corpus-element.php` cuts fixtures with this and
 * `tests/RonnebyHandlersTest.php` walks every occurrence of a tag with it, so
 * the fixture in the repository and the elements the corpus gate converts are
 * the same spans of text — which they would not be if each had its own scanner.
 *
 * Plain functions rather than a class: the cut script is a standalone script
 * that must run without the plugin's autoloader, and the test only needs the
 * one call.
 */

if ( ! function_exists( 'wbdc_find_shortcodes' ) ) {
    /**
     * Every `[tag …]…[/tag]` (or self-closing `[tag …]`) in a document, with the
     * offset it starts at.
     *
     * The closing tag is matched by counting opens and closes rather than with a
     * lazy `.*?`, so an element holding another of the same tag — a
     * `dfd_accordion` inside a `vc_tta_section`, most often — is cut whole
     * instead of at the first `[/tag]`. An occurrence inside one already
     * returned is not returned again.
     *
     * @return array<int, array{offset: int, text: string}>
     */
    function wbdc_find_shortcodes( string $document, string $tag ): array {
        $pattern = '/\[' . preg_quote( $tag, '/' ) . '(?![\w-])([^\]]*)\]/';

        if ( preg_match_all( $pattern, $document, $opens, PREG_OFFSET_CAPTURE ) === 0 ) {
            return [];
        }

        $close     = '[/' . $tag . ']';
        $found     = [];
        $skip_past = -1;

        foreach ( $opens[0] as $open ) {
            [ $open_text, $offset ] = $open;

            if ( $offset < $skip_past ) {
                continue;
            }

            // A self-closing element (`[dfd_spacer …]`) has no closing tag at all.
            if ( strpos( $document, $close, $offset ) === false ) {
                $found[] = [ 'offset' => $offset, 'text' => $open_text ];

                continue;
            }

            $depth  = 1;
            $cursor = $offset + strlen( $open_text );
            $end    = null;

            while ( $depth > 0 ) {
                $next_open  = preg_match( $pattern, $document, $m, PREG_OFFSET_CAPTURE, $cursor ) === 1 ? $m[0][1] : false;
                $next_close = strpos( $document, $close, $cursor );

                if ( $next_close === false ) {
                    break;
                }

                // Another open of the same tag before the close? Then the close
                // belongs to it, and this element's own is further on.
                if ( $next_open !== false && $next_open < $next_close ) {
                    $depth++;
                    $cursor = $next_open + strlen( $m[0][0] );

                    continue;
                }

                $depth--;
                $cursor = $next_close + strlen( $close );
                if ( $depth === 0 ) {
                    $end = $cursor;
                }
            }

            if ( $end === null ) {
                // Unbalanced: keep the opening tag alone rather than the rest of
                // the document.
                $found[] = [ 'offset' => $offset, 'text' => $open_text ];

                continue;
            }

            $found[]   = [ 'offset' => $offset, 'text' => substr( $document, $offset, $end - $offset ) ];
            $skip_past = $end;
        }

        return $found;
    }
}
