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
 * A `<!DOCTYPE` or `<!ENTITY` declaration is refused outright rather than
 * parsed with entity substitution disabled: a WordPress export never carries
 * one, so a file that does is not the file the user thinks they are uploading.
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
        if ( preg_match( '/<!(DOCTYPE|ENTITY)/i', $xml ) ) {
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

        $directory = substr( $url, 0, (int) strrpos( $url, '/' ) + 1 );
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
     * when the first attempt hit one of libxml's default size limits — which
     * a multi-megabyte theme demo can.
     *
     * @throws \RuntimeException
     */
    private function load( string $xml ): \DOMDocument {
        $previous = libxml_use_internal_errors( true );

        foreach ( [ LIBXML_NONET | LIBXML_NOCDATA, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE ] as $flags ) {
            $dom = new \DOMDocument();

            if ( $dom->loadXML( $xml, $flags ) ) {
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );

                return $dom;
            }

            libxml_clear_errors();
        }

        libxml_use_internal_errors( $previous );

        throw new \RuntimeException( 'The export file is not well-formed XML.' );
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
