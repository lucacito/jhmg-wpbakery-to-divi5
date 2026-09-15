<?php
/**
 * Conversion input read directly from posts on this site.
 *
 * Strictly read-only: this class only reads. What is then done with the post —
 * rewriting it in place, or writing a copy beside it — is the committer's
 * decision. Either way the original shortcodes survive the run, on the post
 * itself when it is converted in place, so an unwanted conversion never costs
 * the user their WPBakery content.
 *
 * What is read is the post's `post_content` (the shortcode string) and the
 * three metas WPBakery writes beside it. Nothing is rendered and no HTML is
 * scraped: the shortcodes are the page.
 */

namespace WPBakeryDivi5Converter\Conversion;

use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InstalledPostSource implements ConversionSource {

    /**
     * WPBakery's own template library post types.
     *
     * `vc4_templates` is the one WPBakery 4+ registers; `templatera` is the
     * Templatera add-on's, which the same "Templates" screen lists. Both hold
     * a fragment meant to be reused rather than a page, which is what the Divi
     * Library is for — so they are marked `library` and routed there when the
     * Pro add-on is present (task-11-amendments §2).
     */
    const LIBRARY_POST_TYPES = [ 'vc4_templates', 'templatera' ];

    /** The three metas `WPBakeryDocumentParser` reads, in the order it declares them. */
    const META_KEYS = [
        WPBakeryDocumentParser::CUSTOM_CSS_META,
        WPBakeryDocumentParser::PAGE_CSS_META,
        WPBakeryDocumentParser::JS_STATUS_META,
    ];

    /** @var int[] */
    private array $postIds;

    /** @param array<int, int|string> $post_ids */
    public function __construct( array $post_ids ) {
        $this->postIds = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
    }

    public function items(): array {
        $items = [];

        foreach ( $this->postIds as $post_id ) {
            $items[] = $this->itemFor( $post_id );
        }

        return $items;
    }

    /** @return array<string,mixed> */
    private function itemFor( int $post_id ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return $this->failed( $post_id, __( 'That page no longer exists.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $content = (string) ( $post->post_content ?? '' );
        $title   = (string) ( $post->post_title ?? '' );

        if ( ! WPBakeryDocumentParser::isWPBakeryContent( $content ) ) {
            return $this->failed(
                $post_id,
                __( 'No WPBakery layout found on that page.', 'jhmg-converter-for-wpbakery-to-divi' ),
                $title
            );
        }

        $source_post_type = (string) ( $post->post_type ?? 'page' );
        $is_library       = in_array( $source_post_type, self::LIBRARY_POST_TYPES, true );

        if ( $is_library || $source_post_type === 'page' ) {
            $post_type = 'page';
        } else {
            $post_type = 'post';
        }

        return [
            'title'            => $title !== '' ? $title : __( 'Imported Page', 'jhmg-converter-for-wpbakery-to-divi' ),
            'post_type'        => $post_type,
            'source_post_type' => $source_post_type,
            'post_name'        => (string) ( $post->post_name ?? '' ),
            'template_type'    => $is_library ? 'library' : '',
            'content'          => $content,
            'meta'             => $this->metaFor( $post_id ),
            // On this site there is a media library to ask; an attachment map
            // is what an export needs, because it has none.
            'attachments'      => [],
            'mode'             => 'direct',
            'error'            => '',
            'source_ref'       => [ 'kind' => 'installed', 'post_id' => $post_id, 'file' => null ],
        ];
    }

    /** @return array<string,string> */
    private function metaFor( int $post_id ): array {
        $meta = [];

        foreach ( self::META_KEYS as $key ) {
            $value        = get_post_meta( $post_id, $key, true );
            $meta[ $key ] = is_scalar( $value ) ? (string) $value : '';
        }

        return $meta;
    }

    /** @return array<string,mixed> */
    private function failed( int $post_id, string $error, string $title = '' ): array {
        return [
            'title'            => $title !== '' ? $title : __( 'Unknown page', 'jhmg-converter-for-wpbakery-to-divi' ),
            'post_type'        => 'page',
            'source_post_type' => '',
            'post_name'        => '',
            'template_type'    => '',
            'content'          => '',
            'meta'             => [],
            'attachments'      => [],
            'mode'             => 'direct',
            'error'            => $error,
            'source_ref'       => [ 'kind' => 'installed', 'post_id' => $post_id, 'file' => null ],
        ];
    }
}
