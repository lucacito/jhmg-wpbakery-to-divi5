<?php

namespace WPBakeryDivi5Converter\Parsers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a WPBakery `post_content` string into a nested node list.
 *
 * The grammar is WordPress's own, ported rather than called: the converter has
 * to parse content that WordPress is not currently rendering (an uploaded WXR
 * file, a page whose shortcodes are not registered), so `get_shortcode_regex()`,
 * `do_shortcode_tag()` and `shortcode_parse_atts()` are reproduced here from
 * wp-includes/shortcodes.php (read off the Docker container, WordPress 7.1)
 * with the tag alternation replaced by `[a-zA-Z0-9_-]+` so every tag matches,
 * registered or not.
 *
 * Grammar, as WordPress defines it:
 * - `[[tag]]` is an escaped shortcode and yields the literal text `[tag]`;
 * - `[tag …/]`, and `[tag …]` with no later `[/tag]`, are self-closing;
 * - `[tag …]…[/tag]` takes the content up to the **first** `[/tag]`. There is
 *   no depth counting, so a tag nested inside itself closes the outer one — the
 *   same way WordPress renders it, which is what the source page looked like.
 */
final class ShortcodeParser {

    /**
     * Tags whose `content` is an editor field rather than a list of child
     * elements. Their inner string is kept verbatim and never parsed.
     *
     * Derived by auditing every `config/**` file of js_composer 9.0.1 and 7.8
     * for a param with `'param_name' => 'content'` whose `type` is
     * `textarea_html`, `textarea_raw_html`, `textarea_ace` or `gutenberg`:
     *
     *   vc_column_text    config/content/shortcode-vc-column-text.php     textarea_html   (both)
     *   vc_message        config/content/shortcode-vc-message.php         textarea_html   (both)
     *   vc_cta            config/buttons/shortcode-vc-cta.php             textarea_html   (both)
     *   vc_toggle         config/content/shortcode-vc-toggle.php          textarea_html   (both)
     *   vc_hoverbox       config/content/shortcode-vc-hoverbox.php        textarea_html   (both)
     *   vc_pricing_table  config/buttons/shortcode-vc-pricing-table.php   textarea_html   (both)
     *   vc_wp_text        config/wp/shortcode-vc-wp-text.php              textarea_html   (both)
     *   vc_raw_html       config/structure/shortcode-vc-raw-html.php      textarea_ace 9.0.1 / textarea_raw_html 7.8
     *   vc_raw_js         config/structure/shortcode-vc-raw-js.php        textarea_ace 9.0.1 / textarea_raw_html 7.8
     *   vc_gutenberg      config/content/shortcode-vc-gutenberg.php 9.0.1 / include/autoload/vendors/shortcode-vc-gutenberg.php 7.8   gutenberg
     *   vc_cta_button2    config/deprecated/shortcode-vc-cta-button2.php  textarea_html   (7.8 only)
     *
     * Deliberately absent: the containers, whose content holds child
     * shortcodes — vc_section, vc_row(_inner), vc_column(_inner), vc_tta_tabs,
     * vc_tta_tour, vc_tta_accordion, vc_tta_pageable, vc_tta_section,
     * vc_tta_toggle(_section), vc_tabs, vc_tour, vc_accordion, vc_tab,
     * vc_accordion_tab. Also absent is vc_custom_field: it has no vc_map params
     * at all in either release (it is attributes-only, see
     * include/templates/shortcodes/vc_custom_field.php), so it has no content
     * to keep raw.
     */
    public const RAW_CONTENT_TAGS = [
        'vc_column_text',
        'vc_message',
        'vc_cta',
        'vc_toggle',
        'vc_hoverbox',
        'vc_raw_html',
        'vc_raw_js',
        'vc_wp_text',
        'vc_gutenberg',
        'vc_pricing_table',
        'vc_cta_button2',
    ];

    /**
     * `get_shortcode_regex()` with `($tagregexp)` replaced by `[a-zA-Z0-9_-]+`.
     *
     * 1 optional second opening bracket, 2 tag, 3 attribute string,
     * 4 the self-closing slash, 5 the content, 6 optional second closing
     * bracket. `/s` so a `.`-free pattern still spans newlines in the content.
     */
    private const SHORTCODE_REGEX = '/\[(\[?)([a-zA-Z0-9_-]+)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/s';

    /** `get_shortcode_atts_regex()`, unchanged. */
    private const ATTS_REGEX = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';

    /**
     * Curly quotes, as entities, folded back to the straight ones the attribute
     * regex expects. Content that has been through `wptexturize()` — an XML
     * export/import round trip, a theme that filters `content_save_pre` —
     * reaches us with `title=&#8220;Hi&#8221;`, which WordPress's own regex
     * reads as a bare positional attribute.
     */
    private const CURLY_QUOTE_ENTITIES = [
        '&#8220;' => '"',
        '&#8221;' => '"',
        '&#8243;' => '"',
        '&#8216;' => "'",
        '&#8217;' => "'",
    ];

    /**
     * Parses a shortcode string into a list of nodes, in document order.
     *
     * An element node is
     *   [ 'tag' => string, 'atts' => array<string|int, string>, 'content' => string,
     *     'children' => array, 'self_closing' => bool, 'offset' => int ]
     * and a run of text between elements is
     *   [ 'tag' => '#text', 'text' => string, 'offset' => int ].
     *
     * `content` is the raw inner string of every element that has a closing
     * tag ('' for a self-closing one). For a tag in $raw_content_tags that is
     * the only representation of what is inside — `children` stays empty.
     * Otherwise `children` holds the same string parsed recursively.
     *
     * Text is kept verbatim; a gap that is only whitespace is dropped, so a
     * pretty-printed document does not gather empty text nodes.
     *
     * `offset` is the byte offset of the match start **in the string handed to
     * this call** — for a child that means an offset into its parent's content
     * string, not into the whole document.
     *
     * @param string   $content          Shortcode string, e.g. a post_content.
     * @param string[] $raw_content_tags Tags whose content is not parsed.
     * @return array<int, array<string, mixed>>
     */
    public static function parse( string $content, array $raw_content_tags = self::RAW_CONTENT_TAGS ): array {
        if ( $content === '' ) {
            return [];
        }

        $matches = [];
        $found   = preg_match_all(
            self::SHORTCODE_REGEX,
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
        );

        if ( ! $found ) {
            return self::textNodes( $content, 0 );
        }

        $nodes  = [];
        $cursor = 0;

        foreach ( $matches as $match ) {
            $whole  = (string) $match[0][0];
            $offset = (int) $match[0][1];

            foreach ( self::textNodes( substr( $content, $cursor, $offset - $cursor ), $cursor ) as $node ) {
                $nodes[] = $node;
            }
            $cursor = $offset + strlen( $whole );

            // [[tag]] escapes the shortcode: strip one bracket from each side
            // and keep the rest as text, exactly as do_shortcode_tag() does.
            if ( $match[1][0] === '[' && $match[6][0] === ']' ) {
                foreach ( self::textNodes( substr( $whole, 1, -1 ), $offset ) as $node ) {
                    $nodes[] = $node;
                }
                continue;
            }

            $tag = (string) $match[2][0];
            // Group 5 only participates when a closing tag was found; group 4
            // is the explicit slash of `[tag /]`.
            $inner        = $match[5][0];
            $self_closing = $match[4][0] === '/' || $inner === null;
            $inner        = (string) $inner;

            $nodes[] = [
                'tag'          => $tag,
                'atts'         => self::parseAtts( (string) $match[3][0] ),
                'content'      => $self_closing ? '' : $inner,
                'children'     => ( $self_closing || in_array( $tag, $raw_content_tags, true ) )
                    ? []
                    : self::parse( $inner, $raw_content_tags ),
                'self_closing' => $self_closing,
                'offset'       => $offset,
            ];
        }

        foreach ( self::textNodes( substr( $content, $cursor ), $cursor ) as $node ) {
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Parses a shortcode's attribute string with `shortcode_parse_atts()`
     * semantics: `key="v"`, `key='v'`, `key=v`, `"positional"`, `'positional'`
     * and bare `positional`. Keys are lower-cased, values are `stripcslashes()`d
     * and a value holding an unbalanced `<…>` is emptied, all as WordPress does.
     *
     * Two normalisations run before matching: WordPress's own fold of
     * non-breaking and zero-width spaces to a plain space, and the curly-quote
     * entities above. WordPress does not fold quotes itself — it never sees
     * texturised shortcodes — but WPBakery content that has been through an
     * export/import round trip does carry them, and without the fold the whole
     * attribute reads as one positional value.
     *
     * @return array<string|int, string> Named attributes keyed by name,
     *                                   positional ones appended by index.
     */
    public static function parseAtts( string $text ): array {
        $folded = preg_replace( '/[\x{00a0}\x{200b}]+/u', ' ', $text );
        if ( $folded !== null ) {
            // preg_replace returns null on invalid UTF-8; keep the raw string.
            $text = $folded;
        }

        $text = strtr( $text, self::CURLY_QUOTE_ENTITIES );

        $atts    = [];
        $matches = [];

        if ( ! preg_match_all( self::ATTS_REGEX, $text, $matches, PREG_SET_ORDER ) ) {
            return $atts;
        }

        foreach ( $matches as $m ) {
            if ( ! empty( $m[1] ) ) {
                $atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
            } elseif ( ! empty( $m[3] ) ) {
                $atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
            } elseif ( ! empty( $m[5] ) ) {
                $atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
            } elseif ( isset( $m[7] ) && $m[7] !== '' ) {
                $atts[] = stripcslashes( $m[7] );
            } elseif ( isset( $m[8] ) && $m[8] !== '' ) {
                $atts[] = stripcslashes( $m[8] );
            } elseif ( isset( $m[9] ) ) {
                $atts[] = stripcslashes( $m[9] );
            }
        }

        // Reject any unclosed HTML elements.
        foreach ( $atts as &$value ) {
            if ( str_contains( $value, '<' ) && preg_match( '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value ) !== 1 ) {
                $value = '';
            }
        }
        unset( $value );

        return $atts;
    }

    /**
     * One `#text` node for a gap, or none at all when the gap is empty or
     * nothing but whitespace.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function textNodes( string $text, int $offset ): array {
        if ( trim( $text ) === '' ) {
            return [];
        }

        return [
            [
                'tag'    => '#text',
                'text'   => $text,
                'offset' => $offset,
            ],
        ];
    }
}
