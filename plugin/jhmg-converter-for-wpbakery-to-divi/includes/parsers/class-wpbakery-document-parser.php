<?php

namespace WPBakeryDivi5Converter\Parsers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads one WPBakery post: its shortcode string and the three metas that go
 * with it, in one call.
 *
 * WPBakery keeps everything that matters in `post_content` plus three post
 * metas, all written by `Vc_Post_Admin::save()`
 * (include/classes/editors/class-vc-post-admin.php, js_composer 9.0.1):
 *
 * - `_wpb_shortcodes_custom_css` — the design-options CSS the editor compiles
 *   from every element's `css` attribute, one stylesheet for the whole post;
 * - `_wpb_post_custom_css`       — the CSS typed into the page settings box;
 * - `_wpb_vc_js_status`          — `'true'` when the post was last saved in the
 *   frontend editor, i.e. the builder is the page's source of truth.
 *
 * There is nothing else to read: the converter never renders the page and
 * never scrapes its HTML.
 *
 * `parse()` never rejects a document. A page with no WPBakery content at all
 * still parses — to whatever shortcodes and text it holds, with
 * `builder_flag` false — because deciding whether a post is worth converting
 * belongs to the caller, which asks `isWPBakeryContent()`.
 */
final class WPBakeryDocumentParser {

    /** The three post metas WPBakery writes beside the content. */
    public const CUSTOM_CSS_META  = '_wpb_shortcodes_custom_css';
    public const PAGE_CSS_META    = '_wpb_post_custom_css';
    public const JS_STATUS_META   = '_wpb_vc_js_status';

    /**
     * A row or a section is the only thing WPBakery cannot render a page
     * without, and neither tag exists outside it. `\b` keeps `vc_row_inner`
     * out: an inner row on its own is a fragment, not a page.
     */
    private const BUILDER_CONTENT_REGEX = '/\[vc_(row|section)\b/';

    /**
     * Parses a post's content and metas into normalised nodes.
     *
     * `nodes` are `ShortcodeParser` nodes — the same shape, `content`,
     * `children`, `self_closing` and `offset` included — with every element's
     * tag and attributes put through `AttributeNormaliser` recursively and the
     * rules it applied attached as `notes`. A node's `content` is replaced
     * when the normaliser hands one back (only `vc_cta_button` does). Text
     * nodes are kept as they are.
     *
     * @param string                       $content Post content (the shortcode string).
     * @param array<string, mixed>         $meta    Post metas, keyed by meta key, in either
     *                                              form `get_post_meta()` returns: the value
     *                                              itself or a list holding it.
     * @return array{nodes: array<int, array<string, mixed>>, custom_css: string, page_css: string, builder_flag: bool}
     * @throws \RuntimeException When PCRE fails on the document (see ShortcodeParser).
     */
    public static function parse( string $content, array $meta = [] ): array {
        return [
            'nodes'        => self::normalise( ShortcodeParser::parse( $content ) ),
            'custom_css'   => self::metaString( $meta, self::CUSTOM_CSS_META ),
            'page_css'     => self::metaString( $meta, self::PAGE_CSS_META ),
            'builder_flag' => self::metaString( $meta, self::JS_STATUS_META ) === 'true',
        ];
    }

    /**
     * Whether a post's content is a WPBakery page at all — the gate a caller
     * asks before handing anything to `parse()`.
     */
    public static function isWPBakeryContent( string $content ): bool {
        return preg_match( self::BUILDER_CONTENT_REGEX, $content ) === 1;
    }

    /**
     * `AttributeNormaliser` over a node list, depth first.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private static function normalise( array $nodes ): array {
        foreach ( $nodes as $index => $node ) {
            if ( $node['tag'] === '#text' ) {
                continue;
            }

            $normalised = AttributeNormaliser::normalise( $node['tag'], $node['atts'] );

            $node['tag']  = $normalised['tag'];
            $node['atts'] = $normalised['atts'];

            // Only one rule rewrites content: `vc_cta_button`, whose text is an
            // attribute where its replacement keeps it as the element's content.
            if ( $normalised['content'] !== null ) {
                $node['content'] = $normalised['content'];
            }

            $node['children'] = self::normalise( $node['children'] );
            $node['notes']    = $normalised['notes'];

            $nodes[ $index ] = $node;
        }

        return $nodes;
    }

    /**
     * One meta value as a string, in either form `get_post_meta()` hands back:
     * `get_post_meta( $id, $key, true )` returns the value, the same call
     * without `$single` returns a list of them. A missing meta is ''.
     *
     * @param array<string, mixed> $meta
     */
    private static function metaString( array $meta, string $key ): string {
        $value = $meta[ $key ] ?? '';

        if ( is_array( $value ) ) {
            $value = $value === [] ? '' : reset( $value );
        }

        return is_scalar( $value ) ? (string) $value : '';
    }
}
