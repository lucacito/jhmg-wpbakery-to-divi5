<?php
/**
 * Reads the posts out of a WordPress export (WXR) file.
 *
 * WPBakery has no export format of its own: a WPBakery page is its
 * `post_content` plus three post metas, so the only way a whole site changes
 * hands is Tools → Export, which writes WXR. Everything this converter needs
 * is in there — the shortcode string in `content:encoded`, the design-options
 * stylesheet in `wp:postmeta`, and the media library as `attachment` items
 * with a `wp:attachment_url` each.
 *
 * This is the only WXR parser in the repository: the import parser, the
 * corpus tests and `scripts/cut-corpus-element.php` all read exports through
 * it, so there is one set of rules about what an export is rather than three
 * that can drift.
 *
 * ### The exports this has to survive
 *
 * The DFD Ronneby demos in `wpbakery templates/` are the real target: up to
 * 5 MB, hundreds of items of a dozen post types (`page`, `post`, `attachment`,
 * `nav_menu_item`, `product`, `acf-field`, …), `wp:postmeta` values holding
 * serialized PHP, and `content:encoded` CDATA sections split around any `]]>`
 * the content happens to contain. So: DOM (not regex), `LIBXML_NONET` so
 * nothing is fetched, `LIBXML_NOCDATA` so a split section reads as one value,
 * and `LIBXML_PARSEHUGE` on a second attempt when a document is past libxml's
 * default limits.
 *
 * A `<!DOCTYPE` or `<!ENTITY` declaration **in the prolog** is refused
 * outright rather than parsed with entity substitution disabled: a WordPress
 * export never carries one, so a file that does is not the file the user
 * thinks they are uploading. The prolog is where a declaration has to be to
 * mean anything, and scoping the check there is what lets a page that quotes
 * `<!DOCTYPE html>` in its content — a code block, a tutorial, a raw-HTML
 * element — convert instead of being refused.
 *
 * Scoping only works if the scope cannot be escaped. Comments and processing
 * instructions are prolog too and can hold anything, `<a` included, so the
 * prolog is bounded on a copy with both taken out — otherwise
 * `<!-- <a --><!DOCTYPE …>` ends the search one comment before the
 * declaration it hides, and libxml substitutes the internal entities into
 * `textContent` whether or not `LIBXML_NOENT` was asked for.
 *
 * The size retry is gated for the same reason: `LIBXML_PARSEHUGE` switches
 * off libxml's own entity-amplification guard along with its size limits, so
 * it is passed only when the first attempt failed on a size limit. A parse
 * that failed on entities has failed, and says so.
 */

namespace WPBakeryDivi5Converter\Parsers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WxrReader {

    const WP_NS = 'http://wordpress.org/export/1.2/';

    const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    /**
     * @return array<int, array{title: string, post_id: int, post_type: string, post_name: string,
     *   status: string, content: string, attachment_url: string, meta: array<string,string>}>
     * @throws \RuntimeException On XML that is malformed, or that declares a DOCTYPE or entities.
     */
    public function read( string $xml ): array {
        if ( preg_match( '/<!(DOCTYPE|ENTITY)/i', self::prolog( $xml ) ) ) {
            throw new \RuntimeException( 'The export file declares a DOCTYPE or entities, which an export never does. Refusing to read it.' );
        }

        $dom = $this->load( $xml );

        $items = [];
        foreach ( $dom->getElementsByTagName( 'item' ) as $item ) {
            $items[] = [
                'title'          => $this->text( $item, 'title' ),
                'post_id'        => (int) $this->wp( $item, 'post_id' ),
                'post_type'      => $this->wp( $item, 'post_type' ),
                'post_name'      => $this->wp( $item, 'post_name' ),
                'status'         => $this->wp( $item, 'status' ),
                'content'        => $this->contentEncoded( $item ),
                'attachment_url' => $this->wp( $item, 'attachment_url' ),
                'meta'           => $this->meta( $item ),
            ];
        }

        return $items;
    }

    /**
     * Everything before the document element.
     *
     * `<?xml …?>`, comments and any declaration live here; the first `<` that
     * is followed by a name character opens the root element (`<rss>`) and
     * everything from there on is content, whatever it quotes.
     *
     * A comment or a processing instruction can hold that `<` itself, and one
     * written before the declaration would end the search in front of it — so
     * the bound is looked for on a copy with comments and instructions taken
     * out. Only the bound is: what comes back is that copy's prolog, which is
     * all the declaration check reads.
     */
    private static function prolog( string $xml ): string {
        $stripped = self::withoutCommentsAndInstructions( $xml );

        // PCRE gave up (a comment past `pcre.backtrack_limit` is one way to
        // make it). Nothing can be scoped to a prolog that could not be
        // found, so the whole document is the scope: a declaration anywhere
        // in a file that pathological is refused rather than trusted.
        if ( $stripped === null ) {
            return $xml;
        }

        return preg_match( '/<[A-Za-z_]/', $stripped, $match, PREG_OFFSET_CAPTURE ) === 1
            ? substr( $stripped, 0, (int) $match[0][1] )
            : $stripped;
    }

    /** The document with its comments and processing instructions removed, or null if PCRE gave up. */
    private static function withoutCommentsAndInstructions( string $xml ): ?string {
        foreach ( [ '/<!--.*?-->/s', '/<\?.*?\?>/s' ] as $pattern ) {
            $xml = preg_replace( $pattern, '', $xml );

            if ( $xml === null ) {
                return null;
            }
        }

        return $xml;
    }

    /**
     * The media library an export carries, as the converter's attachment map.
     *
     * An export has no site to ask, so an `image="42"` in a shortcode can only
     * be resolved from the export's own `attachment` items. `wp:attachment_url`
     * gives the full-size URL; a named size (`img_size="medium"`) needs the
     * `_wp_attachment_metadata` postmeta, whose `sizes[<name>][file]` entries
     * are file names relative to the attachment's own directory
     * (task-11-amendments §5).
     *
     * @param array<int, array<string,mixed>> $items `read()` output.
     * @return array<int, array{url: string, sizes: array<string,string>}>
     */
    public static function attachments( array $items ): array {
        $map = [];

        foreach ( $items as $item ) {
            if ( ( $item['post_type'] ?? '' ) !== 'attachment' ) {
                continue;
            }

            $id  = (int) ( $item['post_id'] ?? 0 );
            $url = trim( (string) ( $item['attachment_url'] ?? '' ) );

            if ( $id <= 0 || $url === '' ) {
                continue;
            }

            $map[ $id ] = [
                'url'   => $url,
                'sizes' => self::sizesFrom( (string) ( $item['meta']['_wp_attachment_metadata'] ?? '' ), $url ),
            ];
        }

        return $map;
    }

    /**
     * `size name ⇒ URL` out of one `_wp_attachment_metadata` value.
     *
     * @return array<string,string>
     */
    private static function sizesFrom( string $serialized, string $url ): array {
        if ( trim( $serialized ) === '' ) {
            return [];
        }

        // The value is a WordPress metadata array; an object in it would be
        // somebody else's payload, not an attachment's sizes.
        if ( preg_match( '/O:\d+:"/', $serialized ) === 1 ) {
            return [];
        }

        $meta = @unserialize( trim( $serialized ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        if ( ! is_array( $meta ) || ! is_array( $meta['sizes'] ?? null ) ) {
            return [];
        }

        // A URL with no path separator has no directory to resolve a size's
        // file name against, and `(int) false` would silently make one out of
        // its first character.
        $slash = strrpos( $url, '/' );
        if ( $slash === false ) {
            return [];
        }

        $directory = substr( $url, 0, $slash + 1 );
        $sizes     = [];

        foreach ( $meta['sizes'] as $name => $size ) {
            $file = is_array( $size ) ? (string) ( $size['file'] ?? '' ) : '';

            if ( ! is_string( $name ) || $name === '' || $file === '' ) {
                continue;
            }

            $sizes[ $name ] = $directory . $file;
        }

        return $sizes;
    }

    /**
     * A libxml parse with the network off, retried with `LIBXML_PARSEHUGE`
     * when — and only when — the first attempt hit one of libxml's default
     * size limits, which a multi-megabyte theme demo can.
     *
     * `LIBXML_PARSEHUGE` lifts the entity-amplification guard along with the
     * size limits, so retrying with it after *any* failure turns libxml's own
     * refusal to expand a bomb into a successful expansion. The retry is
     * therefore asked one question first: was this a size failure? A parse
     * that fell over on entities is reported as the failure it is.
     *
     * @throws \RuntimeException
     */
    private function load( string $xml ): \DOMDocument {
        $previous = libxml_use_internal_errors( true );

        /** @var \LibXMLError[] $errors */
        $errors = [];

        foreach ( [ LIBXML_NONET | LIBXML_NOCDATA, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE ] as $attempt => $flags ) {
            $dom = new \DOMDocument();

            if ( $dom->loadXML( $xml, $flags ) ) {
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );

                return $dom;
            }

            $errors = libxml_get_errors();
            libxml_clear_errors();

            if ( $attempt === 0 && ! self::isSizeFailure( $errors ) ) {
                break;
            }
        }

        libxml_use_internal_errors( $previous );

        throw new \RuntimeException( esc_html( self::failureMessage( $errors ) ) );
    }

    /**
     * Was this failure one of libxml's default size limits, and nothing else?
     *
     * The limits announce themselves in the message — "Excessive depth in
     * document: 256 use XML_PARSE_HUGE option", "Name too long", "AttValue
     * length too long", "Huge input lookup" — and libxml ≥ 2.11 also has a
     * code of its own for them (`XML_ERR_RESOURCE_LIMIT`). A size error is
     * usually followed by the parse errors it caused, so one is enough; an
     * entity error anywhere in the batch is not, because that is precisely
     * the failure the retry must not paper over.
     *
     * @param \LibXMLError[] $errors
     */
    private static function isSizeFailure( array $errors ): bool {
        $size = false;

        foreach ( $errors as $error ) {
            if ( self::isEntityError( $error ) ) {
                return false;
            }

            $size = $size || self::isSizeError( $error );
        }

        return $size;
    }

    /** `XML_ERR_UNDECLARED_ENTITY` (26), `XML_WAR_UNDECLARED_ENTITY` (27), `XML_ERR_ENTITY_LOOP` (89), `XML_ERR_ENTITY_BOUNDARY` (90), `XML_ERR_ENTITY_PROCESSING` (104), and anything else that names an entity. */
    private static function isEntityError( \LibXMLError $error ): bool {
        return in_array( $error->code, [ 26, 27, 89, 90, 104 ], true )
            || stripos( $error->message, 'entit' ) !== false;
    }

    /** `XML_ERR_NAME_TOO_LONG` (110), `XML_ERR_RESOURCE_LIMIT` (113, libxml ≥ 2.11), and the limits that only say so in words. */
    private static function isSizeError( \LibXMLError $error ): bool {
        return in_array( $error->code, [ 110, 113 ], true )
            || preg_match( '/XML_PARSE_HUGE|too long|huge input|huge text node|limit exceeded/i', $error->message ) === 1;
    }

    /**
     * Why the file was refused, in the reader's own words with libxml's
     * appended — so the notice the user reads names the reason instead of
     * leaving them with an import that found nothing.
     *
     * @param \LibXMLError[] $errors
     */
    private static function failureMessage( array $errors ): string {
        foreach ( $errors as $error ) {
            if ( self::isEntityError( $error ) ) {
                return sprintf(
                    'The export file could not be read: %s. A WordPress export declares no entities, and expanding these is refused.',
                    trim( $error->message )
                );
            }
        }

        $detail = isset( $errors[0] ) ? trim( $errors[0]->message ) : '';

        return $detail === ''
            ? 'The export file is not well-formed XML.'
            : sprintf( 'The export file is not well-formed XML: %s.', $detail );
    }

    /** @return array<string,string> */
    private function meta( \DOMElement $item ): array {
        $meta = [];

        foreach ( $item->getElementsByTagNameNS( self::WP_NS, 'postmeta' ) as $postmeta ) {
            $key   = $this->wp( $postmeta, 'meta_key' );
            $value = $this->wp( $postmeta, 'meta_value' );

            if ( $key !== '' && ! isset( $meta[ $key ] ) ) {
                $meta[ $key ] = $value;
            }
        }

        return $meta;
    }

    /**
     * A direct child of the item wins over a descendant: `wp:post_id` is the
     * post's, never a `wp:comment`'s `wp:comment_id` neighbour, and
     * `wp:meta_key` inside a `wp:postmeta` is that meta's.
     */
    private function wp( \DOMElement $parent, string $name ): string {
        $nodes = $parent->getElementsByTagNameNS( self::WP_NS, $name );

        foreach ( $nodes as $node ) {
            if ( $node->parentNode === $parent ) {
                return (string) $node->textContent;
            }
        }

        return $nodes->length > 0 ? (string) $nodes->item( 0 )->textContent : '';
    }

    private function contentEncoded( \DOMElement $item ): string {
        foreach ( $item->childNodes as $child ) {
            if ( $child instanceof \DOMElement && $child->localName === 'encoded' && $child->namespaceURI === self::CONTENT_NS ) {
                return (string) $child->textContent;
            }
        }

        return '';
    }

    /** An unprefixed child element (`<title>`), which `getElementsByTagName()` would find in any namespace. */
    private function text( \DOMElement $parent, string $name ): string {
        foreach ( $parent->childNodes as $child ) {
            if ( $child instanceof \DOMElement && $child->localName === $name && ( $child->namespaceURI === null || $child->namespaceURI === '' ) ) {
                return (string) $child->textContent;
            }
        }

        return '';
    }
}
